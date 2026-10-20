<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Support\Config;

/** OIDC 설정 값(P28C, [oidc]). 리다이렉트 URI는 설정한 공개 주소(oidc.redirect_base, 비우면 app.base_url)로만 만든다 — Host 헤더는 쓰지 않는다 */
final class OidcSettings
{
    /**
     * @param list<string> $scopes
     * @param list<string> $allowedAlgs
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientAuth,
        public readonly array $scopes,
        public readonly string $claimLogin,
        public readonly string $claimName,
        public readonly bool $useUserinfo,
        public readonly array $allowedAlgs,
        public readonly string $buttonLabel,
        public readonly bool $providerLogout,
        public readonly string $publicBase,
        public readonly int $cacheTtl,
        public readonly int $staleMax,
        public readonly int $startPerMinute,
        public readonly int $testPerMinute,
        public readonly bool $allowLocalDev,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        $base = trim($c->string('oidc.redirect_base'));
        if ($base === '') {
            $base = $c->string('app.base_url');
        }
        return new self(
            $c->bool('oidc.enabled'), trim($c->string('oidc.issuer')), trim($c->string('oidc.client_id')), $c->string('oidc.client_auth'),
            array_values(array_filter(explode(' ', trim($c->string('oidc.scopes'))), static fn (string $s): bool => $s !== '')),
            trim($c->string('oidc.claim_login')), trim($c->string('oidc.claim_name')), $c->bool('oidc.use_userinfo'),
            array_values((array) $c->get('oidc.allowed_algs')), trim($c->string('oidc.button_label')), $c->bool('oidc.provider_logout'),
            rtrim($base, '/'), $c->int('oidc.jwks_cache_ttl'), $c->int('oidc.jwks_stale_max'),
            $c->int('oidc.start_per_minute'), $c->int('oidc.test_per_minute'), $c->bool('oidc.allow_local_dev'),
        );
    }

    /** 로그인에 쓸 수 있을 만큼 채워졌는가(클라이언트 비밀은 따로) */
    public function complete(): bool
    {
        return $this->issuer !== '' && $this->clientId !== '';
    }

    public function needsSecret(): bool
    {
        return $this->clientAuth !== 'none';
    }

    /** IdP에 등록할 리다이렉트 URI */
    public function redirectUri(): string
    {
        return $this->publicBase . '/auth/oidc/callback';
    }

    /** 제공자 로그아웃 뒤 돌아올 주소(IdP에 등록) */
    public function postLogoutUri(): string
    {
        return $this->publicBase . '/login?logged_out=1';
    }
}
