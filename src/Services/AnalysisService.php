<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Events\AnalysisCancelled;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\Queue\AnalysisQueue;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 분석 조회·취소 API(명세 5.2, 5.4, 19.8, P14). 프로젝트 접근 판정은 라우트의 RequireProjectAccess가 먼저 한다
 * (분석 id → 소유 프로젝트, 보이지 않으면 404 analysis_not_found).
 *
 * - 목록: 프로젝트별, 상태 필터(쉼표 구분), 최신순. 상세: 상태, 대기 순번(queued만), 요청자, 시각, 실패 코드·메시지.
 * - P19: 결과 요약(심각도별 건수, 비교 대상), 소요 시간, 설정 출처·무시된 설정, 실패 코드의 원인·조치·가이드 키(ErrorCatalog),
 *   업로드 경고 확인 기록, 이 사용자가 취소할 수 있는지(can_cancel)를 함께 돌려준다.
 * - 취소: queued·running만, 요청한 사용자 본인 또는 admin(주기 실행은 admin만). 다른 사용자 403, 끝난 분석 409.
 *   queued는 즉시 cancelled(워커의 선점은 status='queued' 조건이라 겹치지 않음), running은 cancel_requested=1 → 워커가 약 1초 안에
 *   프로세스 트리를 종료하고 cancelled로 기록한다. 결함과 비교 기준(직전 성공 분석)은 건드리지 않는다.
 */
final class AnalysisService
{
    private const STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'];

