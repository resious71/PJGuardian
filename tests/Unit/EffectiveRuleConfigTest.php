<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Rules\RuleOverrides;
use PJGuardian\Services\Tools\EffectiveRuleConfig;
use PJGuardian\Tests\TestCase;

/**
 * 실효 설정 만들기(P22B): 결정적 출력(정렬·중복 제거·글자 검사), 빈 변경 = null, ruff TOML(extend + extend-select/ignore, 경로 이스케이프),
 * ESLint 규칙 수준(error/off), 켤 수 없는 이유의 우선순위.
 */
final class EffectiveRuleConfigTest extends TestCase
{
    public function testCanonicalIsDeterministicAndFiltered(): void
    {
        $this->assertSame(null, EffectiveRuleConfig::canonical('ruff', null));
        $this->assertSame(null, EffectiveRuleConfig::canonical('ruff', ['enable' => [], 'disable' => []]));
        $this->assertSame(
            ['enable' => ['D100', 'E501'], 'disable' => ['F401']],
            EffectiveRuleConfig::canonical('ruff', ['enable' => ['E501', 'D100', 'E501', 'e501', 'E5"; x', ''], 'disable' => ['F401', 'E501']]),
            '정렬·중복 제거, 잘못된 코드는 버림, 켜기와 끄기가 겹치면 켜기',
        );
        $this->assertSame(
            ['enable' => ['@typescript-eslint/explicit-function-return-type', 'eqeqeq'], 'disable' => ['no-unused-vars']],
            EffectiveRuleConfig::canonical('eslint', ['enable' => ['eqeqeq', '@typescript-eslint/explicit-function-return-type', '../x', 'a b'], 'disable' => ['no-unused-vars', '@other/rule']]),
        );
    }

    public function testRuffToml(): void
    {
        $toml = EffectiveRuleConfig::ruffToml('C:\\cfg dir\\"q"\\ruff.default.toml', ['enable' => ['D100'], 'disable' => ['E501', 'F401']]);
        $this->assertContains('extend = "C:/cfg dir/\\"q\\"/ruff.default.toml"', $toml, '역슬래시는 /로, 따옴표는 이스케이프');
        $this->assertContains("[lint]\nextend-select = [\"D100\"]\nignore = [\"E501\", \"F401\"]\n", $toml);
    }

    public function testEslintRules(): void
    {
        $this->assertSame(['eqeqeq' => 'error', 'no-undef' => 'off', 'no-unused-vars' => 'off'],
            EffectiveRuleConfig::eslintRules(['enable' => ['eqeqeq'], 'disable' => ['no-unused-vars', 'no-undef']]));
    }

    public function testBlockReasonPriority(): void
    {
        $this->assertSame(null, RuleOverrides::blockReason('stable', []));
        $this->assertSame('preview', RuleOverrides::blockReason('preview', ['requires_options']));
        $this->assertSame('removed', RuleOverrides::blockReason('removed', []));
        $this->assertSame('deprecated', RuleOverrides::blockReason('deprecated', []));
        $this->assertSame('requires_type_info', RuleOverrides::blockReason('stable', ['requires_options', 'requires_type_info']));
        $this->assertSame('requires_options', RuleOverrides::blockReason('stable', ['requires_options']));
    }
}
