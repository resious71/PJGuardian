<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 그룹 API(/api/v1/groups, /api/v1/users/search, 명세 3.1). 라우트는 로그인 + 임시 비밀번호 변경 완료만 검사하고,
 * 그룹별 권한(관리 가능 여부, admin 전용)은 GroupService가 GroupPermission으로 판정한다.
 * 관리할 수 없는 그룹은 404, admin 전용 작업을 그룹 관리자가 시도하면 403.
 */
final class GroupController
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    private static function user(Request $request): array
    {
        return $request->attribute('user');
    }

    private static function id(Request $request, string $name = 'id'): int
    {
        return (int) $request->param($name);
    }

    /** GET /api/v1/groups */
    public function list(Request $request): Response
    {
        return Response::data($this->app->groups()->list(self::user($request)));
    }

    /** POST /api/v1/groups {name, description?} → 201 (admin) */
    public function create(Request $request): Response
    {
        return Response::data($this->app->groups()->create(self::user($request), $request->clientIp, $request->json()), 201);
    }

    /** GET /api/v1/groups/{id} */
    public function show(Request $request): Response
    {
        return Response::data($this->app->groups()->get(self::user($request), self::id($request)));
    }

    /** PATCH /api/v1/groups/{id} {name?, description?} (admin) */
    public function update(Request $request): Response
    {
        return Response::data($this->app->groups()->update(self::user($request), $request->clientIp, self::id($request), $request->json()));
    }

    /** GET /api/v1/groups/{id}/deletion-info (admin) */
    public function deletionInfo(Request $request): Response
    {
        return Response::data($this->app->groups()->deletionInfo(self::user($request), self::id($request)));
    }

    /** DELETE /api/v1/groups/{id} {confirm_name} (admin) */
    public function delete(Request $request): Response
    {
        $this->app->groups()->delete(self::user($request), $request->clientIp, self::id($request), $request->json()['confirm_name'] ?? null);
        return Response::data(['deleted' => true]);
    }

    /** GET /api/v1/groups/{id}/members */
    public function members(Request $request): Response
    {
        return Response::data($this->app->groups()->members(self::user($request), self::id($request)));
    }

    /** POST /api/v1/groups/{id}/members {login_id} → 201 */
    public function addMember(Request $request): Response
    {
        $this->app->groups()->addMember(self::user($request), $request->clientIp, self::id($request), $request->json()['login_id'] ?? null);
        return Response::data(['added' => true], 201);
    }

    /** DELETE /api/v1/groups/{id}/members/{userId} */
    public function removeMember(Request $request): Response
    {
        $this->app->groups()->removeMember(self::user($request), $request->clientIp, self::id($request), self::id($request, 'userId'));
        return Response::data(['removed' => true]);
    }

    /** PUT /api/v1/groups/{id}/members/{userId}/manager {is_manager: bool} */
    public function setManager(Request $request): Response
    {
        $this->app->groups()->setManager(self::user($request), $request->clientIp, self::id($request), self::id($request, 'userId'), $request->json()['is_manager'] ?? null);
        return Response::data(['is_manager' => (bool) ($request->json()['is_manager'] ?? false)]);
    }

    /** POST /api/v1/groups/{id}/accounts {login_id, display_name, email?} → 201 {user, temp_password}. role은 서버가 user로 고정 */
    public function createAccount(Request $request): Response
    {
        return Response::data($this->app->groups()->createAccount(self::user($request), $request->clientIp, self::id($request), $request->json()), 201);
    }

    /** GET /api/v1/users/search?q= : 로그인 ID와 이름만, 최소 2자, 20건, 활성 사용자 */
    public function searchUsers(Request $request): Response
    {
        return Response::data($this->app->groups()->searchUsers(self::user($request), $request->query['q'] ?? null));
    }
}
