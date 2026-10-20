<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Analysis\AnalysisJob;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Tools\DefectWriter;
use PJGuardian\Services\Tools\EslintRunner;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\RuffConfigResolver;
use PJGuardian\Services\Tools\RuffRunner;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolAnalysisRunner;
use PJGuardian\Services\Tools\ToolExecution;
use PJGuardian\Services\Tools\ToolOutputException;
use PJGuardian\Services\Tools\ToolOutputParser;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 실제 ruff·ESLint 실행(명세 5.3, 6.1, 19.1, 19.10, P15). samples/가 정답 기준(samples/EXPECTED.md, 서버 기본 설정).
 * bin/ruff/0.16.10, bin/eslint/10.11.0과 Node가 있어야 한다(없으면 그 시험은 건너뜀을 출력하고 통과로 둔다).
 */
final class ToolRunnersTest extends TestCase
{
    private const SAMPLES = PJG_ROOT . '/samples';
    private App $app;
    private string $root;
    private ?string $node = null;

    public function setUp(): void
    {
        $this->root = $this->tempDir('trun');
        mkdir($this->root . '/work');
        mkdir($this->root . '/logs');
        $node = (new NodeRuntime(null, '20.19.0'))->path() ?? (is_file('C:/tools/nodejs/node.exe') ? 'C:/tools/nodejs/node.exe' : null);
        $this->node = $node;
        $this->app = TestApp::make($this->root . '/logs', [
            'paths' => ['work_dir' => $this->root . '/work', 'uploads_dir' => $this->root . '/work', 'snapshots_dir' => $this->root . '/work'],
            'tools' => ['node_path' => (string) $node],
        ]);
    }

    private function ruff(): ?SelectedTool
    {
        $exe = realpath(PJG_ROOT . '/bin/ruff/0.16.10/' . ToolRegistry::executableRel('ruff'));
        return $exe === false ? null : new SelectedTool('ruff', '0.16.10', ToolRegistry::os(), (string) realpath(PJG_ROOT . '/bin/ruff/0.16.10'), $exe);
    }

    private function eslint(?string $script = null): ?SelectedTool
    {
        $dir = realpath(PJG_ROOT . '/bin/eslint/10.11.0');
        if ($dir === false || $this->node === null) {
            return null;
        }
        $exe = $script ?? $dir . '/node_modules/eslint/bin/eslint.js';
        return new SelectedTool('eslint', '10.11.0', ToolRegistry::os(), $dir, (string) realpath($exe), (string) realpath($this->node), '24.0.0');
    }

    private function skip(string $why): void
    {
        fwrite(STDOUT, "      [건너뜀] {$why}\n");
    }

    private function matcher(array $patterns = []): ExcludeMatcher
    {
        return new ExcludeMatcher((array) $this->app->config->get('extract.default_excludes'), $patterns);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $file): array
    {
        return array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    private function project(array $files): string
    {
        $dir = $this->root . '/p' . bin2hex(random_bytes(3));
        foreach ($files as $rel => $content) {
            Fs::ensureDir(dirname($dir . '/' . $rel));
            file_put_contents($dir . '/' . $rel, $content);
        }
        return $dir;
    }

    // ---- 시료 대조 ----

    /** @needs-bin bin/ruff·bin/eslint 실행 파일과 Node */
    public function testSamplesMatchExpected(): void
    {
        $ruff = $this->ruff();
        $eslint = $this->eslint();
        if ($ruff === null || $eslint === null) {
            $this->skip('bin/ruff 또는 bin/eslint·Node 없음');
            return;
        }
        $cases = [
            ['ruff', 'python_sample', 19, 0, []],
            ['ruff', 'python_syntax_error', 2, 2, []],
            ['ruff', 'empty_no_target', 0, 0, []],
            ['eslint', 'js_sample', 5, 0, ['.eslintrc.json']],
            ['eslint', 'js_sample_noconfig', 5, 0, []],
            ['eslint', 'js_with_js_config', 4, 0, ['.eslintrc.js']],
            ['eslint', 'ts_sample', 6, 0, []],
            ['eslint', 'empty_no_target', 0, 0, []],
        ];
        $table = [];
        foreach ($cases as [$tool, $sample, $total, $syntax, $ignored]) {
            $out = "{$this->root}/{$tool}-{$sample}.ndjson";
            $r = $tool === 'ruff'
                ? $this->app->ruffRunner()->run($ruff, self::SAMPLES . "/{$sample}", $this->matcher(), $out)
                : $this->app->eslintRunner()->run($eslint, self::SAMPLES . "/{$sample}", $this->matcher(), $out);
            $this->assertTrue($r->ok, "{$tool} {$sample}: {$r->failureCode} {$r->detail}");
            $this->assertSame([$total, $syntax], [$r->stats['written'], $r->stats['syntax']], "{$tool} {$sample} 결함·문법 오류 수");
            $this->assertSame($ignored, array_column($r->ignored, 'file'), "{$tool} {$sample} 무시한 설정");
            $this->assertSame('server_default', $r->configSource, '업로드 ESLint 설정은 무시, ruff 시료에는 설정 없음');
            $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $r->configHash));
            $this->assertSame($total, count($this->rows($out)));
            $table[] = sprintf('%s %s 기대 %d / 실제 %d', $tool, $sample, $total, $r->stats['written']);
        }
        fwrite(STDOUT, '      [대조] ' . implode(', ', $table) . "\n");

