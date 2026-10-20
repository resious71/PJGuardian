<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

use PJGuardian\Support\Config;

/** 인증 관련 설정값([auth], 명세 3·19.5). 기간은 초. */
final class AuthSettings
{
    public function __construct(
        public readonly int $passwordMinLength,
        public readonly int $passwordMinClasses,
        public readonly int $lockoutMaxFailures,
        public readonly int $lockoutDuration,
        public readonly int $ipFailureWindow,
        public readonly int $ipMaxFailures,
        public readonly int $ipBlockDuration,
        public readonly int $sessionIdle,
        public readonly int $sessionTouchInterval,
        public readonly int $tempPasswordExpiry,
        public readonly int $bcryptCost,
        public readonly bool $secureCookie,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        return new self(
            $c->int('auth.password_min_length'),
            $c->int('auth.password_min_char_classes'),
            $c->int('auth.lockout_max_failures'),
            $c->int('auth.lockout_duration'),
            $c->int('auth.ip_failure_window'),
            $c->int('auth.ip_max_failures'),
            $c->int('auth.ip_block_duration'),
            $c->int('auth.session_idle'),
            $c->int('auth.session_touch_interval'),
            $c->int('auth.temp_password_expiry'),
            $c->int('auth.bcrypt_cost'),
            // 명세 3: 세션 쿠키는 항상 Secure. HTTPS 강제를 끈 개발 환경(HTTP 접속)에서만 생략한다
            $c->bool('security.require_https'),
        );
    }

    public function passwordPolicy(): PasswordPolicy
    {
        return new PasswordPolicy($this->passwordMinLength, $this->passwordMinClasses);
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }
}
