<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 점검용 HTTP GET(하드닝 점검, 명세 19.5). 상태 코드와 응답 헤더만 읽는다(본문은 읽지 않음).
 * 외부 명령 없이 소켓으로 요청한다. 리다이렉트는 따라가지 않는다.
 *
 * - $resolveIp: 호스트 이름 대신 이 IP로 접속(hosts에 개발용 이름이 없을 때). TLS SNI와 Host 헤더는 원래 이름.
 * - $verifyTls: 인증서 검증. 기본 true. 자체 서명 인증서를 쓰는 개발 PC에서만 false(--insecure)로 점검한다.
 */
final class HttpProbe
{
    /** @return array{status:int, headers:array<string,string>}|null 접속 실패 시 null */
    public static function get(string $baseUrl, string $path, ?string $resolveIp = null, bool $verifyTls = true, int $timeout = 5): ?array
    {
        $u = parse_url($baseUrl);
        if ($u === false || !isset($u['host'])) {
            return null;
        }
        $https = ($u['scheme'] ?? 'https') === 'https';
        $host = $u['host'];
        $port = $u['port'] ?? ($https ? 443 : 80);
        $prefix = rtrim($u['path'] ?? '', '/');
        $ctx = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
        ]]);
        $target = ($https ? 'ssl://' : 'tcp://') . ($resolveIp ?? $host) . ':' . $port;
        $fp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return null;
        }
        stream_set_timeout($fp, $timeout);
        fwrite($fp, 'GET ' . $prefix . $path . " HTTP/1.1\r\nHost: " . $host . ($port !== ($https ? 443 : 80) ? ':' . $port : '')
            . "\r\nUser-Agent: PJGuardian-HardeningCheck\r\nAccept: */*\r\nConnection: close\r\n\r\n");
        $head = '';
        while (!feof($fp) && !str_contains($head, "\r\n\r\n") && strlen($head) < 65536) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;
        }
        fclose($fp);
        $lines = explode("\r\n", explode("\r\n\r\n", $head, 2)[0]);
        if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', (string) array_shift($lines), $m) !== 1) {
            return null;
        }
        $headers = [];
        foreach ($lines as $line) {
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }
        return ['status' => (int) $m[1], 'headers' => $headers];
    }
}
