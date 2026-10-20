<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * "변경 없음" 판단(P27, 명세 18.2·19.10). 스케줄러(ScheduleTicker → ScheduledAnalysisLauncher)와 "지금 가져와 분석"(분석 자식이 가져오기 전에)이
 * 같은 함수를 부른다. 소스를 내려받지 않고 원격 최신 리비전만 조회한다(RepoHead = 연결 테스트와 같은 호출).
 *
 * 생략(unchanged)은 아래가 **모두** 같을 때만. 하나라도 다르거나 알 수 없으면 분석(changed):
 *   기준 = 직전 성공 분석(status succeeded, no_change = 0 — 실패·취소·변경 없음은 기준이 아님), 상세 보관 안(run_detail_deleted_at IS NULL)
 *   연결    = analyses.source_key ↔ 지금 연결의 sourceKey(종류·주소·브랜치·하위 경로/depot 경로)
 *   조건    = analyses.env_key ↔ AnalysisEnvKey(도구·버전·서버 설정 해시(규칙 켜기·끄기)·개정·제외 패턴·대상 확장자)
 *   리비전  = analyses.source_revision ↔ 원격 최신(Git 브랜치 헤드 커밋, Perforce 경로 최신 changelist)
 * 사유: forced / no_base / connection_changed / tool_or_config_changed / revision_differs / check_error / same.
 * 원격 조회 실패(check_error, code = 정제된 오류 코드)는 "변경 없음"이 아니다 — 호출자는 평소처럼 큐에 넣고, 가져오기가 같은 오류로
 * 재시도(P26)·실패 기록을 한다. 그 밖의 예외도 check_error(경고 로그)로 분석 경로. 잘못 생략하는 것보다 한 번 더 분석하는 쪽이 안전하다.
 * 원격을 조회했으면 연결의 "마지막 확인"(last_check_at·revision·result)을 갱신한다.
 */
final class UnchangedSourceCheck
{
    public const REASONS = ['forced', 'no_base', 'connection_changed', 'tool_or_config_changed', 'revision_differs', 'check_error', 'same'];

