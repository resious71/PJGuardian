<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\SchemaFeatures;
use PJGuardian\Tests\TestCase;

/**
 * 마이그레이션 028(P28D): 알림 종류 saml_cert_expiring을 위해 notifications·notification_prefs 재구성. 027 DB의 행·id·AUTOINCREMENT 순번·인덱스 5개 그대로,
 * 새 종류를 넣을 수 있고 모르는 종류는 여전히 거부, 외래 키 위반 0, 재실행 안전, 적용 여부 판정(SchemaFeatures::samlCertNotice).
 */
final class SamlNotificationMigrationTest extends TestCase
{
    public function testAddsKindAndKeepsRows(): void
    {
        $dir = $this->tempDir('mig028');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/*.sql') ?: [] as $f) {
            if (strcmp(substr(basename($f), 0, 3), '027') <= 0) {
                copy($f, "{$dir}/m/" . basename($f));
            }
        }
        $clock = new FixedClock('2026-10-11T00:00:00Z');
        $db = new Db("{$dir}/t.sqlite", 2000);
        (new Migrator($db, "{$dir}/m", $clock))->migrate();
        $t = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('admin', 'Admin', 'admin', 'active', 'local', 'x', ?, ?)", [$t, $t]);
        $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (1, 'backup_done', '{}', 'k1', ?), (1, 'account_pending', '{}', 'k2', ?)", [$t, $t]);
        $db->execute('DELETE FROM notifications WHERE id = 2');
        $db->execute("INSERT INTO notification_prefs (user_id, kind, enabled, updated_at) VALUES (1, 'backup_done', 0, ?)", [$t]);
        $seq = (int) $db->value("SELECT seq FROM sqlite_sequence WHERE name = 'notifications'");
        $this->assertFalse(SchemaFeatures::samlCertNotice($db));
        $this->assertThrows(\PDOException::class, fn () => $db->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (1, 'saml_cert_expiring', '{}', 'k3', ?)", [$t]));

        copy(PJG_ROOT . '/migrations/028_saml_cert_notifications.sql', "{$dir}/m/028_saml_cert_notifications.sql");
        $db2 = new Db("{$dir}/t.sqlite", 2000);
        $this->assertSame(['028'], (new Migrator($db2, "{$dir}/m", $clock))->migrate());
        $this->assertSame([], (new Migrator($db2, "{$dir}/m", $clock))->migrate(), '재실행 안전');
        $this->assertTrue(SchemaFeatures::samlCertNotice($db2));
        $this->assertSame([[1, 'backup_done']], array_map(static fn (array $r): array => [(int) $r['id'], $r['kind']], $db2->all('SELECT id, kind FROM notifications')));
        $this->assertSame($seq, (int) $db2->value("SELECT seq FROM sqlite_sequence WHERE name = 'notifications'"), '순번 보존(지운 id 2를 다시 쓰지 않음)');
        $this->assertSame(1, (int) $db2->value('SELECT COUNT(*) FROM notification_prefs'));
        $db2->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (1, 'saml_cert_expiring', '{}', 'k3', ?)", [$t]);
        $this->assertSame(3, (int) $db2->value("SELECT id FROM notifications WHERE kind = 'saml_cert_expiring'"));
        $db2->execute("INSERT INTO notification_prefs (user_id, kind, enabled, updated_at) VALUES (1, 'saml_cert_expiring', 1, ?)", [$t]);
        $this->assertThrows(\PDOException::class, fn () => $db2->execute("INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at) VALUES (1, 'nope', '{}', 'k4', ?)", [$t]));
        $this->assertSame(5, (int) $db2->value("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = 'notifications' AND name NOT LIKE 'sqlite_%'"));
        $this->assertSame([], $db2->all('PRAGMA foreign_key_check'));
        $this->assertSame('ok', $db2->value('PRAGMA integrity_check'));
        $this->assertSame(0, (int) $db2->value("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%_new'"));
        // 사용자를 지우면 알림·설정이 함께 지워지는 외래 키(ON DELETE CASCADE)가 그대로
        $this->assertContains('ON DELETE CASCADE', (string) $db2->value("SELECT sql FROM sqlite_master WHERE name = 'notifications'"));
    }
}
