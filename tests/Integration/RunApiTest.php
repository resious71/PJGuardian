<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * Run API(P20R): 결함 목록·요약·상세·코드의 run, Run 목록. Run 비교는 P20S에서 제거했다(경로가 없음을 확인).
 *
 * 결함(RunIntervalsTest와 같은 흐름을 줄임): Run 1 a1 a2 b1 → Run 2 a1 a3(b.py 삭제) → 수동 D2… 없음 → Run 3 a1 a3, D1 in_progress(Run 2 뒤)
 *   D1 a1: Run 1~3, Run 2 뒤 in_progress   D2 a2: Run 1   D3 b1: Run 1   D4 a3: Run 2~3
 * 사용자: admin, mgr(이 프로젝트에 매핑된 그룹의 그룹 관리자), member(같은 그룹 일반 구성원), direct(직접 매핑), mgrOther(매핑 안 된
 * 그룹의 관리자이면서 직접 매핑), mgrNone(매핑 안 된 그룹의 관리자 — 프로젝트가 안 보임), outsider(매핑 없음)
 */
final class RunApiTest extends TestCase
{
    private App $app;
    private RunFixture $f;
    private int $p;
    /** @var array<string, int> */
    private array $d = [];
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $root = $this->tempDir('runapi');
        $this->app = TestApp::make($root);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'mgr' => 'user', 'member' => 'user', 'direct' => 'user', 'mgrOther' => 'user', 'mgrNone' => 'user', 'outsider' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, 'runapi', 'python');
        $now = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g1', ?, ?), ('g2', ?, ?)", [$now, $now, $now, $now]);
        $g1 = (int) $db->value("SELECT id FROM groups WHERE name = 'g1'");
        $g2 = (int) $db->value("SELECT id FROM groups WHERE name = 'g2'");
        foreach ([[$g1, 'mgr', 1], [$g1, 'member', 0], [$g2, 'mgrOther', 1], [$g2, 'mgrNone', 1]] as [$g, $login, $m]) {
            $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, ?, ?)', [$g, $this->u[$login], $m, $now]);
        }
        foreach ([['group', $g1], ['user', $this->u['direct']], ['user', $this->u['mgrOther']]] as [$type, $sid]) {
            $db->execute('INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, ?, ?, ?)', [$this->p, $type, $sid, $now]);
        }
        $this->f = new RunFixture($this->app, $root, $this->p, $this->u['admin']);
        $f = $this->f;
        $f->analyze(['a.py' => 3, 'b.py' => 1], [['a.py', 1], ['a.py', 2], ['b.py', 1]]);
        $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 3]]);
        $this->d = ['D1' => $f->id('a.py', 1), 'D2' => $f->id('a.py', 2), 'D3' => $f->id('b.py', 1), 'D4' => $f->id('a.py', 3)];
        $user = $db->one('SELECT * FROM users WHERE id = ?', [$this->u['admin']]);
        $this->app->defectUpdate()->update($user, $this->d['D1'], ['version' => 1 + (int) $db->value('SELECT version FROM defects WHERE id = ?', [$this->d['D1']]) - 1,
            'status' => 'in_progress', 'assignee_id' => $this->u['direct']]);
        $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 3]]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @param array<string, string> $q @return list<string> */
    private function names(TestClient $c, array $q, int $status = 200): array
    {
        $r = $c->get("/api/v1/projects/{$this->p}/defects", $q);
        $this->assertSame($status, $r->status, json_encode($q) . ' ' . $r->body);
        if ($status !== 200) {
            return [$r->decoded()['error']['code']];
        }
        $names = array_flip($this->d);
        $out = array_map(static fn (array $i): string => $names[$i['id']], $r->decoded()['data']);
        sort($out);
        return $out;
    }

    public function testRunParamOnDefectList(): void
    {
        $c = $this->as('member');
        $current = $this->names($c, []);
        $this->assertSame(['D1', 'D2', 'D3', 'D4'], $current, 'run 생략 = 현재 표(해결된 결함 포함, 기존 동작)');
        $this->assertSame($current, $this->names($c, ['run' => '3']), '최신 Run = 현재 표');
        $this->assertSame(['D1', 'D2', 'D3'], $this->names($c, ['run' => '1']));
        $this->assertSame(['D1', 'D4'], $this->names($c, ['run' => '2']));
        // 과거 Run의 값은 그 Run 종료 시점(D1의 in_progress·담당자는 Run 2 뒤)
        $r = $c->get("/api/v1/projects/{$this->p}/defects", ['run' => '2', 'status' => 'open']);
        $this->assertSame(2, $r->decoded()['page']['total'], 'Run 2 시점 D1은 open');
        $row = array_values(array_filter($r->decoded()['data'], fn (array $i): bool => $i['id'] === $this->d['D1']))[0];
        $this->assertSame(['open', null], [$row['status'], $row['assignee'] === null ? null : $row['assignee']['id'] ?? null]);
        $this->assertSame(1, $c->get("/api/v1/projects/{$this->p}/defects", ['status' => 'in_progress'])->decoded()['page']['total'], '현재는 in_progress');
        // 필터는 복원 값 기준
        $this->assertSame(['D1'], $this->names($c, ['run' => '2', 'q' => 'a.py:1']));
        // 오류
        $this->assertSame(['invalid_run'], $this->names($c, ['run' => 'x'], 422));
        $this->assertSame(['invalid_run'], $this->names($c, ['run' => '0'], 422));
        $this->assertSame(['run_not_found'], $this->names($c, ['run' => '9'], 404));
        // 보이지 않는 프로젝트는 run이 있어도 404(존재를 숨김)
        $this->assertSame(['project_not_found'], $this->names($this->as('outsider'), ['run' => '1'], 404));
    }

    public function testSummaryRunView(): void
    {
        $c = $this->as('direct');
        $s = $c->get("/api/v1/projects/{$this->p}/defects/summary", ['run' => '1'])->decoded()['data'];
        $this->assertSame(3, $s['total']);
        $this->assertSame(['run' => 1, 'latest' => 3, 'read_only' => true], $s['run_view']);
        $this->assertSame(['latest' => 3, 'backfill_needed' => false, 'unnumbered' => 0], $s['runs']);
        $s = $c->get("/api/v1/projects/{$this->p}/defects/summary")->decoded()['data'];
        $this->assertSame([4, null, false], [$s['total'], $s['run_view']['run'], $s['run_view']['read_only']]);
    }

    public function testDetailAndCodeInRun(): void
    {
        $c = $this->as('member');
        $d = $c->get("/api/v1/defects/{$this->d['D1']}", ['run' => '2'])->decoded()['data'];
        $this->assertSame(['open', 'not_defined', null], [$d['status'], $d['severity'], $d['assignee']]);
        $this->assertSame(['run' => 2, 'latest' => 3, 'in_run' => true, 'read_only' => true], $d['run_view']);
        $this->assertSame([['from' => 1, 'to' => null]], $d['runs']);
        $this->assertSame([1, 3], [$d['first_seen']['run'], $d['last_seen']['run']]);
        $cur = $c->get("/api/v1/defects/{$this->d['D1']}")->decoded()['data'];
        $this->assertSame('in_progress', $cur['status']);
        $this->assertFalse(isset($cur['run_view']), 'run 생략이면 run_view 없음(기존 응답 + 필드)');
        // 그 Run에 없던 결함: 200 + in_run=false
        $d = $c->get("/api/v1/defects/{$this->d['D2']}", ['run' => '2'])->decoded()['data'];
        $this->assertSame([false, [['from' => 1, 'to' => 1]]], [$d['run_view']['in_run'], $d['runs']]);
        // 코드: 한 번도 존재하지 않은 Run이면 not_in_run
        $code = $c->get("/api/v1/defects/{$this->d['D4']}/code", ['run' => '1'])->decoded()['data'];
        $this->assertSame(['none', 'not_in_run'], [$code['source'], $code['reason']]);
        $this->assertSame(404, $c->get("/api/v1/defects/{$this->d['D4']}/code", ['run' => '7'])->status);
        $this->assertSame(422, $c->get("/api/v1/defects/{$this->d['D4']}", ['run' => 'abc'])->status);
        $this->assertSame(404, $this->as('outsider')->get("/api/v1/defects/{$this->d['D1']}", ['run' => '1'])->status, '보이지 않으면 404');
        // 이력: 자동 이력에 Run 번호
        $h = $c->get("/api/v1/defects/{$this->d['D2']}/history")->decoded()['data'];
        $auto = array_values(array_filter($h, static fn (array $i): bool => $i['kind'] === 'auto'));
        $this->assertSame([2], array_column($auto, 'run'));
    }

    public function testRunList(): void
    {
        $c = $this->as('member');
        $r = $c->get("/api/v1/projects/{$this->p}/runs");
        $this->assertSame(200, $r->status, $r->body);
        $data = $r->decoded()['data'];
        $this->assertSame([3, 2, 1], array_column($data, 'run'));
        $this->assertSame([2, 2, 3], array_column($data, 'total'), '그 Run에 존재한 결함 수');
        $this->assertSame(3, $r->decoded()['page']['total']);
        $this->assertSame('0', $r->headers()['X-Runs-Backfill-Needed'] ?? null);
        $this->assertSame(404, $this->as('outsider')->get("/api/v1/projects/{$this->p}/runs")->status);
        $this->assertSame(401, (new TestClient($this->app))->get("/api/v1/projects/{$this->p}/runs")->status);
    }

    public function testPagesWithRun(): void
    {
        $c = $this->as('member');
        $html = $c->get("/defects/{$this->d['D1']}", ['run' => '2'])->body;
        $this->assertTrue(str_contains($html, 'data-run-banner="2"'), '과거 Run 안내');
        $this->assertTrue(str_contains($html, 'data-read-only-edit') && !str_contains($html, 'data-edit-form'), '수정 폼 대신 안내');
        $html = $c->get("/defects/{$this->d['D1']}")->body;
        $this->assertTrue(str_contains($html, 'data-edit-form') && !str_contains($html, 'data-run-banner='), '최신 Run = 기존 화면');
        $html = $c->get("/defects/{$this->d['D2']}", ['run' => '2'])->body;
        $this->assertTrue(str_contains($html, 'data-not-in-run'), '그 Run에 없던 결함 안내');
        $this->assertSame(404, $c->get("/defects/{$this->d['D1']}", ['run' => '9'])->status);
        $this->assertSame(422, $c->get("/defects/{$this->d['D1']}", ['run' => 'x'])->status);
        $this->assertSame(404, $this->as('outsider')->get("/defects/{$this->d['D1']}", ['run' => '1'])->status);
    }

    public function testWriteApisTakeNoRun(): void
    {
        // 쓰기 API는 run을 받지 않는다: 붙여 보내도 무시되고 현재 결함을 바꾼다(과거 Run 값을 바꾸는 경로가 없음)
        $c = $this->as('admin');
        $id = $this->d['D4'];
        $v = (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$id]);
        $r = $c->request('PATCH', "/api/v1/defects/{$id}", ['run' => '1'], ['version' => $v, 'severity' => 'low']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('low', (string) $this->app->db()->value('SELECT severity FROM defects WHERE id = ?', [$id]));
        $this->assertSame(['none', 'not_in_run'], [$c->get("/api/v1/defects/{$id}/code", ['run' => '1'])->decoded()['data']['source'], 'not_in_run'],
            'Run 1에는 여전히 없음');
    }

    public function testRunCompareRemoved(): void
    {
        // P20S: Run 비교 화면·API·메뉴·선택기 항목이 없다(admin 기준). compare·set 쿼리는 모르는 파라미터라 무시되고 현재 목록이 나온다
        $c = $this->as('admin');
        $this->assertSame(404, $c->get('/compare')->status);
        $this->assertSame(404, $c->get("/projects/{$this->p}/compare")->status);
        $this->assertSame(404, $c->get("/api/v1/projects/{$this->p}/runs/compare", ['from' => '1', 'to' => '2'])->status);
        $html = $c->get("/projects/{$this->p}")->body;
        $this->assertFalse(str_contains($html, 'nav.compare') || str_contains($html, 'data-compare'), '메뉴·버튼·선택기 항목 없음');
        $this->assertSame($this->names($c, []), $this->names($c, ['compare' => '1:2', 'set' => 'added']));
    }
}
