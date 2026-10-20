<?php

declare(strict_types=1);

/**
 * 배포 묶음 점검(P29, 읽기 전용 — 파일을 바꾸지 않는다).
 *   php tools/verify_bundle.php <묶음 폴더>
 *
 * 있어야 하는 것: vendor/autoload.php(Composer 라이브러리 — SAML 등, git에 없으므로 묶음을 만들 때 composer install --no-dev 결과를 넣어야 함),
 *   resources/guide/user/ko·en(가이드 본문), samples/의 도구 스모크 시료(P32 — ToolSmokeTest가 쓰는 python_sample·python_syntax_error·js_sample_noconfig·ts_sample), resources/faq/seed.ko.json(FAQ 시드, X16), resources/guide/screenshots.json이 가리키는 이미지 전부, composer.lock과 vendor/composer/installed.json의 버전 일치.
 * 없어야 하는 것: 개발 전용 tests/·tools/proto/·.git, 비밀·데이터 config/secrets.ini·secrets.*(secrets.example 제외)·*.pjgkey·data/의 파일(빈 폴더 표지 .gitkeep만 허용, P31),
 *   설치마다 다른 설정 config/config.ini·config/settings.local.ini*(X14 — 개발 PC의 설정이 운영으로 넘어가지 않게, config/*.example은 허용).
 * 결과: 정상 0, 문제 있으면 목록을 출력하고 1, 사용법 오류 2.
 */

if ($argc < 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "사용법: php tools/verify_bundle.php <묶음 폴더>\n");
    exit(2);
}
$root = rtrim(str_replace('\\', '/', (string) realpath($argv[1])), '/');
$problems = [];
$ok = [];

// 1. 있어야 하는 것
if (is_file("{$root}/vendor/autoload.php")) {
    $ok[] = 'vendor/autoload.php';
} else {
    $problems[] = 'vendor/autoload.php 없음 — 묶음을 만들 때 composer install --no-dev --optimize-autoloader 결과를 넣으세요(docs/vendor_build.md 3절)';
}
foreach (['ko', 'en'] as $lang) {
    $dir = "{$root}/resources/guide/user/{$lang}";
    $md = is_dir($dir) ? glob($dir . '/*.md') : [];
    if ($md === [] || $md === false) {
        $problems[] = "resources/guide/user/{$lang} 없음 또는 본문(.md) 없음";
    } else {
        $ok[] = "resources/guide/user/{$lang}(" . count($md) . '개 절)';
    }
}
// P32: 도구 스모크 시료(ToolSmokeTest) — 없으면 새 설치에서 ruff·ESLint 버전이 스모크 실패('시료 없음')로 모두 사용 불가가 되어 분석을 못 한다
$smokeMissing = [];
foreach (['python_sample', 'python_syntax_error', 'js_sample_noconfig', 'ts_sample'] as $s) {
    if ((glob("{$root}/samples/{$s}/*") ?: []) === []) {
        $smokeMissing[] = $s;
    }
}
if ($smokeMissing === []) {
    $ok[] = 'samples/ 도구 스모크 시료 4개';
} else {
    $problems[] = '도구 스모크 시료 없음: samples/' . implode(', samples/', $smokeMissing) . ' — 없으면 분석 도구가 모두 사용 불가(build_bundle은 samples/의 소스 시료를 넣음)';
}
// X16: FAQ 시드(없어도 앱은 동작하지만 첫 설치에서 tools/faq_import.php로 FAQ를 채울 수 없다)
$seed = "{$root}/resources/faq/seed.ko.json";
$seedData = is_file($seed) ? json_decode((string) file_get_contents($seed), true) : null;
if (!is_array($seedData) || !is_array($seedData['items'] ?? null)) {
    $problems[] = 'resources/faq/seed.ko.json 없음 또는 형식 오류 — FAQ 시드(tools/faq_import.php)';
} else {
    $ok[] = 'resources/faq/seed.ko.json(' . count($seedData['items']) . '개 질문)';
}
$shotsFile = "{$root}/resources/guide/screenshots.json";
if (!is_file($shotsFile)) {
    $problems[] = 'resources/guide/screenshots.json 없음';
} else {
    $shots = json_decode((string) file_get_contents($shotsFile), true);
    if (!is_array($shots) || !is_array($shots['images'] ?? null)) {
        $problems[] = 'resources/guide/screenshots.json 형식 오류';
    } else {
        $n = 0;
        foreach ($shots['images'] as $img) {
            $aud = (string) ($img['audience'] ?? 'user');
            foreach ((array) ($img['langs'] ?? ['ko', 'en']) as $lang) {
                $file = "{$root}/resources/guide/{$aud}/img/{$img['slug']}.{$lang}.png";
                if (is_file($file)) {
                    $n++;
                } else {
                    $problems[] = "가이드 이미지 없음: resources/guide/{$aud}/img/{$img['slug']}.{$lang}.png";
                }
            }
        }
        $ok[] = "가이드 이미지 {$n}개";
    }
}
$lockFile = "{$root}/composer.lock";
$installedFile = "{$root}/vendor/composer/installed.json";
if (!is_file($lockFile)) {
    $problems[] = 'composer.lock 없음';
} elseif (!is_file($installedFile)) {
    $problems[] = 'vendor/composer/installed.json 없음(vendor/가 Composer로 설치되지 않음)';
} else {
    $lock = json_decode((string) file_get_contents($lockFile), true);
    $installed = json_decode((string) file_get_contents($installedFile), true);
    $want = [];
    foreach ((array) ($lock['packages'] ?? []) as $p) {
        $want[(string) $p['name']] = (string) $p['version'];
    }
    $have = [];
    foreach ((array) ($installed['packages'] ?? $installed ?? []) as $p) {
        if (is_array($p) && isset($p['name'], $p['version'])) {
            $have[(string) $p['name']] = (string) $p['version'];
        }
    }
    $mismatch = 0;
    foreach ($want as $name => $ver) {
        if (($have[$name] ?? null) !== $ver) {
            $mismatch++;
            $problems[] = "vendor 버전 불일치: {$name} composer.lock {$ver}, 설치 " . ($have[$name] ?? '없음');
        }
    }
    if ($want !== [] && $mismatch === 0) {
        $ok[] = 'composer.lock ↔ vendor 버전 일치(' . count($want) . '개)';
    }
}

