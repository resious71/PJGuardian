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
 * 프로젝트 접근 범위(명세 3.1, 19.10): 역할 × 매핑 조합 표, 존재 숨김(404), 변경 즉시 반영, 매핑 API, 트리거, 판정기 도우미.
 *
 * 사용자: admin, direct(p1에 직접 매핑), member(g1 구성원), manager(g1 관리자), other(g2 구성원), nobody(매핑 없음)
 * 프로젝트: p1(g1 + direct 매핑), p2(매핑 없음 → admin만)
 */
final class ProjectAccessTest extends TestCase
{
    private App $app;
    private int $p1;
    private int $p2;
    private int $g1;
    private int $g2;
    /** @var array<string, int> */
    private array $id = [];

    public function setUp(): void
    {
        $root = $this->tempDir('access');
        $this->app = TestApp::make($root, ['paths' => ['snapshots_dir' => $root, 'uploads_dir' => $root, 'work_dir' => $root]]);
        foreach (['admin' => 'admin', 'direct' => 'user', 'member' => 'user', 'manager' => 'user', 'other' => 'user', 'nobody' => 'user'] as $login => $role) {
            $this->id[$login] = TestDb::user($this->app->db(), $login, $role);
        }
        $admin = $this->app->users()->get($this->id['admin']);
        $g = $this->app->groups();
        $this->g1 = (int) $g->create($admin, null, ['name' => 'team1'])['id'];
        $this->g2 = (int) $g->create($admin, null, ['name' => 'team2'])['id'];
        $g->addMember($admin, null, $this->g1, 'member');
        $g->addMember($admin, null, $this->g1, 'manager');
        $g->setManager($admin, null, $this->g1, $this->id['manager'], true);
        $g->addMember($admin, null, $this->g2, 'other');
        $this->p1 = TestDb::project($this->app->db(), 'alpha');
        $this->p2 = TestDb::project($this->app->db(), 'beta');
        $this->access()->add($this->actor(), $this->p1, ['subject_type' => 'group', 'group_id' => $this->g1]);
        $this->access()->add($this->actor(), $this->p1, ['subject_type' => 'user', 'login_id' => 'direct']);
    }

