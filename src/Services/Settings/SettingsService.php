<?php

declare(strict_types=1);

namespace PJGuardian\Services\Settings;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;

/**
 * 관리자 "설정 관리"(P27A): 허용 목록(ConfigSchema::EDITABLE) 키의 조회·변경·기본값 복원·경로 확인. admin 전용(라우트 미들웨어).
 *
 * - 값은 config/settings.local.ini에만 쓴다(SettingsStore). config.ini·secrets.ini·DB는 쓰지 않는다.
 * - 변경은 전체 성공 또는 전체 실패: 형식·범위(422 setting_invalid_value), 키 간 규칙(422 setting_cross_check),
 *   환경 변수가 정한 키(422 setting_env_locked), 경로 검사(422 setting_path_invalid), 허용 목록 밖(400 setting_not_editable),
 *   버전 표지 불일치(409 settings_conflict)를 모두 쓰기 전에 본다.
 * - 감사: 키마다 한 줄 config.update(target_type config, target_id 키, details {old, new, old_source}). 허용 목록 키만 다루므로 민감 값은 없다.
 *   변경 이력은 그 줄들을 (target_type, target_id) 색인으로 읽는다(마이그레이션 없음).
 */
final class SettingsService
{
    public const AUDIT_ACTION = 'config.update';
    /** P28B: 화면에서 고치지만 감사 로그·변경 이력에는 값을 남기지 않는 키(바인드 DN — 바뀌었다는 사실만 "***") */
    public const AUDIT_MASKED = ['ldap.bind_dn'];

    private static function auditValue(string $name, string $v): string
    {
        return in_array($name, self::AUDIT_MASKED, true) && $v !== '' ? '***' : $v;
    }
    public const HISTORY_LIMIT = 50;

    /**
     * @param \Closure(): Config $reload 저장 뒤 설정 파일을 다시 읽는다
     * @param array{running: bool, version: ?string, beat_at: ?string, pending_restart: list<string>} $worker 워커 생존 신호의 설정 반영 정보
     */
    public function __construct(
        private readonly Config $config,
        private readonly SettingsStore $store,
        private readonly PathPolicy $paths,
        private readonly AuditLog $audit,
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly \Closure $reload,
        private readonly array $worker = ['running' => false, 'version' => null, 'beat_at' => null, 'pending_restart' => []],
        // 키별 "지금 바꿀 수 없음" 검사(P27B: backup.dir은 백업·복원 진행 중이면 거부). 키 → (): ?string 사유 코드(null이면 허용)
        private readonly array $guards = [],
        // P32B 업그레이드 유지보수 모드를 켜기 직전 백업: (Actor) → {ok, code, name}. null이면 백업 없이(시험)
        private readonly ?\Closure $beforeMaintenance = null,
    ) {
    }

    /** @return array<string, mixed> 화면·API 응답 */
    public function view(?Config $config = null): array
    {
        $config ??= $this->config;
        $registry = $config->registry();
        $items = [];
        $groups = ConfigSchema::EDITABLE_GROUPS;
        foreach ($registry->all() as $name => $item) {
            $spec = $item['spec'];
            $base = $config->base($name);
            $source = $config->source($name);
            if (!in_array($item['group'], $groups, true)) {
                $groups[] = $item['group'];
            }
            $items[] = [
                'key' => $name,
                'group' => $item['group'],
                'type' => $spec['type'],
                'value' => Config::display($config->get($name), $spec),
                'source' => $source,
                // "기본값으로"를 누르면 돌아갈 값(config.ini 또는 형식 기본값)
                'base' => ['value' => Config::display($base['value'], $spec), 'source' => $base['source']],
                'default' => array_key_exists('default', $spec) ? (string) $spec['default'] : null,
                'min' => isset($spec['min']) ? Config::display($spec['min'], $spec) : ($spec['type'] === 'percent' ? '0' : null),
                'max' => isset($spec['max']) ? Config::display($spec['max'], $spec) : ($spec['type'] === 'percent' ? '100' : null),
                'values' => $spec['values'] ?? null,
                'restart' => $item['restart'],
                'machine_specific' => $item['machine_specific'],
                'env_locked' => $source === 'env',
                'env_name' => \PJGuardian\Support\SettingsRegistry::envName($name),
            ];
        }
        $version = $config->localVersion();
        return [
            'items' => $items,
            'groups' => $groups,
            'version' => $version,
            'warnings' => $config->warnings(),
            'worker' => $this->worker + ['applied' => $this->worker['running'] ? ($this->worker['version'] ?? '') === $version : null],
            'history' => $this->history(),
        ];
    }

