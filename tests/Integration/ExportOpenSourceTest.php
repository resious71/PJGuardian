<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Fs;
use PJGuardian\Tests\TestCase;

/**
 * 공개용 트리 내보내기(P33 3절, tools/export_open_source.php): 임시 git 저장소에서 제외 목록 준수(prompts/·docs/results/·명세·config.ini·DB·.env·
 * vendor/·data/는 .gitkeep만), bin/의 ruff·ESLint는 현재 버전 폴더 하나만, 실행 파일이 있으면 멈춤, 내보낼 파일의 미커밋 변경은 멈춤(제외 대상 변경은 무시),
 * 저장소 안 dist/ 밖 출력 거부, 매니페스트(SHA-256), 공개용 사본 바꾸기. 마지막으로 이 저장소 자체를 내보내 실제 규칙을 확인한다(스캐너·링크 검사는 따로 시험).
 */
final class ExportOpenSourceTest extends TestCase
{
    private string $dir;
    private string $repo;

    public function setUp(): void
    {
        $this->dir = $this->tempDir('oss');
        $this->repo = $this->dir . '/repo';
        $files = [
            'README.md' => "# Demo\nSee `docs/install_guide.md`.\n",
            'src/A.php' => "<?php\n",
            'prompts/p01.md' => "prompt\n",
            'docs/results/P01.md' => "report\n",
            'PJGuardian_requirements.md' => "spec\n",
            'docs/presentations/intro.pptx' => "PK\x03\x04 slides", // X22: 추적되어도 내보내지 않음
            'tools/proto/old.php' => "<?php\n",
            'config/config.ini' => "[app]\n",
            'config/config.ini.example' => "[app]\n",
            'config/secrets.example' => "# example\n",
            'config/settings.local.ini' => "[x]\n",
            'data/db/.gitkeep' => '',
            'data/db/demo.sqlite' => 'SQLite format 3',
            'data/logs/app.log' => "log\n",
            '.env' => "X=1\n",
            'vendor/lib.php' => "<?php\n",
            'bin/ruff/.gitkeep' => '',
            'bin/ruff/0.9.0/SHA256SUMS' => str_repeat('a', 64) . " *windows/ruff.exe\n",
            'bin/ruff/1.0.0/SHA256SUMS' => str_repeat('b', 64) . " *windows/ruff.exe\n",
            'bin/eslint/1.0.0/SHA256SUMS' => str_repeat('c', 64) . " *package.json\n",
            'bin/eslint/2.0.0/SHA256SUMS' => str_repeat('d', 64) . " *package.json\n",
            'bin/eslint/2.0.0/package.json' => "{}\n",
            'bin/eslint/2.0.0/package-lock.json' => "{}\n",
            'bin/git/1.0.0/VERSION.txt' => "git\n",
            'bin/p4/1.0.0/SHA256SUMS' => str_repeat('e', 64) . " *windows/p4.exe\n",
        ];
        foreach ($files as $rel => $data) {
            Fs::ensureDir(dirname($this->repo . '/' . $rel));
            file_put_contents($this->repo . '/' . $rel, $data);
        }
        $this->git(['init', '-q']);
        $this->git(['add', '-A', '-f']);
        $this->git(['commit', '-q', '-m', 'init']);
    }

