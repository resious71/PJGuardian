<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\Db;
use PJGuardian\Tests\TestCase;

/** P20: 연결을 열 때 journal_mode 문이 SQLITE_BUSY를 내면 busy_timeout 안에서 다시 한다(동시 읽기 + 큰 쓰기 겹침에서 발견) */
final class DbBusyRetryTest extends TestCase
{
    private static function busy(): \PDOException
    {
        $e = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        $e->errorInfo = ['HY000', 5, 'database is locked'];
        return $e;
    }

    public function testRetriesOnlyBusyWithinTimeout(): void
    {
        $n = 0;
        $this->assertSame('wal', Db::retryOnBusy(function () use (&$n): string {
            if (++$n < 3) {
                throw self::busy();
            }
            return 'wal';
        }, 1000));
        $this->assertSame(3, $n, '잠김 두 번 뒤 성공');

        $n = 0;
        $threw = null;
        try {
            Db::retryOnBusy(function () use (&$n): void {
                $n++;
                throw new \PDOException('no such table: x');
            }, 1000);
        } catch (\PDOException $e) {
            $threw = $e->getMessage();
        }
        $this->assertSame([1, 'no such table: x'], [$n, $threw], '다른 오류는 다시 하지 않음');

        $t = microtime(true);
        $threw = false;
        try {
            Db::retryOnBusy(static fn () => throw self::busy(), 100);
        } catch (\PDOException) {
            $threw = true;
        }
        $this->assertTrue($threw && microtime(true) - $t < 1.0, '제한 시간이 지나면 던짐');
    }

    public function testOpeningWalDatabaseWhileWriterHoldsLock(): void
    {
        $file = $this->tempDir('busy') . '/b.sqlite';
        $writer = new Db($file);
        $writer->execute('CREATE TABLE t (x INTEGER)');
        $writer->pdo()->exec('BEGIN IMMEDIATE');
        $writer->execute('INSERT INTO t VALUES (1)');
        $reader = new Db($file, 200); // 쓰기 잠금이 있어도 이미 WAL이면 연결은 열린다(읽기는 WAL 스냅숏)
        $this->assertSame(0, (int) $reader->value('SELECT COUNT(*) FROM t'));
        $writer->pdo()->exec('COMMIT');
        $this->assertSame(1, (int) $reader->value('SELECT COUNT(*) FROM t'));
    }
}
