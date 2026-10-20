<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

use PJGuardian\Repositories\UserRepository;
use PJGuardian\Services\Auth\Ldap\LdapLogin;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 로그인 판정(명세 3, 13, 19.2, 19.5).
 *
 * 순서
 *  1. IP 차단 확인: 같은 IP의 최근 실패 N건(기본 20)이 창(10분) 안에 몰려 있으면 마지막 실패 + 차단 시간(15분)까지 거부.
 *     차단 중의 시도는 기록하지 않는다(차단이 끝없이 늘어나지 않게).
 *  2. 계정 조회. 없으면 같은 비용의 더미 해시를 검증해 시간 차이를 없앤다(계정 열거 방지).
 *  3. 비밀번호는 잠금·비활성 여부와 관계없이 항상 검증한다(응답 시간으로 상태가 드러나지 않게).
 *  4. 잠금 중(locked_until > 지금)이면 비밀번호가 맞아도 거부. 실패 횟수는 늘리지 않는다.
 *  5. 틀리면 실패 횟수 +1, 최대(5)에 이르면 15분 잠금하고 횟수를 0으로 돌린다(해제 후 다시 5번 기회).
 *  6. 맞으면 실패 횟수 초기화. 비활성 계정은 거부, 임시 비밀번호가 만료됐으면 temp_password_expired.
 *  7. 성공: 관리자가 비밀번호를 바꾼 뒤 첫 로그인이면 알림(password_set_by_admin)을 결과에 담고 기록을 비운다.
 *  없는 ID·틀린 비밀번호·잠금·비활성은 모두 같은 코드(login_failed)다.
 *
 * P28B 외부 인증(LDAP/AD): 입력 ID의 계정이 auth_type=ldap이거나, 계정이 없고 LDAP이 켜져 있으면 LdapLogin이 판정한다.
 *  - 로컬 계정(같은 ID)은 디렉터리로 로그인되지 않는다. 꺼진 방식의 계정(ldap인데 LDAP 꺼짐)은 일반 실패.
 *  - 앱의 계정 잠금(5회/15분)은 LDAP 계정에 적용하지 않는다(디렉터리 정책). IP 차단은 그대로, 그리고 같은 로그인 ID로
 *    창(auth.ip_failure_window) 안에 lockout_max_failures × 2번 실패하면 디렉터리에 묻지 않고 거부(디렉터리 계정 잠금 폭주 방지).
 *  - 결과: 승인 대기(pending)·거절(rejected)·대기 상한(pending_limit)은 세션 없이 안내, 디렉터리에 닿지 않으면 directory_unavailable.
 *
 * P28C SSO(OIDC): 비밀번호 폼이 아니라 콜백에서 attemptExternal(제공자가 확인한 신원) — oidc 계정은 이 폼(attempt)으로 로그인되지 않는다(일반 실패).
 */
final class LoginService
{
    /** @var array<string, AuthProvider> */
    private array $providers = [];
    private readonly UserRepository $users;

