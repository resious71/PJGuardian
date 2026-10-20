<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 화면 → 가이드 절(P29 도움말 링크) 한 곳. View::page가 템플릿 이름으로 찾아 레이아웃 머리 오른쪽에 "이 화면 도움말"(partials/help_link)을 그린다.
 * 값: [audience, key] — 사용자 가이드는 /guide/user#<key>, 관리자 가이드는 /guide/admin#<key>(P29C). 관리자 절 링크는 **admin에게만** 그린다
 * (그룹 목록처럼 그룹 관리자도 여는 화면이 있어서 — View::page가 역할로 거른다).
 * 설정 관리는 카테고리 탭마다 절이 달라 CONFIG_TABS로(화면 스크립트가 탭을 바꿀 때 링크를 고친다).
 * GuideCatalogTest가 모든 key가 ko·en 절로 풀리는지 검사한다.
 */
final class GuideLinks
{
    /** @var array<string, array{0:string, 1:string}> */
    public const SCREENS = [
        'projects/list' => ['user', 'projects'],
        'projects/show' => ['user', 'defects'],
        'upload' => ['user', 'upload'],
        'analyses/show' => ['user', 'analysis-status'],
        'defects/picker' => ['user', 'defects'],
        'defects/show' => ['user', 'defects'],
        'defects/mine' => ['user', 'defects'],
        'history/picker' => ['user', 'history'],
        'history/show' => ['user', 'history'],
        'dashboard/index' => ['user', 'dashboard'],
        'rules/index' => ['user', 'rules'],
        'rules/show' => ['user', 'rules'],
        'notifications/index' => ['user', 'notifications'],
        'account' => ['user', 'account'],
        'password' => ['user', 'account'],
        // P29C 관리자 화면(admin에게만 링크)
        'admin/users' => ['admin', 'accounts'],
        'admin/users_import' => ['admin', 'account-csv'],
        'groups/list' => ['admin', 'groups-access'],
        'groups/show' => ['admin', 'groups-access'],
        'groups/form' => ['admin', 'groups-access'],
        'groups/delete' => ['admin', 'groups-access'],
        'projects/form' => ['admin', 'projects-admin'],
        'projects/delete' => ['admin', 'projects-admin'],
        'admin/tools' => ['admin', 'tools-bin'],
        'admin/worker' => ['admin', 'worker'],
        'admin/checks' => ['admin', 'monitoring'],
        'admin/monitoring' => ['admin', 'monitoring'], // P30 시스템 모니터링
        'admin/audit_logs' => ['admin', 'audit-log'], // P30 감사 로그
        'admin/runs' => ['admin', 'projects-admin'],
        'admin/rule_settings' => ['admin', 'rules-admin'],
        'admin/config_settings' => ['admin', 'settings'],
        'admin/schedules' => ['admin', 'schedule'],
        'admin/backups' => ['admin', 'backup-restore'],
    ];

    /**
     * 시스템 관리 > 설정 관리의 카테고리 탭(ConfigSchema 묶음 id) → 관리자 가이드 절. 없는 탭(예: 점검용 더미)은 기본 절(settings)
     *
     * @var array<string, string>
     */
    public const CONFIG_TABS = [
        'retention' => 'retention-disk', 'disk' => 'retention-disk', 'notification' => 'notifications-admin', 'monitoring' => 'monitoring', 'admin_mail' => 'admin-mail', 'schedule' => 'schedule',
        'repository' => 'repository', 'analysis' => 'settings', 'backup' => 'backup-restore', 'auth' => 'external-auth',
        'auth_ldap' => 'auth-ldap', 'auth_oidc' => 'auth-oidc', 'auth_saml' => 'auth-saml',
        'maintenance' => 'deploy-bundle', // P32B 업그레이드 유지보수 모드 — 배포 묶음 절의 "업그레이드 때"
    ];

    /** @return array{0:string, 1:string}|null */
    public static function forTemplate(string $template): ?array
    {
        return self::SCREENS[$template] ?? null;
    }
}
