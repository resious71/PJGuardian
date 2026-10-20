<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/**
 * PHP ldap 확장을 쓰는 실제 전송(P28B). TLS 인증서 검증은 항상 켠다(LDAP_OPT_X_TLS_DEMAND — 서버 인증서와 호스트 이름 검증,
 * 끄는 설정은 없다). 레퍼럴 따라가기 끔, 프로토콜 3. 경고는 @로 누르고 오류 번호만 쓴다(서버 메시지·DN을 로그에 남기지 않음).
 */
final class ExtLdapTransport implements LdapTransport
{
    public function available(): bool
    {
        return extension_loaded('ldap');
    }

    public function open(string $url, string $caFile, int $connectTimeout, int $searchTimeout): mixed
    {
        if (!$this->available()) {
            return null;
        }
        // TLS 옵션은 연결 전에(전역 + 연결별). 새 TLS 문맥으로 적용(LDAP_OPT_X_TLS_NEWCTX)
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
        if ($caFile !== '') {
            @ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $caFile);
        }
        $link = @ldap_connect($url);
        if ($link === false) {
            return null;
        }
        @ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, $connectTimeout);
        @ldap_set_option($link, LDAP_OPT_TIMELIMIT, $searchTimeout);
        @ldap_set_option($link, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
        if ($caFile !== '') {
            @ldap_set_option($link, LDAP_OPT_X_TLS_CACERTFILE, $caFile);
        }
        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            @ldap_set_option($link, LDAP_OPT_X_TLS_NEWCTX, 0);
        }
        return $link;
    }

    public function startTls(mixed $link): bool
    {
        return @ldap_start_tls($link);
    }

    public function bind(mixed $link, string $dn, string $password): bool
    {
        return @ldap_bind($link, $dn, $password);
    }

    public function errno(mixed $link): int
    {
        return (int) @ldap_errno($link);
    }

    public function search(mixed $link, string $baseDn, string $filter, array $attributes, int $sizeLimit, int $timeLimit, bool $baseOnly = false): ?array
    {
        $res = $baseOnly
            ? @ldap_read($link, $baseDn, $filter, $attributes, 0, $sizeLimit, $timeLimit)
            : @ldap_search($link, $baseDn, $filter, $attributes, 0, $sizeLimit, $timeLimit);
        if ($res === false) {
            // 크기 한도 초과(4)는 결과가 더 있다는 뜻 — 2건 이상으로 본다
            if ($this->errno($link) !== 4) {
                return null;
            }
        }
        $out = [];
        if ($res !== false) {
            for ($e = @ldap_first_entry($link, $res); $e !== false; $e = @ldap_next_entry($link, $e)) {
                $attrs = [];
                foreach ($attributes as $a) {
                    $vals = @ldap_get_values_len($link, $e, $a);
                    if (is_array($vals)) {
                        unset($vals['count']);
                        $attrs[strtolower($a)] = array_map('strval', array_values($vals));
                    }
                }
                $out[] = ['dn' => (string) @ldap_get_dn($link, $e), 'attrs' => $attrs];
            }
        } else {
            $out = [['dn' => '', 'attrs' => []], ['dn' => '', 'attrs' => []]];
        }
        return $out;
    }

    public function close(mixed $link): void
    {
        @ldap_unbind($link);
    }
}
