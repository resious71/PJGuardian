<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Auth\PasswordPolicy;
use PJGuardian\Support\Navigation;
use PJGuardian\Tests\Support\AuthFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\TestCase;

/**
 * 최초 관리자의 고정 초기 비밀번호(X02, 사용자 확정 결정).
 * - createInitialAdmin()만 비밀번호 정책을 건너뛴다(유일한 예외). 다른 모든 경로는 정책을 그대로 거친다.
 * - 첫 로그인 때 변경을 강제하지 않음(2026-10-13 사용자 결정 — X02의 변경 강제를 없앰, 의도된 단언 변경), 24시간 만료는 적용하지 않음, 잠금·IP 제한은 그대로.
 * - 바꾸기 전까지 password_kind = 'initial'(하드닝 점검 경고, 공개 정보 initial_password_pending).
 */
final class InitialAdminTest extends TestCase
{
    private const INITIAL = 'admin';
    private string $logDir;
    private AuthFixture $f;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('initadmin');
        $this->f = new AuthFixture($this->logDir);
    }

    private function create(): int
    {
        return (int) $this->f->users->createInitialAdmin(Actor::cli(), 'admin', '관리자', null)['id'];
    }

    public function testInitialAdminLogsInWithFixedPasswordWithoutForcedChange(): void
    {
        $id = $this->create();
        $row = $this->f->row($id);
        $this->assertSame('admin', $row['role']);
        $this->assertSame(0, (int) $row['must_change_password'], '첫 로그인 때 변경을 강제하지 않음');
        $this->assertSame(null, $row['temp_password_expires_at'], '24시간 만료를 적용하지 않음');
        $this->assertSame('initial', $row['password_kind']);

        $this->f->clock->advance(30 * 86400);
        $r = $this->f->login->attempt('admin', self::INITIAL, '10.0.0.1');
        $this->assertTrue($r->ok, '30일이 지나도 초기 비밀번호로 로그인(만료 없음)');
        $this->assertFalse($r->user['must_change_password']);
        $this->assertTrue($r->user['initial_password_pending'], '공개 정보에 초기 비밀번호 미변경 표시');
    }

    public function testRefusesWhenActiveAdminExists(): void
    {
        $this->create();
        $e = $this->assertThrows(ApiException::class, fn () => $this->create());
        $this->assertSame('admin_exists', $e->errorCode);
    }

    public function testNewPasswordMustSatisfyPolicyAndOldOneStopsWorking(): void
    {
        $id = $this->create();
        [, $s] = $this->f->sessions->rotate(null, $id, null, null);
        // 정책(명세 3장: 8자 이상, 2종 이상) 위반 값: 고정 초기값, 7자, 1종(소문자), 1종(숫자)
        foreach (['admin', 'Abc1234', 'abcdefghij', '12345678'] as $weak) {
            $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->changeOwnPassword($id, self::INITIAL, $weak, $s->idHash));
            $this->assertSame('password_policy', $e->errorCode, '거부: ' . $weak);
        }
        $this->f->users->changeOwnPassword($id, self::INITIAL, 'N3w-Admin-Pass', $s->idHash);
        $row = $this->f->row($id);
        $this->assertSame(0, (int) $row['must_change_password']);
        $this->assertSame('user', $row['password_kind']);
        $this->assertFalse($this->f->login->attempt('admin', self::INITIAL, '10.0.0.1')->ok, '변경 후 admin/admin 거부');
        $this->assertTrue($this->f->login->attempt('admin', 'N3w-Admin-Pass', '10.0.0.1')->ok);
    }

    public function testNoOtherPathCanSetTheFixedPassword(): void
    {
        $adminId = $this->create();
        $actor = Actor::fromUser($this->f->row($adminId), null);
        $policy = new PasswordPolicy(10, 3);

        // 사용자 생성 API: 비밀번호를 받지 않고 정책을 만족하는 임시 비밀번호를 만든다
        $r = $this->f->users->create($actor, ['login_id' => 'kim', 'display_name' => 'kim', 'password' => self::INITIAL]);
        $this->assertTrue($r['temp_password'] !== self::INITIAL);
        $this->assertSame([], $policy->violations($r['temp_password']));
        $this->assertFalse($this->f->login->attempt('kim', self::INITIAL, '10.0.0.2')->ok);

        // 본인 비밀번호 변경
        $kim = (int) $r['user']['id'];
        $e = $this->assertThrows(ApiException::class, fn () => $this->f->users->changeOwnPassword($kim, $r['temp_password'], self::INITIAL, null));
        $this->assertSame('password_policy', $e->errorCode);

        // 임시 비밀번호 발급(관리자 화면, CLI reset-password 공통)
        $t = $this->f->users->issueTempPassword($actor, $kim);
        $this->assertTrue($t['temp_password'] !== self::INITIAL);

        // 표준 입력으로 비밀번호를 주는 최초 관리자 생성(--password-stdin)도 정책 적용
        $f2 = new AuthFixture($this->logDir);
        $e = $this->assertThrows(ApiException::class, fn () => $f2->users->createAdmin(Actor::cli(), 'root', 'root', null, self::INITIAL));
        $this->assertSame('password_policy', $e->errorCode);
    }

    public function testResetPasswordIssuesRandomTemporaryNotFixedValue(): void
    {
        $id = $this->create();
        $t = $this->f->users->issueTempPassword(Actor::cli(), $id, 'cli.user_password_reset');
        $this->assertTrue($t['temp_password'] !== self::INITIAL, '무작위 임시 비밀번호');
        $row = $this->f->row($id);
        $this->assertSame('temporary', $row['password_kind']);
        $this->assertTrue($row['temp_password_expires_at'] !== null, '임시 비밀번호에는 24시간 만료');
        $this->assertFalse($this->f->login->attempt('admin', self::INITIAL, '10.0.0.1')->ok);
    }

    public function testLockoutStillApplies(): void
    {
        $this->create();
        for ($i = 0; $i < 5; $i++) {
            $this->f->login->attempt('admin', 'wrong', '10.0.0.' . $i);
        }
        $this->assertFalse($this->f->login->attempt('admin', self::INITIAL, '10.0.1.1')->ok, '5회 실패 후 잠금');
    }

    public function testInitialAdminPendingListForHardening(): void
    {
        $id = $this->create();
        $this->assertSame(['admin'], $this->f->users->initialPasswordPendingAdmins());
        [, $s] = $this->f->sessions->rotate(null, $id, null, null);
        $this->f->users->changeOwnPassword($id, self::INITIAL, 'N3w-Admin-Pass', $s->idHash);
        $this->assertSame([], $this->f->users->initialPasswordPendingAdmins());
    }

    public function testAuditAndLogsDoNotContainThePassword(): void
    {
        $id = $this->create();
        $this->f->login->attempt('admin', self::INITIAL, '10.0.0.1');
        [, $s] = $this->f->sessions->rotate(null, $id, null, null);
        $this->f->users->changeOwnPassword($id, self::INITIAL, 'N3w-Admin-Pass', $s->idHash);

        $row = $this->f->db->one("SELECT details FROM audit_log WHERE action = 'user.create'");
        $details = json_decode((string) $row['details'], true);
        $this->assertSame('initial_fixed', $details['auth_setup'] ?? null, '방식만 남김');
        // 로그인 ID와 역할 이름은 정당하게 'admin'이므로(비밀번호와 같은 글자) 값 비교에서 뺀다
        unset($details['login_id'], $details['role']);
        $this->assertFalse(in_array(self::INITIAL, array_map('strval', array_values($details)), true), '감사 로그 세부에 비밀번호 값 없음');

        $log = $this->f->logText($this->logDir);
        foreach (explode("\n", trim($log)) as $line) {
            $ctx = json_decode($line, true)['context'] ?? [];
            unset($ctx['login_id'], $ctx['role'], $ctx['by'], $ctx['to']); // 로그인 ID·역할·행위자 이름은 제외
            $this->assertFalse(in_array(self::INITIAL, array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $ctx), true), '로그 문맥에 비밀번호 값 없음: ' . $line);
        }
        $this->assertNotContains('N3w-Admin-Pass', $log);
    }

    public function testHttpFlowDoesNotForcePasswordChange(): void
    {
        $app = TestApp::make($this->tempDir('initadmin_http'));
        $app->users()->createInitialAdmin(Actor::cli(), 'admin', '관리자', null);
        $c = new TestClient($app);
        $login = $c->login('admin', self::INITIAL);
        $this->assertSame(200, $login->status);
        $this->assertFalse($login->decoded()['data']['user']['must_change_password']);
        $this->assertSame(200, $c->get(Navigation::HOME)->status, '변경 화면으로 보내지 않고 바로 홈');
        $this->assertSame(200, $c->get('/api/v1/users')->status, '관리자 기능을 바로 쓸 수 있음');
        // 스스로 바꾸면 정책 적용, 이후 admin/admin 거부

        $weak = $c->post('/api/v1/auth/password', ['current_password' => self::INITIAL, 'new_password' => 'admin']);
        $this->assertSame('password_policy', $weak->decoded()['error']['code']);
        $this->assertSame(200, $c->post('/api/v1/auth/password', ['current_password' => self::INITIAL, 'new_password' => 'N3w-Admin-Pass'])->status);
        $this->assertSame(200, $c->get(Navigation::HOME)->status);
        $this->assertSame(401, (new TestClient($app))->login('admin', self::INITIAL)->status, '이후 admin/admin 거부');
    }
}
