<?php

declare(strict_types=1);

namespace PJGuardian\Services\Admin;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Support\Db;
use PJGuardian\Support\EnvironmentCheck;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 관리자 "점검" 화면(P20A, 읽기 전용, P30 시스템 모니터링이 확장). 웹 요청 안에서 프로세스를 띄우거나 큰 폴더를 훑지 않는다.
 *
 * - 설정: 주요 키의 현재 값과 기본값(config.ini.example) 대비 변경 여부. **비밀값은 "설정됨/미설정"만**(값을 읽어 내보내지 않음).
 *   경로 설정은 앱 폴더 기준 상대 경로면 그대로, 절대 경로면 값을 숨기고 "절대 경로"로만 표시한다(서버 경로 비노출).
 *   시작 명령(worker.start_command)·Node 경로도 값 대신 설정 여부만.
 * - 환경: EnvironmentCheck(check_env.php와 같은 함수) + 시스템 시각 + HTTPS 여부. Node는 워커 생존 신호의 검사 결과.
 * - 디스크: 전체 여유·사용률(가벼움, 매번) + 구분별 사용량(관리 작업 disk_scan이 계산한 캐시와 계산 시각).
 * - 정리 작업: 마지막 실행 결과 파일(<work>/admin/cleanup_last.json), 휴지통 항목 수, cleanup_pending.jsonl 줄 수,
 *   정리 대기 미리보기(disk_scan이 함께 계산한 dry-run 결과).
 * - 실패한 분석: 최근 7일 사유별 건수와 최근 목록. 관리자 조치가 필요한 사유는 관리 화면 링크.
 */
