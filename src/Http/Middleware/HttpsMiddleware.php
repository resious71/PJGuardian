<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\ApiException;
use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * HTTPS 강제(명세 10: Apache 리다이렉트에 더한 앱의 이중 검사).
 * - GET/HEAD: 설정 app.base_url의 HTTPS 주소로 301. Host 헤더는 믿지 않는다(열린 리다이렉트 방지).
 * - 그 밖의 메서드: 본문이 사라지므로 리다이렉트하지 않고 403 https_required.
 * - security.require_https = false이면 통과(개발용).
 */
final class HttpsMiddleware implements Middleware
{
    public function __construct(private readonly bool $required, private readonly string $baseUrl)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->required || $request->secure) {
            return $next($request);
        }
        if (in_array($request->method, ['GET', 'HEAD'], true)) {
            $query = $request->query !== [] ? '?' . http_build_query($request->query) : '';
            $target = (string) preg_replace('#^http://#', 'https://', $this->baseUrl);
            return Response::redirect($target . $request->path . $query, 301);
        }
        throw new ApiException(403, 'https_required');
    }
}
