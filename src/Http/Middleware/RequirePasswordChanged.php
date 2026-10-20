<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 임시 비밀번호로 로그인했으면 비밀번호 변경 외의 모든 요청을 막는다(명세 3, 13).
 * RequireLogin 다음에 둔다. 허용: 비밀번호 변경 화면·API, 로그아웃, 현재 사용자 조회(이 미들웨어를 붙이지 않음).
 */
final class RequirePasswordChanged implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $user = $request->attribute('user');
        if (is_array($user) && $user['must_change_password'] === true) {
            if ($request->isApi()) {
                throw new ApiException(403, 'password_change_required');
            }
            return Response::redirect($request->basePath . '/password');
        }
        return $next($request);
    }
}
