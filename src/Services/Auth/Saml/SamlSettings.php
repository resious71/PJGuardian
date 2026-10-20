<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Saml;

use OneLogin\Saml2\Constants;
use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigSchema;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * SAML 설정 값(P28D, [saml]) → onelogin/php-saml 설정 배열. 공개 주소는 OIDC와 같은 규칙(oidc.redirect_base, 비우면 app.base_url — Host 헤더 아님).
 * 라이브러리 보안 옵션은 여기 한 곳에서 정하고 완화하지 않는다: strict, 스키마 검사, 응답·어서션 중 하나 이상 서명(라이브러리 기본 — 서명 없으면 거부),
 * IdP 시작(요청 ID 없는 InResponseTo) 거부, 요청 서명·암호화 요구는 설정, 서명·다이제스트는 SHA-256, 인증 컨텍스트 강제 안 함(ADFS Windows 인증 호환).
 */
final class SamlSettings
{
    public const NAMEID_FORMATS = [
        'persistent' => Constants::NAMEID_PERSISTENT,
        'unspecified' => Constants::NAMEID_UNSPECIFIED,
        'emailAddress' => Constants::NAMEID_EMAIL_ADDRESS,
        'transient' => Constants::NAMEID_TRANSIENT,
    ];

    public function __construct(
        public readonly bool $enabled,
        public readonly string $idpEntityId,
        public readonly string $idpSsoUrl,
        public readonly string $idpCert,
        public readonly string $idpCertNext,
        public readonly string $spEntityIdSetting,
        public readonly string $nameIdFormat,
        public readonly string $idSource,
        public readonly string $attrLogin,
        public readonly string $attrName,
        public readonly string $attrEmail,
        public readonly bool $signRequests,
        public readonly bool $wantEncrypted,
        public readonly string $buttonLabel,
        public readonly string $publicBase,
        public readonly int $startPerMinute,
        public readonly int $testPerMinute,
        public readonly bool $allowLocalDev,
        public readonly int $certWarnSeconds,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        $base = trim($c->string('oidc.redirect_base'));
        if ($base === '') {
            $base = $c->string('app.base_url');
        }
        return new self(
            $c->bool('saml.enabled'), trim($c->string('saml.idp_entity_id')), trim($c->string('saml.idp_sso_url')),
            trim($c->string('saml.idp_cert')), trim($c->string('saml.idp_cert_next')), trim($c->string('saml.sp_entity_id')),
            $c->string('saml.name_id_format'), trim($c->string('saml.id_source')), trim($c->string('saml.attr_login')),
            trim($c->string('saml.attr_name')), trim($c->string('saml.attr_email')), $c->bool('saml.sign_requests'), $c->bool('saml.want_encrypted'),
            trim($c->string('saml.button_label')), rtrim($base, '/'), $c->int('saml.start_per_minute'), $c->int('saml.test_per_minute'),
            $c->bool('saml.allow_local_dev'), $c->int('notification.cert_expiry_warn'),
        );
    }

    /** 서명 검증 라이브러리(onelogin/php-saml, Composer vendor/)가 있는가 — 없으면 SAML은 꺼진 것처럼(점검 화면에 이유) */
    public static function libraryAvailable(): bool
    {
        return class_exists(\OneLogin\Saml2\Auth::class) && class_exists(\RobRichards\XMLSecLibs\XMLSecurityDSig::class);
    }

    /** 로그인에 쓸 수 있을 만큼 채워졌는가(SP 키는 따로) */
    public function complete(): bool
    {
        return $this->idpEntityId !== '' && $this->idpSsoUrl !== '' && $this->idpCert !== '' && $this->attrLogin !== '';
    }

    /** SP 키가 필요한가(요청 서명 또는 암호화된 어서션) */
    public function needsSpKey(): bool
    {
        return $this->signRequests || $this->wantEncrypted;
    }

    public function spEntityId(): string
    {
        return $this->spEntityIdSetting !== '' ? $this->spEntityIdSetting : $this->publicBase . '/auth/saml/metadata';
    }

    public function acsUrl(): string
    {
        return $this->publicBase . '/auth/saml/acs';
    }

    public function metadataUrl(): string
    {
        return $this->publicBase . '/auth/saml/metadata';
    }

    /** @return list<array{which:string, b64:string}> 등록된 IdP 인증서(현재·다음) */
    public function idpCerts(): array
    {
        $out = [];
        foreach (['current' => $this->idpCert, 'next' => $this->idpCertNext] as $which => $b64) {
            if ($b64 !== '') {
                $out[] = ['which' => $which, 'b64' => $b64];
            }
        }
        return $out;
    }

    /**
     * onelogin/php-saml 설정 배열.
     *
     * @param array{key:string, cert:string}|null $sp SP 개인 키·인증서(PEM, 필요할 때만)
     * @return array<string, mixed>
     */
    public function library(?array $sp): array
    {
        $certs = [];
        foreach ($this->idpCerts() as $c) {
            $info = ConfigSchema::samlCertInfo($c['b64']);
            if ($info === null) {
                throw new SamlException('cert_invalid');
            }
            $certs[] = $info['pem'];
        }
        if ($certs === []) {
            throw new SamlException('not_configured');
        }
        $spData = [
            'entityId' => $this->spEntityId(),
            'assertionConsumerService' => ['url' => $this->acsUrl(), 'binding' => Constants::BINDING_HTTP_POST],
            'NameIDFormat' => self::NAMEID_FORMATS[$this->nameIdFormat] ?? Constants::NAMEID_PERSISTENT,
        ];
        if ($sp !== null) {
            $spData['x509cert'] = $sp['cert'];
            $spData['privateKey'] = $sp['key'];
        }
        return [
            'strict' => true,
            'debug' => false,
            'sp' => $spData,
            'idp' => [
                'entityId' => $this->idpEntityId,
                'singleSignOnService' => ['url' => $this->idpSsoUrl, 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert' => $certs[0],
                // 롤오버: 현재·다음 인증서 중 하나로 서명돼 있으면 통과(인증서 전체로 검증 — 지문만으로 검증하지 않음)
                'x509certMulti' => ['signing' => $certs],
            ],
            'security' => [
                'authnRequestsSigned' => $this->signRequests,
                'wantAssertionsEncrypted' => $this->wantEncrypted,
                'wantNameIdEncrypted' => false,
                'wantMessagesSigned' => false,
                'wantAssertionsSigned' => false,
                'wantNameId' => $this->idSource === 'nameid',
                'wantXMLValidation' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'relaxDestinationValidation' => false,
                'destinationStrictlyMatches' => false, // 앱이 정확히 같은지 따로 확인(SamlLogin)
                'allowRepeatAttributeName' => false,
                'requestedAuthnContext' => false,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm' => XMLSecurityDSig::SHA256,
                'signMetadata' => false,
                'lowercaseUrlencoding' => false,
            ],
        ];
    }
}
