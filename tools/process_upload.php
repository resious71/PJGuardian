<?php

declare(strict_types=1);

/**
 * 업로드 후처리(명세 5.4, 19.10)를 워커 없이 수동으로 한다(점검·복구용). 운영은 tools/worker.php가 같은 서비스를 자식 프로세스로 부른다(P14).
 * 워커와 동시에 돌지 않도록 워커의 단일 인스턴스 잠금을 잡는다(워커가 실행 중이면 종료 코드 3).
 *   1) 조립: assemble_pending → assembling → extracting (UploadAssembler, P12)
 *   2) 해제·사전 검사: extracting → needs_confirmation(경고) 또는 queued(분석 대기열) / failed (UploadProcessor, P13)
 * PHP memory_limit은 설정 extract.worker_memory_limit(웹 요청과 별도, 명세 19.10)으로 맞춘다.
 *
 * 사용법: php tools/process_upload.php --once|--all [--config=<config.ini>]
 *   --once     조립 대기 하나를 조립하고, 해제 대기 하나를 처리한다
 *   --all      처리할 것이 없을 때까지 반복한다
 *   --config   다른 설정 파일(점검용 격리 환경). 기본은 config/config.ini
 * 종료 코드: 성공(또는 처리할 것 없음) 0, 실패한 업로드가 있으면 1, 설정·실행 오류 2, 워커가 실행 중 3
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Support\Config;

$opt = getopt('', ['once', 'all', 'config:']);
if (!isset($opt['once']) && !isset($opt['all'])) {
    fwrite(STDERR, "사용법: php tools/process_upload.php --once|--all [--config=<config.ini>]\n");
    exit(2);
}

try {
    $config = isset($opt['config']) ? Config::load((string) $opt['config']) : null;
    $app = App::create(PJG_ROOT, $config);
    $lock = $app->workerState()->tryLock();
    if ($lock === null) {
        echo "워커가 실행 중입니다. 업로드 후처리는 워커가 합니다(tools/worker.php --status).\n";
        exit(3);
    }
    ini_set('memory_limit', (string) $app->config->int('extract.worker_memory_limit'));
    $assembler = $app->uploadAssembler();
    $processor = $app->uploadProcessor();
    $failed = 0;
    $did = 0;
    do {
        $round = 0;
        $a = $assembler->assembleNext();
        if ($a !== null) {
            $round++;
            if ($a['status'] === 'failed') {
                $failed++;
                printf("[조립 실패] %s (사유는 로그) %.2f초\n", $a['upload_id'], $a['seconds']);
            } else {
                printf("[조립] %s 크기 %d bytes, SHA-256 %s, %.2f초, PHP 최대 메모리 %.1fMB → %s\n",
                    $a['upload_id'], $a['size'], $a['sha256'], $a['seconds'], $a['peak_memory'] / 1048576, $a['status']);
            }
        }
        $p = $processor->processNext();
        if ($p !== null) {
            $round++;
            if ($p['status'] === 'failed') {
                $failed++;
                printf("[해제 실패] %s %s: %s\n", $p['upload_id'], $p['error_code'], $p['error_message']);
            } else {
                printf("[해제] %s 파일 %d개 %d bytes, 제외 %d개, 대상 %d개, 최상위 폴더 \"%s\" 제거 \"%s\", %.2f초, 경고 %s → %s%s\n",
                    $p['upload_id'], $p['files'], $p['bytes'], $p['skipped'], $p['targets'], $p['top_folder'], $p['stripped_prefix'], $p['seconds'],
                    $p['warnings'] === [] ? '없음' : implode(',', $p['warnings']), $p['status'],
                    isset($p['analysis_id']) ? ' (분석 #' . $p['analysis_id'] . ')' : '');
            }
        }
        $did += $round;
    } while (isset($opt['all']) && $round > 0);
    if ($did === 0) {
        echo "처리할 업로드 없음(조립 대기·해제 대기 0개)\n";
    }
    exit($failed > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
