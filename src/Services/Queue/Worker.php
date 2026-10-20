<?php

declare(strict_types=1);

namespace PJGuardian\Services\Queue;

use PJGuardian\App;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\RunningProcess;
use PJGuardian\Support\CleanupQueue;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\Trash;

/**
 * CLI 워커(tools/worker.php, 명세 5.2, 5.4, 19.8, 19.10). 부모는 **감독만** 한다: 무거운 일은 모두 자식 프로세스에서.
 *
 * 루프(약 0.1초 간격)
 * 1. 자식 감독: 분석 자식(tools/run_analysis.php <id>)과 업로드 자식(tools/run_upload.php assemble|extract <id>)을 poll한다.
 *    - 분석: 타임아웃(analysis.timeout) → 트리 종료, failed(timeout). 취소(cancel_requested, worker.cancel_check_interval마다 확인)
 *      → 트리 종료, cancelled. 자식이 결과를 남기지 않고 끝남 → failed(analysis_crashed).
 *      끝나면 작업 디렉토리 삭제, 성공이면 업로드 폴더(zip)도 즉시 삭제. 지우지 못하면 work/cleanup_pending.jsonl.
 *    - 업로드: 상한(worker.extract_timeout) 초과·비정상 종료 → 업로드 failed(assemble_failed / extract_timeout / extract_crashed).
 * 2. 선점(worker.poll_interval마다, 정지 요청이 없을 때): 분석은 AnalysisQueue::claimNext(전체 analysis.max_concurrent,
 *    프로젝트당 1개, 건너뛰기), 업로드 후처리는 별도 한도(worker.upload_concurrency)로 조립 대기 → 해제 대기 순.
 * 3. 생존 신호(worker.heartbeat_interval마다, WorkerState::beat).
 * 4. 관리 작업(P20A, admin_tasks): 관리자 화면이 넣은 도구 재검증·정리 실행·디스크 사용량 계산을 한 번에 하나씩
 *    자식(tools/run_admin_task.php <id>)으로 실행한다. 분석 슬롯과 따로 세고, 상한은 worker.admin_task_timeout.
 * 5. 주기 분석 스케줄러(P26, scheduler.tick_seconds마다, 정지 요청이 없을 때): ScheduleTicker::tick이 도래한 프로젝트의 분석을 큐에 넣는다
 *    (짧은 DB 작업만 — 가져오기·분석은 위 선점으로 자식이 한다). 결과는 생존 신호의 scheduler(마지막 틱 시각·건수)에 남긴다.
 * 6. 장애 판정(P30, monitoring.cache마다): SystemHealth::snapshot — 화면 방문이 없어도 경고 시작·계속·해소를 경고 로그에 남기고,
 *    정상 → 경고로 바뀔 때 관리자 장애 이벤트(AdminAlertSink, P30B 이메일 연결 지점)를 부른다. 오류는 그 회차만 건너뛴다.
 *
 * 기동: 단일 인스턴스 잠금(없으면 종료 코드 3) → 남은 작업 복구(WorkerRecovery::recoverAll, running → failed worker_crashed).
 * 정지: SIGTERM·SIGINT(Linux, pcntl), Ctrl+C·Ctrl+Break(Windows 콘솔), stop.request 파일(tools/worker.php --stop, 관리자 화면 P20A)
 *   → 새 선점을 멈추고 진행 중인 자식이 끝나기를 기다린다. 워커 시작 시각보다 오래된 stop.request는 무시하고 지운다.
 *   재시작 요청(kind=restart)이면 종료 코드 75(RESTART_EXIT_CODE)로 끝나 서비스 관리자가 다시 띄운다(worker.supervised). --force-stop이거나 정지 신호를 두 번 받으면 자식을 종료하고 분석은 failed(worker_stopped),
 *   업로드는 되돌린다.
 */
final class Worker
{
    private const TICK_MICROS = 100_000;
    /** 재시작 요청으로 끝날 때의 종료 코드(EX_TEMPFAIL). systemd Restart=on-failure·NSSM 기본 동작이 다시 띄운다 */
    public const RESTART_EXIT_CODE = 75;
    private const CHILD_OUTPUT_LIMIT = 16 * 1024 * 1024;

    /** @var array<int, array{proc: RunningProcess, row: array<string, mixed>}> */
    private array $analyses = [];
    /** @var array<string, array{proc: RunningProcess, stage: string}> */
    private array $uploads = [];
    private bool $stopping = false;
    private bool $forceNow = false;
    private string $startedAt = '';
    /** @var list<string> 자식 PHP 실행 인자(-c, -d memory_limit, 빠진 확장) */
    private array $phpArgs = [];
    /** @var array<string, string> */
    private array $childEnv = [];
    private int $processed = 0;
    private ?RunningProcess $trashChild = null;
    /** @var array{proc: RunningProcess, task: array<string, mixed>}|null 관리 작업 자식(P20A, 한 번에 하나) */
    private ?array $adminChild = null;
    private string $stopKind = 'stop';
    /** @var array<string, mixed> 생존 신호에 함께 쓰는 관리자 화면용 정보(P20A) */
    private array $extra = [];
    /** Node "상태 갱신"(P21C) 때 다시 읽을 설정 파일(tools/worker.php가 정함). null이면 시작 때 읽은 설정 그대로 */
    private ?string $reloadIni = null;
    /** 규칙 데이터 적재 요청(P22)을 마지막으로 넣은 시각(microtime). 시작 때와 rules.load_interval마다 */
    private float $rulesRequestedAt = -INF;
    /** 알림 보관 정리(P24) 마지막 실행 시각(microtime). 시작 때 한 번, 그 뒤 NOTIFICATION_PRUNE_SECONDS마다 */
    private float $notificationsPrunedAt = -INF;
    private const NOTIFICATION_PRUNE_SECONDS = 3600;
    private const NOTIFICATION_PRUNE_BATCH = 1000;
    /** 장애 판정(P30) 마지막 시각(microtime) */
    private float $healthAt = -INF;
    private float $anchorAt = -INF;
    /** 시작할 때 체크포인트를 하므로 그 시각부터 셈 */
    private float $idleCheckpointAt = 0.0;
    /** 주기 분석 스케줄러(P26) 마지막 틱 시각(microtime) */
    private float $scheduleTickedAt = -INF;
    /** @var array{0: int, 1: int}|null 화면 설정 파일(settings.local.ini, P27A)의 [수정 시각, 크기] — 바뀌면 다시 읽는다 */
    private ?array $settingsStamp = null;
    /** @var array<string, string> restart=worker 키의 시작 때 값(표시 형태) — 다시 읽은 뒤 달라졌으면 "재시작 필요" */
    private array $startSettings = [];
    /** @var array{proc: RunningProcess, job: array<string, mixed>}|null 백업·리허설 자식(P27B, 한 번에 하나) */
    private ?array $backupChild = null;
    /** @var array{proc: RunningProcess, request: array<string, mixed>}|null 복원 자식(P27B) */
    private ?array $restoreChild = null;
    /** 복원 요청을 보고 진행 중인 작업을 기다리기 시작한 시각(microtime) */
    private ?float $drainStartedAt = null;
    private float $backupCheckedAt = -INF;
    private float $rehearsalCheckedAt = -INF;
    private const BACKUP_CHECK_SECONDS = 30;
    private const REHEARSAL_CHECK_SECONDS = 3600;
    /** 복원 자식의 시간 상한(초) — 큰 스냅샷 복사·마이그레이션 포함 [가정] 4시간 */
    private const RESTORE_TIMEOUT = 4 * 3600;
    /** 관리자 화면 "상태 갱신"의 node --version 시간 제한(초). 화면은 10초를 기다린다 */
    private const NODE_REFRESH_TIMEOUT = 5;

