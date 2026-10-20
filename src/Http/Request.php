<?php

declare(strict_types=1);

namespace PJGuardian\Http;

/**
 * HTTP 요청. 경로는 앱 기준 경로(배포 위치의 접두사를 뺀 값, 항상 '/'로 시작)다.
 */
final class Request
{
    /** @var array<string, mixed> 라우터·미들웨어가 붙이는 값(route_params, request_id 등) */
    private array $attributes = [];

    private ?array $jsonCache = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers 소문자 이름
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly array $headers = [],
        private readonly string $body = '',
        public readonly bool $secure = false,
        public readonly string $clientIp = '',
        public readonly string $basePath = '',
    ) {
    }

    /**
     * SCRIPT_NAME에서 앱이 놓인 웹 경로 접두사를 구한다('/index.php' → '', '/JGuardian/public/index.php' → '/JGuardian/public').
     * Windows의 dirname('/index.php')는 '\'를 돌려주므로 결과도 '/'로 바꿔야 한다
     * (그대로 두면 자산 주소가 '\/assets/…'가 되고 브라우저는 '//assets/…', 즉 다른 호스트로 해석한다).
     */
    public static function basePathFromScript(string $scriptName): string
    {
        $script = str_replace('\\', '/', $scriptName);
        if ($script === '') {
            return '';
        }
        $dir = str_replace('\\', '/', dirname($script));
        return $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $name) {
            if (isset($_SERVER[$k])) {
                $headers[$name] = (string) $_SERVER[$k];
            }
        }
        // 앱이 놓인 위치(예: /JGuardian/public)를 뺀 경로로 라우팅한다
        $base = self::basePathFromScript((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode($uriPath);
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        $path = '/' . ltrim($path, '/');

        $https = $_SERVER['HTTPS'] ?? '';
        $secure = ($https !== '' && strtolower((string) $https) !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            '',
            $secure,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $base,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        // 웹 요청은 필요할 때 php://input을 읽는다(큰 본문을 미리 읽지 않음)
        return $this->body !== '' ? $this->body : (PHP_SAPI === 'cli' ? '' : (string) file_get_contents('php://input'));
    }

    /**
     * 본문을 스트림으로(청크 업로드처럼 큰 원시 본문을 메모리에 올리지 않고 조금씩 읽을 때, P12).
     * 웹 요청은 php://input, 시험(CLI)은 생성자로 받은 본문을 메모리 스트림으로 돌려준다.
     *
     * @return resource
     */
    public function bodyStream()
    {
        if ($this->body !== '' || PHP_SAPI === 'cli') {
            $s = fopen('php://memory', 'w+b');
            fwrite($s, $this->body);
            rewind($s);
            return $s;
        }
        return fopen('php://input', 'rb');
    }

    /**
     * JSON 본문. 형식이 틀리면 400 invalid_json.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonCache === null) {
            $raw = $this->body();
            try {
                $data = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiException(400, 'invalid_json');
            }
            if (!is_array($data)) {
                throw new ApiException(400, 'invalid_json');
            }
            $this->jsonCache = $data;
        }
        return $this->jsonCache;
    }

    /** Cookie 헤더의 값(없으면 null) */
    public function cookie(string $name): ?string
    {
        foreach (explode(';', (string) $this->header('cookie')) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && $parts[0] === $name) {
                return $parts[1];
            }
        }
        return null;
    }

    /**
     * HTML 폼 본문(application/x-www-form-urlencoded). 다른 형식이면 빈 배열.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        if (!str_starts_with(strtolower((string) $this->header('content-type')), 'application/x-www-form-urlencoded')) {
            return [];
        }
        parse_str($this->body(), $data);
        return $data;
    }

    /** 폼 필드 문자열(배열이 오면 빈 문자열) */
    public function formString(string $name): string
    {
        $v = $this->form()[$name] ?? '';
        return is_string($v) ? $v : '';
    }

    /** 경로 + 쿼리 문자열(로그인 후 돌아올 주소) */
    public function pathWithQuery(): string
    {
        return $this->path . ($this->query === [] ? '' : '?' . http_build_query($this->query));
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    /** 접속 정보만 바꾼 사본(신뢰 프록시 해석 — Http\ForwardedHeaders만 쓴다) */
    public function withConnection(string $clientIp, bool $secure): self
    {
        $c = new self($this->method, $this->path, $this->query, $this->headers, $this->body, $secure, $clientIp, $this->basePath);
        $c->attributes = $this->attributes;
        $c->jsonCache = $this->jsonCache;
        return $c;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /** 라우트 경로 파라미터 ({id} 등) */
    public function param(string $name): ?string
    {
        $params = $this->attributes['route_params'] ?? [];
        return isset($params[$name]) ? (string) $params[$name] : null;
    }
}
