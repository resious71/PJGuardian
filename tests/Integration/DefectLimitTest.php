<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Services\Defects\DefectLimitExceededException;
use PJGuardian\Services\Queue\AnalysisQueue;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\DefectHarness;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 프로젝트 결함 수 경고·상한(P19C, [limits] project_defect_warn / project_defect_max) — 도구 없이 결과 NDJSON을 만들어 실제 반영 경로(AnalysisFinalizer)를 탄다.
 * - 상한: 이번에 감지한 결함만으로 max를 넘으면 쓰기 전에 DefectLimitExceededException(결함·분석 그대로), 같으면 통과, 0이면 제한 없음
 * - 경고: 현재 결함(해결 아님)이 warn을 넘으면 활성 admin에게만 defect_count_warn(프로젝트당 한 건, 건수 갱신·읽음 유지), 프로젝트 화면 경고 한 줄
 * - 오류 코드 카탈로그·실패 문구, 결함이 많은 파일의 임시 저장소 경로도 finalize에서 쓰인다
 */
final class DefectLimitTest extends TestCase
{
    private DefectHarness $h;
    private int $p;
    private string $dir;
    private int $seq = 0;

    private function boot(array $limits, array $analysis = []): void
    {
        $root = $this->tempDir('deflimit');
        $this->h = new DefectHarness($root, ['limits' => $limits] + ($analysis === [] ? [] : ['analysis' => $analysis]));
        $this->p = $this->h->project('lim', 'python');
        $this->dir = $root . '/src';
        Fs::ensureDir($this->dir);
        $body = '';
        for ($i = 1; $i <= 20; $i++) {
            $body .= "v{$i} = {$i}\n";
        }
        file_put_contents($this->dir . '/a.py', $body);
    }

