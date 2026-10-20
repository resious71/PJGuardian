<?php

declare(strict_types=1);

namespace PJGuardian\Services\Admin;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 도구 설치 패키지 스테이징(P20A, 명세 12, 19.1). 관리자가 웹에서 도구 패키지 zip을 올리면 여기에 **보관만** 한다.
 * 압축 풀기·bin/ 설치·검증은 서버 CLI(`php tools/tools_ctl.php install --staging=<id>`, ToolInstaller)가 한다.
 *
 * - 저장 위치: <work_dir>/tool_staging/<id>/package.zip (받는 중에는 package.zip.part). 프로젝트 업로드(uploads/)와 별도.
 *   경로는 서버가 만든 id(32자리 16진수)로만 만든다. 파일명은 표시용.
 * - 청크는 순서대로 이어 붙인다(전용 청크 업로드, 프로젝트 업로드의 조립·워커와 무관). 청크마다 SHA-256(X-Chunk-Sha256) 검사.
 *   같은 청크를 다시 보내면(응답을 못 받은 재전송) 이미 받은 것으로 보고 건너뛴다. 순서를 건너뛰면 409(details.next).
 * - complete가 전체 SHA-256을 계산해 보여 준다(1GB 기준 수 초). 관리자가 배포처 공개 해시(expected_sha256)를 넣으면 대조하고,
 *   다르면 설치 명령을 보여 주지 않는다(CLI도 거부).
 * - 크기 한도 tools.package_max_size, 동시에 받는 중 2개, 디스크 사전 검사(DiskGuard: 사용률 차단 + 진행 중 업로드 예약 합계 + 다른 스테이징의 남은 크기).
 * - 정리: tools.staging_retention(기본 7일) 동안 변화가 없으면 정리 작업이 폴더를 지우고 discarded로 바꾼다(설치 중 제외).
 */
