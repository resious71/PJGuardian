<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 프로젝트 화면(명세 3.1, 4장, 13장, 20.4). 목록은 볼 수 있는 프로젝트만, 상세는 라우트의 RequireProjectAccess가 먼저 판정(404),
 * 생성·수정·삭제 화면은 관리자(라우트 미들웨어). 수정 화면에 접근 권한 영역(매핑, pages/project_access.js).
 * 폼 제출은 화면 JS(pages/project_form.js, project_delete.js)가 API로 보낸다. 서버는 화면을 그리고 권한을 검사한다.
 */
final class ProjectPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** P19C: 현재 결함(해결 아님)이 [limits] project_defect_warn을 넘으면 {n, warn}, 아니면 null(0 = 끔) */
    private function defectWarn(int $projectId): ?array
    {
        $warn = $this->app->config->int('limits.project_defect_warn');
        if ($warn <= 0) {
            return null;
        }
        $n = (int) $this->app->db()->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status <> 'resolved'", [$projectId]);
        return $n > $warn ? ['n' => $n, 'warn' => $warn] : null;
    }

    private static function isAdmin(Request $request): bool
    {
        return ($request->attribute('user')['role'] ?? '') === 'admin';
    }

    /** @param array<string, mixed> $vars */
    private function render(Request $request, string $template, string $title, array $vars = [], array $scripts = [], ?string $active = 'nav.projects'): Response
    {
        $html = $this->app->view($request)->page($template, [
            'title' => $title,
            'user' => $request->attribute('user'),
            'active' => $active,
            'scripts' => $scripts,
        ], $vars + ['isAdmin' => self::isAdmin($request)]);
        return Response::html($html);
    }

    /** GET /projects: 목록(홈). 프로젝트가 없으면 빈 상태(관리자 = 생성 버튼, 사용자 = 요청 안내) */
    public function list(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        [$items, $total] = $this->app->projects()->list(null, $p->size, $p->offset(), $request->attribute('user'));
        return $this->render($request, 'projects/list', Lang::get('projects.title'), [
            'projects' => $items,
            // P26: 주기 분석 사용 중인 프로젝트(다음 예정, admin이면 연속 실패)
            'schedules' => $this->app->schedules()->summaries(array_map(static fn (array $p): int => (int) $p['id'], $items), self::isAdmin($request)),
            'total' => $total,
            'pageNo' => $p->page,
            'pages' => max(1, (int) ceil($total / $p->size)),
        ]);
    }

    /**
     * GET /projects/{id}: 상세(헤더 + 탭). 구현된 탭만 넣는다(비어 있으면 탭을 숨긴다).
     * P17: "결함" 탭(목록·필터·검색, pages/defects.js). 필터 상태는 이 화면의 URL 쿼리에 들어간다(새로고침·링크 공유).
     * P19: 결함 현황 요약(심각도·상태·담당자별 건수, 명세 8)과 최근 분석 카드, "분석" 탭(pages/analyses.js, 주소 #analyses로 바로 열림).
     */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $project = $this->app->projects()->get($id, self::isAdmin($request));
        // View는 한 번만 만든다(App::view가 세션의 일회성 알림을 꺼내므로 두 번 만들면 배너가 사라진다)
        $view = $this->app->view($request);
        $tabs = [[
            'id' => 'defects',
            'label' => Lang::get('defects.tab'),
            'html' => $view->partial('defects/panel', [
                'project' => $project,
                'overview' => $this->app->defectQuery()->overview($id),
                'pageSize' => $this->app->config->int('ui.defect_page_size'),
            ]),
        ], [
            'id' => 'analyses',
            'label' => Lang::get('analyses.tab'),
            'html' => $view->partial('analyses/panel', ['project' => $project, 'pageSize' => $this->app->config->int('ui.defect_page_size')]),
        ]];
        // 최근 분석 카드 = 실제로 분석한 가장 최근 행(P27C: 변경 없음 확인은 저장소 상자의 "마지막 확인"과 이력에서 본다)
        [$latest] = $this->app->analyses()->list($id, null, 1, 0, $request->attribute('user'), null, true);
        // 이후 단계가 같은 형식으로 탭을 더한다(설정 탭 등)
        return Response::html($view->page('projects/show', [
            'title' => $project['name'],
            'user' => $request->attribute('user'),
            'active' => 'nav.projects',
            'scripts' => ['js/pages/defects.js', 'js/pages/analyses.js', 'js/pages/project_repo_status.js'],
        ], ['project' => $project, 'tabs' => $tabs, 'isAdmin' => self::isAdmin($request),
            'summary' => $this->app->defectQuery()->summary($id, \PJGuardian\Services\Defects\DefectFilter::fromQuery([]), 1),
            'latest' => $latest[0] ?? null, 'latestRun' => $this->app->runs()->status($id)['latest'], 'defectWarn' => $this->defectWarn($id)]));
    }

    /** GET /projects/new */
    public function newForm(Request $request): Response
    {
        return $this->render($request, 'projects/form', Lang::get('projects.new_title'), [
            'project' => null,
            'defaults' => $this->app->projects()->defaultExcludes(),
        ], ['js/pages/project_form.js'], 'admin.projects');
    }

    /** GET /projects/{id}/edit */
    public function editForm(Request $request): Response
    {
        $id = (int) $request->param('id');
        $project = $this->app->projects()->get($id, true);
        return $this->render($request, 'projects/form', Lang::get('projects.edit_title', ['name' => $project['name']]), [
            'project' => $project,
            'defaults' => $project['default_excludes'],
            'access' => $this->app->projectAccess()->mappings($id),
            'allGroups' => $this->app->groups()->list($request->attribute('user')),
        ], ['js/pages/project_form.js', 'js/pages/project_access.js', 'js/pages/project_repo.js', 'js/pages/project_schedule.js'], 'admin.projects');
    }

    /** GET /projects/{id}/delete: 삭제될 분석 횟수·결함 건수·스냅샷 크기, 이름을 정확히 입력해야 버튼 활성 */
    public function deleteForm(Request $request): Response
    {
        $id = (int) $request->param('id');
        $project = $this->app->projects()->get($id, true);
        return $this->render($request, 'projects/delete', Lang::get('projects.delete_title', ['name' => $project['name']]), [
            'project' => $project,
            'info' => $this->app->projects()->deletionInfo($id) + ['access_mappings' => $this->app->projectAccess()->mappingCount($id)],
        ], ['js/pages/project_delete.js'], 'admin.projects');
    }
}
