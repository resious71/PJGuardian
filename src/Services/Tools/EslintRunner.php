<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * ESLint 실행(명세 5.3, 6.1, 19.10): ESLint 10.x + 서버가 만든 flat config.
 *   node --max-old-space-size=<MB> <bin/eslint/<버전>>/node_modules/eslint/bin/eslint.js -c tools/eslint/pjg.flat.config.cjs
 *        --format tools/eslint/ndjson-formatter.cjs --no-error-on-unmatched-pattern --no-warn-ignored .
 * - 업로드된 ESLint 설정(eslint.config.*, .eslintrc*, package.json의 eslintConfig)은 **통째로 무시**하고 ignored_configs에 남긴다.
 *   JS 설정 파일은 분석 대상에서도 뺀다(ignores). 설정 출처는 항상 server_default.
 * - 업로드된 .gitignore는 무시, 루트 .eslintignore만 서버 ignore 패턴으로 바꿔 적용(eslintIgnorePatterns).
 * - 서버 기본 제외 목록(이름)은 ignores로 넘기고, 프로젝트 추가 패턴은 해제 단계에서 빠졌으며 결과를 쓸 때 ExcludeMatcher로 거른다.
 * - 종료 코드 0(결함 없음)·1(결함 있음)이 성공. 2는 설정 검증 오류면 tool_config_error, 그 밖은 tool_crash, 힙 부족은 memory_limit.
 * - P22B 관리자 규칙 변경: 모든 ESLint 분석에 적용(업로드 설정은 원래 무시). 변경이 있을 때만 -c를 OVERRIDES_CONFIG_FILE(기본 설정을 읽고
 *   맨 뒤에 규칙 변경을 얹는 스크립트)로 바꾸고 해시에 변경 집합과 그 스크립트 해시를 더한다. 기본 스크립트(CONFIG_FILE)는 고치지 않는다 —
 *   그 내용이 해시에 들어가므로, 고치면 변경이 없는 서버의 모든 ESLint 분석이 "설정 변경"으로 보인다.
 */
final class EslintRunner
{
    public const CONFIG_FILE = 'tools/eslint/pjg.flat.config.cjs';
    public const FORMATTER_FILE = 'tools/eslint/ndjson-formatter.cjs';
    public const OVERRIDES_CONFIG_FILE = 'tools/eslint/pjg.flat.overrides.config.cjs';
    public const MAX_IGNORE_LINES = 1000;

    /** @param list<string> $extensions 대상 확장자(설정 upload.target_extensions_javascript, ".js" 형식) */
    public function __construct(
        private readonly ToolExecution $exec,
        private readonly string $appRoot,
        private readonly string $baseConfig,
        private readonly array $extensions,
        private readonly Logger $logger,
    ) {
    }

