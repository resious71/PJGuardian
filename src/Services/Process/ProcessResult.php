<?php

declare(strict_types=1);

namespace PJGuardian\Services\Process;

/**
 * 프로세스 실행 결과.
 *
 * reason: normal(스스로 끝남, exitCode 유효) / timeout / output_limit / cancelled(취소 콜백·terminate) / start_failed
 * stdoutPath·stderrPath: 출력 임시 파일. 호출자가 읽은 뒤 cleanup()으로 지운다(P15가 도구 JSON을 여기서 읽는다).
 * outputBytes: 끝난 뒤의 stdout + stderr 크기. detectedBytes: 출력 한도 초과를 감지한 순간의 크기.
 * killMs: 트리 종료 명령에 걸린 시간(밀리초, 종료한 경우만). Windows는 taskkill 실행 시간이 대부분이다.
 */
final class ProcessResult
{
    public const NORMAL = 'normal';
    public const TIMEOUT = 'timeout';
    public const OUTPUT_LIMIT = 'output_limit';
    public const CANCELLED = 'cancelled';
    public const START_FAILED = 'start_failed';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $exitCode,
        public readonly int $durationMs,
        public readonly ?int $pid,
        public readonly string $stdoutPath,
        public readonly string $stderrPath,
        public readonly int $outputBytes,
        public readonly int $outputLimit,
        public readonly ?int $detectedBytes = null,
        public readonly ?string $killMethod = null,
        public readonly ?int $killMs = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->reason === self::NORMAL && $this->exitCode === 0;
    }

    /** 출력 한도를 넘겨 쓴 양(바이트). 한도 초과가 아니면 0 */
    public function overshoot(): int
    {
        return $this->reason === self::OUTPUT_LIMIT ? max(0, $this->outputBytes - $this->outputLimit) : 0;
    }

    /** stdout 앞부분을 최대 $max 바이트까지 읽는다(한도를 넘긴 출력도 메모리에 다 올리지 않는다) */
    public function stdout(int $max = 64 * 1024 * 1024): string
    {
        return is_file($this->stdoutPath) ? (string) file_get_contents($this->stdoutPath, false, null, 0, max(0, $max)) : '';
    }

    public function stderr(int $max = 1024 * 1024): string
    {
        return is_file($this->stderrPath) ? (string) file_get_contents($this->stderrPath, false, null, 0, max(0, $max)) : '';
    }

    public function cleanup(): void
    {
        foreach ([$this->stdoutPath, $this->stderrPath] as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** @return array<string, mixed> 로그용 요약 */
    public function summary(): array
    {
        return [
            'reason' => $this->reason, 'exit_code' => $this->exitCode, 'duration_ms' => $this->durationMs, 'pid' => $this->pid,
            'output_bytes' => $this->outputBytes, 'overshoot' => $this->overshoot(), 'kill' => $this->killMethod, 'kill_ms' => $this->killMs,
        ];
    }
}
