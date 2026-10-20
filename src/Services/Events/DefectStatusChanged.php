<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/**
 * 사용자가 결함 상태를 바꿈(P18). 수동 변경이 커밋된 뒤 결함마다 하나. 자동 전환(분석)은 AssignedDefectsChanged(P16).
 * 지금은 구독자가 없다(P24가 필요하면 구독: 예 담당자가 아닌 사람이 상태를 바꿨을 때 담당자에게 알림).
 */
final class DefectStatusChanged
{
    public function __construct(
        public readonly int $defectId,
        public readonly int $projectId,
        public readonly string $from,
        public readonly string $to,
        public readonly int $changedBy,
        public readonly ?int $assigneeId,
    ) {
    }
}
