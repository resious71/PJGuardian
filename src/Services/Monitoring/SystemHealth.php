<?php

declare(strict_types=1);

namespace PJGuardian\Services\Monitoring;

use PJGuardian\App;
use PJGuardian\Support\EnvironmentCheck;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 장애 판정 한 곳(P30, 명세 14·19.3): 시스템 모니터링 화면·/health·관리자 배너·(P30B) 이메일이 **같은 판정**을 쓴다.
 *
 * 1. cards(): 카드별 사실을 모은다(워커·큐·디스크·최근 실패·백업·bin 도구·주기 분석·HTTPS 인증서·앱 정보). 카드 하나가 실패해도
 *    그 카드만 {available:false, reason}이고 나머지는 그대로(화면 전체가 깨지지 않음). 웹 요청 안에서 프로세스를 띄우거나 네트워크에 붙지 않는다.
 * 2. judge(): 사실 → 경고 목록(순수 함수, 경계값 시험 대상). 코드·수준:
 *      worker_down(fail) 워커 생존 신호 없음·멈춤·응답 없음 / queue_stalled(warn) 워커가 살아 있고 빈 자리가 있는데 시작할 수 있는 가장 오래된 대기 분석이 monitoring.queue_stall 넘게 대기 /
 *      disk_warn(warn)·disk_block(fail) disk.warn_percent·block_percent / backup_failed(warn) 마지막 실행이 실패(마지막 성공보다 나중) /
 *      backup_stale(warn) 자동 백업이 켜져 있는데 마지막 성공이 "오래됨" 기준(백업 화면과 같은 계산)보다 오래됨·없음 /
 *      tool_unhealthy(warn) ruff·eslint의 쓸 버전이 없거나 체크섬·스모크 실패·파일 바뀜 / repo_fetch_failing(warn) 주기 분석 연속 실패 monitoring.repo_failures회 이상 /
 *      cert_expiring(warn) 인증서 만료가 notification.cert_expiry_warn 안 / cert_expired(fail) / audit_anchor_mismatch(fail, P31) 감사 로그가 DB 밖 기록과 어긋남.
 *    전체 상태: fail이 하나라도 있으면 fail, warn이 있으면 warn, 없으면 ok. 값을 못 구한 카드는 경고를 만들지 않는다(화면에 "확인 불가"만).
 * 3. evaluate(): 판정 + 상태 변화 기록(잠금 안에서). 경고가 새로 생기거나 수준이 바뀌면 경고 로그(logs/alert-*.log)에 한 줄, 이어지면 monitoring.log_repeat마다 한 줄,
 *    풀리면 한 줄. **정상 → 경고**로 바뀌는 순간에만 AdminAlertSink::raised(이메일을 붙일 한 지점). 결과는 캐시(<work>/monitoring/health.json, monitoring.cache).
 *    부르는 곳: 워커(monitoring.cache마다 — 방문자가 없어도 상태 변화를 기록), 캐시가 지난 뒤의 화면·배너·/health.
 */
final class SystemHealth
{
    public const CODES = ['worker_down', 'queue_stalled', 'disk_warn', 'disk_block', 'backup_failed', 'backup_stale', 'tool_unhealthy', 'repo_fetch_failing', 'cert_expiring', 'cert_expired', 'audit_anchor_mismatch'];
    public const CARDS = ['worker', 'queue', 'disk', 'failures', 'backup', 'tools', 'schedules', 'cert', 'app'];

    private ?AdminAlertSink $sink;

    public function __construct(private readonly App $app, ?AdminAlertSink $sink = null, private readonly ?Logger $alertLog = null)
    {
        $this->sink = $sink;
    }

    private function dir(): string
    {
        return rtrim($this->app->paths->work(), '/') . '/monitoring';
    }

    private function now(): string
    {
        return SystemClock::iso($this->app->clock->now());
    }

    // ---- 1. 사실 ----

