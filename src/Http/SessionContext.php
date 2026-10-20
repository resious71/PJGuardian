<?php

declare(strict_types=1);

namespace PJGuardian\Http;

use PJGuardian\Services\Auth\AuthSettings;
use PJGuardian\Services\Auth\Session;
use PJGuardian\Services\Auth\SessionService;

/**
 * 요청 하나의 세션 상태. SessionMiddleware가 만들어 요청 속성 'session_ctx'로 붙이고,
 * 컨트롤러가 ensure()/login()/logout()을 부르면 응답에 Set-Cookie를 붙인다.
 *
 * 쿠키: pjg_session=<세션 ID>; Path=<배포 경로>; HttpOnly; Secure; SameSite=Lax (명세 3). 만료 시각 없는 세션 쿠키.
 */
final class SessionContext
{
    public const COOKIE = 'pjg_session';

    private ?string $newRawId = null;
    private bool $clear = false;

    /**
     * @param \Closure(): SessionService $serviceFactory 세션을 만들거나 바꿀 때만 부른다(쿠키 없는 요청은 DB를 열지 않음)
     * @param array<string, mixed>|null $user 공개 사용자 정보
     */
    public function __construct(
        private readonly \Closure $serviceFactory,
        private readonly AuthSettings $settings,
        private readonly string $ip,
        private readonly ?string $userAgent,
        private readonly string $cookiePath,
        private ?Session $session,
        private ?array $user,
        private readonly bool $expired,
        private readonly bool $hadCookie,
    ) {
    }

    private function service(): SessionService
    {
        return ($this->serviceFactory)();
    }

    public function session(): ?Session
    {
        return $this->session;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    /** 쿠키는 있었지만 무활동으로 만료됨 */
    public function expired(): bool
    {
        return $this->expired;
    }

    public function csrfToken(): ?string
    {
        return $this->session?->csrfToken;
    }

    /** 세션이 없으면 로그인 전 세션을 만든다(로그인 폼·CSRF 토큰 발급용). */
    public function ensure(): Session
    {
        if ($this->session === null) {
            [$raw, $this->session] = $this->service()->start($this->ip, $this->userAgent);
            $this->newRawId = $raw;
            $this->clear = false;
        }
        return $this->session;
    }

    /**
     * 로그인 성공: 세션 ID와 CSRF 토큰을 새로 만든다.
     *
     * @param array<string, mixed> $user 공개 사용자 정보
     */
    public function login(array $user): Session
    {
        [$raw, $this->session] = $this->service()->rotate($this->session, (int) $user['id'], $this->ip, $this->userAgent);
        $this->newRawId = $raw;
        $this->user = $user;
        $this->clear = false;
        return $this->session;
    }

    /** @param array<string, string> $flash 다음 화면에 한 번 보여 줄 알림 */
    public function setFlash(array $flash): void
    {
        if ($this->session !== null) {
            $this->session = $this->service()->setFlash($this->session, $flash);
        }
    }

    /** @return array<string, string>|null 알림을 꺼낸다(한 번만) */
    public function takeFlash(): ?array
    {
        if ($this->session === null || $this->session->flash === null) {
            return null;
        }
        $flash = $this->service()->takeFlash($this->session);
        $s = $this->session;
        $this->session = new Session($s->idHash, $s->userId, $s->csrfToken, $s->createdAt, $s->lastActivityAt, null);
        return $flash;
    }

    public function logout(): void
    {
        if ($this->session !== null) {
            $this->service()->destroy($this->session);
        }
        $this->session = null;
        $this->user = null;
        $this->newRawId = null;
        $this->clear = true;
    }

    /** 응답에 붙일 Set-Cookie 값(바꿀 것이 없으면 null) */
    public function setCookieHeader(): ?string
    {
        $attrs = '; Path=' . $this->cookiePath . '; HttpOnly' . ($this->settings->secureCookie ? '; Secure' : '') . '; SameSite=Lax';
        if ($this->newRawId !== null) {
            return self::COOKIE . '=' . $this->newRawId . $attrs;
        }
        if ($this->clear || ($this->hadCookie && $this->session === null)) {
            return self::COOKIE . '=; Max-Age=0' . $attrs;
        }
        return null;
    }
}
