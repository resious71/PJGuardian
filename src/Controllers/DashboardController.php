<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 대시보드(명세 9, P20): 화면 GET /dashboard와 구역별 API(/api/v1/dashboard/*). 로그인한 모든 사용자.
 * 집계 범위는 DashboardService가 보이는 프로젝트로 거르고, project_id는 requireView(보이지 않으면 404).
 */
final class DashboardController
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    private static function user(Request $request): array
    {
        return $request->attribute('user');
    }

    public function page(Request $request): Response
    {
        $user = self::user($request);
        return Response::html($this->app->view($request)->page('dashboard/index', [
            'title' => Lang::get('dashboard.title'),
            'user' => $user,
            'active' => 'nav.dashboard',
            'scripts' => ['js/pages/dashboard.js'],
            'body_class' => 'page-dashboard',
        ], ['projects' => array_values($this->app->dashboard()->visibleProjects($user)), 'isAdmin' => ($user['role'] ?? '') === 'admin',
            'trendProjects' => $this->app->dashboard()->trendProjects()]));
    }

    /** GET /api/v1/dashboard/summary */
    public function summary(Request $request): Response
    {
        return Response::data($this->app->dashboard()->summary(self::user($request)));
    }

    /** GET /api/v1/dashboard/trend?project_id=&language= (language: python|javascript, 그 밖은 전체 — P21C) */
    public function trend(Request $request): Response
    {
        return Response::data($this->app->dashboard()->trend(self::user($request), $request->query['project_id'] ?? null, $request->query['language'] ?? null));
    }

    /** GET /api/v1/dashboard/top-rules?project_id=&language= */
    public function topRules(Request $request): Response
    {
        return Response::data($this->app->dashboard()->top(self::user($request), 'rules', $request->query['project_id'] ?? null, $request->query['language'] ?? null));
    }

    /** GET /api/v1/dashboard/top-files?project_id= */
    public function topFiles(Request $request): Response
    {
        return Response::data($this->app->dashboard()->top(self::user($request), 'files', $request->query['project_id'] ?? null));
    }

    /** GET /api/v1/dashboard/tools */
    public function tools(Request $request): Response
    {
        return Response::data($this->app->dashboard()->tools(self::user($request)));
    }
}
