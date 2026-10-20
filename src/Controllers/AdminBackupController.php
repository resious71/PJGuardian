<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Backup\BackupException;
use PJGuardian\Services\Backup\BackupSchedule;
use PJGuardian\Services\Backup\BundleFormat;
use PJGuardian\Services\Backup\KeyVault;
use PJGuardian\Services\Backup\RestoreStateFile;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Lang;

/**
 * 관리자 "시스템 관리 > 백업·복원"(P27B, admin만 — 라우트 미들웨어 $admin, 쓰기는 CSRF).
 * 내려받기·삭제·복원·키 내보내기는 **관리자 비밀번호 재입력**(분당 5회). 감사: backup.create(요청)·download·delete·verify, restore.start, key.export·import.
 * 백업은 워커 작업(파일 요청), 복원은 워커가 별도 프로세스로 — 이 컨트롤러는 웹 요청 안에서 DB를 바꾸지 않는다(사전 검증·요청 파일만).
 */
final class AdminBackupController
{
    private const REAUTH_PER_MINUTE = 5;

    /** 오류 코드 → HTTP 상태 */
    private const STATUS = [
        'bundle_not_found' => 404, 'upload_not_found' => 404,
        'backup_busy' => 409, 'restore_in_progress' => 409, 'bundle_exists' => 409, 'backup_last_success_protected' => 409, 'worker_not_running' => 409,
        'bundle_needs_verify' => 409, 'chunk_out_of_order' => 409, 'upload_incomplete' => 409,
        'upload_too_large' => 413,
        'key_import_rate_limited' => 429, 'reauth_rate_limited' => 429,
        'backup_disk_low' => 507, 'backup_disk_full' => 507, 'restore_disk_low' => 507,
        'reauth_failed' => 403,
        'bundle_name_invalid' => 400, 'upload_size_invalid' => 400, 'chunk_index_invalid' => 400, 'chunk_hash_invalid' => 400, 'chunk_length_invalid' => 400,
        'key_file_invalid' => 400, 'key_passphrase_weak' => 400, 'key_passphrase_mismatch' => 400, 'restore_confirm_mismatch' => 400, 'invalid_backup_request' => 400,
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function page(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('admin/backups', [
            'title' => Lang::get('backups.title'), 'user' => $request->attribute('user'), 'active' => 'admin.tools',
            'scripts' => ['js/pages/admin_backups.js'], 'body_class' => 'page-admin-system',
        ], ['sub' => 'backups']));
    }

