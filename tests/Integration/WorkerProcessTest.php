<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\Support\ZipBuilder;
use PJGuardian\Tests\TestCase;

/**
 * 실제 워커 프로세스(tools/worker.php) 시험(명세 5.2, 5.4, 19.8, 19.10, P14). 별도 설정 파일·파일 DB·임시 폴더로 실행한다.
 * 분석은 가짜 분석기(PJG_FAKE_ANALYSIS)로: 성공·실패·크래시·대기(손자 프로세스)·출력 폭주.
 * 확인: 업로드 → 조립·해제 → 분석 → 성공까지 자동 진행, 취소·타임아웃 뒤 남은 프로세스 0개, 출력 한도, 비정상 종료 복구,
 * 단일 인스턴스, 정상·강제 정지, 오래 걸리는 해제를 되돌리지 않음.
 */
final class WorkerProcessTest extends TestCase
{
    private string $root;
    private string $ini;
    private App $app;
    private int $py;
    private int $kim;
    /** @var list<array{proc: resource, out: string}> */
    private array $workers = [];

    /** @param array<string, array<string, string>> $over */
    private function boot(array $over = []): void
    {
        $this->root = $this->tempDir('wproc');
        foreach (['uploads', 'work', 'logs', 'snapshots'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $base = [
            'paths' => [
                'db_file' => $this->root . '/db.sqlite', 'uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work',
                'logs_dir' => $this->root . '/logs', 'snapshots_dir' => $this->root . '/snapshots',
            ],
            'auth' => ['bcrypt_cost' => '4'],
            'upload' => ['chunk_size' => '256KB', 'max_zip_size' => '8MB'],
            'worker' => ['heartbeat_interval' => '1s', 'heartbeat_stale' => '3s', 'poll_interval' => '1s', 'cancel_check_interval' => '1s'],
        ];
        foreach ($over as $section => $keys) {
            $base[$section] = ($base[$section] ?? []) + $keys;
            foreach ($keys as $k => $v) {
                $base[$section][$k] = $v;
            }
        }
        $this->ini = TestApp::writeIni($this->root . '/config.ini', $base);
        TestDb::migrated($this->root . '/db.sqlite');
        $this->app = App::create(PJG_ROOT, Config::load($this->ini));
        $db = $this->app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $this->kim = TestDb::user($db, 'kim', 'user');
        $this->py = TestDb::project($db, 'py', 'python');
        $this->app->projectAccess()->add(Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], null), $this->py, ['subject_type' => 'user', 'login_id' => 'kim']);
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
    }

    public function tearDown(): void
    {
        foreach ($this->workers as $w) {
            if (is_resource($w['proc'])) {
                $st = proc_get_status($w['proc']);
                if ($st['running']) {
                    ProcessTree::kill((int) $st['pid']);
                }
                proc_close($w['proc']);
            }
        }
        $this->workers = [];
        // 시험이 중간에 실패해도 가짜 분석기의 자식·손자가 남지 않게(PID 파일에 적힌 것)
        foreach (isset($this->root) ? Fs::listDir($this->root) : [] as $f) {
            if (str_starts_with($f, 'pids')) {
                foreach (explode(' ', trim((string) @file_get_contents($this->root . '/' . $f))) as $pid) {
                    if (ProcessTree::imageName((int) $pid) === 'php.exe' || ProcessTree::imageName((int) $pid) === 'php') {
                        ProcessTree::kill((int) $pid);
                    }
                }
            }
        }
        unset($this->app);
        gc_collect_cycles();
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed>|null $fake PJG_FAKE_ANALYSIS
     * @return int 워커 번호
     */
    private function startWorker(array $args = [], ?array $fake = null): int
    {
        $n = count($this->workers);
        $out = $this->root . "/worker{$n}.out";
        $env = \PJGuardian\Services\Process\ProcessRunner::minimalEnv(sys_get_temp_dir());
        if ($fake !== null) {
            $env['PJG_FAKE_ANALYSIS'] = (string) json_encode($fake);
        }
        $proc = proc_open(
            [PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini, ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']],
            $pipes, PJG_ROOT, $env, ['bypass_shell' => true],
        );
        fclose($pipes[0]);
        $this->workers[] = ['proc' => $proc, 'out' => $out];
        return $n;
    }

    private function workerPid(int $n): int
    {
        return (int) proc_get_status($this->workers[$n]['proc'])['pid'];
    }

    /** 워커가 끝나기를 기다려 종료 코드를 돌려준다 */
    private function waitExit(int $n, float $timeout = 60): int
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($this->workers[$n]['proc']);
            if (!$st['running']) {
                return (int) $st['exitcode'];
            }
            usleep(50_000);
        }
        $this->fail("워커 {$n}가 {$timeout}초 안에 끝나지 않음\n" . $this->output($n));
    }

