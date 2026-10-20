<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Repo\RepoConnections;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Repo\RepoService;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;

/**
 * 스케줄러(P26, 명세 18.2) — 워커의 주기 작업 하나(새 프로세스·서비스 없음). 워커가 scheduler.tick_seconds(기본 60초)마다 tick()을 부른다.
 *
 * 한 번의 틱: next_run_at <= 지금인 사용 중 일정을 오래된 순으로 최대 scheduler.max_per_tick개(부분 인덱스 범위 조회). 프로젝트마다:
 *  1. (트랜잭션 밖) 연결·허용 호스트·자격 증명 확인, 디스크 확인(신규 가져오기 차단 기준 90%·여유 공간) — 느릴 수 있는 확인은 쓰기 잠금 밖에서.
 *  2. (쓰기 트랜잭션 하나) 일정을 다시 읽어 아직 도래 상태인지 확인 → 가장 최근 도래(밀렸으면 1회만, ScheduleCalc::due) 계산 →
 *     결과 결정: 연결 문제 skipped_no_connection / queued·running 있음 skipped_busy / 디스크 skipped_disk / 그 밖은
 *     ScheduledAnalysisLauncher::launch(분석 행 등록) → schedule_ticks 기록((project_id, due_at) 유일) + next_run_at·요약 갱신.
 *     같은 도래를 두 워커가 동시에 처리해도 BEGIN IMMEDIATE + 다시 읽기 + 유일 제약으로 한 번만 등록된다(진 쪽은 아무것도 바꾸지 않음).
 *  P27: 1에서 연결·디스크 문제가 없고 바쁘지 않으면 UnchangedSourceCheck::evaluate(원격 최신 리비전 조회, 연결 테스트와 같은 호출·타임아웃)도
 *     트랜잭션 밖에서 하고 결과를 launch에 넘긴다. 변경 없음이면 결과 unchanged(분석 등록 없음, "변경 없음" 행 기록·갱신 — 연속 실패 횟수에는 중립, P27C).
 *     조회 실패·판단 오류는 변경 없음이 아니라 평소처럼 등록(가져오기가 같은 오류로 재시도·실패 기록).
 *  3. 건너뜀 중 디스크·연결 문제는 관리자에게 알린다(ScheduleOutcomes::skipped, 실패 횟수로는 세지 않음). 바쁨(이미 실행 중)은 알리지 않는다.
 * 오류는 그 틱만 멈추고(남은 프로젝트는 다음 틱) 경고 로그 — 워커를 죽이지 않는다.
 */