    /** @param (\Closure(string): void)|null $out 콘솔 출력 */
    public function __construct(
        private readonly App $app,
        private readonly ?string $configPath = null,
        private readonly bool $once = false,
        private readonly bool $forceStop = false,
        private readonly ?float $maxSeconds = null,
        private readonly ?\Closure $out = null,
    ) {
    }

    /** Node "상태 갱신" 때 다시 읽을 설정 파일(P21C) */
    public function reloadConfigFrom(?string $iniPath): void
    {
        $this->reloadIni = $iniPath;
    }

    /**
     * Node.js 확인 → 생존 신호의 node. 워커의 실행 환경(계정·PATH)에서 확인한다(웹은 node를 실행하지 않는다, P20A).
     * 경로는 남기지 않고 출처(설정/PATH)만. $refresh면 설정 파일을 다시 읽어 tools.node_path 변경도 반영한다(P21C).
     */
    private function checkNode(bool $refresh): array
    {
        $cfg = $this->app->config;
        $configError = false;
        if ($refresh && $this->reloadIni !== null) {
            try {
                $cfg = \PJGuardian\Support\Config::load($this->reloadIni, dirname($this->reloadIni) . '/secrets.ini', getenv());
            } catch (\Throwable) {
                $configError = true; // 설정 파일이 지금 틀렸으면 시작 때 설정으로 확인하고 그 사실을 알린다
            }
        }
        $path = $cfg->get('tools.node_path');
        $runtime = $refresh
            ? new \PJGuardian\Services\Tools\NodeRuntime(is_string($path) ? $path : null, (string) $cfg->get('tools.node_min_version'), self::NODE_REFRESH_TIMEOUT)
            : $this->app->nodeRuntime();
        $node = $runtime->check();
        return ['ok' => $node['ok'], 'version' => $node['version'], 'reason' => $node['reason'], 'min' => $runtime->minVersion(),
            'source' => is_string($path) ? 'config' : 'path', 'checked_at' => SystemClock::iso($this->app->clock->now())]
            + ($configError ? ['config_error' => true] : []);
    }

    /**
     * 화면 설정 다시 읽기(P27A): settings.local.ini의 [수정 시각, 크기, 내용 해시(P32)]가 바뀌면 설정을 다시 읽어 App에 넣는다(App::replaceConfig).
     * 워커가 쓸 때마다 $this->app->config에서 읽는 값(restart=none)은 다음 작업부터 새 값이다. 시작 때만 읽는 값(restart=worker)은
     * 생존 신호의 config.pending_restart에 올려 화면이 "워커를 다시 시작해야 반영"을 보여 준다. 설정이 틀리면 경고만 남기고 이전 값 유지.
     *
     * @return bool 생존 신호를 곧바로 다시 써야 하는지(처음·변경)
     */
    private function reloadSettings(): bool
    {
        $path = $this->app->config->localPath();
        if ($path === null) {
            return false;
        }
        clearstatcache(true, $path);
        // P32: 수정 시각(초)·크기만 보면 같은 초에 같은 길이로 다시 저장한 값(예: 79 → 55)을 놓친다 — 내용 해시도 본다(작은 파일)
        $stamp = is_file($path) ? [(int) @filemtime($path), (int) @filesize($path), (string) @hash_file('sha256', $path)] : [0, 0, ''];
        if ($this->settingsStamp === null) {
            $this->settingsStamp = $stamp;
            foreach ($this->app->config->registry()->all() as $name => $item) {
                if ($item['restart'] === 'worker') {
                    $this->startSettings[$name] = \PJGuardian\Support\Config::display($this->app->config->get($name), $item['spec']);
                }
            }
            $this->extra['config'] = ['version' => $this->app->config->localVersion(), 'loaded_at' => SystemClock::iso($this->app->clock->now()), 'pending_restart' => []];
            return true;
        }
        if ($stamp === $this->settingsStamp) {
            return false;
        }
        $this->settingsStamp = $stamp;
        $old = $this->app->config;
        try {
            $new = $old->reload();
        } catch (\Throwable $e) {
            $this->app->logger->warn('화면 설정 다시 읽기 실패(이전 값 유지)', ['error' => $e->getMessage()]);
            return false;
        }
        $changed = [];
        $pending = [];
        foreach ($new->registry()->all() as $name => $item) {
            $now = \PJGuardian\Support\Config::display($new->get($name), $item['spec']);
            if ($now !== \PJGuardian\Support\Config::display($old->get($name), $item['spec'])) {
                $changed[] = $name;
            }
            if ($item['restart'] === 'worker' && isset($this->startSettings[$name]) && $now !== $this->startSettings[$name]) {
                $pending[] = $name;
            }
        }
        $this->app->replaceConfig($new);
        $this->extra['config'] = ['version' => $new->localVersion(), 'loaded_at' => SystemClock::iso($this->app->clock->now()), 'pending_restart' => $pending];
        $this->app->logger->info('화면 설정 다시 읽음', ['changed' => $changed, 'pending_restart' => $pending, 'warnings' => count($new->warnings())]);
        $this->say('화면 설정 다시 읽음' . ($changed === [] ? '' : ': ' . implode(', ', $changed)));
        return true;
    }

