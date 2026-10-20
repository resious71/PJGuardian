<?php

declare(strict_types=1);

/**
 * bin/의 git·p4 점검(X09): 체크섬 매니페스트(ToolManifest::verify — 앱과 같은 함수), 변조 감지(임시 복사본), Linux 파일 형식(ELF x86-64),
 * Windows에서 격리 실행(절대 경로, PATH = System32만, GIT_CONFIG_NOSYSTEM=1, 임시 HOME — 사용자·시스템 설정을 읽지 않음).
 * 외부 저장소·서버에 접속하지 않는다(로컬 임시 저장소, p4는 localhost:1 접속 실패만 확인). bin/은 읽기만 한다.
 *
 *   php tools/check_bin_vcs.php [--git=2.56.0] [--p4=2026.1.0] [--no-tamper]
 * 종료 코드: 0 모두 통과, 1 실패 있음
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\Tools\ToolManifest;
use PJGuardian\Support\Fs;

$opt = getopt('', ['git::', 'p4::', 'no-tamper']);
$gitVer = is_string($opt['git'] ?? null) ? $opt['git'] : '2.56.0';
$p4Ver = is_string($opt['p4'] ?? null) ? $opt['p4'] : '2026.1.0';
$bin = PJG_ROOT . '/bin';
$win = PHP_OS_FAMILY === 'Windows';
$fail = 0;
$say = static function (bool $ok, string $what, string $detail = '') use (&$fail): void {
    $fail += $ok ? 0 : 1;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $what . ($detail === '' ? '' : ' — ' . $detail) . "\n";
};

/** 실행(인자 배열, 셸 없음). @return array{0:int, 1:string} */
function run(array $cmd, array $env, string $cwd, int $timeout = 30): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        return [-1, 'start failed'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $t0 = microtime(true);
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            proc_close($p);
            return [$st['exitcode'], $out];
        }
        if (microtime(true) - $t0 > $timeout) {
            proc_terminate($p);
            proc_close($p);
            return [-2, $out . "\n(timeout)"];
        }
        usleep(20_000);
    }
}

// 저장소 밖 임시 폴더(프로젝트 .git 설정을 찾아 올라가지 않게 GIT_CEILING_DIRECTORIES도 둔다)
$tmpRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/pjg_check_bin_vcs_' . bin2hex(random_bytes(3));
Fs::ensureDir($tmpRoot);
$home = $tmpRoot . '/home';
Fs::ensureDir($home);
$sysRoot = (string) (getenv('SystemRoot') ?: 'C:\\Windows');
$baseEnv = $win
    ? ['SystemRoot' => $sysRoot, 'PATH' => $sysRoot . '\\System32', 'TEMP' => $tmpRoot, 'TMP' => $tmpRoot, 'HOME' => $home, 'USERPROFILE' => $home]
    : ['PATH' => '/usr/bin:/bin', 'HOME' => $home, 'TMPDIR' => $tmpRoot];
$gitEnv = $baseEnv + ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $home . '/.gitconfig', 'XDG_CONFIG_HOME' => $home . '/.config',
    'GIT_TERMINAL_PROMPT' => '0', 'GIT_CEILING_DIRECTORIES' => dirname($tmpRoot), 'GIT_AUTHOR_NAME' => 'check', 'GIT_AUTHOR_EMAIL' => 'check@example.invalid',
    'GIT_COMMITTER_NAME' => 'check', 'GIT_COMMITTER_EMAIL' => 'check@example.invalid'];
$p4Env = $baseEnv + ['P4CONFIG' => '', 'P4ENVIRO' => $home . '/p4enviro', 'P4TICKETS' => $home . '/p4tickets', 'P4TRUST' => $home . '/p4trust', 'P4USER' => 'check', 'P4CLIENT' => 'check'];

