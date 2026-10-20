<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Support\Db;

/**
 * 결함의 "마지막 감지 분석"(last_seen)을 읽는 **유일한** 방법(P19C, 마이그레이션 023). 화면·API·내보내기·대시보드·Run·스냅샷이 모두 이것을 쓴다.
 *
 * 저장(DefectStore):
 *   seen_live = 1 → 프로젝트의 최신 실제 성공 분석(status succeeded, no_change = 0의 최대 id)에서 감지됨. last_seen_analysis_id는 오래된 값일 수 있다.
 *   seen_live = 0 → last_seen_analysis_id가 마지막 감지 분석 그대로(해결·범위 밖 유지·023 전 행).
 * 유지(계속 감지) 결함은 분석마다 쓰지 않는다 — 감지 → 미감지가 될 때만 그때의 최신 분석으로 고정한다. 읽는 값은 이전(행마다 갱신하던 때)과 같다.
 *
 * 최신 분석 id는 분석 행 인덱스 analyses_project_status_nochange_id(022)로 한 번에 찾는다. 프로젝트 하나를 읽을 때는 latest()로 한 번 구해
 * 매개변수로 넘기고(행마다 하위 질의 없음), 여러 프로젝트를 읽을 때(내 결함)는 상관 하위 질의를 쓴다.
 */
final class DefectSeen
{
    /** 프로젝트의 최신 실제 성공 분석(상관 하위 질의) */
    private const LATEST_SQL = "(SELECT MAX(sa.id) FROM analyses sa WHERE sa.project_id = %s.project_id AND sa.status = 'succeeded' AND sa.no_change = 0)";

    /**
     * 마지막 감지 분석 id 식. $latest를 주면(한 프로젝트) 그 값을 매개변수 하나(?)로 쓴다 — 호출자가 params에 latest()를 넣는다.
     *
     * @param string $alias 결함 표 별칭(영문자만)
     */
    public static function expr(string $alias = 'd', bool $bound = false): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('잘못된 별칭');
        }
        $live = $bound ? 'CAST(? AS INTEGER)' : sprintf(self::LATEST_SQL, $alias);
        return "(CASE WHEN {$alias}.seen_live = 1 THEN {$live} ELSE {$alias}.last_seen_analysis_id END)";
    }

    /**
     * 한 프로젝트 목록 정렬용(P19C): 감지 중 행의 마지막 감지 Run 번호·종료 시각은 모두 최신 분석의 값이므로 정렬 식에 값으로 넣는다(행마다 하위 질의 없음).
     * 감지 중이 아닌 행만 기존처럼 분석 행을 기본 키로 읽는다.
     *
     * @return array{run: ?int, at: ?string}
     */
    public static function liveValues(Db $db, int $projectId): array
    {
        $r = $db->one('SELECT run_number, finished_at FROM analyses WHERE id = ?', [self::latest($db, $projectId)]);
        return ['run' => $r === null || $r['run_number'] === null ? null : (int) $r['run_number'], 'at' => $r['finished_at'] ?? null];
    }

    /**
     * 마지막 감지 분석의 열(run_number 또는 finished_at) 식과 매개변수 — 목록 정렬용.
     * $live(liveValues, 한 프로젝트)를 주면 감지 중 행은 그 값을 매개변수로 쓰고 나머지 행만 분석 행을 기본 키로 읽는다.
     * 주지 않으면(여러 프로젝트) expr()을 상관 하위 질의로 쓴다.
     *
     * @param array{run: ?int, at: ?string}|null $live
     * @return array{0: string, 1: list<mixed>}
     */
    public static function analysisColumn(string $column, ?array $live = null): array
    {
        $key = ['run_number' => 'run', 'finished_at' => 'at'][$column] ?? throw new \InvalidArgumentException('잘못된 열');
        if ($live === null) {
            return ["(SELECT a.{$column} FROM analyses a WHERE a.id = " . self::expr('d') . ')', []];
        }
        // run_number는 정수 비교가 되도록(매개변수는 문자열로 묶인다)
        $value = $column === 'run_number' ? 'CAST(? AS INTEGER)' : '?';
        return ["(CASE WHEN d.seen_live = 1 THEN {$value} ELSE (SELECT a.{$column} FROM analyses a WHERE a.id = d.last_seen_analysis_id) END)", [$live[$key]]];
    }

    /** 프로젝트의 최신 실제 성공 분석 id(없으면 0 — seen_live 행이 있을 수 없다) */
    public static function latest(Db $db, int $projectId): int
    {
        return (int) ($db->value("SELECT MAX(id) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0", [$projectId]) ?? 0);
    }

    /**
     * updated_at(상세 API)의 읽기 값: 예전에는 감지될 때마다 반영 시각으로 갱신했다 → 감지 중(seen_live) 행은 최신 분석 종료 시각과 저장 값 중 늦은 것.
     *
     * @param array<string, mixed> $row seen_live, updated_at
     */
    public static function updatedAt(array $row, ?string $latestFinishedAt): string
    {
        $u = (string) $row['updated_at'];
        if ((int) ($row['seen_live'] ?? 0) === 1 && $latestFinishedAt !== null && $latestFinishedAt > $u) {
            return $latestFinishedAt;
        }
        return $u;
    }
}
