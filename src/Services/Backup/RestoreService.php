<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Settings\SettingsStore;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\Paths;
use PJGuardian\Support\SystemClock;

/**
 * 복원(P27B, 명세 14·19.4). **웹 요청 안에서 하지 않는다** — tools/restore.php(워커가 자식으로 실행하거나 명령줄)가 execute()를 부른다.
 * 웹은 precheck()(변경 없음)만 하고 요청 파일을 쓴다.
 *
 * execute 단계(각 단계를 <work>/run/restore_state.json에 기록 — DB가 바뀌는 중이라 파일):
 *   precheck → maintenance(플래그) → pre_restore_backup(실패하면 시작하지 않음) → replace_db(같은 볼륨 임시 이름으로 풀기·integrity_check·
 *   지금 DB는 체크포인트 뒤 .before_restore로 옮기고 -wal·-shm 제거·원자적 교체) → replace_snapshots(폴더 단위, 지금 것은 .before_restore)
 *   → migrate(묶음 스키마 < 앱이면) → finalize(모든 세션 무효, 복원된 DB의 감사 로그에 restore.finish, 고른 설정 적용, 가져온 키 적용)
 *   → 끝. replace_db 이후 어디서든 실패하면 자동 롤백(.before_restore 되돌림, restore.rollback 기록) — 롤백도 실패하면 수동 복구 절차를 남긴다.
 * 유지보수 플래그는 finally에서 끈다.
 */
final class RestoreService
{
    public const STEPS = ['precheck', 'maintenance', 'pre_restore_backup', 'replace_db', 'replace_snapshots', 'migrate', 'finalize'];

    public function __construct(
        private readonly Config $config,
        private readonly BackupService $backups,
        private readonly Paths $dataPaths,
        private readonly string $root,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly MaintenanceMode $maintenance,
        private readonly string $secretsFile,
        // 시험 전용 장애 주입: 단계 이름(replaced_db·replaced_snapshots·migrate·finalize)으로 불린다 — 예외를 던지면 그 지점에서 실패
        private readonly ?\Closure $fault = null,
    ) {
    }

    /**
     * 강제 종료된 복원(상태 running인데 그 프로세스가 없음)을 되돌린다 — 워커 시작·명령줄 복원 시작 때 부른다.
     * 이번 복원이 옮긴 .before_restore(복원 시작 이후 시각)만 되돌리고, 유지보수 플래그를 끈다.
     *
     * @return bool 되돌렸으면 true
     */
    public function recoverInterrupted(): bool
    {
        $file = new RestoreStateFile($this->stateFile(), $this->clock);
        $s = $file->read();
        if (($s['status'] ?? null) !== 'running') {
            return false;
        }
        $pid = (int) ($s['pid'] ?? 0);
        if ($pid > 0 && $pid !== (int) getmypid() && \PJGuardian\Services\Process\ProcessTree::isAlive($pid)) {
            return false;
        }
        $since = (int) strtotime((string) ($s['started_at'] ?? 'now')) - 2;
        $mine = static fn (string $p): bool => file_exists($p) && (int) filemtime($p) >= $since;
        $dbFile = $this->dataPaths->dbFile();
        $snapDir = rtrim(str_replace('\\', '/', $this->dataPaths->snapshots()), '/');
        $manual = [];
        $ok = true;
        $changed = false;
        try {
            foreach ([$this->config->iniPath(), $this->config->localPath(), $this->secretsFile] as $cfg) {
                if ($cfg !== null && $mine($cfg . '.before_restore') && ($s['step'] ?? '') === 'finalize') {
                    Fs::atomicWrite($cfg, (string) file_get_contents($cfg . '.before_restore'));
                    $changed = true;
                }
            }
            @unlink($this->pendingKeyFile());
            if ($mine($dbFile . '.before_restore') && in_array($s['step'] ?? '', ['replace_db', 'replace_snapshots', 'migrate', 'finalize'], true)) {
                foreach (['', '-wal', '-shm'] as $x) {
                    if (is_file($dbFile . $x)) {
                        @rename($dbFile . $x, $dbFile . '.failed_restore' . $x);
                    }
                }
                self::renameRetry($dbFile . '.before_restore', $dbFile);
                $changed = true;
            }
            if (is_dir($snapDir . '.before_restore') && $mine($snapDir . '.before_restore') && in_array($s['step'] ?? '', ['replace_snapshots', 'migrate', 'finalize'], true)) {
                if (is_dir($snapDir)) {
                    self::renameRetry($snapDir, $snapDir . '.failed_restore_' . bin2hex(random_bytes(3)));
                }
                self::renameRetry($snapDir . '.before_restore', $snapDir);
                $changed = true;
            }
            BackupService::removeTree($snapDir . '.restore_new');
            @unlink($dbFile . '.restore_new');
        } catch (\Throwable $e) {
            $ok = false;
            $manual[] = ['move' => self::rel($dbFile . '.before_restore', $this->root), 'to' => self::rel($dbFile, $this->root)];
            $this->logger->error('중단된 복원 되돌리기 실패', ['error' => $e->getMessage()]);
        }
        $file->finish($ok ? ($changed ? 'rolled_back' : 'failed') : 'rollback_failed', ['code' => 'restore_interrupted', 'params' => []], (array) ($s['result'] ?? []), $manual);
        $this->maintenance->leave();
        $this->logger->warn('중단된 복원을 정리함', ['step' => $s['step'] ?? null, 'rolled_back' => $changed, 'ok' => $ok]);
        return true;
    }

