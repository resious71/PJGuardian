<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\Db;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 목록 필터·큐·비교 쿼리가 EXPLAIN QUERY PLAN에서 인덱스를 쓰는지 확인한다(docs/db_schema.md 5절).
 * 실패 기준: 대상 테이블 전체 스캔("SCAN <테이블>" 뒤에 USING이 없음). 기본 정렬을 인덱스로 처리하는
 * 쿼리는 임시 정렬("USE TEMP B-TREE FOR ORDER BY")도 없어야 한다.
 */
final class IndexUsageTest extends TestCase
{
    private Db $db;

    public function setUp(): void
    {
        $this->db = TestDb::migrated();
        // 통계가 있는 상태도 확인하기 위해 데이터를 넣고 ANALYZE. 운영처럼 결함이 여러 프로젝트에 나뉘어 있게 한다
        // (한 프로젝트에 모든 행이 있으면 플래너가 "전부 읽는 편이 낫다"고 판단할 수 있다)
        // 행마다 커밋하면 Windows에서 매우 느리므로 준비 데이터는 트랜잭션 하나로 넣는다
        $this->db->transaction(function (): void {
        $u = TestDb::user($this->db);
        $projects = [];
        for ($k = 0; $k < 10; $k++) {
            $pid = TestDb::project($this->db, 'p' . $k);
            $projects[] = [$pid, TestDb::analysis($this->db, $pid, $u)];
        }
        for ($i = 0; $i < 400; $i++) {
            [$p, $a] = $projects[$i % 10];
            TestDb::defect($this->db, $p, $a, [
                'rule_key' => 'ruff:F' . (400 + $i % 7),
                'file_path' => 'src/m' . ($i % 20) . '.py',
                'line_no' => $i + 1,
                'severity' => ['critical', 'high', 'medium', 'low', 'not_defined'][$i % 5],
                'status' => ['open', 'in_progress', 'resolved'][$i % 3],
                'assignee_id' => $i % 4 === 0 ? $u : null,
            ]);
        }
        });
        $this->db->pdo()->exec('ANALYZE');
    }

    /** @return list<string> */
    private function plan(string $sql, array $params): array
    {
        return array_column($this->db->all('EXPLAIN QUERY PLAN ' . $sql, $params), 'detail');
    }

    private function assertUsesIndex(string $label, string $sql, array $params, bool $noTempSort = false): void
    {
        $plan = $this->plan($sql, $params);
        $text = implode(' | ', $plan);
        foreach ($plan as $step) {
            if (preg_match('/^SCAN (\w+)(?: AS \w+)?$/', $step, $m) === 1) {
                $this->fail("{$label}: 전체 스캔 {$m[1]} — {$text}");
            }
        }
        $this->assertTrue(str_contains($text, 'USING'), "{$label}: 인덱스를 쓰지 않음 — {$text}");
        if ($noTempSort) {
            $this->assertNotContains('TEMP B-TREE', $text, "{$label}: 정렬을 인덱스로 처리하지 못함");
        }
    }

    public function testDefectListFiltersUseIndexes(): void
    {
        $base = 'SELECT id, rule_key, file_path, line_no, severity, status FROM defects WHERE project_id = ?';
        $cases = [
            ['필터 없음', '', [], true],
            ['상태', ' AND status = ?', ['open'], true],
            ['심각도', ' AND severity = ?', ['high'], true],
            ['담당자', ' AND assignee_id = ?', [1], true],
            ['담당자 없음', ' AND assignee_id IS NULL', [], true],
            ['규칙', ' AND rule_key = ?', ['ruff:F401'], true],
            ['상태+심각도', ' AND status = ? AND severity = ?', ['open', 'high'], false],
            ['상태+담당자', ' AND status = ? AND assignee_id = ?', ['open', 1], false],
            ['규칙+상태', ' AND rule_key = ? AND status = ?', ['ruff:F401', 'open'], false],
        ];
        foreach ($cases as [$label, $where, $params, $noSort]) {
            $this->assertUsesIndex($label, $base . $where . ' ORDER BY id LIMIT 50 OFFSET 100', array_merge([1], $params), $noSort);
            $this->assertUsesIndex($label . ' 건수', 'SELECT COUNT(*) FROM defects WHERE project_id = ?' . $where, array_merge([1], $params));
        }
        // 파일: 정확히 일치 또는 폴더 접두사(범위 조건으로 인덱스 사용. LIKE 'dir/%'는 인덱스를 못 쓴다)
        $this->assertUsesIndex('파일 일치', $base . ' AND file_path = ? ORDER BY line_no', [1, 'src/m1.py'], true);
        $this->assertUsesIndex('폴더 접두사', $base . ' AND file_path >= ? AND file_path < ? ORDER BY file_path, line_no LIMIT 50', [1, 'src/', 'src0'], true);
    }

