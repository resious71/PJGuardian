<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;

/**
 * 업그레이드 유지보수 모드의 화면 상태(P32B, 명세 19.4): 켜짐 배너와 사전 공지 배너. 설정 [maintenance] enabled·message·notice_at(표시 시간대 "YYYY-MM-DD HH:MM").
 * 켜기 직전 백업·쓰기 차단·워커 정지는 SettingsService·UpgradeMaintenanceMiddleware·Worker가 각각 한다(이 클래스는 읽기만).
 */
final class UpgradeMaintenance
{
    /** @return array{active: bool, message: string, notice: ?string}|null 보일 배너가 없으면 null */
    public static function banner(Config $config, Clock $clock): ?array
    {
        $active = $config->bool('maintenance.enabled');
        $message = trim((string) ($config->get('maintenance.message') ?? ''));
        $notice = null;
        $at = $config->get('maintenance.notice_at');
        if (!$active && is_string($at) && $at !== '') {
            $tz = new \DateTimeZone($config->string('app.display_timezone'));
            $when = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $at, $tz);
            // 예정 시각이 지나지 않았을 때만 공지(지난 공지는 저절로 사라짐)
            if ($when !== false && $when > $clock->now()) {
                $notice = $at;
            }
        }
        if (!$active && $notice === null) {
            return null;
        }
        return ['active' => $active, 'message' => $message, 'notice' => $notice];
    }
}
