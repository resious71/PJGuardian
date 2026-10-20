<?php

declare(strict_types=1);

/**
 * 배포 묶음 만들기(P31 C-3). 저장소 파일(git ls-files — 커밋된 것 + 무시 목록에 없는 새 파일)만 복사하고, 빌드 PC의 vendor/(composer install --no-dev 결과)를 더한 뒤
 * 만든 직후 tools/verify_bundle.php로 검증한다. 산출물은 dist/(git 무시).
 *
 *   php tools/build_bundle.php [--out=dist] [--zip] [--with-bin] [--force]
 *
 * 넣지 않는 것: 개발·시험(tests/, tools/proto/, tools/guide_demo/, tools/acceptance/, samples/zips·zips_bad — P32: samples/의 소스 시료는 넣는다,
 *   분석 도구 스모크 테스트(ToolSmokeTest)가 실행 중에 samples/python_sample 등을 쓰므로 빠지면 새 설치에서 ruff·ESLint가 모두 '사용 불가'), 단계 보고·프롬프트(docs/results/, prompts/), 데이터(data/는 빈 폴더 뼈대 .gitkeep만),
 *   설치마다 다른 설정·비밀(config/config.ini, settings.local.ini*, secrets.*(secrets.example 제외)) — 이것들은 git이 관리하지 않으므로 애초에 목록에 없다.
 *   bin/의 실행 파일은 git이 관리하지 않으므로 매니페스트(SHA256SUMS·VERSION·package*.json)만 들어간다. --with-bin이면 그 매니페스트에 적힌 파일도 복사한다.
 * 함께 쓰는 것: BUNDLE.json(앱 버전·커밋·작업 트리 변경 여부·만든 시각·파일 수), SHA256SUMS(묶음 안 모든 파일 — 배포 후 무결성 확인).
 * 작업 트리에 커밋하지 않은 변경이 있으면 멈춘다(--force면 BUNDLE.json에 dirty=true로 남기고 계속).
 * 결과: 성공 0, 검증 실패 1, 사용법·환경 오류 2.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$opt = getopt('', ['out:', 'zip', 'with-bin', 'force']);
$outRoot = rtrim(str_replace('\\', '/', (string) ($opt['out'] ?? PJG_ROOT . '/dist')), '/');
$git = static function (array $args): string {
    $p = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT);
    if (!is_resource($p)) {
        fwrite(STDERR, "git을 실행할 수 없습니다\n");
        exit(2);
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
};

$commit = trim($git(['rev-parse', 'HEAD']));
$dirty = trim($git(['status', '--porcelain'])) !== '';
if ($commit === '') {
    fwrite(STDERR, "git 저장소가 아닙니다\n");
    exit(2);
}
if ($dirty && !isset($opt['force'])) {
    fwrite(STDERR, "커밋하지 않은 변경이 있습니다. 커밋한 뒤 만들거나 --force(BUNDLE.json에 dirty 표시)\n");
    exit(2);
}
if (!is_file(PJG_ROOT . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/가 없습니다 — 먼저 composer install --no-dev --optimize-autoloader(docs/vendor_build.md 3절)\n");
    exit(2);
}
$installed = json_decode((string) file_get_contents(PJG_ROOT . '/vendor/composer/installed.json'), true);
if (($installed['dev'] ?? false) === true && ($installed['dev-package-names'] ?? []) !== []) {
    fwrite(STDERR, "vendor/에 개발 의존성이 있습니다 — composer install --no-dev로 다시 설치하세요\n");
    exit(2);
}

$excludePrefixes = ['tests/', 'tools/proto/', 'tools/guide_demo/', 'tools/acceptance/', 'samples/zips/', 'samples/zips_bad/', 'docs/results/', 'prompts/', 'dist/', '.git'];
// 커밋된 파일 + 무시 목록에 없는 새 파일(커밋 전 작업 트리로 만들 때 새 소스가 빠지지 않게 — 그때는 dirty로 표시됨)
$files = array_values(array_filter(explode("\0", $git(['ls-files', '-z', '--cached', '--others', '--exclude-standard'])), static function (string $f) use ($excludePrefixes): bool {
    if ($f === '') {
        return false;
    }
    foreach ($excludePrefixes as $p) {
        if (str_starts_with($f, $p)) {
            return false;
        }
    }
    if (str_starts_with($f, 'data/')) {
        return basename($f) === '.gitkeep'; // data/는 빈 폴더 뼈대만(설치하자마자 폴더가 있게 — verify_bundle이 데이터 파일을 막음)
    }
    return !in_array($f, ['PJGuardian_requirements.md'], true); // 요구사항 명세(개발 문서)
}));

$version = PJGuardian\App::VERSION;
$name = 'pjguardian-' . $version . '-' . gmdate('Ymd-His') . '-' . substr($commit, 0, 8);
$dir = "{$outRoot}/{$name}";
if (!is_dir($outRoot) && !mkdir($outRoot, 0777, true)) {
    fwrite(STDERR, "출력 폴더를 만들 수 없습니다: {$outRoot}\n");
    exit(2);
}
mkdir($dir, 0777, true);
$copy = static function (string $rel) use ($dir): void {
    $src = PJG_ROOT . '/' . $rel;
    if (!is_file($src)) {
        return; // git이 아는데 작업 트리에서 지운 파일(--force일 때)
    }
    $dst = $dir . '/' . $rel;
    if (!is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0777, true);
    }
    copy($src, $dst);
};
foreach ($files as $f) {
    $copy($f);
}
// vendor/ 통째로(git 무시 — 빌드 PC의 설치 결과)
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PJG_ROOT . '/vendor', FilesystemIterator::SKIP_DOTS));
$vendorFiles = 0;
foreach ($it as $f) {
    $copy('vendor/' . substr(str_replace('\\', '/', $f->getPathname()), strlen(PJG_ROOT . '/vendor/')));
    $vendorFiles++;
}
// bin/ 실행 파일(선택): 매니페스트에 적힌 파일만
$binFiles = 0;
if (isset($opt['with-bin'])) {
    foreach (glob(PJG_ROOT . '/bin/*/*/SHA256SUMS') ?: [] as $sums) {
        $base = dirname($sums);
        foreach (file($sums, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^[0-9a-f]{64}\s+\*?(.+)$/i', $line, $m) === 1) {
                $copy(substr(str_replace('\\', '/', $base), strlen(PJG_ROOT) + 1) . '/' . trim($m[1]));
                $binFiles++;
            }
        }
    }
}

