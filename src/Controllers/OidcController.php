<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;
use PJGuardian\Services\Auth\Oidc\OidcException;
use PJGuardian\Support\Navigation;

/**
 * SSO 로그인(OpenID Connect, P28C) — 로그인 전에 쓰는 화면 경로(세션 없음 허용).
 *   GET /auth/oidc/start[?return=<앱 안 경로>]  로그인 전 세션을 만들고(없으면) state·nonce·PKCE를 그 세션에 묶어 IdP 인가 엔드포인트로 302.
 *                                               IP당 분당 oidc.start_per_minute회.
 *   GET /auth/oidc/callback?code&state           state 확인 → 코드 교환·ID 토큰 검증 → 계정 결정(공통 서비스) → 세션 새로 발급(세션 고정 방지) → 돌아갈 곳.
 * 실패는 항상 로그인 화면으로(/login?sso_error=E-SSO-…, 일반 문구 + 안전한 코드), 감사 로그 auth.login_failed에 사유. 승인 대기·거절·상한은 안내 상자
 * (/login?sso_notice=…). 코드·state·토큰은 로그·감사 로그·주소(돌아가는 주소)에 남기지 않는다.
 */
final class OidcController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function ctx(Request $request): SessionContext
    {
        return $request->attribute('session_ctx');
    }

    private static function toLogin(Request $request, string $param, string $value): Response
    {
        return Response::redirect($request->basePath . '/login?' . $param . '=' . rawurlencode($value));
    }

    /** GET /auth/oidc/start */
    public function start(Request $request): Response
    {
        $user = $request->attribute('user');
        if ($user !== null) {
            return Response::redirect($request->basePath . Navigation::HOME);
        }
        if (!$this->app->oidcEnabled()) {
            return self::toLogin($request, 'sso_error', 'E-SSO-CONFIG');
        }
        $login = $this->app->oidcLogin();
        if (!$this->app->oidcStates()->allowStart($request->clientIp, $login->settings()->startPerMinute)) {
            $this->app->externalAccounts()->failedAudit('', 'oidc_start_rate', $request->clientIp, 'oidc');
            return self::toLogin($request, 'sso_error', 'E-SSO-RATE');
        }
        $session = self::ctx($request)->ensure();
        $return = Navigation::safeNext($request->query['return'] ?? null) ?? '';
        try {
            $url = $login->begin($session->idHash, $return);
        } catch (OidcException $e) {
            return $this->failed($request, $e);
        }
        $this->app->logger->info('SSO(OIDC) 로그인 시작', ['ip' => $request->clientIp]);
        return Response::redirect($url);
    }

    /** GET /auth/oidc/callback */
    public function callback(Request $request): Response
    {
        if ($request->attribute('user') !== null) {
            return Response::redirect($request->basePath . Navigation::HOME); // 이미 로그인 — state를 쓰지 않는다
        }
        if (!$this->app->oidcEnabled()) {
            return self::toLogin($request, 'sso_error', 'E-SSO-CONFIG');
        }
        $ctx = self::ctx($request);
        try {
            $out = $this->app->oidcLogin()->complete($request->query, $ctx->session()?->idHash);
        } catch (OidcException $e) {
            return $this->failed($request, $e);
        }
        $result = $this->app->login()->attemptExternal('oidc', $out['issuer'], $out['identity'], $request->clientIp);
        if (!$result->ok) {
            return match ($result->code) {
                'pending' => self::toLogin($request, 'sso_notice', 'account_pending'),
                'rejected' => self::toLogin($request, 'sso_notice', 'account_rejected'),
                'pending_limit' => self::toLogin($request, 'sso_notice', 'account_requests_closed'),
                'ip_blocked' => self::toLogin($request, 'sso_error', 'E-SSO-RATE'),
                default => self::toLogin($request, 'sso_error', 'E-SSO-DENIED'),
            };
        }
        $this->app->sessions()->purgeExpired();
        $ctx->login($result->user); // 세션 ID·CSRF 토큰 새로(로그인 전 세션 ID는 버림)
        return Response::redirect($request->basePath . (Navigation::safeNext($out['return']) ?? Navigation::HOME));
    }

    private function failed(Request $request, OidcException $e): Response
    {
        $this->app->logger->warn('SSO(OIDC) 로그인 실패', ['reason' => $e->reason, 'ip' => $request->clientIp]);
        $this->app->externalAccounts()->failedAudit('', 'oidc_' . $e->reason, $request->clientIp, 'oidc');
        return self::toLogin($request, 'sso_error', $e->userCode());
    }
}
