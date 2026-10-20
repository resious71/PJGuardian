<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Services\Events\AssignedDefectsChanged;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\ProjectService;
use PJGuardian\Services\Queue\AnalysisQueue;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\WalCheckpoint;

/**
 * 성공한 도구 실행 결과를 결함으로 반영한다(P16). 분석 자식 프로세스(tools/run_analysis.php) 안에서, 작업 디렉토리가 있을 때 부른다.
 *
 *   1) 결과 NDJSON → 시그니처(DefectIngest, 임시 표) — 쓰기 잠금 없음
 *   2) 분석 범위(도구가 분석한 파일) 읽기, 취소 요청 확인
 *   3) 스냅샷 zip 만들기(SnapshotService::create) — 쓰기 잠금 없음
 *   4) 하나의 쓰기 트랜잭션: 결함 판정·반영 + 파일 목록·스냅샷 행 + 분석 succeeded(조건부)  (DefectStore)
 *   5) 커밋 뒤: 결과·범위 파일 삭제(실패는 로그만), 이벤트, 오래된 스냅샷 정리(실패는 로그만)
 *   P19C 결함 수: 1 뒤(쓰기 전) 이번에 감지한 결함만으로 [limits] project_defect_max를 넘으면 DefectLimitExceededException(반영 안 함).
 *     5에서 현재 결함(해결 아님)이 project_defect_warn을 넘으면 활성 admin에게 defect_count_warn(프로젝트당 한 건, 건수 갱신).
 * 4가 실패하면 트랜잭션 전체가 되돌아가 결함은 하나도 바뀌지 않는다. 만든 스냅샷은 지운다. 호출자가 분석을 failed로 기록한다.
 */
