<?php

declare(strict_types=1);

namespace PJGuardian\Services\Runs;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Analysis\AnalysisComparison;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Support\Db;

/**
 * Run(성공한 분석의 회차) 조회·과거 Run 보기(P20R). 정의는 docs/architecture.md 30절.
 *
 * - Run N에 **존재**한 결함 = 구간(defect_runs)이 N을 덮는 결함.
 * - 과거 Run의 상태·담당자·심각도 = 결함 표의 현재 값에서 **Run N 종료 표지(run_history_mark) 뒤의 첫 이력의 이전 값**으로 되돌린 값.
 *   표지 뒤 이력이 없으면 현재 값. 최신 Run의 표지 뒤에는 그 뒤 수동 변경만 있으므로, 최신 Run 복원 = 현재 값(이후 수동 변경이 없을 때).
 * - `run` 생략(또는 최신 Run 번호) = 지금처럼 결함 표 전체·현재 값(기존 동작). 과거 Run만 존재 집합 + 복원 값.
 * - 줄·메시지는 Run별로 저장하지 않는다(결함 표의 마지막 감지 값 = "최신 감지 위치").
 */
final class RunService
{
    public function __construct(private readonly Db $db)
    {
    }

    /** 프로젝트의 Run 준비 상태: 번호 없는 성공 분석 수(되채움 필요), 최신 Run */
    public function status(int $projectId): array
    {
        $missing = (int) $this->db->value("SELECT COUNT(*) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND run_number IS NULL", [$projectId]);
        $latest = $this->db->value('SELECT MAX(run_number) FROM analyses WHERE project_id = ?', [$projectId]);
        return ['latest' => $latest === null ? null : (int) $latest, 'backfill_needed' => $missing > 0, 'unnumbered' => $missing];
    }

