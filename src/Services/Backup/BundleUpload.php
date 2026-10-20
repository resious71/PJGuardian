<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Support\Clock;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 묶음 올리기(P27B) — 다른 PC에서 만든 .pjgbak를 화면으로 올린다(큰 파일은 백업 폴더에 직접 복사하는 쪽이 기본 경로).
 * 프로토콜은 도구 패키지 스테이징(P20A ToolStaging)과 같다: init → 청크 PUT(X-Chunk-Sha256, 순서대로 이어 붙임, 재전송은 건너뜀) → complete.
 * 다른 점: 받는 상태를 DB가 아니라 파일(<work>/run/backup_upload/<id>/state.json)에 둔다 — 백업 목록·상태는 복원이 DB를 바꿔도 남아야 하므로.
 * 한도 [backup] upload_max_mb, 확장자·이름 규칙(BundleFormat::validName), 같은 이름이 있으면 거부, 디스크 여유 검사.
 * 다 받으면 백업 폴더로 옮기고 곁의 정보에 uploaded = true·검증 전으로 둔다 — **검증을 통과하기 전에는 복원할 수 없다**.
 */
final class BundleUpload
{
    public const CHUNK = 8 * 1024 * 1024;

    public function __construct(
        private readonly BackupPaths $paths,
        private readonly BackupService $backups,
        private readonly Clock $clock,
        private readonly int $maxBytes,
        private readonly \PJGuardian\Services\Settings\PathPolicy $policy,
    ) {
    }

    /** @param array<string, mixed> $data file_name, total_size @return array<string, mixed> */
    public function init(int $userId, array $data): array
    {
        $name = is_string($data['file_name'] ?? null) ? basename(str_replace('\\', '/', $data['file_name'])) : '';
        $size = $data['total_size'] ?? null;
        if (!BundleFormat::validName($name)) {
            throw new BackupException('bundle_name_invalid');
        }
        if (!is_int($size) || $size <= 0) {
            throw new BackupException('upload_size_invalid');
        }
        if ($size > $this->maxBytes) {
            throw new BackupException('upload_too_large', ['max_mb' => intdiv($this->maxBytes, 1048576)]);
        }
        if (is_file($this->paths->dir() . '/' . $name)) {
            throw new BackupException('bundle_exists');
        }
        $free = @disk_free_space(dirname($this->paths->runDir()));
        if ($free !== false && $free < 2 * $size + 64 * 1024 * 1024) {
            throw new BackupException('backup_disk_low', ['need_mb' => (int) ceil(2 * $size / 1048576), 'free_mb' => (int) floor($free / 1048576)]);
        }
        $id = bin2hex(random_bytes(16));
        $dir = $this->paths->uploadRoot() . '/' . $id;
        Fs::ensureDir($dir);
        touch($dir . '/bundle.part');
        $state = ['id' => $id, 'user_id' => $userId, 'file_name' => $name, 'total_size' => $size, 'chunk_size' => self::CHUNK,
            'total_chunks' => intdiv($size + self::CHUNK - 1, self::CHUNK), 'received' => 0, 'created_at' => SystemClock::iso($this->clock->now())];
        Fs::atomicWrite($dir . '/state.json', (string) json_encode($state));
        return $state;
    }

    /** @param resource $body @return array{index: int, received: int, next: int, total_chunks: int} */
    public function chunk(int $userId, string $id, string $index, string $sha, $body): array
    {
        $dir = $this->dir($id);
        $st = $this->state($id, $userId);
        if (preg_match('/^\d{1,7}$/D', $index) !== 1 || (int) $index >= $st['total_chunks']) {
            throw new BackupException('chunk_index_invalid');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1) {
            throw new BackupException('chunk_hash_invalid');
        }
        $n = (int) $index;
        $last = $st['total_chunks'] - 1;
        $expect = $n < $last ? $st['chunk_size'] : $st['total_size'] - $st['chunk_size'] * $last;
        $tmp = $dir . '/chunk.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = fopen($tmp, 'xb');
        $ctx = hash_init('sha256');
        $len = 0;
        try {
            while (!feof($body)) {
                $buf = fread($body, 1024 * 1024);
                if ($buf === false || $buf === '') {
                    break;
                }
                $len += strlen($buf);
                if ($len > $expect) {
                    throw new BackupException('chunk_length_invalid');
                }
                hash_update($ctx, $buf);
                fwrite($out, $buf);
            }
            fclose($out);
            $out = null;
            if ($len !== $expect) {
                throw new BackupException('chunk_length_invalid');
            }
            if (!hash_equals($sha, hash_final($ctx))) {
                throw new BackupException('chunk_hash_mismatch');
            }
            $lock = FileLock::acquire($dir . '/.lock');
            try {
                $cur = $this->state($id, $userId);
                $next = intdiv($cur['received'], $cur['chunk_size']);
                if ($n < $next) {
                    return ['index' => $n, 'received' => $cur['received'], 'next' => $next, 'total_chunks' => $cur['total_chunks']]; // 재전송
                }
                if ($n > $next) {
                    throw new BackupException('chunk_out_of_order', ['next' => $next]);
                }
                $in = fopen($tmp, 'rb');
                $dst = fopen($dir . '/bundle.part', 'ab');
                stream_copy_to_stream($in, $dst);
                fclose($in);
                fclose($dst);
                $cur['received'] += $len;
                Fs::atomicWrite($dir . '/state.json', (string) json_encode($cur));
                return ['index' => $n, 'received' => $cur['received'], 'next' => $n + 1, 'total_chunks' => $cur['total_chunks']];
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

    /** 다 받았으면 백업 폴더로 옮긴다(검증 전 상태) @return string 묶음 이름 */
    public function complete(int $userId, string $id): string
    {
        $dir = $this->dir($id);
        $lock = FileLock::acquire($dir . '/.lock');
        try {
            $st = $this->state($id, $userId);
            $part = $dir . '/bundle.part';
            clearstatcache(true, $part);
            if ($st['received'] !== $st['total_size'] || (int) filesize($part) !== $st['total_size']) {
                throw new BackupException('upload_incomplete');
            }
            $this->paths->prepare($this->policy);
            $target = $this->paths->dir() . '/' . $st['file_name'];
            if (is_file($target)) {
                throw new BackupException('bundle_exists');
            }
            if (!@rename($part, $target)) {
                throw new BackupException('backup_io_failed', [], '올린 묶음 옮기기 실패');
            }
            $this->backups->writeSidecar($st['file_name'], null, null, true);
        } finally {
            $lock->release();
        }
        BackupService::removeTree($dir);
        return $st['file_name'];
    }

    public function discard(int $userId, string $id): void
    {
        $this->state($id, $userId);
        BackupService::removeTree($this->dir($id));
    }

    private function dir(string $id): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            throw new BackupException('upload_not_found');
        }
        return $this->paths->uploadRoot() . '/' . $id;
    }

    /** @return array<string, mixed> 다른 사용자의 올리기는 없는 것과 같다 */
    private function state(string $id, int $userId): array
    {
        $d = json_decode((string) @file_get_contents($this->dir($id) . '/state.json'), true);
        if (!is_array($d) || (int) ($d['user_id'] ?? 0) !== $userId) {
            throw new BackupException('upload_not_found');
        }
        return $d;
    }
}
