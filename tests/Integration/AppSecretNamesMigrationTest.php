<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\SchemaFeatures;
use PJGuardian\Tests\TestCase;

/**
 * 마이그레이션 027(P28C): app_secrets 이름 제약을 목록(ldap.bind_password만)에서 형식 검사로. 026 DB의 LDAP 비밀 행 그대로(암호문·작성자),
 * oidc.client_secret을 넣을 수 있고 형식이 틀린 이름은 거부, 외래 키(작성자 ON DELETE SET NULL) 그대로, 재실행 안전, 적용 여부 판정(SchemaFeatures).
 */
final class AppSecretNamesMigrationTest extends TestCase
{
    public function testWidensNameCheckAndKeepsRows(): void
    {
        $dir = $this->tempDir('mig027');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/*.sql') ?: [] as $f) {
            if (strcmp(substr(basename($f), 0, 3), '026') <= 0) {
                copy($f, "{$dir}/m/" . basename($f));
            }
        }
        $db = new Db("{$dir}/t.sqlite", 2000);
        $clock = new FixedClock('2026-10-11T00:00:00Z');
        (new Migrator($db, "{$dir}/m", $clock))->migrate();
        $t = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('admin', 'Admin', 'admin', 'active', 'local', 'x', ?, ?)", [$t, $t]);
        $db->execute("INSERT INTO app_secrets (name, cipher, nonce, key_id, updated_at, updated_by) VALUES ('ldap.bind_password', 'CIPHER', 'NONCE', '0123456789abcdef', ?, 1)", [$t]);
        $this->assertFalse(SchemaFeatures::appSecretNames($db), '026만 적용 = 027 전');
        $this->assertThrows(\PDOException::class, fn () => $db->execute("INSERT INTO app_secrets (name, cipher, nonce, key_id, updated_at) VALUES ('oidc.client_secret', 'c', 'n', '0123456789abcdef', ?)", [$t]));

        copy(PJG_ROOT . '/migrations/027_app_secret_names.sql', "{$dir}/m/027_app_secret_names.sql");
        $db2 = new Db("{$dir}/t.sqlite", 2000); // 판정 캐시는 연결마다 — 새 연결로 확인
        $this->assertSame(['027'], (new Migrator($db2, "{$dir}/m", $clock))->migrate());
        $this->assertSame([], (new Migrator($db2, "{$dir}/m", $clock))->migrate(), '재실행 안전');
        $this->assertTrue(SchemaFeatures::appSecretNames($db2) && SchemaFeatures::oidc($db2));
        $this->assertSame(['ldap.bind_password', 'CIPHER', 'NONCE', '0123456789abcdef', $t, 1], array_values($db2->one('SELECT * FROM app_secrets')), 'LDAP 비밀 행 그대로');
        $db2->execute("INSERT INTO app_secrets (name, cipher, nonce, key_id, updated_at) VALUES ('oidc.client_secret', 'c', 'n', '0123456789abcdef', ?)", [$t]);
        foreach (['Oidc.secret', 'nodot', 'a.', '.x', 'oidc.client-secret', 'oidc.client secret', str_repeat('a', 60) . '.long'] as $bad) {
            $this->assertThrows(\PDOException::class, fn () => $db2->execute("INSERT INTO app_secrets (name, cipher, nonce, key_id, updated_at) VALUES (?, 'c', 'n', '0123456789abcdef', ?)", [$bad, $t]));
        }
        $this->assertSame([], $db2->all('PRAGMA foreign_key_check'));
        $this->assertSame('ok', $db2->value('PRAGMA integrity_check'));
        $this->assertSame(0, (int) $db2->value("SELECT COUNT(*) FROM sqlite_master WHERE name = 'app_secrets_new'"));
        $this->assertContains('REFERENCES users (id) ON DELETE SET NULL', (string) $db2->value("SELECT sql FROM sqlite_master WHERE name = 'app_secrets'"));
    }
}
