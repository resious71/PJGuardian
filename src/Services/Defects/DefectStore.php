<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Services\Runs\RunTracker;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Db;
use PJGuardian\Support\ExcludeMatcher;

/**
 * 결함 저장과 판정(명세 6.1~6.3, 8, 19.10). DefectIngest가 채운 temp.cur_defects를 **하나의 쓰기 트랜잭션**에서 반영한다.
 * 분석 행을 succeeded로 바꾸는 것도 같은 트랜잭션의 마지막 단계이며 "running이고 취소 요청 없음" 조건부다(P14) —
 * 조건이 맞지 않으면(그사이 취소·타임아웃) 전체를 되돌린다. 실패·취소된 분석은 결함을 하나도 바꾸지 않는다.
 *
 * 판정(고유 키 (project_id, signature, ordinal))
 *   이번에 감지 + 기존 없음            → 신규(심각도 = 규칙 기본 심각도(P21S, 출처 auto. 매핑이 없거나 끄면 not_defined·none), 상태 open,
 *                                        담당자 없음, first_seen = 이번). 이력은 남기지 않는다(생성 시점 값이라 과거 Run 복원과 맞다)
 *   이번에 감지 + 기존 resolved        → 재오픈 open(이력 auto, 사유 redetected, version + 1). 수동 resolved도 같다
 *   이번에 감지 + 기존 그 밖의 상태     → 유지(상태·version 그대로). 위치·메시지가 바뀐 행만 갱신한다(P19C)
 *   기존(resolved 아님)이 이번에 미감지:
 *     1. 이번 분석의 제외 규칙(ExcludeMatcher)에 해당 → 범위 밖, 상태 유지
 *     2. 도구가 분석한 파일 목록(ScopeList)에 있음  → resolved, 사유 not_detected
 *     3. 작업 디렉토리에 파일이 있음(업로드 설정의 제외 등으로 도구가 보지 않음) → 범위 밖, 상태 유지
 *     4. 파일이 없음(이번 zip에서 삭제)            → resolved, 사유 file_deleted
 *   자동 전환은 현재 상태(open, in_progress, postponed, intentional, not_fix)와 무관하며 이전 상태는 이력에 남고 version + 1.
 *
 * Run(P20R, 마이그레이션 011): 같은 트랜잭션에서 Run 번호(성공한 분석 수 + 1)를 부여하고, 존재 구간(defect_runs)·Run 종료 표지
 * (run_history_mark)·Run 요약(run_summary)을 기록한다(Services\Runs\RunTracker). 위 판정은 바꾸지 않는다.
 *
 * 기존 결함(재감지·재오픈·유지·범위 밖)의 심각도와 출처는 어떤 경우에도 바꾸지 않는다(사람이 바꾼 값 보호, P21S).
 *
 * 마지막 감지(P19C, 마이그레이션 023 — 읽기는 DefectSeen 한 곳): 유지 결함의 last_seen을 행마다 쓰지 않는다. 감지된 행은 seen_live = 1
 * ("최신 실제 성공 분석에서 감지")이고, 감지 → 미감지가 될 때만 last_seen_analysis_id = 직전 최신 분석·seen_live = 0으로 고정한다.
 * 그래서 같은 결과를 다시 반영하면 결함 표 쓰기는 0건이다(위치·메시지가 바뀐 행, 신규·재오픈·해결·고정만 쓴다).
 * 기존 결함 짝(서명·순번 → id)은 쓰기 트랜잭션 **전에** 읽기로 찾아 temp.cur_defects.defect_id에 둔다(match()) — 트랜잭션 안의 단계는
 * 기본 키로만 잇는다. 같은 프로젝트의 결함은 분석 하나만 만들므로(프로젝트당 실행 1) 그사이 짝이 바뀌지 않는다. 같은 읽기에서 갱신할 행 표시(upd)와
 * 이번에 감지되지 않은 결함 후보와 그 분류(temp.unseen)도 구한다 —상태처럼 사용자가 바꿀 수 있는 값은 트랜잭션 안에서 다시 읽는다.
 */
final class DefectStore
{
    /** 짝이 없는(신규) 결과 수 — match()가 센다 */
    private int $newCandidates = -1;

