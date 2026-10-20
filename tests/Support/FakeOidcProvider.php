<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Services\Auth\Oidc\HttpTransport;
use PJGuardian\Services\Auth\Oidc\Jose;
use PJGuardian\Services\Auth\Oidc\OidcException;

/**
 * 가짜 OIDC 제공자(P28C 시험·격리 점검 전용 — 실제 IdP에 연결하지 않는다). HttpTransport로 앱에 끼워
 * 디스커버리·JWKS·토큰·UserInfo 엔드포인트를 흉내 내고, 시험용 키 쌍(RSA 2048 kid rsa1, EC P-256 kid ec1 — 실행마다 새로 만듦)으로 ID 토큰에 서명한다.
 * 인가 엔드포인트(브라우저가 가는 곳)는 시험이 issueCode()로 대신한다(격리 점검은 브라우저 요청 가로채기가 codes.json에 쓴다).
 *
 * 공격 시험용 옵션(issueCode의 $opt): sign = rsa|ec|none|hs256(공개 키 PEM을 HMAC 비밀로 — 키 혼동)|tamper, kid(헤더 kid 바꿈), header(헤더 덮어쓰기),
 * 클레임은 $claims로 덮어쓴다(iss·aud·exp·nonce 등, null이면 뺌). 엔드포인트별 응답 바꾸기: $routes[URL] = fn(method, headers, body) → 응답.
 */
final class FakeOidcProvider implements HttpTransport
{
    /** @var list<array{method:string, url:string, ip:string, headers:array<string,string>, body:string}> */
    public array $log = [];
    /** @var array<string, \Closure> URL → fn(string $method, array $headers, string $body): array{status:int, headers?:array, body:string} */
    public array $routes = [];
    /** @var array<string, array<string, mixed>> 코드 → {claims, nonce, challenge, redirect_uri, opt} */
    public array $codes = [];
    /** @var array<string, bool> */
    public array $usedCodes = [];
    /** @var list<string> JWKS에 내보낼 kid */
    public array $published = ['rsa1', 'ec1'];
    /** @var array<string, \OpenSSLAsymmetricKey> */
    private array $keys = [];
    /** @var array<string, mixed> 디스커버리 덮어쓰기(null이면 그 항목 뺌) */
    public array $discovery = [];

