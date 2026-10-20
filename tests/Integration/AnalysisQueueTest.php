<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Services\Queue\AnalysisQueue;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 분석 큐 스케줄링(명세 5.2, 5.4, 19.8, P14): FIFO, 프로젝트당 1개와 건너뛰기, 전체 동시 실행 한도, 원자적 선점(연결 2개·프로세스 2개),
 * 대기 순번, 결과 기록 조건(running이고 취소 요청 없음).
 */
final class AnalysisQueueTest extends TestCase
{
    private Db $db;
    private AnalysisQueue $q;
    private int $user;

    public function setUp(): void
    {
        $this->db = TestDb::migrated();
        $this->q = new AnalysisQueue($this->db, new FixedClock('2026-10-04T00:00:00Z'));
        $this->user = TestDb::user($this->db, 'kim', 'user');
    }

    private function queued(int $project, array $over = []): int
    {
        return TestDb::analysis($this->db, $project, $this->user, ['status' => 'queued'] + $over);
    }

    private function status(int $id): string
    {
        return (string) $this->db->value('SELECT status FROM analyses WHERE id = ?', [$id]);
    }

    private function claimId(int $max = 2): ?int
    {
        $r = $this->q->claimNext($max, 4242);
        return $r === null ? null : (int) $r['id'];
    }

    public function testFifoWithSkipAheadAcrossProjects(): void
    {
        $p1 = TestDb::project($this->db, 'p1');
        $p2 = TestDb::project($this->db, 'p2');
        $a = $this->queued($p1);
        $b = $this->queued($p1);
        $c = $this->queued($p2);
        $this->assertSame($a, $this->claimId(), '가장 먼저 등록한 A');
        // B는 같은 프로젝트의 A가 실행 중이라 막힘 → 뒤 순서의 C(다른 프로젝트)가 먼저(명세 19.8)
        $this->assertSame($c, $this->claimId(), 'B 대신 C');
        $this->assertSame('queued', $this->status($b));
        $this->assertSame(null, $this->claimId(), '전체 2개 실행 중');
        $this->assertTrue($this->q->complete($a, AnalysisOutcome::succeeded(['total_count' => 0, 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0])));
        $this->assertSame($b, $this->claimId(), 'A가 끝나면 B');
        $row = $this->db->one('SELECT worker_pid, started_at, process_group_id FROM analyses WHERE id = ?', [$b]);
        $this->assertSame([4242, '2026-10-04T00:00:00Z', null], [(int) $row['worker_pid'], $row['started_at'], $row['process_group_id']]);
    }

    public function testGlobalConcurrencyLimit(): void
    {
        $ids = [];
        foreach (['x', 'y', 'z'] as $n) {
            $ids[] = $this->queued(TestDb::project($this->db, $n));
        }
        $this->assertSame($ids[0], $this->claimId());
        $this->assertSame($ids[1], $this->claimId());
        $this->assertSame(null, $this->claimId(), '3개 프로젝트 3개 작업 → 동시에 2개만');
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM analyses WHERE status = 'running'"));
        $this->assertTrue($this->q->fail($ids[0], 'timeout'));
        $this->assertSame($ids[2], $this->claimId(), '하나가 끝나면 세 번째');
        $this->assertSame(null, $this->q->claimNext(3, 1), '이미 running인 것은 다시 선점하지 않음');
    }

    public function testCancelRequestedQueuedIsNotClaimedAndPositions(): void
    {
        $p = [TestDb::project($this->db, 'a'), TestDb::project($this->db, 'b'), TestDb::project($this->db, 'c'), TestDb::project($this->db, 'd')];
        $a = $this->queued($p[0]);
        $b = $this->queued($p[1], ['cancel_requested' => 1]);
        $c = $this->queued($p[2]);
        $d = $this->queued($p[3]);
        $this->assertSame([1, null, 2, 3], [$this->q->position($a), $this->q->position($b), $this->q->position($c), $this->q->position($d)], '취소 요청된 것은 순번 없음');
        $this->assertSame($a, $this->claimId());
        $this->assertSame($c, $this->claimId(), '취소 요청된 B는 건너뜀');
        $this->assertSame([null, 1], [$this->q->position($a), $this->q->position($d)], 'running은 순번 없음, 앞이 빠지면 당겨짐');
        $this->assertSame(null, $this->q->position(99999));
    }

