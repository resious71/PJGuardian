<?php

declare(strict_types=1);

/**
 * 측정 도우미(tools/seed_defects.php --bench): 다른 연결에서 결함 한 건을 50ms마다 수정하며(사용자의 결함 수정 흉내)
 * 쓰기 잠금 대기 시간을 잰다. stop 파일이 생기면 끝내고 "최대 대기 ms, 평균 ms, 횟수, 실패 횟수"를 JSON으로 출력한다.
 * 사용: php tools/lib/write_probe.php <db 파일> <stop 파일> <busy_timeout ms>
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

[, $file, $stop, $busy] = $argv;
$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = ' . (int) $busy);
$pdo->exec('PRAGMA journal_mode = WAL');
// P19C: PJG_PROBE_NOAUTO=1이면 이 연결의 자동 체크포인트를 끈다 — 잠금 대기만 재고 싶을 때(기본은 웹 연결과 같은 SQLite 기본값: 큰 반영 직후 이 연결의
// COMMIT이 자동 체크포인트로 WAL을 옮기느라 오래 걸릴 수 있다 — 그 시간도 "대기"에 들어간다)
if (getenv('PJG_PROBE_NOAUTO') === '1') {
    $pdo->exec('PRAGMA wal_autocheckpoint = 0');
}
// PJG_PROBE_NORMAL=1이면 운영 연결(Support\Db)처럼 synchronous=NORMAL — COMMIT마다 fsync하지 않는다(기본 FULL은 큰 반영의 디스크 쓰기와 겹치면 오래 기다린다)
if (getenv('PJG_PROBE_NORMAL') === '1') {
    $pdo->exec('PRAGMA synchronous = NORMAL');
}
$waits = [];
$fail = 0;
while (!is_file($stop)) {
    $t = hrtime(true);
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec("UPDATE defects SET severity = severity WHERE id = (SELECT MIN(id) FROM defects)");
        $pdo->exec('COMMIT');
        $waits[] = (hrtime(true) - $t) / 1e6;
    } catch (\PDOException) {
        $fail++;
        try {
            $pdo->exec('ROLLBACK');
        } catch (\PDOException) {
        }
    }
    usleep(50_000);
}
echo json_encode(['max_ms' => $waits === [] ? 0 : (int) max($waits), 'avg_ms' => $waits === [] ? 0 : (int) (array_sum($waits) / count($waits)), 'count' => count($waits), 'failed' => $fail]);
