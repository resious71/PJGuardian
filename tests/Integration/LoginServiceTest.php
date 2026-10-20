<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Tests\Support\AuthFixture;
use PJGuardian\Tests\TestCase;

/**
 * 로그인 판정(명세 3, 13, 19.5)
 * - 계정 잠금: 5회 연속 실패 → 15분, 잠금 중에는 올바른 비밀번호도 거부, 15분 후 허용, 성공하면 실패 횟수 초기화
 * - IP 제한: 같은 IP 10분 내 20회 실패 → 15분 차단(다른 계정이어도), 시간 경과 후 해제
 * - 계정 열거 방지: 없는 ID와 틀린 비밀번호의 결과가 같고 소요 시간이 비슷함(더미 해시 검증)
 * - 비활성 계정 거부, 임시 비밀번호 24시간 만료
 */
final class LoginServiceTest extends TestCase
{
    private string $logDir;
    private AuthFixture $f;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('login');
        $this->f = new AuthFixture($this->logDir);
    }

    public function testSuccessfulLogin(): void
    {
        $id = $this->f->user('kim');
        $r = $this->f->login->attempt('KIM', 'Passw0rd!x', '10.0.0.1');
        $this->assertTrue($r->ok, '로그인 ID는 대소문자 무시');
        $this->assertSame($id, (int) $r->user['id']);
        $this->assertFalse(array_key_exists('password_hash', $r->user), '결과에 비밀번호 해시 없음');
    }

    public function testFiveFailuresLockForFifteenMinutesEvenWithCorrectPassword(): void
    {
        $id = $this->f->user('kim');
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame('login_failed', $this->f->login->attempt('kim', 'wrong' . $i, '10.0.0.1')->code);
        }
        $this->assertSame(4, (int) $this->f->row($id)['failed_login_count']);
        $this->assertSame(null, $this->f->row($id)['locked_until'], '4회까지는 잠기지 않음');

        $this->assertSame('login_failed', $this->f->login->attempt('kim', 'wrong5', '10.0.0.1')->code);
        $this->assertSame('2026-10-02T12:15:00Z', $this->f->row($id)['locked_until'], '5회째 실패 → 15분 잠금');

        $locked = $this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1');
        $this->assertFalse($locked->ok, '잠금 중에는 올바른 비밀번호도 거부');
        $this->assertSame('login_failed', $locked->code, '잠금 여부를 따로 알리지 않음(존재 여부 노출 방지)');

        $this->f->clock->advance(14 * 60 + 59);
        $this->assertFalse($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1')->ok, '14분 59초: 아직 잠김');

        $this->f->clock->advance(1);
        $this->assertTrue($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1')->ok, '15분 후 허용');
        $row = $this->f->row($id);
        $this->assertSame(0, (int) $row['failed_login_count'], '성공하면 실패 횟수 초기화');
        $this->assertSame(null, $row['locked_until']);
    }

    public function testSuccessResetsConsecutiveFailures(): void
    {
        $id = $this->f->user('kim');
        for ($i = 0; $i < 4; $i++) {
            $this->f->login->attempt('kim', 'wrong', '10.0.0.1');
        }
        $this->assertTrue($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1')->ok);
        for ($i = 0; $i < 4; $i++) {
            $this->f->login->attempt('kim', 'wrong', '10.0.0.1');
        }
        $this->assertSame(null, $this->f->row($id)['locked_until'], '연속이 아니므로(중간 성공) 잠기지 않음');
        $this->assertTrue($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1')->ok);
    }

    public function testIpBlockedAfterTwentyFailuresInTenMinutesAcrossAccounts(): void
    {
        $this->f->user('victim');
        // 서로 다른(대부분 없는) 계정으로 19회 실패, 10분 안에
        for ($i = 0; $i < 19; $i++) {
            $this->assertSame('login_failed', $this->f->login->attempt('nobody' . $i, 'x', '10.9.9.9')->code);
            $this->f->clock->advance(30);
        }
        $this->assertTrue($this->f->login->attempt('victim', 'Passw0rd!x', '10.9.9.9')->ok, '19회까지는 차단 안 됨');
        $this->assertSame('login_failed', $this->f->login->attempt('nobody19', 'x', '10.9.9.9')->code, '20번째 실패');

        $blocked = $this->f->login->attempt('victim', 'Passw0rd!x', '10.9.9.9');
        $this->assertFalse($blocked->ok, '차단된 IP는 다른 계정·올바른 비밀번호도 거부');
        $this->assertSame('ip_blocked', $blocked->code);
        $this->assertTrue($this->f->login->attempt('victim', 'Passw0rd!x', '10.0.0.2')->ok, '다른 IP는 영향 없음');

        $this->f->clock->advance(15 * 60 - 1);
        $this->assertSame('ip_blocked', $this->f->login->attempt('victim', 'Passw0rd!x', '10.9.9.9')->code, '15분 직전: 아직 차단');
        $this->f->clock->advance(1);
        $this->assertTrue($this->f->login->attempt('victim', 'Passw0rd!x', '10.9.9.9')->ok, '15분 후 해제');
    }

    public function testFailuresSpreadOverMoreThanTenMinutesDoNotBlock(): void
    {
        $this->f->user('kim');
        for ($i = 0; $i < 25; $i++) {
            $this->f->login->attempt('ghost', 'x', '10.8.8.8');
            $this->f->clock->advance(40); // 20회가 760초에 걸침(> 10분)
        }
        $this->assertTrue($this->f->login->attempt('kim', 'Passw0rd!x', '10.8.8.8')->ok);
    }

    public function testBlockedAttemptsDoNotExtendTheBlock(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->f->login->attempt('ghost', 'x', '10.7.7.7');
        }
        $this->f->clock->advance(10 * 60);
        $this->assertSame('ip_blocked', $this->f->login->attempt('ghost', 'x', '10.7.7.7')->code);
        $this->f->clock->advance(5 * 60);
        $this->assertSame('login_failed', $this->f->login->attempt('ghost', 'x', '10.7.7.7')->code, '차단 중 시도는 기록하지 않으므로 15분 뒤 해제');
    }

    public function testUnknownUserAndWrongPasswordLookTheSame(): void
    {
        $this->f->user('kim');
        $a = $this->f->login->attempt('kim', 'wrong-password', '10.0.0.1');
        $b = $this->f->login->attempt('no-such-user', 'wrong-password', '10.0.0.2');
        $this->assertSame([$a->ok, $a->code, $a->user], [$b->ok, $b->code, $b->user]);
    }

    public function testUnknownUserTakesAboutAsLongAsWrongPassword(): void
    {
        // 더미 해시를 같은 비용으로 검증해야 시간이 비슷하다. 측정이 보이도록 비용 10으로(약 수십 ms)
        $f = new AuthFixture($this->logDir, ['bcrypt_cost' => '10']);
        $f->db->execute(
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at)"
            . " VALUES ('kim', 'kim', 'user', 'active', 'local', ?, '2026-10-02T12:00:00Z', '2026-10-02T12:00:00Z')",
            [password_hash('Passw0rd!x', PASSWORD_BCRYPT, ['cost' => 10])],
        );
        $time = static function (callable $fn): float {
            $best = INF;
            for ($i = 0; $i < 3; $i++) {
                $t = hrtime(true);
                $fn();
                $best = min($best, (hrtime(true) - $t) / 1e6);
            }
            return $best;
        };
        $n = 0;
        $wrong = $time(static function () use ($f, &$n): void { $f->login->attempt('kim', 'wrong', '10.1.0.' . (++$n)); });
        $unknown = $time(static function () use ($f, &$n): void { $f->login->attempt('ghost', 'wrong', '10.1.0.' . (++$n)); });
        $ratio = $unknown / max($wrong, 0.001);
        $this->assertTrue($wrong > 5, "bcrypt 검증이 실제로 수행됨(틀린 비밀번호 {$wrong}ms)");
        $this->assertTrue($ratio > 0.6 && $ratio < 1.6, sprintf('소요 시간 비슷함: 틀린 비밀번호 %.1fms, 없는 ID %.1fms', $wrong, $unknown));
    }

    public function testInactiveAccountIsRejectedLikeAWrongPassword(): void
    {
        $this->f->user('admin', 'Passw0rd!x', 'admin');
        $this->f->user('old', 'Passw0rd!x', 'user', 'inactive');
        $r = $this->f->login->attempt('old', 'Passw0rd!x', '10.0.0.1');
        $this->assertFalse($r->ok);
        $this->assertSame('login_failed', $r->code);
    }

    public function testTemporaryPasswordExpiresAfter24Hours(): void
    {
        $adminId = $this->f->user('admin', 'Passw0rd!x', 'admin');
        $id = $this->f->user('kim');
        $actor = \PJGuardian\Services\Actor::fromUser($this->f->row($adminId), '10.0.0.1');
        $issued = $this->f->users->issueTempPassword($actor, $id);
        $this->assertSame('2026-10-03T12:00:00Z', $issued['expires_at']);

        $this->f->clock->advance(24 * 3600 - 1);
        $ok = $this->f->login->attempt('kim', $issued['temp_password'], '10.0.0.1');
        $this->assertTrue($ok->ok, '24시간 직전에는 로그인 가능');
        $this->assertTrue((bool) $ok->user['must_change_password'], '변경 강제 표시');

        $this->f->clock->advance(1);
        $expired = $this->f->login->attempt('kim', $issued['temp_password'], '10.0.0.1');
        $this->assertFalse($expired->ok, '24시간 후 만료');
        $this->assertSame('temp_password_expired', $expired->code);
        $this->assertFalse($this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1')->ok, '이전 비밀번호는 이미 무효');
    }

    public function testLogsDoNotContainPasswords(): void
    {
        $this->f->user('kim');
        $this->f->login->attempt('kim', 'Wr0ng-S3cret-Value!', '10.0.0.1');
        $this->f->login->attempt('kim', 'Passw0rd!x', '10.0.0.1');
        $log = $this->f->logText($this->logDir);
        $this->assertContains('"login_id":"kim"', $log, '로그인 시도는 기록됨');
        $this->assertNotContains('Wr0ng-S3cret-Value!', $log);
        $this->assertNotContains('Passw0rd!x', $log);
    }
}
