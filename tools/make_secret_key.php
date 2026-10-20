<?php

declare(strict_types=1);

/**
 * 저장소 자격 증명 암호화 키 만들기(P25, 명세 18.4·19.4). 키(32바이트 임의 값의 base64)를 **지정한 시크릿 파일에만** 쓴다 —
 * 화면·로그에 출력하지 않는다. 웹 루트 밖(config/)의 파일만 허용하고, Linux에서는 권한 0600으로 만든다.
 *
 * 사용법:
 *   php tools/make_secret_key.php [--out=config/secrets.ini]            키가 없을 때만 encryption_key를 쓴다(있으면 거부 — 덮어쓰면 저장된 자격 증명을 풀 수 없다)
 *   php tools/make_secret_key.php --rotate [--out=…]                    새 키를 encryption_key로, 지금 키를 encryption_key_previous로 옮긴다
 *                                                                      (이후 관리자가 연결을 저장할 때마다 새 키로 다시 암호화된다. docs/repo_integration.md 3절)
 * 환경 변수 PJG_ENCRYPTION_KEY를 쓰는 서버는 이 파일 대신 그 값을 관리한다(이 도구는 파일만 고친다).
 * 종료 코드: 0 성공, 1 이미 있음(--rotate 없이)·잘못된 위치, 2 사용법 오류
 */
require dirname(__DIR__) . '/src/bootstrap.php';

$opt = getopt('', ['out:', 'rotate', 'help']);
if (isset($opt['help'])) {
    fwrite(STDOUT, "사용법: php tools/make_secret_key.php [--out=config/secrets.ini] [--rotate]\n");
    exit(0);
}
$out = (string) ($opt['out'] ?? PJG_ROOT . '/config/secrets.ini');
$abs = str_replace('\\', '/', $out);
if (!preg_match('#^([A-Za-z]:)?/#', $abs)) {
    $abs = str_replace('\\', '/', (string) getcwd()) . '/' . $abs;
}
$public = str_replace('\\', '/', (string) realpath(PJG_ROOT . '/public'));
$dir = realpath(dirname($abs));
if ($dir === false) {
    fwrite(STDERR, "시크릿 파일의 폴더가 없습니다.\n");
    exit(2);
}
$dir = str_replace('\\', '/', $dir);
if ($public !== '' && ($dir === $public || str_starts_with($dir . '/', $public . '/'))) {
    fwrite(STDERR, "웹 루트(public/) 안에는 키를 둘 수 없습니다.\n");
    exit(1);
}
$file = $dir . '/' . basename($abs);
$raw = is_file($file) ? (string) file_get_contents($file) : "; PJGuardian 시크릿(config/secrets.example 참고). 커밋하지 마세요.\n";
$current = preg_match('/^\s*encryption_key\s*=\s*"?([A-Za-z0-9+\/=]{40,})"?\s*$/m', $raw, $m) === 1 ? $m[1] : null;
if ($current !== null && !isset($opt['rotate'])) {
    fwrite(STDERR, "이미 encryption_key가 있습니다. 바꾸려면 --rotate(지금 키는 encryption_key_previous로 옮김). 덮어쓰면 저장된 자격 증명을 풀 수 없습니다.\n");
    exit(1);
}
$key = base64_encode(random_bytes(32));
$set = static function (string $text, string $name, string $value): string {
    $line = $name . ' = "' . $value . '"';
    $n = 0;
    $text = (string) preg_replace('/^\s*' . preg_quote($name, '/') . '\s*=.*$/m', $line, $text, 1, $n);
    return $n > 0 ? $text : rtrim($text, "\n") . "\n" . $line . "\n";
};
$raw = $set($raw, 'encryption_key', $key);
if ($current !== null) {
    $raw = $set($raw, 'encryption_key_previous', $current);
}
$tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
$old = umask(0077);
file_put_contents($tmp, $raw);
umask($old);
@chmod($tmp, 0600);
if (!rename($tmp, $file)) {
    @unlink($tmp);
    fwrite(STDERR, "시크릿 파일을 쓰지 못했습니다.\n");
    exit(1);
}
@chmod($file, 0600);
$id = PJGuardian\Services\Repo\SecretBox::keyId(base64_decode($key));
fwrite(STDOUT, ($current === null ? "암호화 키를 만들었습니다" : "암호화 키를 바꿨습니다(이전 키는 encryption_key_previous)") . ": {$file} (키 식별자 {$id}, 키 값은 출력하지 않음)\n");
fwrite(STDOUT, "이 파일은 DB 백업과 따로 보관하세요. 키를 잃으면 저장된 자격 증명은 복구할 수 없습니다.\n");
exit(0);
