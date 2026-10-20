<?php

declare(strict_types=1);

/**
 * 감사 로그 측정(P30): 임시 DB(마이그레이션 적용)에 해시 체인이 이어진 가짜 감사 행을 넣고 화면 조회·무결성 검사·보존 정리를 잰다. 실제 DB와 무관(끝나면 지움).
 *   php tools/bench_audit.php [--rows=1000000] [--days=120] [--repeat=20] [--batch=5000] [--keep]
 * 출력: 조건별 목록(1쪽 50행 + 건수) p50·p95, 무결성 검사 조각(20,000행)·전체, 보존 정리(90일, 묶음별 최대 쓰기 시간), 실행 계획(색인 사용 여부).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\Audit\AuditQuery;
use PJGuardian\Services\Audit\AuditRetention;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\AuditChain;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;

$opt = getopt('', ['rows:', 'days:', 'repeat:', 'batch:', 'keep']);
$rows = (int) ($opt['rows'] ?? 1000000);
$days = (int) ($opt['days'] ?? 120);
$repeat = (int) ($opt['repeat'] ?? 20);
$batch = (int) ($opt['batch'] ?? 5000);
$dir = PJG_ROOT . '/data/work/bench_audit_' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$file = $dir . '/bench.sqlite';
$db = new Db($file);
(new Migrator($db, PJG_ROOT . '/migrations', new FixedClock('2026-10-12T00:00:00Z')))->migrate();

$now = new DateTimeImmutable('2026-10-12T00:00:00Z');
$clock = new FixedClock($now->format('Y-m-d\TH:i:s\Z'));
$t0 = hrtime(true);
$actions = ['user.update', 'user.create', 'group.member_add', 'project.update', 'repo.test', 'repo.fetch_now', 'config.update', 'faq.update', 'backup.create', 'rules.override_set',
    'tool.pin', 'export.defects', 'auth.login_failed', 'schedule.save', 'project.access_add', 'run.detail_delete'];
$actors = [];
for ($i = 0; $i < 40; $i++) {
    $actors[] = 'admin' . $i;
}
$actors[] = 'system';
$actors[] = 'cli';
$prev = AuditChain::GENESIS;
$step = intdiv($days * 86400, $rows) ?: 1;
$start = $now->getTimestamp() - $days * 86400;
$pdo = $db->pdo();
$st = $pdo->prepare('INSERT INTO audit_log (occurred_at, actor_user_id, actor_label, action, target_type, target_id, details, ip, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?)');
$pdo->beginTransaction();
for ($i = 0; $i < $rows; $i++) {
    $a = $actions[$i % count($actions)];
    $row = ['occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $start + $i * $step), 'actor_user_id' => null, 'actor_label' => $actors[($i * 7 + intdiv($i, 42)) % count($actors)], 'action' => $a,
        'target_type' => explode('.', $a)[0], 'target_id' => (string) ($i % 5000), 'details' => '{"n":' . $i . ',"note":"bench ' . ($i % 997) . '"}', 'ip' => '10.0.' . ($i % 250) . '.' . ($i % 200)];
    $h = AuditChain::hash($prev, $row);
    $st->execute([...array_values($row), $prev, $h]);
    $prev = $h;
    if ($i % 100000 === 99999) {
        $pdo->commit();
        $pdo->beginTransaction();
    }
}
$pdo->commit();
$pdo->exec('ANALYZE');
printf("행 %s개 넣음(%d일에 걸쳐): %.1f초, DB %.0f MB\n", number_format($rows), $days, (hrtime(true) - $t0) / 1e9, filesize($file) / 1048576);

$q = new AuditQuery($db, $clock);
$cases = [
    '조건 없음' => [],
    '최근 7일' => ['days' => '7'],
    '최근 90일' => ['days' => '90'],
    '작업 접두사 user.' => ['action' => 'user.'],
    '작업 이름 faq.update' => ['action' => 'faq.update'],
    '행위자 부분 일치 admin3' => ['actor' => 'admin3'],
    '드문 행위자 cli + 최근 30일' => ['actor' => 'cli', 'days' => '30'],
    '대상 project:42' => ['target' => 'project:42'],
    '검색 "bench 99"' => ['q' => 'bench 99'],
    '접두사 + 7일 + 검색' => ['action' => 'repo.', 'days' => '7', 'q' => 'bench 12'],
    '마지막 쪽(1쪽 50행, page=100)' => ['__page' => 100],
];
printf("\n%-34s %8s %8s %10s\n", '조건(1쪽 50행 + 건수)', 'p50 ms', 'p95 ms', '건수');
foreach ($cases as $name => $f) {
    $page = (int) ($f['__page'] ?? 1);
    unset($f['__page']);
    $filter = $q->filter($f);
    $ms = [];
    $total = 0;
    for ($k = 0; $k < $repeat; $k++) {
        $t = hrtime(true);
        [, $total] = $q->list($filter, $page, 50);
        $ms[] = (hrtime(true) - $t) / 1e6;
    }
    sort($ms);
    printf("%-34s %8.1f %8.1f %10s\n", $name, $ms[intdiv($repeat, 2)], $ms[(int) ceil($repeat * 0.95) - 1], number_format($total));
}
// 실행 계획(색인 사용)
foreach (['action 접두사' => "SELECT * FROM audit_log WHERE action >= 'user.' AND action < 'user/' ORDER BY id DESC LIMIT 50",
    '기간 → id 범위' => "SELECT MIN(id) FROM audit_log WHERE occurred_at >= '2026-10-05T00:00:00Z'"] as $label => $sql) {
    $plan = implode(' | ', array_column($db->all('EXPLAIN QUERY PLAN ' . $sql), 'detail'));
    printf("계획 %s: %s\n", $label, $plan);
}

// 무결성 검사
$t = hrtime(true);
$r = AuditChain::verifyRange($db, 0, 20000);
printf("\n무결성 검사 한 조각(20,000행): %.0f ms\n", (hrtime(true) - $t) / 1e6);
$t = hrtime(true);
$after = 0;
$chunks = 0;
do {
    $r = AuditChain::verifyRange($db, $after, 20000);
    $after = $r['last_id'];
    $chunks++;
} while ($r['ok'] && !$r['done']);
printf("무결성 검사 전체(조각 %d개): %.1f초, 결과 %s\n", $chunks, (hrtime(true) - $t) / 1e9, $r['ok'] ? '정상' : '불일치');

// 보존 정리(90일) — 묶음별 시간(쓰기 잠금 길이)
$ret = new AuditRetention($db, $clock, new AuditLog($db, $clock), 90 * 86400, $batch);
$pre = $ret->preview();
$batchMs = [];
$t = hrtime(true);
$pr = $ret->purge();
$deleted = $pr['deleted'];
$total = (hrtime(true) - $t) / 1e9;
printf("\n보존 정리(90일): %s행 지움(묶음 %d행), 전체 %.1f초(그중 끝의 PASSIVE 체크포인트 %.1f초), 정리 뒤 체인 %s\n", number_format($deleted), $batch, $total,
    $pr['checkpoint_ms'] / 1000, AuditChain::verify($db)['ok'] ? '정상' : '불일치');
\PJGuardian\Support\WalCheckpoint::disableAuto($db); // 아래 "묶음 하나" 측정도 정리와 같은 조건(자동 체크포인트 끔) — 트랜잭션 시간 = 쓰기 잠금 길이
// 묶음 하나의 쓰기 시간: 남은 행으로 기준을 앞당겨 한 묶음만 지움
for ($k = 0; $k < 3; $k++) {
    $tb = hrtime(true);
    $db->transaction(static function (Db $db) use ($batch): void {
        $cap = (int) ($db->value('SELECT id FROM audit_log ORDER BY id LIMIT 1 OFFSET ?', [$batch - 1]) ?? 0);
        if ($cap === 0) {
            return;
        }
        $row = $db->one('SELECT hash FROM audit_log WHERE id = ?', [$cap]);
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 1 WHERE id = 1');
        $db->execute('DELETE FROM audit_log WHERE id <= ?', [$cap]);
        $db->execute('UPDATE audit_log_meta SET anchor_hash = ?, purged_through = ?, purge_enabled = 0 WHERE id = 1', [(string) $row['hash'], $cap]);
    });
    $batchMs[] = (hrtime(true) - $tb) / 1e6;
}
printf("묶음 하나(%d행) 쓰기 트랜잭션: %s ms, 정리 뒤 체인 %s\n", $batch, implode(' / ', array_map(static fn (float $m): string => sprintf('%.0f', $m), $batchMs)), AuditChain::verify($db)['ok'] ? '정상' : '불일치');

if (!isset($opt['keep'])) {
    unset($q, $ret, $st, $pdo);
    $db = null;
    gc_collect_cycles();
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}
