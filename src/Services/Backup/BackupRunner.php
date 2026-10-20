<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Notifications\NotificationService;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 백업·리허설 작업 실행과 일일 백업 판단(P27B). 워커가 띄운 자식(tools/backup.php --job)과 워커의 주기 확인이 쓴다.
 *
 * - 자동 백업(P27E — 종류 이름은 호환으로 daily): [backup] enabled·schedule_mode(매일·매주·매월)·daily_at(실행 시각)의 예정마다 한 번(BackupSchedule).
 *   실패하면 10분 뒤 한 번 다시(같은 예정), 그래도 실패면 그 예정은 끝내고 관리자 알림 backup_failed(같은 사유는 1시간에 한 번 — 명세 19.3)·감사·로그.
 *   성공하면 보관 정리. 꺼져 있으면 자동 백업·보관 정리·실패 알림 모두 없음(수동 백업은 그대로).
 * - 수동 백업: 성공하면 요청한 관리자에게 backup_done, 실패하면 backup_failed(관리자 전체).
 * - 복원 리허설 기한: 마지막 리허설(없으면 상태 파일을 처음 만든 시각)에서 rehearsal_interval_days가 지나면 관리자 알림 restore_rehearsal_due(기한마다 한 번).
 */
final class BackupRunner
{
    public const RETRY_SECONDS = 600;

    public function __construct(
        private readonly Config $config,
        private readonly BackupService $backups,
        private readonly BackupJobs $jobs,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly ?NotificationService $notifications,
        private readonly ?AuditLog $audit,
        private readonly ?RestoreService $restore = null,
    ) {
    }

    /**
     * 자동 백업을 지금 요청할 때인가(P27E, 워커가 확인할 때마다 — 상태 파일만 읽음). 요청할 예정 시각(UTC ISO)을 돌려준다.
     *
     * 규칙: 가장 최근 예정(≤ 지금) 이후 이 예약으로 성공·시도를 마친 자동 백업이 없으면 그 예정 하나를 실행한다 — 워커가 오래 꺼져 있어
     * 여러 예정이 지나갔어도 한 번만. 실패는 10분 뒤 한 번 더(같은 예정), 그래도 실패면 그 예정은 끝.
     * 규칙(방식·시각·요일·날짜)이 바뀌면 그때 지난 예정은 지난 것으로 보고 새 규칙의 다음 예정부터. 꺼져 있으면 실행하지 않는다(켜면
     * 최근 예정 이후 자동 백업이 없을 때 다음 확인에서 한 번). P27B 상태(daily.date)는 처음 한 번 이어받는다.
     */
    public function autoDue(): ?string
    {
        $schedule = BackupSchedule::fromConfig($this->config);
        $now = $this->clock->now();
        $prev = $schedule->previous($now);
        if ($prev === null) {
            return null;
        }
        $prevIso = SystemClock::iso($prev);
        $auto = $this->autoState($schedule, $prev);
        if (!$this->config->bool('backup.enabled') || $this->jobs->active() !== null) {
            return null;
        }
        if (is_string($auto['slot'] ?? null) && strtotime($auto['slot']) >= $prev->getTimestamp()) {
            return null; // 이 예정은 처리됨
        }
        $retry = $auto['retry_at'] ?? null;
        if (($auto['retry_slot'] ?? null) === $prevIso && is_string($retry) && strtotime($retry) > $now->getTimestamp()) {
            return null; // 재시도 대기
        }
        return $prevIso;
    }

    /** 다음 자동 백업 예정(UTC ISO) — 꺼져 있으면 null. 최근 예정이 아직 처리되지 않았으면 곧(재시도 대기면 그 시각) */
    public function nextAuto(): ?string
    {
        if (!$this->config->bool('backup.enabled')) {
            return null;
        }
        $schedule = BackupSchedule::fromConfig($this->config);
        $now = $this->clock->now();
        $prev = $schedule->previous($now);
        $auto = $this->backups->state()->read()['auto'] ?? null;
        if ($prev !== null && is_array($auto) && ($auto['rule'] ?? null) === $schedule->key()
            && !(is_string($auto['slot'] ?? null) && strtotime($auto['slot']) >= $prev->getTimestamp())) {
            $retry = ($auto['retry_slot'] ?? null) === SystemClock::iso($prev) && is_string($auto['retry_at'] ?? null) ? $auto['retry_at'] : null;
            return $retry ?? SystemClock::iso($now);
        }
        $next = $schedule->next($now);
        return $next === null ? null : SystemClock::iso($next);
    }

