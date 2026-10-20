<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Support\Fs;

/**
 * 두 도구 실행기(RuffRunner, EslintRunner)의 공통 조건: 프로세스 실행기(bin/ + Node만 허용), 한도, 임시 폴더.
 * 실패 분류(명세 6.1, 15.2): 문법 오류는 결함이고, 도구 자체의 실행 실패만 분석 실패다.
 *   timeout(시간 초과) · output_limit(출력 한도) · memory_limit(메모리 부족: Linux prlimit, Node 힙) ·
 *   tool_config_error(설정 오류: ruff 종료 코드 2, ESLint 설정 검증 오류) · tool_crash(그 밖의 비정상 종료, 출력 형식 오류)
 */
final class ToolExecution
{
    /**
     * @param int $timeoutSeconds analysis.timeout
     * @param int $outputLimit analysis.output_limit
     * @param int $memoryLimit analysis.process_memory_limit (ruff는 Linux만 prlimit, ESLint는 --max-old-space-size)
     */
    public function __construct(
        public readonly ProcessRunner $runner,
        public readonly int $timeoutSeconds,
        public readonly int $outputLimit,
        public readonly int $memoryLimit,
        public readonly string $tempRoot,
    ) {
    }

    /** 실행마다 쓰는 빈 임시 폴더(HOME·TEMP 겸 출력 파일 위치). 끝나면 호출자가 지운다 */
    public function newTemp(): string
    {
        Fs::ensureDir($this->tempRoot);
        $dir = $this->tempRoot . '/' . bin2hex(random_bytes(6));
        Fs::ensureDir($dir);
        return $dir;
    }

    /** 프로세스 결과의 실패 코드(성공 범위 종료 코드면 null). $configError: 설정 오류로 볼 stderr인지 판단 */
    public static function classify(ProcessResult $r, array $okExitCodes, \Closure $configError): ?string
    {
        return match (true) {
            $r->reason === ProcessResult::TIMEOUT => 'timeout',
            $r->reason === ProcessResult::OUTPUT_LIMIT => 'output_limit',
            $r->reason === ProcessResult::START_FAILED => 'tool_crash',
            $r->reason === ProcessResult::CANCELLED => 'tool_crash',
            in_array($r->exitCode, $okExitCodes, true) => null,
            self::isMemoryError($r->stderr(64 * 1024)) => 'memory_limit',
            $configError($r) => 'tool_config_error',
            default => 'tool_crash',
        };
    }

    /** 메모리 부족 흔적: Rust(ruff) "memory allocation of N bytes failed", Node "JavaScript heap out of memory" */
    public static function isMemoryError(string $stderr): bool
    {
        return preg_match('/memory allocation of \d+ bytes failed|heap out of memory|out of memory|Cannot allocate memory/i', $stderr) === 1;
    }

    /** 로그용 stderr 앞부분(내부 경로가 있을 수 있어 사용자 화면에는 쓰지 않는다) */
    public static function stderrHead(ProcessResult $r): string
    {
        return mb_substr(trim($r->stderr(8192)), 0, 1000);
    }
}
