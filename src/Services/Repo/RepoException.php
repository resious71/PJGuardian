<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

/**
 * 저장소 연동 오류(P25). code는 화면·분석 실패에 그대로 쓰는 정제된 오류 코드(문구는 문자열 리소스), params는 보여 줘도 안전한 값
 * (예: 서버 지문, 허용 목록 안내용 호스트 이름). 원문 stderr·자격 증명은 넣지 않는다(서버 로그에만, 마스킹 후).
 */
final class RepoException extends \RuntimeException
{
    /** 분석 실패 코드(ErrorCatalog::ANALYSIS에 등록, 가이드 키 analysis.failure.<코드>) */
    public const FAILURE_CODES = [
        'repo_auth_failed', 'repo_unreachable', 'repo_not_found', 'repo_timeout', 'repo_limit_exceeded', 'repo_host_not_allowed',
        'repo_tls_untrusted', 'repo_tool_unavailable', 'repo_empty', 'repo_not_configured', 'repo_secret_unreadable', 'repo_fetch_failed',
        'repo_unsafe_content',
    ];

    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $errorCode, public readonly array $params = [], ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
    }
}
