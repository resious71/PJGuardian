<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 사용자 관리 API(/api/v1/users, 관리자 전용, 명세 13). 삭제 API는 없다(비활성화만).
 * 응답의 사용자 정보는 UserRepository::toPublic(비밀번호 해시 없음).
 * 임시 비밀번호는 발급 응답에 한 번만 담고, 로그·감사 로그에는 남기지 않는다.
 */
final class UserController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    private static function id(Request $request): int
    {
        return (int) $request->param('id');
    }

    /** GET /api/v1/users?q=&role=&status=&page=&size= */
    public function list(Request $request): Response
    {
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        $str = static fn (string $k): ?string => is_string($request->query[$k] ?? null) ? $request->query[$k] : null;
        [$items, $total] = $this->app->users()->list(['q' => $str('q'), 'role' => $str('role'), 'status' => $str('status'), 'approval' => $str('approval')], $p->size, $p->offset());
        // 소속 그룹 이름(읽기 전용 표시, P11A)
        $groups = $this->app->groups()->groupNamesByUser();
        foreach ($items as &$item) {
            $item['groups'] = $groups[(int) $item['id']] ?? [];
        }
        unset($item);
        // P28B: 승인 대기·거절 건수(배지)와 켜진 인증 방식(외부 방식이 켜져 있을 때만 "인증 방식 변경"을 보임)
        return Response::page($items, $p, $total, ['approval_counts' => $this->app->users()->approvalCounts(), 'auth_types' => $this->app->authProviders()->enabledTypes()]);
    }

    /** POST /api/v1/users/{id}/approve {role: user|admin, updated_at} — 승인 대기·거절 계정 승인(P28B). 다른 관리자가 먼저 처리했으면 409 */
    public function approve(Request $request): Response
    {
        $b = $request->json();
        return Response::data($this->app->externalAccounts()->approve(self::actor($request), self::id($request), $b['role'] ?? 'user', $b['updated_at'] ?? ''));
    }

    /** POST /api/v1/users/{id}/reject {updated_at} — 승인 대기 거절(계정은 rejected로 남음) */
    public function reject(Request $request): Response
    {
        return Response::data($this->app->externalAccounts()->reject(self::actor($request), self::id($request), $request->json()['updated_at'] ?? ''));
    }

    /** DELETE /api/v1/users/{id}?updated_at= — 승인된 적 없는 계정(대기·거절)만 지운다. 승인된 계정은 지우지 않는다(비활성화만) */
    public function deletePending(Request $request): Response
    {
        $this->app->externalAccounts()->delete(self::actor($request), self::id($request), is_string($request->query['updated_at'] ?? null) ? $request->query['updated_at'] : '');
        return Response::data(['deleted' => true]);
    }

    /** PUT /api/v1/users/{id}/auth-type {auth_type} → {user, temp_password?, temp_password_expires_at?} — 인증 방식 전환(P28B) */
    public function authType(Request $request): Response
    {
        return Response::data($this->app->users()->changeAuthType(self::actor($request), self::id($request), $request->json()['auth_type'] ?? null));
    }

    /** GET /api/v1/users/{id} */
    public function show(Request $request): Response
    {
        return Response::data($this->app->users()->get(self::id($request)));
    }

    /** POST /api/v1/users {login_id, display_name, email?, role?} → 201 {user, temp_password} */
    public function create(Request $request): Response
    {
        return Response::data($this->app->users()->create(self::actor($request), $request->json()), 201);
    }

    /** PATCH /api/v1/users/{id} {display_name?, email?}: 다른 필드는 무시(UserService::updateProfile, "내 정보"와 같은 함수) */
    public function update(Request $request): Response
    {
        return Response::data($this->app->users()->updateProfile($request->attribute('user'), $request->clientIp, self::id($request), $request->json()));
    }

    /** PUT /api/v1/users/{id}/role {role} */
    public function role(Request $request): Response
    {
        return Response::data($this->app->users()->changeRole(self::actor($request), self::id($request), $request->json()['role'] ?? null));
    }

    /** PUT /api/v1/users/{id}/status {status: active|inactive} */
    public function status(Request $request): Response
    {
        return Response::data($this->app->users()->setStatus(self::actor($request), self::id($request), $request->json()['status'] ?? null));
    }

    /** POST /api/v1/users/{id}/temp-password → {temp_password, expires_at, user} */
    public function tempPassword(Request $request): Response
    {
        return Response::data($this->app->users()->issueTempPassword(self::actor($request), self::id($request)));
    }

    /**
     * PUT /api/v1/users/{id}/password {password}: 관리자가 다른 사용자의 비밀번호를 정한다.
     * 정책 적용, 변경 강제 없음(다음 로그인 때 알림만), 본인은 400 self_password_change. 응답에 비밀번호를 담지 않는다.
     */
    public function password(Request $request): Response
    {
        return Response::data($this->app->users()->setPasswordByAdmin(self::actor($request), self::id($request), $request->json()['password'] ?? null));
    }

    /** POST /api/v1/users/{id}/unlock */
    public function unlock(Request $request): Response
    {
        if (self::id($request) <= 0) {
            throw new ApiException(404, 'user_not_found');
        }
        return Response::data($this->app->users()->unlock(self::actor($request), self::id($request)));
    }
}
