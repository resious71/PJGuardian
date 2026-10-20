<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Repo\RepoConnections;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\SystemClock;

/**
 * 프로젝트별 주기 분석 설정(P26, 명세 18.2·18.4). admin만 읽고 바꾼다(라우트). 일반 사용자는 RepoConnections::view의
 * schedule(사용 중 여부·다음 예정)만 본다.
 *
 * - 일정: 사용 안 함(기본) 또는 N일 간격(1~365) + 실행 시각(KST HH:MM). 권장 7일.
 * - 켜거나 간격·시각을 바꾸면 next_run_at = 바꾼 시점 이후 가장 가까운 지정 시각(ScheduleCalc::firstAfter). 그대로 저장하면 유지.
 * - 켜기 조건: 저장소 연결이 있고(업로드 전용 아님) 그 호스트가 허용 목록에 있고 자격 증명을 읽을 수 있을 것(아니면 409
 *   schedule_connection_required + 사유). 마지막 연결 테스트·가져오기가 실패였거나 아직 확인하지 않았으면 저장은 하고 경고를 돌려준다.
 * - 연결을 해제하면 주기를 자동으로 끄고 감사 로그(schedule.auto_disable, reason repo_disconnected)를 남긴다(RepoConnections::delete가 부른다).
 * - 낙관적 개정 번호(revision, 다르면 409 schedule_conflict). 감사 schedule.save(바뀐 항목의 이전·새 값).
 */
final class ScheduleService
{
    public const RECOMMENDED_DAYS = 7;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly RepoConnections $connections,
        // 최소 간격(일, repository.min_interval)
        private readonly int $minDays = 1,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function row(int $projectId): ?array
    {
        return $this->db->one('SELECT * FROM project_schedules WHERE project_id = ?', [$projectId]);
    }

    /**
     * 주기를 켤 수 있는지(연결·허용 호스트·자격 증명). 이유 코드 또는 null(켤 수 있음).
     *
     * @param array<string, mixed>|null $conn
     */
    public function blocker(int $projectId, ?array $conn = null): ?string
    {
        $conn ??= $this->connections->row($projectId);
        if ($conn === null) {
            return 'repo_not_configured';
        }
        $view = $this->connections->view($projectId, true);
        if (($view['host_allowed'] ?? false) !== true) {
            return 'repo_host_not_allowed';
        }
        if ($conn['secret_cipher'] !== null) {
            try {
                $this->connections->target($projectId);
            } catch (RepoException) {
                return 'repo_secret_unreadable';
            }
        }
        return null;
    }

    /**
     * admin 화면·API용.
     *
     * @return array<string, mixed>
     */
    public function view(int $projectId): array
    {
        $r = $this->row($projectId);
        $conn = $this->connections->row($projectId);
        $connStatus = $conn === null ? null : RepoConnections::status($conn);
        return [
            'enabled' => $r !== null && (int) $r['enabled'] === 1,
            'interval_days' => $r === null ? self::RECOMMENDED_DAYS : (int) $r['interval_days'],
            'run_time' => $r['run_time'] ?? '03:00',
            'next_run_at' => $r['next_run_at'] ?? null,
            'last_due_at' => $r['last_due_at'] ?? null,
            'last_analysis_id' => isset($r['last_analysis_id']) ? (int) $r['last_analysis_id'] : null,
            'last_result' => $r['last_result'] ?? null,
            'last_result_at' => $r['last_result_at'] ?? null,
            'consecutive_failures' => (int) ($r['consecutive_failures'] ?? 0),
            'last_error_code' => $r['last_error_code'] ?? null,
            'last_error_title' => self::errorTitle($r['last_error_code'] ?? null),
            'last_error_at' => $r['last_error_at'] ?? null,
            'skip_count' => (int) ($r['skip_count'] ?? 0),
            'last_skip_reason' => $r['last_skip_reason'] ?? null,
            'last_skip_at' => $r['last_skip_at'] ?? null,
            'revision' => $r === null ? null : (int) $r['revision'],
            'updated_at' => $r['updated_at'] ?? null,
            // 켜기 조건과 경고(연결 상태)
            'blocker' => $this->blocker($projectId, $conn),
            'connection_status' => $connStatus,
            // 같은 시각에 예약된 다른 프로젝트 수(시각 → 수). 화면이 고른 시각의 수를 안내한다
            'time_counts' => $this->timeCounts($projectId),
            'recommended_days' => self::RECOMMENDED_DAYS,
            'min_days' => max(ScheduleCalc::INTERVAL_MIN, $this->minDays),
            'max_days' => ScheduleCalc::INTERVAL_MAX,
        ];
    }