    /**
     * 상태 파일의 자동 백업 기록. 처음(P27B에서 넘어옴)이면 daily.date를 이어받고, 규칙이 바뀌었으면 지금의 최근 예정을 처리된 것으로 둔다.
     *
     * @return array{rule: string, slot: ?string, retry_slot: ?string, attempts: int, retry_at: ?string}
     */
    private function autoState(BackupSchedule $schedule, \DateTimeImmutable $prev): array
    {
        $state = $this->backups->state()->read();
        $auto = is_array($state['auto'] ?? null) ? $state['auto'] : null;
        if ($auto !== null && ($auto['rule'] ?? null) === $schedule->key()) {
            return $auto;
        }
        if ($auto === null) {
            $legacy = $state['daily']['date'] ?? null;
            $local = $prev->setTimezone(new \DateTimeZone((string) $this->config->get('app.display_timezone')))->format('Y-m-d');
            $handled = $schedule->mode === 'daily' && $legacy === $local ? SystemClock::iso($prev) : null;
        } else {
            $handled = SystemClock::iso($prev);
            $this->logger->info('자동 백업 주기 바뀜(지난 예정은 건너뜀)', ['from' => $auto['rule'] ?? null, 'to' => $schedule->key()]);
        }
        $auto = ['rule' => $schedule->key(), 'slot' => $handled, 'retry_slot' => null, 'attempts' => 0, 'retry_at' => null];
        $this->backups->state()->update(static function (array $s) use ($auto): array {
            $s['auto'] = $auto;
            return $s;
        });
        return $auto;
    }

    /**
     * 작업 하나 실행(자식 프로세스). 요청 파일은 끝나면 지운다.
     *
     * @param array<string, mixed> $job
     * @return array{ok: bool, code: ?string, name: ?string}
     */
    public function run(array $job): array
    {
        $this->jobs->markStarted();
        try {
            return ($job['type'] ?? 'backup') === 'rehearsal' ? $this->rehearse($job) : $this->backup($job);
        } finally {
            $this->jobs->finish();
        }
    }

    /** @param array<string, mixed> $job @return array{ok: bool, code: ?string, name: ?string} */
    private function backup(array $job): array
    {
        $kind = (string) ($job['kind'] ?? 'manual');
        $by = (string) ($job['by'] ?? 'system');
        // 자동 백업의 예정 시각(워커가 요청에 넣음). 없으면(명령줄 등) 지금의 가장 최근 예정
        $slot = is_string($job['slot'] ?? null) ? $job['slot'] : ($kind === 'daily' ? $this->currentSlot() : null);
        try {
            $r = $this->backups->create($kind, (bool) ($job['snapshots'] ?? true), $by,
                fn (string $step, int $done, int $total) => $this->jobs->writeProgress($step, $done, $total),
                fn (): bool => $this->jobs->cancelled());
        } catch (\Throwable $e) {
            $code = $e instanceof BackupException ? $e->errorCode : 'backup_failed';
            $params = $e instanceof BackupException ? $e->params : [];
            $this->logger->warn('백업 실패', ['kind' => $kind, 'code' => $code, 'error' => $e->getMessage()]);
            $retry = false;
            $this->backups->state()->update(function (array $s) use ($kind, $code, $params, $slot, &$retry): array {
                $s['last_failure'] = ['at' => SystemClock::iso($this->clock->now()), 'code' => $code, 'params' => $params, 'kind' => $kind];
                if ($kind === 'daily') {
                    // 자동 백업(P27E): 같은 예정의 두 번째 실패면 그 예정은 끝(처리됨), 첫 실패면 10분 뒤 재시도
                    $auto = is_array($s['auto'] ?? null) ? $s['auto'] : [];
                    $attempts = ($auto['retry_slot'] ?? null) === $slot ? (int) ($auto['attempts'] ?? 0) + 1 : 1;
                    $retry = $attempts < 2 && $code !== 'backup_cancelled';
                    $s['auto'] = $retry
                        ? ['retry_slot' => $slot, 'attempts' => $attempts, 'retry_at' => SystemClock::iso($this->clock->now()->modify('+' . self::RETRY_SECONDS . ' seconds'))] + $auto
                        : ['slot' => $slot, 'retry_slot' => null, 'attempts' => $attempts, 'retry_at' => null] + $auto;
                }
                return $s;
            });
            $this->audit?->record(Actor::labeled($by), 'backup.create', 'backup', null, ['kind' => $kind, 'result' => 'failed', 'code' => $code]);
            if (!$retry && $code !== 'backup_cancelled') {
                $this->alertFailure($code, $kind);
            }
            return ['ok' => false, 'code' => $code, 'name' => null];
        }
        $this->backups->state()->update(function (array $s) use ($r, $kind, $slot): array {
            $s['last_success'] = ['at' => SystemClock::iso($this->clock->now()), 'file' => $r['name'], 'size' => $r['size'], 'kind' => $kind];
            $s['since'] ??= SystemClock::iso($this->clock->now());
            if ($kind === 'daily') {
                $s['auto'] = ['slot' => $slot, 'retry_slot' => null, 'attempts' => 0, 'retry_at' => null] + (is_array($s['auto'] ?? null) ? $s['auto'] : []);
            }
            return $s;
        });
        $this->audit?->record(Actor::labeled($by), 'backup.create', 'backup', $r['name'], ['kind' => $kind, 'result' => 'ok', 'size' => $r['size'], 'ms' => $r['ms']]);
        // 보관 정리는 자동 백업이 성공한 뒤에만, 자동 백업이 켜져 있을 때만(P27E — 끈 상태에서 파일이 지워지지 않게)
        $deleted = $kind === 'daily' && $this->config->bool('backup.enabled') ? $this->backups->prune() : [];
        if ($deleted !== []) {
            $this->audit?->record(Actor::system(), 'backup.delete', 'backup', null, ['files' => $deleted, 'reason' => 'retention']);
        }
        if ($kind === 'manual' && is_int($job['by_id'] ?? null)) {
            $this->notifications?->emit('backup_done', [(int) $job['by_id']], [], ['file' => $r['name'], 'size' => $r['size']], 'backup_done:' . $r['name']);
        }
        return ['ok' => true, 'code' => null, 'name' => $r['name']];
    }

