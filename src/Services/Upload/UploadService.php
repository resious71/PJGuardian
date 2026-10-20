<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 청크 업로드(명세 5.1, 5.4, 19.3, 19.10). 프로토타입(tools/proto/chunk_upload.php)을 제품 코드로 다시 쓴 것.
 *
 * - 프로젝트 접근은 라우트의 RequireProjectAccess가 먼저 판정한다(init은 프로젝트 id, 나머지는 upload_id → 소유 프로젝트).
 *   여기서는 **업로드 소유자**를 확인한다: 다른 사용자의 upload_id는 관리자라도 404 upload_not_found(존재를 숨김).
 * - 청크는 원시 본문으로 받아 1MB씩 읽으며 SHA-256을 계산하고, 임시 파일에 쓴 뒤 해시가 맞으면 <n>.part로 원자적 교체.
 *   같은 청크 재전송은 덮어쓴다(멱등).
 * - complete는 조립하지 않는다: 모든 청크를 확인하고 assemble_pending으로 바꿔 202. 조립은 UploadAssembler(워커, P14)가 한다.
 * - 경로는 서버가 만든 upload_id와 정수 청크 번호로만 만든다(UploadFiles). 파일명은 표시용.
 * - 상태 전이는 docs/db_schema.md 8.1.
 */