    /**
     * 규칙 데이터 적재 요청(P22):워커 시작 때와 rules.load_interval마다 관리 작업 rules_load를 넣는다(대기 중이면 합쳐짐).
     * 실제 적재는 자식 프로세스(run_admin_task)가 하고, 활성 버전·설치 버전·매핑이 그대로면 아무것도 바꾸지 않는다(내용 해시).
     * bin/에 새 버전을 넣거나 고정·차단을 바꾸면(관리자 화면은 즉시 요청) 늦어도 이 주기 안에 반영된다. 분석을 막지 않는다.
     */
    private function requestRulesLoad(float $now): void
    {
        $cfg = $this->app->config;
        if ($this->stopping || !$cfg->bool('rules.auto_load') || $now - $this->rulesRequestedAt < (float) $cfg->int('rules.load_interval')) {
            return;
        }
        $this->rulesRequestedAt = $now;
        try {
            $this->app->adminTasks()->request('rules_load', '', null);
        } catch (\Throwable $e) {
            $this->app->logger->warn('규칙 데이터 적재 요청 실패(분석에는 영향 없음)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 알림 보관 정리(P24): 워커 시작 때와 1시간마다. 읽은 알림·오래된 안 읽은 알림·사용자당 상한 초과분을 단계마다 최대 1,000건 지운다
     * (남으면 다음 주기에 이어서. 많이 남았으면 1분 뒤 다시). 분석을 막지 않고, 실패는 경고 로그만. 감사 로그 없음(대량·자동).
     */
    private function pruneNotifications(float $now): void
    {
        if ($this->stopping || $now - $this->notificationsPrunedAt < self::NOTIFICATION_PRUNE_SECONDS) {
            return;
        }
        $this->notificationsPrunedAt = $now;
        try {
            $t0 = hrtime(true);
            $r = $this->app->notifications()->prune(null, self::NOTIFICATION_PRUNE_BATCH);
            if ($r['more']) {
                $this->notificationsPrunedAt = $now - self::NOTIFICATION_PRUNE_SECONDS + 60;
            }
            if ($r['read'] + $r['unread'] + $r['over'] > 0) {
                $this->app->logger->info('알림 보관 정리', $r + ['ms' => (int) ((hrtime(true) - $t0) / 1e6)]);
            }
        } catch (\Throwable $e) {
            $this->app->logger->warn('알림 보관 정리 실패(분석에는 영향 없음)', ['error' => $e->getMessage()]);
        }
    }

    /** P31 B-2: 한가할 때도 database.idle_checkpoint_interval마다 PASSIVE 체크포인트(웹 연결은 자동 체크포인트를 끄므로 웹 쓰기의 WAL도 워커가 옮김) */
    private function idleCheckpoint(float $now): void
    {
        if ($now - $this->idleCheckpointAt < (float) $this->app->config->int('database.idle_checkpoint_interval')) {
            return;
        }
        $this->idleCheckpointAt = $now;
        $this->checkpoint($this->analyses === [] && $this->uploads === [] && $this->adminChild === null);
    }

    /** P31: log.audit_anchor_interval마다 감사 로그 최신 해시를 DB 밖(audit-anchor.jsonl)에 남긴다. 실패는 경고 로그만(워커는 계속) */
    private function anchorAudit(float $now): void
    {
        if ($now - $this->anchorAt < (float) $this->app->config->int('log.audit_anchor_interval')) {
            return;
        }
        $this->anchorAt = $now;
        try {
            if (!$this->app->auditAnchor()->record()) {
                $this->app->logger->warn('감사 로그 외부 기록을 쓰지 못함(이번 회차 건너뜀)');
            }
        } catch (\Throwable $e) {
            $this->app->logger->warn('감사 로그 외부 기록 실패', ['error' => $e::class]);
        }
    }

    /** 장애 판정(P30): monitoring.cache마다 SystemHealth 캐시를 새로 판정(상태 변화 기록·장애 이벤트). P30B: 그 판정으로 관리자 이메일 발송 규칙 */
    private function evaluateHealth(float $now): void
    {
        if ($now - $this->healthAt < (float) $this->app->config->int('monitoring.cache')) {
            return;
        }
        $this->healthAt = $now;
        try {
            $snap = $this->app->systemHealth()->snapshot();
        } catch (\Throwable $e) {
            $this->app->logger->warn('장애 판정 실패(이번 회차 건너뜀)', ['error' => $e::class]);
            return;
        }
        // P30B 관리자 장애 이메일: 같은 판정으로 발송 규칙만 적용(꺼짐·불완전이면 아무것도 안 함, 짧은 시간 상한 — 실패해도 워커는 계속)
        try {
            $this->app->alertMailer()->tick($snap);
        } catch (\Throwable $e) {
            $this->app->logger->warn('관리자 이메일 처리 실패(이번 회차 건너뜀)', ['error' => $e::class]);
        }
    }

    /**
     * 주기 분석 스케줄러(P26): scheduler.tick_seconds마다 도래한 일정을 큐에 넣는다. 오류는 그 틱만 건너뛰고 경고 로그(워커는 계속).
     * 019 전 DB(표 없음)면 조용히 건너뛴다. 정지 중에는 새로 넣지 않는다. 등록했으면 true(바로 선점하게)
     */
    private function tickSchedules(float $now): bool
    {
        if ($this->stopping || $now - $this->scheduleTickedAt < (float) $this->app->config->int('scheduler.tick_seconds')) {
            return false;
        }
        $this->scheduleTickedAt = $now;
        $t0 = hrtime(true);
        try {
            $r = $this->app->scheduleTicker()->tick();
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException && str_contains($e->getMessage(), 'no such table: project_schedules')) {
                return false;
            }
            $r = ['due' => 0, 'queued' => 0, 'skipped' => 0, 'raced' => 0, 'error' => 'tick_failed'];
            $this->app->logger->warn('스케줄러 틱 실패(다음 틱에 다시, 분석에는 영향 없음)', ['error' => $e->getMessage()]);
        }
        $this->extra['scheduler'] = ['last_tick_at' => SystemClock::iso($this->app->clock->now()), 'ms' => (int) ((hrtime(true) - $t0) / 1e6)] + $r;
        if ($r['queued'] > 0) {
            $this->say(sprintf('주기 분석 %d개 등록(도래 %d, 건너뜀 %d)', $r['queued'], $r['due'], $r['skipped']));
        }
        return $r['queued'] > 0;
    }

    /** Node "상태 갱신" 요청이 있으면 다시 확인하고 요청을 지운다(P21C). 처리했으면 true */
    private function handleNodeCheck(WorkerState $state): bool
    {
        if (!is_file($state->nodeCheckFile())) {
            return false;
        }
        $req = $state->nodeCheckRequest();
        $this->extra['node'] = $this->checkNode(true) + ['request_at' => $req['requested_at'] ?? null];
        $state->clearNodeCheck();
        $this->app->logger->info('Node 상태 갱신', array_diff_key($this->extra['node'], ['source' => 1]) + ['by' => $req['by'] ?? null]);
        return true;
    }

    private function say(string $line): void
    {
        if ($this->out !== null) {
            ($this->out)('[' . gmdate('H:i:s') . 'Z] ' . $line);
        }
    }

    /** @return int 종료 코드: 0 정상, 3 이미 실행 중, 75 재시작 요청으로 종료 */
    public function run(): int
    {
        $state = $this->app->workerState();
        $lock = $state->tryLock();
        if ($lock === null) {
            $this->say('다른 워커가 이미 실행 중입니다(단일 인스턴스). 종료합니다.');
            return 3;
        }
        try {
            $state->clearStop();
            $this->startedAt = SystemClock::iso($this->app->clock->now());
            // P27B: 강제 종료된 복원(상태 running, 프로세스 없음)을 먼저 되돌린다 — DB를 열기 전에. 오래된 유지보수 플래그는 경고
            try {
                if ($this->app->restoreService()->recoverInterrupted()) {
                    $this->say('중단된 복원을 정리했습니다(restore_state.json)');
                }
                $stale = $this->app->maintenance()->minutes();
                if ($stale !== null && $stale >= $this->app->config->int('backup.maintenance_stale_minutes') && !is_file($this->app->backupPaths()->restoreRequest())) {
                    $this->app->logger->warn('오래된 유지보수 플래그(복원 프로세스가 비정상 종료한 흔적) — php tools/restore.php --leave-maintenance', ['minutes' => $stale]);
                    $this->say("경고: 유지보수 플래그가 {$stale}분째 켜져 있습니다(php tools/restore.php --leave-maintenance)");
                }
            } catch (\Throwable $e) {
                $this->app->logger->warn('복원 상태 점검 실패', ['error' => $e->getMessage()]);
            }
            $rec = $this->app->workerRecovery()->recoverAll();
            $rec['admin_tasks'] = $this->app->adminTasks()->recoverRunning();
            $this->extra['last_recovery'] = ['at' => $this->startedAt] + $rec;
            if ($rec['analyses'] + $rec['uploads'] + $rec['killed'] > 0) {
                $this->say(sprintf('복구: 실행 중으로 남은 분석 %d개 → 실패(worker_crashed), 업로드 후처리 %d개 되돌림, 남은 자식 프로세스 %d개 종료',
                    $rec['analyses'], $rec['uploads'], $rec['killed']));
            }
            // P20S: 시작할 때 한 번 체크포인트(지난 워커가 비정상 종료해 WAL이 남아 있어도 계속 커지지 않게)
            $this->checkpoint(true);
            $this->idleCheckpointAt = microtime(true);
            // 첫 작업을 잡기 전에 생존 신호를 남긴다(잡자마자 죽어도 정리 작업이 "신호 파일 없음"(24시간 안전망)으로 오판하지 않게)
            $state->beat((int) getmypid(), $this->startedAt, 'running', [], [], App::VERSION, $this->extra);
            $this->prepareChildCommand();
            // ESLint용 Node 런타임 검사(명세 19.10: 기동 시 최소 버전). 문제가 있어도 ruff 분석은 계속한다
            // 관리자 화면의 Node 상태(웹은 node를 실행하지 않는다, P20A). 이후 "상태 갱신"(P21C) 요청이 오면 다시 확인한다
            $this->extra['node'] = $node = $this->checkNode(false);
            $state->clearNodeCheck(); // 이 워커가 뜨기 전의 요청은 지금 확인으로 처리됨
            if (!$node['ok']) {
                $this->app->logger->warn('Node 런타임 문제(ESLint 분석은 tool_unavailable로 실패)', $node + ['min' => $this->app->nodeRuntime()->minVersion()]);
                $this->say('경고: Node 런타임 문제(' . $node['reason'] . ') — ESLint 분석은 실패합니다');
            }
            $this->installSignalHandlers();
            $this->app->logger->info('워커 시작', ['pid' => getmypid(), 'recovered' => $rec, 'once' => $this->once]);
            $this->say(sprintf('워커 시작 pid=%d (분석 동시 %d개, 업로드 후처리 동시 %d개)', getmypid(),
                $this->app->config->int('analysis.max_concurrent'), $this->app->config->int('worker.upload_concurrency')));
            $this->loop($state);
            $restart = $this->stopKind === 'restart';
            // 처리한 정지 요청은 지운다(재시작된 워커가 같은 신호로 바로 멈추지 않게. 시작 때도 지운다)
            $state->clearStop();
            $state->beat((int) getmypid(), $this->startedAt, 'stopped', [], [], App::VERSION, $this->extra + ['exit' => $restart ? 'restart' : 'stop']);
            $this->app->logger->info('워커 종료', ['pid' => getmypid(), 'processed' => $this->processed, 'restart' => $restart]);
            $this->say('워커 종료(처리 ' . $this->processed . '건)' . ($restart ? ' — 재시작 요청: 종료 코드 ' . self::RESTART_EXIT_CODE : ''));
            return $restart ? self::RESTART_EXIT_CODE : 0;
        } finally {
            $lock->release();
        }
    }

    private function loop(WorkerState $state): void
    {
        $cfg = $this->app->config;
        $poll = (float) $cfg->int('worker.poll_interval');
        $beatEvery = (float) $cfg->int('worker.heartbeat_interval');
        $t0 = microtime(true);
        $lastPoll = -INF;
        $lastBeat = -INF;
        while (true) {
            $now = microtime(true);
            if (!$this->stopping && $state->stopRequested()) {
                $req = $state->stopRequest();
                if ($req !== null && $req['requested_at'] !== null && $req['requested_at'] < $this->startedAt) {
                    // 이 워커가 뜨기 전의 요청(지난 워커에 보낸 신호) → 무시하고 지운다
                    $state->clearStop();
                    $this->app->logger->info('오래된 정지 요청 무시', ['requested_at' => $req['requested_at'], 'started_at' => $this->startedAt]);
                } else {
                    $this->stopping = true;
                    $this->stopKind = $req['kind'] ?? 'stop';
                    $this->extra['stop_kind'] = $this->stopKind;
                    $this->say(($this->stopKind === 'restart' ? '재시작' : '정지') . ' 요청: 새 작업을 받지 않고 진행 중인 작업을 기다립니다');
                    $lastBeat = -INF;
                }
            }
            if (!$this->stopping && $this->maxSeconds !== null && $now - $t0 >= $this->maxSeconds) {
                $this->stopping = true;
                $this->say('정지 요청: 새 작업을 받지 않고 진행 중인 작업을 기다립니다');
            }
            if ($this->stopping && ($this->forceStop || $this->forceNow) && ($this->analyses !== [] || $this->uploads !== [] || $this->adminChild !== null)) {
                $this->killAll();
            }
            // P21C: Node "상태 갱신" 요청 → 워커 재시작 없이 다시 확인하고 바로 생존 신호에 쓴다
            if ($this->handleNodeCheck($state)) {
                $lastBeat = -INF; // 바로 생존 신호에 쓴다
            }
            // P27A: 화면 설정이 바뀌었으면 재시작 없이 다시 읽는다(다음 작업부터 새 값). 반영한 버전을 바로 생존 신호에 쓴다
            if ($this->reloadSettings()) {
                $lastBeat = -INF;
            }
            // P27B 복원: 요청이 있으면 새 작업을 받지 않고 진행 중인 작업을 기다린 뒤(또는 취소) DB 연결을 모두 닫고 복원 자식을 실행한다.
            // 그동안은 DB를 쓰는 일(주기 분석·규칙 적재·알림 정리·작업 집기)을 하지 않는다 — 생존 신호(파일)만
            $restoring = $this->handleRestore($now);
            if ($restoring || is_file($this->app->backupPaths()->maintenanceFlag())) {
                $this->superviseAnalyses();
                $this->superviseUploads();
                $this->superviseAdminTask();
                $this->superviseBackup();
                if ($now - $lastBeat >= $beatEvery) {
                    $lastBeat = $now;
                    $state->beat((int) getmypid(), $this->startedAt, $this->stopping ? 'stopping' : 'maintenance', $this->childList('analyses'), $this->childList('uploads'), App::VERSION,
                        $this->extra + ['backup' => $this->backupInfo(), 'restore' => $this->restoreChild !== null ? 'running' : ($restoring ? 'draining' : null)]);
                }
                if ($this->stopping && !$restoring && $this->analyses === [] && $this->uploads === [] && $this->adminChild === null && $this->backupChild === null) {
                    return;
                }
                usleep(self::TICK_MICROS);
                continue;
            }
            $this->scheduleBackups($now);
            $this->requestRulesLoad($now);
            $this->pruneNotifications($now);
            $this->evaluateHealth($now);
            $this->anchorAudit($now);
            $this->idleCheckpoint($now);
            $scheduled = $this->app->config->bool('maintenance.enabled') ? false : $this->tickSchedules($now);
            if ($scheduled) {
                $lastPoll = -INF; // 등록한 주기 분석을 바로 선점
            }
            $changed = $this->superviseAnalyses() | $this->superviseUploads() | $this->superviseAdminTask() | $this->superviseBackup();
            $this->superviseTrash();
            $idle = false;
            if (!$this->stopping && $now - $lastPoll >= $poll) {
                $lastPoll = $now;
                $claimed = $this->claimAnalyses() | $this->claimUploads() | $this->claimAdminTask() | $this->claimBackupJob();
                $changed = $changed || $claimed;
                $idle = !$claimed && $this->analyses === [] && $this->uploads === [] && $this->adminChild === null && $this->backupChild === null;
            }
            if ($changed || $now - $lastBeat >= $beatEvery) {
                $lastBeat = $now;
                $admin = $this->adminChild === null ? [] : [['id' => (int) $this->adminChild['task']['id'], 'kind' => (string) $this->adminChild['task']['kind'], 'pid' => $this->adminChild['proc']->pid]];
                $state->beat((int) getmypid(), $this->startedAt, $this->stopping ? 'stopping' : 'running', $this->childList('analyses'), $this->childList('uploads'), App::VERSION,
                    $this->extra + ['admin_tasks' => $admin, 'backup' => $this->backupInfo()]);
            }
            if ($this->stopping && $this->analyses === [] && $this->uploads === [] && $this->trashChild === null && $this->adminChild === null && $this->backupChild === null) {
                return;
            }
            if ($this->once && $idle && $this->trashChild === null) {
                return;
            }
            usleep(self::TICK_MICROS);
        }
    }

    /** @return list<array<string, mixed>> */
    private function childList(string $kind): array
    {
        $out = [];
        if ($kind === 'analyses') {
            foreach ($this->analyses as $id => $c) {
                $out[] = ['id' => $id, 'pid' => $c['proc']->pid];
            }
        } else {
            foreach ($this->uploads as $id => $c) {
                $out[] = ['id' => $id, 'pid' => $c['proc']->pid, 'stage' => $c['stage']];
            }
        }
        return $out;
    }

    // ---- 분석 ----

    private function claimAnalyses(): bool
    {
        // P32B 업그레이드 유지보수 모드: 새 분석을 시작하지 않는다(진행 중인 것은 끝냄, 대기열은 그대로 — 끄면 이어서)
        if ($this->app->config->bool('maintenance.enabled')) {
            return false;
        }
        $queue = $this->app->analysisQueue();
        $max = $this->app->config->int('analysis.max_concurrent');
        $claimed = false;
        while (count($this->analyses) < $max) {
            $row = $queue->claimNext($max, (int) getmypid());
            if ($row === null) {
                break;
            }
            $claimed = true;
            $this->startAnalysis($row);
        }
        return $claimed;
    }

    /** 저장소 출처 분석의 가져오기 시간(초). 업로드 분석은 0 @param array<string, mixed> $row */
    private function fetchAllowance(array $row): int
    {
        if (!in_array($row['source_kind'] ?? 'upload', ['git', 'perforce'], true)) {
            return 0;
        }
        $own = $this->app->db()->value('SELECT fetch_timeout FROM project_repo_connections WHERE project_id = ?', [(int) $row['project_id']]);
        return is_numeric($own) ? (int) $own : $this->app->config->int('repository.fetch_timeout');
    }

    /** @param array<string, mixed> $row */
    private function startAnalysis(array $row): void
    {
        $id = (int) $row['id'];
        $queue = $this->app->analysisQueue();
        $cmd = [PHP_BINARY, ...$this->phpArgs, $this->app->root . '/tools/run_analysis.php', ...$this->configArg(), (string) $id];
        $p = $this->internalRunner()->start($cmd, new ProcessOptions(
            cwd: $this->app->root,
            env: $this->childEnv,
            // P25: 저장소 출처는 가져오기 시간(repository.fetch_timeout 또는 연결별 재정의)을 분석 시간과 별도로 더한다(명세 18.3)
            timeoutSeconds: (float) ($this->app->config->int('analysis.timeout') + $this->fetchAllowance($row)),
            outputLimit: self::CHILD_OUTPUT_LIMIT,
            outputDir: $this->tmpDir(),
            cancelCheckSeconds: (float) $this->app->config->int('worker.cancel_check_interval'),
            newProcessGroup: true,
        ), static fn (): bool => $queue->cancelRequested($id));
        if ($p instanceof ProcessResult) {
            $p->cleanup();
            $failed = $queue->fail($id, 'analysis_start_failed');
            $this->removeWork($row['work_dir'] ?? null, $id);
            if ($failed) {
                $this->app->events()->dispatch(new \PJGuardian\Services\Events\AnalysisFailed($id, (int) $row['project_id'], 'analysis_start_failed'));
            }
            $this->app->logger->error('분석 자식 프로세스 시작 실패', ['analysis_id' => $id]);
            $this->say("분석 #{$id} 시작 실패");
            return;
        }
        $queue->setProcess($id, $p->pid);
        $this->analyses[$id] = ['proc' => $p, 'row' => $row];
        $this->app->logger->info('분석 시작', ['analysis_id' => $id, 'project_id' => (int) $row['project_id'], 'child_pid' => $p->pid]);
        $this->say(sprintf('분석 #%d 시작(프로젝트 %d, 자식 pid=%d)', $id, (int) $row['project_id'], $p->pid));
    }

    private function superviseAnalyses(): bool
    {
        $changed = false;
        foreach ($this->analyses as $id => $c) {
            $r = $c['proc']->poll();
            if ($r === null) {
                continue;
            }
            unset($this->analyses[$id]);
            $changed = true;
            $this->finishAnalysis($id, $c['row'], $r);
            // P20S: 분석이 끝날 때마다(성공·실패·취소 모두, 다음 작업을 가져오기 전) 체크포인트. 반영 연결은 자동 체크포인트를 껐다
            $this->checkpoint($this->analyses === [] && $this->uploads === [] && $this->adminChild === null);
            // P21B: 체크포인트 뒤 Run 상세 자동 보관(성공한 분석만, 별도 작은 트랜잭션)
            $this->retainRuns($id, (int) $c['row']['project_id']);
        }
        return $changed;
    }

    /** Run 상세 자동 보관(P21B): 이 분석이 성공했으면 그 프로젝트에서 runs.detail_keep을 넘는 오래된 Run의 상세를 지운다(최대 runs.retention_batch개) */
    private function retainRuns(int $analysisId, int $projectId): void
    {
        $keep = $this->app->config->int('runs.detail_keep');
        // 변경 없음(P27)은 Run이 아니므로 보관 정리를 부르지 않는다
        if ($keep <= 0 || $this->app->db()->value("SELECT status FROM analyses WHERE id = ? AND no_change = 0", [$analysisId]) !== 'succeeded') {
            return;
        }
        try {
            $t = hrtime(true);
            $runs = $this->app->runDetails()->retain($projectId, $keep, $this->app->config->int('runs.retention_batch'));
            if ($runs !== []) {
                $ms = (int) ((hrtime(true) - $t) / 1e6);
                $this->app->logger->info('Run 상세 자동 보관', ['project_id' => $projectId, 'runs' => $runs, 'keep' => $keep, 'ms' => $ms]);
                $this->say(sprintf('프로젝트 %d: Run %s 상세 삭제(보관 %d개, %dms)', $projectId, implode(',', $runs), $keep, $ms));
            }
        } catch (\Throwable $e) {
            $this->app->logger->warn('Run 상세 자동 보관 실패(다음 분석 뒤 다시 시도)', ['project_id' => $projectId, 'error' => $e->getMessage()]);
        }
    }

    /** WAL 체크포인트(Support\WalCheckpoint). 실패해도 워커는 계속한다(경고 로그만) */
    private function checkpoint(bool $idle): void
    {
        try {
            $r = $this->app->walCheckpoint()->run($idle);
            $this->extra['wal_checkpoint'] = ['at' => SystemClock::iso($this->app->clock->now())] + $r;
        } catch (\Throwable $e) {
            $this->app->logger->warn('WAL 체크포인트 실행 실패(분석 결과에는 영향 없음)', ['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $row */
    private function finishAnalysis(int $id, array $row, ProcessResult $r, ?string $forcedCode = null): void
    {
        $queue = $this->app->analysisQueue();
        $db = $this->app->db();
        if ($forcedCode !== null) {
            $queue->fail($id, $forcedCode);
        } else {
            switch ($r->reason) {
                case ProcessResult::TIMEOUT:
                    $queue->fail($id, 'timeout');
                    break;
                case ProcessResult::CANCELLED:
                    $queue->markCancelled($id);
                    break;
                case ProcessResult::OUTPUT_LIMIT:
                    $queue->fail($id, 'analysis_crashed');
                    break;
                default:
                    // 자식이 결과를 기록하지 못하고 끝남. 취소 요청과 겹쳐 기록을 거절당한 경우는 취소로
                    if ($db->value('SELECT status FROM analyses WHERE id = ?', [$id]) === 'running') {
                        $queue->cancelRequested($id) ? $queue->markCancelled($id) : $queue->fail($id, 'analysis_crashed');
                    }
            }
        }
        // work_dir는 다시 읽는다: 저장소 출처는 자식이 가져오면서 기록한다(P25)
        $final = $db->one('SELECT status, failure_code, upload_id, work_dir FROM analyses WHERE id = ?', [$id]);
        $stderr = trim($r->stderr(4096));
        $r->cleanup();
        $this->removeWork($final['work_dir'] ?? $row['work_dir'] ?? null, $id);
        // 가져오기 임시 폴더(자식이 정상이면 이미 지웠다. 취소·시간 초과로 트리를 끝낸 경우 남는다)
        $stage = \PJGuardian\Services\Repo\RepoImport::stageDir($this->app->paths->work(), $id);
        // P19C: 결함이 많은 파일의 임시 SQLite(자식이 정상이면 이미 지웠다. 시간 초과·강제 종료면 남는다)
        \PJGuardian\Services\Defects\DefectIngest::cleanupSpill($this->app->paths->work(), (string) $id);
        if (!\PJGuardian\Services\Repo\RepoImport::removeStage($stage)) {
            (new CleanupQueue($this->app->paths->work() . '/cleanup_pending.jsonl', $this->app->clock))->add($stage, 'repo_fetch_stage', ['analysis_id' => $id]);
        }
        if (($final['status'] ?? null) === 'succeeded' && is_string($final['upload_id'] ?? null)) {
            // 분석이 성공하면 zip은 즉시 지운다(명세 5.4). 실패·취소는 upload.failed_zip_retention(1일) 뒤 정리 작업이 지운다
            $this->removePath($this->app->uploadFiles()->dir($final['upload_id']), 'analysis_succeeded_zip', $id);
        }
        $this->processed++;
        // 부모가 실패로 끝낸 경우(타임아웃·자식 비정상 종료·강제 정지). 자식이 기록한 실패는 자식이 이벤트를 보냈다
        if (($final['status'] ?? null) === 'failed' && in_array($final['failure_code'] ?? null, ['timeout', 'analysis_crashed', 'analysis_start_failed', 'worker_stopped'], true)) {
            $this->app->events()->dispatch(new \PJGuardian\Services\Events\AnalysisFailed($id, (int) $row['project_id'], (string) $final['failure_code']));
        } elseif (($final['status'] ?? null) === 'cancelled') {
            // 실행 중 취소(P24 알림): 부모가 자식 트리를 종료한 뒤 cancelled로 기록했다
            $this->app->events()->dispatch(new \PJGuardian\Services\Events\AnalysisCancelled($id, (int) $row['project_id']));
        }
        $ctx = ['analysis_id' => $id, 'status' => $final['status'] ?? null, 'failure_code' => $final['failure_code'] ?? null] + $r->summary();
        if ($stderr !== '') {
            $ctx['child_stderr'] = mb_substr($stderr, 0, 2000);
        }
        $this->app->logger->info('분석 종료', $ctx);
        $this->say(sprintf('분석 #%d 종료: %s%s (%.1f초, 자식 %s)', $id, (string) ($final['status'] ?? '?'),
            isset($final['failure_code']) ? ' ' . $final['failure_code'] : '', $r->durationMs / 1000, $r->reason));
    }

    // ---- 업로드 후처리 ----

    private function claimUploads(): bool
    {
        $max = $this->app->config->int('worker.upload_concurrency');
        $claimed = false;
        while (count($this->uploads) < $max) {
            $id = $this->app->uploadAssembler()->claimNext();
            $stage = 'assemble';
            if ($id === null) {
                $id = $this->app->uploadProcessor()->claimNext();
                $stage = 'extract';
            }
            if ($id === null) {
                break;
            }
            $claimed = true;
            $cmd = [PHP_BINARY, ...$this->phpArgs, $this->app->root . '/tools/run_upload.php', ...$this->configArg(), $stage, $id];
            $p = $this->internalRunner()->start($cmd, new ProcessOptions(
                cwd: $this->app->root,
                env: $this->childEnv,
                timeoutSeconds: (float) $this->app->config->int('worker.extract_timeout'),
                outputLimit: self::CHILD_OUTPUT_LIMIT,
                outputDir: $this->tmpDir(),
                newProcessGroup: true,
            ));
            if ($p instanceof ProcessResult) {
                $this->failUpload($id, $stage, $p);
                continue;
            }
            $this->uploads[$id] = ['proc' => $p, 'stage' => $stage];
            $this->say(sprintf('업로드 %s… %s 시작(자식 pid=%d)', substr($id, 0, 8), $stage === 'assemble' ? '조립' : '해제', $p->pid));
        }
        return $claimed;
    }

    private function superviseUploads(): bool
    {
        $changed = false;
        foreach ($this->uploads as $id => $c) {
            $r = $c['proc']->poll();
            if ($r === null) {
                continue;
            }
            unset($this->uploads[$id]);
            $changed = true;
            $id = (string) $id;
            if (!$r->ok()) {
                $this->failUpload($id, $c['stage'], $r);
            }
            $row = $this->app->db()->one('SELECT status, error_code FROM uploads WHERE id = ?', [$id]);
            $this->app->logger->info('업로드 후처리 자식 종료', ['upload_id' => $id, 'stage' => $c['stage'], 'status' => $row['status'] ?? null] + $r->summary());
            $this->say(sprintf('업로드 %s… %s 끝: %s%s (%.1f초)', substr($id, 0, 8), $c['stage'] === 'assemble' ? '조립' : '해제',
                (string) ($row['status'] ?? '?'), isset($row['error_code']) ? ' ' . $row['error_code'] : '', $r->durationMs / 1000));
            $r->cleanup();
        }
        return $changed;
    }

    private function failUpload(string $id, string $stage, ProcessResult $r): void
    {
        $detail = $r->reason . ' exit=' . ($r->exitCode ?? '-') . ' ' . mb_substr(trim($r->stderr(2000)), 0, 500);
        if ($stage === 'assemble') {
            $this->app->uploadAssembler()->failClaimed($id, $detail);
        } else {
            $this->app->uploadProcessor()->failClaimed($id, $r->reason === ProcessResult::TIMEOUT ? 'extract_timeout' : 'extract_crashed', $detail);
        }
    }

    // ---- 관리 작업(P20A) ----

    private function claimAdminTask(): bool
    {
        if ($this->adminChild !== null) {
            return false;
        }
        $task = $this->app->adminTasks()->claimNext();
        if ($task === null) {
            return false;
        }
        $id = (int) $task['id'];
        $p = $this->internalRunner()->start(
            [PHP_BINARY, ...$this->phpArgs, $this->app->root . '/tools/run_admin_task.php', ...$this->configArg(), (string) $id],
            new ProcessOptions(cwd: $this->app->root, env: $this->childEnv, timeoutSeconds: (float) $this->app->config->int('worker.admin_task_timeout'),
                outputLimit: self::CHILD_OUTPUT_LIMIT, outputDir: $this->tmpDir(), newProcessGroup: true),
        );
        if ($p instanceof ProcessResult) {
            $p->cleanup();
            $this->app->adminTasks()->finish($id, false, ['code' => 'start_failed']);
            return true;
        }
        $this->adminChild = ['proc' => $p, 'task' => $task];
        $this->say(sprintf('관리 작업 #%d %s 시작(자식 pid=%d)', $id, (string) $task['kind'], $p->pid));
        return true;
    }

    private function superviseAdminTask(): bool
    {
        if ($this->adminChild === null) {
            return false;
        }
        $r = $this->adminChild['proc']->poll();
        if ($r === null) {
            return false;
        }
        $this->finishAdminTask($r, null);
        return true;
    }

    private function finishAdminTask(ProcessResult $r, ?string $forcedCode): void
    {
        $task = $this->adminChild['task'] ?? null;
        $this->adminChild = null;
        if ($task === null) {
            return;
        }
        $id = (int) $task['id'];
        // 자식이 결과를 남기지 못하고 끝났으면(타임아웃·비정상 종료) 실패로 닫는다
        if ($this->app->db()->value('SELECT status FROM admin_tasks WHERE id = ?', [$id]) === 'running') {
            $this->app->adminTasks()->finish($id, false, ['code' => $forcedCode ?? ($r->reason === ProcessResult::TIMEOUT ? 'timeout' : 'crashed')]);
        }
        $this->app->logger->info('관리 작업 종료', ['admin_task_id' => $id, 'kind' => $task['kind']] + $r->summary());
        $this->say(sprintf('관리 작업 #%d 종료(%.1f초, 자식 %s)', $id, $r->durationMs / 1000, $r->reason));
        $r->cleanup();
    }

    // ---- 백업·복원(P27B) ----

    /** 일일 백업 시각이 되면(상태 파일만 읽음) 작업을 넣고, 시간마다 복원 리허설 기한을 본다. 실패는 경고 로그만 */
    private function scheduleBackups(float $now): void
    {
        if ($this->stopping) {
            return;
        }
        try {
            if ($now - $this->backupCheckedAt >= self::BACKUP_CHECK_SECONDS) {
                $this->backupCheckedAt = $now;
                // 자동 백업(P27E: 켜기/끄기·매일/매주/매월 — 판단은 BackupRunner::autoDue, 종류 이름은 호환으로 daily)
                $slot = $this->backupChild === null ? $this->app->backupRunner(false)->autoDue() : null;
                if ($slot !== null) {
                    $this->app->backupJobs()->request(['type' => 'backup', 'kind' => 'daily', 'snapshots' => true, 'by' => 'system', 'by_id' => null, 'slot' => $slot]);
                    $this->say('자동 백업 요청(예정 ' . $slot . ')');
                }
            }
            if ($now - $this->rehearsalCheckedAt >= self::REHEARSAL_CHECK_SECONDS) {
                $this->rehearsalCheckedAt = $now;
                $this->app->backupRunner()->checkRehearsalDue();
                // P28D: SAML IdP 인증서 만료 임박 알림(인증서마다 하루 한 건, 028 전이면 하지 않음)
                if ($this->app->samlEnabled()) {
                    $this->app->samlAdmin()->notifyCertExpiry();
                }
            }
        } catch (\Throwable $e) {
            $this->app->logger->warn('백업 일정 확인 실패(다음에 다시)', ['error' => $e->getMessage()]);
        }
    }

    private function claimBackupJob(): bool
    {
        if ($this->backupChild !== null) {
            return false;
        }
        $job = $this->app->backupJobs()->active();
        if ($job === null || ($job['started_at'] ?? null) !== null && $this->backupChild === null && $this->staleJob($job)) {
            return false;
        }
        $p = $this->internalRunner()->start(
            [PHP_BINARY, ...$this->phpArgs, $this->app->root . '/tools/backup.php', '--job', ...$this->configArg()],
            new ProcessOptions(cwd: $this->app->root, env: $this->childEnv, timeoutSeconds: (float) self::RESTORE_TIMEOUT,
                outputLimit: self::CHILD_OUTPUT_LIMIT, outputDir: $this->tmpDir(), newProcessGroup: true),
        );
        if ($p instanceof ProcessResult) {
            $p->cleanup();
            $this->app->backupJobs()->finish();
            $this->app->logger->warn('백업 자식 시작 실패', ['job' => $job['type'] ?? null]);
            return true;
        }
        $this->backupChild = ['proc' => $p, 'job' => $job];
        $this->say(sprintf('%s 시작(자식 pid=%d)', ($job['type'] ?? '') === 'rehearsal' ? '복원 리허설' : '백업(' . ($job['kind'] ?? '') . ')', $p->pid));
        return true;
    }

    /** 이전 워커가 시작하고 죽은 작업(started_at 있음, 자식 없음) → 지우고 다시 집지 않는다 */
    private function staleJob(array $job): bool
    {
        $this->app->backupJobs()->finish();
        $this->app->logger->warn('끝나지 않은 백업 작업 정리(워커가 바뀜)', ['job' => $job['type'] ?? null, 'kind' => $job['kind'] ?? null]);
        return true;
    }

    private function superviseBackup(): bool
    {
        if ($this->backupChild === null) {
            return false;
        }
        $r = $this->backupChild['proc']->poll();
        if ($r === null) {
            return false;
        }
        $job = $this->backupChild['job'];
        $this->backupChild = null;
        if ($this->app->backupJobs()->active() !== null) {
            // 자식이 결과를 남기지 못하고 끝났다(타임아웃·비정상 종료)
            $this->app->backupJobs()->finish();
            $this->app->logger->warn('백업 자식이 비정상 종료', ['job' => $job['type'] ?? null] + $r->summary());
        }
        $this->say(sprintf('백업 작업 종료(%.1f초, 자식 %s)', $r->durationMs / 1000, $r->reason));
        $r->cleanup();
        return true;
    }

    /** @return array<string, mixed>|null 생존 신호의 백업 작업 정보 */
    private function backupInfo(): ?array
    {
        return $this->backupChild === null ? null
            : ['type' => (string) ($this->backupChild['job']['type'] ?? ''), 'kind' => $this->backupChild['job']['kind'] ?? null, 'pid' => $this->backupChild['proc']->pid];
    }

    /**
     * 복원 요청 처리. 진행 중(기다림·자식 실행)이면 true.
     *  1) 요청을 처음 보면 유지보수 플래그(웹 쓰기 막힘), "모두 취소"면 실행 중 분석에 취소 요청
     *  2) 분석·업로드·관리 작업·백업 자식이 모두 끝날 때까지 기다림(restore_wait_max_minutes 넘으면 restore_busy로 실패)
     *  3) DB 연결을 모두 닫고(App::resetConnections) tools/restore.php --request 자식 실행 — 복원 서비스가 상태 파일·롤백·플래그 해제를 맡는다
     *  4) 끝나면 요청 파일을 지우고 설정을 다시 읽고, 복원된 DB의 실행 중 행을 시작 때처럼 정리한다
     */
    private function handleRestore(float $now): bool
    {
        $paths = $this->app->backupPaths();
        if ($this->restoreChild !== null) {
            $r = $this->restoreChild['proc']->poll();
            if ($r === null) {
                return true;
            }
            $this->restoreChild = null;
            $this->drainStartedAt = null;
            @unlink($paths->restoreRequest());
            $this->app->maintenance()->leave(); // 복원 서비스가 끄지만 자식이 비정상 종료한 경우까지
            $this->app->logger->info('복원 자식 종료', $r->summary());
            $this->say(sprintf('복원 종료(%.1f초, 자식 %s)', $r->durationMs / 1000, $r->reason));
            $r->cleanup();
            try {
                $this->app->replaceConfig($this->app->config->reload());
            } catch (\Throwable $e) {
                $this->app->logger->warn('복원 뒤 설정 다시 읽기 실패(이전 설정 유지)', ['error' => $e->getMessage()]);
            }
            $this->app->resetConnections();
            try {
                $this->app->workerRecovery()->recoverAll(false, 'worker_stopped');
            } catch (\Throwable $e) {
                $this->app->logger->warn('복원 뒤 실행 중 행 정리 실패', ['error' => $e->getMessage()]);
            }
            return false;
        }
        $req = json_decode((string) @file_get_contents($paths->restoreRequest()), true);
        if (!is_array($req)) {
            $this->drainStartedAt = null;
            return false;
        }
        if ($this->drainStartedAt === null) {
            $this->drainStartedAt = $now;
            $this->app->maintenance()->enter('restore', (string) ($req['by'] ?? 'admin'));
            $this->say('복원 요청: 새 작업을 받지 않고 진행 중인 작업을 기다립니다');
            if (($req['wait_mode'] ?? 'wait') === 'cancel') {
                try {
                    $this->app->db()->execute("UPDATE analyses SET cancel_requested = 1 WHERE status = 'running'");
                } catch (\Throwable $e) {
                    $this->app->logger->warn('복원 전 분석 취소 요청 실패', ['error' => $e->getMessage()]);
                }
            }
        }
        if ($this->analyses !== [] || $this->uploads !== [] || $this->adminChild !== null || $this->backupChild !== null) {
            if ($now - $this->drainStartedAt > 60 * $this->app->config->int('backup.restore_wait_max_minutes')) {
                (new \PJGuardian\Services\Backup\RestoreStateFile($paths->restoreState(), $this->app->clock))->finish('failed', ['code' => 'restore_busy', 'params' => []], []);
                @unlink($paths->restoreRequest());
                $this->app->maintenance()->leave();
                $this->drainStartedAt = null;
                $this->say('복원 취소: 진행 중인 작업이 시간 안에 끝나지 않았습니다');
                return false;
            }
            return true;
        }
        $this->app->resetConnections();
        $p = $this->internalRunner()->start(
            [PHP_BINARY, ...$this->phpArgs, $this->app->root . '/tools/restore.php', '--request', ...$this->configArg()],
            new ProcessOptions(cwd: $this->app->root, env: $this->childEnv, timeoutSeconds: (float) self::RESTORE_TIMEOUT,
                outputLimit: self::CHILD_OUTPUT_LIMIT, outputDir: $this->tmpDir(), newProcessGroup: true),
        );
        if ($p instanceof ProcessResult) {
            $p->cleanup();
            (new \PJGuardian\Services\Backup\RestoreStateFile($paths->restoreState(), $this->app->clock))->finish('failed', ['code' => 'restore_start_failed', 'params' => []], []);
            @unlink($paths->restoreRequest());
            $this->app->maintenance()->leave();
            $this->drainStartedAt = null;
            return false;
        }
        $this->restoreChild = ['proc' => $p, 'request' => $req];
        $this->say(sprintf('복원 시작(자식 pid=%d)', $p->pid));
        return true;
    }

    // ---- 정지 ----

    /** 강제 정지: 자식 트리를 종료하고 분석은 failed(worker_stopped), 업로드는 되돌린다 */
    private function killAll(): void
    {
        if ($this->backupChild !== null) {
            $this->backupChild['proc']->terminate()->cleanup();
            $this->backupChild = null;
            $this->app->backupJobs()->finish();
        }
        foreach ($this->analyses as $id => $c) {
            $r = $c['proc']->terminate();
            $this->finishAnalysis($id, $c['row'], $r, 'worker_stopped');
        }
        $this->analyses = [];
        foreach ($this->uploads as $c) {
            $c['proc']->terminate()->cleanup();
        }
        $this->uploads = [];
        if ($this->adminChild !== null) {
            $this->finishAdminTask($this->adminChild['proc']->terminate(), 'worker_stopped');
        }
        // 업로드는 assembling·extracting(work_dir)으로 남아 있다 → 되돌려 다음 기동 때 다시 처리
        $this->app->workerRecovery()->recoverAll(false, 'worker_stopped');
        $this->say('강제 정지: 진행 중인 작업을 종료했습니다');
    }

    private function installSignalHandlers(): void
    {
        $handler = function (): void {
            if ($this->stopping) {
                $this->forceNow = true; // 두 번째 신호는 강제 정지
            }
            $this->stopping = true;
        };
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            try {
                @sapi_windows_set_ctrl_handler(static function (int $event) use ($handler): void {
                    $handler();
                });
            } catch (\Throwable) {
                // 콘솔이 없는 실행(서비스·작업 스케줄러)은 stop.request 파일로 정지한다
            }
        }
    }

    // ---- 도우미 ----

    private ?ProcessRunner $internal = null;

    /** 워커의 PHP 자식만 허용하는 실행기(도구 실행용 bin/ 허용 목록과 별개) */
    private function internalRunner(): ProcessRunner
    {
        return $this->internal ??= $this->app->internalRunner();
    }

    /** @return list<string> */
    private function configArg(): array
    {
        return $this->configPath !== null ? ['--config=' . $this->configPath] : [];
    }

    private function tmpDir(): string
    {
        $dir = $this->app->workerState()->dir() . '/tmp';
        Fs::ensureDir($dir);
        return $dir;
    }

    /**
     * 자식 PHP 명령: 같은 php.ini(-c), memory_limit = extract.worker_memory_limit, 부모에 있는데 자식 기본 설정에 없는 확장은
     * -d extension=로 켠다(부모를 -d extension=intl로 띄운 경우). 환경 변수는 최소 환경 + PJG_*(시크릿·시험 설정).
     */
    private function prepareChildCommand(): void
    {
        // 자식은 최소 PATH라, tools.node_path를 비워 PATH에서 찾는 경우를 위해 부모가 찾은 Node 폴더를 넣어 준다(P15)
        $node = $this->app->nodeRuntime()->path();
        $env = ProcessRunner::minimalEnv(sys_get_temp_dir(), $node !== null ? [dirname($node)] : []);
        foreach (getenv() as $k => $v) {
            if (str_starts_with((string) $k, 'PJG_')) {
                $env[(string) $k] = (string) $v;
            }
        }
        $this->childEnv = $env;
        $args = [];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            $args = ['-c', $ini];
        }
        $args[] = '-d';
        $args[] = 'memory_limit=' . $this->app->config->int('extract.worker_memory_limit');
        $required = ['intl', 'pdo_sqlite', 'zip', 'mbstring', 'openssl', 'fileinfo'];
        $probe = $this->internalRunner()->run(
            [PHP_BINARY, ...$args, '-r', 'echo implode(",", array_filter(' . var_export($required, true) . ', static fn ($e) => !extension_loaded($e)));'],
            new ProcessOptions(env: $env, timeoutSeconds: 30, outputDir: $this->tmpDir()),
        );
        $missing = array_filter(explode(',', trim($probe->stdout(4096))));
        $probe->cleanup();
        foreach ($missing as $ext) {
            if (extension_loaded($ext)) {
                $args[] = '-d';
                $args[] = 'extension=' . $ext;
            }
        }
        $this->phpArgs = $args;
    }

    /**
     * 분석 종료 시 작업 디렉토리 정리. 파일이 100만 개일 수 있어 부모는 휴지통으로 옮기기만 하고(rename),
     * 비우기는 자식(tools/empty_trash.php)이 한다. 옮기지 못하면 정리 대상 목록(cleanup_pending.jsonl)에 남긴다.
     */
    private function removeWork(mixed $workDir, int $analysisId): void
    {
        if (!is_string($workDir) || preg_match('/^[0-9a-f]{8}$/', $workDir) !== 1) {
            return;
        }
        $path = rtrim(str_replace('\\', '/', $this->app->paths->work()), '/') . '/' . $workDir;
        if (!Trash::forWork($this->app->paths->work())->move($path)) {
            $this->app->logger->warn('작업 디렉토리를 옮기지 못함(정리 대상 목록에 남김)', ['analysis_id' => $analysisId, 'path' => $path]);
            (new CleanupQueue($this->app->paths->work() . '/cleanup_pending.jsonl', $this->app->clock))->add($path, 'analysis_work_dir', ['analysis_id' => $analysisId]);
        }
    }

    /** 휴지통이 비어 있지 않으면 비우는 자식을 하나 띄운다(동시에 하나) */
    private function superviseTrash(): void
    {
        if ($this->trashChild !== null) {
            $r = $this->trashChild->poll();
            if ($r === null) {
                return;
            }
            $this->trashChild = null;
            $r->cleanup();
        }
        if (Trash::forWork($this->app->paths->work())->isEmpty()) {
            return;
        }
        $p = $this->internalRunner()->start(
            [PHP_BINARY, ...$this->phpArgs, $this->app->root . '/tools/empty_trash.php', ...$this->configArg()],
            new ProcessOptions(cwd: $this->app->root, env: $this->childEnv, timeoutSeconds: (float) $this->app->config->int('worker.extract_timeout'),
                outputLimit: self::CHILD_OUTPUT_LIMIT, outputDir: $this->tmpDir(), newProcessGroup: true),
        );
        if ($p instanceof RunningProcess) {
            $this->trashChild = $p;
        } else {
            $p->cleanup();
        }
    }

    private function removePath(string $path, string $reason, int $analysisId): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (!Fs::removeTree($path)) {
            $this->app->logger->warn('지우지 못한 파일(정리 대상 목록에 남김)', ['analysis_id' => $analysisId, 'path' => $path]);
            (new CleanupQueue($this->app->paths->work() . '/cleanup_pending.jsonl', $this->app->clock))->add($path, $reason, ['analysis_id' => $analysisId]);
        }
    }
}
