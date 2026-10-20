<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

/**
 * 사전 작성 보강 자료(P22, 명세 11장): resources/rules/enrichment/<도구>.json. 도구가 내놓은 값보다 우선순위가 낮아 **비어 있는 항목만** 채운다.
 *
 * 형식: {"format": 1, "rules": {"<규칙>": {"summary"?: 문자열, "description"?: 마크다운, "docs_url"?: "https://…", "sources"?: ["https://…", …], "guide"?: {…}}}}
 * - summary·description·docs_url(도구 값을 채우는 항목)이 있으면 sources(출처 주소 1개 이상, https)가 필수(지어낸 설명 금지 — validate()가 거부).
 * - guide(P32B, 명세 11 "쉬운 설명"): 직접 쓴 쉬운 설명·나쁜 예·좋은 예·고치는 법(한국어·영어). 도구 원문을 덮어쓰지 않고 따로 보인다.
 *   {name?(ruff 규칙 이름 — 도구와 대조), lang?(py|js|ts), bad, good(코드, 언어 공통), ko: {summary, fix}, en: {summary, fix}, checked?: {tool, version}}
 *   checked는 tools/rules_guide_check.mjs가 실제 도구로 "나쁜 예는 걸리고 좋은 예는 안 걸림"을 확인하고 적는다. **checked가 없으면 보이지 않는다**.
 * - 채우는 절차는 docs/rules.md 5절.
 */
final class RuleEnrichment
{
    /** @var array<string, array<string, array<string, mixed>>>|null */
    private ?array $data = null;

    public function __construct(private readonly string $dir)
    {
    }

    /** @return list<string> 형식 오류(없으면 빈 배열) */
    public function validate(): array
    {
        $errors = [];
        foreach (['ruff', 'eslint'] as $tool) {
            $file = "{$this->dir}/{$tool}.json";
            if (!is_file($file)) {
                continue;
            }
            $doc = json_decode((string) file_get_contents($file), true);
            if (!is_array($doc) || ($doc['format'] ?? null) !== 1 || !is_array($doc['rules'] ?? null)) {
                $errors[] = "{$tool}: format 1과 rules 객체가 필요함";
                continue;
            }
            foreach ($doc['rules'] as $rule => $e) {
                $where = "{$tool}:{$rule}";
                if (!is_array($e)) {
                    $errors[] = "{$where}: 객체가 아님";
                    continue;
                }
                $extra = array_diff(array_keys($e), ['summary', 'description', 'docs_url', 'sources', 'guide']);
                if ($extra !== []) {
                    $errors[] = "{$where}: 알 수 없는 항목 " . implode(', ', $extra);
                }
                $fills = isset($e['summary']) || isset($e['description']) || isset($e['docs_url']);
                if (!$fills && !isset($e['guide'])) {
                    $errors[] = "{$where}: 채울 항목(summary·description·docs_url) 또는 guide가 필요함";
                }
                if (isset($e['guide'])) {
                    array_push($errors, ...self::guideErrors($where, $e['guide']));
                }
                $sources = $e['sources'] ?? null;
                if ($fills && (!is_array($sources) || $sources === [] || array_filter($sources, static fn ($s): bool => !is_string($s) || !str_starts_with($s, 'https://')) !== [])) {
                    $errors[] = "{$where}: sources(https 주소 1개 이상)가 필요함";
                }
                foreach (['summary', 'description'] as $k) {
                    if (isset($e[$k]) && (!is_string($e[$k]) || trim($e[$k]) === '' || strlen($e[$k]) > 20000)) {
                        $errors[] = "{$where}: {$k}는 비지 않은 문자열(20000바이트 이하)";
                    }
                }
                if (isset($e['docs_url']) && (!is_string($e['docs_url']) || !str_starts_with($e['docs_url'], 'https://'))) {
                    $errors[] = "{$where}: docs_url은 https 주소";
                }
            }
        }
        return $errors;
    }

