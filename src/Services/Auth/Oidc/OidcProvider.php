<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * 제공자 메타데이터(디스커버리)와 서명 키(JWKS) — 캐시 포함(P28C).
 *  - 디스커버리: <발급자>/.well-known/openid-configuration. 받은 issuer가 설정 값과 **정확히** 같아야 하고, 엔드포인트는 https여야 한다.
 *    id_token_signing_alg_values_supported·code_challenge_methods_supported가 있으면 허용 알고리즘·S256과 겹쳐야 한다.
 *  - JWKS: jwks_uri. 캐시(oidc.jwks_cache_ttl, 기본 1시간). 알 수 없는 kid면 한 번 강제로 새로 받는다 — 단 마지막으로 받은 지 60초 안이면 받지 않는다(빈도 제한).
 *  - 가져오기 실패: 캐시가 ttl + oidc.jwks_stale_max(상한 24시간) 안이면 오래된 캐시를 쓴다(경고 로그).
 * 캐시 파일: <작업 폴더>/oidc/cache/<발급자 해시>.json (발급자가 바뀌면 다른 파일). 비밀·토큰은 없다(공개 메타데이터·공개 키만).
 */
final class OidcProvider
{
    public const MIN_REFRESH = 60;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly OidcSettings $settings,
        private readonly OidcHttp $http,
        private readonly Clock $clock,
        private readonly string $cacheDir,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, mixed> 검사한 디스커버리 문서 */
    public function metadata(bool $force = false): array
    {
        $c = $this->load();
        $now = $this->now();
        $d = $c['discovery'] ?? null;
        if (!$force && is_array($d) && $d['fetched_at'] + $this->settings->cacheTtl > $now) {
            return $d['doc'];
        }
        try {
            $doc = $this->fetchDiscovery();
        } catch (OidcException $e) {
            if (!$force && is_array($d) && $d['fetched_at'] + $this->settings->cacheTtl + $this->settings->staleMax > $now) {
                $this->logger->warn('OIDC 디스커버리 가져오기 실패 — 오래된 캐시 사용', ['reason' => $e->reason]);
                return $d['doc'];
            }
            throw $e;
        }
        $c['discovery'] = ['doc' => $doc, 'fetched_at' => $now];
        $this->save($c);
        return $doc;
    }

    /** 캐시에 있는 디스커버리 문서(네트워크 없음, 로그아웃·CSP용) @return array<string, mixed>|null */
    public function cachedMetadata(): ?array
    {
        $d = $this->load()['discovery'] ?? null;
        return is_array($d) ? $d['doc'] : null;
    }

    /**
     * @param bool $refresh 알 수 없는 kid — 새로 받기(마지막으로 받은 지 MIN_REFRESH초 안이면 캐시 그대로)
     * @return list<array<string, mixed>>
     */
    public function keys(bool $refresh = false, bool $force = false): array
    {
        $c = $this->load();
        $now = $this->now();
        $j = $c['jwks'] ?? null;
        $fresh = is_array($j) && $j['fetched_at'] + $this->settings->cacheTtl > $now;
        if (!$force && is_array($j) && ($refresh ? $j['fetched_at'] + self::MIN_REFRESH > $now : $fresh)) {
            return $j['keys'];
        }
        try {
            $keys = $this->fetchJwks($this->metadata()['jwks_uri']);
        } catch (OidcException $e) {
            if (!$force && is_array($j) && $j['fetched_at'] + $this->settings->cacheTtl + $this->settings->staleMax > $now) {
                $this->logger->warn('OIDC JWKS 가져오기 실패 — 오래된 캐시 사용', ['reason' => $e->reason]);
                return $j['keys'];
            }
            throw $e;
        }
        $c = $this->load();
        $c['jwks'] = ['keys' => $keys, 'fetched_at' => $now];
        $this->save($c);
        return $keys;
    }

    /** @return array<string, mixed> */
    private function fetchDiscovery(): array
    {
        if (!$this->settings->complete()) {
            throw new OidcException('not_configured');
        }
        $res = $this->http->get(rtrim($this->settings->issuer, '/') . '/.well-known/openid-configuration', ['Accept' => 'application/json']);
        if ($res['status'] !== 200) {
            throw new OidcException('http_status');
        }
        $doc = json_decode($res['body'], true);
        if (!is_array($doc) || array_is_list($doc)) {
            throw new OidcException('discovery_invalid');
        }
        if (($doc['issuer'] ?? null) !== $this->settings->issuer) {
            throw new OidcException('issuer_mismatch');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            if (!is_string($doc[$k] ?? null) || !OidcHttp::validUrl($doc[$k])) {
                throw new OidcException('discovery_invalid');
            }
        }
        foreach (['userinfo_endpoint', 'end_session_endpoint'] as $k) {
            if (isset($doc[$k]) && (!is_string($doc[$k]) || !OidcHttp::validUrl($doc[$k]))) {
                unset($doc[$k]); // 선택 엔드포인트가 이상하면 쓰지 않는다
            }
        }
        $algs = $doc['id_token_signing_alg_values_supported'] ?? null;
        if (is_array($algs) && array_intersect($algs, $this->settings->allowedAlgs) === []) {
            throw new OidcException('discovery_invalid.alg');
        }
        $pkce = $doc['code_challenge_methods_supported'] ?? null;
        if (is_array($pkce) && !in_array('S256', $pkce, true)) {
            throw new OidcException('discovery_invalid.pkce');
        }
        $keep = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'end_session_endpoint',
            'id_token_signing_alg_values_supported', 'token_endpoint_auth_methods_supported', 'code_challenge_methods_supported'];
        return array_intersect_key($doc, array_flip($keep));
    }

    /** @return list<array<string, mixed>> */
    private function fetchJwks(string $uri): array
    {
        $res = $this->http->get($uri, ['Accept' => 'application/json']);
        if ($res['status'] !== 200) {
            throw new OidcException('http_status');
        }
        $doc = json_decode($res['body'], true);
        if (!is_array($doc) || !is_array($doc['keys'] ?? null) || count($doc['keys']) > 100) {
            throw new OidcException('jwks_invalid');
        }
        $keys = [];
        foreach ($doc['keys'] as $k) {
            if (is_array($k) && in_array($k['kty'] ?? null, ['RSA', 'EC'], true)) {
                // 공개 키 필드만 보관(개인 키 필드 d 등이 섞여 와도 버린다)
                $keys[] = array_intersect_key($k, array_flip(['kty', 'kid', 'use', 'alg', 'key_ops', 'n', 'e', 'crv', 'x', 'y']));
            }
        }
        return $keys;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function file(): string
    {
        return $this->cacheDir . '/' . substr(hash('sha256', $this->settings->issuer), 0, 24) . '.json';
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->cache === null) {
            $raw = @file_get_contents($this->file());
            $v = is_string($raw) ? json_decode($raw, true) : null;
            $this->cache = is_array($v) && ($v['issuer'] ?? null) === $this->settings->issuer ? $v : ['issuer' => $this->settings->issuer];
        }
        return $this->cache;
    }

    /** @param array<string, mixed> $c */
    private function save(array $c): void
    {
        $this->cache = $c;
        Fs::ensureDir($this->cacheDir);
        Fs::atomicWrite($this->file(), (string) json_encode($c, JSON_UNESCAPED_SLASHES));
    }
}
