<?php

declare(strict_types=1);

namespace PJGuardian\Services\Process;

/**
 * 실행 중인 프로세스(ProcessRunner::start). 워커처럼 여러 프로세스를 한 루프에서 감독할 때 poll()을 반복해 부른다.
 * poll()은 기다리지 않는다: 끝났으면 결과, 아니면 null. 타임아웃·출력 한도·취소 콜백을 확인해 넘으면 트리를 종료한다.
 */
final class RunningProcess
{
    private int $start;
    private int $lastCancelCheck;
    private ?ProcessResult $result = null;

    /**
     * @param resource $proc
     * @param (\Closure(): bool)|null $shouldCancel
     */
    public function __construct(
        private $proc,
        public readonly int $pid,
        public readonly string $stdoutPath,
        public readonly string $stderrPath,
        private readonly ProcessOptions $options,
        private readonly ?\Closure $shouldCancel = null,
    ) {
        $this->start = hrtime(true);
        $this->lastCancelCheck = $this->start;
    }

    public function elapsedSeconds(): float
    {
        return (hrtime(true) - $this->start) / 1e9;
    }

    /**
     * 지금까지 쓴 출력 크기(폴링). 감지 지연은 폴링 간격이 아니라 Windows의 타이머 해상도(usleep이 약 15.6ms 단위)와
     * taskkill 시간(약 50~80ms)이 대부분이다(P14 측정, docs/results/P14.md). fstat으로 바꿔도 차이가 없었다.
     */
    public function outputBytes(): int
    {
        clearstatcache(false, $this->stdoutPath);
        clearstatcache(false, $this->stderrPath);
        return (int) @filesize($this->stdoutPath) + (int) @filesize($this->stderrPath);
    }

    /** 끝났으면 결과(한 번 만들면 같은 결과), 아니면 null */
    public function poll(): ?ProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $st = proc_get_status($this->proc);
        if (!$st['running']) {
            // exitcode는 running=false를 처음 본 호출에서만 유효하다. 첫 폴링 전에 끝났어도 출력 한도는 지킨다
            $bytes = $this->outputBytes();
            if ($bytes > $this->options->outputLimit) {
                return $this->finish(ProcessResult::OUTPUT_LIMIT, null, null, $bytes);
            }
            return $this->finish(ProcessResult::NORMAL, (int) $st['exitcode'], null, null);
        }
        $bytes = $this->outputBytes();
        if ($bytes > $this->options->outputLimit) {
            return $this->terminate(ProcessResult::OUTPUT_LIMIT, $bytes);
        }
        if ($this->options->timeoutSeconds > 0 && $this->elapsedSeconds() > $this->options->timeoutSeconds) {
            return $this->terminate(ProcessResult::TIMEOUT);
        }
        if ($this->shouldCancel !== null && (hrtime(true) - $this->lastCancelCheck) / 1e9 >= $this->options->cancelCheckSeconds) {
            $this->lastCancelCheck = hrtime(true);
            if (($this->shouldCancel)()) {
                return $this->terminate(ProcessResult::CANCELLED);
            }
        }
        return null;
    }

    /** 끝날 때까지 poll()을 반복한다 */
    public function wait(): ProcessResult
    {
        while (($r = $this->poll()) === null) {
            usleep($this->options->pollMicros);
        }
        return $r;
    }

    /** 프로세스 트리를 강제 종료하고 결과를 돌려준다(이미 끝났으면 그 결과) */
    public function terminate(string $reason = ProcessResult::CANCELLED, ?int $detectedBytes = null): ProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        $t = hrtime(true);
        $method = ProcessTree::kill($this->pid);
        return $this->finish($reason, null, $method, $detectedBytes, (int) ((hrtime(true) - $t) / 1e6));
    }

    private function finish(string $reason, ?int $exitCode, ?string $killMethod, ?int $detectedBytes, ?int $killMs = null): ProcessResult
    {
        proc_close($this->proc);
        $this->result = new ProcessResult(
            $reason,
            $reason === ProcessResult::NORMAL ? $exitCode : null,
            (int) ((hrtime(true) - $this->start) / 1e6),
            $this->pid,
            $this->stdoutPath,
            $this->stderrPath,
            $this->outputBytes(),
            $this->options->outputLimit,
            $detectedBytes,
            $killMethod,
            $killMs,
        );
        return $this->result;
    }
}