    /** a.py의 1..n줄에 결함 → 반영(통계 또는 예외) */
    private function analyze(int $n): ?array
    {
        $id = $this->h->startAnalysis($this->p, 'ruff');
        $results = $this->h->app->resultsDir();
        Fs::ensureDir($results);
        $nd = "{$results}/{$id}.ndjson";
        $out = '';
        for ($l = 1; $l <= $n; $l++) {
            $out .= json_encode(['file' => 'a.py', 'line' => $l, 'column' => 1, 'rule_key' => 'ruff:F401', 'tool' => 'ruff', 'language' => 'python', 'message' => "m{$l}"]) . "\n";
        }
        file_put_contents($nd, $out);
        file_put_contents("{$results}/{$id}.scope", "a.py\n");
        $o = AnalysisOutcome::succeeded(['total_count' => $n, 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0],
            ['result_file' => $nd, 'scope_file' => "{$results}/{$id}.scope", 'tool_version' => '0.16.10', 'config_source' => 'server_default', 'config_hash' => str_repeat('a', 64)]);
        $this->seq = $id;
        return $this->h->app->analysisFinalizer()->finalize($id, $this->h->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]), $this->dir, $o);
    }

    public function testMaxRejectsBeforeWritingAndWarnNotifiesAdminsOnly(): void
    {
        $this->boot(['project_defect_warn' => '3', 'project_defect_max' => '5']);
        $db = $this->h->app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $admin2 = TestDb::user($db, 'admin2', 'admin');
        $user = TestDb::user($db, 'lee', 'user');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$this->p, $user]);
        // 경고 기준 이하: 알림 없음
        $this->assertSame(3, $this->analyze(3)['new']);
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM notifications WHERE kind = 'defect_count_warn'"));
        // 상한과 같음(5): 통과, 경고 기준(3) 넘음 → 활성 admin 둘에게만
        $this->assertSame(2, $this->analyze(5)['new']);
        $rows = $db->all("SELECT user_id, project_id, analysis_id, params, dedupe_key FROM notifications WHERE kind = 'defect_count_warn' ORDER BY user_id");
        $this->assertSame([$admin, $admin2], array_map('intval', array_column($rows, 'user_id')), '활성 admin만(일반 사용자·담당자 아님)');
        $this->assertSame(['count' => 5, 'warn' => 3], json_decode((string) $rows[0]['params'], true));
        $this->assertSame("defect_count_warn:{$this->p}", $rows[0]['dedupe_key']);
        // 읽음 표시 뒤 다시 넘음(4) → 같은 한 건의 건수만 갱신(읽음 유지)
        $db->execute("UPDATE notifications SET read_at = '2026-10-02T00:00:00Z' WHERE user_id = ?", [$admin]);
        $this->analyze(4);
        $this->assertSame(2, (int) $db->value("SELECT COUNT(*) FROM notifications WHERE kind = 'defect_count_warn'"));
        $r = $db->one("SELECT params, read_at, analysis_id FROM notifications WHERE kind = 'defect_count_warn' AND user_id = ?", [$admin]);
        $this->assertSame([4, '2026-10-02T00:00:00Z', $this->seq], [json_decode((string) $r['params'], true)['count'], $r['read_at'], (int) $r['analysis_id']]);
        // 상한 초과(6): 쓰기 전에 예외 — 결함·분석 행 그대로(running → 자식이 실패로 기록)
        $before = $db->all('SELECT * FROM defects ORDER BY id');
        $this->assertThrows(DefectLimitExceededException::class, fn () => $this->analyze(6), '결함 6건 > 상한 5건');
        $this->assertSame($before, $db->all('SELECT * FROM defects ORDER BY id'), '부분 반영 없음');
        $this->assertSame('running', $db->value('SELECT status FROM analyses WHERE id = ?', [$this->seq]));
        // 분석 자식이 기록하는 실패(같은 코드·카탈로그·문구)
        $this->assertTrue($this->h->app->analysisQueue()->complete($this->seq, AnalysisOutcome::failed('defect_limit_exceeded', '결함 6건 > 상한 5건')));
        $this->assertSame(['failed', 'defect_limit_exceeded'], array_values($db->one('SELECT status, failure_code FROM analyses WHERE id = ?', [$this->seq])));
        $cat = ErrorCatalog::describe('analysis', 'defect_limit_exceeded');
        $this->assertSame([true, '결함 수 상한 초과'], [$cat['known'], $cat['title']]);
        $this->assertContains('제외 패턴', $cat['action']);
        $this->assertContains('허용 상한', AnalysisQueue::failureMessage('defect_limit_exceeded'));
        // 일반 사용자: 알림 목록·개수에 admin 전용 종류 없음, 프로젝트 화면에는 경고 한 줄(누구나 — 결함 수는 볼 수 있는 정보)
        $lee = new TestClient($this->h->app);
        $this->assertSame(200, $lee->login('lee', 'Passw0rd!x')->status);
        $this->assertSame([], array_values(array_filter($lee->get('/api/v1/notifications')->decoded()['data'], static fn (array $n): bool => $n['kind'] === 'defect_count_warn')));
        $page = $lee->get("/projects/{$this->p}")->body;
        $this->assertContains('data-defect-count-warn', $page);
        $this->assertContains('결함이 4건으로 많습니다(경고 기준 3건)', $page);
        $adm = new TestClient($this->h->app);
        $this->assertSame(200, $adm->login('admin', 'Passw0rd!x')->status);
        $items = array_values(array_filter($adm->get('/api/v1/notifications')->decoded()['data'], static fn (array $n): bool => $n['kind'] === 'defect_count_warn'));
        $this->assertSame([1, "/projects/{$this->p}"], [count($items), $items[0]['href']]);
    }

    public function testZeroMeansNoLimitAndSpillPathRunsInFinalize(): void
    {
        $this->boot(['project_defect_warn' => '0', 'project_defect_max' => '0'], ['file_defect_spill_threshold' => '5']);
        $stats = $this->analyze(20);
        $this->assertSame([20, 20, 1, 20], [$stats['new'], $stats['ingest']['count'], $stats['ingest']['spilled_files'], $stats['ingest']['spill_peak_rows']]);
        $this->assertSame(0, (int) $this->h->app->db()->value('SELECT COUNT(*) FROM notifications'));
        $this->assertSame([], Fs::listDir($this->h->root . '/work/spill'), '임시 SQLite 정리');
        // 반영 연결의 캐시 = database.analysis_cache_mb(기본 128MB, KiB 음수 표기)
        $this->assertSame(-128 * 1024, (int) $this->h->app->db()->value('PRAGMA cache_size'));
        $page = (new TestClient($this->h->app));
        TestDb::user($this->h->app->db(), 'admin', 'admin');
        $this->assertSame(200, $page->login('admin', 'Passw0rd!x')->status);
        $this->assertNotContains('data-defect-count-warn', $page->get("/projects/{$this->p}")->body, '0 = 경고 없음');
    }
}
