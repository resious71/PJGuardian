<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

/**
 * OIDC 외부 요청 한 번(P28C). 실제 구현은 StreamHttpTransport(소켓 + TLS 검증), 시험은 프로세스 안의 가짜 IdP — 실제 IdP에 연결하지 않는다.
 * 호출자(OidcHttp)가 주소 판정·고정(pinnedIp)·리다이렉트·https 검사를 맡고, 전송은 정해진 주소로 한 번 보내고 응답을 읽기만 한다.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:string} 헤더 이름은 소문자
     * @throws OidcException unavailable | timeout | too_large | tls
     */
    public function send(string $method, string $url, string $pinnedIp, array $headers, string $body, int $maxBytes, int $connectTimeout, int $totalTimeout): array;
}