    public function stateFile(): string
    {
        return $this->backups->paths()->restoreState();
    }

    /** 키 가져오기로 받은 키를 복원 끝까지 두는 파일(secrets.ini와 같은 폴더·같은 보호, 끝나면 지움) */
    public function pendingKeyFile(): string
    {
        return $this->secretsFile . '.restore_pending';
    }

    /**
     * 사전 검증 + 미리보기. **현재 데이터를 바꾸지 않는다**(임시 폴더만).
     *
     * @return array<string, mixed>
     */
    public function precheck(string $bundleFile): array
    {
        $tmp = $this->backups->paths()->runDir() . '/precheck-' . bin2hex(random_bytes(6));
        $reader = new BundleReader($bundleFile);
        try {
            $m = $reader->open();
            $reader->verifyHashes();
            $reader->extract(BundleFormat::DB, $tmp . '/db.sqlite');
            [$counts, $schema, $lastAnalysis] = BackupService::inspectDb($tmp . '/db.sqlite', true);
            // 검증(BackupService::verify)과 같은 대조: 묶음 DB의 건수·스키마 = manifest(manifest만 고친 변조)
            if ($counts !== ($m['counts'] ?? null) || $schema !== ($m['schema_version'] ?? null)) {
                throw new BackupException('bundle_counts_mismatch');
            }
            $app = $this->backups->appSchema();
            if (strcmp($schema, $app) > 0) {
                throw new BackupException('restore_schema_newer', ['bundle' => $schema, 'app' => $app]);
            }
            $dbFile = $this->dataPaths->dbFile();
            $snapDir = rtrim(str_replace('\\', '/', $this->dataPaths->snapshots()), '/');
            $current = null;
            $loss = null;
            if (is_file($dbFile) && filesize($dbFile) > 0) {
                try {
                    [$cc, $cs] = BackupService::inspectDb($dbFile);
                    $current = ['counts' => $cc, 'schema_version' => $cs];
                    $loss = $this->loss($dbFile, (string) ($m['created_at'] ?? ''));
                } catch (BackupException) {
                    $current = null; // 지금 DB를 읽을 수 없음(빈 설치 등) — 미리보기에서 "없음"
                }
            }
            $snapBytes = array_sum(array_map(static fn (string $f): int => (int) @filesize($snapDir . '/' . $f), BackupService::snapshotFiles($snapDir)));
            $need = $reader->totalSize() + (int) @filesize($dbFile) + $snapBytes + 64 * 1024 * 1024;
            $free = @disk_free_space(dirname($dbFile));
            if ($free !== false && $free < $need) {
                throw new BackupException('restore_disk_low', ['need_mb' => (int) ceil($need / 1048576), 'free_mb' => (int) floor($free / 1048576)]);
            }
            $keys = [$this->config->secret('encryption_key'), $this->config->secret('encryption_key_previous')];
            $keyTest = KeyVault::testAgainstDb($keys, $tmp . '/db.sqlite');
            return [
                'bundle' => basename($bundleFile),
                'created_at' => $m['created_at'] ?? null, 'created_at_local' => $m['created_at_local'] ?? null, 'host' => $m['host'] ?? null, 'os' => $m['os'] ?? null,
                'app_version' => $m['app_version'] ?? null, 'schema_version' => $schema, 'app_schema' => $app, 'migrate' => strcmp($schema, $app) < 0,
                'counts' => $counts, 'current' => $current, 'loss' => $loss, 'last_analysis_at' => $lastAnalysis,
                'snapshots' => (bool) ($m['options']['snapshots'] ?? false), 'snapshot_count' => count(array_filter($reader->names(), static fn (string $n): bool => str_starts_with($n, 'snapshots/'))),
                'blanked' => $m['blanked'] ?? [], 'bin_tools' => $m['bin_tools'] ?? [],
                'config' => $this->configDiff($reader),
                // 지금 키로 묶음 DB의 자격 증명을 풀 수 있는가: ok | mismatch(키 가져오기 필요, 없으면 연동만 "다시 입력") | none(자격 증명 없음)
                'key' => $keyTest === null ? 'none' : ($keyTest ? 'ok' : 'mismatch'),
                'size' => (int) filesize($bundleFile), 'need_mb' => (int) ceil($need / 1048576),
            ];
        } finally {
            $reader->close();
            BackupService::removeTree($tmp);
        }
    }

