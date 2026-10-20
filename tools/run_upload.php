<?php

declare(strict_types=1);

/**
 * 업로드 후처리 한 건을 하는 워커의 자식 프로세스(명세 5.4, 19.10, P14). tools/worker.php가 업로드를 가져온(claim) 뒤 띄운다.
 *   assemble <upload_id>  조립(UploadAssembler::assembleClaimed): assembling → extracting / failed
 *   extract  <upload_id>  해제·사전 검사(UploadProcessor::processClaimed): extracting(work_dir) → queued / needs_confirmation / failed
 * PHP memory_limit은 부모가 extract.worker_memory_limit으로 넘긴다(-d). 상한(worker.extract_timeout)은 부모가 감독한다.
 *
 * 사용법: php tools/run_upload.php [--config=<config.ini>] assemble|extract <upload_id>
 * 종료 코드: 0 처리함(결과는 업로드 상태), 1 예외(부모가 업로드를 실패 처리), 2 사용법·설정 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Upload\UploadFiles;
use PJGuardian\Support\Config;

$opt = getopt('', ['config:'], $rest);
$stage = $argv[$rest] ?? '';
$id = $argv[$rest + 1] ?? '';
if (!in_array($stage, ['assemble', 'extract'], true) || !UploadFiles::validId($id)) {
    fwrite(STDERR, "사용법: php tools/run_upload.php [--config=<config.ini>] assemble|extract <upload_id>\n");
    exit(2);
}

try {
    $config = isset($opt['config']) ? Config::load((string) $opt['config']) : null;
    $app = App::create(PJG_ROOT, $config);
} catch (\Throwable $e) {
    fwrite(STDERR, '설정 오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$app->logger->withJobId('upload:' . $id);
try {
    $r = $stage === 'assemble' ? $app->uploadAssembler()->assembleClaimed($id) : $app->uploadProcessor()->processClaimed($id);
    if ($r === null) {
        $app->logger->warn('가져온 상태가 아닌 업로드(이미 처리됨)', ['stage' => $stage]);
    }
    exit(0);
} catch (\Throwable $e) {
    $app->logger->error('업로드 후처리 예외', ['stage' => $stage, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    exit(1);
}
