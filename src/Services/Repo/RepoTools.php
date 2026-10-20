<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Logger;

/**
 * git·p4 클라이언트 만들기(P25). 실행 파일은 ToolRegistry::select(체크섬·스모크를 통과한 `bin/` 버전)만 — 검증 실패·없음이면
 * repo_tool_unavailable(그 도구를 쓰지 않는다). 시험은 $override로 명령을 바꾼다(가짜 p4 스크립트 — 제품 경로에서는 쓰지 않음).
 */
final class RepoTools
{
    /** @param array{git?: list<string>, p4?: list<string>} $override */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ProcessRunner $runner,
        private readonly Logger $logger,
        private readonly string $tmpDir,
        private readonly ?string $caFile,
        private readonly array $override = [],
    ) {
    }

    /** @param list<string> $secrets 로그에서 가릴 값 */
    public function git(array $secrets): GitClient
    {
        return new GitClient($this->command('git'), new VcsProcess($this->runner, $this->logger, $this->tmpDir, $secrets), $this->caFile);
    }

    /** @param list<string> $secrets */
    public function p4(array $secrets): PerforceClient
    {
        return new PerforceClient($this->command('p4'), new VcsProcess($this->runner, $this->logger, $this->tmpDir, $secrets));
    }

    /** @return list<string> */
    private function command(string $tool): array
    {
        if (isset($this->override[$tool])) {
            return $this->override[$tool];
        }
        $sel = $this->registry->select($tool);
        if ($sel === null) {
            throw new RepoException('repo_tool_unavailable', ['tool' => $tool]);
        }
        return [$sel->executable];
    }
}
