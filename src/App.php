<?php

declare(strict_types=1);

namespace PJGuardian;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Middleware\CsrfMiddleware;
use PJGuardian\Http\Middleware\ErrorMiddleware;
use PJGuardian\Http\Middleware\HttpsMiddleware;
use PJGuardian\Http\Middleware\RequestIdMiddleware;
use PJGuardian\Http\Middleware\SecurityHeadersMiddleware;
use PJGuardian\Http\Middleware\SessionMiddleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Http\Router;
use PJGuardian\Http\SessionContext;
use PJGuardian\Services\Admin\AdminTasks;
use PJGuardian\Services\Admin\DiskUsage;
use PJGuardian\Services\Admin\SystemChecks;
use PJGuardian\Services\Admin\ToolAdmin;
use PJGuardian\Services\Admin\ToolStaging;
use PJGuardian\Services\Admin\WorkerControl;
use PJGuardian\Services\Analysis\AnalysisRunner;
use PJGuardian\Services\Analysis\FakeAnalysisRunner;
use PJGuardian\Services\AnalysisService;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Dashboard\DashboardService;
use PJGuardian\Services\Defects\AnalysisFinalizer;
use PJGuardian\Services\Defects\DefectCode;
use PJGuardian\Services\Defects\DefectQuery;
use PJGuardian\Services\Defects\DefectUpdate;
use PJGuardian\Services\Defects\SnapshotService;
use PJGuardian\Services\Events\EventDispatcher;
use PJGuardian\Services\Accounts\AccountImportService;
use PJGuardian\Services\Auth\AuthProviderRegistry;
use PJGuardian\Services\Auth\AuthSettings;
use PJGuardian\Services\Auth\LoginService;
use PJGuardian\Services\Auth\SessionService;
use PJGuardian\Services\GroupPermission;
use PJGuardian\Services\GroupService;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\ProjectService;
use PJGuardian\Services\Runs\RunService;
use PJGuardian\Services\Queue\AnalysisQueue;
use PJGuardian\Services\Queue\WorkerRecovery;
use PJGuardian\Services\Queue\WorkerState;
use PJGuardian\Services\Tools\EslintRunner;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\RuffConfigResolver;
use PJGuardian\Services\Tools\RuffRunner;
use PJGuardian\Services\Tools\ToolAnalysisRunner;
use PJGuardian\Services\Tools\ToolExecution;
use PJGuardian\Services\Tools\ToolInstaller;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Services\Tools\ToolSmokeTest;
use PJGuardian\Services\Upload\CleanupService;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Services\Upload\UploadAssembler;
use PJGuardian\Services\Archive\UnzipLimits;
use PJGuardian\Services\Upload\UploadFiles;
use PJGuardian\Services\Upload\UploadProcessor;
use PJGuardian\Services\Upload\UploadService;
use PJGuardian\Services\Upload\UploadSettings;
use PJGuardian\Services\UserService;
use PJGuardian\Support\Assets;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\Icons;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Support\Redact;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\View;

/**
 * 앱 조립(간단한 수동 의존성 연결)과 요청 처리.
 *
 * 전역 미들웨어 순서(바깥 → 안쪽):
 *   RequestId → SecurityHeaders → Error → Https → Session → Csrf → Router(라우트별 미들웨어 → 핸들러)
 *   - 보안 헤더는 오류 응답과 리다이렉트에도 붙는다.
 *   - HTTPS 거부(403)도 공통 오류 형식으로 나간다.
 *   - Session: 쿠키로 세션·사용자를 찾는다(쿠키가 없으면 DB를 열지 않음). Csrf: 상태 변경 요청 전부 검사(예외 경로 없음).
 *
 * DB와 서비스는 처음 쓸 때 만든다(db(), sessions() 등). 테스트는 생성자에 메모리 DB를 넣는다.
 */
final class App
{
    public const NAME = 'PJGuardian';
    public const VERSION = '1.0.0';

    public readonly Router $router;
    private ?AuthSettings $authSettings = null;
    private ?\Closure $maintenanceBackup = null;
    private ?SessionService $sessions = null;
    private ?AuditLog $audit = null;
    private ?\PJGuardian\Services\AppSettings $appSettings = null;
    private ?UserService $users = null;
    private ?LoginService $login = null;
    private ?ProjectService $projects = null;
    private ?GroupPermission $groupPermission = null;
    private ?GroupService $groups = null;
    private ?AuthProviderRegistry $authProviders = null;
    private ?AccountImportService $accountImport = null;
    /** 시험·격리 점검이 바꿔 끼우는 디렉터리 클라이언트(P28B). null이면 실제 PhpLdapClient */
    private ?\Closure $directoryFactory = null;
    /** 시험·격리 점검이 바꿔 끼우는 OIDC 전송·이름 풀이(P28C, 가짜 IdP). null이면 실제 소켓 전송·DNS */
    private ?\PJGuardian\Services\Auth\Oidc\HttpTransport $oidcTransport = null;
    private ?\Closure $oidcResolver = null;
    private ?ProjectAccess $projectAccess = null;
    private ?\PJGuardian\Support\AssetVersion $assetVersion = null;
    private ?UploadService $uploads = null;
    private ?DiskGuard $diskGuard = null;

    public function __construct(
        public readonly string $root,
        // P27A: 워커가 화면 설정 파일 변경을 다시 읽으면 replaceConfig()로 바꾼다(직접 대입하지 않는다)
        public Config $config,
        public readonly Clock $clock,
        public readonly Logger $logger,
        public readonly Paths $paths,
        private ?Db $db = null,
    ) {
        if ($config->localPath() === null) {
            // P27A: 배열로 만든 설정(시험)은 화면 설정 파일을 작업 폴더에 둔다(실제 config/settings.local.ini를 건드리지 않게)
            $this->config = $config->withLocalFile(rtrim(str_replace('\\', '/', $paths->work()), '/') . '/' . Config::LOCAL_FILE);
        }
        $this->router = new Router();
        (require __DIR__ . '/routes.php')($this->router, $this);
    }

    /** 웹·CLI 진입점이 쓰는 기본 조립. 설정이 틀리면 ConfigException. */
    public static function create(string $root, ?Config $config = null, ?Clock $clock = null): self
    {
        $config ??= Config::fromRoot($root);
        $clock ??= new SystemClock();
        $paths = new Paths($root, $config);
        $logger = new Logger($paths->logs(), $clock, $config->string('log.level'));
        return new self($root, $config, $clock, $logger, $paths);
    }

    /**
     * 설정 교체(P27A, 워커가 settings.local.ini 변경을 볼 때). 설정 값을 품고 재사용하는 서비스(디스크 감시·업로드)는 버리고 새 값으로 다시 만든다.
     * DB 접속·인증 설정은 편집 대상이 아닌 키만 쓰므로 그대로 둔다.
     */
    public function replaceConfig(Config $config): void
    {
        $this->config = $config;
        $this->diskGuard = null;
        $this->uploads = null;
    }

    // ---- 설정 관리(P27A) ----

    public function settingsStore(): \PJGuardian\Services\Settings\SettingsStore
    {
        return new \PJGuardian\Services\Settings\SettingsStore((string) $this->config->localPath());
    }

    /**
     * 경로 설정 검증(P27A·P27B 공유): 프로젝트 폴더·데이터 폴더(paths.*)·운영체제 폴더 금지.
     * 예외는 프로젝트 바로 아래 backups/(P27B 기본 백업 폴더, 사용자 결정 2026-10-08) — data/ 안(예전 paths.backups_dir 기본 data/backups 포함)은 계속 금지.
     */
    public function pathPolicy(): \PJGuardian\Services\Settings\PathPolicy
    {
        $p = $this->paths;
        return new \PJGuardian\Services\Settings\PathPolicy($this->root, [$this->root . '/public', $p->work(), $p->uploads(), $p->snapshots(), $p->logs(), $p->bin(), dirname($p->dbFile())],
            [$this->root . '/backups'], dirname($p->dbFile()), $this->internalRunner(), 5.0, $this->workRoot());
    }

    // ---- 백업·복원(P27B) ----

    public function backupPaths(): \PJGuardian\Services\Backup\BackupPaths
    {
        return new \PJGuardian\Services\Backup\BackupPaths($this->root, $this->config, $this->workRoot());
    }

    public function backups(): \PJGuardian\Services\Backup\BackupService
    {
        return new \PJGuardian\Services\Backup\BackupService($this->config, $this->backupPaths(), $this->pathPolicy(), $this->paths, $this->root, $this->clock,
            $this->logger, self::VERSION);
    }

    public function maintenance(): \PJGuardian\Services\Backup\MaintenanceMode
    {
        return new \PJGuardian\Services\Backup\MaintenanceMode($this->backupPaths()->maintenanceFlag(), $this->clock);
    }

    /** 시크릿 파일(설정 파일과 같은 폴더의 secrets.ini — 워커 --config·시험 설정도 같은 규칙) */
    public function secretsFile(): string
    {
        $ini = $this->config->iniPath();
        return $ini !== null ? dirname($ini) . '/secrets.ini' : $this->root . '/config/secrets.ini';
    }

    public function restoreService(): \PJGuardian\Services\Backup\RestoreService
    {
        return new \PJGuardian\Services\Backup\RestoreService($this->config, $this->backups(), $this->paths, $this->root, $this->clock, $this->logger,
            $this->maintenance(), $this->secretsFile());
    }

