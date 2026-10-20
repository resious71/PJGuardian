<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/**
 * 인증 제공자. 계정의 auth_type(local | ldap)마다 하나다.
 * 지금은 LocalAuthProvider만 있다. LDAP/AD(명세 19.2)는 P28에서 같은 인터페이스로 추가한다:
 *   - verify(): 디렉터리에 바인드해 확인
 *   - appliesLockout(): false(잠금은 디렉터리 정책)
 *   - 앱에 없는 계정의 자동 생성은 P28이 LoginService에 추가한다.
 */
interface AuthProvider
{
    /** users.auth_type 값 */
    public function type(): string;

    /**
     * 비밀번호 확인.
     *
     * @param array<string, mixed> $user users 행
     */
    public function verify(array $user, string $password): bool;

    /** 앱의 계정 잠금(명세 3: 5회 실패 15분)과 비밀번호 정책을 적용하는가 */
    public function appliesLockout(): bool;
}
