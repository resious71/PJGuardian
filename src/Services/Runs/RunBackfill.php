<?php

declare(strict_types=1);

namespace PJGuardian\Services\Runs;

use PJGuardian\Support\Db;

/**
 * 기존 분석·결함의 Run 되채움(P20R, tools/backfill_runs.php). 마이그레이션 011 뒤 한 번 실행한다. 새 설치에는 필요 없다.
 *
 * 1. Run 번호: 성공한 분석을 프로젝트별 종료 시각(같으면 id) 순으로 1부터.
 * 2. Run 종료 표지: 그 Run까지의 자동 이력(분석의 Run ≤ N)과 그 Run 종료 시각 이전의 수동 이력 중 가장 큰 id.
 *    (실시간 반영은 트랜잭션 끝의 정확한 id를 쓴다. 되채움은 같은 초에 일어난 수동 변경의 앞뒤를 시각으로만 가른다 — 근사)
 * 3. 존재 구간: first_seen의 Run부터 시작, 자동 이력 not_detected·file_deleted(그 분석의 Run)에서 직전 Run까지로 닫고 redetected에서 다시 연다.
 *    이력이 남지 않는 경우(P16: resolved는 자동 전환 대상이 아님)
 *      - 수동 resolved 뒤 미감지: 마지막 감지 Run이 최신 Run보다 앞이고 상태가 resolved면 그 Run에서 닫는다(범위 밖이었을 수도 있음 — 근사).
 *      - 수동 resolved 뒤 미감지였다가 다시 감지(redetected): 닫힌 시점을 알 수 없어 이어진 구간으로 둔다(근사).
 *    상태가 resolved가 아닌데 마지막 감지가 최신 Run보다 앞이면 범위 밖 유지(실시간 반영과 같음).
 * 4. Run 요약: 각 Run의 존재 집합을 그 Run 종료 시점 값(RunService 복원)으로 센다.
 *
 * 구간은 번호를 붙이기 전에 배치로 만들고(그동안 프로젝트는 "되채움 필요" = 반영이 구간을 건드리지 않음), 마지막에 짧은 트랜잭션 하나에서
 * 번호·표지·요약을 쓴다. 그사이 새 분석이 끝났으면 그 프로젝트를 다시 계산한다. 이미 끝난 프로젝트는 건너뛴다(멱등). --force는 다시 만든다.
 */
final class RunBackfill
{
    private const BATCH = 2000;

    public function __construct(private readonly Db $db, private readonly RunService $runs)
    {
    }

    /** @return list<int> 되채움이 필요한 프로젝트(번호 없는 성공 분석이 있음) */
    public function pending(): array
    {
        return array_map('intval', array_column($this->db->all(
            "SELECT DISTINCT project_id FROM analyses WHERE status = 'succeeded' AND no_change = 0 AND run_number IS NULL ORDER BY project_id",
        ), 'project_id'));
    }

