<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\Config;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 업그레이드 유지보수 모드(P32B, 명세 19.4 — [maintenance] enabled·message·notice_at):
 * - 켜기 = 설정 관리 저장. 직전 DB 백업이 성공해야 저장(실패하면 409 maintenance_backup_failed, 파일·감사 그대로). 켜기·끄기는 maintenance.on/off 감사.
 * - 켜져 있으면 admin이 아닌 사용자의 쓰기(업로드 포함)는 503 maintenance, 읽기·로그인은 그대로, admin은 모두 됨.
 * - 배너: 켜짐(모든 사용자, admin은 끄는 곳 안내), 사전 공지(예정 시각 전까지만). /health에 maintenance. 워커는 새 분석을 시작하지 않음.
 */
final class UpgradeMaintenanceTest extends TestCase
{
    private string $dir;
    private string $ini;
    private \PJGuardian\Support\Db $db;
    private ?\Closure $backup = null;
    private int $project;

    public function setUp(): void
    {
        $this->dir = $this->tempDir('maint');
        $this->ini = TestApp::writeIni($this->dir . '/config.ini', [
            'paths' => ['work_dir' => $this->dir . '/work', 'logs_dir' => $this->dir . '/logs', 'uploads_dir' => $this->dir . '/uploads',
                'snapshots_dir' => $this->dir . '/snap', 'backups_dir' => $this->dir . '/backups'],
            'auth' => ['bcrypt_cost' => '4'],
            'health' => ['allowed_ips' => '127.0.0.1'],
        ]);
        $this->db = TestDb::migrated();
        $admin = TestDb::user($this->db, 'admin', 'admin');
        $kim = TestDb::user($this->db, 'kim', 'user');
        $this->project = TestDb::project($this->db, 'py', 'python');
        $this->db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-09T00:00:00Z')", [$this->project, $kim]);
        unset($admin);
    }

    /** 요청마다 새 App(웹처럼 설정 파일을 다시 읽는다) */
    private function app(): App
    {
        $config = Config::load($this->ini, $this->dir . '/secrets.ini');
        $clock = new FixedClock('2026-10-09T01:00:00Z'); // KST 10:00
        $paths = new Paths(PJG_ROOT, $config);
        $app = new App(PJG_ROOT, $config, $clock, new Logger($paths->logs(), $clock, 'INFO'), $paths, $this->db);
        if ($this->backup !== null) {
            $app->useMaintenanceBackup($this->backup);
        }
        return $app;
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app());
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function put(array $changes): \PJGuardian\Http\Response
    {
        $admin = $this->as('admin');
        $v = $admin->get('/api/v1/admin/config-settings')->decoded()['data']['version'];
        return $admin->put('/api/v1/admin/config-settings', ['changes' => $changes, 'version' => $v]);
    }

