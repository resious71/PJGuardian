<?php

declare(strict_types=1);

namespace PJGuardian\Services\Analysis;

/**
 * 두 성공 분석 사이에 **분석 조건이 바뀌었는지** 판정한다(P20 대시보드 추이의 변경 표식, P21 히스토리 비교가 같은 함수를 쓴다).
 *
 * 바뀜 = 도구 이름·도구 버전 문자열(ESLint는 "10.11.0 (Node 24.21.0)"처럼 Node 버전 포함)·설정 출처(upload / server_default)·
 * 설정 해시 중 하나라도 다름. 값이 없는(null) 쪽은 그 항목을 "모름"으로 보고 비교하지 않는다(옛 분석 행).
 * 이전 분석이 없으면(첫 분석) 바뀌지 않은 것으로 본다(표식 없음).
 */
final class AnalysisComparison
{
    public const FIELDS = ['tool', 'tool_version', 'config_source', 'config_hash'];

    /**
     * @param array<string, mixed>|null $previous analyses 행(또는 같은 키를 가진 배열). null = 첫 분석
     * @param array<string, mixed> $current
     */
    public static function changedBetween(?array $previous, array $current): bool
    {
        return self::differences($previous, $current) !== [];
    }

    /**
     * 바뀐 항목과 이전·이후 값.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     * @return array<string, array{from: ?string, to: ?string}>
     */
    public static function differences(?array $previous, array $current): array
    {
        if ($previous === null) {
            return [];
        }
        $out = [];
        foreach (self::FIELDS as $f) {
            $a = $previous[$f] ?? null;
            $b = $current[$f] ?? null;
            if ($a === null || $b === null || $a === '' || $b === '') {
                continue;
            }
            if ((string) $a !== (string) $b) {
                $out[$f] = ['from' => (string) $a, 'to' => (string) $b];
            }
        }
        return $out;
    }
}