    /** GET /api/v1/admin/backups — 요약·목록·진행 */
    public function index(Request $request): Response
    {
        return $this->wrap(function (): Response {
            $backups = $this->app->backups();
            $paths = $backups->paths();
            $state = $backups->state()->read();
            $now = $this->app->clock->now()->getTimestamp();
            // 폴더가 없으면 백업과 같은 준비(만들기·쓰기 시험·.gitignore)를 해 본다 — 새 설치에서 "폴더 없음"을 오류로 보이지 않게(명세: 없으면 자동으로 만든다)
            try {
                $dirCheck = $paths->prepare($this->app->pathPolicy());
            } catch (BackupException $e) {
                $dirCheck = $this->app->pathPolicy()->check((string) $this->app->config->get('backup.dir'), false);
            }
            $jobs = $this->app->backupJobs();
            $last = $state['last_success']['at'] ?? null;
            $rehearsal = $state['last_rehearsal']['at'] ?? null;
            $rehearsalBase = $rehearsal ?? ($state['since'] ?? null);
            $beat = $this->app->workerState()->read();
            $since = $this->app->workerState()->secondsSinceBeat();
            $workerRunning = is_array($beat) && ($beat['state'] ?? '') !== 'stopped' && $since !== null && $since <= $this->app->config->int('worker.heartbeat_stale');
            $legacy = $this->app->config->get('paths.backups_dir');
            $keys = [$this->app->config->secret('encryption_key'), $this->app->config->secret('encryption_key_previous')];
            $flag = $this->app->maintenance()->active();
            return Response::data([
                'dir' => $paths->dir(),
                'dir_source' => $this->app->config->source('backup.dir'),
                'dir_check' => $dirCheck['code'],
                'dir_free_bytes' => $dirCheck['free_bytes'],
                'inside_project' => $paths->insideProject(),
                'legacy_backups_dir' => is_string($legacy) && trim($legacy) !== '' && trim($legacy) !== 'data/backups' ? $legacy : null,
                'last_success' => $state['last_success'] ?? null,
                'last_failure' => $state['last_failure'] ?? null,
                'last_rehearsal' => $state['last_rehearsal'] ?? null,
                'stale' => $last === null || $now - (int) strtotime((string) $last) > 3600 * BackupSchedule::fromConfig($this->app->config)->staleHours($this->app->config->int('backup.backup_stale_hours')),
                'rehearsal_due' => $rehearsalBase !== null && ($state['last_success'] ?? null) !== null
                    && $now - (int) strtotime((string) $rehearsalBase) > 86400 * $this->app->config->int('backup.rehearsal_interval_days'),
                'next_daily' => $this->app->backupRunner(false)->nextAuto(),
                // 자동 백업(P27E): 켜기/끄기·주기, "오래됨" 기준(주기에 맞춤), 디스크 추정에 쓰는 값(화면 backup_core.diskEstimate)
                'schedule' => $this->scheduleView(),
                'retention_days' => $this->app->config->int('backup.retention_days'),
                'job' => $jobs->active() === null ? null : ['type' => $jobs->active()['type'] ?? null, 'kind' => $jobs->active()['kind'] ?? null,
                    'started' => ($jobs->active()['started_at'] ?? null) !== null, 'progress' => $jobs->progress(), 'cancelling' => $jobs->cancelled()],
                'restore' => RestoreStatusController::publicState($this->app),
                'restore_pending' => is_file($paths->restoreRequest()),
                'maintenance_stale' => $flag !== null && ($this->app->maintenance()->minutes() ?? 0) >= $this->app->config->int('backup.maintenance_stale_minutes'),
                'worker_running' => $workerRunning,
                'key' => ['available' => $keys[0] !== null, 'fingerprint' => $keys[0] !== null ? KeyVault::fingerprint((string) $keys[0]) : null],
                'upload_max_mb' => $this->app->config->int('backup.upload_max_mb'),
                'items' => $backups->list(),
            ]);
        });
    }

