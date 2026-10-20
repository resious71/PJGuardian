<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Analysis\AnalysisJob;
use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Services\Analysis\AnalysisRunner;
use PJGuardian\Services\ProjectService;
use PJGuardian\Services\Rules\RuleOverrides;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * 실제 분석 실행기(P15): ruff 또는 ESLint로 작업 디렉토리를 분석해 정규화한 결함을 NDJSON 파일로 남긴다.
 * 결함의 DB 저장·시그니처·자동 resolved는 P16이 이 파일을 읽어 한다. 이 단계의 "성공"은 결과 파일 생성 성공이다.
 *
 * 순서: 디스크 사전 검사(출력 한도 + 초과 여유, 명세 19.3 → 부족하면 실패 insufficient_storage) → 도구 선택(레지스트리, 없으면
 * tool_unavailable) → 프로젝트 제외 판정기 → 도구 실행 → 결과 파일 <work_dir>/results/<분석 id>.ndjson.
 * 실패에서는 결과 파일을 만들지 않는다(쓰던 파일은 지운다).
 * P22B: 도구를 고른 직후 관리자 규칙 변경을 한 번 읽어(RuleOverrides::snapshotFor) 그 분석 동안 고정한다 — 큐에서 기다리는 동안의 변경은
 * 반영되고, 이미 시작한 분석은 바뀌지 않는다.
 */
final class ToolAnalysisRunner implements AnalysisRunner
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly RuffRunner $ruff,
        private readonly EslintRunner $eslint,
        private readonly ProjectService $projects,
        private readonly DiskGuard $disk,
        private readonly string $resultsDir,
        private readonly int $outputLimit,
        private readonly int $outputMargin,
        private readonly Logger $logger,
        private readonly ?RuleOverrides $overrides = null,
    ) {
    }

    public static function resultFile(string $resultsDir, int $analysisId): string
    {
        return rtrim($resultsDir, '/') . '/' . $analysisId . '.ndjson';
    }

    /** 도구가 분석한 파일 목록(분석 범위, P16) */
    public static function scopeFile(string $resultsDir, int $analysisId): string
    {
        return rtrim($resultsDir, '/') . '/' . $analysisId . '.scope';
    }

    public function run(AnalysisJob $job): AnalysisOutcome
    {
        if ($job->workPath === null || !is_dir($job->workPath)) {
            return AnalysisOutcome::failed('tool_crash', '작업 디렉토리 없음');
        }
        Fs::ensureDir($this->resultsDir);
        try {
            // 출력 한도 감지는 폴링이라 한도를 넘겨 쓸 수 있다(P14 실측 250~450MB) → 한도 + 여유
            $this->disk->checkFree($this->resultsDir, $this->outputLimit + $this->outputMargin);
        } catch (ApiException $e) {
            return AnalysisOutcome::failed('insufficient_storage', $e->errorCode . ' ' . json_encode($e->params));
        }
        $tool = $this->registry->select($job->tool);
        if ($tool === null) {
            return AnalysisOutcome::failed('tool_unavailable', $job->tool . ': 검증을 통과한 버전 없음');
        }
        $matcher = $this->projects->matcherFor($job->projectId);
        $file = self::resultFile($this->resultsDir, $job->analysisId);
        $scope = self::scopeFile($this->resultsDir, $job->analysisId);
        $set = $this->overrides?->snapshotFor($tool->tool, $tool->version);
        if ($set !== null) {
            $this->logger->info('규칙 변경 적용', ['tool' => $tool->tool, 'revision' => $set['revision'], 'enable' => count($set['enable']),
                'disable' => count($set['disable']), 'skipped' => count($set['skipped'])]);
        }
        $out = $job->tool === 'ruff'
            ? $this->ruff->run($tool, $job->workPath, $matcher, $file, null, $scope, $set)
            : $this->eslint->run($tool, $job->workPath, $matcher, $file, null, $scope, $set);
        if (!$out->ok) {
            @unlink($file);
            @unlink($scope);
            return AnalysisOutcome::failed((string) $out->failureCode, $out->detail, $out->toolInfo());
        }
        $stats = $out->stats ?? ['written' => 0, 'syntax' => 0, 'skipped' => [], 'by_rule' => []];
        $this->logger->info('분석 결과 파일 생성', [
            'file' => basename($file), 'defects' => $stats['written'], 'syntax_errors' => $stats['syntax'], 'skipped' => $stats['skipped'],
            'tool' => $out->toolLabel, 'config_source' => $out->configSource, 'ignored_configs' => count($out->ignored),
        ]);
        // 결함 통계는 P16이 결함을 저장하며 채운다. 지금은 감지 건수만 total_count에 둔다(신규·해결 판정 전)
        return AnalysisOutcome::succeeded(
            ['total_count' => $stats['written'], 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0],
            $out->toolInfo() + ['result_file' => $file, 'scope_file' => $scope, 'scope_count' => $out->scopeCount],
        );
    }
}
