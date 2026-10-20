<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Analysis\AnalysisEnvKey;
use PJGuardian\Services\Events\AnalysisSucceeded;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Repo\RepoService;
use PJGuardian\Services\Repo\UnchangedSourceCheck;
use PJGuardian\Services\Runs\RunTracker;
use PJGuardian\Services\Schedule\ScheduledAnalysisLauncher;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Db;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 성공(변경 없음)(P27, 명세 18.2·19.10) — 도구·네트워크 없이(원격 리비전·조건 키를 주입) 판단 표와 기록·제외·알림·API를 본다.
 * 실제 git 저장소·워커·가짜 p4 흐름은 RepoUnchangedFlowTest.
 *
 * - 판정 표: 같음 → unchanged(same) / 리비전 다름 / 연결 다름(브랜치·종류·021 전 기준) / 조건 다름(도구 버전·설정·규칙·제외·확장자) /
 *   처음(기준 없음) / 직전이 실패여도 직전 "성공"과 비교 / 성공이 "변경 없음"뿐 / 기준 상세 삭제 / 원격 조회 실패 / 판단 중 예외 / 강제
 * - 기록: 주기 분석은 같은 리비전이면 한 행에 확인 횟수·마지막 확인을 올린다(이력이 늘지 않음), 사용자 실행은 그 행을 마감
 * - 제외: Run 번호·되채움 필요 여부·비교 기준·이력 이전/다음·대시보드 추이·보관 수·최근 성공·연결 상태의 마지막 분석 — 변경 없음 100개가 쌓여도 그대로
 * - 알림 없음, API(unchanged 필드·숨기기 필터·비교 409·force는 admin만·CSRF·보이지 않는 프로젝트 404)
 */
