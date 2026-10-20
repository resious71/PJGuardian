<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Defects\DefectQuery;
use PJGuardian\Services\Defects\DefectUpdate;
use PJGuardian\Services\Events\DefectAssigned;
use PJGuardian\Services\Events\DefectStatusChanged;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Tests\Support\DefectHarness;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 결함 추적(명세 6.2, 6.3, 17, P18): 담당자·심각도·상태 변경, 이력, 낙관적 잠금, 일괄 변경, 내게 지정된 결함, 이벤트.
 * 위험한 로직(동시 수정·이력 정확성)이라 구현보다 먼저 썼다.
 * 사용자: admin, kim·lee·ghost(py 매핑, ghost는 뒤에 비활성화), park(매핑 없음). 결함 d1~d4는 py, o1은 other(kim 매핑).
 */
final class DefectUpdateTest extends TestCase
{
    private App $app;
    private int $py;
    private int $other;
    /** @var array<string, int> */
    private array $u = [];
    /** @var array<string, int> */
    private array $d = [];

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('defupd'), ['ui' => ['bulk_update_max' => '5']]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'ghost' => 'user', 'park' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->py = TestDb::project($db, 'py', 'python');
        $this->other = TestDb::project($db, 'other', 'python');
        $actor = Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null);
        foreach (['kim', 'lee', 'ghost'] as $login) {
            $this->app->projectAccess()->add($actor, $this->py, ['subject_type' => 'user', 'login_id' => $login]);
        }
        $this->app->projectAccess()->add($actor, $this->other, ['subject_type' => 'user', 'login_id' => 'kim']);
        $a = TestDb::analysis($db, $this->py, $this->u['kim']);
        foreach (['d1', 'd2', 'd3', 'd4'] as $i => $k) {
            $this->d[$k] = TestDb::defect($db, $this->py, $a, ['line_no' => $i + 1, 'message' => "m{$i}"]);
        }
        $oa = TestDb::analysis($db, $this->other, $this->u['kim']);
        $this->d['o1'] = TestDb::defect($db, $this->other, $oa);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) $this->app->db()->one('SELECT * FROM defects WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    private function history(int $id): array
    {
        return $this->app->db()->all('SELECT field, old_value, new_value, changed_by, changed_at, kind, reason_code, memo, analysis_id FROM defect_history WHERE defect_id = ? ORDER BY id', [$id]);
    }

    private function patch(TestClient $c, int $id, array $body): \PJGuardian\Http\Response
    {
        return $c->patch("/api/v1/defects/{$id}", $body);
    }

    // ---- 단건 변경과 이력 ----

    public function testUpdateWritesOneHistoryRowPerChangedField(): void
    {
        $kim = $this->as('kim');
        $d1 = $this->d['d1'];
        $r = $this->patch($kim, $d1, ['version' => 1, 'severity' => 'high', 'status' => 'in_progress', 'assignee_id' => $this->u['lee'], 'memo' => '  <b>재현됨</b> 확인  ']);
        $this->assertSame(200, $r->status, $r->body);
        $data = $r->decoded()['data'];
        $this->assertSame([2, 'high', 'in_progress', $this->u['lee'], true, true], [$data['version'], $data['severity'], $data['status'], $data['assignee']['id'], $data['assignee']['active'], $data['changed']]);
        $h = $this->history($d1);
        $this->assertSame(3, count($h), '필드별 한 행');
        $byField = array_column($h, null, 'field');
        $this->assertSame(['not_defined', 'high'], [$byField['severity']['old_value'], $byField['severity']['new_value']]);
        $this->assertSame(['open', 'in_progress'], [$byField['status']['old_value'], $byField['status']['new_value']]);
        $this->assertSame([null, (string) $this->u['lee']], [$byField['assignee']['old_value'], $byField['assignee']['new_value']]);
        foreach ($h as $row) {
            $this->assertSame(['manual', $this->u['kim'], null, null, '<b>재현됨</b> 확인'], [$row['kind'], (int) $row['changed_by'], $row['reason_code'], $row['analysis_id'], $row['memo']], '구분·변경자·사유(앞뒤 공백 제거, 원문 그대로 저장)');
            $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $row['changed_at']), 'UTC ISO 시각');
        }
        // 이력 API도 같은 내용(최신순), 사유는 텍스트 그대로
        $api = $kim->get("/api/v1/defects/{$d1}/history")->decoded()['data'];
        $this->assertSame('<b>재현됨</b> 확인', $api[0]['memo']);
        $this->assertSame('kim', $api[0]['changed_by']['login_id']);
    }

    public function testSameValuesChangeNeitherHistoryNorVersion(): void
    {
        $kim = $this->as('kim');
        $d1 = $this->d['d1'];
        $r = $this->patch($kim, $d1, ['version' => 1, 'severity' => 'not_defined', 'status' => 'open', 'assignee_id' => null, 'memo' => '그대로']);
        $this->assertSame([200, false, 1], [$r->status, $r->decoded()['data']['changed'], $r->decoded()['data']['version']]);
        $this->assertSame([], $this->history($d1));
        $this->assertSame(1, (int) $this->row($d1)['version']);
        // 하나만 다르면 그 필드만 이력, version + 1
        $r = $this->patch($kim, $d1, ['version' => 1, 'severity' => 'not_defined', 'status' => 'postponed']);
        $this->assertSame([2, 1], [$r->decoded()['data']['version'], count($this->history($d1))]);
        $this->assertSame('status', $this->history($d1)[0]['field']);
    }

    public function testAllThirtyStatusTransitionsAreAllowedAndRecorded(): void
    {
        $kim = $this->as('kim');
        $id = $this->d['d2'];
        $statuses = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'];
        $done = 0;
        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if ($from === $to) {
                    continue;
                }
                $v = (int) $this->row($id)['version'];
                if ($this->row($id)['status'] !== $from) {
                    $this->assertSame(200, $this->patch($kim, $id, ['version' => $v, 'status' => $from])->status);
                    $v++;
                }
                $r = $this->patch($kim, $id, ['version' => $v, 'status' => $to]);
                $this->assertSame([200, $to, $v + 1], [$r->status, $r->decoded()['data']['status'] ?? null, $r->decoded()['data']['version'] ?? null], "{$from} → {$to}");
                $last = $this->history($id)[count($this->history($id)) - 1];
                $this->assertSame(['status', $from, $to, 'manual'], [$last['field'], $last['old_value'], $last['new_value'], $last['kind']], "{$from} → {$to} 이력");
                $done++;
            }
        }
        $this->assertSame(30, $done);
        $this->assertSame((int) $this->row($id)['version'] - 1, count($this->history($id)), '변경마다 이력 한 행, version도 같은 수만큼');
    }

    // ---- 낙관적 잠금 ----

    public function testVersionConflictReturns409WithLatestValues(): void
    {
        $kim = $this->as('kim');
        $lee = $this->as('lee');
        $d1 = $this->d['d1'];
        $this->assertSame(1, $kim->get("/api/v1/defects/{$d1}")->decoded()['data']['version']);
        $this->assertSame(1, $lee->get("/api/v1/defects/{$d1}")->decoded()['data']['version']);
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => 1, 'status' => 'intentional', 'memo' => 'kim'])->status);
        $r = $this->patch($lee, $d1, ['version' => 1, 'status' => 'not_fix', 'severity' => 'low', 'memo' => 'lee']);
        $this->assertSame([409, 'version_conflict'], [$r->status, $r->decoded()['error']['code']]);
        $cur = $r->decoded()['error']['details']['current'];
        $this->assertSame([2, 'intentional', 'not_defined', $d1], [$cur['version'], $cur['status'], $cur['severity'], $cur['id']], '최신 값');
        $this->assertSame(['intentional', 'not_defined', 2], [$this->row($d1)['status'], $this->row($d1)['severity'], (int) $this->row($d1)['version']], 'lee의 값은 반영되지 않음');
        $this->assertSame(['kim'], array_column($this->history($d1), 'memo'));
        // 최신 version으로 다시 시도하면 성공
        $this->assertSame(200, $this->patch($lee, $d1, ['version' => 2, 'status' => 'not_fix'])->status);
    }

    /** 실제 DB 연결 2개: 둘 다 version 1을 읽은 뒤 순서대로 저장 → 두 번째는 409 */
    public function testTwoConnectionsSecondWriterGetsConflict(): void
    {
        [$file, $defect, $user] = $this->fileDb();
        [$s1] = $this->service($file);
        [$s2] = $this->service($file);
        $actor = ['id' => $user, 'role' => 'user', 'status' => 'active'];
        $this->assertSame(1, $s1->current($defect)['version']);
        $this->assertSame(1, $s2->current($defect)['version']);
        $this->assertSame(2, $s1->update($actor, $defect, ['version' => 1, 'status' => 'resolved'])['version']);
        $e = $this->assertThrows(ApiException::class, fn () => $s2->update($actor, $defect, ['version' => 1, 'severity' => 'high']));
        $this->assertSame([409, 'version_conflict', 2, 'resolved'], [$e->status, $e->errorCode, $e->details['current']['version'], $e->details['current']['status']]);
        $db = new Db($file, 2000);
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM defect_history WHERE defect_id = ?', [$defect]));
        $this->assertSame('not_defined', (string) $db->value('SELECT severity FROM defects WHERE id = ?', [$defect]));
    }

    /** 프로세스 2개가 같은 version으로 동시에 저장(출발 신호로 맞춤). 20번 반복해 매번 정확히 하나만 성공해야 한다 */
    public function testTwoProcessesRaceExactlyOneWinsRepeatedly(): void
    {
        [$file, $defect, $user] = $this->fileDb();
        $db = new Db($file, 2000);
        $dir = $this->tempDir('race');
        $rounds = 20;
        $stats = ['both_started' => 0];
        for ($round = 1; $round <= $rounds; $round++) {
            $version = (int) $db->value('SELECT version FROM defects WHERE id = ?', [$defect]);
            $go = "{$dir}/go{$round}";
            $procs = [];
            foreach (['a' => 'in_progress', 'b' => 'postponed'] as $who => $status) {
                $procs[$who] = proc_open([PHP_BINARY, PJG_ROOT . '/tests/Support/defect_update_race.php', $file, (string) $defect, (string) $version, (string) $user, $round % 2 === 0 ? $status : ($who === 'a' ? 'not_fix' : 'intentional'), $go, "{$dir}/{$who}{$round}.json"],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                fclose($pipes[0]);
            }
            usleep(400_000); // 두 자식이 DB를 열고 기다리게
            touch($go);
            $out = [];
            foreach ($procs as $who => $p) {
                while (($s = proc_get_status($p)) && $s['running']) {
                    usleep(5000);
                }
                proc_close($p);
                $out[$who] = json_decode((string) @file_get_contents("{$dir}/{$who}{$round}.json"), true);
                $this->assertTrue(is_array($out[$who]), "round {$round} {$who} 결과 없음");
            }
            $oks = array_values(array_filter($out, static fn (array $r): bool => $r['ok']));
            $conflicts = array_values(array_filter($out, static fn (array $r): bool => !$r['ok']));
            $this->assertSame([1, 1], [count($oks), count($conflicts)], "round {$round}: " . json_encode($out));
            $this->assertSame('version_conflict', $conflicts[0]['code']);
            $this->assertSame($version + 1, $oks[0]['version']);
            $this->assertSame($version + 1, $conflicts[0]['current_version'], '충돌 쪽은 이긴 쪽이 저장한 최신 version을 받는다');
            $this->assertSame($version + 1, (int) $db->value('SELECT version FROM defects WHERE id = ?', [$defect]));
        }
        $this->assertSame($rounds, (int) $db->value('SELECT COUNT(*) FROM defect_history WHERE defect_id = ?', [$defect]), '라운드마다 이력 정확히 한 행');
    }

    /** @return array{0: string, 1: int, 2: int} 파일 DB, 결함 id, 사용자 id */
    private function fileDb(): array
    {
        $file = $this->tempDir('fdb') . '/race.sqlite';
        $db = TestDb::migrated($file);
        $user = TestDb::user($db, 'kim', 'user');
        $p = TestDb::project($db, 'p', 'python');
        $a = TestDb::analysis($db, $p, $user);
        $defect = TestDb::defect($db, $p, $a);
        unset($db);
        return [$file, $defect, $user];
    }

    /** @return array{0: DefectUpdate, 1: EventDispatcher} */
    private function service(string $file): array
    {
        $db = new Db($file, 5000);
        $clock = new FixedClock(TestDb::T0);
        $logger = new Logger($this->tempDir('svc'), $clock, 'ERROR');
        $events = new EventDispatcher($logger);
        return [new DefectUpdate($db, $clock, new ProjectAccess($db, $clock, new AuditLog($db, $clock), $logger), $events, new DefectQuery($db), 500), $events];
    }

    // ---- 담당자 ----

    public function testAssigneeRulesAndInactiveDisplay(): void
    {
        $kim = $this->as('kim');
        $d1 = $this->d['d1'];
        $v = 1;
        $r = $this->patch($kim, $d1, ['version' => $v++, 'assignee_id' => $this->u['ghost']]);
        $this->assertSame(200, $r->status, $r->body);
        // 담당 중인 사용자가 비활성화되어도 담당자는 유지되고 비활성으로 표시
        $this->app->db()->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->u['ghost']]);
        $d = $kim->get("/api/v1/defects/{$d1}")->decoded()['data'];
        $this->assertSame([$this->u['ghost'], false], [$d['assignee']['id'], $d['assignee']['active']]);
        $this->assertContains('data-inactive', $kim->get("/defects/{$d1}")->body);
        // 다른 필드만 바꾸는 것은 비활성 담당자가 있어도 된다(담당자 필드를 보내지 않음)
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => $v++, 'severity' => 'low'])->status);
        $this->assertSame($this->u['ghost'], (int) $this->row($d1)['assignee_id']);
        // 새로 지정: 비활성·없는 사용자·접근 권한 없는 사용자는 거부
        foreach ([$this->u['ghost'], 999999, $this->u['park'], 'abc', 0, -3] as $bad) {
            $r = $this->patch($kim, $d1, ['version' => $v, 'assignee_id' => $bad]);
            $this->assertSame([400, 'invalid_assignee'], [$r->status, $r->decoded()['error']['code'] ?? null], (string) json_encode($bad));
        }
        // admin은 매핑 없이도 접근하므로 지정 가능, 해제(null)도 가능
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => $v++, 'assignee_id' => $this->u['admin']])->status);
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => $v++, 'assignee_id' => null])->status);
        $this->assertSame(null, $this->row($d1)['assignee_id']);
        $last = $this->history($d1)[count($this->history($d1)) - 1];
        $this->assertSame([(string) $this->u['admin'], null], [$last['old_value'], $last['new_value']]);
        // 담당자가 접근을 잃으면 담당은 유지하고 "접근 권한 없음" 표시
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => $v++, 'assignee_id' => $this->u['lee']])->status);
        $this->app->db()->execute("DELETE FROM project_access WHERE subject_type = 'user' AND subject_id = ?", [$this->u['lee']]);
        $d = $kim->get("/api/v1/defects/{$d1}")->decoded()['data'];
        $this->assertSame([$this->u['lee'], true, false], [$d['assignee']['id'], $d['assignee']['active'], $d['assignee']['has_access']]);
        $this->assertContains('data-no-access', $kim->get("/defects/{$d1}")->body);
        $list = $kim->get("/api/v1/projects/{$this->py}/defects", ['assignee' => (string) $this->u['lee']])->decoded()['data'];
        $this->assertSame(false, $list[0]['assignee']['has_access']);
    }

    public function testAssigneeCandidates(): void
    {
        $this->app->db()->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->u['ghost']]);
        $kim = $this->as('kim');
        $r = $kim->get("/api/v1/projects/{$this->py}/assignees");
        $this->assertSame(200, $r->status, $r->body);
        $logins = array_column($r->decoded()['data'], 'login_id');
        sort($logins);
        $this->assertSame(['admin', 'kim', 'lee'], $logins, '접근 가능한 활성 사용자만(비활성 ghost, 매핑 없는 park 제외)');
        $this->assertSame(['display_name', 'id', 'login_id'], (static function (array $k): array { sort($k); return $k; })(array_keys($r->decoded()['data'][0])), '이메일·역할 없음');
        $this->assertSame(['lee'], array_column($kim->get("/api/v1/projects/{$this->py}/assignees", ['q' => 'LE'])->decoded()['data'], 'login_id'));
        $paged = $kim->get("/api/v1/projects/{$this->py}/assignees", ['size' => '2', 'page' => '2'])->decoded();
        $this->assertSame([3, 1], [$paged['page']['total'], count($paged['data'])]);
        $pr = $this->as('park')->get("/api/v1/projects/{$this->py}/assignees");
        $this->assertSame([404, 'project_not_found'], [$pr->status, $pr->decoded()['error']['code']]);
        $this->assertSame(400, $kim->get("/api/v1/projects/{$this->py}/assignees", ['q' => str_repeat('a', 101)])->status);
    }

    // ---- 검증·권한 ----

    public function testValidation(): void
    {
        $kim = $this->as('kim');
        $d1 = $this->d['d1'];
        $cases = [
            [['status' => 'open'], 'invalid_version'],
            [['version' => 'abc', 'status' => 'open'], 'invalid_version'],
            [['version' => 0, 'status' => 'open'], 'invalid_version'],
            [['version' => '1', 'status' => 'open'], 'invalid_version'],
            [['version' => 1, 'severity' => 'urgent'], 'invalid_severity'],
            [['version' => 1, 'severity' => null], 'invalid_severity'],
            [['version' => 1, 'status' => 'closed'], 'invalid_status'],
            [['version' => 1, 'status' => 'open', 'memo' => str_repeat('가', 1001)], 'invalid_memo'],
            [['version' => 1, 'status' => 'open', 'memo' => "a\x00b"], 'invalid_memo'],
            [['version' => 1, 'status' => 'open', 'memo' => 5], 'invalid_memo'],
            [['version' => 1], 'no_change_fields'],
            [['version' => 1, 'memo' => '사유만'], 'no_change_fields'],
        ];
        foreach ($cases as [$body, $code]) {
            $r = $this->patch($kim, $d1, $body);
            $this->assertSame([400, $code], [$r->status, $r->decoded()['error']['code'] ?? null], json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(1, (int) $this->row($d1)['version']);
        $this->assertSame([], $this->history($d1));
        // 사유 1000자(경계), 줄바꿈 허용
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => 1, 'status' => 'resolved', 'memo' => str_repeat('가', 999) . "\n"])->status);
    }

    public function testAccessPermissionsAndCsrf(): void
    {
        $d1 = $this->d['d1'];
        $park = $this->as('park');
        $hidden = $this->patch($park, $d1, ['version' => 1, 'status' => 'resolved']);
        $missing = $this->patch($park, 999999, ['version' => 1, 'status' => 'resolved']);
        $this->assertSame([404, 'defect_not_found'], [$hidden->status, $hidden->decoded()['error']['code']], '접근 불가는 없는 결함과 같은 404(권한 검사보다 먼저)');
        $this->assertSame($missing->decoded()['error']['message'], $hidden->decoded()['error']['message']);
        $anon = new TestClient($this->app);
        $anon->fetchCsrf();
        $this->assertSame(401, $this->patch($anon, $d1, ['version' => 1, 'status' => 'resolved'])->status, '비로그인 401');
        $kim = $this->as('kim');
        $noCsrf = $kim->request('PATCH', "/api/v1/defects/{$d1}", [], ['version' => 1, 'status' => 'resolved'], null, [], false);
        $this->assertSame([403, 'csrf_invalid'], [$noCsrf->status, $noCsrf->decoded()['error']['code']]);
        $this->assertSame(1, (int) $this->row($d1)['version']);
        // 관리자 제한 없음: 일반 사용자 kim, admin 모두 가능
        $this->assertSame(200, $this->patch($kim, $d1, ['version' => 1, 'status' => 'resolved'])->status);
        $this->assertSame(200, $this->patch($this->as('admin'), $d1, ['version' => 2, 'status' => 'open'])->status);
        foreach (["/api/v1/projects/{$this->py}/defects/bulk", "/api/v1/projects/{$this->py}/assignees"] as $path) {
            $this->assertSame(404, ($path === "/api/v1/projects/{$this->py}/assignees" ? $park->get($path) : $park->post($path, ['items' => [['id' => $d1, 'version' => 1]], 'changes' => ['status' => 'open']]))->status, "park {$path}");
        }
    }

    // ---- 일괄 변경 ----

    public function testBulkPartialSuccessPerItemResults(): void
    {
        $kim = $this->as('kim');
        ['d1' => $d1, 'd2' => $d2, 'd3' => $d3, 'd4' => $d4] = $this->d;
        $this->assertSame(200, $this->patch($kim, $d2, ['version' => 1, 'severity' => 'low'])->status); // d2는 version 2가 됨
        $this->app->db()->execute("UPDATE defects SET status = 'intentional', version = version WHERE id = ?", [$d3]); // d3은 이미 목표 값(이력 없이 시험 준비)
        $r = $kim->post("/api/v1/projects/{$this->py}/defects/bulk", [
            'items' => [['id' => $d1, 'version' => 1], ['id' => $d2, 'version' => 1], ['id' => $d3, 'version' => 1], ['id' => $d4, 'version' => 1]],
            'changes' => ['status' => 'intentional', 'assignee_id' => $this->u['lee']],
            'memo' => '일괄',
        ]);
        $this->assertSame(200, $r->status, $r->body);
        $data = $r->decoded()['data'];
        $this->assertSame([[$d1, 2], [$d3, 2], [$d4, 2]], array_map(static fn (array $x): array => [$x['id'], $x['version']], $data['updated']), '성공 목록(새 version)');
        $this->assertSame([$d2], array_column($data['conflicts'], 'id'), '충돌 목록');
        $this->assertSame([2, 'open', 'low'], [$data['conflicts'][0]['current']['version'], $data['conflicts'][0]['current']['status'], $data['conflicts'][0]['current']['severity']], '충돌 항목의 최신 값');
        $this->assertSame([], $data['unchanged']);
        $this->assertSame(['open', null], [$this->row($d2)['status'], $this->row($d2)['assignee_id']], '충돌 항목은 바뀌지 않음');
        $this->assertSame(['status', 'assignee'], array_column($this->history($d1), 'field'), 'd1: 필드별 이력');
        $this->assertSame(['assignee'], array_column($this->history($d3), 'field'), 'd3: 상태는 이미 같아 담당자만');
        $this->assertSame(['일괄'], array_unique(array_column($this->history($d4), 'memo')));
        // 모두 같은 값이면 unchanged
        $r2 = $kim->post("/api/v1/projects/{$this->py}/defects/bulk", ['items' => [['id' => $d1, 'version' => 2]], 'changes' => ['status' => 'intentional']])->decoded()['data'];
        $this->assertSame([[], [$d1], []], [$r2['updated'], $r2['unchanged'], $r2['conflicts']]);
    }

    public function testBulkValidationRejectsWholeRequest(): void
    {
        $kim = $this->as('kim');
        ['d1' => $d1, 'd2' => $d2, 'o1' => $o1] = $this->d;
        $bad = [
            [['items' => [['id' => $d1, 'version' => 1], ['id' => $o1, 'version' => 1]], 'changes' => ['status' => 'resolved']], 'defect_not_in_project'],
            [['items' => [['id' => $d1, 'version' => 1], ['id' => 999999, 'version' => 1]], 'changes' => ['status' => 'resolved']], 'defect_not_in_project'],
            [['items' => [], 'changes' => ['status' => 'resolved']], 'invalid_bulk'],
            [['items' => [['id' => $d1, 'version' => 1], ['id' => $d1, 'version' => 1]], 'changes' => ['status' => 'resolved']], 'invalid_bulk'],
            [['items' => [['id' => $d1]], 'changes' => ['status' => 'resolved']], 'invalid_version'],
            [['items' => [['id' => 'x', 'version' => 1]], 'changes' => ['status' => 'resolved']], 'invalid_bulk'],
            [['items' => [['id' => $d1, 'version' => 1]], 'changes' => []], 'no_change_fields'],
            [['items' => [['id' => $d1, 'version' => 1]], 'changes' => ['status' => 'gone']], 'invalid_status'],
            [['items' => [['id' => $d1, 'version' => 1]], 'changes' => ['assignee_id' => $this->u['park']]], 'invalid_assignee'],
            [['items' => array_map(static fn (int $i): array => ['id' => $i, 'version' => 1], range(1, 6)), 'changes' => ['status' => 'resolved']], 'bulk_too_large'],
        ];
        foreach ($bad as [$body, $code]) {
            $r = $kim->post("/api/v1/projects/{$this->py}/defects/bulk", $body);
            $this->assertSame([400, $code], [$r->status, $r->decoded()['error']['code'] ?? null], json_encode($body));
        }
        foreach ([$d1, $d2, $o1] as $id) {
            $this->assertSame([1, 'open'], [(int) $this->row($id)['version'], $this->row($id)['status']], '하나도 바뀌지 않음');
        }
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM defect_history'));
        $this->assertSame(403, $kim->request('POST', "/api/v1/projects/{$this->py}/defects/bulk", [], ['items' => [['id' => $d1, 'version' => 1]], 'changes' => ['status' => 'resolved']], null, [], false)->status, 'CSRF');
    }

    // ---- 이벤트 ----

    public function testAssignedAndStatusEventsArePublishedAfterCommit(): void
    {
        $got = [];
        $this->app->events()->subscribe(DefectAssigned::class, static function (DefectAssigned $e) use (&$got): void { $got[] = ['assigned', $e->defectId, $e->assigneeId, $e->previousAssigneeId, $e->changedBy]; });
        $this->app->events()->subscribe(DefectStatusChanged::class, static function (DefectStatusChanged $e) use (&$got): void { $got[] = ['status', $e->defectId, $e->from, $e->to]; });
        $kim = $this->as('kim');
        $d1 = $this->d['d1'];
        $this->patch($kim, $d1, ['version' => 1, 'assignee_id' => $this->u['lee'], 'status' => 'in_progress']);
        $this->patch($kim, $d1, ['version' => 2, 'severity' => 'high']); // 이벤트 없음
        $this->patch($kim, $d1, ['version' => 3, 'assignee_id' => null]); // 해제는 assigned 아님
        $this->patch($kim, $d1, ['version' => 1, 'assignee_id' => $this->u['kim']]); // 충돌: 이벤트 없음
        $kim->post("/api/v1/projects/{$this->py}/defects/bulk", ['items' => [['id' => $this->d['d2'], 'version' => 1], ['id' => $this->d['d3'], 'version' => 1]], 'changes' => ['assignee_id' => $this->u['kim']]]);
        $this->assertSame([
            ['assigned', $d1, $this->u['lee'], null, $this->u['kim']],
            ['status', $d1, 'open', 'in_progress'],
            ['assigned', $this->d['d2'], $this->u['kim'], null, $this->u['kim']],
            ['assigned', $this->d['d3'], $this->u['kim'], null, $this->u['kim']],
        ], $got);
    }

    // ---- 내게 지정된 결함 ----

    public function testMyDefectsListsOnlyMineInVisibleProjects(): void
    {
        $db = $this->app->db();
        $kim = $this->u['kim'];
        foreach (['d1' => 'open', 'd2' => 'in_progress', 'd3' => 'resolved', 'o1' => 'open'] as $k => $st) {
            $db->execute('UPDATE defects SET assignee_id = ?, status = ? WHERE id = ?', [$kim, $st, $this->d[$k]]);
        }
        $db->execute('UPDATE defects SET assignee_id = ? WHERE id = ?', [$this->u['lee'], $this->d['d4']]);
        $c = $this->as('kim');
        $r = $c->get('/api/v1/my-defects');
        $this->assertSame(200, $r->status, $r->body);
        $ids = array_column($r->decoded()['data'], 'id');
        sort($ids);
        $expect = [$this->d['d1'], $this->d['d2'], $this->d['d3'], $this->d['o1']];
        sort($expect);
        $this->assertSame($expect, $ids);
        $names = array_column(array_map(static fn (array $x): array => ['id' => $x['id'], 'p' => $x['project']['name']], $r->decoded()['data']), 'p', 'id');
        $this->assertSame(['py', 'other'], [$names[$this->d['d1']], $names[$this->d['o1']]], '항목마다 프로젝트 이름');
        $s = $c->get('/api/v1/my-defects/summary')->decoded()['data'];
        $this->assertSame(['open' => 2, 'in_progress' => 1, 'postponed' => 0, 'intentional' => 0, 'not_fix' => 0, 'resolved' => 1], $s['status']);
        $this->assertSame([[$this->py, 'py', 3], [$this->other, 'other', 1]], array_map(static fn (array $p): array => [$p['id'], $p['name'], $p['count']], $s['projects']));
        $this->assertSame([$this->d['o1']], array_column($c->get('/api/v1/my-defects', ['project' => (string) $this->other])->decoded()['data'], 'id'));
        $this->assertSame([$this->d['d2']], array_column($c->get('/api/v1/my-defects', ['status' => 'in_progress'])->decoded()['data'], 'id'));
        $this->assertSame(400, $c->get('/api/v1/my-defects', ['status' => 'bogus'])->status);
        // 프로젝트 접근을 잃으면 그 프로젝트의 담당 결함은 보이지 않는다
        $db->execute("DELETE FROM project_access WHERE project_id = ? AND subject_type = 'user' AND subject_id = ?", [$this->other, $kim]);
        $this->assertSame(3, $c->get('/api/v1/my-defects')->decoded()['page']['total']);
        $this->assertSame(0, $c->get('/api/v1/my-defects', ['project' => (string) $this->other])->decoded()['page']['total'], '보이지 않는 프로젝트를 지정해도 0건');
        // 화면
        $page = $c->get('/my-defects');
        $this->assertSame(200, $page->status);
        $this->assertContains('data-my-defects', $page->body);
        $this->assertContains('href="/my-defects"', $page->body, '주 메뉴');
        $this->assertContains('지정된 결함이 없습니다', $this->as('park')->get('/my-defects')->body, '빈 상태');
        $this->assertSame(401, (new TestClient($this->app))->get('/api/v1/my-defects')->status);
    }

    public function testDetailPageHasEditControlsAndListHasBulkSelection(): void
    {
        $kim = $this->as('kim');
        $html = $kim->get("/defects/{$this->d['d1']}")->body;
        $this->assertContains('data-edit-form', $html);
        $this->assertContains('name="severity"', $html);
        $this->assertContains('name="status"', $html);
        $this->assertContains('name="memo"', $html);
        $this->assertContains('data-version="1"', $html);
        $this->assertContains('js/pages/defect_detail.js', $html);
        $list = $kim->get("/projects/{$this->py}")->body;
        $this->assertContains('data-bulk-bar', $list);
        $this->assertContains('data-select-page', $list);
    }

    // ---- 분석과의 연계(실제 ruff) ----

    public function testAutoResolveAfterReadMakesUserSaveConflictAndManualResolveIsReopened(): void
    {
        $h = new DefectHarness($this->tempDir('updh'));
        if ($h->tool('ruff') === null) {
            echo "  (건너뜀: bin/ruff 없음)\n";
            return;
        }
        $db = $h->app->db();
        $pid = $h->project('re', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', 1, ?)", [$pid, TestDb::T0]);
        $dir = $h->copySample('python_sample');
        $h->analyze($pid, 'ruff', $dir);
        $kim = new TestClient($h->app);
        $this->assertSame(200, $kim->login('kim', 'Passw0rd!x')->status);
        // 1) 사용자가 조회한 뒤 분석이 자동 resolved로 바꾸면 사용자의 저장은 409
        $target = (int) $db->value("SELECT id FROM defects WHERE project_id = ? AND file_path = 'main.py' ORDER BY id LIMIT 1", [$pid]);
        $seen = $kim->get("/api/v1/defects/{$target}")->decoded()['data'];
        $orig = (string) file_get_contents("{$dir}/main.py");
        unlink("{$dir}/main.py");
        $h->analyze($pid, 'ruff', $dir);
        $r = $kim->patch("/api/v1/defects/{$target}", ['version' => $seen['version'], 'status' => 'in_progress', 'memo' => '작업 시작']);
        $this->assertSame([409, 'version_conflict', 'resolved', $seen['version'] + 1], [$r->status, $r->decoded()['error']['code'] ?? null,
            $r->decoded()['error']['details']['current']['status'] ?? null, $r->decoded()['error']['details']['current']['version'] ?? null], '자동 변경도 version을 올린다');
        // 2) 수동으로 resolved로 바꾼 결함이 계속 감지되면 다음 분석에서 open으로 재오픈
        file_put_contents("{$dir}/main.py", $orig);
        $h->analyze($pid, 'ruff', $dir); // main.py 결함 재오픈
        $manual = (int) $db->value("SELECT id FROM defects WHERE project_id = ? AND file_path = 'config_loader.py' ORDER BY id LIMIT 1", [$pid]);
        $v = (int) $db->value('SELECT version FROM defects WHERE id = ?', [$manual]);
        $this->assertSame(200, $kim->patch("/api/v1/defects/{$manual}", ['version' => $v, 'status' => 'resolved', 'memo' => '고친 줄 앎'])->status);
        $h->analyze($pid, 'ruff', $dir);
        $row = $db->one('SELECT status, version FROM defects WHERE id = ?', [$manual]);
        $this->assertSame(['open', $v + 2], [$row['status'], (int) $row['version']], '수동 resolved(+1) → 재감지 재오픈(+1)');
        $hist = $db->all('SELECT kind, old_value, new_value, memo FROM defect_history WHERE defect_id = ? ORDER BY id', [$manual]);
        $this->assertSame([['manual', 'open', 'resolved', '고친 줄 앎'], ['auto', 'resolved', 'open', null]], array_map('array_values', $hist));
    }
}