    public function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public string $clientSecret,
        public int $now,
        string $tempDir,
        public string $clientAuth = 'basic',
        private readonly ?string $stateDir = null,
    ) {
        $cnf = $tempDir . '/openssl-min.cnf';
        if (!is_file($cnf)) {
            @mkdir($tempDir, 0777, true);
            file_put_contents($cnf, "# 최소 설정(Windows XAMPP의 openssl.cnf 없이 키 생성)\n");
        }
        $saved = $stateDir !== null ? json_decode((string) @file_get_contents($stateDir . '/keys.json'), true) : null;
        foreach (['rsa1' => 'rsa', 'rsa2' => 'rsa', 'ec1' => 'ec'] as $kid => $type) {
            if (is_array($saved) && isset($saved[$kid])) {
                $this->keys[$kid] = openssl_pkey_get_private($saved[$kid]);
                continue;
            }
            $this->keys[$kid] = $type === 'rsa'
                ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $cnf])
                : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 384, 'config' => $cnf]);
        }
        if ($stateDir !== null && !is_array($saved)) {
            $out = [];
            foreach ($this->keys as $kid => $k) {
                openssl_pkey_export($k, $pem, null, ['config' => $cnf]);
                $out[$kid] = $pem;
            }
            file_put_contents($stateDir . '/keys.json', json_encode($out));
        }
    }

    /** 격리 점검 라우터용: 상태 폴더(keys.json·codes.json·used.json)에서 만든다 */
    public static function fromDir(string $dir, string $issuer, string $clientId, string $secret): self
    {
        $cfg = json_decode((string) @file_get_contents($dir . '/idp.json'), true) ?: [];
        $f = new self($issuer, $clientId, $secret, time(), $dir, (string) ($cfg['client_auth'] ?? 'basic'), $dir);
        $f->codes = json_decode((string) @file_get_contents($dir . '/codes.json'), true) ?: [];
        $f->usedCodes = json_decode((string) @file_get_contents($dir . '/used.json'), true) ?: [];
        if (isset($cfg['published']) && is_array($cfg['published'])) {
            $f->published = $cfg['published'];
        }
        if (isset($cfg['discovery']) && is_array($cfg['discovery'])) {
            $f->discovery = $cfg['discovery'];
        }
        return $f;
    }

    /** 시험의 "브라우저가 IdP에서 로그인함": 코드를 만든다 @param array<string, mixed> $claims @param array<string, mixed> $opt */
    public function issueCode(array $claims, string $nonce, string $challenge, string $redirectUri, array $opt = []): string
    {
        $code = 'code-' . bin2hex(random_bytes(12));
        $this->codes[$code] = ['claims' => $claims, 'nonce' => $nonce, 'challenge' => $challenge, 'redirect_uri' => $redirectUri, 'opt' => $opt];
        return $code;
    }

    /** @return array<string, mixed> 공개 JWK */
    public function jwk(string $kid): array
    {
        $d = openssl_pkey_get_details($this->keys[$kid]);
        if (isset($d['rsa'])) {
            return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => Jose::b64urlEncode($d['rsa']['n']), 'e' => Jose::b64urlEncode($d['rsa']['e'])];
        }
        return ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'alg' => 'ES256', 'crv' => 'P-256',
            'x' => Jose::b64urlEncode(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => Jose::b64urlEncode(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))];
    }

    public function publicPem(string $kid): string
    {
        return (string) openssl_pkey_get_details($this->keys[$kid])['key'];
    }

    /** @param array<string, mixed> $claims @param array<string, mixed> $opt */
    public function idToken(array $claims, string $nonce, array $opt = []): string
    {
        $sign = (string) ($opt['sign'] ?? 'rsa');
        $kid = (string) ($opt['kid'] ?? ($sign === 'ec' ? 'ec1' : 'rsa1'));
        $alg = match ($sign) { 'ec' => 'ES256', 'none' => 'none', 'hs256' => 'HS256', default => 'RS256' };
        $header = array_merge(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid], (array) ($opt['header'] ?? []));
        $payload = array_merge(['iss' => $this->issuer, 'aud' => $this->clientId, 'exp' => $this->now + 300, 'iat' => $this->now, 'nonce' => $nonce], $claims);
        $payload = array_filter($payload, static fn ($v): bool => $v !== null);
        $input = Jose::b64urlEncode((string) json_encode($header)) . '.' . Jose::b64urlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signKid = (string) ($opt['sign_kid'] ?? ($sign === 'ec' ? 'ec1' : (isset($this->keys[$kid]) && $kid !== 'ec1' ? $kid : 'rsa1')));
        $sig = match ($sign) {
            'none' => '',
            'hs256' => hash_hmac('sha256', $input, $this->publicPem('rsa1'), true),
            'ec' => self::derToRaw(self::sign($input, $this->keys['ec1'])),
            default => self::sign($input, $this->keys[$signKid]),
        };
        if ($sign === 'tamper') {
            $sig[10] = chr(ord($sig[10]) ^ 0x01);
        }
        return $input . '.' . ($sig === '' ? '' : Jose::b64urlEncode($sig));
    }

    public function send(string $method, string $url, string $pinnedIp, array $headers, string $body, int $maxBytes, int $connectTimeout, int $totalTimeout): array
    {
        $this->log[] = ['method' => $method, 'url' => $url, 'ip' => $pinnedIp, 'headers' => $headers, 'body' => $body];
        if (isset($this->routes[$url])) {
            $res = ($this->routes[$url])($method, $headers, $body) + ['headers' => []];
        } else {
            $res = match (true) {
                $url === $this->issuer . '/.well-known/openid-configuration' => self::json($this->discoveryDoc()),
                $url === $this->issuer . '/jwks' => self::json(['keys' => array_map($this->jwk(...), $this->published)]),
                $url === $this->issuer . '/token' && $method === 'POST' => $this->token($headers, $body),
                $url === $this->issuer . '/userinfo' => self::json(['sub' => 'unknown']),
                default => ['status' => 404, 'headers' => [], 'body' => '{}'],
            };
        }
        if (strlen($res['body']) > $maxBytes) {
            throw new OidcException('too_large'); // 실제 전송과 같이 상한에서 끊는다
        }
        return $res;
    }

    /** @return array<string, mixed> */
    public function discoveryDoc(): array
    {
        $doc = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer . '/authorize',
            'token_endpoint' => $this->issuer . '/token',
            'jwks_uri' => $this->issuer . '/jwks',
            'userinfo_endpoint' => $this->issuer . '/userinfo',
            'end_session_endpoint' => $this->issuer . '/logout',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        ];
        foreach ($this->discovery as $k => $v) {
            if ($v === null) {
                unset($doc[$k]);
            } else {
                $doc[$k] = $v;
            }
        }
        return $doc;
    }

    /** @param array<string, string> $headers @return array{status:int, headers:array<string,string>, body:string} */
    private function token(array $headers, string $body): array
    {
        parse_str($body, $form);
        $code = (string) ($form['code'] ?? '');
        $entry = $this->codes[$code] ?? null;
        if (($form['grant_type'] ?? '') !== 'authorization_code' || $entry === null || isset($this->usedCodes[$code])) {
            return self::json(['error' => 'invalid_grant'], 400);
        }
        $this->usedCodes[$code] = true;
        if ($this->stateDir !== null) {
            file_put_contents($this->stateDir . '/used.json', json_encode($this->usedCodes));
        }
        // 클라이언트 인증
        $auth = $headers['Authorization'] ?? '';
        $ok = match ($this->clientAuth) {
            'basic' => $auth === 'Basic ' . base64_encode(urlencode($this->clientId) . ':' . urlencode($this->clientSecret)) && !isset($form['client_secret']),
            'post' => ($form['client_id'] ?? '') === $this->clientId && ($form['client_secret'] ?? '') === $this->clientSecret && $auth === '',
            default => ($form['client_id'] ?? '') === $this->clientId && !isset($form['client_secret']) && $auth === '',
        };
        if (!$ok) {
            return self::json(['error' => 'invalid_client'], 401);
        }
        if (($form['redirect_uri'] ?? '') !== $entry['redirect_uri']) {
            return self::json(['error' => 'invalid_grant'], 400);
        }
        $verifier = (string) ($form['code_verifier'] ?? '');
        if (Jose::b64urlEncode(hash('sha256', $verifier, true)) !== $entry['challenge']) {
            return self::json(['error' => 'invalid_grant'], 400); // PKCE 불일치
        }
        $opt = (array) ($entry['opt'] ?? []);
        return self::json(['access_token' => 'at-' . bin2hex(random_bytes(8)), 'token_type' => 'Bearer', 'expires_in' => 300,
            'id_token' => $this->idToken((array) $entry['claims'], (string) $entry['nonce'], $opt)]);
    }

    /** @param array<string, mixed> $v @return array{status:int, headers:array<string,string>, body:string} */
    public static function json(array $v, int $status = 200): array
    {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => (string) json_encode($v, JSON_UNESCAPED_SLASHES)];
    }

    private static function sign(string $input, \OpenSSLAsymmetricKey $key): string
    {
        openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
        return $sig;
    }

    /** DER ECDSA 서명 → JWS r‖s(각 32바이트) */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }
}
