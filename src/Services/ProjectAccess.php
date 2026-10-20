<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 프로젝트 접근 판정(명세 3.1, 19.10). **"누가 이 프로젝트를 볼 수 있는가"는 이 클래스 한 곳에서만 판정한다.**
 *
 * - 볼 수 있는 사람: 활성 admin, 직접 매핑된 사용자, 매핑된 그룹의 구성원(그룹 관리자 포함). 매핑이 없으면 admin만.
 * - 보이지 않는 프로젝트는 없는 프로젝트와 똑같이 404 project_not_found(존재를 숨김).
 * - 캐시하지 않는다: 구성원·매핑 변경이 다음 요청부터 바로 반영된다.
 * - 접근 단계는 하나다. 매핑 변경은 admin만(라우트 RequireAdmin) 하고 감사 로그에 남긴다.
 *
 * 프로젝트 id를 받는 라우트는 RequireProjectAccess 미들웨어로 requireView를 거친다(docs/architecture.md 19절).
 * 목록 쿼리는 visibleProjectsSql()/scopeFor()의 조건을 붙여 한 쿼리로 거른다(N+1 없음).
 */
final class ProjectAccess
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string, mixed>|null $user */
    public function isAdmin(?array $user): bool
    {
        return is_array($user) && ($user['role'] ?? null) === 'admin' && ($user['status'] ?? 'active') === 'active';
    }

    /** @param array<string, mixed>|null $user */
    private static function isActive(?array $user): bool
    {
        return is_array($user) && isset($user['id']) && ($user['status'] ?? 'active') === 'active';
    }

    /**
     * 일반 사용자가 볼 수 있는 프로젝트 조건(별칭 $alias의 프로젝트 행에 붙인다). 매개변수는 [$userId, $userId].
     *
     * @return array{0: string, 1: list<int>}
     */
    public function visibleProjectsSql(int $userId, string $alias = 'p'): array
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('별칭 형식');
        }
        $sql = "(EXISTS (SELECT 1 FROM project_access pa WHERE pa.project_id = {$alias}.id AND pa.subject_type = 'user' AND pa.subject_id = ?)"
            . " OR EXISTS (SELECT 1 FROM project_access pa JOIN group_members gm ON gm.group_id = pa.subject_id"
            . " WHERE pa.project_id = {$alias}.id AND pa.subject_type = 'group' AND gm.user_id = ?))";
        return [$sql, [$userId, $userId]];
    }

    /**
     * 현재 사용자의 목록 조건: admin = 전체, 일반 사용자 = visibleProjectsSql, 비로그인·비활성 = 없음.
     *
     * @param array<string, mixed>|null $user
     * @return array{0: string, 1: list<int>}
     */
    public function scopeFor(?array $user, string $alias = 'p'): array
    {
        if ($this->isAdmin($user)) {
            return ['1 = 1', []];
        }
        if (!self::isActive($user)) {
            return ['0 = 1', []];
        }
        return $this->visibleProjectsSql((int) $user['id'], $alias);
    }

    /** @param array<string, mixed>|null $user */
    public function canView(?array $user, int $projectId): bool
    {
        [$cond, $params] = $this->scopeFor($user);
        return $this->db->value("SELECT 1 FROM projects p WHERE p.id = ? AND {$cond}", [$projectId, ...$params]) !== null;
    }

    /**
     * 없거나 볼 수 없으면 404 project_not_found. 응답은 실제로 없는 프로젝트와 같다(상태 코드·오류 코드·문구).
     *
     * @param array<string, mixed>|null $user
     */
    public function requireView(?array $user, int $projectId): void
    {
        if (!$this->canView($user, $projectId)) {
            throw new ApiException(404, 'project_not_found');
        }
    }

    /** 사용자 id로 판정(담당자 표시 "접근 권한 없음", 알림 대상 거르기 등). 비활성 사용자는 false */
    public function userCanView(int $userId, int $projectId): bool
    {
        $user = $this->db->one('SELECT id, role, status FROM users WHERE id = ?', [$userId]);
        return $user !== null && $this->canView($user, $projectId);
    }

    /**
     * 접근할 수 있는 활성 사용자 SQL(활성 admin + 직접 매핑 + 그룹 구성원, 중복 없음). 매개변수는 [$projectId x3].
     */
    private const ACCESS_USERS_SQL = "SELECT u.id, u.login_id, u.display_name, u.role FROM users u WHERE u.status = 'active' AND ("
        . "u.role = 'admin'"
        . " OR EXISTS (SELECT 1 FROM project_access pa WHERE pa.project_id = ? AND pa.subject_type = 'user' AND pa.subject_id = u.id)"
        . " OR EXISTS (SELECT 1 FROM project_access pa JOIN group_members gm ON gm.group_id = pa.subject_id"
        . " WHERE pa.project_id = ? AND pa.subject_type = 'group' AND gm.user_id = u.id))";

    /**
     * 담당자 후보(P18): 이 프로젝트에 접근할 수 있는 활성 사용자. 로그인 ID 순.
     *
     * @return list<array{id:int, login_id:string, display_name:string, role:string}>
     */
    public function assignableUsers(int $projectId): array
    {
        if ($this->db->value('SELECT 1 FROM projects WHERE id = ?', [$projectId]) === null) {
            return [];
        }
        $rows = $this->db->all(self::ACCESS_USERS_SQL . ' ORDER BY u.login_id COLLATE NOCASE', [$projectId, $projectId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'login_id' => (string) $r['login_id'], 'display_name' => (string) $r['display_name'], 'role' => (string) $r['role'],
        ], $rows);
    }

    /**
     * 알림 수신자 후보(P21 등): 접근할 수 있는 활성 사용자 id(중복 없음, 오름차순).
     *
     * @return list<int>
     */
    public function usersWithAccess(int $projectId): array
    {
        return array_map(static fn (array $u): int => $u['id'], $this->assignableUsers($projectId));
    }

    // ---- 매핑 조회·변경(admin) ----

    /**
     * 매핑된 그룹(이름, 구성원 수)·사용자(로그인 ID, 이름, 활성)와, admin을 뺀 "볼 수 있는 사람 수"(중복 제거, 활성만).
     *
     * @return array{groups: list<array<string, mixed>>, users: list<array<string, mixed>>, viewer_count: int}
     */
    public function mappings(int $projectId): array
    {
        $groups = $this->db->all(
            "SELECT g.id, g.name, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS member_count, pa.created_at"
            . " FROM project_access pa JOIN groups g ON g.id = pa.subject_id"
            . " WHERE pa.project_id = ? AND pa.subject_type = 'group' ORDER BY g.name COLLATE NOCASE",
            [$projectId],
        );
        $users = $this->db->all(
            "SELECT u.id, u.login_id, u.display_name, u.status, pa.created_at"
            . " FROM project_access pa JOIN users u ON u.id = pa.subject_id"
            . " WHERE pa.project_id = ? AND pa.subject_type = 'user' ORDER BY u.login_id COLLATE NOCASE",
            [$projectId],
        );
        $viewers = (int) $this->db->value('SELECT COUNT(*) FROM (' . self::ACCESS_USERS_SQL . ") WHERE role <> 'admin'", [$projectId, $projectId]);
        return [
            'groups' => array_map(static fn (array $r): array => [
                'group_id' => (int) $r['id'], 'name' => (string) $r['name'], 'member_count' => (int) $r['member_count'], 'created_at' => (string) $r['created_at'],
            ], $groups),
            'users' => array_map(static fn (array $r): array => [
                'user_id' => (int) $r['id'], 'login_id' => (string) $r['login_id'], 'display_name' => (string) $r['display_name'],
                'active' => $r['status'] === 'active', 'created_at' => (string) $r['created_at'],
            ], $users),
            'viewer_count' => $viewers,
        ];
    }

    /** @return array{groups:int, users:int} */
    public function summary(int $projectId): array
    {
        $row = $this->db->one(
            "SELECT SUM(subject_type = 'group') AS g, SUM(subject_type = 'user') AS u FROM project_access WHERE project_id = ?",
            [$projectId],
        );
        return ['groups' => (int) ($row['g'] ?? 0), 'users' => (int) ($row['u'] ?? 0)];
    }

    public function mappingCount(int $projectId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM project_access WHERE project_id = ?', [$projectId]);
    }

    /**
     * 매핑 추가: {subject_type:'group', group_id} 또는 {subject_type:'user', login_id}.
     * 대상이 없으면 404(group_not_found, user_not_found), 이미 있으면 409 access_exists,
     * 비활성 사용자 409 user_inactive, admin 사용자 409 admin_implicit_access(admin은 항상 볼 수 있음).
     *
     * @param array<string, mixed> $data
     * @return array{groups: list<array<string, mixed>>, users: list<array<string, mixed>>, viewer_count: int}
     */
    public function add(Actor $actor, int $projectId, array $data): array
    {
        $type = $data['subject_type'] ?? null;
        if ($type === 'group') {
            $gid = $data['group_id'] ?? null;
            if (!is_int($gid) && !(is_string($gid) && ctype_digit($gid))) {
                throw new ApiException(400, 'invalid_input');
            }
            $subject = $this->db->one('SELECT id, name FROM groups WHERE id = ?', [(int) $gid]) ?? throw new ApiException(404, 'group_not_found');
            $name = (string) $subject['name'];
        } elseif ($type === 'user') {
            $login = $data['login_id'] ?? null;
            if (!is_string($login) || trim($login) === '') {
                throw new ApiException(400, 'invalid_input');
            }
            $subject = $this->db->one('SELECT id, login_id, role, status FROM users WHERE login_id = ?', [trim($login)])
                ?? throw new ApiException(404, 'user_not_found');
            if ($subject['status'] !== 'active') {
                throw new ApiException(409, 'user_inactive');
            }
            if ($subject['role'] === 'admin') {
                throw new ApiException(409, 'admin_implicit_access');
            }
            $name = (string) $subject['login_id'];
        } else {
            throw new ApiException(400, 'invalid_input');
        }
        $subjectId = (int) $subject['id'];
        $this->db->transaction(function () use ($actor, $projectId, $type, $subjectId, $name): void {
            if ($this->db->value('SELECT 1 FROM project_access WHERE project_id = ? AND subject_type = ? AND subject_id = ?', [$projectId, $type, $subjectId]) !== null) {
                throw new ApiException(409, 'access_exists');
            }
            $this->db->execute(
                'INSERT INTO project_access (project_id, subject_type, subject_id, created_at, created_by) VALUES (?, ?, ?, ?, ?)',
                [$projectId, $type, $subjectId, SystemClock::iso($this->clock->now()), $actor->userId],
            );
            $this->audit->record($actor, 'project.access_add', 'project', (string) $projectId, ['subject_type' => $type, 'subject_id' => $subjectId, 'name' => $name]);
        });
        $this->logger->info('프로젝트 접근 매핑 추가', ['project_id' => $projectId, 'subject_type' => $type, 'subject_id' => $subjectId, 'by' => $actor->label]);
        return $this->mappings($projectId);
    }

    /**
     * 매핑 제거. 없으면 404 access_not_found.
     *
     * @return array{groups: list<array<string, mixed>>, users: list<array<string, mixed>>, viewer_count: int}
     */
    public function remove(Actor $actor, int $projectId, string $type, int $subjectId): array
    {
        if (!in_array($type, ['group', 'user'], true)) {
            throw new ApiException(400, 'invalid_input');
        }
        $this->db->transaction(function () use ($actor, $projectId, $type, $subjectId): void {
            $name = $type === 'group'
                ? $this->db->value("SELECT g.name FROM project_access pa JOIN groups g ON g.id = pa.subject_id WHERE pa.project_id = ? AND pa.subject_type = 'group' AND pa.subject_id = ?", [$projectId, $subjectId])
                : $this->db->value("SELECT u.login_id FROM project_access pa JOIN users u ON u.id = pa.subject_id WHERE pa.project_id = ? AND pa.subject_type = 'user' AND pa.subject_id = ?", [$projectId, $subjectId]);
            if ($name === null) {
                throw new ApiException(404, 'access_not_found');
            }
            $this->db->execute('DELETE FROM project_access WHERE project_id = ? AND subject_type = ? AND subject_id = ?', [$projectId, $type, $subjectId]);
            $this->audit->record($actor, 'project.access_remove', 'project', (string) $projectId, ['subject_type' => $type, 'subject_id' => $subjectId, 'name' => (string) $name]);
        });
        $this->logger->info('프로젝트 접근 매핑 제거', ['project_id' => $projectId, 'subject_type' => $type, 'subject_id' => $subjectId, 'by' => $actor->label]);
        return $this->mappings($projectId);
    }
}
