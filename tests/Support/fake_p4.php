<?php

declare(strict_types=1);

/**
 * 시험용 가짜 p4(P25): 실제 p4d가 없어 인자·환경 변수·종료 코드·-G 출력 형식만 흉내 낸다. 실행: php fake_p4.php <상태.json> <p4 인자…>. 상태:
 *   {password, ticket, fingerprint(서버 지문, ssl일 때), change, files: {상대 경로: 내용}, depot: "//depot/proj/", log: 기록 파일}
 * 호출마다 인자와 관심 환경 변수(P4PORT·P4USER·P4CONFIG·P4TICKETS·P4TRUST·P4ENVIRO, P4PASSWD는 값 대신 유무)를 log에 남긴다.
 * 지원 명령: trust -i <지문>, login(표준 입력 비밀번호), -G changes -m 1 -s submitted <경로>, -G print <경로>@<변경>, -V.
 */
require dirname(__DIR__, 2) . '/src/Services/Repo/P4Marshal.php';
require dirname(__DIR__, 2) . '/src/Services/Repo/RepoException.php';

use PJGuardian\Services\Repo\P4Marshal;

$st = json_decode((string) file_get_contents((string) $argv[1]), true); // 상태 파일(명령 앞부분으로 넘김: [php, fake_p4.php, 상태.json])
$args = array_slice($argv, 2);
$env = static fn (string $k): ?string => getenv($k) === false ? null : (string) getenv($k);
file_put_contents($st['log'], json_encode(['args' => $args, 'P4PORT' => $env('P4PORT'), 'P4USER' => $env('P4USER'), 'P4CONFIG' => $env('P4CONFIG'),
    'P4TICKETS' => $env('P4TICKETS'), 'P4TRUST' => $env('P4TRUST'), 'P4ENVIRO' => $env('P4ENVIRO'), 'has_P4PASSWD' => $env('P4PASSWD') !== null,
    'PATH' => $env('PATH'), 'HOME' => $env('HOME') ?? $env('USERPROFILE')]) . "\n", FILE_APPEND);

$port = (string) $env('P4PORT');
$ssl = str_starts_with($port, 'ssl:');
$trusted = static fn (): bool => !$ssl || str_contains((string) @file_get_contents((string) $env('P4TRUST')), (string) $st['fingerprint']);
$authed = static function () use ($st, $env): bool {
    return $env('P4PASSWD') === $st['ticket'] || str_contains((string) @file_get_contents((string) $env('P4TICKETS')), (string) $st['ticket']);
};
$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

if ($args === ['-V']) {
    echo "Rev. P4/FAKE/2026.1/1 (2026/01/01).\n";
    exit(0);
}
if (($st['unreachable'] ?? false) === true) {
    $fail("Perforce client error:\n\tConnect to server failed; check \$P4PORT.\n\tTCP connect to {$port} failed.");
}
if ($args[0] === 'trust') {
    if (($args[1] ?? '') === '-i' && ($args[2] ?? '') === $st['fingerprint']) {
        file_put_contents((string) $env('P4TRUST'), $port . ' ' . $st['fingerprint'] . "\n");
        exit(0);
    }
    $fail('Fingerprint mismatch.');
}
if (!$trusted()) {
    $fail("The authenticity of '{$port}' can't be established,\nthis may be your first attempt to connect to this P4PORT.\nThe fingerprint for the key sent to your client is\n{$st['fingerprint']}\nTo allow connection use the 'p4 trust' command.");
}
if ($args === ['login']) {
    $pw = trim((string) stream_get_contents(STDIN));
    if ($pw !== $st['password']) {
        $fail("Password invalid.");
    }
    file_put_contents((string) $env('P4TICKETS'), $port . '=' . $env('P4USER') . ':' . $st['ticket'] . "\n");
    echo "User logged in.\n";
    exit(0);
}
if (!$authed()) {
    $fail('Perforce password (P4PASSWD) invalid or unset.');
}
if ($args[0] === '-G' && ($args[1] ?? '') === 'changes') {
    $path = (string) end($args);
    if (!str_starts_with($path, $st['depot'])) {
        echo P4Marshal::encode(['code' => 'error', 'data' => "{$path} - no such file(s).\n", 'severity' => 2, 'generic' => 17]);
        exit(1);
    }
    echo P4Marshal::encode(['code' => 'stat', 'change' => (string) $st['change'], 'status' => 'submitted', 'user' => 'dev']);
    exit(0);
}
if ($args[0] === '-G' && ($args[1] ?? '') === 'print') {
    [$path, $change] = explode('@', (string) end($args)) + [1 => ''];
    if ($change !== (string) $st['change']) {
        $fail('bad change');
    }
    foreach ($st['files'] as $rel => $content) {
        $type = str_ends_with($rel, '.lnk') ? 'symlink' : 'text';
        echo P4Marshal::encode(['code' => 'stat', 'depotFile' => $st['depot'] . $rel, 'rev' => '1', 'change' => (string) $st['change'], 'action' => 'add', 'type' => $type]);
        foreach (str_split($content === '' ? ' ' : $content, 7) as $chunk) {
            echo P4Marshal::encode(['code' => 'text', 'data' => $chunk]);
        }
    }
    foreach (($st['deleted'] ?? []) as $rel) {
        echo P4Marshal::encode(['code' => 'stat', 'depotFile' => $st['depot'] . $rel, 'rev' => '2', 'action' => 'delete', 'type' => 'text']);
    }
    exit(0);
}
$fail('unsupported fake p4 command: ' . implode(' ', $args));
