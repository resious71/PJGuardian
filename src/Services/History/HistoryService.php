<?php

declare(strict_types=1);

namespace PJGuardian\Services\History;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Analysis\AnalysisComparison;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;

/**
 * 히스토리(P21, 명세 8): 분석 이력과 "직전 성공 분석 대비" 비교, 결함 한 건의 전체 변경 이력(타임라인). 정의는 docs/architecture.md 32절.
 *
 * - 비교 기준 = 그 분석의 직전 성공 분석(`analyses.baseline_analysis_id`, P19가 반영 때 저장. `no_change`는 기준이 아님). 첫 성공은 기준 없음.
 * - 신규·해결·재오픈·유지 건수는 반영(P16)이 `analyses`에 저장한 값. 목록은 결함 목록 API의 `analysis`+`change` 필터(같은 정의)로 본다.
 * - 범위 밖 유지 = 그 Run에 존재한 결함(Run 요약 total) − 그 분석이 감지한 결함(total_count). Run 요약이 없으면(되채움 전) null.
 * - 버전/설정 변경 = AnalysisComparison::differences(기준, 이 분석)(P20·P20R과 같은 함수).
 */
final class HistoryService
{
    private const TOP_N = 10;

    public function __construct(private readonly Db $db, private readonly float $scopeChangeRatio)
    {
    }

    /**
     * 분석 이력(최신순, 모든 상태). 성공 행만 비교 열(신규·해결·재오픈, 열린 결함 증감, 변경)이 있다.
     * P27: "변경 없음" 행은 비교 열 없이 확인 횟수·마지막 확인·기준 Run을 준다. $hideUnchanged면 빼고 센다(필터 "변경 없음 숨기기").
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(int $projectId, int $limit, int $offset, bool $hideUnchanged = false): array
    {
        $extra = $hideUnchanged ? ' AND a.no_change = 0' : '';
        $total = (int) $this->db->value('SELECT COUNT(*) FROM analyses a WHERE a.project_id = ?' . $extra, [$projectId]);
        $rows = $this->db->all(
            'SELECT a.*, up.file_name AS upload_file_name, b.run_number AS b_run, b.tool AS b_tool, b.tool_version AS b_tool_version, b.config_source AS b_config_source,'
            . ' b.config_hash AS b_config_hash, b.run_summary AS b_run_summary'
            . ' FROM analyses a LEFT JOIN uploads up ON up.id = a.upload_id LEFT JOIN analyses b ON b.id = a.baseline_analysis_id'
            . ' WHERE a.project_id = ?' . $extra . ' ORDER BY a.id DESC LIMIT ? OFFSET ?',
            [$projectId, $limit, $offset],
        );
        $items = array_map(fn (array $r): array => $this->row($r), $rows);
        $links = (new \PJGuardian\Services\Repo\RepoLinks($this->db))->forAnalyses(array_column($items, 'analysis_id'));
        // 변경 없음 행의 기준 Run(그 리비전을 실제로 분석한 분석). 행이 적어 한 번에 조회(021 전 DB에는 열이 없다 — 그때는 변경 없음 행도 없다)
        $baseIds = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => (int) $r['no_change'] === 1 && isset($r['unchanged_base_analysis_id'])
            ? (int) $r['unchanged_base_analysis_id'] : null, $rows))));
        $bases = [];
        if ($baseIds !== []) {
            foreach ($this->db->all('SELECT id, run_number, finished_at FROM analyses WHERE id IN (SELECT value FROM json_each(?))', [json_encode($baseIds)]) as $b) {
                $bases[(int) $b['id']] = ['analysis_id' => (int) $b['id'], 'run' => $b['run_number'] === null ? null : (int) $b['run_number'], 'finished_at' => $b['finished_at']];
            }
        }
        foreach ($items as &$it) {
            $it['source']['link'] = $links[$it['analysis_id']] ?? null;
            if ($it['unchanged']) {
                $it['unchanged_base'] = $bases[$it['unchanged_base_id']] ?? null;
            }
            unset($it['unchanged_base_id']);
        }
        unset($it);
        return [$items, $total];
    }

    /** @param array<string, mixed> $r */
    private function row(array $r): array
    {
        $ok = $r['status'] === 'succeeded';
        $unchanged = (int) ($r['no_change'] ?? 0) === 1;
        $open = $ok ? self::openOf($r['run_summary']) : null;
        $prevOpen = $ok && $r['baseline_analysis_id'] !== null ? self::openOf($r['b_run_summary']) : null;
        $base = $r['baseline_analysis_id'] === null ? null
            : ['tool' => $r['b_tool'], 'tool_version' => $r['b_tool_version'], 'config_source' => $r['b_config_source'], 'config_hash' => $r['b_config_hash']];
        $diff = $ok ? AnalysisComparison::differences($base, $r) : [];
        $code = $r['failure_code'];
        return [
            'analysis_id' => (int) $r['id'],
            'run' => $r['run_number'] === null ? null : (int) $r['run_number'],
            'status' => (string) $r['status'],
            'queued_at' => $r['queued_at'], 'started_at' => $r['started_at'], 'finished_at' => $r['finished_at'],
            'source' => ['kind' => (string) $r['source_kind'], 'ref' => $r['source_ref'], 'file_name' => $r['upload_file_name'],
                'revision' => $r['source_revision'] ?? null, 'link' => null], // P25: 커밋/changelist, 커밋 링크(아래에서 채움)
            'tool_name' => (string) $r['tool'], 'tool_version' => $r['tool_version'],
            'counts' => $ok && $r['total_count'] !== null ? [
                'detected' => (int) $r['total_count'], 'new' => (int) $r['new_count'], 'resolved' => (int) $r['resolved_count'],
                'reopened' => (int) $r['reopened_count'], 'retained' => (int) $r['unchanged_count'],
            ] : null,
            'open_total' => $open,
            'open_delta' => $open !== null && $prevOpen !== null ? $open - $prevOpen : null,
            'baseline' => $r['baseline_analysis_id'] === null ? null : ['analysis_id' => (int) $r['baseline_analysis_id'], 'run' => $r['b_run'] === null ? null : (int) $r['b_run']],
            'changed' => $diff !== [], 'changes' => $diff,
            'failure' => $code === null ? null : ['code' => (string) $code, 'title' => ErrorCatalog::describe('analysis', (string) $code)['title']],
            // P21B: 상세가 삭제된 Run(번호·날짜·건수·변경 표시는 그대로, 과거 Run 보기·카드 링크만 막힘)
            'detail_deleted' => ($r['run_detail_deleted_at'] ?? null) !== null,
            // P27: 성공(변경 없음) — Run·비교 열 없음. 같은 리비전의 연속 확인은 한 행(확인 횟수·마지막 확인)
            'unchanged' => $unchanged,
            'unchanged_check_count' => $unchanged ? max(1, (int) ($r['unchanged_check_count'] ?? 1)) : null,
            'unchanged_last_checked_at' => $unchanged ? ($r['unchanged_last_checked_at'] ?? $r['finished_at']) : null,
            'unchanged_base' => null,
            'unchanged_base_id' => $unchanged && isset($r['unchanged_base_analysis_id']) ? (int) $r['unchanged_base_analysis_id'] : null,
            'trigger_kind' => (string) $r['trigger_kind'],
        ];
    }

