<?php

declare(strict_types=1);

/**
 * 결함 조회 측정(P31 B-3 — P19B 3.3 표와 같은 경우 + 과거 Run). 이미 만든 DB(예: bench_runs --synthetic --keep)를 **사본**으로 재고,
 * 경우마다 5회 중앙값(ms)과 실행 계획(EXPLAIN QUERY PLAN, 느린 경우)을 낸다. 웹 연결과 같은 SQLite 기본 캐시(2MB).
 *
 *   php tools/perf/query_bench.php --db=<sqlite> [--project=<id>] [--repeat=5] [--plans] [--json=<파일>]
 *
 * 원본 DB는 열지 않는다 — 시작할 때 VACUUM INTO로 data/work/qbench_*에 복사하고 끝나면 지운다(--keep이면 남김).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;

$opt = getopt('', ['db:', 'project:', 'repeat:', 'plans', 'json:', 'keep']);
$src = (string) ($opt['db'] ?? '');
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "--db=<sqlite 파일> 필요\n");
    exit(2);
}
$repeat = max(1, (int) ($opt['repeat'] ?? 5));
$work = PJG_ROOT . '/data/work/qbench_' . bin2hex(random_bytes(3));
mkdir($work, 0777, true);
$copy = $work . '/q.sqlite';
(new PDO('sqlite:' . $src))->exec('VACUUM INTO ' . (new PDO('sqlite::memory:'))->quote($copy));
$db = new Db($copy, 30000);
$pid = (int) ($opt['project'] ?? $db->value('SELECT project_id FROM defects GROUP BY project_id ORDER BY COUNT(*) DESC LIMIT 1'));
$config = Config::fromArray(TestApp::rawConfig(['paths' => ['work_dir' => $work, 'logs_dir' => $work]]));
$clock = new FixedClock('2026-10-14T00:00:00Z');
$app = new App(PJG_ROOT, $config, $clock, new Logger($work, $clock, 'ERROR'), new Paths(PJG_ROOT, $config), $db);
$db->pdo()->exec('PRAGMA cache_size = -2000');
$db->pdo()->exec('ANALYZE');

$q = $app->defectQuery();
$runs = $app->runs();
$f = static fn (array $x): DefectFilter => DefectFilter::fromQuery($x);
$total = (int) $db->value('SELECT COUNT(*) FROM defects WHERE project_id = ?', [$pid]);
$latest = (int) ($runs->status($pid)['latest'] ?? 0);
$sampleFile = (string) $db->value('SELECT file_path FROM defects WHERE project_id = ? LIMIT 1 OFFSET ?', [$pid, intdiv($total, 3)]);
$folder = substr($sampleFile, 0, (int) strrpos($sampleFile, '/') + 1);
$word = (string) $db->value("SELECT substr(message, 1, 8) FROM defects WHERE project_id = ? LIMIT 1 OFFSET ?", [$pid, intdiv($total, 2)]);
$pathPart = substr(basename($sampleFile), 0, 7);

$timeIt = static function (callable $fn) use ($repeat): float {
    $ms = [];
    for ($i = 0; $i < $repeat; $i++) {
        $t = hrtime(true);
        $fn();
        $ms[] = (hrtime(true) - $t) / 1e6;
    }
    sort($ms);
    return $ms[intdiv(count($ms), 2)];
};

$cases = [
    ['현재 표', '필터 없음 목록 1쪽', fn () => $q->list($pid, $f([]), 50, 0)],
    ['현재 표', '필터 없음 요약', fn () => $q->summary($pid, $f([]), 200)],
    ['현재 표', '상태 open 요약', fn () => $q->summary($pid, $f(['status' => 'open']), 200)],
    ['현재 표', '폴더 접두사 목록', fn () => $q->list($pid, $f(['file' => $folder]), 50, 0)],
    ['현재 표', '다중 + 검색어 목록', fn () => $q->list($pid, $f(['status' => 'open,in_progress', 'q' => $word]), 50, 0)],
    ['현재 표', '다중 + 검색어 요약', fn () => $q->summary($pid, $f(['status' => 'open,in_progress', 'q' => $word]), 200)],
    ['현재 표', '다중 + 경로 포함 + 심각도 정렬 목록', fn () => $q->list($pid, $f(['status' => 'open', 'file' => $pathPart, 'file_mode' => 'contains', 'sort' => 'severity', 'order' => 'desc']), 50, 0)],
    ['현재 표', '다중 + 경로 포함 요약', fn () => $q->summary($pid, $f(['status' => 'open', 'file' => $pathPart, 'file_mode' => 'contains']), 200)],
    ['현재 표', '깊은 쪽(파일 정렬)', fn () => $q->list($pid, $f([]), 50, max(0, $total - 50))],
    ['현재 표', '깊은 쪽(심각도 정렬)', fn () => $q->list($pid, $f(['sort' => 'severity']), 50, max(0, $total - 50))],
    ['현재 표', '최근 감지 정렬 첫 쪽', fn () => $q->list($pid, $f(['sort' => 'last_seen', 'order' => 'desc']), 50, 0)],
    ['현재 표', '깊은 쪽(최근 감지 정렬)', fn () => $q->list($pid, $f(['sort' => 'last_seen', 'order' => 'desc']), 50, max(0, $total - 50))],
    ['현재 표', '깊은 쪽(Run 정렬)', fn () => $q->list($pid, $f(['sort' => 'run']), 50, max(0, $total - 50))],
    ['현재 표', '건수(검색어)', fn () => $q->count($pid, $f(['q' => $word]))],
];
if ($latest > 1) {
    $past = max(1, $latest - 1);
    $run = $runs->resolve($pid, (string) $past);
    $rsrc = $run === null ? null : $runs->source($pid, $run);
    $exist = $runs->existCount($pid, $past);
    $cases[] = ["과거 Run {$past}", '목록 첫 쪽', fn () => $q->list($pid, $f([]), 50, 0, $rsrc, $run)];
    $cases[] = ["과거 Run {$past}", '상태 필터 목록', fn () => $q->list($pid, $f(['status' => 'open,in_progress']), 50, 0, $rsrc, $run)];
    $cases[] = ["과거 Run {$past}", '담당자 필터 목록', fn () => $q->list($pid, $f(['assignee' => 'none']), 50, 0, $rsrc, $run)];
    $cases[] = ["과거 Run {$past}", '마지막 쪽', fn () => $q->list($pid, $f([]), 50, max(0, $exist - 50), $rsrc, $run)];
    $cases[] = ["과거 Run {$past}", '요약 + 상태 필터', fn () => $q->summary($pid, $f(['status' => 'open']), 200, $rsrc)];
}

printf("DB 사본 %s, 프로젝트 %d, 결함 %d, 최신 Run %d, 검색어 '%s', 경로 '%s', 폴더 '%s', %d회 중앙값\n\n", basename($copy), $pid, $total, $latest, $word, $pathPart, $folder, $repeat);
echo "| 대상 | 경우 | 중앙값(ms) |\n| --- | --- | --- |\n";
$out = [];
foreach ($cases as [$scope, $name, $fn]) {
    $ms = $timeIt($fn);
    $out[] = ['scope' => $scope, 'case' => $name, 'ms' => round($ms, 1)];
    printf("| %s | %s | %s |\n", $scope, $name, number_format($ms, 1));
}
if (isset($opt['plans'])) {
    // 실행한 SQL의 실행 계획: PDO 감시 대신 느린 경우를 다시 돌리며 SQLite가 마지막으로 준비한 문장을 알 수 없으므로,
    // 같은 조건의 대표 SQL을 직접 EXPLAIN 한다(DefectQuery가 만드는 것과 같은 모양 — 코드 변경 시 함께 고친다)
    echo "\n실행 계획(대표 SQL)\n";
    $plans = [
        '검색어(LIKE)' => ["SELECT COUNT(*) FROM defects d WHERE d.project_id = ? AND d.status IN ('open','in_progress') AND (d.message LIKE ? ESCAPE '\\' OR d.file_path LIKE ? ESCAPE '\\' OR d.rule_key LIKE ? ESCAPE '\\')",
            [$pid, "%{$word}%", "%{$word}%", "%{$word}%"]],
        '경로 포함' => ["SELECT COUNT(*) FROM defects d WHERE d.project_id = ? AND d.status = 'open' AND d.file_path LIKE ? ESCAPE '\\'", [$pid, "%{$pathPart}%"]],
    ];
    foreach ($plans as $name => [$sql, $params]) {
        echo "-- {$name}\n";
        foreach ($db->all('EXPLAIN QUERY PLAN ' . $sql, $params) as $r) {
            echo '   ' . $r['detail'] . "\n";
        }
    }
}
if (isset($opt['json'])) {
    file_put_contents((string) $opt['json'], json_encode(['project' => $pid, 'defects' => $total, 'latest_run' => $latest, 'cases' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
// 열린 연결을 모두 놓아야 Windows에서 사본을 지울 수 있다(경우 클로저가 앱·조회 객체를 잡고 있음)
unset($cases, $q, $runs, $f, $timeIt, $app, $db, $fn);
if (!isset($opt['keep'])) {
    gc_collect_cycles();
    @unlink($copy);
    @unlink($copy . '-wal');
    @unlink($copy . '-shm');
    foreach (glob($work . '/*') ?: [] as $f2) {
        @unlink($f2);
    }
    @rmdir($work);
}