final class UnchangedSourceTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
    private const C1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const C2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2';

    private App $app;
    private string $root;
    private int $p;
    private int $hidden;
    /** @var array<string, int> */
    private array $u = [];
    private string $head = self::C1;
    private ?string $env;
    private bool $headFails = false;
    private bool $envThrows = false;
    private int $headCalls = 0;

    public function setUp(): void
    {
        $this->root = $this->tempDir('unchanged');
        mkdir($this->root . '/work');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']], ['encryption_key' => self::KEY]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->p = TestDb::project($db, 'repo', 'python');
        $this->hidden = TestDb::project($db, 'hidden', 'python');
        $now = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, ?)", [$this->p, $this->u['kim'], $now]);
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g', ?, ?)", [$now, $now]);
        $g = $db->lastInsertId();
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?)', [$g, $this->u['mgr'], $now]);
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', ?, ?)", [$this->p, $g, $now]);
        $db->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, '', ?, ?)", [$now, $now]);
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
        $this->env = str_repeat('e', 64);
        $this->connect('main');
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], '127.0.0.1');
    }

    private function connect(string $branch, ?string $path = null): void
    {
        $rev = $this->app->repoConnections()->row($this->p)['revision'] ?? null;
        $this->app->repoConnections()->save($this->actor(), $this->u['admin'], $this->p, ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'branch' => $branch,
            'path' => $path, 'secret' => 'tok-1234'] + ($rev === null ? [] : ['revision' => (int) $rev]));
    }

    private function check(): UnchangedSourceCheck
    {
        return new UnchangedSourceCheck($this->app->db(), $this->app->clock, $this->app->logger,
            function (int $pid): array {
                $this->headCalls++;
                if ($this->headFails) {
                    throw new RepoException('repo_unreachable');
                }
                return ['ref' => 'main', 'revision' => $this->head];
            },
            function (int $pid): ?string {
                if ($this->envThrows) {
                    throw new \RuntimeException('registry broken');
                }
                return $this->env;
            });
    }

    private function conn(): array
    {
        return $this->app->repoConnections()->row($this->p);
    }

    /** 저장소 출처의 실제 성공 분석(기준이 될 수 있는 행) */
    private function base(string $rev = self::C1, array $over = []): int
    {
        return TestDb::analysis($this->app->db(), $this->p, $this->u['admin'], $over + ['source_kind' => 'git', 'source_ref' => 'main', 'source_revision' => $rev,
            'source_key' => UnchangedSourceCheck::sourceKey($this->conn()), 'env_key' => $this->env]);
    }

    public function testDecisionTable(): void
    {
        $c = $this->check();
        $this->assertSame(['changed', 'no_base'], [($r = $c->evaluate($this->p))['decision'], $r['reason']], '처음 분석(기준 없음)');
        $this->assertSame(0, $this->headCalls, '기준이 없으면 원격을 조회하지 않는다');
        $b1 = $this->base();
        $r = $c->evaluate($this->p);
        $this->assertSame(['unchanged', 'same', self::C1, $b1], [$r['decision'], $r['reason'], $r['remote_revision'], $r['base_analysis_id']]);
        $this->assertSame(['unchanged', self::C1], [$this->conn()['last_check_result'], $this->conn()['last_check_revision']], '마지막 확인 기록');
        $this->assertSame(TestDb::T0, $this->conn()['last_check_at']);
        $this->head = self::C2;
        $this->assertSame(['changed', 'revision_differs'], [($r = $c->evaluate($this->p))['decision'], $r['reason']]);
        $this->assertSame('changed', $this->conn()['last_check_result']);
        $this->head = self::C1;
        // 강제: 원격을 보지 않고 분석
        $calls = $this->headCalls;
        $this->assertSame(['changed', 'forced'], [($r = $c->evaluate($this->p, true))['decision'], $r['reason']]);
        $this->assertSame($calls, $this->headCalls);
        // 조건(도구 버전·설정 해시·규칙·제외·확장자를 묶은 키)이 다르면 같은 커밋이어도 분석
        $this->env = str_repeat('f', 64);
        $this->assertSame(['changed', 'tool_or_config_changed'], [($r = $c->evaluate($this->p))['decision'], $r['reason']]);
        $this->env = null; // 도구를 쓸 수 없음 → 판단 불가 → 분석
        $this->assertSame('tool_or_config_changed', $c->evaluate($this->p)['reason']);
        $this->env = str_repeat('e', 64);
        // 직전 분석이 실패여도 직전 "성공"과 비교한다(실패는 기준이 아님)
        TestDb::analysis($this->app->db(), $this->p, $this->u['admin'], ['status' => 'failed', 'source_kind' => 'git', 'failure_code' => 'repo_unreachable']);
        $this->assertSame(['unchanged', $b1], [($r = $c->evaluate($this->p))['decision'], $r['base_analysis_id']]);
        // 원격 조회 실패 → 변경 없음이 아니다(가져오기 경로가 재시도·실패를 기록)
        $this->headFails = true;
        $this->assertSame(['changed', 'check_error', 'repo_unreachable'], [($r = $c->evaluate($this->p))['decision'], $r['reason'], $r['code']]);
        $this->assertSame('error', $this->conn()['last_check_result']);
        $this->headFails = false;
        // 판단 중 예외 → 분석(경고 로그)
        $this->envThrows = true;
        $this->assertSame(['changed', 'check_error', 'check_exception'], [($r = $c->evaluate($this->p))['decision'], $r['reason'], $r['code']]);
        $this->envThrows = false;
        // 연결(브랜치)을 바꾸면 같은 커밋이어도 분석, 마지막 확인은 지운다
        $this->connect('release');
        $this->assertSame(null, $this->conn()['last_check_at']);
        $this->assertSame(['changed', 'connection_changed'], [($r = $c->evaluate($this->p))['decision'], $r['reason']]);
        $this->connect('main');
        $this->assertSame('unchanged', $c->evaluate($this->p)['decision'], '같은 연결로 돌아오면 다시 같음');
        // 기준 상세가 보관 정리로 지워졌으면 분석
        $this->app->db()->execute("UPDATE analyses SET run_number = 1, run_detail_deleted_at = ?, run_detail_deleted_reason = 'retention' WHERE id = ?", [TestDb::T0, $b1]);
        $this->assertSame('no_base', $c->evaluate($this->p)['reason']);
    }

    public function testBaseKindsAndLegacyRows(): void
    {
        $c = $this->check();
        // 직전 성공이 업로드 분석 → 다른 출처
        TestDb::analysis($this->app->db(), $this->p, $this->u['admin']);
        $this->assertSame('connection_changed', $c->evaluate($this->p)['reason']);
        // 021 전에 끝난 저장소 분석(키 없음) → 같은 조건인지 알 수 없어 분석
        TestDb::analysis($this->app->db(), $this->p, $this->u['admin'], ['source_kind' => 'git', 'source_revision' => self::C1]);
        $this->assertSame('connection_changed', $c->evaluate($this->p)['reason']);
        $b = $this->base(self::C1, ['env_key' => null]);
        $this->assertSame('tool_or_config_changed', $c->evaluate($this->p)['reason']);
        $this->app->db()->execute('UPDATE analyses SET env_key = ? WHERE id = ?', [$this->env, $b]);
        $this->assertSame('unchanged', $c->evaluate($this->p)['decision']);
        // 성공이 "변경 없음"뿐이면(실제 분석이 없으면) 기준 없음
        $p2 = TestDb::project($this->app->db(), 'only-unchanged', 'python');
        $this->app->repoConnections()->save($this->actor(), $this->u['admin'], $p2, ['kind' => 'git', 'url' => 'https://ghe.corp/team/b.git']);
        TestDb::analysis($this->app->db(), $p2, $this->u['admin'], ['source_kind' => 'git', 'source_revision' => self::C1, 'no_change' => 1,
            'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);
        $this->assertSame('no_base', $c->evaluate($p2)['reason']);
    }

    public function testScheduledRecordFoldsSameRevisionIntoOneRow(): void
    {
        $b1 = $this->base();
        $c = $this->check();
        $launcher = new ScheduledAnalysisLauncher();
        $launch = function (string $now) use ($c, $launcher): array {
            $hint = $c->evaluate($this->p);
            return $this->app->db()->transaction(fn (Db $db): array => $launcher->launch($db, $this->p, $this->conn(), $now, $now, $hint), write: true);
        };
        $r1 = $launch('2026-10-03T00:00:00Z');
        $this->assertSame(['unchanged', null], [$r1['result'], $r1['detail']]);
        $row = $this->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$r1['analysis_id']]);
        $this->assertSame(['succeeded', 1, null, 'schedule', null, self::C1, $b1, 1, '2026-10-03T00:00:00Z', null, null],
            [$row['status'], (int) $row['no_change'], $row['run_number'], $row['trigger_kind'], $row['requested_by'], $row['source_revision'],
                (int) $row['unchanged_base_analysis_id'], (int) $row['unchanged_check_count'], $row['unchanged_last_checked_at'], $row['started_at'], $row['work_dir']]);
        $r2 = $launch('2026-10-10T00:00:00Z');
        $r3 = $launch('2026-10-17T00:00:00Z');
        $this->assertSame([$r1['analysis_id'], 'folded', $r1['analysis_id']], [$r2['analysis_id'], $r2['detail'], $r3['analysis_id']], '같은 리비전은 한 행');
        $row = $this->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$r1['analysis_id']]);
        $this->assertSame([3, '2026-10-17T00:00:00Z'], [(int) $row['unchanged_check_count'], $row['unchanged_last_checked_at']]);
        $this->assertSame(2, (int) $this->app->db()->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$this->p]), '이력이 주기마다 늘지 않는다');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM analyses WHERE status IN ('queued', 'running')"), '큐를 거치지 않는다');
        // 새 커밋 → 등록(queued). 그 사이 다른 실제 분석이 기준을 바꿨으면(경쟁) 생략하지 않는다
        $this->head = self::C2;
        $this->assertSame('queued', $launch('2026-10-24T00:00:00Z')['result']);
        $this->app->db()->execute("UPDATE analyses SET status = 'cancelled', finished_at = ? WHERE status = 'queued'", [TestDb::T0]);
        $this->head = self::C1;
        $hint = $c->evaluate($this->p);
        $this->base(self::C1); // 판단 뒤 트랜잭션 전에 다른 성공이 생김
        $r = $this->app->db()->transaction(fn (Db $db): array => $launcher->launch($db, $this->p, $this->conn(), 'x', '2026-10-25T00:00:00Z', $hint), write: true);
        $this->assertSame('queued', $r['result'], '기준이 바뀌었으면 큐에 넣는다');
    }

    public function testUserFetchRowIsClosedAsUnchanged(): void
    {
        $b1 = $this->base();
        $id = $this->app->db()->transaction(fn (Db $db): int => RepoService::enqueue($db, $this->conn(), $this->p, $this->u['admin'], TestDb::T0), write: true);
        $this->app->db()->execute("UPDATE analyses SET status = 'running', started_at = ? WHERE id = ?", [TestDb::T0, $id]);
        $eval = $this->check()->evaluate($this->p);
        $r = $this->app->db()->transaction(fn (Db $db): ?array => UnchangedSourceCheck::record($db, $this->p, $this->conn(), $eval, '2026-10-02T12:00:05Z', $id), write: true);
        $this->assertSame(['analysis_id' => $id, 'folded' => false], $r);
        $a = $this->app->analyses()->get($id);
        $this->assertSame(['succeeded', true, 1, null, $b1, false], [$a['status'], $a['unchanged'], $a['unchanged_check_count'], $a['run'], $a['unchanged_base']['analysis_id'], $a['first_analysis']]);
        // 이미 running이 아니면(취소됨) 기록하지 않는다
        $this->assertSame(null, $this->app->db()->transaction(fn (Db $db): ?array => UnchangedSourceCheck::record($db, $this->p, $this->conn(), $eval, TestDb::T0, $id), write: true));
        // 알림 없음: 성공 이벤트가 와도 변경 없음이면 아무것도 만들지 않는다
        $this->app->events()->dispatch(new AnalysisSucceeded($id, $this->p, []));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications'));
    }

    public function testExcludedFromRunsBaselineTrendRetentionAndRecent(): void
    {
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['admin']);
        $a1 = $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 2]])['analysis_id'];
        $a2 = $f->analyze(['a.py' => 3], [['a.py', 1]])['analysis_id'];
        $db = $this->app->db();
        $db->execute('UPDATE analyses SET source_kind = ?, source_revision = ?, source_key = ?, env_key = ? WHERE id = ?',
            ['git', self::C1, UnchangedSourceCheck::sourceKey($this->conn()), $this->env, $a2]);
        $summary = $db->value('SELECT run_summary FROM analyses WHERE id = ?', [$a2]);
        $defects = $db->all('SELECT id, status, last_seen_analysis_id FROM defects WHERE project_id = ? ORDER BY id', [$this->p]);
        // 사용자 "지금 가져와 분석" 변경 없음 100개(각각 한 행) + 주기 확인
        $c = $this->check();
        for ($i = 0; $i < 100; $i++) {
            $id = RepoService::enqueue($db, $this->conn(), $this->p, $this->u['admin'], TestDb::T0);
            $db->execute("UPDATE analyses SET status = 'running', started_at = ? WHERE id = ?", [TestDb::T0, $id]);
            $this->assertTrue(UnchangedSourceCheck::record($db, $this->p, $this->conn(), $c->evaluate($this->p), sprintf('2026-10-02T12:%02d:00Z', $i % 60), $id) !== null);
        }
        $last = $id;
        $this->assertSame(100, (int) $db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ? AND no_change = 1 AND run_number IS NULL', [$this->p]), 'Run 번호 없음');
        $this->assertSame(3, RunTracker::nextRun($db, $this->p), '다음 Run 번호는 실제 분석만 센다');
        $this->assertTrue(RunTracker::ready($db, $this->p), '변경 없음 때문에 되채움이 필요해지지 않는다');
        $this->assertSame(0, $this->app->runs()->status($this->p)['missing'] ?? 0);
        $this->assertSame([], (new \PJGuardian\Services\Runs\RunBackfill($db, new \PJGuardian\Services\Runs\RunService($db)))->pending(), '되채움 대상 아님');
        $this->assertSame($summary, $db->value('SELECT run_summary FROM analyses WHERE id = ?', [$a2]), 'run_summary 그대로');
        $this->assertSame($defects, $db->all('SELECT id, status, last_seen_analysis_id FROM defects WHERE project_id = ? ORDER BY id', [$this->p]), '결함 그대로');
        // 최근 성공·연결의 마지막 분석 = 실제 분석
        $this->assertSame($a2, (int) $this->app->projects()->get($this->p, true)['latest_success']['id']);
        $this->assertSame([$a2, 2], [$this->app->repoConnections()->view($this->p, false)['last_analyzed']['analysis_id'], $this->app->repoConnections()->view($this->p, false)['last_analyzed']['run']]);
        // 다음 실제 분석의 비교 기준 = 직전 실제 성공(변경 없음 아님)
        $a3 = $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 3]]);
        $this->assertSame([3, $a2], [(int) $db->value('SELECT run_number FROM analyses WHERE id = ?', [$a3['analysis_id']]), (int) $db->value('SELECT baseline_analysis_id FROM analyses WHERE id = ?', [$a3['analysis_id']])]);
        // 히스토리: 이전·다음은 실제 분석만, 변경 없음 비교는 409
        $cmp = $this->app->history()->comparison($a3['analysis_id']);
        $this->assertSame([$a2, null], [$cmp['nav']['prev'], $cmp['nav']['next']]);
        $this->assertSame([$a1, $a3['analysis_id']], [$this->app->history()->comparison($a2)['nav']['prev'], $this->app->history()->comparison($a2)['nav']['next']]);
        $this->assertThrows(\PJGuardian\Http\ApiException::class, fn () => $this->app->history()->comparison($last), 'analysis_unchanged');
        // 이력: 보이되(배지 데이터) 숨기기 필터로 뺄 수 있다
        [$items, $total] = $this->app->history()->list($this->p, 200, 0);
        $this->assertSame(103, $total);
        $row = array_values(array_filter($items, static fn (array $r): bool => $r['analysis_id'] === $last))[0];
        $this->assertSame([true, null, null, 1, ['analysis_id' => $a2, 'run' => 2, 'finished_at' => '2026-10-01T02:00:00Z']], [$row['unchanged'], $row['run'], $row['counts'], $row['unchanged_check_count'], $row['unchanged_base']], 'RunFixture 두 번째 분석 시각');
        [$items, $total] = $this->app->history()->list($this->p, 200, 0, true);
        $this->assertSame([3, [3, 2, 1]], [$total, array_column($items, 'run')]);
        // 대시보드 추이·Run 목록: 실제 Run만
        $trend = $this->app->dashboard()->trend(['id' => $this->u['admin'], 'role' => 'admin'], $this->p);
        $points = $trend['series'][0]['points'] ?? $trend['points'] ?? [];
        $this->assertSame([1, 2, 3], array_column($points, 'run'), json_encode(array_keys($trend)));
        $this->assertSame([3, 2, 1], array_column($this->app->runs()->list($this->p, 50, 0)[0], 'run'));
        // 보관(최근 N개 Run 세기): 변경 없음이 100개여도 Run만 센다
        $this->assertSame([1], $this->app->runDetails()->retentionCandidates($this->p, 2));
    }

    public function testAnalysisEnvKeyCoversVersionRulesExcludesAndExtensions(): void
    {
        $bin = $this->root . '/bin';
        $make = function (string $v) use ($bin): void {
            $os = ToolRegistry::os();
            $dir = "{$bin}/ruff/{$v}";
            mkdir("{$dir}/{$os}", 0700, true);
            $rel = ToolRegistry::executableRel('ruff');
            file_put_contents("{$dir}/{$rel}", 'fake' . $v);
            file_put_contents("{$dir}/SHA256SUMS", hash_file('sha256', "{$dir}/{$rel}") . " *{$rel}\n");
        };
        $make('0.16.10');
        $this->app->setToolRegistry(new ToolRegistry($this->app->db(), $this->app->clock, $this->app->logger, $bin, $this->root . '/work/tools', new NodeRuntime(null, '20.19.0'),
            static fn (SelectedTool $t): array => ['ok' => true, 'message' => 'ok']));
        $key = fn (array $ext = ['python' => ['py']]): ?string => (new AnalysisEnvKey($this->app->db(), $this->app->toolRegistry(), $this->app->projects(), $this->app->ruleOverrides(),
            $this->app->eslintRunner(), PJG_ROOT . '/config/ruff.default.toml', $ext))->compute($this->p);
        $k0 = $key();
        $this->assertSame(64, strlen((string) $k0));
        $this->assertSame($k0, $key(), '같은 조건이면 같은 키');
        $this->assertTrue($key(['python' => ['py', 'pyi']]) !== $k0, '대상 확장자');
        $make('0.17.0');
        $k1 = $key();
        $this->assertTrue($k1 !== $k0, 'ruff 버전');
        $this->app->db()->execute("INSERT INTO rule_override_state (tool, revision, updated_at) VALUES ('ruff', 1, ?) ON CONFLICT (tool) DO UPDATE SET revision = revision + 1", [TestDb::T0]);
        $k2 = $key();
        $this->assertTrue($k2 !== $k1, '관리자 규칙 켜기·끄기 개정');
        $this->app->db()->execute('UPDATE projects SET exclude_patterns = ? WHERE id = ?', ['["build/**"]', $this->p]);
        $this->assertTrue($key() !== $k2, '프로젝트 제외 패턴');
        $parts = (new AnalysisEnvKey($this->app->db(), $this->app->toolRegistry(), $this->app->projects(), $this->app->ruleOverrides(), $this->app->eslintRunner(),
            PJG_ROOT . '/config/ruff.default.toml', ['python' => ['py']]))->parts($this->p);
        $this->assertSame(['tool', 'tool_version', 'server_config_hash', 'rule_override_revision', 'exclude_defaults', 'exclude_patterns', 'target_extensions'], array_keys($parts));
        $this->assertSame(['ruff', '0.17.0', 1, ['build/**']], [$parts['tool'], $parts['tool_version'], $parts['rule_override_revision'], $parts['exclude_patterns']]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    public function testApiAccessForceAndFields(): void
    {
        $b1 = $this->base();
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get("/api/v1/projects/{$this->p}/history", ['unchanged' => 'hide'])->status);
        // 비로그인 POST는 세션 CSRF 토큰이 없어 미들웨어 순서상 403(CSRF)이 먼저 — 어느 쪽이든 실행되지 않는다
        $this->assertTrue(in_array($anon->post("/api/v1/projects/{$this->p}/repo/fetch", ['force' => true])->status, [401, 403], true));
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM analyses WHERE status = 'queued'"));
        foreach (['kim', 'mgr'] as $who) {
            $c = $this->as($who);
            $this->assertSame(403, $c->post("/api/v1/projects/{$this->p}/repo/fetch", ['force' => true])->status, "{$who}: force는 admin만");
            $this->assertSame(404, $c->get("/api/v1/projects/{$this->hidden}/history", ['unchanged' => 'hide'])->status, '보이지 않는 프로젝트 404');
            $this->assertSame(200, $c->get("/api/v1/projects/{$this->p}/history", ['unchanged' => 'hide'])->status);
        }
        $admin = $this->as('admin');
        $this->assertSame([400, 'invalid_unchanged_filter'], [($r = $admin->get("/api/v1/projects/{$this->p}/history", ['unchanged' => 'x']))->status, $r->decoded()['error']['code']]);
        $this->assertSame(403, $admin->post("/api/v1/projects/{$this->p}/repo/fetch", ['force' => true], false)->status, 'CSRF');
        $this->assertSame([400, 'force'], [($r = $admin->post("/api/v1/projects/{$this->p}/repo/fetch", ['force' => 'yes']))->status, $r->decoded()['error']['details']['field'] ?? null]);
        $this->assertSame(404, $admin->post("/api/v1/projects/9999/repo/fetch", ['force' => true])->status);
        // 강제 분석: 행에 force_analysis, 감사 repo.fetch_now에 force
        $r = $admin->post("/api/v1/projects/{$this->p}/repo/fetch", ['force' => true]);
        $this->assertSame(202, $r->status);
        $id = (int) $r->decoded()['data']['analysis_id'];
        $this->assertSame(['queued', 1, 'user'], array_values($this->app->db()->one('SELECT status, force_analysis, trigger_kind FROM analyses WHERE id = ?', [$id])));
        $audit = json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'repo.fetch_now' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame([$id, true], [$audit['analysis_id'], $audit['force'] ?? null]);
        $this->assertSame(true, $admin->get("/api/v1/analyses/{$id}")->decoded()['data']['force_analysis']);
        // 일반 요청은 force 없음(감사에도 없음)
        $this->app->db()->execute("UPDATE analyses SET status = 'cancelled', finished_at = ? WHERE id = ?", [TestDb::T0, $id]);
        $id2 = (int) $admin->post("/api/v1/projects/{$this->p}/repo/fetch")->decoded()['data']['analysis_id'];
        $this->assertSame(0, (int) $this->app->db()->value('SELECT force_analysis FROM analyses WHERE id = ?', [$id2]));
        $this->assertSame(false, array_key_exists('force', json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'repo.fetch_now' ORDER BY id DESC LIMIT 1"), true)));
        // 변경 없음 행의 API 필드(분석·이력·비교·연결 상태)
        $this->app->db()->execute("UPDATE analyses SET status = 'running', started_at = ? WHERE id = ?", [TestDb::T0, $id2]);
        $this->app->db()->transaction(fn (Db $db) => UnchangedSourceCheck::record($db, $this->p, $this->conn(), $this->check()->evaluate($this->p), TestDb::T0, $id2), write: true);
        $kim = $this->as('kim');
        $a = $kim->get("/api/v1/analyses/{$id2}")->decoded()['data'];
        $this->assertSame(['succeeded', true, 1, TestDb::T0, $b1, null], [$a['status'], $a['unchanged'], $a['unchanged_check_count'], $a['unchanged_last_checked_at'], $a['unchanged_base']['analysis_id'], $a['run']]);
        $h = $kim->get("/api/v1/projects/{$this->p}/history")->decoded();
        $this->assertSame([true, false], [$h['data'][0]['unchanged'], $h['data'][count($h['data']) - 1]['unchanged']]);
        $this->assertSame([409, 'analysis_unchanged'], [($r = $kim->get("/api/v1/analyses/{$id2}/comparison"))->status, $r->decoded()['error']['code']]);
        $v = $kim->get("/api/v1/projects/{$this->p}/repo")->decoded()['data'];
        $this->assertSame(['unchanged', $b1], [$v['last_check']['result'], $v['last_analyzed']['analysis_id']]);
        $this->assertSame(false, array_key_exists('location', $v), '일반 사용자에게 주소 없음');
    }

    /**
     * P27C(사용자 결정 2026-10-08): 실패 뒤 "변경 없음" 확인이 있어도 실패 프로젝트로 남고 실제 분석이 성공해야 빠진다. 연속 실패는 변경 없음에 중립.
     * 실패 뒤 변경 없음이 나오는 실제 경로 = 같은 헤드에서의 실패(가져오기 일시 오류·강제 분석 실패 등). 새 커밋에서 실패했으면 다음 확인은 재분석.
     */
    public function testFailureStaysUntilRealSuccessAndUnchangedIsNeutral(): void
    {
        $db = $this->app->db();
        $this->app->setUnchangedCheck($this->check());
        $admin = ['id' => $this->u['admin'], 'role' => 'admin'];
        $failed = fn (): array => array_column($this->app->dashboard()->summary($admin)['failed_projects'], 'project_id');
        $b1 = $this->base(self::C1, ['finished_at' => '2026-10-02T10:00:00Z']);
        $this->assertSame([], $failed(), '성공뿐이면 실패 아님');
        // 주기 일정(연속 실패 1, 알림 1건 — 읽음)
        $this->app->schedules()->save($this->actor(), $this->u['admin'], $this->p, ['enabled' => true, 'interval_days' => 1, 'run_time' => '03:00']);
        $f1 = TestDb::analysis($db, $this->p, $this->u['admin'], ['status' => 'failed', 'source_kind' => 'git', 'trigger_kind' => 'schedule', 'requested_by' => null,
            'failure_code' => 'repo_unreachable', 'finished_at' => '2026-10-02T11:00:00Z', 'total_count' => null, 'new_count' => null, 'resolved_count' => null,
            'reopened_count' => null, 'unchanged_count' => null]);
        $this->app->scheduleOutcomes()->onEnded($f1);
        $db->execute('UPDATE notifications SET read_at = ?', [TestDb::T0]);
        $notif = $db->all('SELECT id, dedupe_key, read_at, params FROM notifications ORDER BY id');
        $this->assertSame(1, count($notif));
        $this->assertSame([$this->p], $failed(), '실패');
        // 같은 헤드(C1) — 주기 도래 2번 → 변경 없음(접힘). 실패 목록·연속 실패·알림은 그대로
        $next = fn () => $this->app->clock->advance(strtotime((string) $db->value('SELECT next_run_at FROM project_schedules WHERE project_id = ?', [$this->p])) + 30 - $this->app->clock->now()->getTimestamp());
        $next();
        $this->assertSame(1, $this->app->scheduleTicker()->tick()['unchanged']);
        $next();
        $this->assertSame(1, $this->app->scheduleTicker()->tick()['unchanged']);
        $nc = (int) $db->value('SELECT id FROM analyses WHERE project_id = ? AND no_change = 1', [$this->p]);
        $this->assertSame([2, $b1], [(int) $db->value('SELECT unchanged_check_count FROM analyses WHERE id = ?', [$nc]), (int) $db->value('SELECT unchanged_base_analysis_id FROM analyses WHERE id = ?', [$nc])]);
        $this->assertSame([$this->p], $failed(), '변경 없음 확인 뒤에도 실패 유지');
        $s = $db->one('SELECT consecutive_failures, first_failure_analysis_id, last_result, last_error_code FROM project_schedules WHERE project_id = ?', [$this->p]);
        $this->assertSame([1, $f1, 'failed', 'repo_unreachable'], [(int) $s['consecutive_failures'], (int) $s['first_failure_analysis_id'], $s['last_result'], $s['last_error_code']], '연속 실패 중립');
        $this->assertSame($notif, $db->all('SELECT id, dedupe_key, read_at, params FROM notifications ORDER BY id'), '알림·읽음 상태 그대로');
        // 프로젝트 화면 최근 분석 카드 = 실제 분석(실패), 이력에는 변경 없음 행이 보인다
        $this->assertSame($f1, $this->app->analyses()->list($this->p, null, 1, 0, null, null, true)[0][0]['id']);
        $this->assertSame([$nc, true], [$this->app->analyses()->list($this->p, null, 1, 0)[0][0]['id'], $this->app->analyses()->list($this->p, null, 1, 0)[0][0]['unchanged']]);
        $this->assertSame([$nc, true], [$this->app->history()->list($this->p, 20, 0)[0][0]['analysis_id'], $this->app->history()->list($this->p, 20, 0)[0][0]['unchanged']]);
        $html = (new TestClient($this->app));
        $this->assertSame(200, $html->login('admin', 'Passw0rd!x')->status);
        $page = $html->get("/projects/{$this->p}")->body;
        $this->assertContains('/analyses/' . $f1, $page, '최근 분석 카드 = 실패한 실제 분석');
        // 다음 실패도 같은 연속(새 알림 없이 횟수만 갱신)
        $f2 = TestDb::analysis($db, $this->p, $this->u['admin'], ['status' => 'failed', 'source_kind' => 'git', 'trigger_kind' => 'schedule', 'requested_by' => null,
            'failure_code' => 'repo_timeout', 'finished_at' => '2026-10-04T11:00:00Z', 'total_count' => null, 'new_count' => null, 'resolved_count' => null,
            'reopened_count' => null, 'unchanged_count' => null]);
        $this->app->scheduleOutcomes()->onEnded($f2);
        $this->assertSame([2, $f1], [(int) $db->value('SELECT consecutive_failures FROM project_schedules WHERE project_id = ?', [$this->p]),
            (int) $db->value('SELECT first_failure_analysis_id FROM project_schedules WHERE project_id = ?', [$this->p])]);
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM notifications'), '같은 연속은 한 건');
        // 실제 분석 성공 → 실패 해제·연속 0
        $ok = $this->base(self::C1, ['trigger_kind' => 'schedule', 'requested_by' => null, 'finished_at' => '2026-10-05T11:00:00Z']);
        $this->app->scheduleOutcomes()->onEnded($ok);
        $this->assertSame([], $failed(), '실제 성공 뒤 해제');
        $this->assertSame(0, (int) $db->value('SELECT consecutive_failures FROM project_schedules WHERE project_id = ?', [$this->p]));
    }

    public function testNewCommitFailureIsReanalyzedNotUnchanged(): void
    {
        $this->base(self::C1);
        // 새 커밋 C2를 가져와 분석하다 실패(도구 오류 등) → 같은 헤드 C2 확인은 기준(C1)과 달라 재분석(변경 없음이 아님)
        TestDb::analysis($this->app->db(), $this->p, $this->u['admin'], ['status' => 'failed', 'source_kind' => 'git', 'source_revision' => self::C2, 'failure_code' => 'tool_crash',
            'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);
        $this->head = self::C2;
        $this->assertSame(['changed', 'revision_differs'], [($r = $this->check()->evaluate($this->p))['decision'], $r['reason']]);
        // 헤드가 기준 리비전으로 되돌아가면(되돌리기·강제 푸시) 변경 없음 — 이때도 실패 표시는 유지(위 시험)
        $this->head = self::C1;
        $this->assertSame('unchanged', $this->check()->evaluate($this->p)['decision']);
    }
}
