<?php

declare(strict_types=1);

/**
 * 시험용 로컬 서버(P26B HostProbeTest): 호스트 연결 테스트의 각 결과를 만들기 위한 최소 서버. 127.0.0.1의 빈 포트에서 듣고 포트 번호를 파일에 쓴다.
 * 사용: php probe_server.php <설정 JSON 파일>
 *   {"mode": "https"|"http"|"hang"|"plain"|"tls-silent", "pem": 인증서+키 PEM 파일, "status": 200, "head_status": (HEAD에만 다른 코드),
 *    "location": (3xx일 때), "body": 바이트 수(응답 본문 크기 — 클라이언트가 읽지 않아야 함), "port_file": 포트를 쓸 파일, "log": 요청 기록 파일}
 *   https/http: 요청 줄과 Authorization·Cookie 헤더 유무를 기록하고 상태 줄 + 큰 본문을 보낸다.
 *   hang: TCP 연결을 받고 아무것도 하지 않는다(TLS 핸드셰이크 시간 초과).
 *   plain: TLS가 아닌 평문 서버(Perforce 평문 포트 흉내) — 연결을 받으면 짧은 글을 쓰고 닫는다.
 *   tls-silent: TLS 핸드셰이크 후 아무것도 보내지 않는다(Perforce SSL 포트 흉내).
 */
$cfg = json_decode((string) file_get_contents($argv[1]), true);
$mode = (string) $cfg['mode'];
$tls = in_array($mode, ['https', 'tls-silent'], true);
$ctx = stream_context_create($tls ? ['ssl' => ['local_cert' => $cfg['pem'], 'verify_peer' => false, 'allow_self_signed' => true]] : []);
$server = stream_socket_server(($tls ? 'ssl' : 'tcp') . '://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents($cfg['port_file'], substr($name, strrpos($name, ':') + 1));
$log = static function (array $r) use ($cfg): void {
    file_put_contents($cfg['log'], json_encode($r) . "\n", FILE_APPEND);
};
$held = [];
while (true) {
    $c = @stream_socket_accept($server, 60);
    if ($c === false) {
        continue; // TCP만 열었다 닫은 연결(핸드셰이크 실패) 등
    }
    if ($mode === 'hang' || $mode === 'tls-silent') {
        $held[] = $c; // 닫지 않고 붙잡아 둔다
        $log(['mode' => $mode, 'accepted' => true]);
        continue;
    }
    if ($mode === 'plain') {
        @fwrite($c, "PERFORCE-PLAIN\n");
        fclose($c);
        $log(['mode' => 'plain', 'accepted' => true]);
        continue;
    }
    stream_set_timeout($c, 5);
    $first = (string) fgets($c, 2048);
    $headers = '';
    while (($l = fgets($c, 2048)) !== false && trim($l) !== '') {
        $headers .= $l;
    }
    $method = strtok($first, ' ');
    $log(['request' => trim($first), 'auth' => stripos($headers, 'authorization:') !== false, 'cookie' => stripos($headers, 'cookie:') !== false]);
    $status = $method === 'HEAD' && isset($cfg['head_status']) ? (int) $cfg['head_status'] : (int) ($cfg['status'] ?? 200);
    $body = (int) ($cfg['body'] ?? 0);
    $out = "HTTP/1.1 {$status} X\r\nContent-Length: {$body}\r\nConnection: close\r\n" . (isset($cfg['location']) ? "Location: {$cfg['location']}\r\n" : '') . "\r\n";
    @fwrite($c, $out);
    $sent = 0;
    if ($method !== 'HEAD') {
        $chunk = str_repeat('B', 65536);
        while ($sent < $body) {
            $n = @fwrite($c, substr($chunk, 0, min(65536, $body - $sent)));
            if ($n === false || $n === 0) {
                break;
            }
            $sent += $n;
        }
    }
    fclose($c);
}
