<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 결함 목록 뒤쪽 페이지(P31 B-3): 뒤쪽 절반은 순서를 뒤집어 앞에서 고른 뒤 다시 뒤집는다. 어떤 페이지든 **정방향 전체 순서**(내보내기용
 * exportIds — 뒤집기를 쓰지 않음)를 그 위치에서 자른 것과 id 순서가 같아야 한다. 현재 표와 과거 Run(존재 집합·전체 복원 두 경로),
 * 정렬 6종 × 오름·내림, 필터 여러 가지(검색어·상태·담당자·심각도 — 복원 값을 쓰는 것 포함), 여러 위치(첫 쪽·가운데·마지막·끝 넘김)에서.
 */
final class DefectListReverseTest extends TestCase
{
    public function testEveryPageEqualsSliceOfForwardOrder(): void
    {
        $root = $this->tempDir('rev');
        $app = TestApp::make($root);
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $kim = TestDb::user($db, 'kim', 'user');
        $pid = TestDb::project($db, 'rev', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, ?)", [$pid, $kim, TestDb::T0]);
        $f = new RunFixture($app, $root, $pid, $admin);
        $files = ['a.py' => 12, 'b.py' => 12, 'c/d.py' => 12, 'e.py' => 12];
        $hits = static function (int $mod) use ($files): array {
            $out = [];
            foreach (array_keys($files) as $i => $file) {
                for ($line = 1; $line <= 12; $line++) {
                    if (($line + $i) % $mod !== 0) {
                        $out[] = [$file, $line, ['ruff:F401', 'ruff:F821', 'ruff:E501'][$line % 3]];
                    }
                }
            }
            return $out;
        };
        $f->analyze($files, $hits(5));
        $user = $db->one('SELECT * FROM users WHERE id = ?', [$admin]);
        $upd = $app->defectUpdate();
        $sev = ['critical', 'high', 'medium', 'low'];
        $st = ['in_progress', 'postponed', 'intentional', 'not_fix'];
        $n = 0;
        foreach ($db->all('SELECT id, version FROM defects WHERE project_id = ? ORDER BY id', [$pid]) as $r) {
            if ($n++ % 3 === 0) {
                $upd->update($user, (int) $r['id'], ['version' => (int) $r['version'], 'severity' => $sev[$n % 4], 'status' => $st[$n % 4], 'assignee_id' => $n % 2 ? $kim : $admin]);
            }
        }
        $f->analyze($files, $hits(4));
        $f->analyze($files, $hits(7));
        $runs = $app->runs();
        $q = $app->defectQuery();
        $filters = [[], ['q' => 'a.py'], ['status' => 'open'], ['assignee' => 'none'], ['severity' => 'high,not_defined'], ['rule' => 'ruff:F821'],
            ['file' => 'c', 'file_mode' => 'contains']];
        $views = [['현재 표', null, null]];
        foreach ([1, 2] as $k) {
            $run = $runs->resolve($pid, (string) $k);
            $views[] = ["Run {$k}", $runs->source($pid, $run), $run];
        }
        $checked = 0;
        foreach ($views as [$label, $src, $run]) {
            foreach ($filters as $fq) {
                foreach (['file', 'rule', 'severity', 'status', 'run', 'last_seen'] as $sort) {
                    foreach (['asc', 'desc'] as $order) {
                        $flt = DefectFilter::fromQuery($fq + ['sort' => $sort, 'order' => $order]);
                        $all = $q->exportIds($pid, $flt, $src, $run);
                        $total = count($all);
                        foreach ([[5, 0], [3, intdiv($total, 2)], [3, intdiv($total, 2) + 1], [5, max(0, $total - 5)], [7, max(0, $total - 3)], [1, max(0, $total - 1)], [4, $total + 2]] as [$limit, $offset]) {
                            foreach ([false, true] as $full) {
                                [$rows, $count] = $q->list($pid, $flt, $limit, $offset, $src, $run, $full);
                                $ctx = "{$label} " . json_encode($fq) . " {$sort} {$order} {$limit}/{$offset}" . ($full ? ' 전체 복원' : '');
                                $this->assertSame($total, $count, $ctx . ' 건수');
                                $this->assertSame(array_slice($all, $offset, $limit), array_map(static fn (array $r): int => (int) $r['id'], $rows), $ctx);
                                $checked++;
                            }
                        }
                    }
                }
            }
        }
        $this->assertSame(3 * count($filters) * 6 * 2 * 7 * 2, $checked);

        // 요약(P31: 검색어·경로 포함·과거 Run은 한 번 훑기): 칩 숫자 = 그 칩 하나만 고른 건수(목록과 같은 원본)
        $combos = [[], ['status' => 'open'], ['q' => 'a.py', 'rule' => 'ruff:F821'], ['assignee' => 'none', 'severity' => 'high,not_defined'],
            ['assignee' => (string) $kim, 'status' => 'open,in_progress,postponed'], ['file' => 'c', 'file_mode' => 'contains', 'rule' => 'ruff:F401,ruff:E501']];
        $count = static fn (array $fq, ?array $src, ?array $run): int => count($q->exportIds($pid, DefectFilter::fromQuery($fq), $src, $run));
        foreach ($views as [$label, $src, $run]) {
            foreach ($combos as $fq) {
                $s = $q->summary($pid, DefectFilter::fromQuery($fq), 200, $src);
                $ctx = "{$label} " . json_encode($fq);
                $this->assertSame($count($fq, $src, $run), $s['total'], $ctx . ' total');
                foreach ($s['severity'] as $v => $n) {
                    $this->assertSame($count(['severity' => $v] + $fq, $src, $run), $n, "{$ctx} severity={$v}");
                }
                foreach ($s['status'] as $v => $n) {
                    $this->assertSame($count(['status' => $v] + $fq, $src, $run), $n, "{$ctx} status={$v}");
                }
                foreach ($s['rules'] as $r) {
                    $this->assertSame($count(['rule' => $r['rule_key']] + $fq, $src, $run), $r['count'], "{$ctx} rule={$r['rule_key']}");
                }
                foreach ($s['assignees'] as $a) {
                    $this->assertSame($count(['assignee' => $a['id'] === null ? 'none' : (string) $a['id']] + $fq, $src, $run), $a['count'], "{$ctx} assignee");
                }
            }
        }
    }
}
