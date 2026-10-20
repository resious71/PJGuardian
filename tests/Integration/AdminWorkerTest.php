<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Admin\WorkerControl;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Services\Queue\Worker;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자 워커 화면(P20A): 상태 읽기, 정지·재시작 요청(파일 신호, supervised=false면 재시작 409), 시작 명령(미설정·proc_open 비활성·
 * 허용하지 않는 명령·인자 배열 실행·요청 값 미사용·제한 시간·종료 코드·출력 가림·이미 실행 중·동시 요청·감사에 명령 없음),
 * 로그 보기(끝에서 읽기·가림), 실제 워커 프로세스(오래된 정지 신호 무시, 재시작 요청 = 진행 중 분석을 끝낸 뒤 종료 코드 75,
 * 관리 작업 실행, 생존 신호의 Node·복구 정보).
 */
final class AdminWorkerTest extends TestCase
{
    private string $root;
    /** @var list<resource> */
    private array $procs = [];

    public function tearDown(): void
    {
        foreach ($this->procs as $p) {
            if (is_resource($p)) {
                $st = proc_get_status($p);
                if ($st['running']) {
                    ProcessTree::kill((int) $st['pid']);
                }
                proc_close($p);
            }
        }
        $this->procs = [];
        gc_collect_cycles();
    }

    /** @param array<string, array<string, string>> $over */
    private function app(array $over = [], ?\Closure $probe = null): App
    {
        $this->root ??= $this->tempDir('adminworker');
        foreach (['work', 'logs'] as $d) {
            Fs::ensureDir($this->root . '/' . $d);
        }
        $base = ['paths' => ['work_dir' => $this->root . '/work'], 'worker' => ['heartbeat_stale' => '30s', 'heartbeat_interval' => '1s']];
        foreach ($over as $s => $keys) {
            $base[$s] = ($base[$s] ?? []) + $keys;
            foreach ($keys as $k => $v) {
                $base[$s][$k] = $v;
            }
        }
        $app = TestApp::make($this->root . '/logs', $base);
        $app->setProcOpenProbe($probe);
        TestDb::user($app->db(), 'admin', 'admin');
        TestDb::user($app->db(), 'kim', 'user');
        return $app;
    }

