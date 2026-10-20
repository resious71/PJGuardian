<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

/**
 * OIDC 로그인·연결 시험 실패(P28C). reason은 감사 로그·로그·연결 시험 결과에 쓰는 내부 사유(토큰·코드·state·비밀 원문 없음),
 * userCode()는 로그인 화면에 보이는 안전한 오류 코드(E-SSO-…) — 화면 문구는 일반 실패 하나에 코드만 다르다.
 */
final class OidcException extends \RuntimeException
{
    /** 화면 오류 코드(로그인 화면 sso_error 허용 목록) */
    public const USER_CODES = ['E-SSO-STATE', 'E-SSO-IDP', 'E-SSO-TOKEN', 'E-SSO-CONFIG', 'E-SSO-UNAVAILABLE', 'E-SSO-RATE', 'E-SSO-DENIED'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function userCode(): string
    {
        $base = explode('.', $this->reason, 2)[0];
        return match ($base) {
            'state_missing', 'state_invalid' => 'E-SSO-STATE',
            'idp_error' => 'E-SSO-IDP',
            'disabled', 'not_configured', 'secret_missing', 'secret_unreadable', 'issuer_mismatch', 'discovery_invalid', 'jwks_invalid', 'blocked_address',
            'not_https', 'bad_url' => 'E-SSO-CONFIG',
            'unavailable', 'timeout', 'too_large', 'http_status', 'redirect_limit', 'tls' => 'E-SSO-UNAVAILABLE',
            'rate', 'busy' => 'E-SSO-RATE',
            default => 'E-SSO-TOKEN', // token_error.*, id_token.*, userinfo_* — 구분자는 점(감사 로그 가리기 규칙 "…token…: 값"에 걸리지 않게)
        };
    }
}
