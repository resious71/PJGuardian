<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Html;
use PJGuardian\Support\Lang;

/**
 * 업그레이드 유지보수 모드(P32B, 명세 19.4 — 설정 [maintenance] enabled). 세션 뒤(사용자 역할을 봐야 함)·CSRF 앞에 둔다.
 * 켜져 있으면 admin이 아닌 사용자의 **쓰기 요청**(GET·HEAD·OPTIONS 밖 — 업로드 조각·완료 포함)은 503 maintenance + 안내 문구.
 * 읽기·로그인·로그아웃·자기 비밀번호 변경은 허용, admin은 모두 허용(업그레이드 작업을 해야 하므로). 복원 중 유지보수(MaintenanceMiddleware)와는 별개.
 */
final class UpgradeMaintenanceMiddleware implements Middleware
{
    private const ALLOWED = ['/api/v1/auth/login', '/api/v1/auth/logout', '/api/v1/auth/password', '/login', '/logout', '/password'];

    /**
     * @param \Closure(): bool $active
     * @param \Closure(): string $message 관리자가 정한 안내 문구(없으면 '')
     */
    public function __construct(private readonly \Closure $active, private readonly \Closure $message)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) || in_array($request->path, self::ALLOWED, true) || !($this->active)()) {
            return $next($request);
        }
        $user = $request->attribute('user');
        if (is_array($user) && ($user['role'] ?? '') === 'admin') {
            return $next($request);
        }
        $text = Lang::get('error.maintenance_upgrade', ['message' => trim(($this->message)())]);
        if ($request->isApi()) {
            $id = $request->attribute('request_id');
            return Response::error(503, 'maintenance', $text, is_string($id) ? $id : null)->withHeader('Retry-After', '600');
        }
        return Response::html('<!doctype html><meta charset="utf-8"><title>' . Html::e(Lang::get('maintenance.title')) . '</title><p>' . Html::e($text) . '</p>', 503)
            ->withHeader('Retry-After', '600');
    }
}