    /** @param SeverityMap|null $severity 규칙 기본 심각도(P21S). null이면 신규 결함은 not_defined */
    public function __construct(private readonly Db $db, private readonly ?SeverityMap $severity = null)
    {
    }

    /**
     * @param array<string, mixed> $tool 도구 정보(tool_version, config_source, config_hash, ignored_configs)
     * @param array{storage_path:string, size_bytes:int, file_count:int}|null $snapshot
     * @param array<string, mixed> $excludeRules analysis_file_lists.exclude_rules
     * @return array{total:int, new:int, reopened:int, resolved:int, resolved_not_detected:int, resolved_file_deleted:int, unchanged:int,
     *   out_of_scope:int, baseline:?int, severity:array<string,int>, tx_ms:int, assigned_changes:list<array{defect_id:int, assignee_id:int, change:string, reason:string}>}
     * @throws AnalysisNotRunningException 그사이 취소·타임아웃 등으로 running이 아님(전체 되돌림)
     */
    public function apply(
        int $analysisId,
        int $projectId,
        ScopeList $scope,
        ExcludeMatcher $matcher,
        string $workPath,
        array $tool,
        ?array $snapshot,
        array $excludeRules,
        string $now,
    ): array {
        $workPath = rtrim(str_replace('\\', '/', $workPath), '/');
        $t0 = hrtime(true);
        $matchMs = $this->match($projectId, $scope, $matcher, $workPath);
        $endMark = 0;
        $result = $this->db->transaction(function (Db $db) use (&$endMark, $analysisId, $projectId, $scope, $workPath, $tool, $snapshot, $excludeRules, $now): array {
            $pdo = $db->pdo();
            $pdo->exec('DELETE FROM temp.reopened');
            $pdo->exec('DELETE FROM temp.auto_resolve');
            $total = (int) $db->value('SELECT COUNT(*) FROM temp.cur_defects');
            // Run(P20R): 번호는 성공한 분석 수 + 1. 되채움 전 프로젝트는 번호만(구간은 되채움이 만든다)
            $run = RunTracker::nextRun($db, $projectId);
            $tracking = RunTracker::ready($db, $projectId);

            $mark = hrtime(true);
            $steps = [];
            $lap = static function (string $name) use (&$mark, &$steps): void {
                $now = hrtime(true);
                $steps[$name] = (int) (($now - $mark) / 1e6);
                $mark = $now;
            };
            // 1) 재오픈 대상(resolved인데 다시 감지)
            // 비교 기준 = 직전 성공 분석(감지 → 미감지가 된 결함의 마지막 감지로도 쓴다)
            $baseline = $db->value("SELECT MAX(id) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 AND id < ?", [$projectId, $analysisId]);
            $db->execute(
                'INSERT INTO temp.reopened (id) SELECT d.id FROM temp.cur_defects c JOIN defects d ON d.id = c.defect_id'
                . " WHERE c.defect_id IS NOT NULL AND d.status = 'resolved'",
            );
            $reopened = (int) $db->value('SELECT COUNT(*) FROM temp.reopened');
            $db->execute(
                'INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, reason_code, memo, analysis_id)'
                . " SELECT d.id, 'status', d.status, 'open', NULL, ?, 'auto', 'redetected', NULL, ? FROM defects d JOIN temp.reopened r ON r.id = d.id",
                [$now, $analysisId],
            );
            $db->execute("UPDATE defects SET status = 'open', version = version + 1, seen_live = 1, last_seen_analysis_id = ?, updated_at = ? WHERE id IN (SELECT id FROM temp.reopened)",
                [$analysisId, $now]);

            $lap('reopen');
            // 2) 감지된 기존 결함: 위치·메시지가 바뀌었거나 아직 seen_live가 아닌 행만(감지 시작·023 전 행). 유지 결함은 쓰지 않는다(P19C)
            $seenWrites = $db->execute(
                'UPDATE defects SET seen_live = 1, last_seen_analysis_id = ?, line_no = c.line_no, col_no = c.col_no, end_line_no = c.end_line_no,'
                . ' end_col_no = c.end_col_no, message = c.message, updated_at = ?'
                . ' FROM temp.cur_defects c WHERE c.upd = 1 AND c.defect_id = defects.id AND (defects.seen_live = 0 OR defects.line_no IS NOT c.line_no'
                . ' OR defects.col_no IS NOT c.col_no OR defects.end_line_no IS NOT c.end_line_no OR defects.end_col_no IS NOT c.end_col_no OR defects.message IS NOT c.message)',
                [$analysisId, $now],
            );

            $lap('update_seen');
            // 3) 신규: 심각도는 규칙 기본 심각도. 이번 결과의 규칙 종류(수십 개)만 PHP 매핑으로 정해 임시 표에 두고 INSERT가 조인한다(결함마다 계산하지 않음)
            $this->ruleSeverities($db);
            // 신규 후보가 없으면(같은 결과 재반영) 임시 표를 훑지 않는다(짝 단계에서 센 수)
            $new = $this->newCandidates === 0 ? 0 : $db->execute(
                'INSERT INTO defects (project_id, signature, ordinal, tool, language, rule_key, file_path, line_no, col_no, end_line_no, end_col_no,'
                . ' message, severity, severity_source, first_seen_analysis_id, last_seen_analysis_id, created_at, updated_at, seen_live)'
                . ' SELECT ?, c.signature, c.ordinal, c.tool, c.language, c.rule_key, c.file_path, c.line_no, c.col_no, c.end_line_no, c.end_col_no,'
                . " c.message, COALESCE(s.severity, 'not_defined'), CASE WHEN s.severity IS NULL THEN 'none' ELSE 'auto' END, ?, ?, ?, ?, 1"
                . ' FROM temp.cur_defects c LEFT JOIN temp.rule_severity s ON s.tool = c.tool AND s.rule_key = c.rule_key'
                . ' WHERE c.defect_id IS NULL',
                [$projectId, $analysisId, $analysisId, $now, $now],
            );
            // 이번에 감지 = 짝이 있는 기존 결함(temp.cur_defects.defect_id) + 이번에 만든 결함(first_seen = 이번 분석). 새 행의 id는 짝에 넣지 않는다(쓰기 트랜잭션을 늘리지 않게)

            if ($tracking) {
                RunTracker::openNew($db, $projectId, $analysisId, $run);
                RunTracker::reopenDetected($db, $projectId, $run);
            }

            $lap('insert_new');
            // 4) 이번에 감지되지 않은 기존 결함(resolved 아님) 분류
            // 분류(temp.unseen.reason)는 match()가 트랜잭션 전에 경로 단위로 정해 두었다 — 여기서는 상태(사용자가 바꿀 수 있음)만 기본 키로 다시 본다
            $counts = ['not_detected' => 0, 'file_deleted' => 0, 'out_of_scope' => 0];
            foreach ($db->all("SELECT u.reason, COUNT(*) AS n FROM temp.unseen u JOIN defects d ON d.id = u.id WHERE d.status <> 'resolved' GROUP BY u.reason") as $r) {
                $counts[$r['reason'] ?? 'out_of_scope'] = (int) $r['n'];
            }
            $db->execute("INSERT INTO temp.auto_resolve (id, reason) SELECT u.id, u.reason FROM temp.unseen u JOIN defects d ON d.id = u.id"
                . " WHERE d.status <> 'resolved' AND u.reason IS NOT NULL ORDER BY u.id");
            $resolved = $counts['not_detected'] + $counts['file_deleted'];
            $db->execute(
                'INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, reason_code, memo, analysis_id)'
                . " SELECT d.id, 'status', d.status, 'resolved', NULL, ?, 'auto', a.reason, NULL, ? FROM temp.auto_resolve a JOIN defects d ON d.id = a.id",
                [$now, $analysisId],
            );
            $db->execute("UPDATE defects SET status = 'resolved', version = version + 1, updated_at = ? WHERE id IN (SELECT id FROM temp.auto_resolve)", [$now]);

            // 담당자가 있는 결함의 자동 전환(P24 알림이 구독할 이벤트 자료)
            $assigned = [];
            foreach ($db->all(
                "SELECT d.id, d.assignee_id, 'reopened' AS change, 'redetected' AS reason FROM temp.reopened r JOIN defects d ON d.id = r.id WHERE d.assignee_id IS NOT NULL"
                . " UNION ALL SELECT d.id, d.assignee_id, 'resolved', a.reason FROM temp.auto_resolve a JOIN defects d ON d.id = a.id WHERE d.assignee_id IS NOT NULL",
            ) as $r) {
                $assigned[] = ['defect_id' => (int) $r['id'], 'assignee_id' => (int) $r['assignee_id'], 'change' => (string) $r['change'], 'reason' => (string) $r['reason']];
            }

            $runClosed = $tracking ? RunTracker::close($db, $run) : null;
            // 감지 → 미감지(해결·범위 밖 유지·수동 해결 뒤 미감지): 마지막 감지를 직전 최신 분석으로 고정(P19C — 상태가 바뀌는 행만 쓴다)
            $frozen = $baseline === null ? 0 : $db->execute(
                // updated_at: 예전에는 감지될 때마다 그 분석 시각이었다 → 고정할 때 마지막 감지 분석의 종료 시각과 늦은 쪽(자동 해결이면 이미 지금)
                'UPDATE defects SET last_seen_analysis_id = ?, seen_live = 0, updated_at = max(updated_at, (SELECT finished_at FROM analyses WHERE id = ?))'
                . ' WHERE id IN (SELECT id FROM temp.unseen) AND seen_live = 1',
                [(int) $baseline, (int) $baseline],
            );

            $lap('classify_resolve');
            // 5) 더 새 분석에서 다시 감지된 결함의 발췌는 폐기(명세 7: 상세는 마지막 감지 분석의 코드)
            $db->execute('DELETE FROM defect_excerpts WHERE EXISTS (SELECT 1 FROM temp.cur_defects c WHERE c.defect_id = defect_excerpts.defect_id)'); // 발췌 표(작음)에서 시작

            $lap('excerpts');
            // 6) 분석 대상 파일 목록·제외 규칙, 스냅샷
            $ins = $pdo->prepare("INSERT INTO analysis_file_lists (analysis_id, exclude_rules, file_count, encoding, files_blob, created_at) VALUES (?, ?, ?, 'zlib-lines-v1', ?, ?)");
            $ins->bindValue(1, $analysisId, \PDO::PARAM_INT);
            $ins->bindValue(2, (string) json_encode($excludeRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $ins->bindValue(3, $scope->count(), \PDO::PARAM_INT);
            $ins->bindValue(4, $scope->encode(), \PDO::PARAM_LOB);
            $ins->bindValue(5, $now);
            $ins->execute();
            if ($snapshot !== null) {
                $db->execute(
                    "INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at) VALUES (?, ?, ?, ?, 'full', ?)",
                    [$analysisId, $snapshot['storage_path'], $snapshot['size_bytes'], $snapshot['file_count'], $now],
                );
            }

            $lap('file_list');
            // 7) 통계·비교 기준·분석 완료(조건부: running이고 취소 요청 없음)
            $severity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'not_defined' => 0];
            foreach ($db->all('SELECT d.severity, COUNT(*) AS n FROM temp.cur_defects c JOIN defects d ON d.id = c.defect_id GROUP BY d.severity'
                . ' UNION ALL SELECT severity, COUNT(*) FROM defects WHERE project_id = ? AND first_seen_analysis_id = ? GROUP BY severity', [$projectId, $analysisId]) as $r) {
                $severity[(string) $r['severity']] += (int) $r['n'];
            }
            // 비교 기준($baseline, 앞에서 구함): 직전 성공 분석. "변경 없음"(no_change, 명세 18.2) 분석은 통계가 없어 제외한다(P19 감사에서 발견, db_schema.md 6절)
            $unchanged = $total - $new - $reopened;
            $ignored = $tool['ignored_configs'] ?? null;
            $runSummary = $tracking ? (string) json_encode(RunTracker::summaryOpen($db, $projectId)) : null;
            $runMark = RunTracker::historyMark($db);
            $done = $db->execute(
                "UPDATE analyses SET status = 'succeeded', finished_at = ?, total_count = ?, new_count = ?, resolved_count = ?, reopened_count = ?,"
                . ' unchanged_count = ?, severity_counts = ?, baseline_analysis_id = ?,'
                . ' tool_version = COALESCE(?, tool_version), config_source = COALESCE(?, config_source), config_hash = COALESCE(?, config_hash),'
                . ' ignored_configs = COALESCE(?, ignored_configs), run_number = ?, run_history_mark = ?, run_summary = ?'
                . " WHERE id = ? AND status = 'running' AND cancel_requested = 0",
                [
                    $now, $total, $new, $resolved, $reopened, $unchanged, (string) json_encode($severity), $baseline === null ? null : (int) $baseline,
                    isset($tool['tool_version']) ? (string) $tool['tool_version'] : null,
                    in_array($tool['config_source'] ?? null, ['upload', 'server_default'], true) ? $tool['config_source'] : null,
                    is_string($tool['config_hash'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $tool['config_hash']) === 1 ? $tool['config_hash'] : null,
                    is_array($ignored) ? (string) json_encode(array_values($ignored), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    $run, $runMark, $runSummary,
                    $analysisId,
                ],
            );
            if ($done !== 1) {
                throw new AnalysisNotRunningException('분석이 running이 아니거나 취소 요청됨');
            }
            $lap('finish');
            $endMark = hrtime(true);
            return [
                'total' => $total, 'new' => $new, 'reopened' => $reopened, 'resolved' => $resolved,
                'resolved_not_detected' => $counts['not_detected'], 'resolved_file_deleted' => $counts['file_deleted'],
                'unchanged' => $unchanged, 'out_of_scope' => $counts['out_of_scope'], 'baseline' => $baseline === null ? null : (int) $baseline,
                'severity' => $severity, 'assigned_changes' => $assigned, 'steps_ms' => $steps,
                'run' => $run, 'run_tracking' => $tracking, 'run_closed' => $runClosed,
                // P19C: 결함 표 쓰기 건수(유지 결함 0건 확인용) — 위치·메시지 갱신·감지 시작, 미감지 고정
                'writes' => ['seen' => $seenWrites, 'frozen' => $frozen],
            ];
        });
        $result['steps_ms']['commit'] = (int) ((hrtime(true) - $endMark) / 1e6); // COMMIT(WAL 기록) 시간
        $result['tx_ms'] = (int) ((hrtime(true) - $t0) / 1e6) - $matchMs;
        $result['match_ms'] = $matchMs;
        return $result;
    }

    /**
     * 쓰기 트랜잭션 전(읽기): 이번 결과의 각 결함 → 기존 결함 id(없으면 NULL), 갱신 표시, 미감지 후보와 그 분류. 본 DB에는 쓰지 않는다(임시 표만). 소요 ms
     *
     * 미감지 분류(temp.unseen.reason): 'not_detected' | 'file_deleted' | NULL(범위 밖 — 상태 유지). 경로·제외 규칙·이번 범위·작업 폴더로만
     * 정해지므로(결함의 file_path는 바뀌지 않는다) 경로마다 한 번 정해 트랜잭션 밖에 둔다. 자동 해결과 Run 구간 닫기(수동 resolved 미감지)가 같이 쓴다.
     */
    private function match(int $projectId, ScopeList $scope, ExcludeMatcher $matcher, string $workPath): int
    {
        $t = hrtime(true);
        $this->db->transaction(function (Db $db) use ($projectId, $scope, $matcher, $workPath): void {
            $db->execute(
                'UPDATE temp.cur_defects SET defect_id = (SELECT d.id FROM defects d WHERE d.project_id = ? AND d.signature = cur_defects.signature AND d.ordinal = cur_defects.ordinal)',
                [$projectId],
            );
            // 갱신할 행(위치·메시지가 바뀌었거나 아직 seen_live가 아님) 표시 — 이 값들은 반영(프로젝트당 하나)만 바꾸므로 그사이 달라지지 않는다
            // (되채움 도구는 seen_live를 0 → 1로만 바꾼다: 표시가 남아 한 번 더 쓰는 쪽이지 빠뜨리지 않는다)
            $db->execute(
                'UPDATE temp.cur_defects SET upd = 1 WHERE defect_id IS NOT NULL AND EXISTS (SELECT 1 FROM defects d WHERE d.id = cur_defects.defect_id'
                . ' AND (d.seen_live = 0 OR d.line_no IS NOT cur_defects.line_no OR d.col_no IS NOT cur_defects.col_no OR d.end_line_no IS NOT cur_defects.end_line_no'
                . ' OR d.end_col_no IS NOT cur_defects.end_col_no OR d.message IS NOT cur_defects.message))',
            );
            // 이번에 감지되지 않은 기존 결함 후보(상태와 무관 — 상태는 트랜잭션 안에서 기본 키로 다시 본다). 결함 행은 반영만 만든다
            // 열린 존재 구간이 없는 기존 결함(재오픈·닫힌 뒤 다시 감지 → 새 구간, RunTracker::reopenDetected). 구간은 반영만 바꾼다
            $db->execute('UPDATE temp.cur_defects SET noint = 1 WHERE defect_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM defect_runs r WHERE r.defect_id = cur_defects.defect_id AND r.to_run IS NULL)');
            $this->newCandidates = (int) $db->value('SELECT COUNT(*) FROM temp.cur_defects WHERE defect_id IS NULL');
            $db->pdo()->exec('DELETE FROM temp.unseen');
            $db->execute('INSERT INTO temp.unseen (id) SELECT d.id FROM defects d WHERE d.project_id = ?'
                . ' AND NOT EXISTS (SELECT 1 FROM temp.cur_defects c WHERE c.defect_id = d.id)', [$projectId]);
            $pdo = $db->pdo();
            $pdo->exec('DELETE FROM temp.unseen_path');
            $add = $pdo->prepare('INSERT INTO temp.unseen_path (path, reason) VALUES (?, ?)');
            foreach ($db->all('SELECT DISTINCT d.file_path FROM temp.unseen u JOIN defects d ON d.id = u.id') as $r) {
                $path = (string) $r['file_path'];
                $add->execute([$path, self::classify($path, $scope, $matcher, $workPath)]);
            }
            $db->execute('UPDATE temp.unseen SET reason = (SELECT p.reason FROM defects d JOIN temp.unseen_path p ON p.path = d.file_path WHERE d.id = unseen.id)');
        }, false);
        return (int) ((hrtime(true) - $t) / 1e6);
    }

    /** 미감지 결함의 분류: 'not_detected' | 'file_deleted' | null(범위 밖) */
    private static function classify(string $path, ScopeList $scope, ExcludeMatcher $matcher, string $workPath): ?string
    {
        if ($matcher->matches($path)) {
            return null;
        }
        if ($scope->contains($path)) {
            return 'not_detected';
        }
        return SourceNormalizer::onDisk($workPath, $path) !== null ? null : 'file_deleted'; // 파일이 있으면 도구가 보지 않은 파일(업로드 설정의 제외) = 범위 밖
    }

    /** temp.rule_severity:이번 결과의 (도구, 규칙 키) → 기본 심각도. not_defined(매핑 없음)는 넣지 않는다(출처 none) */
    private function ruleSeverities(Db $db): void
    {
        $pdo = $db->pdo();
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS rule_severity (tool TEXT NOT NULL, rule_key TEXT NOT NULL, severity TEXT NOT NULL, PRIMARY KEY (tool, rule_key))');
        $pdo->exec('DELETE FROM temp.rule_severity');
        if ($this->severity === null) {
            return;
        }
        $ins = $pdo->prepare('INSERT INTO temp.rule_severity (tool, rule_key, severity) VALUES (?, ?, ?)');
        foreach ($db->all('SELECT DISTINCT tool, rule_key FROM temp.cur_defects') as $r) {
            $sev = $this->severity->forRule((string) $r['tool'], (string) $r['rule_key']);
            if ($sev !== 'not_defined') {
                $ins->execute([(string) $r['tool'], (string) $r['rule_key'], $sev]);
            }
        }
    }
}
