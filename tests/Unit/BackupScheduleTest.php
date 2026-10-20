<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Backup\BackupSchedule;
use PJGuardian\Tests\TestCase;

/**
 * 자동 백업 예정 계산(P27E, 순수): 매일·매주·매월(없는 날짜는 말일, 윤년), 연말·연초·자정·요일 경계, 서머타임 시간대에서 중복·누락 없음.
 */
final class BackupScheduleTest extends TestCase
{
    private static function kst(string $mode, string $at = '02:30', string $week = 'sun', string $month = '1'): BackupSchedule
    {
        return new BackupSchedule($mode, $at, $week, $month, new \DateTimeZone('Asia/Seoul'));
    }

    /** 현지 시각 문자열 → UTC 시각 */
    private static function at(string $local, string $tz = 'Asia/Seoul'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone($tz));
    }

    private static function local(?\DateTimeImmutable $d, string $tz = 'Asia/Seoul'): ?string
    {
        return $d?->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i D');
    }

    public function testDailyPreviousAndNextAroundRunTimeAndMidnight(): void
    {
        $s = self::kst('daily');
        $this->assertSame('2026-10-09 02:30 Fri', self::local($s->previous(self::at('2026-10-10 02:29'))), '시각 1분 전 → 어제 예정');
        $this->assertSame('2026-10-10 02:30 Sat', self::local($s->previous(self::at('2026-10-10 02:30'))), '정각 = 예정(≤ 지금)');
        $this->assertSame('2026-10-11 02:30 Sun', self::local($s->next(self::at('2026-10-10 02:30'))), '다음은 지금보다 뒤');
        $this->assertSame('2026-10-10 02:30 Sat', self::local($s->next(self::at('2026-10-09 23:59'))), '자정 경계');
        $this->assertSame('2027-01-01 02:30 Fri', self::local($s->next(self::at('2026-12-31 03:00'))), '연말 → 연초');
        $s0 = self::kst('daily', '00:00');
        $this->assertSame('2026-10-10 00:00 Sat', self::local($s0->previous(self::at('2026-10-10 00:00'))));
        $this->assertSame('2026-10-09 00:00 Fri', self::local($s0->previous(self::at('2026-10-09 23:59'))));
        $this->assertSame('2026-10-09T17:30:00+00:00', $s->previous(self::at('2026-10-10 03:00'))?->format('c'), 'UTC로 돌려줌(KST 02:30 = 전날 17:30Z)');
    }

    public function testWeeklyOnChosenWeekdayAcrossWeekAndYearBoundary(): void
    {
        $s = self::kst('weekly', '02:30', 'sun');
        $this->assertSame('2026-10-04 02:30 Sun', self::local($s->previous(self::at('2026-10-10 12:00'))));
        $this->assertSame('2026-10-11 02:30 Sun', self::local($s->next(self::at('2026-10-10 12:00'))));
        $this->assertSame('2026-10-11 02:30 Sun', self::local($s->previous(self::at('2026-10-11 02:30'))), '그 요일 정각');
        $this->assertSame('2026-10-04 02:30 Sun', self::local($s->previous(self::at('2026-10-11 02:29'))), '그 요일이지만 시각 전');
        $mon = self::kst('weekly', '23:00', 'mon');
        $this->assertSame('2027-01-04 23:00 Mon', self::local($mon->next(self::at('2026-12-29 00:00'))), '연말 → 연초 첫 월요일');
        $this->assertSame('2026-12-28 23:00 Mon', self::local($mon->previous(self::at('2027-01-03 00:00'))));
        foreach (array_keys(BackupSchedule::DAYS) as $d) {
            $n = self::kst('weekly', '02:30', $d)->next(self::at('2026-10-10 12:00'));
            $this->assertSame(BackupSchedule::DAYS[$d], (int) $n?->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('N'), $d);
        }
    }

    public function testMonthlyMissingDaysUseLastDayIncludingLeapYears(): void
    {
        $d31 = self::kst('monthly', '02:30', 'sun', '31');
        $this->assertSame('2026-11-30 02:30 Mon', self::local($d31->next(self::at('2026-11-01 00:00'))), '30일까지인 달 → 30일');
        $this->assertSame('2027-02-28 02:30 Sun', self::local($d31->next(self::at('2027-02-01 00:00'))), '평년 2월 → 28일');
        $this->assertSame('2028-02-29 02:30 Tue', self::local($d31->next(self::at('2028-02-01 00:00'))), '윤년 2월 → 29일');
        $this->assertSame('2026-12-31 02:30 Thu', self::local($d31->next(self::at('2026-12-01 00:00'))));
        $d29 = self::kst('monthly', '02:30', 'sun', '29');
        $this->assertSame('2027-02-28 02:30 Sun', self::local($d29->next(self::at('2027-02-01 00:00'))), '29일 지정 평년 2월 → 28일');
        $this->assertSame('2028-02-29 02:30 Tue', self::local($d29->next(self::at('2028-02-01 00:00'))), '29일 지정 윤년 2월 → 29일');
        $this->assertSame('2027-03-29 02:30 Mon', self::local($d29->next(self::at('2027-02-28 03:00'))), '다음 달은 다시 29일');
        $last = self::kst('monthly', '02:30', 'sun', 'last');
        $this->assertSame('2026-10-31 02:30 Sat', self::local($last->next(self::at('2026-10-10 00:00'))), '말일');
        $this->assertSame('2026-09-30 02:30 Wed', self::local($last->previous(self::at('2026-10-10 00:00'))));
        $this->assertSame('2027-01-31 02:30 Sun', self::local($last->next(self::at('2026-12-31 03:00'))), '연말 말일 지난 뒤 → 1월 말일');
        $d1 = self::kst('monthly', '02:30', 'sun', '1');
        $this->assertSame('2027-01-01 02:30 Fri', self::local($d1->next(self::at('2026-12-15 00:00'))), '연초 1일');
        $this->assertSame('2026-12-01 02:30 Tue', self::local($d1->previous(self::at('2026-12-31 23:59'))));
        // 1년을 하루씩 훑어 31일 지정이 달마다 정확히 한 번
        $count = [];
        $day = new \DateTimeImmutable('2028-01-01', new \DateTimeZone('Asia/Seoul'));
        for ($i = 0; $i < 366; $i++) {
            if (($slot = $d31->slotOn($day->modify("+{$i} days"))) !== null) {
                $m = $slot->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m');
                $count[$m] = ($count[$m] ?? 0) + 1;
            }
        }
        $this->assertSame(12, count($count));
        $this->assertSame([1], array_values(array_unique($count)));
    }

    public function testDaylightSavingTransitionsHaveNoDuplicateOrMissingRuns(): void
    {
        // 미국 동부: 2026-03-08 02:00 → 03:00(02:30이 없음), 2026-11-01 01:00~02:00이 두 번
        foreach (['02:30', '01:30', '02:00', '03:00'] as $at) {
            $s = new BackupSchedule('daily', $at, 'sun', '1', new \DateTimeZone('America/New_York'));
            foreach ([['2026-03-05', '2026-03-12'], ['2026-10-29', '2026-11-05']] as [$from, $to]) {
                $now = self::at("{$from} 00:00", 'America/New_York');
                $end = self::at("{$to} 00:00", 'America/New_York');
                $dates = [];
                $last = null;
                while (($n = $s->next($now)) !== null && $n < $end) {
                    $this->assertTrue($last === null || $n > $last, "{$at}: 시각은 늘기만");
                    $dates[] = $n->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
                    $last = $n;
                    $now = $n; // 그 예정 직후부터 다음
                }
                $this->assertSame(count($dates), count(array_unique($dates)), "{$at} {$from}: 하루 두 번 없음");
                $this->assertSame(7, count($dates), "{$at} {$from}: 7일에 7번(빠짐 없음) — " . implode(',', $dates));
            }
        }
        // 사라지는 시각은 앞으로 밀린다(그날 한 번), 두 번 오는 시각은 그중 하나만
        $gap = new BackupSchedule('daily', '02:30', 'sun', '1', new \DateTimeZone('America/New_York'));
        $this->assertSame('2026-03-08 03:30 EDT', $gap->next(self::at('2026-03-08 00:00', 'America/New_York'))?->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i T'));
        $dup = new BackupSchedule('daily', '01:30', 'sun', '1', new \DateTimeZone('America/New_York'));
        $first = $dup->next(self::at('2026-11-01 00:00', 'America/New_York'));
        $this->assertSame('2026-11-01 01:30', $first?->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i'), '두 번 오는 01:30 중 하나(PHP는 표준시 쪽)');
        $this->assertSame('2026-11-02', $dup->next($first)?->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d'), '같은 날 다른 01:30에 다시 실행하지 않음');
        // 반대쪽 반구(시드니): 2026-04-05 03:00 → 02:00, 2026-10-04 02:00 → 03:00
        $syd = new BackupSchedule('weekly', '02:30', 'sun', '1', new \DateTimeZone('Australia/Sydney'));
        $this->assertSame('2026-04-05', $syd->next(self::at('2026-04-04 12:00', 'Australia/Sydney'))?->setTimezone(new \DateTimeZone('Australia/Sydney'))->format('Y-m-d'));
        $this->assertSame('2026-10-04 03:30', $syd->next(self::at('2026-10-03 12:00', 'Australia/Sydney'))?->setTimezone(new \DateTimeZone('Australia/Sydney'))->format('Y-m-d H:i'));
    }

    public function testKeyStaleHoursAndValidation(): void
    {
        $this->assertSame('daily@02:30', self::kst('daily', '02:30', 'mon', '15')->key(), '쓰이지 않는 요일·날짜는 표지에 없음');
        $this->assertSame('weekly:mon@02:30', self::kst('weekly', '02:30', 'mon', '15')->key());
        $this->assertSame('monthly:last@04:00', self::kst('monthly', '04:00', 'mon', 'last')->key());
        $this->assertSame(36, self::kst('daily')->staleHours(36));
        $this->assertSame(192, self::kst('weekly')->staleHours(36), '매주 8일');
        $this->assertSame(792, self::kst('monthly')->staleHours(36), '매월 33일');
        $this->assertSame(1000, self::kst('monthly')->staleHours(1000), '설정값이 더 크면 설정값');
        foreach ([['hourly', '02:30', 'sun', '1'], ['daily', '24:00', 'sun', '1'], ['daily', '2:30', 'sun', '1'], ['weekly', '02:30', 'sunday', '1'],
            ['monthly', '02:30', 'sun', '0'], ['monthly', '02:30', 'sun', '32'], ['monthly', '02:30', 'sun', "1\n"]] as $bad) {
            $this->assertThrows(\InvalidArgumentException::class, fn () => self::kst(...$bad));
        }
    }
}
