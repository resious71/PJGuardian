<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\RepoFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 주기 분석 전체 흐름(P26) — 실제 워커(tools/worker.php --once) + 실제 git(bin/의 MinGit) + 실제 ruff + 로컬 HTTP 저장소.
 * 시간은 기다리지 않는다: 도래·재시도 시각을 DB에서 과거로 옮긴 뒤 워커를 다시 돌린다(워커는 실제 시계).
 *  1. 도래 → 워커의 스케줄러가 등록 → 가져와 분석 성공(실행 주체 schedule, 요청자 없음), 완료 알림 없음
 *  2. 담당 결함이 있는 사용자에게 analysis_summary(P24 그대로)
 *  3. 서버 다운(접속 불가) → 같은 분석 행이 재시도 대기(queued) 2번 → 3번째 failed 1건, 연속 실패 1, schedule_failed(admin만), 주기 유지
 *  4. 인증 오류 → 재시도 없이 바로 failed, 연속 실패 2(알림은 같은 한 건의 횟수만 갱신)
 *  5. 다음 회차 성공 → 연속 실패 0
 *  6. 대상 파일 0개 → failed(repo_empty), 기존 결함 보존
 */
final class ScheduleWorkerTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
    private const TOKEN = 'x-access-token:tok-1234';

    private string $root;
    private string $ini;
    private App $app;
    private RepoFixture $fx;
    private int $p;
    /** @var array<string, int> */
    private array $u = [];

    private function boot(): bool
    {
        if (RepoFixture::git() === null || RepoFixture::httpBackend() === null || !is_file(PJG_ROOT . '/bin/ruff/0.16.10/windows/ruff.exe')) {
            fwrite(STDOUT, "      [건너뜀] bin/git·ruff 또는 git-http-backend 없음\n");
            return false;
        }
        $this->root = $this->tempDir('schedwork');
        foreach (['uploads', 'work', 'logs', 'snapshots'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->ini = TestApp::writeIni($this->root . '/config.ini', [
            'paths' => ['db_file' => $this->root . '/db.sqlite', 'uploads_dir' => $this->root . '/uploads', 'work_dir' => $this->root . '/work',
                'logs_dir' => $this->root . '/logs', 'snapshots_dir' => $this->root . '/snapshots'],
            'auth' => ['bcrypt_cost' => '4'],
            'worker' => ['heartbeat_interval' => '1s', 'heartbeat_stale' => '3s', 'poll_interval' => '1s', 'cancel_check_interval' => '1s'],
            'repository' => ['allow_local_dev' => 'true', 'connect_retry_interval' => '5m'],
            'disk' => ['block_percent' => '99', 'warn_percent' => '98'],
        ]);
        file_put_contents($this->root . '/secrets.ini', 'encryption_key = "' . self::KEY . "\"\n");
        TestDb::migrated($this->root . '/db.sqlite');
        $this->app = App::create(PJG_ROOT, Config::load($this->ini, $this->root . '/secrets.ini'));
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, 'sched', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$this->p, $this->u['kim']]);
        $this->fx = new RepoFixture($this->root . '/fx');
        return true;
    }

    public function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->release();
        }
        unset($this->app);
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], '127.0.0.1');
    }

    private function runWorker(): string
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', '--config=' . $this->ini, '--once'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return $out;
    }

    /** 도래 시각을 과거로(지금 도래) */
    private function dueNow(): void
    {
        $this->app->db()->execute('UPDATE project_schedules SET next_run_at = ? WHERE project_id = ?', [gmdate('Y-m-d\TH:i:s\Z', time() - 120), $this->p]);
    }

    /** 서버를 (다시) 띄우고 연결 주소의 포트를 맞춘다 */
    private function serve(string $auth): void
    {
        $this->fx->start(['auth' => $auth]);
        $this->app->db()->execute('UPDATE project_repo_connections SET location = ? WHERE project_id = ?', [$this->fx->url('app'), $this->p]);
    }

    /** @return array<string, mixed> */
    private function latest(): array
    {
        return $this->app->db()->one("SELECT * FROM analyses WHERE project_id = ? AND trigger_kind = 'schedule' ORDER BY id DESC LIMIT 1", [$this->p]);
    }

    /** @return array<string, mixed> */
    private function sched(): array
    {
        return $this->app->db()->one('SELECT * FROM project_schedules WHERE project_id = ?', [$this->p]);
    }

    private function logs(): string
    {
        $out = '';
        foreach (Fs::listDir($this->root . '/logs') as $f) {
            $out .= implode("\n", array_filter(explode("\n", (string) file_get_contents($this->root . '/logs/' . $f)), static fn (string $l): bool => str_contains($l, '"ERROR"') || str_contains($l, 'WARN')));
        }
        return mb_substr($out, 0, 3000);
    }

    /** @needs-bin bin/git·bin/ruff 실행 파일과 git-http-backend */
    public function testScheduledRunRetryFailureAndRecovery(): void
    {
        if (!$this->boot()) {
            return;
        }
        $db = $this->app->db();
        $this->fx->commit('app', ['app/main.py' => "import os\nimport sys\n", 'app/util.py' => "import json\n"]);
        $this->fx->start(['auth' => self::TOKEN]);
        $this->app->repoAllowedHosts()->add($this->actor(), $this->u['admin'], ['host' => '127.0.0.1', 'allow_http' => true]);
        $this->app->repoConnections()->save($this->actor(), $this->u['admin'], $this->p, ['kind' => 'git', 'url' => $this->fx->url('app'), 'secret' => 'tok-1234']);
        $this->app->schedules()->save($this->actor(), $this->u['admin'], $this->p, ['enabled' => true, 'interval_days' => 7, 'run_time' => '03:00']);

        // 1) 도래 → 워커의 스케줄러 등록 → 가져와 분석 성공
        $this->dueNow();
        $out = $this->runWorker();
        $this->assertTrue($db->value("SELECT 1 FROM analyses WHERE trigger_kind = 'schedule'") !== null, $out . json_encode($db->all('SELECT * FROM schedule_ticks')) . $this->logs());
        $a = $this->latest();
        $this->assertSame(['succeeded', 'git', null, 1, 3], [$a['status'], $a['source_kind'], $a['requested_by'], (int) $a['run_number'], (int) $a['new_count']], $out . $this->logs());
        $s = $this->sched();
        $this->assertSame(['succeeded', 0, (int) $a['id']], [$s['last_result'], (int) $s['consecutive_failures'], (int) $s['last_analysis_id']]);
        $this->assertTrue(strtotime((string) $s['next_run_at']) > time() + 86400 * 6, '다음 = 7일 계열');
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM notifications'), '완료 알림 없음(요청자 없음, 담당 결함 없음)');
        $this->assertContains('주기 분석 1개 등록', $out);

        // 2) 담당 결함이 있는 사용자에게 analysis_summary
        $db->execute("UPDATE defects SET assignee_id = ? WHERE project_id = ? AND file_path = 'app/main.py'", [$this->u['kim'], $this->p]);
        $this->fx->commit('app', ['app/util.py' => null]);
        $this->dueNow();
        $this->runWorker();
        $a = $this->latest();
        $this->assertSame(['succeeded', 1], [$a['status'], (int) $a['resolved_count']], $this->logs());
        $this->assertSame([['user_id' => $this->u['kim'], 'kind' => 'analysis_summary']], $db->all('SELECT user_id, kind FROM notifications'));

        // 3) 서버 다운 → 같은 행이 재시도 대기 2번 → 3번째 failed 1건
        $this->fx->stop();
        $this->dueNow();
        $before = (int) $db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$this->p]);
        $this->runWorker();
        $a = $this->latest();
        $this->assertSame(['queued', 1, 'repo_unreachable', null], [$a['status'], (int) $a['retry_count'], $a['retry_code'], $a['failure_code']], $this->logs());
        $wait = strtotime((string) $a['retry_after']) - time();
        $this->assertTrue($wait > 240 && $wait <= 300, "5분 뒤 재시도({$wait}초)");
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM notifications WHERE kind = 'schedule_failed'"), '재시도 중에는 알리지 않음');
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => preg_match('/^[0-9a-f]{8}$/', $n) === 1)));
        for ($attempt = 2; $attempt <= 3; $attempt++) {
            $db->execute('UPDATE analyses SET retry_after = ? WHERE id = ?', [gmdate('Y-m-d\TH:i:s\Z', time() - 1), (int) $a['id']]);
            $this->runWorker();
        }
        $a = $this->latest();
        $this->assertSame(['failed', 'repo_unreachable', 2], [$a['status'], $a['failure_code'], (int) $a['retry_count']], $this->logs());
        $this->assertSame($before + 1, (int) $db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$this->p]), '이력에는 실패 1건');
        $s = $this->sched();
        $this->assertSame([1, 1, 'failed', 'repo_unreachable'], [(int) $s['enabled'], (int) $s['consecutive_failures'], $s['last_result'], $s['last_error_code']], '주기 유지');
        $n = $db->all("SELECT user_id, params FROM notifications WHERE kind = 'schedule_failed'");
        $this->assertSame([$this->u['admin']], array_map('intval', array_column($n, 'user_id')), 'admin에게만');
        $this->assertSame([1, 3], [json_decode($n[0]['params'], true)['count'], json_decode($n[0]['params'], true)['attempts']]);

        // 4) 인증 오류 → 재시도 없이 바로 failed, 같은 알림 한 건의 횟수만 2
        $this->serve('x-access-token:other');
        $this->dueNow();
        $this->runWorker();
        $a = $this->latest();
        $this->assertSame(['failed', 'repo_auth_failed', 0], [$a['status'], $a['failure_code'], (int) $a['retry_count']], $this->logs());
        $n = $db->all("SELECT params FROM notifications WHERE kind = 'schedule_failed'");
        $this->assertSame([1, 2, 'repo_auth_failed'], [count($n), json_decode($n[0]['params'], true)['count'], json_decode($n[0]['params'], true)['code']]);

        // 5) 다음 회차 성공 → 연속 실패 0
        $this->serve(self::TOKEN);
        $this->dueNow();
        $this->runWorker();
        $this->assertSame('succeeded', $this->latest()['status'], $this->logs());
        $this->assertSame([0, null], [(int) $this->sched()['consecutive_failures'], $this->sched()['first_failure_analysis_id']]);

        // 6) 대상 파일 0개 → failed(repo_empty), 기존 결함 보존(일괄 resolved 없음)
        $open = (int) $db->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status <> 'resolved'", [$this->p]);
        $this->fx->commit('app', ['app/main.py' => null, 'README.md' => "docs\n"]);
        $this->dueNow();
        $this->runWorker();
        $this->assertSame(['failed', 'repo_empty'], [$this->latest()['status'], $this->latest()['failure_code']], $this->logs());
        $this->assertSame($open, (int) $db->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status <> 'resolved'", [$this->p]));
        // 토큰은 로그에 없음
        foreach (Fs::listDir($this->root . '/logs') as $f) {
            $this->assertNotContains('tok-1234', (string) file_get_contents($this->root . '/logs/' . $f));
        }
    }
}
