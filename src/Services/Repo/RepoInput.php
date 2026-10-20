<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Http\ApiException;

/**
 * 저장소 연결 입력 검증(P25, 명세 18.1·18.4). 모든 값은 git·p4에 **인자 배열**로만 넘어가지만, 인자 주입(`-`로 시작)·줄바꿈(헤더 주입)·
 * 경로 이탈을 입력 단계에서 막는다. 실패는 400(필드별 코드, details.field).
 *
 * Git URL: http/https만(ssh·file·git·상대 경로 금지), 사용자 정보(user:pass@) 금지, 쿼리·조각 금지, 호스트는 정규화(소문자·후행 점 제거).
 * P4PORT: `ssl:host:port`(SSL) 또는 `host:port`·`tcp:host:port`·`tcp4:host:port`(비SSL). 그 밖의 접두(rsh·ssl6·tcp6 등)는 거부.
 * depot 경로: `//depot/…/...` 하나(와일드카드는 끝의 `...`만, `@ # % *`·`..` 조각 금지).
 */
final class RepoInput
{
    public const MAX_URL = 500;

    /** @return array{url:string, scheme:string, host:string, port:int, path:string} */
    public static function gitUrl(mixed $v): array
    {
        if (!is_string($v) || self::hasControl($v)) {
            throw self::bad('url');
        }
        $v = trim($v);
        if ($v === '' || strlen($v) > self::MAX_URL || preg_match('/[\x00-\x20\x7F]/', $v) === 1 || str_starts_with($v, '-')) {
            throw self::bad('url');
        }
        if (preg_match('#^(https?)://#i', $v) !== 1) {
            throw new ApiException(400, 'invalid_repo_url_scheme', [], null, [], [], ['field' => 'url']);
        }
        $p = parse_url($v);
        if ($p === false || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw self::bad('url');
        }
        $scheme = strtolower((string) $p['scheme']);
        $host = HostPolicy::normalizeHost((string) $p['host']);
        if ($host === null) {
            throw self::bad('url');
        }
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw self::bad('url');
        }
        $path = (string) ($p['path'] ?? '');
        if ($path === '' || $path === '/' || str_contains($path, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            throw self::bad('url');
        }
        $hostPart = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        return ['url' => $scheme . '://' . $hostPart . ($default ? '' : ':' . $port) . $path, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path];
    }

    /** 브랜치(선택). git check-ref-format의 핵심 규칙 + 인자 주입 방지 */
    public static function branch(mixed $v): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (!is_string($v) || self::hasControl($v)) {
            throw self::bad('branch');
        }
        $v = trim($v);
        if (strlen($v) > 200 || preg_match('#^[A-Za-z0-9._/-]+$#', $v) !== 1 || str_starts_with($v, '-') || str_starts_with($v, '/')
            || str_ends_with($v, '/') || str_ends_with($v, '.') || str_ends_with($v, '.lock') || str_contains($v, '..') || str_contains($v, '//')
            || preg_match('#(^|/)\.#', $v) === 1) {
            throw self::bad('branch');
        }
        return $v;
    }

    /** Git 하위 경로(선택): 저장소 안 상대 경로만 */
    public static function subPath(mixed $v): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (!is_string($v) || self::hasControl($v)) {
            throw self::bad('path');
        }
        $v = trim(trim($v), '/');
        if ($v === '' || strlen($v) > 500 || preg_match('/[\x00-\x1F\x7F\\\\:*?"<>|]/', $v) === 1 || str_starts_with($v, '-')) {
            throw self::bad('path');
        }
        foreach (explode('/', $v) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                throw self::bad('path');
            }
        }
        return $v;
    }

    /** @return array{port:string, ssl:bool, host:string, number:int} port = 정규화한 P4PORT(ssl:host:port 또는 host:port) */
    public static function p4Port(mixed $v): array
    {
        if (!is_string($v) || self::hasControl($v)) {
            throw self::bad('location');
        }
        $v = trim($v);
        if ($v === '' || strlen($v) > 300 || preg_match('/[\x00-\x20\x7F]/', $v) === 1
            || preg_match('/^(?:(ssl|tcp|tcp4):)?(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+):([0-9]{1,5})$/i', $v, $m) !== 1) {
            throw self::bad('location');
        }
        $ssl = strtolower($m[1]) === 'ssl';
        $host = HostPolicy::normalizeHost(trim($m[2], '[]'));
        $num = (int) $m[3];
        if ($host === null || $num < 1 || $num > 65535) {
            throw self::bad('location');
        }
        $hostPart = str_contains($host, ':') ? '[' . $host . ']' : $host;
        return ['port' => ($ssl ? 'ssl:' : '') . $hostPart . ':' . $num, 'ssl' => $ssl, 'host' => $host, 'number' => $num];
    }

    public static function depotPath(mixed $v): string
    {
        if (!is_string($v) || self::hasControl($v)) {
            throw self::bad('path');
        }
        $v = trim($v);
        if (strlen($v) > 500 || preg_match('#^//[A-Za-z0-9_.-]+(/[^/\x00-\x20\x7F@\#%*]+)*/\.\.\.$#', $v) !== 1) {
            throw self::bad('path');
        }
        foreach (explode('/', substr($v, 2, -4)) as $seg) {
            if ($seg === '..' || $seg === '.' || str_contains($seg, '...')) {
                throw self::bad('path');
            }
        }
        return $v;
    }

    public static function username(mixed $v, bool $required): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) {
                throw self::bad('username');
            }
            return null;
        }
        if (!is_string($v) || self::hasControl($v) || strlen(trim($v)) > 200 || preg_match('/[\x00-\x20\x7F]/', trim($v)) === 1 || str_starts_with(trim($v), '-')) {
            throw self::bad('username');
        }
        return trim($v);
    }

    /** 토큰·비밀번호·티켓: 줄바꿈·제어 문자 금지(HTTP 헤더·환경 변수 주입 방지), 4,000자 이하 */
    public static function secret(mixed $v): string
    {
        if (!is_string($v) || $v === '' || strlen($v) > 4000 || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            throw self::bad('secret');
        }
        return $v;
    }

    /** SSL 서버 지문(선택): XX:XX:… 16진 */
    public static function fingerprint(mixed $v): ?string
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (!is_string($v) || self::hasControl($v)) {
            throw self::bad('fingerprint');
        }
        $v = strtoupper(trim($v));
        if (preg_match('/^([0-9A-F]{2}:){19,63}[0-9A-F]{2}$/', $v) !== 1) {
            throw self::bad('fingerprint');
        }
        return $v;
    }

    public static function fetchTimeout(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_int($v) || $v < 60 || $v > 86400) {
            throw self::bad('fetch_timeout');
        }
        return $v;
    }

    /** 사용자 정보를 뺀 주소(화면·기록용). 저장된 URL에는 원래 사용자 정보가 없지만 방어적으로 한 번 더 */
    public static function redactUrl(string $url): string
    {
        return (string) preg_replace('#^(https?://)[^/@]*@#i', '$1', $url);
    }

    /** 제어 문자(NUL·줄바꿈·탭 등) — trim이 앞뒤 NUL을 지우므로 자르기 전에 본다 */
    private static function hasControl(string $v): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $v) === 1;
    }

    private static function bad(string $field): ApiException
    {
        return new ApiException(400, 'invalid_repo_input', ['field' => $field], null, [], [], ['field' => $field]);
    }
}
