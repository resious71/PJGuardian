<?php

declare(strict_types=1);

namespace PJGuardian\Services\Monitoring;

use PJGuardian\Support\Logger;

/** P30 기본 구현: 장애 이벤트를 경고 로그(logs/alert-YYYYMMDD.log)에 한 줄 남긴다. 이메일은 보내지 않는다(P30B). */
final class LogAlertSink implements AdminAlertSink
{
    public function __construct(private readonly Logger $alertLog)
    {
    }

    public function raised(array $event): void
    {
        $this->alertLog->warn('관리자 장애 이벤트', ['code' => $event['code'], 'level' => $event['level'], 'params' => $event['params'], 'event' => 'raised']);
    }
}
