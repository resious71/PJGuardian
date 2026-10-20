<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Saml;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;

/**
 * 어서션 ID 재사용 거부(P28D, 리플레이 방지): 쓴 어서션 ID를 만료 시각까지 기억한다(<작업 폴더>/saml/assertions/, 파일 이름 = (발급자, ID)의 해시,
 * 만들기는 배타적 생성 'x' — 동시에 같은 ID가 두 번 와도 하나만 통과). 만료가 지난 기록은 기록할 때 지운다.
 */
final class ReplayGuard
{
    public function __construct(private readonly string $dir, private readonly Clock $clock)
    {
    }

    /** 처음 보는 ID면 기록하고 true, 이미 쓴 ID면 false */
    public function claim(string $issuer, string $assertionId, int $expiresAt): bool
    {
        Fs::ensureDir($this->dir);
        $now = $this->clock->now()->getTimestamp();
        foreach (Fs::listDir($this->dir) as $name) {
            $exp = (int) (@file_get_contents($this->dir . '/' . $name) ?: 0);
            if ($exp > 0 && $exp < $now) {
                @unlink($this->dir . '/' . $name);
            }
        }
        $path = $this->dir . '/' . hash('sha256', $issuer . "\n" . $assertionId);
        $fp = @fopen($path, 'x');
        if ($fp === false) {
            $exp = (int) (@file_get_contents($path) ?: 0);
            if ($exp >= $now) {
                return false;
            }
            @unlink($path); // 만료된 기록(지우지 못하고 남은 것) — 다시 기록
            $fp = @fopen($path, 'x');
            if ($fp === false) {
                return false;
            }
        }
        fwrite($fp, (string) max($expiresAt, $now + 60));
        fclose($fp);
        return true;
    }
}