final class SystemChecks
{
    /** 화면에 보여 줄 설정 키(섹션.키) */
    public const CONFIG_KEYS = [
        'app.env', 'app.base_url', 'app.display_timezone', 'security.require_https',
        'paths.db_file', 'paths.uploads_dir', 'paths.work_dir', 'paths.snapshots_dir', 'paths.logs_dir', 'paths.bin_dir', 'paths.backups_dir',
        'upload.max_zip_size', 'upload.chunk_size', 'upload.max_concurrent_per_user',
        'upload.incomplete_chunk_retention', 'upload.failed_zip_retention', 'upload.confirmation_retention',
        'extract.max_extracted_size', 'extract.max_files', 'analysis.timeout', 'analysis.max_concurrent', 'analysis.output_limit',
        'disk.warn_percent', 'disk.block_percent',
        'log.level', 'log.error_access_retention', 'log.audit_retention', 'backup.dir', 'backup.daily_at', 'backup.retention_days', 'snapshot.full_keep_count',
        'tools.node_path', 'tools.node_min_version', 'tools.package_max_size', 'tools.staging_retention',
        'worker.supervised', 'worker.start_command', 'worker.heartbeat_stale', 'worker.admin_task_timeout',
    ];
    /** 값을 보여 주지 않고 설정 여부만 보여 줄 키(경로·명령) */
    private const PRESENCE_ONLY = ['tools.node_path', 'worker.start_command'];
    /** 관리자 조치가 필요한 실패 사유 → 관리 화면 */
    public const ADMIN_ACTION = [
        'tool_unavailable' => 'admin/tools', 'tool_crash' => 'admin/tools', 'tool_config_error' => 'admin/tools', 'tool_failed' => 'admin/tools',
        'insufficient_storage' => 'admin/checks#disk', 'result_save_failed' => 'admin/checks#disk',
        'worker_crashed' => 'admin/worker', 'analysis_crashed' => 'admin/worker', 'analysis_start_failed' => 'admin/worker',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly ?Config $defaults,
        private readonly string $appRoot,
        private readonly string $workDir,
        private readonly DiskUsage $disk,
        private readonly AdminTasks $tasks,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function configView(): array
    {
        $schema = ConfigSchema::definition();
        $out = [];
        foreach (self::CONFIG_KEYS as $name) {
            [$section, $key] = explode('.', $name, 2);
            $spec = $schema[$section][$key];
            $value = $this->config->get($name);
            $default = $this->defaults?->get($name);
            $changed = $this->defaults !== null && $value !== $default;
            $row = ['key' => $name, 'changed' => $changed, 'kind' => 'value', 'value' => null];
            if (in_array($name, self::PRESENCE_ONLY, true)) {
                $row['kind'] = 'presence';
                $row['value'] = $value !== null && $value !== '' ? 'set' : 'unset';
            } elseif ($section === 'paths') {
                $p = str_replace('\\', '/', (string) $value);
                if (preg_match('#^([A-Za-z]:)?/#', $p) === 1) {
                    $row['kind'] = 'absolute_path';
                } else {
                    $row['value'] = $p;
                }
            } else {
                $row['value'] = self::format($value, (string) $spec['type']);
                $row['default'] = $this->defaults !== null ? self::format($default, (string) $spec['type']) : null;
            }
            $out[] = $row;
        }
        foreach (Config::SECRET_KEYS as $k) {
            // 값은 읽기만 하고 내보내지 않는다(설정 여부만)
            $out[] = ['key' => 'secret.' . $k, 'changed' => false, 'kind' => 'secret', 'value' => $this->config->secret($k) !== null ? 'set' : 'unset'];
        }
        return $out;
    }

    private static function format(mixed $v, string $type): string
    {
        return match (true) {
            $v === null => '',
            $type === 'size' && is_int($v) => Config::formatSize($v),
            $type === 'duration' && is_int($v) => Config::formatDuration($v),
            is_bool($v) => $v ? 'true' : 'false',
            is_array($v) => implode(', ', $v),
            default => (string) $v,
        };
    }

    /**
     * @param array<string, string> $dataDirs
     * @param array<string, mixed>|null $node 워커 생존 신호의 Node 결과
     * @return array<string, mixed>
     */
    public function environment(array $dataDirs, string $binDir, bool $https, ?array $node, ?string $configDir = null, ?string $publicDir = null): array
    {
        $items = [...EnvironmentCheck::platform(), ...EnvironmentCheck::ini($this->config->int('upload.chunk_size')),
            ...EnvironmentCheck::directories($dataDirs, $binDir, true),
            // P31 A-4 보안 점검(알리되 막지 않음)
            ...($configDir !== null && $publicDir !== null ? EnvironmentCheck::security($configDir, $publicDir) : [])];
        return [
            'items' => $items,
            'time_utc' => SystemClock::iso($this->clock->now()),
            'timezone' => $this->config->string('app.display_timezone'),
            'https' => $https,
            'node' => $node,
        ];
    }

    /** @return array<string, mixed> */
    public function disk(string $dataDir): array
    {
        $live = EnvironmentCheck::disk($dataDir, $this->config->int('disk.warn_percent'), $this->config->int('disk.block_percent'));
        return $live + [
            'warn_percent' => $this->config->int('disk.warn_percent'),
            'block_percent' => $this->config->int('disk.block_percent'),
            'usage' => $this->disk->cached(),
            'task' => $this->tasks->active('disk_scan', '') ?? $this->tasks->latest('disk_scan'),
        ];
    }

    public static function cleanupFile(string $workDir): string
    {
        return rtrim($workDir, '/') . '/admin/cleanup_last.json';
    }

    /** 정리 작업 결과 기록(tools/cleanup.php, 관리 작업 cleanup_run). 서버 경로 없음 */
    public static function recordCleanup(string $workDir, string $at, string $source, bool $ok, array $result): void
    {
        try {
            Fs::ensureDir(dirname(self::cleanupFile($workDir)));
            Fs::atomicWrite(self::cleanupFile($workDir), (string) json_encode(['at' => $at, 'source' => $source, 'ok' => $ok, 'result' => $result]));
        } catch (\Throwable) {
            // 기록을 못 해도 정리는 끝났다
        }
    }

    /** @return array<string, mixed> */
    public function cleanup(): array
    {
        $raw = @file_get_contents(self::cleanupFile($this->workDir));
        $last = $raw === false ? null : json_decode($raw, true);
        $trash = rtrim($this->workDir, '/') . '/trash';
        $pendingFile = rtrim($this->workDir, '/') . '/cleanup_pending.jsonl';
        $pending = 0;
        $h = @fopen($pendingFile, 'rb');
        if ($h !== false) {
            while (($line = fgets($h)) !== false) {
                $pending += trim($line) !== '' ? 1 : 0;
            }
            fclose($h);
        }
        $usage = $this->disk->cached();
        return [
            'last' => is_array($last) ? $last : null,
            'trash_items' => count(Fs::listDir($trash)),
            'pending_items' => $pending,
            'preview' => is_array($usage['cleanup_preview'] ?? null) ? $usage['cleanup_preview'] : null,
            'preview_at' => $usage['scanned_at'] ?? null,
            'task' => $this->tasks->active('cleanup_run', '') ?? $this->tasks->latest('cleanup_run'),
        ];
    }

    /** @return array<string, mixed> 최근 7일 실패 분석 */
    public function failures(int $days = 7): array
    {
        $cutoff = SystemClock::iso($this->clock->now()->modify('-' . $days . ' days'));
        $byCode = [];
        foreach ($this->db->all("SELECT failure_code, COUNT(*) AS n FROM analyses WHERE status = 'failed' AND finished_at >= ? GROUP BY failure_code ORDER BY n DESC, failure_code", [$cutoff]) as $r) {
            $code = (string) ($r['failure_code'] ?? 'unknown');
            $d = ErrorCatalog::describe('analysis', $code);
            $byCode[] = ['code' => $code, 'count' => (int) $r['n'], 'title' => $d['title'], 'action' => $d['action'],
                'admin_href' => self::ADMIN_ACTION[$code] ?? null, 'guide_key' => $d['guide_key']];
        }
        $recent = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'project_id' => (int) $r['project_id'], 'project_name' => (string) $r['project_name'],
            'failure_code' => $r['failure_code'], 'finished_at' => $r['finished_at'],
        ], $this->db->all(
            "SELECT a.id, a.project_id, p.name AS project_name, a.failure_code, a.finished_at FROM analyses a JOIN projects p ON p.id = a.project_id"
            . " WHERE a.status = 'failed' AND a.finished_at >= ? ORDER BY a.finished_at DESC, a.id DESC LIMIT 20",
            [$cutoff],
        ));
        return ['days' => $days, 'by_code' => $byCode, 'recent' => $recent];
    }
}
