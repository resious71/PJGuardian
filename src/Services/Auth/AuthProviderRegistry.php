<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/**
 * 인증 방식 목록 한 곳(P28A). 계정은 인증 방식을 하나 가진다(local | ldap | oidc | saml, 사용자 결정 2026-10-10).
 * CSV 일괄 처리의 auth_type 검증과 사용자 생성이 이 목록을 쓴다 — P28B(LDAP/AD)·P28C(OIDC)·P28D(SAML)가
 * enabledTypes()에 항목을 더하고 화면 라벨(account.auth_<방식>, ko·en)을 두면 CSV가 그 값을 자동으로 받아들인다.
 * 이 단계에서는 local만 켜져 있다.
 */
final class AuthProviderRegistry
{
    /** 이 앱이 아는 인증 방식(켜지지 않은 값은 "아직 켜지지 않은 인증 방식", 모르는 값은 "인증 방식 형식 오류") */
    public const KNOWN = ['local', 'ldap', 'oidc', 'saml'];

    /** @param list<string> $enabled 켜진 방식(기본: local만) */
    public function __construct(private readonly array $enabled = ['local'])
    {
    }

    /** @return list<string> 지금 켜진 인증 방식(첫 값이 기본) */
    public function enabledTypes(): array
    {
        return array_values(array_intersect(self::KNOWN, $this->enabled));
    }

    public function isEnabled(string $type): bool
    {
        return in_array($type, $this->enabledTypes(), true);
    }

    public function isKnown(string $type): bool
    {
        return in_array($type, self::KNOWN, true);
    }

    /** 기본 인증 방식(CSV에서 비운 칸) */
    public function defaultType(): string
    {
        return $this->enabledTypes()[0] ?? 'local';
    }
}
