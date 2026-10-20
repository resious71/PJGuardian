<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Queue\Worker;
use PJGuardian\Support\ConfigException;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * Run 상세 보관과 삭제(P21B): 자동 보관(최근 keep개, 수동 삭제 제외, 상한·이어서·멱등·최신 보호·성공 분석만·감사), 설정 검증,
 * 수동 삭제 API(권한·검증·트랜잭션·감사), 다른 Run 보호(삭제 전후 응답 동일), 삭제된 Run 접근(410)과 표시, 마이그레이션 013.
 */
final class RunDetailsTest extends TestCase
{
    private App $app;
    private RunFixture $f;
    private int $pid;
    private int $admin;
    /** @var array<int, int> Run → 분석 id */
    private array $a = [];

    public function setUp(): void
    {
        $root = $this->tempDir('rundetail');
        $this->app = TestApp::make($root);
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        $kim = TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        TestDb::user($db, 'outsider', 'user');
        $this->pid = TestDb::project($db, 'proj-r', 'python');
        $t = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, ?)", [$this->pid, $kim, $t]);
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g1', ?, ?)", [$t, $t]);
        $g = $db->lastInsertId();
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?)', [$g, $mgr, $t]);
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', ?, ?)", [$this->pid, $g, $t]);
        $this->f = new RunFixture($this->app, $root, $this->pid, $this->admin);
    }

    /** Run n개(감지 결과가 조금씩 다름: 해결·재오픈·열린 구간이 이어지는 결함 포함) */
    private function runs(int $n): void
    {
        $plan = [[1, 2, 3], [1, 2], [1, 2, 3], [1, 4], [1, 2, 4], [1, 3], [1, 2, 3, 4]];
        for ($i = 0; $i < $n; $i++) {
            $hits = array_map(static fn (int $l): array => ['a.py', $l], $plan[$i % count($plan)]);
            $this->a[$i + 1] = $this->f->analyze(['a.py' => 4], $hits)['analysis_id'];
        }
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    /** @return array<int, ?string> Run → 삭제 사유(null = 상세 있음) */
    private function states(): array
    {
        return array_map(static fn (?string $v): ?string => $v, array_column($this->app->db()->all(
            'SELECT run_number, run_detail_deleted_reason FROM analyses WHERE project_id = ? AND run_number IS NOT NULL ORDER BY run_number', [$this->pid]), 'run_detail_deleted_reason', 'run_number'));
    }

    private function deleteApi(TestClient $c, array $runs, bool $csrf = true): \PJGuardian\Http\Response
    {
        return $c->request('POST', "/api/v1/admin/projects/{$this->pid}/runs/delete-detail", [], ['runs' => $runs], null, [], $csrf);
    }

    public function testAutoRetentionKeepsNewestAndIsIdempotent(): void
    {
        $d = $this->app->runDetails();
        $this->runs(15);
        $this->assertSame([], $d->retain($this->pid, 15, 20), '15개 이하면 아무것도 안 함');
        $this->runs(1); // Run 16
        $this->assertSame([1], $d->retain($this->pid, 15, 20), '16번째 뒤 Run 1');
        $this->assertSame([], $d->retain($this->pid, 15, 20), '멱등');
        // 수동 삭제는 15개 계산에서 빠진다
        $this->assertSame(['deleted' => [5], 'already' => []], $d->deleteManual(['id' => $this->admin, 'login_id' => 'admin'], null, $this->pid, [5]));
        $this->runs(1); // Run 17: 상세 있는 Run = 2~4, 6~17 = 15개
        $this->assertSame([], $d->retain($this->pid, 15, 20), '수동 삭제 Run 제외하고 15개');
        $this->runs(3); // Run 20: 18개 → 3개 초과, 상한 2면 둘만
        $this->assertSame([2, 3], $d->retain($this->pid, 15, 2), '상한');
        $this->assertSame([4], $d->retain($this->pid, 15, 2), '다음에 이어서');
        $this->assertSame([], $d->retain($this->pid, 0, 20), 'keep 0 = 끔');
        $states = $this->states();
        $this->assertSame(['retention', 'retention', 'retention', 'retention', 'manual'], array_values(array_slice($states, 0, 5, true)));
        $this->assertSame(15, count(array_filter($states, static fn (?string $s): bool => $s === null)));
        // 최신 Run은 keep이 아주 작아도 대상이 아니다(keep 3 → 남는 3개에 최신 포함)
        $this->assertSame(range(6, 17), $d->retain($this->pid, 3, 100));
        $this->assertSame([null, null, null], array_values(array_slice($this->states(), -3, 3, true)));
        $this->assertSame([], $d->retentionCandidates($this->pid, 3));
        // 감사 로그: 자동(system) 4번, 수동 1번
        $audit = $this->app->db()->all("SELECT actor_label, details FROM audit_log WHERE action = 'run.detail_delete' ORDER BY id");
        $this->assertSame(['system', 'admin', 'system', 'system', 'system'], array_column($audit, 'actor_label'));
        $this->assertSame(['runs' => [5], 'reason' => 'manual'], json_decode($audit[1]['details'], true));
    }

    public function testWorkerRetainsOnlyAfterSuccessfulAnalysis(): void
    {
        $app = TestApp::make($this->tempDir('rundetail-w'), ['runs' => ['detail_keep' => '3', 'retention_batch' => '20']]);
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $pid = TestDb::project($db, 'w', 'python');
        $f = new RunFixture($app, $this->tempDir('rundetail-wf'), $pid, $admin);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $f->analyze(['a.py' => 1], [['a.py', 1]])['analysis_id'];
        }
        $failed = $f->failed('failed');
        $worker = new Worker($app);
        $retain = new \ReflectionMethod($worker, 'retainRuns');
        $retain->invoke($worker, $failed, $pid);
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM analyses WHERE run_detail_deleted_at IS NOT NULL'), '실패한 분석 뒤에는 하지 않음');
        $retain->invoke($worker, end($ids), $pid);
        $this->assertSame([1, 2], array_map('intval', array_column($db->all('SELECT run_number FROM analyses WHERE run_detail_deleted_at IS NOT NULL ORDER BY run_number'), 'run_number')));
        $off = TestApp::make($this->tempDir('rundetail-off'), ['runs' => ['detail_keep' => '0']]);
        $this->assertSame(0, $off->config->int('runs.detail_keep'));
    }

    public function testConfigValidation(): void
    {
        foreach (['1', '2', '-1', 'abc', '10001'] as $bad) {
            $this->assertThrows(ConfigException::class, static fn () => TestApp::config(['runs' => ['detail_keep' => $bad]]), '', "detail_keep={$bad}");
        }
        foreach (['0', '3', '15'] as $ok) {
            $this->assertSame((int) $ok, TestApp::config(['runs' => ['detail_keep' => $ok]])->int('runs.detail_keep'));
        }
        $this->assertSame(15, TestApp::config()->int('runs.detail_keep'), '기본 15');
        $this->assertSame(20, TestApp::config()->int('runs.retention_batch'));
    }

    public function testManualDeleteApiPermissionsAndValidation(): void
    {
        $this->runs(6);
        $admin = $this->as('admin');
        // 권한: 보이지 않는 프로젝트 404(접근 판정이 먼저), 매핑 사용자·그룹 관리자 403, 비로그인 401
        $this->assertSame(404, $this->deleteApi($this->as('outsider'), [1])->status);
        $this->assertSame(404, $this->as('outsider')->get("/api/v1/admin/projects/{$this->pid}/runs")->status);
        $this->assertSame(403, $this->deleteApi($this->as('kim'), [1])->status);
        $this->assertSame(403, $this->deleteApi($this->as('mgr'), [1])->status);
        $this->assertSame(403, $this->as('mgr')->get("/api/v1/admin/projects/{$this->pid}/runs")->status);
        $this->assertSame(401, (new TestClient($this->app))->get("/api/v1/admin/projects/{$this->pid}/runs")->status, '비로그인 401');
        $this->assertTrue(in_array((new TestClient($this->app))->request('POST', "/api/v1/admin/projects/{$this->pid}/runs/delete-detail", [], ['runs' => [1]])->status, [401, 403], true), '비로그인 POST는 CSRF 또는 로그인에서 거부');
        $this->assertSame(403, $this->deleteApi($admin, [1], false)->status, 'CSRF 없음');
        // 검증
        foreach ([[], ['1'], [0], [1.5], ['a' => 1], array_fill(0, 101, 1), 'x'] as $bad) {
            $r = $admin->request('POST', "/api/v1/admin/projects/{$this->pid}/runs/delete-detail", [], ['runs' => $bad]);
            $this->assertSame([400, 'invalid_run_set'], [$r->status, $r->decoded()['error']['code']], json_encode($bad));
        }
        $r = $this->deleteApi($admin, [2, 99]);
        $this->assertSame([404, 'run_not_found', [99]], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['runs']]);
        // 최신 Run이 섞이면 전체 거부(아무것도 안 바뀜)
        $r = $this->deleteApi($admin, [1, 2, 6]);
        $this->assertSame([409, 'run_latest_not_deletable', [6]], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['runs']]);
        $this->assertSame([], array_filter($this->states()));
        // 성공: 중복 합침, 이미 삭제된 Run 건너뜀
        $r = $this->deleteApi($admin, [2, 1, 2]);
        $this->assertSame(['deleted' => [1, 2], 'already' => []], $r->decoded()['data']);
        $r = $this->deleteApi($admin, [2, 3]);
        $this->assertSame(['deleted' => [3], 'already' => [2]], $r->decoded()['data']);
        $this->assertSame(['manual', 'manual', 'manual', null, null, null], array_values($this->states()));
        $by = array_unique(array_column($this->app->db()->all('SELECT run_detail_deleted_by FROM analyses WHERE run_detail_deleted_at IS NOT NULL'), 'run_detail_deleted_by'));
        $this->assertSame([$this->admin], array_map('intval', array_values($by)));
        // 관리 목록·요약
        $list = $admin->get("/api/v1/admin/projects/{$this->pid}/runs", ['size' => '2'])->decoded();
        $this->assertSame([6, 5], array_column($list['data'], 'run'));
        $this->assertSame([true, false], array_column($list['data'], 'latest'));
        $this->assertSame(['kept' => 3, 'deleted' => 3, 'latest' => 6, 'keep' => 15, 'next_retention' => [], 'kept_runs' => [4, 5, 6]], $list['summary']);
        $this->assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'run.detail_delete'"));
        // 화면: admin만(그룹 관리자 403)
        $this->assertSame(200, $admin->get('/admin/runs')->status);
        $this->assertSame(403, $this->as('mgr')->get('/admin/runs')->status);
    }

    public function testManualDeleteRollsBackOnError(): void
    {
        $this->runs(4);
        // 감사 로그 기록이 실패하면(트리거로 강제) 상세 삭제도 되돌린다(한 트랜잭션)
        $db = $this->app->db();
        $db->pdo()->exec("CREATE TEMP TRIGGER fail_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $this->assertThrows(\Throwable::class, fn () => $this->app->runDetails()->deleteManual(['id' => $this->admin, 'login_id' => 'admin'], null, $this->pid, [1, 2]));
        $db->pdo()->exec('DROP TRIGGER fail_audit');
        $this->assertSame([], array_filter($this->states()), '전부 롤백');
    }

    /** @return array<string, mixed> 남는 Run들의 보기 응답(목록 여러 정렬·필터, 요약, 결함 상세, 코드) */
    private function snapshot(TestClient $c, array $runs): array
    {
        $out = [];
        $ids = array_map('intval', array_column($this->app->db()->all('SELECT id FROM defects WHERE project_id = ? ORDER BY id', [$this->pid]), 'id'));
        foreach ($runs as $n) {
            $q = $n === null ? [] : ['run' => (string) $n];
            foreach ([[], ['sort' => 'status', 'order' => 'desc'], ['sort' => 'run'], ['status' => 'open'], ['severity' => 'not_defined', 'sort' => 'last_seen']] as $fq) {
                $list = $c->get("/api/v1/projects/{$this->pid}/defects", $q + $fq)->decoded();
                foreach ($list['data'] as &$d) {
                    unset($d['last_detected_detail_deleted']); // 표시 전용 필드(삭제한 Run에 대해서만 바뀜) — 나머지 바이트를 비교
                }
                unset($d);
                $out["list {$n} " . json_encode($fq)] = $list;
            }
            $out["summary {$n}"] = $c->get("/api/v1/projects/{$this->pid}/defects/summary", $q)->decoded();
            foreach ($ids as $id) {
                $out["detail {$n} {$id}"] = $c->get("/api/v1/defects/{$id}", $q)->decoded();
                $out["code {$n} {$id}"] = $c->get("/api/v1/defects/{$id}/code", $q)->decoded();
            }
        }
        return $out;
    }

    public function testDeletingRunsDoesNotChangeOtherRuns(): void
    {
        $this->runs(7);
        $c = $this->as('admin');
        $keep = [5, 6, null];
        $before = $this->snapshot($c, $keep);
        $historyBefore = $c->get("/api/v1/projects/{$this->pid}/history", ['size' => '50'])->decoded();
        $this->assertSame(200, $this->deleteApi($c, [1, 2, 3, 4])->status);
        $this->assertSame($before, $this->snapshot($c, $keep), 'Run 1~4 삭제 뒤 Run 5·6·최신 보기 동일');
        // 중간 Run만(열린 구간이 지나는 결함 a1 포함)
        $before = $this->snapshot($c, [6, null]);
        $this->assertSame(200, $this->deleteApi($c, [5])->status);
        $this->assertSame($before, $this->snapshot($c, [6, null]), 'Run 5만 삭제 뒤 Run 6·최신 동일');
        $this->assertSame([[1, null]], $this->f->intervals($this->f->id('a.py', 1)), '구간은 지우지 않음');
        // 히스토리 표: 건수·번호·날짜·변경 표시는 같고 상세 상태만 표시
        $historyAfter = $c->get("/api/v1/projects/{$this->pid}/history", ['size' => '50'])->decoded();
        $strip = static fn (array $rows): array => array_map(static function (array $r): array {
            unset($r['detail_deleted']);
            return $r;
        }, $rows);
        $this->assertSame($strip($historyBefore['data']), $strip($historyAfter['data']));
        $this->assertSame([false, false, true, true, true, true, true], array_column($historyAfter['data'], 'detail_deleted'));
        $cmp = $c->get("/api/v1/analyses/{$this->a[2]}/comparison")->decoded()['data'];
        $this->assertSame([true, (int) $this->app->db()->value('SELECT new_count FROM analyses WHERE id = ?', [$this->a[2]])], [$cmp['analysis']['detail_deleted'], $cmp['counts']['new']], '비교는 저장 건수로 그대로');
    }

    public function testDeletedRunAccessAndDisplay(): void
    {
        $this->runs(4);
        $c = $this->as('kim');
        $dash = static function (TestClient $c, int $pid): array {
            $s = $c->get('/api/v1/dashboard/trend', ['project_id' => (string) $pid])->decoded()['data']['series'][0]['points'];
            return array_map(static function (array $p): array {
                unset($p['detail_deleted']);
                return $p;
            }, $s);
        };
        $trendBefore = $dash($c, $this->pid);
        $this->assertSame(200, $this->deleteApi($this->as('admin'), [2])->status);
        $id = $this->f->id('a.py', 1);
        foreach (["/api/v1/projects/{$this->pid}/defects", "/api/v1/projects/{$this->pid}/defects/summary", "/api/v1/defects/{$id}", "/api/v1/defects/{$id}/code"] as $path) {
            $r = $c->get($path, ['run' => '2']);
            $this->assertSame([410, 'run_detail_deleted', 2], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['run']], $path);
            $this->assertSame(404, $c->get($path, ['run' => '99'])->status, "{$path} 없는 Run");
            $this->assertSame(200, $c->get($path, ['run' => '3'])->status, "{$path} 남은 Run");
        }
        $page = $c->get("/defects/{$id}", ['run' => '2']);
        $this->assertSame(410, $page->status);
        $this->assertTrue(str_contains($page->body, 'data-run-deleted="2"') && str_contains($page->body, '/defects/' . $id . '"'), '안내 + 최신 보기 링크');
        // Run 선택기 목록, 대시보드 추이(값 불변), 전체 이력, 결함 표 Run 열
        $runs = $c->get("/api/v1/projects/{$this->pid}/runs")->decoded()['data'];
        $this->assertSame([null, null, 'manual', null], array_map(static fn (array $r): ?string => $r['detail_deleted']['reason'] ?? null, $runs));
        $this->assertSame($trendBefore, $dash($c, $this->pid), '대시보드 추이 값 불변');
        $points = $c->get('/api/v1/dashboard/trend', ['project_id' => (string) $this->pid])->decoded()['data']['series'][0]['points'];
        $this->assertSame([false, true, false, false], array_column($points, 'detail_deleted'));
        $tl = $c->get("/api/v1/defects/{$id}/timeline")->decoded()['data'];
        foreach ($tl as $e) {
            $this->assertSame($e['run'] === 2, $e['run_detail_deleted'], json_encode($e));
        }
        // a2는 Run 2에서 마지막으로 감지됨(Run 3에서 다시 감지: plan [1,2,3]) → 마지막 감지 Run 3. Run 2가 마지막인 결함을 만든다
        $this->f->analyze(['a.py' => 4], [['a.py', 1]]);
        $rows = $c->get("/api/v1/projects/{$this->pid}/defects", ['sort' => 'file'])->decoded()['data'];
        $flags = array_column($rows, 'last_detected_detail_deleted', 'last_detected_run');
        foreach ($flags as $run => $flag) {
            $this->assertSame($run === 2, $flag, "Run {$run}");
        }
    }

    public function testMigration013(): void
    {
        $dir = $this->tempDir('mig013');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $file) {
            if (basename($file) < '013') {
                copy($file, "{$dir}/m/" . basename($file));
            }
        }
        $db = new Db("{$dir}/x.sqlite");
        (new Migrator($db, "{$dir}/m", new FixedClock()))->migrate();
        $uid = TestDb::user($db, 'admin', 'admin');
        $pid = TestDb::project($db, 'p', 'python');
        $aid = TestDb::analysis($db, $pid, $uid, ['status' => 'succeeded', 'finished_at' => '2026-10-01T00:00:00Z']);
        $db->execute('UPDATE analyses SET run_number = 1 WHERE id = ?', [$aid]);
        $before = $db->one('SELECT * FROM analyses WHERE id = ?', [$aid]);
        copy(PJG_ROOT . '/migrations/013_run_detail_retention.sql', "{$dir}/m/013_run_detail_retention.sql");
        $this->assertSame(['013'], (new Migrator($db, "{$dir}/m", new FixedClock()))->migrate());
        $after = $db->one('SELECT * FROM analyses WHERE id = ?', [$aid]);
        $this->assertSame([null, null, null], [$after['run_detail_deleted_at'], $after['run_detail_deleted_by'], $after['run_detail_deleted_reason']], '기존 Run = 상세 있음');
        unset($after['run_detail_deleted_at'], $after['run_detail_deleted_by'], $after['run_detail_deleted_reason']);
        $this->assertSame($before, $after, '다른 열 그대로');
        // 제약: 사유·시각·사용자 조합, Run 번호 없는 분석은 지울 수 없음
        $bad = [
            ["'2026-10-02T00:00:00Z'", 'NULL', "'manual'"], ["'2026-10-02T00:00:00Z'", (string) $uid, "'retention'"], ['NULL', 'NULL', "'retention'"], ["'2026-10-02T00:00:00Z'", 'NULL', 'NULL'], ["'x'", 'NULL', "'retention'"],
        ];
        foreach ($bad as [$at, $by, $reason]) {
            $this->assertThrows(\PDOException::class, static fn () => $db->pdo()->exec("UPDATE analyses SET run_detail_deleted_at = {$at}, run_detail_deleted_by = {$by}, run_detail_deleted_reason = {$reason} WHERE id = {$aid}"));
        }
        $db->pdo()->exec("UPDATE analyses SET run_detail_deleted_at = '2026-10-02T00:00:00Z', run_detail_deleted_reason = 'retention' WHERE id = {$aid}");
        $other = TestDb::analysis($db, $pid, $uid, ['status' => 'failed', 'finished_at' => '2026-10-01T00:00:00Z', 'failure_code' => 'tool_crash']);
        $this->assertThrows(\PDOException::class, static fn () => $db->pdo()->exec("UPDATE analyses SET run_detail_deleted_at = '2026-10-02T00:00:00Z', run_detail_deleted_reason = 'retention' WHERE id = {$other}"));
    }
}
