<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;

/**
 * 로그인 요청 상태(P28C): state·nonce·PKCE code_verifier·돌아갈 주소를 **서버 쪽 파일**에 둔다(<작업 폴더>/oidc/state/).
 *  - 파일 이름은 state의 SHA-256(state 원문은 디스크에도 없다), 내용은 로그인 전 세션 ID 해시에 묶인다 — 다른 브라우저에서 온 콜백(CSRF)은 거부.
 *  - 10분 만료, **한 번만 사용**: 꺼낼 때 이름 바꾸기(원자적)로 가져가므로 같은 state로 두 번 오면(리플레이·코드 재사용) 두 번째는 없음.
 *  - 쌓이지 않게: 만들 때 만료된 파일을 지우고, 미사용 요청이 MAX_OUTSTANDING을 넘으면 새 요청을 받지 않는다(busy).
 */
final class OidcStateStore
{
    public const TTL = 600;
    public const MAX_OUTSTANDING = 2000;

    public function __construct(private readonly string $dir, private readonly Clock $clock)
    {
    }

    /** @return array{state:string, nonce:string, verifier:string} */
    public function create(string $sessionHash, string $issuer, string $return): array
    {
        Fs::ensureDir($this->dir);
        $now = $this->clock->now()->getTimestamp();
        $outstanding = 0;
        foreach (Fs::listDir($this->dir) as $name) {
            $p = $this->dir . '/' . $name;
            $mtime = @filemtime($p);
            if ($mtime !== false && $mtime < $now - 2 * self::TTL) {
                @unlink($p);
            } else {
                $outstanding++;
            }
        }
        $rateDir = dirname($this->dir) . '/rate'; // 시작 요청 제한 기록도 1시간 지나면 정리
        foreach (is_dir($rateDir) ? Fs::listDir($rateDir) : [] as $name) {
            $mtime = @filemtime($rateDir . '/' . $name);
            if ($mtime !== false && $mtime < $now - 3600) {
                @unlink($rateDir . '/' . $name);
            }
        }
        if ($outstanding >= self::MAX_OUTSTANDING) {
            throw new OidcException('busy');
        }
        $out = ['state' => Jose::b64urlEncode(random_bytes(32)), 'nonce' => Jose::b64urlEncode(random_bytes(32)), 'verifier' => Jose::b64urlEncode(random_bytes(32))];
        Fs::atomicWrite($this->path($out['state']), (string) json_encode([
            'session' => $sessionHash, 'issuer' => $issuer, 'nonce' => $out['nonce'], 'verifier' => $out['verifier'], 'return' => $return, 'created' => $now,
        ], JSON_UNESCAPED_SLASHES));
        return $out;
    }

    /**
     * 꺼내고 지운다(한 번만). 없음·이미 사용·만료·다른 세션·다른 발급자면 null.
     *
     * @return array{nonce:string, verifier:string, return:string}|null
     */
    public function take(string $state, string $sessionHash, string $issuer): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $state) !== 1) {
            return null;
        }
        $path = $this->path($state);
        $claimed = $path . '.taken-' . bin2hex(random_bytes(6));
        if (!is_file($path) || !@rename($path, $claimed)) {
            return null;
        }
        $raw = @file_get_contents($claimed);
        @unlink($claimed);
        $v = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($v) || !is_string($v['session'] ?? null) || !is_int($v['created'] ?? null)) {
            return null;
        }
        if ($v['created'] + self::TTL <= $this->clock->now()->getTimestamp() || !hash_equals($v['session'], $sessionHash) || ($v['issuer'] ?? null) !== $issuer) {
            return null;
        }
        return ['nonce' => (string) $v['nonce'], 'verifier' => (string) $v['verifier'], 'return' => (string) $v['return']];
    }

    /**
     * 로그인 시작 요청 제한: IP당 분당 $perMinute회(파일 이름은 IP의 해시). 넘으면 false.
     * 잠금 전용 파일로 순서를 맞춘다(Windows flock은 강제 잠금 — 잠근 파일 자체는 읽고 쓰지 않음).
     */
    public function allowStart(string $ip, int $perMinute): bool
    {
        $dir = dirname($this->dir) . '/rate';
        Fs::ensureDir($dir);
        $key = substr(hash('sha256', $ip), 0, 32);
        $lock = \PJGuardian\Support\FileLock::acquire($dir . '/' . $key . '.lock');
        try {
            $file = $dir . '/' . $key . '.json';
            $now = $this->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $perMinute) {
                return false;
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
            return true;
        } finally {
            $lock->release();
        }
    }

    private function path(string $state): string
    {
        return $this->dir . '/' . hash('sha256', $state) . '.json';
    }
}