final class AnalysisFinalizer
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly ProjectService $projects,
        private readonly AnalysisQueue $queue,
        private readonly SnapshotService $snapshots,
        private readonly EventDispatcher $events,
        // 규칙 기본 심각도(P21S). null이면 신규 결함은 not_defined(설정 severity.auto_assign = false)
        private readonly ?SeverityMap $severity = null,
        // P19C [limits] project_defect_warn / project_defect_max(0 = 끔), 경고 알림
        private readonly int $defectWarn = 0,
        private readonly int $defectMax = 0,
        private readonly ?\PJGuardian\Services\Notifications\NotificationService $notifications = null,
        // P19C: 한 파일의 결함이 이보다 많으면 임시 SQLite(<work>/spill)로. 작업 폴더가 없으면(시험) 메모리 경로만
        private readonly int $spillThreshold = PHP_INT_MAX,
        private readonly ?string $workRoot = null,
        // P19C: 이 연결(분석 자식)의 SQLite 페이지 캐시 MB(0 = SQLite 기본 2MB). 큰 프로젝트 반영의 색인 갱신이 캐시 부족으로 디스크를 오가지 않게
        private readonly int $cacheMb = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row analyses 행(running)
     * @return array<string, mixed>|null 반영한 통계. 그사이 취소·종료되어 반영하지 않았으면 null
     */
    public function finalize(int $analysisId, array $row, string $workPath, AnalysisOutcome $outcome): ?array
    {
        $projectId = (int) $row['project_id'];
        $resultFile = (string) $outcome->tool['result_file'];
        $scopeFile = (string) ($outcome->tool['scope_file'] ?? '');
        $t0 = hrtime(true);
        memory_reset_peak_usage();
        if ($this->cacheMb > 0) {
            // 캐시는 PHP memory_limit 밖(SQLite 할당)이고 분석 자식이 끝나면 사라진다. 웹 연결은 기본값 그대로
            $this->db->pdo()->exec('PRAGMA cache_size = -' . ($this->cacheMb * 1024));
        }

        $ingest = (new DefectIngest($this->db, $this->spillThreshold, $this->workRoot === null ? null : DefectIngest::spillRoot($this->workRoot), (string) $analysisId))
            ->load($resultFile, $workPath);
        // 반영 뒤에는 이번에 감지한 결함이 모두 존재한다 → 그것만으로 상한을 넘으면 분명히 넘는다(해결될 결함을 세지 않는 하한, 쓰기 전)
        if ($this->defectMax > 0 && $ingest['count'] > $this->defectMax) {
            throw new DefectLimitExceededException((int) $ingest['count'], $this->defectMax);
        }
        $scope = $scopeFile !== '' ? ScopeList::fromFile($scopeFile) : ScopeList::fromPaths([]);
        $matcher = $this->projects->matcherFor($projectId);
        $excludeRules = [
            'server_default' => $matcher->defaults(),
            'project' => $matcher->patterns(),
            'config_files' => array_values(array_map(static fn (array $i): string => (string) $i['file'], (array) ($outcome->tool['ignored_configs'] ?? []))),
        ];
        if ($this->queue->cancelRequested($analysisId)) {
            $this->logger->info('취소 요청이 있어 결과를 반영하지 않음');
            return null;
        }
        $snapshot = $this->snapshots->create($projectId, $analysisId, $workPath, $scope);
        // P20S: 이 연결(분석 자식)의 자동 체크포인트를 끈다. 큰 COMMIT 직후의 체크포인트는 워커가 분석 사이에 한다(Support\WalCheckpoint)
        WalCheckpoint::disableAuto($this->db);
        try {
            $stats = (new DefectStore($this->db, $this->severity))->apply(
                $analysisId, $projectId, $scope, $matcher, $workPath, $outcome->tool, $snapshot, $excludeRules,
                SystemClock::iso($this->clock->now()),
            );
        } catch (AnalysisNotRunningException) {
            $this->snapshots->discard($projectId, $analysisId);
            $this->logger->info('분석이 더 이상 실행 중이 아니어서 결과를 반영하지 않음(취소·종료와 겹침)');
            return null;
        } catch (\Throwable $e) {
            $this->snapshots->discard($projectId, $analysisId);
            throw $e;
        }
        foreach ([$resultFile, $scopeFile] as $f) {
            if ($f !== '' && is_file($f) && !@unlink($f)) {
                $this->logger->warn('결과 파일을 지우지 못함(정리 작업이 1일 뒤 지움)', ['file' => basename($f)]);
            }
        }
        $stats += ['ingest' => $ingest, 'scope_files' => $scope->count(), 'snapshot' => $snapshot,
            'seconds' => round((hrtime(true) - $t0) / 1e9, 2), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1)];
        $this->logger->info('결함 반영 완료', array_diff_key($stats, ['assigned_changes' => true]));
        $this->events->dispatch(new AnalysisSucceeded($analysisId, $projectId, array_diff_key($stats, ['assigned_changes' => true]), $stats['assigned_changes']));
        if ($stats['assigned_changes'] !== []) {
            $this->events->dispatch(new AssignedDefectsChanged($analysisId, $projectId, $stats['assigned_changes']));
        }
        $this->warnDefectCount($projectId, $analysisId);
        try {
            $this->snapshots->prune($projectId);
        } catch (\Throwable $e) {
            $this->logger->error('스냅샷 정리 실패(분석 결과에는 영향 없음)', ['error' => $e->getMessage()]);
        }
        return $stats;
    }

    /** 현재 결함(해결 아님)이 경고 기준을 넘으면 활성 admin에게 알림(같은 프로젝트는 한 건에 건수·분석 갱신, 읽음 유지). 실패는 로그만 */
    private function warnDefectCount(int $projectId, int $analysisId): void
    {
        if ($this->defectWarn <= 0 || $this->notifications === null) {
            return;
        }
        try {
            $n = (int) $this->db->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status <> 'resolved'", [$projectId]);
            if ($n > $this->defectWarn) {
                $this->notifications->emit('defect_count_warn', $this->notifications->activeAdmins(), ['project_id' => $projectId, 'analysis_id' => $analysisId],
                    ['count' => $n, 'warn' => $this->defectWarn], 'defect_count_warn:' . $projectId, refresh: true);
                $this->logger->warn('프로젝트 결함 수 경고', ['project_id' => $projectId, 'count' => $n, 'warn' => $this->defectWarn]);
            }
        } catch (\Throwable $e) {
            $this->logger->warn('결함 수 경고 실패(분석 결과에는 영향 없음)', ['error' => $e->getMessage()]);
        }
    }
}