    /** 그 Run 종료 시점의 열린 결함(존재 결함 − resolved) */
    private static function openOf(mixed $summary): ?int
    {
        $s = is_string($summary) ? json_decode($summary, true) : null;
        return is_array($s) && isset($s['total']) ? (int) $s['total'] - (int) ($s['by_status']['resolved'] ?? 0) : null;
    }

    /**
     * 비교 결과: 기준(직전 성공) 대비. 성공이 아니면 409 not_succeeded.
     *
     * @return array<string, mixed>
     */
    public function comparison(int $analysisId): array
    {
        $a = $this->db->one('SELECT a.*, up.file_name AS upload_file_name FROM analyses a LEFT JOIN uploads up ON up.id = a.upload_id WHERE a.id = ?', [$analysisId]);
        if ($a === null) {
            throw new ApiException(404, 'analysis_not_found');
        }
        if ($a['status'] !== 'succeeded') {
            throw new ApiException(409, 'not_succeeded', ['status' => (string) $a['status']]);
        }
        if ((int) ($a['no_change'] ?? 0) === 1) {
            throw new ApiException(409, 'analysis_unchanged'); // P27: 변경 없음은 분석이 아니어서 비교 대상이 없다(기준 Run을 본다)
        }
        $pid = (int) $a['project_id'];
        $b = $a['baseline_analysis_id'] === null ? null : $this->db->one('SELECT * FROM analyses WHERE id = ?', [(int) $a['baseline_analysis_id']]);
        $diff = AnalysisComparison::differences($b, $a);
        $summary = json_decode((string) ($a['run_summary'] ?? ''), true);
        $existing = is_array($summary) ? (int) $summary['total'] : null;
        $counts = [
            'new' => (int) $a['new_count'], 'resolved' => (int) $a['resolved_count'], 'reopened' => (int) $a['reopened_count'],
            'retained' => (int) $a['unchanged_count'], 'detected' => (int) $a['total_count'],
            // 범위 밖 유지: 그 Run에 존재했지만 이번에 감지되지 않은 결함(분석 범위 밖이라 상태 유지). Run 요약이 없으면 계산하지 않음
            'out_of_scope' => $existing === null ? null : max(0, $existing - (int) $a['total_count']),
        ];
        $sets = [];
        foreach (['new', 'resolved', 'reopened'] as $change) {
            [$from, $params] = self::changeSource($change, $analysisId, $pid);
            $sev = array_fill_keys(DefectFilter::SEVERITIES, 0);
            foreach ($this->db->all('SELECT d.severity AS s, COUNT(*) AS n FROM ' . $from . ' GROUP BY d.severity', $params) as $r) {
                $sev[(string) $r['s']] = (int) $r['n'];
            }
            $sets[$change] = ['by_severity' => $sev];
            if ($change !== 'reopened') {
                $sets[$change]['top_rules'] = array_map(static fn (array $r): array => ['rule_key' => (string) $r['k'], 'count' => (int) $r['n']], $this->db->all(
                    'SELECT d.rule_key AS k, COUNT(*) AS n FROM ' . $from . ' GROUP BY d.rule_key ORDER BY n DESC, d.rule_key LIMIT ?', [...$params, self::TOP_N]));
                $sets[$change]['top_files'] = array_map(static fn (array $r): array => ['file_path' => (string) $r['k'], 'count' => (int) $r['n']], $this->db->all(
                    'SELECT d.file_path AS k, COUNT(*) AS n FROM ' . $from . ' GROUP BY d.file_path ORDER BY n DESC, d.file_path LIMIT ?', [...$params, self::TOP_N]));
            }
        }
        // 분석 범위(대상 파일 수)
        $scope = null;
        if ($b !== null) {
            $files = [];
            foreach ($this->db->all('SELECT analysis_id, file_count FROM analysis_file_lists WHERE analysis_id IN (?, ?)', [$analysisId, (int) $b['id']]) as $f) {
                $files[(int) $f['analysis_id']] = (int) $f['file_count'];
            }
            if (isset($files[$analysisId], $files[(int) $b['id']])) {
                [$from, $to] = [$files[(int) $b['id']], $files[$analysisId]];
                $ratio = abs($to - $from) / max(1, max($from, $to));
                $scope = ['from_files' => $from, 'to_files' => $to, 'ratio' => round($ratio, 3), 'changed' => $ratio >= $this->scopeChangeRatio];
            }
        }
        $warnings = [];
        if ($diff !== []) {
            $warnings[] = 'history.warn_tool_change';
        }
        if ($scope !== null && $scope['changed']) {
            $warnings[] = 'history.warn_scope_change';
        }
        $nav = $this->db->one(
            "SELECT (SELECT MAX(id) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND id < ?) AS prev,"
            . " (SELECT MIN(id) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND id > ?) AS next",
            [$pid, $analysisId, $pid, $analysisId],
        );
        $brief = static fn (array $r): array => ['analysis_id' => (int) $r['id'], 'run' => $r['run_number'] === null ? null : (int) $r['run_number'],
            'finished_at' => $r['finished_at'], 'tool_name' => (string) $r['tool'], 'tool_version' => $r['tool_version'], 'config_source' => $r['config_source'],
            'detail_deleted' => ($r['run_detail_deleted_at'] ?? null) !== null];
        return [
            'analysis' => $brief($a) + ['project_id' => $pid],
            'baseline' => $b === null ? null : $brief($b),
            'counts' => $counts,
            'sets' => $sets,
            'changed' => $diff !== [], 'changes' => $diff,
            'scope' => $scope,
            'warnings' => $warnings,
            // 결함 목록(결함 탭)으로 갈 필터 값(P19와 같은 정의). 해결 목록은 "이 분석에서 해결된 결함"이며 현재 상태(이후 재오픈 등)를 함께 보인다
            'filters' => array_map(static fn (string $c): array => ['analysis' => $analysisId, 'change' => $c], ['new' => 'new', 'resolved' => 'resolved', 'reopened' => 'reopened']),
            'nav' => ['prev' => $nav['prev'] === null ? null : (int) $nav['prev'], 'next' => $nav['next'] === null ? null : (int) $nav['next']],
        ];
    }

