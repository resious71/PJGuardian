<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Services\Auth\Oidc\HttpTransport;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * 모의 SAML IdP(P28D 시험·격리 점검 전용 — 실제 IdP에 연결하지 않는다). 실행마다 만든 시험용 키·자체 서명 인증서(현재·다음·공격자)로
 * SAML 응답을 만들고 서명한다(서명은 시험 쪽 일 — 앱의 검증은 라이브러리). 메타데이터 XML, 그리고 HttpTransport로 메타데이터 주소·SSO 주소를 흉내 낸다.
 *
 * response() 옵션: in_response_to, destination, recipient, audience, issuer, assertion_issuer, name_id, name_id_format, attributes(Name → 값 목록),
 * friendly(Name → FriendlyName), not_before·not_on_or_after·scd_not_on_or_after·session_not_on_or_after(지금 기준 초), assertion_id,
 * sign(assertion|response|both|none), key(current|next|other), sha1, tamper(서명 뒤 속성 값 바꿈), wrap(two|nested — 서명된 어서션 + 위조 어서션),
 * doctype(DOCTYPE·ENTITY 삽입), status(Success가 아니면 그 코드), encrypt_with(SP 인증서 PEM — 어서션 암호화).
 */
final class FakeSamlIdp implements HttpTransport
{
    /** @var array<string, array{key:string, cert:string}> */
    private array $keys = [];
    /** @var list<array{method:string, url:string}> */
    public array $log = [];
    /** @var array<string, \Closure> */
    public array $routes = [];

    public function __construct(
        string $tempDir,
        public readonly string $entityId = 'https://idp.example.test/saml',
        public readonly string $ssoUrl = 'https://idp.example.test/saml/sso',
        public int $now = 0,
        ?string $stateDir = null,
    ) {
        $this->now = $now > 0 ? $now : time();
        $saved = $stateDir !== null ? json_decode((string) @file_get_contents($stateDir . '/saml_keys.json'), true) : null;
        if (is_array($saved)) {
            $this->keys = $saved;
            return;
        }
        @mkdir($tempDir, 0777, true);
        $cnf = $tempDir . '/openssl-saml.cnf';
        file_put_contents($cnf, "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n");
        foreach (['current' => 3650, 'next' => 3650, 'other' => 3650] as $name => $days) {
            $this->keys[$name] = self::makeKey($cnf, 'Fake IdP ' . $name, $days);
        }
        if ($stateDir !== null) {
            file_put_contents($stateDir . '/saml_keys.json', json_encode($this->keys));
        }
    }

    /** @return array{key:string, cert:string} */
    public static function makeKey(string $cnf, string $cn, int $days, int $validFromOffsetDays = 0): array
    {
        $opt = ['config' => $cnf, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $k = openssl_pkey_new($opt);
        $csr = openssl_csr_new(['commonName' => $cn], $k, $opt);
        $x = openssl_csr_sign($csr, null, $k, $days, $opt, random_int(1, PHP_INT_MAX));
        openssl_pkey_export($k, $keyPem, null, $opt);
        openssl_x509_export($x, $certPem);
        while (openssl_error_string() !== false) {
        }
        return ['key' => $keyPem, 'cert' => $certPem];
    }

    /** 설정에 넣을 인증서 값(PEM 본문 base64 한 줄) */
    public function certB64(string $which = 'current'): string
    {
        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $this->keys[$which]['cert']);
    }

    /** 다른 키·인증서로 바꾼다(만료 임박 인증서 시험 등) @param array{key:string, cert:string} $kc */
    public function setKey(string $which, array $kc): void
    {
        $this->keys[$which] = $kc;
    }

    /** IdP 메타데이터 XML(서명 인증서 현재·다음) */
    public function metadataXml(bool $withNext = true): string
    {
        $kd = static fn (string $b64): string => '<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>'
            . $b64 . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>';
        return '<?xml version="1.0"?><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . $this->entityId . '">'
            . '<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            . $kd($this->certB64('current')) . ($withNext ? $kd($this->certB64('next')) : '')
            . '<md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>'
            . '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="' . $this->ssoUrl . '"/>'
            . '</md:IDPSSODescriptor></md:EntityDescriptor>';
    }

