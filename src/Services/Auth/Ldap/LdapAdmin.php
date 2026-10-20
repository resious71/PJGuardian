<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Auth\AppSecrets;
use PJGuardian\Support\Clock;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * 관리자 LDAP 화면 지원(P28B, admin만): 상태, 검색 계정 비밀번호 저장·교체·삭제(값은 다시 보여 주지 않음 — set·none·unreadable),
 * 연결 시험(서버 연결·검색 계정 바인드·Base DN·시험용 로그인 ID 검색, 비밀번호는 묻지 않음). 연결 시험은 사용자당 분당 ldap.test_per_minute회.
 * 응답·로그·감사 로그에 비밀번호·바인드 DN 원문을 넣지 않는다.
 */
final class LdapAdmin
{
    /** @param \Closure(): DirectoryClient $client */
    public function __construct(
        private readonly LdapSettings $settings,
        private readonly AppSecrets $secrets,
        private readonly \Closure $client,
        private readonly bool $extensionLoaded,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly string $workRoot,
        private readonly int $testPerMinute,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $ca = $this->settings->caFile;
        return [
            'enabled' => $this->settings->enabled,
            'extension_loaded' => $this->extensionLoaded,
            'configured' => $this->settings->complete() && $this->settings->bindDn !== '',
            'servers' => count($this->settings->servers),
            'bind_password' => $this->secrets->state('ldap.bind_password'),
            'ca_file' => $ca === '' ? 'none' : (is_file($ca) && is_readable($ca) ? 'ok' : 'missing'),
        ];
    }

    /**
     * 시스템 점검 화면 항목(P28B): LDAP이 켜져 있을 때만 — 확장 없음·설정 빠짐·검색 계정 비밀번호 없음/읽을 수 없음·CA 파일 없음·연결 불가는 문제로.
     * 연결은 설정이 갖춰졌을 때만 시험한다(연결 시간 상한 ldap.connect_timeout). 값·DN은 넣지 않는다.
     *
     * @return list<array{section:string, id:string, status:string, value:string, note:?string, params:array<string,mixed>}>
     */
    public function checkItems(): array
    {
        if (!$this->settings->enabled) {
            return [];
        }
        $item = static fn (string $status, string $note): array => ['section' => 'auth', 'id' => 'ldap', 'status' => $status, 'value' => '', 'note' => $note, 'params' => []];
        $st = $this->status();
        if (!$this->extensionLoaded) {
            return [$item('fail', 'ldap_extension_missing')];
        }
        if (!$st['configured']) {
            return [$item('fail', 'ldap_not_configured')];
        }
        if ($st['bind_password'] !== 'set') {
            return [$item('fail', $st['bind_password'] === 'unreadable' ? 'ldap_secret_unreadable' : 'ldap_secret_missing')];
        }
        if ($st['ca_file'] === 'missing') {
            return [$item('fail', 'ldap_ca_missing')];
        }
        try {
            $steps = ($this->client)()->probe(null);
        } catch (DirectoryException $e) {
            $steps = [['step' => 'connect', 'ok' => false, 'code' => $e->reason, 'detail' => []]];
        }
        foreach ($steps as $s) {
            if ($s['ok'] === false) {
                return [$item('fail', $s['step'] === 'connect' ? 'ldap_unreachable' : 'ldap_' . $s['step'] . '_failed')];
            }
        }
        return [$item('ok', 'ldap_ok')];
    }

    public function setBindPassword(Actor $actor, mixed $password): string
    {
        if (!is_string($password)) {
            throw new ApiException(400, 'app_secret_invalid', ['max' => AppSecrets::MAX_BYTES]);
        }
        $replaced = $this->secrets->state('ldap.bind_password') !== 'none';
        $this->secrets->put('ldap.bind_password', $password, $actor->userId);
        $this->audit->record($actor, 'auth.ldap_secret_set', null, null, ['replaced' => $replaced]); // 값은 남기지 않는다
        $this->logger->info('LDAP 검색 계정 비밀번호 저장', ['by' => $actor->label]);
        return 'set';
    }

    public function deleteBindPassword(Actor $actor): string
    {
        if ($this->secrets->delete('ldap.bind_password')) {
            $this->audit->record($actor, 'auth.ldap_secret_delete', null, null, []);
        }
        return 'none';
    }

    /**
     * @return array{steps: list<array{step:string, ok:?bool, code:?string, detail:array<string,mixed>}>, ok: bool}
     */
    public function test(Actor $actor, mixed $loginId): array
    {
        if ($loginId !== null && (!is_string($loginId) || strlen($loginId) > 100 || preg_match('/[\x00-\x1f\x7f]/', $loginId) === 1)) {
            throw new ApiException(400, 'invalid_input');
        }
        $this->consumeQuota((int) $actor->userId);
        if (!$this->extensionLoaded) {
            $steps = [['step' => 'connect', 'ok' => false, 'code' => 'extension_missing', 'detail' => []]];
        } else {
            try {
                $steps = ($this->client)()->probe($loginId === '' ? null : $loginId);
            } catch (DirectoryException $e) {
                $steps = [['step' => 'connect', 'ok' => false, 'code' => $e->reason, 'detail' => []]];
            }
        }
        $ok = $steps !== [] && array_reduce($steps, static fn (bool $c, array $s): bool => $c && $s['ok'] !== false, true);
        $this->logger->info('LDAP 연결 시험', ['ok' => $ok, 'steps' => implode(',', array_map(static fn (array $s): string => $s['step'] . ':' . ($s['code'] ?? 'ok'), $steps)), 'by' => $actor->label]);
        return ['steps' => $steps, 'ok' => $ok];
    }

    /** 사용자당 분당 횟수(저장소 연결 시험 P25와 같은 방식 — 잠금 전용 파일 + 시각 목록) */
    private function consumeQuota(int $userId): void
    {
        $dir = $this->workRoot . '/ldap_test';
        Fs::ensureDir($dir);
        $lock = FileLock::acquire($dir . '/rate-' . $userId . '.lock');
        try {
            $file = $dir . '/rate-' . $userId . '.json';
            $now = $this->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $this->testPerMinute) {
                throw new ApiException(429, 'ldap_test_rate_limited', ['n' => $this->testPerMinute]);
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
        } finally {
            $lock->release();
        }
    }
}
