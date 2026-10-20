<?php

declare(strict_types=1);

/**
 * 결함 대량 데이터 생성·측정(P16, P17이 재사용).
 *
 * 측정: php tools/seed_defects.php --bench [--defects=100000] [--files=1000] [--scope-files=1000000] [--severity=on|off] [--cases=A,B,C,D]
 *   --ckpt=prod: 운영처럼 자동 체크포인트를 끄고 경우 사이에 체크포인트, 반영 연결 캐시 database.analysis_cache_mb(기본 auto = P19B 비교 조건)
 *   --cache-mb=N: --ckpt=prod일 때 반영 연결의 SQLite 캐시 MB(기본 128 = 운영 기본, 0 = SQLite 기본 2MB)
 *   --spill=N: 한 파일 결함이 N보다 많으면 임시 SQLite로(P19C, 기본 20000 = 운영 기본, 0 = 끔)
 *   --cases: 실행할 경우만(기본 전부, A2는 A에 포함). 경우가 끝날 때마다 한 줄씩 먼저 출력한다(P19B: 50만 건의 C는 메모리 1GB를 넘음)
 *   --severity=off: 신규 결함에 규칙 기본 심각도를 넣지 않는다(P21S 이전 동작, 반영 시간 비교용). 규칙은 실제 ruff 규칙 10종을 돌아가며 쓴다
 *   임시 폴더(data/work/bench_*)에 합성 소스·결과 NDJSON·파일 DB를 만들고 실제 반영 코드(DefectIngest + DefectStore,
 *   SnapshotService)를 돌려 시간·최대 메모리·쓰기 트랜잭션 시간을 표로 출력한 뒤 지운다.
 *   A 신규 전체(파일 N개 × 결함), B 같은 데이터에서 10% 파일의 결함 줄 변경, C 파일 1개에 결함 전부, D 분석 대상 파일 목록 100만 개.
 * 시드: php tools/seed_defects.php --db=<sqlite 파일> --project=<id> --defects=50000 [--files=500] [--snapshots-dir=<폴더>]
 *   기존 DB의 프로젝트에 성공 분석 1건과 결함 N건을 넣는다(목록·필터 화면 시험용, 개발 DB에는 쓰지 말 것).
 *   --snapshots-dir를 주면 그 분석의 코드 스냅샷(source.zip)과 snapshots 행도 만든다(P17 코드 뷰어 측정).
 * 종료 코드: 0 성공, 2 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\Defects\DefectIngest;
use PJGuardian\Services\Defects\DefectStore;
use PJGuardian\Services\Defects\ScopeList;
use PJGuardian\Services\Defects\SnapshotService;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Db;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\SystemClock;

ini_set('memory_limit', '1024M');
$opt = getopt('', ['bench', 'defects:', 'files:', 'scope-files:', 'db:', 'project:', 'snapshots-dir:', 'severity:', 'cases:', 'spill:', 'ckpt:', 'cache-mb:']);
// P19C: 한 파일 결함이 이보다 많으면 임시 SQLite(운영 기본 analysis.file_defect_spill_threshold 20000). 0이면 끔(P19B 이전 동작 비교용)
$spillThreshold = (int) ($opt['spill'] ?? 20000);
// P19C: --ckpt=prod면 운영과 같게 반영 연결의 자동 체크포인트를 끄고 분석 사이에 워커처럼 체크포인트(PASSIVE 뒤 256MB 넘으면 TRUNCATE).
// 기본(auto)은 P19B와 같은 비교 조건(자동 체크포인트가 COMMIT 시간에 들어간다)
$ckptProd = ($opt['ckpt'] ?? 'auto') === 'prod';
$cacheMb = (int) ($opt['cache-mb'] ?? 128);
// P21S: 신규 결함의 규칙 기본 심각도(운영 기본값 on). --severity=off면 이전 동작(not_defined)으로 반영 시간을 비교한다
$severityMap = ($opt['severity'] ?? 'on') === 'off' ? null : new SeverityMap(PJG_ROOT . '/resources/severity');
/** 합성 결과의 규칙(심각도가 서로 다른 실제 규칙들을 돌아가며) */
const RULES = ['ruff:F821', 'ruff:F401', 'ruff:E501', 'ruff:S105', 'ruff:E731', 'ruff:B006', 'ruff:F841', 'ruff:E721', 'ruff:S608', 'ruff:UP006'];
$defects = max(1, (int) ($opt['defects'] ?? 100000));
$files = max(1, (int) ($opt['files'] ?? 1000));

