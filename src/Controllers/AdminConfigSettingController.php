<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Lang;

/**
 * 관리자 "설정 관리"(P27A, admin만 — 라우트 미들웨어 $admin, 쓰기는 CSRF). 허용 목록 키만 다루고 값은 config/settings.local.ini에 저장한다.
 * DB에 저장하는 화면 설정(대시보드 추이 수·알림 보관 — P21C·P24 /api/v1/admin/settings)과는 별개다(화면에 안내 링크만).
 *
 *   GET    /admin/config-settings                         화면
 *   GET    /api/v1/admin/config-settings                  항목·유효값·출처·기준 값·버전 표지·워커 반영·변경 이력
 *   PUT    /api/v1/admin/config-settings                  {changes: {키: 값}, version, create_paths?: [키]} — 전체 성공 또는 전체 실패
 *   POST   /api/v1/admin/config-settings/validate         {key, value, create?} 경로 확인(저장 없음)
 *   DELETE /api/v1/admin/config-settings/{key}?version=   기본값으로(화면 설정 지움)
 */
final class AdminConfigSettingController
{
    public function __construct(private readonly App $app)
    {
    }

    public function page(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('admin/config_settings', [
            'title' => Lang::get('config_settings.title'), 'user' => $request->attribute('user'), 'active' => 'admin.tools', // X10: 시스템 관리의 탭
            // X12: 다른 저장소의 두 구역(알림 보관 기간 = app_settings, 허용 호스트 = DB 표)을 함께 싣는다 — 각자 자기 API로 저장
            'scripts' => ['js/pages/admin_config_settings.js', 'js/pages/admin_notifications.js', 'js/pages/admin_repo_hosts.js'], 'body_class' => 'page-admin-config',
        ], ['allowLocalDev' => $this->app->config->bool('repository.allow_local_dev'), 'hostTestMax' => $this->app->config->int('repository.host_test_max')]));
    }

    public function index(Request $request): Response
    {
        return Response::data($this->app->settingsService()->view());
    }

    public function update(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || !is_array($body['changes'] ?? null) || !is_string($body['version'] ?? null)) {
            throw new ApiException(400, 'invalid_config_settings');
        }
        $create = $body['create_paths'] ?? [];
        if (!is_array($create) || !array_is_list($create)) {
            throw new ApiException(400, 'invalid_config_settings');
        }
        return Response::data($this->app->settingsService()->update($this->actor($request), $body['changes'], $body['version'],
            array_values(array_filter($create, 'is_string'))));
    }

    public function validate(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || !is_string($body['key'] ?? null) || !is_string($body['value'] ?? null)) {
            throw new ApiException(400, 'invalid_config_settings');
        }
        return Response::data($this->app->settingsService()->validatePath($body['key'], $body['value'], ($body['create'] ?? false) === true));
    }

    public function reset(Request $request): Response
    {
        $version = $request->query['version'] ?? null;
        if (!is_string($version)) {
            throw new ApiException(400, 'invalid_config_settings');
        }
        return Response::data($this->app->settingsService()->reset($this->actor($request), (string) $request->param('key'), $version));
    }

    private function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }
}
