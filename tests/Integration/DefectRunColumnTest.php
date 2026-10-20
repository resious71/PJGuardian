<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Defects\DefectQuery;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 결함 표 "Run"·"최근 감지" 열(P21U): 목록 행의 last_detected_run·last_detected_at·last_detected_analysis_id, 과거 Run 보기의 한정 규칙,
 * 정렬(run·last_seen, 오름·내림, 같은 값은 id 순, 페이지를 나눠도 같은 순서), 허용 밖 sort 400, 내 결함, 접근 범위.
 *
 * 흐름(a.py 3줄, b.py 2줄):
 *   Run 1: a1 a2 a3 b1
 *   (실패한 분석 — Run 번호 없음, 감지에 영향 없음)
 *   Run 2: a1 a2 b1        → a3 해결(마지막 감지 Run 1)
 *   Run 3: a1 b1 b2        → a2 해결(마지막 감지 Run 2), b2 신규
 */
final class DefectRunColumnTest extends TestCase
{
    private App $app;
    private RunFixture $f;
    private int $pid;
    /** @var array<int, int> Run → 분석 id */
    private array $a = [];
    private int $failed;
    /** @var array<string, int> */
    private array $d = [];

    public function setUp(): void
    {
        $root = $this->tempDir('runcol');
        $this->app = TestApp::make($root);
        $db = $this->app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        TestDb::user($db, 'outsider', 'user');
        $this->pid = TestDb::project($db, 'runcol', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', (SELECT id FROM users WHERE login_id = 'kim'), '2026-10-01T00:00:00Z')", [$this->pid]);
        $this->f = new RunFixture($this->app, $root, $this->pid, $admin);
        $f = $this->f;
        $files = ['a.py' => 3, 'b.py' => 2];
        $this->a[1] = $f->analyze($files, [['a.py', 1], ['a.py', 2], ['a.py', 3], ['b.py', 1]])['analysis_id'];
        $this->failed = $f->failed('failed');
        $this->a[2] = $f->analyze($files, [['a.py', 1], ['a.py', 2], ['b.py', 1]])['analysis_id'];
        $this->a[3] = $f->analyze($files, [['a.py', 1], ['b.py', 1], ['b.py', 2]])['analysis_id'];
        foreach (['a1' => ['a.py', 1], 'a2' => ['a.py', 2], 'a3' => ['a.py', 3], 'b1' => ['b.py', 1], 'b2' => ['b.py', 2]] as $k => [$file, $line]) {
            $this->d[$k] = $f->id($file, $line);
        }
        // 다른 프로젝트(kim은 볼 수 없음)의 결함: 목록에 섞이지 않아야 한다
        $other = TestDb::project($db, 'other', 'python');
        (new RunFixture($this->app, $root, $other, $admin))->analyze(['z.py' => 1], [['z.py', 1]]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    /** @param array<string, string> $q @return list<array<string, mixed>> */
    private function rows(TestClient $c, array $q = []): array
    {
        $r = $c->get("/api/v1/projects/{$this->pid}/defects", $q + ['size' => '200']);
        $this->assertSame(200, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function names(array $rows): array
    {
        $by = array_flip($this->d);
        return array_map(static fn (array $i): string => $by[$i['id']] ?? ('?' . $i['id']), $rows);
    }

    private function finished(int $run): string
    {
        return (string) $this->app->db()->value('SELECT finished_at FROM analyses WHERE id = ?', [$this->a[$run]]);
    }

    public function testLastDetectedValuesSkipFailedAnalysis(): void
    {
        $rows = $this->rows($this->as('kim'), ['sort' => 'file']);
        $this->assertSame(['a1', 'a2', 'a3', 'b1', 'b2'], $this->names($rows), '다른 프로젝트 결함은 섞이지 않음');
        $expect = ['a1' => 3, 'a2' => 2, 'a3' => 1, 'b1' => 3, 'b2' => 3];
        foreach ($rows as $row) {
            $k = $this->names([$row])[0];
            $run = $expect[$k];
            $this->assertSame($run, $row['last_detected_run'], "{$k} 마지막 감지 Run(실패 분석은 Run이 아니고 감지에도 영향 없음)");
            $this->assertSame($this->a[$run], $row['last_detected_analysis_id'], $k);
            $this->assertSame($this->finished($run), $row['last_detected_at'], $k);
            // 기존 필드는 그대로
            $this->assertSame(['analysis_id' => $this->a[$run], 'finished_at' => $this->finished($run), 'run' => $run], $row['last_seen'], $k);
        }
        $this->assertFalse(in_array($this->failed, array_column($rows, 'last_detected_analysis_id'), true), '실패 분석은 마지막 감지가 아님');
    }

    public function testMissingRunNumberIsNull(): void
    {
        // 되채움 전처럼 Run 번호가 없는 분석: Run 열은 null(화면 "—"), 날짜는 그대로
        $this->app->db()->execute('UPDATE analyses SET run_number = NULL WHERE id = ?', [$this->a[1]]);
        $rows = $this->rows($this->as('kim'), ['sort' => 'file']);
        $a3 = $rows[array_search($this->d['a3'], array_column($rows, 'id'), true)];
        $this->assertSame([null, $this->finished(1)], [$a3['last_detected_run'], $a3['last_detected_at']]);
        // 정렬: Run 번호 없음(null)은 오름차순 맨 앞
        $this->assertSame('a3', $this->names($this->rows($this->as('kim'), ['sort' => 'run']))[0]);
        // 단위: 값 없음
        $this->assertSame(['last_detected_run' => null, 'last_detected_at' => null, 'last_detected_analysis_id' => 9],
            DefectQuery::lastDetected(['analysis_id' => 9, 'finished_at' => null, 'run' => null]));
    }

    public function testPastRunViewCapsToThatRun(): void
    {
        $c = $this->as('kim');
        // Run 2 보기: 존재 결함 a1 a2 b1. a1·b1의 마지막 감지는 Run 3이지만 Run 2로 한정(시각도 Run 2 종료)
        $rows = $this->rows($c, ['run' => '2', 'sort' => 'file']);
        $this->assertSame(['a1', 'a2', 'b1'], $this->names($rows));
        foreach ($rows as $row) {
            $this->assertSame([2, $this->a[2], $this->finished(2)], [$row['last_detected_run'], $row['last_detected_analysis_id'], $row['last_detected_at']], $this->names([$row])[0]);
            $this->assertTrue($row['last_seen']['run'] >= 2, '기존 last_seen은 최신 값 그대로(화면이 한정 여부를 안다)');
        }
        // Run 1 보기: a3는 마지막 감지 = Run 1(한정 아님), 나머지는 Run 1로 한정
        $rows = $this->rows($c, ['run' => '1', 'sort' => 'file']);
        $this->assertSame(['a1', 'a2', 'a3', 'b1'], $this->names($rows));
        $this->assertSame([1, 1, 1, 1], array_column($rows, 'last_detected_run'));
        // 단위: 한정 규칙(마지막 감지 ≤ N이면 그대로)
        $view = ['run' => 2, 'analysis_id' => 20, 'finished_at' => '2026-10-02T00:00:00Z'];
        $this->assertSame(['last_detected_run' => 1, 'last_detected_at' => 'x', 'last_detected_analysis_id' => 10],
            DefectQuery::lastDetected(['analysis_id' => 10, 'finished_at' => 'x', 'run' => 1], $view));
        $this->assertSame(['last_detected_run' => 2, 'last_detected_at' => '2026-10-02T00:00:00Z', 'last_detected_analysis_id' => 20],
            DefectQuery::lastDetected(['analysis_id' => 30, 'finished_at' => 'y', 'run' => 3], $view));
    }

    public function testSortByRunAndDateStableAcrossPages(): void
    {
        $c = $this->as('kim');
        // 같은 값(Run 3: a1 b1 b2)은 id 순(오름) / id 역순(내림)
        $asc = ['a3', 'a2', 'a1', 'b1', 'b2'];
        $desc = ['b2', 'b1', 'a1', 'a2', 'a3'];
        foreach (['run', 'last_seen'] as $sort) {
            $this->assertSame($asc, $this->names($this->rows($c, ['sort' => $sort])), "{$sort} 오름");
            $this->assertSame($desc, $this->names($this->rows($c, ['sort' => $sort, 'order' => 'desc'])), "{$sort} 내림");
            // 페이지를 나눠도 같은 순서(행이 섞이거나 빠지지 않음)
            $paged = [];
            foreach (['1', '2', '3'] as $page) {
                $r = $c->get("/api/v1/projects/{$this->pid}/defects", ['sort' => $sort, 'order' => 'desc', 'size' => '2', 'page' => $page]);
                $paged = [...$paged, ...$this->names($r->decoded()['data'])];
            }
            $this->assertSame($desc, $paged, "{$sort} 페이지");
        }
        // 날짜 정렬은 종료 시각 순: Run 2의 종료 시각을 Run 1보다 앞으로 바꾸면 a2가 a3보다 앞(Run 정렬은 그대로)
        $this->app->db()->execute("UPDATE analyses SET finished_at = '2026-09-30T00:00:00Z' WHERE id = ?", [$this->a[2]]);
        $this->assertSame(['a2', 'a3', 'a1', 'b1', 'b2'], $this->names($this->rows($c, ['sort' => 'last_seen'])), '날짜 = 종료 시각');
        $this->assertSame($asc, $this->names($this->rows($c, ['sort' => 'run'])), 'Run 번호');
        // 과거 Run 보기의 정렬은 한정한 값으로: Run 2 보기에서는 모두 Run 2 → id 순
        $this->assertSame(['b1', 'a2', 'a1'], $this->names($this->rows($c, ['run' => '2', 'sort' => 'run', 'order' => 'desc'])));
        $this->assertSame(['a1', 'a2', 'b1'], $this->names($this->rows($c, ['run' => '2', 'sort' => 'last_seen'])));
    }

    public function testInvalidSortAndAccess(): void
    {
        $kim = $this->as('kim');
        foreach (['run;DROP', 'last_detected_run', 'RUN'] as $bad) {
            $r = $kim->get("/api/v1/projects/{$this->pid}/defects", ['sort' => $bad]);
            $this->assertSame(400, $r->status, $bad);
            $this->assertSame('invalid_sort', $r->decoded()['error']['code']);
        }
        // 보이지 않는 프로젝트는 정렬 값과 무관하게 404(접근 검사가 먼저)
        $this->assertSame(404, $this->as('outsider')->get("/api/v1/projects/{$this->pid}/defects", ['sort' => 'run'])->status);
        $this->assertSame(404, $this->as('outsider')->get("/api/v1/projects/{$this->pid}/defects", ['sort' => 'bad'])->status);
    }

    public function testMyDefectsHaveRunColumn(): void
    {
        $db = $this->app->db();
        $kim = (int) $db->value("SELECT id FROM users WHERE login_id = 'kim'");
        $db->execute('UPDATE defects SET assignee_id = ? WHERE id IN (?, ?)', [$kim, $this->d['a2'], $this->d['b2']]);
        $r = $this->as('kim')->get('/api/v1/my-defects');
        $this->assertSame(200, $r->status, $r->body);
        $got = array_column($r->decoded()['data'], 'last_detected_run', 'id');
        ksort($got);
        $this->assertSame([$this->d['a2'] => 2, $this->d['b2'] => 3], $got);
    }
}
