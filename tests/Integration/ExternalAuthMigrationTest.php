<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\TestCase;

/**
 * 마이그레이션 026(P28B 외부 인증): 부모 표 users 재구성을 외래 키를 끈 채(표지 pjg:foreign-keys-off) 한 트랜잭션으로.
 * 데이터가 있는 025 DB에서: 행·id·순번 보존, 자식 행(알림·알림 설정은 ON DELETE CASCADE) 그대로, foreign_key_check 0건, 외래 키 다시 켜짐,
 * auth_type 4값, 승인 상태 제약, 승인된 계정 삭제 금지(대기 계정은 정리 가능), 트리거·인덱스, 재실행 안전, 빈 DB.
 */
final class ExternalAuthMigrationTest extends TestCase
{
    private function dbAt(string $version): array
    {
        $dir = $this->tempDir('mig026');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/*.sql') ?: [] as $f) {
            if (strcmp(substr(basename($f), 0, 3), $version) <= 0) {
                copy($f, "{$dir}/m/" . basename($f));
            }
        }
        $db = new Db("{$dir}/t.sqlite", 2000);
        (new Migrator($db, "{$dir}/m", new FixedClock('2026-10-11T00:00:00Z')))->migrate();
        return [$db, $dir];
    }

    public function testRebuildKeepsRowsChildrenAndConstraints(): void
    {
        [$db, $dir] = $this->dbAt('025');
        $t = '2026-10-01T00:00:00Z';
        $hash = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('admin', 'Admin', 'admin', 'active', 'local', ?, ?, ?)", [$hash, $t, $t]);
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('dir', 'Dir', 'user', 'active', 'ldap', NULL, ?, ?)", [$t, $t]);
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('gone', 'Gone', 'user', 'inactive', 'local', ?, ?, ?)", [$hash, $t, $t]);
        $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (1, 'backup_done', '{}', 'k1', ?), (2, 'backup_done', '{}', 'k2', ?)", [$t, $t]);
        $db->execute("INSERT INTO notification_prefs (user_id, kind, enabled, updated_at) VALUES (2, 'backup_done', 0, ?)", [$t]);
        $db->execute("INSERT INTO sessions (id_hash, user_id, csrf_token, created_at, last_activity_at) VALUES (?, 1, ?, ?, ?)", [str_repeat("a", 64), str_repeat("b", 64), $t, $t]);
        $db->execute("INSERT INTO login_failures (ip, failed_at) VALUES ('10.0.0.1', ?)", [$t]);
        $before = $this->counts($db);
        $seq = (int) $db->value("SELECT seq FROM sqlite_sequence WHERE name = 'users'");

        copy(PJG_ROOT . '/migrations/026_external_auth.sql', "{$dir}/m/026_external_auth.sql");
        $this->assertSame(['026'], (new Migrator($db, "{$dir}/m", new FixedClock('2026-10-11T00:00:00Z')))->migrate());
        $this->assertSame($before, $this->counts($db), '행 수 보존(알림·알림 설정이 CASCADE로 지워지지 않음)');
        $this->assertSame($seq, (int) $db->value("SELECT seq FROM sqlite_sequence WHERE name = 'users'"), 'AUTOINCREMENT 순번 보존');
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM sqlite_sequence WHERE name LIKE '%_new'"));
        $this->assertSame([], $db->all('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) $db->value('PRAGMA foreign_keys'), '외래 키 다시 켜짐');
        $this->assertSame('ok', $db->value('PRAGMA integrity_check'));
        $this->assertSame(['approved', 'approved', 'approved'], array_column($db->all('SELECT approval_status FROM users ORDER BY id'), 'approval_status'));
        $this->assertSame(['dir', 'ldap', null], array_values($db->one("SELECT login_id, auth_type, password_hash FROM users WHERE id = 2")));
        // 다른 표의 트리거·외래 키 참조가 그대로(users_new·users_old 이름이 남지 않음)
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM sqlite_master WHERE sql LIKE '%users_new%' OR sql LIKE '%users_old%'"));
        $this->assertSame(['users_keep_last_active_admin', 'users_no_delete'], array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'users' ORDER BY name"), 'name'));
        // 제약: 4값, 외부 방식은 비밀번호 없음, 승인 안 된 계정은 비활성·외부, 승인된 계정 삭제 금지, 외부 식별자 유일
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at, approval_status, external_id) VALUES ('sso', 'S', 'user', 'active', 'oidc', NULL, ?, ?, 'approved', 'sub-1')", [$t, $t]);
        $bad = [
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('x1', 'X', 'user', 'active', 'kerberos', NULL, '{$t}', '{$t}')",
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('x2', 'X', 'user', 'active', 'saml', 'h', '{$t}', '{$t}')",
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at, approval_status) VALUES ('x3', 'X', 'user', 'active', 'ldap', NULL, '{$t}', '{$t}', 'pending')",
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at, approval_status) VALUES ('x4', 'X', 'user', 'inactive', 'local', 'h', '{$t}', '{$t}', 'pending')",
            "INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at, approval_status, external_id) VALUES ('x5', 'X', 'user', 'active', 'oidc', NULL, '{$t}', '{$t}', 'approved', 'sub-1')",
            'DELETE FROM users WHERE id = 3',
        ];
        foreach ($bad as $sql) {
            $this->assertThrows(\PDOException::class, fn () => $db->execute($sql)); // 제약 위반
        }
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at, approval_status) VALUES ('wait', 'W', 'user', 'inactive', 'ldap', NULL, ?, ?, 'pending')", [$t, $t]);
        $this->assertSame(1, $db->execute("DELETE FROM users WHERE login_id = 'wait'"), '승인된 적 없는 계정은 지울 수 있음');
        $this->assertSame([], (new Migrator($db, "{$dir}/m", new FixedClock('2026-10-11T00:00:00Z')))->migrate(), '재실행 안전');
    }

    public function testEmptyDatabaseAndMarkerOnlyWithForeignKeysOff(): void
    {
        [$db] = $this->dbAt('026');
        $this->assertSame('026', $db->value("SELECT max(version) FROM schema_migrations"));
        $this->assertSame([], $db->all('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) $db->value('PRAGMA foreign_keys'));
        $cols = array_column($db->all('PRAGMA table_info(login_failures)'), 'name');
        $this->assertTrue(in_array('login_key', $cols, true));
        $this->assertTrue(str_starts_with((string) file_get_contents(PJG_ROOT . '/migrations/026_external_auth.sql'), Migrator::FK_OFF_MARKER), '표지는 첫 줄');
    }

    /** @return array<string, int> */
    private function counts(Db $db): array
    {
        $out = [];
        foreach (['users', 'notifications', 'notification_prefs', 'sessions', 'login_failures'] as $t) {
            $out[$t] = (int) $db->value("SELECT COUNT(*) FROM {$t}");
        }
        return $out;
    }
}
