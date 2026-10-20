<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\Navigation;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 앱 전체(전역 미들웨어 포함)를 거치는 인증·세션·CSRF·RBAC 시험(명세 3, 10, 13, 19.7).
 */
final class AuthHttpTest extends TestCase
{
    private string $logDir;
    private App $app;
    private int $adminId;
    private int $kimId;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('authhttp');
        $this->app = TestApp::make($this->logDir);
        $db = $this->app->db();
        $this->adminId = TestDb::user($db, 'admin', 'admin');
        $this->kimId = TestDb::user($db, 'kim', 'user');
    }

    private function client(string $ip = '127.0.0.1'): TestClient
    {
        return new TestClient($this->app, $ip);
    }

    private function loggedIn(string $login): TestClient
    {
        $c = $this->client();
        $res = $c->login($login, 'Passw0rd!x');
        $this->assertSame(200, $res->status, $login . ' 로그인: ' . $res->body);
        return $c;
    }

    // ---- 세션 쿠키 ----

    public function testSessionCookieAttributesAndRegenerationOnLogin(): void
    {
        $c = $this->client();
        $c->fetchCsrf();
        $set = (string) $c->last->header('Set-Cookie');
        $this->assertSame(1, preg_match('/^pjg_session=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; Secure; SameSite=Lax$/', $set), '쿠키 속성: ' . $set);
        $before = $c->sessionCookie();
        $csrfBefore = $c->csrf;

        $res = $c->login('kim', 'Passw0rd!x');
        $this->assertSame(200, $res->status);
        $this->assertTrue($c->sessionCookie() !== null && $c->sessionCookie() !== $before, '로그인 시 세션 ID 재생성');
        $this->assertTrue($c->csrf !== $csrfBefore, 'CSRF 토큰도 새로');
        $this->assertSame('kim', $res->decoded()['data']['user']['login_id']);
        $this->assertFalse(str_contains($res->body, 'password_hash'), '응답에 해시 없음');

        // 이전 세션 ID를 들고 온 공격자는 로그인 상태가 아님
        $attacker = $this->client();
        $attacker->cookies['pjg_session'] = (string) $before;
        $this->assertSame(401, $attacker->get('/api/v1/auth/me')->status);
    }

    public function testLoginFailureResponsesDoNotRevealAccountExistence(): void
    {
        $c = $this->client();
        $c->fetchCsrf();
        $a = $c->post('/api/v1/auth/login', ['login_id' => 'kim', 'password' => 'nope']);
        $b = $c->post('/api/v1/auth/login', ['login_id' => 'ghost', 'password' => 'nope']);
        $this->assertSame([401, 'login_failed'], [$a->status, $a->decoded()['error']['code']]);
        $strip = static fn (array $d): array => ['code' => $d['error']['code'], 'message' => $d['error']['message']];
        $this->assertSame($strip($a->decoded()), $strip($b->decoded()), '같은 응답');
    }

    public function testIdleTimeoutAndLogout(): void
    {
        $c = $this->loggedIn('kim');
        $this->assertSame(200, $c->get('/api/v1/auth/me')->status);
        $this->app->clock->advance(30 * 60 + 1);
        $res = $c->get('/api/v1/auth/me');
        $this->assertSame(401, $res->status, '30분 무활동 만료');
        $this->assertSame('session_expired', $res->decoded()['error']['code']);

        $c = $this->loggedIn('kim');
        $cookie = $c->sessionCookie();
        $res = $c->post('/api/v1/auth/logout');
        $this->assertSame(200, $res->status);
        $this->assertContains('Max-Age=0', (string) $res->header('Set-Cookie'), '쿠키 삭제 지시');
        $replay = $this->client();
        $replay->cookies['pjg_session'] = (string) $cookie;
        $this->assertSame(401, $replay->get('/api/v1/auth/me')->status, '로그아웃한 세션 ID 재사용 불가(서버에서 폐기)');
    }

    // ---- CSRF ----

    public function testCsrfIsRequiredForEveryStateChangingMethod(): void
    {
        $c = $this->loggedIn('admin');
        $routes = [
            ['POST', '/api/v1/auth/logout', []],
            ['POST', '/api/v1/users', ['login_id' => 'x1', 'display_name' => 'x']],
            ['PATCH', '/api/v1/users/' . $this->kimId, ['display_name' => 'x']],
            ['PUT', '/api/v1/users/' . $this->kimId . '/role', ['role' => 'admin']],
            ['PUT', '/api/v1/users/' . $this->kimId . '/status', ['status' => 'inactive']],
            ['POST', '/api/v1/users/' . $this->kimId . '/temp-password', []],
            ['POST', '/api/v1/users/' . $this->kimId . '/unlock', []],
            ['POST', '/api/v1/auth/password', ['current_password' => 'x', 'new_password' => 'y']],
            ['DELETE', '/api/v1/users/' . $this->kimId, []],
        ];
        foreach ($routes as [$m, $path, $data]) {
            $none = $c->request($m, $path, [], $data, null, [], false);
            $this->assertSame(403, $none->status, "{$m} {$path} 토큰 없음");
            $this->assertSame('csrf_invalid', $none->decoded()['error']['code'] ?? null, "{$m} {$path}");
            $wrong = $c->request($m, $path, [], $data, null, ['x-csrf-token' => str_repeat('0', 64)], false);
            $this->assertSame(403, $wrong->status, "{$m} {$path} 틀린 토큰");
        }
        $this->assertSame('user', $this->app->db()->value('SELECT role FROM users WHERE id = ?', [$this->kimId]), '아무것도 바뀌지 않음');
        $this->assertSame(200, $c->get('/api/v1/auth/me')->status, '세션 유지(로그아웃되지 않음)');
    }

    public function testLoginItselfRequiresCsrf(): void
    {
        $c = $this->client();
        $res = $c->post('/api/v1/auth/login', ['login_id' => 'kim', 'password' => 'Passw0rd!x'], false);
        $this->assertSame(403, $res->status, '로그인 전 세션도 토큰 필요(예외 경로 없음)');
    }

    public function testHeaderTokenAndFormFieldAreAccepted(): void
    {
        $c = $this->loggedIn('admin');
        $this->assertSame(200, $c->put('/api/v1/users/' . $this->kimId . '/role', ['role' => 'admin'])->status, '헤더 토큰');

        $page = $this->client();
        $html = $page->get('/login')->body;
        $this->assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m), '로그인 폼에 숨은 필드');
        $res = $page->form('/login', ['_csrf' => $m[1], 'login_id' => 'kim', 'password' => 'Passw0rd!x']);
        $this->assertSame(302, $res->status, '폼 필드 토큰으로 로그인');
        $res = $page->form('/login', ['_csrf' => 'bad', 'login_id' => 'kim', 'password' => 'Passw0rd!x']);
        $this->assertSame(403, $res->status, '폼의 틀린 토큰 거부');
    }

    public function testGetDoesNotChangeState(): void
    {
        $c = $this->loggedIn('kim');
        $this->assertSame(405, $c->get('/api/v1/auth/logout')->status, '로그아웃은 GET으로 불가');
        $this->assertSame(405, $c->get('/logout')->status);
        $this->assertSame(200, $c->get('/api/v1/auth/me')->status, '여전히 로그인 상태');
    }

    // ---- RBAC ----

    public function testUnauthenticatedApiIs401AndPageRedirectsToLogin(): void
    {
        $c = $this->client();
        foreach (['/api/v1/auth/me', '/api/v1/users'] as $path) {
            $res = $c->get($path);
            $this->assertSame(401, $res->status, $path);
            $this->assertSame('unauthenticated', $res->decoded()['error']['code']);
        }
        $res = $c->get('/admin/users', ['status' => 'active']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/login?next=%2Fadmin%2Fusers%3Fstatus%3Dactive', $res->header('Location'), '로그인 후 돌아올 주소');
        $this->assertSame('/login', $c->get('/')->header('Location'), '루트: 비로그인 → 로그인 화면');
    }

    public function testRegularUserIsForbiddenFromAdminApiAndPages(): void
    {
        $c = $this->loggedIn('kim');
        $checks = [
            ['GET', '/api/v1/users', null],
            ['GET', '/api/v1/users/' . $this->adminId, null],
            ['POST', '/api/v1/users', ['login_id' => 'x', 'display_name' => 'x']],
            ['PUT', '/api/v1/users/' . $this->kimId . '/role', ['role' => 'admin']],
            ['PUT', '/api/v1/users/' . $this->adminId . '/status', ['status' => 'inactive']],
            ['POST', '/api/v1/users/' . $this->adminId . '/temp-password', []],
            ['POST', '/api/v1/users/' . $this->kimId . '/unlock', []],
        ];
        foreach ($checks as [$m, $path, $data]) {
            $res = $c->request($m, $path, [], $data);
            $this->assertSame(403, $res->status, "{$m} {$path}");
            $this->assertSame('forbidden', $res->decoded()['error']['code']);
        }
        $page = $c->get('/admin/users');
        $this->assertSame(403, $page->status, '관리자 화면 URL 직접 접근도 서버에서 차단');
        $this->assertContains('text/html', (string) $page->header('Content-Type'));
        $this->assertSame('user', $this->app->db()->value('SELECT role FROM users WHERE id = ?', [$this->kimId]), '스스로 승격 불가');
    }

    public function testAdminMenuFollowsSessionRole(): void
    {
        $admin = $this->loggedIn('admin');
        $home = $admin->get(Navigation::HOME);
        $this->assertSame(200, $home->status);
        $this->assertContains('data-nav="admin.users"', $home->body);
        $this->assertContains('href="/admin/users"', $home->body, '구현된 관리자 메뉴는 링크');

        $user = $this->loggedIn('kim');
        $home = $user->get(Navigation::HOME);
        $this->assertSame(200, $home->status);
        $this->assertNotContains('data-nav="admin.', $home->body, '일반 사용자 HTML에는 관리자 메뉴 없음');
        $this->assertNotContains('/admin/users', $home->body);
        $this->assertContains('kim', $home->body);
    }

    // ---- 계정 보호 ----

    public function testDeactivationEndsActiveSessionOnNextRequest(): void
    {
        $kim = $this->loggedIn('kim');
        $admin = $this->loggedIn('admin');
        $this->assertSame(200, $admin->put('/api/v1/users/' . $this->kimId . '/status', ['status' => 'inactive'])->status);
        $this->assertSame(401, $kim->get('/api/v1/auth/me')->status, '비활성화된 사용자의 세션 즉시 무효');
        $this->assertSame(401, $this->client()->login('kim', 'Passw0rd!x')->status, '비활성 계정 로그인 거부');
    }

    public function testRoleChangeEndsActiveSession(): void
    {
        $kim = $this->loggedIn('kim');
        $admin = $this->loggedIn('admin');
        $this->assertSame(200, $admin->put('/api/v1/users/' . $this->kimId . '/role', ['role' => 'admin'])->status);
        $this->assertSame(401, $kim->get('/api/v1/auth/me')->status, '역할이 바뀌면 다시 로그인');
    }

    public function testLastAdminIsProtectedOverHttp(): void
    {
        $admin = $this->loggedIn('admin');
        $res = $admin->put('/api/v1/users/' . $this->adminId . '/status', ['status' => 'inactive']);
        $this->assertSame(409, $res->status);
        $this->assertSame('last_admin', $res->decoded()['error']['code']);
        $res = $admin->put('/api/v1/users/' . $this->adminId . '/role', ['role' => 'user']);
        $this->assertSame('last_admin', $res->decoded()['error']['code']);
        // P28B(의도된 단언 변경): DELETE /api/v1/users/{id}가 생겼지만 승인된 적 없는 계정(승인 대기·거절)만 지운다 — 승인된 계정은 409, 그대로 남음(비활성화만, 명세 13)
        $res = $admin->request('DELETE', '/api/v1/users/' . $this->kimId, ['updated_at' => 'x']);
        $this->assertSame([409, 'user_not_deletable'], [$res->status, $res->decoded()['error']['code']], '승인된 계정은 지우지 않음');
        $this->assertSame(200, $admin->get('/api/v1/users/' . $this->kimId)->status, '계정이 그대로');
    }

    // ---- 임시 비밀번호 ----

    public function testTemporaryPasswordForcesChangeBeforeAnythingElse(): void
    {
        $admin = $this->loggedIn('admin');
        $res = $admin->post('/api/v1/users/' . $this->kimId . '/temp-password');
        $this->assertSame(200, $res->status);
        $temp = (string) $res->decoded()['data']['temp_password'];

        $kim = $this->client();
        $login = $kim->login('kim', $temp);
        $this->assertSame(200, $login->status);
        $this->assertTrue($login->decoded()['data']['user']['must_change_password']);

        $this->assertSame(200, $kim->get('/api/v1/auth/me')->status, '자기 정보 조회는 허용');
        $home = $kim->get(Navigation::HOME);
        $this->assertSame(302, $home->status);
        $this->assertSame('/password', $home->header('Location'), '화면은 비밀번호 변경으로');
        $this->assertSame('/password', $kim->get('/')->header('Location'));
        $this->assertSame(200, $kim->get('/password')->status);

        // 관리자 권한이 있어도 변경 전에는 막힌다(역할 변경 API는 세션을 끊으므로 DB로 직접 바꿔 시험)
        $this->app->db()->execute("UPDATE users SET role = 'admin' WHERE id = ?", [$this->kimId]);
        $blocked = $kim->get('/api/v1/users');
        $this->assertSame(403, $blocked->status);
        $this->assertSame('password_change_required', $blocked->decoded()['error']['code']);

        $bad = $kim->post('/api/v1/auth/password', ['current_password' => $temp, 'new_password' => 'weakpassword']);
        $this->assertSame(400, $bad->status);
        $this->assertSame('password_policy', $bad->decoded()['error']['code'], '새 비밀번호에 정책 적용');

        $ok = $kim->post('/api/v1/auth/password', ['current_password' => $temp, 'new_password' => 'N3w-Passw0rd']);
        $this->assertSame(200, $ok->status, $ok->body);
        $this->assertSame(200, $kim->get(Navigation::HOME)->status, '변경 후 해제');
        $this->assertSame(200, $kim->get('/api/v1/users')->status);
    }

    // ---- 루트·리다이렉트 ----

    public function testRootAndLoginRedirects(): void
    {
        $c = $this->client();
        $html = $c->get('/login', ['next' => 'https://evil.example/x'])->body;
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m);
        $res = $c->form('/login', ['_csrf' => $m[1], 'login_id' => 'kim', 'password' => 'Passw0rd!x', 'next' => 'https://evil.example/x']);
        $this->assertSame(Navigation::HOME, $res->header('Location'), '외부 주소로 이동하지 않고 홈으로');
        $this->assertSame(Navigation::HOME, $c->get('/')->header('Location'), '루트: 로그인 상태 → 홈');
        $this->assertSame(Navigation::HOME, $c->get('/login')->header('Location'), '로그인 상태에서 로그인 화면 → 홈');

        foreach (['//evil.example/x', '/\\evil.example', 'javascript:alert(1)', "/home\r\nX: y", '/api/v1/users', 'http://pjguardian.local/home'] as $bad) {
            $c2 = $this->client();
            preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $c2->get('/login')->body, $m2);
            $loc = $c2->form('/login', ['_csrf' => $m2[1], 'login_id' => 'kim', 'password' => 'Passw0rd!x', 'next' => $bad])->header('Location');
            $this->assertSame(Navigation::HOME, $loc, '거부: ' . json_encode($bad));
        }
        $c3 = $this->client();
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $c3->get('/login')->body, $m3);
        $loc = $c3->form('/login', ['_csrf' => $m3[1], 'login_id' => 'admin', 'password' => 'Passw0rd!x', 'next' => '/admin/users?status=active'])->header('Location');
        $this->assertSame('/admin/users?status=active', $loc, '앱 안의 경로는 허용');
    }

    public function testLoginPageShowsGenericErrorAndEscapesInput(): void
    {
        $c = $this->client();
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $c->get('/login')->body, $m);
        $res = $c->form('/login', ['_csrf' => $m[1], 'login_id' => '<script>x</script>', 'password' => 'nope']);
        $this->assertSame(401, $res->status);
        $this->assertContains('&lt;script&gt;x&lt;/script&gt;', $res->body, '입력값 이스케이프');
        $this->assertNotContains('<script>x', $res->body);
        $this->assertNotContains('nope', $res->body, '비밀번호는 다시 채우지 않음');
    }

    // ---- 로그 ----

    public function testLogsContainNoPasswordsOrSessionIds(): void
    {
        $c = $this->client();
        $c->fetchCsrf();
        $c->post('/api/v1/auth/login', ['login_id' => 'kim', 'password' => 'Wr0ng!Secret-123']);
        $c->login('kim', 'Passw0rd!x');
        $sessionId = (string) $c->sessionCookie();
        $admin = $this->loggedIn('admin');
        $temp = (string) $admin->post('/api/v1/users/' . $this->kimId . '/temp-password')->decoded()['data']['temp_password'];

        $log = '';
        foreach (scandir($this->logDir) ?: [] as $f) {
            if (str_ends_with($f, '.log')) {
                $log .= (string) file_get_contents($this->logDir . '/' . $f);
            }
        }
        $this->assertContains('로그인 성공', $log);
        $this->assertContains('로그인 실패', $log);
        foreach (['Wr0ng!Secret-123', 'Passw0rd!x', $temp, $sessionId, (string) $c->csrf] as $secret) {
            $this->assertNotContains($secret, $log, '로그에 비밀 값 없음');
        }
    }
}
