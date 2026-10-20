<?php

declare(strict_types=1);

/**
 * 인수 시험(P32) 시나리오 9의 가짜 Git 서버 준비(시험 지원 코드 tests/Support/RepoFixture를 그대로 씀 — 묶음에는 없음, 인터넷 없음).
 *   php tools/acceptance/git_fixture.php commit <폴더> <저장소 이름> <원본 폴더> [추가 파일 JSON]   → 커밋 해시
 *   php tools/acceptance/git_fixture.php config <폴더> <포트> <사용자:토큰>                           → git_http_router.php용 설정 파일 경로
 * 서버는 실행기가 `PJG_GIT_HTTP_CONFIG=<설정> php -S 127.0.0.1:<포트> tests/Support/git_http_router.php`로 띄운다(포트를 고정해 껐다 켤 수 있게).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Tests\Support\RepoFixture;

[, $cmd, $root] = $argv + [1 => '', 2 => ''];
if ($root === '' || !in_array($cmd, ['commit', 'config'], true)) {
    fwrite(STDERR, "사용법: php tools/acceptance/git_fixture.php commit|config <폴더> …\n");
    exit(2);
}
@mkdir($root, 0777, true);
$f = new RepoFixture(str_replace('\\', '/', (string) realpath($root)));
if ($cmd === 'commit') {
    [, , , $name, $src, $extra] = $argv + [3 => 'app', 4 => '', 5 => '{}'];
    $files = [];
    if ($src !== '' && is_dir($src)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            if (!str_contains($rel, '__pycache__')) {
                $files[$rel] = (string) file_get_contents($file->getPathname());
            }
        }
    }
    foreach ((array) json_decode((string) $extra, true) as $rel => $content) {
        $files[(string) $rel] = $content === null ? null : (string) $content;
    }
    echo $f->commit($name, $files), "\n";
    exit(0);
}
[, , , $port, $auth] = $argv + [3 => '0', 4 => ''];
$cfg = $f->root . '/git_http.json';
file_put_contents($cfg, json_encode(['root' => $f->root . '/served', 'backend' => RepoFixture::httpBackend(), 'log' => $f->root . '/http.log', 'auth' => $auth === '' ? null : $auth]));
echo $cfg, "\n";
