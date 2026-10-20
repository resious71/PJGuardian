<?php

declare(strict_types=1);

/**
 * 마지막 감지 되채움(P19C, 마이그레이션 023 뒤 한 번, 선택). 기존 결함 중 "프로젝트의 최신 실제 성공 분석에서 감지된" 행(last_seen_analysis_id =
 * 최신 분석)을 seen_live = 1로 바꾼다. 하지 않아도 앱은 정상이다(seen_live = 0 행은 last_seen_analysis_id를 그대로 읽고, 다음 반영이 감지된 행을
 * 한 번 바꾼다). 미리 해 두면 그 첫 반영이 결함 행을 쓰지 않는다(큰 프로젝트의 쓰기 대기를 첫 번부터 줄임).
 *
 *   php tools/backfill_seen.php [--db=<sqlite 파일>|--config=<ini>] [--project=<id>] [--batch=20000] [--apply]
 *     기본은 미리보기(바꿀 행 수만). --apply면 프로젝트마다, 결함 id 범위로 나눈 짧은 쓰기 트랜잭션(--batch행)으로 바꾼다.
 *     멱등(이미 바꾼 행은 조건에 걸리지 않음) — 중간에 멈춰도 다시 실행하면 이어서 한다. 읽는 값(DefectSeen)은 전후가 같다.
 * 종료 코드: 0 성공, 1 실패, 2 사용법·설정 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Defects\DefectSeen;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;

$opt = getopt('', ['db:', 'config:', 'project:', 'batch:', 'apply']);
try {
    if (isset($opt['db'])) {
        $db = new Db((string) $opt['db']);
    } else {
        $app = App::create(PJG_ROOT, isset($opt['config']) ? Config::load((string) $opt['config']) : null);
        $db = $app->db();
    }
    if ($db->value("SELECT 1 FROM pragma_table_info('defects') WHERE name = 'seen_live'") === null) {
        fwrite(STDERR, "마이그레이션 023이 적용되지 않았습니다(php tools/migrate.php 먼저)\n");
        exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$apply = isset($opt['apply']);
$batch = max(1000, (int) ($opt['batch'] ?? 20000));
$projects = isset($opt['project']) ? [(int) $opt['project']] : array_map('intval', array_column($db->all('SELECT id FROM projects ORDER BY id'), 'id'));
$total = 0;
$t0 = microtime(true);
printf("%s — 프로젝트 %d개%s\n", $apply ? '적용' : '미리보기(--apply로 적용)', count($projects), $apply ? ", 한 번에 {$batch}행" : '');
foreach ($projects as $pid) {
    $latest = DefectSeen::latest($db, $pid);
    if ($latest === 0) {
        continue;
    }
    $n = (int) $db->value('SELECT COUNT(*) FROM defects WHERE project_id = ? AND seen_live = 0 AND last_seen_analysis_id = ?', [$pid, $latest]);
    $t = microtime(true);
    $done = 0;
    $maxTx = 0;
    if ($apply && $n > 0) {
        $range = $db->one('SELECT MIN(id) AS lo, MAX(id) AS hi FROM defects WHERE project_id = ?', [$pid]);
        for ($lo = (int) $range['lo']; $lo <= (int) $range['hi']; $lo += $batch) {
            $tx = microtime(true);
            // 최신 분석을 트랜잭션 안에서 다시 확인한다(그사이 새 분석이 끝났으면 그 분석이 이미 감지 행을 바꿨다 — 남은 것만)
            $done += (int) $db->transaction(static function (Db $db) use ($pid, $lo, $batch): int {
                $latestNow = DefectSeen::latest($db, $pid);
                return $db->execute('UPDATE defects SET seen_live = 1 WHERE project_id = ? AND id >= ? AND id < ? AND seen_live = 0 AND last_seen_analysis_id = ?',
                    [$pid, $lo, $lo + $batch, $latestNow]);
            });
            $maxTx = max($maxTx, (int) ((microtime(true) - $tx) * 1000));
        }
    }
    $total += $apply ? $done : $n;
    printf("  프로젝트 %d: 최신 분석 #%d, 대상 %d행%s\n", $pid, $latest, $n,
        $apply ? sprintf(', 바꿈 %d행, %.2f초(쓰기 트랜잭션 최대 %dms)', $done, microtime(true) - $t, $maxTx) : '');
}
printf("합계 %d행, %.2f초\n", $total, microtime(true) - $t0);
exit(0);
