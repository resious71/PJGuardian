<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Queue\Worker;
use PJGuardian\Support\SystemClock;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자 화면 Node.js "상태 갱신"(P21C 1절): 웹은 요청 파일(node_check.request)만 쓰고, 워커가 설정을 다시 읽어 자기 실행 환경에서 확인한다.
 */
final class NodeRefreshTest extends TestCase
{
    private App $app;
    private string $root;

    public function setUp(): void
    {
        $this->root = $this->tempDir('noderef');
        mkdir($this->root . '/work');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'worker' => ['heartbeat_stale' => '30s', 'heartbeat_interval' => '1s']]);
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $admin = $this->app->users()->get((int) $db->value("SELECT id FROM users WHERE login_id = 'admin'"));
        $g = (int) $this->app->groups()->create($admin, null, ['name' => 'G'])['id'];
        $this->app->groups()->addMember($admin, null, $g, 'mgr');
        $this->app->groups()->setManager($admin, null, $g, $mgr, true);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function beat(string $state = 'running'): void
    {
        $now = SystemClock::iso($this->app->clock->now());
        $this->app->workerState()->beat(4242, $now, $state, [], [], App::VERSION,
            ['node' => ['ok' => false, 'version' => null, 'reason' => 'node_not_found', 'min' => '20.19.0', 'source' => 'config', 'checked_at' => '2026-10-01T00:00:00Z']]);
    }

    /** 워커의 설정 파일(node_path만 바꿔 가며 다시 읽게) */
    private function ini(?string $nodePath): string
    {
        $file = $this->root . '/worker.ini';
        TestApp::writeIni($file, ['paths' => ['work_dir' => $this->root . '/work', 'db_file' => $this->app->db()->file()]] + ($nodePath === null ? [] : ['tools' => ['node_path' => $nodePath]]));
        return $file;
    }

    public function testEndpointPermissionsAndWorkerStopped(): void
    {
        $url = '/api/v1/admin/tools/node/refresh';
        $this->assertSame(403, $this->as('kim')->post($url)->status);
        $this->assertSame(403, $this->as('mgr')->post($url)->status);
        $anon = new TestClient($this->app);
        $anon->fetchCsrf();
        $this->assertSame(401, $anon->post($url)->status);
        $admin = $this->as('admin');
        $this->assertSame(403, $admin->request('POST', $url, [], [], null, [], false)->status, 'CSRF');
        // 워커 없음 → 409 + 마지막 확인 결과(없으면 null)
        $r = $admin->post($url);
        $this->assertSame([409, 'worker_not_running'], [$r->status, $r->decoded()['error']['code']]);
        $this->beat('stopped');
        $r = $admin->post($url);
        $this->assertSame(409, $r->status);
        $this->assertSame('2026-10-01T00:00:00Z', $r->decoded()['error']['details']['node']['checked_at']);
        $this->assertFalse(is_file($this->app->workerState()->nodeCheckFile()), '멈춘 워커에는 요청을 남기지 않음');
    }

    public function testRequestIsSingleAndCarriesNoUserInput(): void
    {
        $this->beat();
        $admin = $this->as('admin');
        $r1 = $admin->request('POST', '/api/v1/admin/tools/node/refresh', [], ['path' => 'C:/evil.exe', 'args' => ['-e', 'x']]);
        $this->assertSame(202, $r1->status, $r1->body);
        $d1 = $r1->decoded()['data'];
        $this->assertSame([false, 10], [$d1['merged'], $d1['wait_seconds']]);
        $r2 = $admin->post('/api/v1/admin/tools/node/refresh');
        $this->assertSame([true, $d1['requested_at']], [$r2->decoded()['data']['merged'], $r2->decoded()['data']['requested_at']], '연타 → 같은 요청');
        $req = json_decode((string) file_get_contents($this->app->workerState()->nodeCheckFile()), true);
        $this->assertSame(['requested_at', 'by'], array_keys($req), '요청 파일에는 시각과 요청자만(경로·인자 없음)');
    }

    public function testWorkerRechecksWithReloadedConfigWithoutRestart(): void
    {
        $state = $this->app->workerState();
        $worker = new Worker($this->app);
        $handle = new \ReflectionMethod($worker, 'handleNodeCheck');
        $extra = new \ReflectionProperty($worker, 'extra');
        $this->assertFalse($handle->invoke($worker, $state), '요청 없으면 아무것도 안 함');

        // 경로 설정이 없는 파일 → 찾을 수 없음
        $worker->reloadConfigFrom($this->ini($this->root . '/no-such-node.exe'));
        $at = $state->requestNodeCheck(1);
        $this->assertTrue($handle->invoke($worker, $state));
        $node = $extra->getValue($worker)['node'];
        $this->assertSame([false, 'node_not_found', 'config', $at], [$node['ok'], $node['reason'], $node['source'], $node['request_at']]);
        $this->assertFalse(is_file($state->nodeCheckFile()), '처리한 요청은 지움');

        // 설정을 바꿔(실행은 되지만 node가 아닌 파일 = PHP) 다시 → 실행할 수 없음(버전 형식 아님). 워커는 다시 시작하지 않았다
        $worker->reloadConfigFrom($this->ini(PHP_BINARY));
        $state->requestNodeCheck(1);
        $handle->invoke($worker, $state);
        $this->assertSame('node_not_runnable', $extra->getValue($worker)['node']['reason']);

        // 실제 Node가 있으면 사용 가능으로 바뀐다(이 PC: C:/tools/nodejs/node.exe)
        $real = getenv('PJG_NODE') ?: 'C:/tools/nodejs/node.exe';
        if (is_file($real)) {
            $worker->reloadConfigFrom($this->ini($real));
            $state->requestNodeCheck(1);
            $handle->invoke($worker, $state);
            $node = $extra->getValue($worker)['node'];
            $this->assertSame([true, null], [$node['ok'], $node['reason']]);
            $this->assertTrue(preg_match('/^\d+\.\d+\.\d+$/', (string) $node['version']) === 1);
        }

        // 설정 파일이 틀리면 시작 때 설정으로 확인하고 그 사실을 표시
        file_put_contents($this->root . '/worker.ini', "[nope]\nx = 1\n");
        $worker->reloadConfigFrom($this->root . '/worker.ini');
        $state->requestNodeCheck(1);
        $handle->invoke($worker, $state);
        $this->assertSame(true, $extra->getValue($worker)['node']['config_error'] ?? null);
    }
}
