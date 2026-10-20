<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Logger;

/**
 * OIDC 로그인 프로토콜(P28C, Authorization Code + PKCE S256). 계정 결정(승인 대기·연결·충돌)은 여기서 하지 않는다 —
 * complete()가 돌려준 신원으로 LoginService::attemptExternal → ExternalAccountProvisioner가 한다.
 *
 *  begin():    state·nonce·code_verifier를 서버 쪽(OidcStateStore, 로그인 전 세션에 묶음)에 두고 인가 엔드포인트 주소를 돌려준다.
 *  complete(): state 확인(없음·재사용·만료·다른 세션 → state_invalid) → IdP error 파라미터 → 서버가 토큰 엔드포인트로 코드 교환
 *              (client_secret_basic 기본 | client_secret_post | none) → ID 토큰 서명·클레임 검사(Jose, IdTokenValidator) → (선택) UserInfo.
 * ID 토큰·액세스 토큰은 이 요청 안에서만 쓰고 저장하지 않는다(리프레시 토큰은 요청하지 않음 — offline_access scope 없음).
 * 코드·state·토큰·클라이언트 비밀은 로그·예외 사유에 넣지 않는다.
 */
final class OidcLogin
{
    /** @param \Closure(): string $secret 클라이언트 비밀(쓸 때만 복호화). 읽을 수 없으면 OidcException(secret_unreadable) */
    public function __construct(
        private readonly OidcSettings $settings,
        private readonly OidcProvider $provider,
        private readonly OidcHttp $http,
        private readonly OidcStateStore $states,
        private readonly \Closure $secret,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function settings(): OidcSettings
    {
        return $this->settings;
    }

    /** 인가 엔드포인트로 보낼 주소 */
    public function begin(string $sessionHash, string $return): string
    {
        if (!$this->settings->enabled) {
            throw new OidcException('disabled');
        }
        if ($this->settings->needsSecret() && $this->clientSecret() === '') {
            throw new OidcException('secret_missing');
        }
        $meta = $this->provider->metadata();
        $st = $this->states->create($sessionHash, $this->settings->issuer, $return);
        $params = [
            'response_type' => 'code',
            'client_id' => $this->settings->clientId,
            'redirect_uri' => $this->settings->redirectUri(),
            'scope' => implode(' ', $this->settings->scopes),
            'state' => $st['state'],
            'nonce' => $st['nonce'],
            'code_challenge' => Jose::b64urlEncode(hash('sha256', $st['verifier'], true)),
            'code_challenge_method' => 'S256',
        ];
        $ep = (string) $meta['authorization_endpoint'];
        return $ep . (str_contains($ep, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $query 콜백 질의
     * @return array{identity: array{login:string, id:string, name:?string, email:?string}, issuer: string, return: string}
     */
    public function complete(array $query, ?string $sessionHash): array
    {
        if (!$this->settings->enabled) {
            throw new OidcException('disabled');
        }
        $state = $query['state'] ?? null;
        if (!is_string($state) || $state === '') {
            throw new OidcException('state_missing');
        }
        $st = $sessionHash === null ? null : $this->states->take($state, $sessionHash, $this->settings->issuer);
        if ($st === null) {
            throw new OidcException('state_invalid');
        }
        if (isset($query['error'])) {
            $err = is_string($query['error']) && preg_match('/^[a-z_]{1,64}$/', $query['error']) === 1 ? $query['error'] : 'other';
            throw new OidcException('idp_error.' . $err);
        }
        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '' || strlen($code) > 4096 || preg_match('/[^\x21-\x7E]/', $code) === 1) {
            throw new OidcException('token_error.no_code');
        }
        $tokens = $this->exchange($code, $st['verifier']);
        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken)) {
            throw new OidcException('token_error.no_id_token');
        }
        $claims = $this->verifyIdToken($idToken, $st['nonce']);
        if ($this->settings->useUserinfo) {
            $claims = $this->withUserinfo($claims, $tokens['access_token'] ?? null);
        }
        return ['identity' => $this->identity($claims), 'issuer' => $this->settings->issuer, 'return' => $st['return']];
    }

    /** 제공자 로그아웃 주소(설정이 켜져 있고 캐시된 메타데이터에 end_session_endpoint가 있을 때만, 네트워크 없음) */
    public function logoutUrl(): ?string
    {
        if (!$this->settings->providerLogout) {
            return null;
        }
        $ep = $this->provider->cachedMetadata()['end_session_endpoint'] ?? null;
        if (!is_string($ep) || !OidcHttp::validUrl($ep)) {
            return null;
        }
        $q = http_build_query(['client_id' => $this->settings->clientId, 'post_logout_redirect_uri' => $this->settings->postLogoutUri()], '', '&', PHP_QUERY_RFC3986);
        return $ep . (str_contains($ep, '?') ? '&' : '?') . $q;
    }

    /** CSP form-action에 더할 제공자 로그아웃 출처(scheme://host[:port]) */
    public function logoutOrigin(): ?string
    {
        $url = $this->logoutUrl();
        if ($url === null) {
            return null;
        }
        $u = parse_url($url);
        return 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    }

    /** @return array<string, mixed> 토큰 응답 */
    private function exchange(string $code, string $verifier): array
    {
        $meta = $this->provider->metadata();
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->settings->redirectUri(), 'code_verifier' => $verifier];
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        $secret = $this->settings->needsSecret() ? $this->clientSecret() : '';
        if ($this->settings->needsSecret() && $secret === '') {
            throw new OidcException('secret_missing');
        }
        if ($this->settings->clientAuth === 'basic') {
            // RFC 6749 2.3.1: 아이디·비밀을 form 인코딩한 뒤 Basic
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($this->settings->clientId) . ':' . urlencode($secret));
        } else {
            $form['client_id'] = $this->settings->clientId;
            if ($this->settings->clientAuth === 'post') {
                $form['client_secret'] = $secret;
            }
        }
        $res = $this->http->post((string) $meta['token_endpoint'], $headers, http_build_query($form, '', '&', PHP_QUERY_RFC1738));
        $body = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($body)) {
            $err = is_array($body) && is_string($body['error'] ?? null) && preg_match('/^[a-z_]{1,64}$/', $body['error']) === 1 ? $body['error'] : 'http_' . $res['status'];
            throw new OidcException('token_error.' . $err);
        }
        return $body;
    }

    /** @return array<string, mixed> 검사한 클레임 */
    private function verifyIdToken(string $jwt, string $nonce): array
    {
        [$header, $claims, $input, $sig] = Jose::decode($jwt);
        try {
            Jose::verify($header, $input, $sig, $this->provider->keys(), $this->settings->allowedAlgs);
        } catch (OidcException $e) {
            if ($e->reason !== 'id_token.unknown_kid') {
                throw $e;
            }
            // 키 교체(kid 회전): JWKS를 한 번 새로 받아 다시(빈도 제한은 OidcProvider)
            Jose::verify($header, $input, $sig, $this->provider->keys(true), $this->settings->allowedAlgs);
        }
        IdTokenValidator::check($claims, $this->settings->issuer, $this->settings->clientId, $nonce, $this->clock->now()->getTimestamp());
        return $claims;
    }

    /**
     * UserInfo(설정으로 켬): sub가 ID 토큰과 같아야 하고, 프로필 값(로그인 ID·이름·이메일)만 보탠다. 식별(sub)·검증 클레임은 ID 토큰 그대로.
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function withUserinfo(array $claims, mixed $accessToken): array
    {
        $ep = $this->provider->metadata()['userinfo_endpoint'] ?? null;
        if (!is_string($ep) || !is_string($accessToken) || $accessToken === '') {
            throw new OidcException('userinfo_unavailable');
        }
        $res = $this->http->get($ep, ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']);
        $ui = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($ui)) {
            throw new OidcException('userinfo_failed');
        }
        if (($ui['sub'] ?? null) !== $claims['sub']) {
            throw new OidcException('userinfo_sub_mismatch');
        }
        foreach (array_unique([$this->settings->claimLogin, $this->settings->claimName, 'email', 'email_verified']) as $k) {
            if (array_key_exists($k, $ui) && !in_array($k, ['iss', 'aud', 'sub', 'nonce', 'exp', 'iat'], true)) {
                $claims[$k] = $ui[$k];
            }
        }
        return $claims;
    }

    /**
     * 클레임 → 앱이 쓰는 신원. email_verified가 false면 이메일을 믿지 않는다(비움). 로그인 ID 후보가 없거나 X06에 맞지 않으면
     * 공통 서비스가 invalid_login_id로 거부한다.
     *
     * @param array<string, mixed> $c
     * @return array{login:string, id:string, name:?string, email:?string}
     */
    private function identity(array $c): array
    {
        $verified = !(($c['email_verified'] ?? null) === false || ($c['email_verified'] ?? null) === 'false');
        $email = $verified && is_string($c['email'] ?? null) ? $c['email'] : null;
        if ($this->settings->claimLogin === 'email_localpart') {
            $login = $email !== null && str_contains($email, '@') ? strstr($email, '@', true) : '';
        } else {
            $v = $c[$this->settings->claimLogin] ?? null;
            $login = is_string($v) ? $v : '';
        }
        $name = $c[$this->settings->claimName] ?? null;
        return ['login' => $login, 'id' => (string) $c['sub'], 'name' => is_string($name) ? $name : null, 'email' => $email];
    }

    private function clientSecret(): string
    {
        return ($this->secret)();
    }
}
