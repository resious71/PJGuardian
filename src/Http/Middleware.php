<?php

declare(strict_types=1);

namespace PJGuardian\Http;

/**
 * 미들웨어: 요청을 받아 다음 단계($next)를 부르거나 직접 응답한다.
 */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response;
}
