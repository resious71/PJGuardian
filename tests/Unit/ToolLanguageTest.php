<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Dashboard\DashboardService;
use PJGuardian\Services\Upload\UploadProcessor;
use PJGuardian\Support\ToolLanguage;
use PJGuardian\Tests\TestCase;

/** 분석 언어 ↔ 도구 대응(X08): 한 곳에서 정의하고 대시보드·업로드·규칙 설명 탭·화면 JS가 같은 값을 쓴다 */
final class ToolLanguageTest extends TestCase
{
    public function testMappingIsDefinedOnceAndReused(): void
    {
        $this->assertSame(['python' => 'ruff', 'javascript' => 'eslint'], ToolLanguage::TOOL_BY_LANGUAGE);
        $this->assertSame(['ruff', 'eslint', null, null], [ToolLanguage::tool('python'), ToolLanguage::tool('javascript'), ToolLanguage::tool('typescript'), ToolLanguage::tool(null)]);
        $this->assertSame(['python', 'javascript', null], [ToolLanguage::language('ruff'), ToolLanguage::language('eslint'), ToolLanguage::language('pylint')]);
        $this->assertSame(ToolLanguage::TOOL_BY_LANGUAGE, DashboardService::LANGUAGE_TOOL, '대시보드 언어 필터(P21C)');
        $this->assertSame(['ruff', 'eslint'], [UploadProcessor::toolFor('python'), UploadProcessor::toolFor('javascript')], '업로드 분석 등록');
        // 화면 쪽(public/assets/js/lib/tool_language.js)도 같은 값
        $js = (string) file_get_contents(PJG_ROOT . '/public/assets/js/lib/tool_language.js');
        $this->assertTrue(preg_match('/TOOL_BY_LANGUAGE = Object\.freeze\(\{ python: \'ruff\', javascript: \'eslint\' \}\)/', $js) === 1, 'JS 대응표가 서버와 같음');
    }
}
