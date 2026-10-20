<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\HardeningCheck;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 초기 관리자 비밀번호 위험 완화(P31 A-3 — X17A 결정 "초기 비밀번호 admin, 변경 강제 없음"은 그대로):
 * - 초기 비밀번호(password_kind = 'initial')인 활성 관리자가 있으면 admin에게 모든 화면 위에 닫을 수 없는 경고 배너(일반 사용자에게는 없음).
 *   본인이면 비밀번호 변경 링크, 다른 관리자면 사용자 관리 링크. base_url이 이 PC 밖이면 더 강한 문구.
 * - 모니터링 화면 API 경고 목록에 한 건(상태 정상 → 경고). /health(외부 감시)는 바꾸지 않는다.
 * - 판정은 하드닝 점검과 같은 출처(UserRepository::initialPasswordPendingAdmins) — 점검 결과는 WARN 유지, 외부 주소면 더 강한 문구.
 * - 비밀번호를 바꾸면 배너·경고가 사라진다.
 */
final class InitialPasswordBannerTest extends TestCase
{
    private function app(string $baseUrl): App
    {
        $dir = $this->tempDir('ipb');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'app' => ['base_url' => $baseUrl], 'health' => ['allowed_ips' => '127.0.0.1']]);
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $db->execute("UPDATE users SET password_kind = 'initial' WHERE id = ?", [$admin]);
        TestDb::user($db, 'ops', 'admin');
        TestDb::user($db, 'kim', 'user');
        return $app;
    }

    private function as(App $app, string $login): TestClient
    {
        $c = new TestClient($app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    public function testBannerForAdminsOnlyWithRightLinkAndMonitoringWarning(): void
    {
        $app = $this->app('https://localhost/PJGuardian');
        $admin = $this->as($app, 'admin');
        foreach (['/dashboard', '/projects', '/admin/monitoring', '/account'] as $page) {
            $html = $admin->get($page)->body;
            $this->assertTrue(str_contains($html, 'data-initial-password-banner'), "{$page}: 배너");
        }
        $html = $admin->get('/dashboard')->body;
        $this->assertTrue(str_contains($html, '초기 비밀번호를 아직 바꾸지 않은 관리자 계정이 있습니다(admin)') && str_contains($html, 'data-public="0"'), '로컬 주소 문구');
        $this->assertTrue(preg_match('#data-initial-password-banner[^>]*>.*?href="[^"]*/password"#s', $html) === 1, '본인 → 비밀번호 변경 링크');
        $this->assertFalse(preg_match('#data-initial-password-banner.*?(banner__close|data-dismiss)#s', $html) === 1, '닫기 없음');

        $ops = $this->as($app, 'ops');
        $this->assertTrue(preg_match('#data-initial-password-banner[^>]*>.*?href="[^"]*/admin/users"#s', $ops->get('/dashboard')->body) === 1, '다른 관리자 → 사용자 관리');
        $kim = $this->as($app, 'kim');
        $this->assertFalse(str_contains($kim->get('/dashboard')->body, 'data-initial-password-banner'), '일반 사용자에게 없음');

        $mon = $ops->get('/api/v1/admin/monitoring')->decoded()['data'];
        $codes = array_column($mon['alerts'], 'code');
        $this->assertTrue(in_array('initial_admin_password', $codes, true), '모니터링 경고 목록에 한 건');
        $this->assertSame('warn', array_values(array_filter($mon['alerts'], static fn (array $a): bool => $a['code'] === 'initial_admin_password'))[0]['level']);
        $this->assertTrue(in_array($mon['status'], ['warn', 'fail'], true));
        $health = $app->handle(new \PJGuardian\Http\Request('GET', '/health', [], ['user-agent' => 't'], '', true, '127.0.0.1'))->body;
        $this->assertFalse(str_contains($health, 'initial_admin_password'), '/health는 그대로');

        // 바꾸면 사라짐
        $this->assertSame(200, $admin->post('/api/v1/auth/password', ['current_password' => 'Passw0rd!x', 'new_password' => 'Changed12!x'])->status);
        $this->assertFalse(str_contains($admin->get('/dashboard')->body, 'data-initial-password-banner'), '변경 뒤 배너 없음');
        $this->assertFalse(in_array('initial_admin_password', array_column($ops->get('/api/v1/admin/monitoring')->decoded()['data']['alerts'], 'code'), true));
    }

    public function testPublicBaseUrlUsesStrongerWordingInBannerAndHardeningCheck(): void
    {
        $app = $this->app('https://pjg.example.com');
        $html = $this->as($app, 'admin')->get('/dashboard')->body;
        $this->assertTrue(str_contains($html, 'data-public="1"') && str_contains($html, '누구나 로그인할 수 있으니'), '외부 주소 문구');
        $res = (new HardeningCheck(PJG_ROOT, $app->config, $app->paths, [], null, null, fn (): array => $app->initialPasswordAdmins()))->run();
        $row = array_values(array_filter($res, static fn (array $r): bool => $r['item'] === '관리자 초기 비밀번호'))[0];
        $this->assertSame('WARN', $row['status'], '막지 않음(FAIL 아님)');
        $this->assertTrue(str_contains($row['detail'], '이 PC 밖에서 접속하는 주소'), $row['detail']);
        $local = (new HardeningCheck(PJG_ROOT, $this->app('https://localhost/x')->config, $app->paths, [], null, null, static fn (): array => ['admin']))->run();
        $row = array_values(array_filter($local, static fn (array $r): bool => $r['item'] === '관리자 초기 비밀번호'))[0];
        $this->assertSame('WARN', $row['status']);
        $this->assertFalse(str_contains($row['detail'], '이 PC 밖'), '로컬 주소는 기존 문구');
        foreach (['https://localhost/PJGuardian' => true, 'http://127.0.0.1:8099' => true, 'https://[::1]/' => true, 'https://app.localhost' => true,
            'https://pjguardian.local' => false, 'https://10.0.0.5' => false] as $u => $local) {
            $this->assertSame($local, App::isLocalBaseUrl($u), $u);
        }
    }
}