    private function access(): \PJGuardian\Services\ProjectAccess
    {
        return $this->app->projectAccess();
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->id['admin'], 'login_id' => 'admin'], '10.0.0.1');
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return list<string> 목록 API의 프로젝트 이름 */
    private function listed(TestClient $c): array
    {
        return array_column($c->get('/api/v1/projects')->decoded()['data'], 'name');
    }

    private function user(string $login): array
    {
        return $this->app->users()->get($this->id[$login]);
    }

    // ---- 조합 표 ----

    public function testRoleByMappingMatrix(): void
    {
        // [목록에 보임, 상세 API, 상세 화면, 수정(PATCH), 삭제(DELETE, 이름 틀림), 삭제 확인 정보, 수정 화면, 삭제 화면]
        $visibleUser = [true, 200, 200, 403, 403, 403, 403, 403];
        $hidden = [false, 404, 404, 404, 404, 404, 404, 404];
        $admin = [true, 200, 200, 200, 400, 200, 200, 200]; // 관리자의 삭제는 이름 확인(400)까지 진행 = 접근·권한 통과
        $expect = [
            'p1' => ['admin' => $admin, 'direct' => $visibleUser, 'member' => $visibleUser, 'manager' => $visibleUser, 'other' => $hidden, 'nobody' => $hidden],
            'p2' => ['admin' => $admin, 'direct' => $hidden, 'member' => $hidden, 'manager' => $hidden, 'other' => $hidden, 'nobody' => $hidden],
        ];
        foreach ($expect as $pname => $rows) {
            $pid = $pname === 'p1' ? $this->p1 : $this->p2;
            $name = $pname === 'p1' ? 'alpha' : 'beta';
            foreach ($rows as $who => $e) {
                $c = $this->as($who);
                $actual = [
                    in_array($name, $this->listed($c), true),
                    $c->get("/api/v1/projects/{$pid}")->status,
                    $c->get("/projects/{$pid}")->status,
                    $c->patch("/api/v1/projects/{$pid}", [])->status,
                    $c->request('DELETE', "/api/v1/projects/{$pid}", [], ['confirm_name' => 'WRONG'])->status,
                    $c->get("/api/v1/projects/{$pid}/deletion-info")->status,
                    $c->get("/projects/{$pid}/edit")->status,
                    $c->get("/projects/{$pid}/delete")->status,
                ];
                $this->assertSame($e, $actual, "{$who} × {$pname}");
            }
        }
        $this->assertSame(2, (int) $this->app->db()->value('SELECT COUNT(*) FROM projects'), '아무것도 지워지지 않음');
    }

    public function testHiddenProjectLooksExactlyLikeMissingOne(): void
    {
        $c = $this->as('nobody');
        foreach (['GET /api/v1/projects/%d', 'PATCH /api/v1/projects/%d', 'DELETE /api/v1/projects/%d', 'GET /api/v1/projects/%d/deletion-info', 'GET /api/v1/projects/%d/access'] as $spec) {
            [$m, $path] = explode(' ', $spec);
            $hidden = $c->request($m, sprintf($path, $this->p2), [], $m === 'GET' ? null : ['confirm_name' => 'beta']);
            $missing = $c->request($m, sprintf($path, 999999), [], $m === 'GET' ? null : ['confirm_name' => 'beta']);
            $strip = static function (array $d): array {
                unset($d['error']['request_id']);
                return $d;
            };
            $this->assertSame(404, $hidden->status, $spec);
            $this->assertSame([$missing->status, $strip($missing->decoded())], [$hidden->status, $strip($hidden->decoded())], "{$spec}: 없는 id와 같은 응답");
            $this->assertSame('project_not_found', $hidden->decoded()['error']['code']);
        }
        // 화면도 같은 본문(요청 ID만 다름)
        $page = static fn (string $b): string => (string) preg_replace('/요청 ID: [0-9a-f]+/', '', $b);
        $this->assertSame($page($c->get('/projects/999999')->body), $page($c->get("/projects/{$this->p2}")->body));
        $this->assertSame($c->get('/projects/999999')->status, $c->get("/projects/{$this->p2}")->status);
    }

    public function testListShowsProjectOnceEvenWithTwoPaths(): void
    {
        // direct를 g1에도 넣는다: 직접 + 그룹, 두 경로
        $this->app->groups()->addMember($this->user('admin'), null, $this->g1, 'direct');
        $res = $this->as('direct')->get('/api/v1/projects')->decoded();
        $this->assertSame(['alpha'], array_column($res['data'], 'name'));
        $this->assertSame(1, $res['page']['total']);
        $this->assertSame(['alpha', 'beta'], $this->listed($this->as('admin')));
        $this->assertSame([], $this->listed($this->as('nobody')));
    }

    public function testChangesApplyOnNextRequest(): void
    {
        $admin = $this->user('admin');
        $member = $this->as('member');
        $direct = $this->as('direct');
        $manager = $this->as('manager');
        $this->assertSame(200, $member->get("/api/v1/projects/{$this->p1}")->status);

        $this->app->groups()->removeMember($admin, null, $this->g1, $this->id['member']);
        $this->assertSame(404, $member->get("/api/v1/projects/{$this->p1}")->status, '구성원 제거 직후');
        $this->assertSame([], $this->listed($member));

        $this->access()->remove($this->actor(), $this->p1, 'user', $this->id['direct']);
        $this->assertSame(404, $direct->get("/api/v1/projects/{$this->p1}")->status, '매핑 제거 직후');

        $this->assertSame(200, $manager->get("/api/v1/projects/{$this->p1}")->status);
        $this->app->groups()->delete($admin, null, $this->g1, 'team1');
        $this->assertSame(404, $manager->get("/api/v1/projects/{$this->p1}")->status, '그룹 삭제 직후');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM project_access WHERE subject_type = 'group' AND subject_id = ?", [$this->g1]), '그룹 매핑도 삭제');

        // 다시 매핑하면 바로 보임
        $this->access()->add($this->actor(), $this->p2, ['subject_type' => 'group', 'group_id' => $this->g2]);
        $this->assertSame(200, $this->as('other')->get("/api/v1/projects/{$this->p2}")->status);
    }

    // ---- 판정기 도우미 ----

    public function testHelpers(): void
    {
        $a = $this->access();
        $this->assertTrue($a->canView($this->user('manager'), $this->p1), '그룹 관리자는 구성원이므로 보임');
        $this->assertFalse($a->canView($this->user('other'), $this->p1));
        $this->assertFalse($a->canView(null, $this->p1));
        $this->assertFalse($a->canView($this->user('admin'), 999999), '없는 프로젝트는 admin도 false');
        $this->assertTrue($a->canView($this->user('admin'), $this->p2));
        $this->assertTrue($a->userCanView($this->id['direct'], $this->p1));
        $this->assertFalse($a->userCanView($this->id['nobody'], $this->p1));

        $this->assertSame(['admin', 'direct', 'manager', 'member'], array_column($a->assignableUsers($this->p1), 'login_id'));
        $this->assertSame(['admin'], array_column($a->assignableUsers($this->p2), 'login_id'), '매핑 없음 → admin만');
        $this->assertSame([], $a->assignableUsers(999999));
        // 비활성 사용자는 후보와 수신자에서 빠지고 판정도 false
        $this->app->db()->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->id['member']]);
        $this->assertSame(['admin', 'direct', 'manager'], array_column($a->assignableUsers($this->p1), 'login_id'));
        $this->assertFalse($a->userCanView($this->id['member'], $this->p1));
        // 두 경로로 매핑돼도 한 번
        $this->app->groups()->addMember($this->user('admin'), null, $this->g1, 'direct');
        $this->assertSame([$this->id['admin'], $this->id['direct'], $this->id['manager']], $a->usersWithAccess($this->p1));

        $m = $a->mappings($this->p1);
        $this->assertSame([['team1', 3]], array_map(static fn (array $g): array => [$g['name'], $g['member_count']], $m['groups']));
        $this->assertSame(['direct'], array_column($m['users'], 'login_id'));
        $this->assertSame(2, $m['viewer_count'], 'admin 제외, 활성만, 중복 제거(direct, manager)');
        $this->assertSame(['groups' => 1, 'users' => 1], $a->summary($this->p1));
        $this->assertSame(['groups' => 0, 'users' => 0], $a->summary($this->p2));

        [$sql, $params] = $a->visibleProjectsSql($this->id['manager']);
        $ids = array_map('intval', array_column($this->app->db()->all("SELECT p.id FROM projects p WHERE {$sql}", $params), 'id'));
        $this->assertSame([$this->p1], $ids);
    }

    // ---- 매핑 API ----

    public function testMappingApi(): void
    {
        $admin = $this->as('admin');
        $base = "/api/v1/projects/{$this->p2}/access";
        $this->assertSame(['groups' => [], 'users' => [], 'viewer_count' => 0], $admin->get($base)->decoded()['data']);

        $r = $admin->post($base, ['subject_type' => 'group', 'group_id' => $this->g2]);
        $this->assertSame(201, $r->status, $r->body);
        $this->assertSame(['team2'], array_column($r->decoded()['data']['groups'], 'name'));
        $this->assertSame(1, $r->decoded()['data']['viewer_count']);
        $r = $admin->post($base, ['subject_type' => 'user', 'login_id' => 'nobody']);
        $this->assertSame([201, 2], [$r->status, $r->decoded()['data']['viewer_count']]);

        $cases = [
            [['subject_type' => 'group', 'group_id' => $this->g2], 409, 'access_exists'],
            [['subject_type' => 'user', 'login_id' => 'nobody'], 409, 'access_exists'],
            [['subject_type' => 'group', 'group_id' => 999], 404, 'group_not_found'],
            [['subject_type' => 'user', 'login_id' => 'ghost'], 404, 'user_not_found'],
            [['subject_type' => 'user', 'login_id' => 'admin'], 409, 'admin_implicit_access'],
            [['subject_type' => 'role', 'login_id' => 'x'], 400, 'invalid_input'],
            [['subject_type' => 'group', 'group_id' => 'abc'], 400, 'invalid_input'],
            [['subject_type' => 'user'], 400, 'invalid_input'],
        ];
        foreach ($cases as [$body, $status, $code]) {
            $res = $admin->post($base, $body);
            $this->assertSame([$status, $code], [$res->status, $res->decoded()['error']['code'] ?? null], json_encode($body));
        }
        TestDb::user($this->app->db(), 'gone', 'user', 'inactive');
        $this->assertSame('user_inactive', $admin->post($base, ['subject_type' => 'user', 'login_id' => 'gone'])->decoded()['error']['code']);

        $this->assertSame(403, $admin->request('POST', $base, [], ['subject_type' => 'user', 'login_id' => 'direct'], null, [], false)->status, 'CSRF');
        $del = $admin->request('DELETE', "{$base}/user/{$this->id['nobody']}");
        $this->assertSame([200, []], [$del->status, $del->decoded()['data']['users']]);
        $this->assertSame('access_not_found', $admin->request('DELETE', "{$base}/user/{$this->id['nobody']}")->decoded()['error']['code']);
        $this->assertSame(404, $admin->request('DELETE', "{$base}/role/1")->status, '종류는 group|user만');

        // 보이는 일반 사용자 403, 보이지 않는 사용자 404, 그룹 관리자도 매핑 못 함
        $this->assertSame(403, $this->as('manager')->get("/api/v1/projects/{$this->p1}/access")->status);
        $this->assertSame(403, $this->as('manager')->post("/api/v1/projects/{$this->p1}/access", ['subject_type' => 'user', 'login_id' => 'nobody'])->status);
        $this->assertSame(404, $this->as('manager')->post("/api/v1/projects/{$this->p2}/access", ['subject_type' => 'user', 'login_id' => 'manager'])->status);
        $this->assertSame(403, $this->as('direct')->request('DELETE', "/api/v1/projects/{$this->p1}/access/user/{$this->id['direct']}")->status);

        $audit = $this->app->db()->all("SELECT action, target_id, details FROM audit_log WHERE action LIKE 'project.access_%' ORDER BY id");
        $last = array_slice($audit, -3);
        $this->assertSame(['project.access_add', 'project.access_add', 'project.access_remove'], array_column($last, 'action'));
        $this->assertSame(['subject_type' => 'group', 'subject_id' => $this->g2, 'name' => 'team2'], json_decode($last[0]['details'], true));
        $this->assertSame(['subject_type' => 'user', 'subject_id' => $this->id['nobody'], 'name' => 'nobody'], json_decode($last[2]['details'], true));
        $this->assertTrue($this->app->audit()->verify()['ok']);
    }

    public function testGroupDeletionInfoCountsMappedProjects(): void
    {
        $admin = $this->user('admin');
        $this->access()->add($this->actor(), $this->p2, ['subject_type' => 'group', 'group_id' => $this->g1]);
        $this->assertSame(['members' => 2, 'projects' => 2], $this->app->groups()->deletionInfo($admin, $this->g1));
        $this->app->groups()->delete($admin, null, $this->g1, 'team1');
        $details = json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'group.delete'"), true);
        $this->assertSame(2, $details['projects']);
        $this->assertSame(1, $this->access()->mappingCount($this->p1), '사용자 매핑은 남음');
    }

    public function testProjectDeleteRemovesMappingsAndInfoShowsCount(): void
    {
        $admin = $this->as('admin');
        $this->assertSame(2, $admin->get("/api/v1/projects/{$this->p1}/deletion-info")->decoded()['data']['access_mappings']);
        $this->assertContains('접근 매핑 2개', $admin->get("/projects/{$this->p1}/delete")->body);
        $this->assertSame(200, $admin->request('DELETE', "/api/v1/projects/{$this->p1}", [], ['confirm_name' => 'alpha'])->status);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM project_access WHERE project_id = ?', [$this->p1]));
    }

    public function testTriggersGuardSubjects(): void
    {
        $db = $this->app->db();
        $ins = static fn (string $type, int $sid) => $db->execute(
            'INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, ?, ?, ?)',
            [1, $type, $sid, TestDb::T0],
        );
        $e = $this->assertThrows(\PDOException::class, fn () => $ins('group', 999));
        $this->assertContains('project_access_subject_missing', $e->getMessage());
        $e = $this->assertThrows(\PDOException::class, fn () => $ins('user', 999));
        $this->assertContains('project_access_subject_missing', $e->getMessage());
        $e = $this->assertThrows(\PDOException::class, fn () => $db->execute("UPDATE project_access SET subject_id = ? WHERE subject_type = 'user'", [$this->id['nobody']]));
        $this->assertContains('project_access_rows_are_immutable', $e->getMessage());
        $e = $this->assertThrows(\PDOException::class, fn () => $ins('team', $this->g1));
        $this->assertContains('CHECK', $e->getMessage());
    }

    // ---- 화면 ----

    public function testScreens(): void
    {
        // 일반 사용자의 빈 목록 문구
        $body = $this->as('nobody')->get('/projects')->body;
        $this->assertContains('접근할 수 있는 프로젝트가 없습니다', $body, '빈 상태 제목');
        $this->assertContains('관리자에게 접근 권한을 요청하세요', $body, '빈 상태 안내');

        // admin 목록: 접근 요약 열, 매핑 없음 = 관리자만
        $list = $this->as('admin')->get('/projects')->body;
        $this->assertContains('그룹 1 · 사용자 1', $list);
        $this->assertContains('관리자만', $list);

        // 상세: admin에게 요약, 일반 사용자에게는 매핑 정보 없음
        $admin = $this->as('admin');
        $this->assertContains('data-access-summary', $admin->get("/projects/{$this->p1}")->body);
        $this->assertContains('매핑이 없어 관리자만 볼 수 있습니다', $admin->get("/projects/{$this->p2}")->body);
        $userBody = $this->as('member')->get("/projects/{$this->p1}")->body;
        $this->assertNotContains('data-access-summary', $userBody);
        $this->assertNotContains('team1', $userBody, '일반 사용자에게 매핑 정보를 보여 주지 않음');

        // 수정 화면의 접근 권한 영역
        $edit = $admin->get("/projects/{$this->p1}/edit")->body;
        $this->assertContains('id="project-access"', $edit);
        $this->assertContains('team1', $edit);
        $this->assertContains('관리자 외 3명이 볼 수 있습니다', $edit);
        $this->assertContains('<option value="' . $this->g2 . '">team2</option>', $edit, '그룹 추가 선택 상자');
        $this->assertNotContains('access-warning', $edit);
        $this->assertContains('access-warning', $admin->get("/projects/{$this->p2}/edit")->body, '매핑 없으면 경고 배너');
    }

    public function testGroupManagerBadge(): void
    {
        $badge = 'data-badge="group-manager"';
        $this->assertContains($badge, $this->as('manager')->get('/projects')->body, '그룹 관리자에게 배지');
        foreach (['admin', 'member', 'nobody'] as $who) {
            $this->assertNotContains($badge, $this->as($who)->get('/projects')->body, "{$who}에게 배지 없음");
        }
        $body = $this->as('manager')->get('/projects')->body;
        $this->assertContains('data-icon="ui.group_manager"', $body, '아이콘 + 텍스트');
    }
}
