<?php

declare(strict_types=1);

/**
 * 시험용(AnalysisQueueTest::testAtomicClaimAcrossProcesses): 같은 DB에서 claimNext를 처리할 것이 없을 때까지 반복하고
 * 선점한 분석 id를 JSON 배열로 출력한다. 출발 파일이 생길 때까지 기다렸다가 두 프로세스가 동시에 시작한다.
 * 사용: php claim_race.php <db 파일> <출발 파일> <최대 동시 실행>
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Services\Queue\AnalysisQueue;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

[, $dbFile, $goFile, $max] = $argv;
$db = new Db($dbFile, 10000);
$queue = new AnalysisQueue($db, new SystemClock());
$deadline = microtime(true) + 20;
while (!is_file($goFile) && microtime(true) < $deadline) {
    usleep(1000);
}
$ids = [];
while (($row = $queue->claimNext((int) $max, getmypid())) !== null) {
    $ids[] = (int) $row['id'];
    usleep(3000); // 다른 프로세스와 번갈아 선점하게 잠깐 쉰다(동시 경쟁 상황을 만들기 위해)
}
echo json_encode($ids);
