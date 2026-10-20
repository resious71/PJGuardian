<?php

declare(strict_types=1);

/**
 * bin/ 도구 내려받기 도우미(P33, docs/bin_setup.md 7절). 저장소의 체크섬 매니페스트가 기준이다 — 받은 파일의 SHA-256이 매니페스트와
 * 같을 때만 bin/에 두고, 다르면 지운다.
 *   php tools/fetch_bin.php --verify [도구 [버전]]           # 오프라인: bin/의 버전 폴더를 매니페스트와 대조(읽기만)
 *   php tools/fetch_bin.php --plan                            # 받을 것·주소·저장 위치를 출력만(네트워크 안 씀)
 *   php tools/fetch_bin.php --download=ruff|git [--version=<버전>] [--os=windows|linux]
 *   공통: --bin=<bin 폴더>(기본 저장소의 bin/, 시험용)
 *
 * - ruff: 공식 GitHub 릴리스의 압축 파일과 .sha256을 받아 대조 → 실행 파일 하나만 꺼내 매니페스트(bin/ruff/<버전>/SHA256SUMS)와 대조.
 * - git(Windows): VERSION.txt의 MinGit 주소·zip SHA-256으로 받아 대조 → 안전 해제(경로 이탈·절대 경로 거부) → 매니페스트 전체 대조.
 * - ESLint(npm ci)·Node.js·p4(독점 — 사용자가 약관에 동의하고 직접 받음)는 받지 않고 --plan에 절차만 적는다.
 * - https만, 인증서 검증 켬, 크기 상한. 이 도구는 개발 중 인터넷 없이 작성했다 — 실제 내려받기는 아직 실행해 보지 않았다(docs/bin_setup.md).
 * 종료 코드: 0 성공, 1 대조 실패·내려받기 실패, 2 사용 오류.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\Tools\ToolManifest;
use PJGuardian\Support\Fs;

$opt = getopt('', ['verify', 'plan', 'download:', 'version:', 'os:', 'bin:'], $rest);
$args = array_slice($argv, $rest);
$bin = rtrim(str_replace('\\', '/', (string) ($opt['bin'] ?? PJG_ROOT . '/bin')), '/');
$os = (string) ($opt['os'] ?? (PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux'));
if (!in_array($os, ['windows', 'linux'], true)) {
    fwrite(STDERR, "--os는 windows 또는 linux\n");
    exit(2);
}

/** @return list<string> 매니페스트가 있는 버전(semver 내림차순) */
$versionsOf = static function (string $tool) use ($bin): array {
    $v = array_map(static fn (string $p): string => basename(dirname($p)), glob("{$bin}/{$tool}/*/SHA256SUMS") ?: []);
    usort($v, static fn (string $a, string $b): int => version_compare($b, $a));
    return $v;
};

if (isset($opt['verify'])) {
    $tools = isset($args[0]) ? [$args[0]] : ['ruff', 'eslint', 'git', 'p4'];
    $bad = 0;
    foreach ($tools as $tool) {
        foreach (isset($args[1]) ? [$args[1]] : $versionsOf($tool) as $v) {
            $dir = "{$bin}/{$tool}/{$v}";
            $r = ToolManifest::verify($dir);
            // 다른 OS 폴더의 파일이 없는 것은 문제가 아니다(ruff·p4 매니페스트는 두 OS를 함께 적는다)
            $other = $os === 'windows' ? 'linux/' : 'windows/';
            $r['problems'] = array_values(array_filter($r['problems'], static fn (string $p): bool => !str_starts_with($p, '파일 없음: ' . $other)));
            $r['ok'] = $r['ok'] || $r['problems'] === [];
            // 매니페스트에 적힌 파일이 하나도 없으면 "아직 두지 않음"(이 OS에서 안 쓰는 도구일 수 있음)
            $absent = $r['files'] > 0 && $r['problems'] !== [] && count(array_filter($r['problems'], static fn (string $p): bool => str_starts_with($p, '파일 없음'))) === count($r['problems']);
            if ($r['ok']) {
                printf("OK    %-7s %-10s 매니페스트 %d개 중 이 OS의 파일 모두 일치\n", $tool, $v, $r['files']);
            } elseif ($absent) {
                printf("없음  %-7s %-10s 매니페스트만 있음(파일을 아직 두지 않음 — docs/bin_setup.md)\n", $tool, $v);
            } else {
                $bad++;
                printf("FAIL  %-7s %-10s %s\n", $tool, $v, implode('; ', array_slice($r['problems'], 0, 5)));
            }
        }
    }
    exit($bad === 0 ? 0 : 1);
}

