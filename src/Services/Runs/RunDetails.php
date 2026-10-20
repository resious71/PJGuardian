<?php

declare(strict_types=1);

namespace PJGuardian\Services\Runs;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * Run 상세 보관과 삭제(P21B, 마이그레이션 013, docs/architecture.md 35절).
 *
 * "상세 삭제"는 analyses 행에 표식(run_detail_deleted_at·by·reason)만 남기는 정리다. 삭제된 Run은 과거 Run 보기(run=N)만 막고(410),
 * Run 번호·종료 표지·Run 요약(건수)·존재 구간·결함 이력·결함 표는 그대로다 — 다른 Run의 보기와 히스토리 표·대시보드 추이가 이 값들을 쓴다.
 *
 * - 자동 보관(retention): 프로젝트에서 상세가 남은 Run이 keep개를 넘으면 가장 오래된 것부터 표시한다. 최신 Run은 절대 대상이 아니다.
 *   수동으로 지운 Run은 keep 계산에서 빠진다(상세가 남은 Run만 센다). 한 번에 최대 limit개, 남으면 다음에 이어서. 여러 번 해도 결과가 같다.
 * - 수동(manual): admin이 고른 Run들. 최신 Run이 섞이면 전체 거부(409), 없는 번호는 404, 이미 지운 Run은 건너뛴다. 한 요청 = 한 트랜잭션.
 * - 둘 다 감사 로그 run.detail_delete(대상 project, 세부: runs, reason). 접근·권한 판정은 라우트(RequireProjectAccess → RequireAdmin)가 먼저 한다.
 */