    private function output(int $n): string
    {
        return (string) @file_get_contents($this->workers[$n]['out']) . (string) @file_get_contents($this->workers[$n]['out'] . '.err');
    }

    private function waitFor(callable $cond, string $what, float $timeout = 30, ?int $worker = null): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($cond()) {
                return;
            }
            usleep(50_000);
        }
        $this->fail("{$what}: {$timeout}초 안에 되지 않음" . ($worker !== null ? "\n" . $this->output($worker) : ''));
    }

    private function status(int $id): ?string
    {
        $v = $this->app->db()->value('SELECT status FROM analyses WHERE id = ?', [$id]);
        return $v === null ? null : (string) $v;
    }

    private function queued(array $over = []): int
    {
        $pid = $over['project_id'] ?? $this->py;
        unset($over['project_id']);
        return TestDb::analysis($this->app->db(), $pid, $this->kim, ['status' => 'queued'] + $over);
    }

    /** @return list<int> */
    private function pids(string $file): array
    {
        $this->waitFor(static fn (): bool => is_file($file), 'PID 파일');
        return array_map('intval', explode(' ', trim((string) file_get_contents($file))));
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    private function send(TestClient $c, string $zip): string
    {
        $data = (string) file_get_contents($zip);
        $r = $c->post("/api/v1/projects/{$this->py}/uploads", ['file_name' => basename($zip), 'total_size' => strlen($data)]);
        $this->assertSame(201, $r->status, $r->body);
        $id = $r->decoded()['data']['upload_id'];
        foreach (str_split($data, 256 * 1024) as $n => $part) {
            $this->assertSame(200, $c->raw('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", $part, ['x-chunk-sha256' => hash('sha256', $part)])->status);
        }
        $this->assertSame(202, $c->post("/api/v1/uploads/{$id}/complete")->status);
        return $id;
    }

    private function workDirs(): array
    {
        return array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1));
    }

    // ---- 시험 ----

    public function testUploadRunsThroughToSucceededAndCleansUp(): void
    {
        $this->boot();
        $upload = $this->send($this->as('kim'), PJG_ROOT . '/samples/zips/python_sample.zip');
        $w = $this->startWorker(['--once'], ['mode' => 'succeed', 'seconds' => 1]);
        $this->assertSame(0, $this->waitExit($w), $this->output($w));
        $out = $this->output($w);
        $this->assertContains('조립 시작', $out);
        $this->assertContains('해제 시작', $out);
        $a = $this->app->db()->one('SELECT * FROM analyses WHERE upload_id = ?', [$upload]);
        $this->assertTrue($a !== null, '업로드 → 분석 생성' . "\n" . $out);
        $this->assertSame(['succeeded', 'fake-0.0.0'], [$a['status'], $a['tool_version']], $out);
        $this->assertTrue($a['started_at'] !== null && $a['finished_at'] !== null);
        $this->assertSame('queued', $this->app->db()->value('SELECT status FROM uploads WHERE id = ?', [$upload]));
        $this->assertFalse(is_dir($this->root . '/uploads/' . $upload), '성공 직후 zip(업로드 폴더) 삭제');
        $this->assertSame([], $this->workDirs(), '작업 디렉토리 삭제');
        $this->assertSame([], Fs::listDir($this->root . '/work/trash'), '휴지통도 비움');
        $hb = $this->app->workerState()->read();
        $this->assertSame('stopped', $hb['state'] ?? null);
        // 분석 작업 ID(job_id)가 자식 로그에 붙는다(명세 19.7)
        $log = implode('', array_map(fn (string $f): string => (string) file_get_contents($this->root . '/logs/' . $f), Fs::listDir($this->root . '/logs')));
        $this->assertContains('"job_id":' . $a['id'], $log);
        $this->assertContains('"job_id":"upload:' . $upload . '"', $log);
    }

    public function testCancelRunningAnalysisKillsWholeTree(): void
    {
        $this->boot();
        $pidFile = $this->root . '/pids';
        $id = $this->queued();
        $w = $this->startWorker(['--max-seconds=60'], ['mode' => 'hang', 'seconds' => 120, 'pid_file' => $pidFile]);
        $pids = $this->pids($pidFile);
        $this->assertSame(2, count(ProcessTree::aliveAmong($pids)), '분석 자식과 손자 실행 중');
        $this->assertSame($pids[0], (int) $this->app->db()->value('SELECT process_group_id FROM analyses WHERE id = ?', [$id]), '자식 PID 기록');
        $this->assertSame('running', $this->status($id));
        $t0 = microtime(true);
        $this->assertSame(200, $this->as('kim')->post("/api/v1/analyses/{$id}/cancel")->status);
        $this->waitFor(fn (): bool => $this->status($id) === 'cancelled', '취소 반영', 15, $w);
        $sec = microtime(true) - $t0;
        $this->assertTrue($sec < 5, "취소 요청 → cancelled {$sec}초");
        $this->assertSame([], ProcessTree::aliveAmong($pids), '취소 후 남은 프로세스 0개(손자 포함)');
        $this->app->workerState()->requestStop();
        $this->assertSame(0, $this->waitExit($w));
        $this->assertContains('cancelled', $this->output($w));
    }

    public function testTimeoutAndOutputLimitAndChildFailures(): void
    {
        $this->boot(['analysis' => ['timeout' => '3s', 'output_limit' => '1MB']]);
        $p = [];
        foreach (['t', 'o', 'f', 'c', 's'] as $n) {
            $p[$n] = TestDb::project($this->app->db(), 'proj_' . $n);
        }
        $pidFile = $this->root . '/pids';
        $ids = [];
        foreach ($p as $k => $pid) {
            $ids[$k] = $this->queued(['project_id' => $pid]);
        }
        $spec = ['analyses' => [
            (string) $ids['t'] => ['mode' => 'hang', 'seconds' => 120, 'pid_file' => $pidFile],
            (string) $ids['o'] => ['mode' => 'output'],
            (string) $ids['f'] => ['mode' => 'fail', 'seconds' => 0],
            (string) $ids['c'] => ['mode' => 'crash', 'seconds' => 0],
            (string) $ids['s'] => ['mode' => 'succeed', 'seconds' => 0],
        ]];
        $w = $this->startWorker(['--once'], $spec);
        $pids = $this->pids($pidFile);
        $this->assertSame(0, $this->waitExit($w, 90), $this->output($w));
        $rows = [];
        foreach ($ids as $k => $id) {
            $r = $this->app->db()->one('SELECT status, failure_code FROM analyses WHERE id = ?', [$id]);
            $rows[$k] = $r['status'] . ':' . ($r['failure_code'] ?? '');
        }
        $this->assertSame([
            't' => 'failed:timeout', 'o' => 'failed:output_limit', 'f' => 'failed:tool_failed', 'c' => 'failed:analysis_crashed', 's' => 'succeeded:',
        ], $rows, $this->output($w));
        $this->assertSame([], ProcessTree::aliveAmong($pids), '타임아웃 후 남은 프로세스 0개');
        $log = (string) file_get_contents($this->app->logger->fileFor(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $this->assertTrue(preg_match('/한도 1048576 bytes, 감지 (\d+) bytes, 최종 (\d+) bytes, 초과 (\d+) bytes/', $log, $m) === 1, '출력 한도 초과량 기록');
        fwrite(STDOUT, "      [측정] 출력 한도 1MB: 감지 {$m[1]} bytes, 최종 {$m[2]} bytes, 초과 {$m[3]} bytes\n");
    }

    public function testWalCheckpointOnStartAndAfterFailedAnalysis(): void
    {
        // P20S: 워커가 시작할 때와 분석이 끝날 때마다(실패도) 체크포인트. 임계값(16MB)을 넘고 한가하면 TRUNCATE로 WAL 파일을 줄인다
        $this->boot(['database' => ['wal_truncate_threshold' => '16MB']]);
        $file = $this->root . '/db.sqlite';
        $writer = new \PJGuardian\Support\Db($file);
        \PJGuardian\Support\WalCheckpoint::disableAuto($writer); // 분석 반영 연결처럼
        $writer->pdo()->exec('CREATE TABLE blob_t (id INTEGER PRIMARY KEY, b BLOB)');
        $grow = function () use ($writer): void {
            $writer->transaction(function (\PJGuardian\Support\Db $db): void {
                $st = $db->pdo()->prepare('INSERT INTO blob_t (b) VALUES (?)');
                for ($i = 0; $i < 80; $i++) {
                    $st->execute([random_bytes(256 * 1024)]);
                }
            });
        };
        $wal = static function () use ($file): int {
            clearstatcache();
            return is_file($file . '-wal') ? (int) filesize($file . '-wal') : 0;
        };
        $grow();
        $this->assertTrue($wal() > 16 * 1048576, '시작 전 WAL ' . $wal());
        $id = $this->queued();
        $w = $this->startWorker(['--once'], ['mode' => 'fail', 'seconds' => 4]);
        $this->waitFor(fn (): bool => $this->status($id) === 'running', '분석 시작', 30, $w);
        $this->assertTrue($wal() < 1048576, '워커 시작 때 TRUNCATE(지난 워커가 남긴 WAL 정리) ' . $wal());
        $grow(); // 분석이 도는 동안 큰 쓰기(반영과 같은 효과)
        $this->assertSame(0, $this->waitExit($w, 60), $this->output($w));
        $this->assertSame('failed', $this->status($id));
        $this->assertTrue($wal() < 1048576, '실패한 분석이 끝난 뒤에도 체크포인트 ' . $wal());
        $log = (string) file_get_contents($this->app->logger->fileFor(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        // P19C(사용자 결정): PASSIVE를 먼저 하고 그 뒤 WAL이 크면 TRUNCATE — 기록 방식 이름이 PASSIVE+TRUNCATE로 바뀜(P20S에서는 TRUNCATE)
        $this->assertSame(2, preg_match_all('/"message":"WAL 체크포인트".*?"mode":"PASSIVE\+TRUNCATE".*?"truncate":"done"/', $log), '시작 1번 + 분석 뒤 1번');
    }

    public function testRecoveryAfterWorkerIsKilled(): void
    {
        $this->boot();
        $pidFile = $this->root . '/pids';
        $id = $this->queued();
        $w = $this->startWorker([], ['mode' => 'hang', 'seconds' => 120, 'pid_file' => $pidFile]);
        $pids = $this->pids($pidFile);
        // P31(시간에 민감한 시험 안정화): 첫 생존 신호 전에 죽이면 신호 파일이 없어 24시간 안전망 판정(safety)이 되므로,
        // 새 생존 신호가 찍히는 것을 본 직후에 죽인다(고정 대기 대신 조건 대기)
        $state = $this->app->workerState();
        $this->waitFor(static fn (): bool => $state->secondsSinceBeat() !== null, '첫 생존 신호');
        $first = (string) ($state->read()['beat_at'] ?? '');
        $this->waitFor(static fn (): bool => (string) ($state->read()['beat_at'] ?? '') !== $first, '새 생존 신호');
        ProcessTree::killOne($this->workerPid($w)); // 워커 부모만 강제 종료(자식은 고아로 남음)
        $this->waitExit($w);
        $this->assertSame(2, count(ProcessTree::aliveAmong($pids)), '부모만 죽으면 자식·손자는 남는다');
        $since = (int) $state->secondsSinceBeat();
        $r = $this->app->workerRecovery()->recoverIfStale();
        if ($since < 3) {
            // 기대: 신호가 끊긴 지 판정 시간(3초) 전 → 아직 되돌리지 않음. 부하로 이미 3초가 지났으면 아래 "되돌림" 판정을 바로 확인한다(어느 쪽이든 판정은 정확해야 함)
            $this->assertSame('waiting', $r['state'], "신호가 끊긴 지 {$since}초: 아직 되돌리지 않음");
            $this->assertSame('running', $this->status($id));
            $this->waitFor(fn (): bool => (int) $state->secondsSinceBeat() >= 4, '판정 시간 + 1초(신호 시각은 초 단위)');
            $r = $this->app->workerRecovery()->recoverIfStale();
        }
        $this->assertSame(['recovered', 1, 1], [$r['state'], $r['analyses'], $r['killed']], '신호가 끊기고 잠금이 풀림 → 되돌림');
        $this->assertSame(['failed', 'worker_crashed'], array_values($this->app->db()->one('SELECT status, failure_code FROM analyses WHERE id = ?', [$id])));
        $this->assertSame([], ProcessTree::aliveAmong($pids), '남은 자식 0개');
    }

    public function testRestartRecoversImmediatelyAndSingleInstance(): void
    {
        $this->boot();
        $pidFile = $this->root . '/pids';
        $id = $this->queued();
        $w1 = $this->startWorker([], ['mode' => 'hang', 'seconds' => 120, 'pid_file' => $pidFile]);
        $pids = $this->pids($pidFile);
        // 단일 인스턴스: 두 번째 워커는 즉시 종료(코드 3)
        $t0 = microtime(true);
        $w2 = $this->startWorker(['--once']);
        $this->assertSame(3, $this->waitExit($w2, 20), $this->output($w2));
        $this->assertTrue(microtime(true) - $t0 < 5, '즉시 종료');
        $this->assertContains('이미 실행 중', $this->output($w2));
        $this->assertSame(2, count(ProcessTree::aliveAmong($pids)), '두 번째 워커는 첫 워커의 작업을 건드리지 않음');

        ProcessTree::killOne($this->workerPid($w1));
        $this->waitExit($w1);
        // 재기동: 잠금을 잡았으므로 신호 시간과 관계없이 즉시 복구
        $w3 = $this->startWorker(['--once']);
        $this->assertSame(0, $this->waitExit($w3), $this->output($w3));
        $this->assertContains('복구', $this->output($w3));
        $this->assertSame('failed', $this->status($id));
        $this->assertSame('worker_crashed', $this->app->db()->value('SELECT failure_code FROM analyses WHERE id = ?', [$id]));
        $this->assertSame([], ProcessTree::aliveAmong($pids), '재기동 복구 후 남은 자식 0개');
    }

    public function testGracefulStopWaitsAndForceStopKills(): void
    {
        $this->boot();
        $a = $this->queued();
        $w = $this->startWorker([], ['mode' => 'succeed', 'seconds' => 3]);
        $this->waitFor(fn (): bool => $this->status($a) === 'running', '실행 시작', 20, $w);
        $this->assertSame(0, (new \PJGuardian\Services\Process\ProcessRunner([], [PHP_BINARY]))->run(
            [PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini, '--stop'],
            new \PJGuardian\Services\Process\ProcessOptions(timeoutSeconds: 30),
        )->exitCode, 'worker.php --stop');
        $b = $this->queued(['project_id' => TestDb::project($this->app->db(), 'other')]);
        $this->assertSame(0, $this->waitExit($w));
        $this->assertSame('succeeded', $this->status($a), '정상 정지는 진행 중인 분석을 기다림');
        $this->assertSame('queued', $this->status($b), '정지 요청 뒤에는 새 작업을 잡지 않음');

        $pidFile = $this->root . '/pids2';
        $w2 = $this->startWorker(['--force-stop'], ['mode' => 'hang', 'seconds' => 120, 'pid_file' => $pidFile]);
        $pids = $this->pids($pidFile);
        $this->app->workerState()->requestStop();
        $this->assertSame(0, $this->waitExit($w2));
        $this->assertSame(['failed', 'worker_stopped'], array_values($this->app->db()->one('SELECT status, failure_code FROM analyses WHERE id = ?', [$b])));
        $this->assertSame([], ProcessTree::aliveAmong($pids), '강제 정지 후 남은 프로세스 0개');
    }

    public function testLongExtractionIsNotRevertedWhileWorkerIsAlive(): void
    {
        $this->boot(['upload' => ['max_zip_size' => '64MB']]);
        $zip = $this->root . '/many.zip';
        $b = new ZipBuilder($zip);
        for ($i = 0; $i < 20000; $i++) {
            $b->add(sprintf('src/pkg%03d/m%05d.py', intdiv($i, 100), $i), "x = {$i}\n");
        }
        $b->close();
        $upload = $this->send($this->as('kim'), $zip);
        $w = $this->startWorker(['--once'], ['mode' => 'succeed', 'seconds' => 0]);
        $this->waitFor(fn (): bool => $this->app->db()->value("SELECT work_dir FROM uploads WHERE id = ? AND status = 'extracting'", [$upload]) !== null, '해제 시작', 30, $w);
        $work = $this->app->db()->value('SELECT work_dir FROM uploads WHERE id = ?', [$upload]);
        $checks = [];
        $t0 = microtime(true);
        // 신호가 끊긴 것으로 보는 시간(3초)보다 오래 해제하는 동안 정리 작업의 판정을 반복해도 되돌리지 않는다
        $beats = [];
        while ($this->app->db()->value('SELECT status FROM uploads WHERE id = ?', [$upload]) === 'extracting') {
            $checks[] = $this->app->workerRecovery()->recoverIfStale()['state'];
            $beats[] = sprintf('%.1f:%s', microtime(true) - $t0, $this->app->workerState()->secondsSinceBeat() ?? '-');
            $this->assertSame($work, $this->app->db()->value('SELECT work_dir FROM uploads WHERE id = ?', [$upload]), '해제 중 work_dir 유지');
            usleep(300_000);
        }
        $sec = microtime(true) - $t0;
        $this->assertSame(0, $this->waitExit($w, 120), $this->output($w));
        // 잠금이 잡혀 있는 동안은 되돌리지 않는다. 디스크 부하로 신호 기록이 잠깐 늦으면 worker_unresponsive(경고만)일 수 있다
        $states = array_count_values($checks);
        $this->assertSame([], array_diff(array_keys($states), ['worker_alive', 'worker_unresponsive']), "살아 있는 워커의 해제는 되돌리지 않음\n" . $this->output($w) . implode(' ', $beats));
        $this->assertTrue($sec > 3, "해제가 신호 판정 시간(3초)보다 오래 걸려야 의미 있는 시험: {$sec}초");
        fwrite(STDOUT, sprintf("      [측정] 항목 20,000개 해제 %.1f초 동안 판정 %d회: %s, 신호 경과(초) 최대 %s\n", $sec, count($checks),
            json_encode($states), max(array_map(static fn (string $b): int => (int) explode(':', $b)[1], $beats))));
        $this->assertSame('succeeded', $this->app->db()->value('SELECT status FROM analyses WHERE upload_id = ?', [$upload]));
    }
}
