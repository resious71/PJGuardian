<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\Router;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testRoutesByMethodAndExtractsParams(): void
    {
        $r = new Router();
        $r->get('/api/v1/projects/{id:\d+}', static fn (Request $q) => Response::data(['get' => $q->param('id')]));
        $r->put('/api/v1/projects/{id:\d+}', static fn (Request $q) => Response::data(['put' => $q->param('id')]));
        $r->get('/api/v1/projects/{id}/files/{name}', static fn (Request $q) => Response::data([$q->param('id'), $q->param('name')]));

        $this->assertSame(['get' => '12'], $r->dispatch(TestApp::request('GET', '/api/v1/projects/12'))->decoded()['data']);
        $this->assertSame(['put' => '12'], $r->dispatch(TestApp::request('PUT', '/api/v1/projects/12'))->decoded()['data']);
        $this->assertSame(['7', 'a.py'], $r->dispatch(TestApp::request('GET', '/api/v1/projects/7/files/a.py'))->decoded()['data']);
        $this->assertSame(['get' => '5'], $r->dispatch(TestApp::request('HEAD', '/api/v1/projects/5'))->decoded()['data'], 'HEAD는 GET 라우트');
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $r = new Router();
        $r->get('/api/v1/projects/{id:\d+}', static fn () => Response::data(1));
        $e = $this->assertThrows(ApiException::class, static fn () => $r->dispatch(TestApp::request('GET', '/api/v1/projects/abc')));
        $this->assertSame(404, $e->status, '제약({id:\d+})에 맞지 않으면 404');
        $e = $this->assertThrows(ApiException::class, static fn () => $r->dispatch(TestApp::request('DELETE', '/api/v1/projects/1')));
        $this->assertSame(405, $e->status);
        $this->assertSame('GET, HEAD', $e->headers['Allow']);
    }

    public function testLiteralPartsAreEscaped(): void
    {
        $r = new Router();
        $r->get('/api/v1/meta.version', static fn () => Response::data('dot'));
        $this->assertThrows(ApiException::class, static fn () => $r->dispatch(TestApp::request('GET', '/api/v1/metaXversion')));
        $this->assertSame('dot', $r->dispatch(TestApp::request('GET', '/api/v1/meta.version'))->decoded()['data']);
    }

    public function testMiddlewareChainOrder(): void
    {
        $trace = new \ArrayObject();
        $mw = static fn (string $name): Middleware => new class ($name, $trace) implements Middleware {
            public function __construct(private string $name, private \ArrayObject $trace)
            {
            }

            public function process(Request $request, callable $next): Response
            {
                $this->trace[] = "{$this->name}:before";
                $res = $next($request);
                $this->trace[] = "{$this->name}:after";
                return $res;
            }
        };
        $r = new Router();
        $r->get('/x', static function () use ($trace): Response {
            $trace[] = 'handler';
            return Response::data(true);
        }, [$mw('a'), $mw('b')]);
        $r->dispatch(TestApp::request('GET', '/x'));
        $this->assertSame(['a:before', 'b:before', 'handler', 'b:after', 'a:after'], $trace->getArrayCopy());
    }

    public function testDuplicateRouteIsRejected(): void
    {
        $r = new Router();
        $r->get('/a', static fn () => Response::data(1));
        $this->assertThrows(\LogicException::class, static fn () => $r->get('/a', static fn () => Response::data(2)));
    }

    public function testRoutesListUsesOpenApiNotation(): void
    {
        $r = new Router();
        $r->get('/api/v1/projects/{id:\d+}', static fn () => Response::data(1));
        $this->assertSame([['method' => 'GET', 'path' => '/api/v1/projects/{id}']], $r->routes());
    }
}