    /**
     * @param \Closure(int): array{ref: ?string, revision: string} $head 프로젝트 → 원격 최신(RepoHead::lookup). RepoException은 조회 실패
     * @param \Closure(int): ?string $envKey 프로젝트 → 분석 조건 키(AnalysisEnvKey::compute)
     */
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly \Closure $head,
        private readonly \Closure $envKey,
    ) {
    }

    /** 연결 식별 키(비밀 없음: Git 주소는 저장 때 이미 사용자 정보가 없다) */
    public static function sourceKey(array $conn): string
    {
        return hash('sha256', (string) json_encode([
            (string) $conn['kind'], (string) $conn['location'], $conn['branch'] ?? null, $conn['path'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{decision: string, reason: string, remote_revision: ?string, remote_ref: ?string, base_analysis_id: ?int,
     *               source_key: ?string, env_key: ?string, code: ?string}
     */
    public function evaluate(int $projectId, bool $force = false): array
    {
        $out = ['decision' => 'changed', 'reason' => 'forced', 'remote_revision' => null, 'remote_ref' => null, 'base_analysis_id' => null,
            'source_key' => null, 'env_key' => null, 'code' => null];
        if ($force) {
            return $out;
        }
        try {
            return $this->judge($projectId, $out);
        } catch (\Throwable $e) {
            $this->logger->warn('변경 없음 판단 오류(분석 경로로 진행)', ['project_id' => $projectId, 'error' => $e->getMessage(),
                'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            return ['reason' => 'check_error', 'code' => 'check_exception'] + $out;
        }
    }

    /** @param array<string, mixed> $out */
    private function judge(int $projectId, array $out): array
    {
        $conn = $this->db->one('SELECT * FROM project_repo_connections WHERE project_id = ?', [$projectId]);
        if ($conn === null) {
            return ['reason' => 'check_error', 'code' => 'repo_not_configured'] + $out;
        }
        $out['source_key'] = self::sourceKey($conn);
        $base = $this->db->one(
            "SELECT id, source_kind, source_revision, source_key, env_key, run_detail_deleted_at FROM analyses"
            . " WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id DESC LIMIT 1",
            [$projectId],
        );
        if ($base === null || $base['run_detail_deleted_at'] !== null) {
            return ['reason' => 'no_base'] + $out;
        }
        $out['base_analysis_id'] = (int) $base['id'];
        if ($base['source_kind'] !== $conn['kind'] || $base['source_key'] !== $out['source_key']) {
            return ['reason' => 'connection_changed'] + $out;
        }
        if ($base['source_revision'] === null || $base['source_revision'] === '') {
            return ['reason' => 'no_base'] + $out;
        }
        $out['env_key'] = ($this->envKey)($projectId);
        if ($out['env_key'] === null || $base['env_key'] !== $out['env_key']) {
            return ['reason' => 'tool_or_config_changed'] + $out;
        }
        try {
            $h = ($this->head)($projectId);
        } catch (RepoException $e) {
            $this->mark($projectId, 'error', null);
            $this->logger->info('원격 리비전 조회 실패(가져오기 경로로)', ['project_id' => $projectId, 'code' => $e->errorCode]);
            return ['reason' => 'check_error', 'code' => $e->errorCode] + $out;
        }
        $out['remote_revision'] = $h['revision'];
        $out['remote_ref'] = $h['ref'];
        $same = $h['revision'] === (string) $base['source_revision'];
        $this->mark($projectId, $same ? 'unchanged' : 'changed', $h['revision']);
        return $same ? ['decision' => 'unchanged', 'reason' => 'same'] + $out : ['reason' => 'revision_differs'] + $out;
    }

    private function mark(int $projectId, string $result, ?string $revision): void
    {
        try {
            $this->db->execute('UPDATE project_repo_connections SET last_check_at = ?, last_check_result = ?, last_check_revision = COALESCE(?, last_check_revision) WHERE project_id = ?',
                [SystemClock::iso($this->clock->now()), $result, $revision === null ? null : substr($revision, 0, 64), $projectId]);
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'no such column: last_check')) {
                throw $e;
            }
        }
    }

    /**
     * "변경 없음" 기록(호출한 쪽의 쓰기 트랜잭션 안에서). 분석·결함·Run·스냅샷·알림은 없다.
     *  - $analysisId가 있으면(지금 가져와 분석: 워커가 집은 running 행) 그 행을 succeeded + no_change로 마감한다.
     *  - 없으면(주기 분석) 프로젝트의 **가장 최근 분석 행**이 같은 리비전·같은 기준·같은 연결·조건의 "변경 없음"이면 확인 횟수 +1·마지막 확인 시각만
     *    갱신하고(이력이 주기마다 늘지 않게), 아니면 새 행(succeeded + no_change, 실행 주체 schedule, 큐·워커를 거치지 않음)을 만든다.
     *
     * @param array<string, mixed> $conn project_repo_connections 행
     * @param array<string, mixed> $eval evaluate() 결과(decision unchanged)
     * @return array{analysis_id: int, folded: bool}|null 마감할 행이 이미 running이 아니면 null
     */
    public static function record(Db $db, int $projectId, array $conn, array $eval, string $now, ?int $analysisId = null): ?array
    {
        $rev = (string) $eval['remote_revision'];
        $ref = mb_substr((string) ($eval['remote_ref'] ?? ''), 0, 500);
        if ($analysisId !== null) {
            $n = $db->execute(
                "UPDATE analyses SET status = 'succeeded', no_change = 1, finished_at = ?, source_ref = ?, source_revision = ?,"
                . ' unchanged_check_count = 1, unchanged_last_checked_at = ?, unchanged_base_analysis_id = ?, source_key = ?, env_key = ?,'
                . " retry_after = NULL WHERE id = ? AND status = 'running'",
                [$now, $ref, $rev, $now, $eval['base_analysis_id'], $eval['source_key'], $eval['env_key'], $analysisId],
            );
            return $n === 1 ? ['analysis_id' => $analysisId, 'folded' => false] : null;
        }
        $last = $db->one('SELECT id, no_change, source_revision, unchanged_base_analysis_id, source_key, env_key FROM analyses WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
        if ($last !== null && (int) $last['no_change'] === 1 && $last['source_revision'] === $rev && $last['source_key'] === $eval['source_key']
            && $last['env_key'] === $eval['env_key'] && $last['unchanged_base_analysis_id'] !== null && (int) $last['unchanged_base_analysis_id'] === $eval['base_analysis_id']) {
            $db->execute('UPDATE analyses SET unchanged_check_count = unchanged_check_count + 1, unchanged_last_checked_at = ? WHERE id = ?', [$now, (int) $last['id']]);
            return ['analysis_id' => (int) $last['id'], 'folded' => true];
        }
        $language = (string) $db->value('SELECT language FROM projects WHERE id = ?', [$projectId]);
        $git = $conn['kind'] === 'git';
        $db->execute(
            'INSERT INTO analyses (project_id, status, no_change, source_kind, source_ref, source_revision, source_url, trigger_kind, requested_by, tool,'
            . ' queued_at, finished_at, unchanged_check_count, unchanged_last_checked_at, unchanged_base_analysis_id, source_key, env_key)'
            . " VALUES (?, 'succeeded', 1, ?, ?, ?, ?, 'schedule', NULL, ?, ?, ?, 1, ?, ?, ?, ?)",
            [$projectId, $conn['kind'], $ref, $rev, $git ? RepoInput::redactUrl((string) $conn['location']) : null,
                \PJGuardian\Services\Upload\UploadProcessor::toolFor($language), $now, $now, $now, $eval['base_analysis_id'], $eval['source_key'], $eval['env_key']],
        );
        return ['analysis_id' => $db->lastInsertId(), 'folded' => false];
    }
}
