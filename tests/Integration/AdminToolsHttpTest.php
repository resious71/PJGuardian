<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자 시스템 관리 화면·API(P20A): 권한(admin만, 그룹 관리자·일반 사용자 403, 비로그인 401), CSRF, 감사 로그,
 * 차단·고정·삭제 예정이 P15 레지스트리 선택에 반영됨, 웹 고정은 검증을 실행하지 않음, 재검증 요청 중복 합침,
 * 스테이징(크기 한도, zip만, 순서 있는 청크, 해시 불일치 시 설치 명령 없음, 웹이 bin/에 쓰지 않음, 폐기),
 * 점검 화면의 비밀값·절대 경로 비노출.
 */
final class AdminToolsHttpTest extends TestCase
{
    private App $app;
    private string $root;
    private string $bin;
    /** @var list<string> 스모크가 불린 버전(웹 요청은 0이어야 함) */
    private array $smokeCalls = [];

    public function setUp(): void
    {
        $this->root = $this->tempDir('admintools');
        $this->bin = $this->root . '/bin';
        foreach (['bin', 'work', 'uploads', 'snapshots', 'logs'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->app = TestApp::make($this->root . '/logs', ['paths' => [
            'bin_dir' => $this->bin, 'work_dir' => $this->root . '/work', 'uploads_dir' => $this->root . '/uploads', 'snapshots_dir' => $this->root . '/snapshots',
        ], 'upload' => ['chunk_size' => '256KB'], 'tools' => ['package_max_size' => '2MB']]);
        $this->app->setToolRegistry(new ToolRegistry(
            $this->app->db(), $this->app->clock, $this->app->logger, $this->bin, $this->root . '/work/tools', new NodeRuntime(null, '20.19.0'),
            function (SelectedTool $t): array {
                $this->smokeCalls[] = $t->version;
                return ['ok' => true, 'message' => '시료 3/3 통과'];
            },
        ));
        $this->app->setDiskGuard(new \PJGuardian\Services\Upload\DiskGuard($this->app->db(), $this->app->uploadSettings(), $this->app->logger,
            static fn (): float => 1e12, static fn (): float => 2e12));
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $admin = $this->app->users()->get((int) $db->value("SELECT id FROM users WHERE login_id = 'admin'"));
        $gid = (int) $this->app->groups()->create($admin, null, ['name' => 'team'])['id'];
        $this->app->groups()->addMember($admin, null, $gid, 'mgr');
        $this->app->groups()->setManager($admin, null, $gid, $mgr, true);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function fakeRuff(string $version): void
    {
        $os = ToolRegistry::os();
        $dir = "{$this->bin}/ruff/{$version}";
        mkdir("{$dir}/{$os}", 0700, true);
        $rel = ToolRegistry::executableRel('ruff');
        file_put_contents("{$dir}/{$rel}", 'fake-ruff' . $version);
        file_put_contents("{$dir}/SHA256SUMS", hash_file('sha256', "{$dir}/{$rel}") . " *{$rel}\n");
    }

    /** @return list<array{0:string,1:string}> 관리자 전용 라우트(메서드, 실제 경로) */
    private function adminRoutes(): array
    {
        $out = [];
        foreach ($this->app->router->routeTable() as $r) {
            if (!str_starts_with($r['path'], '/api/v1/admin/') && !in_array($r['path'], ['/admin/tools', '/admin/worker', '/admin/checks', '/admin/runs', '/admin/config-settings', '/admin/backups'], true)) {
                continue;
            }
            // P21B: 프로젝트 단위 관리자 라우트(/api/v1/admin/projects/{id}/…)는 접근 검사가 먼저라(보이지 않으면 404) kim·mgr가 **볼 수 있는**
            // 프로젝트로 채운다 — 그래야 "admin이 아니면 403"을 그대로 검사한다
            $path = (string) preg_replace('#^/api/v1/admin/projects/\{id[^}]*\}#', '/api/v1/admin/projects/' . $this->visibleProject(), $r['path']);
            // P27A: 설정 관리의 {key}는 설정 키 형식(섹션.키)으로 채운다. P27B: {name}은 백업 묶음 이름, {token}은 내려받기 표(48자 16진)
            $path = (string) preg_replace(['/\{tool[^}]*\}/', '/\{version[^}]*\}/', '/\{index[^}]*\}/', '/\{id[^}]*\}/', '/\{key[^}]*\}/', '/\{name[^}]*\}/', '/\{token[^}]*\}/'],
                ['ruff', '1.0.0', '0', str_repeat('0', 32), 'disk.warn_percent', 'pjguardian-20261009-023000-manual.pjgbak', str_repeat('0', 48)], $path);
            $out[] = [$r['method'], $path];
        }
        return $out;
    }

    /** kim(직접 매핑)과 mgr(그룹 매핑)가 볼 수 있는 프로젝트 id */
    private function visibleProject(): int
    {
        static $made = [];
        $key = spl_object_id($this->app);
        if (!isset($made[$key])) {
            $db = $this->app->db();
            $pid = TestDb::project($db, 'admin-routes', 'python');
            $admin = $this->app->users()->get((int) $db->value("SELECT id FROM users WHERE login_id = 'admin'"));
            $actor = \PJGuardian\Services\Actor::fromUser($admin, null);
            $this->app->projectAccess()->add($actor, $pid, ['subject_type' => 'user', 'login_id' => 'kim']);
            $this->app->projectAccess()->add($actor, $pid, ['subject_type' => 'group', 'group_id' => (int) $db->value("SELECT id FROM groups WHERE name = 'team'")]);
            $made[$key] = $pid;
        }
        return $made[$key];
    }

    public function testOnlyAdminCanUseEveryAdminRoute(): void
    {
        $routes = $this->adminRoutes();
        $this->assertTrue(count($routes) >= 25, '관리자 라우트 ' . count($routes) . '개');
        $anon = new TestClient($this->app);
        $anon->fetchCsrf(); // CSRF 검사(전역)를 통과한 뒤 로그인 검사가 401을 내는지 본다
        $kim = $this->as('kim');
        $mgr = $this->as('mgr');
        $admin = $this->as('admin');
        foreach ($routes as [$m, $path]) {
            $api = str_starts_with($path, '/api/');
            $r = $anon->request($m, $path, [], $m === 'GET' ? null : []);
            $this->assertSame($api ? 401 : 302, $r->status, "비로그인 {$m} {$path}");
            foreach (['kim' => $kim, 'mgr' => $mgr] as $who => $c) {
                $res = $c->request($m, $path, [], $m === 'GET' ? null : []);
                $this->assertSame(403, $res->status, "{$who} {$m} {$path}");
            }
            $res = $admin->request($m, $path, [], $m === 'GET' ? null : []);
            // P27B: 비밀번호 재입력이 필요한 동작(키 내보내기 등)은 비밀번호 없이 보내면 403 reauth_failed — 권한 거부가 아니라 본인 확인 실패
            $reauth = $res->status === 403 && (($res->decoded()['error']['code'] ?? null) === 'reauth_failed');
            $this->assertFalse(in_array($res->status, [401, 403], true) && !$reauth, "admin {$m} {$path} → {$res->status}");
        }
        // 메뉴: admin만(그룹 관리자·일반 사용자 HTML에 없음)
        $this->assertContains('data-nav="admin.tools"', $admin->get('/admin/tools')->body);
        $this->assertNotContains('admin.tools', $mgr->get('/projects')->body);
        $this->assertNotContains('admin.tools', $kim->get('/projects')->body);
    }

    public function testCsrfRequiredForChanges(): void
    {
        $this->fakeRuff('1.0.0');
        $admin = $this->as('admin');
        foreach ([['POST', '/api/v1/admin/tools/ruff/versions/1.0.0/block'], ['POST', '/api/v1/admin/worker/stop'], ['POST', '/api/v1/admin/cleanup/run'],
            ['POST', '/api/v1/admin/tool-staging'], ['DELETE', '/api/v1/admin/tools/ruff/versions/1.0.0/removal']] as [$m, $p]) {
            $r = $admin->request($m, $p, [], [], null, [], false);
            $this->assertSame(403, $r->status, "{$m} {$p}");
            $this->assertSame('csrf_invalid', $r->decoded()['error']['code']);
        }
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM tool_versions WHERE status = 'blocked'"));
    }

    public function testBlockPinRemovalDriveRegistrySelectionAndAudit(): void
    {
        $this->fakeRuff('1.0.0');
        $this->fakeRuff('1.1.0');
        $reg = $this->app->toolRegistry();
        $this->assertSame('1.1.0', $reg->select('ruff')?->version, '최신 선택(검증)');
        $this->assertSame(['1.1.0'], $this->smokeCalls);
        $admin = $this->as('admin');
        $over = $admin->get('/api/v1/admin/tools')->decoded()['data'];
        $ruff = $over['tools'][0];
        $this->assertSame(['ruff', 'latest', '1.1.0'], [$ruff['tool'], $ruff['selection']['reason'], $ruff['selection']['version']]);
        $byV = array_column($ruff['versions'], null, 'version');
        $this->assertTrue($byV['1.1.0']['selected']);
        $this->assertSame([false, 'new', true], [$byV['1.0.0']['selected'], $byV['1.0.0']['status'], $byV['1.0.0']['unused']], '구버전: 검증 전·사용되지 않음');
        $this->assertSame('full', $byV['1.1.0']['last_check_method']);

        // 차단 → 선택에서 빠짐(직전 버전 검증 후 선택)
        $r = $admin->post('/api/v1/admin/tools/ruff/versions/1.1.0/block', ['reason' => '회귀 발견']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('1.0.0', $reg->select('ruff')?->version);
        $audit = $this->app->db()->one("SELECT * FROM audit_log WHERE action = 'tool.block'");
        $this->assertSame(['tool', 'ruff@1.1.0'], [$audit['target_type'], $audit['target_id']]);
        $this->assertContains('회귀 발견', (string) $audit['details']);
        $this->assertNotContains($this->root, (string) $audit['details'], '감사에 서버 경로 없음');

        // 고정된 버전은 차단할 수 없고, 웹 고정은 검증을 실행하지 않는다
        $calls = count($this->smokeCalls);
        $this->assertSame(200, $admin->post('/api/v1/admin/tools/ruff/versions/1.1.0/unblock')->status);
        $pin = $admin->post('/api/v1/admin/tools/ruff/versions/1.1.0/pin');
        $this->assertSame([409, 'tool_needs_verification'], [$pin->status, $pin->decoded()['error']['code']], '차단 해제로 기록이 지워져 검증 전 → 고정 거부');
        $this->assertSame($calls, count($this->smokeCalls), '웹 요청은 스모크를 실행하지 않음');
        $this->assertSame(200, $admin->post('/api/v1/admin/tools/ruff/versions/1.0.0/pin')->status, '검증된 버전은 고정');
        $this->assertSame('1.0.0', $reg->select('ruff')?->version, '고정 = 자동 최신 선택 중단');
        $this->assertSame('pinned', $admin->get('/api/v1/admin/tools')->decoded()['data']['tools'][0]['selection']['reason']);
        $blk = $admin->post('/api/v1/admin/tools/ruff/versions/1.0.0/block');
        $this->assertSame([409, 'tool_pinned'], [$blk->status, $blk->decoded()['error']['code']]);
        $this->assertSame(409, $admin->post('/api/v1/admin/tools/ruff/versions/1.0.0/removal')->status, '고정된 버전은 삭제 예정 표시 불가');
        $this->assertSame(200, $admin->post('/api/v1/admin/tools/ruff/unpin')->status);

        // 삭제 예정 → 선택 제외 + 서버 명령 안내, 해제 → 다시 후보
        $reg->select('ruff'); // 1.1.0 다시 검증
        $this->assertSame(200, $admin->post('/api/v1/admin/tools/ruff/versions/1.1.0/removal', ['reason' => '구버전 정리'])->status);
        $this->assertSame('1.0.0', $reg->select('ruff')?->version, '삭제 예정은 선택에서 제외');
        $v = array_column($admin->get('/api/v1/admin/tools')->decoded()['data']['tools'][0]['versions'], null, 'version')['1.1.0'];
        $this->assertSame('php tools/tools_ctl.php remove ruff 1.1.0', $v['remove_command']);
        $this->assertSame(200, $admin->request('DELETE', '/api/v1/admin/tools/ruff/versions/1.1.0/removal', [], [])->status);
        $this->assertSame('1.1.0', $reg->select('ruff')?->version);
        $this->assertSame(404, $admin->post('/api/v1/admin/tools/ruff/versions/9.9.9/block')->status, '없는 버전 404');

        $actions = array_column($this->app->db()->all("SELECT action FROM audit_log WHERE action LIKE 'tool.%' ORDER BY id"), 'action');
        $this->assertSame(['tool.block', 'tool.unblock', 'tool.pin', 'tool.unpin', 'tool.mark_removal', 'tool.unmark_removal'], $actions, '실패한 요청은 감사에 남지 않음');
        $this->assertTrue(is_dir($this->bin . '/ruff/1.1.0') && count(Fs::listDir($this->bin . '/ruff')) === 2, 'bin/은 그대로');
    }

    public function testReverifyRequestIsQueuedAndMerged(): void
    {
        $this->fakeRuff('2.0.0');
        $admin = $this->as('admin');
        $a = $admin->post('/api/v1/admin/tools/ruff/versions/2.0.0/verify');
        $this->assertSame(202, $a->status, $a->body);
        $b = $admin->post('/api/v1/admin/tools/ruff/versions/2.0.0/verify');
        $this->assertSame([false, true], [$a->decoded()['data']['merged'], $b->decoded()['data']['merged']]);
        $this->assertSame($a->decoded()['data']['task']['id'], $b->decoded()['data']['task']['id'], '같은 요청으로 합침');
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM admin_tasks WHERE kind = 'tool_verify' AND target = 'ruff@2.0.0'"));
        $this->assertSame([], $this->smokeCalls, '웹은 검증을 실행하지 않음(워커 자식이 한다)');
        $v = $admin->get('/api/v1/admin/tools')->decoded()['data']['tools'][0]['versions'][0];
        $this->assertSame('queued', $v['verify_task']['status']);
        // 워커가 가져가 실행(여기서는 자식 대신 같은 함수 직접)
        $task = $this->app->adminTasks()->claimNext();
        $r = $this->app->toolRegistry()->reverify('ruff', '2.0.0');
        $this->app->adminTasks()->finish((int) $task['id'], $r['ok'], ['status' => $r['status']]);
        $this->assertSame(['2.0.0'], $this->smokeCalls);
        $c = $admin->post('/api/v1/admin/tools/ruff/versions/2.0.0/verify');
        $this->assertSame(false, $c->decoded()['data']['merged'], '끝난 뒤의 요청은 새 작업');
        $this->assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'tool.reverify_request'") - 1);
    }

    private function upload(TestClient $c, string $data, array $over = []): array
    {
        $init = $c->post('/api/v1/admin/tool-staging', $over + ['tool' => 'ruff', 'file_name' => 'ruff-1.2.0.zip', 'total_size' => strlen($data)]);
        $this->assertSame(201, $init->status, $init->body);
        $s = $init->decoded()['data'];
        foreach (str_split($data, $s['chunk_size']) as $i => $chunk) {
            $r = $c->raw('PUT', "/api/v1/admin/tool-staging/{$s['id']}/chunks/{$i}", $chunk, ['x-chunk-sha256' => hash('sha256', $chunk)]);
            $this->assertSame(200, $r->status, $r->body);
        }
        $done = $c->post("/api/v1/admin/tool-staging/{$s['id']}/complete");
        $this->assertSame(200, $done->status, $done->body);
        return $done->decoded()['data'];
    }

    public function testStagingStoresOnlyAndShowsInstallCommand(): void
    {
        $admin = $this->as('admin');
        $before = Fs::listDir($this->bin);
        $data = random_bytes(600 * 1024); // 청크 3개(256KB)
        $s = $this->upload($admin, $data);
        $this->assertSame(['staged', hash('sha256', $data), null], [$s['status'], $s['sha256'], $s['hash_match']]);
        $this->assertSame('php tools/tools_ctl.php install --staging=' . $s['id'], $s['install_command']);
        $this->assertSame(hash('sha256', $data), hash_file('sha256', $this->root . '/work/tool_staging/' . $s['id'] . '/package.zip'), '작업 폴더의 스테이징에 저장');
        $this->assertSame($before, Fs::listDir($this->bin), '웹은 bin/에 쓰지 않음');
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'tool.package_staged' AND target_id = ?", [$s['id']]));

        // 배포처 해시가 다르면 설치 명령을 보여 주지 않음
        $bad = $admin->patch("/api/v1/admin/tool-staging/{$s['id']}", ['expected_sha256' => str_repeat('a', 64)])->decoded()['data'];
        $this->assertSame([false, null], [$bad['hash_match'], $bad['install_command']]);
        $good = $admin->patch("/api/v1/admin/tool-staging/{$s['id']}", ['expected_sha256' => strtoupper(hash('sha256', $data))])->decoded()['data'];
        $this->assertSame(true, $good['hash_match']);
        $this->assertSame(400, $admin->patch("/api/v1/admin/tool-staging/{$s['id']}", ['expected_sha256' => 'xyz'])->status);

        // 폐기 → 폴더 삭제, 감사
        $this->assertSame('discarded', $admin->request('DELETE', "/api/v1/admin/tool-staging/{$s['id']}")->decoded()['data']['status']);
        $this->assertFalse(is_dir($this->root . '/work/tool_staging/' . $s['id']));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'tool.package_discarded'"));
        $this->assertSame([], $admin->get('/api/v1/admin/tool-staging')->decoded()['data']['items']);
    }

    public function testStagingLimitsAndChunkOrder(): void
    {
        $admin = $this->as('admin');
        $big = $admin->post('/api/v1/admin/tool-staging', ['tool' => 'ruff', 'file_name' => 'x.zip', 'total_size' => 3 * 1024 * 1024]);
        $this->assertSame([413, 'package_too_large'], [$big->status, $big->decoded()['error']['code']], 'tools.package_max_size(시험 2MB)');
        $this->assertSame('package_not_zip', $admin->post('/api/v1/admin/tool-staging', ['tool' => 'ruff', 'file_name' => 'x.tar.gz', 'total_size' => 10])->decoded()['error']['code']);
        $this->assertSame('invalid_tool', $admin->post('/api/v1/admin/tool-staging', ['tool' => 'git', 'file_name' => 'x.zip', 'total_size' => 10])->decoded()['error']['code']);
        $s = $admin->post('/api/v1/admin/tool-staging', ['tool' => 'eslint', 'file_name' => 'e.zip', 'total_size' => 300 * 1024])->decoded()['data'];
        $c0 = random_bytes(256 * 1024);
        $c1 = random_bytes(44 * 1024);
        $skip = $admin->raw('PUT', "/api/v1/admin/tool-staging/{$s['id']}/chunks/1", $c1, ['x-chunk-sha256' => hash('sha256', $c1)]);
        $this->assertSame([409, 'staging_chunk_out_of_order', 0], [$skip->status, $skip->decoded()['error']['code'], $skip->decoded()['error']['details']['next']]);
        $badHash = $admin->raw('PUT', "/api/v1/admin/tool-staging/{$s['id']}/chunks/0", $c0, ['x-chunk-sha256' => str_repeat('0', 64)]);
        $this->assertSame([422, 'chunk_hash_mismatch'], [$badHash->status, $badHash->decoded()['error']['code']]);
        $this->assertSame(200, $admin->raw('PUT', "/api/v1/admin/tool-staging/{$s['id']}/chunks/0", $c0, ['x-chunk-sha256' => hash('sha256', $c0)])->status);
        $again = $admin->raw('PUT', "/api/v1/admin/tool-staging/{$s['id']}/chunks/0", $c0, ['x-chunk-sha256' => hash('sha256', $c0)]);
        $this->assertSame(256 * 1024, $again->decoded()['data']['received_bytes'], '재전송은 이어 붙이지 않음');
        $this->assertSame([409, 'staging_incomplete'], [$admin->post("/api/v1/admin/tool-staging/{$s['id']}/complete")->status,
            $admin->post("/api/v1/admin/tool-staging/{$s['id']}/complete")->decoded()['error']['code']]);
        $this->assertSame(200, $admin->raw('PUT', "/api/v1/admin/tool-staging/{$s['id']}/chunks/1", $c1, ['x-chunk-sha256' => hash('sha256', $c1)])->status);
        $done = $admin->post("/api/v1/admin/tool-staging/{$s['id']}/complete")->decoded()['data'];
        $this->assertSame(hash('sha256', $c0 . $c1), $done['sha256']);
        // 받는 중 동시 2개
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(201, $admin->post('/api/v1/admin/tool-staging', ['tool' => 'ruff', 'file_name' => "p{$i}.zip", 'total_size' => 1000])->status);
        }
        $this->assertSame(429, $admin->post('/api/v1/admin/tool-staging', ['tool' => 'ruff', 'file_name' => 'p3.zip', 'total_size' => 1000])->status);
        $this->assertSame(404, $admin->get('/api/v1/admin/tool-staging/' . str_repeat('f', 32))->status);
    }

    public function testStagingRetentionCleanup(): void
    {
        $admin = $this->as('admin');
        $s = $this->upload($admin, random_bytes(1000));
        $staging = $this->app->toolStaging();
        $this->assertSame(['staging_purged' => 0], $staging->purgeExpired(7 * 86400), '7일 전에는 지우지 않음');
        $this->app->db()->execute('UPDATE tool_staging SET updated_at = ? WHERE id = ?', ['2026-09-20T00:00:00Z', $s['id']]);
        $this->assertSame(['staging_purged' => 1], $staging->purgeExpired(7 * 86400, true), 'dry-run은 세기만');
        $this->assertTrue(is_dir($this->root . '/work/tool_staging/' . $s['id']));
        $this->assertSame(1, $this->app->cleanup()->runAll()['staging_purged'], '정리 작업(tools.staging_retention)');
        $this->assertFalse(is_dir($this->root . '/work/tool_staging/' . $s['id']));
        $this->assertSame('discarded', $this->app->db()->value('SELECT status FROM tool_staging WHERE id = ?', [$s['id']]));
    }

    public function testChecksHideSecretsAndAbsolutePaths(): void
    {
        $secret = 'Sup3rSecretKeyValue-9f8e7d';
        $app = TestApp::make($this->root . '/logs', ['paths' => ['work_dir' => $this->root . '/work', 'bin_dir' => $this->bin]]);
        $cfg = TestApp::rawConfig(['paths' => ['logs_dir' => $this->root . '/logs', 'work_dir' => $this->root . '/work', 'bin_dir' => $this->bin],
            'auth' => ['bcrypt_cost' => '4'], 'worker' => ['start_command' => 'C:\\secret-tools\\start.exe --token=abc']]);
        $config = \PJGuardian\Support\Config::fromArray($cfg, ['encryption_key' => $secret]);
        $app = new App(PJG_ROOT, $config, $app->clock, $app->logger, new \PJGuardian\Support\Paths(PJG_ROOT, $config), TestDb::migrated());
        TestDb::user($app->db(), 'admin', 'admin');
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $res = $c->get('/api/v1/admin/checks');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertNotContains($secret, $res->body, '시크릿 값 없음');
        $this->assertNotContains('secret-tools', $res->body, '시작 명령 값 없음');
        $this->assertNotContains(str_replace('/', '\\/', $this->root), $res->body, '서버 경로 없음(JSON)');
        $this->assertNotContains($this->root, $res->body);
        $rows = array_column($res->decoded()['data']['config'], null, 'key');
        $this->assertSame(['secret', 'set'], [$rows['secret.encryption_key']['kind'], $rows['secret.encryption_key']['value']]);
        $this->assertSame('unset', $rows['secret.smtp_password']['value']);
        $this->assertSame(['presence', 'set', true], [$rows['worker.start_command']['kind'], $rows['worker.start_command']['value'], $rows['worker.start_command']['changed']]);
        $this->assertSame(['absolute_path', null], [$rows['paths.work_dir']['kind'], $rows['paths.work_dir']['value']]);
        $this->assertSame('data/db/pjguardian.sqlite', $rows['paths.db_file']['value'], '상대 경로 설정은 그대로');
        $this->assertSame(['false', false], [$rows['worker.supervised']['value'], $rows['worker.supervised']['changed']]);
        $env = $res->decoded()['data']['environment'];
        $ids = array_column($env['items'], 'id');
        foreach (['os', 'php_version', 'ext', 'sqlite_version', 'proc_open', 'memory_limit', 'dir'] as $id) {
            $this->assertTrue(in_array($id, $ids, true), "환경 항목 {$id}");
        }
        $binItem = array_values(array_filter($env['items'], static fn (array $i): bool => $i['id'] === 'dir' && $i['params']['name'] === 'bin'))[0];
        $this->assertTrue(in_array($binItem['note'], ['bin_writable_web', 'bin_readonly_web'], true), '웹 계정의 bin/ 쓰기 여부 표시');
        $this->assertSame('<bin>', $binItem['value'], '경로는 이름표로');
    }

    public function testFailuresSummaryAndCleanupRequestDedup(): void
    {
        $db = $this->app->db();
        $pid = TestDb::project($db, 'p1', 'python');
        $uid = (int) $db->value("SELECT id FROM users WHERE login_id = 'admin'");
        foreach (['tool_unavailable', 'tool_unavailable', 'timeout'] as $code) {
            TestDb::analysis($db, $pid, $uid, ['status' => 'failed', 'failure_code' => $code, 'finished_at' => '2026-10-01T00:00:00Z']);
        }
        TestDb::analysis($db, $pid, $uid, ['status' => 'failed', 'failure_code' => 'timeout', 'finished_at' => '2026-09-01T00:00:00Z']);
        $admin = $this->as('admin');
        $f = $admin->get('/api/v1/admin/checks')->decoded()['data']['failures'];
        $by = array_column($f['by_code'], null, 'code');
        $this->assertSame([2, 'admin/tools', 1, null], [$by['tool_unavailable']['count'], $by['tool_unavailable']['admin_href'], $by['timeout']['count'], $by['timeout']['admin_href']], '최근 7일만, 관리자 조치 사유는 링크');
        $this->assertSame(3, count($f['recent']));
        $a = $admin->post('/api/v1/admin/cleanup/run')->decoded()['data'];
        $b = $admin->post('/api/v1/admin/cleanup/run')->decoded()['data'];
        $this->assertSame([false, true, $a['task']['id']], [$a['merged'], $b['merged'], $b['task']['id']], '중복 실행 방지');
        $this->assertSame(202, $admin->post('/api/v1/admin/checks/disk-scan')->status);
        $this->assertSame(2, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'cleanup.run_request'"));
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'disk.scan_request'"));
        $this->assertSame('queued', $admin->get('/api/v1/admin/checks')->decoded()['data']['cleanup']['task']['status']);
    }

    public function testPagesRenderShellsWithoutServerPaths(): void
    {
        $admin = $this->as('admin');
        foreach (['/admin/tools' => 'data-admin-tools', '/admin/worker' => 'data-admin-worker', '/admin/checks' => 'data-admin-checks'] as $page => $marker) {
            $body = $admin->get($page)->body;
            $this->assertContains($marker, $body, $page);
            $this->assertContains('aria-current="page"', $body);
            $this->assertNotContains($this->root, $body, $page);
        }
        $this->assertNotContains('data-cancel', $admin->get('/admin/worker')->body, '관리자 분석 취소 버튼 없음');
    }
}
