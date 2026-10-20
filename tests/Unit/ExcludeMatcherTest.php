<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/**
 * 제외 패턴 판정기(명세 4장, 5.1, 19.10). 해제(P13)·신규/해결 판정(P16)·브라우저 사전 제외(P12)가 모두 이것만 쓴다.
 * 문법: docs/architecture.md "제외 패턴" 절.
 */
final class ExcludeMatcherTest extends TestCase
{
    private const DEFAULTS = ['node_modules', '.venv', 'venv', '__pycache__', '.git', 'dist', 'build', '__MACOSX', '.DS_Store'];

    private function m(array $patterns = [], ?array $defaults = null): ExcludeMatcher
    {
        return new ExcludeMatcher($defaults ?? self::DEFAULTS, $patterns);
    }

    public function testDefaultsComeFromConfig(): void
    {
        $this->assertSame(self::DEFAULTS, TestApp::config()->get('extract.default_excludes'), 'config.ini.example의 기본 목록(명세 4장 + 19.10)');
    }

    public function testDefaultNamesMatchWholeSegmentsOnly(): void
    {
        $m = $this->m();
        foreach (['node_modules/a.js', 'src/node_modules/x/y.js', '.git/config', 'pkg/__pycache__/m.pyc', 'dist/app.js', 'a/b/build/out.txt', '__MACOSX/._main.py', 'src/.DS_Store', '.venv/lib/x.py', 'venv'] as $p) {
            $this->assertTrue($m->matches($p), '제외: ' . $p);
        }
        foreach (['builder/tool.py', 'src/dist.py', 'my_dist/a.py', 'distribution/x', 'src/build.py', 'node_modules2/a.js', 'a/.gitignore', 'venv.py', 'src/app.py'] as $p) {
            $this->assertFalse($m->matches($p), '제외 아님: ' . $p);
        }
    }

    public function testExplainNamesTheRule(): void
    {
        $m = $this->m(['*.log', '/docs/**']);
        $this->assertSame('default:node_modules', $m->explain('web/node_modules/react/index.js'));
        $this->assertSame('pattern:*.log', $m->explain('logs/app.log'));
        $this->assertSame('pattern:/docs/**', $m->explain('docs/guide/a.md'));
        $this->assertSame(null, $m->explain('src/app.py'));
    }

    public function testSingleStarAndQuestionMark(): void
    {
        $m = $this->m(['*.log', 'tmp?', 'gen/*.py']);
        $this->assertTrue($m->matches('app.log'));
        $this->assertTrue($m->matches('a/b/c.log'), '슬래시 없는 패턴은 어느 깊이에서든 이름과 비교');
        $this->assertFalse($m->matches('app.logs'));
        $this->assertTrue($m->matches('tmp1/x.py'));
        $this->assertFalse($m->matches('tmp12/x.py'), '?는 한 글자');
        $this->assertTrue($m->matches('gen/a.py'));
        $this->assertTrue($m->matches('src/gen/a.py'), '앞에 / 없으면 어느 깊이에서든 시작');
        $this->assertFalse($m->matches('gen/sub/a.py'), '*는 /를 넘지 않음');
    }

    public function testDoubleStarAndRootAnchor(): void
    {
        $m = $this->m(['/docs/**', 'tests/**/fixtures', '/setup.py']);
        $this->assertTrue($m->matches('docs/a.md'));
        $this->assertTrue($m->matches('docs/x/y/z.md'));
        $this->assertFalse($m->matches('src/docs/a.md'), '앞의 /는 루트 기준');
        $this->assertFalse($m->matches('docs'), '끝의 **는 "그 아래 전부": docs라는 이름의 파일은 제외하지 않음');
        $this->assertTrue($m->matches('tests/fixtures/data.json'), '**는 0개 이상 경로 조각');
        $this->assertTrue($m->matches('tests/a/b/fixtures/data.json'));
        $this->assertTrue($m->matches('pkg/tests/unit/fixtures/x'));
        $this->assertTrue($m->matches('setup.py'));
        $this->assertFalse($m->matches('pkg/setup.py'));
    }

