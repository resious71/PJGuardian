<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 과거 Run 원본(RunService::source)의 P31 B-3 개선이 값을 바꾸지 않는다: 무작위 이력(심각도·상태·담당자, 미지정 ↔ 지정, 같은 필드 여러 번,
 * 표지 앞뒤)을 만들고 Run마다 **옛 SQL(행마다 하위 쿼리로 표지 뒤 첫 이력의 이전 값)**과 새 SQL의 결과(id·심각도·상태·담당자)를 통째로 대조한다.
 */
final class RunSourceEquivalenceTest extends TestCase
{
    private static function legacy(string $field, string $alias): string
    {
        $sub = "(SELECT %s FROM defect_history h WHERE h.defect_id = {$alias}.id AND h.field = '{$field}' AND h.id > ? ORDER BY h.id LIMIT 1)";
        return match ($field) {
            'assignee' => 'CAST(NULLIF(COALESCE(' . sprintf($sub, "COALESCE(h.old_value, '')") . ", {$alias}.assignee_id), '') AS INTEGER)",
            default => 'COALESCE(' . sprintf($sub, 'h.old_value') . ", {$alias}.{$field})",
        };
    }

    public function testNewSourceEqualsLegacyRestore(): void
    {
        mt_srand(31);
        $app = TestApp::make($this->tempDir('runsrc'));
        $db = $app->db();
        $u = [TestDb::user($db, 'admin', 'admin'), TestDb::user($db, 'kim', 'user'), TestDb::user($db, 'lee', 'user')];
        $pid = TestDb::project($db, 'p');
        $an = TestDb::analysis($db, $pid, $u[0]);
        $sev = ['critical', 'high', 'medium', 'low', 'not_defined'];
        $st = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'];
        $ids = [];
        for ($i = 0; $i < 300; $i++) {
            $ids[] = TestDb::defect($db, $pid, $an, ['severity' => $sev[mt_rand(0, 4)], 'status' => $st[mt_rand(0, 5)], 'assignee_id' => mt_rand(0, 2) === 0 ? null : $u[mt_rand(0, 2)]]);
        }
        $t = TestDb::T0;
        $marks = [];
        for ($run = 1; $run <= 4; $run++) {
            foreach ($ids as $k => $id) {
                // 존재 구간: 대부분 계속, 일부는 Run 2에 끝남
                if ($run === 1) {
                    $db->execute('INSERT INTO defect_runs (defect_id, project_id, from_run, to_run) VALUES (?, ?, 1, ?)', [$id, $pid, $k % 7 === 0 ? 2 : null]);
                }
            }
            // 이 Run 동안의 변경 이력(현재 값도 함께 바꿈 — 이력의 old = 바뀌기 전 값)
            foreach (array_rand($ids, 60) as $k) {
                $id = $ids[$k];
                $field = ['severity', 'status', 'assignee'][mt_rand(0, 2)];
                $row = $db->one('SELECT severity, status, assignee_id FROM defects WHERE id = ?', [$id]);
                if ($field === 'assignee') {
                    $old = $row['assignee_id'] === null ? null : (string) $row['assignee_id'];
                    $new = mt_rand(0, 2) === 0 ? null : $u[mt_rand(0, 2)];
                    $db->execute('UPDATE defects SET assignee_id = ? WHERE id = ?', [$new, $id]);
                    $newV = $new === null ? null : (string) $new;
                } else {
                    $old = (string) $row[$field];
                    $newV = $field === 'severity' ? $sev[mt_rand(0, 4)] : $st[mt_rand(0, 5)];
                    $db->execute("UPDATE defects SET {$field} = ? WHERE id = ?", [$newV, $id]);
                }
                $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind) VALUES (?, ?, ?, ?, ?, ?, 'manual')",
                    [$id, $field, $old, $newV, $u[0], $t]);
            }
            $marks[$run] = (int) $db->value('SELECT COALESCE(MAX(id), 0) FROM defect_history');
        }
        $runs = $app->runs();
        $compared = 0;
        foreach ($marks as $run => $mark) {
            [$src, $params] = $runs->source($pid, ['run' => $run, 'mark' => $mark]);
            $new = $db->all('SELECT d.id, d.severity, d.status, d.assignee_id FROM ' . $src . ' ORDER BY d.id', $params);
            $old = $db->all('SELECT d0.id, ' . self::legacy('severity', 'd0') . ' AS severity, ' . self::legacy('status', 'd0') . ' AS status, '
                . self::legacy('assignee', 'd0') . ' AS assignee_id FROM defect_runs r JOIN defects d0 ON d0.id = r.defect_id'
                . ' WHERE r.project_id = ? AND r.from_run <= ? AND (r.to_run IS NULL OR r.to_run >= ?) ORDER BY d0.id', [$mark, $mark, $mark, $pid, $run, $run]);
            $norm = static fn (array $rows): array => array_map(static fn (array $r): array => [(int) $r['id'], (string) $r['severity'], (string) $r['status'],
                $r['assignee_id'] === null ? null : (int) $r['assignee_id']], $rows);
            $this->assertSame($norm($old), $norm($new), "Run {$run}");
            $compared += count($new);
        }
        $this->assertTrue($compared > 900, "대조 행 {$compared}");
    }
}
