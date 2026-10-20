<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Navigation;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/** 프로젝트 API·화면(명세 4장, 13장, 20.4): 권한, 빈 상태, 이스케이프, 삭제 확인 */
final class ProjectHttpTest extends TestCase
{
    private App $app;

    public function setUp(): void
    {
        $root = $this->tempDir('projhttp');
        $this->app = TestApp::make($root, ['paths' => ['snapshots_dir' => $root, 'uploads_dir' => $root, 'work_dir' => $root]]);
        TestDb::user($this->app->db(), 'admin', 'admin');
        TestDb::user($this->app->db(), 'kim', 'user');
    }

    /** P11B: 일반 사용자는 매핑된 프로젝트만 본다. 조회·권한 시험은 kim을 프로젝트에 직접 매핑하고 한다 */
    private function grant(int $projectId, string $login): void
    {
        $adminId = (int) $this->app->db()->value("SELECT id FROM users WHERE login_id = 'admin'");
        $this->app->projectAccess()->add(Actor::fromUser(['id' => $adminId, 'login_id' => 'admin'], null), $projectId, ['subject_type' => 'user', 'login_id' => $login]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
        return $c;
    }

    public function testHomeIsTheProjectList(): void
    {
        $this->assertSame('/projects', Navigation::HOME, 'P11부터 홈 = 프로젝트 목록');
        $c = $this->as('kim');
        $this->assertSame('/projects', $c->get('/')->header('Location'));
        $this->assertSame('/projects', $c->get('/home')->header('Location'), '옛 임시 홈 주소는 홈으로 이동');
    }

    public function testRegularUserCannotCreateUpdateDeleteViaApiOrPages(): void
    {
        $admin = $this->as('admin');
        $id = (int) $admin->post('/api/v1/projects', ['name' => 'demo', 'language' => 'python'])->decoded()['data']['id'];
        $kim = $this->as('kim');
        $this->assertSame(404, $kim->patch("/api/v1/projects/{$id}", ['name' => 'hacked'])->status, 'P11B: 매핑 전에는 보이지 않으므로 404');
        $this->grant($id, 'kim');
        $checks = [
            ['POST', '/api/v1/projects', ['name' => 'x', 'language' => 'python']],
            ['PATCH', "/api/v1/projects/{$id}", ['name' => 'hacked']],
            ['DELETE', "/api/v1/projects/{$id}", ['confirm_name' => 'demo']],
            ['GET', "/api/v1/projects/{$id}/deletion-info", null],
        ];
        foreach ($checks as [$m, $path, $data]) {
            $res = $kim->request($m, $path, [], $data);
            $this->assertSame(403, $res->status, "{$m} {$path}");
            $this->assertSame('forbidden', $res->decoded()['error']['code']);
        }
        foreach (['/projects/new', "/projects/{$id}/edit", "/projects/{$id}/delete"] as $page) {
            $this->assertSame(403, $kim->get($page)->status, $page);
        }
        $this->assertSame('demo', $this->app->db()->value('SELECT name FROM projects WHERE id = ?', [$id]), '바뀌지 않음');

        // 조회는 매핑된 사용자(P11B)
        $this->assertSame(200, $kim->get('/api/v1/projects')->status);
        $this->assertSame(200, $kim->get("/api/v1/projects/{$id}")->status);
        $this->assertSame(200, $kim->get('/api/v1/projects/default-excludes')->status);
        $this->assertSame(200, $kim->get('/projects')->status);
        $this->assertSame(200, $kim->get("/projects/{$id}")->status);
        $this->assertSame(401, (new TestClient($this->app))->get('/api/v1/projects')->status, '비로그인 401');
    }

    public function testApiCrudFlowWithErrors(): void
    {
        $admin = $this->as('admin');
        $created = $admin->post('/api/v1/projects', ['name' => '  web  ', 'language' => 'javascript', 'description' => '<b>설명</b>']);
        $this->assertSame(201, $created->status);
        $id = (int) $created->decoded()['data']['id'];
        $this->assertSame('project_name_taken', $admin->post('/api/v1/projects', ['name' => 'WEB', 'language' => 'python'])->decoded()['error']['code']);
        $lang = $admin->patch("/api/v1/projects/{$id}", ['language' => 'python']);
        $this->assertSame([409, 'language_immutable'], [$lang->status, $lang->decoded()['error']['code']]);
        $bad = $admin->patch("/api/v1/projects/{$id}", ['exclude_patterns' => ['../x']]);
        $this->assertSame('invalid_exclude_pattern', $bad->decoded()['error']['code']);
        $this->assertContains('../x', $bad->decoded()['error']['message'], '어떤 패턴이 문제인지 알려 줌');
        $ok = $admin->patch("/api/v1/projects/{$id}", ['exclude_patterns' => ['*.min.js', 'vendor/']]);
        $this->assertSame(['*.min.js', 'vendor/'], $ok->decoded()['data']['exclude_patterns']);

        $list = $admin->get('/api/v1/projects')->decoded();
        $this->assertSame(1, $list['page']['total']);
        $this->assertSame('web', $list['data'][0]['name']);
        $this->assertTrue(array_key_exists('strip_top_folder', $admin->get("/api/v1/projects/{$id}")->decoded()['data']));
        $this->grant($id, 'kim');
        $this->assertFalse(array_key_exists('strip_top_folder', $this->as('kim')->get("/api/v1/projects/{$id}")->decoded()['data']), '관리자에게만');
        $defaults = $admin->get('/api/v1/projects/default-excludes')->decoded()['data'];
        $this->assertSame(['node_modules', '.venv', 'venv', '__pycache__', '.git', 'dist', 'build', '__MACOSX', '.DS_Store'], $defaults['names']);

        $info = $admin->get("/api/v1/projects/{$id}/deletion-info")->decoded()['data'];
        $this->assertSame(['analyses' => 0, 'defects' => 0, 'snapshot_bytes' => 0, 'uploads' => 0, 'access_mappings' => 1], $info, 'P11B: 함께 사라질 접근 매핑 수(kim)');
        $mismatch = $admin->request('DELETE', "/api/v1/projects/{$id}", [], ['confirm_name' => 'WEB']);
        $this->assertSame([400, 'project_name_mismatch'], [$mismatch->status, $mismatch->decoded()['error']['code']]);
        $noCsrf = $admin->request('DELETE', "/api/v1/projects/{$id}", [], ['confirm_name' => 'web'], null, [], false);
        $this->assertSame(403, $noCsrf->status, 'CSRF 필요');
        $del = $admin->request('DELETE', "/api/v1/projects/{$id}", [], ['confirm_name' => 'web']);
        $this->assertSame(200, $del->status, $del->body);
        $this->assertSame(404, $admin->get("/api/v1/projects/{$id}")->status);
    }

    public function testEmptyStateDiffersForAdminAndUser(): void
    {
        $user = $this->as('kim')->get('/projects')->body;
        $this->assertContains('empty-state', $user);
        $this->assertContains('관리자에게 접근 권한을 요청하세요', $user, 'P11B: 볼 수 있는 프로젝트가 없을 때의 문구');
        $this->assertNotContains('href="/projects/new"', $user, '일반 사용자에게 생성 버튼 없음');

        $admin = $this->as('admin')->get('/projects')->body;
        $this->assertContains('empty-state', $admin);
        $this->assertContains('href="/projects/new"', $admin, '관리자에게는 생성 버튼');
    }

    public function testListAndDetailEscapeUserInput(): void
    {
        $admin = $this->as('admin');
        $id = (int) $admin->post('/api/v1/projects', ['name' => '<script>alert(1)</script>', 'language' => 'python', 'description' => '<img src=x onerror=1>'])->decoded()['data']['id'];
        $admin->patch("/api/v1/projects/{$id}", ['exclude_patterns' => ['<b>*.log</b>']]);
        foreach (['/projects', "/projects/{$id}", "/projects/{$id}/edit", "/projects/{$id}/delete"] as $page) {
            $body = $admin->get($page)->body;
            $this->assertNotContains('<script>alert(1)', $body, $page);
            $this->assertNotContains('<img src=x', $body, $page);
            $this->assertNotContains('<b>*.log', $body, $page);
        }
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $admin->get('/projects')->body);
    }

