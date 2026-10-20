<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigSchema;

/** LDAP 설정 값(P28B, [ldap] — 예전 키 server_url·attr_user_id·ca_cert_file은 새 키가 비었을 때 대신) */
final class LdapSettings
{
    /**
     * @param list<string> $servers
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $servers,
        public readonly bool $startTls,
        public readonly string $caFile,
        public readonly string $baseDn,
        public readonly string $userFilter,
        public readonly string $bindDn,
        public readonly string $attrLogin,
        public readonly string $attrName,
        public readonly string $attrEmail,
        public readonly string $attrId,
        public readonly string $attrDisabled,
        public readonly int $connectTimeout,
        public readonly int $searchTimeout,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        $raw = [];
        foreach (['enabled', 'servers', 'server_url', 'use_starttls', 'ca_file', 'ca_cert_file', 'base_dn', 'user_filter', 'bind_dn',
            'attr_login', 'attr_user_id', 'attr_name', 'attr_email', 'attr_id', 'attr_disabled'] as $k) {
            $raw[$k] = $c->get('ldap.' . $k);
        }
        $login = trim((string) $raw['attr_login']);
        if ($login === '' && is_string($raw['attr_user_id']) && $raw['attr_user_id'] !== '') {
            $login = $raw['attr_user_id'];
        }
        return new self(
            (bool) $raw['enabled'], ConfigSchema::ldapServers($raw), (bool) $raw['use_starttls'], ConfigSchema::ldapCaFile($raw),
            trim((string) $raw['base_dn']), (string) $raw['user_filter'], trim((string) $raw['bind_dn']),
            $login, trim((string) $raw['attr_name']), trim((string) $raw['attr_email']), trim((string) $raw['attr_id']), trim((string) $raw['attr_disabled']),
            $c->int('ldap.connect_timeout'), $c->int('ldap.search_timeout'),
        );
    }

    /** 로그인에 쓸 수 있을 만큼 채워졌는가(검색 계정 비밀번호는 따로) */
    public function complete(): bool
    {
        return $this->servers !== [] && $this->baseDn !== '' && $this->attrLogin !== '' && $this->attrId !== '';
    }

    /** @return list<string> 검색 때 읽을 속성(빈 이름 제외) */
    public function attributes(): array
    {
        return array_values(array_unique(array_filter([$this->attrLogin, $this->attrName, $this->attrEmail, $this->attrId, $this->attrDisabled], static fn (string $a): bool => $a !== '')));
    }
}
