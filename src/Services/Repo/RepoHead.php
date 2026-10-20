<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

/**
 * 원격 최신 리비전 조회(P27) — 소스를 내려받지 않는다. 연결 테스트(RepoService::test)와 **같은 호출**을 쓴다:
 * 허용 호스트 판정(이름 풀이 결과 검사) → 판정한 주소로 고정 → 체크섬을 통과한 bin/ 도구(RepoTools) → 격리 환경(VcsSandbox) → 타임아웃.
 *   Git      = ls-remote(지정 브랜치 헤드, 브랜치가 없으면 원격 기본 브랜치 HEAD)
 *   Perforce = changes -m 1 <depot 경로>#head(경로 기준 최신 submitted changelist)
 * 실패는 RepoException(정제된 코드: repo_unreachable·repo_auth_failed·repo_timeout·repo_host_not_allowed 등). 호출자가 분류한다.
 */
final class RepoHead
{
    public function __construct(
        private readonly HostPolicy $hosts,
        private readonly RepoTools $tools,
        private readonly string $workRoot,
    ) {
    }

    /**
     * @return array{ref: ?string, revision: string} ref = 실제 브랜치(Git) | depot 경로(Perforce)
     */
    public function lookup(GitTarget|P4Target $target, float $timeout): array
    {
        $sb = new VcsSandbox(rtrim($this->workRoot, '/') . '/repo_test/' . bin2hex(random_bytes(6)));
        try {
            $ip = $target instanceof GitTarget
                ? $this->hosts->check($target->host, $target->port, $target->scheme)
                : $this->hosts->check($target->host, $target->portNumber, 'p4');
            $target = $target->withPinnedIp($ip);
            $secrets = array_values(array_filter([$target instanceof GitTarget ? $target->token : $target->secret]));
            if ($target instanceof GitTarget) {
                $r = $this->tools->git($secrets)->test($target, $sb, $timeout);
                return ['ref' => $r['branch'], 'revision' => $r['commit']];
            }
            $r = $this->tools->p4($secrets)->test($target, $sb, $timeout);
            return ['ref' => $target->depotPath, 'revision' => (string) $r['change']];
        } finally {
            $sb->remove();
        }
    }
}
