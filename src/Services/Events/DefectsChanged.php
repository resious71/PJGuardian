<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/**
 * 사람의 결함 수동 변경 한 번(단건 또는 일괄 요청 하나, P24). 커밋 뒤 DefectUpdate가 결함별 이벤트(DefectAssigned·DefectStatusChanged)와 함께
 * 요청마다 하나 보낸다 — 알림이 받는 사람별로 묶을 수 있게. 자동 전환(분석)은 이 이벤트가 아니다.
 *
 * items: 실제로 바뀐 결함만 [{id, diff: {field: [old, new]}, assignee: 변경 뒤 담당자|null, history: {field: defect_history.id}}]
 */
final class DefectsChanged
{
    /** @param list<array{id:int, diff:array<string, array{0:mixed, 1:mixed}>, assignee:?int, history:array<string, int>}> $items */
    public function __construct(
        public readonly int $projectId,
        public readonly int $changedBy,
        public readonly array $items,
    ) {
    }
}