// 무결성 목록과 묶음 정보
$all = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $all[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
}
sort($all);
$sumLines = '';
foreach ($all as $rel) {
    $sumLines .= hash_file('sha256', "{$dir}/{$rel}") . '  ' . $rel . "\n";
}
file_put_contents("{$dir}/SHA256SUMS", $sumLines);
file_put_contents("{$dir}/BUNDLE.json", json_encode([
    'name' => $name, 'app_version' => $version, 'commit' => $commit, 'dirty' => $dirty, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'files' => count($all), 'vendor_files' => $vendorFiles, 'bin_files' => $binFiles, 'php_min' => '8.2',
    'install' => 'docs/install_guide.md — config/config.ini.example을 config/config.ini로 복사·편집, data/ 하위 폴더 생성, php tools/migrate.php, php tools/create_admin.php',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
printf("묶음: %s\n파일 %d개(vendor %d, bin 실행 파일 %d), 커밋 %s%s\n", $dir, count($all) + 2, $vendorFiles, $binFiles, substr($commit, 0, 8), $dirty ? ' (작업 트리 변경 있음 — dirty)' : '');

// 만든 직후 검증
echo "\n== tools/verify_bundle.php\n";
$p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/verify_bundle.php', $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
echo stream_get_contents($pipes[1]), stream_get_contents($pipes[2]);
$code = proc_close($p);
if ($code !== 0) {
    fwrite(STDERR, "검증 실패 — 묶음을 쓰지 마세요\n");
    exit(1);
}
if (isset($opt['zip'])) {
    $zipPath = "{$outRoot}/{$name}.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (array_merge($all, ['SHA256SUMS', 'BUNDLE.json']) as $rel) {
        $zip->addFile("{$dir}/{$rel}", "{$name}/{$rel}");
    }
    $zip->close();
    printf("zip: %s (%.1f MB)\n", $zipPath, filesize($zipPath) / 1048576);
}
exit(0);
