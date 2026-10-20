<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * IP 주소가 목록(IP 또는 CIDR, IPv4·IPv6)에 드는지 — 헬스체크 허용 IP(P30)와 신뢰 프록시(P32B)가 같이 쓴다. 빈 목록은 아무것도 들지 않음.
 */
final class IpList
{
    /** @param list<string> $list */
    public static function contains(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false || $list === []) {
            return false;
        }
        foreach ($list as $entry) {
            [$net, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $len = $bits === null ? strlen($bin) * 8 : (int) $bits;
            if ($len < 0 || $len > strlen($bin) * 8) {
                continue;
            }
            $full = intdiv($len, 8);
            $rest = $len % 8;
            if (substr($bin, 0, $full) !== substr($netBin, 0, $full)) {
                continue;
            }
            if ($rest > 0) {
                $mask = (0xFF << (8 - $rest)) & 0xFF;
                if ((ord($bin[$full]) & $mask) !== (ord($netBin[$full]) & $mask)) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
