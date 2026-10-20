<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\RepoFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * "지금 가져와 분석" 전체 흐름(P25) — 실제 워커 프로세스(tools/worker.php) + 실제 git(bin/의 MinGit) + 실제 ruff + 로컬 HTTP 저장소.
 * 확인: 큐 → 워커 → 가져오기(자식 프로세스) → 업로드와 같은 반영(결함 저장·Run·자동 resolved·비교 기준) → 출처·커밋 기록, 작업·임시 폴더 정리,
 * 대상 파일 0개면 failed(repo_empty)이고 기존 결함은 그대로, 가져오는 중 취소 → cancelled + 폴더 정리, 워커가 가져오는 도중 죽으면 다음 기동 때 정리.
 */
final class RepoWorkerTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private string $root;
    private string $ini;
    private App $app;
    private RepoFixture $fx;
    private int $p;
    private int $admin;
    /** @var resource|null */
    private $worker = null;

    private function boot(): bool
    {
        if (RepoFixture::git() === null || RepoFixture::httpBackend() === null || !is_file(PJG_ROOT . '/bin/ruff/0.16.10/windows/ruff.exe')) {
            fwrite(STDOUT, "      [건너뜀] bin/git·ruff 또는 git-http-backend 없음\n");
            return false;
        }
        $this->root = $this->tempDir('repowork');
        foreach (['uploads', 'work', 'logs', 'snapshots'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->ini = TestApp::writeIni($this->root . '/config.ini', [
            'paths' => ['db_file' => $this->root . '/db.sqlite', 'uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work',
                'logs_dir' => $this->root . '/logs', 'snapshots_dir' => $this->root . '/snapshots'],
            'auth' => ['bcrypt_cost' => '4'],
            'worker' => ['heartbeat_interval' => '1s', 'heartbeat_stale' => '3s', 'poll_interval' => '1s', 'cancel_check_interval' => '1s'],
            'repository' => ['allow_local_dev' => 'true'],
            'disk' => ['block_percent' => '99', 'warn_percent' => '98'],
        ]);
        file_put_contents($this->root . '/secrets.ini', 'encryption_key = "' . self::KEY . "\"\n");
        TestDb::migrated($this->root . '/db.sqlite');
        $this->app = App::create(PJG_ROOT, Config::load($this->ini, $this->root . '/secrets.ini'));
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        $this->p = TestDb::project($db, 'repo', 'python');
        $this->fx = new RepoFixture($this->root . '/fx');
        return true;
    }

    public function tearDown(): void
    {
        if (is_resource($this->worker)) {
            $st = proc_get_status($this->worker);
            if ($st['running']) {
                ProcessTree::kill((int) $st['pid']);
            }
            proc_close($this->worker);
        }
        if (isset($this->fx)) {
            $this->fx->release();
        }
        unset($this->app);
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->admin, 'login_id' => 'admin'], '127.0.0.1');
    }

    private function connect(string $url): void
    {
        $this->app->repoAllowedHosts()->add($this->actor(), $this->admin, ['host' => '127.0.0.1', 'allow_http' => true]);
        $this->app->repoConnections()->save($this->actor(), $this->admin, $this->p, ['kind' => 'git', 'url' => $url, 'secret' => 'tok-1234']);
    }

    /** 워커를 --once로 돌려 큐가 빌 때까지 */
    private function runWorker(): string
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini, '--once'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return $out;
    }

    private function fetchNow(): int
    {
        return $this->app->repoService()->fetchNow($this->actor(), $this->admin, $this->p)['analysis_id'];
    }

    /** @return array<string, mixed> */
    private function analysis(int $id): array
    {
        return $this->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]);
    }

    /** @needs-bin bin/git·bin/ruff 실행 파일과 git-http-backend */
    public function testFetchAnalyzeLikeUploadThenResolveAndEmptyKeepsDefects(): void
    {
        if (!$this->boot()) {
            return;
        }
        $c1 = $this->fx->commit('app', ['app/main.py' => "import os\nimport sys\n", 'app/util.py' => "import json\n"]);
        $this->fx->start(['auth' => 'x-access-token:tok-1234']);
        $this->connect($this->fx->url('app'));
        $id = $this->fetchNow();
        $out = $this->runWorker();
        $a = $this->analysis($id);
        $this->assertSame('succeeded', $a['status'], $out . json_encode($a));
        $this->assertSame([$c1, 'main', 'git', 1, 3], [$a['source_revision'], $a['source_ref'], $a['source_kind'], (int) $a['run_number'], (int) $a['new_count']]);
        $this->assertTrue($a['fetched_at'] !== null);
        $this->assertSame(3, (int) $this->app->db()->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status = 'open'", [$this->p]));
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1)), '작업 디렉토리 정리');
        $this->assertSame([], is_dir($this->root . '/work/fetch') ? Fs::listDir($this->root . '/work/fetch') : [], '가져오기 임시 폴더 정리');
        $conn = $this->app->repoConnections()->view($this->p, false);
        $this->assertSame(['ok', $c1], [$conn['status'], $conn['last_analyzed']['revision']]);
        // 두 번째 커밋: util.py 삭제 → 자동 resolved(파일 삭제), 비교 기준 = 직전 성공
        $c2 = $this->fx->commit('app', ['app/util.py' => null]);
        $id2 = $this->fetchNow();
        $this->runWorker();
        $a2 = $this->analysis($id2);
        $this->assertSame(['succeeded', $c2, 1, $id], [$a2['status'], $a2['source_revision'], (int) $a2['resolved_count'], (int) $a2['baseline_analysis_id']]);
        // 대상 확장자 0개 → failed(repo_empty), 기존 결함 그대로(일괄 resolved 없음)
        $this->fx->commit('app', ['app/main.py' => null, 'README.md' => "only docs\n"]);
        $open = (int) $this->app->db()->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status = 'open'", [$this->p]);
        $id3 = $this->fetchNow();
        $this->runWorker();
        $a3 = $this->analysis($id3);
        $logs = '';
        foreach (Fs::listDir($this->root . '/logs') as $f) {
            $logs .= implode("\n", array_filter(explode("\n", (string) file_get_contents($this->root . '/logs/' . $f)), static fn (string $l): bool => str_contains($l, '"ERROR"') || str_contains($l, 'child_stderr')));
        }
        $this->assertSame(['failed', 'repo_empty'], [$a3['status'], $a3['failure_code']], mb_substr($logs, 0, 3000));
        $this->assertSame($open, (int) $this->app->db()->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status = 'open'", [$this->p]), '기존 결함 보존');
        $this->assertSame('error', $this->app->repoConnections()->row($this->p)['last_fetch_status']);
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1)), '실패도 정리');
        // 토큰은 헤더로만(URL·로그에 없음)
        foreach ($this->fx->requests() as $q) {
            $this->assertNotContains('tok-1234', $q['uri']);
        }
        foreach (Fs::listDir($this->root . '/logs') as $f) {
            $this->assertNotContains('tok-1234', (string) file_get_contents($this->root . '/logs/' . $f), "로그 {$f}에 토큰 없음");
        }
    }

    /** @needs-bin bin/git·bin/ruff 실행 파일과 git-http-backend */
    public function testCancelDuringFetchAndCrashRecoveryLeaveNothing(): void
    {
        if (!$this->boot()) {
            return;
        }
        $this->fx->commit('app', ['a.py' => "import os\n"]);
        $this->fx->start(['slow' => 8]);
        $this->connect($this->fx->url('app'));
        $id = $this->fetchNow();
        $this->worker = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini], [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/w.out', 'a'], 2 => ['file', $this->root . '/w.out', 'a']],
            $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        $t0 = microtime(true);
        while (microtime(true) - $t0 < 20 && !is_dir($this->root . '/work/fetch/' . $id)) {
            usleep(100_000);
        }
        $this->assertTrue(is_dir($this->root . '/work/fetch/' . $id), '가져오는 중');
        $this->app->analyses()->cancel(['id' => $this->admin, 'role' => 'admin'], $id);
        while (microtime(true) - $t0 < 40 && $this->analysis($id)['status'] === 'running') {
            usleep(200_000);
        }
        $this->assertSame('cancelled', $this->analysis($id)['status']);
        usleep(500_000);
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1)), '취소 뒤 작업 디렉토리 없음');
        $this->assertFalse(is_dir($this->root . '/work/fetch/' . $id), '취소 뒤 가져오기 폴더 없음');
        // 워커가 가져오는 도중 죽음 → 다음 기동 때 worker_crashed + 폴더 정리
        $id2 = $this->fetchNow();
        while (microtime(true) - $t0 < 60 && !is_dir($this->root . '/work/fetch/' . $id2)) {
            usleep(100_000);
        }
        $st = proc_get_status($this->worker);
        ProcessTree::kill((int) $st['pid']);
        proc_close($this->worker);
        $this->worker = null;
        usleep(500_000);
        $this->runWorker();
        $a = $this->analysis($id2);
        $this->assertSame(['failed', 'worker_crashed'], [$a['status'], $a['failure_code']]);
        $this->assertFalse(is_dir($this->root . '/work/fetch/' . $id2), '다음 기동 때 가져오기 폴더 정리');
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1)), '작업 디렉토리도 정리');
    }
}
