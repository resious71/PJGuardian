<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\MigrationException;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

final class MigratorTest extends TestCase
{
    private function tables(Db $db): array
    {
        return array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name');
    }

    public function testAppliesRealMigrationsToEmptyDbAndIsIdempotent(): void
    {
        $dir = $this->tempDir('mig');
        $db = new Db("$dir/a.sqlite");
        $m = new Migrator($db, PJG_ROOT . '/migrations', new FixedClock());
        $first = $m->migrate();
        $this->assertTrue(in_array('001', $first, true));
        $schemaBefore = $db->all('SELECT type, name, sql FROM sqlite_master ORDER BY name');
        $this->assertSame([], $m->migrate(), '두 번째 실행은 적용할 것이 없음');
        $this->assertSame($schemaBefore, $db->all('SELECT type, name, sql FROM sqlite_master ORDER BY name'), '다시 실행해도 스키마 변화 없음');
        $this->assertSame(['applied'], array_values(array_unique(array_column($m->status(), 'state'))));
        $this->assertSame('wal', strtolower((string) $db->value('PRAGMA journal_mode')));
        $this->assertSame(1, (int) $db->value('PRAGMA foreign_keys'));
        $this->assertSame(5000, (int) $db->value('PRAGMA busy_timeout'), '기본 busy_timeout 5000ms');
    }

    public function testFailingMigrationIsRolledBackAndStopsLaterOnes(): void
    {
        $dir = $this->tempDir('mig');
        mkdir("$dir/m");
        file_put_contents("$dir/m/001_a.sql", "CREATE TABLE a (x INTEGER);\n");
        file_put_contents("$dir/m/002_b.sql", "CREATE TABLE b (x INTEGER);\nINSERT INTO b VALUES (1);\nINSERT INTO no_such_table VALUES (1);\n");
        file_put_contents("$dir/m/003_c.sql", "CREATE TABLE c (x INTEGER);\n");
        $db = new Db("$dir/f.sqlite");
        $m = new Migrator($db, "$dir/m", new FixedClock());
        /** @var MigrationException $e */
        $e = $this->assertThrows(MigrationException::class, static fn () => $m->migrate(), '002_b 실패');
        $this->assertSame('002', $e->version);
        $this->assertSame(['a', 'schema_migrations'], $this->tables($db), '002의 b 테이블은 롤백, 003은 적용하지 않음');
        $this->assertSame(['001'], array_column($db->all('SELECT version FROM schema_migrations'), 'version'));
        // 적용 전인 002는 고쳐도 된다 → 고친 뒤 다시 실행하면 002, 003 적용
        file_put_contents("$dir/m/002_b.sql", "CREATE TABLE b (x INTEGER);\nINSERT INTO b VALUES (1);\n");
        $this->assertSame(['002', '003'], $m->migrate());
        $this->assertSame(['a', 'b', 'c', 'schema_migrations'], $this->tables($db));
    }

    public function testModifiedAppliedMigrationIsRefused(): void
    {
        $dir = $this->tempDir('mig');
        mkdir("$dir/m");
        file_put_contents("$dir/m/001_a.sql", "CREATE TABLE a (x INTEGER);\n");
        $db = new Db("$dir/f.sqlite");
        $m = new Migrator($db, "$dir/m", new FixedClock());
        $m->migrate();
        file_put_contents("$dir/m/001_a.sql", "CREATE TABLE a (x INTEGER, y TEXT);\n");
        file_put_contents("$dir/m/002_b.sql", "CREATE TABLE b (x INTEGER);\n");
        $this->assertSame('modified', $m->status()[0]['state']);
        $this->assertThrows(MigrationException::class, static fn () => $m->migrate(), '001(modified)');
        $this->assertSame(['a', 'schema_migrations'], $this->tables($db), '바뀐 파일이 있으면 아무것도 적용하지 않음');
    }

    public function testBackupHookRunsOnceBeforeApplyingAndCanAbort(): void
    {
        $dir = $this->tempDir('mig');
        mkdir("$dir/m");
        file_put_contents("$dir/m/001_a.sql", "CREATE TABLE a (x INTEGER);\n");
        file_put_contents("$dir/m/002_b.sql", "CREATE TABLE b (x INTEGER);\n");
        $calls = [];
        $db = new Db("$dir/f.sqlite");
        $failing = new Migrator($db, "$dir/m", new FixedClock(), static function (array $pending) use (&$calls): void {
            $calls[] = array_column($pending, 'version');
            throw new \RuntimeException('백업 실패');
        });
        $this->assertThrows(\RuntimeException::class, static fn () => $failing->migrate(), '백업 실패');
        $this->assertSame(['schema_migrations'], $this->tables($db), '백업이 실패하면 적용하지 않음(명세 19.4)');
        $ok = new Migrator($db, "$dir/m", new FixedClock(), static function (array $pending) use (&$calls): void {
            $calls[] = array_column($pending, 'version');
        });
        $ok->migrate();
        $ok->migrate();
        $this->assertSame([['001', '002'], ['001', '002']], $calls, '적용할 것이 있을 때만, 한 번씩 호출');
    }

    public function testRejectsBadFileNamesDuplicatesAndPragma(): void
    {
        $dir = $this->tempDir('mig');
        mkdir("$dir/m1");
        file_put_contents("$dir/m1/01_short.sql", 'SELECT 1;');
        $db = new Db("$dir/f.sqlite");
        $this->assertThrows(MigrationException::class, static fn () => (new Migrator($db, "$dir/m1", new FixedClock()))->migrate(), '이름 규칙');
        mkdir("$dir/m2");
        file_put_contents("$dir/m2/001_a.sql", 'SELECT 1;');
        file_put_contents("$dir/m2/001_b.sql", 'SELECT 1;');
        $this->assertThrows(MigrationException::class, static fn () => (new Migrator($db, "$dir/m2", new FixedClock()))->migrate(), '같은 순번');
        mkdir("$dir/m3");
        file_put_contents("$dir/m3/001_a.sql", "PRAGMA foreign_keys = OFF;\nCREATE TABLE a (x);\n");
        $this->assertThrows(MigrationException::class, static fn () => (new Migrator($db, "$dir/m3", new FixedClock()))->migrate(), 'PRAGMA');
    }

    public function testTransactionHelperUsesSavepointsForNesting(): void
    {
        $db = TestDb::migrated();
        $pid = TestDb::project($db);
        try {
            $db->transaction(static function (Db $db) use ($pid): void {
                $db->execute("UPDATE projects SET description = 'outer' WHERE id = ?", [$pid]);
                try {
                    $db->transaction(static function (Db $db) use ($pid): void {
                        $db->execute("UPDATE projects SET description = 'inner' WHERE id = ?", [$pid]);
                        throw new \RuntimeException('inner fail');
                    });
                } catch (\RuntimeException) {
                }
                throw new \LogicException('outer fail');
            });
        } catch (\LogicException) {
        }
        $this->assertSame('', $db->value('SELECT description FROM projects WHERE id = ?', [$pid]), '바깥 롤백으로 모두 취소');
        $db->transaction(static function (Db $db) use ($pid): void {
            $db->execute("UPDATE projects SET description = 'kept' WHERE id = ?", [$pid]);
            try {
                $db->transaction(static fn (Db $db) => throw new \RuntimeException('inner only'));
            } catch (\RuntimeException) {
            }
        });
        $this->assertSame('kept', $db->value('SELECT description FROM projects WHERE id = ?', [$pid]), '안쪽 실패는 안쪽만 취소');
        $this->assertFalse($db->inTransaction());
    }
}