    /** AuthnRequest 주소(HTTP-Redirect)에서 요청 ID·RelayState·ACS를 꺼낸다 @return array{id:string, relay:string, acs:string, issuer:string} */
    public static function parseRequest(string $redirectUrl): array
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $q);
        $xml = gzinflate((string) base64_decode((string) $q['SAMLRequest']));
        $doc = new \DOMDocument();
        $doc->loadXML((string) $xml);
        $root = $doc->documentElement;
        $issuer = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0)?->textContent ?? '';
        return ['id' => $root->getAttribute('ID'), 'relay' => (string) ($q['RelayState'] ?? ''), 'acs' => $root->getAttribute('AssertionConsumerServiceURL'), 'issuer' => $issuer,
            'signed' => isset($q['Signature'], $q['SigAlg'])];
    }

    /** @param array<string, mixed> $o */
    public function response(array $o): string
    {
        $t = static fn (int $off): string => gmdate('Y-m-d\TH:i:s\Z', $off);
        $now = $this->now;
        $rid = '_r' . bin2hex(random_bytes(16));
        $aid = (string) ($o['assertion_id'] ?? '_a' . bin2hex(random_bytes(16)));
        $acs = (string) ($o['acs'] ?? '');
        $issuer = (string) ($o['issuer'] ?? $this->entityId);
        $aIssuer = (string) ($o['assertion_issuer'] ?? $issuer);
        $irt = $o['in_response_to'] ?? null;
        $attrs = '';
        foreach ((array) ($o['attributes'] ?? []) as $name => $values) {
            $fn = isset($o['friendly'][$name]) ? ' FriendlyName="' . htmlspecialchars((string) $o['friendly'][$name], ENT_XML1) . '"' : '';
            $attrs .= '<saml:Attribute Name="' . htmlspecialchars((string) $name, ENT_XML1) . '"' . $fn . ' NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic">';
            foreach ((array) $values as $v) {
                $attrs .= '<saml:AttributeValue xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="xs:string">'
                    . htmlspecialchars((string) $v, ENT_XML1) . '</saml:AttributeValue>';
            }
            $attrs .= '</saml:Attribute>';
        }
        $nameIdFormat = (string) ($o['name_id_format'] ?? 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent');
        $irtAttr = $irt === null ? '' : ' InResponseTo="' . $irt . '"';
        $session = isset($o['session_not_on_or_after']) ? ' SessionNotOnOrAfter="' . $t($now + (int) $o['session_not_on_or_after']) . '"' : '';
        $assertion = '<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="' . $aid . '" Version="2.0" IssueInstant="' . $t($now) . '">'
            . '<saml:Issuer>' . htmlspecialchars($aIssuer, ENT_XML1) . '</saml:Issuer>'
            . '<saml:Subject><saml:NameID Format="' . $nameIdFormat . '">' . htmlspecialchars((string) ($o['name_id'] ?? 'nid-default'), ENT_XML1) . '</saml:NameID>'
            . '<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="'
            . $t($now + (int) ($o['scd_not_on_or_after'] ?? 300)) . '" Recipient="' . htmlspecialchars((string) ($o['recipient'] ?? $acs), ENT_XML1) . '"' . $irtAttr . '/></saml:SubjectConfirmation></saml:Subject>'
            . '<saml:Conditions NotBefore="' . $t($now + (int) ($o['not_before'] ?? -30)) . '" NotOnOrAfter="' . $t($now + (int) ($o['not_on_or_after'] ?? 300)) . '">'
            . '<saml:AudienceRestriction><saml:Audience>' . htmlspecialchars((string) ($o['audience'] ?? ''), ENT_XML1) . '</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            . '<saml:AuthnStatement AuthnInstant="' . $t($now) . '"' . $session . ' SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>'
            . 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            . ($attrs === '' ? '' : '<saml:AttributeStatement>' . $attrs . '</saml:AttributeStatement>')
            . '</saml:Assertion>';
        $sign = (string) ($o['sign'] ?? 'assertion');
        $kc = $this->keys[(string) ($o['key'] ?? 'current')];
        $alg = !empty($o['sha1']) ? [XMLSecurityKey::RSA_SHA1, XMLSecurityDSig::SHA1] : [XMLSecurityKey::RSA_SHA256, XMLSecurityDSig::SHA256];
        if ($sign === 'assertion' || $sign === 'both') {
            $assertion = self::sign($assertion, $kc, $alg);
        }
        if (!empty($o['tamper'])) {
            $assertion = (string) preg_replace('/(<saml:AttributeValue[^>]*>)([^<]*)/', '$1$2-tampered', $assertion, 1);
        }
        if (isset($o['encrypt_with'])) {
            $assertion = self::encrypt($assertion, (string) $o['encrypt_with']);
        }
        if (($o['wrap'] ?? null) === 'two') {
            $forged = str_replace(['ID="' . $aid . '"', htmlspecialchars((string) ($o['name_id'] ?? 'nid-default'), ENT_XML1)], ['ID="_forged"', 'attacker'], self::unsigned($assertion));
            $assertion = $forged . $assertion;
        } elseif (($o['wrap'] ?? null) === 'nested') {
            // 위조 어서션을 앞에, 서명된 원래 어서션은 위조 어서션의 Advice 안에(서명 래핑)
            $forged = str_replace('ID="' . $aid . '"', 'ID="_forged"', self::unsigned($assertion));
            $assertion = str_replace('</saml:Issuer>', '</saml:Issuer><saml:Advice>' . $assertion . '</saml:Advice>', $forged);
        }
        $status = (string) ($o['status'] ?? 'Success');
        $dest = array_key_exists('destination', $o) ? $o['destination'] : $acs;
        $destAttr = $dest === null ? '' : ' Destination="' . htmlspecialchars((string) $dest, ENT_XML1) . '"';
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="' . $rid . '" Version="2.0"'
            . ' IssueInstant="' . $t($now) . '"' . $destAttr . $irtAttr . '>'
            . '<saml:Issuer>' . htmlspecialchars($issuer, ENT_XML1) . '</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:' . $status . '"/></samlp:Status>'
            . ($status === 'Success' ? $assertion : '')
            . '</samlp:Response>';
        if ($sign === 'response' || $sign === 'both') {
            $xml = self::sign($xml, $kc, $alg);
        }
        if (!empty($o['doctype'])) {
            $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///c:/windows/win.ini">]>' . self::stripDecl($xml);
        }
        return base64_encode($xml);
    }

    /**
     * 시험 쪽 서명(xmlseclibs): 감싼 서명(enveloped) + 배타적 정규화, 서명은 Issuer 바로 뒤(스키마 순서).
     *
     * @param array{key:string, cert:string} $kc
     * @param array{0:string, 1:string} $alg
     */
    private static function sign(string $xml, array $kc, array $alg): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $root = $dom->documentElement;
        $key = new XMLSecurityKey($alg[0], ['type' => 'private']);
        $key->loadKey($kc['key'], false);
        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReferenceList([$root], $alg[1], ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
        $dsig->sign($key);
        $dsig->add509Cert($kc['cert'], true);
        $issuer = null;
        foreach ($root->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === 'Issuer') {
                $issuer = $n;
                break;
            }
        }
        $dsig->insertSignature($root, $issuer?->nextSibling);
        return (string) $dom->saveXML($root);
    }

    private static function stripDecl(string $xml): string
    {
        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', trim($xml)));
    }

    private static function unsigned(string $assertion): string
    {
        return (string) preg_replace('#<ds:Signature.*</ds:Signature>#s', '', $assertion);
    }

    private static function encrypt(string $assertionXml, string $spCertPem): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($assertionXml);
        $sym = new XMLSecurityKey(XMLSecurityKey::AES128_CBC);
        $sym->generateSessionKey();
        $pub = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type' => 'public']);
        $pub->loadKey($spCertPem, false, true);
        $enc = new XMLSecEnc();
        $enc->setNode($doc->documentElement);
        $enc->type = XMLSecEnc::Element;
        $enc->encryptKey($pub, $sym);
        $encData = $enc->encryptNode($sym, false);
        return '<saml:EncryptedAssertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">' . $encData->ownerDocument->saveXML($encData) . '</saml:EncryptedAssertion>';
    }

    public function send(string $method, string $url, string $pinnedIp, array $headers, string $body, int $maxBytes, int $connectTimeout, int $totalTimeout): array
    {
        $this->log[] = ['method' => $method, 'url' => $url];
        if (isset($this->routes[$url])) {
            $res = ($this->routes[$url])($method, $headers, $body) + ['headers' => []];
        } elseif ($url === 'https://idp.example.test/saml/metadata') {
            $res = ['status' => 200, 'headers' => ['content-type' => 'application/samlmetadata+xml'], 'body' => $this->metadataXml()];
        } elseif ($url === $this->ssoUrl) {
            $res = ['status' => 400, 'headers' => [], 'body' => 'missing SAMLRequest'];
        } else {
            $res = ['status' => 404, 'headers' => [], 'body' => ''];
        }
        if (strlen($res['body']) > $maxBytes) {
            throw new \PJGuardian\Services\Auth\Oidc\OidcException('too_large');
        }
        return $res;
    }
}
