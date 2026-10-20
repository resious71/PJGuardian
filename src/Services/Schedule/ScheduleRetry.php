<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

use PJGuardian\Support\CleanupQueue;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 주기 분석 가져오기 재시도(P26, 명세 18.3 "한 회차에서 저장소 접속이 연속 3회 실패하면 그 회차를 중단").
 *
 * 방식(① 같은 행 재큐): 주기 분석(trigger_kind = schedule)의 가져오기가 **재시도할 만한 오류**로 실패하면 분석 행을 failed로 마감하지 않고
 * queued로 되돌린다(retry_count + 1, retry_after = 지금 + repository.connect_retry_interval(5분)). 선점(AnalysisQueue::claimNext)은 retry_after 전의 행을
 * 건너뛰므로 기다리는 동안 워커는 다른 작업(사용자 업로드 등)을 처리한다(sleep 없음). 3번째에도 실패하면 그때 failed 1건으로 마감한다.
 * 실패 행을 새로 만들지 않으므로 이력에는 최종 결과 1건만 남는다. 재시도 간격 동안 같은 프로젝트에는 queued 행이 있어
 * 주기 도래·"지금 가져와 분석"은 건너뜀·409가 된다(프로젝트당 1개 규칙 그대로).
 *
 * 재시도하지 않는 오류: 인증·호스트 허용 안 됨·도구 없음·연결 없음·자격 증명을 읽을 수 없음·SSL 신뢰 안 됨·저장소/경로 없음·한도 초과·
 * 대상 0개·안전하지 않은 내용·디스크 부족 — 다시 해도 결과가 같다(설정을 고쳐야 함). 사용자가 누른 "지금 가져와 분석"은 재시도하지 않는다(P25).
 */
final class ScheduleRetry
{
    /** 재시도할 만한(일시적일 수 있는) 가져오기 오류 */
    public const RETRYABLE = ['repo_unreachable', 'repo_timeout', 'repo_fetch_failed'];

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        // 한 회차 최대 시도 수(repository.connect_max_attempts, 명세 18.3: 연속 3회 실패하면 중단)
        private readonly int $maxAttempts,
        // 재시도 간격(초, repository.connect_retry_interval)
        private readonly int $retrySeconds,
        // 작업 디렉토리 루트(가져오기가 먼저 만든 빈 작업 디렉토리를 지운다)
        private readonly string $workRoot = '',
    ) {
    }

    public static function retryable(string $code): bool
    {
        return in_array($code, self::RETRYABLE, true);
    }

    /**
     * 가져오기 실패한 분석을 재시도 대기로 되돌릴지 정하고, 되돌렸으면 true(호출한 쪽은 결과를 기록하지 않고 끝낸다).
     *
     * @param array<string, mixed> $row analyses 행(running)
     */
    public function requeue(int $analysisId, array $row, string $code): bool
    {
        if (($row['trigger_kind'] ?? null) !== 'schedule' || !self::retryable($code)) {
            return false;
        }
        $attempt = (int) ($row['retry_count'] ?? 0) + 1; // 이번이 몇 번째 시도였나
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        $after = SystemClock::iso($this->clock->now()->modify('+' . $this->retrySeconds . ' seconds'));
        // 가져오기는 작업 디렉토리를 먼저 기록한다(RepoImport). 되돌릴 때 비우고 지워야 다음 시도가 다시 가져온다
        $workDir = $this->db->value('SELECT work_dir FROM analyses WHERE id = ?', [$analysisId]);
        $n = $this->db->execute(
            "UPDATE analyses SET status = 'queued', started_at = NULL, worker_pid = NULL, process_group_id = NULL, fetched_at = NULL, work_dir = NULL,"
            . ' retry_count = ?, retry_after = ?, retry_code = ?'
            . " WHERE id = ? AND status = 'running' AND cancel_requested = 0",
            [$attempt, $after, $code, $analysisId],
        );
        if ($n !== 1) {
            return false; // 그사이 취소 요청 등 → 평소대로 마감
        }
        if (is_string($workDir) && preg_match('/^[0-9a-f]{8}$/', $workDir) === 1 && $this->workRoot !== '') {
            $path = rtrim(str_replace('\\', '/', $this->workRoot), '/') . '/' . $workDir;
            if (is_dir($path) && !Fs::removeTree($path)) {
                (new CleanupQueue($this->workRoot . '/cleanup_pending.jsonl', $this->clock))->add($path, 'retry_work_dir', ['analysis_id' => $analysisId]);
            }
        }
        $this->logger->warn('주기 분석 가져오기 실패 → 재시도 대기', [
            'analysis_id' => $analysisId, 'project_id' => (int) $row['project_id'], 'code' => $code,
            'attempt' => $attempt, 'max' => $this->maxAttempts, 'retry_after' => $after,
        ]);
        return true;
    }
}
