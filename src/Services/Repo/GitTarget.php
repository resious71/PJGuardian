<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

/** Git 접속 대상(검증·정규화된 값). token은 복호화한 평문 — 로그·직렬화에 넣지 않는다(__debugInfo로 가림) */
final class GitTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $branch,
        public readonly ?string $subPath,
        public readonly ?string $username,
        public readonly ?string $token,
        public readonly ?string $pinnedIp = null,
    ) {
    }

    public function withPinnedIp(string $ip): self
    {
        return new self($this->url, $this->scheme, $this->host, $this->port, $this->branch, $this->subPath, $this->username, $this->token, $ip);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'branch' => $this->branch, 'sub_path' => $this->subPath, 'token' => $this->token === null ? null : '***'];
    }
}
