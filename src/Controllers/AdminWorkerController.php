<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Support\LogTail;

/**
 * 관리자 "워커" API(P20A, admin 전용). 상태·큐는 읽기 전용, 정지·재시작은 파일 신호, 시작은 설정의 고정 명령(WorkerControl).
 * 웹은 tools/worker.php를 실행하지 않고 PID로 프로세스를 종료하지 않는다. 관리자 분석 취소는 없다(요청자만 취소).
 */
final class AdminWorkerController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    public function show(Request $request): Response
    {
        return Response::data($this->app->workerControl()->status());
    }

    /** POST: 안전 정지 요청(진행 중인 작업은 끝낸 뒤 종료) */
    public function stop(Request $request): Response
    {
        return Response::data($this->app->workerControl()->requestStop(self::actor($request), 'stop'));
    }

    /** POST: 재시작 요청(worker.supervised=true일 때만, 아니면 409 restart_not_supervised) */
    public function restart(Request $request): Response
    {
        return Response::data($this->app->workerControl()->requestStop(self::actor($request), 'restart'));
    }

    /** POST: 사전 등록 작업 시작(worker.start_command). 요청 본문은 쓰지 않는다(명령에 UI 값이 들어가지 않음) */
    public function start(Request $request): Response
    {
        return Response::data($this->app->workerControl()->start(self::actor($request)));
    }

    /**
     * GET /api/v1/admin/logs?scope=worker|all&lines=N: 오늘(없으면 어제) 앱 로그의 마지막 N줄(기본 worker.log_tail_lines).
     * 끝에서부터 읽고(LogTail), 줄마다 경로·비밀값을 가린다(Redact). scope=worker는 웹 요청이 아닌 줄(request_id 없음: 워커·CLI)만.
     */
    public function logs(Request $request): Response
    {
        $max = $this->app->config->int('worker.log_tail_lines');
        $lines = $request->query['lines'] ?? null;
        $n = is_string($lines) && ctype_digit($lines) ? max(10, min((int) $lines, 2000)) : $max;
        $scope = ($request->query['scope'] ?? 'worker') === 'all' ? 'all' : 'worker';
        if (!in_array($request->query['scope'] ?? 'worker', ['worker', 'all'], true)) {
            throw new ApiException(400, 'invalid_log_scope');
        }
        $now = $this->app->clock->now();
        $file = null;
        foreach ([$now, $now->modify('-1 day')] as $t) {
            $f = $this->app->logger->fileFor($t);
            if (is_file($f)) {
                $file = $f;
                break;
            }
        }
        $out = [];
        $truncated = 0;
        if ($file !== null) {
            $r = LogTail::read($file, $scope === 'all' ? $n : min(2000, $n * 10));
            $truncated = $r['truncated_lines'];
            foreach ($r['lines'] as $line) {
                if ($scope === 'worker' && preg_match('/"request_id":"[^"]+"/', $line) === 1) {
                    continue;
                }
                $out[] = $line;
            }
            $out = array_slice($out, -$n);
        }
        return Response::data([
            'file' => $file !== null ? basename($file) : null, 'scope' => $scope, 'lines' => $this->app->redact()->lines($out),
            'requested' => $n, 'truncated_lines' => $truncated,
        ]);
    }
}
