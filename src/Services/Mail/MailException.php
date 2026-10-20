<?php

declare(strict_types=1);

namespace PJGuardian\Services\Mail;

/**
 * 메일 발송 실패(P30B). reason은 화면·감사 로그·상태 표에 쓰는 사유 코드 하나뿐(서버 응답 원문·주소·비밀 없음):
 *   connect(연결 실패) · tls(TLS 실패 — 인증서 검증 포함) · auth(인증 실패) · rejected(수신 거부 — 보낸 사람·받는 사람·본문)
 *   · timeout(시간 초과) · config(설정이 갖춰지지 않음) · invalid(주소·제목 형식 — 헤더 주입 등)
 */
final class MailException extends \RuntimeException
{
    public const REASONS = ['connect', 'tls', 'auth', 'rejected', 'timeout', 'config', 'invalid'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('mail_' . $reason);
    }
}
