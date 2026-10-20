<?php

declare(strict_types=1);

/**
 * 인수 시험(P32) 설치 단계: 릴리스 묶음 zip을 새 폴더에 풀고 설치 가이드(docs/install_guide.md 2·5·6·7절) 순서대로 설치한다.
 *   php tools/acceptance/install.php --zip=<묶음 zip> --dir=<빈 폴더> --port=<포트> [--bin=ruff,eslint,git] [--restore-only]
 *
 * - 묶음 점검(tools/verify_bundle.php) → 설정·비밀 예시 복사 → base_url·require_https(시험 서버는 http)·node_path만 바꿈 → make_secret_key →
 *   bin/ 도구 배치(개발 PC의 bin/<도구>/<버전>을 읽기만 해서 복사 — 6절의 "다른 PC에서 만든 폴더를 통째로 복사") → check_env → migrate → migrate --status →
 *   create_admin(초기 비밀번호 admin).
 * - --restore-only: 10.3절(새 PC — 설치까지): 7절(DB 만들기)과 create_admin을 건너뛴다(복원이 DB를 만든다).
 * - 개발 PC의 공유 config·개발 DB·사용자 워커는 읽지도 쓰지도 않는다. 결과는 JSON 한 줄(단계별 종료 코드·핵심 줄)로 표준 출력에.
 *   비밀 값(키·비밀번호)은 출력하지 않는다.
 */

$opt = getopt('', ['zip:', 'dir:', 'port:', 'bin::', 'restore-only', 'node::']);
$zip = (string) ($opt['zip'] ?? '');
$dir = rtrim(str_replace('\\', '/', (string) ($opt['dir'] ?? '')), '/');
$port = (int) ($opt['port'] ?? 0);
if (!is_file($zip) || $dir === '' || $port <= 0 || (is_dir($dir) && array_diff(scandir($dir) ?: [], ['.', '..']) !== [])) {
    fwrite(STDERR, "사용법: php tools/acceptance/install.php --zip=<묶음 zip> --dir=<빈 폴더> --port=<포트> [--bin=ruff,eslint,git] [--restore-only]\n");
    exit(2);
}
$repo = str_replace('\\', '/', dirname(__DIR__, 2));
$php = PHP_BINARY;
$steps = [];
$fail = false;

/** @param list<string> $cmd */
function step(string $name, array $cmd, string $cwd, ?string $stdin = null, array $env = []): array
{
    global $steps, $fail;
    $t = microtime(true);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env === [] ? null : $env + getenv(), ['bypass_shell' => true]);
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    $code = proc_close($p);
    $lines = array_values(array_filter(array_map('rtrim', explode("\n", str_replace("\r", '', $out))), static fn (string $l): bool => $l !== ''));
    $steps[] = ['step' => $name, 'cmd' => implode(' ', array_map(static fn (string $a): string => str_contains($a, '/') ? basename($a) : $a, array_slice($cmd, 1))),
        'code' => $code, 'seconds' => round(microtime(true) - $t, 1), 'tail' => array_slice($lines, -12)];
    if ($code !== 0) {
        $fail = true;
    }
    return ['code' => $code, 'out' => $out];
}

/** ini 텍스트의 [섹션] 안 키 값을 바꾼다(주석·순서 유지) */
function setIni(string $text, string $section, string $key, string $value): string
{
    $lines = explode("\n", $text);
    $cur = '';
    foreach ($lines as $i => $line) {
        if (preg_match('/^\[([^\]]+)\]/', $line, $m) === 1) {
            $cur = $m[1];
        } elseif ($cur === $section && preg_match('/^' . preg_quote($key, '/') . '[ \t]*=/', $line) === 1) {
            $lines[$i] = $key . ' = "' . $value . '"' . (str_ends_with($line, "\r") ? "\r" : '');
            return implode("\n", $lines);
        }
    }
    throw new RuntimeException("설정 키 없음 [{$section}] {$key}");
}

@mkdir($dir, 0777, true);
$dir = str_replace("\\", "/", (string) realpath($dir));
$app = "{$dir}/app";

