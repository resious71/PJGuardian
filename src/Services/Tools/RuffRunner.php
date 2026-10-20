<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * ruff 실행(명세 5.3, 6.1, 19.10).
 *   ruff check --output-format json-lines --no-cache --no-respect-gitignore --exit-zero --config <설정> --extend-exclude <기본 제외 이름> .
 * - --exit-zero: 결함이 있어도 0. 0이 아니면 도구 실패(2 = 설정·옵션 오류 → tool_config_error, 그 밖 → tool_crash).
 * - --no-respect-gitignore: 업로드된 .gitignore는 따르지 않는다(명세 19.10). 분석 디렉토리가 git 무시 경로 아래여도 분석된다(P02).
 * - 제외: 서버 기본 목록(경로 조각 이름)은 --extend-exclude로 넘기고, 프로젝트 추가 패턴은 해제 단계에서 이미 빠졌으며
 *   결과를 쓸 때 ExcludeMatcher로 한 번 더 거른다(제외 판정 구현은 ExcludeMatcher 하나, docs/architecture.md 17.1).
 * - Linux는 prlimit --as(analysis.process_memory_limit), Windows는 메모리 제한 없음(시간·출력 한도로 대신).
 * - P22B 관리자 규칙 변경: 서버 기본 설정을 쓸 때만 적용한다(프로젝트가 올린 ruff 설정은 그대로 존중). 변경이 있으면 작업 폴더에
 *   실효 설정(EffectiveRuleConfig::ruffToml)을 만들어 --config로 넘기고, 설정 해시에 변경 집합을 더한다. 변경이 없으면 지금과 같은 명령·같은 해시.
 */
final class RuffRunner
{
    public function __construct(
        private readonly ToolExecution $exec,
        private readonly RuffConfigResolver $configs,
        private readonly Logger $logger,
    ) {
    }

    public const FLAGS = ['check', '--output-format', 'json-lines', '--no-cache', '--no-respect-gitignore', '--exit-zero'];

