<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Mail\AlertMailer;
use PJGuardian\Services\Mail\MailException;
use PJGuardian\Services\Mail\MailSender;
use PJGuardian\Services\Mail\SmtpMailSender;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\FakeSmtp;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자 장애 이메일(P30B):
 * - SmtpMailSender: 로컬 가짜 수신기(tests/Support/fake_smtp_server.php, 127.0.0.1 — 외부 연결 없음)로 평문·STARTTLS·SMTPS·AUTH LOGIN,
 *   인증서 검증은 켠 그대로(시험 CA만 신뢰 목록으로 줌 — 자체 서명은 tls 실패), 인증 실패·수신 거부·시간 초과·연결 실패 사유 코드, 헤더 주입·받는 사람 20개 거부.
 * - 발송 규칙(AlertMailer::tick): 새로 → 바로, 이어짐 → 간격 안 억제·간격 뒤 "지속 중"(억제 횟수), 수준 오름 → 바로(낮은 쪽 복구 메일 없음), 풀림 → 복구,
 *   한 판정 = 한 통, 꺼짐·불완전·031 없음 → 아무것도 안 함, 보내기 실패 → 사유 기록·워커 계속·간격 뒤 재시도, 여러 프로세스 동시 판정 → 한 번만.
 * - 메일 글: [PJGuardian] 접두사·수준 단어, 경로·비밀·사용자 입력(프로젝트 이름) 없음, 언어 설정.
 * - API: admin만(403), SMTP 비밀번호는 응답·감사·로그·DB 평문 어디에도 없음, 시험 발송 횟수 제한·감사(받는 사람 가림)·설정 부족 409.
 * - 마이그레이션 031 적용·되돌리기(사본), 정리 작업.
 */
final class AlertMailTest extends TestCase
{
    private const PASSWORD = 'Sm7p-Secret-Value-9x';
    private const ON = [
        'enabled' => 'true', 'smtp_host' => 'mail.example.test', 'smtp_port' => '587', 'smtp_tls' => 'starttls', 'smtp_auth' => 'none',
        'from_address' => 'pjg@example.test', 'recipients' => 'ops@example.test, lead@example.test', 'language' => 'ko', 'resend_interval' => '1h',
    ];

    /** @var list<array{to:list<string>, subject:string, body:string}> */
    private array $sent = [];
    private ?\Throwable $failWith = null;

