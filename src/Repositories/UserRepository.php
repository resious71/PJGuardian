<?php

declare(strict_types=1);

namespace PJGuardian\Repositories;

use PJGuardian\Support\Db;
use PJGuardian\Support\SchemaFeatures;

/**
 * users 테이블. 모든 쿼리는 Prepared Statement.
 * 화면·API로 내보낼 때는 toPublic()을 거친다(비밀번호 해시를 절대 포함하지 않음).
 */
final class UserRepository
{
    /** 목록 정렬·필터에 쓸 수 있는 공개 컬럼 */
    private const PUBLIC_COLUMNS = 'id, login_id, display_name, email, role, status, auth_type, temp_password_expires_at,'
        . ' must_change_password, password_kind, failed_login_count, locked_until, created_at, updated_at';
    /** P28B(마이그레이션 026 뒤): 승인 상태·외부 신원 연결 여부 */
    private const EXTERNAL_COLUMNS = ', approval_status, (external_id IS NOT NULL) AS external_linked';

    private function cols(): string
    {
        return self::PUBLIC_COLUMNS . (SchemaFeatures::externalAuth($this->db) ? self::EXTERNAL_COLUMNS : '');
    }

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null 비밀번호 해시 포함(인증용) */
    public function findForAuth(string $loginId): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE login_id = ?', [$loginId]);
    }

    /** @return array<string, mixed>|null 비밀번호 해시 포함(내부용) */
    public function findRaw(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null 해시 없는 행 */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT ' . $this->cols() . ' FROM users WHERE id = ?', [$id]);
    }

    public function loginIdExists(string $loginId): bool
    {
        return $this->db->value('SELECT 1 FROM users WHERE login_id = ?', [$loginId]) !== null;
    }

    /** 활성 로컬(앱 비밀번호) 관리자 수 — 로컬 관리자는 항상 한 명 이상 남는다(P28B 사용자 결정) */
    public function countActiveLocalAdmins(?int $excludeId = null): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND auth_type = 'local' AND id <> ?",
            [$excludeId ?? 0],
        );
    }

    /** @return array{pending:int, rejected:int} 승인 대기·거절 계정 수(P28B) */
    public function approvalCounts(): array
    {
        $out = ['pending' => 0, 'rejected' => 0];
        if (!SchemaFeatures::externalAuth($this->db)) {
            return $out;
        }
        foreach ($this->db->all("SELECT approval_status, COUNT(*) AS n FROM users WHERE approval_status <> 'approved' GROUP BY approval_status") as $r) {
            $out[(string) $r['approval_status']] = (int) $r['n'];
        }
        return $out;
    }

    public function countActiveAdmins(?int $excludeId = null): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?",
            [$excludeId ?? 0],
        );
    }

    /**
     * 목록. 필터: q(로그인 ID·이름·이메일 부분 일치), role, status. 정렬: 로그인 ID.
     *
     * @param array{q?:?string, role?:?string, status?:?string} $f
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    /**
     * 그룹 구성원 추가용 사용자 검색(명세 3.1): 활성 사용자만, 로그인 ID·이름 부분 일치, 로그인 ID와 이름만 돌려준다.
     *
     * @return list<array{login_id:string, display_name:string}>
     */
    public function searchActive(string $q, int $limit): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $rows = $this->db->all(
            "SELECT login_id, display_name FROM users WHERE status = 'active'"
            . " AND (login_id LIKE ? ESCAPE '\\' OR display_name LIKE ? ESCAPE '\\') ORDER BY login_id COLLATE NOCASE LIMIT ?",
            [$like, $like, $limit],
        );
        return array_map(static fn (array $r): array => ['login_id' => (string) $r['login_id'], 'display_name' => (string) $r['display_name']], $rows);
    }

    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            $where[] = "(login_id LIKE ? ESCAPE '\\' OR display_name LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }
        if (($f['role'] ?? '') !== '') {
            $where[] = 'role = ?';
            $params[] = $f['role'];
        }
        if (($f['status'] ?? '') !== '') {
            $where[] = 'status = ?';
            $params[] = $f['status'];
        }
        // P28B: 기본은 승인된 계정만. approval=pending|rejected는 외부 인증 첫 로그인의 승인 대기·거절 계정만
        if (!SchemaFeatures::externalAuth($this->db)) {
            // 026 전: 승인 대기 계정이 없다
            if (in_array($f['approval'] ?? '', ['pending', 'rejected'], true)) {
                $where[] = '0';
            }
        } elseif (in_array($f['approval'] ?? '', ['pending', 'rejected'], true)) {
            $where[] = 'approval_status = ?';
            $params[] = $f['approval'];
        } else {
            $where[] = "approval_status = 'approved'";
        }
        $cond = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM users' . $cond, $params);
        $rows = $this->db->all(
            'SELECT ' . $this->cols() . ' FROM users' . $cond . ' ORDER BY login_id COLLATE NOCASE LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );
        return [$rows, $total];
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        if (!SchemaFeatures::externalAuth($this->db)) {
            $this->db->execute(
                'INSERT INTO users (login_id, display_name, email, role, status, auth_type, password_hash,'
                . ' temp_password_expires_at, must_change_password, password_kind, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $row['login_id'], $row['display_name'], $row['email'] ?? null, $row['role'], $row['status'] ?? 'active',
                    $row['auth_type'] ?? 'local', $row['password_hash'] ?? null, $row['temp_password_expires_at'] ?? null,
                    (int) ($row['must_change_password'] ?? 0), $row['password_kind'] ?? 'user', $row['created_at'], $row['created_at'],
                ],
            );
            return $this->db->lastInsertId();
        }
        $this->db->execute(
            'INSERT INTO users (login_id, display_name, email, role, status, auth_type, password_hash,'
            . ' temp_password_expires_at, must_change_password, password_kind, created_at, updated_at,'
            . ' approval_status, external_id, external_issuer)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['login_id'], $row['display_name'], $row['email'] ?? null, $row['role'], $row['status'] ?? 'active',
                $row['auth_type'] ?? 'local', $row['password_hash'] ?? null, $row['temp_password_expires_at'] ?? null,
                (int) ($row['must_change_password'] ?? 0), $row['password_kind'] ?? 'user', $row['created_at'], $row['created_at'],
                $row['approval_status'] ?? 'approved', $row['external_id'] ?? null, $row['external_issuer'] ?? null,
            ],
        );
        return $this->db->lastInsertId();
    }

    public function updateProfile(int $id, string $displayName, ?string $email, string $now): void
    {
        $this->db->execute('UPDATE users SET display_name = ?, email = ?, updated_at = ? WHERE id = ?', [$displayName, $email, $now, $id]);
    }

    public function updateRole(int $id, string $role, string $now): void
    {
        $this->db->execute('UPDATE users SET role = ?, updated_at = ? WHERE id = ?', [$role, $now, $id]);
    }

    public function updateStatus(int $id, string $status, string $now): void
    {
        $this->db->execute('UPDATE users SET status = ?, updated_at = ? WHERE id = ?', [$status, $now, $id]);
    }

    /**
     * 비밀번호 설정. $tempExpiresAt이 있으면 임시 비밀번호(다음 로그인 때 변경 강제, password_kind=temporary),
     * 없으면 사용자가 정한 비밀번호(password_kind=user). 잠금도 푼다. 관리자 변경 알림은 지운다(새 비밀번호로 대체됨).
     */
    public function setPassword(int $id, string $hash, ?string $tempExpiresAt, string $now): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = ?, temp_password_expires_at = ?, must_change_password = ?, password_kind = ?,'
            . ' admin_password_set_at = NULL, failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [$hash, $tempExpiresAt, $tempExpiresAt === null ? 0 : 1, $tempExpiresAt === null ? 'user' : 'temporary', $now, $id],
        );
    }

    /**
     * 관리자가 정한 비밀번호(명세 13장·19.10). 변경을 강제하지 않고(must_change_password = 0),
     * 다음 로그인 때 알림을 보이도록 admin_password_set_at을 기록한다. 임시 비밀번호 상태와 잠금은 푼다.
     */
    public function setPasswordByAdmin(int $id, string $hash, string $now): void
    {
        $this->db->execute(
            "UPDATE users SET password_hash = ?, temp_password_expires_at = NULL, must_change_password = 0, password_kind = 'user',"
            . ' admin_password_set_at = ?, failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [$hash, $now, $now, $id],
        );
    }

    /** 다음 로그인 알림을 꺼낸다(있으면 시각을 돌려주고 비운다) */
    public function takeAdminPasswordNotice(int $id): ?string
    {
        $at = $this->db->value('SELECT admin_password_set_at FROM users WHERE id = ?', [$id]);
        if ($at === null) {
            return null;
        }
        $this->db->execute('UPDATE users SET admin_password_set_at = NULL WHERE id = ?', [$id]);
        return (string) $at;
    }

    /** @return list<string> 초기 비밀번호를 아직 바꾸지 않은 활성 관리자의 로그인 ID(하드닝 점검) */
    public function initialPasswordPendingAdmins(): array
    {
        return array_map('strval', array_column($this->db->all(
            "SELECT login_id FROM users WHERE role = 'admin' AND status = 'active' AND password_kind = 'initial' ORDER BY login_id",
        ), 'login_id'));
    }

    /** 로그인 성공 후 재해시(비용 변경 시). 다른 상태는 건드리지 않는다. */
    public function rehash(int $id, string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function recordFailure(int $id, int $count, ?string $lockedUntil): void
    {
        $this->db->execute('UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?', [$count, $lockedUntil, $id]);
    }

    public function clearFailures(int $id): void
    {
        $this->db->execute('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ? AND (failed_login_count <> 0 OR locked_until IS NOT NULL)', [$id]);
    }

    /**
     * API·화면용 공개 정보. 비밀번호 해시는 어떤 경우에도 넣지 않는다.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublic(array $row, string $nowIso): array
    {
        $locked = ($row['locked_until'] ?? null) !== null && (string) $row['locked_until'] > $nowIso;
        return [
            'id' => (int) $row['id'],
            'login_id' => (string) $row['login_id'],
            'display_name' => (string) $row['display_name'],
            'email' => $row['email'] ?? null,
            'role' => (string) $row['role'],
            'status' => (string) $row['status'],
            'auth_type' => (string) $row['auth_type'],
            'must_change_password' => (int) ($row['must_change_password'] ?? 0) === 1,
            // 최초 관리자의 고정 초기 비밀번호를 아직 바꾸지 않음(X02)
            'initial_password_pending' => ($row['password_kind'] ?? 'user') === 'initial',
            'temp_password_expires_at' => $row['temp_password_expires_at'] ?? null,
            'locked' => $locked,
            'locked_until' => $locked ? $row['locked_until'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            // P28B: 승인 상태(외부 인증 첫 로그인 = pending), 외부 신원 연결 여부(external_id 값은 내리지 않음)
            'approval_status' => (string) ($row['approval_status'] ?? 'approved'),
            'external_linked' => (bool) ($row['external_linked'] ?? (($row['external_id'] ?? null) !== null)),
        ];
    }
}