    /**
     * 결함 목록 필터(DefectFilter의 analysis+change)와 같은 집합을, 그 분석의 작은 집합에서 출발하는 FROM으로(결함 표 전체를 훑지 않게).
     * 신규: defects_first_seen 인덱스, 해결·재오픈: defect_history_analysis 인덱스(이력 한 행 = 결함 하나, 같은 분석에서 두 번 전환하지 않음).
     * `+d.project_id`는 프로젝트 인덱스를 고르지 않게 하는 표시다(조건은 같다).
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private static function changeSource(string $change, int $analysisId, int $projectId): array
    {
        if ($change === 'new') {
            return ['defects d WHERE d.first_seen_analysis_id = ? AND +d.project_id = CAST(? AS INTEGER)', [$analysisId, $projectId]];
        }
        return ['defect_history h JOIN defects d ON d.id = h.defect_id WHERE h.analysis_id = ? AND h.reason_code IN (SELECT value FROM json_each(?)) AND +d.project_id = CAST(? AS INTEGER)',
            [$analysisId, json_encode($change === 'resolved' ? ['not_detected', 'file_deleted'] : ['redetected', 'still_detected']), $projectId]];
    }

    /**
     * 결함 한 건의 전체 변경 이력(시간순): 발생, 상태·담당자·심각도 변경(수동), 자동 해결·재오픈, 사라짐(이력이 없는 존재 끝).
     * 각 행에 Run(자동 = 그 분석의 Run, 수동 = 그 시점 직전 Run 뒤 "Run N 이후"). 맨 위 요약은 존재 구간.
     *
     * @return array{summary: array<string, mixed>, items: list<array<string, mixed>>, total: int}
     */
    public function timeline(int $defectId, int $limit, int $offset): array
    {
        $d = $this->db->one('SELECT id, project_id, first_seen_analysis_id, created_at, status FROM defects WHERE id = ?', [$defectId]);
        if ($d === null) {
            throw new ApiException(404, 'defect_not_found');
        }
        $pid = (int) $d['project_id'];
        $runs = $this->db->all('SELECT id, run_number, run_history_mark, finished_at, run_detail_deleted_at FROM analyses WHERE project_id = ? AND run_number IS NOT NULL ORDER BY run_number', [$pid]);
        $runOfAnalysis = [];
        $finishedOfRun = [];
        $marks = [];
        $deletedRuns = [];
        foreach ($runs as $r) {
            $runOfAnalysis[(int) $r['id']] = (int) $r['run_number'];
            $finishedOfRun[(int) $r['run_number']] = $r['finished_at'];
            $marks[(int) $r['run_number']] = (int) ($r['run_history_mark'] ?? 0);
            if ($r['run_detail_deleted_at'] !== null) {
                $deletedRuns[(int) $r['run_number']] = true;
            }
        }
        $intervals = array_map(static fn (array $r): array => ['from' => (int) $r['from_run'], 'to' => $r['to_run'] === null ? null : (int) $r['to_run']],
            $this->db->all('SELECT from_run, to_run FROM defect_runs WHERE defect_id = ? ORDER BY from_run', [$defectId]));
        $events = [];
        $firstRun = $runOfAnalysis[(int) $d['first_seen_analysis_id']] ?? null;
        $fa = $this->db->one('SELECT finished_at FROM analyses WHERE id = ?', [(int) $d['first_seen_analysis_id']]);
        $events[] = ['at' => $fa['finished_at'] ?? $d['created_at'], 'order' => 0, 'kind' => 'appeared', 'run' => $firstRun, 'run_after' => false,
            'field' => null, 'from' => null, 'to' => null, 'by' => null, 'reason' => null, 'memo' => null, 'analysis_id' => (int) $d['first_seen_analysis_id']];
        $autoRuns = [];
        $hist = $this->db->all(
            'SELECT h.id, h.field, h.old_value, h.new_value, h.changed_at, h.kind, h.reason_code, h.memo, h.analysis_id, u.id AS uid, u.login_id, u.display_name, u.status AS ustatus'
            . ' FROM defect_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.defect_id = ? ORDER BY h.id',
            [$defectId],
        );
        foreach ($hist as $h) {
            $auto = $h['kind'] === 'auto';
            // P21S: 규칙 기본 심각도 일괄 적용(자동, 분석 없음)은 수동 변경처럼 "Run N 이후"에 놓는다
            $ruleDefault = $auto && $h['reason_code'] === 'rule_default';
            if ($auto && !$ruleDefault) {
                $run = $runOfAnalysis[(int) $h['analysis_id']] ?? null;
                $after = false;
                $kind = $h['reason_code'] === 'redetected' || $h['reason_code'] === 'still_detected' ? 'reopened' : 'auto_resolved';
                if ($run !== null) {
                    $autoRuns[$run] = true;
                }
            } else {
                // 수동 이력: 그 이력 id보다 표지가 작은 마지막 Run 뒤("Run N 이후"). Run 표지 이전이면 그 Run 안의 변경
                $run = null;
                foreach ($marks as $n => $mark) {
                    if ($mark < (int) $h['id']) {
                        $run = $n;
                    }
                }
                $after = $run !== null;
                $kind = $ruleDefault ? 'severity_default'
                    : (['status' => 'status_changed', 'assignee' => 'assignee_changed', 'severity' => 'severity_changed'][(string) $h['field']] ?? 'status_changed');
            }
            $events[] = ['at' => $h['changed_at'], 'order' => (int) $h['id'], 'kind' => $kind, 'run' => $run, 'run_after' => $after,
                'field' => (string) $h['field'], 'from' => $h['old_value'], 'to' => $h['new_value'],
                'by' => $auto || $h['uid'] === null ? null : ['id' => (int) $h['uid'], 'login_id' => $h['login_id'], 'display_name' => $h['display_name'], 'active' => $h['ustatus'] === 'active'],
                'reason' => $h['reason_code'], 'memo' => $h['memo'], 'analysis_id' => $h['analysis_id'] === null ? null : (int) $h['analysis_id']];
        }
        // 이력 없이 존재가 끝난 구간(수동 resolved 뒤 미감지 등): 다음 Run에서 "사라짐"
        foreach ($intervals as $i) {
            if ($i['to'] !== null && !isset($autoRuns[$i['to'] + 1])) {
                $events[] = ['at' => $finishedOfRun[$i['to'] + 1] ?? null, 'order' => PHP_INT_MAX, 'kind' => 'disappeared', 'run' => $i['to'] + 1, 'run_after' => false,
                    'field' => null, 'from' => null, 'to' => null, 'by' => null, 'reason' => null, 'memo' => null, 'analysis_id' => null];
            }
        }
        // 순서는 시각이 아니라 Run 흐름으로 정한다: Run N의 발생·자동 전환·사라짐 → "Run N 이후" 수동 변경 → Run N+1 …
        // (자동 이력 시각은 분석 종료 시각, 수동 이력은 실제 시각이라 시각만으로는 같은 초·시계 차이에서 순서가 흔들린다)
        $key = static function (array $e): array {
            $run = $e['run'] ?? 0;
            $phase = $e['run_after'] ? 2 : ($e['kind'] === 'appeared' ? 0 : 1);
            return [$run, $phase, $e['order']];
        };
        usort($events, static fn (array $x, array $y): int => $key($x) <=> $key($y));
        $latest = $runs === [] ? null : (int) end($runs)['run_number'];
        return [
            'summary' => ['first_run' => $firstRun, 'latest_run' => $latest, 'intervals' => $intervals, 'status' => (string) $d['status'],
                'exists_now' => $intervals !== [] && end($intervals)['to'] === null, 'history_count' => count($hist)],
            // P21B: 그 Run의 상세가 삭제됐으면 표시("Run N(상세 삭제됨)"), 이력 행 자체는 그대로
            'items' => array_map(static function (array $e) use ($deletedRuns): array {
                unset($e['order']);
                $e['run_detail_deleted'] = $e['run'] !== null && isset($deletedRuns[$e['run']]);
                return $e;
            }, array_slice($events, $offset, $limit)),
            'total' => count($events),
        ];
    }
}
