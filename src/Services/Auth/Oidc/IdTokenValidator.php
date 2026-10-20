<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

/**
 * ID 토큰 클레임 검사(P28C, OpenID Connect Core 3.1.3.7). 서명 검증(Jose::verify)이 끝난 클레임에만 쓴다.
 *  - iss: 설정한 발급자와 정확히 같음
 *  - aud: 클라이언트 ID를 포함(문자열 또는 배열). 대상이 여럿이면 azp가 있어야 하고 클라이언트 ID와 같아야 함. azp가 있으면 늘 같아야 함
 *  - exp(필수)·iat(필수)·nbf(있으면): 시계 오차 60초 허용
 *  - nonce: 로그인 시작 때 만든 값과 같음(상수 시간 비교)
 *  - sub: 필수, 1~255자, 제어 문자 없음
 */
final class IdTokenValidator
{
    public const SKEW = 60;

    /** @param array<string, mixed> $c */
    public static function check(array $c, string $issuer, string $clientId, string $nonce, int $now): void
    {
        if (!is_string($c['iss'] ?? null) || $c['iss'] !== $issuer) {
            throw new OidcException('id_token.iss');
        }
        $aud = $c['aud'] ?? null;
        if (is_string($aud)) {
            $aud = [$aud];
        }
        if (!is_array($aud) || $aud === [] || !in_array($clientId, $aud, true)) {
            throw new OidcException('id_token.aud');
        }
        if (count($aud) > 1 && !isset($c['azp'])) {
            throw new OidcException('id_token.azp');
        }
        if (isset($c['azp']) && $c['azp'] !== $clientId) {
            throw new OidcException('id_token.azp');
        }
        if (!self::isTime($c['exp'] ?? null) || (int) $c['exp'] + self::SKEW <= $now) {
            throw new OidcException('id_token.expired');
        }
        if (!self::isTime($c['iat'] ?? null) || (int) $c['iat'] - self::SKEW > $now) {
            throw new OidcException('id_token.iat');
        }
        if (array_key_exists('nbf', $c) && (!self::isTime($c['nbf']) || (int) $c['nbf'] - self::SKEW > $now)) {
            throw new OidcException('id_token.nbf');
        }
        if (!is_string($c['nonce'] ?? null) || $nonce === '' || !hash_equals($nonce, $c['nonce'])) {
            throw new OidcException('id_token.nonce');
        }
        $sub = $c['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 255 || preg_match('/[\x00-\x1F\x7F]/', $sub) === 1) {
            throw new OidcException('id_token.sub');
        }
    }

    private static function isTime(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && $v > 0 && $v < 32503680000; // 3000년 전
    }
}
