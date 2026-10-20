<?php

declare(strict_types=1);

/**
 * 시험용 가짜 SMTP 수신기(P30B) — 127.0.0.1에서만 받고 외부로 연결하지 않는다. 제품 코드가 아니며 메일을 전달하지 않는다(받은 것을 파일에 적기만).
 *   php tests/Support/fake_smtp_server.php --port=N --mode=plain|starttls|smtps --out=<jsonl> [--cert=<pem> --key=<pem>] [--user=u --pass=p]
 *        [--reject-rcpt=<주소>] [--silent] [--ready=<파일>]
 * --silent: 연결은 받되 인사(220)를 보내지 않음(시간 초과 시험). --ready: 듣기 시작하면 이 파일을 만든다(시험이 기다림).
 * 받은 메시지는 --out에 한 줄 JSON {from, rcpt[], data, tls, auth}로 덧붙인다.
 */
$opt = getopt('', ['port:', 'mode:', 'out:', 'cert:', 'key:', 'user:', 'pass:', 'reject-rcpt:', 'silent', 'ready:']);
$port = (int) ($opt['port'] ?? 0);
$mode = (string) ($opt['mode'] ?? 'plain');
$out = (string) ($opt['out'] ?? '');
$ctx = stream_context_create(['ssl' => [
    'local_cert' => $opt['cert'] ?? '', 'local_pk' => $opt['key'] ?? '', 'verify_peer' => false, 'allow_self_signed' => true,
]]);
$server = stream_socket_server(($mode === 'smtps' ? 'tls' : 'tcp') . "://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
if (isset($opt['ready'])) {
    file_put_contents((string) $opt['ready'], 'ready');
}
while (true) {
    $c = @stream_socket_accept($server, 3600);
    if ($c === false) {
        continue; // smtps 핸드셰이크 실패(클라이언트가 인증서를 거부) 등
    }
    stream_set_timeout($c, 30);
    if (isset($opt['silent'])) {
        sleep(60);
        fclose($c);
        continue;
    }
    $w = static function (string $line) use ($c): void {
        fwrite($c, $line . "\r\n");
    };
    $w('220 fake.smtp.test ESMTP');
    $tls = $mode === 'smtps';
    $auth = false;
    $from = '';
    $rcpt = [];
    while (($line = fgets($c)) !== false) {
        $cmd = rtrim($line, "\r\n");
        $verb = strtoupper(strtok($cmd, ' ') ?: '');
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $caps = ['250-fake.smtp.test'];
            if ($mode === 'starttls' && !$tls) {
                $caps[] = '250-STARTTLS';
            }
            if (isset($opt['user']) && ($tls || $mode === 'plain')) {
                $caps[] = '250-AUTH LOGIN';
            }
            $caps[] = '250 8BITMIME';
            foreach ($caps as $cap) {
                $w($cap);
            }
        } elseif ($verb === 'STARTTLS') {
            $w('220 Ready to start TLS');
            if (@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) !== true) {
                break; // 클라이언트가 인증서를 거부
            }
            $tls = true;
        } elseif ($verb === 'AUTH') {
            $w('334 VXNlcm5hbWU6');
            $u = base64_decode(rtrim((string) fgets($c), "\r\n"));
            $w('334 UGFzc3dvcmQ6');
            $p = base64_decode(rtrim((string) fgets($c), "\r\n"));
            if ($u === ($opt['user'] ?? null) && $p === ($opt['pass'] ?? null)) {
                $auth = true;
                $w('235 2.7.0 Authentication successful');
            } else {
                $w('535 5.7.8 Authentication credentials invalid');
            }
        } elseif ($verb === 'MAIL') {
            if (isset($opt['user']) && !$auth) {
                $w('530 5.7.0 Authentication required');
                continue;
            }
            $from = (string) preg_replace('/^MAIL FROM:\s*<?([^>]*)>?.*$/i', '$1', $cmd);
            $rcpt = [];
            $w('250 OK');
        } elseif ($verb === 'RCPT') {
            $to = (string) preg_replace('/^RCPT TO:\s*<?([^>]*)>?.*$/i', '$1', $cmd);
            if (isset($opt['reject-rcpt']) && strcasecmp($to, (string) $opt['reject-rcpt']) === 0) {
                $w('550 5.1.1 User unknown');
                continue;
            }
            $rcpt[] = $to;
            $w('250 OK');
        } elseif ($verb === 'DATA') {
            $w('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($d = fgets($c)) !== false && $d !== ".\r\n") {
                $data .= str_starts_with($d, '..') ? substr($d, 1) : $d;
            }
            if ($out !== '') {
                file_put_contents($out, json_encode(['from' => $from, 'rcpt' => $rcpt, 'data' => $data, 'tls' => $tls, 'auth' => $auth]) . "\n", FILE_APPEND | LOCK_EX);
            }
            $w('250 OK queued');
        } elseif ($verb === 'RSET' || $verb === 'NOOP') {
            $w('250 OK');
        } elseif ($verb === 'QUIT') {
            $w('221 Bye');
            break;
        } else {
            $w('502 Command not implemented');
        }
    }
    @fclose($c);
}