    /** @return list<string> guide 형식 오류 */
    private static function guideErrors(string $where, mixed $g): array
    {
        if (!is_array($g)) {
            return ["{$where}: guide는 객체"];
        }
        $errors = [];
        $extra = array_diff(array_keys($g), ['name', 'lang', 'bad', 'good', 'ko', 'en', 'checked']);
        if ($extra !== []) {
            $errors[] = "{$where}: guide에 알 수 없는 항목 " . implode(', ', $extra);
        }
        foreach (['bad', 'good'] as $k) {
            if (!is_string($g[$k] ?? null) || trim($g[$k]) === '' || strlen($g[$k]) > 4000) {
                $errors[] = "{$where}: guide.{$k}(코드)는 비지 않은 문자열(4000바이트 이하)";
            }
        }
        if (isset($g['lang']) && !in_array($g['lang'], ['py', 'js', 'ts'], true)) {
            $errors[] = "{$where}: guide.lang은 py·js·ts";
        }
        if (isset($g['name']) && (!is_string($g['name']) || preg_match('/^[a-z0-9-]+$/', $g['name']) !== 1)) {
            $errors[] = "{$where}: guide.name은 규칙 이름(소문자·숫자·-)";
        }
        foreach (['ko', 'en'] as $lang) {
            $l = $g[$lang] ?? null;
            if (!is_array($l) || array_keys($l) !== ['summary', 'fix'] && array_keys($l) !== ['fix', 'summary']) {
                $errors[] = "{$where}: guide.{$lang}은 summary·fix 두 항목";
                continue;
            }
            foreach (['summary', 'fix'] as $k) {
                if (!is_string($l[$k]) || trim($l[$k]) === '' || strlen($l[$k]) > 2000) {
                    $errors[] = "{$where}: guide.{$lang}.{$k}는 비지 않은 문자열(2000바이트 이하)";
                }
            }
        }
        if (isset($g['checked']) && (!is_array($g['checked']) || !is_string($g['checked']['tool'] ?? null) || !is_string($g['checked']['version'] ?? null))) {
            $errors[] = "{$where}: guide.checked는 {tool, version}";
        }
        return $errors;
    }

    /**
     * 쉬운 설명(P32B): 실제 도구로 확인한(checked) 것만, 화면 언어(ko·en)의 요약·고치는 법 + 공통 코드 예시. 없으면 null.
     *
     * @return array{summary: string, fix: string, bad: string, good: string, lang: string, checked: array{tool: string, version: string}}|null
     */
    public function guide(string $tool, string $rule, string $lang): ?array
    {
        $g = $this->load()[$tool][$rule]['guide'] ?? null;
        if (!is_array($g) || !is_array($g['checked'] ?? null)) {
            return null;
        }
        $l = $g[$lang === 'en' ? 'en' : 'ko'];
        return ['summary' => (string) $l['summary'], 'fix' => (string) $l['fix'], 'bad' => (string) $g['bad'], 'good' => (string) $g['good'],
            'lang' => (string) ($g['lang'] ?? ($tool === 'ruff' ? 'py' : (str_starts_with($rule, '@typescript-eslint/') ? 'ts' : 'js'))),
            'checked' => ['tool' => (string) $g['checked']['tool'], 'version' => (string) $g['checked']['version']]];
    }

    /**
     * 비어 있는 항목만 채운다. 채운 것이 있으면 enrichment_sources를 붙인다.
     *
     * @param array<string, mixed> $rule RuleExtractor의 규칙 하나
     * @return array<string, mixed>
     */
    public function apply(string $tool, array $rule): array
    {
        $e = $this->load()[$tool][(string) $rule['rule']] ?? null;
        if ($e === null) {
            return $rule;
        }
        $used = false;
        foreach (['summary', 'description', 'docs_url'] as $k) {
            if (($rule[$k] ?? null) === null || $rule[$k] === '') {
                if (isset($e[$k])) {
                    $rule[$k] = $e[$k];
                    $used = true;
                }
            }
        }
        if ($used) {
            $rule['enrichment_sources'] = array_values($e['sources']);
        }
        return $rule;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $errors = $this->validate();
        if ($errors !== []) {
            throw new RuleLoadException('보강 자료 형식 오류: ' . implode('; ', array_slice($errors, 0, 5)));
        }
        $this->data = [];
        foreach (['ruff', 'eslint'] as $tool) {
            $file = "{$this->dir}/{$tool}.json";
            $this->data[$tool] = is_file($file) ? (array) json_decode((string) file_get_contents($file), true)['rules'] : [];
        }
        return $this->data;
    }
}
