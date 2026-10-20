<?php

declare(strict_types=1);

namespace PJGuardian\Support;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** UTC ISO 8601 문자열(초 단위). DB 저장 형식. */
    public static function iso(\DateTimeInterface $t): string
    {
        return \DateTimeImmutable::createFromInterface($t)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
