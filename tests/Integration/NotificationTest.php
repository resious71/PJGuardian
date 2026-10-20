<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Events\AnalysisFailed;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 앱 안 알림(P24): 접근 통제(비로그인 401·본인만·admin도 남의 알림 404·CSRF·접근 회수 뒤 숨김·접근 없는 사람에게 만들지 않음),
 * 발생(분석 성공·실패·취소·워커 복구, 요약 수신자, 자기 지정, 사람 변경만, 일괄 묶음, assigned 우선, 해제 없음, 자동 변경 없음),
 * 멱등(같은 사건 두 번), 실패 격리(표가 없어도 본 동작 성공 + 경고 로그), 종류별 설정, 읽음·삭제 API, 관리자 보관 설정, 사용자·프로젝트 삭제 정리,
 * 헤더 배지 마크업, 배경 폴링이 세션 활동이 아님.
 *
 * 프로젝트 P: kim·lee·choi 접근(직접 매핑), park은 접근 없음. RunFixture의 분석 요청자는 kim.
 */
final class NotificationTest extends TestCase
{
    private App $app;
    private string $root;
    private int $p;
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $this->root = $this->tempDir('notif');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']]);
        mkdir($this->root . '/work');
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'choi' => 'user', 'park' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, '알림 <프로젝트>', 'python');
        foreach (['kim', 'lee', 'choi'] as $who) {
            $this->grant($this->p, $who);
        }
    }

    private function grant(int $projectId, string $who): void
    {
        $this->app->db()->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$projectId, $this->u[$who]]);
    }

    private function revoke(int $projectId, string $who): void
    {
        $this->app->db()->execute("DELETE FROM project_access WHERE project_id = ? AND subject_type = 'user' AND subject_id = ?", [$projectId, $this->u[$who]]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return list<array<string, mixed>> 그 사람의 알림 행(오래된 순) */
    private function rows(string $who, ?string $kind = null): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = ?' . ($kind === null ? '' : ' AND kind = ?') . ' ORDER BY id';
        return array_map(static function (array $r): array {
            $r['params'] = json_decode((string) $r['params'], true);
            return $r;
        }, $this->app->db()->all($sql, $kind === null ? [$this->u[$who]] : [$this->u[$who], $kind]));
    }

    /** @return array<string, mixed> */
    private function user(string $who): array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$this->u[$who]]);
    }

    private function version(int $defectId): int
    {
        return (int) $this->app->db()->value('SELECT version FROM defects WHERE id = ?', [$defectId]);
    }

    private function change(string $who, int $defectId, array $changes): void
    {
        $this->app->defectUpdate()->update($this->user($who), $defectId, ['version' => $this->version($defectId)] + $changes);
    }

    /** @param list<int> $ids */
    private function bulk(string $who, array $ids, array $changes): void
    {
        $items = array_map(fn (int $id): array => ['id' => $id, 'version' => $this->version($id)], $ids);
        $this->app->defectUpdate()->bulk($this->user($who), $this->p, ['items' => $items, 'changes' => $changes]);
    }

    /** RunFixture 분석 + 분석 완료 처리(AnalysisFinalizer와 같은 이벤트) */
    private function analyze(RunFixture $f, array $files, array $hits): array
    {
        $s = $f->analyze($files, $hits);
        $this->app->events()->dispatch(new AnalysisSucceeded($s['analysis_id'], $this->p, array_diff_key($s, ['assigned_changes' => true]), $s['assigned_changes']));
        return $s;
    }

    private function emitFor(string $who, int $projectId = 0, string $key = 'k1'): int
    {
        return $this->app->notifications()->emit('analysis_done', [$this->u[$who]], ['project_id' => $projectId ?: $this->p, 'analysis_id' => 1],
            ['run' => 1, 'total' => 1, 'new' => 1, 'resolved' => 0, 'reopened' => 0, 'no_change' => false], $key);
    }

    // ---- 접근 통제 ----

    public function testOnlyOwnerSeesNotificationsAdminIncluded(): void
    {
        $this->assertSame(1, $this->emitFor('kim'));
        $id = (int) $this->rows('kim')[0]['id'];
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get('/api/v1/notifications')->status);
        $this->assertSame(401, $anon->get('/api/v1/notifications/unread-count')->status);

        $kim = $this->as('kim');
        $r = $kim->get('/api/v1/notifications')->decoded();
        $this->assertSame(1, $r['page']['total']);
        $item = $r['data'][0];
        $this->assertSame(['analysis_done', '알림 <프로젝트>', "/projects/{$this->p}?run=1", null], [$item['kind'], $item['project']['name'], $item['href'], $item['read_at']]);
        $this->assertSame(1, $kim->get('/api/v1/notifications/unread-count')->decoded()['data']['count']);

        foreach (['lee', 'admin'] as $who) {
            $c = $this->as($who);
            $this->assertSame(0, $c->get('/api/v1/notifications')->decoded()['page']['total'], "{$who}: 남의 알림은 목록에 없음");
            $this->assertSame(0, $c->get('/api/v1/notifications/unread-count')->decoded()['data']['count']);
            foreach ([['POST', "/api/v1/notifications/{$id}/read"], ['POST', "/api/v1/notifications/{$id}/unread"], ['DELETE', "/api/v1/notifications/{$id}"]] as [$m, $path]) {
                $res = $c->request($m, $path, [], []);
                $this->assertSame(404, $res->status, "{$who} {$m} {$path}");
                $this->assertSame('notification_not_found', $res->decoded()['error']['code']);
            }
        }
        $this->assertSame(404, $kim->post('/api/v1/notifications/999999/read')->status, '없는 id도 같은 404');
        $this->assertSame(403, $kim->post("/api/v1/notifications/{$id}/read", [], false)->status, 'CSRF 없으면 거부');
        $this->assertSame(403, $kim->request('DELETE', '/api/v1/notifications/read', [], null, null, [], false)->status);
        $this->assertSame(403, $kim->request('PUT', '/api/v1/notifications/preferences', [], ['analysis_done' => false], null, [], false)->status);
        $this->assertSame(null, $this->rows('kim')[0]['read_at'], '남의 시도·CSRF 거부는 아무것도 바꾸지 않음');
        // 잘못된 조건
        $this->assertSame(400, $kim->get('/api/v1/notifications', ['kind' => 'email'])->status);
        $this->assertSame(400, $kim->get('/api/v1/notifications', ['unread' => 'yes'])->status);
        $this->assertSame(400, $kim->get('/api/v1/notifications', ['size' => '500'])->status);
    }

    public function testNotCreatedWithoutAccessAndHiddenAfterRevoke(): void
    {
        $this->assertSame(0, $this->emitFor('park'), '접근 없는 사람에게는 만들지 않음');
        $db = $this->app->db();
        $db->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->u['choi']]);
        $this->assertSame(0, $this->emitFor('choi'), '비활성 사용자에게도 만들지 않음');
        $db->execute("UPDATE users SET status = 'active' WHERE id = ?", [$this->u['choi']]);
        $this->assertSame(0, count($this->rows('park')) + count($this->rows('choi')));

        $this->emitFor('lee', 0, 'a');
        $id = (int) $this->rows('lee')[0]['id'];
        $lee = $this->as('lee');
        $this->assertSame(1, $lee->get('/api/v1/notifications/unread-count')->decoded()['data']['count']);
        $this->revoke($this->p, 'lee');
        $this->assertSame(0, $lee->get('/api/v1/notifications/unread-count')->decoded()['data']['count'], '접근 회수 → 개수에서 빠짐');
        $this->assertSame(0, $lee->get('/api/v1/notifications')->decoded()['page']['total'], '목록에서 빠짐');
        $this->assertSame(404, $lee->post("/api/v1/notifications/{$id}/read")->status, '이동(읽음)도 없는 것과 같음');
        $this->assertSame(1, count($this->rows('lee')), '행은 남음(보관 정리가 지움)');
        $this->grant($this->p, 'lee');
        $this->assertSame(1, $lee->get('/api/v1/notifications/unread-count')->decoded()['data']['count'], '다시 매핑하면 다시 보임');
        // 그룹 경로의 접근도 같은 판정
        $this->revoke($this->p, 'lee');
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        $g = $db->lastInsertId();
        $db->execute("INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 0, '2026-10-01T00:00:00Z')", [$g, $this->u['lee']]);
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', ?, '2026-10-01T00:00:00Z')", [$this->p, $g]);
        $this->assertSame(1, $lee->get('/api/v1/notifications/unread-count')->decoded()['data']['count'], '그룹 매핑으로 보임');
        // 프로젝트 삭제 → 그 프로젝트 알림도 지워짐(CASCADE)
        $db->execute('DELETE FROM project_access WHERE project_id = ?', [$this->p]);
        $db->execute('DELETE FROM projects WHERE id = ?', [$this->p]);
        $this->assertSame([], $this->rows('lee'));
    }

    // ---- 분석 종료 ----

    public function testAnalysisSucceededNotifiesRequesterAndAssigneesOnce(): void
    {
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['kim']);
        $s1 = $this->analyze($f, ['a.py' => 5, 'b.py' => 2], [['a.py', 1], ['a.py', 2], ['a.py', 3], ['b.py', 1]]);
        $done = $this->rows('kim', 'analysis_done');
        $this->assertSame(1, count($done));
        $this->assertSame(['run' => 1, 'total' => 4, 'new' => 4, 'resolved' => 0, 'reopened' => 0, 'no_change' => false], $done[0]['params']);
        $this->assertSame([$this->p, $s1['analysis_id']], [(int) $done[0]['project_id'], (int) $done[0]['analysis_id']]);
        $this->assertSame([], $this->app->db()->all("SELECT * FROM notifications WHERE kind = 'analysis_summary'"), '담당 결함이 아무에게도 없으면 요약 없음');

        // a1 → lee, a2 → choi, a3 → kim(요청자), b1 → park에게는 지정할 수 없음
        $this->change('admin', $f->id('a.py', 1), ['assignee_id' => $this->u['lee']]);
        $this->change('admin', $f->id('a.py', 2), ['assignee_id' => $this->u['choi']]);
        $this->change('admin', $f->id('a.py', 3), ['assignee_id' => $this->u['kim']]);
        // Run 2: a2·a3 해결(choi·kim 담당), a1 유지, b1 해결, c1 신규
        $s2 = $this->analyze($f, ['a.py' => 5, 'b.py' => 2, 'c.py' => 1], [['a.py', 1], ['c.py', 1]]);
        $this->assertSame([1, 3, 0], [$s2['new'], $s2['resolved'], $s2['reopened']]);
        $this->assertSame(['run' => 2, 'total' => 2, 'new' => 1, 'resolved' => 3, 'reopened' => 0, 'no_change' => false], $this->rows('kim', 'analysis_done')[1]['params']);
        $this->assertSame([], $this->rows('kim', 'analysis_summary'), '요청자는 요약을 받지 않음(analysis_done에 건수)');
        $lee = $this->rows('lee', 'analysis_summary');
        $this->assertSame(1, count($lee), '열린 담당 결함이 있는 lee');
        $this->assertSame(['mine_resolved' => 0, 'mine_reopened' => 0, 'run' => 2, 'new' => 1, 'resolved' => 3, 'reopened' => 0], $lee[0]['params']);
        $choi = $this->rows('choi', 'analysis_summary');
        $this->assertSame(1, count($choi), '담당 결함이 이번에 해결된 choi(열린 담당은 없음)');
        $this->assertSame(1, $choi[0]['params']['mine_resolved']);
        $this->assertSame([], $this->rows('park'), '접근 없는 사람 없음');
        $this->assertSame([], $this->rows('choi', 'defect_changed'), '자동 해결은 결함 변경 알림이 아님');

        // 같은 사건 두 번(워커 재시도·중복 호출) → 한 건
        $this->app->events()->dispatch(new AnalysisSucceeded($s2['analysis_id'], $this->p, [], $s2['assigned_changes']));
        $this->assertSame(2, count($this->rows('kim', 'analysis_done')));
        $this->assertSame(1, count($this->rows('lee', 'analysis_summary')));

        // Run 3: 변화 없음 → 요청자 analysis_done만, 요약 없음
        $this->analyze($f, ['a.py' => 5, 'b.py' => 2, 'c.py' => 1], [['a.py', 1], ['c.py', 1]]);
        $this->assertSame(['run' => 3, 'total' => 2, 'new' => 0, 'resolved' => 0, 'reopened' => 0, 'no_change' => false], $this->rows('kim', 'analysis_done')[2]['params']);
        $this->assertSame(1, count($this->rows('lee', 'analysis_summary')), '변화 없으면 요약 없음');

        // Run 4: choi 담당 a2 재감지 → 재오픈
        $this->analyze($f, ['a.py' => 5, 'b.py' => 2, 'c.py' => 1], [['a.py', 1], ['a.py', 2], ['c.py', 1]]);
        $this->assertSame(1, $this->rows('choi', 'analysis_summary')[1]['params']['mine_reopened']);
    }

    public function testAnalysisFailedCancelledAndRecoveredNotifyRequester(): void
    {
        $db = $this->app->db();
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['kim']);
        $failed = $f->failed('failed');
        $this->app->events()->dispatch(new AnalysisFailed($failed, $this->p, 'tool_crash'));
        $this->app->events()->dispatch(new AnalysisFailed($failed, $this->p, 'tool_crash'));
        $rows = $this->rows('kim', 'analysis_failed');
        $this->assertSame(1, count($rows), '같은 실패 두 번이어도 한 건');
        $this->assertSame(['status' => 'failed', 'code' => 'tool_crash'], $rows[0]['params']);
        $this->assertSame([], $this->rows('lee'), '요청자에게만');
        $item = $this->as('kim')->get('/api/v1/notifications')->decoded()['data'][0];
        $this->assertSame("/analyses/{$failed}", $item['href']);
        $this->assertSame('analysis-failures', $item['failure']['guide_key'], '오류 코드 가이드 키 연결(P29 의도된 단언 변경: 사용자 가이드 절 key)');

        // 대기 중 취소(API)
        $queued = TestDb::analysis($db, $this->p, $this->u['kim'], ['status' => 'queued']);
        $this->assertSame(200, $this->as('kim')->post("/api/v1/analyses/{$queued}/cancel")->status);
        $this->assertSame(['status' => 'cancelled', 'code' => null], $this->rows('kim', 'analysis_failed')[1]['params']);

        // 워커 비정상 종료 복구(실행 중 → failed worker_crashed)
        $running = TestDb::analysis($db, $this->p, $this->u['kim'], ['status' => 'running']);
        $this->app->workerRecovery()->recoverAll();
        $this->assertSame('failed', $db->value('SELECT status FROM analyses WHERE id = ?', [$running]));
        $this->assertSame(['status' => 'failed', 'code' => 'worker_crashed'], $this->rows('kim', 'analysis_failed')[2]['params']);

        // 요청자가 없는 분석(주기 분석)은 건너뜀
        $sched = TestDb::analysis($db, $this->p, $this->u['kim'], ['status' => 'failed', 'trigger_kind' => 'schedule', 'requested_by' => null, 'failure_code' => 'timeout']);
        $before = (int) $db->value('SELECT COUNT(*) FROM notifications');
        $this->app->events()->dispatch(new AnalysisFailed($sched, $this->p, 'timeout'));
        $this->assertSame($before, (int) $db->value('SELECT COUNT(*) FROM notifications'));
    }

    /** 실제 도구(ruff) + AnalysisFinalizer 경로: 커밋 뒤 이벤트로 analysis_done·analysis_summary가 만들어진다 */
    public function testRealFinalizerPathCreatesNotifications(): void
    {
        $h = new \PJGuardian\Tests\Support\DefectHarness($this->tempDir('notif-real'));
        if ($h->tool('ruff') === null) {
            fwrite(STDOUT, "      [건너뜀] bin/ruff 없음\n");
            return;
        }
        $db = $h->app->db();
        $kim = (int) $db->value("SELECT id FROM users WHERE login_id = 'kim'");
        $lee = TestDb::user($db, 'lee', 'user');
        $pid = $h->project('real', 'python');
        foreach ([$kim, $lee] as $uid) {
            $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$pid, $uid]);
        }
        $dir = $h->copySample('python_sample');
        $h->analyze($pid, 'ruff', $dir);
        $done = $db->all("SELECT params FROM notifications WHERE user_id = ? AND kind = 'analysis_done'", [$kim]);
        $this->assertSame(1, count($done));
        $this->assertSame(19, json_decode((string) $done[0]['params'], true)['new']);
        // lee에게 한 건 지정한 뒤 그 결함이 사라지면 요약(mine_resolved 1)
        $d = (int) $db->value('SELECT id FROM defects WHERE project_id = ? ORDER BY id LIMIT 1', [$pid]);
        $admin = ['id' => $kim, 'role' => 'user', 'status' => 'active'];
        $h->app->defectUpdate()->update($admin, $d, ['version' => (int) $db->value('SELECT version FROM defects WHERE id = ?', [$d]), 'assignee_id' => $lee]);
        $file = (string) $db->value('SELECT file_path FROM defects WHERE id = ?', [$d]);
        unlink($dir . '/' . $file);
        $h->analyze($pid, 'ruff', $dir);
        $sum = $db->all("SELECT params FROM notifications WHERE user_id = ? AND kind = 'analysis_summary'", [$lee]);
        $this->assertSame(1, count($sum));
        $this->assertTrue(json_decode((string) $sum[0]['params'], true)['mine_resolved'] >= 1);
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'defect_assigned'", [$lee]));
    }

    // ---- 결함 변경 ----

    public function testDefectAssignedAndChangedRules(): void
    {
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['admin']);
        $this->analyze($f, ['a.py' => 9], [['a.py', 1], ['a.py', 2], ['a.py', 3], ['a.py', 4], ['a.py', 5], ['a.py', 6]]);
        [$d1, $d2, $d3, $d4, $d5, $d6] = array_map(fn (int $l): int => $f->id('a.py', $l), [1, 2, 3, 4, 5, 6]);

        // 자기 지정 → 본인이 받음, 본인의 상태 변경도 받음
        $this->change('kim', $d1, ['assignee_id' => $this->u['kim']]);
        $a = $this->rows('kim', 'defect_assigned');
        $this->assertSame([1, ['count' => 1], $d1], [count($a), $a[0]['params'], (int) $a[0]['defect_id']]);
        $this->change('kim', $d1, ['status' => 'in_progress']);
        $c = $this->rows('kim', 'defect_changed');
        $this->assertSame([1, ['count' => 1, 'status' => 'in_progress', 'severity' => null]], [count($c), $c[0]['params']]);
        $this->change('lee', $d1, ['severity' => 'high']);
        $this->assertSame(['count' => 1, 'status' => null, 'severity' => 'high'], $this->rows('kim', 'defect_changed')[1]['params'], '다른 사람의 심각도 변경');
        // 같은 요청에서 지정 + 상태 → 새 담당자에게 defect_assigned만
        $this->change('admin', $d2, ['assignee_id' => $this->u['lee'], 'status' => 'postponed']);
        $this->assertSame([1, 0], [count($this->rows('lee', 'defect_assigned')), count($this->rows('lee', 'defect_changed'))]);
        // 해제는 알림 없음, 담당자 없는 결함의 상태 변경도 없음
        $n = (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications');
        $this->change('admin', $d2, ['assignee_id' => null]);
        $this->change('admin', $d3, ['status' => 'not_fix']);
        $this->assertSame($n, (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications'));
        // 값이 그대로면(변경 없음) 알림 없음
        $this->change('admin', $d1, ['status' => 'in_progress']);
        $this->assertSame($n, (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications'));

        // 일괄: choi에게 3건 지정 → 1건(count 3, defect_id 없음), 일괄 상태 변경 → 1건
        $this->bulk('admin', [$d4, $d5, $d6], ['assignee_id' => $this->u['choi']]);
        $a = $this->rows('choi', 'defect_assigned');
        $this->assertSame([1, ['count' => 3], null], [count($a), $a[0]['params'], $a[0]['defect_id']]);
        $this->bulk('admin', [$d4, $d5, $d6], ['status' => 'intentional', 'severity' => 'low']);
        $c = $this->rows('choi', 'defect_changed');
        $this->assertSame([1, ['count' => 3, 'status' => 'intentional', 'severity' => 'low']], [count($c), $c[0]['params']]);
        $item = $this->as('choi')->get('/api/v1/notifications', ['kind' => 'defect_changed'])->decoded()['data'][0];
        $this->assertSame('/my-defects', $item['href'], '일괄은 내 결함 목록으로');
        // 담당자가 섞인 일괄 상태 변경 → 받는 사람마다 1건
        $this->bulk('admin', [$d1, $d4, $d5], ['status' => 'open']);
        $this->assertSame([3, 2], [count($this->rows('kim', 'defect_changed')), count($this->rows('choi', 'defect_changed'))]);
        $this->assertSame(['count' => 2, 'status' => 'open', 'severity' => null], $this->rows('choi', 'defect_changed')[1]['params']);
        // 일괄에서 지정(lee로 d4) + 상태: lee는 assigned만, 그 요청에서 d1(kim 담당) 상태 변경은 kim에게 changed
        $this->bulk('admin', [$d1, $d4], ['assignee_id' => $this->u['lee'], 'status' => 'in_progress']);
        $this->assertSame([2, 0], [count($this->rows('lee', 'defect_assigned')), count($this->rows('lee', 'defect_changed'))]);
        $this->assertSame(['count' => 2], $this->rows('lee', 'defect_assigned')[1]['params']);
        // 담당 결함의 자동 해결(분석)은 defect_changed가 아님
        $before = count($this->rows('lee', 'defect_changed'));
        $this->analyze($f, ['a.py' => 9], [['a.py', 5], ['a.py', 6]]);
        $this->assertSame($before, count($this->rows('lee', 'defect_changed')));
        $this->assertSame(1, count($this->rows('lee', 'analysis_summary')), '대신 요약');
        // 문장에 결함 메시지·경로가 들어가지 않음
        foreach ($this->app->db()->all('SELECT params, dedupe_key FROM notifications') as $r) {
            $this->assertFalse(str_contains((string) $r['params'], 'a.py') || str_contains((string) $r['params'], 'm a'), (string) $r['params']);
        }
    }

    public function testDefectEventDedupeKeyIsHistoryBased(): void
    {
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['admin']);
        $this->analyze($f, ['a.py' => 3], [['a.py', 1]]);
        $d = $f->id('a.py', 1);
        $this->change('admin', $d, ['assignee_id' => $this->u['lee']]);
        $h = (int) $this->app->db()->value("SELECT id FROM defect_history WHERE defect_id = ? AND field = 'assignee' AND kind = 'manual'", [$d]);
        $this->assertSame("defect_assigned:h{$h}", $this->rows('lee', 'defect_assigned')[0]['dedupe_key']);
        // 같은 이벤트를 다시 보내도(재시도) 한 건
        $this->app->notifications()->emit('defect_assigned', [$this->u['lee']], ['project_id' => $this->p, 'defect_id' => $d], ['count' => 1], "defect_assigned:h{$h}");
        $this->assertSame(1, count($this->rows('lee', 'defect_assigned')));
    }

    // ---- 실패 격리 ----

    public function testFailureToCreateDoesNotBreakMainActions(): void
    {
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['kim']);
        $this->analyze($f, ['a.py' => 3], [['a.py', 1]]);
        $d = $f->id('a.py', 1);
        $db = $this->app->db();
        $db->execute('ALTER TABLE notifications RENAME TO notifications_off');
        // 결함 변경(HTTP)도, 분석 완료 처리도 정상
        $kim = $this->as('kim');
        $r = $kim->patch("/api/v1/defects/{$d}", ['version' => $this->version($d), 'assignee_id' => $this->u['kim'], 'status' => 'in_progress']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('in_progress', $db->value('SELECT status FROM defects WHERE id = ?', [$d]));
        $this->analyze($f, ['a.py' => 3], []);
        $this->assertSame('resolved', $db->value('SELECT status FROM defects WHERE id = ?', [$d]));
        $log = (string) file_get_contents($this->app->logger->fileFor(new \DateTimeImmutable('2026-10-02T12:00:00Z')));
        $this->assertContains('알림 생성 실패(본 동작에는 영향 없음)', $log);
        $this->assertContains('"level":"WARN"', $log);
        // 표가 없을 때: 개수는 0(헤더가 깨지지 않음), 목록은 503
        $this->assertSame(['count' => 0, 'available' => false], $kim->get('/api/v1/notifications/unread-count')->decoded()['data']);
        $this->assertSame(503, $kim->get('/api/v1/notifications')->status);
        $db->execute('ALTER TABLE notifications_off RENAME TO notifications');
    }

    // ---- 종류별 설정 ----

    public function testPreferencesApplyAtCreationPerUser(): void
    {
        $lee = $this->as('lee');
        $all = ['analysis_done' => true, 'analysis_failed' => true, 'defect_assigned' => true, 'defect_changed' => true, 'analysis_summary' => true];
        $this->assertSame($all, $lee->get('/api/v1/notifications/preferences')->decoded()['data'], '기본 모두 켜짐');
        $r = $lee->put('/api/v1/notifications/preferences', ['defect_assigned' => false]);
        $this->assertSame(array_merge($all, ['defect_assigned' => false]), $r->decoded()['data']);
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM notification_prefs WHERE user_id = ?', [$this->u['lee']]), '꺼진 것만 저장');

        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['admin']);
        $this->analyze($f, ['a.py' => 3], [['a.py', 1], ['a.py', 2]]);
        $this->change('admin', $f->id('a.py', 1), ['assignee_id' => $this->u['lee']]);
        $this->change('admin', $f->id('a.py', 2), ['assignee_id' => $this->u['choi']]);
        $this->assertSame([0, 1], [count($this->rows('lee', 'defect_assigned')), count($this->rows('choi', 'defect_assigned'))], '꺼진 종류는 만들지 않음, 다른 사용자는 그대로');
        $lee->put('/api/v1/notifications/preferences', ['defect_assigned' => true]);
        $this->assertSame(0, count($this->rows('lee', 'defect_assigned')), '다시 켜도 지난 알림은 생기지 않음');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM notification_prefs WHERE user_id = ?', [$this->u['lee']]), '기본값이면 행 없음');
        $this->change('admin', $f->id('a.py', 1), ['assignee_id' => $this->u['choi']]);
        $this->change('admin', $f->id('a.py', 1), ['assignee_id' => $this->u['lee']]);
        $this->assertSame(1, count($this->rows('lee', 'defect_assigned')), '켠 뒤의 사건은 받음');

        foreach ([[], ['email' => true], ['analysis_done' => 1], ['analysis_done' => 'false'], [true]] as $bad) {
            $res = $lee->put('/api/v1/notifications/preferences', $bad);
            $this->assertSame(400, $res->status, json_encode($bad));
            $this->assertSame('invalid_notification_pref', $res->decoded()['error']['code']);
        }
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'notification%'"), '개인 설정은 감사 로그 없음');
    }

    // ---- 읽음·삭제 ----

    public function testReadUnreadReadAllDeleteAreOwnAndIdempotent(): void
    {
        foreach (['n1', 'n2', 'n3'] as $k) {
            $this->emitFor('kim', 0, $k);
        }
        $this->emitFor('lee', 0, 'l1');
        [$n1, $n2, $n3] = array_map(static fn (array $r): int => (int) $r['id'], $this->rows('kim'));
        $kim = $this->as('kim');
        $r = $kim->post("/api/v1/notifications/{$n1}/read")->decoded()['data'];
        $this->assertSame('2026-10-02T12:00:00Z', $r['read_at']);
        $this->app->db()->execute("UPDATE notifications SET read_at = '2026-10-02T11:00:00Z' WHERE id = ?", [$n1]);
        $this->assertSame('2026-10-02T11:00:00Z', $kim->post("/api/v1/notifications/{$n1}/read")->decoded()['data']['read_at'], '멱등: 처음 읽은 시각 유지');
        $this->assertSame(2, $kim->get('/api/v1/notifications/unread-count')->decoded()['data']['count']);
        $this->assertSame(2, $kim->get('/api/v1/notifications', ['unread' => '1'])->decoded()['page']['total']);
        $this->assertSame(null, $kim->post("/api/v1/notifications/{$n1}/unread")->decoded()['data']['read_at']);
        $this->assertSame(null, $kim->post("/api/v1/notifications/{$n1}/unread")->decoded()['data']['read_at'], '멱등');
        $this->assertSame(3, $kim->post('/api/v1/notifications/read-all')->decoded()['data']['updated']);
        $this->assertSame(0, $kim->post('/api/v1/notifications/read-all')->decoded()['data']['updated'], '멱등');
        $this->assertSame(null, $this->rows('lee')[0]['read_at'], '모두 읽음은 내 것만');
        $this->assertSame(200, $kim->post("/api/v1/notifications/{$n2}/unread")->status);
        $this->assertSame(200, $kim->request('DELETE', "/api/v1/notifications/{$n3}")->status);
        $this->assertSame(404, $kim->request('DELETE', "/api/v1/notifications/{$n3}")->status, '지운 것은 404');
        $this->assertSame(1, $kim->request('DELETE', '/api/v1/notifications/read')->decoded()['data']['deleted'], '읽은 n1만');
        $this->assertSame([$n2], array_map(static fn (array $r): int => (int) $r['id'], $this->rows('kim')));
        $this->assertSame(1, count($this->rows('lee')), '남의 알림은 그대로');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'notification%'"), '읽음·삭제는 감사 로그 없음');
    }

    // ---- 관리자 보관 설정 ----

    public function testAdminRetentionSettings(): void
    {
        $admin = $this->as('admin');
        $s = $admin->get('/api/v1/admin/settings')->decoded()['data'];
        $this->assertSame(['value' => 7, 'default' => 7, 'min' => 1, 'max' => 365], $s['notifications.retain_read_days']);
        $this->assertSame(['value' => 30, 'default' => 30, 'min' => 1, 'max' => 365], $s['notifications.retain_unread_days']);
        $this->assertSame(['value' => 1000, 'default' => 1000, 'min' => 100, 'max' => 10000], $s['notifications.max_per_user']);
        foreach ([0, 366, 'x', '1.5', null] as $bad) {
            $this->assertSame(400, $admin->patch('/api/v1/admin/settings', ['notifications.retain_read_days' => $bad])->status, json_encode($bad));
        }
        $this->assertSame(400, $admin->patch('/api/v1/admin/settings', ['notifications.max_per_user' => 99])->status);
        $this->assertSame(403, $this->as('kim')->patch('/api/v1/admin/settings', ['notifications.retain_read_days' => 3])->status, '일반 사용자 403');
        $r = $admin->patch('/api/v1/admin/settings', ['notifications.retain_read_days' => 3, 'notifications.retain_unread_days' => '10']);
        $this->assertSame(200, $r->status);
        $audit = $this->app->db()->all("SELECT details FROM audit_log WHERE action = 'setting.update' ORDER BY id");
        $this->assertSame([['key' => 'notifications.retain_read_days', 'from' => 7, 'to' => 3], ['key' => 'notifications.retain_unread_days', 'from' => 30, 'to' => 10]],
            array_map(static fn (array $a): array => json_decode((string) $a['details'], true), $audit));
        // 다음 정리부터 반영: 4일 전에 읽은 알림이 지워짐(기본 7일이면 남았을 것)
        $this->emitFor('kim', 0, 'old');
        $this->app->db()->execute("UPDATE notifications SET read_at = '2026-09-28T12:00:00Z' WHERE dedupe_key = 'old'");
        $out = $this->app->notifications()->prune(new \DateTimeImmutable('2026-10-02T12:00:00Z'));
        $this->assertSame(1, $out['read']);
        // 화면: admin만, 일반 사용자 403. X12(의도된 단언 변경): 옛 주소는 설정 관리 "알림" 탭으로 이동하고 구역은 그 화면에
        $this->assertSame('/admin/config-settings#notification', $admin->get('/admin/notifications')->header('Location'));
        $page = $admin->get('/admin/config-settings');
        $this->assertSame(200, $page->status);
        $this->assertContains('data-setting="notifications.retain_read_days"', $page->body);
        $this->assertSame(403, $this->as('kim')->get('/admin/notifications')->status);
    }

    // ---- 사용자 삭제·화면·세션 ----

    public function testUserRowsAreNeverDeletedButNotificationFksCascade(): void
    {
        // 사용자 행은 지우지 않는다(비활성화만, 트리거 users_are_never_deleted). 혹시 지워지는 경로가 생겨도 알림·설정은 함께 지워지게 FK CASCADE
        $db = $this->app->db();
        $this->assertThrows(\PDOException::class, fn () => $db->execute('DELETE FROM users WHERE id = ?', [$this->u['park']]), 'users_are_never_deleted');
        foreach (['notifications', 'notification_prefs'] as $table) {
            $fk = array_values(array_filter($db->all("PRAGMA foreign_key_list({$table})"), static fn (array $r): bool => $r['table'] === 'users'));
            $this->assertSame([['user_id', 'CASCADE']], array_map(static fn (array $r): array => [$r['from'], $r['on_delete']], $fk), $table);
        }
        // 비활성 사용자: 알림을 새로 받지 않고(접근 판정이 활성만) 로그인도 못 한다
        $db->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->u['lee']]);
        $this->assertSame(0, $this->emitFor('lee'));
    }

    public function testHeaderBellPageAndBackgroundPollDoesNotTouchSession(): void
    {
        $kim = $this->as('kim');
        $body = $kim->get('/projects')->body;
        $this->assertContains('data-notif-bell', $body);
        $this->assertContains('data-notif-badge', $body);
        $this->assertContains('id="notif-panel"', $body);
        $this->assertContains('<meta name="notification-poll" content="60">', $body);
        $this->assertContains('href="/notifications" data-nav="nav.notifications"', $body);
        $page = $kim->get('/notifications');
        $this->assertSame(200, $page->status);
        $this->assertContains('data-notif-list', $page->body);
        $this->assertContains('role="switch"', $page->body);
        $this->assertNotContains('notification-poll', (new TestClient($this->app))->get('/login')->body, '로그인 화면에는 없음');

        $db = $this->app->db();
        $db->execute("UPDATE sessions SET last_activity_at = '2026-10-02T11:50:00Z' WHERE user_id = ?", [$this->u['kim']]);
        $r = $kim->request('GET', '/api/v1/notifications/unread-count', [], null, null, ['X-PJG-Background' => '1']);
        $this->assertSame(200, $r->status);
        $this->assertSame('2026-10-02T11:50:00Z', $db->value('SELECT last_activity_at FROM sessions WHERE user_id = ?', [$this->u['kim']]), '배경 폴링은 활동이 아님');
        $kim->get('/api/v1/notifications/unread-count');
        $this->assertSame('2026-10-02T12:00:00Z', $db->value('SELECT last_activity_at FROM sessions WHERE user_id = ?', [$this->u['kim']]), '일반 요청은 활동');
    }
}