    public function tearDown(): void
    {
        // git 객체 파일은 읽기 전용이라 Windows에서 지우기 전에 쓰기 가능으로 바꾼다
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                @chmod($f->getPathname(), $f->isDir() ? 0777 : 0666);
            }
        }
        Fs::removeTree($this->dir);
    }

    private function git(array $args): string
    {
        $p = proc_open(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.invalid', '-c', 'core.autocrlf=false', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repo);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), 'git ' . implode(' ', $args) . ': ' . $out);
        return $out;
    }

    /** @return array{0: int, 1: string} */
    private function export(array $args): array
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/export_open_source.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private function exportDemo(array $extra = []): array
    {
        return $this->export(['--repo=' . $this->repo, '--out=' . $this->dir . '/out', '--ruff=1.0.0', '--eslint=2.0.0', '--no-scan', '--no-links', ...$extra]);
    }

    /** @return list<string> 출력 트리의 파일(상대 경로) */
    private function treeFiles(string $root): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $files[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
        }
        sort($files);
        return $files;
    }

    public function testExcludesAndKeepsOnlyCurrentToolVersions(): void
    {
        [$code, $out] = $this->exportDemo();
        $this->assertSame(0, $code, $out);
        $files = $this->treeFiles($this->dir . '/out');
        $this->assertSame([
            'OSS_MANIFEST.json', 'README.md',
            'bin/eslint/2.0.0/SHA256SUMS', 'bin/eslint/2.0.0/package-lock.json', 'bin/eslint/2.0.0/package.json',
            'bin/git/1.0.0/VERSION.txt', 'bin/p4/1.0.0/SHA256SUMS', 'bin/ruff/.gitkeep', 'bin/ruff/1.0.0/SHA256SUMS',
            'config/config.ini.example', 'config/secrets.example', 'data/db/.gitkeep', 'src/A.php',
        ], $files, '제외 목록 준수: 개발 기록·명세·설정·비밀·DB·로그·.env·vendor/·지난 도구 버전 없음');
        foreach (['ruff' => '1.0.0', 'eslint' => '2.0.0'] as $tool => $v) {
            $this->assertSame([$v], array_values(array_filter(Fs::listDir($this->dir . "/out/bin/{$tool}"), fn ($e) => is_dir($this->dir . "/out/bin/{$tool}/{$e}"))), "bin/{$tool} 버전 폴더 하나 = 현재 버전");
        }
        $this->assertFalse(is_dir($this->dir . '/out/docs/presentations'), 'docs/presentations/는 추적되어도 내보내지 않음(X22)');
        $this->assertFalse(is_dir($this->dir . '/out/tools/proto'));
        $this->assertContains('docs/presentations/', $out, '제외 규칙 목록에 나옴');
        $m = json_decode((string) file_get_contents($this->dir . '/out/OSS_MANIFEST.json'), true);
        $this->assertSame(false, $m['dirty']);
        $this->assertSame(['ruff' => '1.0.0', 'eslint' => '2.0.0', 'git' => '1.0.0', 'p4' => '1.0.0'], $m['bin_versions']);
        $this->assertSame(12, count($m['files']));
        $readme = array_values(array_filter($m['files'], static fn ($f) => $f['path'] === 'README.md'))[0];
        $this->assertSame(hash_file('sha256', $this->dir . '/out/README.md'), $readme['sha256']);
        // 공개용 사본 바꾸기: 커밋되지 않은 설치 가이드 대신 빠른 설치 문서를 가리킴(원본은 그대로)
        $this->assertContains('docs/install_quick.ko.md', (string) file_get_contents($this->dir . '/out/README.md'));
        $this->assertContains('docs/install_guide.md', (string) file_get_contents($this->repo . '/README.md'));
        // 다시 만들면 이전 트리를 지우고 새로
        [$code] = $this->exportDemo();
        $this->assertSame(0, $code);
    }

    /** X22: .gitignore의 "공개할 때만" 주석 블록과 공개 제외 폴더 줄(+ 주석)은 공개용 사본에서 지우고, 이미 지운 원본에서 다시 내보내도 멈추지 않는다 */
    public function testGitignoreBlocksRemovedAndReexportIsStable(): void
    {
        file_put_contents($this->repo . '/.gitignore', "/vendor/\n/data/db/*.sqlite\n\n"
            . "# =====================================================================\n# 오픈소스로 공개할 때만 사용 (지금은 주석)\n# =====================================================================\n"
            . "# /prompts/                      # 개발 과정 프롬프트 기록\n# /samples/private/              # 샘플\n\n"
            . "# ---- 소개·교육 PPT (공개하지 않음, 사용자 결정) ----\n/docs/presentations/\n\n# ---- 개발 기록 ----\n/prompts/\n/tools/proto/\n");
        $this->git(['add', '-A', '-f']);
        $this->git(['commit', '-q', '-m', 'gitignore']);
        [$code, $out] = $this->exportDemo();
        $this->assertSame(0, $code, $out);
        $public = (string) file_get_contents($this->dir . '/out/.gitignore');
        foreach (['오픈소스로 공개할 때만', 'presentations', '/prompts/', '/tools/proto/', 'PPT', '개발 기록'] as $s) {
            $this->assertNotContains($s, $public, "공개용 .gitignore에 {$s} 없음");
        }
        $this->assertContains("/vendor/\n/data/db/*.sqlite\n", $public, '나머지 줄은 그대로');
        $this->assertContains('/docs/presentations/', (string) file_get_contents($this->repo . '/.gitignore'), '개발 저장소 원본은 그대로');
        // 공개 저장소처럼: 공개용 트리를 새 저장소로 만들고 거기서 다시 내보내면 바꾸기 규칙이 "이미 적용됨"으로 건너뛴다
        $second = $this->dir . '/public';
        Fs::ensureDir($second);
        foreach ($this->treeFiles($this->dir . '/out') as $rel) {
            if ($rel !== 'OSS_MANIFEST.json') {
                Fs::ensureDir(dirname($second . '/' . $rel));
                copy($this->dir . '/out/' . $rel, $second . '/' . $rel);
            }
        }
        $repo = $this->repo;
        $this->repo = $second;
        $this->git(['init', '-q']);
        $this->git(['add', '-A', '-f']);
        $this->git(['commit', '-q', '-m', 'public']);
        $this->repo = $repo;
        [$code, $out] = $this->export(['--repo=' . $second, '--out=' . $this->dir . '/out2', '--ruff=1.0.0', '--eslint=2.0.0', '--no-scan', '--no-links']);
        $this->assertSame(0, $code, $out);
        $this->assertContains('.gitignore#0: 이미 적용됨', $out);
        $this->assertContains('.gitignore#1: 이미 적용됨', $out);
        $this->assertSame($public, (string) file_get_contents($this->dir . '/out2/.gitignore'));
    }

    /** X22: docs/install_guide.md가 없으면 가리키는 곳을 빠른 설치로 바꾸고 목차 줄을 빼며, 커밋되어 있으면 그대로 둔다 */
    public function testInstallGuidePresentOrMissing(): void
    {
        $row = "| [install_guide.md](install_guide.md) | 설치 가이드(새 설치·옛 PC에서 옮기기·점검 목록) |\n";
        file_put_contents($this->repo . '/docs/README.md', "# docs\n\n| 문서 | 내용 |\n| --- | --- |\n{$row}| [other.md](other.md) | 기타 |\n");
        file_put_contents($this->repo . '/docs/other.md', "See `docs/install_guide.md` and `docs/install_guide.md`.\n");
        $this->git(['add', '-A', '-f']);
        $this->git(['commit', '-q', '-m', 'docs']);
        [$code, $out] = $this->exportDemo();
        $this->assertSame(0, $code, $out);
        $this->assertNotContains('install_guide.md', (string) file_get_contents($this->dir . '/out/docs/README.md'), '없으면 목차 줄을 뺌');
        $this->assertSame("See `docs/install_quick.ko.md` and `docs/install_quick.ko.md`.\n", (string) file_get_contents($this->dir . '/out/docs/other.md'));
        $this->assertContains('파일 2개 3곳', $out, 'README.md 1곳 + other.md 2곳');
        // 커밋하면 그대로 들어가고 바꾸기는 건너뛴다
        file_put_contents($this->repo . '/docs/install_guide.md', "# 설치 가이드\n");
        $this->git(['add', '-A', '-f']);
        $this->git(['commit', '-q', '-m', 'guide']);
        [$code, $out] = $this->exportDemo();
        $this->assertSame(0, $code, $out);
        $this->assertTrue(is_file($this->dir . '/out/docs/install_guide.md'));
        $this->assertContains($row, (string) file_get_contents($this->dir . '/out/docs/README.md'), '있으면 목차 줄 유지');
        $this->assertSame("See `docs/install_guide.md` and `docs/install_guide.md`.\n", (string) file_get_contents($this->dir . '/out/docs/other.md'));
        $this->assertContains('`docs/install_guide.md`', (string) file_get_contents($this->dir . '/out/README.md'));
        $this->assertNotContains('전체 바꾸기', $out);
    }

    public function testStopsOnUncommittedChangesButIgnoresExcludedPaths(): void
    {
        file_put_contents($this->repo . '/prompts/p01.md', "changed\n");
        [$code, $out] = $this->exportDemo();
        $this->assertSame(0, $code, '제외 대상의 변경은 보지 않음: ' . $out);
        file_put_contents($this->repo . '/README.md', "# changed\n");
        [$code, $out] = $this->exportDemo();
        $this->assertSame(2, $code);
        $this->assertContains('커밋하지 않은 변경', $out);
        $this->assertContains('README.md', $out);
        [$code, $out] = $this->exportDemo(['--force']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(true, json_decode((string) file_get_contents($this->dir . '/out/OSS_MANIFEST.json'), true)['dirty']);
        // 새 파일은 --with-untracked(+ --force)일 때만
        file_put_contents($this->repo . '/NEW.md', "new\n");
        [$code] = $this->exportDemo(['--force']);
        $this->assertFalse(is_file($this->dir . '/out/NEW.md'));
        [$code, $out] = $this->exportDemo(['--force', '--with-untracked']);
        $this->assertSame(0, $code, $out);
        $this->assertTrue(is_file($this->dir . '/out/NEW.md'));
        [$code] = $this->exportDemo(['--with-untracked']);
        $this->assertSame(2, $code, '--with-untracked는 --force와 함께만');
    }

    public function testStopsOnExecutablesUnknownVersionsAndUnsafeOutput(): void
    {
        // 실행 파일이 추적되면 멈추고 트리를 남기지 않는다
        Fs::ensureDir($this->repo . '/bin/git/1.0.0/windows');
        file_put_contents($this->repo . '/bin/git/1.0.0/windows/git.exe', "MZ\x90\x00binary");
        Fs::ensureDir($this->repo . '/bin/ruff/1.0.0/linux');
        file_put_contents($this->repo . '/bin/ruff/1.0.0/linux/ruff', "\x7fELF....");
        $this->git(['add', '-A', '-f']);
        $this->git(['commit', '-q', '-m', 'exe']);
        [$code, $out] = $this->exportDemo();
        $this->assertSame(1, $code, $out);
        $this->assertContains('bin/git/1.0.0/windows/git.exe', $out);
        $this->assertContains('bin/ruff/1.0.0/linux/ruff', $out);
        $this->assertFalse(is_dir($this->dir . '/out'));
        // 등록되지 않은 버전
        [$code, $out] = $this->export(['--repo=' . $this->repo, '--out=' . $this->dir . '/out', '--ruff=3.0.0', '--eslint=2.0.0', '--no-scan', '--no-links']);
        $this->assertSame(2, $code);
        $this->assertContains('3.0.0', $out);
        // 저장소 안이지만 dist/ 밖, 이전 트리가 아닌 비어 있지 않은 폴더
        [$code] = $this->export(['--repo=' . $this->repo, '--out=' . $this->repo . '/src', '--ruff=1.0.0', '--eslint=2.0.0', '--no-scan', '--no-links']);
        $this->assertSame(2, $code);
        Fs::ensureDir($this->dir . '/other');
        file_put_contents($this->dir . '/other/keep.txt', 'x');
        [$code] = $this->export(['--repo=' . $this->repo, '--out=' . $this->dir . '/other', '--ruff=1.0.0', '--eslint=2.0.0', '--no-scan', '--no-links']);
        $this->assertSame(2, $code);
        $this->assertTrue(is_file($this->dir . '/other/keep.txt'), '남의 폴더는 지우지 않음');
    }

    public function testThisRepositoryWithRealRules(): void
    {
        // 이 사본 자체가 git 저장소의 최상위여야 한다(예: 아직 git init 하지 않은 공개용 트리 폴더에서 돌리면 건너뜀)
        $p = proc_open(['git', 'rev-parse', '--show-toplevel'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT);
        $top = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        proc_close($p);
        if ($top === '' || strtolower(str_replace('\\', '/', (string) realpath($top))) !== strtolower(str_replace('\\', '/', (string) realpath(PJG_ROOT)))) {
            echo "    (건너뜀: 이 폴더는 git 저장소의 최상위가 아님 — {$top})\n";
            return;
        }
        $latest = static function (string $tool): string {
            $v = array_map(static fn (string $p): string => basename(dirname($p)), glob(PJG_ROOT . "/bin/{$tool}/*/SHA256SUMS") ?: []);
            usort($v, 'version_compare');
            return (string) end($v);
        };
        [$code, $out] = $this->export(['--out=' . $this->dir . '/self', '--ruff=' . $latest('ruff'), '--eslint=' . $latest('eslint'), '--no-scan', '--no-links', '--force', '--with-untracked']); // 커밋 전 새 파일(LICENSE 등)도 — 커밋 뒤에는 같은 결과
        $this->assertSame(0, $code, $out);
        $root = $this->dir . '/self';
        foreach (['prompts', 'docs/results', 'vendor', 'dist'] as $d) {
            $this->assertFalse(is_dir($root . '/' . $d), "{$d}/ 없음");
        }
        foreach (['PJGuardian_requirements.md', 'config/config.ini', 'config/secrets.ini'] as $f) {
            $this->assertFalse(is_file($root . '/' . $f), "{$f} 없음");
        }
        foreach (['LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', 'composer.json', 'composer.lock', '.gitignore', '.gitattributes', 'CLAUDE.md', 'config/config.ini.example'] as $f) {
            $this->assertTrue(is_file($root . '/' . $f), "{$f} 있음");
        }
        foreach (['ruff', 'eslint'] as $tool) {
            $dirs = array_values(array_filter(Fs::listDir($root . "/bin/{$tool}"), static fn ($e) => is_dir($root . "/bin/{$tool}/{$e}")));
            $this->assertSame([$latest($tool)], $dirs, "bin/{$tool}는 현재 버전 하나");
        }
        $this->assertSame([], array_values(array_filter($this->treeFiles($root . '/data'), static fn ($f) => basename($f) !== '.gitkeep')), 'data/는 뼈대만');
        // 공개용 사본 바꾸기: 개발 PC 경로 일반화, .gitignore의 "공개할 때만" 주석 블록 정리
        $claude = (string) file_get_contents($root . '/CLAUDE.md');
        $this->assertNotContains('C:\\tools\\nodejs\\node.exe', $claude);
        $this->assertContains('공개 저장소 안내', $claude);
        $this->assertNotContains('오픈소스로 공개할 때만 사용', (string) file_get_contents($root . '/.gitignore'));
    }
}
