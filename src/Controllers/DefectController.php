<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Defects\DefectFilter;

/**
 * 결함 API(명세 6.2, 6.4, 7, 8, 17): 조회(P17)와 담당자·심각도·상태 변경, 일괄 변경, 담당자 후보, 내게 지정된 결함(P18).
 * 프로젝트 접근은 라우트의 RequireProjectAccess가 먼저 판정한다: 목록·요약 = 프로젝트 id, 상세·이력·코드 = 결함 id → 소유 프로젝트
 * (보이지 않으면 없는 결함과 같은 404 defect_not_found).
 */
final class DefectController
{
    public function __construct(private readonly App $app)
    {
    }

    private function pagination(Request $request): Pagination
    {
        return Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
    }

    /** GET /api/v1/projects/{id}/defects?rule=&severity=&status=&assignee=&file=&file_mode=&q=&sort=&order=&page=&size= */
    /**
     * 목록 원본: run(과거 Run 보기). 없으면 null(결함 표, 기존 동작).
     *
     * @return array{0: ?array, 1: ?array, 2: array<string, mixed>} [원본, 행 덮어쓰기용 Run, 응답에 붙일 run_view]
     */
    private function source(Request $request, int $projectId): array
    {
        $runs = $this->app->runs();
        $run = $runs->resolve($projectId, $request->query['run'] ?? null);
        if ($run === null) {
            return [null, null, ['run' => null, 'latest' => $runs->status($projectId)['latest'], 'read_only' => false]];
        }
        return [$runs->source($projectId, $run), $run, ['run' => $run['run'], 'latest' => $run['latest'], 'read_only' => true]];
    }

    public function list(Request $request): Response
    {
        $projectId = (int) $request->attribute('project_id');
        $filter = DefectFilter::fromQuery($request->query);
        $p = $this->pagination($request);
        [$source, $runView] = $this->source($request, $projectId);
        [$items, $total] = $this->app->defectQuery()->list($projectId, $filter, $p->size, $p->offset(), $source, $runView);
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/projects/{id}/defects/summary: 현재 필터의 항목별 건수(필터 칩) + 빈 상태 구분(분석 전/결함 0건) */
    public function summary(Request $request): Response
    {
        $projectId = (int) $request->attribute('project_id');
        $filter = DefectFilter::fromQuery($request->query);
        $q = $this->app->defectQuery();
        [$source, $run, $view] = $this->source($request, $projectId);
        $limit = $this->app->config->int('ui.page_size_max');
        // 과거 Run + 필터 없음: 칩 건수는 저장된 Run 요약(복원 계산 없이, P20R 5절 "저장된 요약 우선"). 규칙은 존재 집합만으로 센다
        $stored = $run !== null && $filter->isEmpty() ? $this->app->runs()->storedSummary($run['analysis_id']) : null;
        $summary = $stored !== null
            ? $q->summaryStored($projectId, $stored, $this->app->runs()->memberSource($projectId, $run['run']), $limit)
            : $q->summary($projectId, $filter, $limit, $source);
        return Response::data($summary
            + ['project' => $q->overview($projectId), 'filter' => $filter->toArray(), 'run_view' => $view, 'runs' => $this->app->runs()->status($projectId)]);
    }

    /** GET /api/v1/defects/{id} */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $projectId = (int) $this->app->defectQuery()->projectIdOf((string) $id);
        return Response::data($this->app->defectQuery()->detail($id, $this->app->runs()->resolve($projectId, $request->query['run'] ?? null)));
    }

    /** GET /api/v1/defects/{id}/history?page=&size= (최신순) */
    public function history(Request $request): Response
    {
        $p = $this->pagination($request);
        [$items, $total] = $this->app->defectQuery()->history((int) $request->param('id'), $p->size, $p->offset());
        // 자동 이력에는 그 분석의 Run 번호(P20R)
        $runs = $this->app->runs()->runsOf(array_values(array_filter(array_column($items, 'analysis_id'))));
        foreach ($items as &$it) {
            $it['run'] = $it['analysis_id'] !== null ? ($runs[$it['analysis_id']] ?? null) : null;
        }
        unset($it);
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/projects/{id}/runs: Run 목록(최신순, 페이지) + 되채움 필요 여부 */
    public function runs(Request $request): Response
    {
        $projectId = (int) $request->attribute('project_id');
        $p = $this->pagination($request);
        [$items, $total] = $this->app->runs()->list($projectId, $p->size, $p->offset());
        $status = $this->app->runs()->status($projectId);
        return Response::page($items, $p, $total, ['status' => $status])->withHeader('X-Runs-Backfill-Needed', $status['backfill_needed'] ? '1' : '0');
    }

    /** PATCH /api/v1/defects/{id}: {version, assignee_id?, severity?, status?, memo?} → 갱신된 결함(+changed). 충돌은 409 + error.details.current */
    public function update(Request $request): Response
    {
        return Response::data($this->app->defectUpdate()->update($request->attribute('user'), (int) $request->param('id'), $request->json()));
    }

    /** POST /api/v1/projects/{id}/defects/bulk: {items:[{id, version}], changes:{…}, memo?} → 항목별 결과(부분 성공) */
    public function bulk(Request $request): Response
    {
        return Response::data($this->app->defectUpdate()->bulk($request->attribute('user'), (int) $request->attribute('project_id'), $request->json()));
    }

    /** GET /api/v1/projects/{id}/assignees?q=&page=&size=: 담당자 후보(접근 가능한 활성 사용자) */
    public function assignees(Request $request): Response
    {
        $p = $this->pagination($request);
        [$items, $total] = $this->app->defectUpdate()->candidates((int) $request->attribute('project_id'), $request->query['q'] ?? null, $p->size, $p->offset());
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/my-defects?project=&status=&page=&size=: 내게 지정된 결함(볼 수 있는 프로젝트만) */
    public function mine(Request $request): Response
    {
        $p = $this->pagination($request);
        $project = $request->query['project'] ?? null;
        if ($project !== null && $project !== '' && (!is_string($project) || preg_match('/^[1-9][0-9]{0,17}$/', $project) !== 1)) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => 'project']);
        }
        $statuses = DefectFilter::fromQuery(['status' => $request->query['status'] ?? null])->statuses;
        [$items, $total] = $this->app->defectQuery()->mine($request->attribute('user'), $project === null || $project === '' ? null : (int) $project, $statuses, $p->size, $p->offset());
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/my-defects/summary: 상태별·프로젝트별 건수 */
    public function mineSummary(Request $request): Response
    {
        return Response::data($this->app->defectQuery()->mineSummary($request->attribute('user')));
    }

    /** GET /api/v1/defects/{id}/code?mode=window|full */
    public function code(Request $request): Response
    {
        $mode = $request->query['mode'] ?? 'window';
        $id = (int) $request->param('id');
        $run = $this->app->runs()->resolve((int) $this->app->defectQuery()->projectIdOf((string) $id), $request->query['run'] ?? null);
        $data = $this->app->defectCode()->get($id, is_string($mode) ? $mode : '', $run);
        // 코드가 속한 분석의 Run 번호(화면의 "Run N 시점의 코드", P20R). not_in_run이면 요청한 Run이 이미 들어 있다
        if (isset($data['analysis_id']) && !array_key_exists('run', $data)) {
            $data['run'] = $this->app->runs()->runsOf([(int) $data['analysis_id']])[(int) $data['analysis_id']] ?? null;
        }
        return Response::data($data);
    }
}