    /** @param array<string, string> $mail alert_mail 덮어쓰기 */
    private function app(array $mail = [], array $more = []): App
    {
        $dir = $this->tempDir('am');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'alert_mail' => $mail + self::ON] + $more,
            ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        $app->useMailSender(fn (): MailSender => new class ($this) implements MailSender {
            public function __construct(private readonly AlertMailTest $t)
            {
            }

            public function send(array $to, string $subject, string $body): void
            {
                $this->t->record($to, $subject, $body);
            }
        });
        return $app;
    }

    /** @param list<string> $to */
    public function record(array $to, string $subject, string $body): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    /** @param list<array{0:string, 1:string, 2?:array<string, mixed>}> $alerts */
    private static function snap(array $alerts, array $cards = []): array
    {
        return ['alerts' => array_map(static fn (array $a): array => ['code' => $a[0], 'level' => $a[1], 'params' => $a[2] ?? []], $alerts), 'cards' => $cards];
    }

    private static function row(App $app, string $key): ?array
    {
        return $app->db()->one('SELECT * FROM alert_mail_state WHERE event_key = ?', [$key]);
    }

    /** @return array<string, mixed> */
    private static function j(\PJGuardian\Http\Response $r): array
    {
        return (array) json_decode($r->body, true);
    }

    private function assertCount(int $n, array $a, string $m = ''): void
    {
        $this->assertSame($n, count($a), $m);
    }

    private function assertStringContainsString(string $needle, string $hay, string $m = ''): void
    {
        $this->assertTrue(str_contains($hay, $needle), ($m !== '' ? $m . ': ' : '') . "'{$needle}' 없음 — " . mb_substr($hay, 0, 400));
    }

    private function assertStringNotContainsString(string $needle, string $hay, string $m = ''): void
    {
        $this->assertFalse($needle !== '' && str_contains($hay, $needle), ($m !== '' ? $m . ': ' : '') . "'{$needle}'이(가) 들어 있음");
    }

    private function assertStringStartsWith(string $prefix, string $hay, string $m = ''): void
    {
        $this->assertTrue(str_starts_with($hay, $prefix), ($m !== '' ? $m . ': ' : '') . "'{$hay}'이(가) '{$prefix}'로 시작하지 않음");
    }

    // ---- 발송기(가짜 수신기) ----

    public function testSmtpSenderPlainStarttlsSmtpsAndAuth(): void
    {
        $dir = $this->tempDir('smtp');
        $cases = [
            ['plain', 'none', [], false, false],
            ['starttls', 'login', ['user' => 'mailer', 'pass' => self::PASSWORD], true, true],
            ['smtps', 'login', ['user' => 'mailer', 'pass' => self::PASSWORD], true, true],
        ];
        foreach ($cases as [$mode, $auth, $opt, $tls, $authed]) {
            $srv = new FakeSmtp($dir . '/' . $mode, $mode, $opt);
            try {
                $sender = new SmtpMailSender('127.0.0.1', $srv->port, $mode === 'plain' ? 'none' : $mode, $auth, $auth === 'login' ? 'mailer' : '',
                    $auth === 'login' ? self::PASSWORD : '', 'pjg@example.test', 'PJGuardian', 5, 10, $srv->trust());
                $sender->send(['ops@example.test', 'lead@example.test'], '[PJGuardian] 경고: 시험', "본문 한 줄\n");
                $msgs = $srv->messages();
                $this->assertCount(1, $msgs, $mode);
                $m = $msgs[0];
                $this->assertSame('pjg@example.test', $m['from'], $mode);
                $this->assertSame(['ops@example.test', 'lead@example.test'], $m['rcpt'], $mode);
                $this->assertSame($tls, $m['tls'], "{$mode} TLS");
                $this->assertSame($authed, $m['auth'], "{$mode} AUTH");
                $this->assertTrue(preg_match('/^Subject: (.+)$/m', $m['data'], $s) === 1, $mode);
                $this->assertSame('[PJGuardian] 경고: 시험', mb_decode_mimeheader(trim($s[1])), $mode);
                $this->assertStringNotContainsString(self::PASSWORD, $m['data'], $mode);
                $this->assertStringNotContainsString('X-Mailer: PHPMailer', $m['data'], '라이브러리 이름 머리글 없음');
            } finally {
                $srv->stop();
            }
        }
    }

    public function testSmtpSenderFailureReasons(): void
    {
        $dir = $this->tempDir('smtpf');
        $try = static function (FakeSmtp|int $srv, string $tls, string $auth = 'none', string $pass = '', array $trust = [], int $sendTimeout = 10, int $connectTimeout = 2): ?string {
            $port = $srv instanceof FakeSmtp ? $srv->port : $srv;
            try {
                (new SmtpMailSender('127.0.0.1', $port, $tls, $auth, $auth === 'login' ? 'mailer' : '', $pass, 'pjg@example.test', 'PJG', $connectTimeout, $sendTimeout,
                    $trust))->send(['ops@example.test'], 's', 'b');
                return null;
            } catch (MailException $e) {
                return $e->reason;
            }
        };
        $s = new FakeSmtp($dir . '/a', 'starttls', ['user' => 'mailer', 'pass' => self::PASSWORD]);
        $this->assertSame('auth', $try($s, 'starttls', 'login', 'wrong-password', $s->trust()), '인증 실패');
        $this->assertSame([], $s->messages());
        // 인증서 검증은 늘 켜짐: 시험 CA를 주지 않으면(=OS 신뢰 저장소만) 실패
        $this->assertSame('tls', $try($s, 'starttls', 'login', self::PASSWORD), '신뢰하지 않는 CA');
        $s->stop();
        $u = new FakeSmtp($dir . '/b', 'smtps', ['untrusted' => true]);
        $this->assertSame('tls', $try($u, 'smtps', 'none', '', $u->trust()), '자체 서명(SMTPS)');
        $u->stop();
        $u2 = new FakeSmtp($dir . '/c', 'starttls', ['untrusted' => true]);
        $this->assertSame('tls', $try($u2, 'starttls', 'none', '', $u2->trust()), '자체 서명(STARTTLS)');
        $u2->stop();
        $r = new FakeSmtp($dir . '/d', 'plain', ['reject_rcpt' => 'ops@example.test']);
        $this->assertSame('rejected', $try($r, 'none'), '수신 거부');
        $this->assertSame([], $r->messages());
        $r->stop();
        $q = new FakeSmtp($dir . '/e', 'plain', ['silent' => true]);
        $t0 = microtime(true);
        $this->assertSame('timeout', $try($q, 'none', 'none', '', [], 3), '응답 없음');
        $this->assertTrue(microtime(true) - $t0 < 8, '시간 상한 안에 끝남');
        $q->stop();
        // 듣는 곳 없는 포트
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $closed = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        // Windows는 거부된 연결도 약 2초 다시 시도하므로 연결 시간 상한을 넉넉히(시간 초과와 구분)
        $this->assertSame('connect', $try($closed, 'none', 'none', '', [], 10, 6), '연결 실패');
    }

    public function testHeaderInjectionAndLimitsRejectedBeforeConnecting(): void
    {
        $sender = new SmtpMailSender('127.0.0.1', 9, 'none', 'none', '', '', 'pjg@example.test', 'PJG', 1, 1);
        $bad = [
            [['ops@example.test'], "제목\r\nBcc: x@evil.test"],
            [["ops@example.test\r\nBcc: x@evil.test"], 's'],
            [['not-an-address'], 's'],
            [[], 's'],
            [array_map(static fn (int $i): string => "u{$i}@example.test", range(1, 21)), 's'],
        ];
        foreach ($bad as $i => [$to, $subject]) {
            try {
                $sender->send($to, $subject, 'b');
                $this->fail("거부해야 함 #{$i}");
            } catch (MailException $e) {
                $this->assertSame('invalid', $e->reason, "#{$i}");
            }
        }
        $nameInj = new SmtpMailSender('127.0.0.1', 9, 'none', 'none', '', '', 'pjg@example.test', "PJG\r\nBcc: x@evil.test", 1, 1);
        try {
            $nameInj->send(['ops@example.test'], 's', 'b');
            $this->fail('보낸 사람 이름 주입');
        } catch (MailException $e) {
            $this->assertSame('invalid', $e->reason);
        }
        TestApp::config(['alert_mail' => self::ON]); // 정상 설정은 통과
        foreach ([['smtp_host' => 'smtp://mail.example.test/x'], ['smtp_host' => 'mail.example.test:25'], ['from_name' => "a\x01b"], ['from_address' => 'nope'],
            ['recipients' => implode(',', array_map(static fn (int $i): string => "u{$i}@example.test", range(1, 21)))], ['events' => 'disk, mail'],
            ['smtp_host' => '']] as $bad) {
            $this->assertThrows(\PJGuardian\Support\ConfigException::class, static fn () => TestApp::config(['alert_mail' => $bad + self::ON]));
        }
    }

    // ---- 사건·규칙 ----

    public function testEventsFromSnapshot(): void
    {
        $snap = self::snap([['worker_down', 'fail'], ['disk_block', 'fail', ['percent' => 97, 'path' => 'D:/secret']], ['tool_unhealthy', 'warn', ['tools' => 'ruff']],
            ['repo_fetch_failing', 'warn', ['count' => 3]], ['cert_expiring', 'warn', ['days' => 5, 'date' => '2026-10-07']]], [
            'tools' => ['items' => [['tool' => 'ruff', 'healthy' => false], ['tool' => 'eslint', 'healthy' => true], ['tool' => 'Bad Tool!', 'healthy' => false]]],
            'schedules' => ['alert_after' => 2, 'failing' => [['project' => ['id' => 7, 'name' => '<b>비밀 프로젝트</b>'], 'consecutive_failures' => 3],
                ['project' => ['id' => 8, 'name' => 'x'], 'consecutive_failures' => 1]]],
        ]);
        $all = ['worker_down', 'queue_stalled', 'disk', 'backup', 'tool', 'repo', 'cert'];
        $ev = AlertMailer::events($snap, $all);
        $this->assertSame(['worker_down', 'disk_block', 'tool_verify_failed:ruff', 'repo_fetch_failed:7', 'cert_expiring'], array_keys($ev));
        $this->assertSame(['percent' => 97], $ev['disk_block']['params'], '안전한 값만(경로 없음)');
        $this->assertSame(['project_id' => 7, 'count' => 3], $ev['repo_fetch_failed:7']['params'], '프로젝트 이름 없음');
        $this->assertSame(['disk_block', 'cert_expiring'], array_keys(AlertMailer::events($snap, ['disk', 'cert'])), '사건 종류로 거름');
    }

    public function testResendIntervalOngoingEscalationRecoveryAndBundle(): void
    {
        $app = $this->app();
        /** @var FixedClock $clock */
        $clock = $app->clock;
        $m = $app->alertMailer();
        $warn = self::snap([['disk_warn', 'warn', ['percent' => 91]]]);

        $r = $m->tick($warn);
        $this->assertSame(['sent' => true, 'items' => 1, 'result' => 'ok'], $r, '새로 → 바로');
        $this->assertCount(1, $this->sent);
        $this->assertSame(['ops@example.test', 'lead@example.test'], $this->sent[0]['to']);
        $this->assertStringStartsWith('[PJGuardian] 경고: ', $this->sent[0]['subject']);
        $this->assertStringContainsString('91%', $this->sent[0]['subject']);
        $this->assertStringContainsString('새로 발생', $this->sent[0]['body']);

        for ($i = 0; $i < 3; $i++) {
            $clock->advance(600);
            $this->assertSame('idle', $m->tick($warn)['result'], '간격 안은 보내지 않음');
        }
        $this->assertCount(1, $this->sent);
        $this->assertSame(3, (int) self::row($app, 'disk_warn')['suppressed_count']);

        $clock->advance(1800); // 처음 발송 뒤 1시간
        $this->assertTrue($m->tick($warn)['sent'], '간격 뒤 지속 중');
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('지속 중', $this->sent[1]['body']);
        $this->assertStringContainsString('다시 감지 3회', $this->sent[1]['body']);
        $this->assertSame(0, (int) self::row($app, 'disk_warn')['suppressed_count']);
        $this->assertSame(2, (int) self::row($app, 'disk_warn')['sent_count']);

        // 수준 오름: 간격과 상관없이 바로, 낮은 쪽은 복구 메일 없이 풀림
        $clock->advance(60);
        $this->assertTrue($m->tick(self::snap([['disk_block', 'fail', ['percent' => 99]]]))['sent']);
        $this->assertCount(3, $this->sent);
        $this->assertStringStartsWith('[PJGuardian] 오류: ', $this->sent[2]['subject']);
        $this->assertStringNotContainsString('복구', $this->sent[2]['body'], '경고 → 오류는 복구가 아님');
        $this->assertSame('resolved', self::row($app, 'disk_warn')['status']);

        // 풀림 → 복구 한 통
        $clock->advance(60);
        $this->assertTrue($m->tick(self::snap([]))['sent']);
        $this->assertCount(4, $this->sent);
        $this->assertStringStartsWith('[PJGuardian] 복구: ', $this->sent[3]['subject']);
        $this->assertStringContainsString('복구됨', $this->sent[3]['body']);
        $this->assertSame('idle', $m->tick(self::snap([]))['result'], '복구는 한 번만');

        // 한 판정 = 한 통(여러 건)
        $clock->advance(60);
        $this->assertSame(3, $m->tick(self::snap([['worker_down', 'fail'], ['backup_failed', 'warn', ['code' => 'backup_disk_full']],
            ['cert_expired', 'fail', ['date' => '2026-10-01']]]))['items']);
        $this->assertCount(5, $this->sent);
        $this->assertStringStartsWith('[PJGuardian] 오류 3건: ', $this->sent[4]['subject']);
        // 다시 생긴 사건은 새로(풀린 뒤 재발)
        $clock->advance(60);
        $m->tick(self::snap([]));
        $clock->advance(60);
        $m->tick(self::snap([['worker_down', 'fail']]));
        $this->assertStringContainsString('새로 발생', end($this->sent)['body']);
    }

    public function testRecoveryOffAndEventKindsFilter(): void
    {
        $app = $this->app(['recovery' => 'false', 'events' => 'disk, cert']);
        $m = $app->alertMailer();
        $this->assertSame('idle', $m->tick(self::snap([['worker_down', 'fail'], ['queue_stalled', 'warn', ['minutes' => 20]]]))['result'], '종류 밖');
        $this->assertTrue($m->tick(self::snap([['cert_expiring', 'warn', ['days' => 9, 'date' => '2026-10-11']]]))['sent']);
        $app->clock->advance(60);
        $this->assertSame('idle', $m->tick(self::snap([]))['result'], '복구 알림 끔');
        $this->assertSame('resolved', self::row($app, 'cert_expiring')['status']);
        $this->assertCount(1, $this->sent);
    }

    public function testBodyHasNoPathsSecretsOrUserInputAndFollowsLanguage(): void
    {
        $app = $this->app(['language' => 'en', 'smtp_auth' => 'login', 'smtp_user' => 'mailer']);
        $app->alertMailer()->setPassword(\PJGuardian\Services\Actor::system(), self::PASSWORD);
        $snap = self::snap([['repo_fetch_failing', 'warn', ['count' => 4]], ['backup_failed', 'warn', ['code' => 'backup_io', 'at' => '2026-10-02T11:00:00Z']]], [
            'schedules' => ['alert_after' => 2, 'failing' => [['project' => ['id' => 12, 'name' => 'Top <Secret> Project'], 'consecutive_failures' => 4]]],
        ]);
        $this->assertTrue($app->alertMailer()->tick($snap)['sent']);
        $mail = $this->sent[0];
        $this->assertSame('[PJGuardian] Warning, 2 events: Scheduled analysis of project #12 keeps failing and more', $mail['subject']);
        $this->assertStringContainsString('project #12', $mail['body']);
        $this->assertStringContainsString('/admin/monitoring', $mail['body']);
        $text = $mail['subject'] . $mail['body'];
        foreach ([self::PASSWORD, 'Top <Secret> Project', 'mailer', PJG_ROOT, str_replace('/', '\\', PJG_ROOT), $app->config->string('paths.work_dir')] as $no) {
            $this->assertStringNotContainsString($no, $text);
        }
        $this->assertSame(0, preg_match('/[가-힣]/u', $text), '영어 메일에 한글 없음');
    }

    public function testDisabledIncompleteOrMissingMigrationSendsNothing(): void
    {
        $off = $this->app(['enabled' => 'false']);
        $this->assertSame('off', $off->alertMailer()->tick(self::snap([['worker_down', 'fail']]))['result']);
        $this->assertSame('off', $off->alertMailer()->status()['state']);
        $this->assertSame(0, (int) $off->db()->value('SELECT COUNT(*) FROM alert_mail_state'), '꺼짐이면 상태도 만들지 않음');

        // 켠 채 서버·보낸 사람이 비면 설정 저장(로드)에서 막힘 — 화면의 "설정 필요"는 비밀번호·받는 사람·031·라이브러리
        $noPass = $this->app(['smtp_auth' => 'login', 'smtp_user' => 'mailer']);
        $st = $noPass->alertMailer()->status();
        $this->assertSame(['setup', ['password']], [$st['state'], $st['missing']]);
        $this->assertSame('off', $noPass->alertMailer()->tick(self::snap([['worker_down', 'fail']]))['result']);

        $noRcpt = $this->app(['recipients' => '']);
        $this->assertSame(['recipients'], $noRcpt->alertMailer()->status()['missing'], '이메일 있는 활성 admin 없음');
        $noRcpt->db()->execute("INSERT INTO users (login_id, display_name, email, role, status, auth_type, password_hash, created_at, updated_at)"
            . " VALUES ('boss', 'boss', 'boss@example.test', 'admin', 'active', 'local', 'x', ?, ?)", [TestDb::T0, TestDb::T0]);
        $this->assertSame(['boss@example.test'], $noRcpt->alertMailer()->recipients(), '비우면 활성 admin');

        $noTable = $this->app();
        $noTable->db()->execute('DROP TABLE alert_mail_state');
        $this->assertSame(['migration'], $noTable->alertMailer()->status()['missing']);
        $this->assertSame('off', $noTable->alertMailer()->tick(self::snap([['worker_down', 'fail']]))['result']);
        $this->assertSame(0, $noTable->alertMailer()->purgeResolved(false));
        $this->assertSame([], $this->sent);
    }

    public function testSendFailureIsRecordedAndRetriedAfterInterval(): void
    {
        $app = $this->app();
        $m = $app->alertMailer();
        $this->failWith = new MailException('auth');
        $this->assertSame(['sent' => false, 'items' => 1, 'result' => 'auth'], $m->tick(self::snap([['worker_down', 'fail']])));
        $this->assertSame('auth', self::row($app, 'worker_down')['last_result']);
        $this->failWith = new \RuntimeException('서버 응답 원문 D:/secret/path');
        $app->clock->advance(3600);
        $this->assertSame('connect', $m->tick(self::snap([['worker_down', 'fail']]))['result'], '모르는 예외도 워커를 멈추지 않음');
        $this->failWith = null;
        $app->clock->advance(60);
        $this->assertSame('idle', $m->tick(self::snap([['worker_down', 'fail']]))['result'], '실패도 간격(폭주 방지)');
        $app->clock->advance(3600);
        $this->assertTrue($m->tick(self::snap([['worker_down', 'fail']]))['sent'], '간격 뒤 다시');
        $this->assertSame('ok', self::row($app, 'worker_down')['last_result']);
        $log = '';
        foreach (glob($app->paths->logs() . '/*.log') ?: [] as $f) {
            $log .= (string) file_get_contents($f);
        }
        $this->assertStringContainsString('관리자 이메일 발송', $log);
        $this->assertStringNotContainsString('D:/secret/path', $log, '예외 메시지는 로그에 없음');
    }

    public function testConcurrentWorkersSendOnce(): void
    {
        $dir = $this->tempDir('amc');
        $file = $dir . '/db.sqlite';
        TestDb::migrated($file);
        $go = $dir . '/go';
        $out = $dir . '/sent.jsonl';
        $procs = [];
        for ($i = 0; $i < 3; $i++) {
            $procs[] = proc_open([PHP_BINARY, PJG_ROOT . '/tests/Support/alert_mail_tick.php', $file, $dir . "/w{$i}", $go, $out],
                [1 => ['file', $dir . "/o{$i}.txt", 'w'], 2 => ['file', $dir . "/e{$i}.txt", 'w']], $pipes);
        }
        usleep(800_000);
        touch($go);
        foreach ($procs as $i => $p) {
            $this->assertSame(0, proc_close($p), (string) @file_get_contents($dir . "/e{$i}.txt"));
        }
        $lines = file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(1, $lines, '세 워커가 같이 판정해도 한 통');
        $results = array_map(static fn (int $i): string => trim((string) file_get_contents($dir . "/o{$i}.txt")), [0, 1, 2]);
        sort($results);
        $this->assertSame(['idle', 'idle', 'ok'], $results);
    }

    // ---- API ----

    public function testApiAdminOnlyAndPasswordNeverLeaks(): void
    {
        $app = $this->app(['smtp_auth' => 'login', 'smtp_user' => 'mailer']);
        $db = $app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('팀', ?, ?)", [TestDb::T0, TestDb::T0]);
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, ?, 1, ?)', [$mgr, TestDb::T0]);
        foreach (['kim', 'mgr'] as $login) {
            $c = new TestClient($app);
            $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status);
            foreach ([['GET', '/api/v1/admin/alert-mail', null], ['PUT', '/api/v1/admin/alert-mail/password', ['password' => 'x']],
                ['DELETE', '/api/v1/admin/alert-mail/password', null], ['POST', '/api/v1/admin/alert-mail/test', []]] as [$method, $path, $json]) {
                $r = $c->request($method, $path, [], $json);
                $this->assertSame(403, $r->status, "{$login} {$method} {$path}");
            }
        }
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM app_secrets WHERE name = 'mail.smtp_password'"));

        $admin = new TestClient($app);
        $this->assertSame(200, $admin->login('admin', 'Passw0rd!x')->status);
        $st = $admin->get('/api/v1/admin/alert-mail');
        $this->assertSame(['setup', 'none', ['password']], [self::j($st)['data']['state'], self::j($st)['data']['password'], self::j($st)['data']['missing']]);
        $put = $admin->put('/api/v1/admin/alert-mail/password', ['password' => self::PASSWORD]);
        $this->assertSame(['password' => 'set'], self::j($put)['data']);
        $after = $admin->get('/api/v1/admin/alert-mail');
        $this->assertSame(['on', 'set'], [self::j($after)['data']['state'], self::j($after)['data']['password']]);
        $this->assertSame(self::PASSWORD, $app->appSecrets()->get('mail.smtp_password'), '복호화해 쓸 수 있음');
        $admin->put('/api/v1/admin/alert-mail/password', ['password' => self::PASSWORD . '2']);
        $del = $admin->request('DELETE', '/api/v1/admin/alert-mail/password');
        $this->assertSame(['password' => 'none'], self::j($del)['data']);
        $actions = array_column($db->all("SELECT action, details FROM audit_log WHERE action LIKE 'mail.%' ORDER BY id"), 'details', 'action');
        $this->assertSame(['mail.secret_set', 'mail.secret_delete'], array_keys($actions));
        $this->assertSame(['mail.secret_set', 'mail.secret_set', 'mail.secret_delete'], array_column($db->all("SELECT action FROM audit_log WHERE action LIKE 'mail.%' ORDER BY id"), 'action'));
        // 어디에도 평문이 없음: API 응답·감사·로그·DB 파일 덤프·설정
        $haystack = $st->body . $put->body . $after->body . $del->body . json_encode($db->all('SELECT * FROM audit_log'), JSON_UNESCAPED_UNICODE)
            . json_encode($db->all('SELECT * FROM app_secrets'), JSON_UNESCAPED_UNICODE);
        foreach (glob($app->paths->logs() . '/*.log') ?: [] as $f) {
            $haystack .= (string) file_get_contents($f);
        }
        $this->assertStringNotContainsString(self::PASSWORD, $haystack);
    }

    public function testTestSendRateLimitAuditAndErrors(): void
    {
        $app = $this->app(['test_per_minute' => '3', 'test_per_hour' => '5']);
        $db = $app->db();
        $id = TestDb::user($db, 'admin', 'admin');
        $db->execute("UPDATE users SET email = 'admin@example.test' WHERE id = ?", [$id]);
        $c = new TestClient($app);
        $this->assertSame(200, $c->login('admin', 'Passw0rd!x')->status);

        $r = $c->post('/api/v1/admin/alert-mail/test', []);
        $this->assertSame(['ok' => true, 'code' => null], self::j($r)['data']);
        $this->assertSame(['admin@example.test'], $this->sent[0]['to'], '비우면 내 계정 이메일');
        $this->assertStringStartsWith('[PJGuardian] ', $this->sent[0]['subject']);
        $c->post('/api/v1/admin/alert-mail/test', ['to' => 'other@example.test']);
        $this->failWith = new MailException('tls');
        $this->assertSame(['ok' => false, 'code' => 'tls'], self::j($c->post('/api/v1/admin/alert-mail/test', []))['data'], '실패도 200 + 사유');
        $limited = $c->post('/api/v1/admin/alert-mail/test', []);
        $this->assertSame([429, 'alert_mail_test_rate_limited'], [$limited->status, self::j($limited)['error']['code']], '분당 3회');
        $this->failWith = null;
        $app->clock->advance(61);
        $this->assertSame(200, $c->post('/api/v1/admin/alert-mail/test', [])->status);
        $app->clock->advance(61);
        $this->assertSame(200, $c->post('/api/v1/admin/alert-mail/test', [])->status);
        $app->clock->advance(61);
        $hour = $c->post('/api/v1/admin/alert-mail/test', []);
        $this->assertSame([429, 'alert_mail_test_hourly_limited'], [$hour->status, self::j($hour)['error']['code']], '전체 시간당 5회');

        $bad = $c->post('/api/v1/admin/alert-mail/test', ['to' => "x@example.test\r\nBcc: y@evil.test"]);
        $this->assertSame([400, 'alert_mail_invalid_recipient'], [$bad->status, self::j($bad)['error']['code']]);

        $rows = $db->all("SELECT details FROM audit_log WHERE action = 'mail.test' ORDER BY id");
        $this->assertCount(5, $rows);
        $first = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(['ok' => true, 'code' => null, 'recipient' => 'a***@example.test'], $first);
        $this->assertSame('tls', json_decode((string) $rows[2]['details'], true)['code']);
        $this->assertStringNotContainsString('admin@example.test', json_encode($db->all('SELECT * FROM audit_log')));

        $none = $this->app(['smtp_auth' => 'login', 'smtp_user' => 'mailer']);
        $nid = TestDb::user($none->db(), 'admin', 'admin');
        $none->db()->execute("UPDATE users SET email = 'admin@example.test' WHERE id = ?", [$nid]);
        $nc = new TestClient($none);
        $nc->login('admin', 'Passw0rd!x');
        $nr = $nc->post('/api/v1/admin/alert-mail/test', []);
        $this->assertSame([409, 'alert_mail_not_configured', ['password']], [$nr->status, self::j($nr)['error']['code'], self::j($nr)['error']['details']['missing'] ?? null]);
    }

    public function testMonitoringAppCardShowsMailState(): void
    {
        $off = $this->app(['enabled' => 'false']);
        $this->assertSame('off', $off->systemHealth()->cards()['app']['alert_mail']);
        $on = $this->app();
        $this->assertSame('on', $on->systemHealth()->cards()['app']['alert_mail']);
        $setup = $this->app(['smtp_auth' => 'login', 'smtp_user' => 'mailer']);
        $this->assertSame('setup', $setup->systemHealth()->cards()['app']['alert_mail']);
        $this->assertFalse(in_array('alert_mail', array_column($setup->systemHealth()->judge($setup->systemHealth()->cards()), 'code'), true), '판정에는 넣지 않음');
    }

    // ---- 마이그레이션·정리 ----

    public function testMigration031ApplyAndRollbackOnCopy(): void
    {
        $dir = $this->tempDir('m031');
        $db = TestDb::migrated($dir . '/a.sqlite');
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM schema_migrations WHERE version = '031'"));
        $db->execute("INSERT INTO alert_mail_state (event_key, kind, level, status, first_at, last_seen_at) VALUES ('disk_warn', 'disk', 'warn', 'active', ?, ?)",
            [TestDb::T0, TestDb::T0]);
        foreach ([
            ["INSERT INTO alert_mail_state (event_key, kind, level, status, first_at, last_seen_at) VALUES ('Bad Key', 'disk', 'warn', 'active', ?, ?)", '키 형식'],
            ["INSERT INTO alert_mail_state (event_key, kind, level, status, first_at, last_seen_at) VALUES ('x_y', 'mail', 'warn', 'active', ?, ?)", '종류'],
            ["INSERT INTO alert_mail_state (event_key, kind, level, status, first_at, last_seen_at) VALUES ('x_z', 'disk', 'warn', 'resolved', ?, ?)", '풀림 시각 없음'],
        ] as [$sql, $why]) {
            try {
                $db->execute($sql, [TestDb::T0, TestDb::T0]);
                $this->fail("CHECK: {$why}");
            } catch (\PDOException) {
            }
        }
        $copy = $dir . '/copy.sqlite';
        $db->execute('VACUUM INTO ?', [$copy]);
        $c = new Db($copy, 2000);
        $c->execute('DROP TABLE alert_mail_state');
        $c->execute("DELETE FROM schema_migrations WHERE version = '031'");
        $this->assertSame(0, (int) $c->value("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'alert_mail_state%'"), '색인도 함께 사라짐');
        $this->assertSame('ok', (string) $c->value('PRAGMA integrity_check'));
        $applied = (new Migrator($c, PJG_ROOT . '/migrations', new FixedClock(TestDb::T0)))->migrate();
        $this->assertSame(['031'], array_map(static fn ($v): string => (string) (is_array($v) ? ($v['version'] ?? reset($v)) : $v), $applied), '다시 적용');
        $this->assertSame(1, (int) $c->value("SELECT COUNT(*) FROM sqlite_master WHERE name = 'alert_mail_state_resolved'"));
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM alert_mail_state'), '원본은 그대로');
    }

    public function testCleanupPurgesOldResolvedRows(): void
    {
        $app = $this->app();
        $db = $app->db();
        $ins = static fn (string $key, string $status, ?string $resolved) => $db->execute(
            'INSERT INTO alert_mail_state (event_key, kind, level, status, first_at, last_seen_at, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$key, 'disk', 'warn', $status, '2026-08-01T00:00:00Z', '2026-08-01T00:00:00Z', $resolved]);
        $ins('disk_warn', 'resolved', '2026-08-20T00:00:00Z'); // 43일 전
        $ins('disk_block', 'resolved', '2026-09-30T00:00:00Z'); // 2일 전
        $ins('cert_expired', 'active', null);
        $this->assertSame(['alert_mail_states_purged' => 1], $app->cleanup()->purgeAlertMailStates(true));
        $this->assertSame(3, (int) $db->value('SELECT COUNT(*) FROM alert_mail_state'), 'dry-run은 지우지 않음');
        $all = $app->cleanup()->runAll(false);
        $this->assertSame(1, $all['alert_mail_states_purged']);
        $this->assertSame(['cert_expired', 'disk_block'], array_column($db->all('SELECT event_key FROM alert_mail_state ORDER BY event_key'), 'event_key'));
    }
}
