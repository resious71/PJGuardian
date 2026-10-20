<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 세션 수명(P31 A-1, HTTP로): 본인 비밀번호 변경 → 같은 사용자의 다른 세션은 끊기고 지금 세션은 이어짐,
 * 관리자의 비밀번호 직접 지정(명세 13장) → 대상의 모든 세션 끊김, 로그아웃한 세션 쿠키는 다시 쓸 수 없음.
 * (쿠키 속성·로그인 시 재발급·유휴 만료·비활성화·역할 변경·임시 비밀번호·복원 뒤 무효화는 AuthHttpTest·SessionServiceTest·UserServiceTest·BackupRestoreTest)
 */
final class SessionLifecycleTest extends TestCase
{
    private function login(\PJGuardian\App $app, string $login, string $pw = 'Passw0rd!x'): TestClient
    {
        $c = new TestClient($app);
        $this->assertSame(200, $c->login($login, $pw)->status, $login);
        return $c;
    }

    public function testOwnPasswordChangeEndsOtherSessionsOnly(): void
    {
        $app = TestApp::make($this->tempDir('sl'));
        TestDb::user($app->db(), 'admin', 'admin');
        TestDb::user($app->db(), 'kim', 'user');
        $a = $this->login($app, 'kim');
        $b = $this->login($app, 'kim');
        $other = $this->login($app, 'admin');
        $r = $a->post('/api/v1/auth/password', ['current_password' => 'Passw0rd!x', 'new_password' => 'Newpass12!x']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(200, $a->get('/api/v1/users/me')->status, '바꾼 세션은 이어짐');
        $this->assertSame(401, $b->get('/api/v1/users/me')->status, '같은 사용자의 다른 세션은 끊김');
        $this->assertSame(200, $other->get('/api/v1/users/me')->status, '다른 사용자는 그대로');
        $this->assertSame(200, (new TestClient($app))->login('kim', 'Newpass12!x')->status);
    }

    public function testAdminPasswordSetEndsTargetSessionsAndLogoutCookieIsDead(): void
    {
        $app = TestApp::make($this->tempDir('sl2'));
        TestDb::user($app->db(), 'admin', 'admin');
        $kimId = TestDb::user($app->db(), 'kim', 'user');
        $kim = $this->login($app, 'kim');
        $admin = $this->login($app, 'admin');
        $this->assertSame(200, $admin->put("/api/v1/users/{$kimId}/password", ['password' => 'Setbyadm1n!'])->status);
        $this->assertSame(401, $kim->get('/api/v1/users/me')->status, '대상 세션 끊김');
        $this->assertSame(200, $admin->get('/api/v1/users/me')->status, '관리자 세션은 그대로');

        $cookie = $admin->sessionCookie();
        $this->assertSame(200, $admin->post('/api/v1/auth/logout')->status);
        $replay = new TestClient($app);
        $res = $replay->request('GET', '/api/v1/users/me', [], null, null, ['cookie' => 'pjg_session=' . $cookie]);
        $this->assertSame(401, $res->status, '로그아웃한 세션 ID 재사용 불가');
    }

    public function testWrongCurrentPasswordUsesLoginLockout(): void
    {
        // P31: 현재 비밀번호 대입 방지 — 로그인 잠금과 같은 횟수(기본 5회)에서 잠기고 그 사용자의 세션이 모두 끊김
        $app = TestApp::make($this->tempDir('sl3'));
        TestDb::user($app->db(), 'admin', 'admin');
        $kimId = TestDb::user($app->db(), 'kim', 'user');
        $a = $this->login($app, 'kim');
        $b = $this->login($app, 'kim');
        $max = $app->config->int('auth.lockout_max_failures');
        for ($i = 1; $i < $max; $i++) {
            $r = $a->post('/api/v1/auth/password', ['current_password' => "wrong-{$i}", 'new_password' => 'Newpass12!x']);
            $this->assertSame([400, 'current_password_incorrect'], [$r->status, $r->decoded()['error']['code'] ?? null], "{$i}회");
        }
        $this->assertSame($max - 1, (int) $app->db()->value('SELECT failed_login_count FROM users WHERE id = ?', [$kimId]));
        $r = $a->post('/api/v1/auth/password', ['current_password' => 'wrong-last', 'new_password' => 'Newpass12!x']);
        $this->assertSame([429, 'too_many_attempts'], [$r->status, $r->decoded()['error']['code'] ?? null], '잠김');
        $this->assertSame(401, $a->get('/api/v1/users/me')->status, '이 세션 끊김');
        $this->assertSame(401, $b->get('/api/v1/users/me')->status, '다른 세션도 끊김');
        $this->assertSame(401, (new TestClient($app))->login('kim', 'Passw0rd!x')->status, '잠긴 동안 로그인 불가');
        $app->clock->advance($app->config->int('auth.lockout_duration') + 1);
        $c = $this->login($app, 'kim');
        $this->assertSame(200, $c->post('/api/v1/auth/password', ['current_password' => 'Passw0rd!x', 'new_password' => 'Newpass12!x'])->status, '잠금이 풀리면 정상');
        $this->assertSame(0, (int) $app->db()->value('SELECT failed_login_count FROM users WHERE id = ?', [$kimId]), '성공하면 실패 횟수 비움');
    }
}
