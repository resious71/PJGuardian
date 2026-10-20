<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Events\DefectAssigned;
use PJGuardian\Services\Events\DefectsChanged;
use PJGuardian\Services\Events\DefectStatusChanged;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 결함 수동 변경(명세 6.2, P18): 담당자·심각도·상태, 단건과 일괄. 결함 수정은 **이 클래스로만** 한다(자동 전환은 P16 DefectStore).
 *
 * 규칙
 * - 낙관적 잠금: 요청의 version이 현재 값과 다르면 409 version_conflict + error.details.current(최신 결함). 갱신은
 *   `UPDATE … SET …, version = version + 1 WHERE id = ? AND version = ?`(쓰기 트랜잭션 BEGIN IMMEDIATE 안). 자동 전환도 version을 올리므로
 *   사용자가 본 뒤 분석이 바꾼 결함도 409가 된다.
 * - 심각도를 바꾸면 출처(severity_source)는 manual(P21S: 규칙 기본 심각도가 덮어쓰지 않는다).
 * - 이력: 바뀐 필드마다 defect_history 한 행(manual, 변경자, 사유). 결함 갱신과 같은 트랜잭션이라 한쪽만 남는 일이 없다.
 *   값이 모두 같으면 아무것도 쓰지 않는다(version·이력 그대로, 응답 changed: false). version이 다르면 값이 같아도 409(최신 값을 보여 준다).
 * - 상태 전이는 제한하지 않는다(6종 사이 자유). 담당자는 그 프로젝트에 접근할 수 있는 활성 사용자만(ProjectAccess::assignableUsers),
 *   null은 해제. 이미 담당인 사용자가 비활성화·접근 상실해도 담당은 그대로 둔다(다른 필드만 바꾸면 담당자를 다시 검사하지 않음).
 * - 사유(memo): 선택, 앞뒤 공백 제거, 1000자(스키마 CHECK), 줄바꿈·탭 외 제어 문자 거부. 원문 그대로 저장하고 화면은 textContent로만.
 * - 일괄: 프로젝트 하나 안의 결함만(아니면 요청 전체 거부 400 defect_not_in_project). 결함마다 version을 검사해 성공·변경 없음·충돌을
 *   나눠 돌려준다(부분 성공). 한 번에 ui.bulk_update_max(기본 500)건까지.
 * - 이벤트: 커밋 뒤 결함마다 DefectAssigned(새 담당자 지정), DefectStatusChanged(상태 변경), 요청마다 DefectsChanged(바뀐 결함 전체·이력 id).
 *   알림(P24)은 DefectsChanged를 구독해 받는 사람별로 묶는다(일괄 변경 = 받는 사람마다 1건, 중복 방지 키 = 이력 id).
 * 접근 판정은 라우트의 RequireProjectAccess가 먼저 한다(결함 id → 프로젝트, 보이지 않으면 404).
 */
final class DefectUpdate
{
    public const MEMO_MAX = 1000; // defect_history.memo CHECK(length <= 1000)

