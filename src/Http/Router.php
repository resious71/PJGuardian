<?php

declare(strict_types=1);

namespace PJGuardian\Http;

/**
 * 메서드별 라우팅, 경로 파라미터, 라우트별 미들웨어.
 *
 * 경로 패턴: /api/v1/projects/{id}  또는 제약 /api/v1/projects/{id:\d+}
 *   - 파라미터 이름은 OpenAPI 경로 표기({id})와 같게 쓴다(OpenApiTest가 비교).
 * 핸들러: callable(Request): Response
 * 일치하는 경로가 없으면 404, 경로는 있으나 메서드가 다르면 405(Allow 헤더).
 * HEAD는 GET 라우트로 처리한다.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, handler:callable, middleware:list<Middleware>}> */
    private array $routes = [];

    /** @param list<Middleware> $middleware */
    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function put(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function patch(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function delete(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $method = strtoupper($method);
        foreach ($this->routes as $r) {
            if ($r['method'] === $method && $r['pattern'] === $pattern) {
                throw new \LogicException("중복 라우트: {$method} {$pattern}");
            }
        }
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => self::compile($pattern),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** 고정 부분은 정규식 이스케이프하고 {name} / {name:제약}만 이름 있는 그룹으로 바꾼다. */
    private static function compile(string $pattern): string
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/', $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $regex = '';
        $pos = 0;
        foreach ($matches as $m) {
            $regex .= preg_quote(substr($pattern, $pos, $m[0][1] - $pos), '#');
            $constraint = isset($m[2]) && $m[2][1] >= 0 ? $m[2][0] : '[^/]+';
            $regex .= '(?P<' . $m[1][0] . '>' . $constraint . ')';
            $pos = $m[0][1] + strlen($m[0][0]);
        }
        $regex .= preg_quote(substr($pattern, $pos), '#');
        return '#^' . $regex . '$#';
    }

    /**
     * 등록된 라우트 목록(OpenAPI 일치 검사용). 패턴의 제약({id:\d+})은 OpenAPI 표기({id})로 바꿔 돌려준다.
     *
     * @return list<array{method:string, path:string}>
     */
    public function routes(): array
    {
        return array_map(static fn (array $r): array => [
            'method' => $r['method'],
            'path' => (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^}]+\}/', '{$1}', $r['pattern']),
        ], $this->routes);
    }

    /**
     * 라우트와 라우트별 미들웨어(프로젝트 접근 판정 선언 검사용, ProjectRouteAccessTest). 경로 표기는 routes()와 같다.
     *
     * @return list<array{method:string, path:string, middleware:list<Middleware>}>
     */
    public function routeTable(): array
    {
        $out = [];
        foreach ($this->routes() as $i => $r) {
            $out[] = $r + ['middleware' => $this->routes[$i]['middleware']];
        }
        return $out;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $request->path, $m) !== 1) {
                continue;
            }
            if ($r['method'] !== $method) {
                $allowed[] = $r['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $req = $request->withAttribute('route_params', $params);
            $handler = $r['handler'];
            $pipeline = array_reduce(
                array_reverse($r['middleware']),
                static fn (callable $next, Middleware $mw): callable => static fn (Request $q): Response => $mw->process($q, $next),
                static fn (Request $q): Response => $handler($q),
            );
            return $pipeline($req);
        }
        if ($allowed !== []) {
            $allow = implode(', ', array_unique(array_merge($allowed, in_array('GET', $allowed, true) ? ['HEAD'] : [])));
            throw new ApiException(405, 'method_not_allowed', headers: ['Allow' => $allow]);
        }
        throw new ApiException(404, 'not_found');
    }
}
