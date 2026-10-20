<?php

declare(strict_types=1);

namespace PJGuardian\Services\Queue;

use PJGuardian\Services\Events\AnalysisFailed;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\Trash;

/**
 * 워커 비정상 종료 복구(명세 5.4, 14, 19.10).
 *
 * - running 분석 → failed(worker_crashed). **재시도하지 않는다**(명세 19.10 확정 결정, 명세 14의 "failed 또는 재대기" 중 failed).
 *   결함과 비교 기준은 건드리지 않는다. 작업 디렉토리는 지운다(zip은 실패 분석 보관 기간 뒤 정리 작업이 지운다).
 * - assembling 업로드 → assemble_pending(다시 조립), extracting + work_dir → 작업 디렉토리 삭제·work_dir 비움(다시 해제).
 *   업로드 후처리는 사용자가 아직 분석을 시작하지 않은 단계라 되돌려 다시 처리한다(P13의 규칙을 이어받음).
 * - 되돌리기 전에 남은 자식 프로세스를 트리째 종료한다: 분석은 DB의 process_group_id, 업로드는 마지막 생존 신호의 PID.
 *   PID가 재사용되었을 수 있으므로 실행 파일 이름이 php인 경우만(Linux는 명령행에 run_analysis.php / run_upload.php도 확인).
 *
 * 언제
 * - recoverAll(): 워커가 기동하면서 단일 인스턴스 잠금을 잡은 직후. 잠금을 잡았으니 다른 워커는 없다 → 남은 것은 모두 죽은 워커의 것.
 * - recoverIfStale(): 정리 작업(tools/cleanup.php)과 tools/worker.php --check. 잠금을 잡을 수 있고(살아 있는 워커 없음)
 *   생존 신호가 worker.heartbeat_stale 이상 끊겼을 때만 되돌린다. 잠금이 잡혀 있으면 워커가 살아 있는 것이므로
 *   오래 걸리는 정상 해제·분석을 되돌리지 않는다(신호도 끊겼으면 "응답 없음" 경고만 남긴다).
 *   신호 파일이 없으면(워커 없이 process_upload.php로 처리) worker.stale_safety(24시간)보다 오래된 것만 되돌린다.
 */
