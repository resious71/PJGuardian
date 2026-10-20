<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Auth\Oidc\OidcHttp;
use PJGuardian\Services\Repo\HostPolicy;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Tests\TestCase;

/**
 * 서버가 밖으로 연결하는 곳의 SSRF 규칙 한 표(P31 A-2). 자세한 시험은 각 기능의 시험(RepoSecurityTest·HostProbeTest·OidcSecurityTest·SamlLoginTest·AlertMailTest)에 있고,
 * 여기서는 **우회 표기 모음**을 같은 판정 함수에 한 번에 넣어 규칙이 한 곳(HostPolicy)에서 나오는지 확인한다.
 *
 * | 연결 지점 | 누가 주소를 넣나 | 규칙 |
 * | 저장소 가져오기·연결 테스트(Git·Perforce) | admin(허용 호스트 목록) | 목록에 있는 호스트만 + 풀린 주소 판정(금지·루프백 거부) + 판정한 주소로 고정(리바인딩 방지), 리다이렉트 안 따름 |
 * | 허용 호스트 연결 테스트 | admin | 위와 같음(HostPolicy::inspect) |
 * | OIDC 디스커버리·JWKS·토큰 | admin(발급자) | https만, 금지 주소 거부, 발급자보다 "더 안쪽" 주소로 리다이렉트 금지, 최대 2번, 크기·시간 상한 |
 * | SAML 메타데이터 가져오기 | admin(주소) | OidcHttp 그대로(가져오는 주소 자신이 기준) |
 * | 관리자 이메일 SMTP | admin(설정) | 형식만(스킴·경로·포트·공백·개행 거부) — 사내 중계(사설·localhost)가 정상 용도라 주소 판정은 하지 않음(P30B 판단). 워커·시험 발송만 연결 |
 */
final class SsrfRulesTest extends TestCase
{
    public function testBypassNotationsAreNormalizedOrRejected(): void
    {
        // 비표준 숫자 표기는 이름 정규화에서 거부(HostPolicy·OidcHttp 공통)
        foreach (['2130706433', '0x7f000001', '0x7f.0.0.1', '0177.0.0.1', '127.1', '127.0.1', '017700000001', '0', '1.1.1.1.1'] as $h) {
            $this->assertSame(null, HostPolicy::normalizeHost($h), $h);
            $this->assertFalse(OidcHttp::validUrl("https://{$h}/x"), "OIDC {$h}");
        }
        // 금지 주소: 표기를 바꿔도 금지
        $blocked = ['169.254.169.254', '::ffff:169.254.169.254', '::ffff:a9fe:a9fe', '::169.254.169.254', '64:ff9b::a9fe:a9fe', '64:ff9b::169.254.169.254',
            '2002:a9fe:a9fe::', '0.0.0.0', '::', 'fe80::1', 'fe80::1%25eth0', 'fd00:ec2::254', '100.100.100.200', '224.0.0.251', 'ff02::1'];
        foreach ($blocked as $ip) {
            $norm = HostPolicy::normalizeHost($ip);
            $this->assertTrue($norm === null || HostPolicy::classify($norm) === 'blocked', "{$ip} → " . ($norm === null ? 'null' : HostPolicy::classify($norm)));
        }
        // 루프백: 표기를 바꿔도 루프백(개발용 예외일 때만 허용)
        foreach (['127.0.0.1', '127.255.255.254', '::1', '0:0:0:0:0:0:0:1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::'] as $ip) {
            $this->assertSame('loopback', HostPolicy::classify((string) HostPolicy::normalizeHost($ip)), $ip);
        }
        // 사설은 사설(목록에 있을 때만), 공인은 공인
        foreach (['10.0.0.1' => 'private', '::ffff:10.0.0.1' => 'private', '2002:0a00:0001::' => 'private', '64:ff9b::0a00:1' => 'private', 'fc00::1' => 'private',
            '8.8.8.8' => 'public', '64:ff9b::808:808' => 'public', '2001:db8::1' => 'public'] as $ip => $class) {
            $this->assertSame($class, HostPolicy::classify((string) HostPolicy::normalizeHost($ip)), $ip);
        }
    }

    public function testDnsAnswersAreJudgedAllAndPinned(): void
    {
        $db = \PJGuardian\Tests\Support\TestDb::migrated();
        $logger = new \PJGuardian\Support\Logger($this->tempDir('ssrf'), new \PJGuardian\Support\FixedClock(), 'ERROR');
        $answers = [
            'rebind.corp' => ['10.0.0.5', '169.254.169.254'],  // 하나라도 금지면 거부
            'six.corp' => ['64:ff9b::a9fe:a9fe'],              // NAT64로 메타데이터
            'loop.corp' => ['127.0.0.1'],
            'ok.corp' => ['10.0.0.7', '10.0.0.8'],
        ];
        $pol = new HostPolicy($db, $logger, false, static fn (string $h): array => $answers[$h] ?? []);
        foreach (['rebind.corp', 'six.corp', 'loop.corp'] as $h) {
            $r = $pol->inspect($h);
            $this->assertSame([false, 'address', null], [$r['allowed'], $r['reason'], $r['pinned']], $h);
        }
        $this->assertSame([false, 'dns'], [$pol->inspect('nx.corp')['allowed'], $pol->inspect('nx.corp')['reason']]);
        $ok = $pol->inspect('ok.corp');
        $this->assertSame([true, '10.0.0.7'], [$ok['allowed'], $ok['pinned']], '판정한 주소로 고정(접속은 이 주소로)');
        $dev = new HostPolicy($db, $logger, true, static fn (string $h): array => $answers[$h] ?? []);
        $this->assertTrue($dev->inspect('loop.corp')['allowed'], '루프백은 개발용 예외일 때만');
        $this->assertFalse($dev->inspect('six.corp')['allowed'], '금지 주소는 예외에서도 금지');
    }

    public function testUrlAndMailHostShapes(): void
    {
        foreach (['http://idp.test/x', 'https://user:pw@idp.test/x', 'https://idp.test/x#frag', 'https://idp.test/ x', 'file:///etc/passwd', 'gopher://idp.test/', 'https://[::1]/x'] as $u) {
            $this->assertFalse(OidcHttp::validUrl($u), $u);
        }
        $this->assertTrue(OidcHttp::validUrl('https://idp.example.test:8443/.well-known/openid-configuration'));
        foreach (['smtp://mail.test', 'mail.test:25', 'mail.test/x', "mail.test\r\nRCPT", 'mail test', 'file:///x', '[::1', 'a..b'] as $h) {
            $this->assertFalse(ConfigSchema::isMailHost($h), $h);
        }
        foreach (['mail.example.test', '10.0.0.5', '[::1]', 'localhost'] as $h) {
            $this->assertTrue(ConfigSchema::isMailHost($h), $h);
        }
    }
}