    private function count(string $action): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    public function testTurnOnNeedsBackupThenBlocksWritesForNonAdmins(): void
    {
        // 직전 백업 실패(이 시험엔 워커가 없음 = worker_not_running) → 켜지 않음
        $r = $this->put(['maintenance.enabled' => 'true', 'maintenance.message' => '22시까지']);
        $this->assertSame([409, 'maintenance_backup_failed'], [$r->status, $r->decoded()['error']['code']], $r->body);
        $this->assertSame('worker_not_running', $r->decoded()['error']['details']['code'] ?? null);
        $this->assertTrue(!is_file($this->dir . '/settings.local.ini'), '파일 그대로');
        $this->assertSame([0, 0], [$this->count('maintenance.on'), $this->count('config.update')]);
        // 백업 성공 → 켜짐 + 감사(직전 백업 묶음 이름)
        $calls = 0;
        $this->backup = function () use (&$calls): array {
            $calls++;
            return ['ok' => true, 'code' => null, 'name' => 'pjguardian-20261009-100000-pre_upgrade.pjgbak'];
        };
        $r = $this->put(['maintenance.enabled' => 'true', 'maintenance.message' => '22시까지']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(1, $calls);
        $this->assertSame('{"bundle":"pjguardian-20261009-100000-pre_upgrade.pjgbak"}', (string) $this->db->value("SELECT details FROM audit_log WHERE action = 'maintenance.on'"));
        $this->assertSame(2, $this->count('config.update'), '설정 변경 감사도 그대로(키마다)');
        // 이미 켜진 상태에서 문구만 바꾸면 백업하지 않음
        $this->assertSame(200, $this->put(['maintenance.message' => '23시까지'])->status);
        $this->assertSame(1, $calls);

        // 일반 사용자: 읽기 200, 쓰기·업로드 503(안내 문구), 로그인·로그아웃 됨
        $kim = $this->as('kim');
        $this->assertSame(200, $kim->get("/api/v1/projects/{$this->project}/defects")->status);
        $w = $kim->put('/api/v1/users/me/preferences', ['language' => 'en']);
        $this->assertSame([503, 'maintenance'], [$w->status, $w->decoded()['error']['code']]);
        $this->assertContains('23시까지', $w->decoded()['error']['message']);
        $up = $kim->post("/api/v1/projects/{$this->project}/uploads", ['file_name' => 'a.zip', 'total_size' => 10]);
        $this->assertSame(503, $up->status, '업로드 시작도 막힘');
        $page = $kim->get('/dashboard')->body;
        $this->assertContains('data-maintenance-banner="on"', $page);
        $this->assertContains('23시까지', $page);
        // admin: 쓰기 가능, 배너는 끄는 곳 안내
        $admin = $this->as('admin');
        $this->assertSame(201, $admin->post('/api/v1/projects', ['name' => 'during', 'language' => 'python'])->status);
        $this->assertContains('유지보수에서 끄세요', $admin->get('/dashboard')->body);
        // /health에 표시(판정 상태와는 별개)
        $h = (new TestClient($this->app()))->get('/health')->decoded();
        $this->assertSame(true, $h['maintenance']);
        // 끄기 → 감사 maintenance.off, 다시 쓰기 됨
        $this->assertSame(200, $this->put(['maintenance.enabled' => 'false'])->status);
        $this->assertSame(1, $this->count('maintenance.off'));
        $this->assertSame(200, $this->as('kim')->put('/api/v1/users/me/preferences', ['language' => 'en'])->status);
        @unlink($this->dir . '/work/monitoring/health_body.json'); // /health 응답 캐시(health.cache, 기본 5초 — 시험 시계는 고정)
        $this->assertSame(false, (new TestClient($this->app()))->get('/health')->decoded()['maintenance']);
    }

    public function testAdvanceNoticeBannerAndValidation(): void
    {
        $this->assertSame(422, $this->put(['maintenance.notice_at' => '2026-02-30 10:00'])->status, '없는 날짜');
        $this->assertSame(422, $this->put(['maintenance.notice_at' => '2026-10-10 9:00'])->status, '형식');
        $this->assertSame(200, $this->put(['maintenance.notice_at' => '2026-10-10 22:00', 'maintenance.message' => '30분 예정'])->status);
        $page = $this->as('kim')->get('/dashboard')->body;
        $this->assertContains('data-maintenance-banner="notice"', $page);
        $this->assertContains('2026-10-10 22:00', $page);
        $this->assertContains('30분 예정', $page);
        // 예정 시각이 지난 공지는 보이지 않음(지금 = KST 2026-10-09 10:00)
        $this->assertSame(200, $this->put(['maintenance.notice_at' => '2026-10-09 09:59'])->status);
        $this->assertNotContains('data-maintenance-banner', $this->as('kim')->get('/dashboard')->body);
    }

    public function testWorkerDoesNotStartNewAnalysesDuringMaintenance(): void
    {
        $user = (int) $this->db->value("SELECT id FROM users WHERE login_id = 'kim'");
        $id = TestDb::analysis($this->db, $this->project, $user, ['status' => 'queued', 'started_at' => null, 'finished_at' => null]);
        file_put_contents($this->dir . '/settings.local.ini', "[maintenance]\nenabled = \"true\"\n");
        $worker = new \PJGuardian\Services\Queue\Worker($this->app(), $this->ini);
        $claim = new \ReflectionMethod($worker, 'claimAnalyses');
        $this->assertSame(false, $claim->invoke($worker));
        $this->assertSame('queued', (string) $this->db->value('SELECT status FROM analyses WHERE id = ?', [$id]), '대기열은 그대로(끄면 이어서)');
    }
}
