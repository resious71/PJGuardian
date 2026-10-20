<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Saml;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;

/**
 * 한 번만 꺼낼 수 있는 짧은 수명의 서버 쪽 기록(P28D, OidcStateStore와 같은 파일 방식): 파일 이름은 키의 SHA-256(키 원문은 디스크에 없음),
 * 꺼낼 때 이름 바꾸기(원자적)로 가져가므로 같은 키로 두 번 오면 두 번째는 없음. 만료(ttl)·쌓임 상한(MAX_OUTSTANDING).
 * 쓰는 곳: AuthnRequest 기록(키 = 요청 ID, 10분 — ACS의 InResponseTo로 찾음), 로그인 마무리 표(키 = 무작위 토큰, 2분).
 */
final class OneTimeStore
{
    public const MAX_OUTSTANDING = 2000;

    public function __construct(private readonly string $dir, private readonly int $ttl, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $data */
    public function put(string $key, array $data): void
    {
        Fs::ensureDir($this->dir);
        $now = $this->clock->now()->getTimestamp();
        $outstanding = 0;
        foreach (Fs::listDir($this->dir) as $name) {
            $p = $this->dir . '/' . $name;
            $mtime = @filemtime($p);
            if ($mtime !== false && $mtime < $now - 2 * $this->ttl - 60) {
                @unlink($p);
            } else {
                $outstanding++;
            }
        }
        if ($outstanding >= self::MAX_OUTSTANDING) {
            throw new SamlException('busy');
        }
        Fs::atomicWrite($this->path($key), (string) json_encode(['data' => $data, 'created' => $now], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed>|null 꺼내고 지운다. 없음·이미 꺼냄·만료면 null */
    public function take(string $key): ?array
    {
        if ($key === '' || strlen($key) > 256) {
            return null;
        }
        $path = $this->path($key);
        $claimed = $path . '.taken-' . bin2hex(random_bytes(6));
        if (!is_file($path) || !@rename($path, $claimed)) {
            return null;
        }
        $raw = @file_get_contents($claimed);
        @unlink($claimed);
        $v = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($v) || !is_array($v['data'] ?? null) || !is_int($v['created'] ?? null)) {
            return null;
        }
        return $v['created'] + $this->ttl > $this->clock->now()->getTimestamp() ? $v['data'] : null;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
