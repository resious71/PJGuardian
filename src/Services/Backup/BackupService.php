<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Services\Settings\PathPolicy;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SystemClock;

/**
 * 백업 묶음 만들기·검증·목록·보관 정리·삭제(P27B, 명세 14·19.3·19.4). 웹 화면·워커 작업·명령줄(tools/backup.php)이 같은 코드를 쓴다.
 *
 * 만들기: 백업 폴더 준비(PathPolicy) → 디스크 사전 검사(필요량 추정 < 여유, 백업 폴더 디스크 사용률이 disk.block_percent 이상이면 중단)
 *   → 폴더 안 임시 폴더에 `VACUUM INTO`(서비스 중에도 일관된 단일 파일, -wal·-shm 없음) → 설정 사본(민감 값 비움) → bin 체크섬 목록
 *   → 스냅샷(목록을 먼저 확정하고 하나씩 임시 폴더로 복사 — 그사이 사라진 파일은 건너뛰고 manifest에 적음) → manifest(크기·SHA-256·건수)
 *   → zip 임시 이름 → 이름 바꾸기 → 곁에 manifest 사본(<묶음>.json) → **바로 검증**(실패하면 지우고 실패). 어떤 실패든 임시 파일을 지운다.
 * 목록은 폴더의 파일로 만든다(DB 표 없음 — 복원이 DB를 바꾸므로).
 */
final class BackupService
{
    /** 핵심 표(검증·미리보기 건수) */
    public const COUNT_TABLES = ['users', 'projects', 'analyses', 'defects'];

    public function __construct(
        private readonly Config $config,
        private readonly BackupPaths $paths,
        private readonly PathPolicy $policy,
        private readonly Paths $dataPaths,
        private readonly string $root,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly string $appVersion,
    ) {
    }

    public function paths(): BackupPaths
    {
        return $this->paths;
    }

    public function state(): BackupState
    {
        return new BackupState($this->paths->stateFile());
    }

