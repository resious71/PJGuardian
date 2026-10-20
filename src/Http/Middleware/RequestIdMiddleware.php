<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Logger;

/**
 * 요청마다 ID를 만들어 로거 문맥과 응답 헤더 X-Request-Id에 넣는다(명세 19.7).
 * 클라이언트가 보낸 X-Request-Id는 믿지 않는다(로그 위조 방지).
 */
final class RequestIdMiddleware implements Middleware
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function process(Request $request, callable $next): Response
    {
        $id = self::newId();
        $this->logger->withRequestId($id);
        $response = $next($request->withAttribute('request_id', $id));
        return $response->withHeader('X-Request-Id', $id);
    }
}
