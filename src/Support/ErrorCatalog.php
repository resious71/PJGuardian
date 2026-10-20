<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 업로드 오류·분석 실패 코드 카탈로그(명세 15.2, P19). 코드마다 제목·원인·사용자가 할 수 있는 조치·가이드 링크 키를 가진다.
 * 화면(업로드·분석 상세)과 API(error / failure 객체)가 이것만 쓴다. 목록과 문구는 docs/error_codes.md(ErrorCatalogTest가 일치 검사).
 *
 * - 문구: failure.<종류>.<코드>.title|cause|action (src/Lang/ko.php). 서버 내부 정보(경로, 스택, 예외 메시지)는 넣지 않는다.
 * - 가이드 링크 키(P29): 사용자 가이드 절의 key — 업로드 upload-errors, 분석 analysis-failures, SSO 로그인 login-errors. 화면의 "자세히 보기"는
 *   /guide/user#<key>(절)와 /guide/errors#<코드>(오류 코드 탭의 그 줄)로 간다. GuideCatalogTest가 모든 키가 ko·en 절로 풀리는지 검사.
 *   (P19~P28C에는 문구 키 모양 upload.error.<코드>·analysis.failure.<코드>였다 — 가이드 절이 생기면서 절 key로 바꿈)
 * - SSO(P29): 로그인 화면 오류 코드 E-SSO-*(P28C·P28D)도 같은 형식으로 — 오류 코드 탭이 함께 보여 준다.
 * - 모르는 코드는 unknown 문구 + 코드(관리자 문의), 가이드 키 null.
 * - 분석 행에 저장하는 한 문장 문구(analyses.failure_message)는 그대로 analysis.failure.<코드>(P14)를 쓴다(로그·이력용).
 */
final class ErrorCatalog
{
    public const UPLOAD = [
        'invalid_zip', 'zip_too_large', 'extract_limit', 'forbidden_entry', 'filename_encoding', 'unsupported_name', 'name_conflict', 'io_error',
        'assemble_failed', 'extract_timeout', 'extract_crashed',
    ];

    public const ANALYSIS = [
        'timeout', 'output_limit', 'memory_limit', 'tool_crash', 'tool_config_error', 'tool_unavailable', 'tool_failed', 'insufficient_storage',
        'result_save_failed', 'worker_crashed', 'worker_stopped', 'analysis_crashed', 'analysis_start_failed',
        // P25 소스 저장소 가져오기
        'repo_auth_failed', 'repo_unreachable', 'repo_not_found', 'repo_timeout', 'repo_limit_exceeded', 'repo_host_not_allowed', 'repo_tls_untrusted', 'repo_tool_unavailable', 'repo_empty', 'repo_not_configured', 'repo_secret_unreadable', 'repo_fetch_failed', 'repo_unsafe_content',
        // P19C 프로젝트 결함 수 상한
        'defect_limit_exceeded',
    ];

    /** 로그인 화면 SSO 오류 코드(P28C·P28D — 사유는 감사 로그에만) */
    public const SSO = ['E-SSO-STATE', 'E-SSO-IDP', 'E-SSO-TOKEN', 'E-SSO-CONFIG', 'E-SSO-UNAVAILABLE', 'E-SSO-RATE', 'E-SSO-DENIED'];

    /** 종류 → 사용자 가이드 절 key */
    public const GUIDE_SECTION = ['upload' => 'upload-errors', 'analysis' => 'analysis-failures', 'sso' => 'login-errors'];

    /**
     * @param 'upload'|'analysis'|'sso' $kind
     * @return array{code:string, known:bool, title:string, cause:string, action:string, guide_key:?string}
     */
    public static function describe(string $kind, string $code): array
    {
        $list = self::codes($kind);
        if (!in_array($code, $list, true)) {
            return [
                'code' => $code, 'known' => false,
                'title' => Lang::get("failure.{$kind}.unknown.title"),
                'cause' => Lang::get("failure.{$kind}.unknown.cause", ['code' => $code]),
                'action' => Lang::get("failure.{$kind}.unknown.action"),
                'guide_key' => null,
            ];
        }
        return [
            'code' => $code, 'known' => true,
            'title' => Lang::get("failure.{$kind}.{$code}.title"),
            'cause' => Lang::get("failure.{$kind}.{$code}.cause"),
            'action' => Lang::get("failure.{$kind}.{$code}.action"),
            'guide_key' => self::GUIDE_SECTION[$kind],
        ];
    }

    /** @return list<string> */
    public static function codes(string $kind): array
    {
        return match ($kind) {
            'upload' => self::UPLOAD,
            'analysis' => self::ANALYSIS,
            'sso' => self::SSO,
            default => throw new \InvalidArgumentException($kind),
        };
    }

    /**
     * 가이드 오류 코드 탭(P29): 종류별 전체 목록. 사용자에게 보이는 문구만(서버 내부 사유·경로 없음 — 관리자 전용 사유는 감사 로그에만).
     *
     * @return list<array{kind:string, title:string, guide_key:string, items:list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::GUIDE_SECTION as $kind => $section) {
            $out[] = ['kind' => $kind, 'title' => Lang::get("guide.errors.group.{$kind}"), 'guide_key' => $section,
                'items' => array_map(static fn (string $c): array => self::describe($kind, $c), self::codes($kind))];
        }
        return $out;
    }
}
