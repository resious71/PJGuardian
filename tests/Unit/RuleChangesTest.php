<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Rules\RuleLoader;
use PJGuardian\Tests\TestCase;

/** 규칙 버전 변경 판정(P22, RuleLoader::diff — 순수 함수) */
final class RuleChangesTest extends TestCase
{
    /** @param array<string, mixed> $over */
    private static function r(array $over = []): array
    {
        $r = array_merge(['summary' => 's', 'description' => 'd', 'default_enabled' => 1, 'fixable' => 'none', 'status' => 'stable', 'deprecation' => null, 'severity' => 'low'], $over);
        $r['content_hash'] = hash('sha256', json_encode($r));
        return $r;
    }

    public function testKinds(): void
    {
        $prev = [
            'same' => self::r(),
            'desc' => self::r(),
            'long' => self::r(),
            'on' => self::r(),
            'sev' => self::r(),
            'dep' => self::r(),
            'fix' => self::r(),
            'gone' => self::r(),
            'old' => self::r(['status' => 'deprecated', 'deprecation' => json_encode(['replaced_by' => ['renamed-to']])]),
            'hint-missing' => self::r(['status' => 'deprecated', 'deprecation' => json_encode(['replaced_by' => ['not-in-next']])]),
        ];
        $next = [
            'same' => self::r(),
            'desc' => self::r(['summary' => 's2']),
            'long' => self::r(['description' => 'd2']),
            'on' => self::r(['default_enabled' => 0]),
            'sev' => self::r(['severity' => 'high']),
            'dep' => self::r(['status' => 'deprecated']),
            'fix' => self::r(['fixable' => 'always']),
            'renamed-to' => self::r(),
            'brand-new' => self::r(),
        ];
        $this->assertSame([
            ['brand-new', 'added', null, null],
            ['dep', 'deprecated', 'stable', 'deprecated'],
            ['desc', 'description', 's', 's2'],
            ['fix', 'fixable', 'none', 'always'],
            ['gone', 'removed', null, null],
            ['hint-missing', 'removed', null, null],
            ['long', 'description', 'd', 'd2'],
            ['on', 'default_enabled', '1', '0'],
            ['renamed-to', 'renamed', 'old', 'renamed-to'],
            ['sev', 'default_severity', 'low', 'high'],
        ], RuleLoader::diff($prev, $next), '대체 정보가 가리키는 새 규칙만 이름 변경, 나머지는 삭제/신설');
    }

    public function testNoChangeAndHashShortcut(): void
    {
        $a = ['x' => self::r(), 'y' => self::r(['summary' => 'q'])];
        $this->assertSame([], RuleLoader::diff($a, $a));
        // 해시가 같으면 다른 필드를 보지 않는다(같은 내용 = 변경 없음)
        $b = $a;
        $b['x']['summary'] = 'changed-but-same-hash';
        $this->assertSame([], RuleLoader::diff($a, $b));
        // 대체 정보 없이 사라지고 생긴 것은 추측하지 않는다
        $this->assertSame([['a', 'removed', null, null], ['b', 'added', null, null]], RuleLoader::diff(['a' => self::r()], ['b' => self::r()]));
    }
}