    /**
     * 복원 리허설(명세 19.4 분기 1회): 사전 검증 + 묶음 DB를 임시 폴더에 풀어 마이그레이션 시험 + 핵심 화면이 쓰는 질의 몇 개.
     * **운영 데이터는 건드리지 않는다**(임시 폴더만, 끝나면 지움).
     *
     * @return array<string, mixed> 결과 요약(건수·스키마·질의 시간)
     */
    public function rehearse(string $bundleFile): array
    {
        $pre = $this->precheck($bundleFile);
        $tmp = $this->backups->paths()->runDir() . '/rehearsal-' . bin2hex(random_bytes(6));
        $reader = new BundleReader($bundleFile);
        try {
            $reader->open();
            $reader->extract(BundleFormat::DB, $tmp . '/db.sqlite');
            $reader->close();
            $db = new Db($tmp . '/db.sqlite');
            $migrated = [];
            if ($pre['migrate']) {
                $migrated = array_map(static fn ($v): string => is_array($v) ? (string) ($v['version'] ?? '') : (string) $v,
                    (array) (new Migrator($db, $this->root . '/migrations', $this->clock))->migrate());
            }
            $t = hrtime(true);
            // 프로젝트 목록·결함 목록 첫 쪽·분석 이력·사용자 목록·감사 로그 끝(화면이 쓰는 표가 읽히는지)
            $db->all('SELECT id, name, language FROM projects ORDER BY name LIMIT 50');
            $pid = (int) ($db->value('SELECT id FROM projects ORDER BY id LIMIT 1') ?? 0);
            $db->all("SELECT id, severity, status, rule_key, file_path, line_no FROM defects WHERE project_id = ? AND status <> 'resolved' ORDER BY file_path, line_no LIMIT 50", [$pid]);
            $db->all('SELECT id, status, finished_at FROM analyses WHERE project_id = ? ORDER BY id DESC LIMIT 20', [$pid]);
            $db->all('SELECT id, login_id, role, status FROM users ORDER BY login_id LIMIT 50');
            $db->value('SELECT MAX(id) FROM audit_log');
            $ms = (int) ((hrtime(true) - $t) / 1e6);
            $integrity = (string) $db->value('PRAGMA integrity_check');
            $db = null;
            if ($integrity !== 'ok') {
                throw new BackupException('bundle_db_corrupt');
            }
            return ['schema' => $pre['schema_version'], 'migrated' => $migrated, 'counts' => $pre['counts'], 'queries_ms' => $ms];
        } finally {
            $reader->close();
            $db = null;
            BackupService::removeTree($tmp);
        }
    }

