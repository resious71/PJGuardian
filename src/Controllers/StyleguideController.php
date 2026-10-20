<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 개발 전용 스타일 가이드(GET /dev/styleguide). app.env=dev가 아니면 404(존재를 드러내지 않음).
 * ?as=admin|user|guest 로 레이아웃(관리자 메뉴 유무, 비로그인)을 미리 본다. 실제 세션 연동은 P10.
 */
final class StyleguideController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        if ($this->app->config->string('app.env') !== 'dev') {
            throw new ApiException(404, 'not_found');
        }
        $as = $request->query['as'] ?? 'admin';
        $as = in_array($as, ['admin', 'user', 'guest'], true) ? $as : 'admin';
        $user = match ($as) {
            'admin' => ['login_id' => 'admin', 'display_name' => Lang::get('styleguide.preview_admin_name'), 'role' => 'admin'],
            'user' => ['login_id' => 'user', 'display_name' => Lang::get('styleguide.preview_user_name'), 'role' => 'user'],
            default => null,
        };
        $view = $this->app->view($request);
        $html = $view->page('styleguide', [
            'title' => Lang::get('styleguide.title'),
            'user' => $user,
            'scripts' => ['js/pages/styleguide.js'],
            'body_class' => 'page-styleguide',
        ], ['previewAs' => $as]);
        return Response::html($html);
    }
}
