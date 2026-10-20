<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Navigation;
use PJGuardian\Tests\Support\AuthFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자가 사용자 관리 화면에서 다른 사용자의 비밀번호를 정한다(2026-10-03 사용자 결정, 명세 13장·19.10).
 * - 정책(8자·2종)을 그대로 적용한다. 본인 비밀번호는 이 기능으로 바꾸지 않는다(현재 비밀번호 확인이 있는 /password).
 * - 대상 사용자는 다음 로그인 때 "관리자가 비밀번호를 변경했다"는 알림만 받는다. 변경을 강제하지 않는다.
 * - 대상의 세션은 즉시 폐기, 잠금은 해제, 감사 로그(user.password_set)에 값은 남기지 않는다.
 */
final class AdminPasswordTest extends TestCase
{
    private string $logDir;
    private AuthFixture $f;
    private int $adminId;
    private Actor $admin;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('adminpw');
        $this->f = new AuthFixture($this->logDir);
        $this->adminId = $this->f->user('admin', 'Passw0rd!x', 'admin');
        $this->admin = Actor::fromUser($this->f->row($this->adminId), '10.0.0.1');
    }

    public function testAdminSetsPasswordAndUserLogsInWithNoticeButNoForcedChange(): void
    {
        $kim = $this->f->user('kim');
        $u = $this->f->users->setPasswordByAdmin($this->admin, $kim, 'kimpass1');
        $this->assertFalse($u['must_change_password'], '변경을 강제하지 않음');
        $this->assertSame('2026-10-02T12:00:00Z', $this->f->row($kim)['admin_password_set_at']);

        $first = $this->f->login->attempt('kim', 'kimpass1', '10.0.0.2');
        $this->assertTrue($first->ok);
        $this->assertFalse($first->user['must_change_password']);
        $this->assertSame(['code' => 'password_set_by_admin', 'at' => '2026-10-02T12:00:00Z'], $first->notice, '다음 로그인 때 알림');
        $this->assertFalse($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.2')->ok, '이전 비밀번호는 무효');

        $second = $this->f->login->attempt('kim', 'kimpass1', '10.0.0.2');
        $this->assertSame(null, $second->notice, '알림은 한 번만');
        $this->assertSame(null, $this->f->row($kim)['admin_password_set_at']);
    }

    public function testPolicyStillApplies(): void
    {
        $kim = $this->f->user('kim');
        foreach (['short1', 'abcdefghij', '12345678', 'admin'] as $weak) {
            $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->setPasswordByAdmin($this->admin, $kim, $weak));
            $this->assertSame('password_policy', $e->errorCode, $weak);
        }
    }

    public function testCannotChangeOwnPasswordHere(): void
    {
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->setPasswordByAdmin($this->admin, $this->adminId, 'newpass12'));
        $this->assertSame('self_password_change', $e->errorCode);
    }

    public function testLdapAccountIsRejected(): void
    {
        $now = TestDb::T0;
        $this->f->db->execute(
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at)"
            . " VALUES ('dir', 'dir', 'user', 'active', 'ldap', NULL, ?, ?)",
            [$now, $now],
        );
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->setPasswordByAdmin($this->admin, $this->f->db->lastInsertId(), 'newpass12'));
        $this->assertSame('not_local_account', $e->errorCode);
    }

    public function testSessionsDestroyedLockClearedTempStateCleared(): void
    {
        $kim = $this->f->user('kim');
        $this->f->users->issueTempPassword($this->admin, $kim);
        for ($i = 0; $i < 5; $i++) {
            $this->f->login->attempt('kim', 'bad', '10.0.1.' . $i);
        }
        $this->f->sessions->rotate(null, $kim, null, null);
        $this->f->users->setPasswordByAdmin($this->admin, $kim, 'kimpass1');
        $row = $this->f->row($kim);
        $this->assertSame(0, (int) $this->f->db->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$kim]), '세션 폐기');
        $this->assertSame(null, $row['locked_until'], '잠금 해제');
        $this->assertSame(0, (int) $row['must_change_password'], '임시 비밀번호 상태 해제');
        $this->assertSame(null, $row['temp_password_expires_at']);
        $this->assertSame('user', $row['password_kind']);
    }

    public function testAuditedWithoutValue(): void
    {
        $kim = $this->f->user('kim');
        $this->f->users->setPasswordByAdmin($this->admin, $kim, 'kimpass1');
        $row = $this->f->db->one("SELECT actor_label, target_id, details FROM audit_log WHERE action = 'user.password_set'");
        $this->assertSame(['admin', (string) $kim], [$row['actor_label'], $row['target_id']]);
        $this->assertSame(['login_id' => 'kim'], json_decode((string) $row['details'], true));
        $this->assertNotContains('kimpass1', $this->f->logText($this->logDir));
        $this->assertSame(true, $this->f->audit->verify()['ok']);
    }

    public function testHttpApiAndLoginNoticeBanner(): void
    {
        $app = TestApp::make($this->tempDir('adminpw_http'));
        $db = $app->db();
        $adminId = TestDb::user($db, 'admin', 'admin');
        $kim = TestDb::user($db, 'kim', 'user');
        $lee = TestDb::user($db, 'lee', 'user');

        $leeClient = new TestClient($app);
        $leeClient->login('lee', 'Passw0rd!x');
        $this->assertSame(403, $leeClient->put("/api/v1/users/{$kim}/password", ['password' => 'kimpass1'])->status, '일반 사용자는 403');

        $admin = new TestClient($app);
        $admin->login('admin', 'Passw0rd!x');
        $bad = $admin->put("/api/v1/users/{$kim}/password", ['password' => 'short']);
        $this->assertSame('password_policy', $bad->decoded()['error']['code']);
        $self = $admin->put("/api/v1/users/{$adminId}/password", ['password' => 'newpass12']);
        $this->assertSame('self_password_change', $self->decoded()['error']['code']);
        $ok = $admin->put("/api/v1/users/{$kim}/password", ['password' => 'kimpass1']);
        $this->assertSame(200, $ok->status, $ok->body);
        $this->assertFalse(str_contains($ok->body, 'kimpass1'), '응답에 비밀번호 없음');

        // 화면 로그인: 비밀번호 변경 화면이 아니라 홈으로 가고, 알림 배너가 한 번 보인다
        $kimClient = new TestClient($app);
        preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $kimClient->get('/login')->body, $m);
        $res = $kimClient->form('/login', ['_csrf' => $m[1], 'login_id' => 'kim', 'password' => 'kimpass1']);
        $this->assertSame(Navigation::HOME, $res->header('Location'), '변경 강제 없음');
        $home = $kimClient->get(Navigation::HOME)->body;
        $this->assertContains('관리자가 비밀번호를 변경했습니다', $home, '알림 배너');
        $this->assertNotContains('관리자가 비밀번호를 변경했습니다', $kimClient->get(Navigation::HOME)->body, '다시 열면 사라짐');

        // API 로그인은 응답에 알림 코드를 담는다
        $admin->put("/api/v1/users/{$kim}/password", ['password' => 'kimpass2']);
        $api = (new TestClient($app))->login('kim', 'kimpass2');
        $this->assertSame('password_set_by_admin', $api->decoded()['data']['notice']['code'] ?? null);
    }
}
