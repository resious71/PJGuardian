<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\CleanupQueue;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 워커 생존 신호 기준 복구와 P14 정리 항목(명세 5.4, 14, 19.10). 프로세스 없이 시계(FixedClock)와 파일로 시험한다.
 * 실제 워커 프로세스를 죽이고 되살리는 시험은 WorkerProcessTest.
 */
final class WorkerRecoveryTest extends TestCase
{
    private App $app;
    private string $root;
    private int $user;
    private int $project;

    public function setUp(): void
    {
        $this->root = $this->tempDir('wrec');
        foreach (['uploads', 'work', 'logs', 'snapshots'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->app = TestApp::make($this->root . '/logs', [
            'paths' => ['uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work', 'snapshots_dir' => $this->root . '/snapshots'],
            'worker' => ['heartbeat_interval' => '10s', 'heartbeat_stale' => '3m'],
        ]);
        $this->user = TestDb::user($this->app->db(), 'kim', 'user');
        $this->project = TestDb::project($this->app->db(), 'p');
    }

    /** running 분석 하나(작업 디렉토리 포함)와 해제 중 업로드 하나 */
    private function inFlight(): array
    {
        $db = $this->app->db();
        mkdir($this->root . '/work/aaaa0001');
        $a = TestDb::analysis($db, $this->project, $this->user, ['status' => 'running', 'work_dir' => 'aaaa0001', 'process_group_id' => 999999]);
        $u = str_repeat('c', 32);
        $now = '2026-10-02T12:00:00Z';
        $db->execute(
            "INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, status, sha256, work_dir, last_activity_at, created_at)"
            . " VALUES (?, ?, ?, 'a.zip', 10, 10, 1, 'extracting', ?, 'bbbb0002', ?, ?)",
            [$u, $this->user, $this->project, str_repeat('d', 64), $now, $now],
        );
        mkdir($this->root . '/work/bbbb0002');
        return [$a, $u];
    }

    private function beat(): void
    {
        $this->app->workerState()->beat(123456, '2026-10-02T11:00:00Z', 'running', [], [], App::VERSION);
    }

    public function testStaleHeartbeatRecoversWhenNoWorkerHoldsLock(): void
    {
        [$a, $u] = $this->inFlight();
        $this->beat();
        $rec = $this->app->workerRecovery();
        $this->app->clock->advance(60);
        $r = $rec->recoverIfStale();
        $this->assertSame(['waiting', 0, 0], [$r['state'], $r['analyses'], $r['uploads']], '신호가 끊긴 지 1분: 아직 되돌리지 않음');
        $this->app->clock->advance(150);
        $dry = $rec->recoverIfStale(true);
        $this->assertSame(['recovered', 1, 1], [$dry['state'], $dry['analyses'], $dry['uploads']], 'dry-run은 세기만');
        $this->assertSame('running', $this->app->db()->value('SELECT status FROM analyses WHERE id = ?', [$a]));
        $r = $rec->recoverIfStale();
        $this->assertSame(['recovered', 1, 1], [$r['state'], $r['analyses'], $r['uploads']], '3분 넘게 끊김 → 되돌림');
        $row = $this->app->db()->one('SELECT status, failure_code, finished_at FROM analyses WHERE id = ?', [$a]);
        $this->assertSame(['failed', 'worker_crashed'], [$row['status'], $row['failure_code']], '재시도 없이 실패(명세 19.10)');
        $up = $this->app->db()->one('SELECT status, work_dir FROM uploads WHERE id = ?', [$u]);
        $this->assertSame(['extracting', null], [$up['status'], $up['work_dir']], '업로드 해제는 되돌려 다시 처리');
        $this->assertFalse(is_dir($this->root . '/work/aaaa0001') || is_dir($this->root . '/work/bbbb0002'), '작업 디렉토리는 휴지통으로');
        $this->assertSame(['trash_emptied' => 2], $this->app->cleanup()->emptyTrash());
        $this->assertSame([], Fs::listDir($this->root . '/work/trash'));
    }

    public function testLiveWorkerIsNeverRevertedEvenIfHeartbeatIsLate(): void
    {
        [$a] = $this->inFlight();
        $this->beat();
        // 다른 프로세스(워커)가 잠금을 잡고 있는 상황: 같은 프로세스에서도 다른 핸들이면 LOCK_NB가 실패한다
        $held = FileLock::acquire($this->app->workerState()->lockFile());
        try {
            $this->app->clock->advance(30);
            $this->assertSame('worker_alive', $this->app->workerRecovery()->recoverIfStale()['state']);
            $this->app->clock->advance(3600);
            $r = $this->app->workerRecovery()->recoverIfStale();
            $this->assertSame(['worker_unresponsive', 0], [$r['state'], $r['analyses']], '잠금이 있으면 신호가 끊겨도 되돌리지 않음(경고만)');
            $this->assertContains('워커 응답 없음', (string) file_get_contents($this->app->logger->fileFor($this->app->clock->now())));
            $this->assertSame('running', $this->app->db()->value('SELECT status FROM analyses WHERE id = ?', [$a]));
        } finally {
            $held->release();
        }
    }

    public function testNoHeartbeatFileUsesTwentyFourHourSafetyNet(): void
    {
        [$a, $u] = $this->inFlight();
        $this->app->clock->advance(23 * 3600);
        $r = $this->app->workerRecovery()->recoverIfStale();
        $this->assertSame(['safety', 0, 0], [$r['state'], $r['analyses'], $r['uploads']]);
        $this->app->clock->advance(2 * 3600);
        $r = $this->app->workerRecovery()->recoverIfStale();
        $this->assertSame(['safety', 1, 1], [$r['state'], $r['analyses'], $r['uploads']]);
        $this->assertSame('failed', $this->app->db()->value('SELECT status FROM analyses WHERE id = ?', [$a]));
        $this->assertSame(null, $this->app->db()->value('SELECT work_dir FROM uploads WHERE id = ?', [$u]));
    }

    public function testCleanStopIsNotTreatedAsCrash(): void
    {
        $this->app->workerState()->beat(1, '2026-10-02T11:00:00Z', 'stopped', [], [], App::VERSION);
        $this->app->clock->advance(3600);
        $this->assertSame('waiting', $this->app->workerRecovery()->recoverIfStale()['state']);
    }

    public function testZipRetentionFollowsAnalysisOutcome(): void
    {
        $db = $this->app->db();
        $files = $this->app->uploadFiles();
        $ids = [];
        foreach (['succeeded', 'failed', 'cancelled', 'queued'] as $i => $st) {
            $id = str_repeat((string) ($i + 1), 32);
            $db->execute(
                "INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, status, sha256, last_activity_at, created_at)"
                . " VALUES (?, ?, ?, 'a.zip', 10, 10, 1, 'queued', ?, '2026-10-02T12:00:00Z', '2026-10-02T12:00:00Z')",
                [$id, $this->user, $this->project, str_repeat('e', 64)],
            );
            TestDb::analysis($db, $this->project, $this->user, ['status' => $st, 'upload_id' => $id, 'failure_code' => $st === 'failed' ? 'timeout' : null]);
            mkdir($files->dir($id));
            file_put_contents($files->zip($id), 'zip');
            $ids[$st] = $id;
        }
        $this->app->clock->advance(3600);
        $this->assertSame(1, $this->app->cleanup()->purgeFailedZips()['failed_zips']);
        $this->assertFalse(is_dir($files->dir($ids['succeeded'])), '성공한 분석의 zip은 바로(워커가 지우지 못한 경우의 안전망)');
        $this->assertTrue(is_dir($files->dir($ids['failed'])) && is_dir($files->dir($ids['cancelled'])), '실패·취소는 1일 전에는 유지');
        $this->app->clock->advance(86400);
        $this->assertSame(2, $this->app->cleanup()->purgeFailedZips()['failed_zips']);
        $this->assertFalse(is_dir($files->dir($ids['failed'])) || is_dir($files->dir($ids['cancelled'])), '1일 뒤 삭제');
        $this->assertTrue(is_dir($files->dir($ids['queued'])), '아직 대기 중인 분석의 zip은 유지');
    }

    public function testCleanupPendingListIsRetriedInsideRootsOnly(): void
    {
        $work = $this->root . '/work';
        $queue = new CleanupQueue($work . '/cleanup_pending.jsonl', $this->app->clock);
        mkdir($this->root . '/snapshots/7/9', 0700, true);
        file_put_contents($this->root . '/snapshots/7/9/a.txt', 'x');
        mkdir($work . '/0000beef');
        $locked = $work . '/0000beef/open.txt';
        file_put_contents($locked, 'x');
        $outside = $this->tempDir('outside');
        file_put_contents($outside . '/keep.txt', 'keep');
        $queue->add($this->root . '/snapshots/7', 'project_delete', ['project_id' => 7]);
        $queue->add($work . '/0000beef', 'analysis_work_dir');
        $queue->add($outside, 'tampered');
        $queue->add($this->root . '/uploads/../../escape', 'tampered');
        $queue->add($this->root . '/uploads/' . str_repeat('f', 32), 'already_gone');
        $h = null;
        if (PHP_OS_FAMILY === 'Windows') {
            $h = fopen($locked, 'r'); // Windows는 열린 파일을 지울 수 없다 → 이번에는 남는다
        }
        $r = $this->app->cleanup()->retryPending();
        if ($h !== null) {
            $this->assertSame(['pending_removed' => 2, 'pending_left' => 1], $r, '스냅샷 삭제 + 이미 없는 것, 열린 파일이 있는 폴더는 남김');
            fclose($h);
            $r = $this->app->cleanup()->retryPending();
            $this->assertSame(['pending_removed' => 1, 'pending_left' => 0], $r, '다음 실행에서 지움');
        }
        $this->assertFalse(is_dir($this->root . '/snapshots/7') || is_dir($work . '/0000beef'));
        $this->assertTrue(is_file($outside . '/keep.txt'), '기준 폴더 밖 경로는 지우지 않음');
        $this->assertFalse(is_file($work . '/cleanup_pending.jsonl'), '모두 처리하면 목록 파일 삭제');
    }

    public function testUploadChildFailureMarksUploadFailed(): void
    {
        [, $u] = $this->inFlight();
        $p = $this->app->uploadProcessor();
        $this->assertTrue($p->failClaimed($u, 'extract_timeout', '자식 시간 초과'));
        $row = $this->app->db()->one('SELECT status, error_code, error_message, work_dir, reserved_bytes FROM uploads WHERE id = ?', [$u]);
        $this->assertSame(['failed', 'extract_timeout', null, 0], [$row['status'], $row['error_code'], $row['work_dir'], (int) $row['reserved_bytes']]);
        $this->assertSame(\PJGuardian\Support\Lang::get('error.extract_timeout'), $row['error_message']);
        $this->assertFalse(is_dir($this->root . '/work/bbbb0002'), '작업 디렉토리는 휴지통으로');
        $this->assertFalse($p->failClaimed($u, 'extract_crashed', '두 번째'), '이미 실패한 업로드는 바꾸지 않음');

        $a = str_repeat('a', 32);
        $this->app->db()->execute(
            "INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, status, last_activity_at, created_at)"
            . " VALUES (?, ?, ?, 'b.zip', 10, 10, 1, 'assembling', '2026-10-02T12:00:00Z', '2026-10-02T12:00:00Z')",
            [$a, $this->user, $this->project],
        );
        mkdir($this->app->uploadFiles()->dir($a));
        $this->assertTrue($this->app->uploadAssembler()->failClaimed($a, 'timeout'));
        $this->assertSame(['failed', 'assemble_failed'], array_values($this->app->db()->one('SELECT status, error_code FROM uploads WHERE id = ?', [$a])));
        $this->assertFalse(is_dir($this->app->uploadFiles()->dir($a)), '조립 실패는 업로드 폴더 삭제');
    }

    public function testRunAllIncludesWorkerAndLogItems(): void
    {
        file_put_contents($this->root . '/logs/app-20200101.log', 'old');
        $r = $this->app->cleanup()->runAll();
        foreach (['worker_state', 'stuck_extractions', 'recovered_analyses', 'pending_removed', 'pending_left', 'logs_deleted', 'trash_emptied', 'failed_zips', 'orphan_work_dirs'] as $k) {
            $this->assertTrue(array_key_exists($k, $r), "runAll에 {$k}");
        }
        $this->assertSame(1, $r['logs_deleted'], '보존 기간 지난 로그 삭제');
        $this->assertFalse(is_file($this->root . '/logs/app-20200101.log'));
    }
}
