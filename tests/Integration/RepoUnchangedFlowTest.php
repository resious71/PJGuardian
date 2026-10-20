<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Repo\UnchangedSourceCheck;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Config;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\RepoFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 성공(변경 없음) 실제 흐름(P27). Git = 실제 워커(tools/worker.php) + 실제 git(bin/의 MinGit) + 실제 ruff + 로컬 HTTP 저장소,
 * 스케줄러는 같은 DB·같은 설정에 FixedClock을 넣은 앱으로 틱(시계 주입). Perforce = 가짜 p4(이 PC에 p4d 없음, 인자만 확인).
 *
 * - 처음 → 분석(Run 1, 연결·조건 키 기록) → 같은 헤드로 "지금 가져와 분석" → 워커가 가져오기 전에 판단해 변경 없음(복제 요청 없음, Run·결함·run_summary 그대로, 알림 없음)
 * - 주기 도래 2번 → 같은 행 확인 횟수 3, 큐 등록 없음 → 새 커밋 → 주기 분석 등록·Run 2(기준 = Run 1)
 * - 강제 분석(force) → Run 생성, 규칙 켜기·끄기 개정 → 분석, 하위 경로 설정(연결 변경) → 분석, 하위 경로 밖 커밋 → 분석(보수적 한계)
 * - 원격 조회 실패 → 변경 없음 아님(check_error) → 주기 분석은 평소처럼 등록(가져오기가 재시도·실패 기록)
 */
