<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;

/**
 * 백업 상태 파일 `<백업 폴더>/backup_state.json`(P27B). DB가 아니라 파일이다 — 복원이 DB를 통째로 바꿔도 백업 기록은 남아야 하므로.
 * 백업 폴더를 바꾸면 새 폴더에서 새로 시작한다(이전 폴더의 기록을 이어받지 않음).
 *
 *   last_success  {at, file, size, kind}            마지막 성공 백업
 *   last_failure  {at, code, kind}                  마지막 실패
 *   daily         {date, attempts, retry_at}        일일 백업: 마지막으로 끝낸(성공 또는 재시도까지 실패) KST 날짜, 그날 시도 수, 다시 시도 시각
 *   last_rehearsal {at, file, ok, ms, code}         마지막 복원 리허설
 *   alerts        {키: 마지막 알림 시각}             같은 사유 1시간 한 번(19.3), 리허설 기한 알림
 * 쓰기는 잠금 전용 파일 + 원자적 교체.
 */
final class BackupState
{
    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $raw = @file_get_contents($this->file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * 읽고 고치고 쓰기(잠금 안).
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed> 쓴 내용
     */
    public function update(\Closure $change): array
    {
        Fs::ensureDir(dirname($this->file));
        $lock = FileLock::acquire($this->file . '.lock');
        try {
            $data = $change($this->read());
            Fs::atomicWrite($this->file, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $data;
        } finally {
            $lock->release();
        }
    }
}