final class RunDetails
{
    public const MANUAL_MAX = 100;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit, private readonly Clock $clock)
    {
    }

    /** @return array{at:string, reason:string, by:?int}|null 분석 행의 상세 삭제 상태 */
    public static function stateOf(array $row): ?array
    {
        $at = $row['run_detail_deleted_at'] ?? null;
        if ($at === null) {
            return null;
        }
        return ['at' => (string) $at, 'reason' => (string) $row['run_detail_deleted_reason'], 'by' => $row['run_detail_deleted_by'] === null ? null : (int) $row['run_detail_deleted_by']];
    }

    /** @return array{kept:int, deleted:int, latest:?int} 상세가 남은 Run 수, 삭제된 Run 수, 최신 Run */
    public function counts(int $projectId): array
    {
        $r = $this->db->one('SELECT SUM(run_detail_deleted_at IS NULL) AS kept, SUM(run_detail_deleted_at IS NOT NULL) AS deleted, MAX(run_number) AS latest'
            . ' FROM analyses WHERE project_id = ? AND run_number IS NOT NULL', [$projectId]);
        return ['kept' => (int) ($r['kept'] ?? 0), 'deleted' => (int) ($r['deleted'] ?? 0), 'latest' => $r['latest'] === null ? null : (int) $r['latest']];
    }

    /** @return list<int> 상세가 남은 Run 번호(오래된 순) */
    public function keptRuns(int $projectId): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT run_number FROM analyses WHERE project_id = ? AND run_number IS NOT NULL AND run_detail_deleted_at IS NULL ORDER BY run_number', [$projectId]), 'run_number'));
    }

    /**
     * 자동 보관 대상(다음 자동 정리가 지울 Run 번호, 오래된 순). keep이 0이면 없음.
     *
     * @return list<int>
     */
    public function retentionCandidates(int $projectId, int $keep, ?int $limit = null): array
    {
        if ($keep <= 0) {
            return [];
        }
        $kept = $this->keptRuns($projectId);
        $latest = $this->counts($projectId)['latest'];
        $over = count($kept) - $keep;
        if ($over <= 0) {
            return [];
        }
        $out = array_values(array_filter(array_slice($kept, 0, $over), static fn (int $n): bool => $n !== $latest));
        return $limit === null ? $out : array_slice($out, 0, $limit);
    }

    /**
     * 자동 보관: 한 프로젝트에서 keep개를 넘는 오래된 Run의 상세를 지운다(최대 limit개, 짧은 쓰기 트랜잭션 하나).
     *
     * @return list<int> 지운 Run 번호
     */
    public function retain(int $projectId, int $keep, int $limit): array
    {
        $runs = $this->retentionCandidates($projectId, $keep, $limit);
        if ($runs === []) {
            return [];
        }
        $now = SystemClock::iso($this->clock->now());
        $done = $this->db->transaction(function (Db $db) use ($projectId, $runs, $now): array {
            $latest = (int) $db->value('SELECT MAX(run_number) FROM analyses WHERE project_id = ?', [$projectId]);
            $out = [];
            foreach ($runs as $n) {
                if ($n === $latest) {
                    continue; // 그사이 새 Run이 생겨도 최신은 지우지 않는다(같은 트랜잭션에서 다시 확인)
                }
                $ok = $db->execute("UPDATE analyses SET run_detail_deleted_at = ?, run_detail_deleted_by = NULL, run_detail_deleted_reason = 'retention'"
                    . ' WHERE project_id = ? AND run_number = ? AND run_detail_deleted_at IS NULL', [$now, $projectId, $n]);
                if ($ok === 1) {
                    $out[] = $n;
                }
            }
            if ($out !== []) {
                $this->audit->record(Actor::system(), 'run.detail_delete', 'project', (string) $projectId, ['runs' => $out, 'reason' => 'retention']);
            }
            return $out;
        }, write: true);
        return $done;
    }

    /**
     * 수동 삭제(admin). $runs: 요청 본문의 Run 번호 배열(정수, 1~100개, 중복은 합침).
     *
     * @param array<string, mixed> $user
     * @return array{deleted:list<int>, already:list<int>}
     */
    public function deleteManual(array $user, ?string $ip, int $projectId, mixed $runs): array
    {
        if (!is_array($runs) || $runs === [] || !array_is_list($runs) || count($runs) > self::MANUAL_MAX) {
            throw new ApiException(400, 'invalid_run_set', ['max' => self::MANUAL_MAX]);
        }
        foreach ($runs as $n) {
            if (!is_int($n) || $n < 1 || $n > 999_999_999) {
                throw new ApiException(400, 'invalid_run_set', ['max' => self::MANUAL_MAX]);
            }
        }
        $runs = array_values(array_unique($runs));
        sort($runs);
        $now = SystemClock::iso($this->clock->now());
        $actor = Actor::fromUser($user, $ip);
        return $this->db->transaction(function (Db $db) use ($projectId, $runs, $now, $user, $actor): array {
            $rows = [];
            foreach ($db->all('SELECT run_number, run_detail_deleted_at FROM analyses WHERE project_id = ? AND run_number IN (SELECT value FROM json_each(?))',
                [$projectId, json_encode($runs)]) as $r) {
                $rows[(int) $r['run_number']] = $r['run_detail_deleted_at'];
            }
            $missing = array_values(array_diff($runs, array_keys($rows)));
            if ($missing !== []) {
                throw new ApiException(404, 'run_not_found', [], null, [], [], ['runs' => $missing]);
            }
            $latest = (int) $db->value('SELECT MAX(run_number) FROM analyses WHERE project_id = ?', [$projectId]);
            if (in_array($latest, $runs, true)) {
                throw new ApiException(409, 'run_latest_not_deletable', ['run' => $latest], null, [], [], ['runs' => [$latest]]);
            }
            $deleted = [];
            $already = [];
            foreach ($runs as $n) {
                if ($rows[$n] !== null) {
                    $already[] = $n;
                    continue;
                }
                $db->execute("UPDATE analyses SET run_detail_deleted_at = ?, run_detail_deleted_by = ?, run_detail_deleted_reason = 'manual'"
                    . ' WHERE project_id = ? AND run_number = ? AND run_detail_deleted_at IS NULL', [$now, (int) $user['id'], $projectId, $n]);
                $deleted[] = $n;
            }
            if ($deleted !== []) {
                $this->audit->record($actor, 'run.detail_delete', 'project', (string) $projectId, ['runs' => $deleted, 'reason' => 'manual']);
            }
            return ['deleted' => $deleted, 'already' => $already];
        }, write: true);
    }

    /**
     * 관리용 Run 목록(최신순, 쪽 나누기): Run 번호·분석·날짜·열린 결함·신규·해결·재오픈·도구 버전·상세 상태.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function adminList(int $projectId, int $limit, int $offset): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ? AND run_number IS NOT NULL', [$projectId]);
        $latest = $this->counts($projectId)['latest'];
        $rows = $this->db->all(
            'SELECT a.id, a.run_number, a.finished_at, a.tool, a.tool_version, a.new_count, a.resolved_count, a.reopened_count, a.run_summary,'
            . ' a.run_detail_deleted_at, a.run_detail_deleted_by, a.run_detail_deleted_reason, u.display_name AS deleted_by_name'
            . ' FROM analyses a LEFT JOIN users u ON u.id = a.run_detail_deleted_by'
            . ' WHERE a.project_id = ? AND a.run_number IS NOT NULL ORDER BY a.run_number DESC LIMIT ? OFFSET ?',
            [$projectId, $limit, $offset],
        );
        $out = [];
        foreach ($rows as $r) {
            $summary = is_string($r['run_summary']) ? json_decode($r['run_summary'], true) : null;
            $state = self::stateOf($r);
            $out[] = [
                'run' => (int) $r['run_number'],
                'analysis_id' => (int) $r['id'],
                'finished_at' => $r['finished_at'],
                'open' => is_array($summary) ? (int) $summary['total'] - (int) ($summary['by_status']['resolved'] ?? 0) : null,
                'new' => (int) $r['new_count'], 'resolved' => (int) $r['resolved_count'], 'reopened' => (int) $r['reopened_count'],
                'tool_name' => (string) $r['tool'], 'tool_version' => $r['tool_version'],
                'latest' => (int) $r['run_number'] === $latest,
                'detail_deleted' => $state === null ? null : $state + ['by_name' => $r['deleted_by_name']],
            ];
        }
        return [$out, $total];
    }
}
