<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\Db;
use PJGuardian\Tests\TestCase;

/** SQLite 최소 버전 점검(P08 후속, 명세 19.10: JSON 함수에 3.38 이상 필요) */
final class DbVersionTest extends TestCase
{
    public function testVersionComparison(): void
    {
        $this->assertTrue(Db::versionAtLeast('3.38.0', '3.38.0'), '같은 버전');
        $this->assertTrue(Db::versionAtLeast('3.39.2', '3.38.0'));
        $this->assertTrue(Db::versionAtLeast('3.40', '3.38.0'), '패치 번호 생략');
        $this->assertTrue(Db::versionAtLeast('4.0.0', '3.38.0'));
        $this->assertFalse(Db::versionAtLeast('3.37.2', '3.38.0'));
        $this->assertFalse(Db::versionAtLeast('3.9.0', '3.38.0'), '문자열 비교가 아니라 숫자 비교(3.9 < 3.38)');
        $this->assertFalse(Db::versionAtLeast('2.99.99', '3.38.0'));
    }

    public function testCurrentRuntimeSatisfiesMinimum(): void
    {
        $db = new Db(':memory:');
        $this->assertTrue(Db::versionAtLeast($db->sqliteVersion(), Db::MIN_SQLITE_VERSION), '실행 중인 SQLite ' . $db->sqliteVersion());
    }

    public function testRejectsOldVersionWithClearMessage(): void
    {
        $e = $this->assertThrows(\RuntimeException::class, static fn () => Db::assertSqliteVersion('3.37.2'));
        $this->assertContains('3.37.2', $e->getMessage());
        $this->assertContains('3.38.0', $e->getMessage());
        $this->assertContains('JSON', $e->getMessage());
    }
}
