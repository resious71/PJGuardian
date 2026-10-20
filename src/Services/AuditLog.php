<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Support\AuditChain;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;

/**
 * 감사 로그(명세 10, 19.7): 관리자 작업을 해시 체인으로 추가한다. 수정·삭제는 DB 트리거가 거부한다.
 * 세부 정보(details)에 비밀번호·토큰·해시를 넣지 않는다(키 이름이 민감하면 AuditChain이 한 번 더 마스킹).
 *
 * 작업 이름(action) 목록: Support\AuditActions(화면 선택지·docs/audit_actions.md). 조회는 Services\Audit\AuditQuery, 보존 정리는 AuditRetention(P30).
 */
final class AuditLog
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $details */
    public function record(Actor $actor, string $action, ?string $targetType = null, ?string $targetId = null, array $details = []): int
    {
        return AuditChain::append($this->db, $this->clock, $actor->userId, $actor->label, $action, $targetType, $targetId, $details, $actor->ip);
    }

    /** @return array{ok:bool, checked:int, broken_at:?int, reason:?string} */
    public function verify(): array
    {
        return AuditChain::verify($this->db);
    }
}
