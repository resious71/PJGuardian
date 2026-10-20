<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Http\ApiException;
use PJGuardian\Repositories\GroupRepository;
use PJGuardian\Repositories\UserRepository;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 사용자 그룹과 그룹 관리자(명세 3장 3.1, 19.10). 권한은 GroupPermission으로만 판정한다.
 *
 * 응답 규칙: 관리할 수 없는 그룹은 404(group_not_found, 존재를 숨김), 관리는 하지만 admin 전용 작업이면 403(forbidden).
 * 그룹 관리자가 할 수 있는 일은 구성원 추가·제거, 다른 구성원의 관리자 지정·해제, 신규 계정 생성뿐이다.
 * 모든 변경은 감사 로그에 남기고, 구성원·관리자 변경은 트랜잭션 안에서 대상의 현재 상태를 다시 확인한다.
 */
final class GroupService
{
    private const NAME_MAX = 100;
    private const DESCRIPTION_MAX = 2000;
    public const SEARCH_MIN = 2;
    public const SEARCH_LIMIT = 20;

    private readonly GroupRepository $repo;
    private readonly UserRepository $users;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly GroupPermission $permission,
        private readonly UserService $userService,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
    ) {
        $this->repo = new GroupRepository($db);
        $this->users = new UserRepository($db);
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** @param array<string, mixed> $user */
    private static function actor(array $user, ?string $ip): Actor
    {
        return Actor::fromUser($user, $ip);
    }

    /** @param array<string, mixed> $user */
    private function actorRole(array $user): string
    {
        return $this->permission->canAdministrate($user) ? 'admin' : 'group_manager';
    }

    /**
     * 관리할 수 있는 그룹을 돌려준다. 없거나 관리할 수 없으면 404.
     *
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    private function managed(?array $user, int $id): array
    {
        $row = $this->repo->find($id);
        if ($row === null || !$this->permission->canManage($user, $id)) {
            throw new ApiException(404, 'group_not_found');
        }
        return $row;
    }

    /** admin 전용: 관리할 수 없으면 404, 그룹 관리자이면 403 */
    private function administered(?array $user, int $id): array
    {
        $row = $this->managed($user, $id);
        if (!$this->permission->canAdministrate($user)) {
            throw new ApiException(403, 'forbidden');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function toPublic(array $row): array
    {
        $out = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
        foreach (['member_count', 'manager_count'] as $k) {
            if (array_key_exists($k, $row)) {
                $out[$k] = (int) $row[$k];
            }
        }
        return $out;
    }

    /**
     * 그룹 목록: admin은 전체, 그룹 관리자는 자기가 관리하는 그룹. 그 밖은 404(메뉴·주소를 숨김).
     *
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public function list(?array $user): array
    {
        if (!$this->permission->managesAny($user)) {
            throw new ApiException(404, 'not_found');
        }
        $rows = $this->repo->listWithCounts($this->permission->canAdministrate($user) ? null : (int) $user['id']);
        return array_map(self::toPublic(...), $rows);
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    public function get(?array $user, int $id): array
    {
        $row = $this->managed($user, $id);
        return self::toPublic($row) + ['member_count' => $this->repo->countMembers($id)];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data name, description?
     * @return array<string, mixed>
     */
    public function create(array $user, ?string $ip, array $data): array
    {
        if (!$this->permission->canAdministrate($user)) {
            throw new ApiException(403, 'forbidden');
        }
        $name = $this->validName($data['name'] ?? null);
        $description = $this->validDescription($data['description'] ?? '');
        $id = $this->db->transaction(function () use ($user, $ip, $name, $description): int {
            if ($this->repo->nameExists($name)) {
                throw new ApiException(409, 'group_name_taken');
            }
            $id = $this->repo->insert($name, $description, (int) $user['id'], $this->now());
            $this->audit->record(self::actor($user, $ip), 'group.create', 'group', (string) $id, ['name' => $name]);
            return $id;
        });
        return $this->get($user, $id);
    }

    /**
     * 이름·설명 수정(admin). 이름이 바뀌면 group.rename, 설명만 바뀌면 group.update.
     *
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(?array $user, ?string $ip, int $id, array $data): array
    {
        $row = $this->administered($user, $id);
        $name = array_key_exists('name', $data) ? $this->validName($data['name']) : (string) $row['name'];
        $description = array_key_exists('description', $data) ? $this->validDescription($data['description']) : (string) $row['description'];
        $changes = [];
        foreach (['name' => [(string) $row['name'], $name], 'description' => [(string) $row['description'], $description]] as $k => [$from, $to]) {
            if ($from !== $to) {
                $changes[$k] = ['from' => $from, 'to' => $to];
            }
        }
        if ($changes !== []) {
            $this->db->transaction(function () use ($user, $ip, $id, $name, $description, $changes): void {
                if (isset($changes['name']) && $this->repo->nameExists($name, $id)) {
                    throw new ApiException(409, 'group_name_taken');
                }
                $this->repo->update($id, $name, $description, $this->now());
                $this->audit->record(self::actor($user, $ip), isset($changes['name']) ? 'group.rename' : 'group.update', 'group', (string) $id, $changes);
            });
        }
        return $this->get($user, $id);
    }

    /**
     * 삭제 확인 정보(admin): 구성원 수, 매핑된 프로젝트 수(P11B가 채운다. 이 단계에서는 0).
     *
     * @param array<string, mixed>|null $user
     * @return array{members:int, projects:int}
     */
    public function deletionInfo(?array $user, int $id): array
    {
        $this->administered($user, $id);
        return ['members' => $this->repo->countMembers($id), 'projects' => $this->mappedProjectCount($id)];
    }

    /** 이 그룹에 매핑된 프로젝트 수(project_access, P11B). 그룹을 지우면 트리거 groups_delete_project_access가 매핑을 함께 지운다 */
    private function mappedProjectCount(int $id): int
    {
        return $this->repo->mappedProjectCount($id);
    }

    /**
     * 그룹 삭제(admin). 이름을 정확히 입력해야 한다. 구성원 행도 함께 지운다(사용자는 그대로).
     *
     * @param array<string, mixed>|null $user
     */
    public function delete(?array $user, ?string $ip, int $id, mixed $confirmName): void
    {
        $row = $this->administered($user, $id);
        if (!is_string($confirmName) || $confirmName !== (string) $row['name']) {
            throw new ApiException(400, 'group_name_mismatch');
        }
        $this->db->transaction(function () use ($user, $ip, $id, $row): void {
            $members = $this->repo->countMembers($id);
            $projects = $this->mappedProjectCount($id);
            $this->repo->delete($id);
            $this->audit->record(self::actor($user, $ip), 'group.delete', 'group', (string) $id, [
                'name' => (string) $row['name'], 'members' => $members, 'projects' => $projects,
            ]);
        });
    }

    /**
     * 구성원 목록: 로그인 ID, 이름, 그룹 관리자 여부, 활성 여부, 추가 시각.
     *
     * @param array<string, mixed>|null $user
     * @return list<array{login_id:string, display_name:string, user_id:int, is_manager:bool, active:bool, added_at:string}>
     */
    public function members(?array $user, int $id): array
    {
        $this->managed($user, $id);
        return array_map(static fn (array $r): array => [
            'login_id' => (string) $r['login_id'],
            'display_name' => (string) $r['display_name'],
            'user_id' => (int) $r['user_id'],
            'is_manager' => (int) $r['is_manager'] === 1,
            'active' => $r['status'] === 'active',
            'added_at' => (string) $r['added_at'],
        ], $this->repo->members($id));
    }

    /**
     * 구성원 추가(로그인 ID로). 활성 사용자만, admin은 구성원 행을 만들지 않는다.
     *
     * @param array<string, mixed>|null $user
     */
    public function addMember(?array $user, ?string $ip, int $id, mixed $loginId): void
    {
        $this->managed($user, $id);
        if (!is_string($loginId) || trim($loginId) === '') {
            throw new ApiException(400, 'invalid_input');
        }
        $this->db->transaction(function () use ($user, $ip, $id, $loginId): void {
            $target = $this->users->findForAuth(trim($loginId)) ?? throw new ApiException(404, 'user_not_found');
            if ($target['status'] !== 'active') {
                throw new ApiException(409, 'user_inactive');
            }
            if ($target['role'] === 'admin') {
                throw new ApiException(409, 'admin_implicit_member');
            }
            if ($this->repo->member($id, (int) $target['id']) !== null) {
                throw new ApiException(409, 'already_member');
            }
            $this->repo->addMember($id, (int) $target['id'], (int) $user['id'], $this->now());
            $this->audit->record(self::actor($user, $ip), 'group.member_add', 'group', (string) $id, [
                'user_id' => (int) $target['id'], 'login_id' => (string) $target['login_id'], 'actor_role' => $this->actorRole($user),
            ]);
        });
    }

    /**
     * 구성원 제거. 자기 자신은 제거할 수 없다(admin에게 요청).
     *
     * @param array<string, mixed>|null $user
     */
    public function removeMember(?array $user, ?string $ip, int $id, int $targetUserId): void
    {
        $this->managed($user, $id);
        if ($targetUserId === (int) $user['id']) {
            throw new ApiException(403, 'cannot_remove_self');
        }
        $this->db->transaction(function () use ($user, $ip, $id, $targetUserId): void {
            $m = $this->repo->member($id, $targetUserId) ?? throw new ApiException(404, 'member_not_found');
            $this->repo->removeMember($id, $targetUserId);
            $login = (string) ($this->users->find($targetUserId)['login_id'] ?? '');
            $this->audit->record(self::actor($user, $ip), 'group.member_remove', 'group', (string) $id, [
                'user_id' => $targetUserId, 'login_id' => $login, 'was_manager' => (int) $m['is_manager'] === 1, 'actor_role' => $this->actorRole($user),
            ]);
        });
    }

    /**
     * 그룹 관리자 지정·해제. 대상은 그 그룹의 구성원이어야 하고, 자기 자신은 해제할 수 없다.
     *
     * @param array<string, mixed>|null $user
     */
    public function setManager(?array $user, ?string $ip, int $id, int $targetUserId, mixed $isManager): void
    {
        $this->managed($user, $id);
        if (!is_bool($isManager)) {
            throw new ApiException(400, 'invalid_input');
        }
        if (!$isManager && $targetUserId === (int) $user['id']) {
            throw new ApiException(403, 'cannot_unset_self');
        }
        $this->db->transaction(function () use ($user, $ip, $id, $targetUserId, $isManager): void {
            $m = $this->repo->member($id, $targetUserId) ?? throw new ApiException(404, 'member_not_found');
            if (((int) $m['is_manager'] === 1) === $isManager) {
                return; // 이미 그 상태
            }
            $this->repo->setManager($id, $targetUserId, $isManager);
            $login = (string) ($this->users->find($targetUserId)['login_id'] ?? '');
            $this->audit->record(self::actor($user, $ip), $isManager ? 'group.manager_set' : 'group.manager_unset', 'group', (string) $id, [
                'user_id' => $targetUserId, 'login_id' => $login, 'actor_role' => $this->actorRole($user),
            ]);
        });
    }

    /**
     * 그룹에서 신규 로컬 계정을 만든다. P10의 사용자 생성 로직을 그대로 쓰되 역할은 user로 고정하고(요청의 role·group_id 무시),
     * 같은 트랜잭션에서 이 그룹의 일반 구성원으로 넣는다. 임시 비밀번호는 응답에 한 번만 담는다.
     *
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $data login_id, display_name, email?
     * @return array{user: array<string, mixed>, temp_password: string}
     */
    public function createAccount(?array $user, ?string $ip, int $id, array $data): array
    {
        $this->managed($user, $id);
        $clean = [
            'login_id' => $data['login_id'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'email' => $data['email'] ?? null,
            'role' => 'user', // 서버가 고정
        ];
        $actor = self::actor($user, $ip);
        $actorRole = $this->actorRole($user);
        $r = $this->userService->create($actor, $clean, ['group_id' => $id, 'actor_role' => $actorRole], function (int $newId) use ($user, $id): void {
            $this->repo->addMember($id, $newId, (int) $user['id'], $this->now());
        });
        $this->logger->info('그룹에서 계정 생성', ['group_id' => $id, 'target_user_id' => $r['user']['id'], 'by' => $actor->label, 'actor_role' => $actorRole]);
        return $r;
    }

    /**
     * 사용자 검색(구성원 추가용): admin 또는 어느 그룹이든 관리자만. 최소 2자, 20건, 활성 사용자, 로그인 ID와 이름만.
     *
     * @param array<string, mixed>|null $user
     * @return list<array{login_id:string, display_name:string}>
     */
    public function searchUsers(?array $user, mixed $q): array
    {
        if (!$this->permission->managesAny($user)) {
            throw new ApiException(403, 'forbidden');
        }
        $q = is_string($q) ? trim($q) : '';
        if (mb_strlen($q, 'UTF-8') < self::SEARCH_MIN) {
            throw new ApiException(400, 'search_query_too_short', ['min' => self::SEARCH_MIN]);
        }
        return $this->users->searchActive($q, self::SEARCH_LIMIT);
    }

    /** @return array<int, list<string>> 사용자 id → 소속 그룹 이름(사용자 관리 화면용, admin) */
    public function groupNamesByUser(): array
    {
        return $this->repo->groupNamesByUser();
    }

    private function validName(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || mb_strlen($v, 'UTF-8') > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            throw new ApiException(400, 'invalid_group_name', ['max' => self::NAME_MAX]);
        }
        return $v;
    }

    private function validDescription(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (!is_string($v)) {
            throw new ApiException(400, 'invalid_description', ['max' => self::DESCRIPTION_MAX]);
        }
        $v = trim($v);
        if (mb_strlen($v, 'UTF-8') > self::DESCRIPTION_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new ApiException(400, 'invalid_description', ['max' => self::DESCRIPTION_MAX]);
        }
        return $v;
    }
}
