<?php

declare(strict_types=1);

/**
 * DB 마이그레이션 (docs/db_schema.md, docs/architecture.md 13절)
 *
 * 사용법:
 *   php tools/migrate.php            적용할 마이그레이션을 모두 적용(여러 번 실행해도 안전)
 *   php tools/migrate.php --status   적용 현황만 출력
 *   php tools/migrate.php --db=<경로>  다른 DB 파일 대상(시험용). 기본은 설정 paths.db_file
 * 종료 코드: 정상 0, 실패 1(실패한 마이그레이션은 롤백되고 이후 적용은 중단)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigException;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\MigrationException;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SystemClock;

$opt = getopt('', ['status', 'db:']);
try {
    $config = Config::fromRoot(PJG_ROOT);
} catch (ConfigException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$paths = new Paths(PJG_ROOT, $config);
$clock = new SystemClock();
$logger = new Logger($paths->logs(), $clock, $config->string('log.level'));
$dbFile = $opt['db'] ?? $paths->dbFile();
$db = new Db($dbFile, $config->int('database.busy_timeout') * 1000);
$migrator = new Migrator($db, PJG_ROOT . '/migrations', $clock, Migrator::defaultBackupHook($logger));

$printStatus = static function () use ($migrator, $dbFile): void {
    echo "DB: {$dbFile}\n";
    $labels = ['applied' => '적용됨', 'pending' => '대기', 'modified' => '적용 뒤 파일 변경됨', 'missing' => '파일 없음'];
    foreach ($migrator->status() as $s) {
        printf("  %s  %-28s %-12s %s\n", $s['version'], $s['name'], $labels[$s['state']], $s['applied_at'] ?? '');
    }
};

try {
    if (isset($opt['status'])) {
        $printStatus();
        exit(0);
    }
    $done = $migrator->migrate();
    echo $done === [] ? "적용할 마이그레이션 없음(최신 상태)\n" : '적용함: ' . implode(', ', $done) . "\n";
    $logger->info('마이그레이션 실행', ['applied' => $done, 'db' => $dbFile]);
    $printStatus();
    exit(0);
} catch (MigrationException $e) {
    fwrite(STDERR, '마이그레이션 실패: ' . $e->getMessage() . "\n");
    $logger->error('마이그레이션 실패', ['version' => $e->version, 'error' => $e->getMessage()]);
    exit(1);
}
