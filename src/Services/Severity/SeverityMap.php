<?php

declare(strict_types=1);

namespace PJGuardian\Services\Severity;

use PJGuardian\Services\Defects\DefectFilter;

/**
 * 규칙(결함 유형)별 기본 심각도(P21S, docs/severity_mapping.md). 데이터는 resources/severity/의 파일이며 코드에 매핑을 두지 않는다.
 *   <도구>.json          사람이 고치는 매핑: mapping_version, defaults(규칙 분류군 → 심각도), prefixes, rules
 *   <도구>.catalog.json  도구가 제공하는 규칙 목록과 분류군(ruff: category, ESLint: meta.type). tools/severity_coverage.php --write-catalog가 만든다
 *
 * 우선순위: 개별 규칙 > 접두사(가장 긴 것) > 분류군 기본 > not_defined. 규칙 키 null·"syntax-error"는 그 도구 매핑의 "syntax-error" 항목.
 * ruff 코드는 대문자로 맞춘다(ESLint 규칙 이름은 대소문자 그대로). ruff 접두사가 글자로 끝나면 다음 글자가 숫자여야 맞는다
 * (D는 D100에 맞고 DJ003·DTZ001에는 맞지 않는다).
 *
 * 파일은 처음 쓸 때 한 번 읽고 이 객체(요청·워커 프로세스 수명)에 둔다. 결함마다 DB를 조회하지 않는다. 형식이 틀리면 SeverityMapException.
 */
final class SeverityMap
{
    public const TOOLS = ['ruff', 'eslint'];
    public const SYNTAX = 'syntax-error';

    /** @var array<string, array{version:int, defaults:array<string, array{severity:string, reason:string}>, prefixes:array<string, array{severity:string, reason:string}>, rules:array<string, array{severity:string, reason:string}>, catalog:array<string, array<string, mixed>>}> */
    private array $maps = [];
    /** @var array<string, array{severity:string, level:string, reason:string, group:?string}> */
    private array $memo = [];

    public function __construct(private readonly string $dir)
    {
    }

    /** 규칙 키("ruff:F401", "eslint:no-undef", "syntax-error", null) → 기본 심각도 */
    public function forRule(string $tool, ?string $ruleKey): string
    {
        return $this->explain($tool, $ruleKey)['severity'];
    }

    /**
     * 기본 심각도와 근거.
     *
     * @return array{severity:string, level:string, reason:string, group:?string} level: rule | prefix | group | none
     */
    public function explain(string $tool, ?string $ruleKey): array
    {
        $key = $tool . "\0" . ($ruleKey ?? '');
        return $this->memo[$key] ??= $this->resolve($tool, $ruleKey);
    }

    /** 매핑 버전(이력 메모 "매핑 v<버전>") */
    public function version(string $tool): int
    {
        return $this->map($tool)['version'];
    }

    /** "ruff v1, eslint v1" */
    public function versionLabel(): string
    {
        return implode(', ', array_map(fn (string $t): string => $t . ' v' . $this->version($t), self::TOOLS));
    }

    /** 두 도구의 파일을 모두 읽어 검증한다(시작 시 점검). 틀리면 예외 */
    public function validate(): void
    {
        foreach (self::TOOLS as $t) {
            $this->map($t);
        }
    }

    /** @return array<string, array<string, mixed>> 규칙 카탈로그(규칙 → name·group 등) */
    public function catalog(string $tool): array
    {
        return $this->map($tool)['catalog'];
    }

    /** @return list<string> 매핑 파일의 개별 규칙 키 */
    public function ruleKeys(string $tool): array
    {
        return array_keys($this->map($tool)['rules']);
    }

    /** 규칙 키 → 도구 안 규칙 이름("ruff:f401" → "F401", "eslint:no-undef" → "no-undef"). 다른 도구 접두사면 그대로 */
    public static function ruleName(string $tool, string $ruleKey): string
    {
        $name = str_starts_with($ruleKey, $tool . ':') ? substr($ruleKey, strlen($tool) + 1) : $ruleKey;
        return $tool === 'ruff' && $name !== self::SYNTAX ? strtoupper($name) : $name;
    }