    public function testTrailingSlashMatchesDirectoriesOnly(): void
    {
        $m = $this->m(['out/']);
        $this->assertTrue($m->matches('out/a.txt'));
        $this->assertTrue($m->matches('src/out/a.txt'));
        $this->assertFalse($m->matches('out'), '같은 이름의 파일은 제외하지 않음');
        $this->assertFalse($m->matches('src/out'));
    }

    public function testCaseSensitive(): void
    {
        $m = $this->m(['*.LOG', 'Temp']);
        $this->assertTrue($m->matches('a.LOG'));
        $this->assertFalse($m->matches('a.log'), '대소문자 구분');
        $this->assertFalse($m->matches('temp/x'), '대소문자 구분');
        $this->assertFalse($this->m()->matches('Node_Modules/a.js'), '기본 목록도 대소문자 구분');
    }

    public function testKoreanPathsAreComparedAfterNfc(): void
    {
        $nfc = \Normalizer::normalize('문서', \Normalizer::FORM_C);
        $nfd = \Normalizer::normalize('문서', \Normalizer::FORM_D);
        $this->assertTrue($nfc !== $nfd, '시험 전제: NFC와 NFD 바이트가 다름');
        $byNfc = $this->m(['/' . $nfc . '/**']);
        $this->assertTrue($byNfc->matches($nfd . '/a.txt'), 'NFD 경로도 NFC 패턴과 일치(macOS zip)');
        $this->assertTrue($byNfc->matches($nfc . '/a.txt'));
        $byNfd = $this->m(['/' . $nfd . '/**']);
        $this->assertTrue($byNfd->matches($nfc . '/a.txt'), 'NFD로 저장한 패턴도 NFC로 정규화');
        $this->assertTrue($this->m(['*.txt'])->matches('한글/파일.txt'));
    }

    public function testBackslashIsTreatedAsSeparatorForSafety(): void
    {
        $this->assertTrue($this->m()->matches('src\\node_modules\\a.js'));
    }

    public function testInvalidPatternsAreRejected(): void
    {
        $bad = [
            '' => 'empty', '   ' => 'empty', '../x' => 'dot_segment', 'a/../b' => 'dot_segment', './a' => 'dot_segment',
            'C:/x' => 'absolute', '//server/share' => 'absolute', 'a\\b' => 'backslash', "a\x00b" => 'control_char',
            'a**b' => 'bad_double_star', '**x/y' => 'bad_double_star', '[abc]' => 'unsupported_syntax', '{a,b}' => 'unsupported_syntax',
            '!keep' => 'unsupported_syntax', '/' => 'empty', str_repeat('a', 201) => 'too_long', 'a//b' => 'empty_segment',
        ];
        foreach ($bad as $pattern => $reason) {
            $this->assertSame($reason, ExcludeMatcher::validatePattern((string) $pattern), json_encode((string) $pattern));
        }
        foreach (['*.log', '/docs/**', 'tests/**/fixtures', 'out/', 'tmp?', '.cache', '한글폴더', '**/gen'] as $ok) {
            $this->assertSame(null, ExcludeMatcher::validatePattern($ok), $ok);
        }
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->m(['../x']));
    }

    public function testNormalizePatternList(): void
    {
        [$list, $errors] = ExcludeMatcher::normalizePatterns(["  *.log ", '', 'out/', '*.log', "\t", '../x']);
        $this->assertSame(['*.log', 'out/'], $list, '앞뒤 공백 제거, 빈 줄 무시, 중복 제거');
        $this->assertSame([['pattern' => '../x', 'reason' => 'dot_segment']], $errors);
    }

    public function testEmptyLists(): void
    {
        $none = new ExcludeMatcher([], []);
        $this->assertFalse($none->matches('node_modules/a.js'), '기본 목록이 비면 아무것도 제외하지 않음');
        $this->assertSame(null, $none->explain('a'));
        $this->assertTrue($this->m([])->matches('dist/x'), '추가 패턴이 없어도 기본 목록은 적용');
    }
}
