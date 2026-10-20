<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Rules\RuleEnrichment;
use PJGuardian\Tests\TestCase;

/**
 * 규칙 쉬운 설명 자료(P32B, 명세 11 — resources/rules/enrichment/*.json의 guide) 검사. 실제 도구 실행 확인은 tools/rules_guide_check.mjs(bin/ 필요)가 하고
 * 결과로 checked를 적는다 — 이 시험은 bin/ 없이 자료 자체만 본다:
 * - 형식(RuleEnrichment::validate), 규칙 키가 그 도구 버전의 규칙 목록(resources/severity/<도구>.catalog.json — bin/의 도구에서 뽑은 목록)에 있음,
 *   ruff 규칙 이름 = 목록의 이름, 모든 guide가 목록과 같은 도구 버전으로 checked, ko·en 둘 다 있고 영어에 한글 없음, 서버 기본 설정 규칙의 대부분(도구마다 55개 이상).
 * - checked가 없는 guide는 화면에 나오지 않음(RuleEnrichment::guide = null).
 */
final class RuleGuideDataTest extends TestCase
{
    public function testGuidesMatchToolCatalogAndAreChecked(): void
    {
        $dir = PJG_ROOT . '/resources/rules/enrichment';
        $this->assertSame([], (new RuleEnrichment($dir))->validate());
        foreach (['ruff' => 55, 'eslint' => 55] as $tool => $min) {
            $catalog = json_decode((string) file_get_contents(PJG_ROOT . "/resources/severity/{$tool}.catalog.json"), true);
            $doc = json_decode((string) file_get_contents("{$dir}/{$tool}.json"), true);
            $guides = array_filter($doc['rules'], static fn (array $e): bool => isset($e['guide']));
            $this->assertTrue(count($guides) >= $min, "{$tool} 쉬운 설명 {$min}개 이상: " . count($guides));
            $bad = [];
            foreach ($guides as $rule => $e) {
                $g = $e['guide'];
                $known = $catalog['rules'][$rule] ?? null;
                if ($known === null) {
                    $bad[] = "{$rule}: 도구 규칙 목록에 없음";
                    continue;
                }
                if ($tool === 'ruff' && ($g['name'] ?? null) !== $known['name']) {
                    $bad[] = "{$rule}: 이름 {$g['name']} ≠ {$known['name']}";
                }
                if (($g['checked'] ?? null) !== ['tool' => $tool, 'version' => $catalog['tool_version']]) {
                    $bad[] = "{$rule}: checked가 {$tool} {$catalog['tool_version']}가 아님";
                }
                if (preg_match('/[가-힣]/u', $g['en']['summary'] . $g['en']['fix'] . $g['bad'] . $g['good']) === 1) {
                    $bad[] = "{$rule}: 영어 설명·코드에 한글";
                }
                if (preg_match('/[가-힣]/u', $g['ko']['summary']) !== 1) {
                    $bad[] = "{$rule}: 한국어 설명이 한국어가 아님";
                }
            }
            $this->assertSame([], $bad, $tool);
        }
    }

    public function testUncheckedGuideIsNotShown(): void
    {
        $dir = $this->tempDir('guide');
        $g = ['bad' => "import os\n", 'good' => "print(1)\n", 'ko' => ['summary' => '설명', 'fix' => '고침'], 'en' => ['summary' => 'Text', 'fix' => 'Fix']];
        file_put_contents("{$dir}/ruff.json", json_encode(['format' => 1, 'rules' => ['F401' => ['guide' => $g], 'F841' => ['guide' => $g + ['checked' => ['tool' => 'ruff', 'version' => '0.16.10']]]]]));
        $e = new RuleEnrichment($dir);
        $this->assertSame([], $e->validate());
        $this->assertSame(null, $e->guide('ruff', 'F401', 'ko'), '확인하지 않은 설명은 보이지 않음');
        $this->assertSame(['설명', 'Fix'], [$e->guide('ruff', 'F841', 'ko')['summary'], $e->guide('ruff', 'F841', 'en')['fix']]);
        // 형식 오류: en 없음, 알 수 없는 항목, 출처 없이 도구 값 채우기
        file_put_contents("{$dir}/ruff.json", json_encode(['format' => 1, 'rules' => ['F401' => ['guide' => array_diff_key($g, ['en' => 1])], 'E501' => ['guide' => $g + ['extra' => 1]], 'E711' => ['summary' => 'x']]]));
        $errors = (new RuleEnrichment($dir))->validate();
        $this->assertSame(3, count($errors), implode(' / ', $errors));
    }
}
