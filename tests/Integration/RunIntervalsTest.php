<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Runs\RunBackfill;
use PJGuardian\Services\Runs\RunTracker;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * Run 번호·결함 존재 구간·과거 Run 값 복원·Run 요약·되채움(P20R). 실제 반영 경로(DefectIngest → DefectStore)를 합성 결과로 탄다.
 *
 * 시나리오(파일 a.py 3줄, b.py, c.py):
 *   Run 1: a1 a2 b1 c1 감지 → D1 D2 D3 D4 신규
 *   (실패·취소 분석: Run이 아님)
 *   Run 2: c.py 제외, b.py 삭제, a1만 감지 → D2 미감지 resolved, D3 파일 삭제 resolved, D4 범위 밖(존재 유지)
 *   Run 3: a1 a2 a3 → D2 재오픈, D5 신규
 *   수동: D1 resolved, D2 in_progress + 담당자 admin, D5 resolved
 *   Run 4: a2 a3 → D1 수동 resolved 미감지(존재 끝), D5 수동 resolved인데 감지 → 재오픈(이어짐)
 *   Run 5: a1 a2 a3 → D1 재감지(새 구간)
 */
final class RunIntervalsTest extends TestCase
{
    private App $app;
    private RunFixture $f;
    private int $admin;
    /** @var array<string, int> */
    private array $d = [];
    /** @var array<int, int> Run → 분석 id */
    private array $a = [];

    public function setUp(): void
    {
        $root = $this->tempDir('runs');
        $this->app = TestApp::make($root);
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        $p = TestDb::project($db, 'runs', 'python');
        $this->f = new RunFixture($this->app, $root, $p, $this->admin);
    }

