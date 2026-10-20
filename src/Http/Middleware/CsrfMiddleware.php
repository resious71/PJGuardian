<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;

/**
 * CSRF 검사(명세 10, 19.7). 상태를 바꾸는 모든 요청(GET·HEAD·OPTIONS 외)에 적용한다(로그인 요청 포함: 로그인 전 세션에도 토큰을 발급한다).
 *
 * 예외는 하나(P28D): SAML ACS(POST /auth/saml/acs) — IdP의 자동 제출 폼이 다른 사이트에서 보내므로 세션 쿠키(SameSite=Lax)도 토큰도 없다.
 * 대신 SamlLogin이 서버가 발급한 요청 ID(InResponseTo)·RelayState·한 번 사용·10분 만료와 서명으로 검증하고, 로그인 자체는
 * 같은 사이트 GET(/auth/saml/finish)에서 시작한 브라우저의 세션과 맞을 때만 한다. 이 경로는 로그인 전 화면 경로라 세션 상태를 바꾸지 않는다.
 *
 * 토큰은 세션에 묶인 64자리 16진수. AJAX는 X-CSRF-Token 헤더, HTML 폼은 숨은 필드 _csrf로 보낸다.
 * 라우팅보다 먼저 검사하므로 없는 경로라도 토큰이 없으면 403이다.
 */
final class CsrfMiddleware implements Middleware
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];
    /** 앱 경로(배포 경로 제외) — 위 주석의 예외 */
    public const EXEMPT_PATHS = ['/auth/saml/acs'];

    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->method, self::SAFE, true) && !in_array($request->path, self::EXEMPT_PATHS, true)) {
            /** @var SessionContext|null $ctx */
            $ctx = $request->attribute('session_ctx');
            $expected = $ctx?->csrfToken();
            $given = $request->header('x-csrf-token') ?? $request->formString('_csrf');
            if ($expected === null || $given === '' || !hash_equals($expected, $given)) {
                throw new ApiException(403, 'csrf_invalid', logContext: [
                    'method' => $request->method,
                    'reason' => $expected === null ? 'no_session' : ($given === '' ? 'missing' : 'mismatch'),
                ]);
            }
        }
        return $next($request);
    }
}
