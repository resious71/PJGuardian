<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/** 그룹 API·화면(명세 3.1): 메뉴 노출, 관리 대상이 아닌 그룹 404, 요청 조작 거부, 변경 즉시 반영, 이스케이프 */
final class GroupHttpTest extends TestCase
{
    private App $app;
    private int $g1;
    private int $g2;
    /** @var array<string, int> */
    private array $id = [];

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('grouphttp'));
        foreach (['admin' => 'admin', 'mgrA' => 'user', 'memberA' => 'user', 'outsider' => 'user', 'mgrB' => 'user'] as $login => $role) {
            $this->id[$login] = TestDb::user($this->app->db(), $login, $role);
        }
        $admin = $this->app->users()->get($this->id['admin']);
        $g = $this->app->groups();
        $this->g1 = (int) $g->create($admin, null, ['name' => 'team1'])['id'];
        $this->g2 = (int) $g->create($admin, null, ['name' => 'team2'])['id'];
        $g->addMember($admin, null, $this->g1, 'mgrA');
        $g->setManager($admin, null, $this->g1, $this->id['mgrA'], true);
        $g->addMember($admin, null, $this->g1, 'memberA');
        $g->addMember($admin, null, $this->g2, 'mgrB');
        $g->setManager($admin, null, $this->g2, $this->id['mgrB'], true);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    public function testMenuVisibleOnlyToAdminAndGroupManagers(): void
    {
        // P12(사용자 결정): admin은 관리자 메뉴 "사용자 관리"와 "프로젝트 관리" 사이의 "그룹 관리", 일반 메뉴에는 없음(중복 방지).
        // admin이 아닌 그룹 관리자는 일반 메뉴 "프로젝트" 다음의 "그룹". 일반 구성원·비구성원은 둘 다 없음
        $order = static function (string $body): array {
            preg_match_all('/data-nav="((?:nav|admin)\.[a-z_]+)"/', $body, $m);
            return $m[1];
        };
        $expect = [
            'admin' => ['nav.dashboard', 'nav.projects', 'nav.defects', 'nav.my_defects', 'nav.rules', 'nav.history', 'nav.notifications', 'nav.guide',
                // P27A: "설정 관리"(admin.settings)를 도구·워커 관리 다음에 추가. X10(의도된 단언 변경): 설정 관리는 "시스템 관리"(admin.tools)의 탭으로 옮겨 메뉴 항목 없음
                'admin.users', 'admin.groups', 'admin.projects', 'admin.tools', 'admin.system', 'admin.audit', 'admin.guide'],
            'mgrA' => ['nav.dashboard', 'nav.projects', 'nav.groups', 'nav.defects', 'nav.my_defects', 'nav.rules', 'nav.history', 'nav.notifications', 'nav.guide'],
            'mgrB' => ['nav.dashboard', 'nav.projects', 'nav.groups', 'nav.defects', 'nav.my_defects', 'nav.rules', 'nav.history', 'nav.notifications', 'nav.guide'],
            'memberA' => ['nav.dashboard', 'nav.projects', 'nav.defects', 'nav.my_defects', 'nav.rules', 'nav.history', 'nav.notifications', 'nav.guide'],
            'outsider' => ['nav.dashboard', 'nav.projects', 'nav.defects', 'nav.my_defects', 'nav.rules', 'nav.history', 'nav.notifications', 'nav.guide'],
        ];
        foreach ($expect as $who => $items) {
            $this->assertSame($items, $order($this->as($who)->get('/projects')->body), "{$who} 메뉴 항목과 순서");
        }
        // 그룹 화면의 현재 메뉴 표시: admin = 그룹 관리, 그룹 관리자 = 그룹
        $this->assertSame(1, preg_match('#href="/groups" data-nav="admin\.groups"[^>]*aria-current="page"#', $this->as('admin')->get('/groups')->body), 'admin: 그룹 관리가 현재 메뉴');
        $this->assertSame(1, preg_match('#href="/groups" data-nav="nav\.groups"[^>]*aria-current="page"#', $this->as('mgrA')->get('/groups')->body), '그룹 관리자: 그룹이 현재 메뉴');
    }

    public function testPagesAndApiForNonManagersAre404(): void
    {
        foreach (['memberA', 'outsider'] as $who) {
            $c = $this->as($who);
            foreach (['/groups', "/groups/{$this->g1}"] as $page) {
                $this->assertSame(404, $c->get($page)->status, "{$who} {$page}");
            }
            $this->assertSame(404, $c->get('/api/v1/groups')->status);
            $this->assertSame(404, $c->get("/api/v1/groups/{$this->g1}/members")->status);
            $this->assertSame(403, $c->get('/api/v1/users/search', ['q' => 'mg'])->status, '검색은 관리자·그룹 관리자만');
        }
        $b = $this->as('mgrB');
        $this->assertSame(404, $b->get("/groups/{$this->g1}")->status, '다른 그룹 관리자: 404(존재를 숨김)');
        $this->assertSame(404, $b->post("/api/v1/groups/{$this->g1}/members", ['login_id' => 'outsider'])->status);
        $this->assertSame(404, $b->post("/api/v1/groups/{$this->g1}/accounts", ['login_id' => 'x1', 'display_name' => 'x'])->status);
        $this->assertSame(200, $b->get("/groups/{$this->g2}")->status);
        $this->assertSame(404, $this->as('admin')->get('/groups/99999')->status);
    }

    public function testGroupManagerCannotDoAdminOnlyThings(): void
    {
        $a = $this->as('mgrA');
        $this->assertSame(403, $a->post('/api/v1/groups', ['name' => 'x'])->status);
        $this->assertSame(403, $a->patch("/api/v1/groups/{$this->g1}", ['name' => 'renamed'])->status);
        $this->assertSame(403, $a->request('DELETE', "/api/v1/groups/{$this->g1}", [], ['confirm_name' => 'team1'])->status);
        foreach (['/groups/new', "/groups/{$this->g1}/edit", "/groups/{$this->g1}/delete", '/admin/users'] as $page) {
            $this->assertSame(403, $a->get($page)->status, $page);
        }
        // 사용자 관리 API(역할·비활성화·비밀번호)도 그대로 403
        $this->assertSame(403, $a->put("/api/v1/users/{$this->id['memberA']}/status", ['status' => 'inactive'])->status);
        $this->assertSame(403, $a->put("/api/v1/users/{$this->id['memberA']}/password", ['password' => 'newpass12'])->status);
        $this->assertSame('team1', $this->app->db()->value('SELECT name FROM groups WHERE id = ?', [$this->g1]));
    }

    public function testCreateAccountIgnoresRoleAndGroupInRequest(): void
    {
        $a = $this->as('mgrA');
        $res = $a->post("/api/v1/groups/{$this->g1}/accounts", ['login_id' => 'hack1', 'display_name' => 'H', 'role' => 'admin', 'group_id' => $this->g2]);
        $this->assertSame(201, $res->status, $res->body);
        $data = $res->decoded()['data'];
        $this->assertSame('user', $data['user']['role']);
        $this->assertTrue(is_string($data['temp_password']) && strlen($data['temp_password']) >= 8, '임시 비밀번호는 응답에 한 번');
        $groups = array_map('intval', array_column($this->app->db()->all('SELECT group_id FROM group_members WHERE user_id = ?', [$data['user']['id']]), 'group_id'));
        $this->assertSame([$this->g1], $groups);
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND login_id = 'hack1'"));
    }

    public function testSelfRemovalAndSelfUnsetAreRefused(): void
    {
        $a = $this->as('mgrA');
        $r = $a->request('DELETE', "/api/v1/groups/{$this->g1}/members/{$this->id['mgrA']}");
        $this->assertSame([403, 'cannot_remove_self'], [$r->status, $r->decoded()['error']['code']]);
        $r = $a->put("/api/v1/groups/{$this->g1}/members/{$this->id['mgrA']}/manager", ['is_manager' => false]);
        $this->assertSame([403, 'cannot_unset_self'], [$r->status, $r->decoded()['error']['code']]);
    }

    public function testMembershipChangesApplyOnNextRequest(): void
    {
        $member = $this->as('memberA');
        $this->assertSame(404, $member->get("/groups/{$this->g1}")->status);
        $a = $this->as('mgrA');
        $this->assertSame(200, $a->put("/api/v1/groups/{$this->g1}/members/{$this->id['memberA']}/manager", ['is_manager' => true])->status);
        $this->assertSame(200, $member->get("/groups/{$this->g1}")->status, '같은 세션의 다음 요청부터 관리 가능(캐시 없음)');
        $this->assertContains('data-nav="nav.groups"', $member->get('/projects')->body);
        $this->assertSame(200, $a->put("/api/v1/groups/{$this->g1}/members/{$this->id['memberA']}/manager", ['is_manager' => false])->status);
        $this->assertSame(404, $member->get("/groups/{$this->g1}")->status, '해제도 다음 요청부터');
    }

    public function testCsrfAndApiFlow(): void
    {
        $a = $this->as('mgrA');
        $noCsrf = $a->request('POST', "/api/v1/groups/{$this->g1}/members", [], ['login_id' => 'outsider'], null, [], false);
        $this->assertSame(403, $noCsrf->status);
        $this->assertSame(201, $a->post("/api/v1/groups/{$this->g1}/members", ['login_id' => 'outsider'])->status);
        $this->assertSame(409, $a->post("/api/v1/groups/{$this->g1}/members", ['login_id' => 'outsider'])->status);
        $search = $a->get('/api/v1/users/search', ['q' => 'outs'])->decoded()['data'];
        $this->assertSame([['login_id' => 'outsider', 'display_name' => 'outsider']], $search, '로그인 ID와 이름만');
        $members = $a->get("/api/v1/groups/{$this->g1}/members")->decoded()['data'];
        $this->assertSame(['memberA', 'mgrA', 'outsider'], array_column($members, 'login_id'));
        $this->assertSame(200, $a->request('DELETE', "/api/v1/groups/{$this->g1}/members/{$this->id['outsider']}")->status);
        $admin = $this->as('admin');
        $this->assertSame(['members' => 2, 'projects' => 0], $admin->get("/api/v1/groups/{$this->g1}/deletion-info")->decoded()['data']);
        $this->assertSame(400, $admin->request('DELETE', "/api/v1/groups/{$this->g1}", [], ['confirm_name' => 'TEAM1'])->status);
        $this->assertSame(200, $admin->request('DELETE', "/api/v1/groups/{$this->g1}", [], ['confirm_name' => 'team1'])->status);
    }

    public function testListPagesAndEscaping(): void
    {
        $admin = $this->as('admin');
        $id = (int) $admin->post('/api/v1/groups', ['name' => '<b>xss</b>', 'description' => '<img src=x onerror=1>'])->decoded()['data']['id'];
        $list = $admin->get('/groups')->body;
        $this->assertContains('href="/groups/new"', $list, 'admin: 새 그룹 버튼');
        $this->assertContains('&lt;b&gt;xss&lt;/b&gt;', $list);
        foreach (['/groups', "/groups/{$id}", "/groups/{$id}/edit", "/groups/{$id}/delete"] as $page) {
            $body = $admin->get($page)->body;
            $this->assertNotContains('<b>xss', $body, $page);
            $this->assertNotContains('<img src=x', $body, $page);
        }
        $mgr = $this->as('mgrA')->get('/groups')->body;
        $this->assertContains('team1', $mgr);
        $this->assertNotContains('team2', $mgr, '자기 그룹만');
        $this->assertNotContains('href="/groups/new"', $mgr);
        $detail = $this->as('mgrA')->get("/groups/{$this->g1}")->body;
        $this->assertContains('data-icon="ui.group_manager"', $detail, '그룹 관리자 표시는 아이콘 + 텍스트');
    }

    public function testUsersScreenShowsGroupNames(): void
    {
        $body = $this->as('admin')->get('/admin/users')->body;
        $this->assertContains('소속 그룹', $body, '사용자 관리 표의 읽기 전용 열');
        $api = $this->as('admin')->get('/api/v1/users')->decoded()['data'];
        $byLogin = array_column($api, 'groups', 'login_id');
        $this->assertSame(['team1'], $byLogin['mgrA']);
        $this->assertSame([], $byLogin['outsider']);
    }
}