    public function testDetailHidesUnimplementedTabsAndShowsLanguageBadge(): void
    {
        $admin = $this->as('admin');
        $id = (int) $admin->post('/api/v1/projects', ['name' => 'js', 'language' => 'javascript'])->decoded()['data']['id'];
        $this->grant($id, 'kim');
        $body = $this->as('kim')->get("/projects/{$id}")->body;
        $main = preg_match('#<main\b.*?</main>#s', $body, $mm) === 1 ? $mm[0] : '';
        // P17 "결함", P19 "분석" 탭이 구현되었다. 구현된 탭만 나오고 이후 단계의 탭(설정 등)은 아직 없어야 한다
        preg_match_all('#role="tab"[^>]*>([^<]*)</button>#', $main, $tabs);
        $this->assertSame(['결함', '분석'], $tabs[1], '구현된 탭만 보임(미완성 탭 숨김). P19: 분석 탭');
        $this->assertNotContains('준비 중', $main, '본문에 "준비 중" 탭을 두지 않음(상단 메뉴의 비활성 항목은 별개)');
        $this->assertContains('JS/TS', $body);
        $this->assertNotContains("/projects/{$id}/edit", $body, '일반 사용자에게 수정 링크 없음');
        $this->assertContains("/projects/{$id}/edit", $admin->get("/projects/{$id}")->body);
    }

    public function testDeletePageShowsCountsAndRequiresName(): void
    {
        $admin = $this->as('admin');
        $id = (int) $admin->post('/api/v1/projects', ['name' => 'demo', 'language' => 'python'])->decoded()['data']['id'];
        $body = $admin->get("/projects/{$id}/delete")->body;
        $this->assertContains('data-confirm-name="demo"', $body);
        $this->assertSame(1, preg_match('/<button[^>]*id="project-delete-submit"[^>]*disabled/', $body), '처음에는 삭제 버튼 비활성');
        $this->assertContains('data-icon="ui.danger"', $body, '파괴적 동작 전용 아이콘');
        $this->assertContains('분석 0회', $body);
    }

    public function testAdminMenuLinksToProjects(): void
    {
        $body = $this->as('admin')->get('/projects')->body;
        $this->assertSame(1, preg_match('#<a class="nav-item" href="/projects" data-nav="admin\.projects"#', $body), '관리자 메뉴 "프로젝트 관리" 연결');
        $this->assertSame(1, preg_match('#href="/projects" data-nav="nav\.projects"#', $body), '주 메뉴 "프로젝트"');
    }
}
