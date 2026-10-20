<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

/** Perforce 접속 대상(검증된 값). secret은 복호화한 비밀번호·티켓 평문 — 로그·직렬화에 넣지 않는다 */
final class P4Target
{
    /** @param 'password'|'ticket'|null $secretKind */
    public function __construct(
        public readonly string $port,
        public readonly bool $ssl,
        public readonly string $host,
        public readonly int $portNumber,
        public readonly string $depotPath,
        public readonly string $username,
        public readonly ?string $secretKind,
        public readonly ?string $secret,
        public readonly ?string $fingerprint,
        public readonly ?string $pinnedIp = null,
    ) {
    }

    public function withPinnedIp(string $ip): self
    {
        return new self($this->port, $this->ssl, $this->host, $this->portNumber, $this->depotPath, $this->username, $this->secretKind, $this->secret, $this->fingerprint, $ip);
    }

    /** 실제로 접속할 P4PORT(판정한 주소로 고정 — DNS 리바인딩 방지) */
    public function connectPort(): string
    {
        $h = $this->pinnedIp ?? $this->host;
        return ($this->ssl ? 'ssl:' : '') . (str_contains($h, ':') ? '[' . $h . ']' : $h) . ':' . $this->portNumber;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['port' => $this->port, 'depot' => $this->depotPath, 'user' => $this->username, 'secret' => $this->secret === null ? null : '***'];
    }
}
