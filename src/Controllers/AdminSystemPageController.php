<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 관리자 "시스템 관리" 화면(P20A, admin 전용): /admin/tools(분석 도구), /admin/worker(워커), /admin/checks(점검, 읽기 전용).
 * 서버는 틀만 그리고 내용은 JS(pages/admin_tools.js, admin_worker.js, admin_checks.js)가 API로 읽어 textContent로 그린다.
 */
final class AdminSystemPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string, mixed> $vars */
    private function page(Request $request, string $view, string $titleKey, string $sub, string $script, array $vars = []): Response
    {
        return Response::html($this->app->view($request)->page($view, [
            'title' => Lang::get($titleKey),
            'user' => $request->attribute('user'),
            'active' => 'admin.tools',
            'scripts' => [$script],
            'body_class' => 'page-admin-system',
        ], ['sub' => $sub] + $vars));
    }

    public function tools(Request $request): Response
    {
        return $this->page($request, 'admin/tools', 'admin_tools.title', 'tools', 'js/pages/admin_tools.js', [
            'maxBytes' => $this->app->config->int('tools.package_max_size'),
            'retentionDays' => intdiv($this->app->config->int('tools.staging_retention'), 86400),
        ]);
    }

    public function worker(Request $request): Response
    {
        return $this->page($request, 'admin/worker', 'admin_worker.title', 'worker', 'js/pages/admin_worker.js', [
            'supervised' => $this->app->config->bool('worker.supervised'),
            'logLines' => $this->app->config->int('worker.log_tail_lines'),
        ]);
    }

    public function checks(Request $request): Response
    {
        // P32B 용량 추정 도우미: 지금 설정값(계산은 화면의 순수 함수 lib/capacity.js — 서버 상태를 바꾸지 않음)
        $c = $this->app->config;
        $days = max(1, $c->int('backup.retention_days')); // 일 단위 정수
        $mode = $c->string('backup.schedule_mode');
        $kept = $c->int('backup.retention_max_count') > 0 ? $c->int('backup.retention_max_count')
            : max($c->int('backup.retention_min_count'), (int) ceil($days / ($mode === 'weekly' ? 7 : ($mode === 'monthly' ? 30 : 1))));
        return $this->page($request, 'admin/checks', 'admin_checks.title', 'checks', 'js/pages/admin_checks.js', ['capacity' => [
            'maxConcurrent' => $c->int('analysis.max_concurrent'), 'snapshotKeep' => $c->int('snapshot.full_keep_count'), 'runsKept' => $c->int('runs.detail_keep'),
            'auditDays' => intdiv($c->int('log.audit_retention'), 86400), 'warnPercent' => $c->int('disk.warn_percent'), 'backupsKept' => $kept,
            'backupSnapshots' => true,
        ]]);
    }
}
