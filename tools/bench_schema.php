<?php

declare(strict_types=1);

/**
 * 스키마 참고 측정(P08): 결함 10만 건 규모 임시 DB에서 대표 목록 쿼리의 실행 계획·시간과 DB 크기.
 * 본 성능 측정은 P17(동시 100명 기준은 19.6)에서 한다.
 *
 * 사용법: php tools/bench_schema.php [결함 수=100000] [분석 횟수=20]
 * 임시 DB는 data/work/ 아래에 만들고 끝나면 지운다.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Migrator;

$defectCount = (int) ($argv[1] ?? 100000);
$analysisCount = (int) ($argv[2] ?? 20);
$dir = PJG_ROOT . '/data/work/bench_' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$file = $dir . '/bench.sqlite';
$T = '2026-10-02T12:00:00Z';

try {
    $db = new Db($file);
    (new Migrator($db, PJG_ROOT . '/migrations', new FixedClock($T)))->migrate();
    mt_srand(7);
    $t0 = hrtime(true);
    $db->transaction(function (Db $db) use ($defectCount, $analysisCount, $T): void {
        $users = [];
        for ($i = 0; $i < 50; $i++) {
            $db->execute("INSERT INTO users (login_id, display_name, role, auth_type, password_hash, created_at, updated_at) VALUES (?, ?, ?, 'local', 'x', ?, ?)",
                ["u{$i}", "사용자{$i}", $i === 0 ? 'admin' : 'user', $T, $T]);
            $users[] = $db->lastInsertId();
        }
        // 결함이 많은 대상 프로젝트 1개 + 다른 프로젝트 9개(각 1천 건)
        $projects = [];
        for ($p = 0; $p < 10; $p++) {
            $db->execute("INSERT INTO projects (name, language, created_at, updated_at) VALUES (?, 'python', ?, ?)", ["project{$p}", $T, $T]);
            $projects[] = $db->lastInsertId();
        }
        $analyses = [];
        foreach ($projects as $pid) {
            for ($a = 0; $a < $analysisCount; $a++) {
                $db->execute(
                    "INSERT INTO analyses (project_id, status, source_kind, trigger_kind, requested_by, tool, tool_version, config_source, config_hash, queued_at, started_at, finished_at,"
                    . " total_count, new_count, resolved_count, reopened_count, unchanged_count) VALUES (?, 'succeeded', 'upload', 'user', ?, 'ruff', '0.16.10', 'server_default', ?, ?, ?, ?, 0, 0, 0, 0, 0)",
                    [$pid, $users[0], str_repeat('c', 64), $T, $T, $T],
                );
                $analyses[$pid][] = $db->lastInsertId();
            }
        }
        $sev = ['critical', 'high', 'medium', 'low', 'not_defined', 'not_defined', 'not_defined'];
        $st = ['open', 'open', 'open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved', 'resolved'];
        $ins = $db->pdo()->prepare(
            'INSERT INTO defects (project_id, signature, ordinal, tool, language, rule_key, file_path, line_no, col_no, end_line_no, end_col_no, message, severity, status, assignee_id,'
            . " first_seen_analysis_id, last_seen_analysis_id, created_at, updated_at) VALUES (?, ?, 0, 'ruff', 'python', ?, ?, ?, 1, ?, 10, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $hist = $db->pdo()->prepare("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_at, kind, reason_code, analysis_id) VALUES (?, 'status', 'open', 'resolved', ?, 'auto', 'not_detected', ?)");
        foreach ($projects as $k => $pid) {
            $n = $k === 0 ? $defectCount : 1000;
            for ($i = 0; $i < $n; $i++) {
                $first = $analyses[$pid][mt_rand(0, $analysisCount - 1)];
                $last = max($first, $analyses[$pid][mt_rand(0, $analysisCount - 1)]);
                $status = $st[mt_rand(0, count($st) - 1)];
                $line = mt_rand(1, 3000);
                $ins->execute([
                    $pid, hash('sha256', "{$pid}-{$i}"),
                    'ruff:' . ['F401', 'F841', 'E711', 'E501', 'F821', 'E722', 'B006', 'UP035', 'SIM108', 'C901'][mt_rand(0, 9)] . (mt_rand(0, 4) === 0 ? mt_rand(0, 60) : ''),
                    'src/pkg' . mt_rand(0, 49) . '/sub' . mt_rand(0, 19) . '/module_' . mt_rand(0, 199) . '.py',
                    $line, $line + mt_rand(0, 3),
                    '`os` imported but unused — 사용하지 않는 import 메시지 예시 ' . mt_rand(0, 99999),
                    $sev[mt_rand(0, count($sev) - 1)], $status, mt_rand(0, 3) === 0 ? $users[mt_rand(0, 49)] : null,
                    $first, $last, $T, $T,
                ]);
                if ($status === 'resolved') {
                    $hist->execute([(int) $db->pdo()->lastInsertId(), $T, $last]);
                }
            }
        }
    });
    $loadSec = (hrtime(true) - $t0) / 1e9;
    $db->pdo()->exec('ANALYZE');
    $db->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    clearstatcache();
    $counts = [
        'defects' => (int) $db->value('SELECT COUNT(*) FROM defects'),
        'defect_history' => (int) $db->value('SELECT COUNT(*) FROM defect_history'),
        'analyses' => (int) $db->value('SELECT COUNT(*) FROM analyses'),
    ];
    $pageSize = (int) $db->value('PRAGMA page_size');
    $sizes = [];
    try {
        foreach ($db->all('SELECT name, SUM(pgsize) AS bytes FROM dbstat GROUP BY name ORDER BY bytes DESC') as $r) {
            $sizes[$r['name']] = (int) $r['bytes'];
        }
    } catch (\PDOException) {
        $sizes = []; // dbstat 가상 테이블이 없는 빌드
    }

    printf("SQLite %s, PHP %s, %s\n", $db->value('SELECT sqlite_version()'), PHP_VERSION, PHP_OS_FAMILY);
    printf("데이터: 결함 %s건(대상 프로젝트 %s건 + 다른 9개 프로젝트 각 1,000건), 이력 %s건, 분석 %s건, 적재 %.1f초\n",
        number_format($counts['defects']), number_format($defectCount), number_format($counts['defect_history']), number_format($counts['analyses']), $loadSec);
    printf("DB 파일 크기: %.1f MB (페이지 %d바이트)\n\n", filesize($file) / 1048576, $pageSize);
    if ($sizes !== []) {
        echo "테이블·인덱스별 크기(상위):\n";
        foreach (array_slice($sizes, 0, 14, true) as $name => $bytes) {
            printf("  %-32s %7.1f MB\n", $name, $bytes / 1048576);
        }
        echo "\n";
    }

    $pid = (int) $db->value("SELECT id FROM projects WHERE name = 'project0'");
    $queries = [
        '1. 상태=open, 기본 정렬(id), 3페이지(50건)' => [
            'SELECT id, rule_key, file_path, line_no, severity, status, assignee_id FROM defects WHERE project_id = ? AND status = ? ORDER BY id LIMIT 50 OFFSET 100',
            [$pid, 'open'],
            'SELECT COUNT(*) FROM defects WHERE project_id = ? AND status = ?',
        ],
        '2. 심각도=high + 상태=open, 첫 페이지' => [
            'SELECT id, rule_key, file_path, line_no, severity, status FROM defects WHERE project_id = ? AND severity = ? AND status = ? ORDER BY id LIMIT 50',
            [$pid, 'high', 'open'],
            'SELECT COUNT(*) FROM defects WHERE project_id = ? AND severity = ? AND status = ?',
        ],
        '3. 폴더 접두사(src/pkg7/) 파일·줄 순, 첫 페이지' => [
            'SELECT id, rule_key, file_path, line_no, severity, status FROM defects WHERE project_id = ? AND file_path >= ? AND file_path < ? ORDER BY file_path, line_no LIMIT 50',
            [$pid, 'src/pkg7/', 'src/pkg70'],
            'SELECT COUNT(*) FROM defects WHERE project_id = ? AND file_path >= ? AND file_path < ?',
        ],
    ];
    foreach ($queries as $label => [$sql, $params, $countSql]) {
        $plan = array_column($db->all('EXPLAIN QUERY PLAN ' . $sql, $params), 'detail');
        $countPlan = array_column($db->all('EXPLAIN QUERY PLAN ' . $countSql, $params), 'detail');
        $best = PHP_FLOAT_MAX;
        $bestCount = PHP_FLOAT_MAX;
        for ($r = 0; $r < 5; $r++) {
            $t = hrtime(true);
            $rows = $db->all($sql, $params);
            $best = min($best, (hrtime(true) - $t) / 1e6);
            $t = hrtime(true);
            $total = (int) $db->value($countSql, $params);
            $bestCount = min($bestCount, (hrtime(true) - $t) / 1e6);
        }
        printf("%s\n  목록: %.2f ms (%d행), 계획: %s\n  건수: %.2f ms (전체 %s건), 계획: %s\n\n",
            $label, $best, count($rows), implode(' | ', $plan), $bestCount, number_format($total), implode(' | ', $countPlan));
    }
} finally {
    unset($db);
    Fs::removeTree($dir);
}
