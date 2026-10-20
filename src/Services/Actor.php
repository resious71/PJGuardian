<?php

declare(strict_types=1);

namespace PJGuardian\Services;

/** 작업 행위자(감사 로그의 actor). 웹 요청은 로그인한 사용자, CLI는 'cli'. */
final class Actor
{
    private function __construct(
        public readonly ?int $userId,
        public readonly string $label,
        public readonly ?string $ip,
    ) {
    }

    /** @param array<string, mixed> $user users 행 또는 공개 정보 */
    public static function fromUser(array $user, ?string $ip): self
    {
        return new self((int) $user['id'], (string) $user['login_id'], $ip === '' ? null : $ip);
    }

    /** 서버가 스스로 한 작업(P21B: Run 상세 자동 보관 — 워커가 분석 사이에) */
    public static function system(): self
    {
        return new self(null, 'system', null);
    }

    /** 서버 CLI(설치 스크립트, 잠금 해제·비밀번호 재설정, 명세 13) */
    public static function cli(): self
    {
        return new self(null, 'cli', null);
    }

    /** 사용자 id 없이 이름표만(P27B 복원: 복원 전 사용자는 복원된 DB에 없을 수 있다 — 예 "restore:admin") */
    public static function labeled(string $label): self
    {
        return new self(null, mb_substr($label, 0, 100), null);
    }
}
