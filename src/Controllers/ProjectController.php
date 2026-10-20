<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 프로젝트 API(/api/v1/projects, 명세 4장, 13장).
 * 목록은 볼 수 있는 프로젝트만, 기본 제외 목록은 로그인한 모든 사용자(전역 정보).
 * 프로젝트 id 경로는 라우트의 RequireProjectAccess가 먼저 판정한다(보이지 않으면 404). 그다음 관리자 전용 동작은 RequireAdmin(403).
 * 접근 매핑 조회·추가·제거(명세 3.1)는 관리자.
 */
final class ProjectController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function isAdmin(Request $request): bool
    {
        return ($request->attribute('user')['role'] ?? '') === 'admin';
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    /** GET /api/v1/projects?q=&page=&size= */
    public function list(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        $q = is_string($request->query['q'] ?? null) ? trim($request->query['q']) : null;
        [$items, $total] = $this->app->projects()->list($q, $p->size, $p->offset(), $request->attribute('user'));
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/projects/{id} */
    public function show(Request $request): Response
    {
        return Response::data($this->app->projects()->get((int) $request->param('id'), self::isAdmin($request)));
    }

    /** GET /api/v1/projects/default-excludes: 브라우저가 폴더를 묶기 전에 뺄 기본 목록(P12) */
    public function defaultExcludes(Request $request): Response
    {
        return Response::data(['names' => $this->app->projects()->defaultExcludes()]);
    }

    /** POST /api/v1/projects {name, language, description?} → 201 */
    public function create(Request $request): Response
    {
        return Response::data($this->app->projects()->create(self::actor($request), $request->json()), 201);
    }

    /** PATCH /api/v1/projects/{id} {name?, description?, exclude_patterns?}. language를 다른 값으로 보내면 409 */
    public function update(Request $request): Response
    {
        return Response::data($this->app->projects()->update(self::actor($request), (int) $request->param('id'), $request->json()));
    }

    /** GET /api/v1/projects/{id}/deletion-info: 삭제될 양 + 함께 사라질 접근 매핑 수 */
    public function deletionInfo(Request $request): Response
    {
        $id = (int) $request->param('id');
        return Response::data($this->app->projects()->deletionInfo($id) + ['access_mappings' => $this->app->projectAccess()->mappingCount($id)]);
    }

    /** GET /api/v1/projects/{id}/access: 매핑된 그룹·사용자, 볼 수 있는 사람 수(관리자 제외) */
    public function access(Request $request): Response
    {
        return Response::data($this->app->projectAccess()->mappings((int) $request->param('id')));
    }

    /** POST /api/v1/projects/{id}/access {subject_type:'group', group_id} | {subject_type:'user', login_id} → 201 */
    public function addAccess(Request $request): Response
    {
        return Response::data($this->app->projectAccess()->add(self::actor($request), (int) $request->param('id'), $request->json()), 201);
    }

    /** DELETE /api/v1/projects/{id}/access/{subjectType}/{subjectId} */
    public function removeAccess(Request $request): Response
    {
        return Response::data($this->app->projectAccess()->remove(
            self::actor($request),
            (int) $request->param('id'),
            (string) $request->param('subjectType'),
            (int) $request->param('subjectId'),
        ));
    }

    /** DELETE /api/v1/projects/{id} {confirm_name}: 이름이 정확히 같아야 삭제 */
    public function delete(Request $request): Response
    {
        return Response::data($this->app->projects()->delete(self::actor($request), (int) $request->param('id'), $request->json()['confirm_name'] ?? null));
    }
}
