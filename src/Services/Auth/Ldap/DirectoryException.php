<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/**
 * 디렉터리 오류(P28B). 메시지는 코드뿐 — DN·필터·비밀번호·서버 응답 원문을 담지 않는다.
 *   unavailable          모든 서버에 연결할 수 없음(TLS 실패 포함)
 *   service_bind_failed  검색 계정 바인드 실패(비밀번호·DN 오류) — 다음 서버로 넘어가지 않음
 *   ambiguous            로그인 ID로 2건 이상 찾음
 *   search_failed        검색 오류(Base DN 등)
 *   not_configured       설정 없음(서버·Base DN·검색 계정 비밀번호)
 *   secret_unreadable    검색 계정 비밀번호를 복호화할 수 없음(키 교체·분실)
 *   extension_missing    PHP ldap 확장 없음
 */
final class DirectoryException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('directory_' . $reason);
    }
}
