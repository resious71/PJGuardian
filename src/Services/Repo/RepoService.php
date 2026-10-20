<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Services\Upload\UploadProcessor;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 웹 요청 쪽 저장소 동작(P25, admin 전용 — 라우트 미들웨어).
 *
 * - 연결 테스트: **웹 요청 안에서** 짧게 실행한다(repository.test_timeout 기본 30초, 사용자당 분당 repository.test_per_minute회).
 *   읽기만(Git ls-remote, Perforce changes) — 저장소에 쓰지 않는다. 같은 안전 규칙(허용 호스트·주소 고정·체크섬 검증 도구·격리 환경).
 *   결과는 정제된 코드(+ Perforce SSL이면 서버가 보낸 지문). 감사 repo.test.
 * - 지금 가져와 분석: 분석 행(queued, 출처 git|perforce, 실행 주체 user, 요청자 = 누른 admin)만 만든다. **git·p4는 워커가 실행**한다.
 *   그 프로젝트에 queued·running 분석이 있으면 409 analysis_in_progress, 디스크 사전 검사(해제 상한 × 2, 사용률 90% 차단)는 507.
 */
final class RepoService
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly AuditLog $audit,
        private readonly RepoConnections $connections,
        private readonly HostPolicy $hosts,
        private readonly RepoTools $tools,
        private readonly DiskGuard $disk,
        private readonly string $workRoot,
        private readonly int $testTimeout,
        private readonly int $testPerMinute,
        private readonly int $extractMax,
    ) {
    }

    /**
     * @param array<string, mixed>|null $draft 저장 전 입력(없으면 저장된 연결)
     * @return array<string, mixed> {ok, code, branch?, commit?, change?, fingerprint?, seconds}
     */
    public function test(Actor $actor, int $userId, int $projectId, ?array $draft): array
    {
        $this->consumeTestQuota($userId);
        $target = $this->connections->target($projectId, $draft); // 형식 오류는 400, 연결 없음은 repo_not_configured
        $t0 = microtime(true);
        try {
            // P27: 원격 최신 리비전 조회(RepoHead)와 같은 호출 — 허용 호스트·주소 고정·검증된 도구·격리 환경
            $r = (new RepoHead($this->hosts, $this->tools, $this->workRoot))->lookup($target, (float) $this->testTimeout);
            $out = $target instanceof GitTarget
                ? ['ok' => true, 'code' => null, 'branch' => $r['ref'], 'commit' => $r['revision']]
                : ['ok' => true, 'code' => null, 'change' => (int) $r['revision']];
        } catch (RepoException $e) {
            $out = ['ok' => false, 'code' => $e->errorCode === 'repo_cancelled' ? 'repo_timeout' : $e->errorCode]
                + array_intersect_key($e->params, ['fingerprint' => 1, 'what' => 1, 'host' => 1, 'reason' => 1]);
        }
        $out['seconds'] = round(microtime(true) - $t0, 2);
        if ($draft === null || $this->sameAsSaved($projectId, $draft)) {
            $this->connections->mark($projectId, 'test', $out['ok'], $out['code']);
        }
        $this->audit->record($actor, 'repo.test', 'project', (string) $projectId, ['ok' => $out['ok'], 'code' => $out['code'], 'draft' => $draft !== null]);
        return $out;
    }

    /** @return array{analysis_id:int} */
    public function fetchNow(Actor $actor, int $userId, int $projectId, bool $force = false): array
    {
        $conn = $this->connections->row($projectId);
        if ($conn === null) {
            throw new ApiException(409, 'repo_not_configured');
        }
        if ($conn['secret_cipher'] !== null) {
            try {
                $this->connections->target($projectId); // 키를 잃었거나 바뀌어 풀 수 없으면 큐에 넣지 않는다
            } catch (RepoException) {
                throw new ApiException(409, 'repo_secret_unreadable');
            }
        }
        $this->disk->check($this->workRoot, 2 * $this->extractMax);
        $now = SystemClock::iso($this->clock->now());
        $id = $this->db->transaction(function (Db $db) use ($conn, $projectId, $userId, $now, $force): int {
            if (self::busy($db, $projectId)) {
                throw new ApiException(409, 'analysis_in_progress');
            }
            return self::enqueue($db, $conn, $projectId, $userId, $now, $force);
        }, write: true);
        // P27: 강제 분석(변경 없음 확인 뒤 "그래도 분석")은 감사에 force = true로 남긴다
        $this->audit->record($actor, 'repo.fetch_now', 'project', (string) $projectId, ['analysis_id' => $id, 'kind' => (string) $conn['kind']] + ($force ? ['force' => true] : []));
        $this->logger->info('저장소 가져와 분석 대기열 등록', ['project_id' => $projectId, 'analysis_id' => $id, 'kind' => (string) $conn['kind'], 'force' => $force]);
        return ['analysis_id' => $id];
    }

    /** 그 프로젝트에 queued·running 분석이 있는지(명세 18.2: 있으면 즉시 실행 409, 주기는 건너뜀) */
    public static function busy(Db $db, int $projectId): bool
    {
        return $db->value("SELECT 1 FROM analyses WHERE project_id = ? AND status IN ('queued', 'running') LIMIT 1", [$projectId]) !== null;
    }

    /**
     * 저장소 출처 분석 행 하나를 큐에 넣는다(queued). "지금 가져와 분석"(요청자 = 누른 admin, 실행 주체 user)과
     * 주기 분석(요청자 없음, 실행 주체 schedule — Schedule\ScheduledAnalysisLauncher)이 **같은 함수**를 쓴다. 호출한 쪽의 쓰기 트랜잭션 안에서.
     * 출처: Git = 브랜치·사용자 정보 없는 주소, Perforce = depot 경로. 도구는 프로젝트 언어로.
     * $force(P27, admin "그래도 분석"): 분석 자식이 변경 없음 판단을 건너뛴다(analyses.force_analysis = 1).
     *
     * @param array<string, mixed> $conn project_repo_connections 행
     */
    public static function enqueue(Db $db, array $conn, int $projectId, ?int $userId, string $now, bool $force = false): int
    {
        $language = (string) $db->value('SELECT language FROM projects WHERE id = ?', [$projectId]);
        $git = $conn['kind'] === 'git';
        $db->execute(
            'INSERT INTO analyses (project_id, status, source_kind, source_ref, source_url, trigger_kind, requested_by, tool, queued_at' . ($force ? ', force_analysis' : '') . ')'
            . " VALUES (?, 'queued', ?, ?, ?, ?, ?, ?, ?" . ($force ? ', 1' : '') . ')',
            [$projectId, $conn['kind'], $git ? $conn['branch'] : $conn['path'], $git ? RepoInput::redactUrl((string) $conn['location']) : null,
                $userId === null ? 'schedule' : 'user', $userId, UploadProcessor::toolFor($language), $now],
        );
        return $db->lastInsertId();
    }

    /** 사용자당 분당 횟수(잠금 전용 파일 + 시각 목록 파일). 저장소 연결 테스트와 호스트 연결 테스트(P26B)가 같은 한도를 쓴다 — 초과 429 */
    public function consumeTestQuota(int $userId): void
    {
        $dir = $this->workRoot . '/repo_test';
        Fs::ensureDir($dir);
        $lock = FileLock::acquire($dir . '/rate-' . $userId . '.lock');
        try {
            $file = $dir . '/rate-' . $userId . '.json';
            $now = $this->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $this->testPerMinute) {
                throw new ApiException(429, 'repo_test_rate_limited', ['n' => $this->testPerMinute]);
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
        } finally {
            $lock->release();
        }
    }

    private function sameAsSaved(int $projectId, array $draft): bool
    {
        $r = $this->connections->row($projectId);
        if ($r === null || ($draft['kind'] ?? null) !== $r['kind'] || (isset($draft['secret']) && $draft['secret'] !== '')) {
            return false;
        }
        $loc = $r['kind'] === 'git' ? ($draft['url'] ?? null) : ($draft['port'] ?? null);
        return trim((string) $loc) === (string) $r['location'] && trim((string) ($draft['path'] ?? '')) === (string) ($r['path'] ?? '')
            && trim((string) ($draft['branch'] ?? '')) === (string) ($r['branch'] ?? '');
    }
}
