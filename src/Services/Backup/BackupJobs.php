<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Support\Clock;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 백업·리허설 작업 요청(P27B) — **파일 한 칸**(<work>/run/backup_job.json). 동시에 하나만(중복 방지), 복원 요청·진행 중에는 받지 않는다.
 * DB 표가 아닌 이유: 백업 묶음 DB에 "진행 중" 행이 들어가 복원 뒤 남지 않게, 그리고 복원이 DB를 바꿔도 작업 상태가 이어지게.
 * 워커가 요청을 보고 자식(tools/backup.php --job)을 띄우고, 자식이 진행(backup_progress.json)을 쓰고 취소 파일(backup_cancel)을 본다.
 *
 * 요청: {id, type: backup|rehearsal, kind(daily|manual|pre_upgrade), snapshots, file(리허설 대상), by, by_id, requested_at, started_at}
 */
final class BackupJobs
{
    public function __construct(private readonly BackupPaths $paths, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $job @return array<string, mixed> 넣은 요청 */
    public function request(array $job): array
    {
        Fs::ensureDir($this->paths->runDir());
        $lock = FileLock::acquire($this->paths->runDir() . '/backup_job.lock');
        try {
            if ($this->active() !== null) {
                throw new BackupException('backup_busy');
            }
            if (is_file($this->paths->restoreRequest()) || is_file($this->paths->maintenanceFlag())) {
                throw new BackupException('restore_in_progress');
            }
            $job = ['id' => bin2hex(random_bytes(6)), 'requested_at' => SystemClock::iso($this->clock->now()), 'started_at' => null] + $job;
            Fs::atomicWrite($this->paths->jobRequest(), (string) json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @unlink($this->paths->jobProgress());
            @unlink($this->paths->jobCancel());
            return $job;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed>|null */
    public function active(): ?array
    {
        $d = json_decode((string) @file_get_contents($this->paths->jobRequest()), true);
        return is_array($d) ? $d : null;
    }

    public function markStarted(): void
    {
        $j = $this->active();
        if ($j !== null) {
            $j['started_at'] = SystemClock::iso($this->clock->now());
            Fs::atomicWrite($this->paths->jobRequest(), (string) json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array{step: string, done: int, total: int, at: string}|null */
    public function progress(): ?array
    {
        $d = json_decode((string) @file_get_contents($this->paths->jobProgress()), true);
        return is_array($d) ? $d : null;
    }

    public function writeProgress(string $step, int $done, int $total): void
    {
        @Fs::atomicWrite($this->paths->jobProgress(), (string) json_encode(['step' => $step, 'done' => $done, 'total' => $total, 'at' => SystemClock::iso($this->clock->now())]));
    }

    public function cancel(): void
    {
        if ($this->active() !== null) {
            @file_put_contents($this->paths->jobCancel(), SystemClock::iso($this->clock->now()));
        }
    }

    public function cancelled(): bool
    {
        return is_file($this->paths->jobCancel());
    }

    public function finish(): void
    {
        @unlink($this->paths->jobRequest());
        @unlink($this->paths->jobProgress());
        @unlink($this->paths->jobCancel());
    }
}
