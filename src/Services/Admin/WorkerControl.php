<?php

declare(strict_types=1);

namespace PJGuardian\Services\Admin;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Queue\WorkerState;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\EnvironmentCheck;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Redact;
use PJGuardian\Support\SystemClock;

/**
 * 관리자 워커 화면(P20A, 명세 5.2, 14, 19.8). 웹은 워커 프로세스를 직접 다루지 않는다.
 *
 * - 상태: 생존 신호 파일(WorkerState)과 큐 표를 읽기만 한다(워커 잠금 파일은 건드리지 않는다: 웹이 잠깐이라도 잡으면 그 순간 뜨는 워커가
 *   단일 인스턴스 검사에 걸려 종료된다). 실행 중 = 신호가 worker.heartbeat_stale 안에 있고 상태가 stopped가 아님.
 * - 정지·재시작 요청: stop.request 파일 신호(WorkerState::requestStop)만 쓴다. 워커가 루프에서 확인해 진행 중인 작업을 끝낸 뒤 종료한다.
 *   재시작은 worker.supervised=true일 때만(서비스 관리자가 종료 코드 75를 보고 다시 띄운다). false면 409 restart_not_supervised.
 * - 시작: 설정 worker.start_command(관리자가 서버에서 적은 고정 명령 하나)를 **셸 없이 인자 배열로** 실행한다.
 *   UI·요청 값은 명령에 들어가지 않는다. 첫 단어는 절대 경로이거나 허용 목록(schtasks, systemctl, sudo)의 이름이고, 이름은 고정된
 *   시스템 폴더에서만 찾는다(PATH를 쓰지 않음). tools/worker.php를 웹이 직접 실행하는 것은 금지(명령이 PHP·worker.php를 가리키면 거부).
 *   제한 시간 worker.start_timeout. 이미 떠 있으면(신호 정상) 409, 시작 요청은 start.lock으로 직렬화하고 30초 안의 재요청은 막는다
 *   (그래도 겹치면 워커의 단일 인스턴스 잠금이 하나만 남긴다). 결과는 종료 코드와 출력 첫 줄(경로·비밀값 가림).
 */
