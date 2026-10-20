<?php

declare(strict_types=1);

/**
 * Run 측정(P20R 6절): 결함 N건 프로젝트에 Run R개를 실제 반영 경로(DefectIngest + DefectStore)로 만들고(Run마다 파일 5~10% 변경,
 * Run 사이에 수동 상태·담당자 변경 이력), 반영 시간과 과거 Run 조회 시간을 잰다. 임시 폴더(data/work/bench_runs_*)만 쓴다.
 *
 *   php tools/bench_runs.php [--defects=100000] [--files=1000] [--runs=50] [--keep] [--users=100]
 *     --keep   측정 뒤 DB를 남긴다(부하 시험 load_basic.mjs --scenario=runs에 쓴다). --users=N과 함께 u001~uNNN(비밀번호 PJG_PASSWORD,
 *              이 프로젝트에 매핑된 그룹의 구성원)을 만든다.
 *     --auto-checkpoint  반영 연결의 자동 체크포인트를 끄지 않는다(P20S 이전 동작, 비교용). 기본은 운영과 같게 끄고 Run 사이에 체크포인트
 *     --every=N  N번째 Run마다 반영 시간을 출력(기본 10)
 *     --synthetic  (P21) 반영 경로 대신 저장 형태(분석 행·결함·존재 구간·자동/수동 이력·Run 요약)를 직접 채운다. 분석 수백 건의 조회 측정용
 *                  (실제 반영은 분석마다 WAL 체크포인트까지 수 초~수십 초라 200건을 만들기 어렵다. 반영 시간은 실제 경로로 따로 잰다)
 *     --change=A-B  Run마다 바꿀 파일 비율 %(기본 5-10). 분석 수백 건을 만들 때는 1-2처럼 낮춰 결함 표가 너무 커지지 않게
 *   php tools/bench_runs.php --measure=<DB 파일> [--project=<id>]   이미 있는 DB(예: 개발 DB 사본)에서 조회만 잰다
 * 출력: Run별 반영 시간 요약(첫·중간·마지막, 평균), 마지막 Run 반영 중 다른 연결의 쓰기 대기(write_probe), 조회 표(중앙값, 3회).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Services\Defects\DefectIngest;
use PJGuardian\Services\Defects\DefectStore;
use PJGuardian\Services\Defects\ScopeList;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\SystemClock;
use PJGuardian\Tests\Support\TestApp;

ini_set('memory_limit', '1024M');
$opt = getopt('', ['defects:', 'files:', 'runs:', 'keep', 'users:', 'measure:', 'project:', 'auto-checkpoint', 'every:', 'change:', 'synthetic', 'cache-mb:']);
[$chMin, $chMax] = array_map('intval', explode('-', (string) ($opt['change'] ?? '5-10')) + [1 => 10]);
$defects = (int) ($opt['defects'] ?? 100000);
$files = (int) ($opt['files'] ?? 1000);
$runCount = (int) ($opt['runs'] ?? 50);
mt_srand(20261005);

function nowAt(int $k, int $minute = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-09-01T00:00:00Z') + $k * 3600 + $minute * 60);
}

/** 작업 디렉토리 + NDJSON + 범위 목록. $tags[파일] = 그 파일 결함 줄의 버전 표지(바뀌면 결함 전체가 새 시그니처) */
function writeProject(string $dir, array $tags, int $per, ?array $only = null): array
{
    $nd = fopen($dir . '.ndjson', 'wb');
    $scope = fopen($dir . '.scope', 'wb');
    foreach ($tags as $f => $tag) {
        $rel = sprintf('src/pkg%03d/mod_%05d.py', intdiv($f, 100), $f);
        $lines = [];
        for ($i = 1; $i <= $per; $i++) {
            $lines[] = "value_{$i} = undefined_{$tag}_{$i}  # 결함 줄 {$i}";
            fwrite($nd, json_encode(['v' => 1, 'tool' => 'ruff', 'language' => 'python', 'rule_key' => 'ruff:F' . (821 + $i % 7), 'file' => $rel, 'line' => $i,
                'column' => 11, 'end_line' => $i, 'end_column' => 20, 'message' => "Undefined name `undefined_{$tag}_{$i}`", 'syntax_error' => false, 'tool_severity' => 'error'],
                JSON_UNESCAPED_UNICODE) . "\n");
        }
        if ($only === null || isset($only[$f])) {
            Fs::ensureDir(dirname($dir . '/' . $rel));
            file_put_contents($dir . '/' . $rel, implode("\n", $lines) . "\n");
        }
        fwrite($scope, $rel . "\n");
    }
    fclose($nd);
    fclose($scope);
    return ['ndjson' => $dir . '.ndjson', 'scope' => $dir . '.scope'];
}

