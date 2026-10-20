<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Icons;
use PJGuardian\Support\Logger;
use PJGuardian\Tests\TestCase;

/** 아이콘 헬퍼(명세 20.2): 크기 규격, 접근성 속성, 없는 이름 처리(dev 오류 / 운영 대체 + WARN) */
final class IconsTest extends TestCase
{
    private const JSON = PJG_ROOT . '/public/assets/icons/icons.json';

    private function icons(bool $strict, ?Logger $logger = null): Icons
    {
        return Icons::fromFile(self::JSON, '/assets/icons/sprite.svg?v=abc', $strict, $logger);
    }

    public function testDecorativeIconIsHiddenFromScreenReaders(): void
    {
        $svg = $this->icons(true)->icon('severity.critical', 16);
        $this->assertSame(
            '<svg class="icon icon-16" width="16" height="16" aria-hidden="true" focusable="false" data-icon="severity.critical">'
            . '<use href="/assets/icons/sprite.svg?v=abc#i-octagon-alert"></use></svg>',
            $svg,
        );
    }

    public function testMeaningfulIconHasRoleAndLabel(): void
    {
        $svg = $this->icons(true)->meaningful('action.delete');
        $this->assertContains('role="img" aria-label="삭제"', $svg);
        $this->assertNotContains('aria-hidden', $svg);
        $this->assertContains('aria-label="&lt;b&gt;"', $this->icons(true)->meaningful('action.delete', 20, '<b>'), '직접 준 라벨은 이스케이프');
    }

    public function testWithLabelEscapesText(): void
    {
        $html = $this->icons(true)->withLabel('severity.high', 16, '<script>x</script>');
        $this->assertContains('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertNotContains('<script>', $html);
        $this->assertContains('<span class="icon-label__text">높음</span>', $this->icons(true)->withLabel('severity.high'));
    }

    public function testOnlyRunningSpins(): void
    {
        $icons = $this->icons(true);
        $this->assertContains('icon-spin', $icons->icon('analysis_status.running'));
        $this->assertNotContains('icon-spin', $icons->icon('analysis_status.queued'));
    }

    public function testRejectsNonStandardSize(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->icons(true)->icon('severity.low', 18), '16, 20, 24');
    }

    public function testUnknownNameThrowsInDev(): void
    {
        $this->assertThrows(\LogicException::class, fn () => $this->icons(true)->icon('no.such'), '매핑에 없는 아이콘 이름');
    }

    public function testUnknownNameFallsBackAndWarnsInProd(): void
    {
        $dir = $this->tempDir('icons');
        $logger = new Logger($dir, new FixedClock('2026-10-02T12:00:00Z'), 'INFO');
        $svg = $this->icons(false, $logger)->icon('no.such');
        $this->assertContains('#i-circle-dashed', $svg, 'ui.fallback 아이콘');
        $log = '';
        foreach (scandir($dir) ?: [] as $f) {
            if (str_ends_with($f, '.log')) {
                $log .= (string) file_get_contents($dir . '/' . $f);
            }
        }
        $line = json_decode(trim($log), true);
        $this->assertSame('WARN', $line['level'] ?? null);
        $this->assertSame('no.such', $line['context']['icon'] ?? null);
    }

    public function testGroupNames(): void
    {
        $this->assertSame(['system.ok', 'system.warn', 'system.error', 'system.unknown'], $this->icons(true)->names('system'));
    }
}
