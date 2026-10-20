<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 관리자 전용(명세 13). 화면 숨김과 관계없이 서버에서 항상 검사한다: API는 403 JSON, 화면은 403 오류 페이지.
 * RequireLogin, RequirePasswordChanged 다음에 둔다.
 */
final class RequireAdmin implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        if (!is_array($user) || $user['role'] !== 'admin') {
            throw new ApiException(403, 'forbidden', logContext: ['user_id' => is_array($user) ? $user['id'] : null, 'path' => $request->path]);
        }
        return $next($request);
    }
}