/** 합성 프로젝트: 파일마다 결함 줄 k개(줄마다 다른 코드). 결과 NDJSON(파일 단위로 이어서)과 범위 목록 */
function makeProject(string $dir, int $files, int $defects, int $changePercent = 0, int $seed = 0): array
{
    Fs::ensureDir($dir . '/src');
    $per = intdiv($defects, $files);
    $nd = fopen($dir . '.ndjson', 'wb');
    $scope = fopen($dir . '.scope', 'wb');
    $changed = (int) round($files * $changePercent / 100);
    for ($f = 0; $f < $files; $f++) {
        $rel = sprintf('src/pkg%03d/mod_%05d.py', intdiv($f, 100), $f);
        Fs::ensureDir(dirname($dir . '/' . $rel));
        $lines = [];
        for ($i = 1; $i <= $per; $i++) {
            $tag = $f < $changed ? "v{$seed}" : 'v0';
            $lines[] = "value_{$i} = undefined_{$tag}_{$i}  # 결함 줄 {$i}";
            fwrite($nd, json_encode(['v' => 1, 'tool' => 'ruff', 'language' => 'python', 'rule_key' => RULES[$i % count(RULES)], 'file' => $rel, 'line' => $i,
                'column' => 11, 'end_line' => $i, 'end_column' => 20, 'message' => "Undefined name `undefined_{$tag}_{$i}`", 'syntax_error' => false, 'tool_severity' => 'error'],
                JSON_UNESCAPED_UNICODE) . "\n");
        }
        file_put_contents($dir . '/' . $rel, implode("\n", $lines) . "\n");
        fwrite($scope, $rel . "\n");
    }
    fclose($nd);
    fclose($scope);
    return ['ndjson' => $dir . '.ndjson', 'scope' => $dir . '.scope'];
}

/**
 * @param string|null $probeDb 지정하면 다른 프로세스가 50ms마다 결함을 수정하며 쓰기 잠금 대기 시간을 잰다(write_probe.php)
 * @return array{ingest_s:float, store_s:float, tx_ms:int, peak_mb:float, stats:array<string,mixed>}
 */
