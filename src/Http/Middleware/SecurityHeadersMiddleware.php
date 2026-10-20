<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 모든 응답에 보안 헤더를 붙인다(명세 10, 19.5).
 * CSP는 인라인 스크립트·스타일을 허용하지 않는 엄격한 정책이다. 필요한 예외는 이유와 함께 이 파일에서만 추가한다.
 * HSTS는 HTTPS 응답에만 붙인다. **HSTS를 보내는 곳은 여기 한 곳**이다(security.hsts_max_age) — Apache vhost에 Header always set으로 또 넣으면
 * PHP 응답에 두 번 나가므로 넣지 않는다(P31, docs/xampp_vhost.md).
 *
 * 예외 1(P17, 명세 19.10 "CodeMirror가 동적 스타일 삽입으로 막히면 요청마다 생성하는 nonce"):
 *   코드 뷰어(CodeMirror 6, style-mod)는 테마 CSS를 <style> 요소로 문서에 넣는다. `style-src 'self'`만으로는 막히는 것을
 *   브라우저에서 확인했다(securitypolicyviolation: style-src-elem inline). 그래서 요청마다 새 nonce를 만들어
 *   `style-src 'self' 'nonce-…'`로 그 요소만 허용한다. 'unsafe-inline'은 쓰지 않는다. script-src는 그대로 'self'뿐이다.
 *   nonce는 요청 속성 csp_nonce → 화면 <meta name="csp-nonce"> → EditorView.cspNonce로 전달된다.
 */
final class SecurityHeadersMiddleware implements Middleware
{
    /** %s = 요청마다 만드는 nonce(base64url, 128비트) */
    public const CSP_TEMPLATE = "default-src 'self'; script-src 'self'; style-src 'self' 'nonce-%s'; img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** P31: 쓰지 않는 브라우저 기능은 끈다(카메라·마이크·위치·결제·USB 등). 복사 단추의 클립보드 쓰기는 브라우저 기본(같은 출처)을 그대로 둔다 */
    public const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=()';

    /** @param (\Closure(): ?string)|null $formActionOrigin 예외 2(P28C): form-action에 더할 출처 하나(https://호스트[:포트]) 또는 null */
    public function __construct(private readonly int $hstsMaxAge, private readonly ?\Closure $formActionOrigin = null)
    {
    }

    /**
     * 예외 2(P28C SSO 제공자 로그아웃): 로그아웃은 폼 POST → 302이고, 브라우저는 폼 제출 뒤 리다이렉트에도 form-action을 적용한다.
     * 제공자 로그아웃(oidc.provider_logout, 기본 꺼짐)이 켜졌을 때만 그 IdP의 로그아웃 엔드포인트 출처 하나를 form-action에 더한다.
     * SSO 로그인 시작은 링크(GET → 302)라 CSP와 관계없다.
     */
    public static function csp(string $nonce, ?string $formActionOrigin = null): string
    {
        $csp = sprintf(self::CSP_TEMPLATE, $nonce);
        if ($formActionOrigin !== null && preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?$#', $formActionOrigin) === 1) {
            $csp = str_replace("form-action 'self'", "form-action 'self' " . $formActionOrigin, $csp);
        }
        return $csp;
    }

    public static function newNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function process(Request $request, callable $next): Response
    {
        $nonce = self::newNonce();
        $response = $next($request->withAttribute('csp_nonce', $nonce))
            ->withHeader('Content-Security-Policy', self::csp($nonce, $this->formActionOrigin === null ? null : ($this->formActionOrigin)()))
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', self::PERMISSIONS_POLICY);
        if ($request->secure && $this->hstsMaxAge > 0) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=' . $this->hstsMaxAge);
        }
        return $response;
    }
}