final class ToolStaging
{
    public const TOOLS = ['ruff', 'eslint'];
    public const MAX_RECEIVING = 2;
    private const READ_BYTES = 1024 * 1024;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly string $root,
        private readonly int $maxBytes,
        private readonly int $chunkBytes,
        private readonly DiskGuard $disk,
        private readonly Logger $logger,
    ) {
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function dir(string $id): string
    {
        if (!self::validId($id)) {
            throw new \InvalidArgumentException('스테이징 id 형식 오류');
        }
        return $this->root . '/' . $id;
    }

    public function packageFile(string $id): string
    {
        return $this->dir($id) . '/package.zip';
    }

    public function resultFile(string $id): string
    {
        return $this->dir($id) . '/result.json';
    }

    private function partFile(string $id): string
    {
        return $this->dir($id) . '/package.zip.part';
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** @return array<string, mixed> 행(없거나 형식이 틀리면 404) */
    public function row(string $id): array
    {
        $row = self::validId($id) ? $this->db->one('SELECT * FROM tool_staging WHERE id = ?', [$id]) : null;
        if ($row === null) {
            throw new ApiException(404, 'staging_not_found');
        }
        return $row;
    }

    private static function sha(mixed $v, bool $optional): ?string
    {
        if ($optional && ($v === null || $v === '')) {
            return null;
        }
        $v = is_string($v) ? strtolower(trim($v)) : '';
        if (preg_match('/^[0-9a-f]{64}$/', $v) !== 1) {
            throw new ApiException(400, 'invalid_sha256');
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $data tool, file_name, total_size, expected_sha256?
     * @return array<string, mixed>
     */
    public function init(int $userId, array $data): array
    {
        $tool = $data['tool'] ?? null;
        if (!is_string($tool) || !in_array($tool, self::TOOLS, true)) {
            throw new ApiException(400, 'invalid_tool');
        }
        $size = $data['total_size'] ?? null;
        if (!is_int($size) || $size <= 0) {
            throw new ApiException(400, 'invalid_upload_size');
        }
        if ($size > $this->maxBytes) {
            throw new ApiException(413, 'package_too_large', ['max' => intdiv($this->maxBytes, 1024 * 1024)]);
        }
        $name = $data['file_name'] ?? null;
        $name = is_string($name) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $name)) : '';
        if ($name === '' || !str_ends_with(strtolower($name), '.zip')) {
            throw new ApiException(400, 'package_not_zip');
        }
        $name = mb_substr($name, 0, 255, 'UTF-8');
        $expected = self::sha($data['expected_sha256'] ?? null, true);
        $chunk = $this->chunkBytes;
        $chunks = intdiv($size + $chunk - 1, $chunk);
        $id = bin2hex(random_bytes(16));
        $now = $this->now();
        Fs::ensureDir($this->root);
        $this->db->transaction(function (Db $db) use ($id, $tool, $name, $size, $chunk, $chunks, $expected, $userId, $now): void {
            $receiving = $db->all("SELECT total_size, received_bytes FROM tool_staging WHERE status = 'receiving'");
            if (count($receiving) >= self::MAX_RECEIVING) {
                throw new ApiException(429, 'too_many_stagings', ['max' => self::MAX_RECEIVING]);
            }
            $others = array_sum(array_map(static fn (array $r): int => (int) $r['total_size'] - (int) $r['received_bytes'], $receiving));
            $this->disk->check($this->root, $size + $others);
            $db->execute(
                'INSERT INTO tool_staging (id, tool, file_name, total_size, received_bytes, chunk_size, total_chunks, status, expected_sha256, uploaded_by, created_at, updated_at)'
                . " VALUES (?, ?, ?, ?, 0, ?, ?, 'receiving', ?, ?, ?, ?)",
                [$id, $tool, $name, $size, $chunk, $chunks, $expected, $userId, $now, $now],
            );
            if (!@mkdir($this->dir($id), 0700)) {
                throw new \RuntimeException('스테이징 폴더를 만들 수 없음');
            }
            touch($this->partFile($id));
        });
        $this->logger->info('도구 패키지 스테이징 시작', ['staging_id' => $id, 'tool' => $tool, 'total_size' => $size]);
        return $this->get($id);
    }

    /**
     * 청크 하나를 이어 붙인다.
     *
     * @param resource $body
     * @return array{index:int, received_bytes:int, next:int, total_chunks:int}
     */
    public function chunk(string $id, mixed $index, mixed $sha, $body, ?int $contentLength): array
    {
        $row = $this->row($id);
        if ($row['status'] !== 'receiving') {
            throw new ApiException(409, 'staging_not_receiving');
        }
        if (!is_string($index) || preg_match('/^\d{1,7}$/', $index) !== 1 || (int) $index >= (int) $row['total_chunks']) {
            throw new ApiException(400, 'invalid_chunk_index');
        }
        $n = (int) $index;
        $sha = self::sha($sha, false);
        $chunkSize = (int) $row['chunk_size'];
        $last = (int) $row['total_chunks'] - 1;
        $expect = $n < $last ? $chunkSize : (int) $row['total_size'] - $chunkSize * $last;
        if ($contentLength !== null && $contentLength !== $expect) {
            throw new ApiException(400, 'invalid_chunk_length', ['expected' => $expect]);
        }
        $tmp = $this->dir($id) . '/chunk.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = @fopen($tmp, 'xb');
        if ($out === false) {
            throw new \RuntimeException('청크 임시 파일을 만들 수 없음');
        }
        $ctx = hash_init('sha256');
        $len = 0;
        try {
            while (!feof($body)) {
                $buf = fread($body, self::READ_BYTES);
                if ($buf === false || $buf === '') {
                    break;
                }
                $len += strlen($buf);
                if ($len > $expect) {
                    throw new ApiException(400, 'invalid_chunk_length', ['expected' => $expect]);
                }
                hash_update($ctx, $buf);
                fwrite($out, $buf);
            }
            fclose($out);
            $out = null;
            if ($len !== $expect) {
                throw new ApiException(400, 'invalid_chunk_length', ['expected' => $expect]);
            }
            if (!hash_equals($sha, hash_final($ctx))) {
                throw new ApiException(422, 'chunk_hash_mismatch');
            }
            $lock = FileLock::acquire($this->dir($id) . '/.lock');
            try {
                $cur = $this->row($id);
                if ($cur['status'] !== 'receiving') {
                    throw new ApiException(409, 'staging_not_receiving');
                }
                $next = intdiv((int) $cur['received_bytes'], $chunkSize);
                if ($n < $next) {
                    // 이미 받은 청크의 재전송(응답 유실): 이어 붙이지 않는다
                    return ['index' => $n, 'received_bytes' => (int) $cur['received_bytes'], 'next' => $next, 'total_chunks' => (int) $cur['total_chunks']];
                }
                if ($n > $next) {
                    throw new ApiException(409, 'staging_chunk_out_of_order', ['next' => $next], null, [], [], ['next' => $next]);
                }
                $part = $this->partFile($id);
                clearstatcache(true, $part);
                if ((int) @filesize($part) !== (int) $cur['received_bytes']) {
                    // 지난 요청이 쓰다가 끊긴 꼬리를 잘라 DB 기록과 맞춘다
                    $h = fopen($part, 'r+b');
                    ftruncate($h, (int) $cur['received_bytes']);
                    fclose($h);
                }
                $in = fopen($tmp, 'rb');
                $dst = fopen($part, 'ab');
                $copied = stream_copy_to_stream($in, $dst);
                fclose($in);
                fclose($dst);
                if ($copied !== $len) {
                    throw new \RuntimeException('청크를 이어 붙이지 못함');
                }
                $received = (int) $cur['received_bytes'] + $len;
                $this->db->execute('UPDATE tool_staging SET received_bytes = ?, updated_at = ? WHERE id = ?', [$received, $this->now(), $id]);
                return ['index' => $n, 'received_bytes' => $received, 'next' => $n + 1, 'total_chunks' => (int) $cur['total_chunks']];
            } finally {
                $lock->release();
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
        }
    }

    /** 모두 받았으면 전체 SHA-256을 계산하고 staged로. @return array<string, mixed> */
    public function complete(string $id): array
    {
        $lock = FileLock::acquire($this->dir($this->row($id)['id']) . '/.lock');
        try {
            $row = $this->row($id);
            if ($row['status'] !== 'receiving') {
                throw new ApiException(409, 'staging_not_receiving');
            }
            $part = $this->partFile($id);
            clearstatcache(true, $part);
            if ((int) $row['received_bytes'] !== (int) $row['total_size'] || (int) @filesize($part) !== (int) $row['total_size']) {
                throw new ApiException(409, 'staging_incomplete');
            }
            $sha = (string) hash_file('sha256', $part);
            if (!@rename($part, $this->packageFile($id))) {
                throw new \RuntimeException('패키지 파일을 저장할 수 없음');
            }
            $this->db->execute("UPDATE tool_staging SET status = 'staged', sha256 = ?, updated_at = ? WHERE id = ?", [$sha, $this->now(), $id]);
        } finally {
            $lock->release();
        }
        $this->logger->info('도구 패키지 스테이징 완료', ['staging_id' => $id, 'sha256' => $sha]);
        return $this->get($id);
    }

    /** 배포처 공개 해시 입력·지우기. @return array<string, mixed> */
    public function setExpected(string $id, mixed $sha): array
    {
        $row = $this->row($id);
        if (!in_array($row['status'], ['receiving', 'staged', 'install_failed'], true)) {
            throw new ApiException(409, 'staging_not_editable');
        }
        $this->db->execute('UPDATE tool_staging SET expected_sha256 = ?, updated_at = ? WHERE id = ?', [self::sha($sha, true), $this->now(), $id]);
        return $this->get($id);
    }

    /** 폐기(설치 중이 아니면). 폴더를 지운다. @return array<string, mixed> */
    public function discard(string $id): array
    {
        $row = $this->row($id);
        if ($row['status'] === 'installing') {
            throw new ApiException(409, 'staging_installing');
        }
        $lock = FileLock::acquire($this->dir($id) . '/.lock');
        try {
            $this->db->execute("UPDATE tool_staging SET status = 'discarded', updated_at = ? WHERE id = ? AND status <> 'installing'", [$this->now(), $id]);
        } finally {
            $lock->release();
        }
        Fs::removeTree($this->dir($id));
        $this->logger->info('도구 패키지 스테이징 폐기', ['staging_id' => $id]);
        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->format($this->row($id));
    }

    /** @return list<array<string, mixed>> 최근(폐기 제외) */
    public function list(int $limit = 20): array
    {
        return array_map($this->format(...), $this->db->all("SELECT * FROM tool_staging WHERE status <> 'discarded' ORDER BY created_at DESC, id LIMIT ?", [$limit]));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        $sha = $row['sha256'];
        $expected = $row['expected_sha256'];
        $match = $sha === null || $expected === null ? null : hash_equals((string) $expected, (string) $sha);
        $result = is_string($row['result'] ?? null) ? json_decode($row['result'], true) : null;
        if (!is_array($result) && self::validId((string) $row['id']) && in_array($row['status'], ['installed', 'install_failed'], true)) {
            $raw = @file_get_contents($this->resultFile((string) $row['id']));
            $result = $raw === false ? null : json_decode($raw, true);
        }
        $installable = $row['status'] === 'staged' && $match !== false;
        return [
            'id' => (string) $row['id'], 'tool' => (string) $row['tool'], 'file_name' => (string) $row['file_name'],
            'total_size' => (int) $row['total_size'], 'received_bytes' => (int) $row['received_bytes'],
            'chunk_size' => (int) $row['chunk_size'], 'total_chunks' => (int) $row['total_chunks'],
            'next_chunk' => intdiv((int) $row['received_bytes'], max(1, (int) $row['chunk_size'])),
            'status' => (string) $row['status'], 'sha256' => $sha, 'expected_sha256' => $expected, 'hash_match' => $match,
            // 서버에서 실행할 명령(경로·비밀값 없음). 해시가 다르거나 아직 받는 중이면 보여 주지 않는다
            'install_command' => $installable ? 'php tools/tools_ctl.php install --staging=' . $row['id'] : null,
            'result' => is_array($result) ? $result : null,
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    // ---- CLI 설치(ToolInstaller)가 쓰는 상태 전이 ----

    /** staged → installing(원자적). 다른 설치가 먼저 가져갔으면 false */
    public function claimForInstall(string $id): bool
    {
        return $this->db->execute("UPDATE tool_staging SET status = 'installing', updated_at = ? WHERE id = ? AND status = 'staged'", [$this->now(), $id]) === 1;
    }

    /** @param array<string, mixed> $result ok, version, status, message(서버 경로 없음) */
    public function recordResult(string $id, bool $ok, array $result): void
    {
        $json = (string) json_encode((object) $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            Fs::atomicWrite($this->resultFile($id), $json);
        } catch (\Throwable) {
            // 결과 파일을 못 써도 DB 기록이 있다
        }
        $this->db->execute('UPDATE tool_staging SET status = ?, result = ?, updated_at = ? WHERE id = ?', [$ok ? 'installed' : 'install_failed', $json, $this->now(), $id]);
        if ($ok) {
            @unlink($this->packageFile($id)); // 설치가 끝난 zip은 바로 지운다(결과 파일만 남김)
        }
    }

    /**
     * 정리 작업: 보관 기간 동안 변화가 없는 스테이징(설치 중 제외)의 폴더를 지우고 discarded로. 행이 없는 폴더도 같은 기준(수정 시각).
     *
     * @return array{staging_purged:int}
     */
    public function purgeExpired(int $retentionSeconds, bool $dryRun = false): array
    {
        $cutoff = SystemClock::iso($this->clock->now()->modify('-' . $retentionSeconds . ' seconds'));
        $n = 0;
        foreach ($this->db->all("SELECT id, status FROM tool_staging WHERE status <> 'installing' AND updated_at < ?", [$cutoff]) as $r) {
            $id = (string) $r['id'];
            if ($r['status'] === 'discarded' && !is_dir($this->dir($id))) {
                continue; // 이미 정리됨
            }
            $n++;
            if (!$dryRun) {
                Fs::removeTree($this->dir($id));
                $this->db->execute("UPDATE tool_staging SET status = 'discarded', updated_at = ? WHERE id = ? AND status <> 'installing'", [$this->now(), $id]);
            }
        }
        $cutTs = $this->clock->now()->getTimestamp() - $retentionSeconds;
        foreach (Fs::listDir($this->root) as $name) {
            if (!self::validId($name) || $this->db->value('SELECT 1 FROM tool_staging WHERE id = ?', [$name]) !== null) {
                continue;
            }
            if ((int) @filemtime($this->root . '/' . $name) < $cutTs) {
                $n++;
                if (!$dryRun) {
                    Fs::removeTree($this->root . '/' . $name);
                }
            }
        }
        return ['staging_purged' => $n];
    }
}
