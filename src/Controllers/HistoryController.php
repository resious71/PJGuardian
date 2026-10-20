<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 히스토리 API(P21, 명세 8). 접근 판정은 라우트의 RequireProjectAccess(프로젝트 / 분석 → 프로젝트 / 결함 → 프로젝트). 보이지 않으면 404.
 * - GET /api/v1/projects/{id}/history: 분석 이력(최신순, 모든 상태, page/size 기본 ui.history_page_size·최대 ui.history_page_size_max)
 * - GET /api/v1/analyses/{id}/comparison: 직전 성공 분석 대비(성공이 아니면 409 not_succeeded)
 * - GET /api/v1/defects/{id}/timeline: 결함 한 건의 전체 변경 이력(시간순, page/size 최대 ui.page_size_max)
 */
final class HistoryController
{
    public function __construct(private readonly App $app)
    {
    }

    public function list(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.history_page_size'), $this->app->config->int('ui.history_page_size_max'));
        // P27: unchanged=hide → "변경 없음" 행을 뺀다(기본: 보이기). 다른 값은 400
        $u = $request->query['unchanged'] ?? null;
        if ($u !== null && $u !== '' && $u !== 'hide' && $u !== 'show') {
            throw new \PJGuardian\Http\ApiException(400, 'invalid_unchanged_filter');
        }
        [$items, $total] = $this->app->history()->list((int) $request->attribute('project_id'), $p->size, $p->offset(), $u === 'hide');
        return Response::page($items, $p, $total);
    }

    public function comparison(Request $request): Response
    {
        return Response::data($this->app->history()->comparison((int) $request->param('id')));
    }

    public function timeline(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.page_size_max'), $this->app->config->int('ui.page_size_max'));
        $r = $this->app->history()->timeline((int) $request->param('id'), $p->size, $p->offset());
        return Response::page($r['items'], $p, $r['total'], ['summary' => $r['summary']]);
    }
}