    /** 키 가져오기 시험: 묶음 DB의 자격 증명을 가져온 키로 실제로 풀어 본다(null = 자격 증명 없음) @param array{encryption_key: string, encryption_key_previous: ?string} $keys */
    public function testKeyAgainstBundle(string $bundleFile, array $keys): ?bool
    {
        $tmp = $this->backups->paths()->runDir() . '/keytest-' . bin2hex(random_bytes(6));
        $reader = new BundleReader($bundleFile);
        try {
            $reader->open();
            $reader->extract(BundleFormat::DB, $tmp . '/db.sqlite');
            return KeyVault::testAgainstDb([$keys['encryption_key'], $keys['encryption_key_previous']], $tmp . '/db.sqlite');
        } finally {
            $reader->close();
            BackupService::removeTree($tmp);
        }
    }

    /**
     * 복원 실행(별도 프로세스). 실패는 예외 대신 상태 파일·반환값으로 알린다.
     *
     * @param array{bundle: string, by: string, config_keys?: list<string>, local_keys?: list<string>, pre_backup?: bool} $req bundle = 묶음 파일 절대 경로
     * @return array<string, mixed> 최종 상태
     */
    public function execute(array $req): array
    {
        $state = new RestoreStateFile($this->stateFile(), $this->clock);
        $state->start(basename($req['bundle']), $req['by']);
        $dbFile = $this->dataPaths->dbFile();
        $snapDir = rtrim(str_replace('\\', '/', $this->dataPaths->snapshots()), '/');
        $undo = [];   // 롤백 동작(마지막부터)
        $manual = []; // 롤백 실패 시 사람이 할 일
        $result = [];
        $replaced = false;
        try {
            $state->step('precheck');
            $pre = $this->precheck($req['bundle']);
            $result['from_schema'] = $pre['schema_version'];
            $state->step('maintenance');
            $this->maintenance->enter('restore', $req['by']);
            $state->step('pre_restore_backup');
            if (($req['pre_backup'] ?? true) && is_file($dbFile) && filesize($dbFile) > 0) {
                $b = $this->backups->create('pre_restore', true, $req['by']);
                $result['pre_restore_backup'] = $b['name'];
            } else {
                $state->skip('pre_restore_backup');
            }
            // ---- 여기부터 바꾼다: 실패하면 롤백 ----
            $state->step('replace_db');
            $replaced = true;
            $undo = array_merge($undo, $this->replaceDb($req['bundle'], $dbFile, $manual));
            $this->fault?->__invoke('replaced_db');
            $state->step('replace_snapshots');
            if ($pre['snapshot_count'] > 0) {
                $undo = array_merge($undo, $this->replaceSnapshots($req['bundle'], $snapDir, $manual));
                $this->fault?->__invoke('replaced_snapshots');
            } else {
                $state->skip('replace_snapshots');
            }
            $state->step('migrate');
            $this->fault?->__invoke('migrate');
            if ($pre['migrate']) {
                $applied = (new Migrator(new Db($dbFile), $this->root . '/migrations', $this->clock))->migrate();
                $result['migrated'] = array_values(array_map(static fn ($v): string => is_array($v) ? (string) ($v['version'] ?? '') : (string) $v, (array) $applied));
            } else {
                $state->skip('migrate');
            }
            $state->step('finalize');
            $undo = array_merge($undo, $this->finalize($req, $dbFile, $result, $manual));
            $this->fault?->__invoke('finalize');
            // P31: DB가 백업 시점으로 돌아갔으므로 감사 로그 DB 밖 기록을 옆으로 옮기고 새로 시작(옛 기록과 대조하면 불일치로 보임)
            \PJGuardian\Services\Audit\AuditAnchor::rotateAfterRestore($this->dataPaths->logs(), $this->clock);
            $state->finish('succeeded', null, $result);
            $this->logger->info('복원 완료', ['bundle' => basename($req['bundle']), 'by' => $req['by']] + array_diff_key($result, ['config' => 1]));
        } catch (\Throwable $e) {
            $code = $e instanceof BackupException ? $e->errorCode : 'restore_failed';
            $params = $e instanceof BackupException ? $e->params : [];
            $this->logger->warn('복원 실패', ['bundle' => basename($req['bundle']), 'code' => $code, 'error' => $e->getMessage()]);
            @unlink($this->pendingKeyFile());
            if (!$replaced) {
                $state->finish('failed', ['code' => $code, 'params' => $params], $result);
            } else {
                $rollbackOk = true;
                foreach (array_reverse($undo) as $u) {
                    try {
                        $u();
                    } catch (\Throwable $re) {
                        $rollbackOk = false;
                        $this->logger->error('복원 롤백 단계 실패', ['error' => $re->getMessage()]);
                    }
                }
                if ($rollbackOk) {
                    $this->auditRollback($dbFile, $req['by'], $code);
                }
                $state->finish($rollbackOk ? 'rolled_back' : 'rollback_failed', ['code' => $code, 'params' => $params], $result, $rollbackOk ? [] : $manual);
            }
        } finally {
            $this->maintenance->leave();
        }
        return $state->read();
    }

