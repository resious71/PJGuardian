<?php

declare(strict_types=1);

namespace PJGuardian\Services\Events;

/** 분석 실패(도구 실패, 결과 저장 실패, 타임아웃, 워커 종료 등). 결함은 바뀌지 않았다 */
final class AnalysisFailed
{
    public function __construct(public readonly int $analysisId, public readonly int $projectId, public readonly string $failureCode)
    {
    }
}