    /** POST /api/v1/admin/backups {snapshots} — 지금 백업(워커 작업) */
    public function create(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $body = (array) $request->json();
            $user = $request->attribute('user');
            $job = $this->app->backupJobs()->request(['type' => 'backup', 'kind' => 'manual', 'snapshots' => ($body['snapshots'] ?? true) !== false,
                'by' => (string) $user['login_id'], 'by_id' => (int) $user['id']]);
            $this->audit($request, 'backup.create', null, ['kind' => 'manual', 'result' => 'requested', 'snapshots' => $job['snapshots']]);
            return Response::data(['job' => $job['id']], 202);
        });
    }

    /** POST /api/v1/admin/backups/cancel */
    public function cancel(Request $request): Response
    {
        $this->app->backupJobs()->cancel();
        return Response::data(['cancelling' => true]);
    }

    /** POST /api/v1/admin/backups/rehearse {name?} — 복원 리허설(워커 작업, 운영 데이터 변경 없음) */
    public function rehearse(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $body = (array) $request->json();
            $name = is_string($body['name'] ?? null) ? $body['name'] : ($this->app->backups()->list()[0]['name'] ?? '');
            $this->bundleFile($name, false);
            $user = $request->attribute('user');
            $this->app->backupJobs()->request(['type' => 'rehearsal', 'file' => $name, 'by' => (string) $user['login_id'], 'by_id' => (int) $user['id']]);
            return Response::data(['file' => $name], 202);
        });
    }

    /** POST /api/v1/admin/backups/{name}/verify — 다시 검증(웹 요청 안, 읽기만) */
    public function verify(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $name = (string) $request->param('name');
            $this->bundleFile($name, false);
            $r = $this->app->backups()->verify($name);
            $this->audit($request, 'backup.verify', $name, ['result' => $r['ok'] ? 'ok' : 'failed', 'code' => $r['code']]);
            return Response::data($r);
        });
    }

    /** POST /api/v1/admin/backups/{name}/download {password} → 한 번 쓰는 내려받기 주소(60초) */
    public function downloadToken(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $name = (string) $request->param('name');
            $this->bundleFile($name, false);
            $this->reauth($request);
            $token = bin2hex(random_bytes(24));
            $user = $request->attribute('user');
            $dir = $this->app->backupPaths()->runDir() . '/download';
            Fs::ensureDir($dir);
            Fs::atomicWrite($dir . '/' . $token . '.json', (string) json_encode(['name' => $name, 'user_id' => (int) $user['id'], 'expires' => $this->app->clock->now()->getTimestamp() + 60]));
            return Response::data(['url' => '/api/v1/admin/backups/download/' . $token]);
        });
    }

    /** GET /api/v1/admin/backups/download/{token} — 같은 관리자·60초 안·한 번만. PHP가 스트리밍(백업 폴더는 웹이 직접 서비스하지 않음) */
    public function download(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $token = (string) $request->param('token');
            $file = $this->app->backupPaths()->runDir() . '/download/' . $token . '.json';
            $t = preg_match('/^[0-9a-f]{48}$/D', $token) === 1 ? json_decode((string) @file_get_contents($file), true) : null;
            @unlink($file);
            $user = $request->attribute('user');
            if (!is_array($t) || (int) $t['user_id'] !== (int) $user['id'] || $t['expires'] < $this->app->clock->now()->getTimestamp()) {
                throw new BackupException('bundle_not_found');
            }
            $path = $this->bundleFile((string) $t['name'], false);
            $this->audit($request, 'backup.download', (string) $t['name'], ['size' => (int) filesize($path)]);
            return Response::stream($path, 'application/octet-stream', 'attachment; filename="' . $t['name'] . '"');
        });
    }

    /** DELETE /api/v1/admin/backups/{name} {password} — 마지막 성공 백업은 지울 수 없음 */
    public function delete(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $name = (string) $request->param('name');
            $this->bundleFile($name, false);
            $this->reauth($request);
            $size = (int) filesize((string) $this->app->backupPaths()->bundle($name));
            $this->app->backups()->delete($name);
            $this->audit($request, 'backup.delete', $name, ['size' => $size, 'reason' => 'manual']);
            return Response::data(['deleted' => $name]);
        });
    }

    /** POST /api/v1/admin/backups/{name}/precheck — 복원 사전 검증·미리보기(변경 없음) */
    public function precheck(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $name = (string) $request->param('name');
            $file = $this->bundleFile($name, true);
            return Response::data($this->app->restoreService()->precheck($file));
        });
    }

    /**
     * POST /api/v1/admin/restore {name, password, confirm: "복원", wait_mode: wait|cancel, config_keys, local_keys, key?: {content, passphrase}}
     * 사전 검증을 다시 하고(변경 없음), 키를 가져오면 복호화 시험까지 통과해야 하며, 요청 파일을 남긴다 — 워커가 별도 프로세스로 복원한다.
     */
    public function restore(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $b = (array) $request->json();
            $name = is_string($b['name'] ?? null) ? $b['name'] : '';
            $file = $this->bundleFile($name, true);
            $this->reauth($request);
            if (($b['confirm'] ?? null) !== Lang::get('backups.restore.confirm_word')) {
                throw new BackupException('restore_confirm_mismatch');
            }
            $paths = $this->app->backupPaths();
            if (is_file($paths->restoreRequest()) || $this->app->maintenance()->active() !== null) {
                throw new BackupException('restore_in_progress');
            }
            if ($this->app->backupJobs()->active() !== null) {
                throw new BackupException('backup_busy');
            }
            $beat = $this->app->workerState()->read();
            $since = $this->app->workerState()->secondsSinceBeat();
            if (!is_array($beat) || ($beat['state'] ?? '') === 'stopped' || $since === null || $since > $this->app->config->int('worker.heartbeat_stale')) {
                throw new BackupException('worker_not_running');
            }
            $restore = $this->app->restoreService();
            $restore->precheck($file);
            $user = $request->attribute('user');
            @unlink($restore->pendingKeyFile());
            $keyImported = false;
            if (is_array($b['key'] ?? null)) {
                $this->app->keyVault()->consumeImportQuota((int) $user['id']);
                $keys = $this->app->keyVault()->import((string) ($b['key']['content'] ?? ''), (string) ($b['key']['passphrase'] ?? ''));
                if ($restore->testKeyAgainstBundle($file, $keys) === false) {
                    throw new BackupException('key_test_failed');
                }
                Fs::atomicWrite($restore->pendingKeyFile(), (string) json_encode(['encryption_key' => $keys['encryption_key'], 'encryption_key_previous' => $keys['encryption_key_previous']]));
                $keyImported = true;
                $this->audit($request, 'key.import', $keys['fingerprint'], ['result' => 'ok', 'bundle' => $name]);
            }
            $list = static fn (mixed $v): array => array_values(array_filter(is_array($v) ? $v : [], static fn ($k): bool => is_string($k) && preg_match('/^[a-z_]+\.[a-z_]+$/D', $k) === 1));
            $req = ['bundle' => $name, 'by' => (string) $user['login_id'], 'by_id' => (int) $user['id'], 'wait_mode' => ($b['wait_mode'] ?? 'wait') === 'cancel' ? 'cancel' : 'wait',
                'config_keys' => $list($b['config_keys'] ?? []), 'local_keys' => $list($b['local_keys'] ?? []), 'key' => $keyImported,
                'requested_at' => \PJGuardian\Support\SystemClock::iso($this->app->clock->now())];
            (new RestoreStateFile($paths->restoreState(), $this->app->clock))->requested($name, (string) $user['login_id']);
            Fs::atomicWrite($paths->restoreRequest(), (string) json_encode($req, JSON_UNESCAPED_UNICODE));
            $this->audit($request, 'restore.start', $name, ['wait_mode' => $req['wait_mode'], 'config_keys' => count($req['config_keys']) + count($req['local_keys']), 'key' => $keyImported]);
            return Response::data(['status_url' => '/restore-status'], 202);
        });
    }

    /** POST /api/v1/admin/backups/key-export {password, passphrase, passphrase2} → {file_name, content}(암호문구로 암호화한 .pjgkey) */
    public function keyExport(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $b = (array) $request->json();
            $this->reauth($request);
            $p1 = (string) ($b['passphrase'] ?? '');
            if (!hash_equals($p1, (string) ($b['passphrase2'] ?? ''))) {
                throw new BackupException('key_passphrase_mismatch');
            }
            $current = $this->app->config->secret('encryption_key');
            if ($current === null) {
                throw new BackupException('key_missing');
            }
            $content = $this->app->keyVault()->export($current, $this->app->config->secret('encryption_key_previous'), $p1);
            $local = $this->app->clock->now()->setTimezone(new \DateTimeZone((string) $this->app->config->get('app.display_timezone')))->format('Ymd');
            $fp = KeyVault::fingerprint($current);
            $this->audit($request, 'key.export', $fp, ['result' => 'ok']);
            return Response::data(['file_name' => "pjguardian-key-{$local}.pjgkey", 'content' => $content, 'fingerprint' => $fp]);
        });
    }

    /** POST /api/v1/admin/backup-uploads {file_name, total_size} */
    public function uploadInit(Request $request): Response
    {
        return $this->wrap(fn (): Response => Response::data($this->app->bundleUpload()->init((int) $request->attribute('user')['id'], (array) $request->json()), 201));
    }

    /** PUT /api/v1/admin/backup-uploads/{id}/chunks/{index} (X-Chunk-Sha256, 원시 본문) */
    public function uploadChunk(Request $request): Response
    {
        return $this->wrap(fn (): Response => Response::data($this->app->bundleUpload()->chunk((int) $request->attribute('user')['id'], (string) $request->param('id'),
            (string) $request->param('index'), strtolower((string) ($request->header('x-chunk-sha256') ?? '')), $request->bodyStream())));
    }

    /** POST /api/v1/admin/backup-uploads/{id}/complete → 백업 폴더로(검증 전) */
    public function uploadComplete(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $name = $this->app->bundleUpload()->complete((int) $request->attribute('user')['id'], (string) $request->param('id'));
            $this->audit($request, 'backup.upload', $name, ['size' => (int) filesize((string) $this->app->backupPaths()->bundle($name))]);
            return Response::data(['name' => $name]);
        });
    }

    /** DELETE /api/v1/admin/backup-uploads/{id} */
    public function uploadDiscard(Request $request): Response
    {
        return $this->wrap(function () use ($request): Response {
            $this->app->bundleUpload()->discard((int) $request->attribute('user')['id'], (string) $request->param('id'));
            return Response::data(['discarded' => true]);
        });
    }

    /** 묶음 이름 → 파일(없으면 404). $forRestore면 올린 묶음은 검증을 통과해야 한다 */
    private function bundleFile(string $name, bool $forRestore): string
    {
        $file = BundleFormat::validName($name) ? $this->app->backupPaths()->bundle($name) : null;
        if ($file === null || !is_file($file)) {
            throw new BackupException('bundle_not_found');
        }
        if ($forRestore) {
            $side = $this->app->backups()->readSidecar($name);
            if (($side['uploaded'] ?? false) === true && (($side['verified']['ok'] ?? false) !== true)) {
                throw new BackupException('bundle_needs_verify');
            }
        }
        return $file;
    }

    /** 관리자 비밀번호 재입력(로컬 계정). 분당 5회 */
    private function reauth(Request $request): void
    {
        $user = $request->attribute('user');
        $this->app->keyVault()->consumeQuota('reauth', (int) $user['id'], self::REAUTH_PER_MINUTE, 'reauth_rate_limited');
        $password = ((array) $request->json())['password'] ?? null;
        $hash = $this->app->db()->value("SELECT password_hash FROM users WHERE id = ? AND auth_type = 'local' AND status = 'active'", [(int) $user['id']]);
        if (!is_string($password) || $password === '' || !is_string($hash) || !password_verify($password, $hash)) {
            $this->app->logger->warn('관리자 비밀번호 재확인 실패(백업·복원)', ['user_id' => (int) $user['id']]);
            throw new BackupException('reauth_failed');
        }
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, string $action, ?string $target, array $details): void
    {
        $this->app->audit()->record(Actor::fromUser($request->attribute('user'), $request->clientIp), $action, 'backup', $target, $details);
    }

    /** @return array<string, mixed> 자동 백업 설정 요약(P27E) */
    private function scheduleView(): array
    {
        $c = $this->app->config;
        $schedule = BackupSchedule::fromConfig($c);
        return [
            'enabled' => $c->bool('backup.enabled'),
            'mode' => $schedule->mode, 'at' => $schedule->at, 'weekly_day' => $schedule->weeklyDay, 'monthly_day' => $schedule->monthlyDay,
            'stale_hours' => $schedule->staleHours($c->int('backup.backup_stale_hours')),
            'retention_min_count' => $c->int('backup.retention_min_count'),
            'retention_max_count' => $c->int('backup.retention_max_count'),
        ];
    }

    /** BackupException → ApiException(문구 backup.error.<code>) */
    private function wrap(\Closure $f): Response
    {
        try {
            return $f();
        } catch (BackupException $e) {
            throw new ApiException(self::STATUS[$e->errorCode] ?? 422, $e->errorCode, $e->params, 'backup.error.' . $e->errorCode, ['detail' => $e->getMessage()], [],
                $e->params === [] ? null : $e->params);
        }
    }
}
