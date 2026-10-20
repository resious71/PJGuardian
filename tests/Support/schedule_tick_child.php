<?php

declare(strict_types=1);

/**
 * 시험용(P26 ScheduleTickerTest): 두 프로세스가 같은 순간에 스케줄러 틱을 돌려 같은 도래가 한 번만 등록되는지 본다.
 * 사용: php schedule_tick_child.php <config.ini> <secrets.ini> <출발 신호 파일> <반복 수>
 * P31(시간에 민감한 시험 안정화): 고정 시작 시각(부모 기준 +1.5초) 대신 — 앱을 띄운 뒤 "<신호 파일>.ready.<PID>"를 만들고,
 * 부모가 모든 자식의 준비 파일을 본 뒤 만드는 신호 파일을 기다렸다가 함께 출발한다(부하로 자식 시작이 늦어도 동시성이 유지됨).
 * 틱을 반복 수만큼 돌리고 결과를 JSON 한 줄로 낸다.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Support\Config;

[, $ini, $secrets, $go, $rounds] = $argv;
$app = App::create(PJG_ROOT, Config::load($ini, $secrets));
$ticker = $app->scheduleTicker();
$app->db()->value('SELECT 1'); // 연결을 미리 열어 둔다(출발 뒤 차이를 줄임)
touch($go . '.ready.' . getmypid());
$deadline = microtime(true) + 120;
while (!is_file($go) && microtime(true) < $deadline) {
    usleep(200);
}
$out = [];
for ($i = 0; $i < (int) $rounds; $i++) {
    try {
        $out[] = $ticker->tick();
    } catch (\Throwable $e) {
        $out[] = ['error' => $e->getMessage()];
    }
}
echo json_encode($out), "\n";