final class WorkerRecovery
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AnalysisQueue $queue,
        private readonly WorkerState $state,
        private readonly string $workRoot,
        private readonly Logger $logger,
        private readonly int $staleSeconds,
        private readonly int $safetySeconds,
        // P24: 복구로 실패 처리한 분석을 알림에 알린다. null이면 보내지 않음(시험)
        private readonly ?EventDispatcher $events = null,
    ) {
    }

    /**
     * @param string $code 분석 실패 코드: worker_crashed(기동 시 복구) / worker_stopped(강제 종료)
     * @return array{analyses:int, uploads:int, killed:int}
     */
    public function recoverAll(bool $dryRun = false, string $code = 'worker_crashed'): array
    {
        return $this->recover(null, $dryRun, $code);
    }

    /**
     * @return array{state:string, analyses:int, uploads:int, killed:int}
     *   state: worker_alive(살아 있음) / worker_unresponsive(잠금은 있는데 신호 끊김, 되돌리지 않음) / waiting(신호가 아직 최근) /
     *          recovered(신호 끊김 → 되돌림) / safety(신호 파일 없음 → 24시간 기준)
     */
    public function recoverIfStale(bool $dryRun = false): array
    {
        $since = $this->state->secondsSinceBeat();
        $lock = $this->state->tryLock();
        if ($lock === null) {
            if ($since !== null && $since >= $this->staleSeconds) {
                $this->logger->warn('워커 응답 없음(잠금은 있으나 생존 신호 끊김)', ['seconds_since_beat' => $since]);
                return ['state' => 'worker_unresponsive', 'analyses' => 0, 'uploads' => 0, 'killed' => 0];
            }
            return ['state' => 'worker_alive', 'analyses' => 0, 'uploads' => 0, 'killed' => 0];
        }
        try {
            if ($since === null) {
                $cutoff = SystemClock::iso($this->clock->now()->modify('-' . $this->safetySeconds . ' seconds'));
                return ['state' => 'safety'] + $this->recover($cutoff, $dryRun, 'worker_crashed');
            }
            if ($since < $this->staleSeconds) {
                return ['state' => 'waiting', 'analyses' => 0, 'uploads' => 0, 'killed' => 0];
            }
            $hb = $this->state->read();
            if (($hb['state'] ?? '') === 'stopped' && $this->queue->running() === [] && !$this->hasClaimedUploads()) {
                return ['state' => 'waiting', 'analyses' => 0, 'uploads' => 0, 'killed' => 0]; // 정상 종료, 되돌릴 것 없음
            }
            return ['state' => 'recovered'] + $this->recover(null, $dryRun, 'worker_crashed');
        } finally {
            $lock->release();
        }
    }

    private function hasClaimedUploads(): bool
    {
        return $this->db->value("SELECT 1 FROM uploads WHERE status = 'assembling' OR (status = 'extracting' AND work_dir IS NOT NULL) LIMIT 1") !== null;
    }

    /**
     * @param string|null $cutoff null이면 전부, 아니면 이 시각보다 오래된 것만(안전망)
     * @return array{analyses:int, uploads:int, killed:int}
     */
    private function recover(?string $cutoff, bool $dryRun, string $code): array
    {
        $killed = 0;
        $hb = $this->state->read();
        $analyses = $this->queue->running();
        if ($cutoff !== null) {
            $analyses = array_values(array_filter($analyses, static fn (array $a): bool => (string) $a['started_at'] < $cutoff));
        }
        $uploads = $this->db->all(
            "SELECT id, status, work_dir, last_activity_at FROM uploads WHERE (status = 'assembling' OR (status = 'extracting' AND work_dir IS NOT NULL))"
            . ' AND (? IS NULL OR last_activity_at < ?) ORDER BY created_at, id',
            [$cutoff, $cutoff],
        );
        if ($dryRun) {
            return ['analyses' => count($analyses), 'uploads' => count($uploads), 'killed' => 0];
        }

        // 1) 남은 자식 프로세스 종료(되돌린 뒤 옛 자식이 결과를 쓰지 않게 먼저)
        $pids = [];
        foreach ($analyses as $a) {
            $pids[(int) ($a['process_group_id'] ?? 0)] = 'run_analysis.php';
        }
        if ($cutoff === null) {
            foreach ((array) ($hb['analyses'] ?? []) as $c) {
                $pids[(int) ($c['pid'] ?? 0)] = 'run_analysis.php';
            }
            foreach ((array) ($hb['uploads'] ?? []) as $c) {
                $pids[(int) ($c['pid'] ?? 0)] = 'run_upload.php';
            }
        }
        foreach ($pids as $pid => $script) {
            if ($pid > 0 && $this->isOurChild($pid, $script)) {
                $method = ProcessTree::kill($pid);
                $killed++;
                $this->logger->warn('남은 작업 프로세스 종료', ['pid' => $pid, 'script' => $script, 'kill' => $method]);
            }
        }

        // 2) 분석: failed(worker_crashed), 작업 디렉토리 삭제
        $nA = 0;
        foreach ($analyses as $a) {
            if ($this->queue->fail((int) $a['id'], $code)) {
                $nA++;
                $this->removeWork($a['work_dir']);
                $this->logger->warn('워커 종료로 분석 실패 처리', ['analysis_id' => (int) $a['id'], 'project_id' => (int) $a['project_id'], 'code' => $code]);
                $this->events?->dispatch(new AnalysisFailed((int) $a['id'], (int) $a['project_id'], $code));
            }
        }
        // P25: 저장소 가져오기 임시 폴더(<work>/fetch/<분석 id>) — 실행 중이 아닌 분석의 것은 모두 지운다(워커가 가져오는 도중 죽은 경우)
        $fetchRoot = rtrim(str_replace('\\', '/', $this->workRoot), '/') . '/fetch';
        if (is_dir($fetchRoot)) {
            foreach (Fs::listDir($fetchRoot) as $name) {
                if (preg_match('/^[1-9][0-9]{0,17}$/', $name) === 1
                    && $this->db->value("SELECT 1 FROM analyses WHERE id = ? AND status = 'running'", [(int) $name]) === null
                    && \PJGuardian\Services\Repo\RepoImport::removeStage($fetchRoot . '/' . $name)) {
                    $this->logger->info('남은 저장소 가져오기 임시 폴더 삭제', ['analysis_id' => (int) $name]);
                }
            }
        }

        // 3) 업로드: 되돌려 다시 처리
        $nU = 0;
        $now = SystemClock::iso($this->clock->now());
        foreach ($uploads as $u) {
            if ($u['status'] === 'assembling') {
                $n = $this->db->execute("UPDATE uploads SET status = 'assemble_pending', last_activity_at = ? WHERE id = ? AND status = 'assembling'", [$now, $u['id']]);
            } else {
                $n = $this->db->execute(
                    "UPDATE uploads SET work_dir = NULL, last_activity_at = ? WHERE id = ? AND status = 'extracting' AND work_dir = ?",
                    [$now, $u['id'], $u['work_dir']],
                );
                if ($n === 1) {
                    $this->removeWork($u['work_dir']);
                }
            }
            if ($n === 1) {
                $nU++;
                $this->logger->warn('중단된 업로드 후처리를 되돌림(다시 처리 대기)', ['upload_id' => $u['id'], 'stage' => $u['status']]);
            }
        }
        return ['analyses' => $nA, 'uploads' => $nU, 'killed' => $killed];
    }

    /** PID 재사용 방지: php 프로세스이고(Linux는 명령행에 스크립트 이름도) 살아 있을 때만 */
    private function isOurChild(int $pid, string $script): bool
    {
        $image = ProcessTree::imageName($pid);
        if ($image === null || !str_starts_with($image, 'php')) {
            return false;
        }
        $cmd = ProcessTree::commandLine($pid);
        return $cmd === null || str_contains($cmd, $script);
    }

    /** 작업 디렉토리는 휴지통으로 옮긴다(큰 디렉토리 삭제로 워커 기동이 늦어지지 않게). 옮기지 못하면 바로 지운다 */
    private function removeWork(mixed $workDir): void
    {
        if (is_string($workDir) && preg_match('/^[0-9a-f]{8}$/', $workDir) === 1) {
            $path = $this->workRoot . '/' . $workDir;
            if (!Trash::forWork($this->workRoot)->move($path)) {
                Fs::removeTree($path);
            }
        }
    }
}
