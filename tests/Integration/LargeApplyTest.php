<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Defects\AnalysisNotRunningException;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Services\Defects\DefectIngest;
use PJGuardian\Services\Defects\DefectStore;
use PJGuardian\Services\Defects\ScopeList;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 대용량 반영 개선(P19C) — 마지막 감지 행별 갱신 제거의 **동일성**, 쓰기 건수, 롤백, 결함이 많은 한 파일(임시 SQLite)의 결과 동일성·정리, 마이그레이션 023.
 *
 * 동일성: 무작위 시나리오(신규·유지·해결·재오픈·파일 삭제·제외 패턴(범위 밖 유지)·수동 해결/상태 변경·실패·취소·변경 없음 행)를 반영하며
 * 매 분석 뒤 모든 결함의 마지막 감지(목록·상세·정렬)와 수정 시각을 "예전 규칙"(마지막으로 감지한 분석, 감지·자동 전환 때 그 분석 시각)으로 계산한 값과 비교한다.
 */
final class LargeApplyTest extends TestCase
{
    private App $app;
    private string $root;
    private int $p;
    private int $u;

    public function setUp(): void
    {
        $this->root = $this->tempDir('largeapply');
        $this->app = TestApp::make($this->root);
        $this->u = TestDb::user($this->app->db(), 'admin', 'admin');
        $this->p = TestDb::project($this->app->db(), 'big', 'python');
    }

