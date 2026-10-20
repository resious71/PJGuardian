<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 결함 화면(명세 6.4, 7, P17).
 * - GET /defects: "결함" 메뉴. 볼 수 있는 프로젝트를 고르면 그 프로젝트 상세의 "결함" 탭(같은 화면)으로 간다.
 *   목록은 ProjectAccess::scopeFor로 거른다(프로젝트 id를 받지 않으므로 경로 판정 대상이 아님).
 * - GET /defects/{id}: 결함 상세. 라우트의 RequireProjectAccess가 결함 → 프로젝트로 먼저 판정한다(보이지 않으면 404).
 *   요약은 서버가 그리고(모든 값 이스케이프), 코드 뷰어와 변경 이력은 화면 JS(pages/defect_detail.js)가 API로 읽어 텍스트로 그린다.
 *   ?run=N(P20R): 과거 Run 시점 값(읽기 전용, 수정 폼 없음). 없는 Run은 404, 형식 오류는 422(API와 같은 판정 RunService::resolve).
 */
final class DefectPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string, mixed> $vars @param list<string> $scripts */
    private function render(Request $request, string $template, string $title, array $vars, array $scripts = [], string $active = 'nav.defects'): Response
    {
        return Response::html($this->app->view($request)->page($template, [
            'title' => $title,
            'user' => $request->attribute('user'),
            'active' => $active,
            'scripts' => $scripts,
        ], $vars));
    }

    /** GET /defects: 프로젝트 선택 */
    public function picker(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        [$items, $total] = $this->app->projects()->list(null, $p->size, $p->offset(), $request->attribute('user'));
        return $this->render($request, 'defects/picker', Lang::get('defects.picker_title'), [
            'projects' => $items,
            'counts' => $this->app->defectQuery()->countsByProject(array_map(static fn (array $x): int => (int) $x['id'], $items)),
            'pageNo' => $p->page,
            'pages' => max(1, (int) ceil($total / $p->size)),
        ]);
    }

    /** GET /defects/{id} */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $runs = $this->app->runs();
        try {
            $run = $runs->resolve((int) $this->app->defectQuery()->projectIdOf((string) $id), $request->query['run'] ?? null);
        } catch (ApiException $e) {
            if ($e->status !== 410) {
                throw $e;
            }
            // P21B: 상세가 삭제된 Run — 410과 안내, 최신 Run(현재 값)으로 보는 링크
            return Response::html($this->app->view($request)->page('partials/run_deleted', [
                'title' => Lang::get('runs.detail_deleted_title', ['run' => (int) ($e->details['run'] ?? 0)]),
                'user' => $request->attribute('user'),
                'active' => 'nav.defects',
                'scripts' => [],
            ], ['run' => (int) ($e->details['run'] ?? 0), 'latestHref' => $request->basePath . '/defects/' . $id]), 410);
        }
        $defect = $this->app->defectQuery()->detail($id, $run);
        $project = $this->app->projects()->get((int) $defect['project_id'], false);
        return $this->render($request, 'defects/show', Lang::get('defects.detail_title', ['rule' => $defect['rule_key'], 'file' => $defect['file_path'], 'line' => $defect['line']]), [
            'defect' => $defect,
            'project' => $project,
            'historyPageSize' => $this->app->config->int('ui.defect_page_size'),
            'excerptContext' => $this->app->config->int('snapshot.excerpt_context_lines'),
            'assignees' => $this->app->projectAccess()->assignableUsers((int) $defect['project_id']),
            'latestRun' => $runs->status((int) $defect['project_id'])['latest'],
            // 규칙 쉬운 설명(P32B, 명세 11) — 규칙 설명 화면과 같은 자료
            'ruleGuide' => str_contains((string) $defect['rule_key'], ':') ? $this->app->ruleQuery()->guide(...explode(':', (string) $defect['rule_key'], 2)) : null,
        ], ['js/pages/defect_detail.js']);
    }

    /** GET /my-defects: 내게 지정된 결함(명세 17). 상태별 건수는 서버가, 목록은 pages/my_defects.js가 API로 */
    public function mine(Request $request): Response
    {
        $summary = $this->app->defectQuery()->mineSummary($request->attribute('user'));
        return $this->render($request, 'defects/mine', Lang::get('defects.mine_title'), [
            'summary' => $summary,
            'pageSize' => $this->app->config->int('ui.defect_page_size'),
        ], ['js/pages/my_defects.js'], 'nav.my_defects');
    }
}