    /**
     * .eslintignore(gitignore 문법) → flat ignores(minimatch, 분석 루트 기준).
     * 빈 줄·주석(#) 무시, 앞의 '!'는 부정으로 유지, 앞의 '/'는 루트 기준, 중간에 '/'가 없으면 어느 깊이든('**\/' 접두),
     * 끝의 '/'는 폴더. 폴더로도 맞도록 '<패턴>/**'를 함께 넣는다.
     *
     * @return list<string>
     */
    public static function eslintIgnorePatterns(string $text): array
    {
        $out = [];
        $n = 0;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $raw) {
            if (++$n > self::MAX_IGNORE_LINES) {
                break;
            }
            $line = rtrim($raw);
            if ($line === '' || str_starts_with($line, '#') || strlen($line) > 500 || preg_match('/[\x00-\x1F]/', $line) === 1) {
                continue;
            }
            $neg = '';
            if (str_starts_with($line, '!')) {
                $neg = '!';
                $line = substr($line, 1);
            }
            $line = str_replace('\\', '/', $line);
            $dirOnly = str_ends_with($line, '/');
            $body = trim($line, '/');
            if ($body === '' || preg_match('#(^|/)\.\.(/|$)#', $body) === 1) {
                continue;
            }
            $anchored = str_starts_with($line, '/') || str_contains($body, '/');
            $p = $anchored ? $body : '**/' . $body;
            if (!$dirOnly) {
                $out[] = $neg . $p;
            }
            $out[] = $neg . $p . '/**';
        }
        return array_values(array_unique($out));
    }

    /** 이름 하나(eslint.config.js 등)를 어느 깊이든 맞는 glob으로 */
    private static function anywhere(string $name): string
    {
        return '**/' . $name;
    }

    /**
     * 대상·제외 패턴(분석 루트와 무관한 부분). .eslintignore 변환은 run()이 더한다.
     *
     * @return array{0: list<string>, 1: list<string>} [files, ignores]
     */
    public function patterns(ExcludeMatcher $matcher): array
    {
        $files = array_map(static fn (string $ext): string => '**/*' . $ext, $this->extensions);
        $ignores = ['**/*.mjs', '**/*.cjs', '**/eslint.config.*', '**/.eslintrc', '**/.eslintrc.*'];
        foreach ($matcher->defaults() as $name) {
            $ignores[] = self::anywhere($name);
            $ignores[] = self::anywhere($name) . '/**';
        }
        return [$files, $ignores];
    }

    /**
     * 설정 해시: 실제 적용한 설정의 정규화된 내용(기본 규칙 JSON + 대상·제외 패턴 + 서버 설정 스크립트 + 형식기)
     * + 관리자 규칙 변경이 있을 때만 그 집합과 변경용 스크립트(P22B — 없으면 P15와 같은 값).
     *
     * @param list<string> $files
     * @param list<string> $ignores
     * @param array{enable: list<string>, disable: list<string>}|null $overrides EffectiveRuleConfig::canonical()
     */
    public function configHash(array $files, array $ignores, ?array $overrides): string
    {
        $doc = [
            'base' => json_decode((string) file_get_contents($this->baseConfig), true),
            'files' => $files, 'ignores' => $ignores,
            'script' => hash_file('sha256', $this->appRoot . '/' . self::CONFIG_FILE), 'formatter' => hash_file('sha256', $this->appRoot . '/' . self::FORMATTER_FILE),
        ];
        if ($overrides !== null) {
            $doc['rule_overrides'] = $overrides;
            $doc['overrides_script'] = hash_file('sha256', $this->appRoot . '/' . self::OVERRIDES_CONFIG_FILE);
        }
        return hash('sha256', json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param string|null $scopeFile 분석한 파일 목록을 쓸 곳(P16). null이면 쓰지 않음
     * @param array{enable?: list<string>, disable?: list<string>}|null $ruleOverrides 관리자 규칙 변경(P22B, 분석 시작 때 읽은 스냅샷). 스모크 테스트는 null
     */
    public function run(SelectedTool $tool, string $root, ExcludeMatcher $matcher, string $resultFile, ?ToolProjectScan $scan = null, ?string $scopeFile = null, ?array $ruleOverrides = null): ToolRunOutcome
    {
        $scan ??= ToolProjectScan::run($root, $matcher);
        $realRoot = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
        [$files, $ignores] = $this->patterns($matcher);
        $overrides = EffectiveRuleConfig::canonical('eslint', $ruleOverrides);
        $eslintIgnore = [];
        if ($scan->hasEslintIgnore) {
            $size = (int) @filesize($realRoot . '/.eslintignore');
            $eslintIgnore = $size <= 1024 * 1024 ? self::eslintIgnorePatterns((string) file_get_contents($realRoot . '/.eslintignore')) : [];
            $ignores = [...$ignores, ...$eslintIgnore];
        }
        $ignored = [];
        foreach ($scan->eslintConfigs as $f) {
            $ignored[] = ['file' => $f, 'reason' => 'eslint_config_ignored'];
        }
        if ($scan->eslintConfigsTotal > count($scan->eslintConfigs)) {
            $ignored[] = ['file' => '…', 'reason' => 'eslint_config_ignored', 'count' => $scan->eslintConfigsTotal - count($scan->eslintConfigs)];
        }
        // P19: 루트 .eslintignore를 서버 ignore 패턴으로 바꿔 적용했다는 기록(분석 상세의 "설정 무시됨" 안내). count = 바꾼 패턴 수
        if ($eslintIgnore !== []) {
            $ignored[] = ['file' => '.eslintignore', 'reason' => 'eslintignore_converted', 'count' => count($eslintIgnore)];
        }
        $configScript = $this->appRoot . '/' . ($overrides !== null ? self::OVERRIDES_CONFIG_FILE : self::CONFIG_FILE);
        $hash = $this->configHash($files, $ignores, $overrides);
        $fail = fn (string $code, string $detail): ToolRunOutcome => new ToolRunOutcome(false, $code, $detail, $tool->label(), 'server_default', $hash, $ignored);
        if ($tool->interpreter === null) {
            return $fail('tool_unavailable', 'Node 런타임 없음');
        }

        $tmp = $this->exec->newTemp();
        try {
            $optionsFile = $tmp . '/eslint-options.json';
            file_put_contents($optionsFile, json_encode([
                'eslint_dir' => $tool->dir, 'base_config' => (string) realpath($this->baseConfig), 'files' => $files, 'ignores' => $ignores,
                'rules' => $overrides === null ? (object) [] : (object) EffectiveRuleConfig::eslintRules($overrides),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $mb = max(256, intdiv($this->exec->memoryLimit, 1024 * 1024));
            $cmd = [
                $tool->interpreter, '--max-old-space-size=' . $mb, $tool->executable,
                '-c', $configScript, '--format', $this->appRoot . '/' . self::FORMATTER_FILE,
                '--no-error-on-unmatched-pattern', '--no-warn-ignored', '.',
            ];
            $r = $this->exec->runner->run($cmd, new ProcessOptions(
                cwd: $realRoot,
                env: ProcessRunner::minimalEnv($tmp, [dirname($tool->interpreter)], ['PJG_ESLINT_OPTIONS' => $optionsFile]),
                timeoutSeconds: (float) $this->exec->timeoutSeconds,
                outputLimit: $this->exec->outputLimit,
                outputDir: $tmp,
            ));
            try {
                $code = ToolExecution::classify($r, [0, 1], static function (ProcessResult $x): bool {
                    $err = $x->stderr(64 * 1024);
                    return $x->exitCode === 2 && preg_match('/Key "|ConfigError|Could not find|Invalid option|configuration/i', $err) === 1;
                });
                if ($code !== null) {
                    $this->logger->warn('ESLint 실행 실패', ['code' => $code] + $r->summary() + ['stderr' => ToolExecution::stderrHead($r)]);
                    return $fail($code, ToolExecution::stderrHead($r));
                }
                $partial = $resultFile . '.part';
                $writer = new DefectWriter($partial, $realRoot, $matcher, $this->logger);
                $scope = $scopeFile !== null ? new ScopeWriter($scopeFile . '.part', $writer) : null;
                try {
                    ToolOutputParser::eslint($r->stdoutPath, static fn (array $d) => $writer->write($d), $scope === null ? null : static fn (string $p) => $scope->add($p));
                } catch (ToolOutputException $e) {
                    $writer->close();
                    $scope?->close();
                    @unlink($partial);
                    if ($scopeFile !== null) {
                        @unlink($scopeFile . '.part');
                    }
                    $this->logger->warn('ESLint 출력 형식 오류', ['error' => $e->getMessage()]);
                    return $fail('tool_crash', $e->getMessage());
                }
                $stats = $writer->close();
                $scopeCount = $scope?->close() ?? 0;
                if (!@rename($partial, $resultFile) || ($scopeFile !== null && !@rename($scopeFile . '.part', $scopeFile))) {
                    @unlink($partial);
                    @unlink($resultFile);
                    if ($scopeFile !== null) {
                        @unlink($scopeFile . '.part');
                    }
                    return $fail('tool_crash', '결과 파일을 옮길 수 없음');
                }
                if ($eslintIgnore !== []) {
                    $this->logger->info('.eslintignore를 서버 ignore 패턴으로 적용', ['patterns' => count($eslintIgnore)]);
                }
                return new ToolRunOutcome(true, null, null, $tool->label(), 'server_default', $hash, $ignored, $stats, $resultFile, $scopeFile, $scopeCount);
            } finally {
                $r->cleanup();
            }
        } finally {
            Fs::removeTree($tmp);
        }
    }
}