    /**
     * @param array<string, mixed> $changes "섹션.키" → 입력 문자열
     * @param list<string> $createPaths 없으면 만들어도 된다고 확인받은 경로 키
     * @return array<string, mixed> 저장 뒤 view()
     */
    public function update(Actor $actor, array $changes, string $version, array $createPaths = []): array
    {
        if ($changes === [] || array_is_list($changes)) {
            throw new ApiException(400, 'invalid_config_settings');
        }
        $registry = $this->config->registry();
        $write = [];
        $values = $this->config->allValues();
        $audit = [];
        foreach ($changes as $name => $raw) {
            $name = (string) $name;
            if (!$registry->has($name)) {
                throw new ApiException(400, 'setting_not_editable', [], null, [], [], ['key' => preg_match('/^[a-z_]+\.[a-z_]+$/', $name) === 1 ? $name : null]);
            }
            if (!is_string($raw) && !is_int($raw) && !is_bool($raw)) {
                throw new ApiException(422, 'setting_invalid_value', [], null, [], [], ['key' => $name]);
            }
            $spec = $registry->get($name)['spec'];
            if ($this->config->source($name) === 'env') {
                throw new ApiException(422, 'setting_env_locked', ['env' => \PJGuardian\Support\SettingsRegistry::envName($name)], null, [], [], ['key' => $name]);
            }
            $text = is_bool($raw) ? ($raw ? 'true' : 'false') : trim((string) $raw);
            try {
                $converted = Config::convertValue($text, $spec);
            } catch (\InvalidArgumentException $e) {
                $this->logger->info('설정 값 거부', ['key' => $name, 'reason' => $e->getMessage()]);
                throw new ApiException(422, 'setting_invalid_value', [], null, [], [], ['key' => $name] + $this->range($spec));
            }
            if ($spec['type'] === 'path') {
                $check = $this->paths->check($text, in_array($name, $createPaths, true));
                if (!in_array($check['code'], ['ok', 'warn_same_volume'], true)) {
                    throw new ApiException(422, 'setting_path_invalid', [], null, [], [], ['key' => $name, 'code' => $check['code'], 'can_create' => $check['can_create']]);
                }
                $converted = (string) $check['path']; // 정규화한 절대 경로로 저장
            }
            [$section, $key] = explode('.', $name, 2);
            $values[$section][$key] = $converted;
            $write[$name] = Config::display($converted, $spec);
            $audit[$name] = ['old' => self::auditValue($name, Config::display($this->config->get($name), $spec)), 'new' => self::auditValue($name, $write[$name]), 'old_source' => $this->config->source($name)];
        }
        $this->crossCheck($values, array_keys($write));
        $this->guard(array_keys($write));
        return $this->commitWithMaintenance($actor, $write, $version, $audit);
    }

    /**
     * P32B: maintenance.enabled가 꺼짐 → 켜짐이면 버전을 먼저 확인하고(충돌이면 백업도 하지 않음) 직전 백업을 한 뒤, 성공해야 저장한다.
     * 실패하면 저장하지 않고 409 maintenance_backup_failed(사유 코드). 켜짐·꺼짐 전이는 config.update와 별도로 maintenance.on/off 감사.
     *
     * @param array<string, ?string> $write
     * @param array<string, array<string, mixed>> $audit
     * @return array<string, mixed>
     */
    private function commitWithMaintenance(Actor $actor, array $write, string $version, array $audit): array
    {
        $transition = null;
        if (array_key_exists('maintenance.enabled', $write)) {
            $old = $this->config->bool('maintenance.enabled');
            $new = $write['maintenance.enabled'] === null ? (bool) $this->config->base('maintenance.enabled')['value'] : $write['maintenance.enabled'] === 'true';
            $transition = $new && !$old ? 'on' : (!$new && $old ? 'off' : null);
        }
        $details = [];
        if ($transition === 'on') {
            $current = $this->store->read();
            if ($current['version'] !== $version) {
                throw new ApiException(409, 'settings_conflict', [], null, [], [], ['version' => $current['version']]);
            }
            if ($this->beforeMaintenance !== null) {
                $r = ($this->beforeMaintenance)($actor);
                if (!($r['ok'] ?? false)) {
                    $code = (string) ($r['code'] ?? 'unknown');
                    $this->logger->warn('유지보수 모드 켜기 실패(직전 백업)', ['code' => $code]);
                    throw new ApiException(409, 'maintenance_backup_failed', ['reason' => $code], null, [], [], ['code' => $code]);
                }
                $details['bundle'] = (string) ($r['name'] ?? '');
            }
        }
        $view = $this->commit($actor, $write, $version, $audit);
        if ($transition !== null) {
            $this->audit->record($actor, 'maintenance.' . $transition, 'config', 'maintenance.enabled', $details);
            $this->logger->info($transition === 'on' ? '업그레이드 유지보수 모드 켬' : '업그레이드 유지보수 모드 끔', $details);
        }
        return $view;
    }

