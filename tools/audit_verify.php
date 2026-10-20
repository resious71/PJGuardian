<?php

declare(strict_types=1);

/**
 * 감사 로그 해시 체인 검증 (명세 19.7)
 *
 * 사용법: php tools/audit_verify.php [--db=<경로>]
 * 출력: 검사한 행 수, 결과, 끊긴 위치(id와 이유), 마지막 해시.
 *   마지막 해시는 DB 밖(백업 기록 등)에 남겨 두면 체인 전체를 다시 계산한 변조도 찾을 수 있다(P30 백업).
 * 종료 코드: 정상 0, 변조 탐지 2, 실행 오류 1
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigException;
use PJGuardian\Support\Db;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SystemClock;

$opt = getopt('', ['db:']);
try {
    $config = Config::fromRoot(PJG_ROOT);
    $paths = new Paths(PJG_ROOT, $config);
    $file = is_string($opt['db'] ?? null) ? $opt['db'] : $paths->dbFile();
    if (!is_file($file)) {
        fwrite(STDERR, "DB 파일이 없습니다: {$file}\n");
        exit(1);
    }
    $db = new Db($file, $config->int('database.busy_timeout') * 1000);
    $v = (new AuditLog($db, new SystemClock()))->verify();
    $meta = $db->one('SELECT anchor_hash, purged_through FROM audit_log_meta WHERE id = 1');
    $last = $db->one('SELECT id, occurred_at, hash FROM audit_log ORDER BY id DESC LIMIT 1');
} catch (ConfigException | \RuntimeException | \PDOException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "DB: {$file}\n";
echo "검사한 행: {$v['checked']}" . ((int) ($meta['purged_through'] ?? 0) > 0 ? " (보존 기간 정리로 id {$meta['purged_through']}까지 삭제됨, 기준점에서 시작)" : '') . "\n";
if ($v['ok']) {
    echo "결과: 정상(해시 체인이 끊기지 않음)\n";
    if ($last !== null) {
        echo "마지막 행: id {$last['id']}, {$last['occurred_at']}\n마지막 해시: {$last['hash']}\n";
    }
    exit(0);
}
$reasons = ['prev_hash_mismatch' => '앞 행과 연결이 끊김(행 삭제·삽입·순서 변경)', 'hash_mismatch' => '행 내용이 바뀜'];
echo "결과: 변조 탐지 — id {$v['broken_at']}에서 {$reasons[$v['reason']]}\n";
exit(2);
