<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 브라우저처럼 쿠키를 유지하며 앱에 요청을 보내는 시험용 클라이언트.
 * CSRF 토큰은 GET /api/v1/auth/csrf 또는 로그인 응답에서 받아 X-CSRF-Token 헤더로 보낸다.
 */
final class TestClient
{
    /** @var array<string, string> */
    public array $cookies = [];
    public ?string $csrf = null;
    public ?Response $last = null;

    public function __construct(public readonly App $app, public string $ip = '127.0.0.1')
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @param array<string, string>|null $form
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, ?array $form = null, array $headers = [], bool $withCsrf = true): Response
    {
        $body = '';
        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers['content-type'] = 'application/json';
        } elseif ($form !== null) {
            $body = http_build_query($form);
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }
        if ($withCsrf && $this->csrf !== null && !in_array($method, ['GET', 'HEAD'], true) && $form === null) {
            $headers['x-csrf-token'] = $this->csrf;
        }
        if ($this->cookies !== []) {
            $headers['cookie'] = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        }
        $headers['user-agent'] ??= 'TestClient/1.0';
        $req = new Request($method, $path, $query, array_change_key_case($headers), $body, true, $this->ip);
        $res = $this->app->handle($req);
        $this->absorbCookie($res);
        $this->last = $res;
        return $res;
    }

    /**
     * 원시 본문 요청(청크 업로드 PUT, P12 · 계정 CSV P28A는 $query에 옵션). CSRF 헤더는 $withCsrf일 때만.
     *
     * @param array<string, string> $headers
     */
    public function raw(string $method, string $path, string $body, array $headers = [], bool $withCsrf = true, array $query = []): Response
    {
        $headers['content-type'] ??= 'application/octet-stream';
        $headers['content-length'] ??= (string) strlen($body);
        if ($withCsrf && $this->csrf !== null) {
            $headers['x-csrf-token'] = $this->csrf;
        }
        if ($this->cookies !== []) {
            $headers['cookie'] = implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        }
        $headers['user-agent'] ??= 'TestClient/1.0';
        $req = new Request($method, $path, $query, array_change_key_case($headers), $body, true, $this->ip);
        $res = $this->app->handle($req);
        $this->absorbCookie($res);
        $this->last = $res;
        return $res;
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query);
    }

    /** @param array<string, mixed> $data */
    public function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        return $this->request('POST', $path, [], $data, null, [], $withCsrf);
    }

    /** @param array<string, mixed> $data */
    public function put(string $path, array $data = []): Response
    {
        return $this->request('PUT', $path, [], $data);
    }

    /** @param array<string, mixed> $data */
    public function patch(string $path, array $data = []): Response
    {
        return $this->request('PATCH', $path, [], $data);
    }

    /** HTML 폼 제출(숨은 필드 _csrf는 호출하는 쪽이 넣는다) @param array<string, string> $fields */
    public function form(string $path, array $fields): Response
    {
        return $this->request('POST', $path, [], null, $fields);
    }

    public function fetchCsrf(): string
    {
        $res = $this->get('/api/v1/auth/csrf');
        $this->csrf = (string) ($res->decoded()['data']['csrf_token'] ?? '');
        return $this->csrf;
    }

    public function login(string $loginId, string $password): Response
    {
        if ($this->csrf === null) {
            $this->fetchCsrf();
        }
        $res = $this->post('/api/v1/auth/login', ['login_id' => $loginId, 'password' => $password]);
        $token = $res->decoded()['data']['csrf_token'] ?? null;
        if (is_string($token)) {
            $this->csrf = $token;
        }
        return $res;
    }

    public function sessionCookie(): ?string
    {
        return $this->cookies['pjg_session'] ?? null;
    }

    private function absorbCookie(Response $res): void
    {
        $set = $res->header('Set-Cookie');
        if ($set === null) {
            return;
        }
        [$pair] = explode(';', $set, 2);
        [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
        if ($value === '' || stripos($set, 'Max-Age=0') !== false) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }
}
