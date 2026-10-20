<?php

declare(strict_types=1);

/**
 * Run 되채움(P20R). 마이그레이션 011을 적용한 뒤 기존 분석·결함에 Run 번호·존재 구간·Run 요약을 채운다. 여러 번 실행해도 같다.
 *
 *   php tools/backfill_runs.php [--db=<sqlite 파일>] [--config=<ini>] [--dry-run] [--project=<id>] [--force]
 *     --dry-run   계산만 하고 쓰지 않는다(Run 수·결함 수·구간 수)
 *     --project   그 프로젝트만
 *     --force     이미 되채운 프로젝트도 다시 만든다(실시간 반영으로 만든 정확한 구간을 근사로 바꿀 수 있으니 필요할 때만)
 * 결과: 프로젝트별 Run 수와 **Run별 검증표**(복원한 존재 결함 수 vs 그 분석에 저장된 감지 건수). 차이는 범위 밖 유지(존재하지만 감지 안 됨) 등.
 * 종료 코드: 0 성공, 1 실패, 2 사용법·설정 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Runs\RunBackfill;
use PJGuardian\Services\Runs\RunService;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;

$opt = getopt('', ['db:', 'config:', 'dry-run', 'project:', 'force']);
try {
    if (isset($opt['db'])) {
        $db = new Db((string) $opt['db']);
    } else {
        $app = App::create(PJG_ROOT, isset($opt['config']) ? Config::load((string) $opt['config']) : null);
        $db = $app->db();
    }
    if ($db->value("SELECT 1 FROM pragma_table_info('analyses') WHERE name = 'run_number'") === null) {
        fwrite(STDERR, "마이그레이션 011이 적용되지 않았습니다(php tools/migrate.php 먼저)\n");
        exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$backfill = new RunBackfill($db, new RunService($db));
$dry = isset($opt['dry-run']);
if (isset($opt['project'])) {
    $projects = [(int) $opt['project']];
} elseif (isset($opt['force'])) {
    $projects = array_map('intval', array_column($db->all('SELECT id FROM projects ORDER BY id'), 'id'));
} else {
    $projects = $backfill->pending();
}
if ($projects === []) {
    echo "되채울 프로젝트가 없습니다(모든 성공 분석에 Run 번호가 있음)\n";
    exit(0);
}
$fail = 0;
foreach ($projects as $pid) {
    $name = (string) $db->value('SELECT name FROM projects WHERE id = ?', [$pid]);
    $t = microtime(true);
    try {
        $r = $backfill->project($pid, $dry);
    } catch (\Throwable $e) {
        fwrite(STDERR, "프로젝트 {$pid} 실패: " . $e->getMessage() . "\n");
        $fail++;
        continue;
    }
    printf("%s 프로젝트 %d(%s): Run %d개, 결함 %d건, 구간 %d개(수동 resolved 미감지로 닫음 %d), %.1f초\n", $dry ? '[dry-run]' : '[완료]', $pid, $name,
        $r['runs'], $r['defects'], $r['intervals'], $r['manual_resolved_closed'], microtime(true) - $t);
    foreach ($r['verify'] ?? [] as $v) {
        printf("  Run %-3d 분석 #%-5d 존재(복원) %6d / 감지(저장) %6s %s%s\n", $v['run'], $v['analysis_id'], $v['restored_total'],
            $v['stored_total'] === null ? '-' : (string) $v['stored_total'], $v['match'] ? '일치' : '차이',
            $v['match'] ? '' : ' 심각도 복원 ' . json_encode($v['restored_severity']) . ' / 저장 ' . json_encode($v['stored_severity']));
    }
}
exit($fail > 0 ? 1 : 0);
