<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 유지보수 모드(P27B 복원 중) — 세션(DB를 연다)보다 **먼저** 둔다. 파일 플래그가 켜져 있으면 DB를 열지 않고:
 *   - GET /restore-status, GET /api/v1/restore-status: 복원 상태(파일 restore_state.json, 비밀 없음) — 로그인 없이
 *   - 그 밖의 API: 503 {"error": {"code": "maintenance"}}
 *   - 그 밖의 화면(로그인 포함): 503 + 복원 상태 페이지(안내 문구)
 * 꺼져 있으면 그대로 다음으로(상태 페이지·API도 보통 라우트로 — 끝난 결과 보기).
 */
final class MaintenanceMiddleware implements Middleware
{
    /**
     * @param \Closure(): bool $active
     * @param \Closure(Request, int): Response $statusPage
     * @param \Closure(): Response $statusApi
     */
    public function __construct(private readonly \Closure $active, private readonly \Closure $statusPage, private readonly \Closure $statusApi)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!($this->active)()) {
            return $next($request);
        }
        if ($request->path === '/api/v1/restore-status') {
            return ($this->statusApi)();
        }
        if ($request->isApi()) {
            $id = $request->attribute('request_id');
            return Response::error(503, 'maintenance', Lang::get('error.maintenance'), is_string($id) ? $id : null);
        }
        return ($this->statusPage)($request, $request->path === '/restore-status' ? 200 : 503);
    }
}
