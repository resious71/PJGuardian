<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 히스토리 화면(P21). 주 메뉴 "히스토리" → 프로젝트 선택(보이는 프로젝트만, 결함 메뉴와 같은 규칙) → /projects/{id}/history.
 * 화면은 시간 흐름과 변화 해석(이력 표·추이 차트·직전 성공 대비 비교 패널). 분석 진행·취소·실패 처리는 프로젝트의 "분석" 탭(P19)이 한다.
 * 본문은 pages/history.js가 API로 읽어 textContent로 그린다.
 */
final class HistoryPageController
{
    public function __construct(private readonly App $app)
    {
    }

    public function picker(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        [$items, $total] = $this->app->projects()->list(null, $p->size, $p->offset(), $request->attribute('user'));
        return Response::html($this->app->view($request)->page('history/picker', [
            'title' => Lang::get('history.picker_title'),
            'user' => $request->attribute('user'),
            'active' => 'nav.history',
            'scripts' => [],
        ], ['projects' => $items, 'pageNo' => $p->page, 'pages' => max(1, (int) ceil($total / $p->size))]));
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $project = $this->app->projects()->get($id, false);
        return Response::html($this->app->view($request)->page('history/show', [
            'title' => Lang::get('history.title') . ' · ' . $project['name'],
            'user' => $request->attribute('user'),
            'active' => 'nav.history',
            'scripts' => ['js/pages/history.js'],
        ], ['project' => $project, 'pageSize' => $this->app->config->int('ui.history_page_size')]));
    }
}
