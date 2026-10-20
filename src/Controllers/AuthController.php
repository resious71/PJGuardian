<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;
use PJGuardian\Services\Auth\LoginResult;

/**
 * 인증 API(/api/v1/auth/...). 모든 POST는 CSRF 헤더가 필요하다(로그인 포함).
 * 로그인 전 클라이언트는 GET /api/v1/auth/csrf로 토큰(과 로그인 전 세션)을 먼저 받는다.
 */
final class AuthController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function ctx(Request $request): SessionContext
    {
        return $request->attribute('session_ctx');
    }

    /** GET /api/v1/auth/csrf: 세션이 없으면 로그인 전 세션을 만들고 토큰을 돌려준다 */
    public function csrf(Request $request): Response
    {
        return Response::data(['csrf_token' => self::ctx($request)->ensure()->csrfToken]);
    }

    /** POST /api/v1/auth/login */
    public function login(Request $request): Response
    {
        $body = $request->json();
        $loginId = $body['login_id'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($loginId) || !is_string($password) || $loginId === '' || $password === '') {
            throw new ApiException(400, 'invalid_input');
        }
        $result = self::attempt($this->app, $request, $loginId, $password);
        $session = self::ctx($request)->login($result->user);
        // notice: 관리자가 비밀번호를 바꾼 뒤 첫 로그인이면 {code: password_set_by_admin, at}, 아니면 null
        return Response::data(['user' => $result->user, 'csrf_token' => $session->csrfToken, 'notice' => $result->notice]);
    }

    /**
     * 로그인 판정 공통(API·화면). 실패하면 ApiException. 성공 결과의 user는 공개 사용자 정보.
     */
    public static function attempt(App $app, Request $request, string $loginId, string $password): LoginResult
    {
        $result = $app->login()->attempt($loginId, $password, $request->clientIp);
        if (!$result->ok) {
            throw match ($result->code) {
                'ip_blocked' => new ApiException(429, 'too_many_attempts'),
                'temp_password_expired' => new ApiException(401, 'temp_password_expired'),
                // P28B 외부 인증: 세션 없이 안내(외부 인증을 통과한 사람에게만 — 존재 노출 없음)
                'pending' => new ApiException(403, 'account_pending'),
                'rejected' => new ApiException(403, 'account_rejected'),
                'pending_limit' => new ApiException(403, 'account_requests_closed'),
                'directory_unavailable' => new ApiException(503, 'auth_service_unavailable'),
                default => new ApiException(401, 'login_failed'),
            };
        }
        $app->sessions()->purgeExpired();
        return $result;
    }

    /** POST /api/v1/auth/logout */
    public function logout(Request $request): Response
    {
        self::ctx($request)->logout();
        return Response::data(['logged_out' => true]);
    }

    /** GET /api/v1/auth/me (임시 비밀번호 상태에서도 허용) */
    public function me(Request $request): Response
    {
        return Response::data(['user' => $request->attribute('user')]);
    }

    /** POST /api/v1/auth/password: 본인 비밀번호 변경(임시 비밀번호 강제 변경 포함) */
    public function changePassword(Request $request): Response
    {
        $body = $request->json();
        $current = $body['current_password'] ?? null;
        $new = $body['new_password'] ?? null;
        if (!is_string($current) || !is_string($new)) {
            throw new ApiException(400, 'invalid_input');
        }
        $user = $request->attribute('user');
        $this->app->users()->changeOwnPassword((int) $user['id'], $current, $new, self::ctx($request)->session()?->idHash);
        return Response::data(['user' => $this->app->users()->get((int) $user['id'])]);
    }
}
