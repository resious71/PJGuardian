<?php

declare(strict_types=1);

namespace PJGuardian\Services\Runs;

use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Support\Db;

/**
 * 분석 반영(DefectStore) 트랜잭션 안에서 Run 번호·존재 구간·Run 요약을 기록한다(P20R, 마이그레이션 011).
 * DefectStore의 판정(재오픈·자동 resolved·범위 밖)은 바꾸지 않고, 그 결과를 구간으로 옮긴다. 변경된 결함만 쓴다:
 *   신규 → 구간 시작, 감지됐는데 열린 구간이 없음(재오픈·닫혔다 다시 감지) → 새 구간, 자동 resolved → 구간 닫기(to_run = 직전 Run),
 *   **수동 resolved인데 이번에 감지되지 않음** → DefectStore는 이력을 남기지 않지만(P16: resolved는 자동 전환 대상이 아님) 존재는
 *   도구 기준이므로 같은 판정(제외 규칙 → 범위 밖, 분석 파일 목록에 있음 → 미감지, 파일 있음 → 범위 밖, 없음 → 삭제)으로 닫는다.
 *   유지(감지 + 열린 구간 있음)와 범위 밖 유지는 쓰지 않는다.
 *
 * 되채움이 끝나지 않은 프로젝트(번호 없는 성공 분석이 있음)는 번호만 부여하고 구간은 건드리지 않는다(되채움이 전체를 다시 만든다).
 */
final class RunTracker
{
    /** 이 프로젝트의 성공 분석에 모두 Run 번호가 있는가(되채움 완료 또는 새 프로젝트) */
    public static function ready(Db $db, int $projectId): bool
    {
        return $db->value("SELECT 1 FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND run_number IS NULL LIMIT 1", [$projectId]) === null;
    }

    /** 이번 성공에 줄 Run 번호(성공한 분석 수 + 1: 되채움이 종료 시각 순으로 매기는 번호와 같다). "변경 없음"(P27)은 Run이 아니므로 세지 않는다 */
    public static function nextRun(Db $db, int $projectId): int
    {
        return (int) $db->value("SELECT COUNT(*) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0", [$projectId]) + 1;
    }

    /** 신규 결함(first_seen = 이번 분석)의 구간 시작 */
    public static function openNew(Db $db, int $projectId, int $analysisId, int $run): void
    {
        $db->execute('INSERT INTO defect_runs (defect_id, project_id, from_run, to_run) SELECT id, project_id, ?, NULL FROM defects WHERE project_id = ? AND first_seen_analysis_id = ?',
            [$run, $projectId, $analysisId]);
    }

    /** 이번에 감지됐는데 열린 구간이 없는 기존 결함(재오픈, 닫힌 뒤 다시 감지) → 새 구간 */
    public static function reopenDetected(Db $db, int $projectId, int $run): int
    {
        return $db->execute(
            'INSERT INTO defect_runs (defect_id, project_id, from_run, to_run) SELECT d.id, d.project_id, ?, NULL FROM temp.cur_defects c'
            . ' JOIN defects d ON d.id = c.defect_id WHERE c.noint = 1 AND d.project_id = ?' // P19C: 짝(defect_id)·구간 없음 표시(noint)는 DefectStore가 쓰기 전에 채운다
            . ' AND NOT EXISTS (SELECT 1 FROM defect_runs r WHERE r.defect_id = d.id AND r.to_run IS NULL)',
            [$run, $projectId],
        );
    }

    /**
     * 이번에 존재하지 않게 된 결함의 구간을 닫는다: 자동 resolved(temp.auto_resolve) + 수동 resolved인데 미감지·삭제
     * (P19C: 이번에 감지되지 않은 기존 결함과 그 분류는 DefectStore가 쓰기 트랜잭션 전에 temp.unseen(reason NULL = 범위 밖)에 둔다).
     *
     * @return array{auto:int, manual:int}
     */
    public static function close(Db $db, int $run): array
    {
        $auto = $db->execute('UPDATE defect_runs SET to_run = ? WHERE to_run IS NULL AND defect_id IN (SELECT id FROM temp.auto_resolve)', [$run - 1]);
        // 프로젝트 전체를 훑지 않고 미감지 후보(temp.unseen)에서만 — 이번 반영이 만든 결함은 후보에 없다(first_seen = 이번 분석)
        $manual = $db->execute(
            'UPDATE defect_runs SET to_run = ? WHERE to_run IS NULL AND defect_id IN (SELECT u.id FROM temp.unseen u JOIN defects d ON d.id = u.id'
            . " WHERE d.status = 'resolved' AND u.reason IS NOT NULL AND u.id NOT IN (SELECT id FROM temp.auto_resolve))",
            [$run - 1],
        );
        return ['auto' => $auto, 'manual' => $manual];
    }

    /**
     * 그 Run에 존재하는(열린 구간) 결함의 상태·심각도·담당자별 건수(Run 종료 시점 = 지금 결함 표 값).
     *
     * @return array{total:int, by_status:array<string,int>, by_severity:array<string,int>, by_assignee:array<string,int>}
     */
    public static function summaryOpen(Db $db, int $projectId): array
    {
        return self::summarize($db->all(
            'SELECT d.status AS st, d.severity AS s, d.assignee_id AS a, COUNT(*) AS n FROM defects d JOIN defect_runs r ON r.defect_id = d.id AND r.to_run IS NULL'
            . ' WHERE d.project_id = ? GROUP BY d.status, d.severity, d.assignee_id',
            [$projectId],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows st, s, a, n
     * @return array{total:int, by_status:array<string,int>, by_severity:array<string,int>, by_assignee:array<string,int>}
     */
    public static function summarize(array $rows): array
    {
        $out = ['total' => 0, 'by_status' => array_fill_keys(DefectFilter::STATUSES, 0), 'by_severity' => array_fill_keys(DefectFilter::SEVERITIES, 0), 'by_assignee' => []];
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            $out['total'] += $n;
            $out['by_status'][(string) $r['st']] += $n;
            $out['by_severity'][(string) $r['s']] += $n;
            $key = $r['a'] === null ? 'none' : (string) (int) $r['a'];
            $out['by_assignee'][$key] = ($out['by_assignee'][$key] ?? 0) + $n;
        }
        ksort($out['by_assignee'], SORT_STRING);
        return $out;
    }

    /** Run 종료 표지 = 지금 defect_history의 마지막 id(이 트랜잭션의 자동 이력 포함) */
    public static function historyMark(Db $db): int
    {
        return (int) ($db->value('SELECT MAX(id) FROM defect_history') ?? 0);
    }
}
