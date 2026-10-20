<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

use PJGuardian\Services\Events\AnalysisCancelled;
use PJGuardian\Services\Events\AnalysisFailed;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\Notifications\NotificationService;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 주기 분석 결과 반영(P26). 분석 종료 이벤트(성공·실패·취소, 결과가 커밋된 뒤)를 구독해 project_schedules의 요약을 고치고
 * 관리자 알림(schedule_failed)을 만든다. 본 동작(분석 기록)은 이미 끝났으므로 여기서 난 오류는 경고 로그만 남긴다.
 *
 * - 성공: 저장소 출처(git·perforce) 분석이면 실행 주체와 관계없이 연속 실패 0("지금 가져와 분석"으로 성공해도 0). 주기 분석이면 마지막 결과 = 성공.
 * - 실패: 주기 분석만 센다(연속 실패 + 1, 마지막 오류). 재시도(ScheduleRetry) 중에는 분석이 끝나지 않았으므로 이벤트가 없고,
 *   최종 실패 1건만 1회로 센다. 같은 분석의 이벤트가 두 번 와도(last_outcome_analysis_id) 한 번만.
 * - 취소: 주기 분석이면 마지막 결과 = 취소(실패로 세지 않음).
 * - 알림: 주기 분석 실패 → 활성 admin 전체에 schedule_failed. 중복 키 = schedule_failed:{프로젝트}:{연속 실패의 첫 분석} — 연속 실패는 새로 쌓지 않고
 *   그 한 건의 횟수·오류·다음 예정·시각만 갱신(읽음 상태 유지). 성공하면 연속이 끊겨 다음 실패는 새 알림.
 *   건너뜀(디스크 차단·연결 문제)은 skipped()로 도래마다 한 건(schedule_skipped:{프로젝트}:{도래}), 실패 횟수로는 세지 않는다.
 * - "변경 없음"(P27·P27C)은 **중립**: 연속 실패를 늘리지도 끊지도 않고 마지막 결과·알림도 바꾸지 않는다(이벤트가 없고 여기를 부르지 않는다).
 *   실제 분석이 성공해야 연속이 끊긴다 — 대시보드 "실패 프로젝트"와 같은 규칙(사용자 결정 2026-10-08).
 * - 완료 알림은 만들지 않는다(명세 18.5). 요청자가 없으므로 analysis_done·analysis_failed도 없다(P24). analysis_summary는 P24 그대로.
 */
