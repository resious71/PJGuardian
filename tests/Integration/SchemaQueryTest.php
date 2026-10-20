<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * "분석별 발생 기록을 따로 저장하지 않는" 설계가 명세 7·8·9장 요구를 만족하는지 시나리오로 확인한다.
 * 여기의 SQL은 docs/db_schema.md 6절의 대표 쿼리와 같다(구현 단계의 Repository가 쓸 기준).
 *
 * 시나리오(프로젝트 하나, ruff):
 *   A1 성공(0.16.10)  최초 분석. d1, d2, d4 감지
 *   A2 실패            비교 기준에서 제외되어야 함
 *   A3 성공(0.17.0)   도구 버전 변경. d1 유지, d3 신규, d2 미감지 → resolved, d4 파일 삭제 → resolved
 *   (A3 뒤 사용자가 d1을 수동 resolved)
 *   A4 성공(0.17.0)   d1(수동 resolved) 계속 감지 → redetected 재오픈, d2 재감지 → redetected 재오픈. 둘 다 신규 아님
 *   (P20A 사용자 결정: still_detected는 쓰지 않는다. 신규 = 처음 감지, 재오픈 = redetected)
 *   A5 성공 no_change  같은 커밋이라 생략("변경 없음"), 비교 기준·추이에서 제외되어야 함
 */
final class SchemaQueryTest extends TestCase
{
    private Db $db;
    /** @var array<string, int> */
    private array $a = [];
    /** @var array<string, int> */
    private array $d = [];
    private int $p;

