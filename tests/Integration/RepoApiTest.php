<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 저장소 연동 API(P25): 접근 통제(비로그인 401, 그룹 관리자·일반 403, 보이지 않는 프로젝트 404 먼저, CSRF), 저장·교체·삭제·유지(비워 저장),
 * 동시 수정 409, 허용 목록 밖 400, 키 없음 503, 일반 사용자 화면에 주소·사용자명·비밀 없음, 응답·로그·감사·DB 어디에도 평문 비밀 없음,
 * 연결 해제·프로젝트 삭제 정리, 지금 가져와 분석(분석 행·출처·요청자, 진행 중 409, 디스크 507), 연결 테스트 분당 제한 429, 허용 호스트 CRUD.
 */
final class RepoApiTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
    private const TOKEN = 'ghp_TOKEN-VALUE-0123456789';

    private App $app;
    private string $root;
    private int $p;
    private int $hidden;
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $this->root = $this->tempDir('repoapi');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'repository' => ['test_per_minute' => '2']], ['encryption_key' => self::KEY]);
        mkdir($this->root . '/work');
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, 'repo', 'python');
        $this->hidden = TestDb::project($db, 'hidden', 'python');
        $now = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, ?)", [$this->p, $this->u['kim'], $now]);
        // mgr = 그룹 관리자(이 프로젝트를 그룹으로 봄)
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g', ?, ?)", [$now, $now]);
        $g = $db->lastInsertId();
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?)', [$g, $this->u['mgr'], $now]);
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', ?, ?)", [$this->p, $g, $now]);
        $db->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, 'GHE', ?, ?)", [$now, $now]);
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function body(): array
    {
        return ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'branch' => 'main', 'secret' => self::TOKEN];
    }

    public function testAccessControl(): void
    {
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get("/api/v1/projects/{$this->p}/repo")->status);
        $admin = $this->as('admin');
        $this->assertSame(200, $admin->put("/api/v1/projects/{$this->p}/repo", $this->body())->status);
        foreach (['kim', 'mgr'] as $who) {
            $c = $this->as($who);
            $this->assertSame(200, $c->get("/api/v1/projects/{$this->p}/repo")->status, "{$who} 상태 조회는 가능");
            $this->assertSame(403, $c->put("/api/v1/projects/{$this->p}/repo", $this->body())->status, "{$who} 저장 403");
            $this->assertSame(403, $c->request('DELETE', "/api/v1/projects/{$this->p}/repo")->status);
            $this->assertSame(403, $c->post("/api/v1/projects/{$this->p}/repo/test")->status);
            $this->assertSame(403, $c->post("/api/v1/projects/{$this->p}/repo/fetch")->status);
            $this->assertSame(404, $c->get("/api/v1/projects/{$this->hidden}/repo")->status, '보이지 않는 프로젝트 404(403보다 먼저)');
            $this->assertSame(404, $c->put("/api/v1/projects/{$this->hidden}/repo", $this->body())->status);
            $this->assertSame(403, $c->get('/api/v1/admin/repo-hosts')->status);
            $this->assertSame(403, $c->get('/admin/repo-hosts')->status);
        }
        $this->assertSame(403, $admin->request('PUT', "/api/v1/projects/{$this->p}/repo", [], $this->body(), null, [], false)->status, 'CSRF');
        $this->assertSame(403, $admin->post("/api/v1/projects/{$this->p}/repo/fetch", [], false)->status, 'CSRF');
    }

    public function testSecretsNeverLeaveTheServer(): void
    {
        $admin = $this->as('admin');
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", $this->body() + ['username' => 'bot']);
        $this->assertSame(200, $r->status, $r->body);
        $v = $r->decoded()['data'];
        $this->assertSame([true, 'token', 'git', 1, false], [$v['secret_set'], $v['secret_kind'], $v['kind'], $v['revision'], $v['plaintext_warning']]);
        $kim = $this->as('kim')->get("/api/v1/projects/{$this->p}/repo");
        $kv = $kim->decoded()['data'];
        $this->assertSame([true, 'git', 'unknown', 'ghe.corp/team/app.git @main'], [$kv['configured'], $kv['kind'], $kv['status'], $kv['label']]);
        foreach (['location', 'username', 'secret_set', 'secret_kind', 'fingerprint', 'host_allowed', 'revision', 'test'] as $k) {
            $this->assertFalse(array_key_exists($k, $kv), "일반 사용자에게 {$k} 없음");
        }
        // 비워 저장 → 유지, 교체, 지움
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'branch' => 'dev', 'secret' => '', 'revision' => 1]);
        $this->assertSame([200, true, 2], [$r->status, $r->decoded()['data']['secret_set'], $r->decoded()['data']['revision']]);
        $this->assertSame(self::TOKEN, $this->app->repoConnections()->target($this->p)->token, '비워 저장하면 유지');
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'secret' => 'new-TOKEN-xyz', 'revision' => 2]);
        $this->assertSame('new-TOKEN-xyz', $this->app->repoConnections()->target($this->p)->token);
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'clear_secret' => true, 'revision' => 3]);
        $this->assertSame(false, $r->decoded()['data']['secret_set']);
        // 동시 수정 409
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'revision' => 1]);
        $this->assertSame([409, 'repo_conflict', 4], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['revision'] ?? null]);
        $admin->put("/api/v1/projects/{$this->p}/repo", $this->body() + ['revision' => 4]);
        // 평문 비밀은 어디에도 없음: 응답, 로그, 감사, DB 원본
        $everything = $admin->get("/api/v1/projects/{$this->p}/repo")->body . $kim->body
            . (string) file_get_contents($this->app->logger->fileFor(new \DateTimeImmutable('2026-10-02T12:00:00Z')))
            . json_encode($this->app->db()->all('SELECT * FROM audit_log')) . json_encode($this->app->db()->all('SELECT * FROM project_repo_connections'));
        foreach ([self::TOKEN, 'new-TOKEN-xyz', base64_encode(self::TOKEN)] as $s) {
            $this->assertNotContains($s, $everything, '평문·base64 비밀 없음');
        }
        $audit = $this->app->db()->all("SELECT action, details FROM audit_log WHERE action LIKE 'repo.%' ORDER BY id");
        $this->assertSame('repo.save', $audit[0]['action']);
        $this->assertSame(['set', 'unchanged', 'replaced', 'cleared'], array_slice(array_map(static fn (array $a): string => json_decode((string) $a['details'], true)['auth_change'], $audit), 0, 4));
    }

    public function testValidationHostListKeyAndDelete(): void
    {
        $admin = $this->as('admin');
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://other.corp/a.git']);
        $this->assertSame([400, 'repo_host_not_listed', 'other.corp'], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['host']]);
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'http://ghe.corp/a.git']);
        $this->assertSame([400, 'repo_host_not_listed'], [$r->status, $r->decoded()['error']['code']], 'HTTP는 allow_http 등록 필요');
        foreach ([['url' => 'ssh://ghe.corp/a.git'], ['url' => 'file:///x'], ['url' => 'https://ghe.corp/a.git', 'branch' => '--upload-pack=x'], ['url' => 'https://u:p@ghe.corp/a.git'],
            ['url' => 'https://ghe.corp/a.git', 'secret' => "a\nb"], ['url' => 'https://ghe.corp/a.git', 'path' => '../x']] as $bad) {
            $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git'] + $bad);
            $this->assertSame(400, $r->status, json_encode($bad));
        }
        $r = $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'perforce', 'port' => 'ghe.corp:1666', 'path' => '//depot/x/...', 'username' => 'r', 'secret' => 'pw', 'secret_kind' => 'password']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame([true, 'password'], [$r->decoded()['data']['plaintext_warning'], $r->decoded()['data']['secret_kind']], '비SSL Perforce는 평문 경고');
        // 키 없는 앱: 자격 증명 저장 거부(키를 만들지 않는다), 비밀 없는 연결은 저장 가능
        $nokey = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']]);
        (new \ReflectionProperty(App::class, 'db'))->setValue($nokey, $this->app->db());
        $c = new TestClient($nokey);
        $c->login('admin', 'Passw0rd!x');
        $r = $c->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/a.git', 'secret' => 'x', 'revision' => 1]);
        $this->assertSame([503, 'repo_secret_key_missing'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertSame(false, $c->get("/api/v1/projects/{$this->p}/repo")->decoded()['data']['key_available']);
        // 해제 → 업로드 전용, 프로젝트 삭제 → 연결 행도 삭제(CASCADE)
        $this->assertSame(200, $admin->request('DELETE', "/api/v1/projects/{$this->p}/repo")->status);
        $this->assertSame(['configured' => false, 'kind' => 'none'], array_intersect_key($admin->get("/api/v1/projects/{$this->p}/repo")->decoded()['data'], ['configured' => 1, 'kind' => 1]));
        $admin->put("/api/v1/projects/{$this->p}/repo", $this->body());
        $this->app->db()->execute('DELETE FROM project_access WHERE project_id = ?', [$this->p]);
        $this->app->db()->execute('DELETE FROM projects WHERE id = ?', [$this->p]);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM project_repo_connections'));
    }

    public function testFetchNowQueuesAndGuards(): void
    {
        $admin = $this->as('admin');
        $this->assertSame([409, 'repo_not_configured'], [($r = $admin->post("/api/v1/projects/{$this->p}/repo/fetch"))->status, $r->decoded()['error']['code']]);
        $admin->put("/api/v1/projects/{$this->p}/repo", $this->body());
        $r = $admin->post("/api/v1/projects/{$this->p}/repo/fetch");
        $this->assertSame(202, $r->status, $r->body);
        $id = $r->decoded()['data']['analysis_id'];
        $a = $this->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]);
        $this->assertSame(['queued', 'git', 'main', 'https://ghe.corp/team/app.git', 'user', $this->u['admin'], null, null],
            [$a['status'], $a['source_kind'], $a['source_ref'], $a['source_url'], $a['trigger_kind'], (int) $a['requested_by'], $a['work_dir'], $a['upload_id']]);
        $r = $admin->post("/api/v1/projects/{$this->p}/repo/fetch");
        $this->assertSame([409, 'analysis_in_progress'], [$r->status, $r->decoded()['error']['code']]);
        $this->app->db()->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-02T12:00:00Z' WHERE id = ?", [$id]);
        $this->app->setDiskGuard(new DiskGuard($this->app->db(), $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e9, static fn (): float => 1.1e9));
        $r = $admin->post("/api/v1/projects/{$this->p}/repo/fetch");
        $this->assertSame([507, 'insufficient_storage'], [$r->status, $r->decoded()['error']['code']], '디스크 사전 검사(해제 상한 × 2)');
        $audit = $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'repo.fetch_now'");
        $this->assertSame(1, (int) $audit);
        // 분석 API·화면에 출처가 보인다(일반 사용자도) — 링크용 주소에는 사용자 정보가 없다
        $kim = $this->as('kim');
        $item = $kim->get("/api/v1/analyses/{$id}")->decoded()['data'];
        $this->assertSame(['git', 'main'], [$item['source_kind'], $item['source_ref']]);
    }

    public function testKeyRotationReencryptsOnSave(): void
    {
        $admin = $this->as('admin');
        $admin->put("/api/v1/projects/{$this->p}/repo", $this->body());
        $oldId = $this->app->db()->value('SELECT secret_key_id FROM project_repo_connections');
        $newKey = base64_encode(str_repeat('n', 32));
        $rot = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']], ['encryption_key' => $newKey, 'encryption_key_previous' => self::KEY]);
        (new \ReflectionProperty(App::class, 'db'))->setValue($rot, $this->app->db());
        $this->assertSame(self::TOKEN, $rot->repoConnections()->target($this->p)->token, '이전 키로 풀림');
        $c = new TestClient($rot);
        $c->login('admin', 'Passw0rd!x');
        $r = $c->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'branch' => 'main', 'revision' => 1]);
        $this->assertSame(200, $r->status, $r->body);
        $newId = $this->app->db()->value('SELECT secret_key_id FROM project_repo_connections');
        $this->assertTrue($newId !== $oldId && $newId === \PJGuardian\Services\Repo\SecretBox::keyId(str_repeat('n', 32)), '저장하면 새 키로 다시 암호화');
        $only = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']], ['encryption_key' => $newKey]);
        (new \ReflectionProperty(App::class, 'db'))->setValue($only, $this->app->db());
        $this->assertSame(self::TOKEN, $only->repoConnections()->target($this->p)->token, '이전 키를 치워도 풀림');
        $audit = $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'repo.save' ORDER BY id DESC LIMIT 1");
        $this->assertSame('reencrypted', json_decode((string) $audit, true)['auth_change']);
        // 키를 잃으면: 읽을 수 없음 표시 + 가져오기 거부
        $lost = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']], ['encryption_key' => base64_encode(str_repeat('z', 32))]);
        (new \ReflectionProperty(App::class, 'db'))->setValue($lost, $this->app->db());
        $this->assertSame(false, $lost->repoConnections()->view($this->p, true)['secret_readable']);
        $l = new TestClient($lost);
        $l->login('admin', 'Passw0rd!x');
        $r = $l->post("/api/v1/projects/{$this->p}/repo/fetch");
        $this->assertSame([409, 'repo_secret_unreadable'], [$r->status, $r->decoded()['error']['code']]);
    }

    public function testRateLimitAndHostAdmin(): void
    {
        $admin = $this->as('admin');
        $admin->put("/api/v1/projects/{$this->p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git']);
        $this->app->setRepoTesting([], static fn (): array => ['169.254.169.254']); // 접속하지 않고 거부되는 경로
        for ($i = 0; $i < 2; $i++) {
            $r = $admin->post("/api/v1/projects/{$this->p}/repo/test");
            $this->assertSame([200, false, 'repo_host_not_allowed'], [$r->status, $r->decoded()['data']['ok'], $r->decoded()['data']['code']]);
        }
        $r = $admin->post("/api/v1/projects/{$this->p}/repo/test");
        $this->assertSame([429, 'repo_test_rate_limited'], [$r->status, $r->decoded()['error']['code']], '분당 2회(설정)');
        $this->assertSame('error', $this->app->repoConnections()->row($this->p)['last_test_status']);
        // 허용 호스트 CRUD(정규화·중복·감사)
        $r = $admin->post('/api/v1/admin/repo-hosts', ['host' => 'Git.LAN.', 'port' => 8080, 'allow_http' => true, 'note' => '사내']);
        $this->assertSame([201, 'git.lan', 8080, true, false], [$r->status, $r->decoded()['data']['host'], $r->decoded()['data']['port'], $r->decoded()['data']['allow_http'], $r->decoded()['data']['github_links']]);
        $hid = $r->decoded()['data']['id'];
        $this->assertSame(409, $admin->post('/api/v1/admin/repo-hosts', ['host' => 'git.lan', 'port' => 8080])->status);
        foreach ([['host' => '0x7f.1'], ['host' => 'a b'], ['host' => 'x.corp', 'port' => 70000], ['host' => 'x.corp', 'allow_http' => 'yes'], ['host' => 'x', 'note' => "a\nb"]] as $bad) {
            $this->assertSame(400, $admin->post('/api/v1/admin/repo-hosts', $bad)->status, json_encode($bad));
        }
        $r = $admin->patch("/api/v1/admin/repo-hosts/{$hid}", ['github_links' => true, 'allow_http' => false]);
        $this->assertSame([true, false], [$r->decoded()['data']['github_links'], $r->decoded()['data']['allow_http']]);
        $this->assertSame(200, $admin->request('DELETE', "/api/v1/admin/repo-hosts/{$hid}")->status);
        $this->assertSame(404, $admin->request('DELETE', "/api/v1/admin/repo-hosts/{$hid}")->status);
        $actions = array_column($this->app->db()->all("SELECT action FROM audit_log WHERE action LIKE 'repo_host.%' ORDER BY id"), 'action');
        $this->assertSame(['repo_host.add', 'repo_host.update', 'repo_host.delete'], $actions);
        // X12(의도된 단언 변경): 옛 화면 주소는 설정 관리 "저장소 연결" 탭으로 이동, 목록은 같은 API
        $this->assertSame('/admin/config-settings#repository', $admin->get('/admin/repo-hosts')->header('Location'));
        $this->assertContains('ghe.corp', (string) json_encode($admin->get('/api/v1/admin/repo-hosts')->decoded()['data']));
        $this->assertContains('data-repo-hosts', $admin->get('/admin/config-settings')->body);
    }
}
