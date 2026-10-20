<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;
use PJGuardian\Repositories\UserRepository;
use PJGuardian\Services\Auth\AuthSettings;
use PJGuardian\Services\Auth\SessionService;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 세션 쿠키로 세션과 사용자를 찾아 요청 속성으로 붙인다(전역 미들웨어).
 *   session_ctx : SessionContext
 *   user        : 공개 사용자 정보(로그인 안 했으면 null)
 *   csrf_token  : 화면 <meta name="csrf-token">과 폼 숨은 필드용
 *
 * - 쿠키가 없으면 DB를 읽지 않는다(세션은 로그인 폼·CSRF 발급 때만 만든다).
 * - 사용자를 매 요청 DB에서 다시 읽는다: 비활성화되면 세션을 지우고 비로그인으로 처리(명세 13: 즉시 무효화).
 * - 활동 시각 갱신은 SessionService::touch(간격 이내면 쓰지 않음). 배경 요청 헤더 X-PJG-Background: 1은 갱신하지 않는다(P24 알림 개수 폴링).
 */
final class SessionMiddleware implements Middleware
{
    /**
     * @param \Closure(): Db $db DB는 쿠키가 있을 때만 연다
     * @param \Closure(): SessionService $sessions
     */
    public function __construct(
        private readonly \Closure $db,
        private readonly \Closure $sessions,
        private readonly AuthSettings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $raw = $request->cookie(SessionContext::COOKIE);
        $session = null;
        $user = null;
        $userUi = null; // P27D 계정의 화면 언어·테마(LocaleMiddleware가 씀)
        $expired = false;
        /** @var SessionService|null $service */
        $service = null;
        if ($raw !== null && $raw !== '') {
            $service = ($this->sessions)();
            $lookup = $service->resolve($raw);
            $session = $lookup->session;
            $expired = $lookup->expired;
            if ($session !== null && $session->userId !== null) {
                $row = (new UserRepository(($this->db)()))->find($session->userId);
                // 비활성·승인되지 않은 계정(P28B: 승인 대기·거절은 늘 비활성이지만 한 번 더)은 세션을 지운다
                if ($row === null || $row['status'] !== 'active' || ($row['approval_status'] ?? 'approved') !== 'approved') {
                    $service->destroy($session);
                    $session = null;
                } else {
                    $user = UserRepository::toPublic($row, SystemClock::iso($this->clock->now()));
                    // P27D 계정의 화면 언어·테마 — 따로 읽고 실패하면(마이그레이션 025 전) 없는 것으로(화면은 쿠키·브라우저 언어로 동작)
                    try {
                        $ui = ($this->db)()->one('SELECT ui_language, ui_theme FROM users WHERE id = ?', [(int) $row['id']]);
                        $userUi = ['language' => $ui['ui_language'] ?? null, 'theme' => $ui['ui_theme'] ?? null];
                    } catch (\Throwable) {
                        $userUi = null;
                    }
                    $this->logger->withUserId($user['id']);
                }
            }
            // 배경 요청(X-PJG-Background: 1 — 헤더 알림 개수 주기 갱신, P24)은 활동으로 치지 않는다(열어 둔 탭이 유휴 만료를 막지 않게)
            if ($session !== null && $request->header('x-pjg-background') !== '1') {
                $session = $service->touch($session);
            }
        }
        $ctx = new SessionContext(
            $this->sessions,
            $this->settings,
            $request->clientIp,
            $request->header('user-agent'),
            $request->basePath === '' ? '/' : $request->basePath,
            $session,
            $user,
            $expired,
            $raw !== null && $raw !== '',
        );
        $request = $request
            ->withAttribute('session_ctx', $ctx)
            ->withAttribute('user', $user)
            ->withAttribute('user_ui', $userUi)
            ->withAttribute('csrf_token', $session?->csrfToken ?? '');

        $response = $next($request);

        $cookie = $ctx->setCookieHeader();
        return $cookie === null ? $response : $response->withHeader('Set-Cookie', $cookie);
    }
}
