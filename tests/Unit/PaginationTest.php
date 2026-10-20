<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Response;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

final class PaginationTest extends TestCase
{
    public function testDefaultsAndOffset(): void
    {
        $p = Pagination::fromRequest(TestApp::request('GET', '/api/v1/x'), 50, 200);
        $this->assertSame([1, 50, 0], [$p->page, $p->size, $p->offset()]);
        $p = Pagination::fromRequest(TestApp::request('GET', '/api/v1/x', ['page' => '3', 'size' => '20']), 50, 200);
        $this->assertSame([3, 20, 40], [$p->page, $p->size, $p->offset()]);
    }

    public function testRejectsInvalidValues(): void
    {
        foreach ([['page' => '0'], ['size' => '0'], ['size' => '201'], ['page' => '-1'], ['page' => 'abc'], ['size' => '1e3'], ['page' => ['1']]] as $q) {
            $e = $this->assertThrows(ApiException::class, static fn () => Pagination::fromRequest(TestApp::request('GET', '/api/v1/x', $q), 50, 200));
            $this->assertSame(400, $e->status);
            $this->assertSame('invalid_pagination', $e->errorCode);
        }
    }

    public function testPageResponseShape(): void
    {
        $p = Pagination::fromRequest(TestApp::request('GET', '/api/v1/x', ['page' => '2', 'size' => '2']), 50, 200);
        $body = Response::page([['id' => 3], ['id' => 4]], $p, 123)->decoded();
        $this->assertSame(['data' => [['id' => 3], ['id' => 4]], 'page' => ['page' => 2, 'size' => 2, 'total' => 123]], $body);
    }
}
