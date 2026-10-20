<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Navigation;

/**
 * 인증 화면: 루트(/), 로그인, 로그아웃, 비밀번호 변경, 임시 홈.
 * 폼은 HTML POST(숨은 필드 _csrf)로 동작한다(스크립트 없이도 로그인 가능).
 */
final class AuthPageController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function ctx(Request $request): SessionContext
    {
        return $request->attribute('session_ctx');
    }

    private static function redirect(Request $request, string $path): Response
    {
        return Response::redirect($request->basePath . $path);
    }

    /** 로그인 상태에 맞는 첫 화면: 비로그인 → 로그인, 임시 비밀번호 → 변경, 그 밖 → 홈 */
    private static function landing(Request $request, ?array $user, ?string $next = null): Response
    {
        if ($user === null) {
            return self::redirect($request, '/login');
        }
        if ($user['must_change_password'] === true) {
            return self::redirect($request, '/password');
        }
        return self::redirect($request, Navigation::safeNext($next) ?? Navigation::HOME);
    }

    /** GET / */
    public function root(Request $request): Response
    {
        return self::landing($request, $request->attribute('user'));
    }

    /** GET /login */
    public function loginForm(Request $request): Response
    {
        if ($request->attribute('user') !== null) {
            return self::landing($request, $request->attribute('user'));
        }
        self::ctx($request)->ensure();
        $notice = match (true) {
            ($request->query['expired'] ?? '') === '1' => 'login.notice_expired',
            ($request->query['logged_out'] ?? '') === '1' => 'login.notice_logged_out',
            default => null,
        };
        // P28C SSO 콜백이 돌려보낸 결과: 실패는 일반 문구 + 안전한 코드(허용 목록만), 승인 대기·거절·상한은 안내 상자
        $ssoError = $request->query['sso_error'] ?? null;
        $error = is_string($ssoError) && in_array($ssoError, \PJGuardian\Services\Auth\Oidc\OidcException::USER_CODES, true)
            ? Lang::get('login.sso_failed', ['code' => $ssoError]) : null;
        $account = null;
        $ssoNotice = $request->query['sso_notice'] ?? null;
        if (is_string($ssoNotice) && in_array($ssoNotice, ['account_pending', 'account_rejected', 'account_requests_closed'], true)) {
            $account = ['kind' => $ssoNotice === 'account_pending' ? 'info' : 'warn', 'text' => Lang::get(match ($ssoNotice) {
                'account_pending' => 'error.account_pending', 'account_rejected' => 'error.account_rejected', default => 'error.account_requests_closed' }), 'code' => $ssoNotice];
        }
        return $this->renderLogin($request, '', is_string($request->query['next'] ?? null) ? $request->query['next'] : '', $notice, $error, 200, $account);
    }

    /** POST /login (폼) */
    public function loginSubmit(Request $request): Response
    {
        $loginId = $request->formString('login_id');
        $password = $request->formString('password');
        $next = $request->formString('next');
        try {
            if ($loginId === '' || $password === '') {
                throw new ApiException(400, 'invalid_input', messageKey: 'login.error_required');
            }
            $result = AuthController::attempt($this->app, $request, $loginId, $password);
        } catch (ApiException $e) {
            // P28B: 외부 인증을 통과했지만 아직 쓸 수 없는 계정(세션 없음) — 오류가 아니라 안내 상자
            if (in_array($e->errorCode, ['account_pending', 'account_rejected', 'account_requests_closed'], true)) {
                return $this->renderLogin($request, $loginId, $next, null, null, 200, ['kind' => $e->errorCode === 'account_pending' ? 'info' : 'warn', 'text' => $e->userMessage(), 'code' => $e->errorCode]);
            }
            return $this->renderLogin($request, $loginId, $next, null, $e->userMessage(), $e->status);
        }
        $ctx = self::ctx($request);
        $ctx->login($result->user);
        if ($result->notice !== null) {
            $ctx->setFlash($result->notice); // 이동한 화면의 레이아웃이 한 번 보여 준다
        }
        return self::landing($request, $result->user, $next);
    }

    /**
     * @param string|null $error 이미 만든 오류 문장
     * @param array{kind:string, text:string, code:string}|null $account P28B 승인 대기·거절 안내
     */
    private function renderLogin(Request $request, string $loginId, string $next, ?string $noticeKey, ?string $error, int $status, ?array $account = null): Response
    {
        $html = $this->app->view($request)->page('login', [
            'title' => Lang::get('login.title'),
            'user' => null,
            'body_class' => 'page-login',
        ], [
            'loginId' => $loginId,
            'next' => Navigation::safeNext($next) ?? '',
            'notice' => $noticeKey === null ? null : Lang::get($noticeKey),
            'error' => $error,
            'account' => $account,
            // P28B SSO 단추 자리 — 켜진 SSO 제공자(P28C OIDC, P28D SAML). 돌아갈 곳(next)은 return으로 넘긴다(시작 경로가 다시 검사)
            'ssoProviders' => array_map(static function (array $p) use ($request, $next): array {
                $safe = Navigation::safeNext($next);
                return ['id' => $p['id'], 'label' => $p['label'] ?? (($p['id'] === 'saml') ? Lang::get('login.sso_saml') : Lang::get('login.sso_oidc')), // 기본 문구
                    'href' => $request->basePath . $p['href'] . ($safe === null ? '' : '?return=' . rawurlencode($safe))];
            }, $this->app->ssoProviders()),
        ]);
        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    /** POST /logout (폼) */
    public function logoutSubmit(Request $request): Response
    {
        $user = $request->attribute('user');
        self::ctx($request)->logout();
        // P28C: 제공자 로그아웃(oidc.provider_logout, 기본 꺼짐)이 켜져 있으면 OIDC 계정은 IdP 로그아웃으로(돌아올 곳은 로그인 화면). 꺼져 있으면 앱 세션만
        if (($user['auth_type'] ?? null) === 'oidc' && $this->app->oidcEnabled()) {
            $url = $this->app->oidcLogin()->logoutUrl();
            if ($url !== null) {
                return Response::redirect($url);
            }
        }
        return self::redirect($request, '/login?logged_out=1');
    }

    /** GET /password */
    public function passwordForm(Request $request): Response
    {
        return $this->renderPassword($request, null, 200);
    }

    /** POST /password (폼) */
    public function passwordSubmit(Request $request): Response
    {
        $user = $request->attribute('user');
        $new = $request->formString('new_password');
        try {
            if ($new !== $request->formString('new_password_confirm')) {
                throw new ApiException(400, 'password_mismatch');
            }
            $this->app->users()->changeOwnPassword((int) $user['id'], $request->formString('current_password'), $new, self::ctx($request)->session()?->idHash);
        } catch (ApiException $e) {
            return $this->renderPassword($request, $e->userMessage(), $e->status);
        }
        self::ctx($request)->setFlash(['code' => 'password_changed']); // 홈 화면이 배너로 한 번 보여 준다
        return self::redirect($request, Navigation::HOME);
    }

    private function renderPassword(Request $request, ?string $error, int $status): Response
    {
        $user = $request->attribute('user');
        $policy = $this->app->authSettings()->passwordPolicy();
        $html = $this->app->view($request)->page('password', [
            'title' => Lang::get('password.title'),
            'user' => $user,
            'body_class' => 'page-password',
        ], [
            'forced' => $user['must_change_password'] === true,
            'external' => ($user['auth_type'] ?? 'local') !== 'local',
            'error' => $error,
            'policyText' => Lang::get('password.policy', ['min' => $policy->minLength(), 'classes' => $policy->minClasses()]),
        ]);
        return Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
    }

    /** GET /home: P10의 임시 홈 주소. P11부터 홈은 프로젝트 목록이므로 그쪽으로 보낸다(옛 즐겨찾기 호환) */
    public function home(Request $request): Response
    {
        return self::redirect($request, Navigation::HOME);
    }
}
