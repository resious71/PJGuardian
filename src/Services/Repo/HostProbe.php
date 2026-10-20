<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 허용 호스트 연결 테스트(P26B, 명세 18.4·19.3) — **저장소·자격 증명 없이 호스트 자체만** 본다. 이 단계의 새 네트워크 코드는 여기에만 있다.
 *
 * 단계
 *  1. 이름 풀이 + 허용 판정(HostPolicy::inspect — 풀린 주소 전부와 분류). 금지 주소로 하나라도 풀리면 **접속하지 않는다**(fail_blocked_address),
 *     풀리지 않으면 fail_dns. 이후 모든 접속은 판정한 첫 주소로 고정(DNS 재해석 없음). 등록된 호스트·포트만(호출한 쪽이 허용 목록의 행을 넘긴다).
 *  2. TCP 접속(포트 = 행의 포트, 0이면 https 443과 HTTP 허용이면 80). 거부·시간 초과를 구분.
 *  3. TLS(443, 그 밖의 포트는 시도): 인증서(만료일·남은 일수·발급자·호스트 이름 일치·지문). 신뢰는 따로 한 번 더 접속해 검증
 *     (repository.ca_cert_file이 있으면 그것만 — git의 http.sslCAInfo와 같은 규칙, 없으면 PHP 기본 CA). 만료 → 이름 불일치 → 신뢰 안 됨 순.
 *     80·443이 아닌 포트에서 TLS가 안 되면 평문 포트(Perforce 등)로 보고 TCP 성공만 보고한다.
 *  4. HTTP(S) 호스트(포트 0·80·443, HTTP 허용·GitHub 호환 표시)는 인증 없이 `HEAD /`(405·501이면 `GET /`)의 **상태 줄만** 읽고 닫는다
 *     (본문을 읽지 않음, 리다이렉트를 따라가지 않음, 쿠키·자격 증명 없음). 401·403은 "응답함(인증 필요)", 그 밖의 4xx(407 제외)는
 *     "응답함(루트에 페이지 없음)"으로 정상(P27C, note = auth|no_page). 5xx와 407(프록시 인증)은 fail_http_status.
 *  5. Perforce로 쓰는 포트(HTTP가 아닌 TLS 포트)는 서버 인증서 지문(공개 키 SHA-1 — p4 trust 형식(가정)·인증서 SHA-256)을 보여 준다. 자동 신뢰하지 않는다.
 *
 * 결과 코드: ok | warn_cert_expiring | fail_dns | fail_blocked_address | fail_connect | fail_tls_untrusted | fail_tls_expired |
 *           fail_tls_name_mismatch | fail_http_status. 상세(오류 문장)는 서버 로그에만, 화면에는 코드와 단계별 결과.
 * 시간 상한: TCP·TLS·HTTP 단계마다(repository.host_test_tcp_timeout 5초·tls 5초·http 10초).
 */
final class HostProbe
{
    public const CODES = ['ok', 'warn_cert_expiring', 'fail_dns', 'fail_blocked_address', 'fail_connect', 'fail_tls_untrusted', 'fail_tls_expired',
        'fail_tls_name_mismatch', 'fail_http_status'];

    public function __construct(
        private readonly HostPolicy $policy,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly ?string $caFile,
        // 인증서 만료 경고 시작(초, notification.cert_expiry_warn 30일)
        private readonly int $warnSeconds,
        private readonly float $tcpTimeout = 5.0,
        private readonly float $tlsTimeout = 5.0,
        private readonly float $httpTimeout = 10.0,
    ) {
    }

    /**
     * @param array{host:string, port:int, allow_http:bool, github_links:bool} $row 허용 목록의 행
     * @return array{code:string, ms:int, dns:array<string,mixed>, targets:list<array<string,mixed>>, cert:?array<string,mixed>}
     */
    public function probe(array $row): array
    {
        $t0 = hrtime(true);
        $host = (string) $row['host'];
        $out = ['code' => 'ok', 'ms' => 0, 'dns' => [], 'targets' => [], 'cert' => null];
        $dns = $this->policy->inspect($host);
        $out['dns'] = ['ok' => $dns['allowed'], 'ips' => $dns['ips'], 'reason' => $dns['reason'], 'pinned' => $dns['pinned']];
        if (!$dns['allowed']) {
            $out['code'] = $dns['reason'] === 'dns' ? 'fail_dns' : 'fail_blocked_address';
            return $this->finish($out, $t0, $host);
        }
        $ip = (string) $dns['pinned'];
        $httpLike = in_array((int) $row['port'], [0, 80, 443], true) || $row['allow_http'] || $row['github_links'];
        $codes = [];
        foreach (self::targets($row) as [$port, $scheme]) {
            $tg = $this->target($host, $ip, $port, $scheme, $httpLike);
            $out['targets'][] = $tg;
            $codes[] = $tg['code'];
            if ($out['cert'] === null && isset($tg['tls']['cert'])) {
                $out['cert'] = $tg['tls']['cert'];
            }
        }
        // 대상 중 가장 나쁜 결과(실패 > 경고 > 정상), 실패끼리는 단계 순서
        foreach (['fail_connect', 'fail_tls_expired', 'fail_tls_name_mismatch', 'fail_tls_untrusted', 'fail_http_status', 'warn_cert_expiring'] as $c) {
            if (in_array($c, $codes, true)) {
                $out['code'] = $c;
                break;
            }
        }
        return $this->finish($out, $t0, $host);
    }

