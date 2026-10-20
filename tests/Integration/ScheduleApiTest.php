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
 * 주기 분석 설정 API(P26, 명세 18.2·18.4): 접근 통제(비로그인 401, 그룹 관리자·일반 사용자 403, 보이지 않는 프로젝트 404 먼저, CSRF),
 * 저장·수정·끄기·409, 입력 검증, 켜기 조건(연결 없음·허용 호스트 아님·자격 증명을 읽을 수 없음), 연결 상태 경고, 다음 실행 시각 규칙,
 * 같은 시각 예약 수, 연결 해제 → 자동 끔 + 감사 로그, 일반 사용자는 /repo의 요약(사용 중·다음 예정)만, 프로젝트 목록 표시,
 * 알림 설정의 admin 전용 종류, 분석 목록 실행 주체 조건, 프로젝트 삭제 정리.
 */
final class ScheduleApiTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private App $app;
    private int $p;
    private int $p2;
    private int $hidden;
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $root = $this->tempDir('schedapi');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work']], ['encryption_key' => self::KEY]);
        mkdir($root . '/work');
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'admin2' => 'admin', 'kim' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, 'sched', 'python');
        $this->p2 = TestDb::project($db, 'other', 'python');
        $this->hidden = TestDb::project($db, 'hidden', 'python');
        $now = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, ?)", [$this->p, $this->u['kim'], $now]);
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g', ?, ?)", [$now, $now]);
        $g = $db->lastInsertId();
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?)', [$g, $this->u['mgr'], $now]);
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', ?, ?)", [$this->p, $g, $now]);
        $db->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, '', ?, ?)", [$now, $now]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function connect(int $pid, string $host = 'ghe.corp'): void
    {
        $this->app->repoConnections()->save(Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], '127.0.0.1'), $this->u['admin'], $pid,
            ['kind' => 'git', 'url' => "https://{$host}/team/app.git", 'branch' => 'main', 'secret' => 'tok-1234']);
    }

    /** @return array<string, mixed> */
    private static function on(int $days = 7, string $time = '03:00', ?int $rev = null): array
    {
        return ['enabled' => true, 'interval_days' => $days, 'run_time' => $time] + ($rev === null ? [] : ['revision' => $rev]);
    }

    public function testAccessControl(): void
    {
        $this->connect($this->p);
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get("/api/v1/projects/{$this->p}/schedule")->status);
        $this->assertSame(401, $anon->get('/api/v1/admin/schedules')->status);
        foreach (['kim', 'mgr'] as $who) {
            $c = $this->as($who);
            $this->assertSame(403, $c->get("/api/v1/projects/{$this->p}/schedule")->status, "{$who} 설정 읽기 403");
            $this->assertSame(403, $c->put("/api/v1/projects/{$this->p}/schedule", self::on())->status, "{$who} 저장 403");
            $this->assertSame(404, $c->get("/api/v1/projects/{$this->hidden}/schedule")->status, '보이지 않는 프로젝트 404(403보다 먼저)');
            $this->assertSame(404, $c->put("/api/v1/projects/{$this->hidden}/schedule", self::on())->status);
            $this->assertSame(403, $c->get('/api/v1/admin/schedules')->status);
            $this->assertSame(403, $c->get('/admin/schedules')->status);
        }
        $admin = $this->as('admin');
        $this->assertSame(404, $admin->get('/api/v1/projects/999999/schedule')->status);
        $this->assertSame(403, $admin->request('PUT', "/api/v1/projects/{$this->p}/schedule", [], self::on(), null, [], false)->status, 'CSRF');
        $this->assertSame(200, $admin->get('/admin/schedules')->status);
        // 일반 사용자: /repo에 요약(사용 중·다음 예정)만, 연속 실패·오류 없음
        $this->assertSame(200, $admin->put("/api/v1/projects/{$this->p}/schedule", self::on())->status);
        $kv = $this->as('kim')->get("/api/v1/projects/{$this->p}/repo")->decoded()['data'];
        $this->assertSame(['enabled' => true, 'next_run_at' => '2026-10-02T18:00:00Z', 'interval_days' => 7], $kv['schedule']);
        $this->assertSame('2026-10-02T18:00:00Z', $kv['next_run_at']);
        $av = $admin->get("/api/v1/projects/{$this->p}/repo")->decoded()['data']['schedule'];
        $this->assertSame([0, null], [$av['consecutive_failures'], $av['last_error_code']], 'admin은 연속 실패도');
    }

    public function testSaveModifyDisableConflictAndValidation(): void
    {
        $admin = $this->as('admin');
        // 연결 없음 → 켤 수 없음(끄는 저장은 가능)
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on());
        $this->assertSame([409, 'schedule_connection_required', 'repo_not_configured'], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['reason'] ?? null]);
        $v = $admin->get("/api/v1/projects/{$this->p}/schedule")->decoded()['data'];
        $this->assertSame([false, 7, '03:00', 'repo_not_configured', null], [$v['enabled'], $v['interval_days'], $v['run_time'], $v['blocker'], $v['revision']]);
        $this->connect($this->p);
        // 입력 검증
        foreach ([['interval_days', 0], ['interval_days', 366], ['interval_days', '7'], ['interval_days', 1.5], ['run_time', '24:00'], ['run_time', '3:00'],
            ['run_time', "03:00\n"], ['run_time', null], ['enabled', 'yes'], ['enabled', null]] as [$f, $bad]) {
            $r = $admin->put("/api/v1/projects/{$this->p}/schedule", [$f => $bad] + self::on());
            $this->assertSame([400, 'invalid_schedule_input', $f], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['field'] ?? null], "{$f}=" . var_export($bad, true));
        }
        // 켜기: 지금 = KST 2026-10-02 21:00 → 다음 = KST 10-03 03:00(UTC 10-02 18:00), 연결 미확인 경고
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on());
        $v = $r->decoded()['data'];
        $this->assertSame([200, true, 7, '03:00', '2026-10-02T18:00:00Z', 1, ['connection_unchecked'], null],
            [$r->status, $v['enabled'], $v['interval_days'], $v['run_time'], $v['next_run_at'], $v['revision'], $v['warnings'], $v['blocker']]);
        // 그대로 저장 → 다음 시각·개정 그대로(감사 없음)
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on(7, '03:00', 1));
        $this->assertSame([200, '2026-10-02T18:00:00Z', 1], [$r->status, $r->decoded()['data']['next_run_at'], $r->decoded()['data']['revision']]);
        // 시각 변경 → 바꾼 시점 이후 가장 가까운 시각(오늘 22:30 KST)
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on(7, '22:30', 1));
        $this->assertSame(['2026-10-02T13:30:00Z', 2], [$r->decoded()['data']['next_run_at'], $r->decoded()['data']['revision']]);
        // 동시 수정 409
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on(1, '22:30', 1));
        $this->assertSame([409, 'schedule_conflict', 2], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['revision'] ?? null]);
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on(1, '22:30'));
        $this->assertSame([409, 'schedule_conflict'], [$r->status, $r->decoded()['error']['code']], '이미 있으면 revision 필수');
        // 끄기 → 다음 시각 없음
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", ['enabled' => false, 'interval_days' => 7, 'run_time' => '22:30', 'revision' => 2]);
        $this->assertSame([200, false, null, 3], [$r->status, $r->decoded()['data']['enabled'], $r->decoded()['data']['next_run_at'], $r->decoded()['data']['revision']]);
        // 감사: 켬, 시각 변경, 끔(이전·새 값)
        $audit = array_map(static fn (array $r): array => json_decode((string) $r['details'], true)['changes'],
            $this->app->db()->all("SELECT details FROM audit_log WHERE action = 'schedule.save' ORDER BY id"));
        $this->assertSame([
            ['enabled' => [false, true], 'interval_days' => [null, 7], 'run_time' => [null, '03:00']],
            ['run_time' => ['03:00', '22:30']],
            ['enabled' => [true, false]],
        ], $audit);
    }

    public function testEnableBlockersAndWarnings(): void
    {
        $admin = $this->as('admin');
        // 허용 목록에 없는 호스트(저장 뒤 목록에서 지움)
        $this->connect($this->p);
        $this->app->db()->execute("DELETE FROM repo_allowed_hosts WHERE host = 'ghe.corp'");
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on());
        $this->assertSame([409, 'repo_host_not_allowed'], [$r->status, $r->decoded()['error']['details']['reason'] ?? null]);
        $this->app->db()->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, '', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        // 자격 증명을 읽을 수 없음(키가 바뀜)
        $other = TestApp::make($this->tempDir('schedkey'), [], ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        $c = new TestClient(new App(PJG_ROOT, $other->config, $this->app->clock, $this->app->logger, $this->app->paths, $this->app->db()));
        $this->assertSame(200, $c->login('admin', 'Passw0rd!x')->status);
        $r = $c->put("/api/v1/projects/{$this->p}/schedule", self::on());
        $this->assertSame([409, 'repo_secret_unreadable'], [$r->status, $r->decoded()['error']['details']['reason'] ?? null]);
        // 연결 테스트가 실패였으면 경고와 함께 저장
        $this->app->repoConnections()->mark($this->p, 'test', false, 'repo_unreachable');
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on());
        $this->assertSame([200, ['connection_error'], 'error'], [$r->status, $r->decoded()['data']['warnings'], $r->decoded()['data']['connection_status']]);
        $this->app->repoConnections()->mark($this->p, 'test', true, null);
        $r = $admin->put("/api/v1/projects/{$this->p}/schedule", self::on(7, '04:00', 1));
        $this->assertSame([200, []], [$r->status, $r->decoded()['data']['warnings']]);
    }

    public function testSameTimeCountsDisconnectAutoOffListAndCascade(): void
    {
        $admin = $this->as('admin');
        $this->connect($this->p);
        $this->connect($this->p2);
        $admin->put("/api/v1/projects/{$this->p2}/schedule", self::on(7, '03:00'));
        $v = $admin->get("/api/v1/projects/{$this->p}/schedule")->decoded()['data'];
        $this->assertSame(['03:00' => 1], $v['time_counts'], '같은 시각에 예약된 다른 프로젝트 수(자기 제외)');
        $admin->put("/api/v1/projects/{$this->p}/schedule", self::on(7, '03:00'));
        // 프로젝트 목록: 주기 표시(아이콘 + 다음 예정), 일반 사용자도 사용 중·다음 예정은 보인다
        $html = $this->as('kim')->get('/projects')->body;
        $this->assertContains('project-schedule-tag', $html);
        $this->assertContains('주기 · 다음 26-10-03 03:00', $html);
        // 연결 해제 → 자동 끔 + 감사
        $this->assertSame(200, $admin->request('DELETE', "/api/v1/projects/{$this->p}/repo")->status);
        $v = $admin->get("/api/v1/projects/{$this->p}/schedule")->decoded()['data'];
        $this->assertSame([false, null, 'repo_not_configured'], [$v['enabled'], $v['next_run_at'], $v['blocker']]);
        $this->assertSame('{"reason":"repo_disconnected"}', $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'schedule.auto_disable' AND target_id = ?", [(string) $this->p]));
        // 연속 실패 경고는 admin 목록에만
        $this->app->db()->execute('UPDATE project_schedules SET consecutive_failures = 2, last_error_code = ? WHERE project_id = ?', ['repo_unreachable', $this->p2]);
        $this->assertContains('연속 실패 2회', $admin->get('/projects')->body);
        $this->app->db()->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$this->p2, $this->u['kim']]);
        $this->assertNotContains('연속 실패', $this->as('kim')->get('/projects')->body);
        // 프로젝트 삭제 → 일정·도래 기록 정리(CASCADE)
        $this->app->db()->execute("INSERT INTO schedule_ticks (project_id, due_at, result, created_at) VALUES (?, '2026-10-01T18:00:00Z', 'skipped_busy', '2026-10-01T18:00:00Z')", [$this->p2]);
        $this->app->db()->execute('DELETE FROM projects WHERE id = ?', [$this->p2]);
        $this->assertSame([0, 0], [(int) $this->app->db()->value('SELECT COUNT(*) FROM project_schedules WHERE project_id = ?', [$this->p2]),
            (int) $this->app->db()->value('SELECT COUNT(*) FROM schedule_ticks WHERE project_id = ?', [$this->p2])]);
    }

    public function testNotificationKindIsAdminOnlyAndTriggerFilter(): void
    {
        $kim = $this->as('kim');
        $prefs = $kim->get('/api/v1/notifications/preferences')->decoded()['data'];
        $this->assertFalse(array_key_exists('schedule_failed', $prefs), '일반 사용자 설정에 admin 전용 종류 없음');
        $r = $kim->put('/api/v1/notifications/preferences', ['schedule_failed' => false]);
        $this->assertSame([400, 'invalid_notification_pref'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertNotContains('schedule_failed', $kim->get('/notifications')->body);
        $admin = $this->as('admin');
        $this->assertSame(true, $admin->get('/api/v1/notifications/preferences')->decoded()['data']['schedule_failed']);
        $this->assertSame(false, $admin->put('/api/v1/notifications/preferences', ['schedule_failed' => false])->decoded()['data']['schedule_failed']);
        $this->assertContains('notif-pref-schedule_failed', $admin->get('/notifications')->body);
        // 역할이 바뀐 사용자에게 남은 admin 전용 알림은 보이지 않는다
        $this->app->db()->execute("INSERT INTO notifications (user_id, kind, project_id, params, dedupe_key, created_at) VALUES (?, 'schedule_failed', ?, '{}', 'x', '2026-10-02T00:00:00Z')", [$this->u['kim'], $this->p]);
        $this->assertSame(0, $kim->get('/api/v1/notifications/unread-count')->decoded()['data']['count'] ?? $kim->get('/api/v1/notifications/unread-count')->decoded()['data']);
        // 분석 목록 실행 주체 조건
        $db = $this->app->db();
        TestDb::analysis($db, $this->p, $this->u['admin']);
        $db->execute("INSERT INTO analyses (project_id, status, source_kind, source_ref, trigger_kind, requested_by, tool, queued_at, started_at, finished_at, failure_code)"
            . " VALUES (?, 'failed', 'git', 'main', 'schedule', NULL, 'ruff', '2026-10-02T00:00:00Z', '2026-10-02T00:00:00Z', '2026-10-02T00:00:00Z', 'repo_unreachable')", [$this->p]);
        $all = $kim->get("/api/v1/projects/{$this->p}/analyses")->decoded();
        $sch = $kim->get("/api/v1/projects/{$this->p}/analyses", ['trigger' => 'schedule'])->decoded();
        $usr = $kim->get("/api/v1/projects/{$this->p}/analyses", ['trigger' => 'user'])->decoded();
        $this->assertSame([2, 1, 1, 'schedule', null], [$all['page']['total'], $sch['page']['total'], $usr['page']['total'], $sch['data'][0]['trigger_kind'], $sch['data'][0]['requested_by']]);
        $r = $kim->get("/api/v1/projects/{$this->p}/analyses", ['trigger' => 'cron']);
        $this->assertSame([400, 'invalid_trigger_filter'], [$r->status, $r->decoded()['error']['code']]);
    }
}
