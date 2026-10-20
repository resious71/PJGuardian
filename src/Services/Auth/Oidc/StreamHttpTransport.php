<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

/**
 * 실제 HTTPS 전송(P28C): 판정한 주소(pinnedIp)로 접속하고 TLS는 원래 호스트 이름으로 검증한다(SNI·peer_name, 인증서 검증을 끄는 옵션 없음).
 * HTTP/1.1 + Connection: close, Content-Length·chunked 본문, 연결 시간·전체 시간·응답 크기 상한. 외부 명령·확장(curl) 없이 소켓만.
 */
final class StreamHttpTransport implements HttpTransport
{
    /** @param string|null $caFile 사내 CA(PEM). null이면 운영체제·PHP 기본 신뢰 목록 */
    public function __construct(private readonly ?string $caFile = null)
    {
    }

    public function send(string $method, string $url, string $pinnedIp, array $headers, string $body, int $maxBytes, int $connectTimeout, int $totalTimeout): array
    {
        $u = parse_url($url);
        if ($u === false || ($u['scheme'] ?? '') !== 'https' || !isset($u['host'])) {
            throw new OidcException('not_https');
        }
        $host = (string) $u['host'];
        $port = (int) ($u['port'] ?? 443);
        $target = $u['path'] ?? '/';
        if (isset($u['query'])) {
            $target .= '?' . $u['query'];
        }
        $ssl = ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
        if ($this->caFile !== null && $this->caFile !== '') {
            $ssl['cafile'] = $this->caFile;
        }
        $deadline = microtime(true) + $totalTimeout;
        $addr = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;
        $fp = @stream_socket_client('ssl://' . $addr . ':' . $port, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        if ($fp === false) {
            throw new OidcException(microtime(true) >= $deadline - $totalTimeout + $connectTimeout ? 'timeout' : (stripos((string) $errstr, 'certificate') !== false ? 'tls' : 'unavailable'));
        }
        try {
            stream_set_timeout($fp, max(1, (int) ceil($deadline - microtime(true))));
            $hostHeader = $host . ($port !== 443 ? ':' . $port : '');
            $req = $method . ' ' . $target . " HTTP/1.1\r\nHost: " . $hostHeader . "\r\nUser-Agent: PJGuardian-OIDC\r\nConnection: close\r\n";
            foreach ($headers as $k => $v) {
                $req .= $k . ': ' . $v . "\r\n";
            }
            if ($method === 'POST') {
                $req .= 'Content-Length: ' . strlen($body) . "\r\n";
            }
            if (@fwrite($fp, $req . "\r\n" . $body) === false) {
                throw new OidcException('unavailable');
            }
            $raw = '';
            while (!feof($fp)) {
                if (microtime(true) > $deadline) {
                    throw new OidcException('timeout');
                }
                $chunk = fread($fp, 8192);
                if ($chunk === false) {
                    break;
                }
                if ($chunk === '' && (stream_get_meta_data($fp)['timed_out'] ?? false)) {
                    throw new OidcException('timeout');
                }
                $raw .= $chunk;
                if (strlen($raw) > $maxBytes + 16384) { // 헤더 여유 16 KiB
                    throw new OidcException('too_large');
                }
            }
        } finally {
            fclose($fp);
        }
        return self::parse($raw, $maxBytes);
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    public static function parse(string $raw, int $maxBytes): array
    {
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            throw new OidcException('unavailable');
        }
        $lines = explode("\r\n", substr($raw, 0, $split));
        if (preg_match('#^HTTP/1\.[01] (\d{3})#', (string) array_shift($lines), $m) !== 1) {
            throw new OidcException('unavailable');
        }
        $headers = [];
        foreach ($lines as $line) {
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }
        $body = substr($raw, $split + 4);
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            $out = '';
            $pos = 0;
            while (true) {
                $eol = strpos($body, "\r\n", $pos);
                if ($eol === false) {
                    throw new OidcException('unavailable');
                }
                $size = hexdec(trim(explode(';', substr($body, $pos, $eol - $pos))[0]));
                if (!is_int($size) || $size < 0) {
                    throw new OidcException('unavailable');
                }
                if ($size === 0) {
                    break;
                }
                $out .= substr($body, $eol + 2, $size);
                if (strlen($out) > $maxBytes) {
                    throw new OidcException('too_large');
                }
                $pos = $eol + 2 + $size + 2;
            }
            $body = $out;
        } elseif (isset($headers['content-length']) && ctype_digit($headers['content-length'])) {
            $body = substr($body, 0, (int) $headers['content-length']);
        }
        if (strlen($body) > $maxBytes) {
            throw new OidcException('too_large');
        }
        return ['status' => (int) $m[1], 'headers' => $headers, 'body' => $body];
    }
}
