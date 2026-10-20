<?php

declare(strict_types=1);

namespace PJGuardian\Services\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PJGuardian\Support\ConfigSchema;

/**
 * SMTP 발송(P30B) — **검증된 라이브러리 PHPMailer**(vendor/, docs/vendor_build.md 3절)가 메시지를 만들고(PHPMailer::preSend) 프로토콜을 처리한다(SMTP 클래스).
 * SMTP를 직접 구현하지 않는다. 단계(연결 → EHLO → STARTTLS → 인증 → MAIL·RCPT·DATA)를 라이브러리 메서드로 하나씩 불러 실패 사유를 나눈다.
 *
 * - TLS: none(평문 — localhost·사설망 중계용) | starttls | smtps(ssl://). **인증서 검증은 늘 켜짐**(verify_peer·verify_peer_name, 자체 서명 거부) —
 *   끄는 옵션은 없다. 사내 CA는 PHP/OS 신뢰 저장소에 넣는다(가이드). $trust는 시험용 신뢰 CA 파일·확인할 이름만(검증을 끄지 않음, 설정에 없음).
 * - 평문(none)에서는 PHPMailer의 자동 STARTTLS도 쓰지 않는다(설정한 그대로).
 * - 시간: 연결 connect_timeout(10초), 응답마다·전체 send_timeout(30초 — 단계 사이에 남은 시간 검사).
 * - 헤더 주입 방지: 제목·주소·보낸 사람 이름에 개행·제어 문자가 있으면 보내기 전에 거부(invalid). 받는 사람 상한 20.
 * - 실패 사유: connect·tls·auth·rejected·timeout·invalid·config(MailException). 서버 응답 원문은 밖으로 내지 않는다.
 */
final class SmtpMailSender implements MailSender
{
    /**
     * @param array{cafile?:string, peer_name?:string} $trust 시험용(신뢰 CA·확인할 이름) — 검증은 그대로 켠다
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $tls,
        private readonly string $auth,
        private readonly string $user,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly int $connectTimeout = 10,
        private readonly int $sendTimeout = 30,
        private readonly array $trust = [],
    ) {
    }

    public function send(array $to, string $subject, string $body): void
    {
        if ($this->host === '' || $this->from === '' || ($this->auth === 'login' && ($this->user === '' || $this->password === ''))) {
            throw new MailException('config');
        }
        self::assertLine($subject, 300);
        self::assertLine($this->fromName, 100);
        if ($to === [] || count($to) > ConfigSchema::ALERT_MAIL_MAX_RECIPIENTS) {
            throw new MailException('invalid');
        }
        foreach ([...$to, $this->from] as $addr) {
            if (!self::validAddress($addr)) {
                throw new MailException('invalid');
            }
        }
        $raw = $this->build($to, $subject, $body);
        $this->transmit($to, $raw);
    }

    public static function validAddress(string $addr): bool
    {
        return preg_match('/[\x00-\x1f\x7f]/', $addr) !== 1 && strlen($addr) <= 254 && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false
            && PHPMailer::validateAddress($addr);
    }

    private static function assertLine(string $s, int $max): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $s) === 1 || mb_strlen($s) > $max) {
            throw new MailException('invalid');
        }
    }

    /** 메시지(머리·본문)를 라이브러리로 만든다 — 보내지는 않음 */
    private function build(array $to, string $subject, string $body): string
    {
        $m = new PHPMailer(true);
        $m->isSMTP(); // SMTP용 머리(To·Subject 포함)
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_BASE64;
        $m->XMailer = ' '; // 프로그램 이름·버전 머리를 넣지 않음
        try {
            $m->setFrom($this->from, $this->fromName, false);
            foreach ($to as $addr) {
                $m->addAddress($addr);
            }
            $m->Subject = $subject;
            $m->isHTML(false);
            $m->Body = $body;
            $m->preSend();
        } catch (\PHPMailer\PHPMailer\Exception) {
            throw new MailException('invalid');
        }
        return $m->getSentMIMEMessage();
    }

    /** @param list<string> $to */
    private function transmit(array $to, string $raw): void
    {
        $smtp = new class () extends SMTP {
            /** @var list<string> 연결·TLS 단계의 PHP 경고 문구(사유 판별용 — 밖으로 내지 않음) */
            public array $warnings = [];

            protected function errorHandler($errno, $errmsg, $errfile = '', $errline = 0)
            {
                $this->warnings[] = (string) $errmsg;
                parent::errorHandler($errno, $errmsg, $errfile, $errline);
            }
        };
        $smtp->do_debug = SMTP::DEBUG_OFF;
        $smtp->Timeout = $this->connectTimeout;
        $smtp->Timelimit = $this->sendTimeout;
        $start = microtime(true);
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'SNI_enabled' => true, 'disable_compression' => true]
            + array_intersect_key($this->trust, ['cafile' => true, 'peer_name' => true]);
        $host = ($this->tls === 'smtps' ? 'ssl://' : 'tcp://') . $this->host;
        $left = function () use ($start): void {
            if (microtime(true) - $start >= $this->sendTimeout) {
                throw new MailException('timeout');
            }
        };
        $fail = function (string $reason, bool $connect = false) use ($smtp, $start): never {
            $elapsed = microtime(true) - $start;
            $warn = strtolower(implode(' ', $smtp->warnings));
            if ($elapsed >= ($connect ? $this->connectTimeout : $this->sendTimeout) - 0.5 || str_contains($warn, 'timed out')) {
                throw new MailException('timeout');
            }
            if ($connect && $this->tls === 'smtps' && preg_match('/ssl|tls|certificate|crypto|handshake/', $warn) === 1) {
                throw new MailException('tls');
            }
            throw new MailException($reason);
        };
        try {
            if (!$smtp->connect($host, $this->port, $this->connectTimeout, ['ssl' => $ssl])) {
                $fail('connect', true);
            }
            $helo = self::heloName();
            $left();
            if (!$smtp->hello($helo)) {
                $fail('rejected');
            }
            if ($this->tls === 'starttls') {
                $left();
                if (!$smtp->startTLS()) {
                    $fail('tls');
                }
                if (!$smtp->hello($helo)) {
                    $fail('rejected');
                }
            }
            if ($this->auth === 'login') {
                $left();
                if (!$smtp->authenticate($this->user, $this->password, 'LOGIN')) {
                    $fail('auth');
                }
            }
            $left();
            if (!$smtp->mail($this->from)) {
                $fail('rejected');
            }
            foreach ($to as $addr) {
                if (!$smtp->recipient($addr)) {
                    $fail('rejected');
                }
            }
            $left();
            if (!$smtp->data($raw)) {
                $fail('rejected');
            }
            $smtp->quit();
        } finally {
            $smtp->close();
        }
    }

    /** EHLO 이름: 서버 이름이 쓸 만하면 그것, 아니면 localhost.localdomain(PHPMailer 기본과 같은 규칙) */
    private static function heloName(): string
    {
        $h = (string) gethostname();
        return preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $h) === 1 ? $h : 'localhost.localdomain';
    }
}
