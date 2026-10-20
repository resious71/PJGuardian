<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Tests\TestCase;

/**
 * 배포 묶음 점검 tools/verify_bundle.php(P29, 읽기 전용): 임시 폴더에 가짜 묶음을 만들어 정상 묶음은 통과(0), vendor/ 누락·이미지 누락·버전 불일치·
 * 비밀 파일(secrets.ini, *.pjgkey)·data/의 데이터 파일(P31: 빈 폴더 표지 .gitkeep만 허용)·tests/·.git 포함은 실패(1), 사용법 오류는 2. 점검이 파일을 바꾸지 않음.
 * X14: 설정 파일 config/config.ini·settings.local.ini*가 들어 있으면 실패, config/*.example만 있으면 통과.
 * X16: FAQ 시드 resources/faq/seed.ko.json이 없거나 형식이 틀리면 실패, 있으면 통과(정상 묶음에 포함).
 * P32: 도구 스모크 시료(samples/python_sample 등 4개)가 없으면 실패 — 인수 시험에서 묶음에 samples/가 빠져 새 설치의 ruff·ESLint가 모두
 *   '시료 없음'으로 사용 불가였던 것. build_bundle.php로 만든 실제 묶음에 시료가 들어가고 verify를 통과하는지도 확인한다.
 */
