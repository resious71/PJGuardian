<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 관리자 장애 이메일(P30B, admin만 — 라우트의 RequireAdmin, 쓰기는 CSRF). 연결 설정 값은 설정 관리(/api/v1/admin/config-settings)에서.
 *   GET    /api/v1/admin/alert-mail             상태(꺼짐·설정 필요·켜짐, 빠진 것, SMTP 비밀번호 set|none|unreadable, 평문 여부, 받는 사람 수)
 *   PUT    /api/v1/admin/alert-mail/password    {password} 저장·교체(값은 다시 내리지 않음)
 *   DELETE /api/v1/admin/alert-mail/password    삭제
 *   POST   /api/v1/admin/alert-mail/test        {to?} 저장된 설정으로 시험 메일 한 통(사용자당 분당·전체 시간당 횟수 제한)
 */
final class AdminAlertMailController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    public function status(Request $request): Response
    {
        return Response::data($this->app->alertMailer()->status());
    }

    public function putPassword(Request $request): Response
    {
        return Response::data(['password' => $this->app->alertMailer()->setPassword(self::actor($request), $request->json()['password'] ?? null)]);
    }

    public function deletePassword(Request $request): Response
    {
        return Response::data(['password' => $this->app->alertMailer()->deletePassword(self::actor($request))]);
    }

    public function test(Request $request): Response
    {
        $body = $request->body() === '' ? [] : $request->json();
        $user = $request->attribute('user');
        $fallback = is_array($user) && is_string($user['email'] ?? null) ? $user['email'] : null;
        return Response::data($this->app->alertMailer()->sendTest(self::actor($request), $body['to'] ?? null, $fallback));
    }
}