// 2. 없어야 하는 것
foreach (['tests', 'tools/proto', '.git'] as $p) {
    if (file_exists("{$root}/{$p}")) {
        $problems[] = "{$p}/ 가 있음 — 배포 묶음에 넣지 않습니다(개발 전용)";
    }
}
// P31: data/는 빈 폴더 뼈대(.gitkeep)만 허용 — 설치하자마자 폴더가 있게(DB·업로드·로그 같은 데이터 파일이 하나라도 있으면 문제)
if (is_dir("{$root}/data")) {
    $dataFiles = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/data", FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getFilename() !== '.gitkeep') {
            $dataFiles++;
            $problems[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1) . ' — data/에는 빈 폴더 표지(.gitkeep)만 넣습니다(데이터 파일 금지)';
        }
    }
    if ($dataFiles === 0) {
        $ok[] = 'data/ 빈 폴더 뼈대만';
    }
}
foreach (glob("{$root}/config/secrets.*") ?: [] as $f) {
    if (basename($f) !== 'secrets.example') {
        $problems[] = 'config/' . basename($f) . ' 가 있음 — 비밀 파일은 묶음에 넣지 않습니다';
    }
}
foreach (array_merge(glob("{$root}/config/config.ini") ?: [], glob("{$root}/config/settings.local.ini*") ?: []) as $f) {
    $problems[] = 'config/' . basename($f) . ' 가 있음 — 설치마다 다른 설정 파일은 묶음에 넣지 않습니다(config/*.example만)';
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with(strtolower($f->getFilename()), '.pjgkey')) {
        $problems[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1) . ' — 키 파일(.pjgkey)은 묶음에 넣지 않습니다';
    }
}

foreach ($ok as $o) {
    echo "OK   {$o}\n";
}
foreach ($problems as $p) {
    echo "FAIL {$p}\n";
}
echo $problems === [] ? "묶음 점검 통과\n" : '문제 ' . count($problems) . "개\n";
exit($problems === [] ? 0 : 1);
