<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Analysis\AnalysisComparison;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 히스토리(P21): 분석 이력, 직전 성공 대비 비교(건수 = 저장 값 = 목록 필터), 변경 판정(P20과 같은 함수), 범위 변화 경고,
 * 결함 전체 이력(타임라인), 결함 목록 Run 필터, 접근 통제.
 *
 * 흐름(a.py 3줄, b.py, c.py):
 *   Run 1: a1 a2 b1                       → 신규 3
 *   (실패한 분석)
 *   Run 2: a.py만(b.py 삭제), a1 a3        → 신규 1(a3), 해결 2(a2 미감지, b1 파일 삭제), 유지 1(a1)
 *   수동: a1 resolved
 *   Run 3: a.py·c.py, a1 a2 a3 c1          → 신규 1(c1), 재오픈 2(a1 수동 resolved 뒤 감지, a2), 유지 1(a3)
 *   수동: a3 resolved
 *   Run 4: c.py 제외, a1 a2                → a3 수동 resolved 뒤 미감지(존재 끝, 이력 없음), c1 범위 밖 유지
 */
final class HistoryTest extends TestCase
{
    private App $app;
    private RunFixture $f;
    private int $admin;
    /** @var array<int, int> Run → 분석 id */
    private array $a = [];
    private int $failed;
    /** @var array<string, int> */
    private array $d = [];