    /** @param array<string, mixed> $job @return array{ok: bool, code: ?string, name: ?string} */
    private function rehearse(array $job): array
    {
        $name = (string) ($job['file'] ?? '');
        $file = $this->backups->paths()->bundle($name);
        $t0 = hrtime(true);
        $code = null;
        $detail = [];
        try {
            if ($file === null || !is_file($file) || $this->restore === null) {
                throw new BackupException('bundle_not_found');
            }
            $detail = $this->restore->rehearse($file);
        } catch (\Throwable $e) {
            $code = $e instanceof BackupException ? $e->errorCode : 'rehearsal_failed';
            $this->logger->warn('복원 리허설 실패', ['file' => $name, 'code' => $code, 'error' => $e->getMessage()]);
        }
        $ms = (int) ((hrtime(true) - $t0) / 1e6);
        $this->backups->state()->update(function (array $s) use ($name, $code, $ms, $detail): array {
            $s['last_rehearsal'] = ['at' => SystemClock::iso($this->clock->now()), 'file' => $name, 'ok' => $code === null, 'code' => $code, 'ms' => $ms] + $detail;
            $s['since'] ??= SystemClock::iso($this->clock->now());
            return $s;
        });
        $this->audit?->record(Actor::labeled((string) ($job['by'] ?? 'system')), 'restore.rehearsal', 'backup', $name, ['result' => $code === null ? 'ok' : 'failed', 'code' => $code, 'ms' => $ms]);
        return ['ok' => $code === null, 'code' => $code, 'name' => $name];
    }

    /** 리허설 기한이 지났으면 관리자에게 한 번(기한마다). 워커가 시간마다 부른다 */
    public function checkRehearsalDue(): bool
    {
        $s = $this->backups->state()->read();
        if (($s['last_success'] ?? null) === null) {
            return false; // 백업이 없으면 리허설할 것도 없다
        }
        $base = $s['last_rehearsal']['at'] ?? ($s['since'] ?? null);
        if (!is_string($base)) {
            return false;
        }
        $due = strtotime($base) + 86400 * $this->config->int('backup.rehearsal_interval_days');
        if ($this->clock->now()->getTimestamp() < $due) {
            return false;
        }
        $dueKey = gmdate('Y-m-d', $due);
        if (($s['alerts']['rehearsal_due'] ?? null) === $dueKey) {
            return false;
        }
        $this->backups->state()->update(static function (array $st) use ($dueKey): array {
            $st['alerts']['rehearsal_due'] = $dueKey;
            return $st;
        });
        $this->notifications?->emit('restore_rehearsal_due', $this->notifications->activeAdmins(), [], ['since' => $base, 'days' => $this->config->int('backup.rehearsal_interval_days')],
            'restore_rehearsal_due:' . $dueKey);
        return true;
    }

    private function currentSlot(): ?string
    {
        $prev = BackupSchedule::fromConfig($this->config)->previous($this->clock->now());
        return $prev === null ? null : SystemClock::iso($prev);
    }

    /** 같은 사유는 1시간에 한 번(명세 19.3). 자동 백업이 꺼져 있으면 새로 알리지 않는다(P27E) */
    private function alertFailure(string $code, string $kind): void
    {
        if (!$this->config->bool('backup.enabled')) {
            return;
        }
        $now = $this->clock->now()->getTimestamp();
        $send = false;
        $this->backups->state()->update(function (array $s) use ($code, $now, &$send): array {
            $last = $s['alerts']['backup_failed:' . $code] ?? null;
            if (!is_string($last) || $now - (int) strtotime($last) >= 3600) {
                $send = true;
                $s['alerts']['backup_failed:' . $code] = SystemClock::iso($this->clock->now());
            }
            return $s;
        });
        if ($send) {
            $this->notifications?->emit('backup_failed', $this->notifications->activeAdmins(), [], ['code' => $code, 'kind' => $kind],
                'backup_failed:' . $code . ':' . gmdate('YmdH', $now));
        }
    }
}
