<?php

declare(strict_types=1);

namespace PJGuardian\Services\Analysis;

/**
 * 분석 결과. 성공이면 통계(analyses의 *_count, severity_counts)와 도구 정보, 실패면 실패 코드.
 * 실패 코드의 사용자 문구는 문자열 리소스 analysis.failure.<코드>(없으면 analysis.failure.unknown).
 */
final class AnalysisOutcome
{
    /**
     * @param array{total_count:int, new_count:int, resolved_count:int, reopened_count:int, unchanged_count:int}|null $counts
     * @param array<string, mixed> $tool tool_version, config_source, config_hash, ignored_configs(P15)
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $failureCode,
        public readonly ?string $detail,
        public readonly ?array $counts,
        public readonly array $tool,
    ) {
    }

    /**
     * @param array{total_count:int, new_count:int, resolved_count:int, reopened_count:int, unchanged_count:int} $counts
     * @param array<string, mixed> $tool
     */
    public static function succeeded(array $counts, array $tool = []): self
    {
        return new self(true, null, null, $counts, $tool);
    }

    /** @param string|null $detail 로그용 상세(사용자에게 보이지 않는다. 내부 경로·예외 메시지를 넣어도 화면에는 안 나감) */
    public static function failed(string $code, ?string $detail = null, array $tool = []): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw new \InvalidArgumentException('실패 코드 형식 오류');
        }
        return new self(false, $code, $detail, null, $tool);
    }
}
