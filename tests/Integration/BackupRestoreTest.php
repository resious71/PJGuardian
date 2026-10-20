<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Backup\BackupException;
use PJGuardian\Services\Backup\BackupService;
use PJGuardian\Services\Backup\BundleFormat;
use PJGuardian\Services\Backup\KeyVault;
use PJGuardian\Services\Backup\RestoreService;
use PJGuardian\Services\Backup\RestoreStateFile;
use PJGuardian\Services\Repo\SecretBox;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 백업·복원(P27B). 격리 설치본(임시 data 폴더·파일 DB·config.ini·secrets.ini)과 프로젝트 밖 임시 백업 폴더로 시험한다(실제 data/·config/·backups/ 안 씀).
 *   - 백업: 묶음 구성(허용 항목만, -wal/-shm 없음), 민감 값·키 없음, 쓰기 중 일관성, 스냅샷 켬/끔·도중 소실, 폴더·디스크 거부, .gitignore
 *   - 보관 정리(시계 주입), 일일 예약·재시도·실패 알림 1시간 한 번, 리허설 기한 알림 한 번
 *   - 사전 검증 실패(손상 zip·manifest 변조·해시 불일치·허용 밖 항목·경로 이탈·더 새 스키마)는 데이터를 바꾸지 않음
 *   - 복원 성공(세션 무효·직전 백업·마이그레이션 없음/있음)·장애 주입 롤백·중단 복구, 설정 선택 복원(PC 고유 기본 해제)·키 가져오기·키 없는 복원
 *   - HTTP: admin만, 비밀번호 재입력·확인 문구, 일회용 내려받기, 마지막 성공 보호, 올린 묶음은 검증 뒤에만 복원, 유지보수 503·독립 상태 페이지
 */
final class BackupRestoreTest extends TestCase
{
    private const T0 = '2026-10-03T03:00:00Z'; // KST 12:00(TestDb 사용자 2026-10-02 뒤)(일일 시각 02:30 지남). 실제 시각보다 앞이라 파일 mtime 비교(중단 복구)가 성립

    private static ?string $template = null;
    private string $dir;
    private string $bk;
    private string $ini;
    private string $db;
    private string $snap;
    private string $k2;
    private FixedClock $clock;

    public function setUp(): void
    {
        $this->dir = $this->tempDir('bkr');
        $this->bk = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/pjg_bkt_' . bin2hex(random_bytes(5));
        $this->db = $this->dir . '/db/pjguardian.sqlite';
        $this->snap = $this->dir . '/snapshots';
        @mkdir(dirname($this->db), 0700, true);
        if (self::$template === null) {
            // 마이그레이션한 파일 DB(Windows에서 새로 만들면 약 1초)를 한 번만 만들어 시험마다 복사한다. 러너의 임시 폴더는 시험마다 지워지므로 시스템 임시 폴더에
            self::$template = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/pjg_bkt_tpl_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sqlite';
            $tpl = TestDb::migrated(self::$template);
            TestDb::user($tpl, 'admin', 'admin');
            TestDb::user($tpl, 'kim', 'user');
            $tpl = null;
            gc_collect_cycles();
            $file = self::$template;
            register_shutdown_function(static function () use ($file): void {
                foreach (['', '-wal', '-shm'] as $x) {
                    @unlink($file . $x);
                }
            });
        }
        copy(self::$template, $this->db);
        foreach (['1/2', '1/3'] as $p) {
            @mkdir("{$this->snap}/{$p}", 0700, true);
            file_put_contents("{$this->snap}/{$p}/source.zip", str_repeat("snapshot {$p}\n", 50));
        }
        $this->ini = $this->writeIni([]);
        $this->k2 = base64_encode(random_bytes(32));
        file_put_contents($this->dir . '/secrets.ini', "encryption_key = \"{$this->k2}\"\nsmtp_password = \"marker-smtp-secret\"\n");
        $this->clock = new FixedClock(self::T0);
    }

    public function tearDown(): void
    {
        BackupService::removeTree($this->bk);
        BackupService::removeTree($this->bk . '_cfg');
    }

    /** @param array<string, array<string, string>> $extra */
    private function writeIni(array $extra): string
    {
        return TestApp::writeIni($this->dir . '/config.ini', array_replace_recursive([
            'paths' => ['db_file' => $this->db, 'work_dir' => $this->dir . '/work', 'logs_dir' => $this->dir . '/logs', 'uploads_dir' => $this->dir . '/uploads',
                'snapshots_dir' => $this->snap],
            'backup' => ['dir' => $this->bk],
            'auth' => ['bcrypt_cost' => '4'],
        ], $extra));
    }

    private function app(): App
    {
        $config = Config::load($this->ini, $this->dir . '/secrets.ini');
        $paths = new Paths(PJG_ROOT, $config);
        return new App(PJG_ROOT, $config, $this->clock, new Logger($paths->logs(), $this->clock, 'INFO'), $paths);
    }

