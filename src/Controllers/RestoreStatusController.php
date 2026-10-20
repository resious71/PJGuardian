<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 복원 진행 독립 상태 페이지(P27B): GET /restore-status, GET /api/v1/restore-status. **로그인 없이·DB 없이** 파일(restore_state.json,
 * maintenance.flag)만 읽는다 — 복원 중에는 세션이 없고, 끝나면 세션이 모두 무효라서. 비밀·절대 경로·요청자 이름은 내보내지 않는다
 * (수동 복구 안내는 프로젝트 기준 상대 경로).
 */
final class RestoreStatusController
{
    public function __construct(private readonly App $app)
    {
    }

    public function page(Request $request): Response
    {
        return $this->pageResponse($request, 200);
    }

    public function api(Request $request): Response
    {
        return $this->apiResponse();
    }

    public function pageResponse(Request $request, int $status): Response
    {
        $html = $this->app->view($request)->page('restore/status', [
            'title' => Lang::get('restore_status.title'), 'user' => null, 'scripts' => ['js/pages/restore_status.js'], 'body_class' => 'page-restore-status',
        ], ['maintenance' => is_file($this->app->backupPaths()->maintenanceFlag())]);
        return Response::html($html, $status);
    }

    public function apiResponse(): Response
    {
        return Response::data(self::publicState($this->app));
    }

    /** @return array<string, mixed> */
    public static function publicState(App $app): array
    {
        $paths = $app->backupPaths();
        $d = json_decode((string) @file_get_contents($paths->restoreState()), true);
        $d = is_array($d) ? $d : [];
        $flag = $app->maintenance()->active();
        return [
            'maintenance' => $flag !== null,
            'maintenance_since' => $flag['since'] ?? null,
            'status' => $d['status'] ?? null,
            'bundle' => $d['bundle'] ?? null,
            'started_at' => $d['started_at'] ?? null,
            'finished_at' => $d['finished_at'] ?? null,
            'step' => $d['step'] ?? null,
            'steps' => array_values(array_map(static fn (array $s): array => ['name' => (string) ($s['name'] ?? ''), 'status' => (string) ($s['status'] ?? ''), 'at' => $s['at'] ?? null],
                array_filter((array) ($d['steps'] ?? []), 'is_array'))),
            'error' => is_array($d['error'] ?? null) ? ['code' => (string) ($d['error']['code'] ?? ''), 'params' => (array) ($d['error']['params'] ?? [])] : null,
            'result' => (object) array_intersect_key((array) ($d['result'] ?? []), array_flip(['counts', 'migrated', 'sessions_cleared', 'config_applied', 'key_imported', 'credentials_unreadable', 'from_schema', 'pre_restore_backup'])),
            'manual' => array_values((array) ($d['manual'] ?? [])),
        ];
    }
}
