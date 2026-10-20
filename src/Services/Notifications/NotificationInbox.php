<?php

declare(strict_types=1);

namespace PJGuardian\Services\Notifications;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\SystemClock;

/**
 * 내 알림 조회·읽음·삭제(P24). **받는 사람 본인 것만**: 모든 쿼리가 user_id = 로그인 사용자 조건을 갖는다(admin도 남의 알림을 못 본다).
 *
 * 접근 범위가 우선한다: 가리키는 프로젝트가 지금 이 사용자에게 보이지 않으면(접근 회수) 그 알림은 목록·개수·이동에서 빠지고,
 * id로 접근해도 없는 것과 같은 404다(행은 보관 정리가 지운다. 프로젝트 삭제는 행도 함께 지워진다 — FK CASCADE).
 * 보이는 프로젝트 id 목록을 먼저 구해(ProjectAccess::scopeFor, 프로젝트 수만큼) JSON 매개변수 하나로 넘긴다 — 알림 행마다 접근 하위 쿼리를 돌리지 않는다.
 *
 * 프로젝트 이름은 저장하지 않고 보여 줄 때 조회한다. 이동 주소는 서버가 접근 판정 뒤 만든다(경로 문자열, 화면이 앱 기준 경로를 붙인다).
 */
final class NotificationInbox
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly ProjectAccess $access,
    ) {
    }

    /**
     * @param array<string, mixed> $user 로그인 사용자
     * @param array{kind?:?string, unread?:bool} $filter 검증된 조건
     * @return array{0: list<array<string, mixed>>, 1: int} [쪽 항목, 전체 수]
     */
    public function list(array $user, array $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($user, $filter);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM notifications n WHERE {$where}", $params);
        $rows = $this->db->all(
            'SELECT n.id, n.kind, n.project_id, n.analysis_id, n.defect_id, n.params, n.created_at, n.read_at, p.name AS project_name'
            . " FROM notifications n LEFT JOIN projects p ON p.id = n.project_id WHERE {$where} ORDER BY n.created_at DESC, n.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset],
        );
        return [array_map(self::item(...), $rows), $total];
    }

    /** 안 읽은 알림 수(보이지 않는 프로젝트의 알림 제외). (user_id, read_at, created_at) 인덱스만 쓴다 */
    public function unreadCount(array $user): int
    {
        [$where, $params] = $this->where($user, ['unread' => true]);
        return (int) $this->db->value("SELECT COUNT(*) FROM notifications n WHERE {$where}", $params);
    }

    /** 읽음(멱등 — 이미 읽었으면 처음 읽은 시각 유지). 남의 것·없는 것·숨긴 것은 404 */
    public function markRead(array $user, int $id): array
    {
        $this->require($user, $id);
        $this->db->execute('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ? AND user_id = ?', [$this->now(), $id, (int) $user['id']]);
        return $this->one($user, $id);
    }

    /** 안 읽음으로 되돌리기(멱등) */
    public function markUnread(array $user, int $id): array
    {
        $this->require($user, $id);
        $this->db->execute('UPDATE notifications SET read_at = NULL WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
        return $this->one($user, $id);
    }

    /** 내 모든 알림을 읽음으로(화면의 필터와 무관). 바꾼 수 */
    public function readAll(array $user): int
    {
        return $this->db->execute('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [$this->now(), (int) $user['id']]);
    }

    /** 한 건 삭제. 남의 것·없는 것·숨긴 것은 404 */
    public function delete(array $user, int $id): void
    {
        $this->require($user, $id);
        $this->db->execute('DELETE FROM notifications WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
    }

    /** 내 읽은 알림 모두 삭제. 지운 수 */
    public function deleteRead(array $user): int
    {
        return $this->db->execute('DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL', [(int) $user['id']]);
    }

    /** @return array<string, mixed> */
    private function one(array $user, int $id): array
    {
        [$where, $params] = $this->where($user, []);
        $row = $this->db->one(
            'SELECT n.id, n.kind, n.project_id, n.analysis_id, n.defect_id, n.params, n.created_at, n.read_at, p.name AS project_name'
            . " FROM notifications n LEFT JOIN projects p ON p.id = n.project_id WHERE {$where} AND n.id = ?",
            [...$params, $id],
        );
        return $row === null ? throw new ApiException(404, 'notification_not_found') : self::item($row);
    }

    private function require(array $user, int $id): void
    {
        [$where, $params] = $this->where($user, []);
        if ($this->db->value("SELECT 1 FROM notifications n WHERE {$where} AND n.id = ?", [...$params, $id]) === null) {
            throw new ApiException(404, 'notification_not_found');
        }
    }

    /**
     * 본인 + 보이는 프로젝트 조건.
     *
     * @param array{kind?:?string, unread?:bool} $filter
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $user, array $filter): array
    {
        $sql = ['n.user_id = ?'];
        $params = [(int) $user['id']];
        if (($filter['unread'] ?? false) === true) {
            $sql[] = 'n.read_at IS NULL';
        }
        if (($filter['kind'] ?? null) !== null) {
            $sql[] = 'n.kind = ?';
            $params[] = $filter['kind'];
        }
        if (!$this->access->isAdmin($user)) {
            [$cond, $cp] = $this->access->scopeFor($user, 'p');
            $visible = array_map('intval', array_column($this->db->all("SELECT p.id FROM projects p WHERE {$cond}", $cp), 'id'));
            $sql[] = '(n.project_id IS NULL OR n.project_id IN (SELECT value FROM json_each(?)))';
            $params[] = json_encode($visible);
            // admin 전용 종류(P26 schedule_failed, P19C defect_count_warn)는 admin이 아니면(역할이 바뀐 경우 포함) 보이지 않는다
            $sql[] = 'n.kind NOT IN (SELECT value FROM json_each(?))';
            $params[] = json_encode(NotificationService::ADMIN_KINDS);
        }
        return [implode(' AND ', $sql), $params];
    }

    /**
     * 행 → API 항목. 문구는 화면이 kind + params로 만든다.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function item(array $r): array
    {
        $params = json_decode((string) $r['params'], true);
        $params = is_array($params) ? $params : [];
        $kind = (string) $r['kind'];
        $projectId = $r['project_id'] === null ? null : (int) $r['project_id'];
        $analysisId = $r['analysis_id'] === null ? null : (int) $r['analysis_id'];
        $defectId = $r['defect_id'] === null ? null : (int) $r['defect_id'];
        $run = isset($params['run']) && is_int($params['run']) ? $params['run'] : null;
        $href = match ($kind) {
            'analysis_done' => $run !== null && $projectId !== null ? "/projects/{$projectId}?run={$run}" : ($analysisId !== null ? "/analyses/{$analysisId}" : null),
            'analysis_failed' => $analysisId !== null ? "/analyses/{$analysisId}" : null,
            // P26: 주기 분석 실패 → 그 분석, 건너뜀(분석 없음) → 관리자 주기 분석 현황
            'schedule_failed' => $analysisId !== null ? "/analyses/{$analysisId}" : '/admin/schedules',
            'defect_count_warn' => $projectId !== null ? "/projects/{$projectId}" : null, // P19C
            'backup_failed', 'backup_done', 'restore_rehearsal_due' => '/admin/backups', // P27B
            'account_pending', 'account_pending_limit' => '/admin/users?approval=pending', // P28B 승인 대기
            'saml_cert_expiring' => '/admin/config-settings#auth_saml', // P28D IdP 인증서 교체(롤오버)
            'auth_identity_mismatch' => '/admin/audit', // P28B 같은 로그인 ID, 다른 외부 식별자 — 감사 로그(auth.login_failed)
            'analysis_summary' => $projectId !== null ? "/projects/{$projectId}" . ($run !== null ? "?run={$run}" : '') : null,
            'defect_assigned', 'defect_changed' => $defectId !== null ? "/defects/{$defectId}" : '/my-defects',
            default => null,
        };
        $item = [
            'id' => (int) $r['id'], 'kind' => $kind, 'params' => (object) $params,
            'created_at' => (string) $r['created_at'], 'read_at' => $r['read_at'] === null ? null : (string) $r['read_at'],
            'project' => $projectId === null ? null : ['id' => $projectId, 'name' => (string) ($r['project_name'] ?? '')],
            'analysis_id' => $analysisId, 'defect_id' => $defectId, 'href' => $href,
        ];
        if (($kind === 'analysis_failed' || ($kind === 'schedule_failed' && !isset($params['skipped']))) && is_string($params['code'] ?? null)) {
            $f = ErrorCatalog::describe('analysis', $params['code']);
            $item['failure'] = ['title' => $f['title'], 'guide_key' => $f['guide_key']];
        }
        return $item;
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }
}
