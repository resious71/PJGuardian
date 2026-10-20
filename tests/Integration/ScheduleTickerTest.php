<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Events\AnalysisCancelled;
use PJGuardian\Services\Events\AnalysisFailed;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Config;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 스케줄러·결과 반영·재시도(P26, 명세 18.2·18.3·18.5) — 시계 주입(FixedClock), 실제 시간을 기다리지 않는다.
 * - 도래 → 분석 등록(출처 git, 실행 주체 schedule, 요청자 NULL, 같은 큐 등록 함수), 다음 시각·도래 기록, 같은 도래 두 번 없음
 * - 이미 queued/running → skipped_busy, 디스크 차단 → skipped_disk(+ 관리자 알림, 실패 횟수 아님), 자격 증명을 읽을 수 없음 → skipped_no_connection
 * - 워커가 꺼져 밀린 일정 → 가장 최근 1회만, 다음은 현재 이후
 * - 두 프로세스가 동시에 틱 → 프로젝트마다 한 번만 등록
 * - 결과: 연속 실패 증감(사용자 "지금 가져와 분석" 성공도 0으로), schedule_failed는 활성 admin에게만·연속 실패는 한 건에 횟수 갱신(읽음 유지),
 *   이벤트 중복에도 한 번, 완료 알림 없음, analysis_summary는 담당자에게(P24 그대로), 취소는 실패로 세지 않음
 * - 재시도: 같은 행 queued + retry_after, 그동안 다른 작업 선점(FIFO 막히지 않음), 시각이 지나면 원래 순서로, 3번째는 마감, 재시도 불가 오류·사용자 실행은 재시도 없음
 * - 019 마이그레이션: 기존 알림 id·순번 보존
 */