    /**
     * `run` 쿼리 값을 해석한다. 생략·최신 Run → null(현재 보기). 정수가 아니면 422 invalid_run, 이 프로젝트에 없는 번호는 404 run_not_found,
     * 상세가 삭제된 Run(P21B)은 410 run_detail_deleted(최신 Run은 삭제될 수 없으므로 최신 보기는 영향 없음).
     *
     * @return array{run:int, analysis_id:int, mark:int, finished_at:?string, latest:int}|null
     */
    public function resolve(int $projectId, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value) || preg_match('/^[1-9][0-9]{0,8}$/', (string) $value) !== 1) {
            throw new ApiException(422, 'invalid_run');
        }
        $row = $this->runRow($projectId, (int) $value);
        $latest = (int) $this->db->value('SELECT MAX(run_number) FROM analyses WHERE project_id = ?', [$projectId]);
        if ($row['run'] === $latest) {
            return null; // 최신 Run = 현재 보기
        }
        if ($row['detail_deleted'] !== null) {
            throw new ApiException(410, 'run_detail_deleted', ['run' => $row['run']], null, [], [], ['run' => $row['run'], 'latest' => $latest]);
        }
        unset($row['detail_deleted']);
        return $row + ['latest' => $latest];
    }

    /** @return array{run:int, analysis_id:int, mark:int, finished_at:?string, detail_deleted:?array} 없으면 404 run_not_found */
    public function runRow(int $projectId, int $run): array
    {
        $r = $this->db->one('SELECT id, run_number, run_history_mark, finished_at, run_detail_deleted_at, run_detail_deleted_by, run_detail_deleted_reason'
            . ' FROM analyses WHERE project_id = ? AND run_number = ?', [$projectId, $run]);
        if ($r === null) {
            throw new ApiException(404, 'run_not_found');
        }
        return ['run' => (int) $r['run_number'], 'analysis_id' => (int) $r['id'], 'mark' => (int) ($r['run_history_mark'] ?? 0), 'finished_at' => $r['finished_at'],
            'detail_deleted' => RunDetails::stateOf($r)];
    }

    /**
     * Run 목록(최신순). total·by_severity·by_status는 저장된 Run 요약(그 Run에 존재한 결함), 없으면(되채움 전) 분석 통계.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(int $projectId, int $limit, int $offset): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM analyses WHERE project_id = ? AND run_number IS NOT NULL', [$projectId]);
        // 표식 판정에 직전 Run이 필요하므로 한 개 더 읽는다
        $rows = $this->db->all(
            'SELECT a.id, a.run_number, a.finished_at, a.tool, a.tool_version, a.config_source, a.config_hash, a.total_count, a.new_count, a.resolved_count,'
            . ' a.reopened_count, a.severity_counts, a.run_summary, a.no_change, a.source_kind, a.source_ref, a.source_revision, u.file_name AS upload_file_name,'
            . ' a.run_detail_deleted_at, a.run_detail_deleted_by, a.run_detail_deleted_reason'
            . ' FROM analyses a LEFT JOIN uploads u ON u.id = a.upload_id WHERE a.project_id = ? AND a.run_number IS NOT NULL ORDER BY a.run_number DESC LIMIT ? OFFSET ?',
            [$projectId, $limit + 1, $offset],
        );
        $out = [];
        foreach (array_slice($rows, 0, $limit) as $i => $r) {
            $prev = $rows[$i + 1] ?? ($r['run_number'] > 1 ? $this->db->one('SELECT tool, tool_version, config_source, config_hash FROM analyses WHERE project_id = ? AND run_number = ?', [$projectId, (int) $r['run_number'] - 1]) : null);
            $out[] = $this->format($r, $prev);
        }
        $links = (new \PJGuardian\Services\Repo\RepoLinks($this->db))->forAnalyses(array_column($out, 'analysis_id'));
        foreach ($out as &$o) {
            $o['source']['link'] = $links[$o['analysis_id']] ?? null;
        }
        unset($o);
        return [$out, $total];
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $prev 직전 Run의 분석 행
     * @return array<string, mixed>
     */
    public function format(array $r, ?array $prev): array
    {
        $summary = is_string($r['run_summary'] ?? null) ? json_decode($r['run_summary'], true) : null;
        $sev = is_string($r['severity_counts'] ?? null) ? json_decode($r['severity_counts'], true) : null;
        $diff = AnalysisComparison::differences($prev, $r);
        return [
            'run' => (int) $r['run_number'],
            'analysis_id' => (int) $r['id'],
            'finished_at' => $r['finished_at'],
            'total' => is_array($summary) ? (int) $summary['total'] : (int) ($r['total_count'] ?? 0),
            'by_severity' => array_map('intval', array_replace(array_fill_keys(DefectFilter::SEVERITIES, 0), is_array($summary) ? $summary['by_severity'] : (is_array($sev) ? $sev : []))),
            'by_status' => is_array($summary) ? array_map('intval', $summary['by_status']) : null,
            'summary_stored' => is_array($summary),
            'new' => (int) ($r['new_count'] ?? 0), 'resolved' => (int) ($r['resolved_count'] ?? 0), 'reopened' => (int) ($r['reopened_count'] ?? 0),
            'tool_name' => (string) $r['tool'], 'tool_version' => $r['tool_version'], 'config_source' => $r['config_source'],
            'changed' => $diff !== [], 'changes' => $diff, 'no_change' => (int) ($r['no_change'] ?? 0) === 1,
            'source' => ['kind' => (string) $r['source_kind'], 'ref' => $r['source_ref'] ?? null, 'file_name' => $r['upload_file_name'] ?? null,
                'revision' => $r['source_revision'] ?? null, 'link' => null], // P25: 커밋/changelist(링크는 list가 채움)
            // P21B: 상세 삭제 상태(삭제 시각·사유). 삭제된 Run은 선택기에서 고를 수 없다(run=N은 410). 사용자 id는 화면에 내지 않는다
            'detail_deleted' => ($s = RunDetails::stateOf($r)) === null ? null : ['at' => $s['at'], 'reason' => $s['reason']],
        ];
    }

    // ---- 과거 Run 보기(존재 집합 + 복원 값) ----

    /** 결함 하나의 필드를 표지 뒤 첫 이력의 이전 값으로 되돌리는 SQL 조각(별칭 $alias, 표지 매개변수 하나) */
    private static function restoreSql(string $field, string $alias): string
    {
        $sub = "(SELECT %s FROM defect_history h WHERE h.defect_id = {$alias}.id AND h.field = '{$field}' AND h.id > ? ORDER BY h.id LIMIT 1)";
        return match ($field) {
            'assignee' => 'CAST(NULLIF(COALESCE(' . sprintf($sub, "COALESCE(h.old_value, '')") . ", {$alias}.assignee_id), '') AS INTEGER)",
            default => 'COALESCE(' . sprintf($sub, 'h.old_value') . ", {$alias}.{$field})",
        };
    }

    /**
     * Run N에 존재한 결함을 Run N 종료 시점 값으로 보여 주는 FROM 원본(별칭 d, 결함 표와 같은 열 이름). DefectFilter·정렬을 그대로 쓴다.
     *
     * @param array{run:int, mark:int} $run
     * @return array{0:string, 1:list<int>}
     */
    public function source(int $projectId, array $run): array
    {
        // P31 B-3: 행마다 하위 쿼리 3개(restoreSql)로 표지 뒤 첫 이력을 찾던 것을, 표지 뒤 이력만 한 번 모아(chg — 보통 결함의 일부)
        // 결함별로 펼쳐(필드마다 "바뀐 적 있음" 표지 + 첫 이력의 이전 값) 결함 표와 한 번 잇는 형태로. 값의 뜻은 restoreSql과 같다
        // (표지 뒤 첫 변경의 이전 값, 없으면 지금 값 — 담당자의 빈 문자열은 미지정). 100만 건 과거 Run 필터·요약·마지막 쪽 측정은 docs/performance.md 4절.
        $sql = '(WITH f AS (SELECT defect_id, field, MIN(id) AS hid FROM defect_history WHERE id > ? AND field IN (\'severity\', \'status\', \'assignee\')'
            . ' GROUP BY defect_id, field),'
            . ' chg AS (SELECT f.defect_id,'
            . " MAX(f.field = 'severity') AS sev_set, MAX(CASE WHEN f.field = 'severity' THEN h.old_value END) AS sev_old,"
            . " MAX(f.field = 'status') AS st_set, MAX(CASE WHEN f.field = 'status' THEN h.old_value END) AS st_old,"
            . " MAX(f.field = 'assignee') AS asg_set, MAX(CASE WHEN f.field = 'assignee' THEN COALESCE(h.old_value, '') END) AS asg_old"
            . ' FROM f JOIN defect_history h ON h.id = f.hid GROUP BY f.defect_id)'
            . ' SELECT d0.id, d0.project_id, d0.signature, d0.ordinal, d0.tool, d0.language, d0.rule_key, d0.file_path, d0.line_no, d0.col_no,'
            . ' d0.end_line_no, d0.end_col_no, d0.message,'
            . ' CASE WHEN c.sev_set = 1 THEN COALESCE(c.sev_old, d0.severity) ELSE d0.severity END AS severity,'
            . ' CASE WHEN c.st_set = 1 THEN COALESCE(c.st_old, d0.status) ELSE d0.status END AS status,'
            . " CASE WHEN c.asg_set = 1 THEN CAST(NULLIF(c.asg_old, '') AS INTEGER) ELSE d0.assignee_id END AS assignee_id,"
            . ' d0.version, d0.first_seen_analysis_id, d0.last_seen_analysis_id, d0.seen_live, d0.created_at, d0.updated_at'
            . ' FROM defect_runs r JOIN defects d0 ON d0.id = r.defect_id LEFT JOIN chg c ON c.defect_id = d0.id'
            . ' WHERE r.project_id = ? AND r.from_run <= ? AND (r.to_run IS NULL OR r.to_run >= ?)) d';
        return [$sql, [$run['mark'], $projectId, $run['run'], $run['run']]];
    }

    /**
     * Run N에 존재한 결함(별칭 d, 결함 표 값 그대로 — 복원 없음). 복원 값이 필요 없는 고르기·집계(규칙 칩, P20S 목록 최적화)용. 두 형태:
     * - 'join'(기본): 존재 구간 표에서 그 Run의 결함만 골라 결함 표와 잇는다. 건수·집계·깊은 페이지·검색처럼 집합 전체를 볼 때 빠르다.
     * - 'exists': 결함 표를 정렬 인덱스(예: defects_project_file_line) 순서로 훑으며 존재를 EXISTS로 확인한다. 앞쪽 페이지는 50건을 찾으면 멈춘다.
     */
    public function memberSource(int $projectId, int $run, string $form = 'join'): array
    {
        if ($form === 'exists') {
            return ['(SELECT d0.* FROM defects d0 WHERE d0.project_id = ? AND EXISTS (SELECT 1 FROM defect_runs r WHERE r.defect_id = d0.id AND r.from_run <= ?'
                . ' AND (r.to_run IS NULL OR r.to_run >= ?))) d', [$projectId, $run, $run]];
        }
        return ['(SELECT d0.* FROM defect_runs r JOIN defects d0 ON d0.id = r.defect_id WHERE r.project_id = ? AND r.from_run <= ? AND (r.to_run IS NULL OR r.to_run >= ?)) d',
            [$projectId, $run, $run]];
    }

    /** @return array{total:int, by_status:array<string,int>, by_severity:array<string,int>, by_assignee:array<string,int>}|null 저장된 Run 요약(되채움 전이면 null) */
    public function storedSummary(int $analysisId): ?array
    {
        $raw = $this->db->value('SELECT run_summary FROM analyses WHERE id = ?', [$analysisId]);
        $s = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($s) && isset($s['total'], $s['by_status'], $s['by_severity'], $s['by_assignee']) ? $s : null;
    }

    /**
     * 결함 몇 개의 Run 시점 값(상세·목록 행 덮어쓰기).
     * 심각도 출처(P21S)도 그 시점 값으로: 표지까지 사람이 심각도를 바꾼 이력이 있으면 manual, 아니면 not_defined는 none·그 밖은 auto
     * (manual은 수동 이력으로만 생기므로 정확하다).
     *
     * @param list<int> $ids
     * @return array<int, array{severity:string, severity_source:string, status:string, assignee_id:?int}>
     */
    public function restoredValues(array $ids, int $mark): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT d.id, ' . self::restoreSql('severity', 'd') . ' AS severity, ' . self::restoreSql('status', 'd') . ' AS status, ' . self::restoreSql('assignee', 'd') . ' AS assignee_id,'
            . " EXISTS (SELECT 1 FROM defect_history h WHERE h.defect_id = d.id AND h.field = 'severity' AND h.kind = 'manual' AND h.id <= ?) AS manual"
            . ' FROM defects d WHERE d.id IN (SELECT value FROM json_each(?))',
            [$mark, $mark, $mark, $mark, json_encode(array_values($ids))],
        );
        $out = [];
        foreach ($rows as $r) {
            $sev = (string) $r['severity'];
            $out[(int) $r['id']] = ['severity' => $sev, 'severity_source' => (int) $r['manual'] === 1 ? 'manual' : ($sev === 'not_defined' ? 'none' : 'auto'),
                'status' => (string) $r['status'], 'assignee_id' => $r['assignee_id'] === null ? null : (int) $r['assignee_id']];
        }
        return $out;
    }

    /** @return list<array{from:int, to:?int}> 결함의 존재 구간(오래된 순) */
    public function intervals(int $defectId): array
    {
        return array_map(static fn (array $r): array => ['from' => (int) $r['from_run'], 'to' => $r['to_run'] === null ? null : (int) $r['to_run']],
            $this->db->all('SELECT from_run, to_run FROM defect_runs WHERE defect_id = ? ORDER BY from_run', [$defectId]));
    }

    /** @param list<array{from:int, to:?int}> $intervals */
    public static function covers(array $intervals, int $run): bool
    {
        foreach ($intervals as $i) {
            if ($i['from'] <= $run && ($i['to'] === null || $i['to'] >= $run)) {
                return true;
            }
        }
        return false;
    }

    /** 분석 id → Run 번호(여러 개) @param list<int> $ids @return array<int, int> */
    public function runsOf(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT id, run_number FROM analyses WHERE id IN (SELECT value FROM json_each(?)) AND run_number IS NOT NULL', [json_encode($ids)]) as $r) {
            $out[(int) $r['id']] = (int) $r['run_number'];
        }
        return $out;
    }

    /** Run N에 존재한 결함 수(되채움 검증·측정) */
    public function existCount(int $projectId, int $run): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM defect_runs WHERE project_id = ? AND from_run <= ? AND (to_run IS NULL OR to_run >= ?)', [$projectId, $run, $run]);
    }
}
