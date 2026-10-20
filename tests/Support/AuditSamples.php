<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Support\Db;

/**
 * 감사 작업별 대표 샘플(X17 AuditDescriberTest·check_audit_ui 가짜 데이터): 코드가 실제로 남기는 details 모양 그대로.
 * 작업 이름 목록(AuditActions)에 작업이 늘면 여기에도 샘플이 있어야 AuditDescriberTest가 통과한다.
 *
 * 고정 데이터(fixture): 사용자 1 alex(관리자, 표시 이름 "Alex Kim")·2 jordan(일반 사용자), 프로젝트 1 Billing, 그룹 1 Platform, FAQ 1 "비밀번호를 잊었어요".
 */
final class AuditSamples
{
    /** 작업 → [행위자, 대상 종류, 대상 id, details] (행위자: 로그인 ID = 사용자 1, 또는 system·cli·restore:<id>·login@<ip>) */
    public const ROWS = [
        'user.create' => ['alex', 'user', '2', ['login_id' => 'jordan', 'role' => 'user', 'auth_type' => 'local', 'actor_role' => 'admin']],
        'user.update' => ['alex', 'user', '2', ['actor_role' => 'admin', 'self' => false, 'display_name' => ['from' => 'Jordan', 'to' => 'Jordan Lee'], 'email' => ['from' => 'j***@example.com', 'to' => 'j***@corp.example']]],
        'user.role_change' => ['alex', 'user', '2', ['from' => 'user', 'to' => 'admin']],
        'user.deactivate' => ['alex', 'user', '2', ['login_id' => 'jordan']],
        'user.activate' => ['alex', 'user', '2', ['login_id' => 'jordan']],
        'user.temp_password' => ['alex', 'user', '2', ['login_id' => 'jordan', 'expires_at' => '2026-10-03T03:00:00Z']],
        'user.password_set' => ['alex', 'user', '2', ['login_id' => 'jordan']],
        'user.unlock' => ['alex', 'user', '2', ['login_id' => 'jordan', 'was_locked' => true]],
        'user.auth_type_change' => ['alex', 'user', '2', ['login_id' => 'jordan', 'from' => 'local', 'to' => 'ldap']],
        'user.bulk_import' => ['alex', null, null, ['created' => 12, 'deactivated' => 2, 'skipped' => 1, 'errors_skipped' => 0, 'warnings' => 3, 'rows' => 15, 'encoding' => 'UTF-8', 'file_sha256' => '0a1b2c3d4e5f', 'skip_errors' => false]],
        'user.pending_create' => ['system', 'user', '2', ['login_id' => 'jordan', 'auth_type' => 'oidc']],
        'user.approve' => ['alex', 'user', '2', ['login_id' => 'jordan', 'role' => 'user', 'from' => 'pending', 'auth_type' => 'oidc']],
        'user.reject' => ['alex', 'user', '2', ['login_id' => 'jordan', 'auth_type' => 'saml']],
        'user.pending_delete' => ['alex', 'user', '77', ['login_id' => 'ghost', 'from' => 'rejected']],
        'user.pending_purge' => ['system', null, null, ['count' => 4, 'retention_days' => 30]],
        'user.external_link' => ['system', 'user', '2', ['login_id' => 'jordan', 'auth_type' => 'ldap']],
        'cli.user_unlock' => ['cli', 'user', '2', ['login_id' => 'jordan', 'was_locked' => true]],
        'cli.user_password_reset' => ['cli', 'user', '2', ['login_id' => 'jordan', 'expires_at' => '2026-10-03T03:00:00Z']],
        'auth.login_failed' => ['login@10.0.0.7', null, null, ['login_id' => 'sam', 'reason' => 'local_account', 'auth_type' => 'ldap']],
        'auth.ldap_secret_set' => ['alex', null, null, ['replaced' => true]],
        'auth.ldap_secret_delete' => ['alex', null, null, []],
        'auth.oidc_secret_set' => ['alex', null, null, ['replaced' => false]],
        'auth.oidc_secret_delete' => ['alex', null, null, []],
        'auth.saml_sp_key_generate' => ['alex', null, null, ['replaced' => false]],
        'auth.saml_sp_key_delete' => ['alex', null, null, []],
        'auth.saml_metadata_import' => ['alex', null, null, ['source' => 'url', 'certs' => 2]],
        'group.create' => ['alex', 'group', '1', ['name' => 'Platform']],
        'group.rename' => ['alex', 'group', '1', ['name' => ['from' => 'Infra', 'to' => 'Platform']]],
        'group.update' => ['alex', 'group', '1', ['description' => ['from' => '', 'to' => '플랫폼 팀']]],
        'group.delete' => ['alex', 'group', '9', ['name' => 'Legacy', 'members' => 3, 'projects' => 1]],
        'group.member_add' => ['alex', 'group', '1', ['user_id' => 2, 'login_id' => 'jordan', 'actor_role' => 'admin']],
        'group.member_remove' => ['alex', 'group', '1', ['user_id' => 2, 'login_id' => 'jordan', 'actor_role' => 'admin', 'was_manager' => false]],
        'group.manager_set' => ['alex', 'group', '1', ['user_id' => 2, 'login_id' => 'jordan', 'actor_role' => 'admin']],
        'group.manager_unset' => ['alex', 'group', '1', ['user_id' => 2, 'login_id' => 'jordan', 'actor_role' => 'admin']],
        'project.create' => ['alex', 'project', '1', ['name' => 'Billing', 'language' => 'python']],
        'project.update' => ['alex', 'project', '1', ['description' => ['from' => '', 'to' => '결제 서비스'], 'exclude_patterns' => ['from' => ['build/'], 'to' => ['build/', 'dist/']]]],
        'project.delete' => ['alex', 'project', '5', ['name' => 'Old app', 'language' => 'javascript', 'analyses' => 14, 'defects' => 230, 'snapshot_bytes' => 5242880]],
        'project.access_add' => ['alex', 'project', '1', ['subject_type' => 'group', 'subject_id' => 1, 'name' => 'Platform']],
        'project.access_remove' => ['alex', 'project', '1', ['subject_type' => 'user', 'subject_id' => 2, 'name' => 'jordan']],
        'run.detail_delete' => ['system', 'project', '1', ['runs' => [3, 4], 'reason' => 'retention']],
        'repo.save' => ['alex', 'project', '1', ['kind' => 'git', 'created' => true, 'fields' => ['location', 'branch'], 'auth_change' => 'set']],
        'repo.delete' => ['alex', 'project', '1', []],
        'repo.test' => ['alex', 'project', '1', ['ok' => false, 'code' => 'repo_auth_failed', 'draft' => true]],
        'repo.fetch_now' => ['alex', 'project', '1', ['analysis_id' => 42, 'kind' => 'git']],
        'repo.host_test' => ['alex', 'repo_host', 'git.example.com', ['code' => 'ok', 'ms' => 230]],
        'repo_host.add' => ['alex', 'repo_host', 'git.example.com', ['allow_http' => false, 'github_links' => true]],
        'repo_host.update' => ['alex', 'repo_host', 'git.example.com:8443', ['allow_http' => [0, true], 'github_links' => [1, true]]],
        'repo_host.delete' => ['alex', 'repo_host', 'git.example.com', []],
        'schedule.save' => ['alex', 'project', '1', ['changes' => ['enabled' => [false, true], 'interval_days' => [7, 1]], 'next_run_at' => '2026-10-03T18:00:00Z']],
        'schedule.auto_disable' => ['alex', 'project', '1', ['reason' => 'repo_disconnected']],
        'rules.override_set' => ['alex', 'rule', 'ruff:F401', ['tool' => 'ruff', 'rule' => 'F401', 'before' => true, 'after' => false, 'base' => true, 'reason' => '오탐이 많음']],
        'rules.override_clear' => ['alex', 'rule', 'eslint:no-console', ['tool' => 'eslint', 'rule' => 'no-console', 'before' => false, 'after' => true]],
        'rules.override_reset_all' => ['alex', 'rule', 'ruff', ['tool' => 'ruff', 'count' => 3, 'rules' => ['F401', 'E501', 'W291']]],
        'rules.override_bulk' => ['alex', 'rule', 'eslint', ['tool' => 'eslint', 'action' => 'disable', 'target' => 'prefix:no-', 'total' => 40, 'changed' => 12, 'unchanged' => 28, 'skipped' => [], 'revision' => ['from' => 5, 'to' => 6], 'reason' => '', 'rules' => ['no-console', 'no-debugger'], 'rules_more' => 10]],
        'rules.reload_request' => ['alex', 'rules', null, ['task_id' => 7]],
        'rules.load' => ['cli', 'rules', null, ['tools' => ['ruff' => ['version' => '0.6.9', 'rules' => 800]], 'changed' => 5, 'errors' => 0]],
        'export.defects' => ['alex', 'project', '1', ['format' => 'xlsx', 'rows' => 120, 'run' => 3, 'filter' => ['status' => ['open']]]],
        'export.rules' => ['alex', 'rules', 'ruff', ['format' => 'csv', 'rows' => 800, 'filter' => ['tool' => 'ruff']]],
        'tool.block' => ['alex', 'tool', 'ruff@0.6.8', ['reason' => '회귀 버그']],
        'tool.unblock' => ['alex', 'tool', 'ruff@0.6.8', []],
        'tool.pin' => ['alex', 'tool', 'eslint@10.11.0', []],
        'tool.unpin' => ['alex', 'tool', 'eslint', []],
        'tool.mark_removal' => ['alex', 'tool', 'ruff@0.5.0', []],
        'tool.unmark_removal' => ['alex', 'tool', 'ruff@0.5.0', []],
        'tool.package_staged' => ['alex', 'tool_staging', '3', ['tool' => 'ruff', 'size' => 15728640, 'sha256' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789', 'hash_match' => null]],
        'tool.package_discarded' => ['alex', 'tool_staging', '3', ['tool' => 'ruff']],
        'tool.package_hash_set' => ['alex', 'tool_staging', '3', ['hash_match' => true]],
        'tool.reverify_request' => ['alex', 'tool', 'ruff@0.6.9', ['task_id' => 8, 'merged' => false]],
        'worker.start_request' => ['alex', 'worker', null, ['ok' => true, 'exit_code' => 0, 'reason' => null]],
        'worker.stop_request' => ['alex', 'worker', null, ['running_analyses' => 1, 'supervised' => false]],
        'worker.restart_request' => ['alex', 'worker', null, ['running_analyses' => 0, 'supervised' => true]],
        'disk.scan_request' => ['alex', 'admin_task', '11', ['merged' => false]],
        'cleanup.run_request' => ['alex', 'admin_task', '12', ['merged' => true]],
        'config.update' => ['alex', 'config', 'upload.failed_zip_retention', ['old' => '1d', 'new' => '3d', 'old_source' => 'default', 'file' => 'settings.local.ini']],
        'maintenance.on' => ['alex', 'config', 'maintenance.enabled', ['bundle' => 'pjguardian-20261014-220000-pre_upgrade.pjgbak']],
        'maintenance.off' => ['alex', 'config', 'maintenance.enabled', []],
        'setting.update' => ['alex', 'setting', 'notifications.retain_read_days', ['key' => 'notifications.retain_read_days', 'from' => 7, 'to' => 14]],
        'backup.create' => ['alex', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['kind' => 'manual', 'result' => 'ok', 'size' => 52428800, 'ms' => 4200]],
        'backup.delete' => ['alex', 'backup', 'PJGuardian_backup_20260901_210000_manual.zip', ['size' => 1048576, 'reason' => 'manual']],
        'backup.download' => ['alex', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['size' => 52428800]],
        'backup.upload' => ['alex', 'backup', 'PJGuardian_backup_20260930_210000_daily.zip', ['size' => 41943040]],
        'backup.verify' => ['alex', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['result' => 'ok', 'code' => null]],
        'restore.start' => ['alex', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['wait_mode' => 'wait', 'config_keys' => 2, 'key' => false]],
        'restore.finish' => ['restore:alex', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['schema_from' => 28, 'migrated' => true, 'config_applied' => 2, 'key_imported' => false, 'credentials_unreadable' => '***', 'sessions_cleared' => 5]],
        'restore.rollback' => ['restore:alex', 'backup', null, ['code' => 'restore_io_failed']],
        'restore.rehearsal' => ['alex', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['result' => 'failed', 'code' => 'bundle_hash_mismatch', 'ms' => 1500]],
        'key.export' => ['alex', 'backup', 'SHA256:ab12cd34', ['result' => 'ok']],
        'key.import' => ['alex', 'backup', 'SHA256:ab12cd34', ['result' => 'ok', 'bundle' => 'PJGuardian_backup_20261002_210000_manual.zip']],
        'faq.create' => ['alex', 'faq', '1', ['category' => '계정', 'question' => '비밀번호를 잊었어요', 'answer_length' => 120, 'answer_sha256' => 'f00dbabe1234', 'published' => true]],
        'faq.update' => ['alex', 'faq', '1', ['fields' => ['answer'], 'question' => '비밀번호를 잊었어요', 'answer_length' => 140, 'answer_sha256' => 'c0ffee123456']],
        'faq.publish' => ['alex', 'faq', '1', ['published' => false, 'question' => '비밀번호를 잊었어요']],
        'faq.delete' => ['alex', 'faq', '31', ['category' => '계정', 'question' => '예전 질문']],
        'faq.reorder' => ['alex', 'faq_category', '계정', ['ids' => [3, 1, 2]]],
        'faq.import' => ['cli', 'faq', null, ['added' => 35, 'skipped' => 0, 'published' => true, 'source' => 'seed.ko.json']],
        'audit.export' => ['alex', 'audit_log', null, ['format' => 'csv', 'rows' => 250, 'filter' => ['action' => 'user.']]],
        'audit.purge' => ['system', 'audit_log', null, ['deleted' => 1200, 'through_id' => 1200, 'through_at' => '2026-07-01T00:00:00Z', 'cutoff' => '2026-07-04T12:00:00Z']],
        'mail.secret_set' => ['alex', null, null, ['replaced' => false]],
        'mail.secret_delete' => ['alex', null, null, []],
        'mail.test' => ['alex', null, null, ['ok' => false, 'code' => 'auth', 'recipient' => 'o***@example.com']],
    ];

    /** 고정 데이터를 넣는다(마이그레이션한 빈 DB) */
    public static function fixture(Db $db): void
    {
        $t = '2026-10-01T00:00:00Z';
        $alex = TestDb::user($db, 'alex', 'admin');
        $db->execute("UPDATE users SET display_name = 'Alex Kim' WHERE id = ?", [$alex]);
        $jordan = TestDb::user($db, 'jordan', 'user');
        $db->execute("UPDATE users SET display_name = 'Jordan Lee' WHERE id = ?", [$jordan]);
        TestDb::project($db, 'Billing');
        $db->execute('INSERT INTO groups (name, created_at, updated_at) VALUES (?, ?, ?)', ['Platform', $t, $t]);
        $db->execute(
            'INSERT INTO faq_items (category, question, answer, search_text, sort_order, is_published, version, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 1, 1, ?, ?)',
            ['계정', '비밀번호를 잊었어요', '관리자에게 임시 비밀번호를 요청하세요.', '비밀번호를 잊었어요', $t, $t],
        );
    }
}
