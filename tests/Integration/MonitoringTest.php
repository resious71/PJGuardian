<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Monitoring\AdminAlertSink;
use PJGuardian\Support\FixedClock;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 시스템 모니터링·헬스체크·관리자 배너(P30):
 * - 권한: 화면·API는 admin만(그룹 관리자·일반 사용자 403, 내용 없음).
 * - 카드 9개, 값을 못 구한 카드만 "확인 불가"(인증서 미설정), 판정은 화면·/health·배너가 같음.
 * - /health: 허용 IP만(밖이면 없는 경로와 같은 404), X-Forwarded-For 위조 무시, 고정 스키마·식별 정보 없음, fail 503·ok/warn 200, 호출 제한 429, 응답 캐시.
 * - 배너: admin에게만, 일반 사용자 HTML에 없음.
 * - 상태 변화: 정상→경고 때만 AdminAlertSink 한 번, 경고 로그(alert-*.log) 시작·계속(간격)·해소. 비밀·경로가 응답에 없음.
 */
final class MonitoringTest extends TestCase
{
    private App $app;
    private string $dir;
    private FixedClock $clock;
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function setUp(): void
    {
        $this->dir = $this->tempDir('mon');
        $this->app = TestApp::make($this->dir, [
            'paths' => ['work_dir' => $this->dir . '/work'],
            'health' => ['allowed_ips' => '127.0.0.1, 10.0.0.0/8', 'per_minute' => '3', 'cache' => '5s'],
            'monitoring' => ['cache' => '30s', 'log_repeat' => '1h'],
        ], ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        $this->clock = $this->app->clock;
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $t = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('팀', ?, ?)", [$t, $t]);
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, ?, 1, ?)', [$mgr, $t]);
        $test = $this;
        $this->app->setAlertSink(new class ($test) implements AdminAlertSink {
            public function __construct(private readonly MonitoringTest $t)
            {
            }

            public function raised(array $event): void
            {
                $this->t->events[] = $event;
            }
        });
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function beat(): void
    {
        $this->app->workerState()->beat(4242, '2026-10-02T11:00:00Z', 'running', [], [], App::VERSION);
    }

    private function alertLog(): string
    {
        $out = '';
        foreach (glob($this->dir . '/alert-*.log') ?: [] as $f) {
            $out .= (string) file_get_contents($f);
        }
        return $out;
    }

    public function testAccessOnlyAdmin(): void
    {
        foreach (['kim', 'mgr'] as $login) {
            $c = $this->as($login);
            foreach (['/admin/monitoring', '/api/v1/admin/monitoring'] as $p) {
                $r = $c->get($p);
                $this->assertSame(403, $r->status, "{$login} {$p}");
                $this->assertNotContains('data-monitoring', $r->body);
                $this->assertNotContains('worker_down', $r->body);
            }
            $this->assertNotContains('data-health-banner', $c->get('/projects')->body, "{$login}: 배너 없음");
            $this->assertNotContains('/admin/monitoring', $c->get('/projects')->body, "{$login}: 메뉴 없음");
        }
        $admin = $this->as('admin');
        $page = $admin->get('/admin/monitoring');
        $this->assertSame(200, $page->status);
        $this->assertContains('data-monitoring', $page->body);
        $this->assertContains('data-nav="admin.system"', $page->body);
        $this->assertNotContains('aria-disabled="true" data-nav="admin.system"', $page->body, '메뉴 활성화');
        $this->assertNotContains('aria-disabled="true" data-nav="admin.audit"', $page->body, '메뉴 활성화');
    }

    public function testSnapshotCardsAndBannerAgree(): void
    {
        $admin = $this->as('admin');
        $d = $admin->get('/api/v1/admin/monitoring')->decoded()['data'];
        $this->assertSame(['worker', 'queue', 'disk', 'failures', 'backup', 'tools', 'schedules', 'cert', 'app'], array_keys($d['cards']));
        $this->assertSame(['available' => false, 'reason' => 'not_configured'], array_intersect_key($d['cards']['cert'], ['available' => 1, 'reason' => 1]), '인증서 미설정은 확인 불가');
        $this->assertTrue($d['cards']['app']['available'] && $d['cards']['app']['version'] === App::VERSION && $d['cards']['app']['schema'] !== '');
        $codes = array_column($d['alerts'], 'code');
        $this->assertTrue(in_array('worker_down', $codes, true), '생존 신호 없음 = 워커 다운');
        $this->assertSame('fail', $d['status']);
        // 배너: 같은 판정(캐시)
        $html = $admin->get('/projects')->body;
        $this->assertContains('data-health-banner data-count="' . count($d['alerts']) . '"', $html);
        $this->assertContains('/admin/monitoring', $html);
        // 비밀·경로 없음
        $raw = $admin->get('/api/v1/admin/monitoring')->body;
        $this->assertNotContains(base64_encode(str_repeat('k', 32)), $raw);
        $this->assertNotContains(str_replace('\\', '/', $this->dir), str_replace(['\\\\', '\\/'], '/', $raw), '서버 경로 없음');
        // 워커가 살아나면(캐시가 지난 뒤) 워커 다운이 사라짐
        $this->beat();
        $this->clock->advance(31);
        $d2 = $admin->get('/api/v1/admin/monitoring')->decoded()['data'];
        $this->assertFalse(in_array('worker_down', array_column($d2['alerts'], 'code'), true));
        $this->assertTrue($d2['cards']['worker']['alive']);
    }

    public function testHealthEndpoint(): void
    {
        $c = new TestClient($this->app, '127.0.0.1');
        $r = $c->get('/health');
        $this->assertSame(503, $r->status, '워커 다운 = fail = 503');
        $body = json_decode($r->body, true);
        $this->assertSame(['status', 'time', 'maintenance', 'checks'], array_keys($body)); // P32B: maintenance(업그레이드 유지보수 모드) 추가 — 의도된 단언 변경
        $this->assertSame(['worker', 'db', 'disk', 'backup', 'queue'], array_keys($body['checks']));
        $this->assertSame(['alive', 'last_seen'], array_keys($body['checks']['worker']));
        $this->assertSame(['waiting', 'oldest_wait_seconds', 'stalled'], array_keys($body['checks']['queue']));
        $this->assertSame('fail', $body['status']);
        $this->assertNotContains('set-cookie', strtolower(implode("\n", array_keys($r->headers()))), '세션 쿠키를 만들지 않음');
        foreach (['admin', 'kim', '팀', str_replace('\\', '/', $this->dir), 'project'] as $bad) {
            $this->assertNotContains($bad, str_replace('\\/', '/', $r->body), "식별 정보 {$bad} 없음");
        }
        // 허용 밖: 없는 경로와 같은 응답(존재를 알리지 않음)
        $out = new TestClient($this->app, '192.168.1.5');
        $deny = $out->get('/health');
        $none = $out->get('/no-such-path-p30');
        $this->assertSame([404, $none->status], [$deny->status, 404]);
        $this->assertSame(json_decode($none->body, true)['error']['code'] ?? $none->body === '', json_decode($deny->body, true)['error']['code'] ?? $deny->body === '');
        $this->assertNotContains('checks', $deny->body);
        // X-Forwarded-For 위조는 믿지 않음
        $spoof = $out->request('GET', '/health', [], null, null, ['x-forwarded-for' => '127.0.0.1', 'x-real-ip' => '127.0.0.1', 'forwarded' => 'for=127.0.0.1']);
        $this->assertSame(404, $spoof->status, '헤더 위조 무시');
        // CIDR 허용
        $this->assertSame(503, (new TestClient($this->app, '10.20.30.40'))->get('/health')->status);
        // 호출 제한(IP당 분당 3회): 127.0.0.1은 이미 1회 → 2회 더 허용, 4번째 429
        $this->assertSame(503, $c->get('/health')->status);
        $this->assertSame(503, $c->get('/health')->status);
        $this->assertSame(429, $c->get('/health')->status);
        $this->clock->advance(61);
        // 캐시: 워커를 살려도 health.cache(5s)·monitoring.cache(30s) 안이면 같은 본문 — 지나면 새 판정(ok/warn → 200)
        $this->beat();
        $first = json_decode($c->get('/health')->body, true);
        $this->assertSame(200, $c->last->status, '워커 살아남 → warn 또는 ok → 200');
        $this->assertTrue(in_array($first['status'], ['ok', 'warn'], true));
        $this->assertTrue($first['checks']['worker']['alive']);
        $this->clock->advance(2);
        $this->assertSame($first['time'], json_decode($c->get('/health')->body, true)['time'], '캐시 안');
        $this->clock->advance(4);
        $this->assertTrue($first['time'] !== json_decode($c->get('/health')->body, true)['time'], '캐시 지남');
    }

    public function testRefreshForcesNewJudgement(): void
    {
        $admin = $this->as('admin');
        $codes = static fn (array $d): array => array_column($d['alerts'], 'code');
        $this->assertTrue(in_array('worker_down', $codes($admin->get('/api/v1/admin/monitoring')->decoded()['data']), true));
        $this->beat(); // 캐시(30s) 안
        $this->assertTrue(in_array('worker_down', $codes($admin->get('/api/v1/admin/monitoring')->decoded()['data']), true), '캐시 안에서는 그대로');
        $this->assertFalse(in_array('worker_down', $codes($admin->get('/api/v1/admin/monitoring', ['refresh' => '1'])->decoded()['data']), true), '"지금 새로 고침"은 캐시 안이어도 새 판정');
    }

    public function testHealthDeniedWhenListEmpty(): void
    {
        $app = TestApp::make($this->tempDir('mon2'), ['paths' => ['work_dir' => $this->tempDir('mon2w')]]);
        $this->assertSame(404, (new TestClient($app, '127.0.0.1'))->get('/health')->status, '기본값(빈 목록) = 모두 거부');
    }

    public function testTransitionsLogAndSinkOnce(): void
    {
        $h = $this->app->systemHealth();
        $s1 = $h->snapshot();
        $this->assertTrue(in_array('worker_down', array_column($s1['alerts'], 'code'), true));
        $raised = array_column($this->events, 'code');
        $this->assertSame(count(array_unique($raised)), count($raised), '경고마다 한 번');
        $this->assertTrue(in_array('worker_down', $raised, true));
        $this->assertSame(1, substr_count($this->alertLog(), '"code":"worker_down"'), '시작 한 줄');
        // 캐시 안: 다시 계산하지 않음
        $h->snapshot();
        $this->clock->advance(31);
        $h->snapshot();
        $this->assertSame(1, substr_count($this->alertLog(), '"code":"worker_down"'), '로그 간격(1h) 안에는 다시 쓰지 않음');
        $this->assertSame(1, count(array_filter($this->events, static fn (array $e): bool => $e['code'] === 'worker_down')), '이어지는 동안 장애 이벤트 다시 없음');
        $this->clock->advance(3600);
        $h->snapshot();
        $this->assertSame(2, substr_count($this->alertLog(), '"code":"worker_down"'), '간격이 지나면 계속 한 줄');
        $this->assertContains('장애 경고 계속', $this->alertLog());
        // 해소
        $this->beat();
        $this->clock->advance(31);
        $h->snapshot();
        $this->assertContains('장애 경고 해소', $this->alertLog());
        // 다시 다운 → 정상→경고이므로 이벤트 한 번 더
        @unlink($this->app->workerState()->heartbeatFile());
        $this->clock->advance(31);
        $h->snapshot();
        $this->assertSame(2, count(array_filter($this->events, static fn (array $e): bool => $e['code'] === 'worker_down')));
        foreach ($this->events as $e) {
            $this->assertSame(['code', 'level', 'params', 'at'], array_keys($e));
        }
    }

    public function testCertificateFromFile(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->assertTrue(true, 'openssl 키 생성 불가 환경 — 건너뜀');
            return;
        }
        $csr = openssl_csr_new(['commonName' => 'pjguardian.test'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 20); // 오늘부터 20일
        openssl_x509_export($cert, $pem);
        $file = $this->dir . '/server.crt';
        file_put_contents($file, $pem);
        $app = TestApp::make($this->tempDir('mon3'), ['paths' => ['work_dir' => $this->tempDir('mon3w')], 'monitoring' => ['tls_cert_file' => $file],
            'notification' => ['cert_expiry_warn' => '30d']]);
        // 시험 앱 시계(2026-10-02)와 실제 오늘이 다를 수 있으므로 남은 일수는 시험 시계 기준으로 계산된 값과 상태만 확인
        $c = $app->systemHealth()->cards()['cert'];
        $this->assertTrue($c['available']);
        $days = (int) floor((strtotime($c['not_after']) - $app->clock->now()->getTimestamp()) / 86400);
        $this->assertSame($days, $c['days_left']);
        $this->assertSame($days < 0 ? 'expired' : ($days <= 30 ? 'expiring' : 'ok'), $c['state']);
        $this->assertNotContains($file, (string) json_encode($c), '파일 경로를 내보내지 않음');
        file_put_contents($file, 'not a certificate');
        $this->assertSame('invalid', $app->systemHealth()->cards()['cert']['reason']);
    }
}
