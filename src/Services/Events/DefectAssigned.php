<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/**
 * 담당자가 지정됨(P18, 명세 17 "담당자로 지정됨" 알림). 사용자의 수동 변경(단건·일괄)이 커밋된 뒤 결함마다 하나.
 * 담당자 해제(null)는 이 이벤트가 아니다. P24 알림이 구독하며, 보낼 때 담당자가 그 프로젝트에 접근할 수 있는지
 * (ProjectAccess::userCanView) 다시 확인한다(접근을 잃은 담당자는 알림 대상에서 뺀다, 명세 3.1).
 */
final class DefectAssigned
{
    public function __construct(
        public readonly int $defectId,
        public readonly int $projectId,
        public readonly int $assigneeId,
        public readonly ?int $previousAssigneeId,
        public readonly int $changedBy,
    ) {
    }
}