    private function scenario(): void
    {
        $f = $this->f;
        $files = ['a.py' => 3, 'b.py' => 1, 'c.py' => 1];
        $this->a[1] = $f->analyze($files, [['a.py', 1], ['a.py', 2], ['b.py', 1], ['c.py', 1]])['analysis_id'];
        foreach (['D1' => ['a.py', 1], 'D2' => ['a.py', 2], 'D3' => ['b.py', 1], 'D4' => ['c.py', 1]] as $k => [$file, $line]) {
            $this->d[$k] = $f->id($file, $line);
        }
        $f->failed('failed');
        $f->failed('cancelled');
        $this->app->db()->execute('UPDATE projects SET exclude_patterns = ? WHERE id = ?', [json_encode(['c.py']), $f->projectId]);
        $this->a[2] = $f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 1]])['analysis_id'];
        $this->a[3] = $f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 1], ['a.py', 2], ['a.py', 3]])['analysis_id'];
        $this->d['D5'] = $f->id('a.py', 3);
        $this->update('D1', ['status' => 'resolved']);
        $this->update('D2', ['status' => 'in_progress', 'assignee_id' => $this->admin, 'severity' => 'high']);
        $this->update('D5', ['status' => 'resolved']);
        $this->a[4] = $f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 2], ['a.py', 3]])['analysis_id'];
        $this->a[5] = $f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 1], ['a.py', 2], ['a.py', 3]])['analysis_id'];
    }

    /** @param array<string, mixed> $changes */
    private function update(string $k, array $changes): void
    {
        $id = $this->d[$k];
        $user = $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$this->admin]);
        $v = (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$id]);
        $this->app->defectUpdate()->update($user, $id, $changes + ['version' => $v]);
    }

    /** @return array<string, array{status:string, assignee_id:?int, severity:string}> 그 Run에 존재한 결함과 복원 값(키 D1…) */
    private function view(int $run): array
    {
        $runs = $this->app->runs();
        [$src, $params] = $runs->source($this->f->projectId, $runs->runRow($this->f->projectId, $run));
        $names = array_flip($this->d);
        $out = [];
        foreach ($this->app->db()->all('SELECT d.id, d.status, d.assignee_id, d.severity FROM ' . $src . ' ORDER BY d.id', $params) as $r) {
            $out[$names[(int) $r['id']]] = ['status' => (string) $r['status'], 'assignee_id' => $r['assignee_id'] === null ? null : (int) $r['assignee_id'],
                'severity' => (string) $r['severity']];
        }
        return $out;
    }

    public function testRunNumbersOnlyForSucceededAndSequential(): void
    {
        $this->scenario();
        $rows = $this->app->db()->all('SELECT id, status, run_number FROM analyses WHERE project_id = ? ORDER BY id', [$this->f->projectId]);
        $this->assertSame([
            ['succeeded', 1], ['failed', null], ['cancelled', null], ['succeeded', 2], ['succeeded', 3], ['succeeded', 4], ['succeeded', 5],
        ], array_map(static fn (array $r): array => [$r['status'], $r['run_number'] === null ? null : (int) $r['run_number']], $rows));
        $this->assertSame(['latest' => 5, 'backfill_needed' => false, 'unnumbered' => 0], $this->app->runs()->status($this->f->projectId));
        // 다른 프로젝트는 자기 번호를 1부터
        $p2 = TestDb::project($this->app->db(), 'other', 'python');
        $g = new RunFixture($this->app, $this->tempDir('runs2'), $p2, $this->admin);
        $g->analyze(['x.py' => 1], [['x.py', 1]]);
        $this->assertSame(1, (int) $this->app->db()->value('SELECT run_number FROM analyses WHERE project_id = ?', [$p2]));
    }

    public function testExistenceIntervals(): void
    {
        $this->scenario();
        $f = $this->f;
        $this->assertSame([[1, 3], [5, null]], $f->intervals($this->d['D1']), 'D1: 수동 resolved 뒤 미감지 → Run 3에서 끝, Run 5 재감지 → 새 구간');
        $this->assertSame([[1, 1], [3, null]], $f->intervals($this->d['D2']), 'D2: 자동 resolved(미감지) → Run 1에서 끝, Run 3 재오픈');
        $this->assertSame([[1, 1]], $f->intervals($this->d['D3']), 'D3: 파일 삭제 → Run 1에서 끝');
        $this->assertSame([[1, null]], $f->intervals($this->d['D4']), 'D4: 제외(범위 밖)는 존재 유지');
        $this->assertSame([[3, null]], $f->intervals($this->d['D5']), 'D5: 수동 resolved인데 감지 → 재오픈, 구간 이어짐');
        // 열린 구간은 결함당 하나
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM (SELECT defect_id FROM defect_runs WHERE to_run IS NULL GROUP BY defect_id HAVING COUNT(*) > 1)'));
    }

    public function testRestoredValuesPerRun(): void
    {
        $this->scenario();
        $open = ['status' => 'open', 'assignee_id' => null, 'severity' => 'not_defined'];
        $this->assertSame(['D1' => $open, 'D2' => $open, 'D3' => $open, 'D4' => $open], $this->view(1));
        $this->assertSame(['D1' => $open, 'D4' => $open], $this->view(2), 'Run 2: D2(미감지)·D3(파일 삭제)는 없음, D4(범위 밖)는 있음');
        $this->assertSame(['D1' => $open, 'D2' => $open, 'D4' => $open, 'D5' => $open], $this->view(3), 'Run 3: 수동 변경은 Run 3 뒤라 반영 안 됨');
        $this->assertSame([
            'D2' => ['status' => 'in_progress', 'assignee_id' => $this->admin, 'severity' => 'high'],
            'D4' => $open,
            'D5' => $open, // 수동 resolved 뒤 Run 4에서 재오픈
        ], $this->view(4));
        // 최신 Run(5) = 현재 표
        $this->assertSame(null, $this->app->runs()->resolve($this->f->projectId, '5'));
        $this->assertSame(['D1', 'D2', 'D4', 'D5'], array_keys($this->view(5)));
        // 저장된 Run 요약 = 복원 집합으로 다시 센 값
        foreach ([1, 2, 3, 4, 5] as $run) {
            $summary = json_decode((string) $this->app->db()->value('SELECT run_summary FROM analyses WHERE id = ?', [$this->a[$run]]), true);
            $v = $this->view($run);
            $this->assertSame(count($v), $summary['total'], "Run {$run} 요약 total");
            $byStatus = array_count_values(array_column($v, 'status'));
            foreach ($byStatus as $st => $n) {
                $this->assertSame($n, $summary['by_status'][$st] ?? 0, "Run {$run} {$st}");
            }
        }
    }

    public function testStoredSummaryEqualsRestoredSummary(): void
    {
        $this->scenario();
        $runs = $this->app->runs();
        $q = $this->app->defectQuery();
        $empty = \PJGuardian\Services\Defects\DefectFilter::fromQuery([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse(\PJGuardian\Services\Defects\DefectFilter::fromQuery(['status' => 'open'])->isEmpty());
        foreach ([1, 2, 3, 4] as $n) {
            $run = $runs->runRow($this->f->projectId, $n);
            $computed = $q->summary($this->f->projectId, $empty, 200, $runs->source($this->f->projectId, $run));
            $stored = $q->summaryStored($this->f->projectId, (array) $runs->storedSummary($run['analysis_id']), $runs->memberSource($this->f->projectId, $n), 200);
            $this->assertSame($computed, $stored, "Run {$n}: 저장 요약 = 복원 계산");
        }
    }

    public function testOptimizedPastRunListEqualsFullRestore(): void
    {
        // P20S: 필터·정렬이 Run 시점 값을 쓰지 않으면 존재 집합으로 고르고 페이지만 복원한다. 모든 조합에서 전체 복원과 같아야 한다
        $this->scenario();
        $runs = $this->app->runs();
        $q = $this->app->defectQuery();
        $pid = $this->f->projectId;
        $filters = [[], ['q' => 'a.py'], ['q' => 'line2'], ['file' => 'a.py'], ['file' => 'c', 'file_mode' => 'contains'], ['rule' => 'ruff:F401'],
            ['analysis' => (string) $this->a[3], 'change' => 'new'], ['analysis' => (string) $this->a[3], 'change' => 'reopened'],
            ['status' => 'open'], ['status' => 'in_progress,resolved'], ['assignee' => 'none'], ['assignee' => (string) $this->admin], ['severity' => 'high'],
            ['q' => 'zzz-없음']];
        $checked = 0;
        foreach ([1, 2, 3, 4] as $n) {
            $run = $runs->resolve($pid, (string) $n);
            $src = $runs->source($pid, $run);
            foreach ($filters as $fq) {
                foreach (['file', 'rule', 'severity', 'status', 'run', 'last_seen'] as $sort) {
                    foreach (['asc', 'desc'] as $order) {
                        $f = \PJGuardian\Services\Defects\DefectFilter::fromQuery($fq + ['sort' => $sort, 'order' => $order]);
                        foreach ([[50, 0], [2, 0], [2, 2], [2, 4], [1, 99]] as [$limit, $offset]) {
                            $fast = $q->list($pid, $f, $limit, $offset, $src, $run);
                            $full = $q->list($pid, $f, $limit, $offset, $src, $run, true);
                            $this->assertSame($full, $fast, "Run {$n} " . json_encode($fq) . " {$sort} {$order} {$limit}/{$offset}");
                            $checked++;
                        }
                    }
                }
            }
        }
        $this->assertSame(4 * count($filters) * 6 * 2 * 5, $checked);
        // 최신 Run = 현재 결함 표(원본 없음): 최적화 대상이 아님
        $this->assertSame(null, $runs->resolve($pid, '5'));
        $this->assertFalse(\PJGuardian\Services\Defects\DefectFilter::fromQuery(['q' => 'x', 'sort' => 'last_seen'])->usesRunValues());
        $this->assertTrue(\PJGuardian\Services\Defects\DefectFilter::fromQuery(['sort' => 'status'])->usesRunValues());
        $this->assertTrue(\PJGuardian\Services\Defects\DefectFilter::fromQuery(['assignee' => 'none'])->usesRunValues());
    }

    public function testInvalidAndUnknownRun(): void
    {
        $this->scenario();
        $runs = $this->app->runs();
        $this->assertSame(null, $runs->resolve($this->f->projectId, null));
        $this->assertSame(null, $runs->resolve($this->f->projectId, ''));
        foreach (['0', '-1', 'abc', '1.5', '01', ['1']] as $bad) {
            $this->assertThrows(\PJGuardian\Http\ApiException::class, static fn () => $runs->resolve(1, $bad));
        }
        try {
            $runs->resolve($this->f->projectId, '6');
            $this->fail('없는 Run');
        } catch (\PJGuardian\Http\ApiException $e) {
            $this->assertSame([404, 'run_not_found'], [$e->status, $e->errorCode]);
        }
        $this->assertSame(3, $runs->resolve($this->f->projectId, '3')['run']);
    }

    public function testBackfillReproducesIntervalsAndIsIdempotent(): void
    {
        $this->scenario();
        $db = $this->app->db();
        $real = [];
        foreach ($this->d as $k => $id) {
            $real[$k] = $this->f->intervals($id);
        }
        // 마이그레이션 직후 상태로 되돌림
        $db->execute('DELETE FROM defect_runs');
        $db->execute('UPDATE analyses SET run_number = NULL, run_history_mark = NULL, run_summary = NULL');
        $this->assertFalse(RunTracker::ready($db, $this->f->projectId), '되채움 전');
        $b = new RunBackfill($db, $this->app->runs());
        $this->assertSame([$this->f->projectId], $b->pending());
        $dry = $b->project($this->f->projectId, true);
        $this->assertSame([5, 5, 0], [$dry['runs'], $dry['defects'], (int) $db->value('SELECT COUNT(*) FROM defect_runs')], 'dry-run은 쓰지 않음');
        $r = $b->project($this->f->projectId);
        $this->assertTrue($r['written']);
        $this->assertSame([], $b->pending());
        $this->assertSame([1, null, null, 2, 3, 4, 5], array_map(static fn ($v) => $v === null ? null : (int) $v,
            array_column($db->all('SELECT run_number FROM analyses WHERE project_id = ? ORDER BY id', [$this->f->projectId]), 'run_number')));
        foreach (['D2', 'D3', 'D4', 'D5'] as $k) {
            $this->assertSame($real[$k], $this->f->intervals($this->d[$k]), "되채움 {$k}");
        }
        // D1(수동 resolved 뒤 미감지였다가 재감지): 미감지 시점이 이력에 없어 이어진 구간(근사, 보고서에 적음)
        $this->assertSame([[1, null]], $this->f->intervals($this->d['D1']));
        // Run 요약 = 복원 집합
        foreach ($r['verify'] as $v) {
            $this->assertSame($this->app->runs()->existCount($this->f->projectId, $v['run']), $v['restored_total'], 'Run ' . $v['run']);
        }
        // 다시 실행: 대상 없음. --force(같은 프로젝트를 다시) 해도 같은 결과
        $before = $db->all('SELECT defect_id, from_run, to_run FROM defect_runs ORDER BY defect_id, from_run');
        $b->project($this->f->projectId);
        $this->assertSame($before, $db->all('SELECT defect_id, from_run, to_run FROM defect_runs ORDER BY defect_id, from_run'), '멱등');
        // 되채움 뒤 새 분석은 실시간 반영
        $this->f->analyze(['a.py' => 3, 'c.py' => 1], [['a.py', 2]]);
        $this->assertSame([[1, 5]], $this->f->intervals($this->d['D1']));
    }

    public function testUnbackfilledProjectGetsNumberButNoIntervals(): void
    {
        $f = $this->f;
        $f->analyze(['a.py' => 1], [['a.py', 1]]);
        $db = $this->app->db();
        $db->execute('DELETE FROM defect_runs');
        $db->execute('UPDATE analyses SET run_number = NULL');
        $f->analyze(['a.py' => 1], [['a.py', 1]]);
        $this->assertSame([null, 2], array_map(static fn ($v) => $v === null ? null : (int) $v,
            array_column($db->all('SELECT run_number FROM analyses ORDER BY id'), 'run_number')), '번호는 성공 분석 수 + 1');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM defect_runs'), '되채움 전에는 구간을 건드리지 않음');
        $this->assertTrue($this->app->runs()->status($f->projectId)['backfill_needed']);
    }
}
