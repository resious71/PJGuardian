<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/** 테스트용 고정 시계. advance()로 시간을 흘려보낸다. */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $iso = '2026-01-01T00:00:00Z')
    {
        $this->now = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