function reflect(Db $db, int $projectId, int $userId, string $dir, array $p, string $now, ?string $probeDb = null): array
{
    global $ckptProd;
    $ckpt = null;
    if ($ckptProd) {
        \PJGuardian\Support\WalCheckpoint::disableAuto($db);
        global $cacheMb;
        if ($cacheMb > 0) {
            $db->pdo()->exec('PRAGMA cache_size = -' . ($cacheMb * 1024)); // 운영의 AnalysisFinalizer와 같게(P19C)
        }
        $ckpt = (new \PJGuardian\Support\WalCheckpoint($db, new Logger(sys_get_temp_dir(), new FixedClock($now), 'ERROR'), 256 * 1048576, 2000, 10000))->run(true);
    }
    $proc = null;
    $stop = $dir . '.probe.stop';
    $out = $dir . '.probe.json';
    if ($probeDb !== null && (int) $db->value('SELECT COUNT(*) FROM defects') > 0) {
        @unlink($stop);
        $proc = proc_open([PHP_BINARY, PJG_ROOT . '/tools/lib/write_probe.php', $probeDb, $stop, '30000'],
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        usleep(500_000);
    }
    $db->execute("INSERT INTO analyses (project_id, status, source_kind, trigger_kind, requested_by, tool, queued_at, started_at) VALUES (?, 'running', 'upload', 'user', ?, 'ruff', ?, ?)", [$projectId, $userId, $now, $now]);
    $aid = $db->lastInsertId();
    memory_reset_peak_usage();
    $t0 = hrtime(true);
    global $spillThreshold;
    $ing = ($spillThreshold > 0 ? new DefectIngest($db, $spillThreshold, DefectIngest::spillRoot($dir . '_work'), (string) $aid) : new DefectIngest($db))
        ->load($p['ndjson'], $dir);
    $t1 = hrtime(true);
    $scope = ScopeList::fromFile($p['scope']);
    global $severityMap;
    $stats = (new DefectStore($db, $severityMap))->apply($aid, $projectId, $scope, new ExcludeMatcher(['node_modules'], []), $dir, ['tool_version' => 'bench'], null,
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
    return ['ingest_s' => ($t1 - $t0) / 1e9, 'store_s' => ($t2 - $t1) / 1e9, 'tx_ms' => $stats['tx_ms'], 'peak_mb' => memory_get_peak_usage(true) / 1048576,
        'stats' => $stats + ['ingest' => $ing], 'aid' => $aid, 'probe' => $probe, 'ckpt_before' => $ckpt];
}

try {
    if (isset($opt['db'], $opt['project'])) {
        // ---- 시드 ----
        $db = new Db((string) $opt['db'], 10000);
        $pid = (int) $opt['project'];
        $user = (int) $db->value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $tmp = PJG_ROOT . '/data/work/seed_' . bin2hex(random_bytes(4));
        $p = makeProject($tmp, $files, $defects);
        $now = SystemClock::iso(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $r = reflect($db, $pid, $user, $tmp, $p, $now);
        if (isset($opt['snapshots-dir'])) {
            $clock = new FixedClock($now);
            $snap = new SnapshotService($db, $clock, new Logger(sys_get_temp_dir(), $clock, 'ERROR'), (string) $opt['snapshots-dir'], $tmp, 5, 20);
            $s = $snap->create($pid, $r['aid'], $tmp, ScopeList::fromFile($p['scope']));
            if ($s !== null) {
                $db->execute("INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at) VALUES (?, ?, ?, ?, 'full', ?)",
                    [$r['aid'], $s['storage_path'], $s['size_bytes'], $s['file_count'], $now]);
            }
        }
        Fs::removeTree($tmp);
        @unlink($p['ndjson']);
        @unlink($p['scope']);
        printf("시드: 프로젝트 %d에 분석 #%d, 결함 %d건(신규 %d)\n", $pid, $r['aid'], $r['stats']['total'], $r['stats']['new']);
        exit(0);
    }
    if (!isset($opt['bench'])) {
        fwrite(STDERR, "사용법: php tools/seed_defects.php --bench [--defects=N] [--files=M] | --db=<sqlite> --project=<id> --defects=N\n");
        exit(2);
    }
    // ---- 측정 ----
    $root = PJG_ROOT . '/data/work/bench_' . bin2hex(random_bytes(4));
    Fs::ensureDir($root);
    $clock = new FixedClock('2026-10-04T00:00:00Z');
    $db = new Db($root . '/bench.sqlite', 10000);
    (new Migrator($db, PJG_ROOT . '/migrations', $clock))->migrate();
    $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('b', 'b', 'admin', 'active', 'local', 'x', ?, ?)", ['2026-10-04T00:00:00Z', '2026-10-04T00:00:00Z']);
    $user = $db->lastInsertId();
    $mkProject = static function (string $name) use ($db): int {
        $db->execute("INSERT INTO projects (name, language, created_at, updated_at) VALUES (?, 'python', '2026-10-04T00:00:00Z', '2026-10-04T00:00:00Z')", [$name]);
        return $db->lastInsertId();
    };
    $rows = [];
    $now = '2026-10-04T00:00:00Z';
    printf("측정: 결함 %d건, PHP %s, %s, SQLite 파일 DB(WAL), 규칙 기본 심각도 %s\n", $defects, PHP_VERSION, PHP_OS_FAMILY, $severityMap === null ? '끔' : '켬');

    $cases = array_flip(explode(',', strtoupper((string) ($opt['cases'] ?? 'A,B,C,D'))));
    $line = static function (string $name, array $r): void {
        printf("  완료 %s: 시그니처 %.2f초, 판정·반영 %.2f초, 트랜잭션 %dms, 최대 %.0fMB%s
", $name, $r['ingest_s'], $r['store_s'], $r['tx_ms'], $r['peak_mb'],
            $r['probe'] !== null ? ', 동시 수정 대기 ' . json_encode($r['probe']) : '');
    };
    // A. 신규 전체
    $pa = $mkProject('A');
    $dirA = $root . '/A';
    $t = hrtime(true);
    $p = makeProject($dirA, $files, $defects);
    $gen = (hrtime(true) - $t) / 1e9;
    $a = reflect($db, $pa, $user, $dirA, $p, $now);
    $rows[] = ["A 신규 전체(파일 {$files} × " . intdiv($defects, $files) . ')', $a];
    $line('A', $a);
    // A2. 같은 데이터 다시(유지만)
    $a2 = reflect($db, $pa, $user, $dirA, $p, $now, $root . '/bench.sqlite');
    $rows[] = ['A2 같은 결과 재반영(유지 전체)', $a2];
    $line('A2', $a2);
    // B. 10% 파일의 결함 줄 변경(해결 + 신규)
    if (isset($cases['B'])) {
        Fs::removeTree($dirA);
        $p = makeProject($dirA, $files, $defects, 10, 1);
        $b = reflect($db, $pa, $user, $dirA, $p, $now, $root . '/bench.sqlite');
        $rows[] = ['B 10% 파일 변경(해결 + 신규)', $b];
        $line('B', $b);
    }
    // C. 파일 1개에 결함 전부
    if (isset($cases['C'])) {
        $pc = $mkProject('C');
        $dirC = $root . '/C';
        $p = makeProject($dirC, 1, $defects);
        $c = reflect($db, $pc, $user, $dirC, $p, $now);
        $rows[] = ["C 파일 1개 × {$defects}", $c];
        $line('C', $c);
    }
    // 스냅샷(A의 파일 {$files}개 zip)
    $snap = new SnapshotService($db, $clock, new Logger($root, $clock, 'ERROR'), $root . '/snap', $root, 5, 20);
    memory_reset_peak_usage();
    $t = hrtime(true);
    $s = $snap->create($pa, 999, $dirA, ScopeList::fromFile($dirA . '.scope'));
    $snapSec = (hrtime(true) - $t) / 1e9;
    $snapMem = memory_get_peak_usage(true) / 1048576;

    echo "\n| 경우 | 결함 | 신규 | 해결 | 유지 | 시그니처 계산(초) | 판정·반영(초) | 쓰기 트랜잭션(ms) | 최대 메모리(MB) |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
    foreach ($rows as [$name, $r]) {
        printf("| %s | %d | %d | %d | %d | %.2f | %.2f | %d | %.0f |\n", $name, $r['stats']['total'], $r['stats']['new'], $r['stats']['resolved'], $r['stats']['unchanged'],
            $r['ingest_s'], $r['store_s'], $r['tx_ms'], $r['peak_mb']);
    }
    echo "\n쓰기 트랜잭션 단계별(ms):\n";
    foreach ($rows as [$name, $r]) {
        printf("  %s: %s%s\n", $name, json_encode(($r['stats']['steps_ms'] ?? []) + ['match(트랜잭션 밖)' => $r['stats']['match_ms'] ?? null, 'writes' => $r['stats']['writes'] ?? null], JSON_UNESCAPED_UNICODE), ($r['probe'] !== null ? ' / 동시 결함 수정(50ms마다) 대기: ' . json_encode($r['probe']) : '')
            . ($r['ckpt_before'] !== null ? sprintf(' / 이 경우 전 체크포인트 %s %dms(WAL %.0fMB → %.0fMB)', $r['ckpt_before']['mode'], $r['ckpt_before']['ms'], $r['ckpt_before']['wal_before'] / 1048576, $r['ckpt_before']['wal_after'] / 1048576) : ''));
    }
    printf("\n합성 데이터 생성 %.1f초. 스냅샷 zip(파일 %d개): %.2f초, %.1fMB, 최대 메모리 %.0fMB\n", $gen, $s['file_count'], $snapSec, $s['size_bytes'] / 1048576, $snapMem);

    // D. 분석 대상 파일 목록 100만 개: 범위 파일(뒤섞인 순서) → 정렬·중복 제거 → 압축·풀기·이진 탐색
    $n = isset($cases['D']) ? (int) ($opt['scope-files'] ?? 1000000) : 0;
    $scopeFile = $root . '/big.scope';
    $fh = fopen($scopeFile, 'wb');
    for ($i = 0; $i < $n; $i++) {
        $k = ($i * 7919) % $n; // 뒤섞인 순서(도구 출력 순서 흉내)
        fwrite($fh, sprintf("src/pkg%04d/sub%02d/module_%07d.py\n", intdiv($k, 1000), $k % 37, $k));
    }
    fclose($fh);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage(true);
    $t1 = hrtime(true);
    $list = ScopeList::fromFile($scopeFile);
    $t2 = hrtime(true);
    $blob = $list->encode();
    $t3 = hrtime(true);
    $back = ScopeList::decode($blob);
    $t4 = hrtime(true);
    $hits = 0;
    for ($i = 0; $i < 100000; $i++) {
        $hits += $back->contains(sprintf('src/pkg%04d/sub%02d/module_%07d.py', intdiv($i * 7, 1000), ($i * 7) % 37, $i * 7)) ? 1 : 0;
    }
    $t5 = hrtime(true);
    printf("D 파일 목록 %d개: 읽기·정렬·중복 제거 %.2f초, 압축 %.2f초(%.1fMB), 풀기 %.2f초, 이진 탐색 10만 번 %.2f초(일치 %d), 이 단계 최대 메모리 +%.0fMB(목록 두 벌: 만든 것 + 푼 것)\n",
        $n, ($t2 - $t1) / 1e9, ($t3 - $t2) / 1e9, strlen($blob) / 1048576, ($t4 - $t3) / 1e9, ($t5 - $t4) / 1e9, $hits, (memory_get_peak_usage(true) - $base) / 1048576);

    unset($db, $snap, $list, $back, $s, $mkProject);
    gc_collect_cycles();
    if (!Fs::removeTree($root)) {
        echo "주의: 측정 폴더를 지우지 못함: {$root}\n";
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(2);
}