    /**
     * 키 없이(또는 키로) 복원한 DB에서 지금 키로 풀 수 없는 저장소 연결 수
     */
    public function unreadableCredentials(string $dbFile): int
    {
        $c = Config::load((string) ($this->config->iniPath() ?? $this->root . '/config/config.ini'), $this->secretsFile, getenv());
        return KeyVault::unreadableCount([$c->secret('encryption_key'), $c->secret('encryption_key_previous')], $dbFile);
    }

    /** @return list<\Closure(): void> 되돌리기 */
    private function replaceDb(string $bundle, string $dbFile, array &$manual): array
    {
        $new = $dbFile . '.restore_new';
        $before = $dbFile . '.before_restore';
        @unlink($new);
        $reader = new BundleReader($bundle);
        try {
            $reader->open();
            $reader->extract(BundleFormat::DB, $new);
        } finally {
            $reader->close();
        }
        BackupService::inspectDb($new, true);
        $undo = [];
        if (is_file($dbFile)) {
            // 지금 DB의 WAL을 본 파일로 합친 뒤(롤백 때 그대로 되돌릴 수 있게) 옮긴다
            $pdo = new \PDO('sqlite:' . $dbFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 30000');
            $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
            $pdo = null;
            if (is_file($before)) {
                @unlink($before);
            }
            self::renameRetry($dbFile, $before);
            $manual[] = ['move' => self::rel($before, $this->root), 'to' => self::rel($dbFile, $this->root)];
            $undo[] = function () use ($dbFile, $before): void {
                foreach (['', '-wal', '-shm'] as $s) {
                    if (is_file($dbFile . $s)) {
                        @unlink($dbFile . $s);
                    }
                }
                self::renameRetry($before, $dbFile);
            };
        } else {
            $undo[] = static function () use ($dbFile): void {
                foreach (['', '-wal', '-shm'] as $s) {
                    @unlink($dbFile . $s);
                }
            };
        }
        foreach (['-wal', '-shm'] as $s) {
            if (is_file($dbFile . $s) && !@unlink($dbFile . $s)) {
                throw new BackupException('restore_io_failed', [], 'WAL 파일 제거 실패');
            }
        }
        self::renameRetry($new, $dbFile);
        return $undo;
    }

    /** @return list<\Closure(): void> */
    private function replaceSnapshots(string $bundle, string $snapDir, array &$manual): array
    {
        $new = $snapDir . '.restore_new';
        $before = $snapDir . '.before_restore';
        BackupService::removeTree($new);
        $reader = new BundleReader($bundle);
        try {
            $reader->open();
            foreach ($reader->names() as $n) {
                if (str_starts_with($n, 'snapshots/')) {
                    $reader->extract($n, $new . '/' . substr($n, strlen('snapshots/')));
                }
            }
        } finally {
            $reader->close();
        }
        @file_put_contents($new . '/.gitkeep', '');
        BackupService::removeTree($before);
        $undo = [];
        if (is_dir($snapDir)) {
            self::renameRetry($snapDir, $before);
            $manual[] = ['move' => self::rel($before, $this->root), 'to' => self::rel($snapDir, $this->root)];
            $undo[] = static function () use ($snapDir, $before): void {
                if (is_dir($snapDir)) {
                    self::renameRetry($snapDir, $snapDir . '.failed_restore_' . bin2hex(random_bytes(3)));
                }
                self::renameRetry($before, $snapDir);
            };
        }
        self::renameRetry($new, $snapDir);
        return $undo;
    }

    /**
     * 모든 세션 무효(복원된 DB의 세션은 과거 것), restore.finish 감사, 고른 설정 적용(.before_restore 보관), 가져온 키 적용.
     *
     * @param array<string, mixed> $result
     * @return list<\Closure(): void>
     */
    private function finalize(array $req, string $dbFile, array &$result, array &$manual): array
    {
        $undo = [];
        $db = new Db($dbFile);
        $result['sessions_cleared'] = $db->execute('DELETE FROM sessions');
        // 설정(선택): settings.local.ini는 P27A 저장소로만, config.ini는 .before_restore 보관 후 고른 키만 바꾼다
        $applied = 0;
        $localKeys = array_values(array_filter((array) ($req['local_keys'] ?? []), 'is_string'));
        $configKeys = array_values(array_filter((array) ($req['config_keys'] ?? []), 'is_string'));
        if ($localKeys !== [] || $configKeys !== []) {
            $reader = new BundleReader($req['bundle']);
            try {
                $reader->open();
                $tmp = $this->backups->paths()->runDir() . '/cfg-' . bin2hex(random_bytes(4));
                $bundleLocal = in_array(BundleFormat::LOCAL, $reader->names(), true) ? ConfigSanitizer::flatten((string) $this->readEntry($reader, BundleFormat::LOCAL, $tmp)) : [];
                $bundleConfig = in_array(BundleFormat::CONFIG, $reader->names(), true) ? ConfigSanitizer::flatten((string) $this->readEntry($reader, BundleFormat::CONFIG, $tmp)) : [];
                BackupService::removeTree($tmp);
            } finally {
                $reader->close();
            }
            $localPath = $this->config->localPath();
            if ($localKeys !== [] && $localPath !== null) {
                $store = new SettingsStore($localPath);
                $before = $store->read();
                $changes = [];
                foreach ($localKeys as $k) {
                    if (isset($bundleLocal[$k]) && $this->config->registry()->has($k) && !BundleFormat::sensitiveKey(substr($k, (int) strrpos($k, '.') + 1))) {
                        $changes[$k] = $bundleLocal[$k];
                    }
                }
                if ($changes !== []) {
                    if (is_file($localPath)) {
                        @copy($localPath, $localPath . '.before_restore');
                    }
                    $store->write($changes, $before['version']);
                    $applied += count($changes);
                    $undo[] = static function () use ($localPath): void {
                        if (is_file($localPath . '.before_restore')) {
                            Fs::atomicWrite($localPath, (string) file_get_contents($localPath . '.before_restore'));
                        } else {
                            @unlink($localPath);
                        }
                    };
                }
            }
            $ini = $this->config->iniPath();
            if ($configKeys !== [] && $ini !== null && is_file($ini)) {
                $text = (string) file_get_contents($ini);
                $new = $text;
                $n = 0;
                foreach ($configKeys as $k) {
                    if (!isset($bundleConfig[$k]) || BundleFormat::sensitiveKey(substr($k, (int) strrpos($k, '.') + 1)) || preg_match('/["\x00-\x1f\x7f]/', $bundleConfig[$k]) === 1) {
                        continue;
                    }
                    $new = self::setIniValue($new, $k, $bundleConfig[$k]);
                    $n++;
                }
                if ($n > 0) {
                    copy($ini, $ini . '.before_restore');
                    Fs::atomicWrite($ini, $new);
                    $undo[] = static function () use ($ini): void {
                        Fs::atomicWrite($ini, (string) file_get_contents($ini . '.before_restore'));
                    };
                    $manual[] = ['move' => self::rel($ini . '.before_restore', $this->root), 'to' => self::rel($ini, $this->root)];
                    try {
                        Config::load($ini, $this->secretsFile, []); // 적용한 설정이 기동 검사를 통과하는지
                    } catch (\Throwable) {
                        throw new BackupException('restore_config_invalid');
                    }
                    $applied += $n;
                }
            }
        }
        $result['config_applied'] = $applied;
        // 가져온 키(복호화 시험을 통과한 것만 pending 파일로 온다)
        $pending = $this->pendingKeyFile();
        $result['key_imported'] = false;
        if (is_file($pending)) {
            $keys = json_decode((string) file_get_contents($pending), true);
            @unlink($pending);
            if (is_array($keys) && is_string($keys['encryption_key'] ?? null)) {
                $secrets = $this->secretsFile;
                $hadSecrets = is_file($secrets);
                KeyVault::writeSecrets($secrets, ['encryption_key' => $keys['encryption_key'], 'encryption_key_previous' => $keys['encryption_key_previous'] ?? null]);
                $result['key_imported'] = true;
                $undo[] = static function () use ($secrets, $hadSecrets): void {
                    if ($hadSecrets && is_file($secrets . '.before_restore')) {
                        Fs::atomicWrite($secrets, (string) file_get_contents($secrets . '.before_restore'));
                    } elseif (!$hadSecrets) {
                        @unlink($secrets);
                    }
                };
            }
        }
        $result['credentials_unreadable'] = $this->unreadableCredentials($dbFile);
        $result['counts'] = BackupService::inspectDb($dbFile)[0];
        // 복원된 DB에는 복원 사실이 없으므로 새로 남긴다(행위자는 복원 전 사용자 — 복원된 DB에 없을 수 있어 id 없이 이름만)
        (new AuditLog($db, $this->clock))->record(Actor::labeled('restore:' . mb_substr($req['by'], 0, 60)), 'restore.finish', 'backup', basename($req['bundle']),
            ['schema_from' => $result['from_schema'] ?? null, 'migrated' => $result['migrated'] ?? [], 'config_applied' => $applied, 'key_imported' => $result['key_imported'],
                'credentials_unreadable' => $result['credentials_unreadable'], 'sessions_cleared' => $result['sessions_cleared']]);
        $db = null;
        return $undo;
    }

    /** 롤백 뒤(복원 전 DB로 돌아온 상태) 감사 restore.rollback */
    private function auditRollback(string $dbFile, string $by, string $code): void
    {
        try {
            if (is_file($dbFile)) {
                (new AuditLog(new Db($dbFile), $this->clock))->record(Actor::labeled('restore:' . mb_substr($by, 0, 60)), 'restore.rollback', 'backup', null, ['code' => $code]);
            }
        } catch (\Throwable $e) {
            $this->logger->warn('restore.rollback 감사 기록 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 설정 차이(유효 값 기준이 아니라 파일 값 — settings.local.ini는 화면 설정 값, config.ini는 파일 값). 민감 키는 목록에 넣지 않는다.
     *
     * @return list<array{file: string, key: string, current: ?string, backup: string, machine: bool}>
     */
    private function configDiff(BundleReader $reader): array
    {
        $tmp = $this->backups->paths()->runDir() . '/diff-' . bin2hex(random_bytes(4));
        $out = [];
        try {
            $names = $reader->names();
            $machine = (array) $this->config->get('backup.config_machine_keys');
            $isMachine = static function (string $key) use ($machine): bool {
                foreach ($machine as $p) {
                    if ($p === $key || (str_ends_with($p, '.*') && str_starts_with($key, substr($p, 0, -1)))) {
                        return true;
                    }
                }
                return false;
            };
            if (in_array(BundleFormat::LOCAL, $names, true)) {
                $local = $this->config->localPath();
                $cur = $local !== null && is_file($local) ? ConfigSanitizer::flatten((string) file_get_contents($local)) : [];
                foreach (ConfigSanitizer::flatten((string) $this->readEntry($reader, BundleFormat::LOCAL, $tmp)) as $k => $v) {
                    if (BundleFormat::sensitiveKey(substr($k, (int) strrpos($k, '.') + 1)) || !$this->config->registry()->has($k)) {
                        continue;
                    }
                    $now = $cur[$k] ?? Config::display($this->config->get($k), $this->config->registry()->get($k)['spec']);
                    if ($now !== $v) {
                        $out[] = ['file' => 'local', 'key' => $k, 'current' => $now, 'backup' => $v,
                            'machine' => $this->config->registry()->get($k)['machine_specific'] || $isMachine($k)];
                    }
                }
            }
            if (in_array(BundleFormat::CONFIG, $names, true)) {
                $ini = $this->config->iniPath();
                $cur = $ini !== null && is_file($ini) ? ConfigSanitizer::flatten((string) file_get_contents($ini)) : [];
                foreach (ConfigSanitizer::flatten((string) $this->readEntry($reader, BundleFormat::CONFIG, $tmp)) as $k => $v) {
                    if (BundleFormat::sensitiveKey(substr($k, (int) strrpos($k, '.') + 1)) || ($cur[$k] ?? null) === $v) {
                        continue;
                    }
                    $out[] = ['file' => 'config', 'key' => $k, 'current' => $cur[$k] ?? null, 'backup' => $v, 'machine' => $isMachine($k)];
                }
            }
        } finally {
            BackupService::removeTree($tmp);
        }
        return $out;
    }

    private function readEntry(BundleReader $reader, string $name, string $tmp): string
    {
        $f = $tmp . '/' . bin2hex(random_bytes(4));
        $reader->extract($name, $f);
        $s = (string) file_get_contents($f);
        @unlink($f);
        return $s;
    }

    /** 이 백업 시각 이후의 변경(지금 DB 기준): 분석·결함 이력·계정 변경 수 @return array{analyses: int, defect_changes: int, accounts: int}|null */
    private function loss(string $dbFile, string $since): ?array
    {
        if ($since === '') {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:' . $dbFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $q = static function (string $sql) use ($pdo, $since): int {
                $s = $pdo->prepare($sql);
                $s->execute([$since]);
                return (int) $s->fetchColumn();
            };
            return [
                'analyses' => $q('SELECT COUNT(*) FROM analyses WHERE queued_at > ?'),
                'defect_changes' => $q('SELECT COUNT(*) FROM defect_history WHERE changed_at > ?'),
                'accounts' => $q('SELECT COUNT(*) FROM users WHERE updated_at > ?'),
            ];
        } catch (\Throwable) {
            return null;
        } finally {
            $pdo = null;
        }
    }

    /** INI 원문에서 "섹션.키" 값을 바꾸거나(없으면 섹션 끝에) 더한다 */
    public static function setIniValue(string $ini, string $name, string $value): string
    {
        [$section, $key] = explode('.', $name, 2);
        $lines = preg_split('/\r\n|\n|\r/', $ini) ?: [];
        $cur = '';
        $sectionEnd = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m) === 1) {
                if ($cur === $section) {
                    $sectionEnd = $i;
                }
                $cur = trim($m[1]);
                continue;
            }
            if ($cur === $section && preg_match('/^(\s*)' . preg_quote($key, '/') . '(\s*=\s*)/', $line, $m) === 1) {
                $lines[$i] = $m[1] . $key . $m[2] . '"' . $value . '"';
                return implode("\n", $lines);
            }
        }
        if ($cur === $section) {
            $lines[] = $key . ' = "' . $value . '"';
        } elseif ($sectionEnd !== null) {
            array_splice($lines, $sectionEnd, 0, [$key . ' = "' . $value . '"']);
        } else {
            $lines[] = '';
            $lines[] = '[' . $section . ']';
            $lines[] = $key . ' = "' . $value . '"';
        }
        return implode("\n", $lines);
    }

    /** Windows에서 다른 프로세스가 잠깐 연 파일은 이름 바꾸기가 실패한다 — 짧게 여러 번(최대 약 30초) */
    public static function renameRetry(string $from, string $to): void
    {
        for ($i = 0; $i < 150; $i++) {
            if (@rename($from, $to)) {
                return;
            }
            usleep(200_000);
        }
        throw new BackupException('restore_io_failed', [], '이름 바꾸기 실패');
    }

    private static function rel(string $path, string $root): string
    {
        $p = str_replace('\\', '/', $path);
        $r = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with(strtolower($p), strtolower($r)) ? substr($p, strlen($r)) : basename($p);
    }
}
