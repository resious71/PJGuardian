<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Support\Config;

/**
 * 자동 백업 예정 시각 계산(P27E, 순수 — 지금 시각은 호출하는 쪽이 Clock으로 넘긴다).
 *
 * 규칙(표시 시간대 기준):
 *   - daily: 매일 실행 시각, weekly: 지정 요일의 실행 시각, monthly: 지정 날짜(1~31 또는 last)의 실행 시각 — 그 달에 없는 날짜는 그 달 마지막 날.
 *   - 예정은 "현지 날짜 하나에 한 번"으로 정한다. 그 날짜의 실행 시각을 현지 시간대로 만들므로 서머타임으로 시각이 사라지는 날은
 *     PHP 규칙대로 앞으로 밀린 시각(예: 02:30 → 03:30), 두 번 오는 날은 PHP가 고른 하나(표준시 쪽)뿐이다 — 날짜마다 한 번이라 중복·누락이 없다.
 *   - 판단은 UTC 시각(타임스탬프)으로 비교한다.
 */
final class BackupSchedule
{
    public const MODES = ['daily', 'weekly', 'monthly'];
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    /** 예정을 찾는 최대 날짜 수(매월 말일도 두 달 안에 하나는 있다) */
    private const SEARCH_DAYS = 400;

    private readonly int $hour;
    private readonly int $minute;

    public function __construct(
        public readonly string $mode,
        public readonly string $at,
        public readonly string $weeklyDay,
        public readonly string $monthlyDay,
        private readonly \DateTimeZone $tz,
    ) {
        if (!in_array($mode, self::MODES, true) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $at, $m) !== 1 || !isset(self::DAYS[$weeklyDay])
            || ($monthlyDay !== 'last' && (preg_match('/^\d{1,2}$/D', $monthlyDay) !== 1 || (int) $monthlyDay < 1 || (int) $monthlyDay > 31))) {
            throw new \InvalidArgumentException('자동 백업 주기 값');
        }
        $this->hour = (int) $m[1];
        $this->minute = (int) $m[2];
    }

    public static function fromConfig(Config $config): self
    {
        return new self((string) $config->get('backup.schedule_mode'), (string) $config->get('backup.daily_at'), (string) $config->get('backup.weekly_day'),
            (string) $config->get('backup.monthly_day'), new \DateTimeZone((string) $config->get('app.display_timezone')));
    }

    /** 규칙 표지(방식·시각·쓰이는 날짜만) — 바뀌면 지난 예정은 이미 지난 것으로 본다 */
    public function key(): string
    {
        return match ($this->mode) {
            'daily' => "daily@{$this->at}",
            'weekly' => "weekly:{$this->weeklyDay}@{$this->at}",
            default => "monthly:{$this->monthlyDay}@{$this->at}",
        };
    }

    /** 가장 최근 예정(≤ 지금). 없으면 null(400일 안에 없을 수 없지만 방어) */
    public function previous(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $ts = $now->getTimestamp();
        $day = $now->setTimezone($this->tz)->setTime(0, 0);
        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            $slot = $this->slotOn($day->modify("-{$i} days"));
            if ($slot !== null && $slot->getTimestamp() <= $ts) {
                return $slot;
            }
        }
        return null;
    }

    /** 다음 예정(> 지금) */
    public function next(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $ts = $now->getTimestamp();
        $day = $now->setTimezone($this->tz)->setTime(0, 0);
        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            $slot = $this->slotOn($day->modify("+{$i} days"));
            if ($slot !== null && $slot->getTimestamp() > $ts) {
                return $slot;
            }
        }
        return null;
    }

    /** 이 현지 날짜가 예정일이면 그 날의 실행 시각(UTC), 아니면 null */
    public function slotOn(\DateTimeImmutable $localDay): ?\DateTimeImmutable
    {
        $y = (int) $localDay->format('Y');
        $mo = (int) $localDay->format('n');
        $d = (int) $localDay->format('j');
        $match = match ($this->mode) {
            'daily' => true,
            'weekly' => (int) $localDay->format('N') === self::DAYS[$this->weeklyDay],
            default => $d === $this->monthlyDate($y, $mo),
        };
        if (!$match) {
            return null;
        }
        // 현지 시간대로 그 날의 실행 시각 — 서머타임 틈이면 PHP가 앞으로 민다(하루 한 번은 그대로)
        $local = (new \DateTimeImmutable('now', $this->tz))->setDate($y, $mo, $d)->setTime($this->hour, $this->minute);
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /** 매월 실행 날짜: 지정 날짜, 그 달에 없으면(31일·29~31일의 2월) 마지막 날, last면 마지막 날 */
    public function monthlyDate(int $year, int $month): int
    {
        $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        return $this->monthlyDay === 'last' ? $last : min((int) $this->monthlyDay, $last);
    }

    /** 주기 길이(일) — "오래됨" 기준·디스크 추정용 */
    public function periodDays(): float
    {
        return match ($this->mode) {
            'daily' => 1.0,
            'weekly' => 7.0,
            default => 30.44,
        };
    }

    /** "백업이 오래됨" 기준(시간): daily = 설정값, weekly = 8일, monthly = 33일(설정값이 더 크면 설정값) */
    public function staleHours(int $configured): int
    {
        return match ($this->mode) {
            'daily' => $configured,
            'weekly' => max($configured, 8 * 24),
            default => max($configured, 33 * 24),
        };
    }
}
