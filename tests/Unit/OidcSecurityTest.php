<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Auth\Oidc\HttpTransport;
use PJGuardian\Services\Auth\Oidc\IdTokenValidator;
use PJGuardian\Services\Auth\Oidc\Jose;
use PJGuardian\Services\Auth\Oidc\OidcException;
use PJGuardian\Services\Auth\Oidc\OidcHttp;
use PJGuardian\Services\Auth\Oidc\StreamHttpTransport;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Tests\Support\FakeOidcProvider;
use PJGuardian\Tests\TestCase;

/**
 * OIDC 보안 규칙(P28C, 실제 IdP 없이): JWS 서명 검증(RFC 7515 ES256 예시 벡터, JWK → PEM이 openssl과 같음, none·HS256 키 혼동·허용 목록·crit·typ·kid 선택·
 * 약한 키·위변조), ID 토큰 클레임(iss·aud·azp·exp·iat·nbf·nonce·sub), 외부 요청 주소 판정(https만·금지 주소·루프백·발급자보다 안쪽·리다이렉트 횟수와
 * 매 단계 검사·POST는 따라가지 않음), 응답 해석·크기 상한·시간 상한(응답하지 않는 로컬 소켓), 설정 검사.
 */
final class OidcSecurityTest extends TestCase
{
    private const ISS = 'https://idp.example.test';

    private function idp(): FakeOidcProvider
    {
        return new FakeOidcProvider(self::ISS, 'pjg-client', 's', 1_800_000_000, $this->tempDir('oidcu'));
    }

