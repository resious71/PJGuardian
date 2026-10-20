<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 분석 조회·취소 API(명세 3.1, 5.2, 5.4, P14). 접근 가능(kim·lee 매핑), 접근 불가(park, 404), admin 세 경우.
 * 취소: 요청자 본인·admin만(lee 403), 끝난 분석 409, queued 즉시 cancelled, running은 취소 요청 플래그, CSRF 필요,
 * 취소 후 결함과 직전 성공 분석 불변.
 */
final class AnalysisApiTest extends TestCase
{
    private App $app;
    private int $py;
    private int $kim;

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('anapi'));
        $db = $this->app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $this->kim = TestDb::user($db, 'kim', 'user');
        TestDb::user($db, 'lee', 'user');
        TestDb::user($db, 'park', 'user');
        $this->py = TestDb::project($db, 'py', 'python');
        $actor = Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], null);
        foreach (['kim', 'lee'] as $u) {
            $this->app->projectAccess()->add($actor, $this->py, ['subject_type' => 'user', 'login_id' => $u]);
        }
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function analysis(string $status, array $over = []): int
    {
        return TestDb::analysis($this->app->db(), $this->py, $this->kim, ['status' => $status] + $over);
    }

    public function testListAndDetailWithAccessScope(): void
    {
        $done = $this->analysis('succeeded');
        $q1 = $this->analysis('queued');
        $q2 = $this->analysis('queued');
        $kim = $this->as('kim');
        $r = $kim->get("/api/v1/projects/{$this->py}/analyses");
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->decoded();
        $this->assertSame([$q2, $q1, $done], array_column($d['data'], 'id'), '최신순');
        $this->assertSame(3, $d['page']['total'] ?? null);
        $this->assertSame([2, 1, null], array_column($d['data'], 'queue_position'));
        $this->assertSame('kim', $d['data'][0]['requested_by']['login_id']);
        $r = $kim->get("/api/v1/projects/{$this->py}/analyses", ['status' => 'succeeded,failed', 'size' => '1']);
        $this->assertSame([[$done], 1], [array_column($r->decoded()['data'], 'id'), $r->decoded()['page']['total']]);
        $this->assertSame(['succeeded', ['total' => 0, 'new' => 0, 'resolved' => 0, 'reopened' => 0, 'unchanged' => 0]],
            [$r->decoded()['data'][0]['status'], $r->decoded()['data'][0]['counts']]);
        $this->assertSame('invalid_status_filter', $kim->get("/api/v1/projects/{$this->py}/analyses", ['status' => 'queued,bogus'])->decoded()['error']['code']);

        $one = $kim->get("/api/v1/analyses/{$q2}")->decoded()['data'];
        $this->assertSame([$q2, 'queued', 2, false, 'ruff'], [$one['id'], $one['status'], $one['queue_position'], $one['cancel_requested'], $one['tool']]);
        $this->assertSame(200, $this->as('admin')->get("/api/v1/analyses/{$q2}")->status, 'admin');

        $park = $this->as('park');
        $this->assertSame([404, 'project_not_found'], [$park->get("/api/v1/projects/{$this->py}/analyses")->status, $park->get("/api/v1/projects/{$this->py}/analyses")->decoded()['error']['code']]);
        $hidden = $park->get("/api/v1/analyses/{$q2}");
        $missing = $kim->get('/api/v1/analyses/999999');
        $this->assertSame([404, 'analysis_not_found'], [$hidden->status, $hidden->decoded()['error']['code']], '보이지 않는 프로젝트의 분석은 404');
        $this->assertSame([404, 'analysis_not_found'], [$missing->status, $missing->decoded()['error']['code']], '없는 분석과 같은 응답');
        $this->assertSame(404, $kim->get('/api/v1/analyses/abc')->status, '형식이 틀린 id');
        $this->assertSame(404, $park->post("/api/v1/analyses/{$q2}/cancel")->status, '보이지 않으면 취소도 404(403 아님)');
    }

    public function testCancelPermissionsAndStates(): void
    {
        $queued = $this->analysis('queued');
        $running = $this->analysis('running');
        $done = $this->analysis('failed', ['failure_code' => 'timeout']);
        $lee = $this->as('lee');
        $r = $lee->post("/api/v1/analyses/{$queued}/cancel");
        $this->assertSame([403, 'analysis_cancel_forbidden'], [$r->status, $r->decoded()['error']['code']], '요청자가 아닌 사용자');
        $kim = $this->as('kim');
        $this->assertSame(403, $kim->post("/api/v1/analyses/{$queued}/cancel", [], false)->status, 'CSRF 토큰 없음');
        $this->assertSame('queued', $this->app->db()->value('SELECT status FROM analyses WHERE id = ?', [$queued]));

        $r = $kim->post("/api/v1/analyses/{$queued}/cancel");
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['cancelled', true, null], [$r->decoded()['data']['status'], $r->decoded()['data']['cancel_requested'], $r->decoded()['data']['queue_position']], 'queued는 즉시 취소');
        $this->assertSame('2026-10-02T12:00:00Z', $r->decoded()['data']['finished_at']);
        $again = $kim->post("/api/v1/analyses/{$queued}/cancel");
        $this->assertSame([409, 'analysis_not_cancellable'], [$again->status, $again->decoded()['error']['code']]);
        $this->assertSame(409, $kim->post("/api/v1/analyses/{$done}/cancel")->status, '끝난 분석');

        $admin = $this->as('admin');
        $r = $admin->post("/api/v1/analyses/{$running}/cancel");
        $this->assertSame(['running', true], [$r->decoded()['data']['status'], $r->decoded()['data']['cancel_requested']], 'running은 취소 요청만(워커가 종료)');

        // 주기 실행(요청자 없음)은 admin만
        $sched = TestDb::analysis($this->app->db(), $this->py, $this->kim, ['status' => 'queued', 'trigger_kind' => 'schedule', 'requested_by' => null]);
        $this->assertSame(403, $kim->post("/api/v1/analyses/{$sched}/cancel")->status);
        $this->assertSame(200, $admin->post("/api/v1/analyses/{$sched}/cancel")->status);
    }

    public function testCancelLeavesDefectsAndBaselineUntouched(): void
    {
        $db = $this->app->db();
        $base = $this->analysis('succeeded');
        $d1 = TestDb::defect($db, $this->py, $base);
        $d2 = TestDb::defect($db, $this->py, $base, ['status' => 'resolved']);
        $before = $db->all('SELECT * FROM defects ORDER BY id');
        $q = $this->analysis('queued');
        $r = $this->analysis('running');
        $kim = $this->as('kim');
        $this->assertSame(200, $kim->post("/api/v1/analyses/{$q}/cancel")->status);
        $this->assertSame(200, $kim->post("/api/v1/analyses/{$r}/cancel")->status);
        $this->app->analysisQueue()->markCancelled($r); // 워커가 하는 일
        $this->assertSame($before, $db->all('SELECT * FROM defects ORDER BY id'), '결함 불변');
        $latest = $db->value("SELECT id FROM analyses WHERE project_id = ? AND status = 'succeeded' ORDER BY id DESC LIMIT 1", [$this->py]);
        $this->assertSame($base, (int) $latest, '직전 성공 분석(비교 기준) 불변');
        $this->assertSame([$d1, $d2], array_map('intval', array_column($before, 'id')));
    }
}