    /** @return array{severity:string, level:string, reason:string, group:?string} */
    private function resolve(string $tool, ?string $ruleKey): array
    {
        $m = $this->map($tool);
        $name = $ruleKey === null || $ruleKey === self::SYNTAX ? self::SYNTAX : self::ruleName($tool, $ruleKey);
        $group = isset($m['catalog'][$name]['group']) ? (string) $m['catalog'][$name]['group'] : null;
        if (isset($m['rules'][$name])) {
            return $m['rules'][$name] + ['level' => 'rule', 'group' => $group];
        }
        $best = null;
        foreach ($m['prefixes'] as $prefix => $v) {
            if (self::prefixMatches($tool, (string) $prefix, $name) && ($best === null || strlen((string) $prefix) > strlen($best))) {
                $best = (string) $prefix;
            }
        }
        if ($best !== null) {
            return $m['prefixes'][$best] + ['level' => 'prefix', 'group' => $group];
        }
        if ($group !== null && isset($m['defaults'][$group])) {
            return $m['defaults'][$group] + ['level' => 'group', 'group' => $group];
        }
        return ['severity' => 'not_defined', 'reason' => '', 'level' => 'none', 'group' => $group];
    }

    private static function prefixMatches(string $tool, string $prefix, string $name): bool
    {
        if (!str_starts_with($name, $prefix) || $name === $prefix) {
            return false;
        }
        if ($tool === 'ruff' && ctype_alpha(substr($prefix, -1))) {
            return ctype_digit($name[strlen($prefix)]);
        }
        return true;
    }

    /** @return array{version:int, defaults:array<string, array{severity:string, reason:string}>, prefixes:array<string, array{severity:string, reason:string}>, rules:array<string, array{severity:string, reason:string}>, catalog:array<string, array<string, mixed>>} */
    private function map(string $tool): array
    {
        if (!in_array($tool, self::TOOLS, true)) {
            throw new SeverityMapException("알 수 없는 도구: {$tool}");
        }
        return $this->maps[$tool] ??= $this->load($tool);
    }

    /** @return array{version:int, defaults:array<string, array{severity:string, reason:string}>, prefixes:array<string, array{severity:string, reason:string}>, rules:array<string, array{severity:string, reason:string}>, catalog:array<string, array<string, mixed>>} */
    private function load(string $tool): array
    {
        $file = "{$this->dir}/{$tool}.json";
        $d = self::json($file);
        if (($d['tool'] ?? null) !== $tool) {
            throw new SeverityMapException("{$file}: tool이 {$tool}이 아님");
        }
        if (!is_int($d['mapping_version'] ?? null) || $d['mapping_version'] < 1) {
            throw new SeverityMapException("{$file}: mapping_version(1 이상의 정수)이 없음");
        }
        $out = ['version' => $d['mapping_version'], 'defaults' => [], 'prefixes' => [], 'rules' => []];
        foreach (['defaults' => 'group', 'prefixes' => 'prefix', 'rules' => 'rule'] as $section => $field) {
            if (!is_array($d[$section] ?? null) || !array_is_list($d[$section])) {
                throw new SeverityMapException("{$file}: {$section}는 목록이어야 함");
            }
            foreach ($d[$section] as $i => $e) {
                $k = is_array($e) ? ($e[$field] ?? null) : null;
                $where = "{$file}: {$section}[{$i}]";
                if (!is_string($k) || $k === '' || strlen($k) > 200) {
                    throw new SeverityMapException("{$where}: {$field} 없음");
                }
                if (!in_array($e['severity'] ?? null, DefectFilter::SEVERITIES, true)) {
                    throw new SeverityMapException("{$where} ({$k}): 잘못된 심각도 " . json_encode($e['severity'] ?? null));
                }
                if (!is_string($e['reason'] ?? null) || trim($e['reason']) === '') {
                    throw new SeverityMapException("{$where} ({$k}): 근거(reason) 없음");
                }
                $k = $tool === 'ruff' && $section !== 'defaults' && $k !== self::SYNTAX ? strtoupper($k) : $k;
                if (isset($out[$section][$k])) {
                    throw new SeverityMapException("{$where}: 중복 키 {$k}");
                }
                $out[$section][$k] = ['severity' => (string) $e['severity'], 'reason' => (string) $e['reason']];
            }
        }
        $catFile = "{$this->dir}/{$tool}.catalog.json";
        $cat = self::json($catFile);
        if (($cat['tool'] ?? null) !== $tool || !is_array($cat['rules'] ?? null)) {
            throw new SeverityMapException("{$catFile}: tool·rules 형식 오류");
        }
        $out['catalog'] = $cat['rules'];
        return $out;
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        if (!is_file($file)) {
            throw new SeverityMapException("매핑 파일 없음: {$file}");
        }
        try {
            $d = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SeverityMapException("{$file}: JSON 형식 오류({$e->getMessage()})");
        }
        if (!is_array($d)) {
            throw new SeverityMapException("{$file}: 객체가 아님");
        }
        return $d;
    }
}