    /** @return array<string, int> 사용 중인 다른 프로젝트의 실행 시각별 수 */
    public function timeCounts(int $exceptProjectId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT run_time, COUNT(*) AS n FROM project_schedules WHERE enabled = 1 AND project_id <> ? GROUP BY run_time', [$exceptProjectId]) as $r) {
            $out[(string) $r['run_time']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * 저장. $in: enabled(bool), interval_days(int 1~365), run_time("HH:MM"), revision(이미 있으면 필수).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> 저장 뒤 view + warnings
     */
    public function save(Actor $actor, int $userId, int $projectId, array $in): array
    {
        $enabled = $in['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw self::invalid('enabled');
        }
        $days = $in['interval_days'] ?? null;
        if (!is_int($days) || $days < max(ScheduleCalc::INTERVAL_MIN, $this->minDays) || $days > ScheduleCalc::INTERVAL_MAX) {
            throw self::invalid('interval_days');
        }
        $time = $in['run_time'] ?? null;
        if (ScheduleCalc::parseTime($time) === null) {
            throw self::invalid('run_time');
        }
        if ($enabled) {
            $blocker = $this->blocker($projectId);
            if ($blocker !== null) {
                throw new ApiException(409, 'schedule_connection_required', ['reason' => $blocker], null, [], [], ['reason' => $blocker]);
            }
        }
        $now = $this->clock->now();
        $nowIso = SystemClock::iso($now);
        $changes = $this->db->transaction(function (Db $db) use ($in, $userId, $projectId, $enabled, $days, $time, $now, $nowIso): array {
            $old = $db->one('SELECT * FROM project_schedules WHERE project_id = ?', [$projectId]);
            if ($old !== null) {
                $rev = $in['revision'] ?? null;
                if (!is_int($rev) || $rev !== (int) $old['revision']) {
                    throw new ApiException(409, 'schedule_conflict', [], null, [], [], ['revision' => (int) $old['revision']]);
                }
            }
            $wasOn = $old !== null && (int) $old['enabled'] === 1;
            $changes = [];
            foreach (['enabled' => [$wasOn, $enabled], 'interval_days' => [$old === null ? null : (int) $old['interval_days'], $days],
                'run_time' => [$old['run_time'] ?? null, $time]] as $f => [$a, $b]) {
                if ($a !== $b) {
                    $changes[$f] = [$a, $b];
                }
            }
            if ($old !== null && $changes === []) {
                return [];
            }
            // 켜거나 간격·시각이 바뀌면 바꾼 시점 이후 가장 가까운 지정 시각부터. 끄면 NULL
            $next = !$enabled ? null
                : ($wasOn && !isset($changes['interval_days']) && !isset($changes['run_time']) ? $old['next_run_at']
                    : ScheduleCalc::iso(ScheduleCalc::firstAfter($now, $time)));
            if ($old === null) {
                $db->execute('INSERT INTO project_schedules (project_id, enabled, interval_days, run_time, next_run_at, revision, created_at, updated_by, updated_at)'
                    . ' VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)', [$projectId, $enabled ? 1 : 0, $days, $time, $next, $nowIso, $userId, $nowIso]);
            } else {
                $n = $db->execute('UPDATE project_schedules SET enabled = ?, interval_days = ?, run_time = ?, next_run_at = ?, revision = revision + 1, updated_by = ?, updated_at = ?'
                    . ' WHERE project_id = ? AND revision = ?', [$enabled ? 1 : 0, $days, $time, $next, $userId, $nowIso, $projectId, (int) $old['revision']]);
                if ($n !== 1) {
                    throw new ApiException(409, 'schedule_conflict');
                }
            }
            return $changes + ['_next' => $next];
        }, write: true);
        if ($changes !== []) {
            $next = $changes['_next'];
            unset($changes['_next']);
            $this->audit->record($actor, 'schedule.save', 'project', (string) $projectId, ['changes' => $changes, 'next_run_at' => $next]);
        }
        $view = $this->view($projectId);
        $warnings = [];
        if ($enabled && $view['connection_status'] !== 'ok') {
            // 마지막 연결 테스트·가져오기가 실패였거나 아직 확인하지 않음 — 저장은 하고 알린다
            $warnings[] = $view['connection_status'] === 'error' ? 'connection_error' : 'connection_unchecked';
        }
        return $view + ['warnings' => $warnings];
    }

    /** 저장소 연결 해제 → 주기 자동 끔(감사 로그). 019 전 DB(표 없음)면 아무것도 하지 않는다 */
    public function disableForDisconnect(Actor $actor, int $projectId): void
    {
        try {
            $n = $this->db->execute('UPDATE project_schedules SET enabled = 0, next_run_at = NULL, revision = revision + 1, updated_at = ? WHERE project_id = ? AND enabled = 1',
                [SystemClock::iso($this->clock->now()), $projectId]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table: project_schedules')) {
                return;
            }
            throw $e;
        }
        if ($n === 1) {
            $this->audit->record($actor, 'schedule.auto_disable', 'project', (string) $projectId, ['reason' => 'repo_disconnected']);
        }
    }

    /**
     * 일반 사용자에게도 보이는 요약(프로젝트 화면·목록): 사용 중 여부·다음 예정. admin이면 연속 실패 횟수도.
     * 019 전 DB면 null.
     *
     * @return array<string, mixed>|null
     */
    public function publicSummary(int $projectId, bool $admin): ?array
    {
        try {
            $r = $this->db->one('SELECT enabled, interval_days, next_run_at, consecutive_failures, last_error_code FROM project_schedules WHERE project_id = ?', [$projectId]);
        } catch (\PDOException) {
            return null;
        }
        $on = $r !== null && (int) $r['enabled'] === 1;
        $out = ['enabled' => $on, 'next_run_at' => $on ? $r['next_run_at'] : null, 'interval_days' => $on ? (int) $r['interval_days'] : null];
        if ($admin) {
            $out += ['consecutive_failures' => (int) ($r['consecutive_failures'] ?? 0), 'last_error_code' => $r['last_error_code'] ?? null,
                'last_error_title' => self::errorTitle($r['last_error_code'] ?? null)];
        }
        return $out;
    }

    /**
     * 프로젝트 목록용 요약(여러 프로젝트 한 번에). 019 전 DB면 빈 배열.
     *
     * @param list<int> $projectIds
     * @return array<int, array<string, mixed>>
     */
    public function summaries(array $projectIds, bool $admin): array
    {
        if ($projectIds === []) {
            return [];
        }
        try {
            $rows = $this->db->all('SELECT project_id, next_run_at, interval_days, consecutive_failures, last_error_code FROM project_schedules WHERE enabled = 1 AND project_id IN (SELECT value FROM json_each(?))',
                [json_encode(array_values($projectIds))]);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['project_id']] = ['enabled' => true, 'next_run_at' => $r['next_run_at'], 'interval_days' => (int) $r['interval_days']]
                + ($admin ? ['consecutive_failures' => (int) $r['consecutive_failures'], 'last_error_code' => $r['last_error_code'],
                    'last_error_title' => self::errorTitle($r['last_error_code'])] : []);
        }
        return $out;
    }

    /** 분석 실패 코드의 짧은 제목(ErrorCatalog, 화면 표시용) */
    public static function errorTitle(mixed $code): ?string
    {
        return is_string($code) && $code !== '' ? (ErrorCatalog::describe('analysis', $code)['title'] ?? null) : null;
    }

    private static function invalid(string $field): ApiException
    {
        return new ApiException(400, 'invalid_schedule_input', ['field' => $field], null, [], [], ['field' => $field]);
    }
}
