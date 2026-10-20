<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/** sessions 행. idHash는 쿠키 값(세션 ID)의 SHA-256이다(원래 ID는 서버에 남기지 않음). */
final class Session
{
    public function __construct(
        public readonly string $idHash,
        public readonly ?int $userId,
        public readonly string $csrfToken,
        public readonly string $createdAt,
        public readonly string $lastActivityAt,
        /** 다음 화면에 한 번 보여 줄 알림(JSON 객체 문자열, 마이그레이션 004). 없으면 null */
        public readonly ?string $flash = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id_hash'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['csrf_token'],
            (string) $row['created_at'],
            (string) $row['last_activity_at'],
            isset($row['flash']) ? (string) $row['flash'] : null,
        );
    }
}
