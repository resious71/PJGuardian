<?php

declare(strict_types=1);

/**
 * 작업 디렉토리 휴지통(data/work/trash) 비우기. 워커가 분석 종료 때 작업 디렉토리를 여기로 옮겨 두고(즉시) 이 스크립트를 자식으로 띄운다
 * (파일 100만 개 삭제처럼 오래 걸리는 일을 감독 루프에서 하지 않기 위해, P14). 정리 작업(tools/cleanup.php)도 비운다.
 *
 * 사용법: php tools/empty_trash.php [--config=<config.ini>]
 * 종료 코드: 0 성공, 2 설정 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Support\Config;
use PJGuardian\Support\Trash;

$opt = getopt('', ['config:']);
try {
    $config = isset($opt['config']) ? Config::load((string) $opt['config']) : null;
    $app = App::create(PJG_ROOT, $config);
} catch (\Throwable $e) {
    fwrite(STDERR, '설정 오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$n = Trash::forWork($app->paths->work())->empty();
if ($n > 0) {
    $app->logger->info('작업 디렉토리 휴지통 비움', ['removed' => $n]);
}
exit(0);