final class ScheduleOutcomes
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly NotificationService $notifications,
    ) {
    }

    public function subscribe(EventDispatcher $events): void
    {
        $events->subscribe(AnalysisSucceeded::class, fn (object $e) => $e instanceof AnalysisSucceeded ? $this->onEnded($e->analysisId) : null);
        $events->subscribe(AnalysisFailed::class, fn (object $e) => $e instanceof AnalysisFailed ? $this->onEnded($e->analysisId) : null);
        $events->subscribe(AnalysisCancelled::class, fn (object $e) => $e instanceof AnalysisCancelled ? $this->onEnded($e->analysisId) : null);
    }

    public function onEnded(int $analysisId): void
    {
        try {
            $this->apply($analysisId);
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException && (str_contains($e->getMessage(), 'no such table: project_schedules') || str_contains($e->getMessage(), 'no such column: retry_count'))) {
                return; // 019 전 DB
            }
            $this->logger->warn('주기 분석 결과 반영 실패(분석 결과에는 영향 없음)', ['analysis_id' => $analysisId, 'error' => $e->getMessage()]);
        }
    }

    private function apply(int $analysisId): void
    {
        $a = $this->db->one('SELECT id, project_id, status, source_kind, trigger_kind, failure_code, retry_count, finished_at FROM analyses WHERE id = ?', [$analysisId]);
        if ($a === null || !in_array($a['source_kind'], ['git', 'perforce'], true) || !in_array($a['status'], ['succeeded', 'failed', 'cancelled'], true)) {
            return;
        }
        $pid = (int) $a['project_id'];
        $schedule = $a['trigger_kind'] === 'schedule';
        $now = SystemClock::iso($this->clock->now());
        $at = (string) ($a['finished_at'] ?? $now);
        $guard = ' AND (last_outcome_analysis_id IS NULL OR last_outcome_analysis_id <> ?)';
        if ($a['status'] === 'succeeded') {
            $this->db->execute(
                'UPDATE project_schedules SET consecutive_failures = 0, first_failure_analysis_id = NULL, last_outcome_analysis_id = ?'
                . ($schedule ? ", last_result = 'succeeded', last_result_at = ?" : '') . ' WHERE project_id = ?' . $guard,
                $schedule ? [$analysisId, $at, $pid, $analysisId] : [$analysisId, $pid, $analysisId],
            );
            return;
        }
        if (!$schedule) {
            return; // 사용자가 누른 가져오기의 실패·취소는 주기 요약에 넣지 않는다(요청자에게 P24 analysis_failed)
        }
        if ($a['status'] === 'cancelled') {
            $this->db->execute("UPDATE project_schedules SET last_result = 'cancelled', last_result_at = ?, last_outcome_analysis_id = ? WHERE project_id = ?" . $guard,
                [$at, $analysisId, $pid, $analysisId]);
            return;
        }
        $code = (string) ($a['failure_code'] ?? 'unknown');
        $counted = $this->db->execute(
            "UPDATE project_schedules SET consecutive_failures = consecutive_failures + 1, first_failure_analysis_id = COALESCE(first_failure_analysis_id, ?),"
            . " last_result = 'failed', last_result_at = ?, last_error_code = ?, last_error_at = ?, last_outcome_analysis_id = ? WHERE project_id = ?" . $guard,
            [$analysisId, $at, $code, $at, $analysisId, $pid, $analysisId],
        );
        $s = $this->db->one('SELECT consecutive_failures, first_failure_analysis_id, next_run_at, enabled FROM project_schedules WHERE project_id = ?', [$pid]);
        if ($s !== null && $counted === 0) {
            return; // 이미 반영한 분석(이벤트 중복)
        }
        $first = $s !== null && $s['first_failure_analysis_id'] !== null ? (int) $s['first_failure_analysis_id'] : $analysisId;
        $this->notifications->emit('schedule_failed', $this->notifications->activeAdmins(), ['project_id' => $pid, 'analysis_id' => $analysisId], [
            'code' => $code,
            'count' => $s === null ? 1 : (int) $s['consecutive_failures'],
            'attempts' => (int) ($a['retry_count'] ?? 0) + 1,
            'next_run_at' => $s !== null && (int) $s['enabled'] === 1 ? $s['next_run_at'] : null,
            'at' => $at,
        ], 'schedule_failed:' . $pid . ':' . $first, refresh: true);
    }

    /** 주기 건너뜀(디스크 차단·연결 문제) → 관리자 알림(도래마다 한 건, 실패 횟수로는 세지 않음) */
    public function skipped(int $projectId, string $result, string $code, string $dueAt, string $nextRunAt): void
    {
        // P27B: 자격 증명을 풀 수 없음(키 없이 복원·키 분실)은 다시 입력할 때까지 매번 같으므로 프로젝트당 한 번만 알린다(도래마다 알리지 않음)
        $dedupe = $code === 'repo_secret_unreadable' ? 'schedule_skipped:' . $projectId . ':repo_secret_unreadable' : 'schedule_skipped:' . $projectId . ':' . $dueAt;
        $this->notifications->emit('schedule_failed', $this->notifications->activeAdmins(), ['project_id' => $projectId], [
            'code' => $code, 'skipped' => $result, 'next_run_at' => $nextRunAt, 'at' => $dueAt,
        ], $dedupe);
    }
}
