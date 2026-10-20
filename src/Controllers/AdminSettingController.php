<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 화면 설정(P21C, admin만 — 라우트 미들웨어 $admin): GET/PATCH /api/v1/admin/settings. 저장은 Services\AppSettings(014).
 */
final class AdminSettingController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /api/v1/admin/settings → {키: {value, default, min, max}} */
    public function index(Request $request): Response
    {
        return Response::data($this->app->appSettings()->all());
    }

    /** PATCH /api/v1/admin/settings 본문 {키: 값} → 바뀐 뒤 전체 */
    public function update(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw new ApiException(400, 'invalid_setting', [], null, [], [], ['key' => null]);
        }
        $user = $request->attribute('user');
        return Response::data($this->app->appSettings()->update(Actor::fromUser($user, $request->clientIp), (int) $user['id'], $body));
    }
}