    public function backupJobs(): \PJGuardian\Services\Backup\BackupJobs
    {
        return new \PJGuardian\Services\Backup\BackupJobs($this->backupPaths(), $this->clock);
    }

    /** 작업 실행(자식)·일일 판단(워커). DB를 열 수 없으면(빈 설치) 알림·감사 없이 */
    public function backupRunner(bool $withDb = true): \PJGuardian\Services\Backup\BackupRunner
    {
        return new \PJGuardian\Services\Backup\BackupRunner($this->config, $this->backups(), $this->backupJobs(), $this->clock, $this->logger,
            $withDb ? $this->notifications() : null, $withDb ? $this->audit() : null, $this->restoreService());
    }

    public function bundleUpload(): \PJGuardian\Services\Backup\BundleUpload
    {
        return new \PJGuardian\Services\Backup\BundleUpload($this->backupPaths(), $this->backups(), $this->clock, $this->config->int('backup.upload_max_mb') * 1024 * 1024,
            $this->pathPolicy());
    }

    public function keyVault(): \PJGuardian\Services\Backup\KeyVault
    {
        return new \PJGuardian\Services\Backup\KeyVault($this->clock, $this->backupPaths()->runDir());
    }

    /**
     * DB 연결과 연결을 품은 재사용 서비스를 모두 버린다(P27B — 워커가 복원 자식을 실행하기 전, Windows는 열린 DB 파일을 바꿀 수 없다).
     * 다음 호출이 새로 연다.
     */
    public function resetConnections(): void
    {
        foreach (['db', 'authSettings', 'sessions', 'audit', 'users', 'login', 'groupPermission', 'groups', 'projects', 'appSettings', 'projectAccess', 'uploads',
            'diskGuard', 'events'] as $prop) {
            if (property_exists($this, $prop)) {
                $this->{$prop} = null;
            }
        }
        gc_collect_cycles();
    }

    public function settingsService(): \PJGuardian\Services\Settings\SettingsService
    {
        $beat = $this->workerState()->read();
        $since = $this->workerState()->secondsSinceBeat();
        $running = is_array($beat) && ($beat['state'] ?? '') !== 'stopped' && $since !== null && $since <= $this->config->int('worker.heartbeat_stale');
        $worker = ['running' => $running, 'version' => $running && is_string($beat['config']['version'] ?? null) ? $beat['config']['version'] : null,
            'beat_at' => is_array($beat) ? ($beat['beat_at'] ?? null) : null,
            'pending_restart' => $running && is_array($beat['config']['pending_restart'] ?? null) ? array_values(array_filter($beat['config']['pending_restart'], 'is_string')) : []];
        // 항상 지금 파일 기준(오래 사는 App — 워커·시험 — 이 시작 때 읽은 설정으로 판단하지 않게)
        $config = $this->config->reload();
        return new \PJGuardian\Services\Settings\SettingsService($config, $this->settingsStore(), $this->pathPolicy(), $this->audit(), $this->db(), $this->logger,
            static fn (): Config => $config->reload(), $worker, [
                // P27B: 백업·복원이 진행·대기 중이면 백업 폴더를 바꾸지 않는다(만들던 묶음·상태 파일이 두 폴더로 갈라지지 않게)
                'backup.dir' => fn (): ?string => $this->backupJobs()->active() !== null ? 'backup_busy'
                    : (is_file($this->backupPaths()->restoreRequest()) || $this->maintenance()->active() !== null ? 'restore_in_progress' : null),
            ], $this->maintenanceBackup ?? fn (\PJGuardian\Services\Actor $actor): array => $this->preMaintenanceBackup($actor));
    }

    /**
     * 업그레이드 유지보수 모드를 켜기 직전 DB 백업(P32B, 명세 19.4): 화면의 "지금 백업"과 같은 길(워커가 하는 백업 작업, pre_upgrade, 스냅샷 제외 —
     * 업그레이드는 DB 구조만 바꾼다)으로 요청하고 끝날 때까지 기다린다(maintenance.backup_wait). 워커가 없거나 백업이 실패·시간 초과면 켜지 않는다.
     *
     * @return array{ok: bool, code: ?string, name: ?string}
     */
    /** 시험: 유지보수 모드를 켜기 직전 백업 대신 (Actor) → {ok, code, name} */
    public function useMaintenanceBackup(?\Closure $backup): void
    {
        $this->maintenanceBackup = $backup;
    }

    public function preMaintenanceBackup(\PJGuardian\Services\Actor $actor): array
    {
        $beat = $this->workerState()->read();
        $since = $this->workerState()->secondsSinceBeat();
        if (!is_array($beat) || ($beat['state'] ?? '') === 'stopped' || $since === null || $since > $this->config->int('worker.heartbeat_stale')) {
            return ['ok' => false, 'code' => 'worker_not_running', 'name' => null];
        }
        try {
            $job = $this->backupJobs()->request(['type' => 'backup', 'kind' => 'pre_upgrade', 'snapshots' => false, 'by' => $actor->label, 'by_id' => $actor->userId]);
        } catch (\PJGuardian\Services\Backup\BackupException $e) {
            return ['ok' => false, 'code' => $e->errorCode, 'name' => null];
        }
        @set_time_limit(0);
        $deadline = microtime(true) + $this->config->int('maintenance.backup_wait');
        while (microtime(true) < $deadline) {
            $active = $this->backupJobs()->active();
            if ($active === null || ($active['id'] ?? null) !== $job['id']) {
                break;
            }
            usleep(300_000);
        }
        if (($this->backupJobs()->active()['id'] ?? null) === $job['id']) {
            return ['ok' => false, 'code' => 'maintenance_backup_timeout', 'name' => null];
        }
        $state = $this->backups()->state()->read();
        $ok = $state['last_success'] ?? null;
        if (is_array($ok) && ($ok['kind'] ?? '') === 'pre_upgrade' && (string) ($ok['at'] ?? '') >= (string) $job['requested_at']) {
            return ['ok' => true, 'code' => null, 'name' => (string) $ok['file']];
        }
        $fail = $state['last_failure'] ?? null;
        return ['ok' => false, 'code' => is_array($fail) ? (string) ($fail['code'] ?? 'unknown') : 'unknown', 'name' => null];
    }

