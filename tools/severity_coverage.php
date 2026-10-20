<?php

declare(strict_types=1);

/**
 * 규칙 기본 심각도 매핑의 적용 범위 점검(P21S, docs/severity_mapping.md 5절).
 *   php tools/severity_coverage.php [--config=<ini>] [--ruff=<버전>] [--eslint=<버전>] [--write-catalog] [--markdown=<파일>] [--strict]
 *
 * bin/의 도구에서 규칙 목록을 읽는다(읽기만, 네트워크 없음):
 *   ruff:   <bin/ruff/<버전>/<os>/ruff> rule --all --output-format json   (category = 분류군, 폐기(Removed)·코드 없는 규칙 제외)
 *   ESLint: <Node> tools/severity/eslint_rules.cjs <bin/eslint/<버전>>    (내장 + @typescript-eslint, meta.type = 분류군)
 * 버전은 지정하지 않으면 디스크의 최신(분석이 고르는 것과 같은 semver 최신. 차단·고정은 tools_ctl.php로 확인).
 *
 * 출력: 도구별 규칙 수, 정해진 단계(개별·접두사·분류군·not_defined) 건수, not_defined로 남는 규칙, 매핑에는 있으나 도구에 없는 규칙(폐기·이름 변경 후보),
 *   카탈로그 파일(resources/severity/<도구>.catalog.json)과 도구 목록의 차이.
 *   --write-catalog: 도구 목록으로 카탈로그 파일을 다시 쓴다(도구 버전을 바꾼 뒤).  --markdown: 전체 매핑 표를 파일로.
 *   --strict: not_defined가 있거나 카탈로그가 도구와 다르면 종료 코드 1.
 * 종료 코드: 0 정상, 1 --strict 위반, 2 오류(도구 없음·실행 실패·매핑 형식 오류)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Rules\RuleExtractor;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Config;

$opt = getopt('', ['config:', 'ruff:', 'eslint:', 'write-catalog', 'markdown:', 'strict']);
$configPath = isset($opt['config']) ? (string) realpath((string) $opt['config']) : null;
$severityDir = PJG_ROOT . '/resources/severity';
$special = [SeverityMap::SYNTAX, 'unused-disable-directive', 'no-rule-id'];

try {
    $app = App::create(PJG_ROOT, $configPath !== null ? Config::load($configPath) : null);
    $reg = $app->toolRegistry();
    $live = [];
    $versions = [];
    foreach (SeverityMap::TOOLS as $tool) {
        $v = isset($opt[$tool]) ? (string) $opt[$tool] : ($reg->versionsOnDisk($tool)[0] ?? null);
        if ($v === null || !in_array($v, $reg->versionsOnDisk($tool), true)) {
            throw new RuntimeException("{$tool}: bin/에 버전 없음" . ($v !== null ? " ({$v})" : ''));
        }
        $versions[$tool] = $v;
        // 추출은 규칙 설명(P22)과 같은 한 곳(Services\Rules\RuleExtractor). 카탈로그 형식은 P21S 그대로(ruff는 Removed 제외)
        $rules = [];
        foreach ($app->ruleExtractor()->extract($tool, $v)['rules'] as $name => $x) {
            if ($tool === 'ruff' && $x['status'] === 'removed') {
                continue;
            }
            $rules[(string) $name] = RuleExtractor::catalogEntry($tool, $x);
        }
        ksort($rules, SORT_STRING);
        $live[$tool] = $rules;
    }

    if (isset($opt['write-catalog'])) {
        foreach ($live as $tool => $rules) {
            $file = "{$severityDir}/{$tool}.catalog.json";
            $doc = ['tool' => $tool, 'tool_version' => $versions[$tool], 'note' => '도구가 제공하는 규칙 목록과 분류군(ruff: category, ESLint: meta.type). 손으로 고치지 않는다 — php tools/severity_coverage.php --write-catalog가 만든다', 'rules' => $rules];
            file_put_contents($file, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            echo "카탈로그 기록: resources/severity/{$tool}.catalog.json ({$versions[$tool]}, 규칙 " . count($rules) . "개)\n";
        }
    }

    $map = new SeverityMap($severityDir);
    $map->validate();
    $strictFail = false;
    $table = [];
    foreach ($live as $tool => $rules) {
        $catalog = $map->catalog($tool);
        $levels = ['rule' => 0, 'prefix' => 0, 'group' => 0, 'none' => 0];
        $bySeverity = array_fill_keys(['critical', 'high', 'medium', 'low', 'not_defined'], 0);
        $none = [];
        foreach ($rules as $name => $x) {
            $e = $map->explain($tool, $tool . ':' . $name);
            $levels[$e['level']]++;
            $bySeverity[$e['severity']]++;
            if ($e['severity'] === 'not_defined') {
                $none[] = $name;
            }
            $table[] = [$tool, $name, (string) ($x['name'] ?? ''), $e];
        }
        foreach ($special as $s) {
            if (in_array($s, $map->ruleKeys($tool), true)) {
                $table[] = [$tool, $s, '(특수 키)', $map->explain($tool, $s === SeverityMap::SYNTAX ? $s : $tool . ':' . $s)];
            }
        }
        $missing = array_values(array_diff($map->ruleKeys($tool), array_keys($rules), $special));
        $catAdded = array_values(array_diff(array_keys($rules), array_keys($catalog)));
        $catRemoved = array_values(array_diff(array_keys($catalog), array_keys($rules)));
        printf("== %s %s (매핑 v%d)\n", $tool, $versions[$tool], $map->version($tool));
        printf("규칙 %d개: 개별 지정 %d · 접두사 %d · 분류군 기본 %d · not_defined %d\n", count($rules), $levels['rule'], $levels['prefix'], $levels['group'], $levels['none']);
        printf("심각도: critical %d · high %d · medium %d · low %d · not_defined %d\n", ...array_values($bySeverity));
        printf("not_defined로 남는 규칙(%d): %s\n", count($none), $none === [] ? '없음' : implode(', ', $none));
        printf("매핑에는 있으나 도구에 없는 규칙(%d, 폐기·이름 변경 후보): %s\n", count($missing), $missing === [] ? '없음' : implode(', ', $missing));
        if ($catAdded !== [] || $catRemoved !== []) {
            printf("카탈로그와 도구 목록이 다름: 도구에만 %d개, 카탈로그에만 %d개 → --write-catalog로 갱신\n", count($catAdded), count($catRemoved));
            $strictFail = true;
        }
        if ($none !== []) {
            $strictFail = true;
        }
        echo "\n";
    }

    if (isset($opt['markdown'])) {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'not_defined' => 4];
        $levelText = ['rule' => '개별', 'prefix' => '접두사', 'group' => '분류군', 'none' => '없음'];
        usort($table, static fn (array $a, array $b): int => [$a[0] === 'eslint', $order[$a[3]['severity']], $a[1]] <=> [$b[0] === 'eslint', $order[$b[3]['severity']], $b[1]]);
        $md = "| 도구 | 규칙 키 | 규칙 이름·설명 | 기본 심각도 | 정한 단계 | 근거 |\n| --- | --- | --- | --- | --- | --- |\n";
        $cell = static fn (string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);
        foreach ($table as [$tool, $name, $label, $e]) {
            $key = $name === SeverityMap::SYNTAX ? $name : "{$tool}:{$name}";
            $group = $e['group'] !== null ? " ({$e['group']})" : '';
            $md .= "| {$tool} | `{$key}` | {$cell($label)} | {$e['severity']} | {$levelText[$e['level']]}{$group} | {$cell($e['reason'])} |\n";
        }
        file_put_contents((string) $opt['markdown'], $md);
        echo "전체 매핑 표: {$opt['markdown']} (" . count($table) . "행)\n";
    }
    exit(isset($opt['strict']) && $strictFail ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
