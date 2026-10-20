<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Schedule\ScheduleCalc;
use PJGuardian\Services\Schedule\ScheduleRetry;
use PJGuardian\Tests\TestCase;

/**
 * 주기 분석 일정 계산(P26, 명세 18.2): 실행 시각 형식, 바꾼 시점 이후 가장 가까운 지정 시각(KST 고정, 날짜·월말·연말 경계),
 * 도래 계산(간격 1·7·365일, 밀린 일정은 가장 최근 도래 1회만, 다음은 항상 현재 이후), 재시도 판정 표.
 */
final class ScheduleCalcTest extends TestCase
{
    private static function at(string $iso): \DateTimeImmutable
    {
        return ScheduleCalc::parseIso($iso);
    }

    public function testRunTimeFormat(): void
    {
        foreach (['00:00' => [0, 0], '03:00' => [3, 0], '09:05' => [9, 5], '23:59' => [23, 59], '12:30' => [12, 30]] as $in => $want) {
            $this->assertSame($want, ScheduleCalc::parseTime($in), $in);
        }
        foreach (['24:00', '3:00', '03:0', '03:60', '0300', ' 03:00', '03:00 ', '', '03-00', '٣:٠٠', "03:00\n", 300, null, '99:99', '-1:00'] as $bad) {
            $this->assertSame(null, ScheduleCalc::parseTime($bad), var_export($bad, true));
        }
    }

    public function testFirstAfterIsNextOccurrenceInKst(): void
    {
        // 지금 = KST 2026-10-02 21:00(UTC 12:00)
        $now = self::at('2026-10-02T12:00:00Z');
        $cases = [
            '22:00' => '2026-10-02T13:00:00Z', // 오늘 뒤 시각 → 오늘
            '21:01' => '2026-10-02T12:01:00Z',
            '21:00' => '2026-10-03T12:00:00Z', // 바로 지금 → 내일(소급·즉시 실행하지 않음)
            '20:59' => '2026-10-03T11:59:00Z', // 오늘 지난 시각 → 내일
            '03:00' => '2026-10-02T18:00:00Z', // KST 내일 03:00 = UTC 오늘 18:00
            '00:00' => '2026-10-02T15:00:00Z', // KST 자정
            '08:59' => '2026-10-02T23:59:00Z',
            '09:00' => '2026-10-03T00:00:00Z', // KST 09:00 = UTC 00:00 (날짜 경계)
        ];
        foreach ($cases as $time => $want) {
            $this->assertSame($want, ScheduleCalc::iso(ScheduleCalc::firstAfter($now, $time)), $time);
        }
        // UTC로는 전날이지만 KST로는 이미 다음 날인 시각(UTC 16:00 = KST 다음 날 01:00)
        $this->assertSame('2026-10-02T17:00:00Z', ScheduleCalc::iso(ScheduleCalc::firstAfter(self::at('2026-10-02T16:00:00Z'), '02:00')));
        $this->assertSame('2026-10-03T15:30:00Z', ScheduleCalc::iso(ScheduleCalc::firstAfter(self::at('2026-10-02T16:00:00Z'), '00:30')));
        // 월말·연말·윤년
        $this->assertSame('2026-10-31T18:00:00Z', ScheduleCalc::iso(ScheduleCalc::firstAfter(self::at('2026-10-31T12:00:00Z'), '03:00')), '10월 31일 → KST 11월 1일 03:00');
        $this->assertSame('2026-12-31T18:00:00Z', ScheduleCalc::iso(ScheduleCalc::firstAfter(self::at('2026-12-31T14:59:59Z'), '03:00')), '연말 → KST 1월 1일');
        $this->assertSame('2028-02-28T15:30:00Z', ScheduleCalc::iso(ScheduleCalc::firstAfter(self::at('2028-02-28T15:00:00Z'), '00:30')), '윤년 2월 29일 KST');
        $this->assertThrows(\InvalidArgumentException::class, fn () => ScheduleCalc::firstAfter($now, '25:00'));
    }

