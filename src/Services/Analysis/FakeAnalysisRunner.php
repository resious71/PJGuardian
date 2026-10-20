<?php

declare(strict_types=1);

namespace PJGuardian\Services\Analysis;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;

/**
 * 가짜 분석기(P14 스텁). 실제 ruff/ESLint 실행(P15) 전까지 큐·워커 수명주기를 검증한다. 결함은 만들지 않는다.
 *
 * 동작은 환경 변수 PJG_FAKE_ANALYSIS(JSON)로 정한다(시험·시연 전용, 없으면 2초 뒤 성공):
 *   {"mode": "succeed", "seconds": 2}
 *   {"default": {...}, "analyses": {"12": {"mode": "hang"}}}   분석 id별로 다르게
 * mode
 *   succeed  seconds초 뒤 성공(통계 0)
 *   fail     seconds초 뒤 실패(tool_failed)
 *   crash    seconds초 뒤 결과를 남기지 않고 종료 코드 70으로 끝남(비정상 종료 모의)
 *   hang     손자 프로세스(php -r sleep)를 띄우고 seconds초(기본 600) 기다림. pid_file이 있으면 "자식PID 손자PID"를 씀
 *            (취소·타임아웃·복구 시험에서 남은 프로세스 0개 확인용)
 *   output   출력을 끝없이 쓰는 가짜 도구를 ProcessRunner로 실행 → 출력 한도(analysis.output_limit)에서 종료 → 실패(output_limit)
 */
final class FakeAnalysisRunner implements AnalysisRunner
{
    /** @param array<string, mixed> $spec */
    public function __construct(
        private readonly ProcessRunner $internal,
        private readonly int $outputLimit,
        private readonly string $tempDir,
        private readonly array $spec = [],
    ) {
    }

    /** @return array<string, mixed> */
    public static function specFromEnv(): array
    {
        $raw = getenv('PJG_FAKE_ANALYSIS');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $v = json_decode($raw, true);
        return is_array($v) ? $v : [];
    }

    /** @return array<string, mixed> */
    private function specFor(int $analysisId): array
    {
        $s = $this->spec['analyses'][(string) $analysisId] ?? $this->spec['default'] ?? $this->spec;
        return is_array($s) ? $s : [];
    }

    public function run(AnalysisJob $job): AnalysisOutcome
    {
        $s = $this->specFor($job->analysisId);
        $mode = (string) ($s['mode'] ?? 'succeed');
        $seconds = (float) ($s['seconds'] ?? ($mode === 'hang' ? 600 : 2));
        $zero = ['total_count' => 0, 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0];
        $tool = ['tool_version' => 'fake-0.0.0', 'config_source' => 'server_default'];
        switch ($mode) {
            case 'succeed':
                usleep((int) ($seconds * 1e6));
                return AnalysisOutcome::succeeded($zero, $tool);
            case 'fail':
                usleep((int) ($seconds * 1e6));
                return AnalysisOutcome::failed('tool_failed', '가짜 분석기 실패 모의', $tool);
            case 'crash':
                usleep((int) ($seconds * 1e6));
                exit(70);
            case 'hang':
                $p = $this->internal->start([PHP_BINARY, '-r', 'sleep(' . (int) ceil($seconds) . ');'], new ProcessOptions(outputDir: $this->tempDir));
                $grand = $p instanceof ProcessResult ? 0 : $p->pid;
                if (isset($s['pid_file']) && is_string($s['pid_file'])) {
                    file_put_contents($s['pid_file'] . '.tmp', getmypid() . ' ' . $grand);
                    rename($s['pid_file'] . '.tmp', $s['pid_file']);
                }
                usleep((int) ($seconds * 1e6));
                return AnalysisOutcome::succeeded($zero, $tool);
            case 'output':
                $r = $this->internal->run(
                    [PHP_BINARY, '-r', '$b = str_repeat("x", 1048576); while (true) { fwrite(STDOUT, $b); }'],
                    new ProcessOptions(timeoutSeconds: 600, outputLimit: $this->outputLimit, outputDir: $this->tempDir),
                );
                $r->cleanup();
                if ($r->reason === ProcessResult::OUTPUT_LIMIT) {
                    return AnalysisOutcome::failed('output_limit', sprintf('한도 %d bytes, 감지 %d bytes, 최종 %d bytes, 초과 %d bytes', $r->outputLimit, (int) $r->detectedBytes, $r->outputBytes, $r->overshoot()), $tool);
                }
                return AnalysisOutcome::failed('tool_failed', '출력 한도에 걸리지 않음: ' . $r->reason, $tool);
            default:
                return AnalysisOutcome::failed('tool_failed', '알 수 없는 가짜 모드: ' . $mode, $tool);
        }
    }
}