final class RepoUnchangedFlowTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
    private const FP = 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01';

    private string $root;
    private string $ini;
    private App $app;
    private RepoFixture $fx;
    private int $p;
    private int $admin;

    private function boot(): bool
    {
        if (RepoFixture::git() === null || RepoFixture::httpBackend() === null || !is_file(PJG_ROOT . '/bin/ruff/0.16.10/windows/ruff.exe')) {
            fwrite(STDOUT, "      [건너뜀] bin/git·ruff 또는 git-http-backend 없음\n");
            return false;
        }
        $this->root = $this->tempDir('repounch');
        foreach (['uploads', 'work', 'logs', 'snapshots'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->ini = TestApp::writeIni($this->root . '/config.ini', [
            'paths' => ['db_file' => $this->root . '/db.sqlite', 'uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work',
                'logs_dir' => $this->root . '/logs', 'snapshots_dir' => $this->root . '/snapshots'],
            'auth' => ['bcrypt_cost' => '4'],
            'worker' => ['heartbeat_interval' => '1s', 'heartbeat_stale' => '3s', 'poll_interval' => '1s', 'cancel_check_interval' => '1s'],
            // P31: 원격 조회 시간 상한은 이 시험의 대상이 아니다 — 부하(다른 프로젝트 실행 등)에서 git 조회가 20초를 넘어 check_error가 난 적이 있어 넉넉히
            'repository' => ['allow_local_dev' => 'true', 'test_timeout' => '60'],
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
        if (isset($this->fx)) {
            $this->fx->release();
        }
        unset($this->app);
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->admin, 'login_id' => 'admin'], '127.0.0.1');
    }

    private function save(array $in): void
    {
        $rev = $this->app->repoConnections()->row($this->p)['revision'] ?? null;
        $this->app->repoConnections()->save($this->actor(), $this->admin, $this->p, $in + ['kind' => 'git', 'url' => $this->fx->url('app'), 'secret' => 'tok-1234']
            + ($rev === null ? [] : ['revision' => (int) $rev]));
    }

    private function runWorker(): string
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini, '--once'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return $out;
    }

    private function fetchNow(bool $force = false): int
    {
        return $this->app->repoService()->fetchNow($this->actor(), $this->admin, $this->p, $force)['analysis_id'];
    }

    /** @return array<string, mixed> */
    private function analysis(int $id): array
    {
        return $this->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]);
    }

    private function clones(): int
    {
        return count(array_filter($this->fx->requests(), static fn (array $q): bool => $q['method'] === 'POST' && str_contains($q['uri'], 'git-upload-pack')));
    }

    /** @needs-bin bin/git·bin/ruff 실행 파일과 git-http-backend */
    public function testGitSameHeadSkipsThenNewCommitForceRulesSubpathAndFailure(): void
    {
        if (!$this->boot()) {
            return;
        }
        $db = $this->app->db();
        $c1 = $this->fx->commit('app', ['app/main.py' => "import os\nimport sys\n", 'tools/x.py' => "import json\n"]);
        $this->fx->start(['auth' => 'x-access-token:tok-1234']);
        $this->app->repoAllowedHosts()->add($this->actor(), $this->admin, ['host' => '127.0.0.1', 'allow_http' => true]);
        $this->save(['branch' => 'main']);

        // 1) 처음: 분석(Run 1) + 연결·조건 키 기록
        $id1 = $this->fetchNow();
        $out = $this->runWorker();
        $a1 = $this->analysis($id1);
        $this->assertSame(['succeeded', 1, $c1], [$a1['status'], (int) $a1['run_number'], $a1['source_revision']], $out);
        $this->assertSame([64, 64], [strlen((string) $a1['source_key']), strlen((string) $a1['env_key'])], '실제 분석에 키 기록');
        $this->assertSame($a1['env_key'], $this->app->analysisEnvKey()->compute($this->p), '웹·워커가 같은 조건 키');
        $this->assertTrue($this->clones() >= 1, '처음에는 복제(git-upload-pack POST)');
        $defects = $db->all('SELECT id, status, last_seen_analysis_id FROM defects WHERE project_id = ? ORDER BY id', [$this->p]);
        $summary = $a1['run_summary'];

        // 2) 같은 헤드로 "지금 가져와 분석" → 워커가 가져오기 전에 판단 → 변경 없음(내려받기·도구·결함·Run 없음)
        $n0 = count($this->fx->requests());
        $id2 = $this->fetchNow();
        $this->runWorker();
        $a2 = $this->analysis($id2);
        $this->assertSame(['succeeded', 1, null, $id1, 1, $c1, null, null], [$a2['status'], (int) $a2['no_change'], $a2['run_number'], (int) $a2['unchanged_base_analysis_id'],
            (int) $a2['unchanged_check_count'], $a2['source_revision'], $a2['work_dir'], $a2['total_count']]);
        // 내려받기 없음: 이 분석의 HTTP 요청 = ls-remote 한 번과 같은 모양(프로토콜 v2는 ls-refs도 POST라 POST 수만으로는 구분하지 않는다)
        $n1 = count($this->fx->requests());
        $this->app->unchangedCheck()->evaluate($this->p);
        $n2 = count($this->fx->requests());
        $this->assertSame($n2 - $n1, $n1 - $n0, 'ls-remote 한 번뿐(복제 없음): ' . json_encode(array_slice($this->fx->requests(), $n0)));
        $this->assertSame(null, $a2['fetched_at'], '가져오기 기록 없음');
        $lsReq = $n2 - $n1;
        $this->assertSame($defects, $db->all('SELECT id, status, last_seen_analysis_id FROM defects WHERE project_id = ? ORDER BY id', [$this->p]), '결함 그대로');
        $this->assertSame($summary, $this->analysis($id1)['run_summary'], 'run_summary 그대로');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM notifications WHERE analysis_id = ?', [$id2]), '알림 없음');
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1)), '작업 디렉토리 없음');
        $this->assertSame('unchanged', $this->app->repoConnections()->row($this->p)['last_check_result']);

        // 3) 주기 분석(시계 주입): 같은 헤드 → 같은 행 확인 횟수만 증가, 큐 등록 없음
        $m0 = count($this->fx->requests());
        $clock = new FixedClock(gmdate('Y-m-d\TH:i:s\Z'));
        $sched = App::create(PJG_ROOT, Config::load($this->ini, $this->root . '/secrets.ini'), $clock);
        $sched->schedules()->save($this->actor(), $this->admin, $this->p, ['enabled' => true, 'interval_days' => 1, 'run_time' => '03:00']);
        $due = function () use ($sched, $clock): array {
            $next = strtotime((string) $sched->db()->value('SELECT next_run_at FROM project_schedules WHERE project_id = ?', [$this->p]));
            $clock->advance($next + 30 - $clock->now()->getTimestamp());
            return $sched->scheduleTicker()->tick();
        };
        $t = $due();
        $this->assertSame([1, 0, 1], [$t['due'], $t['queued'], $t['unchanged']], json_encode($t));
        $due();
        $a2 = $this->analysis($id2);
        $this->assertSame([3, 0], [(int) $a2['unchanged_check_count'], (int) $db->value("SELECT COUNT(*) FROM analyses WHERE status IN ('queued', 'running')")]);
        $this->assertSame(['unchanged', 'unchanged'], array_column($db->all('SELECT result FROM schedule_ticks WHERE project_id = ? ORDER BY id', [$this->p]), 'result'));
        $this->assertSame(2, (int) $db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$this->p]), '이력 행이 늘지 않음');
        $this->assertSame(2 * $lsReq, count($this->fx->requests()) - $m0, '주기 확인 2번 = ls-remote 2번(복제 없음)');
        $s = $db->one('SELECT last_result, consecutive_failures, last_analysis_id FROM project_schedules WHERE project_id = ?', [$this->p]);
        // P27C(사용자 결정): 변경 없음은 주기 결과에 중립 — 마지막 결과를 바꾸지 않는다(주기 분석이 아직 한 번도 끝나지 않아 null). P27에서는 succeeded였다
        $this->assertSame([null, 0, $id2], [$s['last_result'], (int) $s['consecutive_failures'], (int) $s['last_analysis_id']]);

        // 4) 새 커밋 → 주기 분석 등록 → Run 2(기준 = Run 1, 변경 없음은 기준이 아님)
        $c2 = $this->fx->commit('app', ['app/main.py' => "import os\nimport sys\nimport re\n"]);
        $t = $due();
        $this->assertSame(1, $t['queued']);
        $this->runWorker();
        $id3 = (int) $db->value('SELECT MAX(id) FROM analyses WHERE project_id = ?', [$this->p]);
        $a3 = $this->analysis($id3);
        $this->assertSame(['succeeded', 0, 2, $id1, $c2, 'schedule'], [$a3['status'], (int) $a3['no_change'], (int) $a3['run_number'], (int) $a3['baseline_analysis_id'],
            $a3['source_revision'], $a3['trigger_kind']]);

        // 5) 강제 분석: 같은 헤드여도 force면 일반 분석(Run 3, 플래그)
        $id4 = $this->fetchNow();
        $this->runWorker();
        $this->assertSame([1, $id3], [(int) $this->analysis($id4)['no_change'], (int) $this->analysis($id4)['unchanged_base_analysis_id']]);
        $id5 = $this->fetchNow(true);
        $this->runWorker();
        $a5 = $this->analysis($id5);
        $this->assertSame(['succeeded', 0, 3, 1, $id3], [$a5['status'], (int) $a5['no_change'], (int) $a5['run_number'], (int) $a5['force_analysis'], (int) $a5['baseline_analysis_id']]);

        // 6) 규칙 켜기·끄기 개정 → 같은 커밋이어도 분석(판단만 확인: 실제 원격 ls-remote)
        $check = $this->app->unchangedCheck();
        $r = $check->evaluate($this->p);
        $this->assertSame(['unchanged', 'same'], [$r['decision'], $r['reason']], json_encode($r));
        $db->execute("INSERT INTO rule_override_state (tool, revision, updated_at) VALUES ('ruff', 1, ?) ON CONFLICT (tool) DO UPDATE SET revision = revision + 1", [TestDb::T0]);
        $this->assertSame(['changed', 'tool_or_config_changed'], [($r = $check->evaluate($this->p))['decision'], $r['reason']]);
        $db->execute("DELETE FROM rule_override_state WHERE tool = 'ruff'");
        // 제외 패턴 변경 → 분석
        $db->execute('UPDATE projects SET exclude_patterns = ? WHERE id = ?', ['["tools/**"]', $this->p]);
        $this->assertSame('tool_or_config_changed', $check->evaluate($this->p)['reason']);
        $db->execute("UPDATE projects SET exclude_patterns = '[]' WHERE id = ?", [$this->p]);
        $this->assertSame('same', ($r = $check->evaluate($this->p))['reason'], json_encode($r));

        // 7) 하위 경로 설정(연결 변경) → 분석, 그 뒤 하위 경로 밖만 바뀐 커밋 → 헤드가 달라져 분석(보수적 한계)
        $this->save(['branch' => 'main', 'path' => 'app']);
        $this->assertSame('connection_changed', $check->evaluate($this->p)['reason']);
        $id6 = $this->fetchNow();
        $this->runWorker();
        $this->assertSame([0, 4], [(int) $this->analysis($id6)['no_change'], (int) $this->analysis($id6)['run_number']]);
        $this->assertSame('same', ($r = $check->evaluate($this->p))['reason'], json_encode($r));
        $this->fx->commit('app', ['tools/x.py' => "import json\nimport csv\n"]);
        $this->assertSame(['changed', 'revision_differs'], [($r = $check->evaluate($this->p))['decision'], $r['reason']]);

        // 8) 원격 조회 실패 → 변경 없음이 아님 → 주기 분석은 평소처럼 등록(가져오기가 재시도·실패 기록)
        $this->fx->stop();
        $r = $check->evaluate($this->p);
        $this->assertSame(['changed', 'check_error'], [$r['decision'], $r['reason']]);
        $this->assertTrue(str_starts_with((string) $r['code'], 'repo_'), (string) $r['code']);
        $this->assertSame(1, $due()['queued'], '조회 실패는 생략하지 않는다');
        $this->assertSame('error', $db->value('SELECT last_check_result FROM project_repo_connections WHERE project_id = ?', [$this->p]));
        // 모든 로그에 토큰 없음
        foreach (Fs::listDir($this->root . '/logs') as $f) {
            $this->assertNotContains('tok-1234', (string) file_get_contents($this->root . '/logs/' . $f), "로그 {$f}");
        }
    }

    public function testPerforceSameChangelistSkipsWithoutPrint(): void
    {
        $root = $this->tempDir('p4unch');
        Fs::ensureDir($root . '/work');
        $app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work']], ['encryption_key' => self::KEY]);
        $state = $root . '/fake_p4.json';
        $log = $root . '/p4.log';
        $write = static fn (int $change) => file_put_contents($state, json_encode(['password' => 'pw-SECRET-1', 'ticket' => 'TICKET-ABCDEF123456', 'fingerprint' => self::FP,
            'change' => $change, 'depot' => '//depot/proj/', 'files' => ['main.py' => "import os\n"], 'deleted' => [], 'log' => $log]));
        $write(4321);
        $app->setRepoTesting(['p4' => [PHP_BINARY, PJG_ROOT . '/tests/Support/fake_p4.php', $state]], static fn (string $h): array => $h === 'p4.example.test' ? ['10.20.30.40'] : []);
        // 도구 레지스트리는 가짜 ruff(조건 키의 도구 버전만 필요 — 실제 스모크를 돌리지 않는다)
        $bin = $root . '/bin';
        $os = ToolRegistry::os();
        mkdir("{$bin}/ruff/0.16.10/{$os}", 0700, true);
        $rel = ToolRegistry::executableRel('ruff');
        file_put_contents("{$bin}/ruff/0.16.10/{$rel}", 'fake');
        file_put_contents("{$bin}/ruff/0.16.10/SHA256SUMS", hash_file('sha256', "{$bin}/ruff/0.16.10/{$rel}") . " *{$rel}\n");
        $app->setToolRegistry(new ToolRegistry($app->db(), $app->clock, $app->logger, $bin, $root . '/work/tools', new NodeRuntime(null, '20.19.0'),
            static fn (SelectedTool $t): array => ['ok' => true, 'message' => 'ok']));
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $p = TestDb::project($db, 'p4proj', 'python');
        $app->setDiskGuard(new DiskGuard($db, $app->uploadSettings(), $app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
        $actor = Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], '127.0.0.1');
        $app->repoAllowedHosts()->add($actor, $admin, ['host' => 'p4.example.test', 'port' => 1666]);
        $app->repoConnections()->save($actor, $admin, $p, ['kind' => 'perforce', 'port' => 'p4.example.test:1666', 'path' => '//depot/proj/...', 'username' => 'reader',
            'secret' => 'TICKET-ABCDEF123456', 'secret_kind' => 'ticket']);
        $conn = $app->repoConnections()->row($p);
        $base = TestDb::analysis($db, $p, $admin, ['source_kind' => 'perforce', 'source_ref' => '//depot/proj/...', 'source_revision' => '4321',
            'source_key' => UnchangedSourceCheck::sourceKey($conn), 'env_key' => $app->analysisEnvKey()->compute($p)]);
        $check = $app->unchangedCheck();
        $r = $check->evaluate($p);
        $this->assertSame(['unchanged', 'same', '4321', $base, '//depot/proj/...'], [$r['decision'], $r['reason'], $r['remote_revision'], $r['base_analysis_id'], $r['remote_ref']]);
        $calls = array_map(static fn (string $l): array => json_decode($l, true), file($log) ?: []);
        $this->assertSame([['-G', 'changes', '-m', '1', '-s', 'submitted', '//depot/proj/...']], array_column($calls, 'args'), 'changes만(print·client 없음)');
        $this->assertSame(['10.20.30.40:1666', 'reader', true], [$calls[0]['P4PORT'], $calls[0]['P4USER'], $calls[0]['has_P4PASSWD']], '판정한 주소 고정·티켓은 환경 변수');
        // 주기 도래 → 변경 없음 행(큐·print 없음)
        $app->schedules()->save($actor, $admin, $p, ['enabled' => true, 'interval_days' => 1, 'run_time' => '03:00']);
        $next = strtotime((string) $db->value('SELECT next_run_at FROM project_schedules WHERE project_id = ?', [$p]));
        $app->clock->advance($next + 30 - $app->clock->now()->getTimestamp());
        $t = $app->scheduleTicker()->tick();
        $this->assertSame([1, 0], [$t['unchanged'], $t['queued']], json_encode($t));
        $row = $db->one('SELECT * FROM analyses WHERE project_id = ? AND no_change = 1', [$p]);
        $this->assertSame(['perforce', '4321', 'schedule', $base, null], [$row['source_kind'], $row['source_revision'], $row['trigger_kind'], (int) $row['unchanged_base_analysis_id'], $row['source_url']]);
        $this->assertSame([], array_values(array_filter(array_map(static fn (string $l): array => json_decode($l, true), file($log) ?: []), static fn (array $c): bool => ($c['args'][1] ?? '') === 'print')));
        // 새 changelist → 분석
        $write(4322);
        $this->assertSame(['changed', 'revision_differs', '4322'], [($r = $check->evaluate($p))['decision'], $r['reason'], $r['remote_revision']]);
        $this->assertNotContains('TICKET-ABCDEF', (string) file_get_contents($log));
    }
}
