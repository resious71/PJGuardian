<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\TestCase;

final class FsTest extends TestCase
{
    public function testListDirIncludesDotFiles(): void
    {
        $dir = $this->tempDir('fs');
        touch("$dir/.lock");
        touch("$dir/a.txt");
        mkdir("$dir/.hidden");
        $names = Fs::listDir($dir);
        sort($names);
        $this->assertSame(['.hidden', '.lock', 'a.txt'], $names, 'glob과 달리 점 파일도 나와야 함');
        $this->assertSame([], Fs::listDir("$dir/none"));
    }

    public function testRemoveTreeDeletesDotFilesAndNestedDirs(): void
    {
        $dir = $this->tempDir('fs');
        mkdir("$dir/x/y/.z", 0700, true);
        file_put_contents("$dir/x/.lock", '');
        file_put_contents("$dir/x/y/.z/f", 'data');
        $this->assertTrue(Fs::removeTree("$dir/x"));
        $this->assertFalse(file_exists("$dir/x"));
        $this->assertTrue(Fs::removeTree("$dir/never-existed"));
    }

    public function testAtomicWriteReplacesWithoutLeftovers(): void
    {
        $dir = $this->tempDir('fs');
        Fs::atomicWrite("$dir/meta.json", '{"v":1}');
        Fs::atomicWrite("$dir/meta.json", '{"v":2}');
        $this->assertSame('{"v":2}', file_get_contents("$dir/meta.json"));
        $this->assertSame(['meta.json'], Fs::listDir($dir), '임시 파일이 남으면 안 됨');
    }

    public function testFileLockAllowsReadingAndReplacingProtectedFile(): void
    {
        // Windows flock은 강제 잠금 → 데이터 파일이 아닌 .lock만 잠가야 같은 요청에서 읽고 교체할 수 있다(명세 19.10)
        $dir = $this->tempDir('lock');
        Fs::atomicWrite("$dir/meta.json", 'old');
        $lock = FileLock::acquire("$dir/.lock");
        try {
            $this->assertSame('old', file_get_contents("$dir/meta.json"));
            Fs::atomicWrite("$dir/meta.json", 'new');
            $this->assertSame('new', file_get_contents("$dir/meta.json"));
            $this->assertSame(null, FileLock::tryAcquire("$dir/.lock"), '이미 잡힌 잠금은 다시 잡을 수 없음');
        } finally {
            $lock->release();
        }
        $again = FileLock::tryAcquire("$dir/.lock");
        $this->assertTrue($again !== null, '해제 후에는 다시 잡을 수 있음');
        $again->release();
        // 잠금 파일이 있어도 디렉토리 통째 삭제 가능(점 파일 포함)
        $this->assertTrue(Fs::removeTree($dir));
    }

    public function testFileLockRefusesDataFiles(): void
    {
        $dir = $this->tempDir('lock');
        $this->assertThrows(\InvalidArgumentException::class, static fn () => FileLock::acquire("$dir/meta.json"), '.lock');
    }
}