final class ScheduleTicker
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly RepoConnections $connections,
        private readonly ScheduleService $schedules,
        private readonly ScheduledAnalysisLauncher $launcher,
        private readonly DiskGuard $disk,
        private readonly ScheduleOutcomes $outcomes,
        private readonly string $workRoot,
        private readonly int $extractMax,
        private readonly int $maxPerTick,
        private readonly ?\PJGuardian\Services\Repo\UnchangedSourceCheck $unchanged = null,
    ) {
    }

    /** @return array{due: int, queued: int, unchanged: int, skipped: int, raced: int, error: ?string} */
    public function tick(): array
    {
        $now = $this->clock->now();
        $nowIso = ScheduleCalc::iso($now);
        $stats = ['due' => 0, 'queued' => 0, 'unchanged' => 0, 'skipped' => 0, 'raced' => 0, 'error' => null];
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT project_id FROM project_schedules WHERE enabled = 1 AND next_run_at <= ? ORDER BY next_run_at, project_id LIMIT ?',
            [$nowIso, $this->maxPerTick],
        ), 'project_id'));
        $stats['due'] = count($ids);
        foreach ($ids as $pid) {
            try {
                $r = $this->processDue($pid, $now);
            } catch (\Throwable $e) {
                // DB 잠금 등: 이 틱은 여기서 멈춘다(도래 상태 그대로 → 다음 틱에 다시)
                $stats['error'] = 'tick_failed';
                $this->logger->warn('스케줄러 틱 오류(다음 틱에 다시)', ['project_id' => $pid, 'error' => $e->getMessage()]);
                break;
            }
            if ($r === null) {
                $stats['raced']++;
            } elseif ($r['result'] === 'queued' || $r['result'] === 'unchanged') {
                $stats[$r['result']]++;
            } else {
                $stats['skipped']++;
            }
        }
        return $stats;
    }

    /**
     * 도래한 프로젝트 하나. 다른 워커가 먼저 처리했으면(또는 그사이 꺼졌으면) null.
     *
     * @return array{result: string, detail: ?string, analysis_id: ?int, due_at: string, next_run_at: string}|null
     */
    public function processDue(int $projectId, \DateTimeImmutable $now): ?array
    {
        $nowIso = ScheduleCalc::iso($now);
        // 1) 쓰기 잠금 밖의 확인
        $conn = $this->connections->row($projectId);
        $connProblem = $conn === null ? 'repo_not_configured' : $this->schedules->blocker($projectId, $conn);
        $diskProblem = null;
        if ($connProblem === null) {
            try {
                $this->disk->check($this->workRoot, 2 * $this->extractMax);
            } catch (ApiException $e) {
                $diskProblem = $e->errorCode;
            }
        }
        // P27: 변경 없음 판단(원격 호출이라 쓰기 잠금 밖에서). 등록하지 않을 상황(연결·디스크 문제, 바쁨)이면 조회하지 않는다
        $hint = [];
        if ($this->unchanged !== null && $connProblem === null && $diskProblem === null && !RepoService::busy($this->db, $projectId)
            && $this->db->value('SELECT 1 FROM project_schedules WHERE project_id = ? AND enabled = 1 AND next_run_at <= ?', [$projectId, $nowIso]) !== null) {
            $hint = $this->unchanged->evaluate($projectId);
        }
        // 2) 한 트랜잭션: 다시 읽기 → 도래 계산 → 결과 → 기록·갱신
        $out = $this->db->transaction(function (Db $db) use ($projectId, $now, $nowIso, $conn, $connProblem, $diskProblem, $hint): ?array {
            $s = $db->one('SELECT enabled, interval_days, next_run_at FROM project_schedules WHERE project_id = ?', [$projectId]);
            if ($s === null || (int) $s['enabled'] !== 1 || $s['next_run_at'] === null) {
                return null;
            }
            $d = ScheduleCalc::due(ScheduleCalc::parseIso((string) $s['next_run_at']), (int) $s['interval_days'], $now);
            if ($d === null) {
                return null; // 다른 워커가 이미 옮겼다
            }
            $dueAt = ScheduleCalc::iso($d['due']);
            $next = ScheduleCalc::iso($d['next']);
            if ($db->value('SELECT 1 FROM schedule_ticks WHERE project_id = ? AND due_at = ?', [$projectId, $dueAt]) !== null) {
                // 같은 도래를 이미 기록(이론상 다시 읽기로 걸러지지만 안전하게): 다음 시각만 맞춘다
                $db->execute('UPDATE project_schedules SET next_run_at = ? WHERE project_id = ? AND next_run_at = ?', [$next, $projectId, $s['next_run_at']]);
                return null;
            }
            if ($connProblem !== null) {
                $res = ['result' => 'skipped_no_connection', 'analysis_id' => null, 'detail' => $connProblem];
            } elseif (RepoService::busy($db, $projectId)) {
                $res = ['result' => 'skipped_busy', 'analysis_id' => null, 'detail' => null];
            } elseif ($diskProblem !== null) {
                $res = ['result' => 'skipped_disk', 'analysis_id' => null, 'detail' => $diskProblem];
            } else {
                $res = $this->launcher->launch($db, $projectId, (array) $conn, $dueAt, $nowIso, $hint);
            }
            $db->execute('INSERT INTO schedule_ticks (project_id, due_at, result, detail, analysis_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$projectId, $dueAt, $res['result'], $res['detail'], $res['analysis_id'], $nowIso]);
            if ($res['result'] === 'queued' || $res['result'] === 'unchanged') {
                $db->execute('UPDATE project_schedules SET next_run_at = ?, last_due_at = ?, last_analysis_id = ? WHERE project_id = ?',
                    [$next, $dueAt, $res['analysis_id'], $projectId]);
            } else {
                $db->execute('UPDATE project_schedules SET next_run_at = ?, last_due_at = ?, skip_count = skip_count + 1, last_skip_reason = ?, last_skip_at = ? WHERE project_id = ?',
                    [$next, $dueAt, $res['detail'] ?? $res['result'], $nowIso, $projectId]);
            }
            return $res + ['due_at' => $dueAt, 'next_run_at' => $next, 'missed' => $d['missed']];
        }, write: true);
        if ($out === null) {
            return null;
        }
        $ctx = ['project_id' => $projectId, 'due_at' => $out['due_at'], 'result' => $out['result'], 'detail' => $out['detail'],
            'analysis_id' => $out['analysis_id'], 'next_run_at' => $out['next_run_at'], 'missed' => $out['missed'], 'reason' => $out['reason'] ?? null];
        $this->logger->info(match ($out['result']) { 'queued' => '주기 분석 등록', 'unchanged' => '주기 분석 생략(변경 없음)', default => '주기 분석 건너뜀' }, $ctx);
        if (in_array($out['result'], ['skipped_disk', 'skipped_no_connection'], true)) {
            $this->outcomes->skipped($projectId, (string) $out['result'], (string) $out['detail'], $out['due_at'], $out['next_run_at']);
        }
        unset($out['missed'], $out['reason']);
        return $out;
    }
}
