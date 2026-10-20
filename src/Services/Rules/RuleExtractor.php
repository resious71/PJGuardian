<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Fs;

/**
 * 설치된 도구에서 규칙 정보를 꺼낸다(P21S 카탈로그와 P22 규칙 설명이 함께 쓰는 한 곳). 네트워크 없음, 고정 경로·고정 인자, 시간·출력 제한.
 *
 * - ruff: `ruff rule --all --output-format json`(코드·이름·요약·설명(마크다운, 예시 포함)·linter·category·수정 가능·상태)
 *         + `ruff check --show-settings --config config/ruff.default.toml <빈 파일>`의 linter.rules.enabled(서버 기본 설정에서 켜진 규칙)
 * - ESLint: `node tools/severity/eslint_rules.cjs <bin/eslint/<버전>>`(코어 + @typescript-eslint의 meta: 설명·문서 주소·type·fixable·
 *           hasSuggestions·schema·deprecated·replacedBy·recommended). 예시 코드는 meta에 없다.
 *
 * 반환(규칙 하나): rule, name, summary, description, category, origin, default_enabled, fixable(none|sometimes|always), has_suggestions,
 *   has_options, status(stable|preview|deprecated|removed), deprecation(배열|null), docs_url — 도구가 내놓지 않는 값은 ''/null.
 *   requires_type_info(P22B, ESLint meta.docs.requiresTypeChecking — 켤 수 없는 규칙 판정. 규칙 내용 해시에는 넣지 않는다).
 */
