<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 그룹 화면(명세 3.1). 목록·상세는 admin과 그 그룹의 관리자, 생성·수정·삭제 화면은 admin(라우트 RequireAdmin).
 * 관리 대상이 아닌 그룹·일반 사용자의 /groups는 404(존재를 숨김). 동작은 화면 JS(pages/groups.js, group_detail.js)가 API로 보낸다.
 */
final class GroupPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string, mixed> $vars */
    private function render(Request $request, string $template, string $title, array $vars = [], array $scripts = []): Response
    {
        $user = $request->attribute('user');
        $isAdmin = $this->app->groupPermission()->canAdministrate($user);
        $html = $this->app->view($request)->page($template, [
            'title' => $title,
            'user' => $user,
            // admin은 관리자 메뉴의 "그룹 관리", 그룹 관리자는 일반 메뉴의 "그룹"
            'active' => $isAdmin ? 'admin.groups' : 'nav.groups',
            'scripts' => $scripts,
        ], $vars + ['isAdmin' => $isAdmin]);
        return Response::html($html);
    }

    /** GET /groups */
    public function list(Request $request): Response
    {
        return $this->render($request, 'groups/list', Lang::get('groups.title'), [
            'groups' => $this->app->groups()->list($request->attribute('user')),
        ]);
    }

    /** GET /groups/{id} */
    public function show(Request $request): Response
    {
        $user = $request->attribute('user');
        $id = (int) $request->param('id');
        $group = $this->app->groups()->get($user, $id);
        $policy = $this->app->authSettings()->passwordPolicy();
        return $this->render($request, 'groups/show', $group['name'], [
            'group' => $group,
            'members' => $this->app->groups()->members($user, $id),
            'selfId' => (int) $user['id'],
            'tempHours' => intdiv($this->app->authSettings()->tempPasswordExpiry, 3600),
            'policyText' => Lang::get('password.policy', ['min' => $policy->minLength(), 'classes' => $policy->minClasses()]),
        ], ['js/pages/group_detail.js']);
    }

    /** GET /groups/new (admin) */
    public function newForm(Request $request): Response
    {
        return $this->render($request, 'groups/form', Lang::get('groups.new_title'), ['group' => null], ['js/pages/groups.js']);
    }

    /** GET /groups/{id}/edit (admin) */
    public function editForm(Request $request): Response
    {
        $group = $this->app->groups()->get($request->attribute('user'), (int) $request->param('id'));
        return $this->render($request, 'groups/form', Lang::get('groups.edit_title', ['name' => $group['name']]), ['group' => $group], ['js/pages/groups.js']);
    }

    /** GET /groups/{id}/delete (admin): 구성원 수·매핑된 프로젝트 수, 이름을 정확히 입력해야 버튼 활성 */
    public function deleteForm(Request $request): Response
    {
        $user = $request->attribute('user');
        $id = (int) $request->param('id');
        $group = $this->app->groups()->get($user, $id);
        return $this->render($request, 'groups/delete', Lang::get('groups.delete_title', ['name' => $group['name']]), [
            'group' => $group,
            'info' => $this->app->groups()->deletionInfo($user, $id),
        ], ['js/pages/groups.js']);
    }
}
