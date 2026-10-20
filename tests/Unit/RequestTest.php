<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Http\Request;
use PJGuardian\Tests\TestCase;

/** 배포 위치 접두사(basePath) 계산. Windows에서 dirname('/index.php')는 '\'를 돌려준다(P09에서 발견). */
final class RequestTest extends TestCase
{
    public function testBasePathFromScriptName(): void
    {
        $this->assertSame('', Request::basePathFromScript('/index.php'), 'vhost 루트 배포');
        $this->assertSame('', Request::basePathFromScript('\index.php'));
        $this->assertSame('/JGuardian/public', Request::basePathFromScript('/JGuardian/public/index.php'));
        $this->assertSame('/JGuardian/public', Request::basePathFromScript('\JGuardian\public\index.php'));
        $this->assertSame('', Request::basePathFromScript(''));
    }
}