function reflectRun(Db $db, int $projectId, int $userId, string $dir, array $p, string $now, ?string $probeDb): array
{
    $proc = null;
    $stop = $dir . '.probe.stop';
    $out = $dir . '.probe.json';
    if ($probeDb !== null) {
        @unlink($stop);
        $proc = proc_open([PHP_BINARY, PJG_ROOT . '/tools/lib/write_probe.php', $probeDb, $stop, '30000'],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        usleep(500_000);
    }
    $db->execute("INSERT INTO analyses (project_id, status, source_kind, trigger_kind, requested_by, tool, queued_at, started_at) VALUES (?, 'running', 'upload', 'user', ?, 'ruff', ?, ?)",
        [$projectId, $userId, $now, $now]);
    $aid = $db->lastInsertId();
    $t0 = hrtime(true);
    (new DefectIngest($db, 20000, DefectIngest::spillRoot($dir . '_work'), 'b'))->load($p['ndjson'], $dir); // P19C: 운영과 같은 임계
    $t1 = hrtime(true);
    $stats = (new DefectStore($db))->apply($aid, $projectId, ScopeList::fromFile($p['scope']), new ExcludeMatcher(['node_modules'], []), $dir,
        ['tool_version' => '0.16.10', 'config_source' => 'server_default', 'config_hash' => str_repeat('a', 64)], null,
        ['server_default' => ['node_modules'], 'project' => [], 'config_files' => []], $now);
    $t2 = hrtime(true);
    $probe = null;
    if ($proc !== null) {
        usleep(300_000);
        touch($stop);
        proc_close($proc);
        $probe = json_decode((string) @file_get_contents($out), true);
        @unlink($stop);
        @unlink($out);
        @unlink($out . '.err');
    }
    return ['ingest_s' => ($t1 - $t0) / 1e9, 'store_s' => ($t2 - $t1) / 1e9, 'tx_ms' => $stats['tx_ms'], 'stats' => $stats, 'probe' => $probe];
}

/** 수동 변경 흉내: 결함 일부의 상태·담당자를 바꾸고 이력(manual)을 남긴다(DefectUpdate와 같은 이력 형식) */
function manualChanges(Db $db, int $projectId, array $users, int $count, string $at): void
{
    $ids = array_map('intval', array_column($db->all('SELECT id FROM defects WHERE project_id = ? ORDER BY random() LIMIT ?', [$projectId, $count]), 'id'));
    $statuses = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'];
    $db->transaction(function (Db $db) use ($ids, $users, $statuses, $at): void {
        foreach ($ids as $k => $id) {
            $row = $db->one('SELECT status, assignee_id FROM defects WHERE id = ?', [$id]);
            if ($k % 2 === 0) {
                $new = $statuses[mt_rand(0, 5)];
                if ($new === $row['status']) {
                    continue;
                }
                $db->execute('UPDATE defects SET status = ?, version = version + 1 WHERE id = ?', [$new, $id]);
                $db->execute("INSERT INTO defect_history (defect_id, kind, field, old_value, new_value, changed_by, changed_at) VALUES (?, 'manual', 'status', ?, ?, ?, ?)",
                    [$id, $row['status'], $new, $users[0], $at]);
            } else {
                $new = $users[mt_rand(0, count($users) - 1)];
                $db->execute('UPDATE defects SET assignee_id = ?, version = version + 1 WHERE id = ?', [$new, $id]);
                $db->execute("INSERT INTO defect_history (defect_id, kind, field, old_value, new_value, changed_by, changed_at) VALUES (?, 'manual', 'assignee', ?, ?, ?, ?)",
                    [$id, $row['assignee_id'] === null ? null : (string) $row['assignee_id'], (string) $new, $users[0], $at]);
            }
        }
    });
}

/** @return float 중앙값(ms), 3회 */
function timeIt(callable $fn): float
{
    $t = [];
    for ($i = 0; $i < 3; $i++) {
        $s = hrtime(true);
        $fn();
        $t[] = (hrtime(true) - $s) / 1e6;
    }
    sort($t);
    return $t[1];
}

function measure(App $app, int $pid): void
{
    // 조회는 웹 연결과 같은 SQLite 기본 캐시로 잰다(반영 연결의 큰 캐시는 P19C 반영 전용 — 이전 측정과 비교 가능하게)
    $app->db()->pdo()->exec('PRAGMA cache_size = -2000');
    $runs = $app->runs();
    $q = $app->defectQuery();
    $code = $app->defectCode();
    $latest = (int) $runs->status($pid)['latest'];
    $targets = array_values(array_unique([1, max(1, intdiv($latest, 2)), max(1, $latest - 1), $latest]));
    $f = static fn (array $q) => DefectFilter::fromQuery($q);
    $rows = [];
    foreach ($targets as $n) {
        $run = $runs->resolve($pid, (string) $n);
        $src = $run === null ? null : $runs->source($pid, $run);
        $label = $run === null ? "Run {$n}(최신 = 현재 표)" : "Run {$n}";
        $exist = $runs->existCount($pid, $n);
        $sample = $q->list($pid, $f([]), 1, 0, $src, $run)[0][0]['id'] ?? null;
        // P20S: 목록·요약은 전체 복원(이전 구현: forceFullRestore, 요약은 복원 계산)과 최적화(이후)를 함께 잰다
        $cases = [
            '목록 첫 페이지' => fn (bool $x) => $q->list($pid, $f([]), 50, 0, $src, $run, $x),
            '목록 상태 필터(open,in_progress)' => fn (bool $x) => $q->list($pid, $f(['status' => 'open,in_progress']), 50, 0, $src, $run, $x),
            '목록 담당자 필터(none)' => fn (bool $x) => $q->list($pid, $f(['assignee' => 'none']), 50, 0, $src, $run, $x),
            '목록 정렬(severity desc)' => fn (bool $x) => $q->list($pid, $f(['sort' => 'severity', 'order' => 'desc']), 50, 0, $src, $run, $x),
            '목록 정렬(rule)' => fn (bool $x) => $q->list($pid, $f(['sort' => 'rule']), 50, 0, $src, $run, $x),
            // P21U: 결함 표 Run·최근 감지 열 정렬(마지막 감지 분석의 Run 번호·종료 시각, 과거 Run이면 그 Run으로 한정)
            '목록 정렬(run desc)' => fn (bool $x) => $q->list($pid, $f(['sort' => 'run', 'order' => 'desc']), 50, 0, $src, $run, $x),
            '목록 정렬(last_seen desc)' => fn (bool $x) => $q->list($pid, $f(['sort' => 'last_seen', 'order' => 'desc']), 50, 0, $src, $run, $x),
            '목록 정렬(run) 마지막 페이지' => fn (bool $x) => $q->list($pid, $f(['sort' => 'run']), 50, max(0, $exist - 50), $src, $run, $x),
            '목록 검색(q=undefined_v0_7)' => fn (bool $x) => $q->list($pid, $f(['q' => 'undefined_v0_7']), 50, 0, $src, $run, $x),
            '목록 마지막 페이지' => fn (bool $x) => $q->list($pid, $f([]), 50, max(0, $exist - 50), $src, $run, $x),
            '요약(필터 없음)' => fn (bool $x) => $x || $run === null ? $q->summary($pid, $f([]), 200, $src)
                : $q->summaryStored($pid, (array) $runs->storedSummary($run['analysis_id']), $runs->memberSource($pid, $n), 200),
            '요약 + 상태 필터' => fn (bool $x) => $q->summary($pid, $f(['status' => 'open']), 200, $src),
        ];
        if ($sample !== null) {
            $cases['상세'] = fn (bool $x) => $q->detail((int) $sample, $run);
            $cases['코드(스냅샷 없음 → 없음/발췌)'] = fn (bool $x) => $code->get((int) $sample, 'window', $run);
        }
        foreach ($cases as $name => $fn) {
            $rows[] = [$label, $name, timeIt(fn () => $fn(true)), $run === null ? null : timeIt(fn () => $fn(false))];
        }
        $rows[] = [$label, "(존재 결함 {$exist}건)", null, null];
    }
    $rows[] = ['Run 목록', '첫 페이지(50)', timeIt(fn () => $runs->list($pid, 50, 0)), null];
    // P21 히스토리: 이력 목록(첫·마지막 쪽), 직전 성공 대비 비교(최신·중간·처음), 결함 전체 이력, 결함 목록 Run 필터
    $h = $app->history();
    $analyses = (int) $app->db()->value('SELECT COUNT(*) FROM analyses WHERE project_id = ?', [$pid]);
    $rows[] = ["히스토리(분석 {$analyses}건)", '이력 첫 쪽(20)', timeIt(fn () => $h->list($pid, 20, 0)), null];
    $rows[] = ["히스토리(분석 {$analyses}건)", '이력 마지막 쪽(20)', timeIt(fn () => $h->list($pid, 20, max(0, $analyses - 20))), null];
    foreach ([$latest, max(1, intdiv($latest, 2)), 1] as $n) {
        $aid = (int) $app->db()->value('SELECT id FROM analyses WHERE project_id = ? AND run_number = ?', [$pid, $n]);
        $rows[] = ["비교 Run {$n}", '요약(건수·분포·상위 10·변경·범위)', timeIt(fn () => $h->comparison($aid)), null];
    }
    $busy = (int) $app->db()->value('SELECT d.id FROM defects d JOIN defect_history hh ON hh.defect_id = d.id WHERE d.project_id = ? GROUP BY d.id ORDER BY COUNT(*) DESC LIMIT 1', [$pid]);
    if ($busy > 0) {
        $hc = (int) $app->db()->value('SELECT COUNT(*) FROM defect_history WHERE defect_id = ?', [$busy]);
        $rows[] = ["전체 이력(이력 {$hc}건)", '첫 쪽(200)', timeIt(fn () => $h->timeline($busy, 200, 0)), null];
    }
    $mid = (string) max(1, intdiv($latest, 2));
    $rows[] = ['결함 목록 Run 필터', "새로 생김 from_run={$mid}", timeIt(fn () => $q->list($pid, $f(['from_run' => $mid]), 50, 0)), null];
    $rows[] = ['결함 목록 Run 필터', "존재 in_run={$mid}~{$latest}", timeIt(fn () => $q->list($pid, $f(['in_run_from' => $mid, 'in_run_to' => (string) $latest]), 50, 0)), null];
    echo "\n| 대상 | 조회 | 전체 복원(이전, ms) | 최적화(이후, ms) |\n| --- | --- | --- | --- |\n";
    foreach ($rows as [$a, $b, $ms, $ms2]) {
        printf("| %s | %s | %s | %s |\n", $a, $b, $ms === null ? '' : number_format($ms, 0), $ms2 === null ? '' : number_format($ms2, 0));
    }
}

/**
 * 합성 이력(P21 측정용): Run 1에 결함 N건, 이후 Run마다 결함의 A~B%가 해결(자동 이력 not_detected, 구간 닫힘)되고 같은 수가 새로 생기며,
 * Run 사이에 0.5%를 수동 변경(상태·담당자 이력)한다. 분석 행에는 반영과 같은 건수·Run 번호·표지·Run 요약·직전 성공 기준을 넣는다.
 */
function synthetic(Db $db, int $pid, int $uid, array $assignees, int $n, int $files, int $runs, int $chMin, int $chMax): void
{
    $open = [];      // 존재하는(열린 구간) 결함 id
    $status = [];    // id → 상태
    $seq = 0;
    $prev = null;
    $t0 = microtime(true);
    $insDefect = $db->pdo()->prepare('INSERT INTO defects (project_id, signature, ordinal, tool, language, rule_key, file_path, line_no, message, first_seen_analysis_id, last_seen_analysis_id, created_at, updated_at)'
        . " VALUES (?, ?, 0, 'ruff', 'python', ?, ?, ?, ?, ?, ?, ?, ?)");
    $insRun = $db->pdo()->prepare('INSERT INTO defect_runs (defect_id, project_id, from_run, to_run) VALUES (?, ?, ?, NULL)');
    $insHist = $db->pdo()->prepare('INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, reason_code, analysis_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    for ($k = 1; $k <= $runs; $k++) {
        $now = nowAt($k);
        $db->transaction(function (Db $db) use (&$open, &$status, &$seq, &$prev, $k, $n, $files, $chMin, $chMax, $pid, $uid, $assignees, $now, $insDefect, $insRun, $insHist): void {
            // Run 사이 수동 변경(직전 Run 뒤)
            if ($k > 1) {
                $ids = array_rand($open, max(1, intdiv(count($open), 200)));
                foreach ((array) $ids as $id) {
                    $new = ['open', 'in_progress', 'postponed', 'resolved'][mt_rand(0, 3)];
                    if ($new !== $status[$id]) {
                        $insHist->execute([$id, 'status', $status[$id], $new, $uid, nowAt($k - 1, 30), 'manual', null, null]);
                        $db->execute('UPDATE defects SET status = ? WHERE id = ?', [$new, $id]);
                        $status[$id] = $new;
                    } else {
                        $a = $assignees[mt_rand(0, count($assignees) - 1)];
                        $insHist->execute([$id, 'assignee', null, (string) $a, $uid, nowAt($k - 1, 30), 'manual', null, null]);
                        $db->execute('UPDATE defects SET assignee_id = ? WHERE id = ?', [$a, $id]);
                    }
                }
            }
            $db->execute("INSERT INTO analyses (project_id, status, source_kind, trigger_kind, requested_by, tool, tool_version, config_source, config_hash, queued_at, started_at, finished_at,"
                . " total_count, new_count, resolved_count, reopened_count, unchanged_count, baseline_analysis_id, run_number) VALUES (?, 'succeeded', 'upload', 'user', ?, 'ruff', ?, 'server_default', ?, ?, ?, ?, 0, 0, 0, 0, 0, ?, ?)",
                [$pid, $uid, $k % 50 === 0 ? '0.17.0' : '0.16.10', str_repeat('a', 64), $now, $now, $now, $prev, $k]);
            $aid = $db->lastInsertId();
            // 해결: 열린 결함의 A~B%
            $resolved = 0;
            if ($k > 1) {
                $cnt = max(1, (int) round(count($open) * mt_rand($chMin, $chMax) / 100));
                foreach ((array) array_rand($open, $cnt) as $id) {
                    if ($status[$id] !== 'resolved') {
                        $insHist->execute([$id, 'status', $status[$id], 'resolved', null, $now, 'auto', 'not_detected', $aid]);
                        $db->execute("UPDATE defects SET status = 'resolved' WHERE id = ?", [$id]);
                        $resolved++;
                    }
                    $db->execute('UPDATE defect_runs SET to_run = ? WHERE defect_id = ? AND to_run IS NULL', [$k - 1, $id]);
                    unset($open[$id]);
                }
            }
            // 신규: Run 1은 N건, 이후 해결한 만큼
            $new = $k === 1 ? $n : max(1, (int) round($n * mt_rand($chMin, $chMax) / 100));
            for ($i = 0; $i < $new; $i++) {
                $seq++;
                $f = $seq % $files;
                $insDefect->execute([$pid, hash('sha256', "syn{$seq}"), 'ruff:F' . (821 + $seq % 7), sprintf('src/pkg%03d/mod_%05d.py', intdiv($f, 100), $f), 1 + intdiv($seq, $files) % 500,
                    "Undefined name `x{$seq}`", $aid, $aid, $now, $now]);
                $id = $db->lastInsertId();
                $insRun->execute([$id, $pid, $k]);
                $open[$id] = true;
                $status[$id] = 'open';
            }
            // 존재 결함의 마지막 감지 = 이 분석(범위 밖 없음)
            $detected = count($open);
            $byStatus = array_fill_keys(['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'], 0);
            foreach ($open as $id => $_) {
                $byStatus[$status[$id]]++;
            }
            $summary = ['total' => $detected, 'by_status' => $byStatus, 'by_severity' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'not_defined' => $detected], 'by_assignee' => ['none' => $detected]];
            $mark = (int) ($db->value('SELECT MAX(id) FROM defect_history') ?? 0);
            $db->execute('UPDATE analyses SET total_count = ?, new_count = ?, resolved_count = ?, unchanged_count = ?, run_history_mark = ?, run_summary = ?, severity_counts = ? WHERE id = ?',
                [$detected, $new, $resolved, $detected - $new, $mark, json_encode($summary), json_encode($summary['by_severity']), $aid]);
            $prev = $aid;
        });
        if ($k % 50 === 0 || $k === $runs) {
            printf("합성 Run %d: 존재 %d, 결함 표 %d행 (%.0f초)\n", $k, count($open), $seq, microtime(true) - $t0);
        }
    }
}

