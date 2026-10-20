<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 분석 조회·취소 API(명세 5.2, 5.4, P14). 로그인 필수, 프로젝트 접근은 라우트의 RequireProjectAccess가 먼저 판정한다
 * (목록 = 프로젝트 id, 상세·취소 = 분석 id → 소유 프로젝트, 보이지 않으면 404). 취소는 CSRF(전역 미들웨어).
 */
final class AnalysisController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /api/v1/projects/{id}/analyses?status=queued,running&page=&size= */
    public function list(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        [$items, $total] = $this->app->analyses()->list((int) $request->attribute('project_id'), $request->query['status'] ?? null, $p->size, $p->offset(), $request->attribute('user'),
            $request->query['trigger'] ?? null);
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/analyses/{id} */
    public function show(Request $request): Response
    {
        return Response::data($this->app->analyses()->get((int) $request->param('id'), $request->attribute('user')));
    }

    /** POST /api/v1/analyses/{id}/cancel */
    public function cancel(Request $request): Response
    {
        return Response::data($this->app->analyses()->cancel($request->attribute('user'), (int) $request->param('id')));
    }
}
