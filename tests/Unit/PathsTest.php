<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

final class PathsTest extends TestCase
{
    public function testResolvesRelativeAndAbsolutePaths(): void
    {
        $root = str_replace('\\', '/', PJG_ROOT);
        $p = new Paths($root, TestApp::config());
        $this->assertSame($root . '/data/logs', $p->logs());
        $this->assertSame($root . '/data/db/pjguardian.sqlite', $p->dbFile());
        $abs = new Paths($root, TestApp::config(['paths' => ['backups_dir' => 'E:/backup/pjg/']]));
        $this->assertSame('E:/backup/pjg', $abs->backups());
    }

    public function testNewWorkDirIsShort(): void
    {
        $tmp = $this->tempDir('paths');
        $p = new Paths(PJG_ROOT, TestApp::config(['paths' => ['work_dir' => $tmp]]));
        $dir = $p->newWorkDir();
        $this->assertTrue(is_dir($dir));
        $this->assertSame(1, preg_match('#/[0-9a-f]{8}$#', $dir), '작업 디렉토리 이름은 8자리 16진수(명세 19.10)');
        $this->assertTrue($dir !== $p->newWorkDir(), '매번 다른 디렉토리');
    }
}
