<?php

declare(strict_types=1);

namespace PJGuardian\Services\Mail;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Audit\AuditQuery;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Auth\AppSecrets;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SchemaFeatures;
use PJGuardian\Support\SystemClock;

/**
 * 관리자 장애 이메일(P30B, 명세 19.3). 판정은 SystemHealth 한 곳 그대로 — 여기는 **판정 결과를 사건으로 바꾸고 발송 규칙**만 적용한다.
 *
 * - 사건 키(events()): worker_down, queue_stalled, disk_warn·disk_block, backup_failed·backup_stale, tool_verify_failed:<도구>(도구 카드의 건강하지 않은 분석 도구마다),
 *   repo_fetch_failed:<프로젝트 id>(주기 분석 연속 실패가 기준 이상인 프로젝트마다), cert_expiring·cert_expired. 수준이 다른 키는 다른 사건(경고→오류는 바로 보냄).
 *   사건 종류(설정 alert_mail.events)로 걸러 낸다. 메일에는 프로젝트 이름(사용자 입력) 대신 번호만.
 * - tick(): **워커의 주기 판정에서만** 부른다(웹 요청은 보내지 않음). BEGIN IMMEDIATE 안에서 상태(alert_mail_state, 031)를 읽고
 *   새 사건(또는 풀렸다 다시 생긴 사건)은 바로, active가 이어지면 alert_mail.resend_interval(기본 1시간)이 지났을 때만 "지속 중"으로, 그 안이면 억제 횟수만 센다.
 *   풀린 사건은 복구 메일(alert_mail.recovery) — 같은 계열의 더 높은 수준이 active면(disk_warn → disk_block) 조용히 풀림 처리.
 *   한 주기의 보낼 것을 **한 통으로 묶고**(제목에 건수), 보낼 시각을 트랜잭션 안에서 먼저 적어 둔다(선점 — 여러 워커가 같이 판정해도 한 번만).
 *   보내기는 트랜잭션 밖(짧은 시간 상한), 실패는 상태(last_result)와 앱 로그에 사유 코드만 — 다음 간격에 다시 보낸다. 판정·분석은 막지 않는다.
 * - 설정이 꺼짐·불완전·031 전이면 아무것도 보내지 않고 상태도 바꾸지 않는다(모니터링 앱 정보 카드에 "꺼짐/설정 필요").
 * - 메일 본문: 사건 이름·수준·시각(화면 시간대)·요약 한두 줄·모니터링 주소(app.base_url)만. 서버 경로·계정·비밀·사용자 입력 원문·스택 추적은 넣지 않는다.
 */
final class AlertMailer
{
    public const KINDS_BY_CODE = [
        'worker_down' => 'worker_down', 'queue_stalled' => 'queue_stalled', 'disk_warn' => 'disk', 'disk_block' => 'disk',
        'backup_failed' => 'backup', 'backup_stale' => 'backup', 'cert_expiring' => 'cert', 'cert_expired' => 'cert',
    ];
    /** 같은 계열(낮은 수준 → 높은 수준): 높은 쪽이 active면 낮은 쪽이 풀려도 복구 메일을 보내지 않는다 */
    private const ESCALATION = ['disk_warn' => 'disk_block', 'cert_expiring' => 'cert_expired'];
    /** 메일 본문에 넣는 값(숫자·날짜·도구 이름·코드만 — 사용자 입력 원문 없음) */
    private const SAFE_PARAMS = ['minutes', 'waiting', 'percent', 'code', 'hours', 'days', 'date', 'tool', 'project_id', 'count'];

