<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * "시스템 관리 > Run 관리"(P21B, admin 전용): Run 상세 보관 현황과 수동 삭제.
 * 라우트는 접근 판정(RequireProjectAccess) 다음 관리자 확인(RequireAdmin) — 보이지 않는 프로젝트 404, admin이 아니면 403.
 */
final class AdminRunController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /admin/runs?project=<id>: 화면 틀(프로젝트 선택은 전체 프로젝트, 내용은 pages/admin_runs.js) */
    public function page(Request $request): Response
    {
        [$projects] = $this->app->projects()->list(null, 1000, 0, $request->attribute('user'));
        return Response::html($this->app->view($request)->page('admin/runs', [
            'title' => Lang::get('admin_runs.title'),
            'user' => $request->attribute('user'),
            'active' => 'admin.tools',
            'scripts' => ['js/pages/admin_runs.js'],
            'body_class' => 'page-admin-system',
        ], ['sub' => 'runs', 'projects' => $projects, 'keep' => $this->app->config->int('runs.detail_keep'), 'max' => \PJGuardian\Services\Runs\RunDetails::MANUAL_MAX]));
    }

    /** GET /api/v1/admin/projects/{id}/runs?page=&size=: Run 목록(최신순) + 보관 요약(상세 있는/삭제된 Run 수, 다음 자동 삭제 대상) */
    public function list(Request $request): Response
    {
        $projectId = (int) $request->attribute('project_id');
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        $details = $this->app->runDetails();
        [$items, $total] = $details->adminList($projectId, $p->size, $p->offset());
        $keep = $this->app->config->int('runs.detail_keep');
        return Response::page($items, $p, $total, ['summary' => $details->counts($projectId) + [
            'keep' => $keep,
            'next_retention' => $details->retentionCandidates($projectId, $keep),
            'kept_runs' => $details->keptRuns($projectId), // "오래된 Run 모두 선택(최근 N개 제외)"가 쪽과 관계없이 고르도록
        ]]);
    }

    /** POST /api/v1/admin/projects/{id}/runs/delete-detail {runs:[…]}: 상세 삭제(한 트랜잭션, 최신 Run 포함 시 전체 409) */
    public function deleteDetail(Request $request): Response
    {
        $body = $request->json();
        $user = $request->attribute('user');
        return Response::data($this->app->runDetails()->deleteManual($user, $request->clientIp, (int) $request->attribute('project_id'), $body['runs'] ?? null));
    }
}
