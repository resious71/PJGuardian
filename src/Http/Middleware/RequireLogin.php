<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\SessionContext;

/**
 * 로그인 필요(라우트 미들웨어). API는 401(unauthenticated, 무활동 만료면 session_expired),
 * 화면은 로그인 화면으로 보낸다(GET이면 돌아올 주소를 next로 넘김).
 */
final class RequireLogin implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->attribute('user') !== null) {
            return $next($request);
        }
        /** @var SessionContext|null $ctx */
        $ctx = $request->attribute('session_ctx');
        $expired = $ctx?->expired() ?? false;
        if ($request->isApi()) {
            throw new ApiException(401, $expired ? 'session_expired' : 'unauthenticated');
        }
        $query = $request->method === 'GET' ? ['next' => $request->pathWithQuery()] : [];
        if ($expired) {
            $query['expired'] = '1';
        }
        return Response::redirect($request->basePath . '/login' . ($query === [] ? '' : '?' . http_build_query($query)));
    }
}
