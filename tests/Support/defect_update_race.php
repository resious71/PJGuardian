<?php

declare(strict_types=1);

// 동시 수정 경쟁 시험용 자식 프로세스(P18, DefectUpdateTest). 출발 신호 파일이 생기면 바로 같은 version으로 결함을 수정한다.
//   php defect_update_race.php <DB 파일> <결함 id> <version> <사용자 id> <상태> <출발 신호 파일> <결과 파일>
// 결과 파일: {"ok":true,"version":N} 또는 {"ok":false,"code":"version_conflict","current_version":N}
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Http\ApiException;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Defects\DefectQuery;
use PJGuardian\Services\Defects\DefectUpdate;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

[, $dbFile, $defectId, $version, $userId, $status, $go, $out] = $argv;
$db = new Db($dbFile, 10000);
$clock = new SystemClock();
$logger = new Logger(sys_get_temp_dir(), $clock, 'ERROR');
$access = new ProjectAccess($db, $clock, new AuditLog($db, $clock), $logger);
$svc = new DefectUpdate($db, $clock, $access, new EventDispatcher($logger), new DefectQuery($db), 500);
$db->value('SELECT 1'); // 연결을 미리 연다(출발 뒤에는 수정만)
$deadline = microtime(true) + 20;
while (!is_file($go) && microtime(true) < $deadline) {
    usleep(1000);
}
try {
    $r = $svc->update(['id' => (int) $userId, 'role' => 'user', 'status' => 'active'], (int) $defectId, ['version' => (int) $version, 'status' => $status]);
    $res = ['ok' => true, 'version' => $r['version']];
} catch (ApiException $e) {
    $res = ['ok' => false, 'code' => $e->errorCode, 'current_version' => $e->details['current']['version'] ?? null];
}
file_put_contents($out, json_encode($res));
