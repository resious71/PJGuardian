<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Archive\SafeUnzip;
use PJGuardian\Services\Archive\UnzipException;
use PJGuardian\Services\Archive\UnzipLimits;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\ZipBuilder;
use PJGuardian\Tests\TestCase;

/**
 * 안전 해제(명세 5.1, 10, 15.2, 19.10): 프로토타입 시험 62건(tests/proto/unzip_test.php)을 제품 코드로 옮기고 보강했다.
 * - 거부 시험마다 깊은 샌드박스(data/work/p13t_<임의>/l1/l2/l3/out)에 풀어 "../" 탈출도 샌드박스 안에서 잡히게 하고,
 *   작업 디렉토리 밖에 새로 생긴 파일 수를 실제 파일 존재로 센다(전부 0이어야 한다). 합계는 static::$outsideTotal.
 * - 한도는 config.ini.example의 값을 읽어 일부만 낮춘다(코드에 고정하지 않는다).
 * - 악의적 zip은 tools/make_bad_zips.py가 만든 samples/zips_bad/(17종), 그 밖의 경우는 ZipBuilder로 만든다.
 */
final class SafeUnzipTest extends TestCase
{
    private const BAD = PJG_ROOT . '/samples/zips_bad';
    private const GOOD = PJG_ROOT . '/samples/zips';
    /** 거부 시험 전체에서 작업 디렉토리 밖에 새로 생긴 파일 수(보고용) */
    public static int $outsideTotal = 0;
    public static int $rejectCases = 0;

    /** @var list<string> */
    private array $sandboxes = [];

    public function tearDown(): void
    {
        foreach ($this->sandboxes as $sb) {
            Fs::removeTree($sb);
        }
        parent::tearDown();
    }

    private function limits(array $over = []): UnzipLimits
    {
        $base = UnzipLimits::fromConfig(TestApp::config());
        return new UnzipLimits($over['zip'] ?? $base->maxZipBytes, $over['total'] ?? $base->maxTotalBytes, $over['files'] ?? $base->maxFiles);
    }

    private function unzip(array $limits = [], array $patterns = []): SafeUnzip
    {
        $defaults = (array) TestApp::config()->get('extract.default_excludes');
        return new SafeUnzip($this->limits($limits), new ExcludeMatcher($defaults, $patterns));
    }

    /** @return array{base:string, dest:string} */
    private function sandbox(): array
    {
        $base = PJG_ROOT . '/data/work/p13t_' . bin2hex(random_bytes(4));
        mkdir($base . '/l1/l2/l3', 0700, true);
        $base = str_replace('\\', '/', (string) realpath($base));
        $this->sandboxes[] = $base;
        return ['base' => $base, 'dest' => $base . '/l1/l2/l3/out'];
    }