    /** @return array<string, array<string, mixed>> (파일:줄:규칙) → 행 */
    private function rows(): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT * FROM defects WHERE project_id = ? ORDER BY id', [$this->p]) as $r) {
            $out["{$r['file_path']}:{$r['line_no']}:{$r['rule_key']}"] = $r;
        }
        return $out;
    }

    public function testRandomScenarioMatchesOldLastSeenRule(): void
    {
        mt_srand(19);
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u);
        $db = $this->app->db();
        $files = [];
        for ($i = 0; $i < 12; $i++) {
            $files["f{$i}.py"] = 15;
        }
        $rules = ['ruff:F401', 'ruff:E711'];
        $expSeen = [];   // 키 → 마지막으로 감지한 분석 id(예전 규칙)
        $expUpdated = []; // 키 → 예전 규칙의 updated_at(감지·자동 전환 때 그 분석 시각)
        $compared = 0;
        $exclude = [];
        for ($step = 1; $step <= 60; $step++) {
            $roll = mt_rand(1, 10);
            if ($roll === 1) {
                $f->failed(mt_rand(0, 1) === 0 ? 'failed' : 'cancelled');
                continue;
            }
            if ($roll === 2) {
                // 변경 없음 행(최신 실제 분석 판정에서 빠져야 한다)
                TestDb::analysis($db, $this->p, $this->u, ['source_kind' => 'git', 'source_revision' => 'x', 'no_change' => 1, 'total_count' => null,
                    'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null, 'finished_at' => '2026-10-01T23:59:00Z']);
                continue;
            }
            // 파일 삭제·제외 패턴(범위 밖 유지)
            $present = $files;
            if (mt_rand(1, 5) === 1) {
                unset($present['f' . mt_rand(0, 11) . '.py']);
            }
            $exclude = mt_rand(1, 6) === 1 ? ['f' . mt_rand(0, 11) . '.py'] : [];
            $db->execute('UPDATE projects SET exclude_patterns = ? WHERE id = ?', [json_encode($exclude), $this->p]);
            $hits = [];
            foreach ($present as $path => $lines) {
                if (in_array($path, $exclude, true)) {
                    continue;
                }
                for ($l = 1; $l <= $lines; $l++) {
                    if (mt_rand(1, 3) === 1) {
                        $hits[] = [$path, $l, $rules[$l % 2]];
                    }
                }
            }
            // 수동 상태 변경(해결·진행 중) — 마지막 감지·수정 시각은 반영 규칙만 따른다(수동 수정은 이 시험에서 updated_at을 바꾸지 않는 SQL)
            foreach (array_slice($this->rows(), 0, 30) as $r) {
                if (mt_rand(1, 8) === 1) {
                    $db->execute('UPDATE defects SET status = ?, version = version + 1 WHERE id = ?', [mt_rand(0, 1) === 0 ? 'resolved' : 'in_progress', (int) $r['id']]);
                }
            }
            // 취소와 겹친 반영(롤백): 결함 표가 그대로여야 한다
            if (mt_rand(1, 6) === 1) {
                $before = $this->rows();
                $aid = TestDb::analysis($db, $this->p, $this->u, ['status' => 'running', 'queued_at' => '2026-10-01T00:00:00Z', 'started_at' => '2026-10-01T00:00:00Z']);
                $db->execute('UPDATE analyses SET cancel_requested = 1 WHERE id = ?', [$aid]);
                $this->assertThrows(AnalysisNotRunningException::class, fn () => $this->applyHits($aid, $present, $hits), '취소 요청됨');
                $this->assertSame($before, $this->rows(), "취소된 반영은 last_seen·seen_live 포함 그대로({$step})");
                $db->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-01T00:00:00Z' WHERE id = ?", [$aid]);
            }
            $stats = $f->analyze($present, $hits);
            $aid = (int) $stats['analysis_id'];
            $at = (string) $db->value('SELECT finished_at FROM analyses WHERE id = ?', [$aid]);
            $rows = $this->rows();
            foreach ($hits as [$path, $line, $rule]) {
                $expSeen["{$path}:{$line}:{$rule}"] = $aid;
                $expUpdated["{$path}:{$line}:{$rule}"] = $at;
            }
            foreach ($db->all("SELECT d.file_path, d.line_no, d.rule_key FROM defect_history h JOIN defects d ON d.id = h.defect_id WHERE h.analysis_id = ? AND h.new_value = 'resolved'", [$aid]) as $h) {
                $expUpdated["{$h['file_path']}:{$h['line_no']}:{$h['rule_key']}"] = $at; // 자동 해결도 예전에 updated_at을 그 시각으로
            }
            // 비교: 상세·목록의 마지막 감지, 상세의 수정 시각
            $q = $this->app->defectQuery();
            [$items] = $q->list($this->p, DefectFilter::fromQuery([]), 500, 0);
            $listSeen = [];
            foreach ($items as $it) {
                $listSeen[$it['id']] = $it['last_seen']['analysis_id'];
            }
            foreach ($rows as $key => $r) {
                $d = $q->detail((int) $r['id']);
                $this->assertSame([$expSeen[$key], $expSeen[$key], $expUpdated[$key]], [$d['last_seen']['analysis_id'], $listSeen[(int) $r['id']], $d['updated_at']],
                    "단계 {$step} {$key}(seen_live {$r['seen_live']})");
                $compared++;
            }
            // 정렬 "최근 감지"(내림차순): 예전 값으로 정렬한 순서와 같다(같은 분석은 id 내림차순)
            [$sorted] = $q->list($this->p, DefectFilter::fromQuery(['sort' => 'last_seen', 'order' => 'desc']), 500, 0);
            $byKeyId = [];
            foreach ($rows as $key => $r) {
                $byKeyId[] = [(int) $r['id'], $expSeen[$key]];
            }
            usort($byKeyId, static fn (array $a, array $b): int => [$b[1], $b[0]] <=> [$a[1], $a[0]]);
            $this->assertSame(array_column($byKeyId, 0), array_column($sorted, 'id'), "단계 {$step} 최근 감지 정렬");
        }
        $this->assertTrue($compared > 5000, "비교한 (단계 × 결함) 수 {$compared}");
        fwrite(STDOUT, "      비교 {$compared}건\n");
    }

    /** RunFixture와 같은 반영(취소 시험용 — 이미 만든 running 분석에) */
    private function applyHits(int $aid, array $files, array $hits): array
    {
        $work = $this->root . '/w' . $this->p;
        $nd = $work . '.cancel.ndjson';
        usort($hits, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = '';
        foreach ($hits as $h) {
            $out .= json_encode(['file' => $h[0], 'line' => $h[1], 'column' => 1, 'rule_key' => $h[2], 'tool' => 'ruff', 'language' => 'python', 'message' => "m {$h[0]}:{$h[1]}"]) . "\n";
        }
        file_put_contents($nd, $out);
        (new DefectIngest($this->app->db()))->load($nd, $work);
        $scope = $this->app->projects()->matcherFor($this->p);
        return (new DefectStore($this->app->db()))->apply($aid, $this->p, ScopeList::fromPaths(array_keys($files)), $scope, $work,
            ['tool_version' => '0.16.10', 'config_source' => 'server_default', 'config_hash' => str_repeat('a', 64)], null, ['server_default' => [], 'project' => [], 'config_files' => []], '2026-10-01T00:00:00Z');
    }

    public function testReapplyWritesNothingAndTenPercentWritesOnlyChanges(): void
    {
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u);
        $files = [];
        $hits = [];
        for ($i = 0; $i < 20; $i++) {
            $files["m{$i}.py"] = 10;
            for ($l = 1; $l <= 10; $l++) {
                $hits[] = ["m{$i}.py", $l];
            }
        }
        $s1 = $f->analyze($files, $hits);
        $this->assertSame([200, 0, 0], [$s1['new'], $s1['writes']['seen'], $s1['writes']['frozen']]);
        $snap = static fn (Db $db, int $p): string => md5(json_encode($db->all('SELECT * FROM defects WHERE project_id = ? ORDER BY id', [$p])));
        $before = $snap($this->app->db(), $this->p);
        $s2 = $f->analyze($files, $hits);
        $this->assertSame([0, 0, 0, 200], [$s2['new'], $s2['writes']['seen'], $s2['writes']['frozen'], $s2['unchanged']], '같은 결과 재반영');
        $this->assertSame($before, $snap($this->app->db(), $this->p), '결함 표 쓰기 0건(모든 열 그대로)');
        // 10% 변경: 2개 파일의 결함을 없애고 1개 파일에 새 결함(파일 줄 수 늘림)
        $files['m0.py'] = 12;
        $hits2 = array_values(array_filter($hits, static fn (array $h): bool => !in_array($h[0], ['m1.py', 'm2.py'], true)));
        $hits2[] = ['m0.py', 11];
        $hits2[] = ['m0.py', 12];
        $s3 = $f->analyze($files, $hits2);
        $this->assertSame([2, 20, 0, 20], [$s3['new'], $s3['resolved'], $s3['writes']['seen'], $s3['writes']['frozen']], '변경분만(해결 20건 고정, 신규 2건)');
    }

    public function testSpillPathMatchesMemoryPathAndCleansUp(): void
    {
        $work = $this->root . '/src';
        Fs::ensureDir($work);
        // 한 파일에 결함 많음(같은 줄 여러 규칙, 같은 코드 줄 반복 → 같은 시그니처 여러 순번, 열 없음/있음 섞음) + 작은 파일
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = $i % 7 === 0 ? 'x = 1' : "v{$i} = {$i}";
        }
        file_put_contents("{$work}/big.py", implode("\n", $lines) . "\n");
        file_put_contents("{$work}/small.py", "import os\n");
        $nd = $this->root . '/r.ndjson';
        $out = '';
        mt_srand(7);
        $rowsBig = [];
        for ($n = 0; $n < 400; $n++) {
            $line = mt_rand(1, 60);
            $rowsBig[] = ['file' => 'big.py', 'line' => $line, 'column' => mt_rand(0, 3) === 0 ? null : mt_rand(1, 4), 'end_line' => mt_rand(0, 2) === 0 ? null : $line,
                'end_column' => mt_rand(0, 2) === 0 ? null : mt_rand(1, 9), 'rule_key' => ['ruff:E501', 'ruff:F401', 'ruff:E711'][mt_rand(0, 2)], 'tool' => 'ruff', 'language' => 'python',
                'message' => 'm' . $n, 'syntax_error' => $n % 50 === 0];
        }
        foreach ($rowsBig as $r) {
            $out .= json_encode($r) . "\n";
        }
        $out .= json_encode(['file' => 'small.py', 'line' => 1, 'column' => 1, 'rule_key' => 'ruff:F401', 'tool' => 'ruff', 'language' => 'python', 'message' => 's']) . "\n";
        file_put_contents($nd, $out);
        $db = $this->app->db();
        $dump = static fn (): array => $db->all('SELECT signature, ordinal, tool, language, rule_key, file_path, line_no, col_no, end_line_no, end_col_no, message FROM temp.cur_defects ORDER BY signature, ordinal');
        $mem = (new DefectIngest($db))->load($nd, $work);
        $memRows = $dump();
        $spillDir = DefectIngest::spillRoot($this->root . '/work');
        $spill = (new DefectIngest($db, 50, $spillDir, '77'))->load($nd, $work);
        $this->assertSame([401, 2, 8, 1, 400], [$spill['count'], $spill['files'], $spill['syntax'], $spill['spilled_files'], $spill['spill_peak_rows']]);
        $this->assertSame([$mem['count'], $mem['files'], $mem['syntax']], [$spill['count'], $spill['files'], $spill['syntax']]);
        $this->assertSame($memRows, $dump(), '두 경로의 시그니처·순번·값이 같다');
        $this->assertSame([], is_dir($spillDir) ? Fs::listDir($spillDir) : [], '성공 뒤 임시 SQLite 없음');
        // 경계: 임계와 같은 수는 메모리 경로, 하나 넘으면 임시 저장소
        $this->assertSame(0, (new DefectIngest($db, 400, $spillDir, '77'))->load($nd, $work)['spilled_files']);
        $this->assertSame(1, (new DefectIngest($db, 399, $spillDir, '77'))->load($nd, $work)['spilled_files']);
        $this->assertSame($memRows, $dump());
        // 넘친 뒤 예외(형식 오류) → 임시 파일 지움
        file_put_contents($nd, $out . "{bad json\n");
        $this->assertThrows(\RuntimeException::class, fn () => (new DefectIngest($db, 50, $spillDir, '77'))->load($nd, $work));
        $this->assertSame([], Fs::listDir($spillDir), '예외 뒤에도 임시 SQLite 없음');
        // 분석 자식이 죽어 남은 파일: 분석 단위·기간 정리
        touch("{$spillDir}/77-0123456789ab.sqlite");
        touch("{$spillDir}/78-0123456789ab.sqlite");
        touch("{$spillDir}/notmine.txt");
        $this->assertSame(1, DefectIngest::cleanupSpill($this->root . '/work', '77'));
        $this->assertSame(0, DefectIngest::cleanupSpill($this->root . '/work', null, 3600), '1시간 안 된 것은 남김');
        touch("{$spillDir}/78-0123456789ab.sqlite", time() - 7200);
        $this->assertSame(1, DefectIngest::cleanupSpill($this->root . '/work', null, 3600));
        $this->assertSame(['notmine.txt'], Fs::listDir($spillDir), '모르는 파일은 건드리지 않음');
    }

    public function testMigration023KeepsNotificationsAndDefects(): void
    {
        $dir = $this->tempDir('m023');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $file) {
            if (basename($file) < '023') {
                copy($file, "{$dir}/m/" . basename($file));
            }
        }
        $clock = $this->app->clock;
        $db = new Db("{$dir}/x.sqlite");
        (new Migrator($db, "{$dir}/m", $clock))->migrate();
        $uid = TestDb::user($db, 'u1', 'admin');
        $pid = TestDb::project($db, 'p', 'python');
        $aid = TestDb::analysis($db, $pid, $uid);
        $db->execute("INSERT INTO defects (project_id, signature, ordinal, tool, language, rule_key, file_path, line_no, message, first_seen_analysis_id, last_seen_analysis_id, created_at, updated_at)"
            . " VALUES (?, ?, 0, 'ruff', 'python', 'ruff:F401', 'a.py', 1, 'm', ?, ?, '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')", [$pid, str_repeat('a', 64), $aid, $aid]);
        foreach (['k1', 'k2', 'k3'] as $k) {
            $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (?, 'schedule_failed', '{}', ?, '2026-10-01T00:00:00Z')", [$uid, $k]);
        }
        $db->execute("DELETE FROM notifications WHERE dedupe_key = 'k3'");
        $before = $db->all('SELECT * FROM notifications ORDER BY id');
        $this->assertThrows(\PDOException::class, static fn () => $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (?, 'defect_count_warn', '{}', 'w', '2026-10-01T00:00:00Z')", [$uid]));
        copy(PJG_ROOT . '/migrations/023_defect_seen_live.sql', "{$dir}/m/023_defect_seen_live.sql");
        $this->assertSame(['023'], (new Migrator($db, "{$dir}/m", $clock))->migrate());
        $this->assertSame($before, $db->all('SELECT * FROM notifications ORDER BY id'), '알림 그대로');
        $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (?, 'defect_count_warn', '{}', 'w', '2026-10-01T00:00:00Z')", [$uid]);
        $this->assertSame(4, $db->lastInsertId(), '지운 id를 다시 쓰지 않음');
        $this->assertSame([0, $aid], [(int) $db->value('SELECT seen_live FROM defects'), (int) $db->value('SELECT last_seen_analysis_id FROM defects')], '기존 결함은 옛 방식(seen_live 0)');
        $this->assertSame(['ok'], array_column($db->all('PRAGMA integrity_check'), 'integrity_check'));
        $this->assertSame([], $db->all('PRAGMA foreign_key_check'));
    }
}
