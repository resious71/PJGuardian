<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Tests\TestCase;

/**
 * 문서 일관성(P31 C-4): docs/·가이드·CLAUDE.md의 깨진 링크·없는 저장소 경로(tools/check_doc_links.php가 0으로 끝남),
 * docs/README.md 목차에 docs/의 모든 문서가 있음.
 */
final class DocLinksTest extends TestCase
{
    public function testNoBrokenLinksAndEveryDocIsIndexed(): void
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/check_doc_links.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $out);
        $index = (string) file_get_contents(PJG_ROOT . '/docs/README.md');
        $missing = [];
        foreach (array_merge(glob(PJG_ROOT . '/docs/*.md') ?: [], [PJG_ROOT . '/docs/openapi.yaml']) as $f) {
            $b = basename($f);
            if ($b !== 'README.md' && !str_contains($index, "({$b})")) {
                $missing[] = $b;
            }
        }
        $this->assertSame([], $missing, 'docs/README.md 목차에 없는 문서');
    }
}
