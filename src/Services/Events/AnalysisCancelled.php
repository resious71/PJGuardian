<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/** 분석 취소(P24): 대기 중 취소(API) 또는 실행 중 취소(워커가 자식 종료 뒤 cancelled 기록). 결함은 바뀌지 않았다 */
final class AnalysisCancelled
{
    public function __construct(public readonly int $analysisId, public readonly int $projectId)
    {
    }
}
