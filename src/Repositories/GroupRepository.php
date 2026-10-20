<?php

declare(strict_types=1);

namespace PJGuardian\Repositories;

use PJGuardian\Support\Db;

/** groups, group_members 테이블(명세 3.1). 모든 쿼리는 Prepared Statement. */
final class GroupRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM groups WHERE id = ?', [$id]);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return $this->db->value('SELECT 1 FROM groups WHERE name = ? AND id <> ?', [$name, $exceptId ?? 0]) !== null;
    }

    /**
     * @param int|null $managerUserId null이면 전체(admin), 값이 있으면 그 사용자가 관리하는 그룹만
     * @return list<array<string, mixed>>
     */
    public function listWithCounts(?int $managerUserId): array
    {
        $sql = 'SELECT g.id, g.name, g.description, g.created_at, g.updated_at,'
            . ' (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS member_count,'
            . ' (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.is_manager = 1) AS manager_count'
            . ' FROM groups g';
        $params = [];
        if ($managerUserId !== null) {
            $sql .= ' WHERE g.id IN (SELECT group_id FROM group_members WHERE user_id = ? AND is_manager = 1)';
            $params[] = $managerUserId;
        }
        return $this->db->all($sql . ' ORDER BY g.name COLLATE NOCASE', $params);
    }

    public function insert(string $name, string $description, ?int $by, string $now): int
    {
        $this->db->execute(
            'INSERT INTO groups (name, description, created_at, created_by, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $description, $now, $by, $now],
        );
        return $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $description, string $now): void
    {
        $this->db->execute('UPDATE groups SET name = ?, description = ?, updated_at = ? WHERE id = ?', [$name, $description, $now, $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM groups WHERE id = ?', [$id]);
    }

    public function isManager(int $groupId, int $userId): bool
    {
        return $this->db->value('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND is_manager = 1', [$groupId, $userId]) !== null;
    }

    public function managesAny(int $userId): bool
    {
        return $this->db->value('SELECT 1 FROM group_members WHERE user_id = ? AND is_manager = 1 LIMIT 1', [$userId]) !== null;
    }

    /** @return array<string, mixed>|null */
    public function member(int $groupId, int $userId): ?array
    {
        return $this->db->one('SELECT * FROM group_members WHERE group_id = ? AND user_id = ?', [$groupId, $userId]);
    }

    /** @return list<array<string, mixed>> 로그인 ID 순 */
    public function members(int $groupId): array
    {
        return $this->db->all(
            'SELECT u.id AS user_id, u.login_id, u.display_name, u.status, m.is_manager, m.added_at'
            . ' FROM group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY u.login_id COLLATE NOCASE',
            [$groupId],
        );
    }

    public function countMembers(int $groupId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM group_members WHERE group_id = ?', [$groupId]);
    }

    public function addMember(int $groupId, int $userId, ?int $by, string $now): void
    {
        $this->db->execute(
            'INSERT INTO group_members (group_id, user_id, is_manager, added_at, added_by) VALUES (?, ?, 0, ?, ?)',
            [$groupId, $userId, $now, $by],
        );
    }

    public function removeMember(int $groupId, int $userId): int
    {
        return $this->db->execute('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$groupId, $userId]);
    }

    public function setManager(int $groupId, int $userId, bool $isManager): int
    {
        return $this->db->execute('UPDATE group_members SET is_manager = ? WHERE group_id = ? AND user_id = ?', [$isManager ? 1 : 0, $groupId, $userId]);
    }

    /** @return array<int, list<string>> 사용자 id → 소속 그룹 이름(이름순). 사용자 관리 화면의 읽기 전용 열 */
    public function groupNamesByUser(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT m.user_id, g.name FROM group_members m JOIN groups g ON g.id = m.group_id ORDER BY g.name COLLATE NOCASE') as $r) {
            $out[(int) $r['user_id']][] = (string) $r['name'];
        }
        return $out;
    }

    public function mappedProjectCount(int $groupId): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM project_access WHERE subject_type = 'group' AND subject_id = ?", [$groupId]);
    }
}