    /** 바꿀 수 있는 필드 → 열 이름(SQL에는 이 표의 값만 들어간다) */
    private const COLUMNS = ['assignee' => 'assignee_id', 'severity' => 'severity', 'status' => 'status'];

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly ProjectAccess $access,
        private readonly EventDispatcher $events,
        private readonly DefectQuery $query,
        private readonly int $bulkMax,
    ) {
    }

    /** @return array<string, mixed> 최신 결함(상세 형식) */
    public function current(int $defectId): array
    {
        return $this->query->detail($defectId);
    }

    /**
     * 단건 변경. $input: version(필수, 정수), assignee_id(키가 있으면 변경, null = 해제), severity, status, memo(선택).
     *
     * @param array<string, mixed> $user 변경자(로그인 사용자)
     * @param array<string, mixed> $input
     * @return array<string, mixed> 갱신된 결함 + changed
     */
    public function update(array $user, int $defectId, array $input): array
    {
        $version = self::version($input['version'] ?? null);
        $projectId = $this->db->value('SELECT project_id FROM defects WHERE id = ?', [$defectId]);
        if ($projectId === null) {
            throw new ApiException(404, 'defect_not_found');
        }
        $changes = $this->changes($input, (int) $projectId);
        $memo = self::memo($input['memo'] ?? null);
        $now = SystemClock::iso($this->clock->now());
        $by = (int) $user['id'];
        $r = $this->db->transaction(function (Db $db) use ($defectId, $version, $changes, $memo, $by, $now): array {
            $row = $db->one('SELECT id, project_id, version, assignee_id, severity, status FROM defects WHERE id = ?', [$defectId]);
            if ($row === null) {
                throw new ApiException(404, 'defect_not_found');
            }
            return $this->applyOne($db, $row, $version, $changes, $memo, $by, $now);
        }, write: true);
        if ($r['result'] === 'conflict') {
            throw $this->conflict($defectId);
        }
        $this->publish([$r], $by);
        return $this->query->detail($defectId) + ['changed' => $r['result'] === 'updated'];
    }

    /**
     * 일괄 변경. $input: items [{id, version}], changes {assignee_id?, severity?, status?}, memo?
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{updated: list<array{id:int, version:int}>, unchanged: list<int>, conflicts: list<array{id:int, current:array<string, mixed>}>}
     */
    public function bulk(array $user, int $projectId, array $input): array
    {
        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            throw new ApiException(400, 'invalid_bulk');
        }
        if (count($items) > $this->bulkMax) {
            throw new ApiException(400, 'bulk_too_large', ['max' => $this->bulkMax]);
        }
        $versions = [];
        foreach ($items as $it) {
            $id = is_array($it) ? ($it['id'] ?? null) : null;
            if (!is_int($id) || $id < 1 || isset($versions[$id])) {
                throw new ApiException(400, 'invalid_bulk');
            }
            $versions[$id] = self::version($it['version'] ?? null);
        }
        $changes = $this->changes(is_array($input['changes'] ?? null) ? $input['changes'] : [], $projectId);
        $memo = self::memo($input['memo'] ?? null);
        // 모두 이 프로젝트의 결함이어야 한다(다른 프로젝트·없는 id가 섞이면 하나도 바꾸지 않는다)
        $found = (int) $this->db->value('SELECT COUNT(*) FROM defects WHERE project_id = ? AND id IN (SELECT value FROM json_each(?))',
            [$projectId, json_encode(array_keys($versions))]);
        if ($found !== count($versions)) {
            throw new ApiException(400, 'defect_not_in_project');
        }
        $now = SystemClock::iso($this->clock->now());
        $by = (int) $user['id'];
        $results = $this->db->transaction(function (Db $db) use ($versions, $changes, $memo, $by, $now, $projectId): array {
            $out = [];
            foreach ($versions as $id => $version) {
                $row = $db->one('SELECT id, project_id, version, assignee_id, severity, status FROM defects WHERE id = ? AND project_id = ?', [$id, $projectId]);
                if ($row === null) {
                    throw new ApiException(400, 'defect_not_in_project'); // 확인과 트랜잭션 사이에 지워짐(프로젝트 삭제): 전체 되돌림
                }
                $out[] = $this->applyOne($db, $row, $version, $changes, $memo, $by, $now);
            }
            return $out;
        }, write: true);
        $this->publish($results, $by);
        $resp = ['updated' => [], 'unchanged' => [], 'conflicts' => []];
        foreach ($results as $r) {
            if ($r['result'] === 'updated') {
                $resp['updated'][] = ['id' => $r['id'], 'version' => $r['version']];
            } elseif ($r['result'] === 'unchanged') {
                $resp['unchanged'][] = $r['id'];
            } else {
                $resp['conflicts'][] = ['id' => $r['id'], 'current' => $this->query->detail($r['id'])];
            }
        }
        return $resp;
    }

    /**
     * 담당자 후보(명세 6.2·3.1): 이 프로젝트에 접근할 수 있는 활성 사용자. 이름·로그인 ID 검색(대소문자 무시), 페이지.
     * 응답에는 id·로그인 ID·표시 이름만(이메일·역할 없음).
     *
     * @return array{0: list<array{id:int, login_id:string, display_name:string}>, 1: int}
     */
    public function candidates(int $projectId, mixed $q, int $limit, int $offset): array
    {
        if ($q !== null && (!is_string($q) || mb_strlen($q) > 100 || preg_match('/[\x00-\x1F\x7F]/', $q) === 1)) {
            throw new ApiException(400, 'invalid_input');
        }
        $q = is_string($q) ? trim($q) : '';
        $list = [];
        foreach ($this->access->assignableUsers($projectId) as $u) {
            if ($q === '' || mb_stripos($u['login_id'], $q) !== false || mb_stripos($u['display_name'], $q) !== false) {
                $list[] = ['id' => $u['id'], 'login_id' => $u['login_id'], 'display_name' => $u['display_name']];
            }
        }
        return [array_slice($list, $offset, $limit), count($list)];
    }

    /**
     * 한 결함에 변경 적용(트랜잭션 안).
     *
     * @param array<string, mixed> $row 현재 행
     * @param array<string, mixed> $changes 필드 → 새 값
     * @return array{result:string, id:int, project_id:int, version:int, diff:array<string, array{0:mixed, 1:mixed}>, assignee:?int, history?:array<string, int>}
     */
    private function applyOne(Db $db, array $row, int $version, array $changes, ?string $memo, int $by, string $now): array
    {
        $id = (int) $row['id'];
        $base = ['id' => $id, 'project_id' => (int) $row['project_id'], 'version' => (int) $row['version'], 'diff' => [],
            'assignee' => $row['assignee_id'] === null ? null : (int) $row['assignee_id']];
        if ((int) $row['version'] !== $version) {
            return ['result' => 'conflict'] + $base;
        }
        $diff = [];
        foreach ($changes as $field => $new) {
            $old = $row[self::COLUMNS[$field]];
            $old = $field === 'assignee' ? ($old === null ? null : (int) $old) : (string) $old;
            if ($old !== $new) {
                $diff[$field] = [$old, $new];
            }
        }
        if ($diff === []) {
            return ['result' => 'unchanged'] + $base;
        }
        $set = [];
        $params = [];
        foreach ($diff as $field => [, $new]) {
            $set[] = self::COLUMNS[$field] . ' = ?';
            $params[] = $new;
        }
        if (isset($diff['severity'])) {
            // P21S: 사람이 바꾼 심각도(not_defined로 바꾼 것 포함)는 manual — 이후 분석·일괄 채움이 덮어쓰지 않는다
            $set[] = "severity_source = 'manual'";
        }
        $n = $db->execute('UPDATE defects SET ' . implode(', ', $set) . ', version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
            [...$params, $now, $id, $version]);
        if ($n !== 1) {
            return ['result' => 'conflict'] + $base;
        }
        $history = [];
        foreach ($diff as $field => [$old, $new]) {
            $db->execute(
                "INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, memo) VALUES (?, ?, ?, ?, ?, ?, 'manual', ?)",
                [$id, $field, $old === null ? null : (string) $old, $new === null ? null : (string) $new, $by, $now, $memo],
            );
            $history[$field] = $db->lastInsertId();
        }
        return ['result' => 'updated', 'version' => $version + 1, 'diff' => $diff, 'history' => $history,
            'assignee' => isset($diff['assignee']) ? $diff['assignee'][1] : $base['assignee']] + $base;
    }

    /** @param list<array<string, mixed>> $results 커밋된 결과 */
    private function publish(array $results, int $by): void
    {
        $items = [];
        $projectId = null;
        foreach ($results as $r) {
            if ($r['result'] !== 'updated') {
                continue;
            }
            $projectId = $r['project_id'];
            $items[] = ['id' => $r['id'], 'diff' => $r['diff'], 'assignee' => $r['assignee'], 'history' => $r['history'] ?? []];
            if (isset($r['diff']['assignee']) && $r['diff']['assignee'][1] !== null) {
                $this->events->dispatch(new DefectAssigned($r['id'], $r['project_id'], $r['diff']['assignee'][1], $r['diff']['assignee'][0], $by));
            }
            if (isset($r['diff']['status'])) {
                $this->events->dispatch(new DefectStatusChanged($r['id'], $r['project_id'], $r['diff']['status'][0], $r['diff']['status'][1], $by, $r['assignee']));
            }
        }
        if ($items !== []) {
            $this->events->dispatch(new DefectsChanged((int) $projectId, $by, $items));
        }
    }

    private function conflict(int $defectId): ApiException
    {
        return new ApiException(409, 'version_conflict', [], null, [], [], ['current' => $this->query->detail($defectId)]);
    }

    /**
     * 요청의 변경 필드 검증. 키가 있는 것만 바꾼다.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function changes(array $input, int $projectId): array
    {
        $out = [];
        if (array_key_exists('severity', $input)) {
            if (!in_array($input['severity'], DefectFilter::SEVERITIES, true)) {
                throw new ApiException(400, 'invalid_severity');
            }
            $out['severity'] = $input['severity'];
        }
        if (array_key_exists('status', $input)) {
            if (!in_array($input['status'], DefectFilter::STATUSES, true)) {
                throw new ApiException(400, 'invalid_status');
            }
            $out['status'] = $input['status'];
        }
        if (array_key_exists('assignee_id', $input)) {
            $a = $input['assignee_id'];
            if ($a !== null) {
                $ok = is_int($a) && $a >= 1 && in_array($a, array_column($this->access->assignableUsers($projectId), 'id'), true);
                if (!$ok) {
                    throw new ApiException(400, 'invalid_assignee');
                }
            }
            $out['assignee'] = $a;
        }
        if ($out === []) {
            throw new ApiException(400, 'no_change_fields');
        }
        return $out;
    }

    private static function version(mixed $v): int
    {
        if (!is_int($v) || $v < 1) {
            throw new ApiException(400, 'invalid_version');
        }
        return $v;
    }

    private static function memo(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw new ApiException(400, 'invalid_memo', ['max' => self::MEMO_MAX]);
        }
        $v = trim(str_replace("\r\n", "\n", $v));
        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $v) === 1 || mb_strlen($v) > self::MEMO_MAX) {
            throw new ApiException(400, 'invalid_memo', ['max' => self::MEMO_MAX]);
        }
        return $v === '' ? null : $v;
    }
}