    private function as(App $app, string $login = 'admin'): TestClient
    {
        $c = new TestClient($app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    /** 살아 있는 워커처럼 생존 신호를 쓴다(시험 시계 기준 $agoSeconds초 전) */
    private function beat(App $app, int $agoSeconds = 1, string $state = 'running'): void
    {
        $at = $app->clock->now()->modify("-{$agoSeconds} seconds")->format('Y-m-d\TH:i:s\Z');
        Fs::ensureDir($app->workerState()->dir());
        file_put_contents($app->workerState()->heartbeatFile(), (string) json_encode([
            'pid' => 4242, 'host' => 'h', 'version' => App::VERSION, 'started_at' => $at, 'beat_at' => $at, 'state' => $state,
            'analyses' => [], 'uploads' => [], 'node' => ['ok' => true, 'version' => '24.1.0', 'reason' => null, 'min' => '20.19.0', 'source' => 'path'],
            'last_recovery' => ['at' => $at, 'analyses' => 1, 'uploads' => 0, 'killed' => 0],
        ]));
    }

    private static function isWin(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public function testStatusStopAndRestartRequests(): void
    {
        $app = $this->app();
        $admin = $this->as($app);
        $s = $admin->get('/api/v1/admin/worker')->decoded()['data'];
        $this->assertSame(['stopped', false, false], [$s['state'], $s['warning_no_worker'], $s['supervised']]);
        $pid = TestDb::project($app->db(), 'p', 'python');
        TestDb::analysis($app->db(), $pid, 1, ['status' => 'queued']);
        $this->assertTrue($admin->get('/api/v1/admin/worker')->decoded()['data']['warning_no_worker'], '대기 분석 + 워커 없음 → 경고');
        $this->assertSame([409, 'worker_not_running'], [$admin->post('/api/v1/admin/worker/stop')->status, $admin->post('/api/v1/admin/worker/stop')->decoded()['error']['code']]);

        $this->beat($app);
        TestDb::analysis($app->db(), $pid, 1, ['status' => 'running', 'started_at' => '2026-10-02T11:58:00Z']);
        $s = $admin->get('/api/v1/admin/worker')->decoded()['data'];
        $this->assertSame(['running', 4242, false, 1, 1], [$s['state'], $s['pid'], $s['warning_no_worker'], count($s['running_analyses']), $s['queued_analyses']]);
        $this->assertSame([120, 'p'], [$s['running_analyses'][0]['elapsed_seconds'], $s['running_analyses'][0]['project_name']], '실행 중 분석(전체 프로젝트)');
        $this->assertSame(['24.1.0', 1], [$s['node']['version'], $s['last_recovery']['analyses']]);

        $r = $admin->post('/api/v1/admin/worker/restart');
        $this->assertSame([409, 'restart_not_supervised'], [$r->status, $r->decoded()['error']['code']], 'supervised=false면 재시작 막힘');
        $this->assertFalse($app->workerState()->stopRequested());
        $ok = $admin->post('/api/v1/admin/worker/stop');
        $this->assertSame(200, $ok->status, $ok->body);
        $req = $app->workerState()->stopRequest();
        $this->assertSame(['stop', '2026-10-02T12:00:00Z'], [$req['kind'], $req['requested_at']]);
        $this->assertSame('stop', $ok->decoded()['data']['stop_request']['kind']);
        $this->assertSame(1, (int) $app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'worker.stop_request'"));

        $this->beat($app, 60);
        $this->assertSame('unresponsive', $admin->get('/api/v1/admin/worker')->decoded()['data']['state'], '신호가 heartbeat_stale보다 오래되면 응답 없음');
        $this->beat($app, 1, 'stopped');
        $this->assertSame('stopped', $admin->get('/api/v1/admin/worker')->decoded()['data']['state']);

        $sup = $this->app(['worker' => ['supervised' => 'true']]);
        $this->beat($sup);
        $r = $this->as($sup)->post('/api/v1/admin/worker/restart');
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('restart', $sup->workerState()->stopRequest()['kind']);
        $this->assertSame(1, (int) $sup->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'worker.restart_request'"));
    }

    public function testStartButtonVisibilityRules(): void
    {
        $app = $this->app();
        $admin = $this->as($app);
        $s = $admin->get('/api/v1/admin/worker')->decoded()['data']['start'];
        $this->assertSame([false, false, 'not_configured'], [$s['configured'], $s['available'], $s['reason']], '비어 있으면 숨김');
        $this->assertSame('start_not_configured', $admin->post('/api/v1/admin/worker/start')->decoded()['error']['code']);

        $off = $this->app(['worker' => ['start_command' => self::fakeCommand('ok')]], static fn (): bool => false);
        $c = $this->as($off);
        $this->assertSame([true, 'proc_open_disabled'], [$c->get('/api/v1/admin/worker')->decoded()['data']['start']['configured'], $c->get('/api/v1/admin/worker')->decoded()['data']['start']['reason']]);
        $this->assertSame('start_proc_open_disabled', $c->post('/api/v1/admin/worker/start')->decoded()['error']['code']);

        foreach (['notepad /x', 'cmd /c exit 0', '"unterminated', PHP_BINARY . ' ' . PJG_ROOT . '/tools/worker.php', 'C:\\no\\such\\file.exe'] as $cmd) {
            $bad = $this->app(['worker' => ['start_command' => $cmd]]);
            $r = $this->as($bad)->post('/api/v1/admin/worker/start');
            $this->assertSame([409, 'start_command_invalid'], [$r->status, $r->decoded()['error']['code'] ?? null], "거부: {$cmd}");
        }
        $this->assertSame(null, WorkerControl::parseCommand('a "b c'));
        $this->assertSame(['schtasks', '/run', '/tn', 'PJGuardian Worker'], WorkerControl::parseCommand('schtasks /run /tn "PJGuardian Worker"'));
        $this->assertSame(['sudo', '-n', 'systemctl', 'start', 'x;rm -rf /'], WorkerControl::parseCommand("sudo -n systemctl start 'x;rm -rf /'"), '셸 문법 없음: 그대로 인자 하나');
        $this->assertSame(null, WorkerControl::resolveExecutable('powershell'), '허용 목록 밖의 이름');
        $this->assertSame(null, WorkerControl::resolveExecutable(PHP_BINARY), 'PHP 직접 실행 금지');
    }

    /** 시험용 시작 명령(관리자가 config.ini에 적는 것과 같은 문자열). Windows는 cmd.exe가 따옴표 붙은 내장 명령을 못 읽어 시스템 프로그램을 쓴다 */
    private static function fakeCommand(string $kind): string
    {
        if (self::isWin()) {
            $sys = rtrim(str_replace('\\', '/', (string) (getenv('SystemRoot') ?: 'C:/Windows')), '/') . '/System32/';
            return match ($kind) {
                'exit3' => $sys . 'choice.exe /c abc /d c /t 0',
                'leak' => $sys . 'findstr.exe password=hunter2 D:\secret\deploy\dir\x.txt',
                default => $sys . 'hostname.exe',
            };
        }
        return match ($kind) {
            'exit3' => "/bin/sh -c 'exit 3'",
            'leak' => "/bin/sh -c 'echo cannot open /home/secret/deploy/x.txt password=hunter2 >&2; exit 1'",
            default => "/bin/sh -c 'exit 0'",
        };
    }

    public function testStartCommandRunsAsArgumentArrayWithResultAndAudit(): void
    {
        // 종료 코드 — 요청 값은 명령에 들어가지 않는다(본문을 무시)
        $fail = $this->app(['worker' => ['start_command' => self::fakeCommand('exit3')]]);
        $admin = $this->as($fail);
        $this->assertTrue($admin->get('/api/v1/admin/worker')->decoded()['data']['start']['available']);
        $r = $admin->post('/api/v1/admin/worker/start', ['command' => 'calc.exe', 'args' => ['/evil']]);
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->decoded()['data'];
        $this->assertSame([false, 3, null], [$d['ok'], $d['exit_code'], $d['reason']], '종료 코드 표시');
        $this->assertNotContains('evil', $r->body);
        $audit = $fail->db()->one("SELECT details FROM audit_log WHERE action = 'worker.start_request'");
        $this->assertSame(['ok' => false, 'exit_code' => 3, 'reason' => null], json_decode((string) $audit['details'], true), '감사: 성공 여부·종료 코드만(명령 문자열 없음)');
        $this->assertSame(3, $admin->get('/api/v1/admin/worker')->decoded()['data']['start']['last']['exit_code'], '마지막 결과 기록');
        $this->assertSame(200, $admin->post('/api/v1/admin/worker/start')->status, '실패 직후 재시도는 허용');

        // 출력 첫 줄은 서버 경로·비밀값을 가린다
        $leak = $this->app(['worker' => ['start_command' => self::fakeCommand('leak')]]);
        $d = $this->as($leak)->post('/api/v1/admin/worker/start')->decoded()['data'];
        $this->assertSame(false, $d['ok']);
        $this->assertTrue($d['output'] !== '', '출력 첫 줄 표시');
        $this->assertNotContains('secret', strtolower($d['output']), '서버 경로 가림: ' . $d['output']);
        $this->assertNotContains('hunter2', $d['output'], '비밀값 가림');

        // 성공한 시작 뒤 30초 안의 요청은 막는다(동시에 눌러도 하나만)
        $ok = $this->app(['worker' => ['start_command' => self::fakeCommand('ok')]]);
        @unlink($ok->workerState()->startRecordFile());
        $c = $this->as($ok);
        $d = $c->post('/api/v1/admin/worker/start')->decoded()['data'];
        $this->assertSame([true, 0], [$d['ok'], $d['exit_code']]);
        $this->assertSame([false, 'recently_requested'], [$c->get('/api/v1/admin/worker')->decoded()['data']['start']['available'],
            $c->get('/api/v1/admin/worker')->decoded()['data']['start']['reason']], '방금 시작함 → 버튼 비활성');
        $again = $c->post('/api/v1/admin/worker/start');
        $this->assertSame([409, 'start_in_progress'], [$again->status, $again->decoded()['error']['code']]);
        @unlink($ok->workerState()->startRecordFile());
        $lock = \PJGuardian\Support\FileLock::acquire($ok->workerState()->startLockFile());
        $this->assertSame('start_in_progress', $c->post('/api/v1/admin/worker/start')->decoded()['error']['code'], '다른 요청이 시작 중(start.lock)');
        $lock->release();
        $this->beat($ok);
        $this->assertSame('worker_already_running', $c->post('/api/v1/admin/worker/start')->decoded()['error']['code'], '이미 떠 있으면 거부');
        $this->assertSame(false, $c->get('/api/v1/admin/worker')->decoded()['data']['start']['available']);
        foreach ([$fail, $leak, $ok] as $a) {
            foreach ($a->db()->all("SELECT details FROM audit_log WHERE action = 'worker.start_request'") as $row) {
                $this->assertNotContains('.exe', strtolower((string) $row['details']));
                $this->assertNotContains('secret', (string) $row['details']);
            }
        }
    }

    public function testStartCommandTimeout(): void
    {
        $cmd = self::isWin() ? (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\ping.exe -n 30 127.0.0.1' : '/bin/sleep 30';
        $app = $this->app(['worker' => ['start_command' => $cmd, 'start_timeout' => '1s']]);
        $t0 = microtime(true);
        $d = $this->as($app)->post('/api/v1/admin/worker/start')->decoded()['data'];
        $this->assertSame([false, 'timeout'], [$d['ok'], $d['reason']]);
        $this->assertTrue(microtime(true) - $t0 < 10, '제한 시간에 멈춤');
    }

    public function testLogTailIsMaskedAndReadFromEnd(): void
    {
        $app = $this->app();
        $admin = $this->as($app); // 로그인 로그가 먼저 쓰이게(아래에서 파일을 새로 쓴다)
        $file = $app->logger->fileFor($app->clock->now());
        $h = fopen($file, 'wb');
        for ($i = 0; $i < 60000; $i++) { // 약 8MB
            fwrite($h, '{"ts":"2026-10-02T11:00:00Z","level":"INFO","request_id":"r' . $i . '","message":"web ' . $i . ' ' . str_repeat('x', 100) . "\"}\n");
        }
        fwrite($h, '{"ts":"2026-10-02T11:59:00Z","level":"WARN","request_id":null,"job_id":"upload:1","message":"worker line",' .
            '"context":{"path":"' . str_replace('/', '\\/', $this->root) . '\\/work\\/ab12cd34","dsn":"https://user:pw123@example.com/x","password":"hunter2"}}' . "\n");
        fwrite($h, '{"ts":"2026-10-02T11:59:01Z","level":"INFO","request_id":"rlast","message":"web last C:\\\\Users\\\\bob\\\\secret.txt"}' . "\n");
        fclose($h);
        $w = $admin->get('/api/v1/admin/logs', ['scope' => 'worker', 'lines' => '10'])->decoded()['data'];
        $this->assertSame(1, count($w['lines']), '워커·CLI 줄만(request_id 없음)');
        $line = $w['lines'][0];
        $this->assertContains('worker line', $line);
        $this->assertNotContains($this->root, str_replace('\\/', '/', $line), '서버 경로 가림');
        $this->assertNotContains('hunter2', $line);
        $this->assertNotContains('pw123', $line);
        $this->assertContains('<work>', $line);
        $a = $admin->get('/api/v1/admin/logs', ['scope' => 'all', 'lines' => '50'])->decoded()['data'];
        $this->assertSame(50, count($a['lines']));
        $this->assertContains('web last', end($a['lines']));
        $this->assertNotContains('bob', end($a['lines']), '드라이브 경로 가림');
        $this->assertSame(basename($file), $a['file'], '파일 이름만');
        $this->assertSame(400, $admin->get('/api/v1/admin/logs', ['scope' => 'etc'])->status);
        $t = \PJGuardian\Support\LogTail::read($file, 5);
        $this->assertTrue($t['bytes_read'] < 200_000, "끝에서 읽기: {$t['bytes_read']} bytes");
    }

    // ---- 실제 워커 프로세스 ----

    private string $ini;
    private App $papp;

    private function bootProcess(array $worker = []): void
    {
        $this->root = $this->tempDir('adminworkerproc');
        foreach (['uploads', 'work', 'logs', 'snapshots'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->ini = TestApp::writeIni($this->root . '/config.ini', [
            'paths' => ['db_file' => $this->root . '/db.sqlite', 'uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work',
                'logs_dir' => $this->root . '/logs', 'snapshots_dir' => $this->root . '/snapshots', 'bin_dir' => $this->root . '/bin'],
            'auth' => ['bcrypt_cost' => '4'],
            'worker' => $worker + ['heartbeat_interval' => '1s', 'heartbeat_stale' => '3s', 'poll_interval' => '1s', 'cancel_check_interval' => '1s'],
        ]);
        TestDb::migrated($this->root . '/db.sqlite');
        $this->papp = App::create(PJG_ROOT, Config::load($this->ini));
        TestDb::user($this->papp->db(), 'admin', 'admin');
    }

    /** @return array{proc: resource, out: string} */
    private function startWorker(array $args = [], ?array $fake = null): array
    {
        $out = $this->root . '/worker' . count($this->procs) . '.out';
        $env = ProcessRunner::minimalEnv(sys_get_temp_dir());
        if ($fake !== null) {
            $env['PJG_FAKE_ANALYSIS'] = (string) json_encode($fake);
        }
        $proc = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini, ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, PJG_ROOT, $env, ['bypass_shell' => true]);
        fclose($pipes[0]);
        $this->procs[] = $proc;
        return ['proc' => $proc, 'out' => $out];
    }

    private function waitFor(callable $cond, string $what, float $timeout, array $w): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($cond()) {
                return;
            }
            usleep(50_000);
        }
        $this->fail("{$what}: {$timeout}초 안에 되지 않음\n" . @file_get_contents($w['out']) . @file_get_contents($w['out'] . '.err'));
    }

    private function waitExit(array $w, float $timeout = 60): int
    {
        $code = null;
        $this->waitFor(static function () use ($w, &$code): bool {
            $st = proc_get_status($w['proc']);
            if ($st['running']) {
                return false;
            }
            $code = (int) $st['exitcode'];
            return true;
        }, '워커 종료', $timeout, $w);
        return (int) $code;
    }

    public function testStaleSignalIgnoredAndRestartWaitsForAnalysis(): void
    {
        $this->bootProcess(['supervised' => 'true']);
        $db = $this->papp->db();
        $pid = TestDb::project($db, 'p', 'python');
        $a = TestDb::analysis($db, $pid, 1, ['status' => 'queued']);
        $state = $this->papp->workerState();
        $w = $this->startWorker(['--max-seconds=60'], ['mode' => 'succeed', 'seconds' => 3]);
        $this->waitFor(static fn (): bool => $db->value('SELECT status FROM analyses WHERE id = ?', [$a]) === 'running', '분석 시작', 20, $w);
        $this->waitFor(static fn (): bool => isset($state->read()['node']), '생존 신호의 Node 정보', 10, $w);
        $hb = $state->read();
        $this->assertSame(['20.19.0', 'path'], [$hb['node']['min'], $hb['node']['source']], '생존 신호에 Node 검사 결과(경로 없음)');
        $this->assertTrue(isset($hb['last_recovery']['at']) && !isset($hb['node']['path']));

        // 워커가 뜨기 전의 요청(지난 워커에 보낸 신호)은 무시하고 지운다
        file_put_contents($state->stopFile(), (string) json_encode(['kind' => 'stop', 'requested_at' => '2020-01-01T00:00:00Z', 'by' => null]));
        $this->waitFor(static function () use ($state): bool {
            clearstatcache();
            return !is_file($state->stopFile());
        }, '오래된 신호 삭제', 10, $w);
        $this->assertTrue(proc_get_status($w['proc'])['running'], '오래된 신호로 멈추지 않음');

        // 재시작 요청: 진행 중인 분석을 끝낸 뒤 종료 코드 75(서비스 관리자가 다시 띄움)
        $client = new TestClient($this->papp);
        $client->login('admin', 'Passw0rd!x');
        $r = $client->post('/api/v1/admin/worker/restart');
        $this->assertSame(200, $r->status, $r->body);
        $this->waitFor(static fn (): bool => ($state->read()['state'] ?? '') === 'stopping', '정지 중 표시', 10, $w);
        $this->assertSame('stopping', $client->get('/api/v1/admin/worker')->decoded()['data']['state']);
        $this->assertSame(Worker::RESTART_EXIT_CODE, $this->waitExit($w));
        $this->assertSame('succeeded', $db->value('SELECT status FROM analyses WHERE id = ?', [$a]), '진행 중인 분석은 끝낸 뒤 종료');
        clearstatcache();
        $this->assertFalse(is_file($state->stopFile()), '처리한 신호는 지움(다시 뜬 워커가 바로 멈추지 않게)');
        $this->assertSame(['stopped', 'restart'], [$state->read()['state'], $state->read()['exit']]);
        $this->assertSame('stopped', $client->get('/api/v1/admin/worker')->decoded()['data']['state'], '하트비트가 멈추면 중지로 표시');
    }

    public function testWorkerRunsAdminTasksInChildren(): void
    {
        $this->bootProcess();
        $tasks = $this->papp->adminTasks();
        $disk = $tasks->request('disk_scan', '', 1)['task'];
        $clean = $tasks->request('cleanup_run', '', 1)['task'];
        $verify = $tasks->request('tool_verify', 'ruff@0.0.1', 1)['task'];
        $w = $this->startWorker(['--once']);
        $this->assertSame(0, $this->waitExit($w, 120));
        $this->assertSame('succeeded', $tasks->get($disk['id'])['status']);
        $usage = $this->papp->diskUsage()->cached();
        $this->assertTrue(isset($usage['categories']['db']) && $usage['categories']['db']['bytes'] > 0, '디스크 사용량 캐시');
        $this->assertTrue(is_array($usage['cleanup_preview']), '정리 대기 미리보기(dry-run)');
        $this->assertSame('succeeded', $tasks->get($clean['id'])['status']);
        $last = json_decode((string) file_get_contents($this->root . '/work/admin/cleanup_last.json'), true);
        $this->assertSame(['admin', true], [$last['source'], $last['ok']], '정리 작업 마지막 실행 기록');
        $v = $tasks->get($verify['id']);
        $this->assertSame('failed', $v['status'], '디스크에 없는 버전의 재검증은 실패로 기록');
        $this->assertNotContains($this->root, (string) json_encode($v['result']), '결과에 서버 경로 없음');
        $out = (string) file_get_contents($w['out']);
        $this->assertContains('관리 작업 #' . $disk['id'], $out);
    }
}