    private function logger(): Logger
    {
        return new Logger($this->tempDir('oidclog'), new FixedClock(), 'INFO');
    }

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (OidcException $e) {
            return $e->reason;
        }
        return 'no-exception';
    }

    public function testRfc7515VectorAndJwkToPemMatchesOpenssl(): void
    {
        // RFC 7515 부록 A.3(ES256) 예시 — 외부에서 만든 서명을 검증할 수 있는지
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU', 'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0'];
        $jwt = 'eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ'
            . '.DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q';
        [$h, $c, $in, $sig] = Jose::decode($jwt);
        Jose::verify($h, $in, $sig, [$jwk], ['ES256']);
        $this->assertSame('joe', $c['iss']);
        $bad = $sig;
        $bad[5] = chr(ord($bad[5]) ^ 1);
        $this->assertSame('id_token.bad_signature', $this->reason(fn () => Jose::verify($h, $in, $bad, [$jwk], ['ES256'])), '한 비트 바꾸면 실패');
        // 시험용 키: JWK에서 만든 PEM이 openssl이 내보낸 공개 키와 같다(RSA·EC)
        $idp = $this->idp();
        foreach (['rsa1', 'ec1'] as $kid) {
            $this->assertSame(trim($idp->publicPem($kid)), trim(Jose::jwkToPem($idp->jwk($kid))), $kid);
        }
        // 실제 서명(RS256·ES256)이 검증된다
        foreach (['rsa' => 'rsa1', 'ec' => 'ec1'] as $sign => $kid) {
            [$h, , $in, $sig] = Jose::decode($idp->idToken(['sub' => 'u1'], 'n', ['sign' => $sign]));
            Jose::verify($h, $in, $sig, [$idp->jwk('rsa1'), $idp->jwk('ec1')], ['RS256', 'ES256']);
        }
        $this->assertSame('id_token.malformed', $this->reason(fn () => Jose::decode('a.b')));
        $this->assertSame('id_token.malformed', $this->reason(fn () => Jose::decode('e30.e30.a+b')), 'base64url 아닌 글자');
        $this->assertSame('id_token.too_large', $this->reason(fn () => Jose::decode(str_repeat('a', 20000))));
    }

    public function testAlgorithmAndKeySelectionRules(): void
    {
        $idp = $this->idp();
        $keys = [$idp->jwk('rsa1'), $idp->jwk('ec1')];
        $try = function (string $jwt, array $allowed = ['RS256', 'ES256'], ?array $ks = null) use ($keys): string {
            return $this->reason(function () use ($jwt, $allowed, $ks, $keys): void {
                [$h, , $in, $sig] = Jose::decode($jwt);
                Jose::verify($h, $in, $sig, $ks ?? $keys, $allowed);
            });
        };
        $this->assertSame('no-exception', $try($idp->idToken(['sub' => 'a'], 'n')));
        $this->assertSame('id_token.alg_not_allowed', $try($idp->idToken(['sub' => 'a'], 'n', ['sign' => 'none'])), 'alg=none');
        $this->assertSame('id_token.alg_not_allowed', $try($idp->idToken(['sub' => 'a'], 'n', ['sign' => 'hs256'])), 'HS256(공개 키를 HMAC 비밀로 — 키 혼동)');
        $this->assertSame('id_token.alg_not_allowed', $try($idp->idToken(['sub' => 'a'], 'n', ['header' => ['alg' => 'RS512']])), '목록 밖 알고리즘');
        $this->assertSame('id_token.alg_not_allowed', $try($idp->idToken(['sub' => 'a'], 'n'), ['ES256']), '설정으로 좁힌 목록(RS256 뺌)');
        $this->assertSame('id_token.alg_not_allowed', $try($idp->idToken(['sub' => 'a'], 'n', ['sign' => 'hs256']), ['RS256', 'HS256']), '설정에 HS256을 넣어도 받지 않음');
        $this->assertSame('id_token.crit_unsupported', $try($idp->idToken(['sub' => 'a'], 'n', ['header' => ['crit' => ['exp']]])));
        $this->assertSame('id_token.bad_typ', $try($idp->idToken(['sub' => 'a'], 'n', ['header' => ['typ' => 'at+jwt']])), '액세스 토큰 typ');
        $this->assertSame('id_token.unknown_kid', $try($idp->idToken(['sub' => 'a'], 'n', ['kid' => 'nope'])));
        $this->assertSame('id_token.bad_signature', $try($idp->idToken(['sub' => 'a'], 'n', ['sign' => 'tamper'])), '서명 위변조');
        $this->assertSame('id_token.bad_signature', $try($idp->idToken(['sub' => 'a'], 'n', ['header' => ['kid' => 'ec1', 'alg' => 'ES256']])), 'RSA 서명을 EC 키로(길이 다름)');
        // 토큰이 스스로 가리키는 키(jwk·jku 헤더)는 쓰지 않는다 — 공격자 키를 넣어도 JWKS의 키로만 검증
        $this->assertSame('id_token.unknown_kid', $try($idp->idToken(['sub' => 'a'], 'n', ['kid' => 'evil', 'header' => ['jwk' => $idp->jwk('rsa2'), 'jku' => 'https://evil.test/jwks']])));
        // kid 없음: 알고리즘에 맞는 키가 정확히 하나일 때만
        $noKid = $idp->idToken(['sub' => 'a'], 'n', ['header' => ['kid' => null]]);
        $this->assertSame('no-exception', $try($noKid, ['RS256'], [$idp->jwk('rsa1'), $idp->jwk('ec1')]));
        $this->assertSame('id_token.unknown_kid', $try($noKid, ['RS256'], [$idp->jwk('rsa1'), array_merge($idp->jwk('rsa2'), ['kid' => 'x'])]), 'RSA 키 둘이면 고를 수 없음');
        // 키 속성이 맞지 않으면 후보에서 뺀다(use=enc, 다른 alg)
        $this->assertSame('id_token.unknown_kid', $try($idp->idToken(['sub' => 'a'], 'n'), ['RS256'], [array_merge($idp->jwk('rsa1'), ['use' => 'enc'])]));
        $this->assertSame('id_token.unknown_kid', $try($idp->idToken(['sub' => 'a'], 'n'), ['RS256'], [array_merge($idp->jwk('rsa1'), ['alg' => 'RS384'])]));
        // 약한 RSA 키(1024비트)
        $weak = array_merge($idp->jwk('rsa1'), ['n' => Jose::b64urlEncode(random_bytes(128))]);
        $this->assertSame('id_token.weak_key', $try($idp->idToken(['sub' => 'a'], 'n'), ['RS256'], [$weak]));
    }

    public function testIdTokenClaims(): void
    {
        $now = 1_800_000_000;
        $ok = ['iss' => self::ISS, 'aud' => 'pjg-client', 'exp' => $now + 300, 'iat' => $now, 'nonce' => 'N1', 'sub' => 'user-1'];
        $check = fn (array $c, string $nonce = 'N1'): string => $this->reason(fn () => IdTokenValidator::check($c, self::ISS, 'pjg-client', $nonce, $now));
        $this->assertSame('no-exception', $check($ok));
        $this->assertSame('no-exception', $check(['aud' => ['pjg-client', 'other'], 'azp' => 'pjg-client'] + $ok), '대상 여럿 + azp');
        $this->assertSame('no-exception', $check(['exp' => $now - 30, 'iat' => $now + 30] + $ok), '시계 오차 60초 안');
        $cases = [
            'id_token.iss' => [['iss' => self::ISS . '/'] + $ok, ['iss' => 'https://evil.test'] + $ok, array_diff_key($ok, ['iss' => 1])],
            'id_token.aud' => [['aud' => 'other'] + $ok, ['aud' => []] + $ok, ['aud' => ['other']] + $ok, array_diff_key($ok, ['aud' => 1])],
            'id_token.azp' => [['aud' => ['pjg-client', 'other']] + $ok, ['azp' => 'other'] + $ok],
            'id_token.expired' => [['exp' => $now - 61] + $ok, ['exp' => 'tomorrow'] + $ok, array_diff_key($ok, ['exp' => 1])],
            'id_token.iat' => [['iat' => $now + 61] + $ok, array_diff_key($ok, ['iat' => 1])],
            'id_token.nbf' => [['nbf' => $now + 61] + $ok],
            'id_token.nonce' => [['nonce' => 'N2'] + $ok, array_diff_key($ok, ['nonce' => 1])],
            'id_token.sub' => [['sub' => ''] + $ok, ['sub' => 123] + $ok, ['sub' => "a\nb"] + $ok, ['sub' => str_repeat('s', 256)] + $ok, array_diff_key($ok, ['sub' => 1])],
        ];
        foreach ($cases as $want => $list) {
            foreach ($list as $i => $c) {
                $this->assertSame($want, $check($c), "{$want} #{$i}");
            }
        }
        $this->assertSame('id_token.nonce', $check($ok, ''), '빈 nonce는 맞춰 보지 않음');
    }

    public function testRequestAddressPolicyAndRedirects(): void
    {
        $hosts = ['idp.example.test' => ['203.0.113.10'], 'cdn.example.test' => ['198.51.100.7'], 'internal.test' => ['10.0.0.5'], 'meta.test' => ['169.254.169.254'],
            'loop.test' => ['127.0.0.1'], 'mixed.test' => ['203.0.113.11', '10.0.0.9'], 'kc.corp.test' => ['10.1.1.1']];
        $resolve = static fn (string $h): array => $hosts[$h] ?? [];
        $fake = new class implements HttpTransport {
            /** @var array<string, array{status:int, headers:array<string,string>, body:string}> */
            public array $res = [];
            /** @var list<string> */
            public array $sent = [];
            public function send(string $method, string $url, string $pinnedIp, array $headers, string $body, int $maxBytes, int $connectTimeout, int $totalTimeout): array
            {
                $this->sent[] = "{$method} {$url} @{$pinnedIp}";
                return $this->res[$url] ?? ['status' => 200, 'headers' => [], 'body' => '{}'];
            }
        };
        $http = new OidcHttp($fake, self::ISS, false, $this->logger(), $resolve);
        $this->assertSame(200, $http->get('https://cdn.example.test/jwks')['status'], '공인 → 공인');
        $this->assertSame('GET https://cdn.example.test/jwks @198.51.100.7', end($fake->sent), '판정한 주소로 고정');
        foreach (['https://internal.test/jwks' => 'blocked_address', 'https://meta.test/x' => 'blocked_address', 'https://loop.test/x' => 'blocked_address',
            'https://mixed.test/x' => 'blocked_address', 'http://cdn.example.test/jwks' => 'not_https', 'https://user:pw@cdn.example.test/' => 'bad_url',
            'https://nowhere.test/x' => 'unavailable', 'https://169.254.169.254/latest' => 'blocked_address', 'https://0x7f.1/x' => 'bad_url'] as $url => $want) {
            $this->assertSame($want, $this->reason(fn () => $http->get($url)), $url);
        }
        // 리다이렉트: GET만, 2번까지, 매 단계 같은 검사(공인 → 사설로 넘기기 거부)
        $fake->res['https://idp.example.test/a'] = ['status' => 302, 'headers' => ['location' => '/b'], 'body' => ''];
        $fake->res['https://idp.example.test/b'] = ['status' => 301, 'headers' => ['location' => 'https://cdn.example.test/c'], 'body' => ''];
        $fake->res['https://cdn.example.test/c'] = ['status' => 200, 'headers' => [], 'body' => 'ok'];
        $this->assertSame('ok', $http->get('https://idp.example.test/a')['body'], '두 번 따라감(상대 경로 포함)');
        $fake->res['https://cdn.example.test/c'] = ['status' => 302, 'headers' => ['location' => 'https://cdn.example.test/d'], 'body' => ''];
        $this->assertSame('redirect_limit', $this->reason(fn () => $http->get('https://idp.example.test/a')), '세 번째는 거부');
        $fake->res['https://idp.example.test/p'] = ['status' => 302, 'headers' => ['location' => 'https://internal.test/admin'], 'body' => ''];
        $this->assertSame('blocked_address', $this->reason(fn () => $http->get('https://idp.example.test/p')), '사설 주소로 리다이렉트');
        $fake->res['https://idp.example.test/q'] = ['status' => 302, 'headers' => ['location' => 'http://cdn.example.test/'], 'body' => ''];
        $this->assertSame('not_https', $this->reason(fn () => $http->get('https://idp.example.test/q')), 'http로 리다이렉트');
        $fake->sent = [];
        $this->assertSame(302, $http->post('https://idp.example.test/p', [], 'x')['status'], 'POST는 따라가지 않음');
        $this->assertSame(1, count($fake->sent));
        // 사내 IdP(발급자가 사설 주소)면 사설 엔드포인트 허용, 루프백은 개발용 예외일 때만
        $corp = new OidcHttp($fake, 'https://kc.corp.test/realms/x', false, $this->logger(), $resolve);
        $this->assertSame(200, $corp->get('https://internal.test/jwks')['status']);
        $this->assertSame('blocked_address', $this->reason(fn () => $corp->get('https://loop.test/x')));
        $this->assertSame('blocked_address', $this->reason(fn () => $corp->get('https://meta.test/x')), '링크 로컬은 늘 거부');
        $dev = new OidcHttp($fake, 'https://loop.test', true, $this->logger(), $resolve);
        $this->assertSame(200, $dev->get('https://loop.test/x')['status'], 'allow_local_dev');
        $this->assertSame('blocked_address', $this->reason(fn () => $dev->get('https://meta.test/x')));
    }

    public function testResponseParsingSizeAndTimeLimits(): void
    {
        $r = StreamHttpTransport::parse("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Type: application/json\r\n\r\n{}extra", 100);
        $this->assertSame([200, '{}', 'application/json'], [$r['status'], $r['body'], $r['headers']['content-type']]);
        $r = StreamHttpTransport::parse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nab\r\n\r\n3;x=1\r\ncde\r\n0\r\n\r\n", 100);
        $this->assertSame("ab\r\ncde", $r['body'], 'chunked');
        $this->assertSame('too_large', $this->reason(fn () => StreamHttpTransport::parse("HTTP/1.1 200 OK\r\n\r\n" . str_repeat('x', 101), 100)));
        $this->assertSame('too_large', $this->reason(fn () => StreamHttpTransport::parse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n80\r\n" . str_repeat('x', 128) . "\r\n0\r\n\r\n", 100)));
        $this->assertSame('unavailable', $this->reason(fn () => StreamHttpTransport::parse("garbage", 100)));
        // 시간 상한: 접속은 받지만 아무 말도 하지 않는 로컬 소켓(TLS 핸드셰이크가 끝나지 않음) — 실제 전송이 상한 안에 끝난다
        $script = $this->tempDir('oidcsock') . '/silent.php';
        file_put_contents($script, '<?php $s = stream_socket_server("tcp://127.0.0.1:0"); fwrite(STDOUT, stream_socket_get_name($s, false) . "\n"); fflush(STDOUT);'
            . ' $c = @stream_socket_accept($s, 10); sleep(6);');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w']], $pipes);
        $addr = trim((string) fgets($pipes[1]));
        $port = (int) substr($addr, strrpos($addr, ':') + 1);
        $t = microtime(true);
        $why = $this->reason(fn () => (new StreamHttpTransport())->send('GET', 'https://localhost:' . $port . '/x', '127.0.0.1', [], '', 1000, 1, 2));
        $elapsed = microtime(true) - $t;
        proc_terminate($proc);
        proc_close($proc);
        $this->assertTrue(in_array($why, ['timeout', 'unavailable', 'tls'], true), "응답 없는 서버: {$why}");
        $this->assertTrue($elapsed < 4.5, sprintf('시간 상한 안에 끝남(%.1f초)', $elapsed));
    }

    public function testSettingsValidation(): void
    {
        $ok = ['enabled' => true, 'issuer' => 'https://login.example.test/tenant/v2.0', 'client_id' => 'abc-123', 'scopes' => 'openid profile email',
            'claim_login' => 'preferred_username', 'claim_name' => 'name', 'allowed_algs' => ['RS256', 'ES256'], 'button_label' => '회사 계정으로 로그인', 'redirect_base' => ''];
        $this->assertSame([], ConfigSchema::oidcErrors($ok, 'https://pjg.example.test'));
        $this->assertSame([], ConfigSchema::oidcErrors(['redirect_base' => 'http://127.0.0.1:8099'] + $ok, 'http://pjg.local'), '루프백 http 공개 주소(개발)');
        $this->assertSame([], ConfigSchema::oidcErrors(['enabled' => false, 'issuer' => '', 'client_id' => ''] + $ok, 'http://pjg.local'), '꺼져 있으면 비어도 됨');
        $bad = [
            'oidc.issuer' => [['issuer' => 'http://login.example.test'], ['issuer' => 'https://login.example.test/?x=1'], ['issuer' => 'https://a..b/']],
            'oidc.client_id' => [['client_id' => 'has space'], ['client_id' => str_repeat('c', 256)]],
            'oidc.scopes' => [['scopes' => 'profile email'], ['scopes' => 'openid "x"']],
            'oidc.claim_login' => [['claim_login' => ''], ['claim_login' => 'bad claim'], ['claim_login' => '1abc']],
            'oidc.claim_name' => [['claim_name' => 'x/y']],
            'oidc.allowed_algs' => [['allowed_algs' => []], ['allowed_algs' => ['HS256']], ['allowed_algs' => ['RS256', 'none']]],
            'oidc.button_label' => [['button_label' => str_repeat('가', 41)]],
            'oidc.redirect_base' => [['redirect_base' => 'http://pjg.example.test'], ['redirect_base' => 'ftp://x']],
            'oidc.enabled' => [['issuer' => ''], ['client_id' => '']],
        ];
        foreach ($bad as $key => $list) {
            foreach ($list as $i => $over) {
                $errs = ConfigSchema::oidcErrors($over + $ok, 'https://pjg.example.test');
                $this->assertTrue($errs !== [] && str_starts_with($errs[0], $key . ':'), "{$key} #{$i}: " . implode(' / ', $errs));
            }
        }
        $errs = ConfigSchema::oidcErrors($ok, 'http://pjg.local');
        $this->assertTrue($errs !== [] && str_starts_with($errs[0], 'oidc.redirect_base:'), 'app.base_url이 http면 공개 주소 필요');
    }
}
