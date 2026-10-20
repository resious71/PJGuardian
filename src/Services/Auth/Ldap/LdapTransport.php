<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/**
 * ext-ldap 호출을 감싼 얇은 층(P28B) — PhpLdapClient의 장애 넘김·TLS·시간 상한 규칙을 실제 서버 없이 시험하려고 분리했다.
 * 연결 핸들은 불투명한 값(mixed). 오류 번호는 LDAP 결과 코드(-1 서버 없음, 49 자격 증명 오류 등).
 */
interface LdapTransport
{
    public function available(): bool;

    /**
     * 연결 준비(아직 네트워크 연결은 하지 않을 수 있음) + 옵션: 프로토콜 3, 레퍼럴 끔, 네트워크 시간 상한, 검색 시간 상한,
     * TLS 인증서 검증 필수(DEMAND, 호스트 이름 포함), CA 파일. 실패하면 null
     */
    public function open(string $url, string $caFile, int $connectTimeout, int $searchTimeout): mixed;

    public function startTls(mixed $link): bool;

    /** 바인드. 성공 true, 실패 false(errno로 이유) */
    public function bind(mixed $link, string $dn, string $password): bool;

    public function errno(mixed $link): int;

    /**
     * 검색(하위 트리). 결과 [{dn, attrs: {이름(소문자) => list<string 원시 값>}}], 최대 $sizeLimit건. 실패 null
     *
     * @param list<string> $attributes
     * @return list<array{dn:string, attrs:array<string, list<string>>}>|null
     */
    public function search(mixed $link, string $baseDn, string $filter, array $attributes, int $sizeLimit, int $timeLimit, bool $baseOnly = false): ?array;

    public function close(mixed $link): void;
}