final class VerifyBundleTest extends TestCase
{
    /** @return array{0:int, 1:string} */
    private function run(string $dir): array
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/verify_bundle.php', $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private function bundle(): string
    {
        $d = $this->tempDir('bundle');
        $w = static function (string $rel, string $text) use ($d): void {
            @mkdir(dirname("{$d}/{$rel}"), 0777, true);
            file_put_contents("{$d}/{$rel}", $text);
        };
        $w('vendor/autoload.php', '<?php');
        $w('composer.lock', json_encode(['packages' => [['name' => 'onelogin/php-saml', 'version' => '4.3.2'], ['name' => 'robrichards/xmlseclibs', 'version' => '3.1.5']]]));
        $w('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'onelogin/php-saml', 'version' => '4.3.2'], ['name' => 'robrichards/xmlseclibs', 'version' => '3.1.5']]]));
        $w('resources/guide/user/ko/01_a.md', 'x');
        $w('resources/guide/user/en/01_a.md', 'x');
        $w('resources/guide/screenshots.json', json_encode(['images' => [['slug' => 'shot', 'audience' => 'user', 'langs' => ['ko', 'en']]]]));
        $w('resources/guide/user/img/shot.ko.png', 'p');
        $w('resources/guide/user/img/shot.en.png', 'p');
        $w('config/secrets.example', 'x');
        $w('resources/faq/seed.ko.json', json_encode(['version' => 1, 'items' => [['category' => 'c', 'question' => 'q', 'answer' => 'a']]]));
        foreach (['python_sample', 'python_syntax_error', 'js_sample_noconfig', 'ts_sample'] as $s) {
            $w("samples/{$s}/a.txt", 'x');
        }
        return $d;
    }

    public function testGoodAndBrokenBundles(): void
    {
        $d = $this->bundle();
        $before = md5(serialize(array_map('filemtime', glob($d . '/*') ?: [])));
        [$code, $out] = $this->run($d);
        $this->assertSame(0, $code, $out);
        $this->assertContains('묶음 점검 통과', $out);
        $this->assertSame($before, md5(serialize(array_map('filemtime', glob($d . '/*') ?: []))), '읽기만');
        $cases = [
            'vendor/autoload.php 없음' => fn (string $d) => unlink("{$d}/vendor/autoload.php"),
            '가이드 이미지 없음' => fn (string $d) => unlink("{$d}/resources/guide/user/img/shot.en.png"),
            'vendor 버전 불일치' => fn (string $d) => file_put_contents("{$d}/vendor/composer/installed.json", json_encode(['packages' => [['name' => 'onelogin/php-saml', 'version' => '4.3.1']]])),
            'config/secrets.ini' => fn (string $d) => file_put_contents("{$d}/config/secrets.ini", 'encryption_key = x'),
            '.pjgkey' => fn (string $d) => file_put_contents("{$d}/keys.pjgkey", 'k'),
            // P31: data/는 빈 폴더 뼈대(.gitkeep)만 허용 — 데이터 파일이 하나라도 있으면 실패(의도된 단언 변경: 예전엔 빈 data/도 실패)
            'data/' => function (string $d): void {
                mkdir("{$d}/data/db", 0777, true);
                file_put_contents("{$d}/data/db/pjguardian.sqlite", 'x');
            },
            'tests/' => fn (string $d) => mkdir("{$d}/tests"),
            '.git/' => fn (string $d) => mkdir("{$d}/.git"),
            'resources/guide/user/en 없음' => fn (string $d) => unlink("{$d}/resources/guide/user/en/01_a.md"),
            // X16: FAQ 시드가 있어야 함(없거나 형식 오류면 실패)
            'resources/faq/seed.ko.json 없음' => fn (string $d) => unlink("{$d}/resources/faq/seed.ko.json"),
            'resources/faq/seed.ko.json 없음 또는 형식 오류' => fn (string $d) => file_put_contents("{$d}/resources/faq/seed.ko.json", '{'),
            // P32: 도구 스모크 시료
            '도구 스모크 시료 없음: samples/ts_sample' => fn (string $d) => unlink("{$d}/samples/ts_sample/a.txt"),
        ];
        // 빈 뼈대만 있는 data/는 통과(build_bundle이 넣는 것 — 설치하자마자 폴더가 있게)
        $d = $this->bundle();
        foreach (['db', 'uploads', 'work', 'snapshots', 'backups', 'logs'] as $sub) {
            mkdir("{$d}/data/{$sub}", 0777, true);
            touch("{$d}/data/{$sub}/.gitkeep");
        }
        [$code, $out] = $this->run($d);
        $this->assertSame(0, $code, $out);
        $this->assertContains('data/ 빈 폴더 뼈대만', $out);
        foreach ($cases as $want => $break) {
            $d = $this->bundle();
            $break($d);
            [$code, $out] = $this->run($d);
            $this->assertSame(1, $code, "{$want}: {$out}");
            $this->assertContains($want, $out, $want);
        }
        $this->assertSame(2, $this->run($d . '/no-such')[0], '사용법 오류');
    }

    /** P32: build_bundle.php가 만든 실제 묶음 — 스모크 시료(소스)는 들어가고 zip 시료·시험·인수 도구는 빠지며 verify 통과 */
    public function testBuiltBundleHasSmokeSamples(): void
    {
        if (!is_file(PJG_ROOT . '/vendor/autoload.php') || !is_dir(PJG_ROOT . '/.git')) {
            $this->assertTrue(true, 'vendor/·git 없는 환경 — 건너뜀');
            return;
        }
        $out = $this->tempDir('built');
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/build_bundle.php', "--out={$out}", '--force'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        $log = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $log);
        $this->assertContains('samples/ 도구 스모크 시료 4개', $log);
        $b = (glob($out . '/pjguardian-*', GLOB_ONLYDIR) ?: [''])[0];
        foreach (['python_sample', 'python_syntax_error', 'js_sample_noconfig', 'ts_sample'] as $s) {
            $this->assertTrue((glob("{$b}/samples/{$s}/*") ?: []) !== [], "samples/{$s}");
        }
        foreach (['samples/zips', 'samples/zips_bad', 'tests', 'tools/acceptance', 'tools/guide_demo', 'docs/results'] as $no) {
            $this->assertTrue(!file_exists("{$b}/{$no}"), "{$no} 없음");
        }
    }

    public function testConfigFilesInBundleAreRejected(): void
    {
        // 없으면 통과: config/*.example만
        $d = $this->bundle();
        file_put_contents("{$d}/config/config.ini.example", '[app]');
        [$code, $out] = $this->run($d);
        $this->assertSame(0, $code, $out);
        // 들어 있으면 실패: 파일마다 이름을 알림
        foreach (['config.ini', 'settings.local.ini', 'settings.local.ini.bak.1'] as $name) {
            $d = $this->bundle();
            file_put_contents("{$d}/config/config.ini.example", '[app]');
            file_put_contents("{$d}/config/{$name}", '[app]');
            [$code, $out] = $this->run($d);
            $this->assertSame(1, $code, "{$name}: {$out}");
            $this->assertContains("config/{$name} 가 있음", $out, $name);
            $this->assertNotContains('config/config.ini.example 가 있음', $out, '예시 파일은 허용');
        }
    }
}
