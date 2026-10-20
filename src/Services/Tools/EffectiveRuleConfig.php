<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * 실효 설정 만들기(P22B, 한 곳): 서버 기본 설정(파일, 읽기만) + 관리자 규칙 변경(override) → 도구가 읽는 설정.
 *
 * override 집합 = ['enable' => [규칙…], 'disable' => [규칙…]](RuleOverrides::snapshotFor가 활성 버전에 있고 켤 수 있는 것만 남긴 값).
 * 같은 입력이면 같은 출력(정렬·중복 제거) — 설정 해시가 안정적이다. 비어 있으면 null(= 지금과 같은 실행·같은 해시).
 *
 * - ruff: 작업 폴더(임시)에 `extend = "<기본 설정 절대 경로>"` + `[lint] extend-select = […]`, `ignore = […]` 파일을 만들어 `--config`로 넘긴다.
 *   ruff는 설정 사슬을 층마다 적용하므로(기본 → 이 파일) 기본의 접두사 선택·ignore보다 이 파일의 개별 코드가 이긴다
 *   (실측 0.16.10: E 아래 E501 끄기, 꺼진 D 계열의 D100 켜기, ignore = ["E5"] 위 E501 켜기, select = ["E501"] 끄기 — docs/results/P22B.md 0절).
 * - ESLint: 규칙 → "off" | "error"(추천 설정과 같은 수준. 결함 심각도는 P21S 매핑이 정하므로 수준은 켜짐 여부에만 쓰인다).
 *   tools/eslint/pjg.flat.overrides.config.cjs가 기본 설정 맨 뒤에 얹는다(TS 규칙은 TS 파일 설정에만).
 */
final class EffectiveRuleConfig
{
    public const RUFF_FILE = 'pjg-ruff-effective.toml';
    private const RUFF_CODE = '/^[A-Z]{1,8}[0-9]{1,6}$/';
    private const ESLINT_NAME = '#^(@typescript-eslint/)?[a-z][a-z0-9-]{0,99}$#';

    /**
     * 정렬·검사한 override 집합. 비면 null.
     *
     * @param array{enable?: list<string>, disable?: list<string>}|null $set
     * @return array{enable: list<string>, disable: list<string>}|null
     */
    public static function canonical(string $tool, ?array $set): ?array
    {
        if ($set === null) {
            return null;
        }
        $pattern = $tool === 'ruff' ? self::RUFF_CODE : self::ESLINT_NAME;
        $clean = static function (array $list) use ($pattern): array {
            $out = array_values(array_unique(array_filter($list, static fn ($r): bool => is_string($r) && preg_match($pattern, $r) === 1)));
            sort($out, SORT_STRING);
            return $out;
        };
        $enable = $clean($set['enable'] ?? []);
        $disable = array_values(array_diff($clean($set['disable'] ?? []), $enable));
        return $enable === [] && $disable === [] ? null : ['enable' => $enable, 'disable' => $disable];
    }

    /**
     * ruff 실효 설정 파일 내용. 기본 설정은 extend로 그대로 읽는다(내용을 복사하지 않음).
     *
     * @param array{enable: list<string>, disable: list<string>} $set canonical()
     */
    public static function ruffToml(string $baseConfig, array $set): string
    {
        $str = static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
        $list = static fn (array $xs): string => '[' . implode(', ', array_map($str, $xs)) . ']';
        $base = str_replace('\\', '/', (string) (realpath($baseConfig) ?: $baseConfig));
        return "# PJGuardian 실효 설정(P22B, 서버가 분석마다 만든 임시 파일): 서버 기본 설정 + 관리자 규칙 변경\n"
            . 'extend = ' . $str($base) . "\n\n[lint]\n"
            . 'extend-select = ' . $list($set['enable']) . "\n"
            . 'ignore = ' . $list($set['disable']) . "\n";
    }

    /**
     * ESLint 규칙 변경(이름 → "error" | "off", 이름순)
     *
     * @param array{enable: list<string>, disable: list<string>} $set canonical()
     * @return array<string, string>
     */
    public static function eslintRules(array $set): array
    {
        $out = [];
        foreach ($set['enable'] as $r) {
            $out[$r] = 'error';
        }
        foreach ($set['disable'] as $r) {
            $out[$r] = 'off';
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
