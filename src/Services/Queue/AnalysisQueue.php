<?php

declare(strict_types=1);

namespace PJGuardian\Services\Queue;

use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Lang;
use PJGuardian\Support\SystemClock;

/**
 * 분석 큐(명세 5.2, 5.4, 19.8). 큐 = analyses.status='queued' 행의 등록 순서(id 오름차순, P08 설계).
 *
 * 스케줄링 규칙
 * - FIFO: 사용자 업로드·저장소 즉시 실행·주기 실행을 구분하지 않는다.
 * - 전체 동시 실행 analysis.max_concurrent(2), 프로젝트당 1개(DB 고유 인덱스 analyses_one_running_per_project로도 보장).
 * - 건너뛰기: 앞 작업이 같은 프로젝트의 실행 때문에 막혀 있으면 다른 프로젝트의 뒤 작업이 먼저 실행된다.
 * - 원자적 선점(claimNext): BEGIN IMMEDIATE 트랜잭션 안에서 "실행 수 확인 → 실행 가능한 가장 앞 작업 선택 → running으로 변경".
 *   쓰기 잠금을 트랜잭션 시작에 잡으므로 두 워커(또는 두 연결)가 동시에 불러도 순서대로 실행되고 같은 작업을 둘 다 가져가지 않는다.
 * - 삭제 중인 프로젝트: 프로젝트 삭제는 한 트랜잭션에서 "queued·running·조립·해제 중이면 거부(project_busy) → 행 삭제(연쇄)"를
 *   하므로, 선점과 삭제가 겹쳐도 선점된 작업은 삭제를 막고(busy), 삭제된 프로젝트의 작업은 행이 없어 선점되지 않는다.
 * - 취소 요청(cancel_requested=1)된 queued 작업은 선점하지 않는다(API가 즉시 cancelled로 바꾼다).
 * - 재시도 대기(P26): retry_after가 지금보다 뒤인 queued 작업(주기 분석 가져오기 재시도)은 선점하지 않는다. 그동안 뒤의 작업이 먼저 실행되고,
 *   시각이 지나면 원래 등록 순서(id)대로 다시 후보가 된다. 상태 전이 추가는 running → queued(ScheduleRetry::requeue) 하나뿐이다.
 */
