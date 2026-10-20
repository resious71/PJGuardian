<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Services\Defects\SnapshotService;
use PJGuardian\Services\Queue\WorkerRecovery;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\Trash;

/**
 * 정리 작업(tools/cleanup.php, 명세 5.4, 14). 단계마다 항목을 추가한다(P30: 백업).
 *
 * P12 purgeIncompleteUploads: 받는 중(receiving)이고 마지막 활동 후 upload.incomplete_chunk_retention(24시간)이 지난 업로드 → aborted·폴더 삭제.
 *     DB 행이 없거나 끝난(aborted·failed) 업로드의 남은 폴더(32자리 16진수 이름)도 같은 기준(폴더 수정 시각)으로 삭제.
 * P13 purgeFailedZips: 실패·취소된 업로드의 zip(uploads/<id>/)을 마지막 활동 후 upload.failed_zip_retention(1일) 뒤 삭제.
 * P13 expireConfirmations: 확인 대기(needs_confirmation)로 upload.confirmation_retention(24시간, 가정)이 지난 업로드 → aborted,
 *     해제 결과(작업 디렉토리)와 업로드 폴더 삭제.
 * P13 purgeOrphanWorkDirs: 어떤 업로드(extracting·needs_confirmation)·분석(queued·running)도 가리키지 않고, 수정 시각이
 *     확인 대기 보관 시간보다 오래된 작업 디렉토리(work/<8자리 16진수>, 분석 종료 때 지우지 못한 잔여물 포함).
 * P14 recoverWorker: 중단된 해제·분석 되돌리기는 워커 생존 신호 기준(WorkerRecovery::recoverIfStale). P13의 "해제 중 24시간" 규칙은
 *     생존 신호 파일이 없을 때의 안전망(worker.stale_safety)으로 남았다.
 * P14 purgeFailedZips 확장: 분석이 실패·취소된 업로드의 zip도 분석 종료 1일 뒤 삭제, 분석이 성공했는데 남은 zip(즉시 삭제 실패)은 바로 삭제.
 * P14 retryPending: work/cleanup_pending.jsonl(프로젝트 삭제·분석 종료 때 지우지 못한 파일)을 다시 지운다. 기준 폴더(uploads, work,
 *     snapshots) 안의 경로만 지우고, 지우지 못한 줄은 남긴다(.lock으로 기록과 직렬화).
 * P14 purgeLogs: 에러·접근 로그(app-YYYYMMDD.log) 보존 기간(log.error_access_retention) 지난 파일 삭제.
 * P30B purgeAlertMailStates: 관리자 이메일 사건 상태(alert_mail_state)에서 풀린 지 alert_mail.state_retention(30일)이 지난 행.
 * P20A purgeToolStaging: 도구 설치 패키지 스테이징(<work>/tool_staging/<id>/)을 마지막 변경 후 tools.staging_retention(7일) 뒤 삭제(설치 중 제외).
 * 이름 규칙 밖의 파일·폴더(.lock, cleanup_pending.jsonl 등)는 건드리지 않는다.
 */
