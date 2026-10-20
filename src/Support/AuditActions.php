<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 감사 로그 작업 이름 목록(P30, 감사 로그 화면의 "작업" 선택지·docs/audit_actions.md). 기록은 코드 곳곳의 AuditLog::record가 하고,
 * 이 표는 그 이름을 한 곳에 모은 것이다 — AuditActionsTest가 코드에 문자열로 쓴 작업 이름이 모두 여기 있는지 검사한다.
 * 로그인 성공·실패·로그아웃은 감사 로그가 아니라 앱 로그(명세 10장 결정 — auth.login_failed는 IP 차단·잠금 같은 보안 사건만).
 */
final class AuditActions
{
    /** 접두사(작업 묶음) → 그 묶음의 작업 이름 */
    public const GROUPS = [
        'user' => ['user.create', 'user.update', 'user.role_change', 'user.deactivate', 'user.activate', 'user.temp_password', 'user.password_set', 'user.unlock',
            'user.auth_type_change', 'user.bulk_import', 'user.pending_create', 'user.approve', 'user.reject', 'user.pending_delete', 'user.pending_purge', 'user.external_link'],
        'cli' => ['cli.user_unlock', 'cli.user_password_reset'],
        'auth' => ['auth.login_failed', 'auth.ldap_secret_set', 'auth.ldap_secret_delete', 'auth.oidc_secret_set', 'auth.oidc_secret_delete',
            'auth.saml_sp_key_generate', 'auth.saml_sp_key_delete', 'auth.saml_metadata_import'],
        'group' => ['group.create', 'group.rename', 'group.update', 'group.delete', 'group.member_add', 'group.member_remove', 'group.manager_set', 'group.manager_unset'],
        'project' => ['project.create', 'project.update', 'project.delete', 'project.access_add', 'project.access_remove'],
        'run' => ['run.detail_delete'],
        'repo' => ['repo.save', 'repo.delete', 'repo.test', 'repo.fetch_now', 'repo.host_test'],
        'repo_host' => ['repo_host.add', 'repo_host.update', 'repo_host.delete'],
        'schedule' => ['schedule.save', 'schedule.auto_disable'],
        'rules' => ['rules.override_set', 'rules.override_clear', 'rules.override_reset_all', 'rules.override_bulk', 'rules.reload_request', 'rules.load'],
        'export' => ['export.defects', 'export.rules'],
        'tool' => ['tool.block', 'tool.unblock', 'tool.pin', 'tool.unpin', 'tool.mark_removal', 'tool.unmark_removal', 'tool.package_staged',
            'tool.package_discarded', 'tool.package_hash_set', 'tool.reverify_request'],
        'worker' => ['worker.start_request', 'worker.stop_request', 'worker.restart_request'],
        'disk' => ['disk.scan_request'],
        'cleanup' => ['cleanup.run_request'],
        'config' => ['config.update'],
        'maintenance' => ['maintenance.on', 'maintenance.off'], // P32B 업그레이드 유지보수 모드
        'setting' => ['setting.update'],
        'backup' => ['backup.create', 'backup.delete', 'backup.download', 'backup.upload', 'backup.verify'],
        'restore' => ['restore.start', 'restore.finish', 'restore.rollback', 'restore.rehearsal'],
        'key' => ['key.export', 'key.import'],
        'faq' => ['faq.create', 'faq.update', 'faq.publish', 'faq.delete', 'faq.reorder', 'faq.import'],
        'audit' => ['audit.export', 'audit.purge'],
        'mail' => ['mail.secret_set', 'mail.secret_delete', 'mail.test'], // P30B 관리자 장애 이메일
    ];

    /** @return list<string> 모든 작업 이름 */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** @return list<string> 접두사(화면 선택지: "user.*" 등) */
    public static function prefixes(): array
    {
        return array_keys(self::GROUPS);
    }
}
