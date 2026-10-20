<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Saml;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use PJGuardian\Services\Auth\Oidc\Jose;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Logger;

/**
 * SAML 2.0 SP 로그인(P28D, SP 시작만 — IdP 시작 응답은 거부). XML 서명 검증·정규화는 **라이브러리(onelogin/php-saml + xmlseclibs)** 가 하고
 * 직접 구현하지 않는다. 앱은 그 앞뒤에서 아래를 더 엄격하게 확인한다(완화 없음).
 *
 *  begin():  AuthnRequest(HTTP-Redirect, ID는 라이브러리의 random_bytes) → 요청 기록(키 = 요청 ID, 로그인 전 세션 해시·RelayState 토큰·돌아갈 곳, 10분·한 번만).
 *  acs():    IdP의 cross-site POST(세션 쿠키가 실리지 않음 — SameSite=Lax 유지). 크기 상한 → 라이브러리가 XML을 안전하게 읽음(DOCTYPE·엔티티 거부) →
 *            InResponseTo로 요청 기록을 꺼냄(없음·재사용·만료·IdP 시작 → 거부) → RelayState 토큰 일치 → 라이브러리 검증(서명 필수·래핑·어서션 1개·
 *            Issuer·Audience·InResponseTo·시간·스키마, 롤오버 인증서 2개) → 앱 검사(Destination·Recipient 정확히 같음, 시계 오차 60초로 시간 다시,
 *            SHA-1 서명·다이제스트 거부, transient NameID 거부, 어서션 ID 재사용 거부) → 신원 → 마무리 토큰(2분·한 번만)으로 303.
 *  finish(): 같은 사이트의 최상위 GET이라 Lax 세션 쿠키가 실린다 — 시작한 브라우저(로그인 전 세션)와 같아야 마무리(로그인 CSRF 방지).
 * 응답 원문·어서션 값·속성 값은 로그·감사·오류에 넣지 않는다(사유 코드만).
 */
final class SamlLogin
{
    public const MAX_RESPONSE_BYTES = 524288;
    public const SKEW = 60;