final class ScheduleTickerTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private App $app;
    private FixedClock $clock;
    private int $p;
    /** @var array<string, int> */
    private array $u = [];
    private float $usedRatio = 0.5;

    public function setUp(): void
    {
        $root = $this->tempDir('schedtick');
        mkdir($root . '/work');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work']], ['encryption_key' => self::KEY]);
        $this->clock = $this->app->clock;
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'admin2' => 'admin', 'kim' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, 'sched', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$this->p, $this->u['kim']]);
        $db->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, '', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, fn (): float => 1e12 * (1 - $this->usedRatio), static fn (): float => 1e12));
        $this->connect($this->p);
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], '127.0.0.1');
    }

    private function connect(int $pid): void
    {
        $this->app->repoConnections()->save($this->actor(), $this->u['admin'], $pid, ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'branch' => 'main', 'secret' => 'tok-1234']);
    }

    private function enable(int $pid, int $days = 7, string $time = '03:00'): void
    {
        $rev = $this->app->schedules()->row($pid)['revision'] ?? null;
        $this->app->schedules()->save($this->actor(), $this->u['admin'], $pid, ['enabled' => true, 'interval_days' => $days, 'run_time' => $time] + ($rev === null ? [] : ['revision' => (int) $rev]));
    }

    /** @return array<string, mixed> */
    private function sched(int $pid): array
    {
        return $this->app->db()->one('SELECT * FROM project_schedules WHERE project_id = ?', [$pid]);
    }

    private function to(string $iso): void
    {
        $this->clock->advance(strtotime($iso) - $this->clock->now()->getTimestamp());
    }

    /** @return list<array<string, mixed>> */
    private function notifs(string $login): array
    {
        return $this->app->db()->all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id', [$this->u[$login]]);
    }

    public function testDueQueuesScheduledAnalysisOnce(): void
    {
        $this->enable($this->p); // 지금 KST 10-02 21:00 → 다음 KST 10-03 03:00 = UTC 10-02 18:00
        $ticker = $this->app->scheduleTicker();
        $this->assertSame(0, $ticker->tick()['due'], '아직 도래 전');
        $this->to('2026-10-02T18:00:40Z');
        $r = $ticker->tick();
        $this->assertSame([1, 1, 0], [$r['due'], $r['queued'], $r['skipped']]);
        $a = $this->app->db()->one('SELECT * FROM analyses WHERE project_id = ?', [$this->p]);
        $this->assertSame(['queued', 'git', 'main', 'schedule', null, 'https://ghe.corp/team/app.git', 'ruff', 0, null],
            [$a['status'], $a['source_kind'], $a['source_ref'], $a['trigger_kind'], $a['requested_by'], $a['source_url'], $a['tool'], (int) $a['retry_count'], $a['retry_after']]);
        $s = $this->sched($this->p);
        $this->assertSame(['2026-10-09T18:00:00Z', '2026-10-02T18:00:00Z', (int) $a['id'], 0], [$s['next_run_at'], $s['last_due_at'], (int) $s['last_analysis_id'], (int) $s['skip_count']]);
        $this->assertSame([['due_at' => '2026-10-02T18:00:00Z', 'result' => 'queued', 'detail' => null, 'analysis_id' => (int) $a['id']]],
            $this->app->db()->all('SELECT due_at, result, detail, analysis_id FROM schedule_ticks WHERE project_id = ?', [$this->p]));
        // 같은 시각에 다시 틱 → 아무것도 하지 않음(다음 도래는 7일 뒤)
        $this->assertSame(0, $ticker->tick()['due']);
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses'));
        // 일반 사용자의 요약: 사용 중·다음 예정
        $this->assertSame(['enabled' => true, 'next_run_at' => '2026-10-09T18:00:00Z', 'interval_days' => 7], $this->app->schedules()->publicSummary($this->p, false));
    }

    public function testBusyDiskAndConnectionSkips(): void
    {
        $this->enable($this->p, 1);
        $ticker = $this->app->scheduleTicker();
        // 1) 이미 대기 중(사용자 업로드) → skipped_busy, 분석을 새로 만들지 않음, 알림 없음
        TestDb::analysis($this->app->db(), $this->p, $this->u['kim'], ['status' => 'queued']);
        $this->to('2026-10-02T18:00:05Z');
        $r = $ticker->tick();
        $this->assertSame([1, 0, 1], [$r['due'], $r['queued'], $r['skipped']]);
        $s = $this->sched($this->p);
        $this->assertSame([1, 'skipped_busy', '2026-10-03T18:00:00Z', 0], [(int) $s['skip_count'], $s['last_skip_reason'], $s['next_run_at'], (int) $s['consecutive_failures']]);
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses'));
        $this->assertSame([], $this->notifs('admin'), '바쁨 건너뜀은 알리지 않음');
        $this->app->db()->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-02T18:10:00Z'");
        // 2) 디스크 사용률 차단 → skipped_disk + 관리자 알림(실패 횟수로 세지 않음), 일반 사용자 알림 없음
        $this->usedRatio = 0.95;
        $this->to('2026-10-03T18:00:05Z');
        $r = $ticker->tick();
        $this->assertSame([1, 0, 1], [$r['due'], $r['queued'], $r['skipped']]);
        $this->assertSame(['skipped_disk', 'disk_usage_blocked'], array_values($this->app->db()->one("SELECT result, detail FROM schedule_ticks WHERE due_at = '2026-10-03T18:00:00Z'")));
        $s = $this->sched($this->p);
        $this->assertSame([2, 'disk_usage_blocked', 0], [(int) $s['skip_count'], $s['last_skip_reason'], (int) $s['consecutive_failures']]);
        foreach (['admin', 'admin2'] as $who) {
            $n = $this->notifs($who);
            $this->assertSame(1, count($n), $who);
            $this->assertSame(['schedule_failed', 'schedule_skipped:' . $this->p . ':2026-10-03T18:00:00Z'], [$n[0]['kind'], $n[0]['dedupe_key']]);
            $this->assertSame(['code' => 'disk_usage_blocked', 'skipped' => 'skipped_disk', 'next_run_at' => '2026-10-04T18:00:00Z', 'at' => '2026-10-03T18:00:00Z'], json_decode($n[0]['params'], true));
        }
        $this->assertSame([], $this->notifs('kim'), '일반 사용자에게 schedule_failed 없음');
        $this->usedRatio = 0.5;
        // 3) 자격 증명을 읽을 수 없음(키가 바뀜) → skipped_no_connection(repo_secret_unreadable), 분석 없음
        $other = new App(PJG_ROOT, TestApp::config(['paths' => ['work_dir' => $this->app->paths->work()]], ['encryption_key' => base64_encode(str_repeat('z', 32))]),
            $this->clock, $this->app->logger, $this->app->paths, $this->app->db());
        $other->setDiskGuard($this->app->diskGuard());
        $this->to('2026-10-04T18:00:05Z');
        $r = $other->scheduleTicker()->tick();
        $this->assertSame([1, 0, 1], [$r['due'], $r['queued'], $r['skipped']]);
        $this->assertSame(['skipped_no_connection', 'repo_secret_unreadable'], array_values($this->app->db()->one("SELECT result, detail FROM schedule_ticks WHERE due_at = '2026-10-04T18:00:00Z'")));
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM analyses WHERE trigger_kind = 'schedule'"));
        $this->assertSame('2026-10-05T18:00:00Z', $this->sched($this->p)['next_run_at'], '주기 설정은 유지, 다음 도래로');
    }

    public function testMissedSchedulesRunOnlyOnceAfterWorkerWasDown(): void
    {
        $this->enable($this->p, 7);
        // 워커가 25일 동안 꺼져 있었다(도래 4번 지나감)
        $this->to('2026-10-27T05:00:00Z');
        $r = $this->app->scheduleTicker()->tick();
        $this->assertSame([1, 1], [$r['due'], $r['queued']]);
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM analyses WHERE trigger_kind = 'schedule'"), '밀린 일정은 한 번만');
        $s = $this->sched($this->p);
        $this->assertSame(['2026-10-23T18:00:00Z', '2026-10-30T18:00:00Z'], [$s['last_due_at'], $s['next_run_at']], '가장 최근 도래, 다음은 현재 이후(같은 계열)');
        $this->assertSame(0, $this->app->scheduleTicker()->tick()['due']);
    }

    public function testTwoProcessesTickingTogetherRegisterEachDueOnce(): void
    {
        $dir = $this->tempDir('schedproc');
        foreach (['work', 'logs', 'uploads', 'snapshots'] as $d) {
            mkdir("{$dir}/{$d}");
        }
        $ini = TestApp::writeIni("{$dir}/config.ini", [
            'paths' => ['db_file' => "{$dir}/db.sqlite", 'work_dir' => "{$dir}/work", 'logs_dir' => "{$dir}/logs", 'uploads_dir' => "{$dir}/uploads", 'snapshots_dir' => "{$dir}/snapshots"],
            'disk' => ['block_percent' => '99', 'warn_percent' => '98'], 'extract' => ['max_extracted_size' => '1MB'],
        ]);
        file_put_contents("{$dir}/secrets.ini", 'encryption_key = "' . self::KEY . "\"\n");
        $db = TestDb::migrated("{$dir}/db.sqlite");
        $admin = TestDb::user($db, 'admin', 'admin');
        $db->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, '', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        $app = App::create(PJG_ROOT, Config::load($ini, "{$dir}/secrets.ini"));
        $actor = Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], '127.0.0.1');
        $pids = [];
        for ($i = 0; $i < 20; $i++) {
            $pid = TestDb::project($db, "p{$i}", 'python');
            $app->repoConnections()->save($actor, $admin, $pid, ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'secret' => 'tok']);
            $app->schedules()->save($actor, $admin, $pid, ['enabled' => true, 'interval_days' => 1, 'run_time' => '03:00']);
            $pids[] = $pid;
        }
        for ($round = 0; $round < 3; $round++) {
            // 모두 도래 상태로(실제 시계 기준 과거) → 두 프로세스가 같은 순간에 틱
            $db->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-01T00:00:00Z' WHERE status = 'queued'");
            $past = gmdate('Y-m-d\TH:i:s\Z', time() - 3600 - $round * 7);
            $db->execute('UPDATE project_schedules SET next_run_at = ?', [$past]);
            // P31: 두 자식이 앱을 띄우고 준비 파일을 만든 뒤에 출발 신호(고정 시작 시각은 부하가 크면 한쪽이 늦게 출발)
            $go = "{$dir}/go{$round}";
            $procs = [];
            for ($k = 0; $k < 2; $k++) {
                $procs[] = proc_open([PHP_BINARY, PJG_ROOT . '/tests/Support/schedule_tick_child.php', $ini, "{$dir}/secrets.ini", $go, '1'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k], PJG_ROOT, null, ['bypass_shell' => true]);
                fclose($pipes[$k][0]);
            }
            for ($w = 0; $w < 1200 && count(glob($go . '.ready.*') ?: []) < 2; $w++) {
                usleep(50_000);
            }
            $this->assertSame(2, count(glob($go . '.ready.*') ?: []), "{$round}회차: 두 자식 준비");
            touch($go);
            $queued = 0;
            $outs = [];
            foreach ($procs as $k => $p) {
                $out = (string) stream_get_contents($pipes[$k][1]) . (string) stream_get_contents($pipes[$k][2]);
                proc_close($p);
                $res = json_decode(trim($out), true);
                $this->assertTrue(is_array($res), $out);
                $this->assertFalse(isset($res[0]['error']) && $res[0]['error'] !== null, $out);
                $queued += $res[0]['queued'];
                $outs[] = trim($out);
            }
            $this->assertSame(20, $queued, "{$round}회차: 두 프로세스 합쳐 20개 — 자식 결과 " . implode(' / ', $outs));
            $this->assertSame(20 * ($round + 1), (int) $db->value("SELECT COUNT(*) FROM analyses WHERE trigger_kind = 'schedule'"), '프로젝트마다 한 번');
            $this->assertSame([], $db->all('SELECT project_id, due_at FROM schedule_ticks GROUP BY project_id, due_at HAVING COUNT(*) > 1'));
            $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM (SELECT project_id FROM analyses WHERE status = 'queued' GROUP BY project_id HAVING COUNT(*) > 1)"));
        }
        unset($app);
    }

    public function testOutcomesCountFailuresAndNotifyAdminsOnly(): void
    {
        $db = $this->app->db();
        $events = $this->app->events();
        $this->enable($this->p);
        $sched = fn (string $status, string $code = null) => TestDb::analysis($db, $this->p, 0, ['status' => $status, 'source_kind' => 'git', 'source_ref' => 'main', 'trigger_kind' => 'schedule',
            'requested_by' => null, 'failure_code' => $code] + ($status === 'failed' ? ['total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null] : []));
        // 1) 주기 실패 → 연속 1, admin 둘에게 schedule_failed(일반 사용자 없음, 완료·실패 요청자 알림 없음)
        $a1 = $sched('failed', 'repo_unreachable');
        $db->execute('UPDATE analyses SET retry_count = 2 WHERE id = ?', [$a1]);
        $events->dispatch(new AnalysisFailed($a1, $this->p, 'repo_unreachable'));
        $s = $this->sched($this->p);
        $this->assertSame([1, $a1, 'failed', 'repo_unreachable'], [(int) $s['consecutive_failures'], (int) $s['first_failure_analysis_id'], $s['last_result'], $s['last_error_code']]);
        $n = $this->notifs('admin');
        $this->assertSame(1, count($n));
        $this->assertSame(['schedule_failed', "schedule_failed:{$this->p}:{$a1}", $a1], [$n[0]['kind'], $n[0]['dedupe_key'], (int) $n[0]['analysis_id']]);
        $this->assertSame(['code' => 'repo_unreachable', 'count' => 1, 'attempts' => 3, 'next_run_at' => '2026-10-02T18:00:00Z', 'at' => TestDb::T0], json_decode($n[0]['params'], true));
        $this->assertSame(1, count($this->notifs('admin2')));
        $this->assertSame([], $this->notifs('kim'));
        // 같은 분석 이벤트가 또 와도 한 번
        $events->dispatch(new AnalysisFailed($a1, $this->p, 'repo_unreachable'));
        $this->assertSame(1, (int) $this->sched($this->p)['consecutive_failures']);
        // 2) 읽은 뒤 또 실패 → 같은 알림 한 건에 횟수 2(읽음 유지), 알림이 쌓이지 않음
        $db->execute("UPDATE notifications SET read_at = '2026-10-02T12:30:00Z' WHERE user_id = ?", [$this->u['admin']]);
        $this->clock->advance(3600);
        $a2 = $sched('failed', 'repo_auth_failed');
        $events->dispatch(new AnalysisFailed($a2, $this->p, 'repo_auth_failed'));
        $n = $this->notifs('admin');
        $this->assertSame(1, count($n), '연속 실패는 한 건');
        $this->assertSame([2, 'repo_auth_failed', 1, $a2, '2026-10-02T12:30:00Z', '2026-10-02T13:00:00Z'],
            [json_decode($n[0]['params'], true)['count'], json_decode($n[0]['params'], true)['code'], json_decode($n[0]['params'], true)['attempts'], (int) $n[0]['analysis_id'], $n[0]['read_at'], $n[0]['created_at']]);
        // 3) 사용자 "지금 가져와 분석" 성공 → 연속 0(마지막 결과는 주기 결과 그대로)
        $u1 = TestDb::analysis($db, $this->p, $this->u['admin'], ['source_kind' => 'git', 'source_ref' => 'main']);
        $events->dispatch(new AnalysisSucceeded($u1, $this->p, []));
        $s = $this->sched($this->p);
        $this->assertSame([0, null, 'failed'], [(int) $s['consecutive_failures'], $s['first_failure_analysis_id'], $s['last_result']]);
        // 4) 다시 실패 → 새 연속(새 알림)
        $a3 = $sched('failed', 'repo_timeout');
        $events->dispatch(new AnalysisFailed($a3, $this->p, 'repo_timeout'));
        $this->assertSame(2, count(array_filter($this->notifs('admin'), static fn (array $n): bool => $n['kind'] === 'schedule_failed')), '새 연속 = 새 알림');
        // 5) 주기 성공: 연속 0, 완료 알림 없음, 담당 결함이 있는 사용자에게 analysis_summary(P24 그대로)
        TestDb::defect($db, $this->p, $u1, ['assignee_id' => $this->u['kim']]);
        $a4 = $sched('succeeded');
        $db->execute('UPDATE analyses SET new_count = 2, total_count = 3, run_number = 2 WHERE id = ?', [$a4]);
        $events->dispatch(new AnalysisSucceeded($a4, $this->p, []));
        $s = $this->sched($this->p);
        $this->assertSame([0, 'succeeded'], [(int) $s['consecutive_failures'], $s['last_result']]);
        $kim = $this->notifs('kim');
        $this->assertSame(['analysis_summary'], array_column($kim, 'kind'));
        $this->assertSame([$u1], array_map('intval', array_column($db->all("SELECT analysis_id FROM notifications WHERE kind IN ('analysis_done', 'analysis_failed')"), 'analysis_id')), '요청자 없는 주기 분석: 완료·실패 알림 없음(사용자 실행 u1만)');
        // 6) 취소는 실패로 세지 않음
        $a5 = $sched('cancelled');
        $events->dispatch(new AnalysisCancelled($a5, $this->p));
        $s = $this->sched($this->p);
        $this->assertSame([0, 'cancelled'], [(int) $s['consecutive_failures'], $s['last_result']]);
        // 7) 비활성 admin·알림을 끈 admin은 받지 않음
        $db->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->u['admin2']]);
        $this->app->notifications()->setPreferences($this->u['admin'], ['schedule_failed' => false]);
        $before = (int) $db->value('SELECT COUNT(*) FROM notifications');
        $a6 = $sched('failed', 'repo_unreachable');
        $events->dispatch(new AnalysisFailed($a6, $this->p, 'repo_unreachable'));
        $this->assertSame($before, (int) $db->value('SELECT COUNT(*) FROM notifications'));
        $this->assertSame(1, (int) $this->sched($this->p)['consecutive_failures'], '알림과 무관하게 횟수는 센다');
        // 일반 사용자가 받는 사람 목록에 섞여도 admin 전용 종류는 만들지 않는다
        $this->assertSame(0, $this->app->notifications()->emit('schedule_failed', [$this->u['kim']], ['project_id' => $this->p], [], 'x:1'));
    }

    public function testRetryRequeuesSameRowAndQueueStaysFifo(): void
    {
        $db = $this->app->db();
        $queue = $this->app->analysisQueue();
        $retry = $this->app->scheduleRetry();
        $other = TestDb::project($db, 'other', 'python');
        $a = TestDb::analysis($db, $this->p, 0, ['status' => 'queued', 'source_kind' => 'git', 'source_ref' => 'main', 'trigger_kind' => 'schedule', 'requested_by' => null]);
        $this->assertSame($a, (int) $queue->claimNext(2, 1)['id']);
        $row = $db->one('SELECT * FROM analyses WHERE id = ?', [$a]);
        // 재시도할 수 없는 경우들
        $this->assertFalse($retry->requeue($a, $row, 'repo_auth_failed'), '인증 오류는 재시도 없음');
        $this->assertFalse($retry->requeue($a, ['trigger_kind' => 'user'] + $row, 'repo_unreachable'), '사용자 실행은 재시도 없음');
        // 1번째 실패 → 재시도 대기(5분 뒤). 가져오기가 먼저 기록한 작업 디렉토리는 비우고 지운다(다음 시도가 다시 가져오게)
        $work = $this->app->paths->work() . '/abcd1234';
        mkdir($work);
        $db->execute("UPDATE analyses SET work_dir = 'abcd1234' WHERE id = ?", [$a]);
        $this->assertTrue($retry->requeue($a, $row, 'repo_unreachable'));
        $r = $db->one('SELECT status, started_at, retry_count, retry_after, retry_code, failure_code, work_dir FROM analyses WHERE id = ?', [$a]);
        $this->assertSame(['queued', null, 1, '2026-10-02T12:05:00Z', 'repo_unreachable', null, null],
            [$r['status'], $r['started_at'], (int) $r['retry_count'], $r['retry_after'], $r['retry_code'], $r['failure_code'], $r['work_dir']]);
        $this->assertFalse(is_dir($work), '작업 디렉토리 삭제');
        $this->assertFalse($queue->hasClaimable());
        // 기다리는 동안 뒤에 온 사용자 업로드가 먼저 실행된다(워커가 막히지 않음)
        $up = TestDb::analysis($db, $other, $this->u['kim'], ['status' => 'queued']);
        $this->assertSame($up, (int) $queue->claimNext(2, 1)['id']);
        $this->assertSame(null, $queue->claimNext(2, 1), '재시도 대기는 시각 전에 선점하지 않음');
        // 시각이 지나면 원래 순서(id)대로 다시 후보
        $this->clock->advance(300);
        $later = TestDb::analysis($db, $other, $this->u['kim'], ['status' => 'queued']);
        $c = $queue->claimNext(3, 1);
        $this->assertSame([$a, null], [(int) $c['id'], $c['retry_after']], '먼저 등록된 재시도 행이 먼저');
        // 2번째 실패 → 다시 대기, 3번째 실패 → 마감(재시도 없음)
        $this->assertTrue($retry->requeue($a, $c, 'repo_timeout'));
        $this->clock->advance(300);
        $c = $queue->claimNext(3, 1);
        $this->assertSame([$a, 2], [(int) $c['id'], (int) $c['retry_count']]);
        $this->assertFalse($retry->requeue($a, $c, 'repo_unreachable'), '3번째는 마감');
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$this->p]), '이력에는 분석 행 하나');
        // 취소 요청이 있으면 되돌리지 않는다
        $db->execute('UPDATE analyses SET cancel_requested = 1 WHERE id = ?', [$later]);
        $this->assertFalse($retry->requeue($later, ['trigger_kind' => 'schedule'] + $db->one('SELECT * FROM analyses WHERE id = ?', [$later]), 'repo_unreachable'));
        // 목록 API의 재시도 표시
        $db->execute("UPDATE analyses SET status = 'queued', started_at = NULL, retry_count = 1, retry_after = '2026-10-02T12:20:00Z', retry_code = 'repo_timeout' WHERE id = ?", [$a]);
        $item = $this->app->analyses()->get($a);
        $this->assertSame(['attempts' => 1, 'next_attempt' => 2, 'after' => '2026-10-02T12:20:00Z', 'code' => 'repo_timeout'], array_diff_key($item['retry'], ['title' => 1]));
        $this->assertSame(null, $item['failure_code'], '재시도 대기는 실패로 보이지 않음');
    }

    public function testMigration019KeepsNotificationsAndSequence(): void
    {
        $dir = $this->tempDir('m019');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $f) {
            if (basename($f) < '019') {
                copy($f, "{$dir}/m/" . basename($f));
            }
        }
        $db = new \PJGuardian\Support\Db("{$dir}/x.sqlite");
        (new Migrator($db, "{$dir}/m", $this->clock))->migrate();
        $uid = TestDb::user($db, 'u1', 'user');
        for ($i = 1; $i <= 3; $i++) {
            $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at, read_at) VALUES (?, 'analysis_done', '{\"run\":1}', ?, '2026-10-01T00:00:00Z', ?)",
                [$uid, "k{$i}", $i === 2 ? '2026-10-01T01:00:00Z' : null]);
        }
        $db->execute("DELETE FROM notifications WHERE dedupe_key = 'k3'");
        $db->execute("INSERT INTO notification_prefs (user_id, kind, enabled, updated_at) VALUES (?, 'defect_changed', 0, '2026-10-01T00:00:00Z')", [$uid]);
        $before = $db->all('SELECT * FROM notifications ORDER BY id');
        $this->assertThrows(\PDOException::class, static fn () => $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (?, 'schedule_failed', '{}', 'z', '2026-10-01T00:00:00Z')", [$uid]));
        copy(PJG_ROOT . '/migrations/019_schedules.sql', "{$dir}/m/019_schedules.sql");
        $this->assertSame(['019'], (new Migrator($db, "{$dir}/m", $this->clock))->migrate());
        $this->assertSame($before, $db->all('SELECT * FROM notifications ORDER BY id'), '알림 그대로(id·읽음)');
        $this->assertSame([['user_id' => $uid, 'kind' => 'defect_changed', 'enabled' => 0]], $db->all('SELECT user_id, kind, enabled FROM notification_prefs'));
        $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (?, 'schedule_failed', '{}', 'z', '2026-10-01T00:00:00Z')", [$uid]);
        $this->assertSame(4, $db->lastInsertId(), '지운 알림 id(3)를 다시 쓰지 않음');
        $this->assertSame(5, (int) $db->value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = 'notifications' AND name LIKE 'notifications_%'"));
        $this->assertSame(['ok'], array_column($db->all('PRAGMA integrity_check'), 'integrity_check'));
        $this->assertSame([], $db->all('PRAGMA foreign_key_check'));
        // CHECK: 사용 중이면 다음 시각 필수, 간격·시각 형식
        $pid = TestDb::project($db, 'p', 'python');
        $t = "'2026-10-01T00:00:00Z'";
        foreach (["1, 7, '03:00', NULL", "0, 0, '03:00', NULL", "0, 366, '03:00', NULL", "0, 7, '24:00', NULL", "0, 7, '3:00', NULL", "1, 7, '03:00', '2026-10-02 18:00'"] as $vals) {
            $this->assertThrows(\PDOException::class, static fn () => $db->execute("INSERT INTO project_schedules (project_id, enabled, interval_days, run_time, next_run_at, created_at, updated_at) VALUES (?, {$vals}, {$t}, {$t})", [$pid]), '', $vals);
        }
        $db->execute("INSERT INTO project_schedules (project_id, enabled, interval_days, run_time, next_run_at, created_at, updated_at) VALUES (?, 1, 7, '03:00', '2026-10-02T18:00:00Z', {$t}, {$t})", [$pid]);
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM project_schedules'));
    }

    /** 019를 적용하기 전 DB에서 새 코드가 떠도(워커 재시작이 먼저인 경우) 선점·결과 기록이 멈추지 않는다 */
    public function testPre019DatabaseKeepsQueueWorking(): void
    {
        $dir = $this->tempDir('pre019');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $f) {
            if (basename($f) < '019') {
                copy($f, "{$dir}/m/" . basename($f));
            }
        }
        $db = new \PJGuardian\Support\Db("{$dir}/x.sqlite");
        (new Migrator($db, "{$dir}/m", $this->clock))->migrate();
        $app = new App(PJG_ROOT, $this->app->config, $this->clock, $this->app->logger, $this->app->paths, $db);
        $uid = TestDb::user($db, 'u', 'admin');
        $pid = TestDb::project($db, 'p', 'python');
        $a = TestDb::analysis($db, $pid, $uid, ['status' => 'queued', 'source_kind' => 'git']);
        $this->assertTrue($app->analysisQueue()->hasClaimable());
        $this->assertSame($a, (int) $app->analysisQueue()->claimNext(2, 1)['id']);
        $db->execute("UPDATE analyses SET status = 'succeeded', finished_at = ? WHERE id = ?", [TestDb::T0, $a]);
        $log = $this->app->logger->fileFor($this->clock->now());
        $before = is_file($log) ? (string) file_get_contents($log) : '';
        $app->events()->dispatch(new AnalysisSucceeded($a, $pid, []));
        $after = is_file($log) ? (string) file_get_contents($log) : '';
        $this->assertNotContains('주기 분석 결과 반영 실패', substr($after, strlen($before)), '019 전 DB: 결과 반영은 조용히 건너뜀');
        $this->assertSame(null, $app->schedules()->publicSummary($pid, true));
        $this->assertThrows(\PDOException::class, static fn () => $app->scheduleTicker()->tick(), 'no such table');
    }
}