final class CleanupService
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly UploadSettings $settings,
        private readonly UploadFiles $files,
        private readonly Logger $logger,
        private readonly ?string $workRoot = null,
        private readonly ?WorkerRecovery $recovery = null,
        private readonly ?string $snapshotsRoot = null,
        private readonly int $logRetentionSeconds = 0,
        private readonly ?SnapshotService $snapshots = null,
        private readonly ?\PJGuardian\Services\Admin\ToolStaging $staging = null,
        private readonly int $stagingRetentionSeconds = 0,
        // P28B: 보관 기간이 지난 승인 대기·거절 계정 정리(bool $dryRun): int — 없으면 건너뜀(마이그레이션 026 전)
        private readonly ?\Closure $pendingAccounts = null,
        // P30: 감사 로그 보존 정리(Services\Audit\AuditRetention) — 없으면 건너뜀(시험·이전 조립)
        private readonly ?\PJGuardian\Services\Audit\AuditRetention $auditRetention = null,
        // P30B: 풀린 지 alert_mail.state_retention(30일)이 지난 관리자 이메일 상태 행(bool $dryRun): int — 없으면 건너뜀
        private readonly ?\Closure $alertMailStates = null,
    ) {
    }

    private function cutoff(int $seconds): string
    {
        return SystemClock::iso($this->clock->now()->modify('-' . $seconds . ' seconds'));
    }

    /** @return array<string, int> 모든 정리 항목 */
    public function runAll(bool $dryRun = false): array
    {
        return $this->recoverWorker($dryRun) + $this->purgeIncompleteUploads($dryRun) + $this->purgeFailedZips($dryRun)
            + $this->expireConfirmations($dryRun) + $this->purgeOrphanWorkDirs($dryRun) + $this->retryPending($dryRun) + $this->purgeLogs($dryRun)
            + $this->emptyTrash($dryRun) + $this->purgeResults($dryRun) + $this->pruneSnapshots($dryRun) + $this->purgeToolStaging($dryRun)
            + $this->purgePendingAccounts($dryRun) + $this->purgeAuditLog($dryRun) + $this->purgeAlertMailStates($dryRun);
    }

    /**
     * @return array{audit_purged:int, audit_through_at:?string} 보존 기간(log.audit_retention, 기본 90일)이 지난 감사 로그(P30). dry-run은 지울 건수만.
     *   해시 체인은 기준점을 옮겨 이어진다(AuditChain::purgeBefore). 실제로 지우면 audit.purge 한 줄이 남는다.
     */
    public function purgeAuditLog(bool $dryRun = false): array
    {
        if ($this->auditRetention === null) {
            return ['audit_purged' => 0, 'audit_through_at' => null];
        }
        $r = $dryRun ? $this->auditRetention->preview() : $this->auditRetention->purge();
        return ['audit_purged' => (int) ($dryRun ? $r['count'] : $r['deleted']), 'audit_through_at' => $r['through_at']];
    }

    /** @return array{alert_mail_states_purged:int} 풀린 지 오래된 관리자 이메일 사건 상태(P30B, 031 전이면 0). dry-run은 수만 */
    public function purgeAlertMailStates(bool $dryRun = false): array
    {
        return ['alert_mail_states_purged' => $this->alertMailStates === null ? 0 : (int) ($this->alertMailStates)($dryRun)];
    }

    /** @return array{pending_accounts_purged:int} 보관 기간(auth.pending_retention_days)이 지난 승인 대기·거절 계정(P28B). dry-run은 수만 */
    public function purgePendingAccounts(bool $dryRun = false): array
    {
        return ['pending_accounts_purged' => $this->pendingAccounts === null ? 0 : (int) ($this->pendingAccounts)($dryRun)];
    }

    /** @return array{staging_purged:int} */
    public function purgeToolStaging(bool $dryRun = false): array
    {
        if ($this->staging === null || $this->stagingRetentionSeconds <= 0) {
            return ['staging_purged' => 0];
        }
        return $this->staging->purgeExpired($this->stagingRetentionSeconds, $dryRun);
    }

    /** @return array{snapshots_pruned:int, excerpts_saved:int} 오래된 스냅샷 정리(P16, SnapshotService::pruneAll). dry-run이면 하지 않음 */
    public function pruneSnapshots(bool $dryRun = false): array
    {
        if ($this->snapshots === null || $dryRun) {
            return ['snapshots_pruned' => 0, 'excerpts_saved' => 0];
        }
        $r = $this->snapshots->pruneAll();
        return ['snapshots_pruned' => $r['pruned'], 'excerpts_saved' => $r['excerpts']];
    }

    /**
     * 분석 결과 NDJSON(<work_dir>/results/<분석 id>.ndjson, P15) 중 upload.failed_zip_retention(1일)보다 오래된 파일 삭제.
     * P16이 결과를 DB에 저장한 뒤 지우게 되면 이 항목은 남은 파일 정리만 한다.
     *
     * @return array{results_deleted:int}
     */
    public function purgeResults(bool $dryRun = false): array
    {
        if ($this->workRoot === null) {
            return ['results_deleted' => 0];
        }
        $dir = $this->workRoot . '/results';
        $cut = $this->clock->now()->getTimestamp() - $this->settings->failedZipRetentionSeconds;
        $n = 0;
        foreach (Fs::listDir($dir) as $name) {
            if (preg_match('/^\d{1,18}\.(ndjson|scope)(\.part)?$/', $name) !== 1 || (int) @filemtime($dir . '/' . $name) >= $cut) {
                continue;
            }
            if ($dryRun || @unlink($dir . '/' . $name)) {
                $n++;
            }
        }
        // P19C: 결함이 많은 파일의 임시 SQLite(<work>/spill) 중 같은 기간보다 오래된 것(분석 자식이 죽어 남은 것)
        if (!$dryRun) {
            $n += \PJGuardian\Services\Defects\DefectIngest::cleanupSpill($this->workRoot, null, $this->settings->failedZipRetentionSeconds);
        }
        return ['results_deleted' => $n];
    }

    /** @return array{trash_emptied:int} 작업 디렉토리 휴지통(work/trash, 워커가 옮겨 둔 것) 비우기 */
    public function emptyTrash(bool $dryRun = false): array
    {
        if ($this->workRoot === null) {
            return ['trash_emptied' => 0];
        }
        $trash = Trash::forWork($this->workRoot);
        return ['trash_emptied' => $dryRun ? count(Fs::listDir($trash->dir())) : $trash->empty()];
    }

    /** @return array{worker_state:string, stuck_extractions:int, recovered_analyses:int} 죽은 워커의 작업 되돌리기(WorkerRecovery) */
    public function recoverWorker(bool $dryRun = false): array
    {
        if ($this->recovery === null) {
            return ['worker_state' => 'none', 'stuck_extractions' => 0, 'recovered_analyses' => 0];
        }
        $r = $this->recovery->recoverIfStale($dryRun);
        return ['worker_state' => $r['state'], 'stuck_extractions' => $r['uploads'], 'recovered_analyses' => $r['analyses']];
    }

    /** @return array{expired_uploads:int, orphan_dirs:int, kept:int} */
    public function purgeIncompleteUploads(bool $dryRun = false): array
    {
        $now = $this->clock->now();
        $cutoff = $this->cutoff($this->settings->retentionSeconds);
        $expired = 0;
        foreach ($this->db->all("SELECT id FROM uploads WHERE status = 'receiving' AND last_activity_at < ? ORDER BY last_activity_at", [$cutoff]) as $r) {
            $id = (string) $r['id'];
            if ($dryRun) {
                $expired++;
                continue;
            }
            $lock = FileLock::tryAcquire($this->files->lock($id));
            if ($lock === null && is_dir($this->files->dir($id))) {
                continue; // 지금 청크를 받는 중
            }
            try {
                $n = $this->db->execute(
                    "UPDATE uploads SET status = 'aborted', reserved_bytes = 0, last_activity_at = ? WHERE id = ? AND status = 'receiving' AND last_activity_at < ?",
                    [SystemClock::iso($now), $id, $cutoff],
                );
            } finally {
                $lock?->release();
            }
            if ($n === 1) {
                $this->files->remove($id);
                $expired++;
                $this->logger->info('미완료 업로드 조각 정리', ['upload_id' => $id]);
            }
        }

        $orphans = 0;
        $kept = 0;
        $cutTs = $now->getTimestamp() - $this->settings->retentionSeconds;
        foreach (Fs::listDir($this->files->root()) as $name) {
            if (!UploadFiles::validId($name) || !is_dir($this->files->dir($name))) {
                continue;
            }
            $status = $this->db->value('SELECT status FROM uploads WHERE id = ?', [$name]);
            if ($status !== null) {
                $kept++; // 끝난 업로드의 zip은 purgeFailedZips가 보관 시간을 따져 지운다
                continue;
            }
            if ((int) @filemtime($this->files->dir($name)) >= $cutTs) {
                $kept++;
                continue;
            }
            if (!$dryRun) {
                $this->files->remove($name);
                $this->logger->info('DB 행이 없는 업로드 폴더 정리', ['upload_id' => $name]);
            }
            $orphans++;
        }
        return ['expired_uploads' => $expired, 'orphan_dirs' => $orphans, 'kept' => $kept];
    }

    /**
     * 실패·취소 업로드의 zip(업로드 폴더) 1일 뒤 삭제. 분석이 실패·취소된 업로드는 분석 종료 1일 뒤, 분석이 성공한 업로드는 바로
     * (워커가 성공 직후 지우지 못한 경우).
     *
     * @return array{failed_zips:int}
     */
    public function purgeFailedZips(bool $dryRun = false): array
    {
        $n = 0;
        $cutoff = $this->cutoff($this->settings->failedZipRetentionSeconds);
        $rows = $this->db->all(
            "SELECT id FROM uploads WHERE status IN ('failed', 'aborted') AND last_activity_at < ?"
            . " UNION SELECT u.id FROM uploads u JOIN analyses a ON a.upload_id = u.id WHERE u.status = 'queued'"
            . " AND ((a.status IN ('failed', 'cancelled') AND a.finished_at < ?) OR a.status = 'succeeded')",
            [$cutoff, $cutoff],
        );
        foreach ($rows as $r) {
            $id = (string) $r['id'];
            if (!is_dir($this->files->dir($id))) {
                continue;
            }
            if (!$dryRun) {
                $this->files->remove($id);
                $this->logger->info('실패·취소 업로드의 zip 정리', ['upload_id' => $id]);
            }
            $n++;
        }
        return ['failed_zips' => $n];
    }

    /** @return array{expired_confirmations:int} 확인 대기 24시간(가정) 초과 → 취소, 해제 결과·업로드 폴더 삭제 */
    public function expireConfirmations(bool $dryRun = false): array
    {
        $n = 0;
        $cutoff = $this->cutoff($this->settings->confirmationRetentionSeconds);
        foreach ($this->db->all("SELECT id, work_dir FROM uploads WHERE status = 'needs_confirmation' AND last_activity_at < ?", [$cutoff]) as $r) {
            $id = (string) $r['id'];
            if ($dryRun) {
                $n++;
                continue;
            }
            $done = $this->db->execute(
                "UPDATE uploads SET status = 'aborted', reserved_bytes = 0, work_dir = NULL, last_activity_at = ? WHERE id = ? AND status = 'needs_confirmation' AND last_activity_at < ?",
                [SystemClock::iso($this->clock->now()), $id, $cutoff],
            );
            if ($done === 1) {
                $this->removeWork($r['work_dir']);
                $this->files->remove($id);
                $n++;
                $this->logger->info('확인 대기 시간이 지난 업로드 취소', ['upload_id' => $id]);
            }
        }
        return ['expired_confirmations' => $n];
    }

    /** @return array{orphan_work_dirs:int} */
    public function purgeOrphanWorkDirs(bool $dryRun = false): array
    {
        if ($this->workRoot === null) {
            return ['orphan_work_dirs' => 0];
        }
        $retention = $this->settings->confirmationRetentionSeconds;
        $used = [];
        foreach ($this->db->all("SELECT work_dir FROM uploads WHERE status IN ('extracting', 'needs_confirmation') AND work_dir IS NOT NULL"
            . " UNION SELECT work_dir FROM analyses WHERE status IN ('queued', 'running') AND work_dir IS NOT NULL") as $r) {
            $used[(string) $r['work_dir']] = true;
        }
        $orphans = 0;
        $cutTs = $this->clock->now()->getTimestamp() - $retention;
        foreach (Fs::listDir($this->workRoot) as $name) {
            if (preg_match('/^[0-9a-f]{8}$/', $name) !== 1 || isset($used[$name]) || !is_dir($this->workRoot . '/' . $name)) {
                continue;
            }
            if ((int) @filemtime($this->workRoot . '/' . $name) >= $cutTs) {
                continue;
            }
            if (!$dryRun) {
                Fs::removeTree($this->workRoot . '/' . $name);
                $this->logger->info('남은 작업 디렉토리 정리', ['work_dir' => $name]);
            }
            $orphans++;
        }
        return ['orphan_work_dirs' => $orphans];
    }

    /** @return array{pending_removed:int, pending_left:int} work/cleanup_pending.jsonl 재시도 */
    public function retryPending(bool $dryRun = false): array
    {
        if ($this->workRoot === null) {
            return ['pending_removed' => 0, 'pending_left' => 0];
        }
        $file = $this->workRoot . '/cleanup_pending.jsonl';
        if (!is_file($file)) {
            return ['pending_removed' => 0, 'pending_left' => 0];
        }
        $roots = array_values(array_filter([$this->files->root(), $this->workRoot, $this->snapshotsRoot]));
        $lock = FileLock::acquire($file . '.lock');
        try {
            $lines = preg_split('/\r?\n/', (string) file_get_contents($file), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $left = [];
            $removed = 0;
            foreach ($lines as $line) {
                $e = json_decode($line, true);
                $path = is_array($e) && is_string($e['path'] ?? null) ? $e['path'] : null;
                if ($path === null || !self::insideAny($path, $roots)) {
                    // 형식이 틀리거나 기준 폴더 밖: 지우지 않고 목록에서도 뺀다
                    $this->logger->warn('정리 대상 목록의 잘못된 항목을 건너뜀', ['line' => mb_substr($line, 0, 200)]);
                    continue;
                }
                if (!file_exists($path)) {
                    $removed++;
                    continue;
                }
                if ($dryRun) {
                    $left[] = $line;
                    continue;
                }
                if (Fs::removeTree($path)) {
                    $removed++;
                    $this->logger->info('정리 대상 목록의 파일 삭제', ['path' => $path, 'reason' => $e['reason'] ?? null]);
                } else {
                    $left[] = $line;
                }
            }
            if (!$dryRun) {
                if ($left === []) {
                    @unlink($file);
                } else {
                    Fs::atomicWrite($file, implode("\n", $left) . "\n");
                }
            }
            return ['pending_removed' => $removed, 'pending_left' => count($left)];
        } finally {
            $lock->release();
        }
    }

    /** @param list<string> $roots 기준 폴더 안(같은 폴더 자체는 아님)이고 ../ 가 없는 경로만 */
    private static function insideAny(string $path, array $roots): bool
    {
        $p = str_replace('\\', '/', $path);
        if (preg_match('#(^|/)\.\.(/|$)#', $p) === 1) {
            return false;
        }
        $key = static fn (string $x): string => PHP_OS_FAMILY === 'Windows' ? strtolower($x) : $x;
        foreach ($roots as $r) {
            $r = rtrim(str_replace('\\', '/', $r), '/');
            if (str_starts_with($key($p), $key($r) . '/') && strlen($p) > strlen($r) + 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array{logs_deleted:int} 에러·접근 로그 보존 기간(log.error_access_retention) 지난 파일 */
    public function purgeLogs(bool $dryRun = false): array
    {
        if ($this->logRetentionSeconds <= 0 || $dryRun) {
            return ['logs_deleted' => 0];
        }
        return ['logs_deleted' => count($this->logger->purgeOlderThan($this->logRetentionSeconds))];
    }

    private function removeWork(mixed $workDir): void
    {
        if ($this->workRoot !== null && is_string($workDir) && preg_match('/^[0-9a-f]{8}$/', $workDir) === 1) {
            Fs::removeTree($this->workRoot . '/' . $workDir);
        }
    }
}