final class AnalysisQueue
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** 019(재시도 열)가 적용된 DB인지. 적용 전 DB에서 새 코드의 워커가 떠도 선점이 멈추지 않게(재시도 조건만 뺀다) */
    private function retryColumns(): bool
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM pragma_table_info('analyses') WHERE name = 'retry_after'") === 1;
    }

    /**
     * 실행 가능한 가장 앞 작업을 running으로 바꿔 돌려준다. 없거나 동시 실행 한도면 null.
     *
     * @return array<string, mixed>|null 선점한 analyses 행
     */
    public function claimNext(int $maxConcurrent, int $workerPid): ?array
    {
        $now = $this->now();
        $retry = $this->retryColumns();
        return $this->db->transaction(function (Db $db) use ($maxConcurrent, $workerPid, $now, $retry): ?array {
            if ((int) $db->value("SELECT COUNT(*) FROM analyses WHERE status = 'running'") >= $maxConcurrent) {
                return null;
            }
            $id = $db->value(
                "SELECT a.id FROM analyses a WHERE a.status = 'queued' AND a.cancel_requested = 0"
                . ($retry ? ' AND (a.retry_after IS NULL OR a.retry_after <= ?)' : '')
                . " AND NOT EXISTS (SELECT 1 FROM analyses r WHERE r.project_id = a.project_id AND r.status = 'running')"
                . ' ORDER BY a.id LIMIT 1',
                $retry ? [$now] : [],
            );
            if ($id === null) {
                return null;
            }
            $n = $db->execute(
                "UPDATE analyses SET status = 'running', started_at = ?, worker_pid = ?, process_group_id = NULL" . ($retry ? ', retry_after = NULL' : '') . " WHERE id = ? AND status = 'queued'",
                [$now, $workerPid, $id],
            );
            return $n === 1 ? $db->one('SELECT * FROM analyses WHERE id = ?', [$id]) : null;
        });
    }

    /** 분석 자식 프로세스(트리 루트 PID, Linux는 PGID와 같음)를 기록한다(취소·복구 때 종료 대상) */
    public function setProcess(int $id, int $pid): void
    {
        $this->db->execute("UPDATE analyses SET process_group_id = ? WHERE id = ? AND status = 'running'", [$pid, $id]);
    }

    public function cancelRequested(int $id): bool
    {
        return (int) $this->db->value('SELECT cancel_requested FROM analyses WHERE id = ?', [$id]) === 1;
    }

    /**
     * 분석 자식이 결과를 기록한다. running이고 취소 요청이 없을 때만(취소·타임아웃으로 이미 끝났으면 false).
     * 실패·취소는 결함과 비교 기준을 건드리지 않는다(명세 5.4). 성공 결과의 결함 저장은 P16이 같은 트랜잭션에 넣는다.
     */
    public function complete(int $id, AnalysisOutcome $o): bool
    {
        return $this->db->transaction(function (Db $db) use ($id, $o): bool {
            $tool = $o->tool;
            if ($o->succeeded) {
                $c = $o->counts ?? [];
                return $db->execute(
                    "UPDATE analyses SET status = 'succeeded', finished_at = ?, total_count = ?, new_count = ?, resolved_count = ?,"
                    . ' reopened_count = ?, unchanged_count = ?, tool_version = COALESCE(?, tool_version), config_source = COALESCE(?, config_source),'
                    . ' config_hash = COALESCE(?, config_hash), ignored_configs = COALESCE(?, ignored_configs)'
                    . " WHERE id = ? AND status = 'running' AND cancel_requested = 0",
                    [$this->now(), $c['total_count'] ?? 0, $c['new_count'] ?? 0, $c['resolved_count'] ?? 0, $c['reopened_count'] ?? 0,
                        $c['unchanged_count'] ?? 0, ...self::toolColumns($tool), $id],
                ) === 1;
            }
            return $db->execute(
                "UPDATE analyses SET status = 'failed', finished_at = ?, failure_code = ?, failure_message = ?,"
                . ' tool_version = COALESCE(?, tool_version), config_source = COALESCE(?, config_source),'
                . ' config_hash = COALESCE(?, config_hash), ignored_configs = COALESCE(?, ignored_configs)'
                . " WHERE id = ? AND status = 'running' AND cancel_requested = 0",
                [$this->now(), $o->failureCode, self::failureMessage((string) $o->failureCode), ...self::toolColumns($tool), $id],
            ) === 1;
        });
    }

    /**
     * 도구 정보 → [tool_version, config_source, config_hash, ignored_configs(JSON)]. 없는 값은 null(COALESCE로 기존 값 유지).
     *
     * @param array<string, mixed> $tool
     * @return list<?string>
     */
    private static function toolColumns(array $tool): array
    {
        $hash = $tool['config_hash'] ?? null;
        $ignored = $tool['ignored_configs'] ?? null;
        return [
            isset($tool['tool_version']) ? (string) $tool['tool_version'] : null,
            in_array($tool['config_source'] ?? null, ['upload', 'server_default'], true) ? $tool['config_source'] : null,
            is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : null,
            is_array($ignored) ? (string) json_encode(array_values($ignored), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];
    }

    /** 워커가 실행 중 분석을 실패로 끝낸다(타임아웃, 자식 비정상 종료, 워커 비정상 종료 복구). 바꿨으면 true */
    public function fail(int $id, string $code): bool
    {
        return $this->db->execute(
            "UPDATE analyses SET status = 'failed', finished_at = ?, failure_code = ?, failure_message = ? WHERE id = ? AND status = 'running'",
            [$this->now(), $code, self::failureMessage($code), $id],
        ) === 1;
    }

    /** 워커가 실행 중 분석을 취소로 끝낸다(자식 트리를 종료한 뒤). 바꿨으면 true */
    public function markCancelled(int $id): bool
    {
        return $this->db->execute(
            "UPDATE analyses SET status = 'cancelled', finished_at = ? WHERE id = ? AND status = 'running'",
            [$this->now(), $id],
        ) === 1;
    }

    /** 실패 코드의 사용자 문구(DB failure_message에 저장, 화면은 이것을 textContent로) */
    public static function failureMessage(string $code): string
    {
        $key = 'analysis.failure.' . $code;
        return Lang::has($key) ? Lang::get($key) : Lang::get('analysis.failure.unknown', ['code' => $code]);
    }

    /**
     * 대기 순번(화면 "대기 3번째"): 이 작업보다 먼저 등록되어 아직 대기 중인 작업 수 + 1. queued가 아니거나 취소 요청됐으면 null.
     * 건너뛰기(프로젝트당 1개) 때문에 실제 시작 순서와 다를 수 있다(근사값).
     */
    public function position(int $id): ?int
    {
        $row = $this->db->one('SELECT status, cancel_requested FROM analyses WHERE id = ?', [$id]);
        if ($row === null || $row['status'] !== 'queued' || (int) $row['cancel_requested'] === 1) {
            return null;
        }
        return 1 + (int) $this->db->value("SELECT COUNT(*) FROM analyses WHERE status = 'queued' AND cancel_requested = 0 AND id < ?", [$id]);
    }

    /** @return list<array<string, mixed>> 실행 중으로 남은 분석(워커 비정상 종료 복구 대상) */
    public function running(): array
    {
        return $this->db->all("SELECT id, project_id, upload_id, work_dir, worker_pid, process_group_id, started_at FROM analyses WHERE status = 'running' ORDER BY id");
    }

    public function hasClaimable(): bool
    {
        return $this->retryColumns()
            ? $this->db->value("SELECT 1 FROM analyses WHERE status = 'queued' AND cancel_requested = 0 AND (retry_after IS NULL OR retry_after <= ?) LIMIT 1", [$this->now()]) !== null
            : $this->db->value("SELECT 1 FROM analyses WHERE status = 'queued' AND cancel_requested = 0 LIMIT 1") !== null;
    }
}