    /**
     * 설정 해시: 실제로 쓴 설정 파일 내용 + 서버 명령행 옵션(+ 관리자 규칙 변경이 있을 때만 그 집합).
     *
     * @param list<string> $files
     * @param array{enable: list<string>, disable: list<string>}|null $overrides EffectiveRuleConfig::canonical()
     */
    public static function configHash(array $files, string $exclude, ?array $overrides): string
    {
        $extra = [self::FLAGS, $exclude];
        if ($overrides !== null) {
            $extra[] = ['rule_overrides' => $overrides];
        }
        return RuffConfigResolver::hashFiles($files, json_encode($extra, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string|null $scopeFile 분석한 파일 목록(ruff check --show-files)을 쓸 곳(P16). null이면 쓰지 않음
     * @param array{enable?: list<string>, disable?: list<string>}|null $ruleOverrides 관리자 규칙 변경(P22B, 분석 시작 때 읽은 스냅샷). 스모크 테스트는 null
     */
    public function run(SelectedTool $tool, string $root, ExcludeMatcher $matcher, string $resultFile, ?ToolProjectScan $scan = null, ?string $scopeFile = null, ?array $ruleOverrides = null): ToolRunOutcome
    {
        $scan ??= ToolProjectScan::run($root, $matcher);
        $conf = $this->configs->resolve($root);
        $exclude = implode(',', $matcher->defaults());
        $flags = self::FLAGS;
        $overrides = $conf['source'] === 'server_default' ? EffectiveRuleConfig::canonical('ruff', $ruleOverrides) : null;
        if ($conf['source'] !== 'server_default' && EffectiveRuleConfig::canonical('ruff', $ruleOverrides) !== null) {
            $this->logger->info('ruff 규칙 변경 미적용(프로젝트가 올린 ruff 설정 사용)', ['config' => basename($conf['path'])]);
        }
        $hash = self::configHash($conf['files'], $exclude, $overrides);
        $ignored = $conf['ignored'];
        foreach ($scan->ruffNested as $f) {
            $ignored[] = ['file' => $f, 'reason' => 'ruff_nested_config'];
        }
        if ($scan->ruffNestedTotal > count($scan->ruffNested)) {
            $ignored[] = ['file' => '…', 'reason' => 'ruff_nested_config', 'count' => $scan->ruffNestedTotal - count($scan->ruffNested)];
        }
        $fail = fn (string $code, string $detail): ToolRunOutcome => new ToolRunOutcome(false, $code, $detail, $tool->label(), $conf['source'], $hash, $ignored);

        $tmp = $this->exec->newTemp();
        $configPath = $conf['path'];
        if ($overrides !== null) {
            $configPath = $tmp . '/' . EffectiveRuleConfig::RUFF_FILE;
            file_put_contents($configPath, EffectiveRuleConfig::ruffToml($conf['path'], $overrides));
        }
        $tail = ['--config', $configPath];
        if ($exclude !== '') {
            array_push($tail, '--extend-exclude', $exclude);
        }
        $tail[] = '.';
        $cmd = [$tool->executable, ...$flags, ...$tail];
        $options = new ProcessOptions(
            cwd: $root,
            env: ProcessRunner::minimalEnv($tmp),
            timeoutSeconds: (float) $this->exec->timeoutSeconds,
            outputLimit: $this->exec->outputLimit,
            memoryLimit: ProcessTree::isWindows() ? null : $this->exec->memoryLimit,
            outputDir: $tmp,
        );
        try {
            $r = $this->exec->runner->run($cmd, $options);
            try {
                $code = ToolExecution::classify($r, [0], static fn (ProcessResult $x): bool => $x->exitCode === 2);
                if ($code !== null) {
                    $this->logger->warn('ruff 실행 실패', ['code' => $code] + $r->summary() + ['stderr' => ToolExecution::stderrHead($r)]);
                    return $fail($code, ToolExecution::stderrHead($r));
                }
                $partial = $resultFile . '.part';
                $writer = new DefectWriter($partial, $root, $matcher, $this->logger);
                try {
                    ToolOutputParser::ruff($r->stdoutPath, static fn (array $d) => $writer->write($d));
                } catch (ToolOutputException $e) {
                    $writer->close();
                    @unlink($partial);
                    $this->logger->warn('ruff 출력 형식 오류', ['error' => $e->getMessage()]);
                    return $fail('tool_crash', $e->getMessage());
                }
                $stats = $writer->close();
                $scopeCount = 0;
                if ($scopeFile !== null) {
                    // 분석 범위: 같은 설정·제외 옵션으로 ruff가 검사하는 파일 목록(한 줄에 절대 경로 하나)
                    $s = $this->exec->runner->run([$tool->executable, 'check', '--show-files', '--no-cache', '--no-respect-gitignore', ...$tail], $options);
                    try {
                        $code = ToolExecution::classify($s, [0], static fn (ProcessResult $x): bool => $x->exitCode === 2);
                        if ($code !== null) {
                            @unlink($partial);
                            return $fail($code, '분석 범위 확인 실패: ' . ToolExecution::stderrHead($s));
                        }
                        $scopeWriter = new ScopeWriter($scopeFile . '.part', new DefectWriter($tmp . '/scope-paths.tmp', $root, $matcher, $this->logger));
                        $h = fopen($s->stdoutPath, 'rb');
                        while ($h !== false && ($line = fgets($h)) !== false) {
                            $line = rtrim($line, "\r\n");
                            if ($line !== '') {
                                $scopeWriter->add($line);
                            }
                        }
                        if ($h !== false) {
                            fclose($h);
                        }
                        $scopeCount = $scopeWriter->close();
                    } finally {
                        $s->cleanup();
                    }
                }
                if (!@rename($partial, $resultFile) || ($scopeFile !== null && !@rename($scopeFile . '.part', $scopeFile))) {
                    @unlink($partial);
                    @unlink($resultFile);
                    if ($scopeFile !== null) {
                        @unlink($scopeFile . '.part');
                    }
                    return $fail('tool_crash', '결과 파일을 옮길 수 없음');
                }
                return new ToolRunOutcome(true, null, null, $tool->label(), $conf['source'], $hash, $ignored, $stats, $resultFile, $scopeFile, $scopeCount);
            } finally {
                $r->cleanup();
            }
        } finally {
            Fs::removeTree($tmp);
        }
    }
}
