<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 분석 상세 화면(명세 5.2, 8, 15.2, P19): GET /analyses/{id}. 라우트의 RequireProjectAccess가 분석 → 프로젝트로 먼저 판정한다
 * (보이지 않으면 없는 분석과 같은 404). 상태·결과·실패는 pages/analysis_detail.js가 API로 읽고 진행 중이면 주기적으로 갱신한다.
 */
final class AnalysisPageController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        $analysis = $this->app->analyses()->get((int) $request->param('id'), $request->attribute('user'));
        $project = $this->app->projects()->get((int) $analysis['project_id'], false);
        return Response::html($this->app->view($request)->page('analyses/show', [
            'title' => isset($analysis['run']) ? Lang::get('runs.title_of', ['run' => $analysis['run'], 'id' => $analysis['id']]) : Lang::get('analyses.title_of', ['id' => $analysis['id']]),
            'user' => $request->attribute('user'),
            'active' => 'nav.projects',
            'scripts' => ['js/pages/analysis_detail.js'],
        ], ['analysis' => $analysis, 'project' => $project]));
    }
}
