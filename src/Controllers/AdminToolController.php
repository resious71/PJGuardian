<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 관리자 "분석 도구" API(P20A, /api/v1/admin/tools·tool-staging, admin 전용, CSRF는 전역).
 * 웹은 bin/에 쓰지 않고 도구를 실행하지 않는다: 차단·고정은 DB 값, 재검증은 관리 작업 요청, 패키지는 스테이징 보관만(설치는 서버 CLI).
 */
final class AdminToolController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    /** @return array{0:string, 1:string} */
    private static function target(Request $request): array
    {
        return [(string) $request->param('tool'), (string) $request->param('version')];
    }

    private static function reason(Request $request): mixed
    {
        $body = $request->body() === '' ? [] : $request->json();
        return $body['reason'] ?? null;
    }

    /** GET /api/v1/admin/tools: 도구별 버전 목록·선택 예상·재검증 상태 + Node(워커 생존 신호) */
    public function index(Request $request): Response
    {
        $hb = $this->app->workerState()->read();
        return Response::data($this->app->toolAdmin()->overview(is_array($hb['node'] ?? null) ? $hb['node'] : null));
    }

    /**
     * POST /api/v1/admin/tools/node/refresh(P21C): 워커에게 Node.js를 다시 확인하라고 요청한다(워커 재시작 없음).
     * 확인은 워커의 실행 환경(계정·PATH)에서 한다 — 웹은 node를 실행하지 않는다. 요청 파일만 쓰고, 화면은 GET /api/v1/admin/tools의
     * node.request_at이 이 응답의 requested_at과 같아질 때까지(최대 10초) 기다린다. 10초 안에 다시 누르면 같은 요청을 돌려준다(요청 하나).
     * 워커가 멈췄거나 응답이 없으면 409 worker_not_running(details: 마지막 확인 결과).
     */
    public function nodeRefresh(Request $request): Response
    {
        $state = $this->app->workerState();
        $hb = $state->read();
        $last = is_array($hb['node'] ?? null) ? $hb['node'] : null;
        $worker = $this->app->workerControl()->status()['state'] ?? 'stopped';
        if (!in_array($worker, ['running', 'stopping'], true)) {
            throw new ApiException(409, 'worker_not_running', [], null, [], [], ['worker' => $worker, 'node' => $last]);
        }
        $pending = $state->nodeCheckRequest();
        $now = $this->app->clock->now()->getTimestamp();
        $at = $pending['requested_at'] ?? null;
        $merged = $at !== null && $now - (int) strtotime($at) < self::NODE_REFRESH_WAIT;
        if (!$merged) {
            $at = $state->requestNodeCheck((int) $request->attribute('user')['id']);
        }
        return Response::data(['requested_at' => $at, 'merged' => $merged, 'wait_seconds' => self::NODE_REFRESH_WAIT, 'node' => $last], 202);
    }

    /** 화면이 워커의 확인을 기다리는 시간(초). 넘으면 "응답 없음" */
    private const NODE_REFRESH_WAIT = 10;

    private function overview(): Response
    {
        $hb = $this->app->workerState()->read();
        return Response::data($this->app->toolAdmin()->overview(is_array($hb['node'] ?? null) ? $hb['node'] : null));
    }

    public function block(Request $request): Response
    {
        [$tool, $version] = self::target($request);
        $this->app->toolAdmin()->block(self::actor($request), $tool, $version, self::reason($request));
        return $this->overview();
    }

    public function unblock(Request $request): Response
    {
        [$tool, $version] = self::target($request);
        $this->app->toolAdmin()->unblock(self::actor($request), $tool, $version, self::reason($request));
        return $this->overview();
    }

    public function pin(Request $request): Response
    {
        [$tool, $version] = self::target($request);
        $this->app->toolAdmin()->pin(self::actor($request), $tool, $version, self::reason($request));
        return $this->overview();
    }

    public function unpin(Request $request): Response
    {
        $this->app->toolAdmin()->unpin(self::actor($request), (string) $request->param('tool'), self::reason($request));
        return $this->overview();
    }

    /** POST …/verify → 202 {task, merged}: 같은 도구·버전의 대기·실행 중 요청이 있으면 합친다 */
    public function verify(Request $request): Response
    {
        [$tool, $version] = self::target($request);
        return Response::data($this->app->toolAdmin()->requestVerify(self::actor($request), $tool, $version, self::reason($request)), 202);
    }

    public function markRemoval(Request $request): Response
    {
        [$tool, $version] = self::target($request);
        $this->app->toolAdmin()->markRemoval(self::actor($request), $tool, $version, self::reason($request));
        return $this->overview();
    }

    public function unmarkRemoval(Request $request): Response
    {
        [$tool, $version] = self::target($request);
        $this->app->toolAdmin()->unmarkRemoval(self::actor($request), $tool, $version, self::reason($request));
        return $this->overview();
    }

    // ---- 스테이징 ----

    public function stagingList(Request $request): Response
    {
        return Response::data(['items' => $this->app->toolStaging()->list(), 'max_bytes' => $this->app->config->int('tools.package_max_size'),
            'retention_seconds' => $this->app->config->int('tools.staging_retention')]);
    }

    public function stagingShow(Request $request): Response
    {
        return Response::data($this->app->toolStaging()->get((string) $request->param('id')));
    }

    /** POST {tool, file_name, total_size, expected_sha256?} → 201 */
    public function stagingInit(Request $request): Response
    {
        return Response::data($this->app->toolStaging()->init((int) $request->attribute('user')['id'], $request->json()), 201);
    }

    /** PUT …/chunks/{index}: 본문 = 청크 그대로, 헤더 X-Chunk-Sha256. 순서대로 이어 붙인다 */
    public function stagingChunk(Request $request): Response
    {
        $len = $request->header('content-length');
        return Response::data($this->app->toolStaging()->chunk(
            (string) $request->param('id'), $request->param('index'), $request->header('x-chunk-sha256'), $request->bodyStream(),
            $len !== null && ctype_digit($len) ? (int) $len : null,
        ));
    }

    /** POST …/complete: 전체 SHA-256 계산 → staged. 감사 tool.package_staged */
    public function stagingComplete(Request $request): Response
    {
        $r = $this->app->toolStaging()->complete((string) $request->param('id'));
        $this->app->audit()->record(self::actor($request), 'tool.package_staged', 'tool_staging', $r['id'],
            ['tool' => $r['tool'], 'size' => $r['total_size'], 'sha256' => $r['sha256'], 'hash_match' => $r['hash_match']]);
        return Response::data($r);
    }

    /** PATCH {expected_sha256}: 배포처 공개 해시 입력(빈 값이면 지움) */
    public function stagingUpdate(Request $request): Response
    {
        $r = $this->app->toolStaging()->setExpected((string) $request->param('id'), $request->json()['expected_sha256'] ?? null);
        $this->app->audit()->record(self::actor($request), 'tool.package_hash_set', 'tool_staging', $r['id'], ['hash_match' => $r['hash_match']]);
        return Response::data($r);
    }

    /** DELETE: 폐기. 감사 tool.package_discarded */
    public function stagingDiscard(Request $request): Response
    {
        $r = $this->app->toolStaging()->discard((string) $request->param('id'));
        $this->app->audit()->record(self::actor($request), 'tool.package_discarded', 'tool_staging', $r['id'], ['tool' => $r['tool']]);
        return Response::data($r);
    }
}
