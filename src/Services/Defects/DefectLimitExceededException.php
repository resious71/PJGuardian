<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

/**
 * 반영하면 프로젝트 결함이 [limits] project_defect_max를 넘는 것이 분명함(P19C). 쓰기 트랜잭션 전에 던진다 — 결함·이전 분석 결과는 그대로.
 * 분석 자식(tools/run_analysis.php)이 분석 실패 defect_limit_exceeded로 기록한다.
 */
final class DefectLimitExceededException extends \RuntimeException
{
    public function __construct(public readonly int $count, public readonly int $max)
    {
        parent::__construct("결함 {$count}건 > 상한 {$max}건");
    }
}
