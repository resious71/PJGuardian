<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Tests\TestCase;

/**
 * README·기능 소개 그림(P33 4-1): README.md·README.en.md·docs/features(.ko).md가 가리키는 그림이 모두 있고 대체 텍스트가 비어 있지 않음,
 * 그림 한 장 300KB·합계 8MB 이하, 파일 이름 규칙(<이름>.png, 한국어판 <이름>.ko.png), docs/screenshots의 모든 그림이 어느 문서엔가 쓰임,
 * 목록(SCREENSHOTS.json)과 실제 파일이 같음. 그림은 tools/make_readme_screenshots.mjs가 만든다.
 */
final class ReadmeAssetsTest extends TestCase
{
    private const DOCS = ['README.md', 'README.en.md', 'docs/features.md', 'docs/features.ko.md'];
    private const MAX_BYTES = 300 * 1024;
    private const MAX_TOTAL = 8 * 1024 * 1024;

    /** @return array<string, list<array{alt: ?string, path: string}>> 문서 → 그림·그림 링크(로컬 .png) */
    private function references(): array
    {
        $refs = [];
        foreach (self::DOCS as $doc) {
            $file = PJG_ROOT . '/' . $doc;
            $this->assertTrue(is_file($file), "{$doc} 없음");
            $text = (string) file_get_contents($file);
            $refs[$doc] = [];
            // 그림 ![alt](path) 과 그림으로 가는 링크 [글](path.png)
            preg_match_all('/(!?)\[([^\]]*)\]\(([^)\s]+\.png)\)/', $text, $m, PREG_SET_ORDER);
            foreach ($m as [, $bang, $label, $path]) {
                if (preg_match('#^[a-z]+://#i', $path) === 1) {
                    continue;
                }
                $refs[$doc][] = ['alt' => $bang === '!' ? $label : null, 'path' => $path];
            }
            // HTML <img>(README 격자)
            preg_match_all('/<img\b[^>]*>/i', $text, $imgs);
            foreach ($imgs[0] as $tag) {
                preg_match('/\bsrc="([^"]+)"/i', $tag, $src);
                preg_match('/\balt="([^"]*)"/i', $tag, $alt);
                if (isset($src[1]) && preg_match('#^[a-z]+://#i', $src[1]) !== 1) {
                    $refs[$doc][] = ['alt' => $alt[1] ?? '', 'path' => $src[1]];
                }
            }
        }
        return $refs;
    }

    public function testReferencedImagesExistWithAltText(): void
    {
        $problems = [];
        $count = 0;
        foreach ($this->references() as $doc => $list) {
            foreach ($list as $r) {
                $count++;
                $abs = realpath(dirname(PJG_ROOT . '/' . $doc) . '/' . $r['path']);
                if ($abs === false || !is_file($abs)) {
                    $problems[] = "{$doc}: 없는 그림 {$r['path']}";
                }
                if ($r['alt'] !== null && trim($r['alt']) === '') {
                    $problems[] = "{$doc}: 대체 텍스트가 빈 그림 {$r['path']}";
                }
            }
        }
        $this->assertSame([], $problems);
        $this->assertTrue($count > 0, '문서에 그림이 하나도 없음');
    }

    public function testScreenshotSizeNamesAndUsage(): void
    {
        $dir = PJG_ROOT . '/docs/screenshots';
        $files = array_values(array_filter(scandir($dir) ?: [], static fn ($f) => str_ends_with($f, '.png')));
        $this->assertTrue(count($files) > 0, 'docs/screenshots에 그림 없음');
        $used = [];
        foreach ($this->references() as $doc => $list) {
            foreach ($list as $r) {
                $abs = realpath(dirname(PJG_ROOT . '/' . $doc) . '/' . $r['path']);
                if ($abs !== false) {
                    $used[str_replace('\\', '/', $abs)] = true;
                }
            }
        }
        $problems = [];
        $total = 0;
        foreach ($files as $f) {
            $size = (int) filesize("{$dir}/{$f}");
            $total += $size;
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.ko)?\.png$/', $f) !== 1) {
                $problems[] = "파일 이름 규칙: {$f}";
            }
            if ($size > self::MAX_BYTES) {
                $problems[] = "{$f}: {$size}바이트 > " . self::MAX_BYTES;
            }
            if (!isset($used[str_replace('\\', '/', (string) realpath("{$dir}/{$f}"))])) {
                $problems[] = "어느 문서에도 쓰이지 않은 그림: {$f}";
            }
        }
        $this->assertSame([], $problems);
        $this->assertTrue($total <= self::MAX_TOTAL, "합계 {$total}바이트 > " . self::MAX_TOTAL);
        // 목록(만든 도구가 씀) = 실제 파일
        $list = json_decode((string) file_get_contents("{$dir}/SCREENSHOTS.json"), true);
        $listed = array_map(static fn ($i) => basename((string) $i['file']), $list['images'] ?? []);
        sort($listed);
        $this->assertSame($files, $listed, 'SCREENSHOTS.json 목록과 docs/screenshots의 그림이 다름');
        foreach ($list['images'] as $i) {
            $this->assertTrue(($i['scene'] ?? '') !== '' && ($i['login'] ?? '') !== '' && ($i['script'] ?? '') === 'tools/make_readme_screenshots.mjs', "목록 항목: {$i['file']}");
        }
    }
}
