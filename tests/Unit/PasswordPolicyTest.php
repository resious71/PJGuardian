<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Auth\PasswordPolicy;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/**
 * 비밀번호 정책(명세 3): 최소 8자, 대문자·소문자·숫자·특수문자 중 2종 이상. 로컬 계정만 적용(19.2).
 * (2026-10-03 사용자 결정으로 10자·3종에서 8자·2종으로 변경, 명세 3장·19.10)
 */
final class PasswordPolicyTest extends TestCase
{
    private function policy(): PasswordPolicy
    {
        return new PasswordPolicy(8, 2);
    }

    public function testConfigDefaultsMatchSpec(): void
    {
        $settings = \PJGuardian\Services\Auth\AuthSettings::fromConfig(TestApp::config());
        $this->assertSame(8, $settings->passwordMinLength, 'config.ini.example 기본값 = 명세 3장');
        $this->assertSame(2, $settings->passwordMinClasses);
    }

    public function testRejectsShorterThanEight(): void
    {
        $this->assertSame(['too_short'], $this->policy()->violations('Abcde1!'), '7자');
        $this->assertSame(['too_short', 'too_few_classes'], $this->policy()->violations('abc'));
    }

    public function testRejectsSingleClass(): void
    {
        $this->assertSame(['too_few_classes'], $this->policy()->violations('abcdefghij'), '소문자만');
        $this->assertSame(['too_few_classes'], $this->policy()->violations('1234567890'), '숫자만');
        $this->assertSame(['too_few_classes'], $this->policy()->violations('ABCDEFGHIJ'), '대문자만');
    }

    public function testBoundaryExactlyEightCharsAndTwoClassesIsAccepted(): void
    {
        $this->assertSame([], $this->policy()->violations('abcdefg1'), '정확히 8자, 소·숫자 2종');
        $this->assertSame([], $this->policy()->violations('Abcdefgh'), '대·소 2종');
        $this->assertSame([], $this->policy()->violations('abcdefg!'), '소·특수 2종');
        $this->assertSame([], $this->policy()->violations('Abcde12!'), '4종');
    }

    public function testCountsCharClasses(): void
    {
        $p = $this->policy();
        $this->assertSame(0, $p->charClasses(''));
        $this->assertSame(1, $p->charClasses('한글비밀번호입니다아'), '한글은 특수문자(그 밖의 문자)로 센다');
        $this->assertSame(4, $p->charClasses('aA1 '), '공백도 특수문자');
    }

    public function testLengthCountsCharactersNotBytes(): void
    {
        // 한글 3자 + 영문 5자 = 8자(바이트는 14)
        $this->assertSame([], $this->policy()->violations('가나다Abcd1'));
        $this->assertSame(['too_short'], $this->policy()->violations('가나다Ab1'), '6자');
    }

    public function testRejectsOverBcryptLimit(): void
    {
        // bcrypt는 72바이트 이후를 무시하므로 조용히 잘리지 않게 거부한다(가정)
        $this->assertSame(['too_long'], $this->policy()->violations(str_repeat('Aa1!', 18) . 'x'), '73바이트');
        $this->assertSame([], $this->policy()->violations(str_repeat('Aa1!', 18)), '72바이트');
    }
}
