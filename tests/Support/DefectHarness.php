<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\App;
use PJGuardian\Services\Analysis\AnalysisOutcome;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolAnalysisRunner;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Fs;

/**
 * P16 시험 도우미: 실제 ruff·ESLint로 작업 디렉토리를 분석하고(P15 실행기) 결과를 결함으로 반영한다(AnalysisFinalizer).
 * 워커·레지스트리 없이 같은 코드 경로를 탄다. bin/ruff/0.16.10, bin/eslint/10.11.0과 Node가 있어야 한다.
 */
final class DefectHarness
{
    public readonly App $app;
    public readonly ?string $node;
    private int $user;

    public function __construct(public readonly string $root, array $overrides = [])
    {
        foreach (['work', 'logs', 'snapshots'] as $d) {
            Fs::ensureDir($root . '/' . $d);
        }
        $this->node = (new NodeRuntime(null, '20.19.0'))->path() ?? (is_file('C:/tools/nodejs/node.exe') ? 'C:/tools/nodejs/node.exe' : null);
        $base = [
            'paths' => ['work_dir' => $root . '/work', 'uploads_dir' => $root . '/work', 'snapshots_dir' => $root . '/snapshots'],
            'tools' => ['node_path' => (string) $this->node],
        ];
        foreach ($overrides as $s => $kv) {
            $base[$s] = ($base[$s] ?? []) + $kv;
            foreach ($kv as $k => $v) {
                $base[$s][$k] = $v;
            }
        }
        $this->app = TestApp::make($root . '/logs', $base);
        $this->user = TestDb::user($this->app->db(), 'kim', 'user');
    }

    public function toolsAvailable(): bool
    {
        return $this->tool('ruff') !== null && $this->tool('eslint') !== null;
    }

    public function tool(string $tool): ?SelectedTool
    {
        if ($tool === 'ruff') {
            $exe = realpath(PJG_ROOT . '/bin/ruff/0.16.10/' . ToolRegistry::executableRel('ruff'));
            return $exe === false ? null : new SelectedTool('ruff', '0.16.10', ToolRegistry::os(), (string) realpath(PJG_ROOT . '/bin/ruff/0.16.10'), $exe);
        }
        $dir = realpath(PJG_ROOT . '/bin/eslint/10.11.0');
        if ($dir === false || $this->node === null) {
            return null;
        }
        return new SelectedTool('eslint', '10.11.0', ToolRegistry::os(), $dir, $dir . '/node_modules/eslint/bin/eslint.js', (string) realpath($this->node), '24');
    }

    public function project(string $name, string $language): int
    {
        return TestDb::project($this->app->db(), $name, $language);
    }

    public function setExcludes(int $projectId, array $patterns): void
    {
        $this->app->db()->execute('UPDATE projects SET exclude_patterns = ? WHERE id = ?', [json_encode($patterns), $projectId]);
    }

    /** 실행 중인 분석 행을 만든다 */
    public function startAnalysis(int $projectId, string $tool): int
    {
        return TestDb::analysis($this->app->db(), $projectId, $this->user, [
            'status' => 'running', 'tool' => $tool, 'tool_version' => null, 'config_source' => null, 'config_hash' => null,
        ]);
    }

    /**
     * 도구 실행 → 결과 파일 → 결함 반영. 반영 통계(취소 등으로 반영하지 않았으면 null).
     *
     * @return array<string, mixed>|null
     */
    public function analyze(int $projectId, string $tool, string $dir, ?int $analysisId = null): ?array
    {
        $id = $analysisId ?? $this->startAnalysis($projectId, $tool);
        $o = $this->run($projectId, $tool, $dir, $id);
        $row = $this->app->db()->one('SELECT * FROM analyses WHERE id = ?', [$id]);
        return $this->app->analysisFinalizer()->finalize($id, $row, $dir, $o);
    }

    /** 도구만 실행(반영 전 결과) */
    public function run(int $projectId, string $tool, string $dir, int $analysisId): AnalysisOutcome
    {
        $results = $this->app->resultsDir();
        Fs::ensureDir($results);
        $file = ToolAnalysisRunner::resultFile($results, $analysisId);
        $scope = ToolAnalysisRunner::scopeFile($results, $analysisId);
        $matcher = $this->app->projects()->matcherFor($projectId);
        $r = $tool === 'ruff'
            ? $this->app->ruffRunner()->run($this->tool('ruff'), $dir, $matcher, $file, null, $scope)
            : $this->app->eslintRunner()->run($this->tool('eslint'), $dir, $matcher, $file, null, $scope);
        if (!$r->ok) {
            throw new \RuntimeException("{$tool} 실패: {$r->failureCode} {$r->detail}");
        }
        return AnalysisOutcome::succeeded(
            ['total_count' => 0, 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0],
            $r->toolInfo() + ['result_file' => $file, 'scope_file' => $scope],
        );
    }

    /** 샘플 폴더를 작업 디렉토리로 복사 */
    public function copySample(string $sample): string
    {
        $dst = $this->root . '/w' . bin2hex(random_bytes(4));
        self::copyTree(PJG_ROOT . '/samples/' . $sample, $dst);
        return $dst;
    }

    public static function copyTree(string $src, string $dst): void
    {
        Fs::ensureDir($dst);
        $src = (string) realpath($src);
        $base = strlen(rtrim(str_replace('\\', '/', $src), '/')) + 1;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr(str_replace('\\', '/', $f->getPathname()), $base);
            $f->isDir() ? Fs::ensureDir("{$dst}/{$rel}") : copy($f->getPathname(), "{$dst}/{$rel}");
        }
    }

    public static function rewrite(string $file, callable $fn): void
    {
        file_put_contents($file, $fn((string) file_get_contents($file)));
    }

    /** @return list<array<string, mixed>> 프로젝트 결함(id 순) */
    public function defects(int $projectId): array
    {
        return $this->app->db()->all('SELECT * FROM defects WHERE project_id = ? ORDER BY id', [$projectId]);
    }

    /** @return array<string, int> 상태별 건수 */
    public function statusCounts(int $projectId): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT status, COUNT(*) AS n FROM defects WHERE project_id = ? GROUP BY status', [$projectId]) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        ksort($out);
        return $out;
    }
}
