<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Services\Repo\HostPolicy;
use PJGuardian\Support\Logger;

/**
 * OIDC 외부 요청(P28C): 디스커버리·JWKS·토큰·UserInfo. 모든 요청은 다음을 지킨다.
 *  - **https만**(사용자 정보·조각 없는 주소), 응답 크기 1 MiB·연결 5초·전체 10초 상한(시험은 주입)
 *  - 주소 판정은 P25/P26B 규칙(HostPolicy::classify): 금지 주소(링크 로컬·메타데이터·멀티캐스트·0/8)는 항상 거부, 루프백은 개발용 예외
 *    (oidc.allow_local_dev)일 때만. 그리고 **발급자보다 안쪽 주소로 가지 않는다** — 발급자가 공인 주소면 엔드포인트·리다이렉트도 공인만,
 *    발급자가 사설(사내 Keycloak 등)이면 사설까지. 판정한 주소로 고정해 접속한다(DNS 리바인딩 방지).
 *  - 리다이렉트는 GET에서만 2번까지(매번 같은 검사), POST(토큰 교환)는 따라가지 않는다.
 */
final class OidcHttp
{
    public const MAX_BYTES = 1048576;
    public const MAX_REDIRECTS = 2;
    private const RANK = ['public' => 0, 'private' => 1, 'loopback' => 2];

    /** @var \Closure(string): list<string> */
    private \Closure $resolve;
    private ?int $zone = null;

    /** @param (\Closure(string): list<string>)|null $resolve 이름 풀이(시험용 주입) */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $issuer,
        private readonly bool $allowLocalDev,
        private readonly Logger $logger,
        ?\Closure $resolve = null,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $connectTimeout = 5,
        private readonly int $totalTimeout = 10,
    ) {
        $this->resolve = $resolve ?? static fn (string $h): array => array_values(array_unique(@gethostbynamel($h) ?: []));
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function get(string $url, array $headers = []): array
    {
        for ($hop = 0; ; $hop++) {
            $res = $this->transport->send('GET', $url, $this->pin($url), $headers, '', $this->maxBytes, $this->connectTimeout, $this->totalTimeout);
            if (!in_array($res['status'], [301, 302, 303, 307, 308], true)) {
                return $res;
            }
            if ($hop >= self::MAX_REDIRECTS || !isset($res['headers']['location'])) {
                throw new OidcException('redirect_limit');
            }
            $url = self::resolveLocation($url, $res['headers']['location']);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function post(string $url, array $headers, string $body): array
    {
        return $this->transport->send('POST', $url, $this->pin($url), $headers, $body, $this->maxBytes, $this->connectTimeout, $this->totalTimeout);
    }

    /** https 주소 검사(사용자 정보·조각·공백 없음, 1000자 이내) */
    public static function validUrl(string $url): bool
    {
        if (strlen($url) > 1000 || preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?(/[^\s\#]*)?$#', $url) !== 1) {
            return false;
        }
        return HostPolicy::normalizeHost((string) parse_url($url, PHP_URL_HOST)) !== null;
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $u = parse_url($base);
            return 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . $location;
        }
        return $location; // 절대 주소 — pin()이 https·주소를 다시 검사
    }

    /** 주소 판정 → 접속할 IP(판정에 쓴 바로 그 주소 — 다시 풀지 않는다) */
    private function pin(string $url): string
    {
        if (!self::validUrl($url)) {
            throw new OidcException(str_starts_with(strtolower($url), 'https://') ? 'bad_url' : 'not_https');
        }
        [$rank, $ip] = $this->inspect((string) HostPolicy::normalizeHost((string) parse_url($url, PHP_URL_HOST)));
        $this->zone ??= $this->inspect((string) HostPolicy::normalizeHost((string) parse_url($this->issuer, PHP_URL_HOST)))[0];
        if ($rank > $this->zone) {
            $this->logger->warn('OIDC: 발급자보다 안쪽 주소로 가는 요청을 막음', ['host' => (string) parse_url($url, PHP_URL_HOST)]);
            throw new OidcException('blocked_address');
        }
        return $ip;
    }

    /** @return array{0:int, 1:string} 호스트의 주소 등급(풀린 주소 중 가장 안쪽)과 접속할 주소. 금지 주소·풀리지 않음은 예외 */
    private function inspect(string $host): array
    {
        if ($host === '') {
            throw new OidcException('bad_url');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            throw new OidcException('unavailable');
        }
        $rank = 0;
        foreach ($ips as $ip) {
            $class = HostPolicy::classify($ip);
            if ($class === 'blocked' || ($class === 'loopback' && !$this->allowLocalDev)) {
                $this->logger->warn('OIDC: 접속 금지 주소로 풀림(접속하지 않음)', ['host' => $host, 'class' => $class]);
                throw new OidcException('blocked_address');
            }
            $rank = max($rank, self::RANK[$class]);
        }
        return [$rank, $ips[0]];
    }
}
