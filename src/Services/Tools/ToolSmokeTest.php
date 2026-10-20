<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;

/**
 * 도구 버전 스모크 테스트(명세 19.1, 19.10): 시료로 실제 실행 → JSON 파싱 → 기대 결함 수 대조(samples/EXPECTED.md, 서버 기본 설정 기준).
 *   ruff:   samples/python_sample 19건, samples/python_syntax_error 2건(모두 syntax-error)
 *   ESLint: samples/js_sample_noconfig 5건, samples/ts_sample 6건, 임시로 만든 문법 오류 JS 1건(syntax-error)
 * 실패하면 그 버전을 invalid로 두고 직전 유효 버전을 쓴다(ToolRegistry). git·p4는 버전 출력 확인(P25).
 */
final class ToolSmokeTest
{
    /** @var array<string, list<array{dir:string, total:int, syntax:int}>> */
    public const CASES = [
        'ruff' => [
            ['dir' => 'python_sample', 'total' => 19, 'syntax' => 0],
            ['dir' => 'python_syntax_error', 'total' => 2, 'syntax' => 2],
        ],
        'eslint' => [
            ['dir' => 'js_sample_noconfig', 'total' => 5, 'syntax' => 0],
            ['dir' => 'ts_sample', 'total' => 6, 'syntax' => 0],
            ['dir' => '@broken_js', 'total' => 1, 'syntax' => 1],
        ],
    ];

    /** @param list<string> $defaultExcludes */
    public function __construct(
        private readonly RuffRunner $ruff,
        private readonly EslintRunner $eslint,
        private readonly string $samplesDir,
        private readonly string $tempRoot,
        private readonly array $defaultExcludes,
        // P25: git·p4 스모크(버전 출력 확인)용 실행기 — bin/만 허용
        private readonly ?\PJGuardian\Services\Process\ProcessRunner $vcsRunner = null,
    ) {
    }

    /**
     * git·p4 스모크(P25): 격리 환경(빈 HOME, GIT_CONFIG_NOSYSTEM, P4CONFIG 비움)에서 `git --version`·`p4 -V`만 실행해 이름·버전을 확인한다.
     *
     * @return array{ok:bool, message:string}
     */
    private function vcs(SelectedTool $tool): array
    {
        if ($this->vcsRunner === null) {
            return ['ok' => false, 'message' => '스모크 실행기 없음'];
        }
        Fs::ensureDir($this->tempRoot);
        $sb = new \PJGuardian\Services\Repo\VcsSandbox($this->tempRoot . '/vcs-smoke-' . bin2hex(random_bytes(4)));
        try {
            $git = $tool->tool === 'git';
            $r = $this->vcsRunner->run([$tool->executable, $git ? '--version' : '-V'], new \PJGuardian\Services\Process\ProcessOptions(
                cwd: $sb->home, env: $git ? $sb->gitEnv() : $sb->p4Env(), timeoutSeconds: 30, outputLimit: 1024 * 1024, outputDir: $sb->home));
            $out = trim($r->stdout(65536));
            $r->cleanup();
            $ok = $r->ok() && ($git ? preg_match('/^git version \d+\.\d+/', $out) === 1 : preg_match('#Rev\. P4/\S+/\d{4}\.\d#', $out) === 1);
            $line = $git ? $out : (preg_match('#Rev\. (P4/\S+)#', $out, $m) === 1 ? $m[1] : '');
            return ['ok' => $ok, 'message' => $ok ? '통과: ' . mb_substr($line, 0, 200) : '버전 확인 실패(종료 ' . (string) $r->exitCode . ')'];
        } finally {
            $sb->remove();
        }
    }

    /** @return array{ok:bool, message:string} */
    public function run(SelectedTool $tool): array
    {
        if ($tool->tool === 'git' || $tool->tool === 'p4') {
            return $this->vcs($tool);
        }
        $cases = self::CASES[$tool->tool] ?? null;
        if ($cases === null) {
            return ['ok' => true, 'message' => '스모크 테스트 없음(이후 단계)'];
        }
        Fs::ensureDir($this->tempRoot);
        $tmp = $this->tempRoot . '/smoke-' . bin2hex(random_bytes(4));
        Fs::ensureDir($tmp);
        $matcher = new ExcludeMatcher($this->defaultExcludes, []);
        $parts = [];
        try {
            foreach ($cases as $i => $c) {
                $dir = $this->samplesDir . '/' . $c['dir'];
                if ($c['dir'] === '@broken_js') {
                    $dir = $tmp . '/broken';
                    Fs::ensureDir($dir);
                    file_put_contents($dir . '/broken.js', "function broken( {\n  return 1;\n}\n");
                }
                if (!is_dir($dir)) {
                    return ['ok' => false, 'message' => '시료 없음: ' . $c['dir']];
                }
                $out = $tmp . '/result' . $i . '.ndjson';
                $r = $tool->tool === 'ruff' ? $this->ruff->run($tool, $dir, $matcher, $out) : $this->eslint->run($tool, $dir, $matcher, $out);
                if (!$r->ok) {
                    return ['ok' => false, 'message' => sprintf('%s: 실행 실패 %s %s', $c['dir'], $r->failureCode, mb_substr((string) $r->detail, 0, 300))];
                }
                $got = $r->stats['written'] ?? -1;
                $syntax = $r->stats['syntax'] ?? -1;
                if ($got !== $c['total'] || $syntax !== $c['syntax']) {
                    return ['ok' => false, 'message' => sprintf('%s: 결함 %d건(기대 %d), 문법 오류 %d건(기대 %d)', $c['dir'], $got, $c['total'], $syntax, $c['syntax'])];
                }
                $parts[] = sprintf('%s %d건', $c['dir'], $got);
            }
            return ['ok' => true, 'message' => '통과: ' . implode(', ', $parts)];
        } finally {
            Fs::removeTree($tmp);
        }
    }
}