    private function restore(App $app, ?\Closure $fault = null): RestoreService
    {
        return new RestoreService($app->config, $app->backups(), $app->paths, PJG_ROOT, $this->clock, $app->logger, $app->maintenance(), $app->secretsFile(), $fault);
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->db, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function count(string $table): int
    {
        $p = $this->pdo();
        $n = (int) $p->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $p = null;
        return $n;
    }

    /** 묶음의 모든 항목 내용(압축 해제) */
    private function entries(string $bundle): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($bundle) === true);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $out;
    }

    private function codeOf(\Closure $f): string
    {
        try {
            $f();
            return 'ok';
        } catch (BackupException $e) {
            return $e->errorCode;
        }
    }

    // ---------------------------------------------------------------- 백업

    public function testBundleHasOnlyAllowedEntriesNoSecretsAndVerifiesItself(): void
    {
        $app = $this->app();
        $r = $app->backups()->create('manual', true, 'admin');
        $file = $this->bk . '/' . $r['name'];
        $this->assertTrue(BundleFormat::validName($r['name']), $r['name']);
        $entries = $this->entries($file);
        foreach (array_keys($entries) as $e) {
            $this->assertTrue($e === BundleFormat::MANIFEST || BundleFormat::allowedEntry($e), "허용 항목: {$e}");
            $this->assertFalse(str_ends_with($e, '-wal') || str_ends_with($e, '-shm'), $e);
        }
        $this->assertTrue(isset($entries['snapshots/1/2/source.zip'], $entries['snapshots/1/3/source.zip'], $entries[BundleFormat::CONFIG], $entries[BundleFormat::BIN]));
        $all = implode("\n", $entries);
        $this->assertNotContains($this->k2, $all, '암호화 키는 묶음 어디에도 없음');
        $this->assertNotContains('marker-smtp-secret', $all, '시크릿 파일 값 없음');
        $this->assertFalse(isset($entries['config/secrets.ini']));
        // manifest 건수 = 묶음 DB, DB 무결성
        $m = json_decode($entries[BundleFormat::MANIFEST], true);
        $tmp = $this->dir . '/check.sqlite';
        file_put_contents($tmp, $entries[BundleFormat::DB]);
        [$counts, $schema] = BackupService::inspectDb($tmp, true);
        $this->assertSame($m['counts'], $counts);
        $this->assertSame(2, $counts['users']);
        $this->assertSame($app->backups()->appSchema(), $schema);
        $this->assertSame('manual', $m['kind']);
        $this->assertSame(hash('sha256', $entries[BundleFormat::DB]), $m['entries'][BundleFormat::DB]['sha256']);
        // 만든 직후 검증 기록, 폴더 .gitignore(묶음이 git에 올라가지 않게), 프로젝트 밖이라 .htaccess 없음
        $this->assertSame(true, $app->backups()->readSidecar($r['name'])['verified']['ok']);
        $this->assertContains('*', (string) file_get_contents($this->bk . '/.gitignore'));
        $this->assertFalse(is_file($this->bk . '/.htaccess'));
        $this->assertSame([$r['name']], array_column($app->backups()->list(), 'name'));
    }

    public function testWritesDuringBackupKeepBundleConsistent(): void
    {
        $app = $this->app();
        $writer = $this->pdo();
        $writer->exec('PRAGMA journal_mode = WAL');
        $insert = $writer->prepare("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES (?, ?, 'user', 'active', 'local', 'x', ?, ?)");
        $n = 0;
        $r = $app->backups()->create('manual', true, 'admin', function (string $step) use ($insert, &$n): void {
            for ($i = 0; $i < 20; $i++) { // 단계마다 쓰기(복사 도중·뒤)
                $insert->execute(['w' . (++$n), 'w', self::T0, self::T0]);
            }
        });
        $writer = null;
        $entries = $this->entries($this->bk . '/' . $r['name']);
        $tmp = $this->dir . '/check.sqlite';
        file_put_contents($tmp, $entries[BundleFormat::DB]);
        [$counts] = BackupService::inspectDb($tmp, true);
        $this->assertSame($r['manifest']['counts'], $counts, '묶음 DB 건수 = manifest(한 시점)');
        $this->assertTrue($counts['users'] >= 2 && $counts['users'] < 2 + $n, "사본 이후의 쓰기는 들어가지 않음({$counts['users']}, 쓰기 {$n})");
        $this->assertTrue($app->backups()->verify($r['name'])['ok']);
    }

    public function testSnapshotOptionAndFileVanishingDuringCopy(): void
    {
        $app = $this->app();
        $off = $app->backups()->create('manual', false, 'admin');
        $this->assertSame([], array_values(array_filter(array_keys($this->entries($this->bk . '/' . $off['name'])), static fn ($e) => str_starts_with($e, 'snapshots/'))));
        $this->assertFalse($off['manifest']['options']['snapshots']);
        $gone = false;
        $on = $app->backups()->create('manual', true, 'admin', function (string $step) use (&$gone): void {
            if ($step === 'snapshots' && !$gone) {
                $gone = true;
                unlink($this->snap . '/1/3/source.zip'); // 정리 작업이 지운 것처럼
            }
        });
        $this->assertSame(['1/3/source.zip'], $on['manifest']['missing_snapshots']);
        $this->assertTrue(isset($this->entries($this->bk . '/' . $on['name'])['snapshots/1/2/source.zip']));
        $this->assertTrue($app->backups()->verify($on['name'])['ok'], '빠진 파일이 있어도 묶음은 일관');
    }

    public function testBackupDirectoryAndDiskChecks(): void
    {
        // 프로젝트 안(data/ 포함)은 PathPolicy가 거부 — 예외는 프로젝트 바로 아래 backups/뿐
        $this->ini = $this->writeIni(['backup' => ['dir' => PJG_ROOT . '/data/work/pjg_bk_forbidden']]);
        $this->assertSame('backup_dir_invalid', $this->codeOf(fn () => $this->app()->backups()->create('manual', true, 'admin')));
        $this->assertFalse(is_dir(PJG_ROOT . '/data/work/pjg_bk_forbidden'));
        $this->ini = $this->writeIni(['backup' => ['dir' => 'backups']]);
        $policy = $this->app()->pathPolicy()->check('backups', false);
        // 금지 위치가 아님(없으면 fail_not_found = 만들 수 있음 — 시험은 실제 프로젝트에 폴더를 만들지 않는다)
        $this->assertTrue(in_array($policy['code'], ['ok', 'warn_same_volume', 'fail_not_found'], true) && ($policy['code'] !== 'fail_not_found' || $policy['can_create'] !== false), '기본 backups/는 허용 — ' . $policy['code']);
        $this->assertTrue($this->app()->backupPaths()->insideProject());
        $this->assertSame('fail_forbidden_location', $this->app()->pathPolicy()->check('data/backups', false)['code'], 'data/ 안은 계속 거부');
        // 디스크 사용률이 차단 기준 이상이면 백업하지 않음
        $this->ini = $this->writeIni(['disk' => ['warn_percent' => '0', 'block_percent' => '1']]);
        $this->assertSame('backup_disk_full', $this->codeOf(fn () => $this->app()->backups()->create('manual', true, 'admin')));
        $this->assertSame([], $this->app()->backups()->list());
    }

    public function testRetentionKeepsRecentMinimumLastSuccessAndUnverified(): void
    {
        $app = $this->app();
        $b = $app->backups();
        $b->paths()->prepare($app->pathPolicy());
        $now = strtotime(self::T0);
        $make = function (string $name, int $daysAgo, bool $verified = true) use ($b, $now): void {
            file_put_contents($this->bk . '/' . $name, 'x');
            $b->writeSidecar($name, ['created_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $daysAgo * 86400)], $verified ? ['ok' => true] : null);
        };
        $make('pjguardian-20260930-020000-daily.pjgbak', 1);
        $make('pjguardian-20260921-020000-daily.pjgbak', 10);
        $make('pjguardian-20260801-020000-daily.pjgbak', 61);   // 기간 지났지만 최근 3개 안
        $make('pjguardian-20260723-020000-daily.pjgbak', 70);   // 지움
        $make('pjguardian-20260713-020000-manual.pjgbak', 80);  // 지움
        $make('pjguardian-20260703-020000-daily.pjgbak', 90);   // 마지막 성공 → 보호
        $make('pjguardian-20260830-020000-pre_restore.pjgbak', 32); // 안전 백업 30일 → 지움
        $make('pjguardian-20260926-020000-pre_restore.pjgbak', 5);
        $make('pjguardian-20260601-020000-daily-2.pjgbak', 120, false); // 검증 기록 없음 → 건드리지 않음
        $b->state()->update(static fn (array $s): array => ['last_success' => ['at' => '2026-07-03T02:00:00Z', 'file' => 'pjguardian-20260703-020000-daily.pjgbak']] + $s);
        $deleted = $b->prune();
        sort($deleted);
        $this->assertSame(['pjguardian-20260713-020000-manual.pjgbak', 'pjguardian-20260723-020000-daily.pjgbak', 'pjguardian-20260830-020000-pre_restore.pjgbak'], $deleted);
        $this->assertFalse(is_file($this->bk . '/pjguardian-20260723-020000-daily.pjgbak.json'), '옆 파일도 지움');
        $this->assertSame(6, count($b->list()));
        $this->assertSame('backup_last_success_protected', $this->codeOf(fn () => $b->delete('pjguardian-20260703-020000-daily.pjgbak')));
    }

    public function testDailyScheduleRetriesOnceAndAlertsAdminsOncePerHour(): void
    {
        $this->clock = new FixedClock('2026-09-30T17:00:00Z'); // KST 10-01 02:00 — 일일 시각(02:30) 전
        $app = $this->app();
        // P27B 상태(어제 일일 백업 함)를 P27E 자동 백업 판단이 이어받는다(의도된 변경: dailyDue() bool → autoDue() 예정 시각|null)
        $app->backups()->paths()->prepare($app->pathPolicy());
        $app->backups()->state()->update(static fn (array $s): array => ['daily' => ['date' => '2026-09-30', 'retry_date' => null, 'attempts' => 0, 'retry_at' => null]] + $s);
        $this->assertSame(null, $app->backupRunner()->autoDue());
        $this->clock->advance(31 * 60); // 02:31
        $this->assertSame('2026-09-30T17:30:00Z', $app->backupRunner()->autoDue(), '오늘(KST 10-01) 02:30 예정');
        // 실패(디스크 차단) → 10분 뒤 한 번 더
        $this->ini = $this->writeIni(['disk' => ['warn_percent' => '0', 'block_percent' => '1']]);
        $app = $this->app();
        $r = $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system']);
        $this->assertSame([false, 'backup_disk_full'], [$r['ok'], $r['code']]);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '재시도 대기');
        $notes = fn (): int => (int) $app->db()->value("SELECT COUNT(*) FROM notifications WHERE kind = 'backup_failed'");
        $this->assertSame(0, $notes(), '첫 실패는 재시도 전이라 알리지 않음');
        $this->clock->advance(600);
        $this->assertTrue($app->backupRunner()->autoDue() !== null);
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system']);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '두 번 실패하면 그날은 끝');
        $this->assertSame(1, $notes(), 'admin 1명에게 한 건');
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'manual', 'by' => 'admin']); // 같은 사유, 1시간 안
        $this->assertSame(1, $notes(), '같은 사유는 1시간에 한 번');
        $this->clock->advance(3600);
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'manual', 'by' => 'admin']);
        $this->assertSame(2, $notes());
        $this->assertSame('backup_disk_full', $app->backups()->state()->read()['last_failure']['code']);
        // 다음 날 성공하면 state 갱신, 감사 backup.create
        $this->ini = $this->writeIni([]);
        $app = $this->app();
        $this->clock->advance(86400);
        $this->assertTrue($app->backupRunner()->autoDue() !== null);
        $ok = $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system']);
        $this->assertTrue($ok['ok']);
        $this->assertSame($ok['name'], $app->backups()->state()->read()['last_success']['file']);
        $this->assertSame(null, $app->backupRunner()->autoDue());
        $this->assertTrue((int) $app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'backup.create'") >= 4);
    }

    public function testRehearsalLeavesDataUntouchedAndDueAlertOnce(): void
    {
        $app = $this->app();
        $b = $app->backupRunner()->run(['type' => 'backup', 'kind' => 'manual', 'by' => 'admin']);
        $before = hash_file('sha256', $this->db);
        $r = $app->backupRunner()->run(['type' => 'rehearsal', 'file' => $b['name'], 'by' => 'admin']);
        $this->assertTrue($r['ok'], (string) $r['code']);
        $this->assertSame($before, hash_file('sha256', $this->db), '운영 DB 그대로');
        $this->assertSame(true, $app->backups()->state()->read()['last_rehearsal']['ok']);
        $this->assertFalse($app->backupRunner()->checkRehearsalDue());
        $this->clock->advance(91 * 86400);
        $this->assertTrue($app->backupRunner()->checkRehearsalDue());
        $this->assertFalse($app->backupRunner()->checkRehearsalDue(), '기한마다 한 번');
        $this->assertSame(1, (int) $app->db()->value("SELECT COUNT(*) FROM notifications WHERE kind = 'restore_rehearsal_due'"));
    }

    // ---------------------------------------------------------------- 사전 검증

    public function testPrecheckRejectsBadBundlesWithoutChangingAnything(): void
    {
        $app = $this->app();
        $good = $app->backups()->create('manual', true, 'admin');
        $src = $this->bk . '/' . $good['name'];
        $variant = function (string $suffix, \Closure $edit) use ($src): string {
            $f = $this->bk . "/pjguardian-20261001-120000-manual-{$suffix}.pjgbak";
            copy($src, $f);
            $zip = new \ZipArchive();
            $zip->open($f);
            $edit($zip);
            $zip->close();
            return $f;
        };
        $zip = new \ZipArchive();
        $zip->open($src);
        $m = json_decode((string) $zip->getFromName(BundleFormat::MANIFEST), true);
        $zip->close();
        $corrupt = $this->bk . '/pjguardian-20261001-120000-manual-1.pjgbak';
        file_put_contents($corrupt, random_bytes(4096));
        $cases = [
            'bundle_corrupt' => $corrupt,
            'bundle_hash_mismatch' => $variant('2', fn (\ZipArchive $z) => $z->addFromString('snapshots/1/2/source.zip', 'tampered')),
            'manifest_mismatch' => $variant('3', function (\ZipArchive $z) use ($m): void {
                $m2 = $m;
                unset($m2['entries']['snapshots/1/3/source.zip']);
                $z->addFromString(BundleFormat::MANIFEST, (string) json_encode($m2));
            }),
            'bundle_entry_not_allowed' => $variant('4', fn (\ZipArchive $z) => $z->addFromString('evil.php', '<?php echo 1;')),
            'bundle_entry_not_allowed ' => $variant('5', fn (\ZipArchive $z) => $z->addFromString('../../escape.txt', 'x')),
            'bundle_counts_mismatch' => $variant('6', function (\ZipArchive $z) use ($m): void {
                $m2 = $m;
                $m2['counts']['users'] = 99;
                $z->addFromString(BundleFormat::MANIFEST, (string) json_encode($m2));
            }),
        ];
        $dbHash = hash_file('sha256', $this->db);
        $snapHash = hash_file('sha256', $this->snap . '/1/2/source.zip');
        foreach ($cases as $code => $file) {
            $this->assertSame(trim($code), $this->codeOf(fn () => $this->restore($app)->precheck($file)), $code);
            $st = $this->restore($app)->execute(['bundle' => $file, 'by' => 'admin']);
            $this->assertSame(['failed', trim($code)], [$st['status'], $st['error']['code']], "실행해도 사전 검증에서 멈춤: {$code}");
        }
        // 더 새 스키마: 묶음 DB에 앱보다 높은 마이그레이션 번호
        $p = $this->pdo();
        $p->exec("INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES ('999', 'future', '" . str_repeat('0', 64) . "', '2026-10-01T00:00:00Z')");
        $p = null;
        $newer = $app->backups()->create('manual', false, 'admin');
        $p = $this->pdo();
        $p->exec("DELETE FROM schema_migrations WHERE version = '999'");
        $p = null;
        $dbHash = hash_file('sha256', $this->db);
        $this->assertSame('restore_schema_newer', $this->codeOf(fn () => $this->restore($app)->precheck($this->bk . '/' . $newer['name'])));
        $this->assertSame($dbHash, hash_file('sha256', $this->db), 'DB 그대로');
        $this->assertSame($snapHash, hash_file('sha256', $this->snap . '/1/2/source.zip'), '스냅샷 그대로');
        $this->assertSame(null, $app->maintenance()->active(), '유지보수 플래그 없음');
        $this->assertFalse(is_file($this->db . '.before_restore'));
    }

    // ---------------------------------------------------------------- 복원

    public function testRestoreSucceedsInvalidatesSessionsAndKeepsSafetyBackup(): void
    {
        $app = $this->app();
        $b = $app->backups()->create('manual', true, 'admin');
        // 백업 뒤 변경: 사용자·세션 추가, 스냅샷 하나 바꿈
        $p = $this->pdo();
        $p->exec("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('later', 'later', 'user', 'active', 'local', 'x', '2026-10-03T04:00:00Z', '2026-10-03T04:00:00Z')");
        $p->exec("INSERT INTO sessions (id_hash, user_id, csrf_token, created_at, last_activity_at) VALUES ('" . str_repeat('a', 64) . "', 1, '" . str_repeat('b', 64) . "', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        $p = null;
        file_put_contents($this->snap . '/1/2/source.zip', 'changed later');
        $pre = $this->restore($app)->precheck($this->bk . '/' . $b['name']);
        $this->assertSame(2, $pre['counts']['users']);
        $this->assertSame(3, $pre['current']['counts']['users']);
        $this->assertSame(1, $pre['loss']['accounts'], '이후 계정 변경 1건');
        $this->assertFalse($pre['migrate']);
        $app->resetConnections();
        $st = $this->restore($app)->execute(['bundle' => $this->bk . '/' . $b['name'], 'by' => 'admin']);
        $this->assertSame('succeeded', $st['status'], (string) json_encode($st['error']));
        $this->assertSame(2, $this->count('users'));
        $this->assertSame(0, $this->count('sessions'), '모든 세션 무효');
        $this->assertSame(str_repeat("snapshot 1/2\n", 50), (string) file_get_contents($this->snap . '/1/2/source.zip'));
        $this->assertTrue(is_file($this->db . '.before_restore') && is_dir($this->snap . '.before_restore'), '되돌릴 수 있게 보관');
        $kinds = array_column($this->app()->backups()->list(), 'kind');
        $this->assertTrue(in_array('pre_restore', $kinds, true), '복원 직전 자동 백업');
        $this->assertSame(null, $this->app()->maintenance()->active());
        $steps = array_column($st['steps'], 'status', 'name');
        $this->assertSame('skipped', $steps['migrate']);
        $this->assertSame('done', $steps['finalize']);
        $this->assertSame(1, (int) $this->app()->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'restore.finish'"), '복원된 DB의 감사 로그');
        $this->assertSame('ok', (string) $this->app()->db()->value('PRAGMA integrity_check'));
    }

    public function testFaultAtEachStepRollsBackToPreRestoreState(): void
    {
        $app = $this->app();
        $b = $app->backups()->create('manual', true, 'admin');
        $p = $this->pdo();
        $p->exec("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES ('later', 'later', 'user', 'active', 'local', 'x', '2026-10-03T04:00:00Z', '2026-10-03T04:00:00Z')");
        $p = null;
        file_put_contents($this->snap . '/1/2/source.zip', 'changed later');
        foreach (['replaced_db', 'replaced_snapshots', 'migrate', 'finalize'] as $point) {
            $app->resetConnections();
            $st = $this->restore($app, static function (string $at) use ($point): void {
                if ($at === $point) {
                    throw new BackupException('restore_io_failed', [], "장애 주입 {$at}");
                }
            })->execute(['bundle' => $this->bk . '/' . $b['name'], 'by' => 'admin', 'pre_backup' => false]);
            $this->assertSame(['rolled_back', 'restore_io_failed'], [$st['status'], $st['error']['code']], $point);
            $this->assertSame(3, $this->count('users'), "{$point}: 복원 전 DB로 돌아옴");
            $this->assertSame('changed later', (string) file_get_contents($this->snap . '/1/2/source.zip'), "{$point}: 스냅샷도");
            $this->assertSame(null, $app->maintenance()->active(), "{$point}: 유지보수 해제");
            $this->assertSame([], $st['manual']);
        }
        $this->assertTrue((int) $this->app()->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'restore.rollback'") >= 4, '롤백 기록(복원 전 DB에)');
    }

    public function testInterruptedRestoreIsRecoveredAtStartup(): void
    {
        $app = $this->app();
        $state = new RestoreStateFile($this->restore($app)->stateFile(), $this->clock);
        $state->start('pjguardian-20261001-120000-manual.pjgbak', 'admin');
        $state->step('replace_db');
        // 프로세스가 replace_db 도중 죽은 모습: 원래 DB는 .before_restore, 자리에는 반쯤 바뀐 DB, pid는 없는 프로세스
        $raw = json_decode((string) file_get_contents($this->restore($app)->stateFile()), true);
        $raw['pid'] = 999999;
        file_put_contents($this->restore($app)->stateFile(), (string) json_encode($raw));
        $app->maintenance()->enter('restore', 'admin');
        $original = hash_file('sha256', $this->db);
        rename($this->db, $this->db . '.before_restore');
        file_put_contents($this->db, 'half written');
        $this->assertTrue($this->restore($app)->recoverInterrupted());
        $this->assertSame($original, hash_file('sha256', $this->db));
        $this->assertSame(null, $app->maintenance()->active());
        $s = $state->read();
        $this->assertSame(['rolled_back', 'restore_interrupted'], [$s['status'], $s['error']['code']]);
        $this->assertFalse($this->restore($app)->recoverInterrupted(), '두 번째는 할 일 없음');
    }

    public function testSelectiveConfigRestoreMachineKeysOffAndKeyImport(): void
    {
        // 백업 때: 화면 설정 2개(보관 45일·백업 폴더), 저장소 자격 증명은 키 K1로 암호화
        $k1 = base64_encode(random_bytes(32));
        $p = $this->pdo();
        $p->exec("INSERT INTO projects (name, language, created_at, updated_at) VALUES ('p', 'python', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        $enc = (new SecretBox([$k1]))->encrypt('tok-secret', 'pjg-repo:1:git');
        $p->prepare("INSERT INTO project_repo_connections (project_id, kind, location, secret_kind, secret_cipher, secret_nonce, secret_key_id, created_at, updated_at) VALUES (1, 'git', 'https://git.example/r.git', 'token', ?, ?, ?, '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')")
            ->execute([$enc['cipher'], $enc['nonce'], $enc['key_id']]);
        $p = null;
        $local = $this->dir . '/settings.local.ini';
        // 묶음을 만든 PC: 화면 설정의 백업 폴더 = $this->bk. 복원할 PC: config.ini의 다른 폴더(PC 고유 차이)
        file_put_contents($local, "[backup]\nretention_days = \"45\"\ndir = \"{$this->bk}\"\n");
        $this->ini = $this->writeIni(['backup' => ['dir' => $this->bk . '_cfg']]);
        $app = $this->app();
        $b = $app->backups()->create('manual', false, 'admin');
        $bundle = $this->bk . '/' . $b['name'];
        unlink($local); // 지금(복원할 PC)은 화면 설정 없음, 키는 K2
        $app = $this->app();
        $pre = $this->restore($app)->precheck($bundle);
        $diff = array_column(array_filter($pre['config'], static fn ($d) => $d['file'] === 'local'), null, 'key');
        $this->assertSame(['45', false], [$diff['backup.retention_days']['backup'], $diff['backup.retention_days']['machine']]);
        $this->assertSame(true, $diff['backup.dir']['machine'], 'backup.dir은 PC 고유 → 화면에서 기본 해제');
        $this->assertSame('mismatch', $pre['key'], '지금 키로 못 품');
        // 키 가져오기 시험: 다른 키는 거부, 맞는 키는 통과
        $this->assertFalse($this->restore($app)->testKeyAgainstBundle($bundle, ['encryption_key' => $this->k2, 'encryption_key_previous' => null]));
        $this->assertTrue($this->restore($app)->testKeyAgainstBundle($bundle, ['encryption_key' => $k1, 'encryption_key_previous' => null]));
        // 키 없이 복원: 연동 1개가 "다시 입력" 대상, 설정은 고른 것만
        $app->resetConnections();
        $st = $this->restore($app)->execute(['bundle' => $bundle, 'by' => 'admin', 'local_keys' => ['backup.retention_days'], 'pre_backup' => false]);
        $this->assertSame('succeeded', $st['status'], (string) json_encode($st['error']));
        $this->assertSame(1, $st['result']['credentials_unreadable']);
        $this->assertSame(false, $st['result']['key_imported']);
        $applied = (string) file_get_contents($local);
        $this->assertContains('retention_days', $applied);
        $this->assertNotContains('dir', $applied, '고르지 않은 PC 고유 항목(backup.dir)은 적용 안 함');
        $this->assertSame(45, $this->app()->config->int('backup.retention_days'));
        $this->assertContains($this->k2, (string) file_get_contents($this->dir . '/secrets.ini'), '키는 그대로');
        // 키를 가져와 다시 복원(화면이 시험을 통과한 키를 pending 파일로 넘긴 것처럼): K1이 현재, K2는 이전 키, 원래 파일 보관
        $app = $this->app();
        file_put_contents($this->restore($app)->pendingKeyFile(), (string) json_encode(['encryption_key' => $k1, 'encryption_key_previous' => null]));
        $app->resetConnections();
        $st = $this->restore($app)->execute(['bundle' => $bundle, 'by' => 'admin', 'pre_backup' => false, 'key' => true]);
        $this->assertSame('succeeded', $st['status'], (string) json_encode($st['error']));
        $this->assertSame([true, 0], [$st['result']['key_imported'], $st['result']['credentials_unreadable']]);
        $secrets = (string) file_get_contents($this->dir . '/secrets.ini');
        $this->assertContains("encryption_key = \"{$k1}\"", $secrets);
        $this->assertContains("encryption_key_previous = \"{$this->k2}\"", $secrets);
        $this->assertContains('marker-smtp-secret', $secrets, '다른 시크릿 줄은 그대로');
        $this->assertTrue(is_file($this->dir . '/secrets.ini.before_restore'));
        $this->assertFalse(is_file($this->restore($app)->pendingKeyFile()));
        $this->assertSame('tok-secret', (new SecretBox([$k1, $this->k2]))->decrypt($enc['cipher'], $enc['nonce'], $enc['key_id'], 'pjg-repo:1:git'));
        $this->assertSame(1, KeyVault::unreadableCount([$this->k2], $this->db), '옛 키만으로는 못 품(확인용)');
    }

    public function testUnreadableCredentialSkipNotifiesAdminsOncePerProject(): void
    {
        // 키 없이 복원한 뒤: 주기 분석은 도래마다 건너뛰지만(repo_secret_unreadable) 관리자 알림은 프로젝트당 한 번. 다른 사유는 종전대로 도래마다
        $app = $this->app();
        $pid = TestDb::project($app->db(), 'p1');
        $out = $app->scheduleOutcomes();
        foreach (['2026-10-03T00:00:00Z', '2026-10-04T00:00:00Z', '2026-10-05T00:00:00Z'] as $due) {
            $out->skipped($pid, 'skipped_connection', 'repo_secret_unreadable', $due, '2026-10-06T00:00:00Z');
        }
        $n = fn (string $code): int => (int) $app->db()->value("SELECT COUNT(*) FROM notifications WHERE kind = 'schedule_failed' AND json_extract(params, '$.code') = ?", [$code]);
        $this->assertSame(1, $n('repo_secret_unreadable'), 'admin 1명 × 한 번');
        foreach (['2026-10-03T00:00:00Z', '2026-10-04T00:00:00Z'] as $due) {
            $out->skipped($pid, 'skipped_disk', 'disk_blocked', $due, '2026-10-06T00:00:00Z');
        }
        $this->assertSame(2, $n('disk_blocked'), '다른 사유(P26)는 도래마다');
    }

    // ---------------------------------------------------------------- 명령줄(다른 PC로 옮기기)

    /** @return array{0: int, 1: string} 종료 코드, 출력(표준 출력 + 오류) */
    private function cli(string $script, array $args, array $env = []): array
    {
        $cmd = array_merge([PHP_BINARY, '-d', 'extension=intl', PJG_ROOT . '/tools/' . $script], $args);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT, $env + getenv());
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), (string) $out];
    }

    public function testCliMovesDataAndKeyToFreshInstall(): void
    {
        // 설치본 A(이 시험의 기본): 저장소 자격 증명(키 K2) 하나
        $p = $this->pdo();
        $p->exec("INSERT INTO projects (name, language, created_at, updated_at) VALUES ('moved', 'python', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        $enc = (new SecretBox([$this->k2]))->encrypt('tok', 'pjg-repo:1:git');
        $p->prepare("INSERT INTO project_repo_connections (project_id, kind, location, secret_kind, secret_cipher, secret_nonce, secret_key_id, created_at, updated_at) VALUES (1, 'git', 'https://git.example/r.git', 'token', ?, ?, ?, '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')")
            ->execute([$enc['cipher'], $enc['nonce'], $enc['key_id']]);
        $p = null;
        [$code, $out] = $this->cli('backup.php', ['--kind=manual', '--config=' . $this->ini]);
        $this->assertSame(0, $code, $out);
        $bundle = $this->app()->backups()->list()[0]['name'];
        $keyFile = $this->dir . '/key.pjgkey';
        file_put_contents($keyFile, $this->app()->keyVault()->export($this->k2, null, 'move passphrase 1'));
        // 설치본 B(새 PC): DB 없음, 다른 키, 다른 데이터·백업 폴더
        $b = $this->tempDir('bkr_b');
        $bBk = $this->bk . '_b';
        $kB = base64_encode(random_bytes(32));
        $iniB = TestApp::writeIni($b . '/config.ini', [
            'paths' => ['db_file' => $b . '/db/pjguardian.sqlite', 'work_dir' => $b . '/work', 'logs_dir' => $b . '/logs', 'uploads_dir' => $b . '/uploads', 'snapshots_dir' => $b . '/snapshots'],
            'backup' => ['dir' => $bBk],
        ]);
        file_put_contents($b . '/secrets.ini', "encryption_key = \"{$kB}\"\n");
        try {
            [$code, $out] = $this->cli('restore.php', ['--file=' . $this->bk . '/' . $bundle, '--dry-run', '--config=' . $iniB]);
            $this->assertSame(0, $code, $out);
            $this->assertContains('풀리지 않음', $out, '키 불일치 안내');
            $this->assertFalse(is_file($b . '/db/pjguardian.sqlite'), '--dry-run은 바꾸지 않음');
            [$code, $out] = $this->cli('restore.php', ['--file=' . $this->bk . '/' . $bundle, '--key=' . $keyFile, '--yes', '--config=' . $iniB], ['PJG_KEY_PASSPHRASE' => 'wrong passphrase']);
            $this->assertSame(1, $code, '틀린 암호문구 — ' . $out);
            $this->assertContains('key_import_failed', $out);
            $this->assertFalse(is_file($b . '/db/pjguardian.sqlite'));
            [$code, $out] = $this->cli('restore.php', ['--file=' . $this->bk . '/' . $bundle, '--key=' . $keyFile, '--yes', '--config=' . $iniB], ['PJG_KEY_PASSPHRASE' => 'move passphrase 1']);
            $this->assertSame(0, $code, $out);
            $this->assertNotContains('move passphrase 1', $out, '암호문구는 출력에 없음');
            $pdo = new \PDO('sqlite:' . $b . '/db/pjguardian.sqlite');
            $this->assertSame('moved', (string) $pdo->query('SELECT name FROM projects')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $pdo = null;
            $this->assertSame(str_repeat("snapshot 1/2\n", 50), (string) file_get_contents($b . '/snapshots/1/2/source.zip'));
            $secrets = (string) file_get_contents($b . '/secrets.ini');
            $this->assertContains("encryption_key = \"{$this->k2}\"", $secrets, 'A의 키가 현재 키');
            $this->assertContains("encryption_key_previous = \"{$kB}\"", $secrets);
            $this->assertSame(0, KeyVault::unreadableCount([$this->k2, $kB], $b . '/db/pjguardian.sqlite'));
            $logs = implode('', array_map(static fn (string $n): string => (string) file_get_contents($b . '/logs/' . $n), \PJGuardian\Support\Fs::listDir($b . '/logs')));
            $this->assertContains('복원 완료', $logs, '로그 파일을 읽었는지');
            $this->assertNotContains($this->k2, $logs, '로그에 키 없음');
            $this->assertNotContains('move passphrase 1', $logs, '로그에 암호문구 없음');
        } finally {
            BackupService::removeTree($bBk);
        }
    }

    // ---------------------------------------------------------------- HTTP

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app());
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    public function testHttpAccessReauthDownloadAndUploadNeedsVerify(): void
    {
        $anon = new TestClient($this->app());
        $this->assertSame(401, $anon->get('/api/v1/admin/backups')->status);
        $this->assertSame(302, $anon->get('/admin/backups')->status);
        $this->assertSame(403, $this->as('kim')->get('/api/v1/admin/backups')->status);
        $admin = $this->as('admin');
        $b = $this->app()->backups()->create('manual', false, 'admin');
        $this->app()->backups()->state()->update(static fn (array $s): array => ['last_success' => ['at' => self::T0, 'file' => $b['name'], 'size' => 1, 'kind' => 'manual']] + $s);
        $d = $admin->get('/api/v1/admin/backups')->decoded()['data'];
        $this->assertSame([$b['name']], array_column($d['items'], 'name'));
        $this->assertSame(KeyVault::fingerprint($this->k2), $d['key']['fingerprint']);
        $this->assertNotContains($this->k2, $admin->get('/api/v1/admin/backups')->body, '키 값은 응답에 없음');
        $this->assertSame(200, $admin->get('/admin/backups')->status);
        // 비밀번호 재입력: 틀리면 403 reauth_failed, 맞으면 한 번 쓰는 주소
        $bad = $admin->request('POST', "/api/v1/admin/backups/{$b['name']}/download", [], ['password' => 'wrong']);
        $this->assertSame([403, 'reauth_failed'], [$bad->status, $bad->decoded()['error']['code']]);
        $tok = $admin->request('POST', "/api/v1/admin/backups/{$b['name']}/download", [], ['password' => 'Passw0rd!x']);
        $this->assertSame(200, $tok->status);
        $url = $tok->decoded()['data']['url'];
        $dl = $admin->get($url);
        $this->assertSame(200, $dl->status);
        $this->assertSame(hash_file('sha256', $this->bk . '/' . $b['name']), hash('sha256', $dl->takeFile()), '내용 그대로');
        $this->assertSame(404, $admin->get($url)->status, '한 번만');
        // 마지막 성공 백업은 지울 수 없음
        $del = $admin->request('DELETE', "/api/v1/admin/backups/{$b['name']}", [], ['password' => 'Passw0rd!x']);
        $this->assertSame([409, 'backup_last_success_protected'], [$del->status, $del->decoded()['error']['code']]);
        // 복원 요청: 확인 문구·워커 없음
        $r = $admin->request('POST', '/api/v1/admin/restore', [], ['name' => $b['name'], 'password' => 'Passw0rd!x', 'confirm' => '아니오']);
        $this->assertSame([400, 'restore_confirm_mismatch'], [$r->status, $r->decoded()['error']['code']]);
        $r = $admin->request('POST', '/api/v1/admin/restore', [], ['name' => $b['name'], 'password' => 'Passw0rd!x', 'confirm' => '복원']);
        $this->assertSame([409, 'worker_not_running'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertFalse(is_file($this->app()->backupPaths()->restoreRequest()), '요청 파일 없음');
        // 묶음 올리기 → "검증 필요"(복원 불가) → 검증하면 복원 대상
        $content = (string) file_get_contents($this->bk . '/' . $b['name']);
        $name = 'pjguardian-20260101-000000-manual.pjgbak';
        $this->assertSame(400, $admin->request('POST', '/api/v1/admin/backup-uploads', [], ['file_name' => 'x.zip', 'total_size' => 10])->status);
        $init = $admin->request('POST', '/api/v1/admin/backup-uploads', [], ['file_name' => $name, 'total_size' => strlen($content)])->decoded()['data'];
        foreach (str_split($content, $init['chunk_size']) as $i => $chunk) {
            $res = $admin->raw('PUT', "/api/v1/admin/backup-uploads/{$init['id']}/chunks/{$i}", $chunk, ['X-Chunk-Sha256' => hash('sha256', $chunk)]);
            $this->assertSame(200, $res->status, (string) $res->body);
        }
        $this->assertSame(200, $admin->request('POST', "/api/v1/admin/backup-uploads/{$init['id']}/complete", [], [])->status);
        $pre = $admin->request('POST', "/api/v1/admin/backups/{$name}/precheck", [], []);
        $this->assertSame([409, 'bundle_needs_verify'], [$pre->status, $pre->decoded()['error']['code']]);
        $this->assertSame(true, $admin->request('POST', "/api/v1/admin/backups/{$name}/verify", [], [])->decoded()['data']['ok']);
        $this->assertSame(200, $admin->request('POST', "/api/v1/admin/backups/{$name}/precheck", [], [])->status);
        $this->assertTrue((int) $this->app()->db()->value("SELECT COUNT(*) FROM audit_log WHERE action IN ('backup.download', 'backup.upload', 'backup.verify')") >= 3);
    }

    public function testChangingBackupDirThroughSettingsUsesNewFolderAndIsBlockedWhileBusy(): void
    {
        $admin = $this->as('admin');
        $first = $this->app()->backups()->create('manual', false, 'admin');
        $new = $this->bk . '_cfg';
        $version = $admin->get('/api/v1/admin/config-settings')->decoded()['data']['version'];
        // 백업 작업이 진행 중이면 409 setting_busy(파일은 그대로)
        $this->app()->backupJobs()->request(['type' => 'backup', 'kind' => 'manual', 'snapshots' => false, 'by' => 'admin']);
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['backup.dir' => $new], 'version' => $version, 'create_paths' => ['backup.dir']]);
        $this->assertSame([409, 'setting_busy', 'backup_busy'], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['reason']]);
        $this->assertFalse(is_file($this->dir . '/settings.local.ini'));
        $this->app()->backupJobs()->finish();
        // 끝나면 바뀜: settings.local.ini에만 기록(config.ini 불변), 이후 백업·목록·상태는 새 폴더, 이전 폴더 파일은 옮기지 않음
        $iniBefore = hash_file('sha256', $this->ini);
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['backup.dir' => $new], 'version' => $version, 'create_paths' => ['backup.dir']]);
        $this->assertSame(200, $r->status, (string) $r->body);
        $this->assertSame($iniBefore, hash_file('sha256', $this->ini));
        $this->assertContains('dir', (string) file_get_contents($this->dir . '/settings.local.ini'));
        $app = $this->app();
        $this->assertSame([], $app->backups()->list(), '새 폴더는 비어 있음(이전 백업은 이어받지 않음)');
        $this->assertSame(null, $app->backups()->state()->read()['last_success'] ?? null);
        $second = $app->backups()->create('manual', false, 'admin');
        $this->assertTrue(is_file($new . '/' . $second['name']));
        $this->assertTrue(is_file($this->bk . '/' . $first['name']), '이전 폴더의 묶음은 그대로');
        $this->assertSame('screen', $this->as('admin')->get('/api/v1/admin/backups')->decoded()['data']['dir_source'], '웹은 요청마다 새 App(설정을 다시 읽음)');
        // 금지 위치는 P27A 경로 검사가 거부
        $version = $admin->get('/api/v1/admin/config-settings')->decoded()['data']['version'];
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['backup.dir' => PJG_ROOT . '/data/bk'], 'version' => $version]);
        $this->assertSame([422, 'setting_path_invalid'], [$r->status, $r->decoded()['error']['code']]);
    }

    public function testMaintenanceModeServesStatusPageAndBlocksOthers(): void
    {
        $admin = $this->as('admin');
        $app = $this->app();
        $app->maintenance()->enter('restore', 'admin');
        (new RestoreStateFile($this->restore($app)->stateFile(), $this->clock))->start('pjguardian-20261001-120000-manual.pjgbak', 'admin');
        $api = $admin->get('/api/v1/projects');
        $this->assertSame([503, 'maintenance'], [$api->status, $api->decoded()['error']['code']]);
        $page = $admin->get('/projects');
        $this->assertSame(503, $page->status);
        $this->assertContains('data-restore-status', $page->body);
        $anon = new TestClient($this->app());
        $st = $anon->get('/api/v1/restore-status');
        $this->assertSame(200, $st->status);
        $data = $st->decoded()['data'];
        $this->assertSame([true, 'running'], [$data['maintenance'], $data['status']]);
        $this->assertNotContains('"by"', (string) $st->body, '요청자 이름 없음');
        $this->assertNotContains(str_replace('/', '\\/', $this->dir), (string) $st->body, '절대 경로 없음');
        $this->assertSame(200, $anon->get('/restore-status')->status);
        $app->maintenance()->leave();
        $this->assertSame(200, $admin->get('/api/v1/projects')->status);
    }
    // ---------------------------------------------------------------- 자동 백업 켜기/끄기·주기(P27E)

    /** 오래된 일반 묶음(검증됨) 하나 — 보관 정리가 지울 수 있는 것 */
    private function oldBundle(App $app, string $name, int $daysAgo): void
    {
        $app->backups()->paths()->prepare($app->pathPolicy());
        file_put_contents($this->bk . '/' . $name, 'x');
        $app->backups()->writeSidecar($name, ['created_at' => gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()->getTimestamp() - $daysAgo * 86400)], ['ok' => true]);
    }

    public function testAutoBackupOffMakesNoBackupAlertOrPrune(): void
    {
        $this->ini = $this->writeIni(['backup' => ['enabled' => 'false']]);
        $app = $this->app();
        foreach (['pjguardian-20260601-020000-daily.pjgbak' => 120, 'pjguardian-20260602-020000-daily.pjgbak' => 119, 'pjguardian-20260603-020000-daily.pjgbak' => 118,
            'pjguardian-20260604-020000-daily.pjgbak' => 117] as $name => $age) {
            $this->oldBundle($app, $name, $age);
        }
        for ($i = 0; $i < 10; $i++) { // 열흘 동안 확인해도 자동 백업 요청 없음
            $this->assertSame(null, $app->backupRunner()->autoDue(), "꺼짐 {$i}일째");
            $this->assertSame(null, $app->backupRunner()->nextAuto());
            $this->clock->advance(86400);
        }
        // 수동 백업은 된다, 정리는 하지 않는다(수동 뒤 정리는 P27B 규칙대로 없음)
        $m = $app->backupRunner()->run(['type' => 'backup', 'kind' => 'manual', 'by' => 'admin']);
        $this->assertTrue($m['ok']);
        // 끄기 전에 시작된 자동 백업은 끝까지 — 그래도 꺼져 있으면 보관 정리는 하지 않음(오래된 4개가 그대로)
        $d = $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'slot' => '2026-10-11T17:30:00Z']);
        $this->assertTrue($d['ok']);
        $this->assertSame(6, count($app->backups()->list()), '자동 정리 없음 — 파일이 지워지지 않음');
        // 꺼진 동안 실패해도 backup_failed 알림 없음(실패 기록은 남음)
        $this->ini = $this->writeIni(['backup' => ['enabled' => 'false'], 'disk' => ['warn_percent' => '0', 'block_percent' => '1']]);
        $app = $this->app();
        foreach (['daily', 'daily', 'manual'] as $kind) {
            $app->backupRunner()->run(['type' => 'backup', 'kind' => $kind, 'by' => 'system', 'slot' => '2026-10-11T17:30:00Z']);
        }
        $this->assertSame(0, (int) $app->db()->value("SELECT COUNT(*) FROM notifications WHERE kind = 'backup_failed'"));
        $this->assertSame('backup_disk_full', $app->backups()->state()->read()['last_failure']['code']);
        // 명령줄 백업도 꺼져 있어도 동작
        $this->ini = $this->writeIni(['backup' => ['enabled' => 'false']]);
        [$code, $out] = $this->cli('backup.php', ['--kind=manual', '--no-snapshots', '--config=' . $this->ini]);
        $this->assertSame(0, $code, $out);
        // 켜면: 그 뒤 자동 백업이 성공하면 정리가 돈다(오래된 것 중 최근 3개 보호 밖은 지움)
        $this->ini = $this->writeIni([]);
        $app = $this->app();
        $slot = $app->backupRunner()->autoDue();
        $this->assertTrue($slot !== null, '켜면 다음 확인에서 한 번');
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'slot' => $slot]);
        $this->assertFalse(is_file($this->bk . '/pjguardian-20260601-020000-daily.pjgbak'), '켠 뒤 자동 백업 성공 → 보관 정리');
    }

    public function testRealWorkerOnceMakesAutoBackupOnlyWhenOnAndOncePerSlot(): void
    {
        // 실제 워커 프로세스(--once, 시스템 시계). 상태가 없으면 최근 예정 하나를 바로 실행한다
        $this->ini = $this->writeIni(['backup' => ['enabled' => 'false']]);
        [$code, $out] = $this->cli('worker.php', ['--once', '--config=' . $this->ini]);
        $this->assertSame(0, $code, $out);
        $this->assertSame([], $this->app()->backups()->list(), '꺼짐: 자동 백업 없음');
        $this->ini = $this->writeIni([]);
        [$code, $out] = $this->cli('worker.php', ['--once', '--config=' . $this->ini]);
        $this->assertSame(0, $code, $out);
        $list = $this->app()->backups()->list();
        $this->assertSame(['daily'], array_column($list, 'kind'), '켜짐: 자동 백업 한 번(종류 이름은 호환으로 daily) — ' . $out);
        $this->assertSame(true, $list[0]['verified']['ok']);
        [$code, $out] = $this->cli('worker.php', ['--once', '--config=' . $this->ini]);
        $this->assertSame(0, $code, $out);
        $this->assertSame(1, count($this->app()->backups()->list()), '같은 예정에 두 번 없음');
    }

    public function testLongOffThenOnRunsOnceAndRuleChangeStartsFromNextSlot(): void
    {
        // KST 10-03 12:00. 이미 오늘 몫 처리
        $app = $this->app();
        $first = $app->backupRunner()->autoDue();
        $this->assertSame('2026-10-02T17:30:00Z', $first, '처음(상태 없음): 최근 예정 한 번');
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'snapshots' => false, 'slot' => $first]);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '같은 예정에 두 번 없음');
        $this->assertSame('2026-10-03T17:30:00Z', $app->backupRunner()->nextAuto(), '다음 = 내일 02:30');
        // 끄고 20일 → 켜기: 지난 예정 20개가 아니라 최근 하나만
        $this->ini = $this->writeIni(['backup' => ['enabled' => 'false']]);
        $this->clock->advance(20 * 86400);
        $this->assertSame(null, $this->app()->backupRunner()->autoDue());
        $this->ini = $this->writeIni([]);
        $app = $this->app();
        $slot = $app->backupRunner()->autoDue();
        $this->assertSame('2026-10-22T17:30:00Z', $slot, '켜면 최근 예정(10-23 02:30 KST) 하나');
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'snapshots' => false, 'slot' => $slot]);
        $this->clock->advance(6 * 3600);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '밀린 예정을 채우지 않음');
        // 워커가 오래 꺼져 있다 켜져도 한 번(상태 그대로, 시계만 9일 뒤)
        $this->clock->advance(9 * 86400);
        $slot = $app->backupRunner()->autoDue();
        $this->assertSame('2026-10-31T17:30:00Z', $slot, '9일 뒤(KST 11-01 18:00) 최근 예정 하나');
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'snapshots' => false, 'slot' => $slot]);
        $this->assertSame(null, $app->backupRunner()->autoDue());
        // 매주 일요일로 바꿈(지금 KST 11-01 18:00 일요일): 오늘 02:30 예정은 지난 것 → 다음 일요일(11-08)부터
        $this->ini = $this->writeIni(['backup' => ['schedule_mode' => 'weekly', 'weekly_day' => 'sun']]);
        $app = $this->app();
        $this->assertSame(null, $app->backupRunner()->autoDue(), '방식을 바꾼 직후 즉시 백업 없음');
        $this->assertSame('2026-11-07T17:30:00Z', $app->backupRunner()->nextAuto());
        $this->clock->advance(6 * 86400); // 토요일
        $this->assertSame(null, $app->backupRunner()->autoDue());
        $this->clock->advance(86400); // 일요일 18:00 KST
        $this->assertSame('2026-11-07T17:30:00Z', $app->backupRunner()->autoDue(), '새 규칙의 예정');
        // 쓰이지 않는 값(monthly_day)이 바뀌어도 규칙 표지는 그대로 — 지난 예정 건너뜀이 생기지 않음
        $this->ini = $this->writeIni(['backup' => ['schedule_mode' => 'weekly', 'weekly_day' => 'sun', 'monthly_day' => '15']]);
        $this->assertSame('2026-11-07T17:30:00Z', $this->app()->backupRunner()->autoDue());
    }

    public function testMonthlyLastDayRetryOnceAndNoDoubleRun(): void
    {
        $this->ini = $this->writeIni(['backup' => ['schedule_mode' => 'monthly', 'monthly_day' => 'last']]);
        $this->clock = new FixedClock('2026-10-29T03:00:00Z'); // KST 10-29 12:00
        $app = $this->app();
        $app->backups()->paths()->prepare($app->pathPolicy());
        $app->backups()->state()->update(static fn (array $s): array => ['auto' => ['rule' => 'monthly:last@02:30', 'slot' => '2026-09-29T17:30:00Z', 'retry_slot' => null, 'attempts' => 0, 'retry_at' => null]] + $s);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '9월 말일 처리됨');
        $this->assertSame('2026-10-30T17:30:00Z', $app->backupRunner()->nextAuto(), '10월 말일 02:30 KST');
        $this->clock->advance(2 * 86400); // KST 10-31 12:00
        $slot = $app->backupRunner()->autoDue();
        $this->assertSame('2026-10-30T17:30:00Z', $slot);
        // 실패 → 10분 뒤 재시도 1회 → 또 실패면 그 예정은 끝(알림 1건)
        $this->ini = $this->writeIni(['backup' => ['schedule_mode' => 'monthly', 'monthly_day' => 'last'], 'disk' => ['warn_percent' => '0', 'block_percent' => '1']]);
        $app = $this->app();
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'slot' => $slot]);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '재시도 대기');
        $this->assertSame('2026-10-31T03:10:00Z', $app->backupRunner()->nextAuto(), '다음 = 재시도 시각');
        $this->clock->advance(600);
        $this->assertSame($slot, $app->backupRunner()->autoDue());
        $app->backupRunner()->run(['type' => 'backup', 'kind' => 'daily', 'by' => 'system', 'slot' => $slot]);
        $this->assertSame(null, $app->backupRunner()->autoDue(), '그 예정은 끝');
        $this->assertSame(1, (int) $app->db()->value("SELECT COUNT(*) FROM notifications WHERE kind = 'backup_failed'"));
        $this->assertSame('2026-11-29T17:30:00Z', $app->backupRunner()->nextAuto(), '11월은 30일까지 → 30일');
    }

    public function testRetentionMaxCountWithMinimumAndLastSuccess(): void
    {
        $this->ini = $this->writeIni(['backup' => ['retention_max_count' => '4']]);
        $app = $this->app();
        $names = [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $d) { // 모두 보관 기간(60일) 안
            $names[$d] = sprintf('pjguardian-202609%02d-020000-daily.pjgbak', 30 - $d);
            $this->oldBundle($app, $names[$d], $d);
        }
        $app->backups()->state()->update(static fn (array $s): array => ['last_success' => ['at' => 'x', 'file' => $names[7]]] + $s); // 가장 오래된 것이 마지막 성공(보호)
        $deleted = $app->backups()->prune();
        sort($deleted);
        $this->assertSame([$names[6], $names[5]], $deleted, '상한 4개 넘는 오래된 것을 지움(마지막 성공은 보호)');
        $this->assertSame(5, count($app->backups()->list()), '최근 4개 + 마지막 성공');
        // 상한 0 = 제한 없음(기간 안이면 그대로)
        $this->ini = $this->writeIni([]);
        $this->assertSame([], $this->app()->backups()->prune());
        // 상한 < 최소 보관 개수는 저장 거부(422 setting_cross_check), 범위 밖·형식 오류 422
        $admin = $this->as('admin');
        $version = $admin->get('/api/v1/admin/config-settings')->decoded()['data']['version'];
        $put = fn (array $c) => $admin->put('/api/v1/admin/config-settings', ['changes' => $c, 'version' => $version]);
        $r = $put(['backup.retention_max_count' => '2']);
        $this->assertSame([422, 'setting_cross_check'], [$r->status, $r->decoded()['error']['code']]);
        foreach ([['backup.schedule_mode', 'hourly'], ['backup.weekly_day', 'sunday'], ['backup.monthly_day', '32'], ['backup.monthly_day', '0'], ['backup.enabled', 'maybe'],
            ['backup.retention_max_count', '1001'], ['backup.daily_at', '25:00']] as [$k, $v]) {
            $r = $put([$k => $v]);
            $this->assertSame([422, 'setting_invalid_value'], [$r->status, $r->decoded()['error']['code']], "{$k}={$v}");
        }
    }

    public function testScheduleSettingsSaveAuditEnvPriorityAndSummary(): void
    {
        $admin = $this->as('admin');
        $items = array_column($admin->get('/api/v1/admin/config-settings')->decoded()['data']['items'], null, 'key');
        foreach (['backup.enabled' => 'bool', 'backup.schedule_mode' => 'enum', 'backup.weekly_day' => 'enum', 'backup.monthly_day' => 'enum', 'backup.retention_max_count' => 'int'] as $k => $type) {
            $this->assertSame([$type, 'backup', false], [$items[$k]['type'], $items[$k]['group'], $items[$k]['machine_specific']], $k);
        }
        $this->assertSame(['daily', 'weekly', 'monthly'], $items['backup.schedule_mode']['values']);
        $this->assertSame('last', end($items['backup.monthly_day']['values']));
        // 저장: 쓰이지 않는 값(daily일 때 weekly_day)도 저장되며 무시. 감사 config.update 키마다
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['backup.enabled' => 'false', 'backup.weekly_day' => 'wed', 'backup.monthly_day' => 'last'],
            'version' => $admin->get('/api/v1/admin/config-settings')->decoded()['data']['version']]);
        $this->assertSame(200, $r->status, (string) $r->body);
        $this->assertSame(3, (int) $this->app()->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'config.update' AND target_id LIKE 'backup.%'"));
        $sum = $this->as('admin')->get('/api/v1/admin/backups')->decoded()['data'];
        $this->assertSame([false, 'daily', 'wed', 'last'], [$sum['schedule']['enabled'], $sum['schedule']['mode'], $sum['schedule']['weekly_day'], $sum['schedule']['monthly_day']]);
        $this->assertSame(null, $sum['next_daily'], '꺼짐 → 다음 예정 없음');
        $this->assertSame(null, $this->app()->backupRunner()->autoDue(), 'daily 규칙 그대로(쓰이지 않는 값은 무시) + 꺼짐');
        // 환경 변수가 화면 설정보다 우선
        $config = Config::load($this->ini, $this->dir . '/secrets.ini', ['PJG_BACKUP_ENABLED' => 'true', 'PJG_BACKUP_SCHEDULE_MODE' => 'weekly']);
        $this->assertSame([true, 'env', 'weekly'], [$config->bool('backup.enabled'), $config->source('backup.enabled'), $config->get('backup.schedule_mode')]);
        // 매주로 바꾸면 요약의 "오래됨" 기준 8일, 6일 전 백업은 오래되지 않음
        $r = $admin->put('/api/v1/admin/config-settings', ['changes' => ['backup.enabled' => 'true', 'backup.schedule_mode' => 'weekly'],
            'version' => $this->as('admin')->get('/api/v1/admin/config-settings')->decoded()['data']['version']]);
        $this->assertSame(200, $r->status, (string) $r->body);
        $app = $this->app();
        $app->backups()->state()->update(fn (array $s): array => ['last_success' => ['at' => gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()->getTimestamp() - 6 * 86400), 'file' => 'x', 'size' => 1, 'kind' => 'daily']] + $s);
        $sum = $this->as('admin')->get('/api/v1/admin/backups')->decoded()['data'];
        $this->assertSame([192, false], [$sum['schedule']['stale_hours'], $sum['stale']], '매주: 8일 기준');
        $this->assertSame('2026-10-06T17:30:00Z', $sum['next_daily'], '다음 수요일 02:30 KST');
    }
}
