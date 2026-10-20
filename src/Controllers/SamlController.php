<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;
use PJGuardian\Services\Auth\Saml\SamlException;
use PJGuardian\Support\Navigation;

/**
 * SSO 로그인(SAML 2.0 SP, P28D) — 로그인 전에 쓰는 경로(세션 없음 허용).
 *   GET  /auth/saml/start[?return=<앱 안 경로>]  로그인 전 세션을 만들고(없으면) AuthnRequest 기록 → IdP로 302(HTTP-Redirect). IP당 분당 saml.start_per_minute회.
 *   POST /auth/saml/acs                         IdP의 자동 제출 폼(cross-site — 세션 쿠키가 실리지 않으므로 CSRF 토큰 대신 InResponseTo·RelayState·한 번 사용으로 검증,
 *                                               CsrfMiddleware의 유일한 예외). 검증 통과 → 303 /auth/saml/finish?t=<2분 한 번 토큰>.
 *   GET  /auth/saml/finish?t=                    같은 사이트 최상위 GET이라 Lax 세션 쿠키가 실림 — 시작한 브라우저와 같아야 계정 결정·세션 새로 발급(세션 고정 방지).
 *   GET  /auth/saml/metadata                     SP 메타데이터 XML(공개 — EntityID·ACS·NameID 형식·SP 인증서). SAML 설정이 덜 됐으면 404.
 * 실패는 항상 로그인 화면(/login?sso_error=E-SSO-…, 일반 문구 + 코드), 감사 auth.login_failed에 사유(saml_…). 응답 원문은 어디에도 남기지 않는다.
 */
final class SamlController
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

    /** GET /auth/saml/start */
    public function start(Request $request): Response
    {
        if ($request->attribute('user') !== null) {
            return Response::redirect($request->basePath . Navigation::HOME);
        }
        if (!$this->app->samlEnabled()) {
            return self::toLogin($request, 'sso_error', 'E-SSO-CONFIG');
        }
        $login = $this->app->samlLogin();
        if (!$this->app->oidcStates()->allowStart('saml:' . $request->clientIp, $login->settings()->startPerMinute)) {
            $this->app->externalAccounts()->failedAudit('', 'saml_start_rate', $request->clientIp, 'saml');
            return self::toLogin($request, 'sso_error', 'E-SSO-RATE');
        }
        $session = self::ctx($request)->ensure();
        try {
            $url = $login->begin($session->idHash, Navigation::safeNext($request->query['return'] ?? null) ?? '');
        } catch (SamlException $e) {
            return $this->failed($request, $e);
        }
        $this->app->logger->info('SSO(SAML) 로그인 시작', ['ip' => $request->clientIp]);
        return Response::redirect($url);
    }

    /** POST /auth/saml/acs */
    public function acs(Request $request): Response
    {
        if (!$this->app->samlEnabled()) {
            return self::toLogin($request, 'sso_error', 'E-SSO-CONFIG');
        }
        try {
            $token = $this->app->samlLogin()->acs($request->form());
        } catch (SamlException $e) {
            return $this->failed($request, $e);
        }
        return Response::redirect($request->basePath . '/auth/saml/finish?t=' . rawurlencode($token), 303);
    }

    /** GET /auth/saml/finish */
    public function finish(Request $request): Response
    {
        if (!$this->app->samlEnabled()) {
            return self::toLogin($request, 'sso_error', 'E-SSO-CONFIG');
        }
        $ctx = self::ctx($request);
        $token = $request->query['t'] ?? '';
        try {
            $out = $this->app->samlLogin()->finish(is_string($token) ? $token : '', $ctx->session()?->idHash);
        } catch (SamlException $e) {
            return $this->failed($request, $e);
        }
        $result = $this->app->login()->attemptExternal('saml', $out['issuer'], $out['identity'], $request->clientIp);
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

    /** GET /auth/saml/metadata */
    public function metadata(Request $request): Response
    {
        if (!\PJGuardian\Services\Auth\Saml\SamlSettings::libraryAvailable() || !\PJGuardian\Support\SchemaFeatures::saml($this->app->db()) || !$this->app->samlSettings()->complete()) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Not Found');
        }
        try {
            $xml = $this->app->samlAdmin()->spMetadata();
        } catch (SamlException) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Not Found');
        }
        $download = ($request->query['download'] ?? '') === '1';
        $headers = ['Content-Type' => 'application/samlmetadata+xml; charset=utf-8', 'Cache-Control' => 'no-cache'];
        if ($download) {
            $headers['Content-Disposition'] = 'attachment; filename="pjguardian-sp-metadata.xml"';
        }
        return new Response(200, $headers, $xml);
    }

    private function failed(Request $request, SamlException $e): Response
    {
        $this->app->logger->warn('SSO(SAML) 로그인 실패', ['reason' => $e->reason, 'ip' => $request->clientIp]);
        $this->app->externalAccounts()->failedAudit('', 'saml_' . $e->reason, $request->clientIp, 'saml');
        return self::toLogin($request, 'sso_error', $e->userCode());
    }
}
