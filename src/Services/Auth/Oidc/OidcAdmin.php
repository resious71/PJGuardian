<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Oidc;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Auth\AppSecrets;
use PJGuardian\Support\Clock;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;

/**
 * 관리자 SSO(OIDC) 화면 지원(P28C, admin만): 상태(리다이렉트 URI 포함), 클라이언트 비밀 저장·교체·삭제(값은 다시 보여 주지 않음),
 * 연결 시험(디스커버리·issuer 일치 → JWKS 키 개수·알고리즘 → 토큰 엔드포인트 도달 — 자격 증명 없이 보내 오류 응답이면 도달), 점검 항목.
 * 연결 시험은 사용자당 분당 oidc.test_per_minute회. 응답·로그·감사 로그에 비밀·토큰 원문을 넣지 않는다.
 */
final class OidcAdmin
{
    public function __construct(
        private readonly OidcSettings $settings,
        private readonly OidcProvider $provider,
        private readonly OidcHttp $http,
        private readonly AppSecrets $secrets,
        private readonly bool $schemaReady,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly string $workRoot,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'enabled' => $this->settings->enabled,
            'schema_ready' => $this->schemaReady,
            'configured' => $this->settings->complete(),
            'client_auth' => $this->settings->clientAuth,
            'client_secret' => $this->schemaReady ? $this->secrets->state('oidc.client_secret') : 'none',
            'redirect_uri' => $this->settings->redirectUri(),
            'post_logout_uri' => $this->settings->postLogoutUri(),
        ];
    }

    /**
     * 시스템 점검 항목: OIDC가 켜져 있을 때만 — 마이그레이션 027 없음·설정 빠짐·클라이언트 비밀 없음/읽을 수 없음·디스커버리 실패는 경고.
     *
     * @return list<array{section:string, id:string, status:string, value:string, note:?string, params:array<string,mixed>}>
     */
    public function checkItems(): array
    {
        if (!$this->settings->enabled) {
            return [];
        }
        $item = static fn (string $status, string $note): array => ['section' => 'auth', 'id' => 'oidc', 'status' => $status, 'value' => '', 'note' => $note, 'params' => []];
        if (!$this->schemaReady) {
            return [$item('warn', 'oidc_migration_required')];
        }
        if (!$this->settings->complete()) {
            return [$item('warn', 'oidc_not_configured')];
        }
        if ($this->settings->needsSecret()) {
            $state = $this->secrets->state('oidc.client_secret');
            if ($state !== 'set') {
                return [$item('warn', $state === 'unreadable' ? 'oidc_secret_unreadable' : 'oidc_secret_missing')];
            }
        }
        try {
            $this->provider->metadata();
        } catch (OidcException $e) {
            $this->logger->warn('OIDC 점검: 디스커버리 실패', ['reason' => $e->reason]);
            return [$item('warn', 'oidc_discovery_failed')];
        }
        return [$item('ok', 'oidc_ok')];
    }

    public function setClientSecret(Actor $actor, mixed $secret): string
    {
        if (!is_string($secret)) {
            throw new ApiException(400, 'app_secret_invalid', ['max' => AppSecrets::MAX_BYTES]);
        }
        $replaced = $this->secrets->state('oidc.client_secret') !== 'none';
        $this->secrets->put('oidc.client_secret', $secret, $actor->userId);
        $this->audit->record($actor, 'auth.oidc_secret_set', null, null, ['replaced' => $replaced]); // 값은 남기지 않는다
        $this->logger->info('OIDC 클라이언트 비밀 저장', ['by' => $actor->label]);
        return 'set';
    }

    public function deleteClientSecret(Actor $actor): string
    {
        if ($this->schemaReady && $this->secrets->delete('oidc.client_secret')) {
            $this->audit->record($actor, 'auth.oidc_secret_delete', null, null, []);
        }
        return 'none';
    }

    /**
     * @return array{steps: list<array{step:string, ok:?bool, code:?string, detail:array<string,mixed>}>, ok: bool}
     */
    public function test(Actor $actor, int $perMinute): array
    {
        $this->consumeQuota((int) $actor->userId, $perMinute);
        $steps = [];
        $failed = false;
        $run = function (string $step, \Closure $fn) use (&$steps, &$failed): void {
            if ($failed) {
                $steps[] = ['step' => $step, 'ok' => null, 'code' => null, 'detail' => []];
                return;
            }
            try {
                $steps[] = ['step' => $step, 'ok' => true, 'code' => null, 'detail' => $fn()];
            } catch (OidcException $e) {
                $failed = true;
                $steps[] = ['step' => $step, 'ok' => false, 'code' => explode('.', $e->reason, 2)[0], 'detail' => []];
            }
        };
        $meta = [];
        $run('discovery', function () use (&$meta): array {
            if (!$this->settings->complete()) {
                throw new OidcException('not_configured');
            }
            $meta = $this->provider->metadata(true);
            return ['issuer_match' => true, 'userinfo' => isset($meta['userinfo_endpoint']), 'end_session' => isset($meta['end_session_endpoint'])];
        });
        $run('jwks', function (): array {
            $keys = $this->provider->keys(false, true);
            $algs = [];
            foreach (['RS256', 'ES256'] as $alg) {
                if (array_filter($keys, static fn (array $k): bool => Jose::keyFits($k, $alg)) !== []) {
                    $algs[] = $alg;
                }
            }
            $usable = array_values(array_intersect($algs, $this->settings->allowedAlgs));
            if ($usable === []) {
                throw new OidcException('jwks_no_usable_key');
            }
            return ['keys' => count($keys), 'algs' => $usable];
        });
        $run('token_endpoint', function () use (&$meta): array {
            // 자격 증명 없이 보낸다 — 4xx 오류 응답이면 도달(코드·비밀 없음). 5xx·연결 실패는 실패
            $res = $this->http->post((string) $meta['token_endpoint'], ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                'grant_type=authorization_code&code=pjg-connection-test');
            if ($res['status'] >= 500) {
                throw new OidcException('http_status');
            }
            return ['status' => $res['status']];
        });
        $ok = !$failed;
        $this->logger->info('OIDC 연결 시험', ['ok' => $ok, 'steps' => implode(',', array_map(static fn (array $s): string => $s['step'] . ':' . ($s['code'] ?? ($s['ok'] === null ? 'skipped' : 'ok')), $steps)), 'by' => $actor->label]);
        return ['steps' => $steps, 'ok' => $ok];
    }

    /** 사용자당 분당 횟수(LDAP 연결 시험과 같은 방식) */
    private function consumeQuota(int $userId, int $perMinute): void
    {
        $dir = $this->workRoot . '/oidc/test';
        Fs::ensureDir($dir);
        $lock = FileLock::acquire($dir . '/rate-' . $userId . '.lock');
        try {
            $file = $dir . '/rate-' . $userId . '.json';
            $now = $this->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $perMinute) {
                throw new ApiException(429, 'oidc_test_rate_limited', ['n' => $perMinute]);
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
        } finally {
            $lock->release();
        }
    }
}
