<?php

declare(strict_types=1);

/**
 * 내보내기 측정(P31 B-4, P23 이월): 결함 N행(기본 50,000 = export.max_rows)의 CSV·Excel 내보내기 시간·PHP 최대 메모리·파일 크기,
 * 동시 3건(서버 전체 자리 export.max_concurrent = 2 → 1건은 429 export_busy), 보낸 뒤 임시 파일(<work>/export)이 남지 않는지.
 * 임시 폴더·새 DB에서만(개발 DB·설정 무관). 웹 요청과 같은 경로(App::handle → ExportController)를 프로세스 안에서 부른다.
 *
 *   php tools/perf/export_bench.php [--rows=50000] [--repeat=3] [--keep]
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;

$opt = getopt('', ['rows:', 'repeat:', 'keep', 'child:', 'format:']);

/** 같은 설치(DB·작업 폴더)를 쓰는 앱 */
function appFor(string $dir): App
{
    $config = TestApp::config(['paths' => ['work_dir' => $dir . '/work', 'logs_dir' => $dir . '/logs'], 'auth' => ['bcrypt_cost' => '4']]);
    $clock = new FixedClock('2026-10-14T00:00:00Z');
    return new App(PJG_ROOT, $config, $clock, new Logger($dir . '/logs', $clock, 'INFO'), new Paths(PJG_ROOT, $config), new Db($dir . '/db.sqlite', 30000));
}

/** 한 번 내보내고 보낸다(출력은 버림) — 상태·시간·크기 */
function exportOnce(App $app, int $pid, string $format, string $login): array
{
    $c = new TestClient($app);
    $c->login($login, 'Passw0rd!x');
    $t = hrtime(true);
    $r = $c->post("/api/v1/projects/{$pid}/defects/export", ['format' => $format]);
    $ms = (hrtime(true) - $t) / 1e6;
    $size = $r->filePath() !== null ? (int) filesize((string) $r->filePath()) : 0;
    $r->takeFile(); // 본문을 읽고 임시 파일을 지운다(웹에서는 send()가 보낸 뒤 지움 — 같은 정리)
    return ['status' => $r->status, 'code' => $r->decoded()['error']['code'] ?? null, 'ms' => round($ms), 'bytes' => $size];
}

if (isset($opt['child'])) {
    // 동시 시험의 자식: 시작 신호를 기다렸다가 한 번
    [$dir, $pid, $login, $go] = explode('|', (string) $opt['child']);
    $app = appFor($dir);
    for ($i = 0; $i < 400 && !is_file($go); $i++) {
        usleep(10_000);
    }
    echo json_encode(exportOnce($app, (int) $pid, (string) ($opt['format'] ?? 'xlsx'), $login)), "\n";
    exit(0);
}

$rows = (int) ($opt['rows'] ?? 50000);
$repeat = max(1, (int) ($opt['repeat'] ?? 3));
$dir = PJG_ROOT . '/data/work/expbench_' . bin2hex(random_bytes(3));
mkdir($dir . '/logs', 0777, true);
$db = TestDb::migrated($dir . '/db.sqlite');
foreach (['admin' => 'admin', 'u1' => 'admin', 'u2' => 'admin', 'u3' => 'admin'] as $login => $role) {
    TestDb::user($db, $login, $role);
}
$pid = TestDb::project($db, 'export-bench');
$db = null;
$t = microtime(true);
passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=2G ' . escapeshellarg(PJG_ROOT . '/tools/seed_defects.php') . ' --db=' . escapeshellarg($dir . '/db.sqlite')
    . " --project={$pid} --defects={$rows} --files=500 > " . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
printf("결함 %d행 준비 %.1f초 (%s)\n", $rows, microtime(true) - $t, basename($dir));
$app = appFor($dir);
// 메시지에 수식 시작 글자·긴 값을 섞어 실제와 비슷하게
$app->db()->execute("UPDATE defects SET message = '=' || message WHERE id % 50 = 0");

echo "\n| 형식 | 회 | 상태 | 시간(ms) | 파일 크기 | PHP 최대 메모리(로그) |\n| --- | --- | --- | --- | --- | --- |\n";
$logFile = static fn (): string => (string) (glob($dir . '/logs/app-*.log')[0] ?? '');
foreach (['csv', 'xlsx'] as $format) {
    for ($i = 1; $i <= $repeat; $i++) {
        $r = exportOnce($app, $pid, $format, 'admin');
        $peak = null;
        foreach (array_reverse(file($logFile()) ?: []) as $line) {
            $j = json_decode($line, true);
            if (($j['message'] ?? '') === '내보내기 파일 생성') {
                $peak = $j['context']['peak_memory'] ?? null;
                break;
            }
        }
        printf("| %s | %d | %d | %s | %.1f MB | %s |\n", $format, $i, $r['status'], number_format($r['ms']), $r['bytes'] / 1048576, $peak === null ? '-' : number_format($peak / 1048576, 1) . ' MB');
    }
}

// 동시 3건(서로 다른 admin 3명 — 사용자당 자리 1과 섞이지 않게)
$go = $dir . '/go';
$procs = [];
foreach (['u1', 'u2', 'u3'] as $k => $login) {
    $procs[$k] = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--child=' . implode('|', [$dir, $pid, $login, $go]), '--format=xlsx'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
}
usleep(1_500_000);
touch($go);
echo "\n동시 3건(xlsx, 서버 전체 자리 " . $app->config->int('export.max_concurrent') . "):\n";
foreach ($procs as $k => $p) {
    $out = trim((string) stream_get_contents($pipes[$k][1]));
    $err = trim((string) stream_get_contents($pipes[$k][2]));
    proc_close($p);
    echo '  ' . ($out !== '' ? $out : 'ERR ' . $err) . "\n";
}
$left = array_values(array_filter(glob($dir . '/work/export/*') ?: [], 'is_file'));
echo "\n남은 임시 내보내기 파일: " . count($left) . "개\n";
$app = null;
if (!isset($opt['keep'])) {
    gc_collect_cycles();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
