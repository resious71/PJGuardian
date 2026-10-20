<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/**
 * 담당자가 있는 결함의 자동 전환(분석 한 번에 한 이벤트). changes: [{defect_id, assignee_id, change: resolved|reopened, reason}]
 * P24 알림이 담당자별로 모아 보낸다.
 */
final class AssignedDefectsChanged
{
    /** @param list<array{defect_id:int, assignee_id:int, change:string, reason:string}> $changes */
    public function __construct(public readonly int $analysisId, public readonly int $projectId, public readonly array $changes)
    {
    }
}
