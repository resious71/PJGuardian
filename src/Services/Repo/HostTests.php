<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 허용 호스트 연결 테스트 실행·기록(P26B, admin 전용 — 라우트). 네트워크는 HostProbe만.
 *
 * - 한 호스트: 허용 목록에 **등록된 행만**(없는 id 404). 등록되지 않은 호스트·임의 주소를 테스트하는 길은 없다.
 * - 전체: 등록된 행을 호스트·포트 순으로 최대 repository.host_test_max(20)개 차례로. 나머지 수는 truncated로 알려 준다.
 * - 분당 제한: 저장소 연결 테스트와 같은 한도(repository.test_per_minute)를 쓰고, 전체 테스트는 **1회**로 센다(초과 429).
 * - 결과를 행에 저장(마지막 시각·코드·소요·인증서 만료일·발급자) — 프로젝트 연결 상태와 별개. 감사 repo.host_test(호스트·코드).
 */
final class HostTests
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly AllowedHosts $hosts,
        private readonly HostProbe $probe,
        private readonly RepoService $repo,
        private readonly int $max,
    ) {
    }

    /** @return array<string, mixed> 단계별 결과 + 저장 뒤 행 */
    public function testOne(Actor $actor, int $userId, int $id): array
    {
        $row = $this->hosts->get($id); // 없으면 404
        $this->repo->consumeTestQuota($userId);
        return $this->run($actor, $row);
    }

    /** @return array{results: list<array<string, mixed>>, total: int, tested: int, truncated: int, max: int} */
    public function testAll(Actor $actor, int $userId): array
    {
        $rows = $this->hosts->list();
        if ($rows === []) {
            throw new ApiException(409, 'repo_host_none');
        }
        $this->repo->consumeTestQuota($userId); // 전체 테스트는 1회로 센다
        $results = [];
        foreach (array_slice($rows, 0, $this->max) as $row) {
            $results[] = $this->run($actor, $row);
        }
        return ['results' => $results, 'total' => count($rows), 'tested' => count($results), 'truncated' => max(0, count($rows) - $this->max), 'max' => $this->max];
    }

    /**
     * @param array<string, mixed> $row AllowedHosts 행
     * @return array<string, mixed>
     */
    private function run(Actor $actor, array $row): array
    {
        $r = $this->probe->probe($row);
        $cert = $r['cert'];
        $this->db->execute('UPDATE repo_allowed_hosts SET last_test_at = ?, last_test_code = ?, last_test_ms = ?, cert_not_after = ?, cert_issuer = ? WHERE id = ?', [
            SystemClock::iso($this->clock->now()), $r['code'], $r['ms'], $cert['not_after'] ?? null, isset($cert['issuer']) ? mb_substr((string) $cert['issuer'], 0, 200) : null,
            (int) $row['id'],
        ]);
        $this->audit->record($actor, 'repo.host_test', 'repo_host', $row['host'] . ($row['port'] > 0 ? ':' . $row['port'] : ''), ['code' => $r['code'], 'ms' => $r['ms']]);
        return ['host' => $this->hosts->get((int) $row['id'])] + $r;
    }
}
