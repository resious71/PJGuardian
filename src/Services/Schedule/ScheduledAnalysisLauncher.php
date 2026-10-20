<?php

declare(strict_types=1);

namespace PJGuardian\Services\Schedule;

use PJGuardian\Services\Repo\RepoService;
use PJGuardian\Services\Repo\UnchangedSourceCheck;
use PJGuardian\Support\Db;

/**
 * 주기 도래 → 분석 등록의 **유일한 호출 지점**(P26). ScheduleTicker가 도래 처리 트랜잭션 안에서 부른다.
 *
 * 하는 일: "지금 가져와 분석"과 같은 큐 등록 함수(RepoService::enqueue)로 분석 행 하나(출처 git|perforce, 실행 주체 schedule, 요청자 NULL)를 만든다.
 * 가져오기·분석은 워커의 분석 자식이 한다(P25와 같은 흐름).
 *
 * P27 "변경 없음": ScheduleTicker가 **트랜잭션 밖**에서 UnchangedSourceCheck::evaluate(원격 최신 리비전 조회 + 기준 분석과 비교)를 마치고
 * 결과를 $hint로 넘긴다(쓰기 잠금을 잡은 채 네트워크를 기다리지 않게). unchanged면 여기서 다시 확인한다 — 그사이 다른 성공 분석이 생겼으면
 * (기준이 바뀌었으면) 생략하지 않고 큐에 넣는다. 생략하면 큐·워커를 거치지 않고 "변경 없음" 행을 기록하거나(새 행) 직전 같은 행의 확인 횟수를 올린다.
 * 주기 분석에는 강제 분석이 없다.
 */
final class ScheduledAnalysisLauncher
{
    /**
     * @param array<string, mixed> $conn project_repo_connections 행(호출한 쪽이 연결·자격 증명을 확인했다)
     * @param array<string, mixed> $hint UnchangedSourceCheck::evaluate 결과(없으면 판단 없이 등록)
     * @return array{result: string, analysis_id: ?int, detail: ?string, reason: ?string} result = queued | unchanged, reason = 판단 사유(로그용)
     */
    public function launch(Db $db, int $projectId, array $conn, string $dueAt, string $now, array $hint = []): array
    {
        if (($hint['decision'] ?? null) === 'unchanged') {
            $base = $db->value("SELECT MAX(id) FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0", [$projectId]);
            if ($base !== null && (int) $base === $hint['base_analysis_id'] && UnchangedSourceCheck::sourceKey($conn) === $hint['source_key']) {
                $r = UnchangedSourceCheck::record($db, $projectId, $conn, $hint, $now);
                if ($r !== null) {
                    return ['result' => 'unchanged', 'analysis_id' => $r['analysis_id'], 'detail' => $r['folded'] ? 'folded' : null, 'reason' => 'same'];
                }
            }
        }
        $id = RepoService::enqueue($db, $conn, $projectId, null, $now);
        return ['result' => 'queued', 'analysis_id' => $id, 'detail' => null, 'reason' => isset($hint['reason']) ? (string) $hint['reason'] : null];
    }
}
