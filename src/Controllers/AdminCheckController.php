<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 관리자 "점검" API(P20A, 읽기 전용 + 관리 작업 요청 2개, admin 전용). 비밀값은 설정 여부만, 서버 경로는 가린다.
 */
final class AdminCheckController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    /** GET /api/v1/admin/checks: 설정·환경·디스크·정리 작업·최근 7일 실패 분석 */
    public function show(Request $request): Response
    {
        $checks = $this->app->systemChecks();
        $hb = $this->app->workerState()->read();
        $env = $checks->environment($this->app->dataDirs(), $this->app->paths->bin(), $request->secure, is_array($hb['node'] ?? null) ? $hb['node'] : null,
            $this->app->config->iniPath() !== null ? dirname((string) $this->app->config->iniPath()) : $this->app->root . '/config', $this->app->root . '/public');
        // P28B: LDAP이 켜져 있을 때만 — 확장·설정·검색 계정 비밀번호·CA 파일·연결
        // P32B 신뢰 프록시: 이 요청의 직접 접속 주소·전달 헤더 기준
        $env['items'] = [...$env['items'], ...\PJGuardian\Support\EnvironmentCheck::proxy((array) ($this->app->config->get('security.trusted_proxies') ?? []),
            (string) $request->attribute('remote_addr', $request->clientIp), $request->header('x-forwarded-for') !== null)];
        $env['items'] = [...$env['items'], ...$this->app->ldapAdmin()->checkItems(), ...$this->app->oidcAdmin()->checkItems(), ...$this->app->samlAdmin()->checkItems()];
        $redact = $this->app->redact();
        foreach ($env['items'] as &$it) {
            $it['value'] = $redact->text((string) $it['value']);
        }
        unset($it);
        return Response::data([
            'config' => $checks->configView(),
            'environment' => $env,
            'disk' => $checks->disk(dirname($this->app->paths->dbFile())),
            'cleanup' => $checks->cleanup(),
            'failures' => $checks->failures(),
        ]);
    }

    /** POST /api/v1/admin/checks/disk-scan → 202 {task, merged}: 구분별 사용량 다시 계산(워커 자식) */
    public function diskScan(Request $request): Response
    {
        $r = $this->app->adminTasks()->request('disk_scan', '', (int) $request->attribute('user')['id']);
        $this->app->audit()->record(self::actor($request), 'disk.scan_request', 'admin_task', (string) $r['task']['id'], ['merged' => $r['merged']]);
        return Response::data($r, 202);
    }

    /** POST /api/v1/admin/cleanup/run → 202 {task, merged}: 지금 정리 실행(워커 자식, 중복 실행 방지) */
    public function cleanupRun(Request $request): Response
    {
        $r = $this->app->adminTasks()->request('cleanup_run', '', (int) $request->attribute('user')['id']);
        $this->app->audit()->record(self::actor($request), 'cleanup.run_request', 'admin_task', (string) $r['task']['id'], ['merged' => $r['merged']]);
        return Response::data($r, 202);
    }
}
