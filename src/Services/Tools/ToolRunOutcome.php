<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * 도구 한 번 실행한 결과. 성공이면 결과 NDJSON 파일과 건수, 실패면 실패 코드(tool_crash, tool_config_error, timeout,
 * output_limit, memory_limit, tool_unavailable). 어느 쪽이든 도구 버전·설정 출처·설정 해시·무시한 설정을 담는다.
 */
final class ToolRunOutcome
{
    /**
     * @param list<array{file:string, reason:string, detail?:string, count?:int}> $ignored
     * @param array{written:int, syntax:int, skipped:array<string,int>, by_rule:array<string,int>}|null $stats
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $failureCode,
        public readonly ?string $detail,
        public readonly string $toolLabel,
        public readonly string $configSource,
        public readonly string $configHash,
        public readonly array $ignored,
        public readonly ?array $stats = null,
        public readonly ?string $resultFile = null,
        public readonly ?string $scopeFile = null,
        public readonly int $scopeCount = 0,
    ) {
    }

    /** @return array<string, mixed> AnalysisOutcome의 도구 정보(analyses 열) */
    public function toolInfo(): array
    {
        return [
            'tool_version' => $this->toolLabel,
            'config_source' => $this->configSource,
            'config_hash' => $this->configHash,
            'ignored_configs' => $this->ignored,
        ];
    }
}
