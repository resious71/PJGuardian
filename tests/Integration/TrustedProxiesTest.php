<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\ForwardedHeaders;
use PJGuardian\Http\Request;
use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigException;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 신뢰 프록시(P32B, 명세 19.10 P10 — [security] trusted_proxies). 접속 주소·https 판정은 Http\ForwardedHeaders 한 곳:
 * 목록 안/밖, IPv6·CIDR, X-Forwarded-For 위조(왼쪽 값·목록 밖에서 온 헤더), 잘못된 값, 기본값(빈 목록) = P32B 전과 같음,
 * 그 결과가 /health 허용 IP·HTTPS 강제·HSTS·감사 로그 IP에 그대로 쓰임, 잘못된 CIDR은 설정 오류.
 */
final class TrustedProxiesTest extends TestCase
{
    private static function req(string $remote, array $headers = [], bool $secure = false): Request
    {
        return new Request('GET', '/x', [], array_change_key_case($headers), '', $secure, $remote);
    }

    public function testResolveRules(): void
    {
        $p = ['10.0.0.5', '10.0.1.0/24', '2001:db8::/32'];
        $ip = static fn (Request $r): array => [$r->clientIp, $r->secure];
        // 기본(빈 목록): 헤더를 보지 않음 — 지금 동작과 같음
        $r = self::req('10.0.0.5', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https']);
        $this->assertSame(['10.0.0.5', false], $ip(ForwardedHeaders::resolve($r, [])));
        // 목록 안의 프록시에서 온 요청: 오른쪽부터 프록시를 건너뛴 첫 주소, Proto
        $this->assertSame(['203.0.113.7', true], $ip(ForwardedHeaders::resolve($r, $p)));
        $this->assertSame(['198.51.100.1', true], $ip(ForwardedHeaders::resolve(self::req('10.0.1.9', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.1, 10.0.0.5', 'X-Forwarded-Proto' => 'http, https']), $p)),
            '왼쪽(클라이언트가 넣을 수 있는 값) 대신 가까운 쪽부터, 프록시 둘 건너뜀');
        $this->assertSame(['2001:db9::1', false], $ip(ForwardedHeaders::resolve(self::req('2001:db8::10', ['X-Forwarded-For' => '2001:db9::1', 'X-Forwarded-Proto' => 'http'], true), $p)), 'IPv6·http로 낮춤');
        // 목록 밖에서 온 헤더는 위조로 보고 무시
        $this->assertSame(['192.0.2.50', false], $ip(ForwardedHeaders::resolve(self::req('192.0.2.50', ['X-Forwarded-For' => '10.0.0.5', 'X-Forwarded-Proto' => 'https']), $p)));
        // 형식이 틀린 값에서 멈춤 → 직접 접속 주소, 프록시만 있는 체인 → 직접 접속 주소, 헤더 없음 → 그대로
        $this->assertSame('10.0.0.5', ForwardedHeaders::resolve(self::req('10.0.0.5', ['X-Forwarded-For' => 'evil, not-an-ip']), $p)->clientIp);
        $this->assertSame('10.0.0.5', ForwardedHeaders::resolve(self::req('10.0.0.5', ['X-Forwarded-For' => '10.0.1.3']), $p)->clientIp);
        $this->assertSame(['10.0.0.5', true], $ip(ForwardedHeaders::resolve(self::req('10.0.0.5', [], true), $p)));
        $this->assertSame(false, ForwardedHeaders::resolve(self::req('10.0.0.5', ['X-Forwarded-Proto' => 'gopher']), $p)->secure, '모르는 값은 서버 판정 그대로');
        // 바뀐 사본도 속성(요청 ID 등)을 이어 받음
        $withAttr = self::req('10.0.0.5', ['X-Forwarded-For' => '203.0.113.7'])->withAttribute('request_id', 'r1');
        $this->assertSame('r1', ForwardedHeaders::resolve($withAttr, $p)->attribute('request_id'));
    }

    public function testInvalidListIsConfigError(): void
    {
        $dir = $this->tempDir('tp');
        foreach (['10.0.0.300', '10.0.0.0/33', 'proxy.local', '2001:db8::/129'] as $bad) {
            try {
                Config::load(TestApp::writeIni("{$dir}/c.ini", ['security' => ['trusted_proxies' => $bad]]));
                $this->fail("거부해야 함: {$bad}");
            } catch (ConfigException) {
                $this->assertTrue(true);
            }
        }
        $ini = TestApp::writeIni("{$dir}/c.ini", ['security' => ['trusted_proxies' => '10.0.0.5, 2001:db8::/32']]);
        $this->assertSame(['10.0.0.5', '2001:db8::/32'], Config::load($ini)->get('security.trusted_proxies'));
    }

    public function testHealthHttpsHstsAndAuditUseResolvedAddress(): void
    {
        $dir = $this->tempDir('tp2');
        $app = TestApp::make($dir, ['security' => ['trusted_proxies' => '10.0.0.5', 'require_https' => 'true'], 'app' => ['base_url' => 'https://pjg.example.test'],
            'health' => ['allowed_ips' => '203.0.113.7'], 'paths' => ['work_dir' => $dir . '/work']]);
        $health = static fn (string $remote, array $h) => $app->handle(new Request('GET', '/health', [], array_change_key_case($h), '', true, $remote))->status;
        $this->assertTrue($health('10.0.0.5', ['X-Forwarded-For' => '203.0.113.7']) !== 404, '신뢰 프록시를 거친 허용 IP(판정 응답 200/503 — 이 시험엔 워커가 없어 503)');
        $this->assertSame(404, $health('10.0.0.9', ['X-Forwarded-For' => '203.0.113.7']), '목록 밖에서 온 헤더는 위조 — 허용 IP로 보지 않음');
        $this->assertSame(404, $health('10.0.0.5', []), '프록시 자신의 주소는 허용 목록에 없음');
        // HTTPS 강제: 프록시가 https라고 알려 주면 통과 + HSTS, 목록 밖에서 같은 헤더는 무시(리다이렉트)
        $page = static fn (string $remote, array $h) => $app->handle(new Request('GET', '/login', [], array_change_key_case($h), '', false, $remote));
        $ok = $page('10.0.0.5', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7']);
        $this->assertSame(200, $ok->status);
        $this->assertTrue($ok->header('Strict-Transport-Security') !== null, 'https로 판정 → HSTS');
        $this->assertSame(301, $page('192.0.2.50', ['X-Forwarded-Proto' => 'https'])->status, '위조한 Proto는 무시 → https로 이동');
        // 감사 로그의 IP = 프록시 뒤 실제 사용자 주소
        TestDb::user($app->db(), 'admin', 'admin');
        $c = new TestClient($app, '10.0.0.5');
        $this->assertSame(200, $c->login('admin', 'Passw0rd!x')->status);
        $c->request('POST', '/api/v1/projects', [], ['name' => 'p', 'language' => 'python'], null, ['X-Forwarded-For' => '198.51.100.23']);
        $this->assertSame('198.51.100.23', (string) $app->db()->value("SELECT ip FROM audit_log WHERE action = 'project.create'"));
    }
}