    /** @return array<string, array<string, mixed>> */
    public function cards(): array
    {
        $out = [];
        foreach (self::CARDS as $card) {
            try {
                $out[$card] = $this->{'card' . ucfirst($card)}() + ['available' => true];
            } catch (\Throwable $e) {
                $this->app->logger->warn('모니터링 카드 계산 실패', ['card' => $card, 'error' => $e::class]);
                $out[$card] = ['available' => false, 'reason' => 'error'];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function cardWorker(): array
    {
        $s = $this->app->workerControl()->status();
        $children = (int) ($s['children']['analyses'] ?? 0) + (int) ($s['children']['uploads'] ?? 0) + (int) ($s['children']['admin_tasks'] ?? 0);
        return ['state' => $s['state'], 'alive' => in_array($s['state'], ['running', 'stopping'], true), 'beat_at' => $s['beat_at'],
            'seconds_since_beat' => $s['seconds_since_beat'], 'stale_after' => $s['stale_after'], 'children' => $children];
    }

    /** @return array<string, mixed> */
    private function cardQueue(): array
    {
        $db = $this->app->db();
        $now = $this->app->clock->now()->getTimestamp();
        $waiting = (int) $db->value("SELECT COUNT(*) FROM analyses WHERE status = 'queued'");
        $oldest = $db->value("SELECT MIN(queued_at) FROM analyses WHERE status = 'queued'");
        // 시작할 수 있는(그 프로젝트에 실행 중인 분석이 없는) 가장 오래된 대기 — 프로젝트당 1개 규칙 때문에 기다리는 것은 정체가 아니다
        $startable = $db->value("SELECT MIN(q.queued_at) FROM analyses q WHERE q.status = 'queued'"
            . " AND NOT EXISTS (SELECT 1 FROM analyses r WHERE r.project_id = q.project_id AND r.status = 'running')");
        $running = array_map(static fn (array $r): array => [
            'analysis_id' => (int) $r['id'], 'project' => ['id' => (int) $r['project_id'], 'name' => (string) $r['name']],
            'run_number' => $r['run_number'] === null ? null : (int) $r['run_number'],
            'elapsed_seconds' => is_string($r['started_at']) ? max(0, $now - (int) strtotime($r['started_at'])) : null,
        ], $db->all("SELECT a.id, a.project_id, p.name, a.run_number, a.started_at FROM analyses a JOIN projects p ON p.id = a.project_id"
            . " WHERE a.status = 'running' ORDER BY a.started_at, a.id"));
        $age = static fn ($t): ?int => is_string($t) && strtotime($t) !== false ? max(0, $now - (int) strtotime($t)) : null;
        return ['waiting' => $waiting, 'oldest_wait_seconds' => $age($oldest), 'startable_wait_seconds' => $age($startable), 'running' => $running,
            'max_concurrent' => $this->app->config->int('analysis.max_concurrent'), 'stall_after_seconds' => $this->app->config->int('monitoring.queue_stall')];
    }

    /** @return array<string, mixed> */
    private function cardDisk(): array
    {
        $warn = $this->app->config->int('disk.warn_percent');
        $block = $this->app->config->int('disk.block_percent');
        $d = EnvironmentCheck::disk(dirname($this->app->paths->dbFile()), $warn, $block);
        if ($d['used_percent'] === null) {
            return ['available' => false, 'reason' => 'unreadable', 'warn_percent' => $warn, 'block_percent' => $block];
        }
        return ['used_percent' => $d['used_percent'], 'free_bytes' => $d['free'], 'total_bytes' => $d['total'], 'warn_percent' => $warn, 'block_percent' => $block,
            'state' => $d['status'] === 'fail' ? 'block' : ($d['status'] === 'warn' ? 'warn' : 'ok')];
    }

    /** @return array<string, mixed> 최근 실패 분석(사유 코드만, 실패 문구 원문·경로 없음) */
    private function cardFailures(): array
    {
        $n = $this->app->config->int('monitoring.recent_failures');
        $rows = $this->app->db()->all("SELECT a.id, a.project_id, p.name, a.run_number, a.failure_code, a.finished_at FROM analyses a JOIN projects p ON p.id = a.project_id"
            . " WHERE a.status = 'failed' ORDER BY a.finished_at DESC, a.id DESC LIMIT ?", [$n]);
        return ['items' => array_map(static fn (array $r): array => ['analysis_id' => (int) $r['id'], 'project' => ['id' => (int) $r['project_id'], 'name' => (string) $r['name']],
            'run_number' => $r['run_number'] === null ? null : (int) $r['run_number'], 'code' => (string) ($r['failure_code'] ?? 'unknown'), 'at' => $r['finished_at']], $rows),
            'limit' => $n];
    }

    /** @return array<string, mixed> */
    private function cardBackup(): array
    {
        $c = $this->app->config;
        $backups = $this->app->backups();
        $state = $backups->state()->read();
        $schedule = \PJGuardian\Services\Backup\BackupSchedule::fromConfig($c);
        $staleHours = $schedule->staleHours($c->int('backup.backup_stale_hours'));
        $last = $state['last_success']['at'] ?? null;
        $failure = $state['last_failure'] ?? null;
        $now = $this->app->clock->now()->getTimestamp();
        $failed = is_array($failure) && is_string($failure['at'] ?? null) && ($last === null || strcmp((string) $failure['at'], (string) $last) > 0);
        $dir = $backups->paths()->dir();
        $sameDisk = null;
        $a = @stat($dir);
        $b = @stat(dirname($this->app->paths->dbFile()));
        if (is_array($a) && is_array($b)) {
            $sameDisk = $a['dev'] === $b['dev'];
        }
        return [
            'enabled' => $c->bool('backup.enabled'),
            'last_success_at' => $last,
            'last_result' => $failed ? 'failed' : ($last !== null ? 'succeeded' : null),
            'last_failure' => $failed ? ['at' => $failure['at'], 'code' => (string) ($failure['code'] ?? 'unknown')] : null,
            'stale' => $last === null || $now - (int) strtotime((string) $last) > 3600 * $staleHours,
            'stale_hours' => $staleHours,
            'next_at' => $this->app->backupRunner(false)->nextAuto(),
            'same_disk' => $sameDisk,
        ];
    }

    /** @return array<string, mixed> ruff·eslint(분석 도구)만 판정, git·p4는 표시만 */
    private function cardTools(): array
    {
        $items = [];
        foreach ($this->app->toolAdmin()->overview(null)['tools'] as $t) {
            $sel = $t['selection'];
            $current = null;
            foreach ($t['versions'] as $v) {
                if ($v['version'] === $sel['version']) {
                    $current = $v;
                }
            }
            $blocked = array_values(array_map(static fn (array $v): string => (string) $v['version'], array_filter($t['versions'], static fn (array $v): bool => ($v['status'] ?? '') === 'blocked')));
            $healthy = $sel['version'] !== null && $current !== null && $current['checksum_ok'] !== false && $current['smoke_ok'] !== false && !$current['fingerprint_changed'];
            $items[] = [
                'tool' => (string) $t['tool'], 'version' => $sel['version'], 'reason' => (string) $sel['reason'], 'pinned' => in_array($sel['reason'], ['pinned', 'pinned_unusable'], true),
                'blocked' => $blocked, 'checksum_ok' => $current['checksum_ok'] ?? null, 'smoke_ok' => $current['smoke_ok'] ?? null,
                'checked_at' => $current['last_check_at'] ?? ($current['checksum_checked_at'] ?? null), 'read_only' => (bool) ($t['read_only'] ?? false),
                'healthy' => ($t['read_only'] ?? false) ? null : $healthy,
            ];
        }
        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private function cardSchedules(): array
    {
        if ($this->app->db()->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'project_schedules'") === null) {
            return ['available' => false, 'reason' => 'not_installed'];
        }
        $o = $this->app->scheduleStatus()->overview(30, 50);
        $limit = $this->app->config->int('monitoring.recent_failures');
        $upcoming = array_values(array_filter($o['schedules'], static fn (array $s): bool => $s['enabled'] && $s['next_run_at'] !== null));
        usort($upcoming, static fn (array $a, array $b): int => strcmp((string) $a['next_run_at'], (string) $b['next_run_at']));
        $failing = array_values(array_filter($o['schedules'], static fn (array $s): bool => $s['consecutive_failures'] > 0));
        usort($failing, static fn (array $a, array $b): int => $b['consecutive_failures'] <=> $a['consecutive_failures']);
        $pick = static fn (array $s): array => ['project' => $s['project'], 'next_run_at' => $s['next_run_at'], 'enabled' => $s['enabled'],
            'consecutive_failures' => $s['consecutive_failures'], 'last_error' => $s['last_error'] === null ? null : ['code' => $s['last_error']['code'], 'at' => $s['last_error']['at']],
            'retry_next' => $s['enabled'] && $s['next_run_at'] !== null];
        return ['upcoming' => array_map($pick, array_slice($upcoming, 0, $limit)), 'failing' => array_map($pick, array_slice($failing, 0, $limit)),
            'max_failures' => $failing === [] ? 0 : (int) $failing[0]['consecutive_failures'], 'alert_after' => $this->app->config->int('monitoring.repo_failures')];
    }

    /**
     * HTTPS 인증서 만료일: 웹 서버가 쓰는 인증서 파일(monitoring.tls_cert_file, PEM)을 읽는다 — 자기 주소에 접속하지 않는다
     * (요청 안에서 자신에게 요청을 보내지 않고, 저장소 연결의 주소 규칙(사설·루프백 거부)을 완화하지 않기 위해, docs/monitoring.md 2절).
     *
     * @return array<string, mixed>
     */
    private function cardCert(): array
    {
        $file = trim((string) ($this->app->config->get('monitoring.tls_cert_file') ?? ''));
        $warnDays = intdiv($this->app->config->int('notification.cert_expiry_warn'), 86400);
        if ($file === '') {
            return ['available' => false, 'reason' => 'not_configured', 'warn_days' => $warnDays];
        }
        $pem = is_file($file) && is_readable($file) ? @file_get_contents($file, false, null, 0, 1 << 20) : false;
        if ($pem === false) {
            return ['available' => false, 'reason' => 'unreadable', 'warn_days' => $warnDays];
        }
        $p = @openssl_x509_parse($pem);
        if (!is_array($p) || !isset($p['validTo_time_t'])) {
            return ['available' => false, 'reason' => 'invalid', 'warn_days' => $warnDays];
        }
        $notAfter = (int) $p['validTo_time_t'];
        $days = (int) floor(($notAfter - $this->app->clock->now()->getTimestamp()) / 86400);
        return ['not_after' => gmdate('Y-m-d\TH:i:s\Z', $notAfter), 'days_left' => $days, 'warn_days' => $warnDays,
            'state' => $days < 0 ? 'expired' : ($days <= $warnDays ? 'expiring' : 'ok')];
    }

    /** @return array<string, mixed> 앱 정보(설치 경로·계정 이름 없음) */
    private function cardApp(): array
    {
        $db = $this->app->db();
        return ['version' => App::VERSION, 'schema' => (string) ($db->value('SELECT MAX(version) FROM schema_migrations') ?? ''),
            'php' => PHP_VERSION, 'sqlite' => (string) $db->value('SELECT sqlite_version()'), 'os' => PHP_OS_FAMILY,
            // P30B 관리자 이메일 상태(정보 한 줄 — 판정·경고에는 넣지 않음): off(꺼짐) | setup(켜졌지만 설정 필요) | on
            'alert_mail' => $this->alertMailState(),
            // P31 감사 로그 DB 밖 기록 대조: ok | mismatch(→ 오류 audit_anchor_mismatch) | unknown(기록 없음 — 경고 아님)
            'audit_anchor' => $this->auditAnchorState()];
    }

    private function auditAnchorState(): string
    {
        try {
            return $this->app->auditAnchor()->check()['state'];
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function alertMailState(): string
    {
        try {
            return $this->app->alertMailer()->status()['state'];
        } catch (\Throwable) {
            return 'setup';
        }
    }

    // ---- 2. 판정(순수 함수) ----

    /**
     * @param array<string, array<string, mixed>> $cards
     * @return list<array{code:string, level:string, params:array<string, scalar|null>}>
     */
    public static function judge(array $cards): array
    {
        $alerts = [];
        $add = static function (string $code, string $level, array $params = []) use (&$alerts): void {
            $alerts[] = ['code' => $code, 'level' => $level, 'params' => $params];
        };
        $w = $cards['worker'] ?? [];
        if (($w['available'] ?? false) && !$w['alive']) {
            $add('worker_down', 'fail', ['state' => (string) $w['state'], 'seconds' => $w['seconds_since_beat']]);
        }
        $q = $cards['queue'] ?? [];
        if (($q['available'] ?? false) && ($w['available'] ?? false) && $w['alive'] && count($q['running']) < (int) $q['max_concurrent']
            && $q['startable_wait_seconds'] !== null && $q['startable_wait_seconds'] > (int) $q['stall_after_seconds']) {
            $add('queue_stalled', 'warn', ['minutes' => intdiv((int) $q['startable_wait_seconds'], 60), 'waiting' => (int) $q['waiting']]);
        }
        $d = $cards['disk'] ?? [];
        if (($d['available'] ?? false) && in_array($d['state'] ?? 'ok', ['warn', 'block'], true)) {
            $add($d['state'] === 'block' ? 'disk_block' : 'disk_warn', $d['state'] === 'block' ? 'fail' : 'warn', ['percent' => $d['used_percent']]);
        }
        $b = $cards['backup'] ?? [];
        if ($b['available'] ?? false) {
            if (($b['last_result'] ?? null) === 'failed') {
                $add('backup_failed', 'warn', ['code' => (string) $b['last_failure']['code'], 'at' => (string) $b['last_failure']['at']]);
            } elseif ($b['enabled'] && $b['stale']) {
                $add('backup_stale', 'warn', ['hours' => (int) $b['stale_hours'], 'at' => $b['last_success_at']]);
            }
        }
        $t = $cards['tools'] ?? [];
        if ($t['available'] ?? false) {
            $bad = array_map(static fn (array $i): string => (string) $i['tool'], array_values(array_filter($t['items'], static fn (array $i): bool => $i['healthy'] === false)));
            if ($bad !== []) {
                $add('tool_unhealthy', 'warn', ['tools' => implode(', ', $bad)]);
            }
        }
        $s = $cards['schedules'] ?? [];
        if (($s['available'] ?? false) && (int) $s['max_failures'] >= (int) $s['alert_after']) {
            $add('repo_fetch_failing', 'warn', ['count' => (int) $s['max_failures']]);
        }
        $c = $cards['cert'] ?? [];
        if (($c['available'] ?? false) && in_array($c['state'] ?? 'ok', ['expiring', 'expired'], true)) {
            $add($c['state'] === 'expired' ? 'cert_expired' : 'cert_expiring', $c['state'] === 'expired' ? 'fail' : 'warn', ['days' => (int) $c['days_left'], 'date' => substr((string) $c['not_after'], 0, 10)]);
        }
        // P31: 감사 로그가 DB 밖 기록(audit-anchor.jsonl)과 어긋남 = 변조 의심(오류). 기록이 없거나 읽을 수 없으면(unknown) 경고하지 않는다
        if (($cards['app']['audit_anchor'] ?? null) === 'mismatch') {
            $add('audit_anchor_mismatch', 'fail', []);
        }
        // 장애(fail)를 먼저 — 배너는 첫 경고를 보인다
        usort($alerts, static fn (array $a, array $b): int => ($a['level'] === 'fail' ? 0 : 1) <=> ($b['level'] === 'fail' ? 0 : 1));
        return $alerts;
    }

    /** @param list<array{level:string}> $alerts */
    public static function status(array $alerts): string
    {
        $levels = array_column($alerts, 'level');
        return in_array('fail', $levels, true) ? 'fail' : ($levels === [] ? 'ok' : 'warn');
    }

    // ---- 3. 평가·캐시·상태 변화 ----

    /** @return array{checked_at:string, status:string, alerts:list<array<string, mixed>>, cards:array<string, mixed>} 캐시(monitoring.cache 안이면 그대로) */
    public function snapshot(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }
        return $this->evaluate($fresh);
    }

    /** @return array{checked_at:string, status:string, alerts:list<array<string, mixed>>, cards:array<string, mixed>}|null */
    private function readCache(): ?array
    {
        $raw = @file_get_contents($this->dir() . '/health.json');
        $c = $raw === false ? null : json_decode($raw, true);
        if (!is_array($c) || !is_string($c['checked_at'] ?? null)) {
            return null;
        }
        $age = $this->app->clock->now()->getTimestamp() - (int) strtotime($c['checked_at']);
        return $age >= 0 && $age < $this->app->config->int('monitoring.cache') ? $c : null;
    }

    /** @return array{checked_at:string, status:string, alerts:list<array<string, mixed>>, cards:array<string, mixed>} */
    public function evaluate(bool $force = false): array
    {
        Fs::ensureDir($this->dir());
        $lock = FileLock::acquire($this->dir() . '/health.lock');
        try {
            $cached = $force ? null : $this->readCache();
            if ($cached !== null) {
                return $cached; // 잠금을 기다리는 동안 다른 요청이 새로 계산함
            }
            $cards = $this->cards();
            $alerts = self::judge($cards);
            $snap = ['checked_at' => $this->now(), 'status' => self::status($alerts), 'alerts' => $alerts, 'cards' => $cards];
            $this->transitions($alerts);
            Fs::atomicWrite($this->dir() . '/health.json', (string) json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $snap;
        } finally {
            $lock->release();
        }
    }

    /** @param list<array{code:string, level:string, params:array<string, mixed>}> $alerts */
    private function transitions(array $alerts): void
    {
        $file = $this->dir() . '/alert_state.json';
        $prev = json_decode((string) @file_get_contents($file), true);
        $prev = is_array($prev) ? $prev : [];
        $now = $this->now();
        $nowTs = $this->app->clock->now()->getTimestamp();
        $repeat = $this->app->config->int('monitoring.log_repeat');
        $log = $this->alertLog ?? $this->app->alertLogger();
        $next = [];
        foreach ($alerts as $a) {
            $p = $prev[$a['code']] ?? null;
            $entry = ['level' => $a['level'], 'since' => $p['since'] ?? $now, 'logged_at' => $p['logged_at'] ?? null];
            if ($p === null || $p['level'] !== $a['level']) {
                $log->warn($p === null ? '장애 경고 시작' : '장애 경고 수준 바뀜', ['code' => $a['code'], 'level' => $a['level'], 'params' => $a['params']]);
                $entry['logged_at'] = $now;
                if ($p === null) {
                    // 정상 → 경고: 관리자 장애 이벤트(P30B 이메일이 붙을 한 지점)
                    try {
                        $this->sink()->raised(['code' => $a['code'], 'level' => $a['level'], 'params' => $a['params'], 'at' => $now]);
                    } catch (\Throwable $e) {
                        $this->app->logger->warn('관리자 장애 이벤트 처리 실패', ['code' => $a['code'], 'error' => $e::class]);
                    }
                }
            } elseif ($p['logged_at'] === null || $nowTs - (int) strtotime((string) $p['logged_at']) >= $repeat) {
                $log->warn('장애 경고 계속', ['code' => $a['code'], 'level' => $a['level'], 'params' => $a['params'], 'since' => $entry['since']]);
                $entry['logged_at'] = $now;
            }
            $next[$a['code']] = $entry;
        }
        foreach ($prev as $code => $p) {
            if (!isset($next[$code]) && is_array($p)) {
                $log->info('장애 경고 해소', ['code' => (string) $code, 'since' => $p['since'] ?? null]);
            }
        }
        if ($next !== $prev) {
            Fs::atomicWrite($file, (string) json_encode((object) $next));
        }
    }

    private function sink(): AdminAlertSink
    {
        return $this->sink ??= new LogAlertSink($this->alertLog ?? $this->app->alertLogger());
    }

    // ---- /health ----

    /**
     * /health 응답 본문(고정 스키마, 식별 정보 없음 — 프로젝트·사용자 이름·경로·비밀 없음).
     *
     * @return array{status:string, time:string, checks:array<string, array<string, mixed>>}
     */
    public function healthBody(): array
    {
        $snap = $this->snapshot();
        $c = $snap['cards'];
        $codes = array_column($snap['alerts'], 'code');
        $dbOk = true;
        try {
            $this->app->db()->value('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        $status = $dbOk ? $snap['status'] : 'fail';
        $b = $c['backup'] ?? [];
        return [
            'status' => $status,
            'time' => $this->now(),
            'maintenance' => $this->app->config->bool('maintenance.enabled'), // P32B 업그레이드 유지보수 모드(상태 판정에는 넣지 않음)
            'checks' => [
                'worker' => ['alive' => (bool) ($c['worker']['alive'] ?? false), 'last_seen' => $c['worker']['beat_at'] ?? null],
                'db' => ['ok' => $dbOk],
                'disk' => ['used_percent' => $c['disk']['used_percent'] ?? null, 'state' => ($c['disk']['available'] ?? false) ? $c['disk']['state'] : 'unknown'],
                'backup' => ['last_success_at' => $b['last_success_at'] ?? null,
                    'state' => !($b['available'] ?? false) ? 'unknown' : (in_array('backup_failed', $codes, true) ? 'failed' : (in_array('backup_stale', $codes, true) ? 'stale' : 'ok'))],
                'queue' => ['waiting' => (int) ($c['queue']['waiting'] ?? 0), 'oldest_wait_seconds' => $c['queue']['oldest_wait_seconds'] ?? null,
                    'stalled' => in_array('queue_stalled', $codes, true)],
            ],
        ];
    }
}
