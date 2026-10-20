<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Auth\SessionService;
use PJGuardian\Tests\Support\AuthFixture;
use PJGuardian\Tests\TestCase;

/**
 * DB 세션(명세 3): 30분 무활동 만료, 로그인 시 ID 재생성, 로그아웃 시 서버에서 폐기,
 * 활동 시각 갱신은 간격(60초) 이내면 건너뜀, DB에는 세션 ID의 해시만 저장.
 */
final class SessionServiceTest extends TestCase
{
    private AuthFixture $f;

    public function setUp(): void
    {
        $this->f = new AuthFixture($this->tempDir('session'));
    }

    public function testStoresOnlyHashOfSessionId(): void
    {
        [$raw, $s] = $this->f->sessions->start('10.0.0.1', 'UA');
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $raw), '32바이트 base64url');
        $this->assertSame(SessionService::hashId($raw), $s->idHash);
        $this->assertSame(0, (int) $this->f->db->value('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [$raw]), '원래 ID는 DB에 없음');
        $this->assertSame(null, $s->userId, '로그인 전 세션');
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $s->csrfToken), 'CSRF 토큰 발급');
    }

    public function testExpiresAfterThirtyMinutesOfInactivity(): void
    {
        $uid = $this->f->user('kim');
        [$raw] = $this->f->sessions->rotate(null, $uid, '10.0.0.1', 'UA');
        $this->f->clock->advance(30 * 60);
        $this->assertTrue($this->f->sessions->resolve($raw)->session !== null, '정확히 30분은 아직 유효');
        $this->f->clock->advance(1);
        $lookup = $this->f->sessions->resolve($raw);
        $this->assertSame(null, $lookup->session, '30분 1초 무활동 → 만료');
        $this->assertTrue($lookup->expired, '만료로 판정(로그인 화면에 안내)');
        $this->assertSame(0, (int) $this->f->db->value('SELECT COUNT(*) FROM sessions'), '만료된 세션은 지움');
    }

    public function testActivityExtendsSessionAndTouchIsThrottled(): void
    {
        $uid = $this->f->user('kim');
        [$raw, $s] = $this->f->sessions->rotate(null, $uid, null, null);
        $writes = static fn (AuthFixture $f): string => (string) $f->db->value('SELECT last_activity_at FROM sessions');

        $this->f->clock->advance(59);
        $this->f->sessions->touch($this->f->sessions->resolve($raw)->session);
        $this->assertSame('2026-10-02T12:00:00Z', $writes($this->f), '60초 이내 활동은 DB에 쓰지 않음');

        $this->f->clock->advance(1);
        $this->f->sessions->touch($this->f->sessions->resolve($raw)->session);
        $this->assertSame('2026-10-02T12:01:00Z', $writes($this->f), '60초가 지나면 갱신');

        // 29분마다 활동하면 계속 유지
        for ($i = 0; $i < 4; $i++) {
            $this->f->clock->advance(29 * 60);
            $cur = $this->f->sessions->resolve($raw)->session;
            $this->assertTrue($cur !== null, "활동 {$i}회차 유지");
            $this->f->sessions->touch($cur);
        }
    }

    public function testLoginRegeneratesSessionId(): void
    {
        $uid = $this->f->user('kim');
        [$anonRaw, $anon] = $this->f->sessions->start('10.0.0.1', 'UA');
        [$newRaw, $new] = $this->f->sessions->rotate($anon, $uid, '10.0.0.1', 'UA');
        $this->assertTrue($newRaw !== $anonRaw, '새 세션 ID');
        $this->assertTrue($new->csrfToken !== $anon->csrfToken, '새 CSRF 토큰');
        $this->assertSame(null, $this->f->sessions->resolve($anonRaw)->session, '로그인 전 ID는 폐기(고정 공격 방지)');
        $this->assertSame($uid, $this->f->sessions->resolve($newRaw)->session->userId);
    }

    public function testDestroyRemovesServerSideSession(): void
    {
        $uid = $this->f->user('kim');
        [$raw, $s] = $this->f->sessions->rotate(null, $uid, null, null);
        $this->f->sessions->destroy($s);
        $this->assertSame(null, $this->f->sessions->resolve($raw)->session, '로그아웃 후 같은 쿠키로는 접근 불가');
        $this->assertFalse($this->f->sessions->resolve($raw)->expired, '만료가 아니라 없음');
    }

    public function testDestroyForUserRemovesAllSessionsOfThatUserOnly(): void
    {
        $kim = $this->f->user('kim');
        $lee = $this->f->user('lee');
        [$k1] = $this->f->sessions->rotate(null, $kim, null, null);
        [$k2, $k2s] = $this->f->sessions->rotate(null, $kim, null, null);
        [$l1] = $this->f->sessions->rotate(null, $lee, null, null);
        $this->assertSame(1, $this->f->sessions->destroyForUser($kim, $k2s->idHash), '현재 세션 제외 옵션');
        $this->assertSame(null, $this->f->sessions->resolve($k1)->session);
        $this->assertTrue($this->f->sessions->resolve($k2)->session !== null);
        $this->assertSame(1, $this->f->sessions->destroyForUser($kim));
        $this->assertTrue($this->f->sessions->resolve($l1)->session !== null, '다른 사용자는 유지');
    }

    public function testMalformedIdIsIgnored(): void
    {
        foreach (['', 'short', str_repeat('a', 44), "../../etc", str_repeat('!', 43)] as $bad) {
            $this->assertSame(null, $this->f->sessions->resolve($bad)->session, json_encode($bad));
        }
    }

    public function testPurgeExpired(): void
    {
        $uid = $this->f->user('kim');
        $this->f->sessions->rotate(null, $uid, null, null);
        $this->f->clock->advance(10 * 60);
        $this->f->sessions->start(null, null);
        $this->f->clock->advance(25 * 60);
        $this->assertSame(1, $this->f->sessions->purgeExpired(), '35분 지난 것만');
        $this->assertSame(1, (int) $this->f->db->value('SELECT COUNT(*) FROM sessions'));
    }
}
