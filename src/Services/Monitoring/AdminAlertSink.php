<?php

declare(strict_types=1);

namespace PJGuardian\Services\Monitoring;

/**
 * 관리자 장애 이벤트를 받는 곳(P30 — 이메일을 붙일 한 지점, docs/monitoring.md 4절).
 * SystemHealth가 경고 하나가 **정상 → 경고(또는 실패)** 로 바뀔 때만 부른다(같은 경고가 이어지는 동안은 다시 부르지 않음).
 * P30의 구현은 LogAlertSink(경고 로그) 하나. P30B가 이메일 구현(SMTP·같은 이벤트 1시간 1회 제한)을 여기에 더한다.
 */
interface AdminAlertSink
{
    /**
     * @param array{code:string, level:string, params:array<string, scalar|null>, at:string} $event 비밀·서버 경로·사용자 입력 원문 없음
     */
    public function raised(array $event): void;
}
