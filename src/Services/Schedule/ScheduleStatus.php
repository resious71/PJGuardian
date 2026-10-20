<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

use PJGuardian\Services\Queue\WorkerState;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;

/**
 * 주기 분석 현황(P26, 명세 18.5 "관리자 시스템 모니터링 화면에 주기 분석의 다음 실행 시각과 최근 실패(다음 주기 재수행 예정 포함)").
 * 화면과 분리한 조회 서비스 — 지금은 시스템 관리 > "주기 분석" 탭이 쓰고, 시스템 모니터링 화면(19장)이 생기면 그대로 옮긴다.
 *
 * - 일정 표: 주기를 한 번이라도 저장한 프로젝트(사용 중 먼저, 다음 실행 시각 순) — 주기, 실행 시각, 다음 실행, 마지막 결과, 연속 실패, 마지막 오류, 건너뜀.
 * - 최근 실패: 최근 days일의 주기 분석 failed(시도 횟수·오류·그 프로젝트의 다음 실행 = 재수행 예정) + 재시도 대기 중인 분석.
 * - 최근 건너뜀: schedule_ticks의 skipped_* 최신순.
 * - 스케줄러: 워커 생존 신호의 scheduler(마지막 틱 시각·결과). 마지막 틱이 tick_seconds × 5보다 오래되면 delayed.
 */
final class ScheduleStatus
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly WorkerState $worker,
        private readonly int $tickSeconds,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(int $failureDays = 30, int $limit = 50): array
    {
        $since = ScheduleCalc::iso($this->clock->now()->modify('-' . $failureDays . ' days'));
        $schedules = array_map(static fn (array $r): array => [
            'project' => ['id' => (int) $r['project_id'], 'name' => (string) $r['name']],
            'enabled' => (int) $r['enabled'] === 1,
            'interval_days' => (int) $r['interval_days'],
            'run_time' => (string) $r['run_time'],
            'next_run_at' => $r['next_run_at'],
            'last_result' => $r['last_result'],
            'last_result_at' => $r['last_result_at'],
            'last_analysis_id' => $r['last_analysis_id'] === null ? null : (int) $r['last_analysis_id'],
            'consecutive_failures' => (int) $r['consecutive_failures'],
            'last_error' => $r['last_error_code'] === null ? null : ['code' => (string) $r['last_error_code'], 'at' => $r['last_error_at'],
                'title' => ErrorCatalog::describe('analysis', (string) $r['last_error_code'])['title'] ?? null],
            'skip_count' => (int) $r['skip_count'],
            'last_skip' => $r['last_skip_reason'] === null ? null : ['reason' => (string) $r['last_skip_reason'], 'at' => $r['last_skip_at']],
            'source_kind' => $r['kind'],
        ], $this->db->all(
            'SELECT s.*, p.name, c.kind FROM project_schedules s JOIN projects p ON p.id = s.project_id'
            . ' LEFT JOIN project_repo_connections c ON c.project_id = s.project_id'
            . ' ORDER BY s.enabled DESC, s.next_run_at IS NULL, s.next_run_at, p.name LIMIT ?',
            [$limit * 20],
        ));
        $failures = array_map(static fn (array $r): array => [
            'analysis_id' => (int) $r['id'],
            'project' => ['id' => (int) $r['project_id'], 'name' => (string) $r['name']],
            'status' => (string) $r['status'],
            'code' => $r['status'] === 'failed' ? $r['failure_code'] : $r['retry_code'],
            'title' => ($c = $r['status'] === 'failed' ? $r['failure_code'] : $r['retry_code']) === null ? null : (ErrorCatalog::describe('analysis', (string) $c)['title'] ?? null),
            'attempts' => (int) $r['retry_count'] + ($r['status'] === 'failed' ? 1 : 0),
            'at' => $r['finished_at'] ?? $r['queued_at'],
            'retry_after' => $r['status'] === 'queued' ? $r['retry_after'] : null,
            // 다음 주기 재수행 예정(주기가 켜져 있으면)
            'next_run_at' => (int) ($r['enabled'] ?? 0) === 1 ? $r['next_run_at'] : null,
        ], $this->db->all(
            "SELECT a.id, a.project_id, a.status, a.failure_code, a.retry_code, a.retry_count, a.retry_after, a.finished_at, a.queued_at, p.name, s.enabled, s.next_run_at"
            . ' FROM analyses a JOIN projects p ON p.id = a.project_id LEFT JOIN project_schedules s ON s.project_id = a.project_id'
            . " WHERE a.trigger_kind = 'schedule' AND ((a.status = 'failed' AND a.finished_at >= ?) OR (a.status = 'queued' AND a.retry_count > 0))"
            . ' ORDER BY a.id DESC LIMIT ?',
            [$since, $limit],
        ));
        $skips = array_map(static fn (array $r): array => [
            'project' => ['id' => (int) $r['project_id'], 'name' => (string) $r['name']],
            'due_at' => $r['due_at'], 'result' => $r['result'], 'detail' => $r['detail'], 'at' => $r['created_at'],
        ], $this->db->all(
            "SELECT t.project_id, t.due_at, t.result, t.detail, t.created_at, p.name FROM schedule_ticks t JOIN projects p ON p.id = t.project_id"
            . " WHERE t.result <> 'queued' ORDER BY t.created_at DESC, t.id DESC LIMIT ?",
            [$limit],
        ));
        return [
            'scheduler' => $this->scheduler(),
            'schedules' => $schedules,
            'failures' => $failures,
            'skips' => $skips,
            'failure_days' => $failureDays,
        ];
    }

    /** @return array{last_tick_at: ?string, delayed: bool, worker_running: bool, tick_seconds: int, last: ?array} */
    public function scheduler(): array
    {
        $hb = $this->worker->read();
        $sch = is_array($hb['scheduler'] ?? null) ? $hb['scheduler'] : null;
        $since = $this->worker->secondsSinceBeat();
        $running = $hb !== null && ($hb['state'] ?? null) !== 'stopped' && $since !== null && $since < 300;
        $last = is_string($sch['last_tick_at'] ?? null) ? $sch['last_tick_at'] : null;
        $age = $last === null ? null : $this->clock->now()->getTimestamp() - (int) strtotime($last);
        return [
            'last_tick_at' => $last,
            // 워커가 돌고 있는데 마지막 틱이 tick_seconds × 5보다 오래됨(또는 틱 기록이 없음) → 지연
            'delayed' => $running && ($age === null || $age > $this->tickSeconds * 5),
            'worker_running' => $running,
            'tick_seconds' => $this->tickSeconds,
            'last' => $sch === null ? null : array_intersect_key($sch, array_flip(['due', 'queued', 'skipped', 'raced', 'error', 'ms'])),
        ];
    }
}