    /** 분석 + 요청자 + 비교 기준 분석 시각 + 업로드(파일 이름, 경고와 확인 시각) */
    private const SELECT = 'SELECT a.*, u.display_name AS requester_name, u.login_id AS requester_login,'
        . ' b.finished_at AS baseline_finished_at, b.run_number AS baseline_run, up.file_name AS upload_file_name, up.warnings AS upload_warnings, up.confirmed_at AS upload_confirmed_at'
        . ' FROM analyses a LEFT JOIN users u ON u.id = a.requested_by LEFT JOIN analyses b ON b.id = a.baseline_analysis_id'
        . ' LEFT JOIN uploads up ON up.id = a.upload_id';

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AnalysisQueue $queue,
        private readonly Logger $logger,
        // P24: 대기 중 취소를 알림에 알린다(커밋 뒤). null이면 보내지 않음(시험)
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    /** 분석 id(경로 문자열) → 소유 프로젝트 id. 형식이 틀리거나 없으면 null(라우트가 404) */
    public function projectIdOf(string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;
        }
        $pid = $this->db->value('SELECT project_id FROM analyses WHERE id = ?', [(int) $id]);
        return $pid === null ? null : (int) $pid;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(int $projectId, mixed $statusFilter, int $limit, int $offset, ?array $user = null, mixed $trigger = null, bool $excludeUnchanged = false): array
    {
        $statuses = [];
        if (is_string($statusFilter) && $statusFilter !== '') {
            foreach (explode(',', $statusFilter) as $s) {
                $s = trim($s);
                if (!in_array($s, self::STATUSES, true)) {
                    throw new ApiException(400, 'invalid_status_filter');
                }
                $statuses[$s] = true;
            }
        } elseif ($statusFilter !== null && $statusFilter !== '') {
            throw new ApiException(400, 'invalid_status_filter');
        }
        // 상태 필터는 허용 목록에서 고른 값만 JSON 배열 매개변수로 넘긴다(SQL 문자열을 이어 붙이지 않음)
        $where = 'a.project_id = ? AND a.status IN (SELECT value FROM json_each(?))';
        $params = [$projectId, json_encode($statuses === [] ? self::STATUSES : array_keys($statuses))];
        // P26: 실행 주체 필터(user = 사용자 업로드·지금 가져와 분석, schedule = 주기 분석)
        if ($trigger !== null && $trigger !== '') {
            if (!in_array($trigger, ['user', 'schedule'], true)) {
                throw new ApiException(400, 'invalid_trigger_filter');
            }
            $where .= ' AND a.trigger_kind = ?';
            $params[] = $trigger;
        }
        if ($excludeUnchanged) {
            $where .= ' AND a.no_change = 0'; // P27C: 프로젝트 화면 "최근 분석" 카드 — 실제 분석만(실패가 변경 없음 확인에 가려지지 않게)
        }
        $total = (int) $this->db->value('SELECT COUNT(*) FROM analyses a WHERE ' . $where, $params);
        $rows = $this->db->all(
            self::SELECT . ' WHERE ' . $where . ' ORDER BY a.id DESC LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );
        return [array_map(fn (array $r): array => $this->format($r, $user), $rows), $total];
    }

    /**
     * @param array<string, mixed>|null $user 있으면 can_cancel을 그 사용자 기준으로
     * @return array<string, mixed>
     */
    public function get(int $id, ?array $user = null): array
    {
        $r = $this->db->one(self::SELECT . ' WHERE a.id = ?', [$id]);
        if ($r === null) {
            throw new ApiException(404, 'analysis_not_found');
        }
        return $this->format($r, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function cancel(array $user, int $id): array
    {
        $row = $this->db->one('SELECT id, project_id, status, requested_by FROM analyses WHERE id = ?', [$id]);
        if ($row === null) {
            throw new ApiException(404, 'analysis_not_found');
        }
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (!$isAdmin && ($row['requested_by'] === null || (int) $row['requested_by'] !== (int) $user['id'])) {
            throw new ApiException(403, 'analysis_cancel_forbidden');
        }
        $now = SystemClock::iso($this->clock->now());
        $done = $this->db->transaction(function (Db $db) use ($id, $now): string {
            if ($db->execute("UPDATE analyses SET status = 'cancelled', cancel_requested = 1, finished_at = ? WHERE id = ? AND status = 'queued'", [$now, $id]) === 1) {
                return 'cancelled';
            }
            if ($db->execute("UPDATE analyses SET cancel_requested = 1 WHERE id = ? AND status = 'running'", [$id]) === 1) {
                return 'requested';
            }
            return 'finished';
        });
        if ($done === 'finished') {
            throw new ApiException(409, 'analysis_not_cancellable');
        }
        $this->logger->info($done === 'cancelled' ? '대기 중 분석 취소' : '실행 중 분석 취소 요청', [
            'analysis_id' => $id, 'project_id' => (int) $row['project_id'], 'by' => (int) $user['id'],
        ]);
        if ($done === 'cancelled') {
            $this->events?->dispatch(new AnalysisCancelled($id, (int) $row['project_id']));
        }
        return $this->get($id, $user);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    /** 변경 없음 행의 기준 분석(id·Run 번호·종료 시각). 021 전 DB·기준이 지워졌으면 null */
    private function unchangedBase(array $r): ?array
    {
        $id = $r['unchanged_base_analysis_id'] ?? null;
        if ((int) ($r['no_change'] ?? 0) !== 1 || $id === null) {
            return null;
        }
        $b = $this->db->one('SELECT id, run_number, finished_at, run_detail_deleted_at FROM analyses WHERE id = ?', [(int) $id]);
        return $b === null ? null : ['analysis_id' => (int) $b['id'], 'run' => $b['run_number'] === null ? null : (int) $b['run_number'],
            'finished_at' => $b['finished_at'], 'detail_deleted' => $b['run_detail_deleted_at'] !== null];
    }

    private function format(array $r, ?array $user = null): array
    {
        $status = (string) $r['status'];
        $active = $status === 'queued' || $status === 'running';
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $mine = $user !== null && $r['requested_by'] !== null && (int) $r['requested_by'] === (int) $user['id'];
        $duration = null;
        if ($r['started_at'] !== null) {
            $end = $r['finished_at'] !== null ? (int) strtotime((string) $r['finished_at']) : $this->clock->now()->getTimestamp();
            $duration = max(0, $end - (int) strtotime((string) $r['started_at']));
        }
        $severity = json_decode((string) ($r['severity_counts'] ?? ''), true);
        $ignored = json_decode((string) ($r['ignored_configs'] ?? ''), true);
        $warnings = json_decode((string) ($r['upload_warnings'] ?? ''), true);
        return [
            'id' => (int) $r['id'],
            // P20R: 성공한 분석의 Run 번호(실패·취소·대기·실행 중·되채움 전은 null)
            'run' => isset($r['run_number']) ? (int) $r['run_number'] : null,
            'project_id' => (int) $r['project_id'],
            'status' => $status,
            'queue_position' => $status === 'queued' ? $this->queue->position((int) $r['id']) : null,
            'cancel_requested' => (int) $r['cancel_requested'] === 1,
            'source_kind' => (string) $r['source_kind'],
            'source_ref' => $r['source_ref'],
            'source_revision' => $r['source_revision'],
            // P25: 가져온 시각·커밋 링크(허용 목록의 GitHub 호환 호스트만, 사용자 정보 없는 기록 주소로 만든다)
            'fetched_at' => $r['fetched_at'] ?? null,
            // P26B: 저장소 가져오기 경고(심볼릭 링크 건너뜀 등) — [{code, count, paths}]
            'source_warnings' => is_array($sw = json_decode((string) ($r['source_warnings'] ?? ''), true)) ? $sw : [],
            'source_link' => (new \PJGuardian\Services\Repo\RepoLinks($this->db))->commitUrl($r['source_url'] ?? null, $r['source_revision']),
            'trigger_kind' => (string) $r['trigger_kind'],
            // P26: 주기 분석 가져오기 재시도 대기(queued) — 몇 번째 시도를 기다리는지·언제·직전 실패 코드. 최종 실패면 시도 횟수
            'retry' => (int) ($r['retry_count'] ?? 0) === 0 ? null : [
                'attempts' => (int) $r['retry_count'] + ($status === 'failed' ? 1 : 0),
                'next_attempt' => $status === 'queued' ? (int) $r['retry_count'] + 1 : null,
                'after' => $status === 'queued' ? ($r['retry_after'] ?? null) : null,
                'code' => $r['retry_code'] ?? null,
                'title' => isset($r['retry_code']) ? (ErrorCatalog::describe('analysis', (string) $r['retry_code'])['title'] ?? null) : null,
            ],
            'requested_by' => $r['requested_by'] === null ? null : [
                'id' => (int) $r['requested_by'], 'login_id' => $r['requester_login'], 'display_name' => $r['requester_name'],
            ],
            'tool' => (string) $r['tool'],
            'tool_version' => $r['tool_version'],
            'upload_id' => $r['upload_id'],
            'queued_at' => $r['queued_at'],
            'started_at' => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'failure_code' => $r['failure_code'],
            // P27D: 저장된 문장(작성 시점 언어) 대신 코드로 요청 언어 문구를 만든다(DB 값은 그대로 — 로그·이력용)
            'failure_message' => $r['failure_code'] === null ? null : AnalysisQueue::failureMessage((string) $r['failure_code']),
            'counts' => $status === 'succeeded' && $r['total_count'] !== null ? [
                'total' => (int) $r['total_count'], 'new' => (int) $r['new_count'], 'resolved' => (int) $r['resolved_count'],
                'reopened' => (int) $r['reopened_count'], 'unchanged' => (int) $r['unchanged_count'],
            ] : null,
            // ---- P19 ----
            'failure' => $r['failure_code'] === null ? null : ErrorCatalog::describe('analysis', (string) $r['failure_code']),
            'severity_counts' => $status === 'succeeded' && is_array($severity) ? array_map('intval', $severity) : null,
            // 비교 대상 = 직전 성공 분석(명세 8). 성공했는데 null이면 최초 분석("비교 대상 없음")
            'baseline' => $r['baseline_analysis_id'] === null ? null : ['id' => (int) $r['baseline_analysis_id'], 'finished_at' => $r['baseline_finished_at'],
                'run' => isset($r['baseline_run']) ? (int) $r['baseline_run'] : null],
            'first_analysis' => $status === 'succeeded' && $r['baseline_analysis_id'] === null && (int) ($r['no_change'] ?? 0) === 0,
            // ---- P27 성공(변경 없음): status는 succeeded 그대로(기존 소비자 호환), 플래그·확인 횟수·마지막 확인·기준(그 리비전을 실제로 분석한 Run) ----
            'unchanged' => (int) ($r['no_change'] ?? 0) === 1,
            'unchanged_check_count' => (int) ($r['no_change'] ?? 0) === 1 ? max(1, (int) ($r['unchanged_check_count'] ?? 1)) : null,
            'unchanged_last_checked_at' => (int) ($r['no_change'] ?? 0) === 1 ? ($r['unchanged_last_checked_at'] ?? $r['finished_at']) : null,
            'unchanged_base' => $this->unchangedBase($r),
            'force_analysis' => (int) ($r['force_analysis'] ?? 0) === 1,
            'duration_seconds' => $duration,
            'config_source' => $r['config_source'],
            // 무시·변환한 설정(명세 5.3 "설정 무시됨"): [{file, reason(eslint_config_ignored|ruff_nested_config|ruff_config_rejected|eslintignore_converted), detail?, count?}]
            'ignored_configs' => is_array($ignored) ? array_values($ignored) : [],
            'upload' => $r['upload_file_name'] === null ? null : [
                'file_name' => (string) $r['upload_file_name'],
                // 사용자가 확인하고 진행한 경고(no_target, top_folder_missing, top_folder_kept)
                'warnings' => is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : [],
                'confirmed_at' => $r['upload_confirmed_at'],
            ],
            // 취소 버튼 표시(서버가 cancel에서 다시 검사): queued·running이고 취소 요청 전이며 요청자 본인 또는 admin
            'can_cancel' => $active && (int) $r['cancel_requested'] === 0 && ($isAdmin || $mine),
        ];
    }
}