    public function testCrossProjectAndHistoryQueriesUseIndexes(): void
    {
        $this->assertUsesIndex('내게 지정된 결함', 'SELECT id FROM defects WHERE assignee_id = ? AND status = ?', [1, 'open']);
        $this->assertUsesIndex('결함 이력', 'SELECT * FROM defect_history WHERE defect_id = ? ORDER BY id', [1], true);
        $this->assertUsesIndex('분석별 해결', "SELECT defect_id FROM defect_history WHERE analysis_id = ? AND reason_code IN ('not_detected', 'file_deleted')", [1]);
        $this->assertUsesIndex('분석별 처음 감지', 'SELECT id FROM defects WHERE first_seen_analysis_id = ?', [1]);
        $this->assertUsesIndex('스냅샷 정리 대상(마지막 감지)', 'SELECT id FROM defects WHERE last_seen_analysis_id = ?', [1]);
        $this->assertUsesIndex('기존 결함 매칭', 'SELECT id, version, status FROM defects WHERE project_id = ? AND signature = ? AND ordinal = ?', [1, str_repeat('a', 64), 0]);
    }

    public function testQueueAndComparisonQueriesUseIndexes(): void
    {
        $this->assertUsesIndex('큐에서 다음 분석', "SELECT id, project_id FROM analyses WHERE status = 'queued' ORDER BY id LIMIT 20", [], true);
        $this->assertUsesIndex('실행 중 개수', "SELECT COUNT(*) FROM analyses WHERE status = 'running'", []);
        $this->assertUsesIndex('프로젝트 queued/running 존재', "SELECT 1 FROM analyses WHERE project_id = ? AND status IN ('queued', 'running') LIMIT 1", [1]);
        $this->assertUsesIndex('직전 성공 분석', "SELECT id FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND id < ? ORDER BY id DESC LIMIT 1", [1, 99], true);
        $this->assertUsesIndex('추이 최근 10건', "SELECT id, total_count FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id DESC LIMIT 10", [1], true);
        $this->assertUsesIndex('분석 이력 목록', 'SELECT * FROM analyses WHERE project_id = ? ORDER BY id DESC LIMIT 50', [1], true);
        $this->assertUsesIndex('후처리 대기 업로드', "SELECT id FROM uploads WHERE status = 'assemble_pending' ORDER BY created_at LIMIT 5", [], true);
        $this->assertUsesIndex('사용자 진행 중 업로드 수', "SELECT COUNT(*) FROM uploads WHERE user_id = ? AND status IN ('receiving', 'assemble_pending', 'assembling', 'extracting', 'needs_confirmation')", [1]);
        $this->assertUsesIndex('미완료 조각 정리', "SELECT id FROM uploads WHERE last_activity_at < ? AND status = 'receiving'", [TestDb::T0]);
        $this->assertUsesIndex('IP 최근 실패 20건', 'SELECT failed_at FROM login_failures WHERE ip = ? ORDER BY failed_at DESC LIMIT 20', ['10.0.0.1'], true);
        $this->assertUsesIndex('감사 로그 기간 조회', 'SELECT * FROM audit_log WHERE occurred_at >= ? ORDER BY occurred_at', [TestDb::T0], true);
    }
}