if (isset($opt['measure'])) {
    $file = (string) $opt['measure'];
    $app = App::create(PJG_ROOT, Config::fromArray(TestApp::rawConfig(['paths' => ['db_file' => $file, 'work_dir' => dirname($file), 'logs_dir' => dirname($file)]])));
    $projects = isset($opt['project']) ? [(int) $opt['project']] : array_map('intval', array_column($app->db()->all('SELECT id FROM projects ORDER BY id'), 'id'));
    foreach ($projects as $pid) {
        echo "\n## 프로젝트 {$pid}\n";
        measure($app, $pid);
    }
    exit(0);
}

$root = str_replace('\\', '/', PJG_ROOT) . '/data/work/bench_runs_' . bin2hex(random_bytes(3));
Fs::ensureDir($root . '/w');
$dbFile = $root . '/bench.sqlite';
try {
    (new Migrator(new Db($dbFile), PJG_ROOT . '/migrations', new SystemClock()))->migrate();
    $app = App::create(PJG_ROOT, Config::fromArray(TestApp::rawConfig(['paths' => ['db_file' => $dbFile, 'work_dir' => $root, 'logs_dir' => $root]])));
    $db = $app->db();
    // P20S: 운영과 같게 반영 연결의 자동 체크포인트를 끄고, Run 사이에 워커처럼 체크포인트한다(--auto-checkpoint면 이전 동작)
    if (!isset($opt['auto-checkpoint'])) {
        \PJGuardian\Support\WalCheckpoint::disableAuto($db);
        // P19C: 운영의 분석 반영 연결과 같은 캐시(database.analysis_cache_mb, --cache-mb=0이면 SQLite 기본)
        if (($cacheMb = (int) ($opt['cache-mb'] ?? 128)) > 0) {
            $db->pdo()->exec('PRAGMA cache_size = -' . ($cacheMb * 1024));
        }
    }
    $ckpt = [];
    $now = nowAt(0);
    $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('benchadmin', 'bench', 'admin', 'active', 'local', 'x', ?, ?)", [$now, $now]);
    $uid = $db->lastInsertId();
    $db->execute("INSERT INTO projects (name, language, created_at, updated_at) VALUES ('runs-bench', 'python', ?, ?)", [$now, $now]);
    $pid = $db->lastInsertId();
    $assignees = [$uid];
    $nUsers = (int) ($opt['users'] ?? 0);
    if ($nUsers > 0) {
        $hash = password_hash((string) getenv('PJG_PASSWORD'), PASSWORD_BCRYPT, ['cost' => 4]);
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('bench', ?, ?)", [$now, $now]);
        $gid = $db->lastInsertId();
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', ?, ?)", [$pid, $gid, $now]);
        for ($i = 1; $i <= $nUsers; $i++) {
            $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, password_kind, created_at, updated_at) VALUES (?, ?, 'user', 'active', 'local', ?, 'user', ?, ?)",
                [sprintf('u%03d', $i), sprintf('사용자 %03d', $i), $hash, $now, $now]);
            $u = $db->lastInsertId();
            $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?)', [$gid, $u, $now]);
            if ($i <= 20) {
                $assignees[] = $u;
            }
        }
    }
    if (isset($opt['synthetic'])) {
        synthetic($db, $pid, $uid, $assignees, $defects, $files, $runCount, $chMin, $chMax);
        printf("결함 표 %d행, 존재 구간 %d행, 이력 %d행, 분석 %d건, DB %.1f MB\n", (int) $db->value('SELECT COUNT(*) FROM defects'), (int) $db->value('SELECT COUNT(*) FROM defect_runs'),
            (int) $db->value('SELECT COUNT(*) FROM defect_history'), (int) $db->value('SELECT COUNT(*) FROM analyses'), filesize($dbFile) / 1048576);
        measure($app, $pid);
        echo "\nDB: {$dbFile}" . (isset($opt['keep']) ? " (남김, 프로젝트 {$pid})" : '') . "\n";
        return;
    }
    $per = intdiv($defects, $files);
    $tags = array_fill(0, $files, 'v0');
    $results = [];
    for ($k = 1; $k <= $runCount; $k++) {
        $changed = null;
        if ($k > 1) {
            // Run마다 파일 5~10%의 결함 줄이 바뀐다 → 그 파일 결함은 해결(미감지) + 새 결함
            $n = max(1, (int) round($files * mt_rand($chMin, $chMax) / 100));
            $changed = [];
            foreach ((array) array_rand($tags, $n) as $f) {
                $tags[$f] = "v{$k}";
                $changed[$f] = true;
            }
            manualChanges($db, $pid, $assignees, intdiv($defects, 100), nowAt($k - 1, 30)); // Run 사이 수동 변경 1%
        }
        $p = writeProject($root . '/w', $tags, $per, $changed);
        $r = reflectRun($db, $pid, $uid, $root . '/w', $p, nowAt($k), $k === $runCount ? $dbFile : null);
        if (!isset($opt['auto-checkpoint'])) {
            $r['ckpt'] = $app->walCheckpoint()->run(true);
            $ckpt[$k] = $r['ckpt']['ms'];
        }
        $results[$k] = $r;
        if ($k === 1 || $k % (int) ($opt['every'] ?? 10) === 0 || $k === $runCount) {
            printf("Run %2d: 신규 %d, 해결 %d, 재오픈 %d, 시그니처 %.1f초, 판정·반영 %.1f초, 쓰기 트랜잭션 %d ms%s%s\n", $k, $r['stats']['new'], $r['stats']['resolved'], $r['stats']['reopened'],
                $r['ingest_s'], $r['store_s'], $r['tx_ms'], $r['probe'] ? sprintf(', 다른 연결 쓰기 대기 최대 %.0f ms(평균 %.0f, 실패 %d)', $r['probe']['max_ms'], $r['probe']['avg_ms'], $r['probe']['failed']) : '',
                isset($r['ckpt']) ? sprintf(' / 분석 사이 체크포인트 %s %d ms(PASSIVE %d ms, TRUNCATE %s %d ms, WAL %.0fMB → %.0fMB)', $r['ckpt']['mode'], $r['ckpt']['ms'],
                $r['ckpt']['passive_ms'] ?? 0, $r['ckpt']['truncate'] ?? '-', $r['ckpt']['truncate_ms'] ?? 0, $r['ckpt']['wal_before'] / 1048576, $r['ckpt']['wal_after'] / 1048576) : '');
        }
    }
    $store = array_column($results, 'store_s');
    $tx = array_column($results, 'tx_ms');
    printf("\n반영(판정·반영) 평균 %.1f초, 최대 %.1f초 / 쓰기 트랜잭션 평균 %d ms, 최대 %d ms (Run %d개)\n", array_sum($store) / count($store), max($store),
        intdiv(array_sum($tx), count($tx)), max($tx), $runCount);
    if ($ckpt !== []) {
        printf("분석 사이 체크포인트 평균 %d ms, 최대 %d ms\n", intdiv(array_sum($ckpt), count($ckpt)), max($ckpt));
    }
    printf("결함 표 %d행, 존재 구간 %d행, 이력 %d행, DB %.1f MB\n", (int) $db->value('SELECT COUNT(*) FROM defects'), (int) $db->value('SELECT COUNT(*) FROM defect_runs'),
        (int) $db->value('SELECT COUNT(*) FROM defect_history'), filesize($dbFile) / 1048576);
    measure($app, $pid);
    echo "\nDB: {$dbFile}" . (isset($opt['keep']) ? " (남김, 프로젝트 {$pid})" : '') . "\n";
} finally {
    if (!isset($opt['keep'])) {
        unset($app, $db);
        gc_collect_cycles();
        Fs::removeTree($root);
    }
}
