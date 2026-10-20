<?php

declare(strict_types=1);

/**
 * 관리 작업 한 건을 하는 워커의 자식 프로세스(P20A). tools/worker.php가 admin_tasks에서 가져온(running) 뒤 띄운다.
 *   tool_verify  '<도구>@<버전>'  전체 체크섬 + 스모크 테스트(ToolRegistry::reverify, 도구별 잠금 안에서)
 *   cleanup_run                   정리 작업 전체(tools/cleanup.php와 같은 CleanupService::runAll) + 마지막 실행 기록
 *   disk_scan                     구분별 디스크 사용량 계산 + 정리 대기 미리보기(dry-run) → <work>/admin/disk_usage.json
 * 결과 요약(서버 경로 없음)을 admin_tasks.result에 쓴다. 상한(worker.admin_task_timeout)은 부모가 감독한다.
 *
 * 사용법: php tools/run_admin_task.php [--config=<config.ini>] <작업 id>
 * 종료 코드: 0 처리함(결과는 작업 상태), 1 예외, 2 사용법·설정 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Admin\SystemChecks;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Config;
use PJGuardian\Support\SystemClock;

$opt = getopt('', ['config:'], $rest);
$id = $argv[$rest] ?? '';
if (preg_match('/^\d{1,12}$/', $id) !== 1) {
    fwrite(STDERR, "사용법: php tools/run_admin_task.php [--config=<config.ini>] <작업 id>\n");
    exit(2);
}
try {
    $app = App::create(PJG_ROOT, isset($opt['config']) ? Config::load((string) $opt['config']) : null);
} catch (\Throwable $e) {
    fwrite(STDERR, '설정 오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$app->logger->withJobId('admin:' . $id);
$tasks = $app->adminTasks();
try {
    $task = $tasks->get((int) $id);
    if ($task['status'] !== 'running') {
        $app->logger->warn('실행 중 상태가 아닌 관리 작업(이미 처리됨)', ['status' => $task['status']]);
        exit(0);
    }
    switch ($task['kind']) {
        case 'tool_verify':
            [$tool, $version] = explode('@', $task['target'], 2) + [1 => ''];
            if (!in_array($tool, ToolRegistry::TOOLS, true) || !ToolRegistry::validVersion($version)) {
                $tasks->finish((int) $id, false, ['code' => 'invalid_target']);
                break;
            }
            $r = $app->toolRegistry()->reverify($tool, $version);
            $tasks->finish((int) $id, $r['ok'], ['status' => $r['status'], 'message' => mb_substr($app->redact()->text($r['message']), 0, 500)]);
            // 활성 버전이 바뀌었을 수 있다 → 규칙 데이터 적재 요청(P22, 바뀐 것이 없으면 적재가 아무것도 하지 않음)
            if ($app->config->bool('rules.auto_load')) {
                $tasks->request('rules_load', '', null);
            }
            break;
        case 'cleanup_run':
            $r = $app->cleanup()->runAll(false);
            SystemChecks::recordCleanup($app->paths->work(), SystemClock::iso($app->clock->now()), 'admin', true, $r);
            $tasks->finish((int) $id, true, $r);
            break;
        case 'disk_scan':
            $cleanup = $app->cleanup();
            $data = $app->diskUsage()->scan(static fn (): array => $cleanup->runAll(true));
            $tasks->finish((int) $id, true, ['duration_ms' => $data['duration_ms']]);
            break;
        case 'rules_load':
            // 규칙 데이터 적재(P22): 활성 버전 + 아직 적재하지 않은 설치 버전. 실패한 도구가 있어도 나머지는 적재(분석에는 영향 없음)
            $report = $app->ruleLoader()->load();
            $actor = $task['requested_by'] === null ? \PJGuardian\Services\Actor::system()
                : \PJGuardian\Services\Actor::fromUser($app->users()->get((int) $task['requested_by']), null);
            $app->ruleLoader()->audit($actor, $report);
            $summary = [];
            foreach ($report['tools'] as $tool => $t) {
                $summary[$tool] = ['active' => $t['active'], 'versions' => array_map(static fn (array $v): array => isset($v['error']) ? ['error' => true]
                    : ['rules' => $v['rules'], 'unchanged' => $v['unchanged'], 'ms' => $v['ms']], $t['versions'])];
            }
            $tasks->finish((int) $id, $report['errors'] === [], ['tools' => $summary, 'changed' => $report['changed'],
                'errors' => array_map(static fn (string $e): string => mb_substr($app->redact()->text($e), 0, 300), array_slice($report['errors'], 0, 5))]);
            if ($report['errors'] !== []) {
                $app->logger->warn('규칙 데이터 적재 일부 실패(분석에는 영향 없음)', ['errors' => array_slice($report['errors'], 0, 5)]);
            }
            break;
        default:
            $tasks->finish((int) $id, false, ['code' => 'unknown_kind']);
    }
    exit(0);
} catch (\Throwable $e) {
    $app->logger->error('관리 작업 예외', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    try {
        $tasks->finish((int) $id, false, ['code' => 'exception']);
    } catch (\Throwable) {
        // 부모가 실패로 닫는다
    }
    exit(1);
}