    /**
     * 묶음 하나를 만들고 검증한다.
     *
     * @param \Closure(string, int, int): void|null $progress (단계, 처리 바이트, 전체 바이트)
     * @param \Closure(): bool|null $cancelled true면 중단(backup_cancelled)
     * @return array<string, mixed> {name, size, kind, manifest}
     */
    public function create(string $kind, bool $snapshots, string $by, ?\Closure $progress = null, ?\Closure $cancelled = null): array
    {
        if (!in_array($kind, BundleFormat::KINDS, true)) {
            throw new \InvalidArgumentException('종류');
        }
        $progress ??= static function (): void {};
        $check = static function () use ($cancelled): void {
            if ($cancelled !== null && $cancelled()) {
                throw new BackupException('backup_cancelled');
            }
        };
        $t0 = hrtime(true);
        $this->paths->prepare($this->policy);
        $dir = $this->paths->dir();
        $dbFile = $this->dataPaths->dbFile();
        $snapDir = rtrim(str_replace('\\', '/', $this->dataPaths->snapshots()), '/');
        $snapFiles = $snapshots ? self::snapshotFiles($snapDir) : [];
        $snapBytes = array_sum(array_map(static fn (string $f): int => (int) @filesize($snapDir . '/' . $f), $snapFiles));
        $dbBytes = (int) @filesize($dbFile) + (int) @filesize($dbFile . '-wal');
        // 임시 사본(DB + 스냅샷) + 묶음(최악 같은 크기) + 여유 64MB
        $need = 2 * ($dbBytes + $snapBytes) + 64 * 1024 * 1024;
        $this->diskCheck($dir, $need);
        $now = $this->clock->now();
        $name = $this->newName($dir, $kind, $now);
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        $zipTmp = $dir . '/' . $name . '.tmp-' . bin2hex(random_bytes(4));
        Fs::ensureDir($tmp);
        try {
            // 1) DB — VACUUM INTO(읽기 연결, 쓰기는 계속돼도 일관된 시점의 사본)
            $progress('db', 0, $dbBytes);
            $snapDb = $tmp . '/pjguardian.sqlite';
            $src = new \PDO('sqlite:' . $dbFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $src->exec('PRAGMA busy_timeout = 30000');
            $src->prepare('VACUUM INTO ?')->execute([$snapDb]);
            $src = null;
            $check();
            [$counts, $schema, $lastAnalysis] = self::inspectDb($snapDb);
            // 2) 설정 사본(민감 값 비움)
            $blanked = [];
            $files = [BundleFormat::DB => $snapDb];
            $ini = $this->config->iniPath();
            if ($ini !== null && is_file($ini)) {
                [$text, $blanked['config']] = ConfigSanitizer::sanitize((string) file_get_contents($ini));
                file_put_contents($tmp . '/config.ini', $text);
                $files[BundleFormat::CONFIG] = $tmp . '/config.ini';
            }
            $local = $this->config->localPath();
            if ($local !== null && is_file($local)) {
                [$text, $blanked['settings_local']] = ConfigSanitizer::sanitize((string) file_get_contents($local));
                file_put_contents($tmp . '/settings.local.ini', $text);
                $files[BundleFormat::LOCAL] = $tmp . '/settings.local.ini';
            }
            // 3) bin 체크섬 목록(실행 파일 없음)
            $bin = $this->binManifest();
            file_put_contents($tmp . '/bin.json', (string) json_encode($bin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $files[BundleFormat::BIN] = $tmp . '/bin.json';
            // 4) 스냅샷: 확정한 목록을 하나씩 복사(사라진 파일은 건너뜀)
            $missing = [];
            $copied = 0;
            foreach ($snapFiles as $rel) {
                $check();
                $to = $tmp . '/snapshots/' . $rel;
                Fs::ensureDir(dirname($to));
                if (!@copy($snapDir . '/' . $rel, $to)) {
                    $missing[] = $rel;
                    continue;
                }
                $files['snapshots/' . $rel] = $to;
                $copied += (int) filesize($to);
                $progress('snapshots', $copied, $snapBytes);
            }
            // 5) manifest
            $entries = [];
            foreach ($files as $entry => $path) {
                $entries[$entry] = ['size' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
            }
            $tz = new \DateTimeZone((string) $this->config->get('app.display_timezone'));
            $manifest = [
                'format' => BundleFormat::FORMAT, 'format_version' => BundleFormat::VERSION, 'kind' => $kind, 'app_version' => $this->appVersion,
                'schema_version' => $schema, 'created_at' => SystemClock::iso($now), 'created_at_local' => $now->setTimezone($tz)->format('Y-m-d H:i:s T'),
                'host' => (string) gethostname(), 'os' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'created_by' => $by,
                'options' => ['snapshots' => $snapshots], 'counts' => $counts, 'last_analysis_at' => $lastAnalysis,
                'blanked' => $blanked, 'missing_snapshots' => $missing, 'bin_tools' => $bin['tools'], 'entries' => $entries,
            ];
            // 6) zip(스냅샷은 이미 압축 — 저장만), 이름 바꾸기
            $zip = new \ZipArchive();
            if ($zip->open($zipTmp, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new BackupException('backup_io_failed', [], 'zip 생성 실패');
            }
            $zip->addFromString(BundleFormat::MANIFEST, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach ($files as $entry => $path) {
                $zip->addFile($path, $entry);
                $zip->setCompressionName($entry, str_starts_with($entry, 'snapshots/') ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE);
            }
            $progress('zip', 0, 0);
            if (!$zip->close()) {
                throw new BackupException('backup_io_failed', [], 'zip 닫기 실패');
            }
            $check();
            $final = $dir . '/' . $name;
            if (!@rename($zipTmp, $final)) {
                throw new BackupException('backup_io_failed', [], '묶음 이름 바꾸기 실패');
            }
            $this->writeSidecar($name, $manifest, null);
            // 7) 바로 검증 — 실패하면 깨진 백업을 남기지 않는다
            $progress('verify', 0, 0);
            $v = $this->verify($name);
            if (!$v['ok']) {
                $this->deleteFiles($name);
                throw new BackupException('backup_verify_failed', ['code' => (string) $v['code']]);
            }
            $size = (int) filesize($final);
            $ms = (int) ((hrtime(true) - $t0) / 1e6);
            $this->logger->info('백업 완료', ['file' => $name, 'size' => $size, 'kind' => $kind, 'snapshots' => count($snapFiles) - count($missing), 'ms' => $ms]);
            return ['name' => $name, 'size' => $size, 'kind' => $kind, 'ms' => $ms, 'manifest' => $manifest];
        } finally {
            @unlink($zipTmp);
            self::removeTree($tmp);
        }
    }

    /**
     * 검증: zip 구조·허용 항목·manifest·항목 해시 → 묶음 DB를 읽기 전용으로 열어 integrity_check = ok, 핵심 표 건수 = manifest.
     * 결과를 곁의 manifest 사본에 적는다.
     *
     * @return array{ok: bool, code: ?string, ms: int}
     */
    public function verify(string $name): array
    {
        $file = $this->paths->bundle($name) ?? throw new BackupException('bundle_not_found');
        $t0 = hrtime(true);
        $code = null;
        $tmp = $this->paths->dir() . '/.verify-' . bin2hex(random_bytes(6));
        $reader = new BundleReader($file);
        $manifest = null;
        try {
            $manifest = $reader->open();
            $reader->verifyHashes();
            $reader->extract(BundleFormat::DB, $tmp . '/db.sqlite');
            [$counts, $schema] = self::inspectDb($tmp . '/db.sqlite', true);
            if ($counts !== ($manifest['counts'] ?? null) || $schema !== $manifest['schema_version']) {
                throw new BackupException('bundle_counts_mismatch');
            }
        } catch (BackupException $e) {
            $code = $e->errorCode;
        } catch (\Throwable $e) {
            $code = 'bundle_corrupt';
            $this->logger->warn('백업 검증 오류', ['file' => $name, 'error' => $e->getMessage()]);
        } finally {
            $reader->close();
            self::removeTree($tmp);
        }
        $ms = (int) ((hrtime(true) - $t0) / 1e6);
        $at = SystemClock::iso($this->clock->now());
        $side = $this->readSidecar($name);
        $this->writeSidecar($name, $side['manifest'] ?? $manifest, ['ok' => $code === null, 'code' => $code, 'at' => $at], $side['uploaded'] ?? false);
        if ($code !== null) {
            $this->logger->warn('백업 검증 실패', ['file' => $name, 'code' => $code]);
        }
        return ['ok' => $code === null, 'code' => $code, 'ms' => $ms];
    }

    /**
     * 목록(최신순): 폴더의 묶음 파일 + 곁의 manifest 사본(없거나 깨졌으면 "검증 필요").
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $dir = $this->paths->dir();
        $out = [];
        foreach (Fs::listDir($dir) as $name) {
            if (!BundleFormat::validName($name) || !is_file($dir . '/' . $name)) {
                continue;
            }
            $side = $this->readSidecar($name);
            $m = $side['manifest'] ?? null;
            $out[] = [
                'name' => $name,
                'kind' => BundleFormat::kindOf($name),
                'size' => (int) filesize($dir . '/' . $name),
                'created_at' => is_array($m) ? ($m['created_at'] ?? null) : null,
                'app_version' => is_array($m) ? ($m['app_version'] ?? null) : null,
                'schema_version' => is_array($m) ? ($m['schema_version'] ?? null) : null,
                'snapshots' => is_array($m) ? (bool) ($m['options']['snapshots'] ?? false) : null,
                'counts' => is_array($m) ? ($m['counts'] ?? null) : null,
                'host' => is_array($m) ? ($m['host'] ?? null) : null,
                'verified' => $side['verified'] ?? null,
                'uploaded' => (bool) ($side['uploaded'] ?? false),
                'mtime' => (int) filemtime($dir . '/' . $name),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['created_at'] ?? '', $b['mtime']] <=> [$a['created_at'] ?? '', $a['mtime']]);
        return $out;
    }

    /**
     * 보관 정리(일일 백업 성공 뒤): 일반(daily·manual)은 retention_days, 안전 백업(pre_restore·pre_upgrade)은 safety_retention_days가 지난 것.
     * 일반은 최근 retention_min_count개를 남기고, 마지막 성공 백업은 어떤 경우에도 남긴다. 검증 실패·업로드 대기 묶음은 건드리지 않는다.
     *
     * @return list<string> 지운 묶음
     */
    public function prune(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $last = (string) ($this->state()->read()['last_success']['file'] ?? '');
        $keepMin = $this->config->int('backup.retention_min_count');
        $keepMax = $this->config->int('backup.retention_max_count');
        $normal = 0;
        $deleted = [];
        foreach ($this->list() as $b) {
            if (($b['verified']['ok'] ?? false) !== true || $b['created_at'] === null) {
                continue;
            }
            $safety = in_array($b['kind'], ['pre_restore', 'pre_upgrade'], true);
            if (!$safety) {
                $normal++;
            }
            $age = $now - (int) strtotime((string) $b['created_at']);
            $limit = 86400 * $this->config->int($safety ? 'backup.safety_retention_days' : 'backup.retention_days');
            // P27E: 일반 백업 개수 상한(0 = 없음) — 상한을 넘는 오래된 것은 기간 안이어도 지운다. 최소 보관 개수·마지막 성공 보호가 앞선다
            $overMax = !$safety && $keepMax > 0 && $normal > $keepMax;
            if (($age <= $limit && !$overMax) || $b['name'] === $last || (!$safety && $normal <= $keepMin)) {
                continue;
            }
            $this->deleteFiles($b['name']);
            $deleted[] = $b['name'];
        }
        if ($deleted !== []) {
            $this->logger->info('백업 보관 정리', ['deleted' => $deleted]);
        }
        return $deleted;
    }

    /** 수동 삭제(마지막 성공 백업은 삭제 불가) */
    public function delete(string $name): void
    {
        if ($this->paths->bundle($name) === null || !is_file((string) $this->paths->bundle($name))) {
            throw new BackupException('bundle_not_found');
        }
        if (($this->state()->read()['last_success']['file'] ?? null) === $name) {
            throw new BackupException('backup_last_success_protected');
        }
        $this->deleteFiles($name);
    }

    /** @return array{manifest?: array<string, mixed>, verified?: array<string, mixed>|null, uploaded?: bool} */
    public function readSidecar(string $name): array
    {
        $raw = @file_get_contents($this->paths->dir() . '/' . $name . '.json');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }

    public function writeSidecar(string $name, ?array $manifest, ?array $verified, bool $uploaded = false): void
    {
        Fs::atomicWrite($this->paths->dir() . '/' . $name . '.json', (string) json_encode(
            ['manifest' => $manifest, 'verified' => $verified, 'uploaded' => $uploaded], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * bin/ 체크섬 목록: 도구/버전 폴더마다 SHA256SUMS·manifest 파일 내용(실행 파일은 넣지 않는다).
     *
     * @return array{tools: list<array{tool: string, version: string}>, files: array<string, string>}
     */
    public function binManifest(): array
    {
        $bin = rtrim(str_replace('\\', '/', $this->dataPaths->bin()), '/');
        $tools = [];
        $files = [];
        foreach (Fs::listDir($bin) as $tool) {
            if (!is_dir("$bin/$tool") || str_starts_with($tool, '.')) {
                continue;
            }
            foreach (Fs::listDir("$bin/$tool") as $version) {
                if (!is_dir("$bin/$tool/$version") || preg_match('/^\d+\.\d+(\.\d+)?$/D', $version) !== 1) {
                    continue;
                }
                $tools[] = ['tool' => $tool, 'version' => $version];
                foreach (Fs::listDir("$bin/$tool/$version") as $f) {
                    if (preg_match('/^(SHA256SUMS.*|manifest.*\.(json|txt|sha256)|VERSION\.txt)$/iD', $f) === 1 && is_file("$bin/$tool/$version/$f") && filesize("$bin/$tool/$version/$f") < 1024 * 1024) {
                        $files["$tool/$version/$f"] = (string) file_get_contents("$bin/$tool/$version/$f");
                    }
                }
            }
        }
        return ['tools' => $tools, 'files' => $files];
    }

    /** 앱이 아는 마지막 마이그레이션 번호(migrations/ 폴더) */
    public function appSchema(): string
    {
        $max = '000';
        foreach (Fs::listDir($this->root . '/migrations') as $f) {
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/D', $f, $m) === 1 && strcmp($m[1], $max) > 0) {
                $max = $m[1];
            }
        }
        return $max;
    }

    /**
     * DB 파일의 핵심 표 건수, 스키마 버전, 마지막 분석 종료 시각. $integrity면 integrity_check = ok가 아니면 예외.
     *
     * @return array{0: array<string, int>, 1: string, 2: ?string}
     */
    public static function inspectDb(string $file, bool $integrity = false): array
    {
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            if ($integrity && (string) $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new BackupException('bundle_db_corrupt');
            }
            $row = $pdo->query('SELECT (SELECT COUNT(*) FROM users), (SELECT COUNT(*) FROM projects), (SELECT COUNT(*) FROM analyses), (SELECT COUNT(*) FROM defects)')
                ->fetch(\PDO::FETCH_NUM);
            $counts = array_combine(self::COUNT_TABLES, array_map('intval', (array) $row));
            $schema = (string) $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            $last = $pdo->query("SELECT MAX(finished_at) FROM analyses WHERE status = 'succeeded'")->fetchColumn();
            return [$counts, $schema, $last === false || $last === null ? null : (string) $last];
        } catch (BackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BackupException('bundle_db_corrupt', [], $e->getMessage());
        } finally {
            $pdo = null;
        }
    }

    /** @return list<string> "<프로젝트>/<분석>/source.zip" */
    public static function snapshotFiles(string $snapDir): array
    {
        $out = [];
        foreach (Fs::listDir($snapDir) as $p) {
            if (preg_match('/^[1-9]\d{0,8}$/D', $p) !== 1 || !is_dir("$snapDir/$p")) {
                continue;
            }
            foreach (Fs::listDir("$snapDir/$p") as $a) {
                if (preg_match('/^[1-9]\d{0,11}$/D', $a) === 1 && is_file("$snapDir/$p/$a/source.zip")) {
                    $out[] = "$p/$a/source.zip";
                }
            }
        }
        sort($out);
        return $out;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (Fs::listDir($dir) as $n) {
            $p = $dir . '/' . $n;
            is_dir($p) && !is_link($p) ? self::removeTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** 백업 폴더 디스크: 여유 < 필요량이면 backup_disk_low, 사용률 ≥ disk.block_percent면 backup_disk_full(19.3 임계 재사용) */
    private function diskCheck(string $dir, int $need): void
    {
        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        if ($free === false || $total === false || $total <= 0) {
            return; // 알 수 없으면(일부 네트워크 드라이브) 쓰기 중 실패로 판단
        }
        if ((($total - $free) / $total) * 100 >= $this->config->int('disk.block_percent')) {
            throw new BackupException('backup_disk_full', ['percent' => (int) round((($total - $free) / $total) * 100)]);
        }
        if ($free < $need) {
            throw new BackupException('backup_disk_low', ['need_mb' => (int) ceil($need / 1048576), 'free_mb' => (int) floor($free / 1048576)]);
        }
    }

    private function newName(string $dir, string $kind, \DateTimeImmutable $now): string
    {
        $stamp = $now->setTimezone(new \DateTimeZone((string) $this->config->get('app.display_timezone')))->format('Ymd-His');
        for ($i = 1; $i < 1000; $i++) {
            $name = "pjguardian-{$stamp}-{$kind}" . ($i === 1 ? '' : "-{$i}") . BundleFormat::EXT;
            if (!file_exists($dir . '/' . $name)) {
                return $name;
            }
        }
        throw new BackupException('backup_io_failed', [], '이름 충돌');
    }

    private function deleteFiles(string $name): void
    {
        $dir = $this->paths->dir();
        @unlink($dir . '/' . $name);
        @unlink($dir . '/' . $name . '.json');
    }
}