    public function testDueKeepsSeriesAndRunsLatestOnlyOnce(): void
    {
        $anchor = self::at('2026-10-05T18:00:00Z'); // KST 10-06 03:00
        // 아직 도래 전
        $this->assertSame(null, ScheduleCalc::due($anchor, 7, self::at('2026-10-05T17:59:59Z')));
        // 정확히 도래
        $d = ScheduleCalc::due($anchor, 7, self::at('2026-10-05T18:00:00Z'));
        $this->assertSame(['2026-10-05T18:00:00Z', '2026-10-12T18:00:00Z', 0], [ScheduleCalc::iso($d['due']), ScheduleCalc::iso($d['next']), $d['missed']]);
        // 1분 늦은 틱(워커가 1분마다 확인)
        $d = ScheduleCalc::due($anchor, 7, self::at('2026-10-05T18:01:00Z'));
        $this->assertSame(['2026-10-05T18:00:00Z', '2026-10-12T18:00:00Z', 0], [ScheduleCalc::iso($d['due']), ScheduleCalc::iso($d['next']), $d['missed']]);
        // 워커가 3주 반 꺼져 있었음 → 가장 최근 도래 1회만, 다음은 현재 이후, 같은 요일·시각 계열 유지
        $d = ScheduleCalc::due($anchor, 7, self::at('2026-10-30T00:00:00Z'));
        $this->assertSame(['2026-10-26T18:00:00Z', '2026-11-02T18:00:00Z', 3], [ScheduleCalc::iso($d['due']), ScheduleCalc::iso($d['next']), $d['missed']]);
        // 간격 1일: 5일 밀림
        $d = ScheduleCalc::due($anchor, 1, self::at('2026-10-10T19:00:00Z'));
        $this->assertSame(['2026-10-10T18:00:00Z', '2026-10-11T18:00:00Z', 5], [ScheduleCalc::iso($d['due']), ScheduleCalc::iso($d['next']), $d['missed']]);
        // 간격 365일(윤년 무관하게 365 × 24시간)
        $d = ScheduleCalc::due($anchor, 365, self::at('2026-10-05T18:00:30Z'));
        $this->assertSame('2027-10-05T18:00:00Z', ScheduleCalc::iso($d['next']));
        // 월말·연말 경계에서도 KST 같은 시각(서머타임 없음)
        $d = ScheduleCalc::due(self::at('2026-12-28T18:00:00Z'), 7, self::at('2026-12-28T18:00:00Z'));
        $this->assertSame('2027-01-04T18:00:00Z', ScheduleCalc::iso($d['next']));
        $this->assertSame('03:00', $d['next']->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('H:i'));
        // 다음 시각은 항상 현재보다 뒤
        foreach ([1, 2, 7, 30, 365] as $n) {
            foreach (['2026-10-05T18:00:00Z', '2026-11-30T00:00:00Z', '2027-06-01T12:34:56Z', '2029-01-01T00:00:00Z'] as $nowIso) {
                $d = ScheduleCalc::due($anchor, $n, self::at($nowIso));
                $this->assertTrue($d['next'] > self::at($nowIso) && $d['due'] <= self::at($nowIso), "{$n}일 {$nowIso}");
                $this->assertSame(0, ($d['next']->getTimestamp() - $anchor->getTimestamp()) % ($n * 86400), '계열 유지');
            }
        }
    }

    public function testRetryableTable(): void
    {
        foreach (['repo_unreachable', 'repo_timeout', 'repo_fetch_failed'] as $c) {
            $this->assertTrue(ScheduleRetry::retryable($c), $c);
        }
        foreach (['repo_auth_failed', 'repo_host_not_allowed', 'repo_tool_unavailable', 'repo_not_configured', 'repo_secret_unreadable', 'repo_tls_untrusted',
            'repo_not_found', 'repo_limit_exceeded', 'repo_empty', 'repo_unsafe_content', 'insufficient_storage', 'timeout', 'analysis_crashed', 'tool_unavailable'] as $c) {
            $this->assertFalse(ScheduleRetry::retryable($c), $c);
        }
    }
}
