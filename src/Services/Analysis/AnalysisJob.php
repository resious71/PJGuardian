<?php

declare(strict_types=1);

namespace PJGuardian\Services\Analysis;

/**
 * 분석 실행기에 넘기는 작업 정보(분석 행에서 만든다). workPath는 해제된 소스가 있는 작업 디렉토리의 절대 경로
 * (data/work/<8자리 16진수>), 없으면 null(저장소 가져오기 P25가 채운다).
 */
final class AnalysisJob
{
    /** @param array<string, mixed> $row analyses 행 그대로(P15·P16이 필요한 열을 읽는다) */
    public function __construct(
        public readonly int $analysisId,
        public readonly int $projectId,
        public readonly string $tool,
        public readonly ?string $workPath,
        public readonly array $row,
    ) {
    }
}
