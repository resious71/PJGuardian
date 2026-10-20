<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Tests\Support\AuthFixture;
use PJGuardian\Tests\TestCase;

/**
 * 계정 관리(명세 3, 13, 10): 마지막 활성 관리자 보호, 비활성화·역할 변경 시 세션 즉시 무효화,
 * 임시 비밀번호(정책 적용, 24시간), 잠금 해제, 감사 로그와 해시 체인.
 */
final class UserServiceTest extends TestCase
{
    private AuthFixture $f;
    private int $adminId;
    private Actor $admin;

    public function setUp(): void
    {
        $this->f = new AuthFixture($this->tempDir('users'));
        $this->adminId = $this->f->user('admin', 'Passw0rd!x', 'admin');
        $this->admin = Actor::fromUser($this->f->row($this->adminId), '10.0.0.1');
    }

    private function sessionsOf(int $uid): int
    {
        return (int) $this->f->db->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$uid]);
    }

    public function testCreateLocalUserIssuesPolicyCompliantTempPassword(): void
    {
        $r = $this->f->users->create($this->admin, ['login_id' => 'kim', 'display_name' => '김개발', 'email' => 'kim@example.com', 'role' => 'user']);
        $this->assertSame('kim', $r['user']['login_id']);
        $this->assertFalse(array_key_exists('password_hash', $r['user']), '응답에 해시 없음');
        $this->assertTrue($r['user']['must_change_password'], '다음 로그인 때 변경 강제');
        $this->assertSame('2026-10-03T12:00:00Z', $r['user']['temp_password_expires_at']);
        $this->assertSame([], (new \PJGuardian\Services\Auth\PasswordPolicy(10, 3))->violations($r['temp_password']), '임시 비밀번호도 정책 충족');
        $this->assertTrue($this->f->login->attempt('kim', $r['temp_password'], '10.0.0.2')->ok);
    }

    public function testCreateValidatesInput(): void
    {
        $this->f->users->create($this->admin, ['login_id' => 'kim', 'display_name' => 'K']);
        $cases = [
            [['login_id' => 'KIM', 'display_name' => 'x'], 'login_id_taken'],
            [['login_id' => '', 'display_name' => 'x'], 'invalid_login_id'],
            [['login_id' => 'a b', 'display_name' => 'x'], 'invalid_login_id'],
            [['login_id' => 'ok', 'display_name' => ''], 'invalid_display_name'],
            [['login_id' => 'ok', 'display_name' => 'x', 'email' => 'not-mail'], 'invalid_email'],
            [['login_id' => 'ok', 'display_name' => 'x', 'role' => 'root'], 'invalid_role'],
        ];
        foreach ($cases as [$data, $code]) {
            $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->create($this->admin, $data));
            $this->assertSame($code, $e->errorCode, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testLastActiveAdminCannotBeDeactivatedOrDemoted(): void
    {
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->setStatus($this->admin, $this->adminId, 'inactive'));
        $this->assertSame('last_admin', $e->errorCode);
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->changeRole($this->admin, $this->adminId, 'user'));
        $this->assertSame('last_admin', $e->errorCode);
        $this->assertSame(409, $e->status);

        // 두 번째 관리자가 있으면 가능
        $second = $this->f->user('admin2', 'Passw0rd!x', 'admin');
        $this->f->users->changeRole($this->admin, $this->adminId, 'user');
        $this->assertSame('user', $this->f->row($this->adminId)['role']);
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->setStatus(Actor::fromUser($this->f->row($second), null), $second, 'inactive'));
        $this->assertSame('last_admin', $e->errorCode, '이제 admin2가 마지막 관리자');
    }

    public function testDeactivationAndRoleChangeInvalidateSessionsImmediately(): void
    {
        $kim = $this->f->user('kim');
        $this->f->sessions->rotate(null, $kim, null, null);
        $this->f->sessions->rotate(null, $kim, null, null);
        $this->f->users->changeRole($this->admin, $kim, 'admin');
        $this->assertSame(0, $this->sessionsOf($kim), '역할 변경 → 세션 폐기');

        $this->f->sessions->rotate(null, $kim, null, null);
        $this->f->users->setStatus($this->admin, $kim, 'inactive');
        $this->assertSame(0, $this->sessionsOf($kim), '비활성화 → 세션 폐기');
        $this->assertFalse($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.3')->ok, '비활성 계정 로그인 거부');

        $this->f->users->setStatus($this->admin, $kim, 'active');
        $this->assertTrue($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.3')->ok, '다시 활성화');
    }

    public function testTempPasswordInvalidatesSessionsAndOldPassword(): void
    {
        $kim = $this->f->user('kim');
        $this->f->sessions->rotate(null, $kim, null, null);
        $r = $this->f->users->issueTempPassword($this->admin, $kim);
        $this->assertSame(0, $this->sessionsOf($kim));
        $row = $this->f->row($kim);
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertFalse(password_verify('Passw0rd!x', (string) $row['password_hash']));
        $this->assertTrue(password_verify($r['temp_password'], (string) $row['password_hash']));
        $this->assertSame(0, (int) $row['failed_login_count'], '임시 비밀번호 발급은 잠금도 푼다');
    }

    public function testChangeOwnPasswordAppliesPolicyAndClearsTempState(): void
    {
        $kim = $this->f->user('kim');
        $temp = $this->f->users->issueTempPassword($this->admin, $kim)['temp_password'];
        [, $current] = $this->f->sessions->rotate(null, $kim, null, null);
        [, $other] = $this->f->sessions->rotate(null, $kim, null, null);

        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->changeOwnPassword($kim, 'wrong', 'NewPassw0rd!', $current->idHash));
        $this->assertSame('current_password_incorrect', $e->errorCode);
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->changeOwnPassword($kim, $temp, 'short1A', $current->idHash));
        $this->assertSame('password_policy', $e->errorCode);
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->changeOwnPassword($kim, $temp, $temp, $current->idHash));
        $this->assertSame('password_reused', $e->errorCode);

        $this->f->users->changeOwnPassword($kim, $temp, 'NewPassw0rd!', $current->idHash);
        $row = $this->f->row($kim);
        $this->assertSame(0, (int) $row['must_change_password']);
        $this->assertSame(null, $row['temp_password_expires_at']);
        $this->assertSame(1, $this->sessionsOf($kim), '다른 세션은 폐기, 현재 세션은 유지');
        $this->assertSame(1, (int) $this->f->db->value('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [$current->idHash]));
        $this->assertTrue($this->f->login->attempt('kim', 'NewPassw0rd!', '10.0.0.2')->ok);
    }

    public function testUnlock(): void
    {
        $kim = $this->f->user('kim');
        for ($i = 0; $i < 5; $i++) {
            $this->f->login->attempt('kim', 'bad', '10.0.0.' . $i);
        }
        $this->assertFalse($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.1.1')->ok, '잠김');
        $this->f->users->unlock($this->admin, $kim);
        $this->assertTrue($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.1.1')->ok, '관리자가 즉시 해제');
    }

    public function testAdminActionsAreAuditedWithIntactChain(): void
    {
        $r = $this->f->users->create($this->admin, ['login_id' => 'kim', 'display_name' => '김']);
        $kim = (int) $r['user']['id'];
        $this->f->users->updateProfile($this->f->row($this->adminId), '10.0.0.1', $kim, ['display_name' => '김개발', 'email' => 'k@example.com']);
        $this->f->users->changeRole($this->admin, $kim, 'admin');
        $this->f->users->setStatus($this->admin, $kim, 'inactive');
        $this->f->users->setStatus($this->admin, $kim, 'active');
        $this->f->users->issueTempPassword($this->admin, $kim);
        $this->f->users->unlock($this->admin, $kim);

        $rows = $this->f->db->all('SELECT actor_user_id, actor_label, action, target_type, target_id, details, ip FROM audit_log ORDER BY id');
        $this->assertSame(
            ['user.create', 'user.update', 'user.role_change', 'user.deactivate', 'user.activate', 'user.temp_password', 'user.unlock'],
            array_column($rows, 'action'),
        );
        $this->assertSame($this->adminId, (int) $rows[0]['actor_user_id']);
        $this->assertSame('admin', $rows[0]['actor_label']);
        $this->assertSame('10.0.0.1', $rows[0]['ip']);
        $this->assertSame(['user', (string) $kim], [$rows[2]['target_type'], $rows[2]['target_id']]);
        $this->assertSame(['from' => 'user', 'to' => 'admin'], json_decode((string) $rows[2]['details'], true));
        $all = implode("\n", array_column($rows, 'details'));
        $this->assertNotContains($r['temp_password'], $all, '감사 로그에 임시 비밀번호 없음');
        $this->assertNotContains('$2y$', $all, '해시도 없음');
        $this->assertSame(['ok' => true, 'checked' => 7, 'broken_at' => null, 'reason' => null], $this->f->audit->verify());
    }

    public function testTamperingIsDetectedAndUpdatesAreRefused(): void
    {
        $this->f->users->create($this->admin, ['login_id' => 'kim', 'display_name' => '김']);
        $this->f->users->create($this->admin, ['login_id' => 'lee', 'display_name' => '이']);
        $this->f->users->create($this->admin, ['login_id' => 'park', 'display_name' => '박']);

        $e = $this->assertThrows(\PDOException::class, fn () => $this->f->db->execute("UPDATE audit_log SET actor_label = 'x' WHERE id = 2"));
        $this->assertContains('audit_log_is_append_only', $e->getMessage(), 'DB가 수정 거부(트리거)');
        $e = $this->assertThrows(\PDOException::class, fn () => $this->f->db->execute('DELETE FROM audit_log WHERE id = 2'));
        $this->assertContains('audit_log_is_append_only', $e->getMessage(), 'DB가 삭제 거부');

        // 트리거를 우회해(파일을 직접 고치는 상황) 중간 행을 바꾸면 검증이 실패한다
        $this->f->db->pdo()->exec('DROP TRIGGER audit_log_no_update');
        $this->f->db->execute("UPDATE audit_log SET details = '{\"login_id\":\"evil\"}' WHERE id = 2");
        $v = $this->f->audit->verify();
        $this->assertFalse($v['ok']);
        $this->assertSame(2, $v['broken_at']);
        $this->assertSame('hash_mismatch', $v['reason']);
    }
}