    /** SQLite 접속(처음 부를 때 연다). SQLite 버전이 3.38 미만이면 예외. */
    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = Db::fromConfig($this->config, $this->paths);
            if (PHP_SAPI !== 'cli') {
                self::applyWebDbPragmas($this->db, $this->config);
            }
        }
        return $this->db;
    }

    /**
     * 웹 요청 연결 설정(P31 B-2): 자동 체크포인트를 database.web_wal_autocheckpoint(기본 0 = 끔)로 — 큰 반영 직후의 WAL을 웹 요청이 옮기지 않게.
     * 체크포인트는 워커(분석 사이·한가할 때 database.idle_checkpoint_interval마다). CLI 도구·워커·분석 자식은 이 함수를 거치지 않는다.
     */
    public static function applyWebDbPragmas(Db $db, Config $config): void
    {
        $db->pdo()->exec('PRAGMA wal_autocheckpoint = ' . max(0, $config->int('database.web_wal_autocheckpoint')));
    }

    public function authSettings(): AuthSettings
    {
        return $this->authSettings ??= AuthSettings::fromConfig($this->config);
    }

    public function sessions(): SessionService
    {
        return $this->sessions ??= new SessionService($this->db(), $this->clock, $this->authSettings());
    }

    private ?\PJGuardian\Services\Monitoring\SystemHealth $systemHealth = null;
    private ?\PJGuardian\Services\Monitoring\AdminAlertSink $alertSink = null;
    private ?Logger $alertLogger = null;

    public function audit(): AuditLog
    {
        return $this->audit ??= new AuditLog($this->db(), $this->clock);
    }

    public function users(): UserService
    {
        return $this->users ??= new UserService($this->db(), $this->clock, $this->authSettings(), $this->sessions(), $this->audit(), $this->logger, $this->authProviders());
    }

    /** 켜진 인증 방식 목록(P28A — CSV 일괄 처리·사용자 생성이 쓴다. P28B~D가 방식을 더함) */
    public function authProviders(): AuthProviderRegistry
    {
        // P28B: LDAP이 켜져 있고 마이그레이션 026(외부 인증 열)이 적용됐으면 ldap도 — CSV·사용자 생성·인증 방식 전환이 이 목록을 쓴다
        $ldap = $this->config->bool('ldap.enabled') && \PJGuardian\Support\SchemaFeatures::externalAuth($this->db());
        // P28C: OIDC가 켜져 있고 026·027이 적용됐으면 oidc도
        $oidc = $this->oidcEnabled();
        // P28D: SAML이 켜져 있고 026·027이 적용됐으면 saml도
        $saml = $this->samlEnabled();
        return $this->authProviders ??= new AuthProviderRegistry(array_merge(['local'], $ldap ? ['ldap'] : [], $oidc ? ['oidc'] : [], $saml ? ['saml'] : []));
    }

    /** LDAP 설정 값(P28B) */
    public function ldapSettings(): \PJGuardian\Services\Auth\Ldap\LdapSettings
    {
        return \PJGuardian\Services\Auth\Ldap\LdapSettings::fromConfig($this->config);
    }

    /** 앱 전역 암호화 비밀(P28B, LDAP 검색 계정 비밀번호) */
    public function appSecrets(): \PJGuardian\Services\Auth\AppSecrets
    {
        return new \PJGuardian\Services\Auth\AppSecrets($this->db(), fn (): \PJGuardian\Services\Repo\SecretBox => $this->secretBox(), $this->clock);
    }

    /** 시험·격리 점검용: 디렉터리 클라이언트를 바꿔 끼운다(실제 LDAP 서버에 연결하지 않음) */
    public function useDirectoryClient(\Closure $factory): void
    {
        $this->directoryFactory = $factory;
        $this->login = null;
    }

    /** 새 디렉터리 클라이언트(요청마다). 검색 계정 비밀번호는 바인드할 때만 복호화 */
    public function directoryClient(): \PJGuardian\Services\Auth\Ldap\DirectoryClient
    {
        if ($this->directoryFactory !== null) {
            return ($this->directoryFactory)();
        }
        $secrets = $this->appSecrets();
        return new \PJGuardian\Services\Auth\Ldap\PhpLdapClient($this->ldapSettings(), new \PJGuardian\Services\Auth\Ldap\ExtLdapTransport(), static function () use ($secrets): string {
            try {
                return $secrets->get('ldap.bind_password');
            } catch (\PJGuardian\Services\Repo\RepoException) {
                throw new \PJGuardian\Services\Auth\Ldap\DirectoryException('secret_unreadable');
            }
        });
    }

    /**
     * 로그인 화면의 SSO 단추(P28B 자리 — P28C OIDC, P28D SAML). href는 배포 경로를 뺀 앱 경로(화면이 붙임).
     * label이 null이면 화면 언어의 기본 문구(login.sso_oidc).
     *
     * @return list<array{id:string, label:?string, href:string}>
     */
    public function ssoProviders(): array
    {
        $out = [];
        if ($this->oidcEnabled() && $this->oidcSettings()->complete()) {
            $label = $this->oidcSettings()->buttonLabel;
            $out[] = ['id' => 'oidc', 'label' => $label === '' ? null : $label, 'href' => '/auth/oidc/start'];
        }
        if ($this->samlEnabled() && $this->samlSettings()->complete()) {
            $label = $this->samlSettings()->buttonLabel;
            $out[] = ['id' => 'saml', 'label' => $label === '' ? null : $label, 'href' => '/auth/saml/start'];
        }
        return $out;
    }

    /** SAML 로그인을 쓸 수 있는가: 설정이 켜져 있고 마이그레이션 026·027이 적용됐고 라이브러리(vendor/ — composer install)가 있음(P28D) */
    public function samlEnabled(): bool
    {
        return $this->config->bool('saml.enabled') && \PJGuardian\Services\Auth\Saml\SamlSettings::libraryAvailable()
            && \PJGuardian\Support\SchemaFeatures::saml($this->db());
    }

    public function samlSettings(): \PJGuardian\Services\Auth\Saml\SamlSettings
    {
        return \PJGuardian\Services\Auth\Saml\SamlSettings::fromConfig($this->config);
    }

    /** SAML 로그인(P28D). 서명 검증은 라이브러리(onelogin/php-saml). SP 키는 필요할 때만 복호화 */
    public function samlLogin(): \PJGuardian\Services\Auth\Saml\SamlLogin
    {
        $secrets = $this->appSecrets();
        $dir = $this->workRoot() . '/saml';
        return new \PJGuardian\Services\Auth\Saml\SamlLogin($this->samlSettings(),
            new \PJGuardian\Services\Auth\Saml\OneTimeStore($dir . '/requests', 600, $this->clock),
            new \PJGuardian\Services\Auth\Saml\OneTimeStore($dir . '/finish', 120, $this->clock),
            new \PJGuardian\Services\Auth\Saml\ReplayGuard($dir . '/assertions', $this->clock),
            static function () use ($secrets): ?array {
                try {
                    if ($secrets->state('saml.sp_private_key') !== 'set' || $secrets->state('saml.sp_cert') !== 'set') {
                        return null;
                    }
                    return ['key' => $secrets->get('saml.sp_private_key'), 'cert' => $secrets->get('saml.sp_cert')];
                } catch (\PJGuardian\Services\Repo\RepoException | \InvalidArgumentException) {
                    throw new \PJGuardian\Services\Auth\Saml\SamlException('sp_key_unreadable');
                }
            }, $this->clock, $this->logger);
    }

    /** 관리자 SSO(SAML) 화면(P28D: 상태·SP 키·메타데이터 가져오기·설정 확인·점검·인증서 만료 알림) */
    public function samlAdmin(): \PJGuardian\Services\Auth\Saml\SamlAdmin
    {
        $s = $this->samlSettings();
        $ca = $this->config->get('repository.ca_cert_file');
        $transport = $this->oidcTransport ?? new \PJGuardian\Services\Auth\Oidc\StreamHttpTransport(is_string($ca) && $ca !== '' ? $ca : null);
        // 외부 요청(SSO 주소 도달·메타데이터 가져오기)은 OIDC와 같은 정책 — 주소 판정 기준은 요청하는 주소 자신
        $http = fn (string $anchor): \PJGuardian\Services\Auth\Oidc\OidcHttp => new \PJGuardian\Services\Auth\Oidc\OidcHttp($transport, $anchor, $s->allowLocalDev, $this->logger, $this->oidcResolver);
        return new \PJGuardian\Services\Auth\Saml\SamlAdmin($s, $this->samlLogin(), $http, $this->appSecrets(),
            \PJGuardian\Support\SchemaFeatures::saml($this->db()), \PJGuardian\Support\SchemaFeatures::samlCertNotice($this->db()),
            $this->audit(), $this->logger, $this->notifications(), $this->clock, $this->workRoot());
    }

    /** OIDC 로그인을 쓸 수 있는가: 설정이 켜져 있고 마이그레이션 026·027이 적용됨(P28C) */
    public function oidcEnabled(): bool
    {
        return $this->config->bool('oidc.enabled') && \PJGuardian\Support\SchemaFeatures::oidc($this->db());
    }

    public function oidcSettings(): \PJGuardian\Services\Auth\Oidc\OidcSettings
    {
        return \PJGuardian\Services\Auth\Oidc\OidcSettings::fromConfig($this->config);
    }

    /** 시험·격리 점검용: SSO 외부 요청(OIDC 디스커버리·토큰, SAML 메타데이터·SSO 주소 도달)을 가짜 IdP로 바꿔 끼운다(실제 IdP에 연결하지 않음) */
    public function useOidcTransport(\PJGuardian\Services\Auth\Oidc\HttpTransport $transport, ?\Closure $resolver = null): void
    {
        $this->oidcTransport = $transport;
        $this->oidcResolver = $resolver;
    }

    public function oidcHttp(): \PJGuardian\Services\Auth\Oidc\OidcHttp
    {
        $ca = $this->config->get('repository.ca_cert_file'); // 사내 CA(저장소 연결과 같은 파일)
        $transport = $this->oidcTransport ?? new \PJGuardian\Services\Auth\Oidc\StreamHttpTransport(is_string($ca) && $ca !== '' ? $ca : null);
        $s = $this->oidcSettings();
        return new \PJGuardian\Services\Auth\Oidc\OidcHttp($transport, $s->issuer, $s->allowLocalDev, $this->logger, $this->oidcResolver);
    }

    public function oidcProvider(?\PJGuardian\Services\Auth\Oidc\OidcHttp $http = null): \PJGuardian\Services\Auth\Oidc\OidcProvider
    {
        return new \PJGuardian\Services\Auth\Oidc\OidcProvider($this->oidcSettings(), $http ?? $this->oidcHttp(), $this->clock, $this->workRoot() . '/oidc/cache', $this->logger);
    }

    public function oidcStates(): \PJGuardian\Services\Auth\Oidc\OidcStateStore
    {
        return new \PJGuardian\Services\Auth\Oidc\OidcStateStore($this->workRoot() . '/oidc/state', $this->clock);
    }

    /** OIDC 로그인 프로토콜(P28C). 클라이언트 비밀은 토큰 교환 때만 복호화 */
    public function oidcLogin(): \PJGuardian\Services\Auth\Oidc\OidcLogin
    {
        $http = $this->oidcHttp();
        $secrets = $this->appSecrets();
        return new \PJGuardian\Services\Auth\Oidc\OidcLogin($this->oidcSettings(), $this->oidcProvider($http), $http, $this->oidcStates(),
            static function () use ($secrets): string {
                try {
                    return $secrets->get('oidc.client_secret');
                } catch (\PJGuardian\Services\Repo\RepoException | \InvalidArgumentException) {
                    throw new \PJGuardian\Services\Auth\Oidc\OidcException('secret_unreadable');
                }
            }, $this->clock, $this->logger);
    }

    /** 관리자 SSO(OIDC) 화면(P28C: 상태·클라이언트 비밀·연결 시험·점검) */
    public function oidcAdmin(): \PJGuardian\Services\Auth\Oidc\OidcAdmin
    {
        $http = $this->oidcHttp();
        return new \PJGuardian\Services\Auth\Oidc\OidcAdmin($this->oidcSettings(), $this->oidcProvider($http), $http, $this->appSecrets(),
            \PJGuardian\Support\SchemaFeatures::oidc($this->db()), $this->audit(), $this->logger, $this->clock, $this->workRoot());
    }

    /** 관리자 LDAP 화면(P28B: 상태·검색 계정 비밀번호·연결 시험) */
    public function ldapAdmin(): \PJGuardian\Services\Auth\Ldap\LdapAdmin
    {
        return new \PJGuardian\Services\Auth\Ldap\LdapAdmin($this->ldapSettings(), $this->appSecrets(), fn (): \PJGuardian\Services\Auth\Ldap\DirectoryClient => $this->directoryClient(),
            $this->directoryFactory !== null || extension_loaded('ldap'), $this->audit(), $this->logger, $this->clock, $this->workRoot(), $this->config->int('ldap.test_per_minute'));
    }

    /** 외부 인증 계정 공통(P28B 승인 대기·승인·거절·정리) */
    public function externalAccounts(): \PJGuardian\Services\Auth\ExternalAccountProvisioner
    {
        return new \PJGuardian\Services\Auth\ExternalAccountProvisioner($this->db(), $this->clock, $this->audit(), $this->logger, $this->notifications(),
            $this->config->int('auth.pending_max'), $this->config->int('auth.pending_retention_days'));
    }

    /** 계정 CSV 일괄 생성·비활성화(P28A) */
    public function accountImport(): AccountImportService
    {
        return $this->accountImport ??= new AccountImportService($this->db(), $this->clock, $this->authSettings(), $this->sessions(), $this->audit(), $this->logger, $this->authProviders());
    }

    public function login(): LoginService
    {
        if ($this->login !== null) {
            return $this->login;
        }
        // P28B: LDAP이 켜져 있고 026이 적용됐을 때만 LDAP 분기(꺼져 있으면 ldap 계정은 일반 실패)
        $ldap = null;
        if ($this->config->bool('ldap.enabled') && \PJGuardian\Support\SchemaFeatures::externalAuth($this->db())) {
            $ldap = new \PJGuardian\Services\Auth\Ldap\LdapLogin($this->ldapSettings(), fn (): \PJGuardian\Services\Auth\Ldap\DirectoryClient => $this->directoryClient(),
                $this->externalAccounts(), $this->logger);
        }
        // P28C: SSO(OIDC) 콜백의 계정 결정은 공통 서비스(026 뒤에만)
        $external = \PJGuardian\Support\SchemaFeatures::externalAuth($this->db()) ? $this->externalAccounts() : null;
        return $this->login ??= new LoginService($this->db(), $this->clock, $this->authSettings(), $this->logger, null, $ldap, $external);
    }

    /** 그룹 권한 판정(명세 3.1). 그룹 관련 권한은 이것만 쓴다 */
    public function groupPermission(): GroupPermission
    {
        return $this->groupPermission ??= new GroupPermission($this->db());
    }

    public function groups(): GroupService
    {
        return $this->groups ??= new GroupService($this->db(), $this->clock, $this->groupPermission(), $this->users(), $this->audit(), $this->logger);
    }

    public function projects(): ProjectService
    {
        return $this->projects ??= new ProjectService(
            $this->db(), $this->clock, $this->paths, $this->audit(), $this->logger,
            (array) $this->config->get('extract.default_excludes'),
            $this->projectAccess(),
        );
    }

    public function uploadSettings(): UploadSettings
    {
        return UploadSettings::fromConfig($this->config);
    }

    /** 업로드 파일 배치: <uploads_dir>/<upload_id>/ (docs/architecture.md 17.2) */
    public function uploadFiles(): UploadFiles
    {
        return new UploadFiles(rtrim(str_replace('\\', '/', $this->paths->uploads()), '/'));
    }

    /** 디스크 사전 검사(업로드 P12, 저장소 가져오기 P25) */
    public function diskGuard(): DiskGuard
    {
        return $this->diskGuard ??= new DiskGuard($this->db(), $this->uploadSettings(), $this->logger);
    }

    // ---- 소스 저장소 연동(P25) ----

    /** @var array{git?: list<string>, p4?: list<string>} 시험용 실행 명령 바꾸기(가짜 p4) */
    private array $repoCommandOverride = [];
    /** @var (\Closure(string): list<string>)|null 시험용 이름 풀이 */
    private ?\Closure $repoResolver = null;

    /** @param array{git?: list<string>, p4?: list<string>} $override */
    public function setRepoTesting(array $override, ?\Closure $resolver = null): void
    {
        $this->repoCommandOverride = $override;
        $this->repoResolver = $resolver;
    }

    /** 자격 증명 암호화(키: secrets encryption_key, 이전 키 encryption_key_previous — DB에 두지 않는다) */
    public function secretBox(): \PJGuardian\Services\Repo\SecretBox
    {
        return new \PJGuardian\Services\Repo\SecretBox([$this->config->secret('encryption_key'), $this->config->secret('encryption_key_previous')]);
    }

    public function repoHostPolicy(): \PJGuardian\Services\Repo\HostPolicy
    {
        return new \PJGuardian\Services\Repo\HostPolicy($this->db(), $this->logger, $this->config->bool('repository.allow_local_dev'), $this->repoResolver);
    }

    public function repoAllowedHosts(): \PJGuardian\Services\Repo\AllowedHosts
    {
        return new \PJGuardian\Services\Repo\AllowedHosts($this->db(), $this->audit(), $this->clock, $this->config->int('notification.cert_expiry_warn'));
    }

    /** 허용 호스트 연결 테스트(P26B): 이름 풀이·TCP·TLS·HTTP 상태 줄 — 저장소·자격 증명 없이 */
    public function repoHostProbe(): \PJGuardian\Services\Repo\HostProbe
    {
        $ca = $this->config->get('repository.ca_cert_file');
        return new \PJGuardian\Services\Repo\HostProbe($this->repoHostPolicy(), $this->clock, $this->logger, is_string($ca) && $ca !== '' ? $ca : null,
            $this->config->int('notification.cert_expiry_warn'), (float) $this->config->int('repository.host_test_tcp_timeout'),
            (float) $this->config->int('repository.host_test_tls_timeout'), (float) $this->config->int('repository.host_test_http_timeout'));
    }

    public function repoHostTests(): \PJGuardian\Services\Repo\HostTests
    {
        return new \PJGuardian\Services\Repo\HostTests($this->db(), $this->clock, $this->audit(), $this->repoAllowedHosts(), $this->repoHostProbe(), $this->repoService(),
            $this->config->int('repository.host_test_max'));
    }

    public function repoConnections(): \PJGuardian\Services\Repo\RepoConnections
    {
        return new \PJGuardian\Services\Repo\RepoConnections($this->db(), $this->clock, $this->audit(), $this->secretBox(), $this->repoHostPolicy());
    }

    public function repoTools(): \PJGuardian\Services\Repo\RepoTools
    {
        $ca = $this->config->get('repository.ca_cert_file');
        return new \PJGuardian\Services\Repo\RepoTools($this->toolRegistry(), new ProcessRunner([$this->paths->bin()], $this->repoCommandOverride === [] ? [] : [PHP_BINARY]),
            $this->logger, $this->workRoot() . '/tools/tmp', is_string($ca) && $ca !== '' ? $ca : null, $this->repoCommandOverride);
    }

    /** 가져오기 단계(분석 자식 프로세스) */
    public function repoImport(): \PJGuardian\Services\Repo\RepoImport
    {
        return new \PJGuardian\Services\Repo\RepoImport(
            $this->db(), $this->clock, $this->logger, $this->repoConnections(), $this->repoHostPolicy(), $this->repoTools(), $this->diskGuard(),
            $this->projects(), UnzipLimits::fromConfig($this->config), $this->workRoot(), $this->config->int('repository.fetch_timeout'),
            $this->uploadSettings()->targetExtensions,
        );
    }

    /** 분석 조건 키(P27): 도구·버전·서버 설정 해시(규칙 켜기·끄기)·개정·제외 패턴·대상 확장자 */
    public function analysisEnvKey(): \PJGuardian\Services\Analysis\AnalysisEnvKey
    {
        return new \PJGuardian\Services\Analysis\AnalysisEnvKey($this->db(), $this->toolRegistry(), $this->projects(), $this->ruleOverrides(), $this->eslintRunner(),
            $this->root . '/config/ruff.default.toml', $this->uploadSettings()->targetExtensions);
    }

    private ?\PJGuardian\Services\Repo\UnchangedSourceCheck $unchangedCheckOverride = null;

    /** 시험용: 원격 조회·조건 키를 주입한 판단(P27C 스케줄러 경로 시험) */
    public function setUnchangedCheck(\PJGuardian\Services\Repo\UnchangedSourceCheck $check): void
    {
        $this->unchangedCheckOverride = $check;
    }

    /** "변경 없음" 판단(P27): 원격 최신 리비전(연결 테스트와 같은 호출, repository.test_timeout) + 기준 분석과 연결·조건 비교 */
    public function unchangedCheck(): \PJGuardian\Services\Repo\UnchangedSourceCheck
    {
        if ($this->unchangedCheckOverride !== null) {
            return $this->unchangedCheckOverride;
        }
        $head = new \PJGuardian\Services\Repo\RepoHead($this->repoHostPolicy(), $this->repoTools(), $this->workRoot());
        $connections = $this->repoConnections();
        $timeout = (float) $this->config->int('repository.test_timeout');
        $env = $this->analysisEnvKey();
        return new \PJGuardian\Services\Repo\UnchangedSourceCheck($this->db(), $this->clock, $this->logger,
            static fn (int $projectId): array => $head->lookup($connections->target($projectId), $timeout),
            static fn (int $projectId): ?string => $env->compute($projectId));
    }

    /** 연결 테스트·지금 가져와 분석(웹 요청) */
    public function repoService(): \PJGuardian\Services\Repo\RepoService
    {
        return new \PJGuardian\Services\Repo\RepoService(
            $this->db(), $this->clock, $this->logger, $this->audit(), $this->repoConnections(), $this->repoHostPolicy(), $this->repoTools(),
            $this->diskGuard(), $this->workRoot(), $this->config->int('repository.test_timeout'), $this->config->int('repository.test_per_minute'),
            $this->config->int('extract.max_extracted_size'),
        );
    }

    // ---- 주기 분석(P26) ----

    public function schedules(): \PJGuardian\Services\Schedule\ScheduleService
    {
        return new \PJGuardian\Services\Schedule\ScheduleService($this->db(), $this->clock, $this->audit(), $this->repoConnections(),
            intdiv($this->config->int('repository.min_interval'), 86400));
    }

    /** 결과 반영·관리자 알림(분석 종료 이벤트 구독) */
    public function scheduleOutcomes(): \PJGuardian\Services\Schedule\ScheduleOutcomes
    {
        return new \PJGuardian\Services\Schedule\ScheduleOutcomes($this->db(), $this->clock, $this->logger, $this->notifications());
    }

    /** 워커의 주기 작업(도래 확인 → 분석 등록) */
    public function scheduleTicker(): \PJGuardian\Services\Schedule\ScheduleTicker
    {
        return new \PJGuardian\Services\Schedule\ScheduleTicker(
            $this->db(), $this->clock, $this->logger, $this->repoConnections(), $this->schedules(), new \PJGuardian\Services\Schedule\ScheduledAnalysisLauncher(),
            $this->diskGuard(), $this->scheduleOutcomes(), $this->workRoot(), $this->config->int('extract.max_extracted_size'), $this->config->int('scheduler.max_per_tick'),
            $this->unchangedCheck(),
        );
    }

    /** 가져오기 재시도(분석 자식) */
    public function scheduleRetry(): \PJGuardian\Services\Schedule\ScheduleRetry
    {
        return new \PJGuardian\Services\Schedule\ScheduleRetry($this->db(), $this->clock, $this->logger,
            $this->config->int('repository.connect_max_attempts'), $this->config->int('repository.connect_retry_interval'), $this->workRoot());
    }

    /** 관리자 주기 분석 현황(화면과 분리한 조회) */
    public function scheduleStatus(): \PJGuardian\Services\Schedule\ScheduleStatus
    {
        return new \PJGuardian\Services\Schedule\ScheduleStatus($this->db(), $this->clock, $this->workerState(), $this->config->int('scheduler.tick_seconds'));
    }

    /** 시험용: 여유 공간 모의 등 */
    public function setDiskGuard(DiskGuard $guard): void
    {
        $this->diskGuard = $guard;
        $this->uploads = null;
    }

    public function uploads(): UploadService
    {
        return $this->uploads ??= new UploadService($this->db(), $this->clock, $this->uploadSettings(), $this->uploadFiles(), $this->diskGuard(), $this->logger, $this->workRoot());
    }

    public function uploadAssembler(): UploadAssembler
    {
        return new UploadAssembler($this->db(), $this->clock, $this->uploadFiles(), $this->logger);
    }

    /** 업로드 후처리: 해제 → 사전 검사 → 경고 확인 → 분석 큐(P13). 워커(P14)와 tools/process_upload.php가 부른다 */
    public function uploadProcessor(): UploadProcessor
    {
        return new UploadProcessor(
            $this->db(), $this->clock, $this->paths, $this->uploadFiles(), $this->uploadSettings(),
            UnzipLimits::fromConfig($this->config), $this->projects(), $this->logger,
        );
    }

    public function cleanup(): CleanupService
    {
        return new CleanupService(
            $this->db(), $this->clock, $this->uploadSettings(), $this->uploadFiles(), $this->logger, $this->workRoot(),
            $this->workerRecovery(), rtrim(str_replace('\\', '/', $this->paths->snapshots()), '/'),
            $this->config->int('log.error_access_retention'), $this->snapshots(), $this->toolStaging(), $this->config->int('tools.staging_retention'),
            \PJGuardian\Support\SchemaFeatures::externalAuth($this->db()) ? fn (bool $dry): int => $this->externalAccounts()->purge($dry) : null,
            $this->auditRetention(),
            fn (bool $dry): int => $this->alertMailer()->purgeResolved($dry),
        );
    }

    /** 분석 큐(명세 5.2, 19.8): 원자적 선점, 결과 기록, 대기 순번 */
    public function analysisQueue(): AnalysisQueue
    {
        return new AnalysisQueue($this->db(), $this->clock);
    }

    /** 분석 조회·취소 API(P14) */
    public function analyses(): AnalysisService
    {
        return new AnalysisService($this->db(), $this->clock, $this->analysisQueue(), $this->logger, $this->events());
    }

    /** WAL 체크포인트(P20S): 워커가 분석 사이·시작 때 실행 */
    public function walCheckpoint(): \PJGuardian\Support\WalCheckpoint
    {
        return new \PJGuardian\Support\WalCheckpoint($this->db(), $this->logger, $this->config->int('database.wal_truncate_threshold'),
            $this->config->int('database.checkpoint_max_block_ms'), $this->config->int('database.busy_timeout') * 1000);
    }

    /** 워커 파일 상태: <work_dir>/worker/(잠금, 생존 신호, 정지 요청) */
    public function workerState(): WorkerState
    {
        return new WorkerState($this->workRoot() . '/worker', $this->clock);
    }

    public function workerRecovery(): WorkerRecovery
    {
        return new WorkerRecovery(
            $this->db(), $this->clock, $this->analysisQueue(), $this->workerState(), $this->workRoot(), $this->logger,
            $this->config->int('worker.heartbeat_stale'), $this->config->int('worker.stale_safety'), $this->events(),
        );
    }

    /** 분석 도구(ruff, ESLint) 실행기: bin/ 아래와 Node 런타임(설정 tools.node_path, 비우면 PATH의 node)만 허용 */
    /** 규칙 데이터 적재(P22) */
    public function ruleLoader(): \PJGuardian\Services\Rules\RuleLoader
    {
        return new \PJGuardian\Services\Rules\RuleLoader($this->db(), $this->ruleExtractor(), $this->toolRegistry(), $this->severityMap(),
            $this->ruleEnrichment(), $this->audit(), $this->clock,
            $this->root . '/resources/rules/requires_options.json');
    }

    /** 내보내기(P23): 결함·규칙 목록 → CSV·XLSX. 임시 파일·잠금은 <work_dir>/export */
    public function exports(): \PJGuardian\Services\Export\ExportService
    {
        return new \PJGuardian\Services\Export\ExportService($this->workRoot() . '/export', $this->config->int('export.max_rows'),
            $this->config->int('export.max_concurrent'), $this->config->int('export.max_per_user'), (float) $this->config->int('export.timeout_seconds'),
            $this->config->int('export.memory_limit'), new \DateTimeZone($this->config->string('app.display_timezone')), $this->logger);
    }

    /** 관리자 규칙 켜기·끄기(P22B): 서버 기본 설정 위에 얹는 규칙별 변경 */
    public function ruleOverrides(): \PJGuardian\Services\Rules\RuleOverrides
    {
        return new \PJGuardian\Services\Rules\RuleOverrides($this->db(), $this->audit(), $this->clock, $this->logger, $this->config->int('rules.override_reset_max'),
            $this->config->int('rules.bulk_max'));
    }

    /** 규칙 설명 조회(P22) */
    public function ruleQuery(): \PJGuardian\Services\Rules\RuleQuery
    {
        return new \PJGuardian\Services\Rules\RuleQuery($this->db(), $this->projectAccess(), $this->adminTasks(), $this->ruleEnrichment());
    }

    /** 규칙 보강 자료(P22)·쉬운 설명(P32B) — resources/rules/enrichment(읽기 전용, 요청마다 파일에서) */
    public function ruleEnrichment(): \PJGuardian\Services\Rules\RuleEnrichment
    {
        return new \PJGuardian\Services\Rules\RuleEnrichment($this->root . '/resources/rules/enrichment');
    }

    /** 설치된 도구에서 규칙 정보 추출(P21S 카탈로그·P22 규칙 설명) */
    public function ruleExtractor(): \PJGuardian\Services\Rules\RuleExtractor
    {
        return new \PJGuardian\Services\Rules\RuleExtractor($this->toolRegistry(), $this->toolRunner(), $this->nodeRuntime(), $this->root, $this->workRoot());
    }

    public function toolRunner(): ProcessRunner
    {
        $node = $this->nodeRuntime()->path();
        return new ProcessRunner([$this->paths->bin()], $node !== null ? [$node] : []);
    }

    private ?NodeRuntime $nodeRuntime = null;

    /** ESLint용 Node 런타임(명세 19.10: node_path, 최소 버전 검사) */
    public function nodeRuntime(): NodeRuntime
    {
        $p = $this->config->get('tools.node_path');
        return $this->nodeRuntime ??= new NodeRuntime(is_string($p) ? $p : null, $this->config->string('tools.node_min_version'));
    }

    /** 도구 실행 공통 조건(한도, 임시 폴더 <work_dir>/tools/tmp) */
    public function toolExecution(): ToolExecution
    {
        return new ToolExecution(
            $this->toolRunner(), $this->config->int('analysis.timeout'), $this->config->int('analysis.output_limit'),
            $this->config->int('analysis.process_memory_limit'), $this->workRoot() . '/tools/tmp',
        );
    }

    public function ruffRunner(): RuffRunner
    {
        return new RuffRunner($this->toolExecution(), new RuffConfigResolver($this->root . '/config/ruff.default.toml'), $this->logger);
    }

    public function eslintRunner(): EslintRunner
    {
        return new EslintRunner(
            $this->toolExecution(), $this->root, $this->root . '/config/eslint.default.json',
            (array) $this->config->get('upload.target_extensions_javascript'), $this->logger,
        );
    }

    private ?ToolRegistry $toolRegistryOverride = null;

    /** 시험용: 가짜 스모크 테스트를 넣은 레지스트리(P20A 관리자 화면·설치 시험) */
    public function setToolRegistry(ToolRegistry $registry): void
    {
        $this->toolRegistryOverride = $registry;
    }

    /** 도구 레지스트리(bin/ 버전 선택·체크섬·스모크·차단·고정, 상태 tool_versions + <work_dir>/tools/ 지문 캐시) */
    public function toolRegistry(): ToolRegistry
    {
        if ($this->toolRegistryOverride !== null) {
            return $this->toolRegistryOverride;
        }
        $smoke = new ToolSmokeTest($this->ruffRunner(), $this->eslintRunner(), $this->root . '/samples', $this->workRoot() . '/tools/tmp',
            (array) $this->config->get('extract.default_excludes'), new ProcessRunner([$this->paths->bin()]));
        return new ToolRegistry(
            $this->db(), $this->clock, $this->logger, $this->paths->bin(), $this->workRoot() . '/tools', $this->nodeRuntime(),
            static fn (\PJGuardian\Services\Tools\SelectedTool $t): array => $smoke->run($t),
        );
    }

    // ---- 관리자 시스템 관리(P20A) ----

    /** 관리 작업 요청 표(웹은 넣기만, 워커 자식이 실행) */
    public function adminTasks(): AdminTasks
    {
        return new AdminTasks($this->db(), $this->clock);
    }

    /** 도구 설치 패키지 스테이징: <work_dir>/tool_staging/<id>/ (웹은 보관만) */
    public function toolStaging(): ToolStaging
    {
        return new ToolStaging(
            $this->db(), $this->clock, $this->workRoot() . '/tool_staging', $this->config->int('tools.package_max_size'),
            $this->config->int('upload.chunk_size'), $this->diskGuard(), $this->logger,
        );
    }

    public function toolAdmin(): ToolAdmin
    {
        return new ToolAdmin($this->toolRegistry(), $this->adminTasks(), $this->audit(), $this->config->bool('rules.auto_load'));
    }

    /** 도구 설치·삭제(서버 CLI 전용, 웹에서 쓰지 않음). $purgeTrash가 null이면 같은 프로세스에서 휴지통을 비운다 */
    public function toolInstaller(?\Closure $purgeTrash = null): ToolInstaller
    {
        $limits = new UnzipLimits($this->config->int('tools.package_max_size'), $this->config->int('extract.max_extracted_size'), $this->config->int('extract.max_files'));
        return new ToolInstaller($this->db(), $this->toolRegistry(), $this->toolStaging(), $limits, $this->paths->bin(), $this->workRoot(), $this->logger, $purgeTrash);
    }

    /** 화면에 내보내는 문자열의 경로·비밀값 가리기(알려진 폴더 → 이름표) */
    public function redact(): Redact
    {
        $roots = [
            'app' => $this->root, 'work' => $this->paths->work(), 'uploads' => $this->paths->uploads(), 'snapshots' => $this->paths->snapshots(),
            'logs' => $this->paths->logs(), 'backups' => $this->backupPaths()->dir(), 'bin' => $this->paths->bin(), 'db' => dirname($this->paths->dbFile()),
            'php' => dirname(PHP_BINARY), 'tmp' => sys_get_temp_dir(),
        ];
        return new Redact($roots);
    }

    public function workerControl(): WorkerControl
    {
        return new WorkerControl(
            $this->db(), $this->clock, $this->workerState(), $this->audit(), $this->logger, $this->redact(),
            $this->config->int('worker.heartbeat_stale'), $this->config->int('analysis.max_concurrent'), $this->config->bool('worker.supervised'),
            $this->config->string('worker.start_command'), $this->config->int('worker.start_timeout'), $this->procOpenProbe,
        );
    }

    /** @var (\Closure(): bool)|null */
    private ?\Closure $procOpenProbe = null;

    /** 시험용: proc_open 사용 가능 여부를 바꾼다(시작 버튼 숨김 시험) */
    public function setProcOpenProbe(?\Closure $probe): void
    {
        $this->procOpenProbe = $probe;
    }

    public function diskUsage(): DiskUsage
    {
        return new DiskUsage([
            'uploads' => $this->paths->uploads(), 'work' => $this->workRoot(), 'snapshots' => $this->paths->snapshots(),
            'logs' => $this->paths->logs(), 'backups' => $this->backupPaths()->dir(), 'db' => $this->paths->dbFile(),
        ], $this->clock);
    }

    public function systemChecks(): SystemChecks
    {
        $example = $this->root . '/config/config.ini.example';
        try {
            $defaults = Config::load($example);
        } catch (\Throwable) {
            $defaults = null;
        }
        return new SystemChecks($this->db(), $this->clock, $this->config, $defaults, $this->root, $this->workRoot(), $this->diskUsage(), $this->adminTasks());
    }

    /** @return array<string, string> 쓰기 점검 대상 data 폴더 */
    public function dataDirs(): array
    {
        return [
            'db' => dirname($this->paths->dbFile()), 'uploads' => $this->paths->uploads(), 'work' => $this->paths->work(),
            'snapshots' => $this->paths->snapshots(), 'backups' => $this->backupPaths()->dir(), 'logs' => $this->paths->logs(),
        ];
    }

    private ?EventDispatcher $events = null;

    /** 도메인 이벤트(P16 자리). 처음 만들 때 알림(P24)을 구독시킨다 — 분석 자식·워커·웹 요청 어디서 보내도 같은 규칙 */
    public function events(): EventDispatcher
    {
        if ($this->events === null) {
            $this->events = new EventDispatcher($this->logger);
            $this->notifications()->subscribe($this->events);
            // P26: 주기 분석 요약·관리자 알림(분석 종료 뒤). 알림 구독 다음에 둔다(실패해도 경고 로그만)
            $this->scheduleOutcomes()->subscribe($this->events);
        }
        return $this->events;
    }

    /** 앱 안 알림(P24): 생성(이벤트 구독)·조회·읽음·설정·보관 정리 */
    public function notifications(): \PJGuardian\Services\Notifications\NotificationService
    {
        return new \PJGuardian\Services\Notifications\NotificationService($this->db(), $this->clock, $this->projectAccess(), $this->logger, $this->appSettings());
    }

    /** 코드 스냅샷(명세 7): <snapshots_dir>/<프로젝트>/<분석>/source.zip, 최근 snapshot.full_keep_count회 보존 */
    public function snapshots(): SnapshotService
    {
        return new SnapshotService(
            $this->db(), $this->clock, $this->logger, $this->paths->snapshots(), $this->workRoot(),
            $this->config->int('snapshot.full_keep_count'), $this->config->int('snapshot.excerpt_context_lines'),
        );
    }

    /** 성공한 도구 결과를 결함으로 반영(P16): 시그니처, 판정, 스냅샷, 통계 */
    public function analysisFinalizer(): AnalysisFinalizer
    {
        return new AnalysisFinalizer($this->db(), $this->clock, $this->logger, $this->projects(), $this->analysisQueue(), $this->snapshots(), $this->events(),
            $this->config->bool('severity.auto_assign') ? $this->severityMap() : null,
            $this->config->int('limits.project_defect_warn'), $this->config->int('limits.project_defect_max'), $this->notifications(),
            $this->config->int('analysis.file_defect_spill_threshold'), $this->workRoot(),
            $this->config->int('database.analysis_cache_mb'));
    }

    private ?\PJGuardian\Services\Severity\SeverityMap $severityMap = null;

    /** Run 상세 보관·삭제(P21B) */
    /** 화면에서 admin이 바꾸는 전체 공통 설정(P21C, 014) */
    public function appSettings(): \PJGuardian\Services\AppSettings
    {
        return $this->appSettings ??= new \PJGuardian\Services\AppSettings($this->db(), $this->audit(), $this->clock);
    }

    public function runDetails(): \PJGuardian\Services\Runs\RunDetails
    {
        return new \PJGuardian\Services\Runs\RunDetails($this->db(), $this->audit(), $this->clock);
    }

    /** 규칙 기본 심각도 매핑(P21S, resources/severity). 이 앱 객체(요청·워커 프로세스) 동안 한 번 읽는다 */
    public function severityMap(): \PJGuardian\Services\Severity\SeverityMap
    {
        return $this->severityMap ??= new \PJGuardian\Services\Severity\SeverityMap($this->root . '/resources/severity');
    }

    /** 결함 조회(P17): 목록·필터 요약·상세·이력. 읽기 전용 */
    public function defectQuery(): DefectQuery
    {
        return new DefectQuery($this->db(), $this->projectAccess(), $this->runs());
    }

    /** Run(성공한 분석의 회차)·과거 Run 보기(P20R) */
    public function runs(): RunService
    {
        return new RunService($this->db());
    }

    /** 히스토리(P21): 분석 이력·직전 성공 대비 비교·결함 전체 이력 */
    public function history(): \PJGuardian\Services\History\HistoryService
    {
        return new \PJGuardian\Services\History\HistoryService($this->db(), (float) $this->config->get('history.scope_change_ratio'));
    }

    /** 대시보드 집계(P20): 보이는 프로젝트 범위, 캐시 <work_dir>/cache/dashboard */
    public function dashboard(): DashboardService
    {
        return new DashboardService(
            $this->db(), $this->clock, $this->projectAccess(), $this->defectQuery(), $this->workRoot() . '/cache/dashboard',
            $this->config->int('dashboard.cache_ttl'), $this->config->int('ui.dashboard_trend_count'), $this->config->int('ui.dashboard_top_n'),
            $this->appSettings(),
        );
    }

    /** 결함 수동 변경(P18): 담당자·심각도·상태, 낙관적 잠금, 이력, 일괄, 이벤트. 결함 수정은 이것으로만 */
    public function defectUpdate(): DefectUpdate
    {
        return new DefectUpdate($this->db(), $this->clock, $this->projectAccess(), $this->events(), $this->defectQuery(), $this->config->int('ui.bulk_update_max'));
    }

    /** 결함 코드 조회(P17, 명세 7): 마지막 감지 분석의 스냅샷 또는 발췌를 JSON 줄 목록으로 */
    public function defectCode(): DefectCode
    {
        return new DefectCode(
            $this->db(), $this->paths->snapshots(),
            $this->config->int('viewer.code_window_lines'), $this->config->int('viewer.code_max_lines'),
            $this->config->int('viewer.code_max_line_length'), $this->config->int('viewer.code_max_file_size'),
        );
    }

    /** 분석 결과 NDJSON 폴더(P15): <work_dir>/results/<분석 id>.ndjson */
    public function resultsDir(): string
    {
        return $this->workRoot() . '/results';
    }

    /** 워커가 자기 PHP 자식(tools/run_analysis.php 등)을 띄우는 실행기: 지금 실행 중인 PHP만 허용 */
    public function internalRunner(): ProcessRunner
    {
        return new ProcessRunner([], [PHP_BINARY]);
    }

    /**
     * 분석 실행기(P15): ruff/ESLint(ToolAnalysisRunner). 환경 변수 PJG_FAKE_ANALYSIS가 있으면 가짜 분석기(P14 워커 시험·시연 전용).
     */
    public function analysisRunner(): AnalysisRunner
    {
        $fake = getenv('PJG_FAKE_ANALYSIS');
        if (is_string($fake) && $fake !== '') {
            $tmp = $this->workRoot() . '/worker/tmp';
            \PJGuardian\Support\Fs::ensureDir($tmp);
            return new FakeAnalysisRunner($this->internalRunner(), $this->config->int('analysis.output_limit'), $tmp, FakeAnalysisRunner::specFromEnv());
        }
        return new ToolAnalysisRunner(
            $this->toolRegistry(), $this->ruffRunner(), $this->eslintRunner(), $this->projects(), $this->diskGuard(), $this->resultsDir(),
            $this->config->int('analysis.output_limit'), $this->config->int('analysis.output_margin'), $this->logger, $this->ruleOverrides(),
        );
    }

    private function workRoot(): string
    {
        return rtrim(str_replace('\\', '/', $this->paths->work()), '/');
    }

    /** 프로젝트 접근 판정(명세 3.1). 판정은 이것 한 곳에서만 한다 */
    public function projectAccess(): ProjectAccess
    {
        return $this->projectAccess ??= new ProjectAccess($this->db(), $this->clock, $this->audit(), $this->logger);
    }

    /**
     * 요청에 맞는 화면 렌더러(자산 주소는 요청의 배포 경로 접두사를 따른다).
     * 아이콘 매핑에 없는 이름은 app.env=dev면 오류, 운영이면 대체 아이콘 + WARN 로그.
     */
    public function view(Request $request): View
    {
        $assets = new Assets($this->root . '/public', $request->basePath, $this->assetVersion());
        $mapFile = $this->root . '/public/assets/icons/icons.json';
        $map = json_decode((string) file_get_contents($mapFile), true, 512, JSON_THROW_ON_ERROR);
        $icons = new Icons($map, $assets->url('icons/sprite.svg'), $this->config->string('app.env') === 'dev', $this->logger);
        // 세션의 일회성 알림(로그인 직후 "관리자가 비밀번호를 변경했습니다" 등)을 꺼내 배너로. 화면을 그릴 때 한 번만 보인다
        $ctx = $request->attribute('session_ctx');
        $flash = $ctx instanceof SessionContext ? $ctx->takeFlash() : null;
        $flashBanners = [];
        if (($flash['code'] ?? null) === 'password_set_by_admin') {
            $at = (new \DateTimeImmutable((string) ($flash['at'] ?? 'now')))
                ->setTimezone(new \DateTimeZone($this->config->string('app.display_timezone')))->format('Y-m-d H:i');
            $flashBanners[] = ['level' => 'warn', 'message' => \PJGuardian\Support\Lang::get('notice.password_set_by_admin', ['at' => $at])];
        } elseif (($flash['code'] ?? null) === 'password_changed') {
            $flashBanners[] = ['level' => 'info', 'message' => \PJGuardian\Support\Lang::get('password.changed')];
        }
        $user = $request->attribute('user');
        $canSeeGroups = is_array($user) && $this->groupPermission()->managesAny($user);
        // P30 관리자 장애 배너: admin에게만, 판정은 SystemHealth 캐시(monitoring.cache) — 요청마다 다시 계산하지 않는다. 실패해도 화면은 그린다
        $healthAlerts = [];
        if (is_array($user) && ($user['role'] ?? '') === 'admin') {
            try {
                $healthAlerts = $this->systemHealth()->snapshot()['alerts'];
            } catch (\Throwable $e) {
                $this->logger->warn('장애 배너 판정 실패', ['error' => $e::class]);
            }
        }
        // P31 초기 관리자 비밀번호 경고 배너: admin에게만, 판정은 하드닝 점검과 같은 출처(UserRepository::initialPasswordPendingAdmins)
        $initialPassword = is_array($user) && ($user['role'] ?? '') === 'admin' ? $this->initialPasswordAdmins() : [];
        return new View($this->root . '/src/Views', $icons, $assets, [
            'timezone' => $this->config->string('app.display_timezone'),
            // P27D 화면 언어·테마(LocaleMiddleware가 정함): <html lang>·data-theme, 헤더 전환 버튼
            'uiLang' => (string) $request->attribute('ui_lang', \PJGuardian\Support\Lang::locale()),
            'uiTheme' => (string) $request->attribute('ui_theme', 'system'),
            'uiLoggedIn' => is_array($user),
            'appEnv' => $this->config->string('app.env'),
            'notificationPoll' => $this->config->int('ui.notification_poll_seconds'),
            'appVersion' => self::VERSION,
            // 컨트롤러가 세션을 새로 만들었으면(로그인 폼) 그 토큰을 쓴다
            'csrfToken' => $request->attribute('session_ctx') instanceof SessionContext
                ? ($request->attribute('session_ctx')->csrfToken() ?? '')
                : (string) $request->attribute('csrf_token', ''),
            // CSP style-src nonce(SecurityHeadersMiddleware가 요청마다 만든다). 코드 뷰어(CodeMirror)의 <style>에만 쓴다(P17)
            'cspNonce' => (string) $request->attribute('csp_nonce', ''),
            'iconGroups' => $map['groups'],
            'flashBanners' => $flashBanners,
            'healthAlerts' => $healthAlerts,
            // P32B 업그레이드 유지보수: 켜짐·사전 공지 배너(모든 사용자)
            'upgradeMaintenance' => \PJGuardian\Services\UpgradeMaintenance::banner($this->config, $this->clock),
            'initialPassword' => $initialPassword === [] ? null : ['admins' => $initialPassword, 'self' => in_array((string) ($user['login_id'] ?? ''), $initialPassword, true),
                'public' => !self::isLocalBaseUrl($this->config->string('app.base_url'))],
            // "그룹" 메뉴: admin 또는 어느 그룹이든 관리자(명세 3.1). 매 요청 DB를 읽어 구성원 변경이 바로 반영된다
            'canSeeGroups' => $canSeeGroups,
            // 상단 "그룹 관리자" 배지: 관리하는 그룹이 있고 시스템 역할이 admin이 아닐 때(명세 19.10)
            'isGroupManager' => $canSeeGroups && ($user['role'] ?? '') !== 'admin',
        ]);
    }

    /** 가이드 본문(P29): resources/guide(웹 루트 밖), 자리표시자 값은 지금 설정·도구 버전 */
    /** P29B FAQ(쓰기 권한은 라우트 $admin, 데이터 규칙·감사는 여기) */
    public function faq(): \PJGuardian\Services\Faq\FaqService
    {
        return new \PJGuardian\Services\Faq\FaqService($this->db(), $this->clock, $this->audit());
    }

    /** P30 장애 판정 한 곳(모니터링 화면·/health·배너·워커). 시험은 setAlertSink로 이벤트를 가로챈다 */
    public function systemHealth(): \PJGuardian\Services\Monitoring\SystemHealth
    {
        return $this->systemHealth ??= new \PJGuardian\Services\Monitoring\SystemHealth($this, $this->alertSink);
    }

    public function setAlertSink(?\PJGuardian\Services\Monitoring\AdminAlertSink $sink): void
    {
        $this->alertSink = $sink;
        $this->systemHealth = null;
    }

    /** @var (\Closure(): \PJGuardian\Services\Mail\MailSender)|null 시험·격리 점검용 발송기(P30B — 실제 SMTP에 연결하지 않음) */
    private ?\Closure $mailSenderFactory = null;

    /** P30B 관리자 장애 이메일(설정·발송 규칙·시험 발송). 발송은 워커의 주기 판정에서만(Worker::evaluateHealth) */
    public function alertMailer(): \PJGuardian\Services\Mail\AlertMailer
    {
        return new \PJGuardian\Services\Mail\AlertMailer($this->db(), $this->config, $this->clock, $this->appSecrets(),
            $this->mailSenderFactory ?? fn (): \PJGuardian\Services\Mail\MailSender => \PJGuardian\Services\Mail\AlertMailer::smtpFrom($this->config, $this->appSecrets()),
            $this->logger, $this->audit(), $this->workRoot());
    }

    /**
     * 초기 비밀번호(X17A, password_kind = 'initial')를 아직 바꾸지 않은 활성 관리자 로그인 ID(P31). 하드닝 점검(HardeningCheck)과 같은 함수 —
     * 관리자 배너·모니터링 경고가 이것 하나로 판정한다. 읽기 실패는 빈 목록(화면은 그린다).
     *
     * @return list<string>
     */
    public function initialPasswordAdmins(): array
    {
        try {
            return (new \PJGuardian\Repositories\UserRepository($this->db()))->initialPasswordPendingAdmins();
        } catch (\Throwable $e) {
            $this->logger->warn('초기 비밀번호 판정 실패', ['error' => $e::class]);
            return [];
        }
    }

    /** app.base_url이 이 PC 안(localhost·127.0.0.1·::1)인가 — 아니면 초기 비밀번호 경고를 더 강하게 */
    public static function isLocalBaseUrl(string $baseUrl): bool
    {
        $host = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST), '[]'));
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
    }

    /** 시험·격리 점검용: 메일 발송기를 바꿔 끼운다(가짜 수신기 등) */
    public function useMailSender(?\Closure $factory): void
    {
        $this->mailSenderFactory = $factory;
    }

    /** P30 장애 경고 로그(logs/alert-YYYYMMDD.log, 보존은 앱 로그와 같음) */
    public function alertLogger(): Logger
    {
        return $this->alertLogger ??= new Logger($this->paths->logs(), $this->clock, 'INFO', 'alert');
    }

    public function auditQuery(): \PJGuardian\Services\Audit\AuditQuery
    {
        return new \PJGuardian\Services\Audit\AuditQuery($this->db(), $this->clock,
            new \PJGuardian\Services\Audit\AuditDescriber($this->db(), $this->config->string('app.display_timezone')));
    }

    public function auditRetention(): \PJGuardian\Services\Audit\AuditRetention
    {
        return new \PJGuardian\Services\Audit\AuditRetention($this->db(), $this->clock, $this->audit(), $this->config->int('log.audit_retention'), $this->config->int('log.audit_purge_batch'),
            $this->auditAnchor());
    }

    /** P31 감사 로그 최신 해시의 DB 밖 기록(로그 폴더 — 웹 루트 밖) */
    public function auditAnchor(): \PJGuardian\Services\Audit\AuditAnchor
    {
        return new \PJGuardian\Services\Audit\AuditAnchor($this->db(), $this->clock, $this->paths->logs());
    }

    public function guideContent(): \PJGuardian\Services\Guide\GuideContent
    {
        $map = json_decode((string) file_get_contents($this->root . '/public/assets/icons/icons.json'), true, 512, JSON_THROW_ON_ERROR);
        $icons = new Icons($map, '', false, $this->logger);
        return new \PJGuardian\Services\Guide\GuideContent(new \PJGuardian\Services\Guide\GuideCatalog($this->root . '/resources/guide'), $this->config, $icons, self::VERSION,
            fn (string $tool): ?string => $this->toolRegistry()->select($tool)?->version, $this->root);
    }

    /** 정적 파일 묶음 버전(P21): 캐시 파일 <work_dir>/cache/assets_version.json */
    public function assetVersion(): \PJGuardian\Support\AssetVersion
    {
        return $this->assetVersion ??= new \PJGuardian\Support\AssetVersion($this->root . '/public/assets', $this->workRoot() . '/cache/assets_version.json');
    }

    /** 로그인 뒤 계정 값이 비어 있으면 쿠키 값을 한 번 옮겨 저장(P27D) — 비어 있는 열만 */
    private function adoptUiPreferences(int $userId, ?string $lang, ?string $theme): void
    {
        if ($lang !== null) {
            $this->db()->execute('UPDATE users SET ui_language = ? WHERE id = ? AND ui_language IS NULL', [$lang, $userId]);
        }
        if ($theme !== null) {
            $this->db()->execute('UPDATE users SET ui_theme = ? WHERE id = ? AND ui_theme IS NULL', [$theme, $userId]);
        }
    }

    public function handle(Request $request): Response
    {
        // P32B: 신뢰 프록시 뒤면 접속 주소·https를 전달 헤더로(목록 밖의 헤더는 무시) — 이 아래 모든 미들웨어·컨트롤러가 이 값만 본다
        $request = \PJGuardian\Http\ForwardedHeaders::resolve($request->withAttribute('remote_addr', $request->clientIp), (array) ($this->config->get('security.trusted_proxies') ?? []));
        // P21: 버전 경로의 정적 파일(/assets/v/<해시>/…)은 세션·CSRF 없이 바로 응답한다(보안 헤더만)
        if (\PJGuardian\Http\StaticAssets::matches($request)) {
            $static = new \PJGuardian\Http\StaticAssets($this->root . '/public/assets', fn (): string => $this->assetVersion()->hash());
            return (new SecurityHeadersMiddleware($this->config->int('security.hsts_max_age')))->process($request, $static->serve(...));
        }
        /** @var list<Middleware> $global */
        $global = [
            new RequestIdMiddleware($this->logger),
            // P28C: 제공자 로그아웃(oidc.provider_logout)이 켜져 있으면 로그아웃 폼의 302가 IdP로 가도록 form-action에 그 출처만 더한다
            new SecurityHeadersMiddleware($this->config->int('security.hsts_max_age'), fn (): ?string => $this->config->bool('oidc.provider_logout') && $this->oidcEnabled()
                ? $this->oidcLogin()->logoutOrigin() : null),
            new ErrorMiddleware($this->logger),
            new HttpsMiddleware($this->config->bool('security.require_https'), $this->config->string('app.base_url')),
            // P27D 화면 언어·테마: 세션 전(쿠키·Accept-Language — 유지보수 안내도 그 언어로), 세션 뒤(계정 값 우선) 두 번
            new \PJGuardian\Http\Middleware\LocaleMiddleware(false),
            // P27B 복원 중 유지보수 모드: DB를 여는 세션보다 먼저(파일 플래그) — 상태 페이지·API만, 나머지 503
            new \PJGuardian\Http\Middleware\MaintenanceMiddleware(
                fn (): bool => is_file($this->backupPaths()->maintenanceFlag()),
                fn (Request $r, int $status): Response => (new \PJGuardian\Controllers\RestoreStatusController($this))->pageResponse($r, $status),
                fn (): Response => (new \PJGuardian\Controllers\RestoreStatusController($this))->apiResponse(),
            ),
            new SessionMiddleware(fn (): Db => $this->db(), fn (): SessionService => $this->sessions(), $this->authSettings(), $this->clock, $this->logger),
            new \PJGuardian\Http\Middleware\LocaleMiddleware(true, fn (int $id, ?string $lang, ?string $theme) => $this->adoptUiPreferences($id, $lang, $theme)),
            // P32B 업그레이드 유지보수 모드: 세션 뒤(역할을 봄) — admin이 아닌 사용자의 쓰기만 503
            new \PJGuardian\Http\Middleware\UpgradeMaintenanceMiddleware(fn (): bool => $this->config->bool('maintenance.enabled'),
                fn (): string => (string) ($this->config->get('maintenance.message') ?? '')),
            new CsrfMiddleware(),
        ];
        $pipeline = array_reduce(
            array_reverse($global),
            static fn (callable $next, Middleware $mw): callable => static fn (Request $r): Response => $mw->process($r, $next),
            fn (Request $r): Response => $this->router->dispatch($r),
        );
        return $pipeline($request);
    }
}
