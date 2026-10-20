<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 유지보수 모드(P27B 복원 중). **DB가 아니라 파일 플래그** `<work>/run/maintenance.flag`(DB가 바뀌는 중이므로).
 *
 * - 켜져 있으면 웹은 DB를 열기 전에(MaintenanceMiddleware) 복원 상태 페이지·그 API만 내보내고 나머지는 503 + 안내(로그인 포함 — 택한 방식: 단순화).
 *   워커는 새 작업을 집지 않고 주기 스케줄러·일일 백업도 멈춘다.
 * - 복원 프로세스가 finally에서 끈다. 워커 시작 때와 화면에서 maintenance_stale_minutes보다 오래된 플래그를 경고한다(비정상 종료 흔적).
 */
final class MaintenanceMode
{
    public function __construct(private readonly string $flagFile, private readonly Clock $clock)
    {
    }

    /** @return array{reason: string, since: string, by: string, pid: int}|null */
    public function active(): ?array
    {
        if (!is_file($this->flagFile)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($this->flagFile), true);
        return is_array($d) ? $d + ['reason' => 'restore', 'since' => '', 'by' => '', 'pid' => 0] : ['reason' => 'restore', 'since' => '', 'by' => '', 'pid' => 0];
    }

    public function enter(string $reason, string $by): void
    {
        Fs::ensureDir(dirname($this->flagFile));
        Fs::atomicWrite($this->flagFile, (string) json_encode(['reason' => $reason, 'since' => SystemClock::iso($this->clock->now()), 'by' => $by, 'pid' => (int) getmypid()]));
    }

    public function leave(): void
    {
        @unlink($this->flagFile);
    }

    /** 켜진 지 몇 분(꺼져 있으면 null) */
    public function minutes(): ?int
    {
        $a = $this->active();
        if ($a === null) {
            return null;
        }
        $t = strtotime((string) $a['since']);
        return $t === false ? null : (int) floor(($this->clock->now()->getTimestamp() - $t) / 60);
    }
}
