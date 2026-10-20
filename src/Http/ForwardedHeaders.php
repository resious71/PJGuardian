<?php

declare(strict_types=1);

namespace PJGuardian\Http;

use PJGuardian\Support\IpList;

/**
 * 신뢰 프록시(P32B, 명세 19.10 P10 — 설정 [security] trusted_proxies). **접속 주소·https 판정은 이 함수 한 곳**에서 정한다:
 * App::handle이 맨 앞에서 한 번 부르고, 그 뒤의 로그인 시도 제한·감사 로그 행위자 IP·/health 허용 IP·HTTPS 강제·HSTS·쿠키 Secure는
 * 모두 결과 Request의 clientIp·secure만 본다.
 *
 * - 목록이 비어 있으면(기본) 아무것도 바꾸지 않는다 = REMOTE_ADDR·서버의 HTTPS 그대로(P32B 전과 같음).
 * - 직접 접속 주소(REMOTE_ADDR)가 목록에 있을 때만 X-Forwarded-For·X-Forwarded-Proto를 믿는다. 목록 밖에서 온 헤더는 무시(위조 방지).
 * - X-Forwarded-For는 오른쪽(가까운 쪽)부터 보며 목록에 있는 프록시를 건너뛰고, 처음 만나는 목록 밖 주소를 클라이언트로 본다
 *   (왼쪽 값은 클라이언트가 마음대로 넣을 수 있으므로 쓰지 않는다). 형식이 틀린 값을 만나면 거기서 멈추고 직접 접속 주소를 쓴다.
 * - X-Forwarded-Proto는 마지막 값(가까운 프록시가 붙인 것)이 https면 secure, http면 secure 아님. 없거나 다른 값이면 서버 판정 그대로.
 */
final class ForwardedHeaders
{
    /** @param list<string> $trusted IP 또는 CIDR */
    public static function resolve(Request $request, array $trusted): Request
    {
        if ($trusted === [] || !IpList::contains($request->clientIp, $trusted)) {
            return $request;
        }
        $client = $request->clientIp;
        $xff = $request->header('x-forwarded-for');
        if ($xff !== null && trim($xff) !== '') {
            foreach (array_reverse(array_map('trim', explode(',', $xff))) as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                if (IpList::contains($hop, $trusted)) {
                    continue;
                }
                $client = $hop;
                break;
            }
        }
        $secure = $request->secure;
        $proto = $request->header('x-forwarded-proto');
        if ($proto !== null && trim($proto) !== '') {
            $parts = array_map(static fn (string $p): string => strtolower(trim($p)), explode(',', $proto));
            $last = (string) end($parts);
            if ($last === 'https' || $last === 'http') {
                $secure = $last === 'https';
            }
        }
        return $client === $request->clientIp && $secure === $request->secure ? $request : $request->withConnection($client, $secure);
    }
}
