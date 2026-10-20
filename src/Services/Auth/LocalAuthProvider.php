<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/** 로컬 계정: bcrypt 해시 검증(명세 3) */
final class LocalAuthProvider implements AuthProvider
{
    public function type(): string
    {
        return 'local';
    }

    public function verify(array $user, string $password): bool
    {
        $hash = $user['password_hash'] ?? null;
        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    public function appliesLockout(): bool
    {
        return true;
    }
}