    public function setUp(): void
    {
        $db = $this->db = TestDb::migrated();
        $u = TestDb::user($db);
        $p = $this->p = TestDb::project($db);
        $this->a['A1'] = TestDb::analysis($db, $p, $u, ['total_count' => 3, 'new_count' => 3]);
        $this->a['A2'] = TestDb::analysis($db, $p, $u, ['status' => 'failed', 'failure_code' => 'timeout', 'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);
        $this->a['A3'] = TestDb::analysis($db, $p, $u, ['tool_version' => '0.17.0', 'baseline_analysis_id' => $this->a['A1'], 'total_count' => 2, 'new_count' => 1, 'resolved_count' => 2, 'unchanged_count' => 1]);
        $this->a['A4'] = TestDb::analysis($db, $p, $u, ['tool_version' => '0.17.0', 'baseline_analysis_id' => $this->a['A3'], 'total_count' => 3, 'new_count' => 1, 'reopened_count' => 2, 'unchanged_count' => 2]);
        $this->a['A5'] = TestDb::analysis($db, $p, $u, ['source_kind' => 'git', 'tool_version' => null, 'no_change' => 1, 'started_at' => null, 'total_count' => null, 'new_count' => null, 'resolved_count' => null, 'reopened_count' => null, 'unchanged_count' => null]);

        $this->d['d1'] = TestDb::defect($db, $p, $this->a['A1'], ['last_seen_analysis_id' => $this->a['A4'], 'file_path' => 'src/keep.py']);
        $this->d['d2'] = TestDb::defect($db, $p, $this->a['A1'], ['last_seen_analysis_id' => $this->a['A4'], 'file_path' => 'src/back.py']);
        $this->d['d3'] = TestDb::defect($db, $p, $this->a['A3'], ['last_seen_analysis_id' => $this->a['A4'], 'file_path' => 'src/new.py']);
        $this->d['d4'] = TestDb::defect($db, $p, $this->a['A1'], ['last_seen_analysis_id' => $this->a['A1'], 'file_path' => 'src/gone.py', 'status' => 'resolved']);

        TestDb::autoHistory($db, $this->d['d2'], $this->a['A3'], 'open', 'resolved', 'not_detected');
        TestDb::autoHistory($db, $this->d['d4'], $this->a['A3'], 'open', 'resolved', 'file_deleted');
        $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, memo) VALUES (?, 'status', 'open', 'resolved', ?, ?, 'manual', '수정함')", [$this->d['d1'], $u, TestDb::T0]);
        TestDb::autoHistory($db, $this->d['d1'], $this->a['A4'], 'resolved', 'open', 'redetected');
        TestDb::autoHistory($db, $this->d['d2'], $this->a['A4'], 'resolved', 'open', 'redetected');

        // 스냅샷: A1은 정리됨(발췌만), A3·A4는 전체 보존
        $db->execute("INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at, pruned_at) VALUES (?, '1/a1.zip', 0, 0, 'pruned', ?, ?)", [$this->a['A1'], TestDb::T0, TestDb::T0]);
        foreach (['A3', 'A4'] as $k) {
            $db->execute("INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at) VALUES (?, ?, 100, 3, 'full', ?)", [$this->a[$k], "1/{$k}.zip", TestDb::T0]);
        }
        $db->execute("INSERT INTO defect_excerpts (defect_id, analysis_id, file_path, start_line, end_line, content, created_at) VALUES (?, ?, 'src/gone.py', 1, 21, 'import os', ?)", [$this->d['d4'], $this->a['A1'], TestDb::T0]);
    }

    /** 직전 성공 분석(명세 8): 실패·취소·"변경 없음"은 제외. 없으면 NULL = 비교 대상 없음 */
    private function baseline(int $analysisId): ?int
    {
        $v = $this->db->value(
            "SELECT id FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND id < ? ORDER BY id DESC LIMIT 1",
            [$this->p, $analysisId],
        );
        return $v === null ? null : (int) $v;
    }

    /** 분석 A의 신규 = 처음 감지(first_seen=A) + 직전 성공 분석에 없던 결함의 재감지(redetected) */
    private function newIn(int $a): array
    {
        $rows = $this->db->all(
            // 신규 = 그 분석에서 처음 감지(P20A 결정, analyses.new_count와 같은 정의). 재감지는 재오픈으로 따로 센다
            'SELECT id FROM defects WHERE first_seen_analysis_id = ? ORDER BY 1',
            [$a],
        );
        return array_map('intval', array_column($rows, 'id'));
    }

    /** 분석 A의 해결 = 자동 resolved 이력(not_detected, file_deleted) */
    private function resolvedIn(int $a): array
    {
        $rows = $this->db->all("SELECT defect_id FROM defect_history WHERE analysis_id = ? AND reason_code IN ('not_detected', 'file_deleted') ORDER BY defect_id", [$a]);
        return array_map('intval', array_column($rows, 'defect_id'));
    }

    public function testBaselineSkipsFailedAndNoChangeAndFirstHasNone(): void
    {
        $this->assertSame(null, $this->baseline($this->a['A1']), '최초 분석은 비교 대상 없음(명세 8)');
        $this->assertSame($this->a['A1'], $this->baseline($this->a['A3']), '실패한 A2는 건너뜀');
        $this->assertSame($this->a['A3'], $this->baseline($this->a['A4']));
        $after = TestDb::analysis($this->db, $this->p, 1, ['status' => 'queued']);
        $this->assertSame($this->a['A4'], $this->baseline($after), '"변경 없음" A5는 비교 기준이 아님');
        $this->assertSame($this->a['A1'], (int) $this->db->value('SELECT baseline_analysis_id FROM analyses WHERE id = ?', [$this->a['A3']]), '분석 시점의 기준을 저장해 두므로 나중에도 같은 비교를 보여 줄 수 있음');
    }

    public function testNewAndResolvedPerAnalysisAreReconstructable(): void
    {
        $this->assertSame([$this->d['d1'], $this->d['d2'], $this->d['d4']], $this->newIn($this->a['A1']), '최초 분석은 전체가 신규');
        $this->assertSame([$this->d['d3']], $this->newIn($this->a['A3']));
        $this->assertSame([$this->d['d2'], $this->d['d4']], $this->resolvedIn($this->a['A3']));
        $this->assertSame([], $this->newIn($this->a['A4']), '재오픈(d1 수동 resolved 뒤 계속 감지, d2 재감지)은 신규가 아님');
        $reopened = array_map('intval', array_column($this->db->all("SELECT defect_id FROM defect_history WHERE analysis_id = ? AND reason_code = 'redetected' ORDER BY defect_id", [$this->a['A4']]), 'defect_id'));
        $this->assertSame([$this->d['d1'], $this->d['d2']], $reopened, '재오픈 2건(통계 reopened_count와 일치)');
        $this->assertSame([], $this->resolvedIn($this->a['A2']), '실패한 분석은 어떤 결함도 전환하지 않음(명세 6.3)');
    }

    public function testTrendLastTenSucceededWithVersionChangeMarker(): void
    {
        $rows = $this->db->all(
            "SELECT id, total_count, tool_version, config_source, config_hash FROM analyses
             WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id DESC LIMIT 10",
            [$this->p],
        );
        $this->assertSame([$this->a['A4'], $this->a['A3'], $this->a['A1']], array_map('intval', array_column($rows, 'id')), '실패·변경 없음 제외(명세 9)');
        $this->assertSame([3, 2, 3], array_map('intval', array_column($rows, 'total_count')));
        $rows = array_reverse($rows);
        $markers = [];
        for ($i = 1; $i < count($rows); $i++) {
            $prev = $rows[$i - 1];
            $cur = $rows[$i];
            if ($prev['tool_version'] !== $cur['tool_version'] || $prev['config_source'] !== $cur['config_source'] || $prev['config_hash'] !== $cur['config_hash']) {
                $markers[] = (int) $cur['id'];
            }
        }
        $this->assertSame([$this->a['A3']], $markers, 'A3에서 도구 버전이 바뀜 → 차트 표식·"버전/설정 변경" 배지(명세 8, 9)');
    }

    public function testDefectDetailCodeSourceAndFirstSeenToolVersion(): void
    {
        $sql = "SELECT d.id, d.last_seen_analysis_id, s.status AS snapshot_status, s.storage_path, e.content AS excerpt,
                       fa.tool_version AS first_seen_tool_version
                FROM defects d
                JOIN analyses fa ON fa.id = d.first_seen_analysis_id
                LEFT JOIN snapshots s ON s.analysis_id = d.last_seen_analysis_id
                LEFT JOIN defect_excerpts e ON e.defect_id = d.id
                WHERE d.id = ?";
        $d1 = $this->db->one($sql, [$this->d['d1']]);
        $this->assertSame('full', $d1['snapshot_status'], 'd1은 마지막 감지 분석 A4의 전체 스냅샷(명세 7)');
        $this->assertSame('1/A4.zip', $d1['storage_path']);
        $this->assertSame('0.16.10', $d1['first_seen_tool_version'], '처음 발생한 분석의 도구 버전(명세 8)');
        $d3 = $this->db->one($sql, [$this->d['d3']]);
        $this->assertSame('0.17.0', $d3['first_seen_tool_version']);
        $d4 = $this->db->one($sql, [$this->d['d4']]);
        $this->assertSame('pruned', $d4['snapshot_status'], 'd4의 마지막 감지 A1은 정리됨');
        $this->assertSame('import os', $d4['excerpt'], '발췌본을 보여 주고 발췌임을 표시(명세 7)');
    }

    public function testSummaryCountsBySeverityStatusAssignee(): void
    {
        // 명세 8: 현재 결함 현황을 심각도별·상태별·담당자별 건수로 요약
        $byStatus = $this->db->all('SELECT status, COUNT(*) AS n FROM defects WHERE project_id = ? GROUP BY status ORDER BY status', [$this->p]);
        $this->assertSame([['status' => 'open', 'n' => 3], ['status' => 'resolved', 'n' => 1]], $byStatus);
        $bySeverity = $this->db->all('SELECT severity, COUNT(*) AS n FROM defects WHERE project_id = ? GROUP BY severity', [$this->p]);
        $this->assertSame([['severity' => 'not_defined', 'n' => 4]], $bySeverity);
    }
}
