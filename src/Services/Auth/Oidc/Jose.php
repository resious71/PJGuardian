<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Support\ConfigSchema;

/**
 * JWS(ID 토큰) 서명 검증(P28C) — 의존성 정책(새 패키지 없음)에 따라 직접 구현하고 openssl_verify로 검증한다.
 *  - 받는 알고리즘은 RS256·ES256뿐(설정 oidc.allowed_algs로 더 좁힐 수 있음). none·HS*·그 밖은 거부(키 혼동 공격 방지).
 *  - 키는 디스커버리의 jwks_uri에서 받은 것만 쓴다. 헤더의 jku·x5u·jwk(토큰이 스스로 가리키는 키)는 쓰지 않는다. crit 헤더는 거부.
 *  - kid로 키를 고른다(kid가 없으면 알고리즘에 맞는 키가 정확히 하나일 때만). 키의 kty·crv·use·alg가 알고리즘과 맞아야 한다. RSA는 2048비트 이상.
 *  - JWK(RSA n·e, EC P-256 x·y)는 DER(SubjectPublicKeyInfo)로 바꿔 PEM으로 넘기고, ES256 서명(r‖s 64바이트)은 DER로 바꾼다.
 */
final class Jose
{
    public const MAX_TOKEN_BYTES = 16384;

    public static function b64urlDecode(string $s): string
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+$/', $s) !== 1 || strlen($s) % 4 === 1) {
            throw new OidcException('id_token.malformed');
        }
        $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($out === false) {
            throw new OidcException('id_token.malformed');
        }
        return $out;
    }

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @return array{0:array<string,mixed>, 1:array<string,mixed>, 2:string, 3:string} [헤더, 클레임, 서명 입력, 서명]
     */
    public static function decode(string $jwt): array
    {
        if (strlen($jwt) > self::MAX_TOKEN_BYTES) {
            throw new OidcException('id_token.too_large');
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcException('id_token.malformed');
        }
        $header = json_decode(self::b64urlDecode($parts[0]), true);
        $claims = json_decode(self::b64urlDecode($parts[1]), true);
        if (!is_array($header) || !is_array($claims) || array_is_list($header) || array_is_list($claims)) {
            throw new OidcException('id_token.malformed');
        }
        // 서명 부분이 빈 토큰(alg=none)도 해석은 하고 verify()가 알고리즘에서 거부한다
        return [$header, $claims, $parts[0] . '.' . $parts[1], $parts[2] === '' ? '' : self::b64urlDecode($parts[2])];
    }

    /**
     * 서명 검증. 맞는 키가 없으면 id_token.unknown_kid(호출자가 JWKS를 한 번 새로 받아 다시 시도).
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $keys JWKS keys
     * @param list<string> $allowed 설정의 허용 알고리즘
     */
    public static function verify(array $header, string $input, string $signature, array $keys, array $allowed): void
    {
        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || !in_array($alg, array_intersect(ConfigSchema::OIDC_ALGS, $allowed), true)) {
            throw new OidcException('id_token.alg_not_allowed');
        }
        if (array_key_exists('crit', $header)) {
            throw new OidcException('id_token.crit_unsupported');
        }
        if (isset($header['typ']) && (!is_string($header['typ']) || !in_array(strtolower($header['typ']), ['jwt', 'application/jwt'], true))) {
            throw new OidcException('id_token.bad_typ');
        }
        $kid = $header['kid'] ?? null;
        if ($kid !== null && (!is_string($kid) || strlen($kid) > 256)) {
            throw new OidcException('id_token.malformed');
        }
        $candidates = array_values(array_filter($keys, static fn (array $k): bool => self::keyFits($k, $alg) && ($kid === null || ($k['kid'] ?? null) === $kid)));
        if ($candidates === [] || ($kid === null && count($candidates) !== 1)) {
            throw new OidcException('id_token.unknown_kid');
        }
        $pem = self::jwkToPem($candidates[0]);
        if ($alg === 'ES256') {
            if (strlen($signature) !== 64) {
                throw new OidcException('id_token.bad_signature');
            }
            $signature = self::ecSignatureToDer($signature);
        }
        $ok = @openssl_verify($input, $signature, $pem, OPENSSL_ALGO_SHA256);
        while (openssl_error_string() !== false) {
            // 오류 큐 비우기(다음 openssl 호출에 남지 않게)
        }
        if ($ok !== 1) {
            throw new OidcException('id_token.bad_signature');
        }
    }

    /** @param array<string, mixed> $k */
    public static function keyFits(array $k, string $alg): bool
    {
        if (isset($k['use']) && $k['use'] !== 'sig') {
            return false;
        }
        if (isset($k['alg']) && $k['alg'] !== $alg) {
            return false;
        }
        if (isset($k['key_ops']) && (!is_array($k['key_ops']) || !in_array('verify', $k['key_ops'], true))) {
            return false;
        }
        return match ($alg) {
            'RS256' => ($k['kty'] ?? null) === 'RSA' && is_string($k['n'] ?? null) && is_string($k['e'] ?? null),
            'ES256' => ($k['kty'] ?? null) === 'EC' && ($k['crv'] ?? null) === 'P-256' && is_string($k['x'] ?? null) && is_string($k['y'] ?? null),
            default => false,
        };
    }

    /** @param array<string, mixed> $k JWK(RSA 또는 EC P-256 공개 키) → PEM */
    public static function jwkToPem(array $k): string
    {
        if (($k['kty'] ?? null) === 'RSA') {
            $n = ltrim(self::b64urlDecode((string) $k['n']), "\0");
            $e = ltrim(self::b64urlDecode((string) $k['e']), "\0");
            if (strlen($n) < 256 || $e === '') {
                throw new OidcException('id_token.weak_key'); // 2048비트 미만
            }
            $rsaKey = self::der(0x30, self::derInt($n) . self::derInt($e));
            $algId = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
            $spki = self::der(0x30, $algId . self::der(0x03, "\0" . $rsaKey));
        } elseif (($k['kty'] ?? null) === 'EC' && ($k['crv'] ?? null) === 'P-256') {
            $x = self::b64urlDecode((string) $k['x']);
            $y = self::b64urlDecode((string) $k['y']);
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new OidcException('id_token.bad_key');
            }
            $algId = self::der(0x30, "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07");
            $spki = self::der(0x30, $algId . self::der(0x03, "\0\x04" . $x . $y));
        } else {
            throw new OidcException('id_token.bad_key');
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** JWS ES256 서명(r‖s, 각 32바이트) → DER SEQUENCE { INTEGER r, INTEGER s } */
    public static function ecSignatureToDer(string $sig): string
    {
        return self::der(0x30, self::derInt(substr($sig, 0, 32)) . self::derInt(substr($sig, 32, 32)));
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }
        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $len = strlen($content);
        if ($len < 0x80) {
            return chr($tag) . chr($len) . $content;
        }
        $l = ltrim(pack('N', $len), "\0");
        return chr($tag) . chr(0x80 | strlen($l)) . $l . $content;
    }
}