    /** 기본값으로(화면 설정을 지워 config.ini·기본값으로 돌아감) */
    public function reset(Actor $actor, string $name, string $version): array
    {
        $registry = $this->config->registry();
        if (!$registry->has($name)) {
            throw new ApiException(400, 'setting_not_editable', [], null, [], [], ['key' => preg_match('/^[a-z_]+\.[a-z_]+$/', $name) === 1 ? $name : null]);
        }
        if ($this->config->source($name) === 'env') {
            throw new ApiException(422, 'setting_env_locked', ['env' => \PJGuardian\Support\SettingsRegistry::envName($name)], null, [], [], ['key' => $name]);
        }
        $current = $this->store->read();
        [$section, $key] = explode('.', $name, 2);
        if (!isset($current['values'][$section][$key])) {
            if ($current['version'] !== $version) {
                throw new ApiException(409, 'settings_conflict', [], null, [], [], ['version' => $current['version']]);
            }
            return $this->view(); // 화면 설정이 없음 — 바꿀 것이 없다
        }
        $spec = $registry->get($name)['spec'];
        $base = $this->config->base($name);
        $values = $this->config->allValues();
        $values[$section][$key] = $base['value'];
        $this->crossCheck($values, [$name]);
        $this->guard([$name]);
        return $this->commitWithMaintenance($actor, [$name => null], $version,
            [$name => ['old' => self::auditValue($name, Config::display($this->config->get($name), $spec)), 'new' => self::auditValue($name, Config::display($base['value'], $spec)), 'old_source' => $this->config->source($name)]]);
    }

    /** 경로 확인(저장 없이 검사만). $create면 없을 때 만든다(화면이 먼저 확인받음) */
    public function validatePath(string $name, string $value, bool $create): array
    {
        $registry = $this->config->registry();
        if (!$registry->has($name) || $registry->get($name)['spec']['type'] !== 'path') {
            throw new ApiException(400, 'setting_not_editable', [], null, [], [], ['key' => preg_match('/^[a-z_]+\.[a-z_]+$/', $name) === 1 ? $name : null]);
        }
        $r = $this->paths->check($value, $create);
        if (!in_array($r['code'], ['ok', 'warn_same_volume'], true)) {
            $this->logger->info('경로 확인 실패', ['key' => $name, 'code' => $r['code']]);
        }
        return $r;
    }

    /** @return list<array{at: string, user: string, key: string, old: ?string, new: ?string}> */
    public function history(int $limit = self::HISTORY_LIMIT): array
    {
        $rows = $this->db->all(
            "SELECT occurred_at, actor_label, target_id, details FROM audit_log WHERE target_type = 'config' AND action = ? ORDER BY id DESC LIMIT ?",
            [self::AUDIT_ACTION, max(1, min(200, $limit))],
        );
        return array_map(static function (array $r): array {
            $d = json_decode((string) $r['details'], true);
            return ['at' => (string) $r['occurred_at'], 'user' => (string) $r['actor_label'], 'key' => (string) $r['target_id'],
                'old' => is_array($d) && isset($d['old']) ? (string) $d['old'] : null, 'new' => is_array($d) && isset($d['new']) ? (string) $d['new'] : null];
        }, $rows);
    }

    /**
     * @param array<string, ?string> $write
     * @param array<string, array{old: string, new: string, old_source: string}> $audit
     */
    /** @param list<string> $names 바꾸려는 키 — 진행 중 작업 때문에 지금 바꿀 수 없으면 409 setting_busy(details key·reason) */
    private function guard(array $names): void
    {
        foreach ($names as $name) {
            $reason = isset($this->guards[$name]) ? ($this->guards[$name])() : null;
            if ($reason !== null) {
                throw new ApiException(409, 'setting_busy', [], null, [], [], ['key' => $name, 'reason' => $reason]);
            }
        }
    }

    private function commit(Actor $actor, array $write, string $version, array $audit): array
    {
        try {
            $newVersion = $this->store->write($write, $version);
        } catch (SettingsConflictException $e) {
            throw new ApiException(409, 'settings_conflict', [], null, [], [], ['version' => $e->currentVersion]);
        }
        foreach ($audit as $name => $a) {
            $this->audit->record($actor, self::AUDIT_ACTION, 'config', $name, $a + ['file' => Config::LOCAL_FILE]);
        }
        $this->logger->info('화면 설정 변경', ['keys' => array_keys($write), 'version' => substr($newVersion, 0, 12)]);
        return $this->view(($this->reload)());
    }

    /**
     * @param array<string, array<string, mixed>> $values
     * @param list<string> $changed
     */
    private function crossCheck(array $values, array $changed): void
    {
        $errors = ConfigSchema::crossCheck($values);
        if ($errors === []) {
            return;
        }
        $keys = [];
        foreach ($errors as $err) {
            if (preg_match('/^([a-z_]+\.[a-z_]+):/', $err, $m) === 1) {
                $keys[] = $m[1];
            }
        }
        throw new ApiException(422, 'setting_cross_check', [], null, ['errors' => $errors], [], ['keys' => array_values(array_unique([...$keys, ...$changed]))]);
    }

    /** @return array{min?: string, max?: string} */
    private function range(array $spec): array
    {
        $out = [];
        if (isset($spec['min'])) {
            $out['min'] = Config::display($spec['min'], $spec);
        }
        if (isset($spec['max'])) {
            $out['max'] = Config::display($spec['max'], $spec);
        }
        return $out;
    }
}
