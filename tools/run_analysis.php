<?php

declare(strict_types=1);

/**
 * 분석 하나를 실행하는 워커의 자식 프로세스(명세 5.2, 19.7, P14). 직접 부르지 않는다: tools/worker.php가 띄우고 감독한다
 * (타임아웃·취소 때 이 프로세스 트리를 통째로 종료).
 *
 * 하는 일: 분석 행(running) 확인 → (저장소 출처) P27 변경 없음 판단("지금 가져와 분석"만, 강제 제외) → 가져오기 → AnalysisRunner::run(P15 ruff/ESLint) → 성공이면 AnalysisFinalizer(P16: 결함 저장·판정·스냅샷,
 * 하나의 트랜잭션), 실패면 AnalysisQueue::complete(결함은 건드리지 않음). 반영 중 오류는 failed(result_save_failed).
 * 로그 컨텍스트의 job_id = 분석 id(웹 요청 ID처럼 웹·워커 로그를 잇는다).
 *
 * 사용법: php tools/run_analysis.php [--config=<config.ini>] <analysis_id>
 * 종료 코드: 0 결과 기록(성공·실패 모두), 1 실행기 예외(부모가 analysis_crashed로 기록), 2 사용법·설정 오류, 3 실행 중인 분석이 아님
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Analysis\AnalysisJob;
use PJGuardian\Support\Config;

$opt = getopt('', ['config:'], $rest);
$id = $argv[$rest] ?? '';
if (preg_match('/^[1-9][0-9]{0,17}$/', (string) $id) !== 1) {
    fwrite(STDERR, "사용법: php tools/run_analysis.php [--config=<config.ini>] <analysis_id>\n");
    exit(2);
}
$id = (int) $id;

try {
    // 저장소 자격 증명 복호화(P25): --config를 줘도 같은 폴더의 secrets.ini와 환경 변수(PJG_*) 시크릿을 읽는다
    $config = isset($opt['config']) ? Config::load((string) $opt['config'], dirname((string) $opt['config']) . '/secrets.ini', getenv()) : null;
    $app = App::create(PJG_ROOT, $config);
} catch (\Throwable $e) {
    fwrite(STDERR, '설정 오류: ' . $e->getMessage() . "\n");
    exit(2);
}
$app->logger->withJobId($id);
$row = $app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]);
if ($row === null || $row['status'] !== 'running') {
    $app->logger->warn('실행 중인 분석이 아님(자식 종료)', ['status' => $row['status'] ?? null]);
    exit(3);
}
$app->logger->withUserId($row['requested_by'] === null ? null : (int) $row['requested_by']);
// P25: 저장소 출처(git·perforce) 분석은 먼저 소스를 가져와 업로드와 같은 안전 해제를 거친다(RepoImport). 실패는 정제된 코드로 failed
if (in_array($row['source_kind'], ['git', 'perforce'], true) && $row['work_dir'] === null) {
    // P27: "지금 가져와 분석"(웹 요청은 원격 호출 없이 행만 만들었다)은 여기서 가져오기 전에 변경 없음을 판단한다. 강제 분석(admin 확인)·
    // 주기 분석(스케줄러가 등록 전에 이미 판단)·재시도 중인 행은 판단하지 않는다. 변경 없음이면 이 행을 succeeded + no_change로 마감(가져오기·도구·결함·Run·알림 없음)
    if ($row['trigger_kind'] === 'user' && (int) ($row['force_analysis'] ?? 0) === 0 && (int) ($row['retry_count'] ?? 0) === 0) {
        $eval = $app->unchangedCheck()->evaluate((int) $row['project_id']);
        $app->logger->info('변경 없음 판단', ['decision' => $eval['decision'], 'reason' => $eval['reason'], 'code' => $eval['code'], 'base_analysis_id' => $eval['base_analysis_id']]);
        $conn = $eval['decision'] === 'unchanged' ? $app->repoConnections()->row((int) $row['project_id']) : null;
        if ($conn !== null) {
            $now = PJGuardian\Support\SystemClock::iso($app->clock->now());
            $done = $app->db()->transaction(static fn (PJGuardian\Support\Db $db): ?array => PJGuardian\Services\Repo\UnchangedSourceCheck::record($db, (int) $row['project_id'], $conn, $eval, $now, $id), write: true);
            // P27C: 변경 없음은 주기 분석의 연속 실패에 중립(실제 분석이 성공해야 끊긴다) — 결과 반영을 부르지 않는다
            $app->logger->info('분석 생략(변경 없음)', ['recorded' => $done !== null, 'revision' => $eval['remote_revision']]);
            exit(0);
        }
    }
    try {
        $app->repoImport()->run($id, $row, static fn (): bool => $app->analysisQueue()->cancelRequested($id));
        $row = $app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]);
        // P27: 실제 분석에 연결·조건 키를 남긴다(다음 판단의 기준). 실패해도 분석은 계속(키가 없으면 다음에 생략하지 않을 뿐)
        try {
            $conn = $app->repoConnections()->row((int) $row['project_id']);
            if ($conn !== null) {
                $app->db()->execute('UPDATE analyses SET source_key = ?, env_key = ? WHERE id = ?',
                    [PJGuardian\Services\Repo\UnchangedSourceCheck::sourceKey($conn), $app->analysisEnvKey()->compute((int) $row['project_id']), $id]);
            }
        } catch (\Throwable $ke) {
            $app->logger->warn('분석 조건 키 기록 실패(다음 변경 없음 판단에서 분석으로)', ['error' => $ke->getMessage()]);
        }
    } catch (PJGuardian\Services\Repo\RepoException $e) {
        if ($e->errorCode === 'repo_cancelled') {
            $app->logger->info('가져오는 중 취소 요청(결과를 기록하지 않음 — 워커가 취소로 기록)');
            exit(0);
        }
        // P26: 주기 분석의 일시적 가져오기 실패는 같은 분석을 재시도 대기(queued, retry_after)로 되돌리고 결과를 기록하지 않는다
        try {
            if ($app->scheduleRetry()->requeue($id, $row, $e->errorCode)) {
                $app->logger->info('가져오기 실패 → 재시도 대기(결과를 기록하지 않음)', ['failure_code' => $e->errorCode, 'stage' => 'repo_fetch']);
                exit(0);
            }
        } catch (\Throwable $re) {
            $app->logger->warn('재시도 대기 전환 실패(이번 실패로 마감)', ['error' => $re->getMessage()]);
        }
        $failed = PJGuardian\Services\Analysis\AnalysisOutcome::failed($e->errorCode, 'repo');
        if ($app->analysisQueue()->complete($id, $failed)) {
            $app->events()->dispatch(new PJGuardian\Services\Events\AnalysisFailed($id, (int) $row['project_id'], $e->errorCode));
        }
        $app->logger->info('분석 결과 기록', ['recorded' => true, 'succeeded' => false, 'failure_code' => $e->errorCode, 'stage' => 'repo_fetch']);
        exit(0);
    } catch (\Throwable $e) {
        // 예상하지 못한 오류(DB 잠금 등)도 분석 실패로 남긴다(비정상 종료 analysis_crashed 대신 가져오기 실패로 — 원인은 로그에만)
        $app->logger->error('가져오기 중 예기치 않은 오류', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
        $failed = PJGuardian\Services\Analysis\AnalysisOutcome::failed('repo_fetch_failed', 'repo');
        if ($app->analysisQueue()->complete($id, $failed)) {
            $app->events()->dispatch(new PJGuardian\Services\Events\AnalysisFailed($id, (int) $row['project_id'], 'repo_fetch_failed'));
        }
        exit(0);
    }
}
$work = is_string($row['work_dir']) && preg_match('/^[0-9a-f]{8}$/', $row['work_dir']) === 1
    ? rtrim(str_replace('\\', '/', $app->paths->work()), '/') . '/' . $row['work_dir']
    : null;
try {
    $outcome = $app->analysisRunner()->run(new AnalysisJob($id, (int) $row['project_id'], (string) $row['tool'], $work, $row));
} catch (\Throwable $e) {
    $app->logger->error('분석 실행기 예외', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    exit(1);
}
if ($outcome->succeeded && isset($outcome->tool['result_file']) && $work !== null) {
    try {
        $stats = $app->analysisFinalizer()->finalize($id, $row, $work, $outcome);
        $recorded = $stats !== null;
    } catch (PJGuardian\Services\Defects\SpillStorageException $e) {
        // P19C: 결함이 많은 파일의 임시 저장소를 만들 여유 공간이 없음(명세 19.3) — 반영 전이라 결함 그대로
        $app->logger->warn('임시 저장소 여유 공간 부족으로 반영하지 않음', ['free' => $e->free, 'need' => $e->need]);
        $outcome = PJGuardian\Services\Analysis\AnalysisOutcome::failed('insufficient_storage', $e->getMessage(), $outcome->tool);
        $recorded = $app->analysisQueue()->complete($id, $outcome);
    } catch (PJGuardian\Services\Defects\DefectLimitExceededException $e) {
        // P19C: 반영하면 결함 수 상한을 넘음 — 쓰기 전에 멈췄다(결함·이전 결과 그대로)
        $app->logger->warn('결함 수 상한 초과로 반영하지 않음', ['count' => $e->count, 'max' => $e->max]);
        $outcome = PJGuardian\Services\Analysis\AnalysisOutcome::failed('defect_limit_exceeded', $e->getMessage(), $outcome->tool);
        $recorded = $app->analysisQueue()->complete($id, $outcome);
    } catch (\Throwable $e) {
        // 반영 트랜잭션은 되돌아갔다(결함 변경 없음). 결과 파일은 남겨 둔다(정리 작업이 1일 뒤 지움)
        $app->logger->error('결과 반영 실패', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
        $outcome = PJGuardian\Services\Analysis\AnalysisOutcome::failed('result_save_failed', $e->getMessage(), $outcome->tool);
        $recorded = $app->analysisQueue()->complete($id, $outcome);
    }
} else {
    $recorded = $app->analysisQueue()->complete($id, $outcome);
}
if (!$outcome->succeeded && $recorded) {
    $app->events()->dispatch(new PJGuardian\Services\Events\AnalysisFailed($id, (int) $row['project_id'], (string) $outcome->failureCode));
}
$app->logger->info('분석 결과 기록', [
    'recorded' => $recorded, 'succeeded' => $outcome->succeeded, 'failure_code' => $outcome->failureCode, 'detail' => $outcome->detail,
]);
exit(0);
