<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;

/**
 * 접속 허용 판정(P25, 명세 18.4 SSRF 방지). 저장소 접속은 **허용 호스트 목록(repo_allowed_hosts)에 있는 호스트만**.
 *
 * 1) 호스트 정규화: 소문자, 후행 점 제거, IPv6 대괄호 제거. 비표준 숫자 표기(0x7f.1, 2130706433, 0177.0.0.1, 127.1)는 거부.
 * 2) 목록 대조: (호스트, 포트) 또는 (호스트, 모든 포트=0). HTTP(평문)는 allow_http=1인 항목만.
 * 3) 이름 풀이 후 주소 판정 — **풀린 주소 중 하나라도** 아래면 거부(DNS가 사설·메타데이터 주소로 풀리는 공격):
 *    - 항상 거부: 0.0.0.0/8·::(미지정), 169.254/16·fe80::/10(링크 로컬, 클라우드 메타데이터 169.254.169.254 포함), 멀티캐스트·브로드캐스트,
 *      100.100.100.200(일부 클라우드 메타데이터), fd00:ec2::254
 *    - loopback(127/8·::1): 개발용 예외(repository.allow_local_dev)가 켜져 있을 때만(목록 등록도 필요), 켜면 WARN 로그
 *    - 사설(10/8·172.16/12·192.168/16·100.64/10·fc00::/7)·공인: 목록에 있으면 허용(사내 GitHub Enterprise·Perforce는 보통 사설 주소)
 * 4) 판정한 주소를 돌려준다 — 호출자가 그 주소로 고정해 접속한다(Git `http.curloptResolve`, Perforce는 P4PORT에 주소) →
 *    판정과 실제 접속 사이에 DNS가 바뀌는 경우(리바인딩)를 막는다. 리다이렉트는 따라가지 않는다(Git `http.followRedirects=false`).
 */
