<?php

declare(strict_types=1);

namespace PJGuardian\Http;

use PJGuardian\Support\Lang;

/**
 * API 오류. 던지면 ErrorMiddleware가 공통 오류 형식으로 바꾼다.
 *
 * - code: snake_case 오류 코드(화면·가이드가 이 코드로 분기)
 * - 메시지는 문자열 리소스 키(기본 "error.<code>")로 찾는다. 코드에 한글 문장을 쓰지 않는다.
 * - logContext는 로그에만 남기고 응답에는 넣지 않는다.
 * - details는 응답의 error.details로 나간다(API 경로만). 화면이 다시 시도하는 데 필요한 공개 값만 넣는다
 *   (예: 낙관적 잠금 충돌의 최신 결함 값, P18). 내부 경로·예외 메시지를 넣지 않는다.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param array<string, string|int|float> $params 메시지 자리표시자
     * @param array<string, mixed> $logContext
     * @param array<string, string> $headers 응답에 붙일 헤더(예: Allow)
     * @param array<string, mixed>|null $details 응답 error.details(공개 값만)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $params = [],
        public readonly ?string $messageKey = null,
        public readonly array $logContext = [],
        public readonly array $headers = [],
        public readonly ?array $details = null,
    ) {
        parent::__construct($errorCode);
    }

    public function userMessage(): string
    {
        return Lang::get($this->messageKey ?? 'error.' . $this->errorCode, $this->params);
    }
}