    /** @return list<string> 탈출 결과가 생길 수 있는 위치 */
    private static function escapeCandidates(array $sb): array
    {
        $names = ['evil.txt', 'a/evil.txt', 'etc/evil.txt', 'server/share/evil.txt', 'passwd'];
        $root = str_replace('\\', '/', (string) realpath(PJG_ROOT));
        $bases = [$sb['dest'], $sb['base'] . '/l1/l2/l3', $sb['base'] . '/l1/l2', $sb['base'] . '/l1', $sb['base'],
            dirname($sb['base']), $root, dirname($root), dirname($root, 2), str_replace('\\', '/', (string) getcwd())];
        $out = ['C:/evil.txt', '/etc/evil.txt', '/evil.txt'];
        foreach ($bases as $b) {
            foreach ($names as $n) {
                $out[] = $b . '/' . $n;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private static function filesUnder(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
            }
        }
        sort($out);
        return $out;
    }

    /**
     * 거부되어야 한다: 오류 코드, 작업 디렉토리 삭제, 샌드박스 잔여 파일 0, 밖에 새 파일 0.
     */
    private function expectReject(string $zip, string $code, array $limits = [], string $stage = '', array $patterns = [], ?bool $strip = null): UnzipException
    {
        $sb = $this->sandbox();
        $candidates = self::escapeCandidates($sb);
        $before = array_filter($candidates, 'file_exists');
        try {
            $this->unzip($limits, $patterns)->extract($zip, $sb['dest'], $strip);
            $this->fail(basename($zip) . ": {$code}로 거부되어야 함");
        } catch (UnzipException $e) {
            $outside = array_values(array_diff(array_filter($candidates, 'file_exists'), $before));
            self::$outsideTotal += count($outside);
            self::$rejectCases++;
            $this->assertSame($code, $e->errorCode, basename($zip) . ' ' . json_encode($e->detail, JSON_UNESCAPED_UNICODE));
            $this->assertSame([], $outside, basename($zip) . ': 작업 디렉토리 밖에 새로 생긴 파일');
            $this->assertFalse(file_exists($sb['dest']), basename($zip) . ': 작업 디렉토리 삭제');
            $this->assertSame([], self::filesUnder($sb['base']), basename($zip) . ': 샌드박스 잔여 파일');
            if ($stage !== '') {
                $this->assertSame($stage, $e->detail['stage'] ?? null, basename($zip) . ' 단계');
            }
            return $e;
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<string>, 2: string} */
    private function extractOk(string $zip, array $limits = [], array $patterns = [], ?bool $strip = null): array
    {
        $sb = $this->sandbox();
        $r = $this->unzip($limits, $patterns)->extract($zip, $sb['dest'], $strip);
        return [$r, self::filesUnder($sb['dest']), $sb['dest']];
    }

    private function built(callable $fill): string
    {
        $sb = $this->sandbox();
        $b = new ZipBuilder($sb['base'] . '/in.zip');
        $fill($b);
        return $b->close();
    }

    // ---- 단위: 경로·이름 규칙(프로토타입 32건) ----

    public function testPathRules(): void
    {
        $cases = [
            ['src/a.py', null], ['./src//a.py', null], ['../a.py', 'parent_dir'], ['a/../../b', 'parent_dir'], ['/etc/x', 'absolute'],
            ['C:/x', 'drive_letter'], ['c:x', 'drive_letter'], ['\\\\srv\\share\\x', 'backslash'], ['a\\b.py', 'backslash'],
            ["a\0b", 'control_char'], ["a\nb", 'control_char'], ['', 'empty_name'], ['./', 'empty_name'],
        ];
        foreach ($cases as [$name, $reason]) {
            try {
                $segs = SafeUnzip::checkPath($name);
                $this->assertSame(null, $reason, json_encode($name) . ' 허용됨');
                $this->assertTrue($segs !== [], json_encode($name));
            } catch (UnzipException $e) {
                $this->assertSame(['forbidden_entry', $reason], [$e->errorCode, $e->detail['reason'] ?? null], json_encode($name));
            }
        }
        $this->assertSame(['src', 'a.py'], SafeUnzip::checkPath('./src//a.py'), '. 과 빈 조각 제거');
    }

    public function testWindowsNameRules(): void
    {
        $names = [
            'CON' => 'reserved', 'con.txt' => 'reserved', 'NUL' => 'reserved', 'aux.txt' => 'reserved', 'com1.py' => 'reserved',
            'LPT9.js' => 'reserved', 'COM¹' => 'reserved', 'trailing.' => 'trailing_dot_space', 'space ' => 'trailing_dot_space',
            'a:b.py' => 'forbidden_char', 'q?.py' => 'forbidden_char', 'x*.py' => 'forbidden_char', 'a<b' => 'forbidden_char',
            'a"b' => 'forbidden_char', 'a|b' => 'forbidden_char', 'a>b' => 'forbidden_char',
            'console.py' => null, 'con_util.py' => null, 'auxiliary.js' => null, 'com10.py' => null, '.eslintrc.json' => null, '한글.py' => null,
        ];
        foreach ($names as $seg => $reason) {
            $this->assertSame($reason, SafeUnzip::windowsNameProblem((string) $seg), (string) $seg);
        }
    }

    public function testNameDecoding(): void
    {
        $this->assertSame('src/a.py', SafeUnzip::decodeName('src/a.py', false), 'ASCII');
        $this->assertSame('한글.py', SafeUnzip::decodeName("\xc7\xd1\xb1\xdb.py", false), '플래그 없음 → CP949');
        $this->assertSame('한글.py', SafeUnzip::decodeName('한글.py', true), '플래그 있음 → UTF-8');
        $this->assertSame('한글.py', SafeUnzip::decodeName('한글.py', false), '플래그 없는 UTF-8: CP949 왕복 실패 + UTF-8 유효 → UTF-8(19.10)');
        $nfd = \Normalizer::normalize('한글.py', \Normalizer::FORM_D);
        $this->assertSame('한글.py', SafeUnzip::decodeName($nfd, true), 'NFC 정규화');
        foreach ([["\xff\xfe\xfd.py", false], ["\xc7\xd1\xb1.py", true]] as [$raw, $flag]) {
            $e = $this->assertThrows(UnzipException::class, fn () => SafeUnzip::decodeName($raw, $flag));
            $this->assertSame('filename_encoding', $e->errorCode, bin2hex($raw));
        }
    }

    // ---- 거부: 금지 항목 ----

    public function testForbiddenEntriesLeaveNothingOutside(): void
    {
        $reasons = ['zip_slip' => 'parent_dir', 'absolute_path' => 'absolute', 'backslash_path' => 'backslash', 'symlink' => 'symlink', 'nested' => 'nested_zip'];
        foreach ($reasons as $n => $reason) {
            $e = $this->expectReject(self::BAD . "/{$n}.zip", 'forbidden_entry');
            $this->assertSame($reason, $e->detail['reason'], $n);
            $this->assertTrue(($e->detail['entry'] ?? '') !== '', '항목 이름을 상세에');
            $this->assertContains((string) $e->detail['entry'], $e->userMessage(), '사용자 문구에 항목 이름(화면은 textContent)');
        }
        // 제외 대상(node_modules) 안이어도 경로 조작·절대 경로는 거부
        foreach (['node_modules/../../evil.txt' => 'parent_dir', '/node_modules/evil.txt' => 'absolute', 'node_modules\\..\\evil.txt' => 'backslash'] as $name => $reason) {
            $zip = $this->built(fn (ZipBuilder $b) => $b->add('src/a.py', 'x')->add($name, 'evil'));
            $this->assertSame($reason, $this->expectReject($zip, 'forbidden_entry')->detail['reason'], $name);
        }
    }

    public function testExcludedEntriesSkipSymlinkNestedAndWindowsChecks(): void
    {
        $zip = $this->built(fn (ZipBuilder $b) => $b
            ->add('proj/src/app.py', 'print(1)')
            ->add('proj/node_modules/.bin/tool', '../tool/cli.js', false, 0120777)   // 제외 대상 안 심볼릭 링크: 검사 안 함
            ->add('proj/node_modules/pkg/inner.zip', 'PK')                           // 제외 대상 안 중첩 zip
            ->add('proj/node_modules/pkg/CON', 'x')                                  // 제외 대상 안 Windows 예약 이름
            ->add('proj/.venv/lib/a:b.py', 'x')
            ->add('proj/build/out.zip', 'PK'));
        [$r, $files] = $this->extractOk($zip);
        $this->assertSame(['src/app.py'], $files);
        $this->assertSame(5, $r['skipped']);
        // 제외 대상 밖이면 거부
        $zip = $this->built(fn (ZipBuilder $b) => $b->add('src/link', 'x', false, 0120777));
        $this->assertSame('symlink', $this->expectReject($zip, 'forbidden_entry')->detail['reason']);
        // 프로젝트 추가 패턴으로 제외해도 같은 규칙
        $zip = $this->built(fn (ZipBuilder $b) => $b->add('src/a.py', 'x')->add('vendor/x.zip', 'PK')->add('vendor/CON', 'x'));
        [$r2, $files2] = $this->extractOk($zip, [], ['vendor/']);
        $this->assertSame([['src/a.py'], 2], [$files2, $r2['skipped']]);
        $this->expectReject($zip, 'forbidden_entry');
    }

    // ---- 한도 ----

    public function testLimits(): void
    {
        $mb = 1024 * 1024;
        $this->expectReject(self::BAD . '/bomb_small.zip', 'extract_limit', ['total' => 50 * $mb], 'precheck');
        $this->expectReject(self::BAD . '/many_files.zip', 'extract_limit', ['files' => 1000], 'precheck');
        $e = $this->expectReject(self::BAD . '/lying_size.zip', 'extract_limit', ['total' => $mb], 'extract');
        $this->assertTrue($e->detail['written_bytes'] > $mb, '실제로 쓴 바이트로 차단(선언은 100바이트)');
        $this->assertSame(100, $e->detail['declared_entry_bytes']);
        $mismatch = $this->expectReject(self::BAD . '/lying_size.zip', 'invalid_zip'); // 한도가 충분하면 선언 크기 불일치
        $this->assertSame('size_mismatch', $mismatch->detail['reason']);
        $this->expectReject(self::GOOD . '/python_sample.zip', 'zip_too_large', ['zip' => 1000]);
        $this->assertContains('1MB', $e->userMessage(), '한도 값을 문구에');

        // 제외 대상은 한도 계산에서 뺀다: 제외 대상만 큰 zip은 낮은 한도에서도 통과
        $zip = $this->built(fn (ZipBuilder $b) => $b->add('src/a.py', 'x')->add('node_modules/big.bin', str_repeat('A', 300000)));
        [$r] = $this->extractOk($zip, ['total' => 1000, 'files' => 1]);
        $this->assertSame([1, 1], [$r['files'], $r['skipped']]);
        // 파일 수 한도 정확히 경계
        $zip = $this->built(fn (ZipBuilder $b) => $b->add('a.py', 'x')->add('b.py', 'y'));
        $this->extractOk($zip, ['files' => 2]);
        $this->expectReject($zip, 'extract_limit', ['files' => 1], 'precheck');
    }

    // ---- 이름 ----

    public function testKoreanNames(): void
    {
        foreach (['cp949_names', 'utf8_names'] as $n) {
            [$r, $files, $dest] = $this->extractOk(self::BAD . "/{$n}.zip");
            $this->assertSame(['src/정상.py', '한글폴더/모듈_예제.py'], $files, $n);
            $this->assertContains('# 한글 파일', (string) file_get_contents($dest . '/한글폴더/모듈_예제.py'), $n);
        }
        // 플래그 없는 UTF-8 이름(항목 하나 '한글폴더/모듈_예제.py'): 19.10에 따라 UTF-8로 해석. 최상위 폴더는 유지 규칙으로 확인
        [$r, $files] = $this->extractOk(self::BAD . '/utf8_noflag.zip', [], [], false);
        $this->assertSame(['한글폴더/모듈_예제.py'], $files, 'utf8_noflag');
        // CP949 이름에 UTF-8 플래그가 잘못 붙은 경우: libzip이 열 때(CHECKCONS) 불일치로 거부 → invalid_zip(오류 21)
        $zip = $this->built(fn (ZipBuilder $b) => $b->add("\xc7\xd1\xb1\xdb.py", 'x', true));
        $this->assertSame(21, $this->expectReject($zip, 'invalid_zip')->detail['libzip_error']);
        // CP949도 UTF-8도 아님
        $zip = $this->built(fn (ZipBuilder $b) => $b->add("\xff\xfe.py", 'x'));
        $this->expectReject($zip, 'filename_encoding');
        // NFD 이름은 NFC로 풀린다
        $nfd = \Normalizer::normalize('가나.py', \Normalizer::FORM_D);
        [, $files] = $this->extractOk($this->built(fn (ZipBuilder $b) => $b->add($nfd, 'x', true)));
        $this->assertSame(['가나.py'], $files);
    }

    public function testUnsupportedNamesAndConflicts(): void
    {
        $e = $this->expectReject(self::BAD . '/long_path.zip', 'unsupported_name');
        $this->assertSame('path_too_long', $e->detail['reason']);
        $this->assertSame('reserved', $this->expectReject(self::BAD . '/reserved_names.zip', 'unsupported_name')->detail['reason']);
        foreach (['src/trailing.' => 'trailing_dot_space', 'src/a?.py' => 'forbidden_char', 'lpt1/x.py' => 'reserved'] as $name => $reason) {
            // 루트에 파일을 하나 두어 최상위 폴더 제거가 일어나지 않게(제거된 폴더 이름은 만들지 않으므로 검사 대상이 아님)
            $zip = $this->built(fn (ZipBuilder $b) => $b->add('root.py', 'r')->add($name, 'x'));
            $this->assertSame($reason, $this->expectReject($zip, 'unsupported_name')->detail['reason'], $name);
        }
        $this->expectReject(self::BAD . '/case_conflict.zip', 'name_conflict');
        // 제거되는 최상위 폴더의 이름은 만들지 않으므로 Windows 규칙에 걸려도 통과
        [, $files] = $this->extractOk($this->built(fn (ZipBuilder $b) => $b->add('lpt1/x.py', 'x')));
        $this->assertSame(['x.py'], $files);
        $conflicts = [
            ['README.md', 'readme.md'],
            ['Lib/a.py', 'lib/b.py'],
            ['가.py', \Normalizer::normalize('가.py', \Normalizer::FORM_D)],
            ['src', 'src/a.py'],              // 같은 이름의 파일과 폴더
            ['src/A.py', 'src/a.py'],
        ];
        foreach ($conflicts as [$a, $b]) {
            $zip = $this->built(fn (ZipBuilder $z) => $z->add($a, 'x', true)->add($b, 'y', true));
            $this->expectReject($zip, 'name_conflict');
        }
        // 이름이 바이트까지 같은 중복 항목은 libzip이 열 때 거부한다(오류 10) → invalid_zip
        $zip = $this->built(fn (ZipBuilder $z) => $z->add('src/a.py', 'x')->add('src/a.py', 'y'));
        $this->assertSame(10, $this->expectReject($zip, 'invalid_zip')->detail['libzip_error']);
        // 폴더 항목이 따로 있어도 충돌 아님
        [, $files] = $this->extractOk($this->built(fn (ZipBuilder $z) => $z->add('src/', '')->add('src/a.py', 'x')->add('src/b.py', 'y')), [], [], false);
        $this->assertSame(['src/a.py', 'src/b.py'], $files);
    }

    // ---- 제외 대상 ----

    public function testExcludedDirsAndProjectPatterns(): void
    {
        [$r, $files] = $this->extractOk(self::BAD . '/excluded_dirs.zip');
        $this->assertSame(['README.md', 'builder/tool.py', 'src/app.py', 'src/dist.py'], $files);
        $this->assertSame(8, $r['skipped']);
        [$r2, $files2] = $this->extractOk(self::BAD . '/excluded_dirs.zip', [], ['/README.md', 'builder/']);
        $this->assertSame(['src/app.py', 'src/dist.py'], $files2, '프로젝트 추가 패턴');
        $this->assertSame(10, $r2['skipped']);
        // 앵커 패턴은 최상위 폴더 제거 뒤의 경로 기준
        $zip = $this->built(fn (ZipBuilder $b) => $b->add('wrap/docs/a.md', 'x')->add('wrap/src/a.py', 'y'));
        [$r3, $files3] = $this->extractOk($zip, [], ['/docs/**']);
        $this->assertSame([['src/a.py'], 'wrap/'], [$files3, $r3['stripped_prefix']]);
    }

    // ---- 최상위 폴더 ----

    public function testTopFolderRules(): void
    {
        [$r, $files] = $this->extractOk(self::GOOD . '/python_sample_toplevel.zip');
        [, $flat] = $this->extractOk(self::GOOD . '/python_sample.zip');
        $this->assertSame(['my_project_v2/', true], [$r['stripped_prefix'], $r['strip_rule']]);
        $this->assertSame($flat, $files, '감싸는 폴더를 뺀 결과가 같다');

        $cases = [
            ['python_sample_toplevel.zip', null, true, 'my_project_v2/', []],
            ['python_sample.zip', null, false, '', []],
            ['python_sample.zip', true, true, '', ['top_folder_missing']],
            ['python_sample_toplevel.zip', false, false, '', ['top_folder_kept']],
            ['python_sample_toplevel.zip', true, true, 'my_project_v2/', []],
            ['python_sample.zip', false, false, '', []],
        ];
        foreach ($cases as [$zip, $rule, $expRule, $expPrefix, $expWarn]) {
            [$r, $files] = $this->extractOk(self::GOOD . '/' . $zip, [], [], $rule);
            $label = $zip . ' 규칙=' . var_export($rule, true);
            $this->assertSame([$expRule, $expPrefix, $expWarn], [$r['strip_rule'], $r['stripped_prefix'], $r['warnings']], $label);
            if ($zip === 'python_sample_toplevel.zip' && $expPrefix === '') {
                $this->assertTrue(str_starts_with($files[0], 'my_project_v2/'), $label . ': 제거 안 함');
            }
        }
        // 단일 폴더지만 하위 항목이 없음(빈 폴더만), 루트에 파일이 섞임 → 제거 안 함
        [$r] = $this->extractOk($this->built(fn (ZipBuilder $b) => $b->add('only/', '')->add('x.py', 'y')));
        $this->assertSame('', $r['top_folder']);
        // 제외 대상(__MACOSX)이 있어도 나머지가 한 폴더면 제거(19.10)
        [$r, $files] = $this->extractOk($this->built(fn (ZipBuilder $b) => $b->add('proj/a.py', 'x')->add('__MACOSX/proj/._a.py', 'y')->add('proj/.DS_Store', 'z')));
        $this->assertSame([['a.py'], 'proj/'], [$files, $r['stripped_prefix']]);
    }

    // ---- 손상·빈 zip ----

    public function testBrokenAndEmptyZips(): void
    {
        $this->assertSame('invalid_zip', $this->expectReject(self::BAD . '/corrupt.zip', 'invalid_zip')->errorCode);
        $this->expectReject(self::BAD . '/not_a_zip.zip', 'invalid_zip');
        $sb = $this->sandbox();
        file_put_contents($sb['base'] . '/empty.zip', pack('VvvvvVVv', 0x06054b50, 0, 0, 0, 0, 0, 0, 0));
        [$r, $files] = $this->extractOk($sb['base'] . '/empty.zip');
        $this->assertSame([0, []], [$r['files'], $files], '빈 zip은 해제 성공(대상 없음 경고는 사전 검사에서)');
        $this->expectReject($sb['base'] . '/missing.zip', 'invalid_zip');
        [$r] = $this->extractOk(self::GOOD . '/empty_no_target.zip');
        $this->assertTrue($r['files'] > 0);
    }

    public function testGoodSamplesAndResultShape(): void
    {
        foreach (['python_sample', 'python_syntax_error', 'js_sample', 'ts_sample', 'js_with_js_config'] as $n) {
            [$r, $files, $dest] = $this->extractOk(self::GOOD . "/{$n}.zip");
            $this->assertTrue($r['files'] === count($files) && $r['files'] > 0, $n);
            $this->assertSame(array_sum(array_map(static fn (string $f): int => (int) filesize($dest . '/' . $f), $files)), $r['bytes'], $n . ' 쓴 바이트');
            $this->assertSame(str_replace('\\', '/', (string) realpath($dest)), $r['dest']);
        }
    }

    public function testExistingNonEmptyDestinationIsRefused(): void
    {
        $sb = $this->sandbox();
        mkdir($sb['dest'], 0700, true);
        file_put_contents($sb['dest'] . '/keep.txt', 'mine');
        $e = $this->assertThrows(UnzipException::class, fn () => $this->unzip()->extract(self::GOOD . '/python_sample.zip', $sb['dest'], null));
        $this->assertSame('io_error', $e->errorCode);
        $this->assertSame('mine', file_get_contents($sb['dest'] . '/keep.txt'), '남의 파일을 지우지 않음');
        // 빈 디렉토리(Paths::newWorkDir가 만든 것)는 그대로 쓴다
        $sb2 = $this->sandbox();
        mkdir($sb2['dest'], 0700, true);
        $this->assertTrue($this->unzip()->extract(self::GOOD . '/python_sample.zip', $sb2['dest'], null)['files'] > 0);
    }

    /** 맨 마지막(이름 순): 거부 시험 합계 확인 */
    public function testZzOutsideFileTotal(): void
    {
        $this->assertTrue(self::$rejectCases >= 30, '거부 시험 수 ' . self::$rejectCases);
        $this->assertSame(0, self::$outsideTotal, '모든 거부 시험에서 작업 디렉토리 밖에 새로 생긴 파일');
        fwrite(STDOUT, sprintf("      [보고] 거부 시험 %d건, 작업 디렉토리 밖에 새로 생긴 파일 %d개\n", self::$rejectCases, self::$outsideTotal));
        $this->assertSame([], glob(PJG_ROOT . '/data/work/p13t_*') ?: [], '시험 샌드박스 정리');
    }

    /**
     * --bigfiles일 때만(PJG_TEST_BIGFILES=1): 항목 100만 개 zip(각 1바이트)을 만들어 해제하며 최대 메모리와 시간을 잰다.
     */
    public function testBigManyEntries(): void
    {
        if (getenv('PJG_TEST_BIGFILES') !== '1') {
            return;
        }
        $n = (int) (getenv('PJG_TEST_BIGFILES_N') ?: 1_000_000);
        $sb = $this->sandbox();
        $t0 = microtime(true);
        $b = new ZipBuilder($sb['base'] . '/many.zip');
        for ($i = 0; $i < $n; $i++) {
            $b->add(sprintf('src/d%03d/m%06d.py', intdiv($i, 1000), $i), 'x');
        }
        $b->close();
        $makeSec = microtime(true) - $t0;
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage(true);
        $t1 = microtime(true);
        $r = $this->unzip(['files' => $n])->extract($sb['base'] . '/many.zip', $sb['dest'], null);
        $sec = microtime(true) - $t1;
        $peak = memory_get_peak_usage(true);
        $this->assertSame($n, $r['files']);
        $t2 = microtime(true);
        Fs::removeTree($sb['dest']);
        $rmSec = microtime(true) - $t2;
        fwrite(STDOUT, sprintf("      [100만 항목] 항목 %d개, zip %.1fMB 생성 %.1f초, 해제 %.1f초, PHP 최대 메모리 %.1fMB(시작 %.1fMB), 삭제 %.1f초\n",
            $n, filesize($sb['base'] . '/many.zip') / 1048576, $makeSec, $sec, $peak / 1048576, $base / 1048576, $rmSec));
    }
}