    /** @param \Closure(): ?array{key:string, cert:string} $spKey SP 키·인증서(필요할 때만 복호화) */
    public function __construct(
        private readonly SamlSettings $settings,
        private readonly OneTimeStore $requests,
        private readonly OneTimeStore $finishes,
        private readonly ReplayGuard $replay,
        private readonly \Closure $spKey,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function settings(): SamlSettings
    {
        return $this->settings;
    }

    /**
     * 라이브러리 설정 배열(SP 키가 필요하면 넣음)
     *
     * @return array<string, mixed>
     */
    public function libraryArray(bool $withKey = true, bool $keyIfPresent = false): array
    {
        $sp = null;
        if ($withKey && $this->settings->needsSpKey()) {
            $sp = ($this->spKey)();
            if ($sp === null) {
                throw new SamlException('sp_key_missing');
            }
        } elseif ($keyIfPresent) {
            $sp = ($this->spKey)(); // SP 메타데이터: 키가 있으면 인증서를 늘 싣는다(IdP가 미리 등록할 수 있게)
        }
        return $this->settings->library($sp);
    }

    /** 라이브러리 설정 객체(설정 검사 포함) */
    public function librarySettings(bool $withKey = true, bool $keyIfPresent = false): Settings
    {
        try {
            return new Settings($this->libraryArray($withKey, $keyIfPresent));
        } catch (\OneLogin\Saml2\Error $e) {
            $this->logger->warn('SAML 설정 오류', ['code' => $e->getCode()]);
            throw new SamlException('settings_invalid');
        }
    }

    /** IdP로 보낼 주소(HTTP-Redirect) */
    public function begin(string $sessionHash, string $return): string
    {
        if (!$this->settings->enabled) {
            throw new SamlException('disabled');
        }
        if (!$this->settings->complete()) {
            throw new SamlException('not_configured');
        }
        try {
            $auth = new Auth($this->libraryArray());
        } catch (\OneLogin\Saml2\Error $e) {
            $this->logger->warn('SAML 설정 오류', ['code' => $e->getCode()]);
            throw new SamlException('settings_invalid');
        }
        $relay = Jose::b64urlEncode(random_bytes(24));
        $url = (string) $auth->login($relay, [], false, false, true);
        $id = (string) $auth->getLastRequestID();
        $this->requests->put($id, ['id' => $id, 'session' => $sessionHash, 'relay' => $relay, 'return' => $return]);
        return $url;
    }

    /**
     * IdP 응답 검증 → 마무리 토큰.
     *
     * @param array<string, mixed> $post
     */
    public function acs(array $post): string
    {
        if (!$this->settings->enabled) {
            throw new SamlException('disabled');
        }
        $raw = $post['SAMLResponse'] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new SamlException('response.missing');
        }
        $raw = (string) preg_replace('/\s+/', '', $raw);
        if (strlen($raw) > intdiv(self::MAX_RESPONSE_BYTES * 4, 3) + 4) {
            throw new SamlException('response.too_large');
        }
        if (preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $raw) !== 1 || base64_decode($raw, true) === false) {
            throw new SamlException('response.bad_base64');
        }
        $settings = $this->librarySettings();
        try {
            $response = new Response($settings, $raw); // XML을 안전하게 읽음(DOCTYPE·ENTITY 거부), 암호화된 어서션 복호화
        } catch (\Throwable $e) {
            $this->logger->warn('SAML 응답을 읽을 수 없음', ['class' => (new \ReflectionClass($e))->getShortName(), 'code' => $e->getCode()]);
            throw new SamlException('response.invalid_xml');
        }
        $inResponseTo = $response->document->documentElement->getAttribute('InResponseTo');
        if ($inResponseTo === '') {
            throw new SamlException('unsolicited'); // IdP 시작 로그인은 지원하지 않는다
        }
        $req = $this->requests->take($inResponseTo);
        if ($req === null) {
            throw new SamlException('request_unknown');
        }
        $relay = $post['RelayState'] ?? '';
        if (!is_string($relay) || !hash_equals((string) $req['relay'], $relay)) {
            throw new SamlException('relay_mismatch');
        }
        if (!$this->validateWithPinnedUrl($response, (string) $req['id'])) {
            throw new SamlException('response.' . self::reasonOf($response->getErrorException()));
        }
        $doc = $response->getXMLDocument();
        $this->appChecks($response, $doc);
        $identity = $this->identity($response);
        $assertionId = (string) $response->getAssertionId();
        if ($assertionId === '' || !$this->replay->claim($this->settings->idpEntityId, $assertionId, $this->expiry($doc))) {
            throw new SamlException('response.replay');
        }
        $token = Jose::b64urlEncode(random_bytes(32));
        $this->finishes->put($token, ['identity' => $identity, 'issuer' => $this->settings->idpEntityId, 'session' => $req['session'], 'return' => $req['return']]);
        return $token;
    }

    /**
     * 같은 브라우저에서 마무리(로그인 전 세션 해시가 시작 때와 같아야).
     *
     * @return array{identity: array{login:string, id:string, name:?string, email:?string}, issuer: string, return: string}
     */
    public function finish(string $token, ?string $sessionHash): array
    {
        $v = $this->finishes->take($token);
        if ($v === null || $sessionHash === null || !hash_equals((string) $v['session'], $sessionHash)) {
            throw new SamlException('finish_invalid');
        }
        return ['identity' => (array) $v['identity'], 'issuer' => (string) $v['issuer'], 'return' => (string) $v['return']];
    }

    /**
     * 라이브러리의 Destination·Recipient 비교는 요청 전역 값($_SERVER)으로 "현재 주소"를 만든다. Host·X-Forwarded 헤더를 믿지 않도록
     * 검증하는 동안만 설정한 공개 주소의 출처와 ACS 경로로 고정하고 끝나면 되돌린다.
     */
    private function validateWithPinnedUrl(Response $response, string $requestId): bool
    {
        $u = parse_url($this->settings->acsUrl());
        $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $saved = ['REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, 'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null];
        Utils::setProxyVars(false);
        Utils::setBaseURL($origin . '/');
        $_SERVER['REQUEST_URI'] = $u['path'] ?? '/';
        unset($_SERVER['QUERY_STRING']);
        try {
            return $response->isValid($requestId);
        } finally {
            Utils::setBaseURL('');
            foreach ($saved as $k => $v) {
                if ($v === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $v;
                }
            }
        }
    }

    /** 라이브러리 검증 뒤 앱이 더 엄격하게 보는 것 */
    private function appChecks(Response $response, \DOMDocument $doc): void
    {
        $acs = $this->settings->acsUrl();
        $x = new \DOMXPath($doc);
        $x->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $x->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $x->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $dest = $response->document->documentElement->getAttribute('Destination');
        $recipients = [];
        foreach ($x->query('//saml:Assertion/saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData[@Recipient]') ?: [] as $n) {
            $recipients[] = $n->getAttribute('Recipient');
        }
        if (($dest !== '' && $dest !== $acs) || ($dest === '' && $recipients === [])) {
            throw new SamlException('response.destination');
        }
        foreach ($recipients as $r) {
            if ($r !== $acs) {
                throw new SamlException('response.recipient');
            }
        }
        // 서명·다이제스트 알고리즘: SHA-1 거부(원본 문서와 복호화한 문서 모두)
        foreach ([$response->document, $doc] as $d) {
            $dx = new \DOMXPath($d);
            $dx->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            foreach ($dx->query('//ds:SignatureMethod/@Algorithm | //ds:DigestMethod/@Algorithm') ?: [] as $a) {
                if (preg_match('/sha1$/i', (string) $a->nodeValue) === 1) {
                    throw new SamlException('response.weak_algorithm');
                }
            }
        }
        // 시간: 시계 오차 60초(라이브러리는 180초)로 다시 — 주입한 시계 기준
        $now = $this->clock->now()->getTimestamp();
        foreach ($x->query('//saml:Assertion/saml:Conditions') ?: [] as $c) {
            if ($c->hasAttribute('NotBefore') && Utils::parseSAML2Time($c->getAttribute('NotBefore')) > $now + self::SKEW) {
                throw new SamlException('response.not_yet_valid');
            }
            if ($c->hasAttribute('NotOnOrAfter') && Utils::parseSAML2Time($c->getAttribute('NotOnOrAfter')) + self::SKEW <= $now) {
                throw new SamlException('response.expired');
            }
        }
        foreach ($x->query('//saml:Assertion/saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData[@NotOnOrAfter]') ?: [] as $n) {
            if (Utils::parseSAML2Time($n->getAttribute('NotOnOrAfter')) + self::SKEW <= $now) {
                throw new SamlException('response.expired');
            }
        }
        $session = $response->getSessionNotOnOrAfter();
        if ($session !== null && $session + self::SKEW <= $now) {
            throw new SamlException('response.expired');
        }
        if ($this->settings->idSource === 'nameid' && $response->getNameIdFormat() === SamlSettings::NAMEID_FORMATS['transient']) {
            throw new SamlException('response.transient_nameid');
        }
    }

    /** 어서션을 기억할 기한(Conditions·SubjectConfirmationData NotOnOrAfter 중 늦은 것 + 오차) */
    private function expiry(\DOMDocument $doc): int
    {
        $x = new \DOMXPath($doc);
        $x->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $max = $this->clock->now()->getTimestamp() + 600;
        foreach ($x->query('//saml:Assertion//@NotOnOrAfter') ?: [] as $a) {
            $max = max($max, Utils::parseSAML2Time((string) $a->nodeValue));
        }
        return $max + 180;
    }

    /** @return array{login:string, id:string, name:?string, email:?string} */
    private function identity(Response $response): array
    {
        $attrs = $response->getAttributes();
        $friendly = $response->getAttributesWithFriendlyName();
        $get = static function (string $name) use ($attrs, $friendly): ?string {
            if ($name === '') {
                return null;
            }
            $v = $attrs[$name][0] ?? $friendly[$name][0] ?? null;
            return is_string($v) && $v !== '' ? $v : null;
        };
        $id = $this->settings->idSource === 'nameid' ? (string) $response->getNameId() : (string) ($get($this->settings->idSource) ?? '');
        if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new SamlException('response.no_identifier');
        }
        return ['login' => $get($this->settings->attrLogin) ?? '', 'id' => $id, 'name' => $get($this->settings->attrName), 'email' => $get($this->settings->attrEmail)];
    }

    private static function reasonOf(?\Throwable $e): string
    {
        if ($e !== null && !$e instanceof ValidationError) {
            // xmlseclibs 단계의 일반 예외(참조·서명 값 검증 실패 등) — 메시지는 분류에만 쓰고 남기지 않는다
            return preg_match('/reference|signature|digest|key/i', $e->getMessage()) === 1 ? 'bad_signature' : 'invalid';
        }
        $code = $e instanceof ValidationError ? $e->getCode() : -1;
        return match ($code) {
            ValidationError::STATUS_CODE_IS_NOT_SUCCESS => 'idp_status',
            ValidationError::NO_SIGNATURE_FOUND, ValidationError::NO_SIGNED_ASSERTION, ValidationError::NO_SIGNED_MESSAGE => 'no_signature',
            ValidationError::INVALID_SIGNATURE, ValidationError::KEY_ALGORITHM_ERROR => 'bad_signature',
            ValidationError::WRONG_SIGNED_ELEMENT, ValidationError::ID_NOT_FOUND_IN_SIGNED_ELEMENT, ValidationError::DUPLICATED_ID_IN_SIGNED_ELEMENTS,
            ValidationError::INVALID_SIGNED_ELEMENT, ValidationError::DUPLICATED_REFERENCE_IN_SIGNED_ELEMENTS, ValidationError::UNEXPECTED_SIGNED_ELEMENTS,
            ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_RESPONSE, ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_ASSERTION,
            ValidationError::WRONG_NUMBER_OF_SIGNATURES, ValidationError::UNEXPECTED_REFERENCE => 'signed_element',
            ValidationError::WRONG_NUMBER_OF_ASSERTIONS => 'assertion_count',
            ValidationError::WRONG_DESTINATION, ValidationError::EMPTY_DESTINATION => 'destination',
            ValidationError::WRONG_AUDIENCE => 'audience',
            ValidationError::WRONG_ISSUER, ValidationError::ISSUER_MULTIPLE_IN_RESPONSE, ValidationError::ISSUER_NOT_FOUND_IN_ASSERTION => 'issuer',
            ValidationError::WRONG_INRESPONSETO => 'in_response_to',
            ValidationError::ASSERTION_TOO_EARLY => 'not_yet_valid',
            ValidationError::ASSERTION_EXPIRED, ValidationError::SESSION_EXPIRED, ValidationError::RESPONSE_EXPIRED => 'expired',
            ValidationError::WRONG_SUBJECTCONFIRMATION => 'subject_confirmation',
            ValidationError::INVALID_XML_FORMAT => 'schema',
            ValidationError::NO_ENCRYPTED_ASSERTION => 'not_encrypted',
            ValidationError::NO_NAMEID, ValidationError::EMPTY_NAMEID => 'no_identifier',
            default => 'invalid',
        };
    }
}