final class RuleExtractor implements RuleSource
{
    private const TIMEOUT = 120;
    private const OUTPUT_LIMIT = 64 * 1024 * 1024;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ProcessRunner $runner,
        private readonly NodeRuntime $node,
        private readonly string $root,
        private readonly string $workDir,
    ) {
    }

    /**
     * @return array{version:string, components:array<string, string>, rules:array<string, array<string, mixed>>}
     */
    public function extract(string $tool, string $version): array
    {
        if (!in_array($version, $this->registry->versionsOnDisk($tool), true)) {
            throw new RuleLoadException("{$tool} {$version}: bin/에 없는 버전");
        }
        $dir = str_replace('\\', '/', (string) realpath($this->registry->versionDir($tool, $version)));
        return $tool === 'ruff' ? $this->ruff($dir, $version) : $this->eslint($dir, $version);
    }

    /** P21S 카탈로그 항목(resources/severity/<도구>.catalog.json, 형식은 P21S 그대로) */
    public static function catalogEntry(string $tool, array $r): array
    {
        if ($tool === 'ruff') {
            return ['name' => $r['name'], 'group' => $r['category'], 'linter' => $r['origin'], 'preview' => $r['status'] === 'preview'];
        }
        return ['group' => $r['category'], 'plugin' => $r['origin'], 'deprecated' => $r['status'] === 'deprecated', 'recommended' => $r['recommended'], 'name' => $r['summary']];
    }

    /** @param list<string> $cmd 고정 인자(사용자 입력 없음) */
    private function run(array $cmd): string
    {
        $r = $this->runner->run(array_map(static fn (string $s): string => str_replace('\\', '/', $s), $cmd), new ProcessOptions(timeoutSeconds: self::TIMEOUT, outputLimit: self::OUTPUT_LIMIT));
        $out = $r->stdout();
        $err = $r->stderr(4096);
        $r->cleanup();
        if (!$r->ok()) {
            throw new RuleLoadException(sprintf('도구 실행 실패(%s, 종료 코드 %s): %s', $r->reason, $r->exitCode ?? '-', trim(substr($err, 0, 500))));
        }
        return $out;
    }

    private function ruff(string $dir, string $version): array
    {
        $exe = $dir . '/' . ToolRegistry::executableRel('ruff');
        $data = json_decode($this->run([$exe, 'rule', '--all', '--output-format', 'json']), true, 64, JSON_THROW_ON_ERROR);
        $enabled = $this->ruffDefaultEnabled($exe);
        $rules = [];
        foreach ($data as $x) {
            if (!is_string($x['code'] ?? null) || $x['code'] === '') {
                continue;
            }
            $status = is_array($x['status'] ?? null) ? array_key_first($x['status']) : null;
            $fix = match ($x['fix_availability'] ?? null) { 'Always' => 'always', 'Sometimes' => 'sometimes', default => 'none' };
            $rules[$x['code']] = [
                'rule' => $x['code'], 'name' => (string) ($x['name'] ?? ''), 'summary' => (string) ($x['summary'] ?? ''),
                'description' => (string) ($x['explanation'] ?? ''), 'category' => is_string($x['category'] ?? null) ? $x['category'] : null,
                'origin' => (string) ($x['linter'] ?? ''), 'default_enabled' => isset($enabled[$x['code']]), 'fixable' => $fix,
                'has_suggestions' => false, 'has_options' => null,
                'status' => match ($status) { 'Preview' => 'preview', 'Deprecated' => 'deprecated', 'Removed' => 'removed', default => 'stable' },
                'deprecation' => $status === 'Deprecated' || $status === 'Removed'
                    ? ['message' => null, 'since' => is_string($x['status'][$status]['since'] ?? null) ? $x['status'][$status]['since'] : null, 'replaced_by' => []] : null,
                'docs_url' => null, // ruff는 문서 주소를 내놓지 않는다(지어내지 않음)
                'recommended' => false,
                'requires_type_info' => false,
            ];
        }
        ksort($rules, SORT_STRING);
        return ['version' => $version, 'components' => [], 'rules' => $rules];
    }

    /** @return array<string, true> 서버 기본 설정(config/ruff.default.toml)에서 켜진 규칙 코드 */
    private function ruffDefaultEnabled(string $exe): array
    {
        $probe = rtrim(str_replace('\\', '/', $this->workDir), '/') . '/rules_probe';
        Fs::ensureDir($probe);
        file_put_contents($probe . '/probe.py', "x = 1\n");
        $out = $this->run([$exe, 'check', '--show-settings', '--no-cache', '--config', $this->root . '/config/ruff.default.toml', $probe . '/probe.py']);
        $start = strpos($out, 'linter.rules.enabled = [');
        if ($start === false) {
            throw new RuleLoadException('ruff 설정 출력에서 linter.rules.enabled를 찾지 못함');
        }
        $end = strpos($out, ']', $start + 24);
        preg_match_all('/\(([A-Z]+[0-9]+)\)/', substr($out, $start, $end === false ? null : $end - $start), $m);
        return array_fill_keys($m[1], true);
    }

    private function eslint(string $dir, string $version): array
    {
        $node = $this->node->path();
        if ($node === null) {
            throw new RuleLoadException('Node.js 런타임 없음(tools.node_path)');
        }
        $doc = json_decode($this->run([$node, $this->root . '/tools/severity/eslint_rules.cjs', $dir]), true, 64, JSON_THROW_ON_ERROR);
        $rules = [];
        foreach ($doc['rules'] ?? [] as $name => $x) {
            $dep = is_array($x['deprecation'] ?? null) ? $x['deprecation'] : null;
            $fix = $x['fixable'] ?? null;
            $rules[(string) $name] = [
                'rule' => (string) $name, 'name' => (string) $name, 'summary' => (string) ($x['name'] ?? ''), 'description' => '',
                'category' => is_string($x['group'] ?? null) ? $x['group'] : null, 'origin' => (string) ($x['plugin'] ?? ''),
                'default_enabled' => (bool) ($x['defaultOn'] ?? $x['recommended'] ?? false),
                // ESLint는 "가끔"을 구분하지 않는다: fixable이 있으면 always(규칙이 고칠 수 있는 경우에만 고친다)
                'fixable' => is_string($fix) && $fix !== '' ? 'always' : 'none',
                'has_suggestions' => (bool) ($x['hasSuggestions'] ?? false),
                'has_options' => isset($x['hasOptions']) ? (bool) $x['hasOptions'] : null,
                'status' => !empty($x['deprecated']) ? 'deprecated' : 'stable',
                'deprecation' => $dep === null ? null : ['message' => $dep['message'] ?? null, 'since' => $dep['since'] ?? null,
                    'replaced_by' => array_values(array_filter((array) ($dep['replacedBy'] ?? []), 'is_string'))],
                'docs_url' => is_string($x['url'] ?? null) && str_starts_with($x['url'], 'https://') ? $x['url'] : null,
                'recommended' => (bool) ($x['recommended'] ?? false),
                'requires_type_info' => (bool) ($x['requiresTypeChecking'] ?? false),
            ];
        }
        ksort($rules, SORT_STRING);
        $components = array_diff_key(array_map('strval', (array) ($doc['versions'] ?? [])), ['eslint' => 1]);
        return ['version' => $version, 'components' => $components, 'rules' => $rules];
    }
}
