<?php

declare(strict_types=1);

/**
 * 시험용(P30B AlertMailTest::testConcurrentWorkersSendOnce): 워커 하나처럼 같은 DB 파일에서 AlertMailer::tick을 한 번 부른다.
 *   php tests/Support/alert_mail_tick.php <DB 파일> <작업 폴더> <시작 신호 파일> <발송 기록 jsonl>
 * 여러 프로세스를 띄운 뒤 시작 신호 파일을 만들면 동시에 판정한다. 메일은 실제로 보내지 않고 기록 파일에 한 줄 덧붙인다(외부 연결 없음).
 * 표준 출력: tick 결과 코드(ok·idle·off…)
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Mail\MailSender;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;

[, $dbFile, $work, $go, $out] = $argv;
@mkdir($work, 0777, true);
$config = TestApp::config(['paths' => ['work_dir' => $work, 'logs_dir' => $work], 'alert_mail' => [
    'enabled' => 'true', 'smtp_host' => 'mail.example.test', 'smtp_auth' => 'none', 'from_address' => 'pjg@example.test', 'recipients' => 'ops@example.test',
]]);
$clock = new FixedClock('2026-10-02T12:00:00Z');
$app = new App(PJG_ROOT, $config, $clock, new Logger($work, $clock, 'INFO'), new Paths(PJG_ROOT, $config), new Db($dbFile, 5000));
$app->useMailSender(static fn (): MailSender => new class ($out) implements MailSender {
    public function __construct(private readonly string $out)
    {
    }

    public function send(array $to, string $subject, string $body): void
    {
        usleep(300_000); // 느린 메일 서버 — 그동안 다른 워커가 같은 사건을 집지 않아야 함
        file_put_contents($this->out, json_encode(['to' => $to, 'subject' => $subject, 'pid' => getmypid()], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
});
for ($i = 0; $i < 200 && !is_file($go); $i++) {
    usleep(20_000);
}
$r = $app->alertMailer()->tick(['alerts' => [['code' => 'worker_down', 'level' => 'fail', 'params' => []]], 'cards' => []]);
echo $r['result'], "\n";