$ruffAsset = static fn (string $o): string => $o === 'windows' ? 'ruff-x86_64-pc-windows-msvc.zip' : 'ruff-x86_64-unknown-linux-gnu.tar.gz';
$ruffUrl = static fn (string $v, string $o): string => "https://github.com/astral-sh/ruff/releases/download/{$v}/" . $ruffAsset($o);
/** @return array{url: string, sha256: string}|null bin/git/<버전>/VERSION.txt의 MinGit 출처 */
$mingit = static function (string $v) use ($bin): ?array {
    $t = (string) @file_get_contents("{$bin}/git/{$v}/VERSION.txt");
    if (preg_match('#출처: (https://github\.com/git-for-windows/git/releases/download/\S+/MinGit-\S+\.zip)#u', $t, $u) !== 1
        || preg_match('/zip sha256: ([0-9a-f]{64})/', $t, $h) !== 1) {
        return null;
    }
    return ['url' => $u[1], 'sha256' => $h[1]];
};

if (isset($opt['plan'])) {
    foreach ($versionsOf('ruff') as $v) {
        echo "ruff {$v} ({$os}): " . $ruffUrl($v, $os) . " (+ .sha256) → bin/ruff/{$v}/{$os}/ — php tools/fetch_bin.php --download=ruff --version={$v} --os={$os}\n";
    }
    foreach ($versionsOf('eslint') as $v) {
        echo "eslint {$v}: cd bin/eslint/{$v} && npm ci --ignore-scripts → php tools/fetch_bin.php --verify eslint {$v}\n";
    }
    foreach ($versionsOf('git') as $v) {
        $m = $mingit($v);
        echo "git {$v} (windows): " . ($m['url'] ?? 'VERSION.txt에 출처 없음') . " → bin/git/{$v}/windows/ — php tools/fetch_bin.php --download=git --version={$v}\n";
        echo "git {$v} (linux): 공식 바이너리 없음 — tools/build_git_linux.sh(docs/vcs_tools.md 4절)\n";
    }
    foreach ($versionsOf('p4') as $v) {
        echo "p4 {$v}: 독점 소프트웨어 — Perforce 공식 사이트에서 약관에 동의하고 직접 받아 bin/p4/{$v}/<os>/에 둠(VERSION.txt의 파일 이름·SHA-256과 대조)\n";
    }
    echo "Node.js: 공식 사이트의 LTS(설정 tools.node_min_version 이상)를 서버에 설치 — bin/ 밖\n";
    exit(0);
}

$tool = (string) ($opt['download'] ?? '');
if (!in_array($tool, ['ruff', 'git'], true)) {
    fwrite(STDERR, "사용법: --verify [도구 [버전]] | --plan | --download=ruff|git [--version=] [--os=]\n");
    exit(2);
}
$version = (string) ($opt['version'] ?? ($versionsOf($tool)[0] ?? ''));
if ($version === '' || !is_file("{$bin}/{$tool}/{$version}/SHA256SUMS")) {
    fwrite(STDERR, "{$tool} {$version}의 매니페스트가 없습니다 — 저장소에 등록된 버전만 받습니다\n");
    exit(2);
}

