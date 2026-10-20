<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/**
 * 분석 성공(결함 반영 커밋 뒤). stats: total, new, resolved, reopened, unchanged, baseline …
 * assignedChanges(P24): 담당자가 있는 결함의 자동 전환 [{defect_id, assignee_id, change: reopened|resolved, reason}] — 알림 요약(analysis_summary)이
 * 사용자별 건수를 결함 표를 다시 훑지 않고 구한다. 같은 자료를 AssignedDefectsChanged로도 보낸다.
 */
final class AnalysisSucceeded
{
    /**
     * @param array<string, mixed> $stats
     * @param list<array{defect_id:int, assignee_id:int, change:string, reason:string}> $assignedChanges
     */
    public function __construct(
        public readonly int $analysisId,
        public readonly int $projectId,
        public readonly array $stats,
        public readonly array $assignedChanges = [],
    ) {
    }
}
