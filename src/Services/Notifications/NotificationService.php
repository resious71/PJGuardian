<?php

declare(strict_types=1);

namespace PJGuardian\Services\Notifications;

use PJGuardian\Services\AppSettings;
use PJGuardian\Services\Events\AnalysisCancelled;
use PJGuardian\Services\Events\AnalysisFailed;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Services\Events\DefectsChanged;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 앱 안 알림 생성(P24, docs/architecture.md 40절). 알림은 **여기서만** 만든다(emit). 호출하는 쪽(분석 종료·결함 변경)은 이벤트만 보내고
 * 알림 규칙(받는 사람·종류별 설정·접근 판정·중복 방지)은 모른다.
 *
 * 종류(코드값 고정, 문구는 화면이 kind + params로 만든다 — 저장된 문장·HTML 없음)
 *   analysis_done     분석 요청자 — 분석 성공(신규·해결·재오픈 건수, Run 번호, 변경 없음)
 *   analysis_failed   분석 요청자 — 실패·취소·시간 초과(상태, 실패 코드)
 *   defect_assigned   새 담당자 — 사람이 담당자를 지정·변경(자기 지정 포함, 해제는 없음). 한 요청에서 받는 사람마다 1건(건수)
 *   defect_changed    현재 담당자 — 사람이 상태·심각도를 바꿈(본인 변경 포함). 한 요청에서 받는 사람마다 1건. 같은 요청에서 같은 사람이
 *                     defect_assigned를 받으면 이것은 보내지 않는다
 *   analysis_summary  그 프로젝트에 열린 담당 결함이 있는(또는 이번 분석이 담당 결함을 자동 전환한) 사용자, 요청자 제외 —
 *                     프로젝트 전체 신규·해결·재오픈과 그 사람 담당 결함의 자동 해결·재오픈 건수. 프로젝트 전체 변화가 0이면 보내지 않는다
 *   defect_count_warn 활성 admin 전체(P19C, ADMIN_KINDS) — 프로젝트의 현재 결함이 [limits] project_defect_warn을 넘음. 프로젝트당 한 건
 *                     (defect_count_warn:{프로젝트})에 건수·분석만 갱신(읽음 유지 — 다시 넘을 때마다 같은 한 건이 갱신된다).
 *   schedule_failed   활성 admin 전체(P26, ADMIN_KINDS — 일반 사용자에게는 만들지도 보이지도 않음) — 주기 분석 실패(재시도 후 최종)·
 *                     주기 건너뜀(디스크 차단·연결 문제). 같은 프로젝트의 연속 실패는 한 건(schedule_failed:{프로젝트}:{첫 실패 분석})에
 *                     횟수·시각·최신 분석만 갱신한다(읽음 상태는 그대로 — emit의 $refresh).
 *
 * 규칙
 * - 접근: 받는 사람이 그 프로젝트를 볼 수 있는 **활성** 사용자일 때만 만든다(ProjectAccess::usersWithAccess, 명세 3.1).
 * - 종류별 끄기: 만들 때 적용(notification_prefs의 enabled=0이면 만들지 않음). 다시 켜도 지난 알림은 생기지 않는다.
 * - 중복 방지: (user_id, dedupe_key) 유일 + INSERT OR IGNORE. 키 = 분석 종료 `analysis_done:{분석}`·`analysis_failed:{분석}`·
 *   `analysis_summary:{분석}`, 수동 변경 `defect_assigned:h{이력 id}`·`defect_changed:h{이력 id}`(그 사람 몫 이력 중 가장 작은 id —
 *   단건·일괄 모두 같은 규칙, 재시도해도 같은 키).
 * - 실패 격리: 본 동작(분석 결과 반영·결함 변경)이 커밋된 **뒤** 이벤트로 불리고, 여기서 난 예외는 경고 로그만 남기고 삼킨다.
 * - 최소 정보: params는 건수·Run 번호·상태값·코드만. 결함 메시지·파일 경로·소스·프로젝트 이름은 넣지 않는다(이름은 보여 줄 때 조회).
 * - 비용: 받는 사람 전체를 한 문장(배치 INSERT, 200명씩)으로 넣는다. 분석 요약의 담당자 목록은 defects_project_assignee_id 인덱스로
 *   "담당자가 있는 행"만 본다(결함 전체를 훑지 않는다).
 */