    /** @param list<AuthProvider>|null $providers 기본은 로컬만 */
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuthSettings $settings,
        private readonly Logger $logger,
        ?array $providers = null,
        private readonly ?LdapLogin $ldap = null,
        private readonly ?ExternalAccountProvisioner $external = null,
    ) {
        foreach ($providers ?? [new LocalAuthProvider()] as $p) {
            $this->providers[$p->type()] = $p;
        }
        $this->users = new UserRepository($db);
    }

    /** 로그에 남길 입력 로그인 ID(원문 대신): 첫 글자·길이·SHA-256 앞 8자리 — 같은 입력의 반복은 알아볼 수 있고 원문은 남지 않음 */
    public static function loginHint(string $loginId): string
    {
        if ($loginId === '') {
            return '';
        }
        $first = mb_substr($loginId, 0, 1);
        return (preg_match('/^[A-Za-z0-9]$/', $first) === 1 ? $first : '?') . '***(' . mb_strlen($loginId) . ', #' . substr(hash('sha256', $loginId), 0, 8) . ')';
    }

    public function attempt(string $loginId, string $password, string $ip): LoginResult
    {
        $now = $this->clock->now();
        $nowIso = SystemClock::iso($now);
        $ip = $ip !== '' ? substr($ip, 0, 45) : 'unknown';
        // P31: 없는 계정·확인 전에는 입력한 로그인 ID 원문을 로그에 남기지 않는다(비밀번호를 ID 칸에 잘못 넣는 경우) — 실제 계정이면 그 계정 ID
        $logCtx = ['login_id' => self::loginHint($loginId), 'ip' => $ip];

        $blockedUntil = $this->ipBlockedUntil($ip, $now);
        if ($blockedUntil !== null) {
            $this->logger->warn('로그인 거부: IP 차단 중', $logCtx + ['until' => $blockedUntil]);
            return LoginResult::failure('ip_blocked');
        }

        $user = $loginId === '' ? null : $this->users->findForAuth($loginId);
        if ($user !== null) {
            $logCtx['login_id'] = mb_substr((string) $user['login_id'], 0, 100);
        }
        if ($loginId !== '' && $this->ldap !== null && $this->ldap->enabled() && ($user === null || $user['auth_type'] === 'ldap')) {
            return $this->attemptLdap($loginId, $password, $ip, $user, $now, $nowIso, $logCtx);
        }
        $provider = $user !== null ? ($this->providers[(string) $user['auth_type']] ?? null) : null;
        if ($user === null || $provider === null) {
            // 같은 비용의 더미 해시로 검증(소요 시간을 맞춘다)
            password_verify($password, $this->dummyHash());
            return $this->fail($ip, $nowIso, $logCtx + ['reason' => $user === null ? 'unknown_user' : 'no_provider']);
        }

        $valid = $provider->verify($user, $password);
        $uid = (int) $user['id'];
        $logCtx['user_id'] = $uid;

        if ($provider->appliesLockout() && $user['locked_until'] !== null && (string) $user['locked_until'] > $nowIso) {
            return $this->fail($ip, $nowIso, $logCtx + ['reason' => 'locked']);
        }
        if (!$valid) {
            if ($provider->appliesLockout()) {
                $count = (int) $user['failed_login_count'] + 1;
                $lockedUntil = null;
                if ($count >= $this->settings->lockoutMaxFailures) {
                    $lockedUntil = SystemClock::iso($now->modify('+' . $this->settings->lockoutDuration . ' seconds'));
                    $count = 0;
                    $this->logger->warn('계정 잠금', $logCtx + ['until' => $lockedUntil]);
                }
                $this->users->recordFailure($uid, $count, $lockedUntil);
            }
            return $this->fail($ip, $nowIso, $logCtx + ['reason' => 'bad_password']);
        }
        if ($user['status'] !== 'active') {
            return $this->fail($ip, $nowIso, $logCtx + ['reason' => 'inactive']);
        }
        $this->users->clearFailures($uid);
        if ($user['temp_password_expires_at'] !== null && (string) $user['temp_password_expires_at'] <= $nowIso) {
            $this->logger->info('로그인 거부: 임시 비밀번호 만료', $logCtx);
            return LoginResult::failure('temp_password_expired');
        }
        if ($provider->type() === 'local' && password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT, ['cost' => $this->settings->bcryptCost])) {
            $this->users->rehash($uid, $this->settings->hash($password));
        }
        $this->logger->withUserId($uid);
        $this->logger->info('로그인 성공', $logCtx);
        // 관리자가 비밀번호를 바꿨으면 이번 로그인에 한 번 알린다(변경은 강제하지 않음, 명세 13장·19.10)
        $setAt = $this->users->takeAdminPasswordNotice($uid);
        $notice = $setAt === null ? null : ['code' => 'password_set_by_admin', 'at' => $setAt];
        return LoginResult::success(UserRepository::toPublic($user, $nowIso), $notice);
    }

    /**
     * LDAP 로그인(P28B). 세션 발급은 호출한 쪽이 성공 결과로만 한다.
     *
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $logCtx
     */
    private function attemptLdap(string $loginId, string $password, string $ip, ?array $user, \DateTimeImmutable $now, string $nowIso, array $logCtx): LoginResult
    {
        $key = self::loginKey($loginId);
        if ($this->loginKeyBlocked($key, $now)) {
            $this->logger->warn('로그인 거부: 같은 로그인 ID 반복 실패(디렉터리에 묻지 않음)', $logCtx);
            return LoginResult::failure('ip_blocked');
        }
        $out = $this->ldap->authenticate($loginId, $password, $user, $ip);
        switch ($out['code']) {
            case 'ok':
                $u = $out['user'];
                $this->logger->withUserId((int) $u['id']);
                $this->logger->info('로그인 성공(LDAP)', $logCtx + ['user_id' => (int) $u['id']]);
                return LoginResult::success(UserRepository::toPublic($u, $nowIso));
            case 'pending':
            case 'rejected':
                $this->logger->info('로그인: 승인 대기·거절 계정(세션 없음)', $logCtx + ['state' => $out['code']]);
                return LoginResult::failure($out['code']);
            case 'limit':
                return LoginResult::failure('pending_limit');
            case 'unavailable':
                return LoginResult::failure('directory_unavailable');
            default:
                return $this->fail($ip, $nowIso, $logCtx + ['reason' => 'ldap_' . ($out['reason'] ?? 'failed')], $key);
        }
    }

    /**
     * SSO 로그인(P28C OIDC, P28D SAML): 제공자 프로토콜(state·토큰 검증)을 통과한 신원으로 계정을 정한다 — ExternalAccountProvisioner::onFederated.
     * IP 차단 중이면 거부. 결과 코드는 LDAP과 같다(ok·pending·rejected·pending_limit·login_failed). 세션 발급은 호출한 쪽이 성공 결과로만.
     *
     * @param array{login:string, id:string, name:?string, email:?string} $identity
     */
    public function attemptExternal(string $authType, string $issuer, array $identity, string $ip): LoginResult
    {
        $now = $this->clock->now();
        $nowIso = SystemClock::iso($now);
        $ip = $ip !== '' ? substr($ip, 0, 45) : 'unknown';
        $logCtx = ['login_id' => mb_substr($identity['login'], 0, 100), 'ip' => $ip, 'auth_type' => $authType];
        if ($this->external === null) {
            return $this->fail($ip, $nowIso, $logCtx + ['reason' => 'no_provider']);
        }
        if ($this->ipBlockedUntil($ip, $now) !== null) {
            return LoginResult::failure('ip_blocked');
        }
        $out = $this->external->onFederated($authType, $issuer, $identity, $ip);
        switch ($out['code']) {
            case 'ok':
                $u = $out['user'];
                $this->logger->withUserId((int) $u['id']);
                $this->logger->info('로그인 성공(' . $authType . ')', $logCtx + ['user_id' => (int) $u['id']]);
                return LoginResult::success(UserRepository::toPublic($u, $nowIso));
            case 'pending':
            case 'rejected':
                $this->logger->info('로그인: 승인 대기·거절 계정(세션 없음)', $logCtx + ['state' => $out['code']]);
                return LoginResult::failure($out['code']);
            case 'limit':
                return LoginResult::failure('pending_limit');
            default:
                return $this->fail($ip, $nowIso, $logCtx + ['reason' => $authType . '_' . ($out['reason'] ?? 'failed')]);
        }
    }

    /** 로그인 ID 키: 소문자 로그인 ID의 SHA-256 앞 32자(원문을 실패 기록에 남기지 않음) */
    public static function loginKey(string $loginId): string
    {
        return substr(hash('sha256', strtolower($loginId)), 0, 32);
    }

    private function loginKeyBlocked(string $key, \DateTimeImmutable $now): bool
    {
        $since = SystemClock::iso($now->modify('-' . $this->settings->ipFailureWindow . ' seconds'));
        if (!\PJGuardian\Support\SchemaFeatures::externalAuth($this->db)) {
            return false;
        }
        $n = (int) $this->db->value('SELECT COUNT(*) FROM login_failures WHERE login_key = ? AND failed_at >= ?', [$key, $since]);
        return $n >= $this->settings->lockoutMaxFailures * 2;
    }

    /** @param array<string, mixed> $logCtx */
    private function fail(string $ip, string $nowIso, array $logCtx, ?string $loginKey = null): LoginResult
    {
        if (\PJGuardian\Support\SchemaFeatures::externalAuth($this->db)) {
            $this->db->execute('INSERT INTO login_failures (ip, failed_at, login_key) VALUES (?, ?, ?)', [$ip, $nowIso, $loginKey]);
        } else {
            $this->db->execute('INSERT INTO login_failures (ip, failed_at) VALUES (?, ?)', [$ip, $nowIso]); // 026 전
        }
        // 이 IP의 판정에 더는 쓰이지 않는 오래된 기록 정리(창 + 차단 시간보다 오래된 것)
        $keepFrom = SystemClock::iso((new \DateTimeImmutable($nowIso))->modify(
            '-' . ($this->settings->ipFailureWindow + $this->settings->ipBlockDuration) . ' seconds',
        ));
        $this->db->execute('DELETE FROM login_failures WHERE ip = ? AND failed_at < ?', [$ip, $keepFrom]);
        $this->logger->warn('로그인 실패', $logCtx);
        return LoginResult::failure('login_failed');
    }

    /** 차단 중이면 해제 시각(ISO), 아니면 null */
    public function ipBlockedUntil(string $ip, \DateTimeImmutable $now): ?string
    {
        $n = $this->settings->ipMaxFailures;
        $rows = $this->db->all('SELECT failed_at FROM login_failures WHERE ip = ? ORDER BY failed_at DESC, id DESC LIMIT ?', [$ip, $n]);
        if (count($rows) < $n) {
            return null;
        }
        $newest = new \DateTimeImmutable((string) $rows[0]['failed_at']);
        $oldest = new \DateTimeImmutable((string) $rows[$n - 1]['failed_at']);
        if ($newest->getTimestamp() - $oldest->getTimestamp() > $this->settings->ipFailureWindow) {
            return null;
        }
        $until = $newest->modify('+' . $this->settings->ipBlockDuration . ' seconds');
        return $now < $until ? SystemClock::iso($until) : null;
    }

    /**
     * 설정 비용과 같은 비용의 bcrypt 문자열. 맞는 비밀번호가 없으므로 항상 false지만 계산 시간은 실제 해시와 같다.
     * 형식: $2y$<비용>$<솔트 22자><해시 31자>
     */
    private function dummyHash(): string
    {
        return sprintf('$2y$%02d$', $this->settings->bcryptCost) . str_repeat('A', 22) . str_repeat('B', 31);
    }
}