final class HostPolicy
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /** @param (\Closure(string): list<string>)|null $resolve 시험용 이름 풀이 */
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly bool $allowLocalDev,
        ?\Closure $resolve = null,
    ) {
        $this->resolve = $resolve ?? self::systemResolver(...);
    }

    /** 정규화한 호스트, 형식이 틀리면 null */
    public static function normalizeHost(string $h): ?string
    {
        $h = strtolower(trim($h));
        if (str_starts_with($h, '[') && str_ends_with($h, ']')) {
            $h = substr($h, 1, -1);
        }
        $h = rtrim($h, '.');
        if ($h === '' || strlen($h) > 253) {
            return null;
        }
        if (str_contains($h, ':')) {
            return filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? (string) inet_ntop((string) inet_pton($h)) : null;
        }
        if (preg_match('/^[0-9.]+$/', $h) === 1 || preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/', $h) === 1) {
            // 숫자로만 된 호스트는 표준 4점 표기 IPv4만(0x7f.1, 2130706433, 0177.0.0.1, 127.1 거부)
            if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || preg_match('/(^|\.)0[0-9]/', $h) === 1) {
                return null;
            }
            return $h;
        }
        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $h) !== 1) {
            return null;
        }
        return $h;
    }

    /** 목록 항목(있으면). @return array<string, mixed>|null */
    public function entry(string $host, int $port): ?array
    {
        return $this->db->one('SELECT * FROM repo_allowed_hosts WHERE host = ? AND port IN (?, 0) ORDER BY port DESC LIMIT 1', [$host, $port]);
    }

    /** 커밋 링크를 만들어도 되는 호스트인지(github.com은 자동) */
    public function githubLinks(string $host, int $port): bool
    {
        if ($host === 'github.com') {
            return true;
        }
        $e = $this->entry($host, $port);
        return $e !== null && (int) $e['github_links'] === 1;
    }

    /**
     * 접속 판정. 허용이면 고정할 주소, 아니면 RepoException(repo_host_not_allowed | repo_unreachable).
     *
     * @param 'https'|'http'|'p4' $scheme
     */
    public function check(string $host, int $port, string $scheme): string
    {
        $host = self::normalizeHost($host) ?? throw new RepoException('repo_host_not_allowed', ['host' => '?']);
        $e = $this->entry($host, $port);
        if ($e === null) {
            throw new RepoException('repo_host_not_allowed', ['host' => $host]);
        }
        if ($scheme === 'http' && (int) $e['allow_http'] !== 1) {
            throw new RepoException('repo_host_not_allowed', ['host' => $host, 'reason' => 'http']);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            throw new RepoException('repo_unreachable', ['host' => $host]);
        }
        foreach ($ips as $ip) {
            $class = self::classify($ip);
            if ($class === 'blocked' || ($class === 'loopback' && !$this->allowLocalDev)) {
                $this->logger->warn('허용 호스트가 접속 금지 주소로 풀림(접속하지 않음)', ['host' => $host, 'class' => $class]);
                throw new RepoException('repo_host_not_allowed', ['host' => $host, 'reason' => 'address']);
            }
            if ($class === 'loopback') {
                $this->logger->warn('개발용 예외로 loopback 저장소에 접속(repository.allow_local_dev = true, 운영에서는 끄세요)', ['host' => $host]);
            }
        }
        return $ips[0];
    }

    /**
     * 호스트 연결 테스트(P26B)용: 이름 풀이 결과와 주소마다의 분류, 접속해도 되는지(check와 같은 규칙). 접속하지 않는다.
     *
     * @return array{ips: list<array{ip: string, class: string}>, allowed: bool, reason: ?string, pinned: ?string}
     *   reason: dns(풀리지 않음) | address(금지 주소로 풀림 — 하나라도) | null
     */
    public function inspect(string $host): array
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        $list = array_map(static fn (string $ip): array => ['ip' => $ip, 'class' => self::classify($ip)], $ips);
        if ($list === []) {
            return ['ips' => [], 'allowed' => false, 'reason' => 'dns', 'pinned' => null];
        }
        foreach ($list as $a) {
            if ($a['class'] === 'blocked' || ($a['class'] === 'loopback' && !$this->allowLocalDev)) {
                $this->logger->warn('호스트 연결 테스트: 접속 금지 주소로 풀림(접속하지 않음)', ['host' => $host, 'class' => $a['class']]);
                return ['ips' => $list, 'allowed' => false, 'reason' => 'address', 'pinned' => null];
            }
        }
        return ['ips' => $list, 'allowed' => true, 'reason' => null, 'pinned' => $list[0]['ip']];
    }

    /** @return 'blocked'|'loopback'|'private'|'public' */
    public static function classify(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return 'blocked';
        }
        if (strlen($bin) === 16) {
            // IPv4 대응 주소(::ffff:a.b.c.d)·IPv4 호환(::a.b.c.d)은 안쪽 IPv4로 판정
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || (str_starts_with($bin, str_repeat("\0", 12)) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) {
                return self::classify((string) inet_ntop(substr($bin, 12)));
            }
            // P31: NAT64(64:ff9b::/96)·6to4(2002::/16)도 안에 IPv4를 담는다 — 안쪽 IPv4로 판정(169.254.169.254 등 우회 방지)
            if (str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
                return self::classify((string) inet_ntop(substr($bin, 12)));
            }
            if (str_starts_with($bin, "\x20\x02")) {
                return self::classify((string) inet_ntop(substr($bin, 2, 4)));
            }
            $b0 = ord($bin[0]);
            $b1 = ord($bin[1]);
            if ($bin === str_repeat("\0", 16)) {
                return 'blocked';                                   // ::
            }
            if ($bin === str_repeat("\0", 15) . "\1") {
                return 'loopback';                                  // ::1
            }
            if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80 || $b0 === 0xff) {
                return 'blocked';                                   // fe80::/10 링크 로컬, ff00::/8 멀티캐스트
            }
            if ((string) inet_ntop($bin) === 'fd00:ec2::254') {
                return 'blocked';                                   // 클라우드 메타데이터(IPv6)
            }
            if (($b0 & 0xfe) === 0xfc) {
                return 'private';                                   // fc00::/7
            }
            return 'public';
        }
        [$a, $b, $c, $d] = array_map('ord', str_split($bin));
        return match (true) {
            $a === 0, $a === 169 && $b === 254, $a >= 224, $a === 100 && $b === 100 && $c === 100 && $d === 200 => 'blocked',
            $a === 127 => 'loopback',
            $a === 10, $a === 172 && $b >= 16 && $b <= 31, $a === 192 && $b === 168, $a === 100 && $b >= 64 && $b <= 127 => 'private',
            default => 'public',
        };
    }

    /** @return list<string> */
    private static function systemResolver(string $host): array
    {
        $out = [];
        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $out[] = $ip;
        }
        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (isset($r['ipv6'])) {
                    $out[] = (string) $r['ipv6'];
                }
            }
        }
        return array_values(array_unique($out));
    }
}