    /** @param \Closure(): MailSender $sender 저장된 설정으로 만든 발송기(시험은 가짜) */
    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly AppSecrets $secrets,
        private readonly \Closure $sender,
        private readonly Logger $logger,
        private readonly AuditLog $audit,
        private readonly string $workRoot,
    ) {
    }

    // ---- 설정·상태 ----

    private function cfg(string $key): mixed
    {
        return $this->config->get('alert_mail.' . $key);
    }

    /** @return list<string> 받는 사람(설정 목록, 비우면 활성 admin 계정의 이메일) */
    public function recipients(): array
    {
        $list = (array) $this->cfg('recipients');
        if ($list === []) {
            $list = array_values(array_filter(array_map('strval', array_column($this->db->all(
                "SELECT email FROM users WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email <> '' ORDER BY id",
            ), 'email')), static fn (string $e): bool => SmtpMailSender::validAddress($e)));
        }
        return array_slice(array_values(array_unique($list)), 0, ConfigSchema::ALERT_MAIL_MAX_RECIPIENTS);
    }

    /**
     * 화면·모니터링용 상태(비밀·주소 원문 없음). missing: 보내려면 채워야 할 것.
     *
     * @return array{enabled:bool, ready:bool, state:string, missing:list<string>, password:string, plaintext:bool, migration:bool, recipients:int, recipients_source:string, tls:string}
     */
    public function status(): array
    {
        // vendor/(PHPMailer)가 없는 설치본: 보낼 수 없음을 상태로 알린다(발송 시도 전에)
        $missing = class_exists(\PHPMailer\PHPMailer\PHPMailer::class) ? [] : ['library'];
        $migration = SchemaFeatures::alertMail($this->db);
        if (!$migration) {
            $missing[] = 'migration';
        }
        if ((string) $this->cfg('smtp_host') === '') {
            $missing[] = 'smtp_host';
        }
        if ((string) $this->cfg('from_address') === '') {
            $missing[] = 'from_address';
        }
        $password = $this->secrets->state('mail.smtp_password');
        if ($this->cfg('smtp_auth') === 'login') {
            if ((string) $this->cfg('smtp_user') === '') {
                $missing[] = 'smtp_user';
            }
            if ($password !== 'set') {
                $missing[] = 'password';
            }
        }
        $recipients = $this->recipients();
        if ($recipients === []) {
            $missing[] = 'recipients';
        }
        $enabled = (bool) $this->cfg('enabled');
        $ready = $missing === [];
        return [
            'enabled' => $enabled, 'ready' => $ready, 'state' => !$enabled ? 'off' : ($ready ? 'on' : 'setup'), 'missing' => $missing,
            'password' => $password, 'plaintext' => $this->cfg('smtp_tls') === 'none', 'migration' => $migration,
            'recipients' => count($recipients), 'recipients_source' => (array) $this->cfg('recipients') === [] ? 'admins' : 'list', 'tls' => (string) $this->cfg('smtp_tls'),
        ];
    }

    /** 저장된 설정으로 만든 SMTP 발송기(App이 넘기는 기본 sender) */
    public static function smtpFrom(Config $c, AppSecrets $secrets): SmtpMailSender
    {
        $password = '';
        if ($c->get('alert_mail.smtp_auth') === 'login' && $secrets->state('mail.smtp_password') === 'set') {
            $password = $secrets->get('mail.smtp_password');
        }
        return new SmtpMailSender((string) $c->get('alert_mail.smtp_host'), $c->int('alert_mail.smtp_port'), (string) $c->get('alert_mail.smtp_tls'),
            (string) $c->get('alert_mail.smtp_auth'), (string) $c->get('alert_mail.smtp_user'), $password, (string) $c->get('alert_mail.from_address'),
            (string) $c->get('alert_mail.from_name'), $c->int('alert_mail.connect_timeout'), $c->int('alert_mail.send_timeout'));
    }

    // ---- 사건 ----

    /**
     * 판정 결과(SystemHealth 스냅숏) → 사건 목록(순수 함수). 키 순서는 판정 순서(오류 먼저).
     *
     * @param array{alerts:list<array{code:string, level:string, params:array<string, mixed>}>, cards:array<string, mixed>} $snap
     * @param list<string> $kinds 켜진 사건 종류
     * @return array<string, array{key:string, kind:string, level:string, code:string, params:array<string, scalar|null>}>
     */
    public static function events(array $snap, array $kinds): array
    {
        $out = [];
        $add = static function (string $key, string $kind, string $level, string $code, array $params) use (&$out, $kinds): void {
            if (in_array($kind, $kinds, true)) {
                $out[$key] = ['key' => $key, 'kind' => $kind, 'level' => $level, 'code' => $code,
                    'params' => array_filter(array_intersect_key($params, array_flip(self::SAFE_PARAMS)), static fn ($v): bool => is_scalar($v) || $v === null)];
            }
        };
        foreach ($snap['alerts'] ?? [] as $a) {
            $code = (string) $a['code'];
            $level = $a['level'] === 'fail' ? 'fail' : 'warn';
            if (isset(self::KINDS_BY_CODE[$code])) {
                $add($code, self::KINDS_BY_CODE[$code], $level, $code, (array) ($a['params'] ?? []));
            } elseif ($code === 'tool_unhealthy') {
                foreach ((array) ($snap['cards']['tools']['items'] ?? []) as $t) {
                    $tool = strtolower((string) ($t['tool'] ?? ''));
                    if (($t['healthy'] ?? null) === false && preg_match('/^[a-z0-9_]{1,40}$/', $tool) === 1) {
                        $add('tool_verify_failed:' . $tool, 'tool', $level, 'tool_verify_failed', ['tool' => $tool]);
                    }
                }
            } elseif ($code === 'repo_fetch_failing') {
                $after = (int) ($snap['cards']['schedules']['alert_after'] ?? PHP_INT_MAX);
                foreach ((array) ($snap['cards']['schedules']['failing'] ?? []) as $s) {
                    $id = (int) ($s['project']['id'] ?? 0);
                    if ($id > 0 && (int) ($s['consecutive_failures'] ?? 0) >= $after) {
                        $add('repo_fetch_failed:' . $id, 'repo', $level, 'repo_fetch_failed', ['project_id' => $id, 'count' => (int) $s['consecutive_failures']]);
                    }
                }
            }
        }
        return $out;
    }

    // ---- 발송 규칙 ----

    /**
     * 워커의 주기 판정 뒤 한 번. 보낼 것이 있으면 한 통.
     *
     * @param array{alerts:list<array<string, mixed>>, cards:array<string, mixed>} $snap
     * @return array{sent:bool, items:int, result:string} result: off·idle·ok·<사유 코드>
     */
    public function tick(array $snap): array
    {
        $st = $this->status();
        if (!$st['enabled'] || !$st['ready']) {
            return ['sent' => false, 'items' => 0, 'result' => 'off'];
        }
        $events = self::events($snap, (array) $this->cfg('events'));
        $now = SystemClock::iso($this->clock->now());
        $nowTs = $this->clock->now()->getTimestamp();
        $interval = $this->config->int('alert_mail.resend_interval');
        $recovery = (bool) $this->cfg('recovery');
        // 선점: 보낼 항목을 정하고 보낸 시각을 먼저 적는다(같은 판정을 다른 워커가 동시에 해도 한쪽만 보낸다)
        $items = $this->db->transaction(function (Db $db) use ($events, $now, $nowTs, $interval, $recovery): array {
            $rows = [];
            foreach ($db->all('SELECT * FROM alert_mail_state') as $r) {
                $rows[(string) $r['event_key']] = $r;
            }
            $items = [];
            foreach ($events as $key => $e) {
                $r = $rows[$key] ?? null;
                if ($r === null || $r['status'] === 'resolved') {
                    $db->execute('INSERT INTO alert_mail_state (event_key, kind, level, status, first_at, last_seen_at, last_sent_at, sent_count, suppressed_count, resolved_at, last_result)'
                        . " VALUES (?, ?, ?, 'active', ?, ?, ?, 1, 0, NULL, 'sending')"
                        . " ON CONFLICT (event_key) DO UPDATE SET kind = excluded.kind, level = excluded.level, status = 'active', first_at = excluded.first_at,"
                        . " last_seen_at = excluded.last_seen_at, last_sent_at = excluded.last_sent_at, sent_count = 1, suppressed_count = 0, resolved_at = NULL, last_result = 'sending'",
                        [$key, $e['kind'], $e['level'], $now, $now, $now]);
                    $items[] = $e + ['state' => 'new', 'first_at' => $now, 'suppressed' => 0];
                    continue;
                }
                $last = $r['last_sent_at'] === null ? 0 : (int) strtotime((string) $r['last_sent_at']);
                if ($nowTs - $last >= $interval) {
                    $db->execute("UPDATE alert_mail_state SET level = ?, last_seen_at = ?, last_sent_at = ?, sent_count = sent_count + 1, last_result = 'sending' WHERE event_key = ?",
                        [$e['level'], $now, $now, $key]);
                    $items[] = $e + ['state' => 'ongoing', 'first_at' => (string) $r['first_at'], 'suppressed' => (int) $r['suppressed_count']];
                    $db->execute('UPDATE alert_mail_state SET suppressed_count = 0 WHERE event_key = ?', [$key]);
                } else {
                    $db->execute('UPDATE alert_mail_state SET level = ?, last_seen_at = ?, suppressed_count = suppressed_count + 1 WHERE event_key = ?', [$e['level'], $now, $key]);
                }
            }
            foreach ($rows as $key => $r) {
                if ($r['status'] !== 'active' || isset($events[$key])) {
                    continue;
                }
                $higher = self::ESCALATION[$key] ?? null;
                $silent = !$recovery || ($higher !== null && isset($events[$higher]));
                $db->execute("UPDATE alert_mail_state SET status = 'resolved', resolved_at = ?, last_result = ? WHERE event_key = ?",
                    [$now, $silent ? $r['last_result'] : 'sending', $key]);
                if (!$silent) {
                    $code = str_contains($key, ':') ? strstr($key, ':', true) : $key;
                    $params = match ($code) {
                        'tool_verify_failed' => ['tool' => substr($key, strlen('tool_verify_failed:'))],
                        'repo_fetch_failed' => ['project_id' => (int) substr($key, strlen('repo_fetch_failed:')), 'count' => 0],
                        default => [],
                    };
                    $items[] = ['key' => $key, 'kind' => (string) $r['kind'], 'level' => (string) $r['level'], 'code' => $code, 'params' => $params,
                        'state' => 'recovered', 'first_at' => (string) $r['first_at'], 'suppressed' => 0];
                }
            }
            return $items;
        });
        if ($items === []) {
            return ['sent' => false, 'items' => 0, 'result' => 'idle'];
        }
        $mail = $this->compose($items, (string) $this->cfg('language'));
        $to = $this->recipients();
        $result = 'ok';
        try {
            ($this->sender)()->send($to, $mail['subject'], $mail['body']);
        } catch (MailException $e) {
            $result = $e->reason;
        } catch (\Throwable $e) {
            $result = 'connect';
            $this->logger->warn('관리자 이메일 발송 오류', ['error' => $e::class]);
        }
        $keys = array_column($items, 'key');
        $this->db->transaction(function (Db $db) use ($keys, $result): void {
            foreach ($keys as $k) {
                $db->execute("UPDATE alert_mail_state SET last_result = ? WHERE event_key = ? AND last_result = 'sending'", [$result, $k]);
            }
        });
        $this->logger->info('관리자 이메일 발송', ['events' => implode(',', $keys), 'states' => implode(',', array_column($items, 'state')), 'result' => $result,
            'recipients' => count($to)]);
        return ['sent' => $result === 'ok', 'items' => count($items), 'result' => $result];
    }

    // ---- 메일 글 ----

    /**
     * @param list<array{key:string, level:string, code:string, params:array<string, mixed>, state:string, first_at:string, suppressed:int}> $items
     * @return array{subject:string, body:string}
     */
    public function compose(array $items, string $lang): array
    {
        $prev = Lang::locale();
        Lang::setLocale($lang);
        try {
            $tz = new \DateTimeZone($this->config->string('app.display_timezone'));
            $time = static fn (string $iso): string => (new \DateTimeImmutable($iso))->setTimezone($tz)->format('Y-m-d H:i T');
            $active = array_values(array_filter($items, static fn (array $i): bool => $i['state'] !== 'recovered'));
            $levelWord = $active === [] ? Lang::get('alert_mail.level.recovered')
                : Lang::get(in_array('fail', array_column($active, 'level'), true) ? 'alert_mail.level.fail' : 'alert_mail.level.warn');
            $title = static fn (array $i): string => Lang::get("alert_mail.event.{$i['code']}", $i['params']);
            $first = $active[0] ?? $items[0];
            $subject = count($items) === 1
                ? Lang::get('alert_mail.subject.one', ['level' => $levelWord, 'title' => $title($first)])
                : Lang::get('alert_mail.subject.many', ['level' => $levelWord, 'n' => count($items), 'title' => $title($first)]);
            $subject = mb_substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $subject), 0, 250);
            $lines = [Lang::get('alert_mail.body.intro'), ''];
            foreach ($items as $i) {
                $lvl = Lang::get($i['state'] === 'recovered' ? 'alert_mail.level.recovered' : "alert_mail.level.{$i['level']}");
                $lines[] = '- [' . $lvl . '] ' . $title($i);
                $lines[] = '  ' . Lang::get("alert_mail.state.{$i['state']}", ['first' => $time($i['first_at']), 'n' => $i['suppressed']]);
            }
            $lines[] = '';
            $lines[] = Lang::get('alert_mail.body.checked', ['time' => $time(SystemClock::iso($this->clock->now()))]);
            $lines[] = Lang::get('alert_mail.body.link', ['url' => rtrim($this->config->string('app.base_url'), '/') . '/admin/monitoring']);
            $lines[] = '';
            $lines[] = Lang::get('alert_mail.body.footer', ['interval' => self::durationText($this->config->int('alert_mail.resend_interval'))]);
            return ['subject' => $subject, 'body' => implode("\n", $lines) . "\n"];
        } finally {
            Lang::setLocale($prev);
        }
    }

    private static function durationText(int $seconds): string
    {
        return $seconds % 3600 === 0 ? Lang::get('alert_mail.hours', ['n' => intdiv($seconds, 3600)]) : Lang::get('alert_mail.minutes', ['n' => intdiv($seconds, 60)]);
    }

    // ---- 시험 발송(설정 화면) ----

    /**
     * 저장된 설정으로 한 통(저장하지 않은 입력은 쓰지 않음). 받는 사람은 하나(기본 로그인한 admin의 이메일).
     * 사용자당 분당 alert_mail.test_per_minute회·전체 시간당 test_per_hour회. 감사 mail.test(받는 사람은 마스킹).
     *
     * @return array{ok:bool, code:?string}
     */
    public function sendTest(Actor $actor, mixed $to, ?string $fallback): array
    {
        $to = is_string($to) && trim($to) !== '' ? trim($to) : (string) $fallback;
        if ($to === '' || !SmtpMailSender::validAddress($to)) {
            throw new ApiException(400, 'alert_mail_invalid_recipient');
        }
        $st = $this->status();
        $missing = array_values(array_diff($st['missing'], ['recipients', 'migration']));
        if ($missing !== []) {
            throw new ApiException(409, 'alert_mail_not_configured', [], null, [], [], ['missing' => $missing]);
        }
        $this->consumeQuota((int) $actor->userId);
        $prev = Lang::locale();
        Lang::setLocale((string) $this->cfg('language'));
        try {
            $tz = new \DateTimeZone($this->config->string('app.display_timezone'));
            $subject = Lang::get('alert_mail.test.subject');
            $body = Lang::get('alert_mail.test.body', ['time' => $this->clock->now()->setTimezone($tz)->format('Y-m-d H:i:s T'),
                'url' => rtrim($this->config->string('app.base_url'), '/') . '/admin/monitoring']) . "\n";
        } finally {
            Lang::setLocale($prev);
        }
        $code = null;
        try {
            ($this->sender)()->send([$to], $subject, $body);
        } catch (MailException $e) {
            $code = $e->reason;
        } catch (\Throwable $e) {
            $code = 'connect';
            $this->logger->warn('관리자 이메일 시험 발송 오류', ['error' => $e::class]);
        }
        $masked = AuditQuery::maskEmail($to);
        $this->audit->record($actor, 'mail.test', null, null, ['ok' => $code === null, 'code' => $code, 'recipient' => $masked]);
        $this->logger->info('관리자 이메일 시험 발송', ['ok' => $code === null, 'code' => $code, 'recipient' => $masked, 'by' => $actor->label]);
        return ['ok' => $code === null, 'code' => $code];
    }

    public function setPassword(Actor $actor, mixed $password): string
    {
        if (!is_string($password)) {
            throw new ApiException(400, 'app_secret_invalid', ['max' => AppSecrets::MAX_BYTES]);
        }
        $replaced = $this->secrets->state('mail.smtp_password') !== 'none';
        $this->secrets->put('mail.smtp_password', $password, $actor->userId);
        $this->audit->record($actor, 'mail.secret_set', null, null, ['replaced' => $replaced]); // 값은 남기지 않는다
        $this->logger->info('관리자 이메일 SMTP 비밀번호 저장', ['by' => $actor->label]);
        return 'set';
    }

    public function deletePassword(Actor $actor): string
    {
        if ($this->secrets->delete('mail.smtp_password')) {
            $this->audit->record($actor, 'mail.secret_delete', null, null, []);
        }
        return 'none';
    }

    /** 사용자당 분당·전체 시간당(잠금 전용 파일 + 시각 목록 — LDAP·저장소 연결 시험과 같은 방식) */
    private function consumeQuota(int $userId): void
    {
        $dir = $this->workRoot . '/alert_mail';
        Fs::ensureDir($dir);
        $lock = FileLock::acquire($dir . '/rate.lock');
        try {
            $now = $this->clock->now()->getTimestamp();
            $read = static fn (string $f, int $window): array => array_values(array_filter((array) json_decode((string) @file_get_contents($f), true),
                static fn ($t): bool => is_int($t) && $t > $now - $window));
            $userFile = $dir . '/rate-' . $userId . '.json';
            $allFile = $dir . '/rate-all.json';
            $mine = $read($userFile, 60);
            $all = $read($allFile, 3600);
            if (count($mine) >= $this->config->int('alert_mail.test_per_minute')) {
                throw new ApiException(429, 'alert_mail_test_rate_limited', ['n' => $this->config->int('alert_mail.test_per_minute')]);
            }
            if (count($all) >= $this->config->int('alert_mail.test_per_hour')) {
                throw new ApiException(429, 'alert_mail_test_hourly_limited', ['n' => $this->config->int('alert_mail.test_per_hour')]);
            }
            $mine[] = $now;
            $all[] = $now;
            Fs::atomicWrite($userFile, (string) json_encode($mine));
            Fs::atomicWrite($allFile, (string) json_encode($all));
        } finally {
            $lock->release();
        }
    }

    /** 정리 작업(P30B): 풀린 지 alert_mail.state_retention(30일)이 지난 상태 행. dry-run은 수만 */
    public function purgeResolved(bool $dryRun): int
    {
        if (!SchemaFeatures::alertMail($this->db)) {
            return 0;
        }
        $cutoff = SystemClock::iso($this->clock->now()->modify('-' . $this->config->int('alert_mail.state_retention') . ' seconds'));
        if ($dryRun) {
            return (int) $this->db->value("SELECT COUNT(*) FROM alert_mail_state WHERE status = 'resolved' AND resolved_at < ?", [$cutoff]);
        }
        return $this->db->execute("DELETE FROM alert_mail_state WHERE status = 'resolved' AND resolved_at < ?", [$cutoff]);
    }
}