final class WorkerControl
{
    public const START_ALLOWED_NAMES = ['schtasks', 'systemctl', 'sudo'];
    /** 같은 시작 요청을 다시 받지 않는 시간(초) */
    public const START_COOLDOWN = 30;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly WorkerState $state,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly Redact $redact,
        private readonly int $staleSeconds,
        private readonly int $maxConcurrent,
        private readonly bool $supervised,
        private readonly string $startCommand,
        private readonly int $startTimeout,
        /** @var (\Closure(): bool)|null 시험용: proc_open 사용 가능 여부 */
        private readonly ?\Closure $procOpenAvailable = null,
    ) {
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** @return array<string, mixed> 상태(읽기 전용) */
    public function status(): array
    {
        $hb = $this->state->read();
        $since = $this->state->secondsSinceBeat();
        $state = match (true) {
            $hb === null => 'stopped',
            ($hb['state'] ?? null) === 'stopped' => 'stopped',
            $since === null || $since > $this->staleSeconds => 'unresponsive',
            ($hb['state'] ?? null) === 'stopping' => 'stopping',
            default => 'running',
        };
        $running = $this->db->all(
            "SELECT a.id, a.project_id, p.name AS project_name, a.tool, a.started_at, a.requested_by FROM analyses a JOIN projects p ON p.id = a.project_id"
            . " WHERE a.status = 'running' ORDER BY a.started_at, a.id",
        );
        $now = $this->clock->now()->getTimestamp();
        foreach ($running as &$r) {
            $t = is_string($r['started_at']) ? strtotime($r['started_at']) : false;
            $r = ['id' => (int) $r['id'], 'project_id' => (int) $r['project_id'], 'project_name' => (string) $r['project_name'], 'tool' => (string) $r['tool'],
                'started_at' => $r['started_at'], 'elapsed_seconds' => $t === false ? null : max(0, $now - $t)];
        }
        unset($r);
        $queued = (int) $this->db->value("SELECT COUNT(*) FROM analyses WHERE status = 'queued'");
        $uploadsPending = (int) $this->db->value("SELECT COUNT(*) FROM uploads WHERE status IN ('assemble_pending', 'extracting')");
        $req = $this->state->stopRequest();
        $alive = in_array($state, ['running', 'stopping'], true);
        $start = $this->startInfo($alive);
        return [
            'state' => $state,
            'pid' => isset($hb['pid']) ? (int) $hb['pid'] : null,
            'host' => $hb['host'] ?? null,
            'version' => $hb['version'] ?? null,
            'started_at' => $hb['started_at'] ?? null,
            'beat_at' => $hb['beat_at'] ?? null,
            'seconds_since_beat' => $since,
            'stale_after' => $this->staleSeconds,
            'children' => ['analyses' => count((array) ($hb['analyses'] ?? [])), 'uploads' => count((array) ($hb['uploads'] ?? [])), 'admin_tasks' => count((array) ($hb['admin_tasks'] ?? []))],
            'running_analyses' => $running,
            'queued_analyses' => $queued,
            'pending_uploads' => $uploadsPending,
            'max_concurrent' => $this->maxConcurrent,
            'last_recovery' => is_array($hb['last_recovery'] ?? null) ? $hb['last_recovery'] : null,
            'node' => is_array($hb['node'] ?? null) ? $hb['node'] : null,
            'stop_request' => $req,
            'last_exit' => $hb['exit'] ?? null,
            'supervised' => $this->supervised,
            'start' => $start,
            // 대기 중인 분석·업로드가 있는데 워커가 없다 → 화면이 눈에 띄게 경고
            'warning_no_worker' => !$alive && ($queued > 0 || $uploadsPending > 0),
        ];
    }

    /** @return array{configured:bool, available:bool, reason:?string, last:?array<string, mixed>} */
    private function startInfo(bool $alive): array
    {
        $configured = trim($this->startCommand) !== '';
        $proc = $this->procOpenAvailable !== null ? ($this->procOpenAvailable)() : EnvironmentCheck::procOpenAvailable();
        $raw = @file_get_contents($this->state->startRecordFile());
        $last = $raw === false ? null : json_decode($raw, true);
        $reason = match (true) {
            !$configured => 'not_configured',
            !$proc => 'proc_open_disabled',
            $alive => 'already_running',
            $this->recentlyStarted(is_array($last) ? $last : null) => 'recently_requested',
            default => null,
        };
        return ['configured' => $configured, 'available' => $reason === null, 'reason' => $reason, 'last' => is_array($last) ? $last : null];
    }

    private function alive(): bool
    {
        return in_array($this->status()['state'], ['running', 'stopping'], true);
    }

    /** @return array<string, mixed> */
    public function requestStop(Actor $actor, string $kind): array
    {
        if ($kind === 'restart' && !$this->supervised) {
            throw new ApiException(409, 'restart_not_supervised');
        }
        $st = $this->status();
        if (!in_array($st['state'], ['running', 'stopping', 'unresponsive'], true)) {
            throw new ApiException(409, 'worker_not_running');
        }
        $this->state->requestStop($kind, $actor->userId);
        $this->audit->record($actor, $kind === 'restart' ? 'worker.restart_request' : 'worker.stop_request', 'worker', null,
            ['running_analyses' => count($st['running_analyses']), 'supervised' => $this->supervised]);
        $this->logger->info($kind === 'restart' ? '워커 재시작 요청(관리자 화면)' : '워커 정지 요청(관리자 화면)', ['by' => $actor->userId]);
        return $this->status();
    }

    /**
     * 시작 명령 문자열을 인자 배열로 나눈다(셸 문법 없음: 따옴표 묶기만). 허용하지 않는 명령이면 null.
     *
     * @return list<string>|null
     */
    public static function parseCommand(string $command): ?array
    {
        $command = trim($command);
        if ($command === '' || preg_match('/[\x00-\x1F\x7F]/', $command) === 1) {
            return null;
        }
        $args = [];
        $cur = '';
        $quote = null;
        $has = false;
        $len = strlen($command);
        for ($i = 0; $i < $len; $i++) {
            $c = $command[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                } else {
                    $cur .= $c;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $has = true;
            } elseif ($c === ' ' || $c === "\t") {
                if ($has || $cur !== '') {
                    $args[] = $cur;
                }
                $cur = '';
                $has = false;
            } else {
                $cur .= $c;
            }
        }
        if ($quote !== null) {
            return null; // 닫히지 않은 따옴표
        }
        if ($has || $cur !== '') {
            $args[] = $cur;
        }
        return $args === [] ? null : $args;
    }

    /**
     * 첫 단어를 실행 파일 경로로 바꾼다. 절대 경로(있는 파일)이거나, 허용 목록 이름을 고정 시스템 폴더에서 찾은 것만.
     * PHP 실행 파일이나 worker.php를 직접 가리키면 거부(웹이 워커를 직접 띄우지 않는다).
     */
    public static function resolveExecutable(string $first): ?string
    {
        $isAbs = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $first) === 1;
        if ($isAbs) {
            $real = realpath($first);
            $path = $real !== false && is_file($real) ? $real : null;
        } else {
            $name = strtolower($first);
            if (str_ends_with($name, '.exe')) {
                $name = substr($name, 0, -4);
            }
            if (!in_array($name, self::START_ALLOWED_NAMES, true)) {
                return null;
            }
            $path = null;
            if (PHP_OS_FAMILY === 'Windows') {
                $sys = rtrim((string) (getenv('SystemRoot') ?: 'C:\\Windows'), '\\/');
                $cand = $sys . '\\System32\\' . $name . '.exe';
                $path = is_file($cand) ? $cand : null;
            } else {
                foreach (['/usr/bin/', '/bin/', '/usr/sbin/', '/sbin/'] as $d) {
                    if (is_file($d . $name)) {
                        $path = $d . $name;
                        break;
                    }
                }
            }
        }
        if ($path === null) {
            return null;
        }
        $base = strtolower(basename(str_replace('\\', '/', $path)));
        if (str_starts_with($base, 'php') || $base === 'worker.php') {
            return null;
        }
        return $path;
    }

    /**
     * 시작 버튼. 사전 등록 작업을 시작하는 고정 명령을 실행한다.
     *
     * @return array<string, mixed> {ok, exit_code, reason, output(첫 줄, 가림), requested_at}
     */
    public function start(Actor $actor): array
    {
        $configured = trim($this->startCommand) !== '';
        if (!$configured) {
            throw new ApiException(409, 'start_not_configured');
        }
        $proc = $this->procOpenAvailable !== null ? ($this->procOpenAvailable)() : EnvironmentCheck::procOpenAvailable();
        if (!$proc) {
            throw new ApiException(409, 'start_proc_open_disabled');
        }
        $args = self::parseCommand($this->startCommand);
        $exe = $args === null ? null : self::resolveExecutable($args[0]);
        if ($args === null || $exe === null) {
            $this->audit->record($actor, 'worker.start_request', 'worker', null, ['ok' => false, 'reason' => 'invalid_command']);
            throw new ApiException(409, 'start_command_invalid');
        }
        Fs::ensureDir($this->state->dir());
        $lock = FileLock::tryAcquire($this->state->startLockFile());
        if ($lock === null) {
            throw new ApiException(409, 'start_in_progress');
        }
        try {
            if ($this->alive()) {
                throw new ApiException(409, 'worker_already_running');
            }
            $raw = @file_get_contents($this->state->startRecordFile());
            $last = $raw === false ? null : json_decode($raw, true);
            if ($this->recentlyStarted(is_array($last) ? $last : null)) {
                throw new ApiException(409, 'start_in_progress');
            }
            $args[0] = $exe;
            $r = (new ProcessRunner([], [$exe]))->run($args, new ProcessOptions(
                cwd: $this->state->dir(), timeoutSeconds: (float) $this->startTimeout, outputLimit: 64 * 1024, outputDir: $this->state->dir(),
            ));
            $out = trim($r->stdout(4096));
            $err = trim($r->stderr(4096));
            $first = static fn (string $s): string => (string) (preg_split('/\r?\n/', $s)[0] ?? '');
            $line = mb_substr($this->redact->text($first($out !== '' ? $out : $err)), 0, 300);
            $r->cleanup();
            $ok = $r->reason === ProcessResult::NORMAL && $r->exitCode === 0;
            $result = [
                'ok' => $ok, 'exit_code' => $r->exitCode, 'reason' => $r->reason === ProcessResult::NORMAL ? null : $r->reason,
                'output' => self::sanitizeOutput($line), 'requested_at' => $this->now(), 'by' => $actor->userId,
            ];
            Fs::atomicWrite($this->state->startRecordFile(), (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            $lock->release();
        }
        // 감사: 성공·실패와 종료 코드만(명령 문자열·경로를 남기지 않는다)
        $this->audit->record($actor, 'worker.start_request', 'worker', null, ['ok' => $ok, 'exit_code' => $r->exitCode, 'reason' => $result['reason']]);
        $this->logger->info('워커 시작 명령 실행(관리자 화면)', ['ok' => $ok, 'exit_code' => $r->exitCode, 'reason' => $result['reason']]);
        return $result;
    }

    /** @param array<string, mixed>|null $last 마지막 시작 기록: 성공한 시작 뒤 START_COOLDOWN초 안이면 다시 시작하지 않는다(워커가 뜨는 중) */
    private function recentlyStarted(?array $last): bool
    {
        if ($last === null || ($last['ok'] ?? false) !== true || !is_string($last['requested_at'] ?? null)) {
            return false;
        }
        $t = strtotime($last['requested_at']);
        return $t !== false && $this->clock->now()->getTimestamp() - $t < self::START_COOLDOWN;
    }

    /** 출력 첫 줄에서 제어 문자를 지운다(이미 Redact를 거친 값) */
    private static function sanitizeOutput(string $s): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s);
    }
}
