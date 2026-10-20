<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/** 사용자 관리 화면(GET /admin/users, 관리자 전용). 목록과 동작은 JS(pages/admin_users.js)가 API로 처리한다. */
final class AdminUserPageController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        $policy = $this->app->authSettings()->passwordPolicy();
        $html = $this->app->view($request)->page('admin/users', [
            'title' => Lang::get('users.title'),
            'user' => $request->attribute('user'),
            'active' => 'admin.users',
            'scripts' => ['js/pages/admin_users.js'],
            'body_class' => 'page-admin-users',
        ], [
            'tempHours' => intdiv($this->app->authSettings()->tempPasswordExpiry, 3600),
            'policyText' => Lang::get('password.policy', ['min' => $policy->minLength(), 'classes' => $policy->minClasses()]),
            'selfId' => (int) $request->attribute('user')['id'],
        ]);
        return Response::html($html);
    }
}
