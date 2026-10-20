<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\App;
use PJGuardian\Services\Defects\DefectIngest;
use PJGuardian\Services\Defects\DefectStore;
use PJGuardian\Services\Defects\ScopeList;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Fs;

/**
 * P20R 시험 도우미: 도구 없이 합성 결과(NDJSON)로 실제 반영 경로(DefectIngest → DefectStore)를 탄다. 결함 = (파일, 줄, 규칙)이며
 * 시그니처가 줄 내용으로 정해지므로 파일마다 줄 내용을 서로 다르게 쓴다(`line{n} = {파일}`).
 */
final class RunFixture
{
    private int $seq = 0;
    private string $work;
    /** 규칙 기본 심각도(P21S). null이면 신규 결함은 not_defined(이전 시험과 같음) */
    public ?SeverityMap $severity = null;

    public function __construct(public readonly App $app, string $root, public readonly int $projectId, private readonly int $userId)
    {
        $this->work = $root . '/w' . $projectId;
        Fs::ensureDir($this->work);
    }

    /**
     * 작업 디렉토리를 $files(경로 → 줄 수)로 바꾸고 $hits([경로, 줄, 규칙])를 감지 결과로 반영한다. 반영 통계 + analysis_id.
     *
     * @param array<string, int> $files
     * @param list<array{0:string, 1:int, 2?:string}> $hits
     * @return array<string, mixed>
     */
    public function analyze(array $files, array $hits): array
    {
        $this->seq++;
        Fs::removeTree($this->work);
        Fs::ensureDir($this->work);
        foreach ($files as $path => $lines) {
            Fs::ensureDir(dirname($this->work . '/' . $path));
            $body = '';
            for ($i = 1; $i <= $lines; $i++) {
                $body .= "line{$i} = '{$path}'\n";
            }
            file_put_contents($this->work . '/' . $path, $body);
        }
        usort($hits, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $nd = $this->work . '.ndjson';
        $out = '';
        foreach ($hits as $h) {
            $out .= json_encode(['file' => $h[0], 'line' => $h[1], 'column' => 1, 'rule_key' => $h[2] ?? 'ruff:F401', 'tool' => 'ruff', 'language' => 'python',
                'message' => "m {$h[0]}:{$h[1]}"]) . "\n";
        }
        file_put_contents($nd, $out);
        $db = $this->app->db();
        $now = sprintf('2026-10-01T%02d:00:00Z', $this->seq);
        $aid = TestDb::analysis($db, $this->projectId, $this->userId, ['status' => 'running', 'queued_at' => $now, 'started_at' => $now]);
        (new DefectIngest($db))->load($nd, $this->work);
        $scope = $this->app->projects()->matcherFor($this->projectId);
        $inScope = array_values(array_filter(array_keys($files), static fn (string $p): bool => !$scope->matches($p)));
        $stats = (new DefectStore($db, $this->severity))->apply($aid, $this->projectId, ScopeList::fromPaths($inScope), $scope, $this->work,
            ['tool_version' => '0.16.10', 'config_source' => 'server_default', 'config_hash' => str_repeat('a', 64)], null, ['server_default' => $scope->defaults(), 'project' => $scope->patterns(), 'config_files' => []], $now);
        @unlink($nd);
        return $stats + ['analysis_id' => $aid];
    }

    /** 실패·취소 분석(Run이 아님) */
    public function failed(string $status = 'failed'): int
    {
        $this->seq++;
        $now = sprintf('2026-10-01T%02d:00:00Z', $this->seq);
        return TestDb::analysis($this->app->db(), $this->projectId, $this->userId, ['status' => $status, 'queued_at' => $now, 'started_at' => $now, 'finished_at' => $now,
            'failure_code' => $status === 'failed' ? 'tool_crash' : null]);
    }

    /** 결함 id: 파일·줄 */
    public function id(string $file, int $line): int
    {
        $id = $this->app->db()->value('SELECT id FROM defects WHERE project_id = ? AND file_path = ? AND line_no = ?', [$this->projectId, $file, $line]);
        if ($id === null) {
            throw new \RuntimeException("결함 없음 {$file}:{$line}");
        }
        return (int) $id;
    }

    /** @return list<array{0:int, 1:?int}> 존재 구간 */
    public function intervals(int $defectId): array
    {
        return array_map(static fn (array $r): array => [(int) $r['from_run'], $r['to_run'] === null ? null : (int) $r['to_run']],
            $this->app->db()->all('SELECT from_run, to_run FROM defect_runs WHERE defect_id = ? ORDER BY from_run', [$defectId]));
    }
}