try {
    // ---- 1. 매니페스트(앱과 같은 검증 함수) ----
    $gitDir = "{$bin}/git/{$gitVer}";
    $p4Dir = "{$bin}/p4/{$p4Ver}";
    $gitRequired = array_values(array_filter(['windows/cmd/git.exe', is_file("{$gitDir}/linux/bin/git") ? 'linux/bin/git' : null]));
    $r = ToolManifest::verify($gitDir, $gitRequired);
    $say($r['ok'], "git {$gitVer} 매니페스트", "파일 {$r['files']}개" . ($r['problems'] === [] ? '' : ': ' . implode('; ', $r['problems'])));
    $r = ToolManifest::verify($p4Dir, ['windows/p4.exe', 'linux/p4']);
    $say($r['ok'], "p4 {$p4Ver} 매니페스트", "파일 {$r['files']}개" . ($r['problems'] === [] ? '' : ': ' . implode('; ', $r['problems'])));

    // ---- 2. 변조 감지(임시 복사본 — 원본 bin/은 건드리지 않음) ----
    if (!isset($opt['no-tamper'])) {
        foreach ([[$p4Dir, 'linux/p4'], [$gitDir, 'windows/ucrt64/bin/git.exe']] as [$src, $victim]) {
            $copy = $tmpRoot . '/tamper_' . basename(dirname($src)) . '_' . basename($src);
            Fs::ensureDir($copy);
            foreach (array_keys(ToolManifest::parse($src)) as $rel) {
                Fs::ensureDir(dirname("{$copy}/{$rel}"));
                copy("{$src}/{$rel}", "{$copy}/{$rel}");
            }
            copy("{$src}/" . ToolManifest::FILE, "{$copy}/" . ToolManifest::FILE);
            $before = ToolManifest::verify($copy);
            $h = fopen("{$copy}/{$victim}", 'r+b');
            fseek($h, 1000);
            $b = fread($h, 1);
            fseek($h, 1000);
            fwrite($h, chr(ord($b) ^ 0x01));
            fclose($h);
            $after = ToolManifest::verify($copy);
            $say($before['ok'] && !$after['ok'] && in_array("해시 불일치: {$victim}", $after['problems'], true),
                '변조 감지(' . basename(dirname($src)) . ": {$victim} 1바이트)", implode('; ', $after['problems']));
            // 매니페스트에 없는 진입점 요구도 거부
            $miss = ToolManifest::verify($copy, ['windows/없는파일.exe']);
            $say(!$miss['ok'] && in_array('매니페스트에 없음: windows/없는파일.exe', $miss['problems'], true), '매니페스트에 없는 진입점 거부(' . basename(dirname($src)) . ')');
        }
    }

    // ---- 3. Linux 파일 형식(Windows에서는 실행하지 않음) ----
    foreach (array_filter(["{$p4Dir}/linux/p4", is_file("{$gitDir}/linux/bin/git") ? "{$gitDir}/linux/bin/git" : null]) as $elf) {
        $head = (string) file_get_contents($elf, false, null, 0, 20);
        $ok = str_starts_with($head, "\x7FELF") && ord($head[4]) === 2 && ord($head[5]) === 1 && unpack('v', substr($head, 18, 2))[1] === 0x3E;
        $say($ok, 'ELF 64비트 little-endian x86-64: ' . substr($elf, strlen(PJG_ROOT) + 1), sprintf('%s바이트', number_format(filesize($elf))));
    }

    // ---- 4. Windows 실행(격리 환경) ----
    if ($win) {
        $git = "{$gitDir}/windows/cmd/git.exe";
        [$code, $out] = run([$git, '--version'], $gitEnv, $tmpRoot);
        $say($code === 0 && str_contains($out, 'git version 2.56.0.windows.2'), 'git --version', trim($out));
        [$code, $out] = run([$git, 'config', '--list', '--show-origin', '--show-scope'], $gitEnv, $tmpRoot);
        $say($code === 0 && !preg_match('/^(system|global)\s/m', $out), 'git 설정: 시스템(번들 etc/gitconfig·설치된 Git)·전역 설정을 읽지 않음', trim($out) === '' ? '(설정 없음)' : str_replace("\n", ' | ', trim($out)));
        [$code, $out] = run([$git, 'config', '--system', '--list', '--show-origin'], $gitEnv + ['GIT_CONFIG_NOSYSTEM' => '0'], $tmpRoot);
        $say(str_contains($out, 'etc/gitconfig'), '참고: GIT_CONFIG_NOSYSTEM이 없으면 번들 etc/gitconfig를 읽음(앱은 항상 끈다)', substr(str_replace("\n", ' | ', trim($out)), 0, 300));
        // 로컬 임시 저장소: init → commit → 로컬 경로 ls-remote, clone
        $repo = $tmpRoot . '/repo';
        Fs::ensureDir($repo);
        file_put_contents($repo . '/a.txt', "hello\n");
        $steps = [[['init', '-q', '-b', 'main'], $repo], [['add', 'a.txt'], $repo], [['commit', '-q', '-m', 'x'], $repo], [['ls-remote', $repo], $tmpRoot],
            [['clone', '-q', '--depth', '1', 'file:///' . str_replace('\\', '/', $repo), $tmpRoot . '/clone'], $tmpRoot], [['-C', $tmpRoot . '/clone', 'rev-parse', 'HEAD'], $tmpRoot]];
        $log = [];
        $okAll = true;
        foreach ($steps as [$args, $cwd]) {
            [$code, $out] = run([$git, ...$args], $gitEnv, $cwd);
            $okAll = $okAll && $code === 0;
            $log[] = $args[0] === '-C' ? 'rev-parse ' . trim($out) : $args[0] . '=' . $code;
        }
        $say($okAll && is_file($tmpRoot . '/clone/a.txt'), '로컬 임시 저장소(init·add·commit·ls-remote·clone·rev-parse)', implode(', ', $log));
        // 설치 위치와 무관: 다른 폴더(변조 시험용 사본이 아닌 새 사본)에서 실행해 exec-path가 그 위치 기준인지
        $moved = $tmpRoot . '/moved/mingit';
        foreach (array_keys(ToolManifest::parse($gitDir)) as $rel) {
            Fs::ensureDir(dirname("{$moved}/{$rel}"));
            copy("{$gitDir}/{$rel}", "{$moved}/{$rel}");
        }
        [$code, $out] = run(["{$moved}/windows/cmd/git.exe", '--exec-path'], $gitEnv, $tmpRoot);
        $norm = strtolower(str_replace('\\', '/', trim($out)));
        $say($code === 0 && str_starts_with($norm, strtolower(str_replace('\\', '/', $moved))), '다른 위치로 옮겨도 자기 위치 기준(exec-path)', trim($out));
        [$code, $out] = run(["{$moved}/windows/cmd/git.exe", '-C', $tmpRoot . '/clone', 'log', '--oneline', '-1'], $gitEnv, $tmpRoot);
        $say($code === 0, '옮긴 사본으로 git log', trim($out));
        [$code, $out] = run(["{$moved}/windows/cmd/git.exe", 'ls-remote', 'https://127.0.0.1:1/none.git'], $gitEnv, $tmpRoot, 20);
        $say($code !== 0 && preg_match('/unable to access|Failed to connect|Could not connect/i', $out) === 1, 'HTTPS 도우미(git-remote-https) 로드 — 닫힌 로컬 포트라 접속 실패가 정상', trim(strtok($out, "\n") ?: ''));

        $p4 = "{$p4Dir}/windows/p4.exe";
        [$code, $out] = run([$p4, '-V'], $p4Env, $tmpRoot);
        preg_match('/Rev\. (\S+) \(([^)]+)\)/', $out, $m);
        $say($code === 0 && str_contains($out, 'P4/NTX64/2026.1/'), 'p4 -V', ($m[1] ?? '?') . ' (' . ($m[2] ?? '?') . ')');
        [$code, $out] = run([$p4, '-p', 'localhost:1', 'info'], $p4Env, $tmpRoot, 20);
        $say($code !== 0 && stripos($out, 'Connect to server failed') !== false, 'p4 -p localhost:1 info → 정상적인 접속 실패', str_replace("\n", ' | ', trim($out)));
        $say(!is_file($home . '/p4enviro') && !is_file($home . '/p4tickets'), 'p4가 설정·티켓 파일을 만들지 않음');
    } else {
        echo "[SKIP] 실행 확인은 Windows용 — Linux에서는 linux/ 실행 파일을 따로 확인(docs/vcs_tools.md)\n";
    }
} finally {
    // git 객체 파일은 읽기 전용이라 먼저 쓰기 권한을 준 뒤 지운다
    if (is_dir($tmpRoot)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS)) as $f) {
            @chmod($f->getPathname(), 0666);
        }
    }
    Fs::removeTree($tmpRoot);
    if (is_dir($tmpRoot)) {
        echo "[WARN] 임시 폴더를 다 지우지 못함: {$tmpRoot}\n";
    }
}
echo $fail === 0 ? "모두 통과\n" : "실패 {$fail}\n";
exit($fail === 0 ? 0 : 1);
