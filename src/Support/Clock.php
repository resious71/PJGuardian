<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 현재 시각 공급자. 테스트에서는 FixedClock을 주입한다.
 *
 * 규약(docs/architecture.md "시간"):
 * - 내부와 DB는 항상 UTC. DB에는 ISO 8601 문자열(Clock::iso, 예: 2026-10-02T13:45:00Z)로 저장한다.
 * - KST 등 표시 시간대로의 변환은 화면 계층에서만 한다(app.display_timezone).
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
