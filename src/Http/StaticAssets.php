<?php

declare(strict_types=1);

namespace PJGuardian\Http;

/**
 * 버전 경로의 정적 파일 응답(P21 0절): GET /assets/v/<해시>/<경로>.
 * - 해시가 지금 묶음 버전과 같으면 `Cache-Control: public, max-age=31536000, immutable`(주소가 내용과 함께 바뀌므로 안전).
 *   다르면(배포 전에 받은 HTML의 옛 주소) 지금 파일을 주되 `no-cache`로 오래 남지 않게 한다.
 * - 경로는 영문·숫자·`._-/`만, `..`·빈 조각 금지, realpath가 public/assets 안이어야 한다. 허용 확장자만(나머지·없는 파일은 404).
 * - 세션·CSRF를 거치지 않는다(App::handle에서 바로). 보안 헤더(nosniff, CSP 등)는 SecurityHeadersMiddleware가 붙인다.
 */
final class StaticAssets
{
    private const PREFIX = '/assets/v/';

    /** @var array<string, string> 확장자 → Content-Type */
    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'map' => 'application/json; charset=utf-8',
    ];

    public function __construct(private readonly string $assetsDir, private readonly \Closure $currentHash)
    {
    }

    public static function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public function serve(Request $request): Response
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return self::notFound();
        }
        $rest = substr($request->path, strlen(self::PREFIX));
        if (preg_match('#^([0-9a-f]{12})/([A-Za-z0-9._/-]+)$#', $rest, $m) !== 1) {
            return self::notFound();
        }
        [$hash, $rel] = [$m[1], $m[2]];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return self::notFound();
            }
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $root = realpath($this->assetsDir);
        $file = realpath($this->assetsDir . '/' . $rel);
        if (!isset(self::TYPES[$ext]) || $root === false || $file === false || !is_file($file)
            || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/')) {
            return self::notFound();
        }
        $current = $hash === ($this->currentHash)();
        $etag = '"' . substr((string) sha1_file($file), 0, 16) . '"';
        $headers = [
            'Content-Type' => self::TYPES[$ext],
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-cache',
            'ETag' => $etag,
        ];
        if (trim((string) $request->header('if-none-match')) === $etag) {
            return new Response(304, $headers, '');
        }
        return new Response(200, $headers, (string) file_get_contents($file));
    }

    private static function notFound(): Response
    {
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Not Found');
    }
}
