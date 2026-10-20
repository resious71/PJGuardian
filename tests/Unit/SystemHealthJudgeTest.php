<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Controllers\MonitoringController;
use PJGuardian\Services\Monitoring\SystemHealth;
use PJGuardian\Tests\TestCase;

/**
 * 장애 판정(P30, SystemHealth::judge — 화면·/health·배너가 같은 함수): 워커 다운, 큐 정체(빈 자리·시작 가능 대기·기준 경계), 디스크 경계값(경고·차단),
 * 백업 실패·오래됨(자동 백업 꺼짐이면 오래됨 경고 없음), 도구, 저장소 연속 실패 경계, 인증서 30일 경계·만료, 확인 불가 카드는 경고를 만들지 않음, 전체 상태.
 * /health 허용 IP(CIDR, IPv4·IPv6, 빈 목록 거부, 형식 오류 무시).
 */
final class SystemHealthJudgeTest extends TestCase
{
    /** @return array<string, array<string, mixed>> 모두 정상인 카드 */
    private function cards(): array
    {
        return [
            'worker' => ['available' => true, 'state' => 'running', 'alive' => true, 'seconds_since_beat' => 3],
            'queue' => ['available' => true, 'waiting' => 0, 'oldest_wait_seconds' => null, 'startable_wait_seconds' => null, 'running' => [], 'max_concurrent' => 2, 'stall_after_seconds' => 900],
            'disk' => ['available' => true, 'used_percent' => 50.0, 'state' => 'ok'],
            'backup' => ['available' => true, 'enabled' => true, 'last_success_at' => '2026-10-02T00:00:00Z', 'last_result' => 'succeeded', 'last_failure' => null, 'stale' => false, 'stale_hours' => 36],
            'tools' => ['available' => true, 'items' => [['tool' => 'ruff', 'healthy' => true], ['tool' => 'git', 'healthy' => null]]],
            'schedules' => ['available' => true, 'max_failures' => 0, 'alert_after' => 2],
            'cert' => ['available' => true, 'days_left' => 200, 'not_after' => '2027-05-01T00:00:00Z', 'state' => 'ok'],
        ];
    }

    /** @return list<string> 경고 코드:수준 */
    private function codes(array $cards): array
    {
        return array_map(static fn (array $a): string => $a['code'] . ':' . $a['level'], SystemHealth::judge($cards));
    }

    public function testAllNormal(): void
    {
        $this->assertSame([], SystemHealth::judge($this->cards()));
        $this->assertSame('ok', SystemHealth::status([]));
    }

    public function testWorkerAndQueue(): void
    {
        $c = $this->cards();
        $c['worker'] = ['available' => true, 'state' => 'unresponsive', 'alive' => false, 'seconds_since_beat' => 900];
        $this->assertSame(['worker_down:fail'], $this->codes($c));
        $this->assertSame('fail', SystemHealth::status(SystemHealth::judge($c)));
        // 큐 정체: 워커가 살아 있고 빈 자리가 있고 시작 가능 대기가 기준 "초과"
        $c = $this->cards();
        $c['queue']['startable_wait_seconds'] = 900;
        $this->assertSame([], $this->codes($c), '기준과 같으면 정체 아님');
        $c['queue']['startable_wait_seconds'] = 901;
        $c['queue']['waiting'] = 3;
        $this->assertSame(['queue_stalled:warn'], $this->codes($c));
        $this->assertSame(15, SystemHealth::judge($c)[0]['params']['minutes']);
        $full = $c;
        $full['queue']['running'] = [['analysis_id' => 1], ['analysis_id' => 2]];
        $this->assertSame([], $this->codes($full), '빈 자리가 없으면(동시 실행 상한) 정체가 아님');
        $down = $c;
        $down['worker']['alive'] = false;
        $down['worker']['state'] = 'stopped';
        $this->assertSame(['worker_down:fail'], $this->codes($down), '워커가 없으면 워커 다운만(큐 정체는 따로 내지 않음)');
    }

    public function testDiskBoundaries(): void
    {
        foreach ([['ok', []], ['warn', ['disk_warn:warn']], ['block', ['disk_block:fail']]] as [$state, $want]) {
            $c = $this->cards();
            $c['disk']['state'] = $state;
            $this->assertSame($want, $this->codes($c), $state);
        }
        $c = $this->cards();
        $c['disk'] = ['available' => false, 'reason' => 'unreadable'];
        $this->assertSame([], $this->codes($c), '확인 불가는 경고 없음');
    }

    public function testBackup(): void
    {
        $c = $this->cards();
        $c['backup']['last_result'] = 'failed';
        $c['backup']['last_failure'] = ['at' => '2026-10-02T03:00:00Z', 'code' => 'backup_io_failed'];
        $this->assertSame(['backup_failed:warn'], $this->codes($c));
        $c = $this->cards();
        $c['backup']['stale'] = true;
        $this->assertSame(['backup_stale:warn'], $this->codes($c));
        $c['backup']['enabled'] = false;
        $this->assertSame([], $this->codes($c), '자동 백업이 꺼져 있으면 오래됨 경고 없음(가정)');
    }

    public function testToolsSchedulesCert(): void
    {
        $c = $this->cards();
        $c['tools']['items'][] = ['tool' => 'eslint', 'healthy' => false];
        $this->assertSame(['tool_unhealthy:warn'], $this->codes($c));
        $this->assertSame('eslint', SystemHealth::judge($c)[0]['params']['tools']);
        $c = $this->cards();
        $c['schedules']['max_failures'] = 1;
        $this->assertSame([], $this->codes($c));
        $c['schedules']['max_failures'] = 2;
        $this->assertSame(['repo_fetch_failing:warn'], $this->codes($c));
        foreach ([['ok', []], ['expiring', ['cert_expiring:warn']], ['expired', ['cert_expired:fail']]] as [$state, $want]) {
            $c = $this->cards();
            $c['cert']['state'] = $state;
            $this->assertSame($want, $this->codes($c), $state);
        }
        $c = $this->cards();
        $c['cert'] = ['available' => false, 'reason' => 'not_configured'];
        $this->assertSame([], $this->codes($c));
    }

    public function testHealthAllowList(): void
    {
        $this->assertFalse(MonitoringController::allowed('127.0.0.1', []), '빈 목록은 모두 거부');
        $this->assertTrue(MonitoringController::allowed('127.0.0.1', ['127.0.0.1']));
        $this->assertTrue(MonitoringController::allowed('10.1.2.200', ['10.1.2.0/24']));
        $this->assertFalse(MonitoringController::allowed('10.1.3.1', ['10.1.2.0/24']));
        $this->assertTrue(MonitoringController::allowed('10.1.2.129', ['10.1.2.128/25']));
        $this->assertFalse(MonitoringController::allowed('10.1.2.127', ['10.1.2.128/25']));
        $this->assertTrue(MonitoringController::allowed('192.168.5.5', ['0.0.0.0/0']));
        $this->assertTrue(MonitoringController::allowed('::1', ['::1/128']));
        $this->assertTrue(MonitoringController::allowed('2001:db8::5', ['2001:db8::/32']));
        $this->assertFalse(MonitoringController::allowed('2001:db9::5', ['2001:db8::/32']));
        $this->assertFalse(MonitoringController::allowed('127.0.0.1', ['::1']), 'IPv4와 IPv6는 서로 맞지 않음');
        $this->assertFalse(MonitoringController::allowed('not-an-ip', ['0.0.0.0/0']));
        $this->assertFalse(MonitoringController::allowed('10.0.0.1', ['10.0.0.0/33', 'garbage']), '형식이 틀린 항목은 무시');
    }
}