        // python_sample 세부: EXPECTED.md의 위치·규칙, 한글 파일명, 같은 줄·같은 코드 중복
        $rows = $this->rows("{$this->root}/ruff-python_sample.ndjson");
        $keys = array_map(static fn (array $d): string => "{$d['file']}:{$d['line']}:{$d['column']}:{$d['rule_key']}", $rows);
        foreach (['app/handlers/api.py:2:8:ruff:F401', 'app/models.py:9:25:ruff:E711', 'app/models.py:14:25:ruff:E711',
            'app/models.py:20:11:ruff:F821', 'app/models.py:24:11:ruff:F821', 'utils/한글_도구.py:8:5:ruff:F841'] as $k) {
            $this->assertTrue(in_array($k, $keys, true), "기대 결함 {$k}");
        }
        $this->assertSame(['v', 'tool', 'language', 'rule_key', 'file', 'line', 'column', 'end_line', 'end_column', 'message', 'syntax_error', 'tool_severity'], array_keys($rows[0]));
        $this->assertSame(['ruff', 'python', false], [$rows[0]['tool'], $rows[0]['language'], $rows[0]['syntax_error']]);
        $syn = $this->rows("{$this->root}/ruff-python_syntax_error.ndjson");
        $this->assertSame(['broken.py', 4, 17, 'syntax-error', true], [$syn[0]['file'], $syn[0]['line'], $syn[0]['column'], $syn[0]['rule_key'], $syn[0]['syntax_error']]);
        $ts = $this->rows("{$this->root}/eslint-ts_sample.ndjson");
        $api2 = array_values(array_filter($ts, static fn (array $d): bool => $d['file'] === 'src/api.ts' && $d['line'] === 2));
        $this->assertSame([29, 35], array_column($api2, 'column'), '같은 줄 두 건(ESLint 결과도 P16 시그니처 시험에 씀)');
        $this->assertSame('typescript', $api2[0]['language']);
        $this->assertSame('eslint:@typescript-eslint/no-explicit-any', $api2[0]['rule_key']);
        $js = $this->rows("{$this->root}/eslint-js_with_js_config.ndjson");
        $this->assertFalse(in_array('.eslintrc.js', array_column($js, 'file'), true), 'JS 설정 파일은 분석 대상 아님');
    }

    // ---- ruff 설정 정책 ----

    /** @needs-bin bin/ruff 실행 파일 */
    public function testRuffExtendOutsideProjectIsRejected(): void
    {
        $outside = $this->root . '/outside.toml';
        file_put_contents($outside, "[lint]\nselect = [\"F401\"]\n");
        $resolver = new RuffConfigResolver(PJG_ROOT . '/config/ruff.default.toml');
        $cases = [
            '상대 경로 ../' => ['ruff.toml' => "extend = \"../outside.toml\"\n"],
            '절대 경로' => ['ruff.toml' => 'extend = "' . str_replace('\\', '/', $outside) . "\"\n"],
            '홈(~)' => ['ruff.toml' => "extend = \"~/outside.toml\"\n"],
            '환경 변수($)' => ['ruff.toml' => "extend = \"\$HOME/outside.toml\"\n"],
            '퍼센트(%)' => ['ruff.toml' => "extend = \"%USERPROFILE%/outside.toml\"\n"],
            '..로 나갔다 들어오기' => ['ruff.toml' => "extend = \"sub/../../outside.toml\"\n", 'sub/x.txt' => ''],
            '인라인 테이블' => ['pyproject.toml' => "[tool]\nruff = { extend = \"../outside.toml\" }\n"],
            '여러 줄 문자열' => ['.ruff.toml' => "extend = '''\n../outside.toml'''\n"],
            '사슬의 두 번째가 밖' => ['ruff.toml' => "extend = \"cfg/base.toml\"\n", 'cfg/base.toml' => "extend = \"../../outside.toml\"\n"],
            '없는 파일' => ['ruff.toml' => "extend = \"nope.toml\"\n"],
            '순환' => ['ruff.toml' => "extend = \"a.toml\"\n", 'a.toml' => "extend = \"ruff.toml\"\n"],
        ];
        foreach ($cases as $name => $files) {
            $dir = $this->project($files);
            $c = $resolver->resolve($dir);
            $this->assertSame('server_default', $c['source'], $name);
            $this->assertSame('ruff_config_rejected', $c['ignored'][0]['reason'] ?? null, $name);
        }
        // 프로젝트 안의 extend 사슬은 허용, pyproject.toml의 [tool.ruff]도 인식
        $ok = $this->project(['ruff.toml' => "extend = \"cfg/base.toml\"\n", 'cfg/base.toml' => "[lint]\nselect = [\"F401\"]\n", 'a.py' => "import os\nx == None\n"]);
        $c = $resolver->resolve($ok);
        $this->assertSame(['upload', 2], [$c['source'], count($c['files'])]);
        $py = $this->project(['pyproject.toml' => "[project]\nname = \"x\"\n\n[tool.ruff.lint]\nselect = [\"E711\"]\n"]);
        $this->assertSame('pyproject.toml', RuffConfigResolver::findRootConfig($py));
        $noRuff = $this->project(['pyproject.toml' => "[project]\nname = \"x\"\n[tool.black]\nextend = \"../x\"\n"]);
        $this->assertSame([null, 'server_default'], [RuffConfigResolver::findRootConfig($noRuff), $resolver->resolve($noRuff)['source']], 'ruff 설정 없는 pyproject는 쓰지 않음');

        $ruff = $this->ruff();
        if ($ruff === null) {
            $this->skip('bin/ruff 없음');
            return;
        }
        // 실제 실행: 업로드 설정(F401만) 적용, 하위 폴더 설정은 쓰지 않고 "무시됨"
        Fs::ensureDir($ok . '/sub');
        file_put_contents($ok . '/sub/ruff.toml', "[lint]\nselect = [\"E\"]\n");
        file_put_contents($ok . '/sub/b.py', "y == None\nimport sys\n");
        $r = $this->app->ruffRunner()->run($ruff, $ok, $this->matcher(), $this->root . '/ok.ndjson');
        $this->assertTrue($r->ok, (string) $r->detail);
        $this->assertSame('upload', $r->configSource);
        $this->assertSame(['ruff:F401' => 2], $r->stats['by_rule'], '업로드 설정(F401만) 적용, 하위 설정(E) 미적용');
        $this->assertSame([['file' => 'sub/ruff.toml', 'reason' => 'ruff_nested_config']], $r->ignored);
        // 거부된 설정 → 서버 기본(E, F)으로 분석
        $bad = $this->project(['ruff.toml' => "extend = \"../outside.toml\"\n", 'a.py' => "import os\nx == None\n"]);
        $r2 = $this->app->ruffRunner()->run($ruff, $bad, $this->matcher(), $this->root . '/bad.ndjson');
        $this->assertSame(['server_default', ['ruff:E711' => 1, 'ruff:F401' => 1, 'ruff:F821' => 1]], [$r2->configSource, $r2->stats['by_rule']]);
        $this->assertTrue($r->configHash !== $r2->configHash, '설정 해시는 실제 적용한 설정에 따라 다름');
        // .gitignore는 따르지 않는다
        $gi = $this->project(['.gitignore' => "*.py\n", 'a.py' => "import os\n"]);
        $r3 = $this->app->ruffRunner()->run($ruff, $gi, $this->matcher(), $this->root . '/gi.ndjson');
        $this->assertSame(1, $r3->stats['written'], '업로드된 .gitignore 무시');
    }

    // ---- ESLint 설정 정책 ----

    public function testEslintIgnoreConversion(): void
    {
        $this->assertSame(['**/gen', '**/gen/**', 'dist/**', 'src/legacy.js', 'src/legacy.js/**', '!**/keep.js', '!**/keep.js/**'],
            EslintRunner::eslintIgnorePatterns("# 주석\n\ngen\n/dist/\nsrc/legacy.js\n!keep.js\n../escape\n"));
    }

    /** @needs-bin bin/eslint(node_modules)와 Node */
    public function testEslintIgnoresUploadedConfigAndAppliesEslintignore(): void
    {
        $eslint = $this->eslint();
        if ($eslint === null) {
            $this->skip('bin/eslint·Node 없음');
            return;
        }
        $marker = $this->root . '/executed.txt';
        $dir = $this->project([
            'src/a.js' => "const unusedA = 1;\n",
            'gen/b.js' => "const unusedB = 1;\n",
            'node_modules/x/c.js' => "const unusedC = 1;\n",
            'eslint.config.js' => "require('fs').writeFileSync(" . var_export($marker, true) . ", 'x');\nconst unusedInConfig = 1;\nmodule.exports = [];\n",
            '.eslintrc.js' => "module.exports = { rules: { 'no-unused-vars': 'off' } };\n",
            'sub/.eslintrc.json' => "{\"rules\":{\"no-unused-vars\":\"off\"}}\n",
            '.eslintignore' => "gen/\n",
            '.gitignore' => "src/\n",
        ]);
        $r = $this->app->eslintRunner()->run($eslint, $dir, $this->matcher(), $this->root . '/e.ndjson');
        $this->assertTrue($r->ok, (string) $r->detail);
        $this->assertSame(['src/a.js'], array_values(array_unique(array_column($this->rows($this->root . '/e.ndjson'), 'file'))),
            '.eslintignore(gen/) 적용, node_modules 제외, 설정 파일 분석 안 함, .gitignore 무시');
        $this->assertSame(['.eslintrc.js', 'eslint.config.js', 'sub/.eslintrc.json', '.eslintignore'], array_column($r->ignored, 'file'));
        $this->assertSame(['eslint_config_ignored', 'eslintignore_converted'], array_values(array_unique(array_column($r->ignored, 'reason'))));
        $this->assertSame(1, $r->ignored[3]['count'], 'P19: .eslintignore 변환 기록(패턴 수)');
        $this->assertFalse(is_file($marker), '업로드된 eslint.config.js를 실행하지 않음');
    }

    /**
     * P19B: 실제 ESLint가 U+2028·U+2029를 줄바꿈으로 세는지, 그 줄 번호가 시그니처·코드 뷰어의 줄 나누기(SourceNormalizer, ESLint만 U+2028/2029)와 맞는지.
     * (P16은 줄 나누기 단위 시험만 했다)
     * @needs-bin bin/eslint(node_modules)와 Node
     */
    public function testEslintLineSeparatorMatchesSourceLines(): void
    {
        $eslint = $this->eslint();
        if ($eslint === null) {
            $this->skip('bin/eslint·Node 없음');
            return;
        }
        // 블록 주석 안의 U+2028, 문자열 안의 U+2029(ES2019부터 문자열에 허용) 뒤에 쓰지 않는 변수
        $src = "/* 첫 줄\u{2028}둘째 줄도 주석 */\nconst s = 'a\u{2029}b';\nexport { s };\nfunction f() { const unusedAfter = 1; }\nexport { f };\n";
        // 한 줄 주석(//) 안의 U+2028은 주석을 끝낸다(JS 문법) → 나머지가 코드가 되어 문법 오류가 2번째 줄에 난다
        $dir = $this->project(['src/ls.js' => $src, 'src/lc.js' => "// 첫 줄\u{2028}둘째 줄\n"]);
        $r = $this->app->eslintRunner()->run($eslint, $dir, $this->matcher(), $this->root . '/ls.ndjson');
        $this->assertTrue($r->ok, (string) $r->detail);
        $hit = array_values(array_filter($this->rows($this->root . '/ls.ndjson'), static fn (array $d): bool => $d['rule_key'] === 'eslint:no-unused-vars'));
        $this->assertSame(1, count($hit), json_encode($this->rows($this->root . '/ls.ndjson'), JSON_UNESCAPED_UNICODE));
        $syntax = array_values(array_filter($this->rows($this->root . '/ls.ndjson'), static fn (array $d): bool => $d['file'] === 'src/lc.js'));
        $this->assertSame([['syntax-error', 2]], array_map(static fn (array $d): array => [$d['rule_key'], $d['line']], $syntax), '한 줄 주석의 U+2028 뒤는 2번째 줄');
        $lines = \PJGuardian\Services\Defects\SourceNormalizer::splitLines($src, 'eslint')['lines'];
        $this->assertSame(6, $hit[0]['line'], 'ESLint: U+2028(주석)·U+2029(문자열)를 줄바꿈으로 센다');
        $this->assertContains('unusedAfter', $lines[$hit[0]['line'] - 1], '같은 줄 번호가 우리 줄 나누기의 그 줄을 가리킨다');
        $this->assertSame(2, count($lines) - count(\PJGuardian\Services\Defects\SourceNormalizer::splitLines($src, 'ruff')['lines']), 'ruff 기준(\\r\\n·\\r·\\n만)으로는 두 줄 적다 — 도구별로 다르게 나눈다');
    }

    /** @needs-bin bin/eslint(node_modules)와 Node */
    public function testEslintTestFileGlobalsOnlyInTestFiles(): void
    {
        // X07: 서버 기본 설정의 테스트 파일 덮어쓰기(jasmine·mocha·jest 환경 + vi·vitest). 테스트 파일에서만 켜지고 진짜 no-undef는 그대로
        $eslint = $this->eslint();
        if ($eslint === null) {
            $this->skip('bin/eslint·Node 없음');
            return;
        }
        $dir = $this->project([
            'src/app.js' => "export function f() { return undefinedThing + 1; }\n",
            'src/notatest.js' => "describe('x', () => {});\nexpect(1);\n",
            'src/app.test.js' => "describe('a', () => { it('b', () => { expect(1).toBe(1); spyOn(console, 'log'); jasmine.any(Number); vi.fn(); notAGlobal(); }); });\n",
            'src/ts.spec.ts' => "describe('t', () => { it('u', () => { expect(1 as number).toBe(1); }); });\n",
            'test/helper.js' => "beforeEach(() => {}); afterAll(() => {}); xit('a', () => {}); fdescribe('b', () => {}); test('c', () => {}); jest.fn(); before(() => {}); window.x = 1;\n",
            'pkg/tests/x.js' => "after(() => {}); xdescribe('d', () => {}); fit('e', () => {}); pending(); fail('f');\n",
            'pkg/__tests__/y.js' => "beforeAll(() => {}); afterEach(() => {}); expect(vitest).toBeDefined();\n",
            'lib/spec/z.js' => "it('g', () => { expect(true).toBe(true); });\n",
        ]);
        $undef = static function (string $file) use (&$rows): array {
            $out = [];
            foreach ($rows as $r) {
                if ($r['rule_key'] === 'eslint:no-undef') {
                    $out[$r['file']][] = preg_match("/^'([^']+)'/", (string) $r['message'], $m) === 1 ? $m[1] : '?';
                }
            }
            ksort($out);
            return $out;
        };
        $r = $this->app->eslintRunner()->run($eslint, $dir, $this->matcher(), $this->root . '/g.ndjson');
        $this->assertTrue($r->ok, (string) $r->detail);
        $rows = $this->rows($this->root . '/g.ndjson');
        $this->assertSame([
            'src/app.js' => ['undefinedThing'],            // 테스트가 아닌 파일의 진짜 정의되지 않은 이름
            'src/app.test.js' => ['notAGlobal'],            // 테스트 파일이라도 테스트 전역이 아닌 이름
            'src/notatest.js' => ['describe', 'expect'],   // 테스트가 아닌 파일에는 테스트 전역을 켜지 않음
        ], $undef(''), '테스트 파일(*.test.*, *.spec.*, test/, tests/, __tests__/, spec/)의 테스트 전역은 no-undef가 아님');
        // 같은 시료를 덮어쓰기 없는 이전 설정으로: 테스트 전역이 no-undef로 나오고 설정 해시가 다르다
        $base = json_decode((string) file_get_contents(PJG_ROOT . '/config/eslint.default.json'), true);
        $base['overrides'] = array_values(array_filter($base['overrides'], static fn (array $o): bool => !isset($o['env'])));
        file_put_contents($this->root . '/old.json', json_encode($base));
        $old = new EslintRunner($this->app->toolExecution(), PJG_ROOT, $this->root . '/old.json', (array) $this->app->config->get('upload.target_extensions_javascript'), $this->app->logger);
        $r0 = $old->run($eslint, $dir, $this->matcher(), $this->root . '/g0.ndjson');
        $this->assertTrue($r0->ok, (string) $r0->detail);
        $rows = $this->rows($this->root . '/g0.ndjson');
        $this->assertTrue(count(array_merge(...array_values($undef('')))) > 20, '이전 설정에서는 테스트 전역도 no-undef');
        $this->assertTrue($r0->configHash !== $r->configHash, '설정이 바뀌면 설정 해시가 바뀐다(비교에서 "버전/설정 변경")');
    }

    // ---- 결과 정규화 방어 ----

    public function testWriterDropsOutsideMissingAndExcludedPaths(): void
    {
        $dir = $this->project(['a.py' => 'x', 'skip/b.py' => 'y']);
        file_put_contents($this->root . '/outside.py', 'z');
        $w = new DefectWriter($this->root . '/w.ndjson', $dir, $this->matcher(['skip/']), $this->app->logger);
        $base = ['tool' => 'ruff', 'language' => 'python', 'rule_key' => 'ruff:F401', 'line' => 1, 'column' => 1, 'end_line' => null, 'end_column' => null, 'message' => 'm', 'syntax_error' => false, 'tool_severity' => null];
        foreach ([$dir . '/a.py', 'a.py', $this->root . '/outside.py', $dir . '/../outside.py', $dir . '/none.py', $dir . '/skip/b.py'] as $f) {
            $w->write(['file' => $f] + $base);
        }
        $s = $w->close();
        $this->assertSame(2, $s['written'], '절대·상대 경로 모두 루트 기준으로');
        $this->assertSame(['outside' => 2, 'missing' => 1, 'excluded' => 1], $s['skipped']);
        $this->assertSame(['a.py', 'a.py'], array_column($this->rows($this->root . '/w.ndjson'), 'file'));
        // NFD 파일 이름도 NFC로
        $nfd = "\u{1112}\u{1161}\u{11AB}.py";
        file_put_contents($dir . '/' . $nfd, 'k');
        $w2 = new DefectWriter($this->root . '/w2.ndjson', $dir, $this->matcher(), $this->app->logger);
        $w2->write(['file' => $dir . '/' . $nfd] + $base);
        $w2->close();
        $this->assertSame("\u{D55C}.py", $this->rows($this->root . '/w2.ndjson')[0]['file']);
    }

    public function testParserIgnoresUnknownFieldsAndRequiresEssentials(): void
    {
        $f = $this->root . '/ruff.jsonl';
        file_put_contents($f, json_encode(['filename' => 'a.py', 'location' => ['row' => 1, 'column' => 2], 'message' => 'm', 'code' => 'F401', 'brand_new_field' => [1, 2]]) . "\n\n"
            . json_encode(['filename' => 'b.py', 'location' => ['row' => 3, 'column' => 1], 'message' => 'SyntaxError: x', 'code' => null]) . "\n");
        $got = [];
        ToolOutputParser::ruff($f, static function (array $d) use (&$got): void {
            $got[] = $d['rule_key'];
        });
        $this->assertSame(['ruff:F401', 'syntax-error'], $got, '알 수 없는 필드 무시, code null = 문법 오류(ruff 0.12 이하)');
        file_put_contents($f, json_encode(['filename' => 'a.py', 'location' => ['column' => 2], 'message' => 'm', 'code' => 'F']) . "\n");
        $this->assertThrows(ToolOutputException::class, fn () => ToolOutputParser::ruff($f, static fn () => null), 'location.row');
        $e = $this->root . '/eslint.jsonl';
        file_put_contents($e, json_encode(['filePath' => 'x.ts', 'ruleId' => null, 'fatal' => true, 'line' => 1, 'message' => 'Parsing error']) . "\n"
            . json_encode(['filePath' => 'x.js', 'ruleId' => null, 'fatal' => false, 'line' => 2, 'message' => 'Unused eslint-disable directive (no problems were reported).']) . "\n");
        $keys = [];
        ToolOutputParser::eslint($e, static function (array $d) use (&$keys): void {
            $keys[] = $d['rule_key'] . '/' . $d['language'];
        });
        $this->assertSame(['syntax-error/typescript', 'eslint:unused-disable-directive/javascript'], $keys);
    }

    // ---- 도구 실패 ----

    /** @needs-bin bin/ruff·bin/eslint 실행 파일과 Node */
    public function testToolFailuresMapToFailureCodesWithoutResultFile(): void
    {
        $ruff = $this->ruff();
        $eslint = $this->eslint();
        if ($ruff === null || $eslint === null) {
            $this->skip('bin/ruff 또는 bin/eslint·Node 없음');
            return;
        }
        $exec = fn (int $timeout = 60, int $limit = 100 * 1024 * 1024): ToolExecution => new ToolExecution(
            new ProcessRunner([PJG_ROOT . '/bin'], [(string) $this->node]), $timeout, $limit, 512 * 1024 * 1024, $this->root . '/tmp');
        $ruffRunner = fn (ToolExecution $x): RuffRunner => new RuffRunner($x, new RuffConfigResolver(PJG_ROOT . '/config/ruff.default.toml'), $this->app->logger);
        $eslintRunner = fn (ToolExecution $x): EslintRunner => new EslintRunner($x, PJG_ROOT, PJG_ROOT . '/config/eslint.default.json', ['.js', '.jsx', '.ts', '.tsx'], $this->app->logger);
        $results = [];
        // ruff 설정 오류(업로드 설정의 알 수 없는 규칙) → tool_config_error
        $badCfg = $this->project(['ruff.toml' => "[lint]\nselect = [\"NOPE999\"]\n", 'a.py' => "import os\n"]);
        $results['ruff 설정 오류'] = [$ruffRunner($exec())->run($ruff, $badCfg, $this->matcher(), $this->root . '/f1.ndjson'), 'tool_config_error', $this->root . '/f1.ndjson'];
        // 출력 한도(10바이트) → output_limit
        $results['ruff 출력 한도'] = [$ruffRunner($exec(60, 10))->run($ruff, self::SAMPLES . '/python_sample', $this->matcher(), $this->root . '/f2.ndjson'), 'output_limit', $this->root . '/f2.ndjson'];
        // 가짜 ESLint 스크립트(Node로 실행)
        $fake = static fn (string $s): string => PJG_ROOT . '/tests/Support/tools/' . $s;
        $js = $this->project(['a.js' => "const a = 1;\n"]);
        foreach ([['crash.cjs', 'tool_crash'], ['badjson.cjs', 'tool_crash'], ['missingfield.cjs', 'tool_crash'], ['oom.cjs', 'memory_limit'], ['configerror.cjs', 'tool_config_error']] as $i => [$s, $code]) {
            $out = $this->root . "/g{$i}.ndjson";
            $results["ESLint {$s}"] = [$eslintRunner($exec())->run($this->eslint($fake($s)), $js, $this->matcher(), $out), $code, $out];
        }
        $t0 = microtime(true);
        $results['ESLint 타임아웃'] = [$eslintRunner($exec(1))->run($this->eslint($fake('sleep.cjs')), $js, $this->matcher(), $this->root . '/t.ndjson'), 'timeout', $this->root . '/t.ndjson'];
        $this->assertTrue(microtime(true) - $t0 < 10, '타임아웃 1초에서 곧 종료');
        foreach ($results as $name => [$r, $code, $file]) {
            $this->assertSame([false, $code], [$r->ok, $r->failureCode], $name . ': ' . mb_substr((string) $r->detail, 0, 200));
            $this->assertFalse(is_file($file) || is_file($file . '.part'), $name . ': 결과 파일 미생성');
        }
        // 분류 규칙(실행 없이): Rust 메모리 할당 실패, 시작 실패
        $mk = static fn (string $reason, ?int $exit, string $stderr): ProcessResult => (static function () use ($reason, $exit, $stderr): ProcessResult {
            $err = tempnam(sys_get_temp_dir(), 'pje');
            file_put_contents($err, $stderr);
            return new ProcessResult($reason, $exit, 1, 1, '', $err, 0, 100);
        })();
        $never = static fn (): bool => false;
        $this->assertSame('memory_limit', ToolExecution::classify($mk('normal', 134, 'memory allocation of 1048576 bytes failed'), [0], $never));
        $this->assertSame('tool_crash', ToolExecution::classify($mk('start_failed', null, ''), [0], $never));
        $this->assertSame(null, ToolExecution::classify($mk('normal', 1, ''), [0, 1], $never));
    }

    // ---- 분석 실행기(레지스트리·디스크 검사·기록) ----

    /** @needs-bin bin/ruff 실행 파일 */
    public function testAnalysisRunnerEndToEnd(): void
    {
        if ($this->ruff() === null) {
            $this->skip('bin/ruff 없음');
            return;
        }
        $db = $this->app->db();
        $user = TestDb::user($db, 'kim', 'user');
        $project = TestDb::project($db, 'py', 'python');
        $work = $this->root . '/work/abcd0001';
        Fs::ensureDir($work);
        foreach (['main.py', 'config_loader.py'] as $f) {
            copy(self::SAMPLES . '/python_sample/' . $f, $work . '/' . $f);
        }
        file_put_contents($work . '/eslint.config.js', '// js config in python project');
        $id = TestDb::analysis($db, $project, $user, ['status' => 'running', 'work_dir' => 'abcd0001', 'tool_version' => null, 'config_source' => null, 'config_hash' => null]);
        $job = new AnalysisJob($id, $project, 'ruff', $work, []);
        $t0 = microtime(true);
        $o = $this->app->analysisRunner()->run($job);
        $first = microtime(true) - $t0;
        $this->assertTrue($o->succeeded, (string) $o->failureCode . ' ' . $o->detail);
        $file = ToolAnalysisRunner::resultFile($this->app->resultsDir(), $id);
        $this->assertSame(5, count($this->rows($file)), 'main.py 3 + config_loader.py 2');
        $this->assertSame(5, $o->counts['total_count']);
        $this->assertSame('active', $db->value("SELECT status FROM tool_versions WHERE tool = 'ruff' AND version = '0.16.10'"), '처음 쓰는 버전: 체크섬 + 스모크');
        $this->assertTrue($this->app->analysisQueue()->complete($id, $o));
        $row = $db->one('SELECT status, tool_version, config_source, config_hash, ignored_configs, total_count FROM analyses WHERE id = ?', [$id]);
        $this->assertSame(['succeeded', '0.16.10', 'server_default', 5], [$row['status'], $row['tool_version'], $row['config_source'], (int) $row['total_count']]);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', (string) $row['config_hash']));
        $this->assertSame('[]', $row['ignored_configs']);
        // 두 번째는 지문만 대조(스모크 없음)라 빠르다
        $t0 = microtime(true);
        $this->assertTrue($this->app->analysisRunner()->run(new AnalysisJob($id + 1000, $project, 'ruff', $work, []))->succeeded);
        fwrite(STDOUT, sprintf("      [측정] 첫 분석(체크섬+스모크 포함) %.2f초, 두 번째 %.2f초\n", $first, microtime(true) - $t0));

        // 디스크 여유 부족(출력 한도 + 여유) → insufficient_storage, 결과 파일 없음
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1024.0, static fn (): float => 1e12));
        $o2 = $this->app->analysisRunner()->run(new AnalysisJob($id + 1, $project, 'ruff', $work, []));
        $this->assertSame([false, 'insufficient_storage'], [$o2->succeeded, $o2->failureCode]);
        $this->assertFalse(is_file(ToolAnalysisRunner::resultFile($this->app->resultsDir(), $id + 1)));
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
        // 쓸 수 있는 버전 없음(차단) → tool_unavailable
        $this->app->toolRegistry()->block('ruff', '0.16.10');
        $o3 = $this->app->analysisRunner()->run(new AnalysisJob($id + 2, $project, 'ruff', $work, []));
        $this->assertSame([false, 'tool_unavailable'], [$o3->succeeded, $o3->failureCode]);
        $this->app->toolRegistry()->unblock('ruff', '0.16.10');
    }
}