    public function setUp(): void
    {
        $root = $this->tempDir('history');
        $this->app = TestApp::make($root);
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        TestDb::user($db, 'outsider', 'user');
        $p = TestDb::project($db, 'hist', 'python');
        $now = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', (SELECT id FROM users WHERE login_id = 'kim'), ?)", [$p, $now]);
        $this->f = new RunFixture($this->app, $root, $p, $this->admin);
        $f = $this->f;
        $this->a[1] = $f->analyze(['a.py' => 3, 'b.py' => 1], [['a.py', 1], ['a.py', 2], ['b.py', 1]])['analysis_id'];
        $this->d = ['a1' => $f->id('a.py', 1), 'a2' => $f->id('a.py', 2), 'b1' => $f->id('b.py', 1)];
        $this->failed = $f->failed('failed');
        $this->a[2] = $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 3]])['analysis_id'];
        $this->d['a3'] = $f->id('a.py', 3);
        $this->set('a1', 'resolved');
        $this->a[3] = $f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 1], ['a.py', 2], ['a.py', 3], ['c.py', 1]])['analysis_id'];
        $this->d['c1'] = $f->id('c.py', 1);
        $this->set('a3', 'resolved');
        $db->execute('UPDATE projects SET exclude_patterns = ? WHERE id = ?', [json_encode(['c.py']), $p]);
        $this->a[4] = $f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 1], ['a.py', 2]])['analysis_id'];
    }

    private function set(string $k, string $status): void
    {
        $user = $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$this->admin]);
        $v = (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$this->d[$k]]);
        $this->app->defectUpdate()->update($user, $this->d[$k], ['version' => $v, 'status' => $status]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    /** @param array<string, string> $q @return list<string> */
    private function names(TestClient $c, array $q): array
    {
        $r = $c->get("/api/v1/projects/{$this->f->projectId}/defects", array_map('strval', $q) + ['size' => '200']);
        $this->assertSame(200, $r->status, $r->body);
        $by = array_flip($this->d);
        $out = array_map(static fn (array $i): string => $by[$i['id']], $r->decoded()['data']);
        sort($out);
        return $out;
    }

    public function testComparisonCountsMatchStoredAndListFilters(): void
    {
        $c = $this->as('kim');
        $expect = [
            1 => ['new' => ['a1', 'a2', 'b1'], 'resolved' => [], 'reopened' => [], 'retained' => 0, 'out_of_scope' => 0],
            2 => ['new' => ['a3'], 'resolved' => ['a2', 'b1'], 'reopened' => [], 'retained' => 1, 'out_of_scope' => 0],
            3 => ['new' => ['c1'], 'resolved' => [], 'reopened' => ['a1', 'a2'], 'retained' => 1, 'out_of_scope' => 0],
            4 => ['new' => [], 'resolved' => [], 'reopened' => [], 'retained' => 2, 'out_of_scope' => 1],
        ];
        foreach ($expect as $run => $e) {
            $r = $c->get("/api/v1/analyses/{$this->a[$run]}/comparison");
            $this->assertSame(200, $r->status, $r->body);
            $cmp = $r->decoded()['data'];
            $row = $this->app->db()->one('SELECT new_count, resolved_count, reopened_count, unchanged_count FROM analyses WHERE id = ?', [$this->a[$run]]);
            foreach (['new', 'resolved', 'reopened'] as $k) {
                $this->assertSame(count($e[$k]), $cmp['counts'][$k], "Run {$run} {$k} 건수");
                $this->assertSame((int) $row["{$k}_count"], $cmp['counts'][$k], "Run {$run} {$k} = 저장 값");
                $this->assertSame($e[$k], $this->names($c, $cmp['filters'][$k] + []), "Run {$run} {$k} 목록 = 필터");
                $this->assertSame(['analysis' => $this->a[$run], 'change' => $k], $cmp['filters'][$k]);
                $this->assertSame(count($e[$k]), array_sum($cmp['sets'][$k]['by_severity']), "Run {$run} {$k} 심각도 합");
            }
            $this->assertSame([$e['retained'], $e['out_of_scope']], [$cmp['counts']['retained'], $cmp['counts']['out_of_scope']], "Run {$run} 유지·범위 밖");
        }
        // 직전 성공 기준: Run 2의 기준은 실패한 분석을 건너뛴 Run 1, 첫 분석은 기준 없음
        $this->assertSame(null, $c->get("/api/v1/analyses/{$this->a[1]}/comparison")->decoded()['data']['baseline']);
        $cmp2 = $c->get("/api/v1/analyses/{$this->a[2]}/comparison")->decoded()['data'];
        $this->assertSame([$this->a[1], 1], [$cmp2['baseline']['analysis_id'], $cmp2['baseline']['run']]);
        $this->assertSame(['prev' => $this->a[1], 'next' => $this->a[3]], $cmp2['nav'], '이전·다음은 성공 분석만');
        // 해결 목록은 "이 분석에서 해결된 결함" — 이후 재오픈된 a2도 들어 있고 현재 상태가 보인다
        $res = $c->get("/api/v1/projects/{$this->f->projectId}/defects", ['analysis' => (string) $this->a[2], 'change' => 'resolved'])->decoded()['data'];
        $status = array_column($res, 'status', 'id');
        $this->assertSame('open', $status[$this->d['a2']], 'Run 3에서 재오픈되어 지금은 open');
        // 상위 10
        $this->assertSame([['rule_key' => 'ruff:F401', 'count' => 2]], $cmp2['sets']['resolved']['top_rules']);
        $this->assertSame(2, count($cmp2['sets']['resolved']['top_files']));
    }

    public function testComparisonErrorsAndAccess(): void
    {
        $c = $this->as('kim');
        $r = $c->get("/api/v1/analyses/{$this->failed}/comparison");
        $this->assertSame([409, 'not_succeeded'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertSame(404, $c->get('/api/v1/analyses/999999/comparison')->status);
        $this->assertSame(404, $c->get('/api/v1/analyses/abc/comparison')->status);
        $o = $this->as('outsider');
        foreach (["/api/v1/analyses/{$this->a[2]}/comparison", "/api/v1/projects/{$this->f->projectId}/history", "/api/v1/defects/{$this->d['a1']}/timeline",
            "/projects/{$this->f->projectId}/history"] as $path) {
            $this->assertSame(404, $o->get($path)->status, "보이지 않으면 404: {$path}");
            $this->assertSame(200, $this->as('admin')->get($path)->status, "admin: {$path}");
            $this->assertSame(200, $c->get($path)->status, "매핑된 사용자: {$path}");
        }
        $this->assertSame(401, (new TestClient($this->app))->get("/api/v1/projects/{$this->f->projectId}/history")->status);
        // 프로젝트 선택: 보이는 프로젝트만
        $this->assertTrue(str_contains($c->get('/history')->body, 'hist'));
        $this->assertFalse(str_contains($o->get('/history')->body, '/projects/' . $this->f->projectId . '/history'));
    }

    public function testHistoryList(): void
    {
        $c = $this->as('kim');
        $r = $c->get("/api/v1/projects/{$this->f->projectId}/history");
        $this->assertSame(200, $r->status, $r->body);
        $rows = $r->decoded()['data'];
        $this->assertSame([$this->a[4], $this->a[3], $this->a[2], $this->failed, $this->a[1]], array_column($rows, 'analysis_id'), '최신순, 실패 포함');
        $this->assertSame([4, 3, 2, null, 1], array_column($rows, 'run'));
        $failed = $rows[3];
        $this->assertSame(['failed', null, null, false], [$failed['status'], $failed['counts'], $failed['open_total'], $failed['changed']]);
        $this->assertSame('tool_crash', $failed['failure']['code']);
        $this->assertSame(['detected' => 4, 'new' => 1, 'resolved' => 0, 'reopened' => 2, 'retained' => 1], $rows[1]['counts']);
        // 열린 결함(그 Run 종료 시점) 증감: Run 2(a1 a3 열림 2) → Run 3(a2 a3 c1 열림 3 + a1 재오픈 = 4)
        $this->assertSame([2, 4, 2], [$rows[2]['open_total'], $rows[1]['open_total'], $rows[1]['open_delta']]);
        $this->assertSame(['analysis_id' => $this->a[2], 'run' => 2], $rows[1]['baseline']);
        $this->assertSame(null, $rows[4]['baseline'], '첫 성공');
        // 페이지
        $p = $c->get("/api/v1/projects/{$this->f->projectId}/history", ['page' => '2', 'size' => '2'])->decoded();
        $this->assertSame([[$this->a[2], $this->failed], 5], [array_column($p['data'], 'analysis_id'), $p['page']['total']]);
        $this->assertSame(400, $c->get("/api/v1/projects/{$this->f->projectId}/history", ['size' => '101'])->status, '최대 100');
    }

    public function testChangeDetectionUsesSameFunctionAsDashboard(): void
    {
        $db = $this->app->db();
        $db->execute("UPDATE analyses SET tool_version = '0.17.0' WHERE id = ?", [$this->a[3]]);
        $db->execute('UPDATE analysis_file_lists SET file_count = 2'); // 범위 변화 경고는 따로 시험(여기서는 도구 변경만)
        $db->execute("UPDATE analyses SET config_source = 'upload', tool_version = '0.17.0' WHERE id = ?", [$this->a[4]]);
        $c = $this->as('kim');
        $cmp3 = $c->get("/api/v1/analyses/{$this->a[3]}/comparison")->decoded()['data'];
        $this->assertSame([true, ['tool_version' => ['from' => '0.16.10', 'to' => '0.17.0']]], [$cmp3['changed'], $cmp3['changes']]);
        $this->assertSame(['history.warn_tool_change'], $cmp3['warnings']);
        $cmp4 = $c->get("/api/v1/analyses/{$this->a[4]}/comparison")->decoded()['data'];
        $this->assertSame(['config_source'], array_keys($cmp4['changes']), '설정 출처만');
        $this->assertSame(false, $c->get("/api/v1/analyses/{$this->a[2]}/comparison")->decoded()['data']['changed'], '모두 같음');
        // 이력 표·대시보드 추이·직접 호출이 같은 판정
        $hist = array_column($c->get("/api/v1/projects/{$this->f->projectId}/history")->decoded()['data'], 'changes', 'analysis_id');
        $trend = array_column($c->get('/api/v1/dashboard/trend', ['project_id' => (string) $this->f->projectId])->decoded()['data']['series'][0]['points'], 'changes', 'analysis_id');
        foreach ([2, 3, 4] as $n) {
            $direct = AnalysisComparison::differences($db->one('SELECT * FROM analyses WHERE id = ?', [$this->a[$n - 1]]), $db->one('SELECT * FROM analyses WHERE id = ?', [$this->a[$n]]));
            $this->assertSame($direct, $hist[$this->a[$n]], "Run {$n} 이력");
            $this->assertSame($direct, $trend[$this->a[$n]], "Run {$n} 추이");
        }
    }

    public function testScopeChangeWarningBoundary(): void
    {
        $db = $this->app->db();
        $set = fn (int $run, int $n) => $db->execute('UPDATE analysis_file_lists SET file_count = ? WHERE analysis_id = ?', [$n, $this->a[$run]]);
        $c = $this->as('kim');
        $get = fn (): array => $c->get("/api/v1/analyses/{$this->a[2]}/comparison")->decoded()['data'];
        $set(1, 4);
        $set(2, 2); // |2-4|/4 = 0.5 → 경고(이상)
        $this->assertSame(['from_files' => 4, 'to_files' => 2, 'ratio' => 0.5, 'changed' => true], $get()['scope']);
        $this->assertSame(['history.warn_scope_change'], $get()['warnings']);
        $set(2, 3); // 0.25
        $this->assertSame([false, []], [$get()['scope']['changed'], $get()['warnings']]);
    }

    public function testTimeline(): void
    {
        $c = $this->as('kim');
        // a2: Run 1 발생 → Run 2 자동 해결 → Run 3 재오픈(지금도 존재)
        $r = $c->get("/api/v1/defects/{$this->d['a2']}/timeline");
        $this->assertSame(200, $r->status, $r->body);
        $t = $r->decoded();
        $this->assertSame([['appeared', 1, false], ['auto_resolved', 2, false], ['reopened', 3, false]],
            array_map(static fn (array $i): array => [$i['kind'], $i['run'], $i['run_after']], $t['data']));
        $this->assertSame(['first_run' => 1, 'latest_run' => 4, 'intervals' => [['from' => 1, 'to' => 1], ['from' => 3, 'to' => null]], 'status' => 'open',
            'exists_now' => true, 'history_count' => 2], $t['summary']);
        // a3: Run 2 발생 → Run 3 이후 수동 resolved → Run 4 사라짐(이력 없이 존재 끝)
        $t = $c->get("/api/v1/defects/{$this->d['a3']}/timeline")->decoded();
        $this->assertSame([['appeared', 2, false], ['status_changed', 3, true], ['disappeared', 4, false]],
            array_map(static fn (array $i): array => [$i['kind'], $i['run'], $i['run_after']], $t['data']));
        $this->assertSame(['open', 'resolved', 'admin'], [$t['data'][1]['from'], $t['data'][1]['to'], $t['data'][1]['by']['login_id']]);
        $this->assertSame(false, $t['summary']['exists_now']);
        // 행 수 = 이력 수 + 발생 + 사라짐
        $hist = (int) $this->app->db()->value('SELECT COUNT(*) FROM defect_history WHERE defect_id = ?', [$this->d['a3']]);
        $this->assertSame($hist + 2, $t['page']['total']);
        // a1: Run 2 이후 수동 resolved → Run 3 재오픈(자동)
        $kinds = array_column($c->get("/api/v1/defects/{$this->d['a1']}/timeline")->decoded()['data'], 'kind');
        $this->assertSame(['appeared', 'status_changed', 'reopened'], $kinds);
        // 페이지
        $p = $c->get("/api/v1/defects/{$this->d['a2']}/timeline", ['page' => '2', 'size' => '2'])->decoded();
        $this->assertSame([['reopened'], 3], [array_column($p['data'], 'kind'), $p['page']['total']]);
        $this->assertSame(404, $c->get('/api/v1/defects/999999/timeline')->status);
    }

    public function testRunFilters(): void
    {
        $c = $this->as('kim');
        $this->assertSame(['a3'], $this->names($c, ['from_run' => '2']), 'Run 2에서 새로 생김');
        $this->assertSame(['c1'], $this->names($c, ['from_run' => '3']));
        $this->assertSame(['a1', 'a2', 'b1'], $this->names($c, ['in_run_from' => '1', 'in_run_to' => '1']));
        $this->assertSame(['a1', 'a3'], $this->names($c, ['in_run_from' => '2']), '한쪽만 = 그 Run 하나');
        $this->assertSame(['a1', 'a2', 'a3', 'b1'], $this->names($c, ['in_run_from' => '1', 'in_run_to' => '2']), '범위와 겹치면 존재');
        $this->assertSame(['a1', 'a2', 'c1'], $this->names($c, ['in_run_from' => '4', 'in_run_to' => '4']), '범위 밖 유지 c1도 존재');
        $this->assertSame(['a3'], $this->names($c, ['from_run' => '2', 'in_run_from' => '2', 'in_run_to' => '3']));
        foreach ([['from_run' => '0'], ['from_run' => 'x'], ['in_run_from' => '3', 'in_run_to' => '2']] as $bad) {
            $r = $c->get("/api/v1/projects/{$this->f->projectId}/defects", $bad);
            $this->assertSame([400, 'invalid_defect_filter'], [$r->status, $r->decoded()['error']['code']], json_encode($bad));
        }
        $this->assertSame(['a3'], array_column(array_filter($c->get("/api/v1/projects/{$this->f->projectId}/defects/summary", ['from_run' => '2'])->decoded()['data']['rules'], fn () => true), 'count') === [1] ? ['a3'] : [], '요약도 같은 필터');
    }
}