    /** @return list<array{0:int, 1:string}> [포트, https|http|tls?] */
    public static function targets(array $row): array
    {
        $port = (int) $row['port'];
        if ($port === 0) {
            return array_values(array_filter([[443, 'https'], $row['allow_http'] ? [80, 'http'] : null]));
        }
        return [[$port, $port === 80 ? 'http' : ($port === 443 ? 'https' : 'tls?')]];
    }

    /** @return array<string, mixed> */
    private function target(string $host, string $ip, int $port, string $scheme, bool $httpLike): array
    {
        $tg = ['port' => $port, 'scheme' => $scheme, 'code' => 'ok', 'tcp' => null, 'tls' => null, 'http' => null, 'plaintext' => false];
        $addr = (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ':' . $port;
        // 2) TCP
        $t = hrtime(true);
        $s = @stream_socket_client('tcp://' . $addr, $errno, $errstr, $this->tcpTimeout);
        $tg['tcp'] = ['ok' => $s !== false, 'ms' => self::ms($t)];
        if ($s === false) {
            $tg['tcp']['error'] = self::isTimeout($errno, (string) $errstr) ? 'timeout' : 'refused';
            $tg['code'] = 'fail_connect';
            $this->logger->info('호스트 연결 테스트: TCP 실패', ['host' => $host, 'port' => $port, 'errno' => $errno, 'error' => $errstr]);
            return $tg;
        }
        fclose($s);
        if ($scheme === 'http') {
            $tg['http'] = $this->http($host, 'tcp://' . $addr, null);
            $tg['code'] = $tg['http']['ok'] ? 'ok' : ($tg['http']['status'] === null ? 'fail_connect' : 'fail_http_status');
            return $tg;
        }
        // 3) TLS(검증 없이 인증서를 받는다 — 판정은 아래에서 직접)
        $t = hrtime(true);
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'allow_self_signed' => true, 'capture_peer_cert' => true]]);
        $s = @stream_socket_client('ssl://' . $addr, $errno, $errstr, $this->tlsTimeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            $tg['tls'] = ['ok' => false, 'ms' => self::ms($t), 'error' => self::isTimeout($errno, (string) $errstr) || self::ms($t) >= (int) ($this->tlsTimeout * 1000) - 50 ? 'timeout' : 'handshake'];
            $this->logger->info('호스트 연결 테스트: TLS 실패', ['host' => $host, 'port' => $port, 'errno' => $errno, 'error' => $errstr]);
            if ($scheme === 'tls?' && ($tg['tls']['error'] === 'handshake' || $httpLike)) {
                // TLS가 아님(핸드셰이크 실패), 또는 HTTP 허용·GitHub 호환 행에서 TLS 응답이 없음 → 평문으로 다시 본다
                return $this->plain($tg, $host, $addr, $httpLike, $tg['tls']['error'] === 'timeout');
            }
            $tg['code'] = 'fail_connect';
            return $tg;
        }
        $params = stream_context_get_params($s);
        fclose($s);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null && $scheme === 'tls?') {
            // 인증서 없이 끝난 연결(평문 HTTP 서버가 TLS 요청에 응답한 경우 등) = TLS가 아님 → 평문 포트로 본다
            return $this->plain($tg, $host, $addr, $httpLike);
        }
        $info = $cert === null ? null : $this->certInfo($cert, $host);
        $trusted = $this->trusted($host, $addr);
        $tg['tls'] = ['ok' => true, 'ms' => self::ms($t), 'trusted' => $trusted, 'cert' => $info];
        if ($info === null) {
            $tg['code'] = 'fail_tls_untrusted';
            return $tg;
        }
        if ($info['expired']) {
            $tg['code'] = 'fail_tls_expired';
        } elseif (!$info['name_match']) {
            $tg['code'] = 'fail_tls_name_mismatch';
        } elseif (!$trusted) {
            $tg['code'] = 'fail_tls_untrusted';
        }
        // 4) HTTP(S) 상태 줄(TLS가 나빠도 호스트가 응답하는지는 보여 준다 — 검증 없는 연결, 자격 증명 없음)
        if ($scheme === 'https' || ($scheme === 'tls?' && $httpLike)) {
            $tg['http'] = $this->http($host, 'ssl://' . $addr, $ctx);
            if ($tg['code'] === 'ok' && !$tg['http']['ok']) {
                $tg['code'] = $tg['http']['status'] === null ? 'fail_connect' : 'fail_http_status';
            }
        } else {
            $tg['p4'] = true; // HTTP가 아닌 TLS 포트(Perforce SSL 등): 지문을 보여 준다
        }
        if ($tg['code'] === 'ok' && $info['expiring']) {
            $tg['code'] = 'warn_cert_expiring';
        }
        return $tg;
    }

    /**
     * 80·443이 아닌 포트에서 TLS가 아닌 경우: 평문 포트로 본다. HTTP 허용·GitHub 호환 행이면 평문 HTTP 상태 줄도 확인하고(사내 HTTP Git),
     * 아니면 TCP 성공만(Perforce 평문 포트 등).
     *
     * @param array<string, mixed> $tg
     * @return array<string, mixed>
     */
    private function plain(array $tg, string $host, string $addr, bool $httpLike, bool $tlsTimedOut = false): array
    {
        $tls = $tg['tls'];
        $tg['plaintext'] = true;
        $tg['tls'] = null;
        if ($httpLike) {
            $tg['http'] = $this->http($host, 'tcp://' . $addr, null);
            if ($tg['http']['status'] !== null) {
                $tg['code'] = $tg['http']['ok'] ? 'ok' : 'fail_http_status';
                return $tg;
            }
            $tg['http'] = null; // HTTP 응답도 없음 — 평문 비HTTP 서비스이거나 응답하지 않는 서버
        }
        if ($tlsTimedOut) {
            // TLS도 HTTP도 답하지 않음: TCP만 열리고 응답이 없는 서버 → 접속 실패(시간 초과)
            $tg['plaintext'] = false;
            $tg['tls'] = $tls;
            $tg['code'] = 'fail_connect';
        }
        return $tg;
    }

    /** 신뢰 검증(이름은 위에서 따로 본다). repository.ca_cert_file이 있으면 그 파일만 */
    private function trusted(string $host, string $addr): bool
    {
        $ssl = ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => false];
        if ($this->caFile !== null && $this->caFile !== '') {
            $ssl['cafile'] = $this->caFile;
        }
        $s = @stream_socket_client('ssl://' . $addr, $errno, $errstr, $this->tlsTimeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        if ($s === false) {
            return false;
        }
        fclose($s);
        return true;
    }

    /**
     * 상태 줄만 읽는다(최대 1KB). HEAD가 405·501이면 GET으로 한 번 더(역시 상태 줄만). 리다이렉트는 따라가지 않는다.
     *
     * @param resource|null $ctx
     * @return array{ok:bool, status:?int, method:string, ms:int, redirect:bool, note:?string}
     */
    private function http(string $host, string $url, $ctx): array
    {
        $t = hrtime(true);
        $status = null;
        $method = 'HEAD';
        foreach (['HEAD', 'GET'] as $method) {
            $status = $this->statusLine($host, $url, $ctx, $method);
            if (!in_array($status, [405, 501], true)) {
                break;
            }
        }
        return ['ok' => self::reached($status), 'status' => $status, 'method' => $method, 'ms' => self::ms($t),
            'redirect' => $status !== null && $status >= 300 && $status < 400, 'note' => self::httpNote($status)];
    }

    /**
     * P27C(사용자 결정 2026-10-08): 상태 줄이 왔으면 호스트에 도달한 것이다 — 2xx·3xx·**407을 뺀 모든 4xx**는 정상(ok).
     * 5xx(서버 오류)와 407(프록시 인증 필요 — 중간 프록시의 신호)은 fail_http_status. 응답 없음(null)은 호출자가 fail_connect로.
     */
    public static function reached(?int $status): bool
    {
        return $status !== null && $status < 500 && $status !== 407;
    }

    /** 화면 안내 구분(코드는 그대로): auth(401·403) | no_page(그 밖의 4xx — 루트에 페이지 없음) | proxy(407) | null */
    public static function httpNote(?int $status): ?string
    {
        if ($status === 401 || $status === 403) {
            return 'auth';
        }
        if ($status === 407) {
            return 'proxy';
        }
        return $status !== null && $status >= 400 && $status < 500 ? 'no_page' : null;
    }

    /** @param resource|null $ctx */
    private function statusLine(string $host, string $url, $ctx, string $method): ?int
    {
        $s = $ctx === null ? @stream_socket_client($url, $errno, $errstr, $this->httpTimeout) : @stream_socket_client($url, $errno, $errstr, $this->httpTimeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            return null;
        }
        $deadline = microtime(true) + $this->httpTimeout;
        stream_set_timeout($s, (int) ceil($this->httpTimeout));
        $hostHeader = str_contains($host, ':') ? "[{$host}]" : $host;
        @fwrite($s, "{$method} / HTTP/1.1\r\nHost: {$hostHeader}\r\nUser-Agent: PJGuardian-host-test\r\nAccept: */*\r\nConnection: close\r\n\r\n");
        $line = @fgets($s, 1024);
        fclose($s); // 본문은 읽지 않고 버린다
        if (!is_string($line) || microtime(true) > $deadline + 1 || preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $line, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /**
     * @param \OpenSSLCertificate|string $cert
     * @return array{not_after:string, days_left:int, issuer:string, subject:string, names:list<string>, name_match:bool, expired:bool, expiring:bool,
     *   fingerprint_sha256:string, key_sha1:string, key_sha256:string}|null
     */
    private function certInfo(mixed $cert, string $host): ?array
    {
        $p = @openssl_x509_parse($cert);
        if (!is_array($p) || !isset($p['validTo_time_t'])) {
            return null;
        }
        $now = $this->clock->now()->getTimestamp();
        $notAfter = (int) $p['validTo_time_t'];
        $names = [];
        foreach (explode(',', (string) ($p['extensions']['subjectAltName'] ?? '')) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $names[] = strtolower(substr($part, 4));
            } elseif (str_starts_with($part, 'IP Address:')) {
                $names[] = strtolower(substr($part, 11));
            }
        }
        if ($names === [] && isset($p['subject']['CN'])) {
            $names[] = strtolower((string) (is_array($p['subject']['CN']) ? end($p['subject']['CN']) : $p['subject']['CN']));
        }
        $issuer = $p['issuer']['CN'] ?? $p['issuer']['O'] ?? '';
        $issuer = is_array($issuer) ? (string) end($issuer) : (string) $issuer;
        $subject = $p['subject']['CN'] ?? '';
        $subject = is_array($subject) ? (string) end($subject) : (string) $subject;
        $keyDer = '';
        $pub = @openssl_pkey_get_public($cert);
        if ($pub !== false) {
            $pem = (string) (openssl_pkey_get_details($pub)['key'] ?? '');
            $keyDer = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        }
        return [
            'not_after' => SystemClock::iso((new \DateTimeImmutable('@' . $notAfter))),
            'days_left' => intdiv($notAfter - $now, 86400),
            'issuer' => mb_substr($issuer, 0, 200),
            'subject' => mb_substr($subject, 0, 200),
            'names' => array_slice($names, 0, 20),
            'name_match' => self::nameMatches($host, $names),
            'expired' => $notAfter < $now || (int) ($p['validFrom_time_t'] ?? 0) > $now,
            'expiring' => $notAfter >= $now && $notAfter - $now < $this->warnSeconds,
            'fingerprint_sha256' => self::colon((string) openssl_x509_fingerprint($cert, 'sha256')),
            'key_sha1' => $keyDer === '' ? '' : self::colon(sha1($keyDer)),
            'key_sha256' => $keyDer === '' ? '' : self::colon(hash('sha256', $keyDer)),
        ];
    }

    /** 인증서 이름(SAN, 없으면 CN)과 호스트 비교. 와일드카드는 맨 왼쪽 한 단계만(*.example.com ↔ a.example.com) */
    public static function nameMatches(string $host, array $names): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($names as $n) {
            $n = strtolower(rtrim((string) $n, '.'));
            if ($n === $host) {
                return true;
            }
            if (str_starts_with($n, '*.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $dot = strpos($host, '.');
                if ($dot !== false && substr($host, $dot + 1) === substr($n, 2)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** 16진 → "AB:CD:…"(Perforce·브라우저가 보여 주는 형식) */
    public static function colon(string $hex): string
    {
        return implode(':', str_split(strtoupper($hex), 2));
    }

    private static function isTimeout(int $errno, string $err): bool
    {
        return in_array($errno, [110, 10060], true) || stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false;
    }

    private static function ms(int $t0): int
    {
        return (int) ((hrtime(true) - $t0) / 1e6);
    }

    /** @param array<string, mixed> $out */
    private function finish(array $out, int $t0, string $host): array
    {
        $out['ms'] = self::ms($t0);
        $this->logger->info('호스트 연결 테스트', ['host' => $host, 'code' => $out['code'], 'ms' => $out['ms']]);
        return $out;
    }
}
