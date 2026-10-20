<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\RunningProcess;
use PJGuardian\Support\Logger;

/**
 * git·p4 한 명령 실행(P25): 기존 ProcessRunner(셸 없음·허용 경로·타임아웃·프로세스 트리 종료·출력 한도)를 그대로 쓴다.
 * - 취소 콜백(분석 취소 요청)이면 RepoException(repo_cancelled), 시간 초과면 repo_timeout.
 * - 감시 콜백(1초마다, 가져온 크기 등)이 true를 돌려주면 트리를 끝내고 repo_limit_exceeded.
 * - stderr는 마스킹한 뒤 서버 로그에만(화면·API에는 오류 코드만). 마스킹: 넘겨받은 비밀 값, Authorization 헤더, URL의 사용자 정보.
 */
final class VcsProcess
{
    /** stderr·작은 stdout 한도(대용량 출력은 파일로 받는 명령이 따로 정한다) */
    public const SMALL_OUTPUT = 4 * 1024 * 1024;

    /** @param list<string> $secrets 로그에서 가릴 값 */
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Logger $logger,
        private readonly string $tmpDir,
        private readonly array $secrets = [],
    ) {
    }

    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     * @param (\Closure(): bool)|null $cancel
     * @param (\Closure(): bool)|null $overLimit
     */
    public function run(array $cmd, array $env, string $cwd, float $timeout, ?\Closure $cancel = null, ?\Closure $overLimit = null,
        int $outputLimit = self::SMALL_OUTPUT, ?string $stdin = null): ProcessResult
    {
        $p = $this->runner->start($cmd, new ProcessOptions(cwd: $cwd, env: $env, timeoutSeconds: $timeout, outputLimit: $outputLimit,
            outputDir: $this->tmpDir, cancelCheckSeconds: 1.0, stdin: $stdin), $cancel);
        if ($p instanceof RunningProcess) {
            $next = microtime(true) + 1.0;
            while (($r = $p->poll()) === null) {
                if ($overLimit !== null && microtime(true) >= $next) {
                    $next = microtime(true) + 1.0;
                    if ($overLimit()) {
                        $r = $p->terminate(ProcessResult::CANCELLED);
                        $r->cleanup();
                        throw new RepoException('repo_limit_exceeded');
                    }
                }
                usleep(20_000);
            }
        } else {
            $r = $p;
        }
        if ($r->reason === ProcessResult::TIMEOUT) {
            $r->cleanup();
            throw new RepoException('repo_timeout');
        }
        if ($r->reason === ProcessResult::CANCELLED) {
            $r->cleanup();
            throw new RepoException('repo_cancelled');
        }
        if ($r->reason === ProcessResult::OUTPUT_LIMIT) {
            $r->cleanup();
            throw new RepoException('repo_limit_exceeded');
        }
        if ($r->reason === ProcessResult::START_FAILED) {
            $r->cleanup();
            throw new RepoException('repo_tool_unavailable');
        }
        return $r;
    }

    public function mask(string $text): string
    {
        foreach ($this->secrets as $s) {
            if ($s !== '' && strlen($s) >= 4) {
                $text = str_replace([$s, base64_encode($s)], '***', $text);
            }
        }
        $text = (string) preg_replace('/(Authorization:\s*)[^\r\n]+/i', '$1***', $text);
        return (string) preg_replace('#(https?://)[^/\s@]+@#i', '$1***@', $text);
    }

    /** 실패 원문(마스킹 후 2,000자)을 서버 로그에만 남긴다 */
    public function logFailure(string $what, string $code, ProcessResult $r): void
    {
        $this->logger->warn('저장소 명령 실패', ['what' => $what, 'code' => $code, 'exit' => $r->exitCode,
            'stderr' => mb_substr($this->mask(trim($r->stderr(8192))), 0, 2000)]);
    }
}
