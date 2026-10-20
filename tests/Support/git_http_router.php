<?php

declare(strict_types=1);

/**
 * 시험용 로컬 Git HTTP 서버(P25): `PJG_GIT_HTTP_CONFIG=<설정.json> php -S 127.0.0.1:<포트> git_http_router.php`. 설정:
 *   {root: 저장소 폴더, backend: git-http-backend 실행 파일, auth: "사용자:토큰"|null, log: 요청 기록 파일}
 * - auth가 있으면 Authorization: Basic 이 맞아야 한다(아니면 401). 요청마다 경로·쿼리·Authorization 유무를 log에 한 줄로 남긴다
 *   (토큰이 URL에 없음·헤더로만 왔음을 시험이 확인).
 * - /redirect/… 는 302로 다른 호스트를 가리킨다(리다이렉트를 따라가지 않는지 시험).
 * - 그 밖은 git-http-backend(CGI, 스마트 HTTP)로 넘긴다. 외부 네트워크를 쓰지 않는다.
 */
$cfg = json_decode((string) file_get_contents((string) getenv('PJG_GIT_HTTP_CONFIG')), true); // 서버를 띄울 때 환경 변수로 설정 파일 경로
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = (string) parse_url($uri, PHP_URL_PATH);
$query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($cfg['log'], json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'uri' => $uri, 'auth' => $authHeader]) . "\n", FILE_APPEND);

if (str_starts_with($path, '/redirect/')) {
    header('Location: http://169.254.169.254' . substr($path, 9), true, 302);
    return;
}
if (($cfg['auth'] ?? null) !== null && $authHeader !== 'Basic ' . base64_encode((string) $cfg['auth'])) {
    header('WWW-Authenticate: Basic realm="git"', true, 401);
    echo 'auth required';
    return;
}
if (($cfg['slow'] ?? 0) > 0) {
    sleep((int) $cfg['slow']);
}
$env = [
    'GIT_PROJECT_ROOT' => $cfg['root'], 'GIT_HTTP_EXPORT_ALL' => '1', 'PATH_INFO' => $path, 'QUERY_STRING' => $query,
    'REQUEST_METHOD' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'CONTENT_TYPE' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'REMOTE_USER' => 'tester', 'REMOTE_ADDR' => '127.0.0.1', 'SystemRoot' => (string) (getenv('SystemRoot') ?: 'C:\\Windows'),
    'PATH' => (string) getenv('PATH'), 'GIT_CONFIG_NOSYSTEM' => '1', 'HOME' => sys_get_temp_dir(),
    'HTTP_GIT_PROTOCOL' => (string) ($_SERVER['HTTP_GIT_PROTOCOL'] ?? ''),
];
$body = (string) file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $body = (string) gzdecode($body);
}
$env['CONTENT_LENGTH'] = (string) strlen($body);
$p = proc_open([$cfg['backend']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cfg['root'], $env, ['bypass_shell' => true]);
fwrite($pipes[0], $body);
fclose($pipes[0]);
$out = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($p);
$split = strpos($out, "\r\n\r\n");
$sep = 4;
if ($split === false) {
    $split = (int) strpos($out, "\n\n");
    $sep = 2;
}
foreach (preg_split('/\r?\n/', substr($out, 0, $split)) ?: [] as $h) {
    if (preg_match('/^Status:\s*(\d+)/i', $h, $m) === 1) {
        http_response_code((int) $m[1]);
    } elseif ($h !== '') {
        header($h);
    }
}
echo substr($out, $split + $sep);