final class UploadService
{
    private const READ_BYTES = 1024 * 1024;
    /** 사용자당 동시 진행 업로드로 세는 상태(명세 19.10, P13): 전송 중·조립 대기·조립 중·해제 대기. 확인 대기는 세지 않는다 */
    public const ACTIVE_FOR_LIMIT = ['receiving', 'assemble_pending', 'assembling', 'extracting'];

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly UploadSettings $settings,
        private readonly UploadFiles $files,
        private readonly DiskGuard $disk,
        private readonly Logger $logger,
        private readonly ?string $workRoot = null,
    ) {
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** 라우트 접근 판정용: upload_id → 소유 프로젝트 id(형식이 틀리거나 없으면 null) */
    public function projectIdOf(string $id): ?int
    {
        if (!UploadFiles::validId($id)) {
            return null;
        }
        $pid = $this->db->value('SELECT project_id FROM uploads WHERE id = ?', [$id]);
        return $pid === null ? null : (int) $pid;
    }

    /**
     * 내 업로드 행. 형식이 틀리거나, 없거나, 다른 사용자의 것이면 404(같은 응답). 경고 확인(UploadProcessor::confirm)도 이것으로 본인 확인.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function owned(array $user, string $id): array
    {
        $row = UploadFiles::validId($id) ? $this->db->one('SELECT * FROM uploads WHERE id = ?', [$id]) : null;
        if ($row === null || (int) $row['user_id'] !== (int) $user['id']) {
            throw new ApiException(404, 'upload_not_found');
        }
        return $row;
    }

    private static function codeMessage(string $code): string
    {
        $key = 'error.' . $code;
        return Lang::has($key) ? Lang::get($key) : Lang::get('error.unknown');
    }

    private static function chunkLength(array $row, int $n): int
    {
        $last = (int) $row['total_chunks'] - 1;
        return $n < $last ? (int) $row['chunk_size'] : (int) $row['total_size'] - (int) $row['chunk_size'] * $last;
    }

    /**
     * 업로드 시작. 한도(413) → 사용자당 동시 업로드(429) → 디스크 사전 검사(507) → 행과 폴더 생성.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data file_name, total_size
     * @return array{upload_id:string, chunk_size:int, total_chunks:int, max_bytes:int}
     */
    public function init(array $user, int $projectId, array $data): array
    {
        $size = $data['total_size'] ?? null;
        if (!is_int($size) || $size <= 0) {
            throw new ApiException(400, 'invalid_upload_size');
        }
        if ($size > $this->settings->maxBytes) {
            throw new ApiException(413, 'upload_too_large', ['max' => intdiv($this->settings->maxBytes, 1024 * 1024)]);
        }
        $name = $data['file_name'] ?? null;
        $name = is_string($name) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $name)) : '';
        if ($name === '') {
            throw new ApiException(400, 'invalid_file_name');
        }
        $name = mb_substr($name, 0, 255, 'UTF-8');
        $chunk = $this->settings->chunkBytes;
        $chunks = intdiv($size + $chunk - 1, $chunk);
        $reserve = $this->disk->reserveFor($size);
        $id = bin2hex(random_bytes(16));
        $now = $this->now();
        Fs::ensureDir($this->files->root());

        $this->db->transaction(function () use ($user, $projectId, $id, $name, $size, $chunk, $chunks, $reserve, $now): void {
            $in = implode(',', array_fill(0, count(self::ACTIVE_FOR_LIMIT), '?'));
            $active = (int) $this->db->value("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND status IN ({$in})", [(int) $user['id'], ...self::ACTIVE_FOR_LIMIT]);
            if ($active >= $this->settings->maxConcurrentPerUser) {
                throw new ApiException(429, 'too_many_uploads', ['max' => $this->settings->maxConcurrentPerUser]);
            }
            // 예약 합계를 읽는 것과 행 추가를 한 쓰기 트랜잭션에서(동시에 시작한 업로드가 서로의 예약을 보게)
            $this->disk->check($this->files->root(), $reserve);
            $this->db->execute(
                'INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, status, reserved_bytes, last_activity_at, created_at)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, 'receiving', ?, ?, ?)",
                [$id, (int) $user['id'], $projectId, $name, $size, $chunk, $chunks, $reserve, $now, $now],
            );
            if (!@mkdir($this->files->dir($id), 0700)) {
                throw new \RuntimeException('업로드 폴더를 만들 수 없음');
            }
        });
        $this->logger->info('업로드 시작', ['upload_id' => $id, 'project_id' => $projectId, 'total_size' => $size, 'chunks' => $chunks, 'reserved' => $reserve]);
        return ['upload_id' => $id, 'chunk_size' => $chunk, 'total_chunks' => $chunks, 'max_bytes' => $this->settings->maxBytes];
    }

    /**
     * 청크 하나. 번호·해시 형식·길이 검사 → 1MB씩 읽어 해시 계산하며 임시 파일에 씀 → 일치하면 <n>.part로 교체.
     *
     * @param array<string, mixed> $user
     * @param resource $body
     * @return array{index:int, received:int, total_chunks:int}
     */
    public function chunk(array $user, string $id, mixed $index, mixed $sha, $body, ?int $contentLength): array
    {
        $row = $this->owned($user, $id);
        if ($row['status'] !== 'receiving') {
            throw new ApiException(409, 'upload_not_receiving');
        }
        if (!is_string($index) || preg_match('/^\d{1,7}$/', $index) !== 1 || (int) $index >= (int) $row['total_chunks']) {
            throw new ApiException(400, 'invalid_chunk_index');
        }
        $n = (int) $index;
        $sha = is_string($sha) ? strtolower(trim($sha)) : '';
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            throw new ApiException(400, 'invalid_chunk_hash');
        }
        $expect = self::chunkLength($row, $n);
        if ($contentLength !== null && $contentLength !== $expect) {
            throw new ApiException(400, 'invalid_chunk_length', ['expected' => $expect]);
        }

        $tmp = $this->files->part($id, $n) . '.uploading.' . bin2hex(random_bytes(4));
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
                if (fwrite($out, $buf) !== strlen($buf)) {
                    throw new \RuntimeException('청크를 쓸 수 없음');
                }
            }
            fclose($out);
            $out = null;
            if ($len !== $expect) {
                throw new ApiException(400, 'invalid_chunk_length', ['expected' => $expect]);
            }
            $actual = hash_final($ctx);
            if (!hash_equals($sha, $actual)) {
                throw new ApiException(422, 'chunk_hash_mismatch');
            }
            // 교체는 잠금 안에서: complete·abort와 겹치지 않게, 그 사이 상태가 바뀌었으면 거부
            $lock = FileLock::acquire($this->files->lock($id));
            try {
                if ($this->db->value('SELECT status FROM uploads WHERE id = ?', [$id]) !== 'receiving') {
                    throw new ApiException(409, 'upload_not_receiving');
                }
                if (!@rename($tmp, $this->files->part($id, $n))) {
                    throw new \RuntimeException('청크를 저장할 수 없음');
                }
                Fs::atomicWrite($this->files->partHash($id, $n), $actual);
                $this->db->execute('UPDATE uploads SET last_activity_at = ? WHERE id = ?', [$this->now(), $id]);
            } finally {
                $lock->release();
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        return ['index' => $n, 'received' => count($this->files->receivedChunks($id)), 'total_chunks' => (int) $row['total_chunks']];
    }

    /**
     * 이어 올리기용: 받은 청크 번호와 각 SHA-256(브라우저가 같은 청크면 건너뜀).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function status(array $user, string $id): array
    {
        $row = $this->owned($user, $id);
        $received = [];
        if ($row['status'] === 'receiving') {
            foreach ($this->files->receivedChunks($id) as $n) {
                $received[] = ['index' => $n, 'sha256' => trim((string) @file_get_contents($this->files->partHash($id, $n)))];
            }
        }
        return [
            'upload_id' => $id,
            'status' => (string) $row['status'],
            'total_size' => (int) $row['total_size'],
            'chunk_size' => (int) $row['chunk_size'],
            'total_chunks' => (int) $row['total_chunks'],
            'received' => $received,
        ];
    }

    /**
     * 모든 청크가 있으면 assemble_pending(조립 대기). 조립은 하지 않는다.
     *
     * @param array<string, mixed> $user
     * @return array{upload_id:string, status:string}
     */
    public function complete(array $user, string $id): array
    {
        $this->owned($user, $id);
        $lock = FileLock::acquire($this->files->lock($id));
        try {
            $row = $this->owned($user, $id);
            if ($row['status'] !== 'receiving') {
                throw new ApiException(409, 'upload_not_receiving');
            }
            $have = array_flip($this->files->receivedChunks($id));
            $missing = 0;
            for ($n = 0; $n < (int) $row['total_chunks']; $n++) {
                clearstatcache(true, $this->files->part($id, $n));
                if (!isset($have[$n]) || filesize($this->files->part($id, $n)) !== self::chunkLength($row, $n)) {
                    $missing++;
                }
            }
            if ($missing > 0) {
                throw new ApiException(409, 'chunks_missing', ['n' => $missing]);
            }
            $this->db->execute("UPDATE uploads SET status = 'assemble_pending', last_activity_at = ? WHERE id = ? AND status = 'receiving'", [$this->now(), $id]);
        } finally {
            $lock->release();
        }
        $this->logger->info('업로드 수신 완료(조립 대기)', ['upload_id' => $id]);
        return ['upload_id' => $id, 'status' => 'assemble_pending'];
    }

    /**
     * 사용자 취소: 받는 중(receiving) 또는 확인 대기(needs_confirmation, P13)만. 업로드 폴더와 해제 결과(작업 디렉토리)를 지우고 aborted(예약 0).
     *
     * @param array<string, mixed> $user
     * @return array{upload_id:string, status:string}
     */
    public function abort(array $user, string $id): array
    {
        $this->owned($user, $id);
        $lock = FileLock::acquire($this->files->lock($id));
        try {
            $row = $this->owned($user, $id);
            if (!in_array($row['status'], ['receiving', 'needs_confirmation'], true)) {
                throw new ApiException(409, 'upload_not_abortable');
            }
            $this->db->execute("UPDATE uploads SET status = 'aborted', reserved_bytes = 0, work_dir = NULL, last_activity_at = ? WHERE id = ?", [$this->now(), $id]);
        } finally {
            $lock->release();
        }
        $work = $row['work_dir'] ?? null;
        if (is_string($work) && preg_match('/^[0-9a-f]{8}$/', $work) === 1 && $this->workRoot !== null && !Fs::removeTree($this->workRoot . '/' . $work)) {
            $this->logger->warn('취소한 업로드의 작업 디렉토리를 지우지 못함(정리 작업이 다시 시도)', ['upload_id' => $id, 'work_dir' => $work]);
        }
        if (!$this->files->remove($id)) {
            $this->logger->warn('취소한 업로드 폴더를 지우지 못함(정리 작업이 다시 시도)', ['upload_id' => $id]);
        }
        $this->logger->info('업로드 취소', ['upload_id' => $id]);
        return ['upload_id' => $id, 'status' => 'aborted'];
    }

    /**
     * 업로드 상태(화면이 폴링). 경고와 연결된 분석은 P13에서 채운다.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    /**
     * 업로드 내용 요약(P21C): 해제 결과의 숫자만(경로·폴더 이름 같은 내부 값은 넣지 않는다). previous는 같은 사용자·같은 프로젝트의 직전 업로드와의 비교.
     *
     * @param array<string, mixed> $s extract.json
     * @return array<string, mixed>
     */
    private static function publicSummary(array $s): array
    {
        $prev = is_array($s['previous'] ?? null) ? $s['previous'] : null;
        return [
            'files' => (int) ($s['files'] ?? 0), 'bytes' => (int) ($s['bytes'] ?? 0), 'excluded' => (int) ($s['skipped'] ?? 0),
            'targets' => (int) ($s['targets'] ?? 0),
            'targets_by_extension' => is_array($s['targets_by_extension'] ?? null) ? array_map('intval', $s['targets_by_extension']) : null,
            'config_skipped' => (int) ($s['config_skipped'] ?? 0),
            'top_folder_removed' => ($s['stripped_prefix'] ?? '') !== '',
            'previous' => $prev === null ? null : ['same' => (bool) $prev['same'], 'size' => (int) $prev['size'], 'size_diff' => (int) $prev['size_diff'], 'created_at' => (string) $prev['created_at']],
        ];
    }

    public function get(array $user, string $id): array
    {
        $row = $this->owned($user, $id);
        $warnings = json_decode((string) $row['warnings'], true);
        $warnings = is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : [];
        $language = (string) $this->db->value('SELECT language FROM projects WHERE id = ?', [(int) $row['project_id']]);
        // 해제 요약(해제가 끝난 업로드만): uploads.summary(P21C, 014) → 없으면 extract.json(이전 업로드). 경고가 없어도 화면에 내용 요약으로 보여 준다
        $summary = json_decode((string) ($row['summary'] ?? ''), true);
        if (!is_array($summary)) {
            $summary = json_decode((string) @file_get_contents($this->files->dir($id) . '/extract.json'), true);
        }
        $analysisId = $this->db->value('SELECT id FROM analyses WHERE upload_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        $code = $row['error_code'];
        return [
            'upload_id' => $id,
            'project_id' => (int) $row['project_id'],
            'file_name' => (string) $row['file_name'],
            'total_size' => (int) $row['total_size'],
            'total_chunks' => (int) $row['total_chunks'],
            'received_chunks' => $row['status'] === 'receiving' ? count($this->files->receivedChunks($id)) : (int) $row['total_chunks'],
            'status' => (string) $row['status'],
            'sha256' => $row['sha256'],
            'error_code' => $row['error_code'],
            // 사용자용 문구(내부 경로·예외 메시지는 담지 않는다). 저장된 문구가 없으면 오류 코드의 문구
            'error_message' => $row['error_code'] === null ? null : (string) ($row['error_message'] ?? self::codeMessage((string) $row['error_code'])),
            'error_guide' => is_string($code) && Lang::has('upload.error.' . $code) ? 'upload.error.' . $code : null,
            // P19: 원인·조치·가이드 키(ErrorCatalog). error_message는 항목 이름 같은 상세가 붙은 한 문장(P13)
            'error' => is_string($code) ? ErrorCatalog::describe('upload', $code) : null,
            'warnings' => $warnings,
            'warning_messages' => UploadProcessor::warningMessages($warnings, $language, $this->settings, is_array($summary) ? $summary : null),
            'summary' => is_array($summary) ? self::publicSummary($summary) : null,
            'confirmed_at' => $row['confirmed_at'],
            'analysis_id' => $analysisId === null ? null : (int) $analysisId,
            // P20R: 그 분석이 성공했으면 Run 번호
            'run' => $analysisId === null ? null : (($n = $this->db->value('SELECT run_number FROM analyses WHERE id = ?', [(int) $analysisId])) === null ? null : (int) $n),
            'created_at' => (string) $row['created_at'],
            'last_activity_at' => (string) $row['last_activity_at'],
        ];
    }
}