    /**
     * 프로젝트 하나. $dryRun이면 계산만 하고 쓰지 않는다.
     *
     * @return array<string, mixed> runs, defects, intervals, verify(Run별 복원 건수 vs 저장 건수)
     */
    public function project(int $projectId, bool $dryRun = false): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $plan = $this->plan($projectId);
            if ($dryRun) {
                return $plan['report'];
            }
            // 구간: 번호를 쓰기 전이라 반영이 이 프로젝트의 구간을 건드리지 않는다. 배치로 짧게
            $this->db->transaction(fn (Db $db) => $db->execute('DELETE FROM defect_runs WHERE project_id = ?', [$projectId]));
            foreach (array_chunk($plan['intervals'], self::BATCH) as $chunk) {
                $this->db->transaction(function (Db $db) use ($chunk, $projectId): void {
                    $stmt = $db->pdo()->prepare('INSERT INTO defect_runs (defect_id, project_id, from_run, to_run) VALUES (?, ?, ?, ?)');
                    foreach ($chunk as [$defect, $from, $to]) {
                        $stmt->execute([$defect, $projectId, $from, $to]);
                    }
                });
            }
            $ok = $this->db->transaction(function (Db $db) use ($projectId, $plan): bool {
                $now = array_map('intval', array_column($db->all("SELECT id FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id", [$projectId]), 'id'));
                $planned = array_keys($plan['runs']);
                sort($planned);
                if ($now !== $planned) {
                    return false; // 그사이 새 분석이 끝남 → 다시 계산
                }
                $db->execute('UPDATE analyses SET run_number = NULL WHERE project_id = ?', [$projectId]);
                foreach ($plan['runs'] as $aid => $r) {
                    $db->execute('UPDATE analyses SET run_number = ?, run_history_mark = ? WHERE id = ?', [$r['run'], $r['mark'], $aid]);
                }
                return true;
            });
            if (!$ok) {
                continue;
            }
            // Run 요약(번호·구간이 생긴 뒤 복원으로 계산)
            $verify = [];
            foreach ($plan['runs'] as $aid => $r) {
                [$src, $params] = $this->runs->source($projectId, ['run' => $r['run'], 'mark' => $r['mark']]);
                $summary = RunTracker::summarize($this->db->all('SELECT d.status AS st, d.severity AS s, d.assignee_id AS a, COUNT(*) AS n FROM ' . $src
                    . ' GROUP BY d.status, d.severity, d.assignee_id', $params));
                $this->db->execute('UPDATE analyses SET run_summary = ? WHERE id = ?', [(string) json_encode($summary), $aid]);
                $verify[] = self::verifyRow($r, $summary);
            }
            return $plan['report'] + ['verify' => $verify, 'written' => true];
        }
        throw new \RuntimeException('되채움 중 새 분석이 계속 끝나 완료하지 못함(워커를 멈추고 다시 실행)');
    }

    /**
     * @param array<string, mixed> $r 계획의 Run(stored_total, stored_severity)
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function verifyRow(array $r, array $summary): array
    {
        $sev = $r['stored_severity'];
        return [
            'run' => $r['run'], 'analysis_id' => $r['analysis_id'], 'no_change' => $r['no_change'],
            'stored_total' => $r['stored_total'], 'restored_total' => $summary['total'],
            'stored_severity' => $sev, 'restored_severity' => $summary['by_severity'],
            'match' => $r['stored_total'] === $summary['total'] && ($sev === null || $sev == $summary['by_severity']),
        ];
    }

    /** @return array{runs: array<int, array<string, mixed>>, intervals: list<array{0:int,1:int,2:?int}>, report: array<string, mixed>} */
    private function plan(int $projectId): array
    {
        $analyses = $this->db->all("SELECT id, finished_at, total_count, severity_counts, no_change FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY finished_at, id", [$projectId]);
        $runs = [];
        $runOf = [];
        foreach ($analyses as $i => $a) {
            $runOf[(int) $a['id']] = $i + 1;
            $sev = is_string($a['severity_counts']) ? json_decode($a['severity_counts'], true) : null;
            $runs[(int) $a['id']] = ['run' => $i + 1, 'analysis_id' => (int) $a['id'], 'finished_at' => (string) $a['finished_at'], 'mark' => 0,
                'stored_total' => $a['total_count'] === null ? null : (int) $a['total_count'], 'stored_severity' => is_array($sev) ? array_map('intval', $sev) : null,
                'no_change' => (int) $a['no_change'] === 1];
        }
        $latest = count($analyses);
        // 표지: 자동 이력은 그 분석의 Run, 수동 이력은 시각
        $marks = array_fill(1, max(1, $latest), 0);
        $hist = $this->db->all('SELECT h.id, h.kind, h.analysis_id, h.changed_at FROM defect_history h JOIN defects d ON d.id = h.defect_id WHERE d.project_id = ? ORDER BY h.id', [$projectId]);
        $byRun = array_values($runs);
        foreach ($hist as $h) {
            if ($h['analysis_id'] !== null) {
                $at = $runOf[(int) $h['analysis_id']] ?? null;
            } else {
                $at = null;
                foreach ($byRun as $r) {
                    if ($r['finished_at'] >= (string) $h['changed_at']) {
                        $at = $r['run'];
                        break;
                    }
                }
                // 마지막 Run 뒤의 수동 이력: 어느 Run 표지에도 들어가지 않는다
            }
            if ($at === null) {
                continue;
            }
            for ($n = $at; $n <= $latest; $n++) {
                $marks[$n] = max($marks[$n], (int) $h['id']);
            }
        }
        foreach ($runs as &$r) {
            $r['mark'] = $marks[$r['run']];
        }
        unset($r);
        // 구간
        $auto = [];
        foreach ($this->db->all("SELECT h.defect_id, h.reason_code, h.analysis_id FROM defect_history h JOIN defects d ON d.id = h.defect_id WHERE d.project_id = ? AND h.kind = 'auto' ORDER BY h.id", [$projectId]) as $h) {
            $auto[(int) $h['defect_id']][] = [(string) $h['reason_code'], $runOf[(int) $h['analysis_id']] ?? null];
        }
        $intervals = [];
        $approx = 0;
        $stmt = $this->db->pdo()->prepare('SELECT d.id, d.status, d.first_seen_analysis_id, ' . \PJGuardian\Services\Defects\DefectSeen::expr('d') . ' AS last_seen_analysis_id FROM defects d WHERE d.project_id = ? ORDER BY d.id');
        $stmt->execute([$projectId]);
        $defects = 0;
        while (($d = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $defects++;
            $start = $runOf[(int) $d['first_seen_analysis_id']] ?? null;
            if ($start === null) {
                continue; // 성공 분석이 아닌 first_seen(있을 수 없음)
            }
            $open = $start;
            foreach ($auto[(int) $d['id']] ?? [] as [$reason, $run]) {
                if ($run === null) {
                    continue;
                }
                if ($reason === 'redetected') {
                    if ($open === null) {
                        $open = $run;
                    }
                } elseif ($open !== null && $run - 1 >= $open) {
                    $intervals[] = [(int) $d['id'], $open, $run - 1];
                    $open = null;
                }
            }
            if ($open !== null) {
                $last = $runOf[(int) $d['last_seen_analysis_id']] ?? $latest;
                if ($d['status'] === 'resolved' && $last < $latest && $last >= $open) {
                    $intervals[] = [(int) $d['id'], $open, $last]; // 수동 resolved 뒤 미감지(근사)
                    $approx++;
                } else {
                    $intervals[] = [(int) $d['id'], $open, null];
                }
            }
        }
        return ['runs' => $runs, 'intervals' => $intervals, 'report' => [
            'project_id' => $projectId, 'runs' => count($runs), 'defects' => $defects, 'intervals' => count($intervals), 'manual_resolved_closed' => $approx,
        ]];
    }
}
