<?php

declare(strict_types=1);

namespace PJGuardian\Services\Mail;

/**
 * 메일 보내기(P30B — 관리자 장애 알림·시험 메일만, 사용자 대상 메일은 없다). 구현: SmtpMailSender(PHPMailer), 시험은 가짜 구현.
 * 실패는 MailException(사유 코드)로만 알린다 — 서버 응답 원문·비밀은 밖으로 내지 않는다.
 */
interface MailSender
{
    /**
     * @param list<string> $to 받는 사람(검증된 주소, 최대 ConfigSchema::ALERT_MAIL_MAX_RECIPIENTS)
     * @param string $subject 한 줄(개행 거부)
     * @param string $body 일반 텍스트
     * @throws MailException
     */
    public function send(array $to, string $subject, string $body): void;
}