final class NotificationService
{
    public const KINDS = ['analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
        'backup_failed', 'backup_done', 'restore_rehearsal_due', 'account_pending', 'account_pending_limit', 'auth_identity_mismatch', 'saml_cert_expiring'];

    /** admin만 받는 종류(설정 화면·목록 조건에도 admin에게만 보인다) */
    public const ADMIN_KINDS = ['schedule_failed', 'defect_count_warn', 'backup_failed', 'backup_done', 'restore_rehearsal_due', // P27B 백업·복원 3종
        'account_pending', 'account_pending_limit', 'auth_identity_mismatch', // P28B 외부 인증 3종
        'saml_cert_expiring']; // P28D SAML IdP 인증서 만료 임박(마이그레이션 028)

    /** params JSON 최대 길이(스키마 CHECK와 같다) */
    public const PARAMS_MAX = 2000;

    /** 한 INSERT 문장의 행 수(매개변수 8개 × 200 = 1600개) */
    private const INSERT_CHUNK = 200;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly ProjectAccess $access,
        private readonly Logger $logger,
        private readonly AppSettings $settings,
    ) {
    }

    /** 이벤트 구독(App::events가 처음 만들 때 한 번) */
    public function subscribe(EventDispatcher $events): void
    {
        $events->subscribe(AnalysisSucceeded::class, fn (object $e) => $e instanceof AnalysisSucceeded ? $this->onAnalysisSucceeded($e) : null);
        $events->subscribe(AnalysisFailed::class, fn (object $e) => $e instanceof AnalysisFailed ? $this->onAnalysisEnded($e->analysisId) : null);
        $events->subscribe(AnalysisCancelled::class, fn (object $e) => $e instanceof AnalysisCancelled ? $this->onAnalysisEnded($e->analysisId) : null);
        $events->subscribe(DefectsChanged::class, fn (object $e) => $e instanceof DefectsChanged ? $this->onDefectsChanged($e) : null);
    }

    /**
     * 알림 만들기(유일한 생성 지점). 실패해도 예외를 던지지 않는다(경고 로그, 0 반환).
     *
     * @param string $kind self::KINDS
     * @param array<int, array<string, mixed>>|list<int> $recipients 받는 사람 id 목록, 또는 id → 그 사람만의 params(공통 params 위에 덮음)
     * @param array{project_id?:?int, analysis_id?:?int, defect_id?:?int} $refs
     * @param array<string, mixed> $params 문구에 쓰는 값만(건수·Run·상태·코드)
     * @param bool $refresh 같은 (사용자, dedupe_key)가 이미 있으면 무시하지 않고 params·analysis_id·created_at만 새 값으로(읽음 상태는 유지, P26)
     * @return int 실제로 만든(또는 갱신한) 수(설정으로 꺼짐·접근 불가·중복은 빠진다)
     */
    public function emit(string $kind, array $recipients, array $refs, array $params, string $dedupeKey, bool $refresh = false): int
    {
        try {
            return $this->create($kind, $recipients, $refs, $params, $dedupeKey, $refresh);
        } catch (\Throwable $e) {
            $this->logger->warn('알림 생성 실패(본 동작에는 영향 없음)', ['kind' => $kind, 'dedupe_key' => $dedupeKey, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * @param array<int, array<string, mixed>>|list<int> $recipients
     * @param array{project_id?:?int, analysis_id?:?int, defect_id?:?int} $refs
     * @param array<string, mixed> $params
     */
    private function create(string $kind, array $recipients, array $refs, array $params, string $dedupeKey, bool $refresh = false): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('알 수 없는 알림 종류: ' . $kind);
        }
        if ($dedupeKey === '' || strlen($dedupeKey) > 120) {
            throw new \InvalidArgumentException('dedupe_key 형식');
        }
        $perUser = [];
        foreach ($recipients as $k => $v) {
            if (is_array($v)) {
                $perUser[(int) $k] = $v;
            } else {
                $perUser[(int) $v] = [];
            }
        }
        unset($perUser[0]);
        if ($perUser === []) {
            return 0;
        }
        $projectId = isset($refs['project_id']) ? (int) $refs['project_id'] : null;
        // 접근 판정: 그 프로젝트를 볼 수 있는 활성 사용자만(프로젝트가 없으면 빈 목록 → 아무도 받지 않음)
        $allowed = $projectId !== null
            ? array_flip($this->access->usersWithAccess($projectId))
            : array_flip(array_map('intval', array_column($this->db->all(
                "SELECT id FROM users WHERE status = 'active' AND id IN (SELECT value FROM json_each(?))", [json_encode(array_keys($perUser))]), 'id')));
        $perUser = array_intersect_key($perUser, $allowed);
        if ($perUser === []) {
            return 0;
        }
        // 종류별 끄기(기본 켜짐 — 꺼진 행만 저장)
        foreach ($this->db->all(
            'SELECT user_id FROM notification_prefs WHERE kind = ? AND enabled = 0 AND user_id IN (SELECT value FROM json_each(?))',
            [$kind, json_encode(array_keys($perUser))],
        ) as $r) {
            unset($perUser[(int) $r['user_id']]);
        }
        if ($perUser === []) {
            return 0;
        }
        $now = SystemClock::iso($this->clock->now());
        $analysisId = isset($refs['analysis_id']) ? (int) $refs['analysis_id'] : null;
        $defectId = isset($refs['defect_id']) ? (int) $refs['defect_id'] : null;
        $rows = [];
        foreach ($perUser as $userId => $own) {
            $data = $own + $params;
            $json = $data === [] ? '{}' : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > self::PARAMS_MAX) {
                throw new \InvalidArgumentException('params가 너무 큼');
            }
            $rows[] = [$userId, $kind, $projectId, $analysisId, $defectId, $json, $dedupeKey, $now];
        }
        $created = 0;
        if (in_array($kind, self::ADMIN_KINDS, true)) {
            // admin 전용 종류: 받는 사람이 admin이 아니면 뺀다(호출한 쪽 실수에도 일반 사용자에게 가지 않게)
            $admins = array_flip(array_map('intval', array_column($this->db->all(
                "SELECT id FROM users WHERE role = 'admin' AND id IN (SELECT value FROM json_each(?))", [json_encode(array_column($rows, 0))]), 'id')));
            $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($admins[$r[0]])));
            if ($rows === []) {
                return 0;
            }
        }
        $this->db->transaction(function (Db $db) use ($rows, $refresh, &$created): void {
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $values = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                $created += $db->execute(
                    $refresh
                        ? 'INSERT INTO notifications (user_id, kind, project_id, analysis_id, defect_id, params, dedupe_key, created_at) VALUES ' . $values
                            . ' ON CONFLICT (user_id, dedupe_key) DO UPDATE SET params = excluded.params, analysis_id = excluded.analysis_id, created_at = excluded.created_at'
                        : 'INSERT OR IGNORE INTO notifications (user_id, kind, project_id, analysis_id, defect_id, params, dedupe_key, created_at) VALUES ' . $values,
                    array_merge(...$chunk),
                );
            }
        }, write: true);
        return $created;
    }

    // ---- 발생 지점(이벤트) ----

    /** 분석 성공: 요청자에게 analysis_done, 담당 결함이 있는 사람들에게 analysis_summary */
    public function onAnalysisSucceeded(AnalysisSucceeded $e): void
    {
        try {
            $a = $this->db->one(
                'SELECT id, project_id, status, requested_by, run_number, total_count, new_count, resolved_count, reopened_count, no_change FROM analyses WHERE id = ?',
                [$e->analysisId],
            );
        } catch (\Throwable $ex) {
            $this->logger->warn('알림 생성 실패(본 동작에는 영향 없음)', ['kind' => 'analysis_done', 'analysis_id' => $e->analysisId, 'error' => $ex->getMessage()]);
            return;
        }
        if ($a === null || $a['status'] !== 'succeeded') {
            return;
        }
        $projectId = (int) $a['project_id'];
        $requester = $a['requested_by'] === null ? null : (int) $a['requested_by'];
        $noChange = (int) $a['no_change'] === 1;
        if ($noChange) {
            return; // "변경 없음"(P27)은 어떤 알림도 만들지 않는다(analysis_done·analysis_summary 없음)
        }
        $counts = [
            'new' => (int) ($a['new_count'] ?? 0), 'resolved' => (int) ($a['resolved_count'] ?? 0), 'reopened' => (int) ($a['reopened_count'] ?? 0),
        ];
        $run = $a['run_number'] === null ? null : (int) $a['run_number'];
        $refs = ['project_id' => $projectId, 'analysis_id' => (int) $a['id']];
        if ($requester !== null) {
            $this->emit('analysis_done', [$requester], $refs,
                ['run' => $run, 'total' => (int) ($a['total_count'] ?? 0)] + $counts + ['no_change' => $noChange], 'analysis_done:' . $a['id']);
        } else {
            // 요청자가 없는 분석(주기 분석 등): 받는 사람 정책은 이후 단계(P25~)가 정한다
            $this->logger->info('요청자 없는 분석: 분석 완료 알림 건너뜀', ['analysis_id' => (int) $a['id']]);
        }
        if ($noChange || array_sum($counts) === 0) {
            return;
        }
        try {
            $mine = [];
            foreach ($e->assignedChanges as $c) {
                $uid = (int) $c['assignee_id'];
                $mine[$uid] ??= ['mine_resolved' => 0, 'mine_reopened' => 0];
                $mine[$uid][$c['change'] === 'reopened' ? 'mine_reopened' : 'mine_resolved']++;
            }
            // 열린 담당 결함이 있는 사용자: (project_id, assignee_id, id) 인덱스로 담당자가 있는 행만 본다
            foreach ($this->db->all(
                "SELECT DISTINCT assignee_id FROM defects WHERE project_id = ? AND assignee_id IS NOT NULL AND status <> 'resolved'", [$projectId],
            ) as $r) {
                $mine[(int) $r['assignee_id']] ??= ['mine_resolved' => 0, 'mine_reopened' => 0];
            }
        } catch (\Throwable $ex) {
            $this->logger->warn('알림 생성 실패(본 동작에는 영향 없음)', ['kind' => 'analysis_summary', 'analysis_id' => (int) $a['id'], 'error' => $ex->getMessage()]);
            return;
        }
        if ($requester !== null) {
            unset($mine[$requester]); // 요청자는 analysis_done에 건수가 있다
        }
        if ($mine !== []) {
            $this->emit('analysis_summary', $mine, $refs, ['run' => $run] + $counts, 'analysis_summary:' . $a['id']);
        }
    }

    /** 분석 실패·취소·시간 초과: 요청자에게 analysis_failed(상태는 DB에서 다시 읽는다 — 이벤트가 겹쳐도 한 건) */
    public function onAnalysisEnded(int $analysisId): void
    {
        try {
            $a = $this->db->one('SELECT id, project_id, status, requested_by, failure_code FROM analyses WHERE id = ?', [$analysisId]);
        } catch (\Throwable $ex) {
            $this->logger->warn('알림 생성 실패(본 동작에는 영향 없음)', ['kind' => 'analysis_failed', 'analysis_id' => $analysisId, 'error' => $ex->getMessage()]);
            return;
        }
        if ($a === null || !in_array($a['status'], ['failed', 'cancelled'], true)) {
            return;
        }
        if ($a['requested_by'] === null) {
            $this->logger->info('요청자 없는 분석: 분석 실패 알림 건너뜀', ['analysis_id' => $analysisId]);
            return;
        }
        $this->emit('analysis_failed', [(int) $a['requested_by']], ['project_id' => (int) $a['project_id'], 'analysis_id' => $analysisId],
            ['status' => (string) $a['status'], 'code' => $a['failure_code'] === null ? null : (string) $a['failure_code']], 'analysis_failed:' . $analysisId);
    }

    /** 사람의 수동 변경 한 요청: 받는 사람별로 묶어 defect_assigned / defect_changed */
    public function onDefectsChanged(DefectsChanged $e): void
    {
        $assigned = []; // user → {ids, hist}
        $changed = [];  // user → {ids, hist, status, severity}
        foreach ($e->items as $it) {
            $diff = $it['diff'];
            $hist = $it['history'];
            if (isset($diff['assignee']) && $diff['assignee'][1] !== null) {
                $to = (int) $diff['assignee'][1];
                $assigned[$to]['ids'][] = (int) $it['id'];
                $assigned[$to]['hist'] = min($assigned[$to]['hist'] ?? PHP_INT_MAX, (int) ($hist['assignee'] ?? PHP_INT_MAX));
            }
            if ((isset($diff['status']) || isset($diff['severity'])) && $it['assignee'] !== null) {
                $to = (int) $it['assignee'];
                $changed[$to]['ids'][] = (int) $it['id'];
                foreach (['status', 'severity'] as $f) {
                    if (isset($diff[$f])) {
                        $changed[$to]['hist'] = min($changed[$to]['hist'] ?? PHP_INT_MAX, (int) ($hist[$f] ?? PHP_INT_MAX));
                        $changed[$to][$f][(string) $diff[$f][1]] = true;
                    }
                }
            }
        }
        $refs = ['project_id' => $e->projectId];
        foreach ($assigned as $user => $g) {
            $one = count($g['ids']) === 1;
            $this->emit('defect_assigned', [$user], $refs + ['defect_id' => $one ? $g['ids'][0] : null],
                ['count' => count($g['ids'])], 'defect_assigned:h' . $g['hist']);
        }
        foreach ($changed as $user => $g) {
            if (isset($assigned[$user])) {
                continue; // 같은 사건에서 지정 알림을 받는 사람에게는 변경 알림을 따로 보내지 않는다
            }
            $one = count($g['ids']) === 1;
            $params = ['count' => count($g['ids'])];
            foreach (['status', 'severity'] as $f) {
                // 바뀐 값이 하나로 같을 때만 넣는다(일괄 변경은 같은 값으로 바꾼다)
                $params[$f] = isset($g[$f]) && count($g[$f]) === 1 ? (string) array_key_first($g[$f]) : null;
            }
            $this->emit('defect_changed', [$user], $refs + ['defect_id' => $one ? $g['ids'][0] : null], $params, 'defect_changed:h' . $g['hist']);
        }
    }

    // ---- 종류별 설정(본인) ----

    /** @return array<string, bool> 종류 → 켜짐 */
    public function preferences(int $userId, bool $admin = true): array
    {
        $out = array_fill_keys(self::kindsFor($admin), true);
        foreach ($this->db->all('SELECT kind, enabled FROM notification_prefs WHERE user_id = ?', [$userId]) as $r) {
            if (isset($out[(string) $r['kind']])) {
                $out[(string) $r['kind']] = (int) $r['enabled'] === 1;
            }
        }
        return $out;
    }

    /**
     * 종류별 설정 바꾸기. 기본(켜짐)과 같은 값은 행을 지운다(차이만 저장). 감사 로그 없음(개인 설정).
     *
     * @param array<string, bool> $changes 검증된 종류 → 켜짐
     * @return array<string, bool>
     */
    /** 이 사용자에게 보이는 종류(admin 전용 종류는 admin에게만) @return list<string> */
    public static function kindsFor(bool $admin): array
    {
        return $admin ? self::KINDS : array_values(array_diff(self::KINDS, self::ADMIN_KINDS));
    }

    /** 활성 admin 전체(admin 알림의 받는 사람) @return list<int> */
    public function activeAdmins(): array
    {
        return array_map('intval', array_column($this->db->all("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id"), 'id'));
    }

    public function setPreferences(int $userId, array $changes, bool $admin = true): array
    {
        $now = SystemClock::iso($this->clock->now());
        $this->db->transaction(function (Db $db) use ($userId, $changes, $now): void {
            foreach ($changes as $kind => $on) {
                if ($on) {
                    $db->execute('DELETE FROM notification_prefs WHERE user_id = ? AND kind = ?', [$userId, $kind]);
                } else {
                    $db->execute('INSERT INTO notification_prefs (user_id, kind, enabled, updated_at) VALUES (?, ?, 0, ?)'
                        . ' ON CONFLICT (user_id, kind) DO UPDATE SET enabled = 0, updated_at = excluded.updated_at', [$userId, $kind, $now]);
                }
            }
        }, write: true);
        return $this->preferences($userId, $admin);
    }

    // ---- 보관 정리(워커) ----

    /**
     * 보관 정리 한 번(워커 주기 작업, 시험은 $now 주입). 기준은 app_settings(notifications.*), 바꾸면 다음 정리부터.
     *   1) 읽은 지 retain_read_days 넘은 알림  2) 만든 지 retain_unread_days 넘은 안 읽은 알림
     *   3) 사용자별 max_per_user 초과분: 읽은 것 중 오래된 것부터, 그래도 넘으면 안 읽은 것 중 오래된 것부터
     * 단계마다 최대 $batch건(남으면 다음 주기에 이어서). 감사 로그 없음(대량·자동).
     *
     * @return array{read:int, unread:int, over:int, more:bool}
     */
    public function prune(?\DateTimeImmutable $now = null, int $batch = 1000): array
    {
        $now ??= $this->clock->now();
        $this->settings->refresh();
        $readDays = $this->settings->int('notifications.retain_read_days');
        $unreadDays = $this->settings->int('notifications.retain_unread_days');
        $max = $this->settings->int('notifications.max_per_user');
        $readCut = SystemClock::iso($now->modify("-{$readDays} days"));
        $unreadCut = SystemClock::iso($now->modify("-{$unreadDays} days"));
        $out = ['read' => 0, 'unread' => 0, 'over' => 0, 'more' => false];
        $out['read'] = $this->db->execute(
            'DELETE FROM notifications WHERE id IN (SELECT id FROM notifications WHERE read_at IS NOT NULL AND read_at < ? LIMIT ?)', [$readCut, $batch]);
        $out['unread'] = $this->db->execute(
            'DELETE FROM notifications WHERE id IN (SELECT id FROM notifications WHERE read_at IS NULL AND created_at < ? LIMIT ?)', [$unreadCut, $batch]);
        $left = $batch;
        foreach ($this->db->all('SELECT user_id, COUNT(*) AS n FROM notifications GROUP BY user_id HAVING COUNT(*) > CAST(? AS INTEGER) LIMIT 100', [$max]) as $r) {
            if ($left <= 0) {
                $out['more'] = true;
                break;
            }
            $excess = min((int) $r['n'] - $max, $left);
            // 읽은 것(read_at NOT NULL) 먼저, 그 안에서 오래된 것부터
            $n = $this->db->execute(
                'DELETE FROM notifications WHERE id IN (SELECT id FROM notifications WHERE user_id = ?'
                . ' ORDER BY (read_at IS NULL), created_at, id LIMIT ?)',
                [(int) $r['user_id'], $excess],
            );
            $out['over'] += $n;
            $left -= $n;
        }
        $out['more'] = $out['more'] || $out['read'] >= $batch || $out['unread'] >= $batch || $left <= 0;
        return $out;
    }
}
