<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

/**
 * 주기 분석 일정 계산(P26, 명세 18.2). 순수 함수 — 현재 시각은 인자로 받는다(시험은 시계 주입).
 *
 * 규칙
 * - 실행 시각은 KST(UTC+9 고정, 서머타임 없음) "HH:MM". 저장·비교는 UTC 초 단위.
 * - 처음 켜거나 간격·시각을 바꾸면: **바꾼 시점 이후 가장 가까운 지정 시각**(오늘 그 시각이 지났으면 내일). 직전 시각을 소급하지 않는다.
 * - 도래를 처리하면: 다음 = 도래 + N일. 워커가 꺼져 있어 여러 번 밀렸으면 **가장 최근 도래 1회만** 처리하고
 *   다음 = 그 도래 + N일(항상 현재 이후). 같은 시각 계열(앵커 + kN일)을 유지한다.
 * - N일 = N × 86,400초(KST는 서머타임이 없어 날짜 경계·월말·연말에도 같은 시각).
 */
final class ScheduleCalc
{
    public const KST_OFFSET = 9 * 3600;
    public const DAY = 86400;
    public const INTERVAL_MIN = 1;
    public const INTERVAL_MAX = 365;

    /** "HH:MM"(00:00~23:59) 형식이면 [시, 분], 아니면 null */
    public static function parseTime(mixed $v): ?array
    {
        if (!is_string($v) || preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])\z/', $v, $m) !== 1) {
            return null;
        }
        return [(int) $m[1], (int) $m[2]];
    }

    /** 바꾼 시점($now) 이후 가장 가까운 지정 시각(KST HH:MM). $now와 같은 초면 다음 날(소급·즉시 실행하지 않음) */
    public static function firstAfter(\DateTimeImmutable $now, string $runTime): \DateTimeImmutable
    {
        $hm = self::parseTime($runTime) ?? throw new \InvalidArgumentException('run_time 형식');
        $t = $now->getTimestamp();
        $kstDayStart = intdiv($t + self::KST_OFFSET, self::DAY) * self::DAY - self::KST_OFFSET; // KST 오늘 0시(UTC 초)
        $cand = $kstDayStart + $hm[0] * 3600 + $hm[1] * 60;
        if ($cand <= $t) {
            $cand += self::DAY;
        }
        return self::at($cand);
    }

    /**
     * 도래 처리: next_run_at(앵커)부터 N일 간격 계열에서 $now 이전(같거나 이전)의 가장 최근 도래와 그다음 시각.
     * $nextRunAt > $now면 아직 도래하지 않음 → null.
     *
     * @return array{due: \DateTimeImmutable, next: \DateTimeImmutable, missed: int}|null missed = 건너뛴(보충하지 않은) 도래 수
     */
    public static function due(\DateTimeImmutable $nextRunAt, int $intervalDays, \DateTimeImmutable $now): ?array
    {
        $anchor = $nextRunAt->getTimestamp();
        $t = $now->getTimestamp();
        if ($anchor > $t) {
            return null;
        }
        $step = max(self::INTERVAL_MIN, $intervalDays) * self::DAY;
        $k = intdiv($t - $anchor, $step);
        $due = $anchor + $k * $step;
        return ['due' => self::at($due), 'next' => self::at($due + $step), 'missed' => $k];
    }

    /** UTC ISO("Y-m-d\TH:i:s\Z") */
    public static function iso(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function parseIso(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    private static function at(int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
    }
}