/** https만, 인증서 검증, 리다이렉트 5번, 크기 상한. 저장한 파일 경로 또는 예외 */
$download = static function (string $url, string $dest, int $maxBytes): void {
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException("https 주소만 받습니다: {$url}");
    }
    $ctx = stream_context_create([
        'http' => ['follow_location' => 1, 'max_redirects' => 5, 'timeout' => 60, 'user_agent' => 'PJGuardian-fetch-bin'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) {
        throw new RuntimeException("받을 수 없습니다: {$url}");
    }
    $out = fopen($dest, 'wb');
    $n = 0;
    while (!feof($in)) {
        $chunk = (string) fread($in, 1 << 16);
        $n += strlen($chunk);
        if ($n > $maxBytes) {
            fclose($in);
            fclose($out);
            throw new RuntimeException("크기 상한({$maxBytes}바이트)을 넘었습니다: {$url}");
        }
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
};

$stage = PJG_ROOT . '/data/work/fetch_bin_' . bin2hex(random_bytes(4));
Fs::ensureDir($stage);
$dirOs = "{$bin}/{$tool}/{$version}/{$os}";
try {
    $manifest = ToolManifest::parse("{$bin}/{$tool}/{$version}");
    if ($tool === 'ruff') {
        $rel = $os === 'windows' ? 'windows/ruff.exe' : 'linux/ruff';
        if (!isset($manifest[$rel])) {
            throw new RuntimeException("매니페스트에 {$rel}이 없습니다");
        }
        $archive = $stage . '/' . $ruffAsset($os);
        $download($ruffUrl($version, $os), $archive, 200 << 20);
        $download($ruffUrl($version, $os) . '.sha256', $archive . '.sha256', 4096);
        $expect = strtolower(substr(trim((string) file_get_contents($archive . '.sha256')), 0, 64));
        if (!hash_equals($expect, (string) hash_file('sha256', $archive))) {
            throw new RuntimeException('압축 파일 해시가 .sha256과 다릅니다');
        }
        $exe = $stage . '/' . basename($rel);
        if ($os === 'windows') {
            $zip = new ZipArchive();
            if ($zip->open($archive) !== true || ($data = $zip->getFromName('ruff.exe')) === false) {
                throw new RuntimeException('zip에 ruff.exe가 없습니다');
            }
            file_put_contents($exe, $data);
            $zip->close();
        } else {
            $tar = new PharData($archive);
            $inner = 'ruff-x86_64-unknown-linux-gnu/ruff';
            if (!isset($tar[$inner])) {
                throw new RuntimeException("압축 파일에 {$inner}가 없습니다");
            }
            file_put_contents($exe, (string) file_get_contents($tar[$inner]->getPathname()));
        }
        if (!hash_equals($manifest[$rel], (string) hash_file('sha256', $exe))) {
            throw new RuntimeException("실행 파일이 매니페스트와 다릅니다({$rel}) — 두지 않습니다");
        }
        Fs::ensureDir($dirOs);
        if (!rename($exe, "{$bin}/{$tool}/{$version}/{$rel}")) {
            throw new RuntimeException('bin/에 옮길 수 없습니다');
        }
        if ($os === 'linux') {
            chmod("{$bin}/{$tool}/{$version}/{$rel}", 0755);
        }
    } else {
        if ($os !== 'windows') {
            throw new RuntimeException('Linux git은 공식 바이너리가 없습니다 — tools/build_git_linux.sh(docs/vcs_tools.md 4절)');
        }
        $m = $mingit($version);
        if ($m === null) {
            throw new RuntimeException('VERSION.txt에 MinGit 출처·zip SHA-256이 없습니다');
        }
        $archive = $stage . '/mingit.zip';
        $download($m['url'], $archive, 300 << 20);
        if (!hash_equals($m['sha256'], (string) hash_file('sha256', $archive))) {
            throw new RuntimeException('MinGit zip 해시가 VERSION.txt와 다릅니다');
        }
        if (is_dir($dirOs)) {
            throw new RuntimeException("{$dirOs}가 이미 있습니다 — 지운 뒤 다시");
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('zip을 열 수 없습니다');
        }
        $tmp = $stage . '/windows';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)|^[A-Za-z]:#', $name) === 1) {
                throw new RuntimeException("안전하지 않은 항목 이름: {$name}");
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            Fs::ensureDir(dirname($tmp . '/' . $name));
            $in = $zip->getStream($name);
            $out = fopen($tmp . '/' . $name, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
        $zip->close();
        if (!rename($tmp, $dirOs)) {
            throw new RuntimeException('bin/에 옮길 수 없습니다');
        }
        $r = ToolManifest::verify("{$bin}/{$tool}/{$version}");
        if (!$r['ok']) {
            Fs::removeTree($dirOs);
            throw new RuntimeException('해제한 파일이 매니페스트와 다릅니다 — 지웠습니다: ' . implode('; ', array_slice($r['problems'], 0, 3)));
        }
    }
    echo "OK {$tool} {$version} ({$os}) — 매니페스트와 일치, bin/에 두었습니다. 다음: php tools/tools_ctl.php verify {$tool} {$version}\n";
    $code = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    $code = 1;
} finally {
    Fs::removeTree($stage);
}
exit($code);