// 2절: 묶음 풀기(웹 루트 밖 새 폴더)
$z = new ZipArchive();
if ($z->open($zip) !== true) {
    fwrite(STDERR, "zip을 열 수 없음\n");
    exit(1);
}
$top = '';
for ($i = 0; $i < $z->numFiles; $i++) {
    $n = (string) $z->getNameIndex($i);
    if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\')) {
        fwrite(STDERR, "묶음에 이상한 경로: {$n}\n");
        exit(1);
    }
    $first = explode('/', $n)[0];
    $top = $top === '' ? $first : ($top === $first ? $top : '*');
}
$tmp = "{$dir}/_unzip";
$count = $z->numFiles;
$z->extractTo($tmp); // 이 PC가 만든 릴리스 묶음(위에서 경로 검사) — 업로드 해제 규칙(extractTo 금지)은 사용자 zip에 대한 것
$z->close();
rename($top !== '*' && is_dir("{$tmp}/{$top}") ? "{$tmp}/{$top}" : $tmp, $app);
@rmdir($tmp);
$steps[] = ['step' => 'unzip', 'cmd' => basename($zip), 'code' => 0, 'seconds' => 0, 'tail' => ["{$count}개 항목(최상위 {$top}/) → app/"]];

step('verify_bundle', [$php, "{$app}/tools/verify_bundle.php", $app], $app);

// 5절: 설정 파일과 비밀 키
copy("{$app}/config/config.ini.example", "{$app}/config/config.ini");
if (!is_file("{$app}/config/secrets.ini")) {
    copy("{$app}/config/secrets.example", "{$app}/config/secrets.ini");
}
$ini = (string) file_get_contents("{$app}/config/config.ini");
$ini = setIni($ini, 'app', 'base_url', "http://127.0.0.1:{$port}");
$ini = setIni($ini, 'security', 'require_https', 'false'); // 시험 서버는 http(내장 서버) — 운영은 true(HTTPS)
$node = (string) ($opt['node'] ?? (is_file('C:/tools/nodejs/node.exe') ? 'C:/tools/nodejs/node.exe' : ''));
$ini = setIni($ini, 'tools', 'node_path', $node);
file_put_contents("{$app}/config/config.ini", $ini);
$steps[] = ['step' => 'config', 'cmd' => 'copy config.ini.example, secrets.example', 'code' => 0, 'seconds' => 0,
    'tail' => ["base_url = http://127.0.0.1:{$port}", 'require_https = false(시험 서버 http)', 'node_path = ' . ($node === '' ? '(PATH)' : basename($node))]];
step('make_secret_key', [$php, "{$app}/tools/make_secret_key.php"], $app);

// 6절: bin/ 도구 배치(개발 PC의 bin/에서 읽기만 — 실행 파일은 묶음에 없고 체크섬 목록만 있다)
$bins = array_filter(explode(',', (string) ($opt['bin'] ?? 'ruff,eslint')));
$copied = [];
foreach ($bins as $tool) {
    foreach (glob("{$repo}/bin/{$tool}/*", GLOB_ONLYDIR) ?: [] as $verDir) {
        $ver = basename($verDir);
        if (!is_file("{$verDir}/SHA256SUMS")) {
            continue;
        }
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($verDir) + 1);
            $dst = "{$app}/bin/{$tool}/{$ver}/{$rel}";
            if ($f->isDir()) {
                @mkdir($dst, 0777, true);
            } elseif (!is_file($dst)) {
                @mkdir(dirname($dst), 0777, true);
                copy($f->getPathname(), $dst);
                $n++;
            }
        }
        $copied[] = "{$tool} {$ver}({$n}개 파일)";
    }
}
$steps[] = ['step' => 'bin', 'cmd' => 'copy bin/' . implode(',', $bins), 'code' => 0, 'seconds' => 0, 'tail' => $copied];

// 7절: 데이터베이스(새 설치일 때만)
$env = step('check_env', [$php, "{$app}/tools/check_env.php"], $app);
if (!isset($opt['restore-only'])) {
    step('migrate', [$php, "{$app}/tools/migrate.php"], $app);
    step('migrate_status', [$php, "{$app}/tools/migrate.php", '--status'], $app);
    step('tools_verify', [$php, "{$app}/tools/tools_ctl.php", 'list'], $app);
    step('create_admin', [$php, "{$app}/tools/create_admin.php", '--login=admin', '--name=관리자', '--skip-http'], $app);
}
echo json_encode(['ok' => !$fail, 'app' => $app, 'steps' => $steps], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit($fail ? 1 : 0);