    public function testCompleteOnlyWhileRunningAndNotCancelled(): void
    {
        $p = TestDb::project($this->db, 'p');
        $a = $this->queued($p);
        $zero = ['total_count' => 0, 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0];
        $this->assertFalse($this->q->complete($a, AnalysisOutcome::succeeded($zero)), 'queued에는 기록하지 않음');
        $this->claimId();
        $this->db->execute('UPDATE analyses SET cancel_requested = 1 WHERE id = ?', [$a]);
        $this->assertFalse($this->q->complete($a, AnalysisOutcome::failed('tool_failed')), '취소 요청 뒤에는 기록하지 않음(워커가 cancelled로)');
        $this->assertTrue($this->q->markCancelled($a));
        $this->assertSame('cancelled', $this->status($a));
        $this->assertFalse($this->q->fail($a, 'timeout'), '끝난 분석은 바꾸지 않음');

        $b = $this->queued($p);
        $this->claimId();
        $this->assertTrue($this->q->complete($b, AnalysisOutcome::failed('tool_failed', '내부 상세')));
        $row = $this->db->one('SELECT status, failure_code, failure_message, finished_at FROM analyses WHERE id = ?', [$b]);
        $this->assertSame(['failed', 'tool_failed', '2026-10-04T00:00:00Z'], [$row['status'], $row['failure_code'], $row['finished_at']]);
        $this->assertNotContains('내부 상세', (string) $row['failure_message'], '상세는 사용자 문구에 넣지 않음');
        $this->assertSame(AnalysisQueue::failureMessage('timeout'), \PJGuardian\Support\Lang::get('analysis.failure.timeout'));
        $this->assertContains('mystery', AnalysisQueue::failureMessage('mystery'), '모르는 코드는 일반 문구 + 코드');
    }

    public function testAtomicClaimWithTwoConnections(): void
    {
        $file = $this->tempDir('queue') . '/q.sqlite';
        $db1 = TestDb::migrated($file);
        $db2 = new Db($file, 5000);
        $user = TestDb::user($db1, 'u', 'user');
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = TestDb::analysis($db1, TestDb::project($db1, 'p' . $i), $user, ['status' => 'queued']);
        }
        $clock = new FixedClock('2026-10-04T00:00:00Z');
        $q1 = new AnalysisQueue($db1, $clock);
        $q2 = new AnalysisQueue($db2, $clock);
        $got = [];
        // 연결 1이 쓰기 트랜잭션(BEGIN IMMEDIATE)을 잡고 있는 동안 연결 2의 선점은 잠금을 기다리다 busy_timeout 뒤 실패한다(같은 작업을 읽지 않음)
        $db1->pdo()->exec('BEGIN IMMEDIATE');
        $db2Short = new Db($file, 200);
        $blocked = false;
        try {
            (new AnalysisQueue($db2Short, $clock))->claimNext(10, 2);
        } catch (\PDOException) {
            $blocked = true;
        }
        $db1->pdo()->exec('ROLLBACK');
        $this->assertTrue($blocked, '다른 연결이 쓰기 잠금을 잡고 있으면 선점이 진행되지 않음');
        for ($i = 0; $i < 3; $i++) {
            $got[] = (int) $q1->claimNext(10, 1)['id'];
            $got[] = (int) $q2->claimNext(10, 2)['id'];
        }
        sort($got);
        $this->assertSame($ids, $got, '번갈아 선점해도 중복·누락 없음');
        $this->assertSame(null, $q2->claimNext(10, 2));
        unset($db2Short, $db2, $q2, $q1, $db1);
    }

    public function testAtomicClaimAcrossProcesses(): void
    {
        $dir = $this->tempDir('queue');
        $file = $dir . '/q.sqlite';
        $db = TestDb::migrated($file);
        $user = TestDb::user($db, 'u', 'user');
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $ids[] = TestDb::analysis($db, TestDb::project($db, 'p' . $i), $user, ['status' => 'queued']);
        }
        unset($db);
        $procs = [];
        foreach ([0, 1] as $n) {
            $out = $dir . "/out{$n}.json";
            $procs[] = [proc_open([PHP_BINARY, PJG_ROOT . '/tests/Support/claim_race.php', $file, $dir . '/go', '1000'],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $dir . "/err{$n}.txt", 'w']], $pipes, null, null, ['bypass_shell' => true]), $out];
        }
        usleep(300_000);
        touch($dir . '/go');
        $all = [];
        foreach ($procs as [$p, $out]) {
            proc_close($p);
            $got = json_decode((string) file_get_contents($out), true);
            $this->assertTrue(is_array($got), '출력: ' . file_get_contents($out) . ' / ' . @file_get_contents($dir . '/err0.txt'));
            $all[] = $got;
        }
        $merged = array_merge(...$all);
        sort($merged);
        $this->assertSame($ids, $merged, '두 프로세스가 동시에 선점해도 중복·누락 없음');
        $this->assertTrue($all[0] !== [] && $all[1] !== [], '두 프로세스 모두 일부를 가져감(동시 실행 확인): ' . count($all[0]) . '/' . count($all[1]));
    }
}
