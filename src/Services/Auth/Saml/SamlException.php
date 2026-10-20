<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Saml;

/**
 * SAML 로그인·설정 확인 실패(P28D). reason은 감사 로그·로그·설정 확인 결과에 쓰는 내부 사유(점 구분자 — 감사 가리기 규칙 "…: 값"에 걸리지 않게,
 * 응답 원문·어서션 값 없음), userCode()는 로그인 화면의 안전한 오류 코드(P28C와 같은 E-SSO-… 체계).
 */
final class SamlException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function userCode(): string
    {
        $base = explode('.', $this->reason, 2)[0];
        if ($this->reason === 'response.idp_status') {
            return 'E-SSO-IDP';
        }
        return match ($base) {
            'request_unknown', 'unsolicited', 'relay_mismatch', 'finish_invalid', 'state_missing' => 'E-SSO-STATE',
            'disabled', 'not_configured', 'sp_key_missing', 'sp_key_unreadable', 'cert_invalid', 'cert_expired', 'settings_invalid' => 'E-SSO-CONFIG',
            'unavailable', 'timeout', 'too_large', 'http_status', 'redirect_limit', 'tls', 'blocked_address', 'not_https', 'bad_url' => 'E-SSO-UNAVAILABLE',
            'rate', 'busy' => 'E-SSO-RATE',
            default => 'E-SSO-TOKEN', // response.*(서명·대상·시간·리플레이 등)
        };
    }
}
