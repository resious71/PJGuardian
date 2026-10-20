<?php

declare(strict_types=1);

/**
 * 분석 워커(명세 5.2, 5.4, 19.8, 19.10, P14). 분석 큐와 업로드 후처리(조립·해제)를 처리한다. 등록 방법은 docs/worker_setup.md.
 *
 * 사용법: php tools/worker.php [--config=<config.ini>] [--once] [--max-seconds=<초>] [--force-stop]
 *         php tools/worker.php [--config=<config.ini>] --stop     실행 중인 워커에 정상 종료 요청(stop.request)
 *         php tools/worker.php [--config=<config.ini>] --status   생존 신호·잠금 상태 출력
 *         php tools/worker.php [--config=<config.ini>] --check    워커가 죽었고 생존 신호가 끊겼으면 남은 작업을 되돌림(정리 작업과 같은 판정)
 *   --once        처리할 것이 없어지면 끝낸다(시험·시연)
 *   --max-seconds 이 시간이 지나면 정상 종료
 *   --force-stop  정지 요청 때 진행 중인 자식을 기다리지 않고 종료(분석은 failed worker_stopped). 정지 신호를 두 번 받아도 같다
 * 종료 코드: 0 정상, 2 설정·실행 오류, 3 이미 다른 워커가 실행 중(단일 인스턴스)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Queue\Worker;
use PJGuardian\Support\Config;

$opt = getopt('', ['config:', 'once', 'max-seconds:', 'force-stop', 'stop', 'status', 'check']);
$configPath = isset($opt['config']) ? (string) realpath((string) $opt['config']) : null;
if (isset($opt['config']) && $configPath === '') {
    fwrite(STDERR, "설정 파일이 없습니다\n");
    exit(2);
}

try {
    // P26: 스케줄러가 저장소 자격 증명을 읽을 수 있는지 확인하므로 --config를 줘도 같은 폴더의 secrets.ini와 환경 변수(PJG_*) 시크릿을 읽는다(분석 자식과 같은 규칙)
    $app = App::create(PJG_ROOT, $configPath !== null ? Config::load($configPath, dirname($configPath) . '/secrets.ini', getenv()) : null);
    $state = $app->workerState();
    if (isset($opt['stop'])) {
        $state->requestStop();
        echo "정상 종료를 요청했습니다(진행 중인 작업이 끝나면 종료). 파일: {$state->stopFile()}\n";
        exit(0);
    }
    if (isset($opt['status'])) {
        $lock = $state->tryLock();
        $alive = $lock === null;
        $lock?->release();
        $hb = $state->read();
        $since = $state->secondsSinceBeat();
        printf("워커: %s, 마지막 생존 신호: %s, 상태: %s, pid: %s, 실행 중 분석 %d개, 업로드 후처리 %d개\n",
            $alive ? '실행 중(잠금 있음)' : '실행 중 아님',
            $since === null ? '없음' : $since . '초 전',
            (string) ($hb['state'] ?? '-'), (string) ($hb['pid'] ?? '-'),
            count((array) ($hb['analyses'] ?? [])), count((array) ($hb['uploads'] ?? [])));
        exit(0);
    }
    if (isset($opt['check'])) {
        $r = $app->workerRecovery()->recoverIfStale();
        printf("판정: %s, 분석 %d개 실패 처리, 업로드 후처리 %d개 되돌림, 남은 자식 %d개 종료\n", $r['state'], $r['analyses'], $r['uploads'], $r['killed']);
        exit(0);
    }
    // 규칙 기본 심각도 매핑(P21S): 형식이 틀리면 분석을 받기 전에 원인을 알리고 끝낸다
    if ($app->config->bool('severity.auto_assign')) {
        $app->severityMap()->validate();
    }
    $worker = new Worker(
        $app,
        $configPath,
        isset($opt['once']),
        isset($opt['force-stop']),
        isset($opt['max-seconds']) ? max(1.0, (float) $opt['max-seconds']) : null,
        static function (string $line): void {
            echo $line, "\n";
        },
    );
    // 관리자 화면 Node "상태 갱신"(P21C)은 설정 파일을 다시 읽어 tools.node_path 변경도 반영한다
    $worker->reloadConfigFrom($configPath ?? PJG_ROOT . '/config/config.ini');
    exit($worker->run());
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
