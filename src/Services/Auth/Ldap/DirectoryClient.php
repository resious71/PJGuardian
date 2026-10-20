<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/**
 * 디렉터리 클라이언트(P28B). 실제 구현은 PhpLdapClient(ext-ldap), 시험은 가짜 구현.
 * 한 요청 안에서 search() 다음 bind()를 부른다(같은 연결을 이어 쓸 수 있다).
 */
interface DirectoryClient
{
    /**
     * 검색 계정으로 바인드한 뒤 로그인 ID로 사용자 한 명을 찾는다(필터 값은 LdapFilter::build로 이스케이프).
     * 0건이면 null, 2건 이상이면 DirectoryException(ambiguous), 연결 실패는 unavailable.
     */
    public function search(string $loginId): ?DirectoryEntry;

    /** 찾은 DN과 입력 비밀번호로 바인드. 맞으면 true, 틀리면 false, 서버 문제는 DirectoryException. 빈 비밀번호는 호출 전에 거부한다 */
    public function bind(string $dn, string $password): bool;

    /**
     * 연결 시험(관리자): 단계별 [{step: connect|service_bind|base_dn|user_search, ok: bool, code: ?string, detail: array}].
     * 앞 단계가 실패하면 뒤 단계는 skipped. user_search는 $loginId가 있을 때만(비밀번호는 묻지 않음). detail에는 가린 값만.
     *
     * @return list<array{step:string, ok:?bool, code:?string, detail:array<string,mixed>}>
     */
    public function probe(?string $loginId): array;
}
