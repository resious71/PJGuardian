<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

use PJGuardian\Http\ApiException;
use PJGuardian\Repositories\UserRepository;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Notifications\NotificationService;
use PJGuardian\Services\UserProfileRules;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 외부 인증 계정 공통 서비스(P28B 4A절 — LDAP/AD, P28C OIDC, P28D SAML이 함께 쓴다).
 *
 * 외부 인증에 성공한 뒤:
 *  - 앱에 계정이 있으면: 같은 인증 방식이어야 하고(로컬 계정은 외부 인증으로 로그인되지 않음 — 자동 전환·탈취 금지), 승인 상태를 본다
 *    (pending → 승인 대기 안내, rejected → 승인되지 않음 안내, 세션 없음). external_id가 비었으면 이 로그인으로 연결(관리자가 미리 만든 계정 —
 *    CSV·인증 방식 전환), 있는데 다르면 거부(같은 로그인 ID를 다른 사람이 다시 씀 — 감사 경고·관리자 알림). 이름·이메일은 갱신하지 않는다.
 *  - 없으면: 로그인 ID 규칙(X06)을 통과하고 대기 상한(auth.pending_max) 안이면 **승인 대기 계정**을 만든다 — 비활성·역할 효력 없음·
 *    비밀번호 없음, 이름·이메일·외부 식별자는 이때 한 번만 외부 값으로. 관리자에게 알림(account_pending, 하루 5건 넘으면 요약 1건).
 * 관리자: 승인(역할 user 기본·admin 선택), 거절(rejected로 남겨 같은 사람의 재요청이 쌓이지 않게), 삭제(승인된 적 없는 계정만),
 * 보관 기간(auth.pending_retention_days)이 지난 대기·거절 계정은 정리 작업이 지운다. 승인·거절은 updated_at으로 동시 처리 충돌(409)을 막는다.
 * SSO(P28C OIDC~)는 onFederated — (발급자, 식별자) 먼저, 로그인 ID 충돌은 거부(login_id_conflict).
 * 감사: user.pending_create, user.approve, user.reject, user.pending_delete, user.pending_purge, user.external_link, auth.login_failed(사유). 이메일 원문은 남기지 않는다.
 */
final class ExternalAccountProvisioner
{
    private const LOGIN_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._@-]{0,99}$/'; // X06(UserService와 같음)
    /** 하루에 개별 알림을 보내는 승인 대기 건수 — 넘으면 그날 요약 1건으로 합친다 */
    public const DAILY_DETAIL = 5;

    private readonly UserRepository $repo;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly NotificationService $notifications,
        private readonly int $pendingMax,
        private readonly int $retentionDays,
    ) {
        $this->repo = new UserRepository($db);
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /**
     * 외부 인증 성공 뒤 판정.
     *
     * @param array{login:string, id:string, name:?string, email:?string} $identity
     * @param array<string, mixed>|null $user 입력한 로그인 ID로 찾은 계정(없으면 null)
     * @return array{code:string, user?:array<string,mixed>, reason?:string}
     *   code: ok | pending | rejected | limit | failed(reason: local_account|invalid_login_id|identity_mismatch|identity_conflict|inactive)
     */
    public function onAuthenticated(string $authType, string $issuer, array $identity, ?array $user, ?string $ip): array
    {
        $user ??= $this->repo->findForAuth($identity['login']);
        if ($user !== null) {
            return $this->existing($authType, $issuer, $identity, $user, $ip);
        }
        if (preg_match(self::LOGIN_ID_RE, $identity['login']) !== 1) {
            $this->failedAudit($identity['login'], 'invalid_login_id', $ip, $authType);
            return ['code' => 'failed', 'reason' => 'invalid_login_id'];
        }
        if ($this->identityOwner($authType, $issuer, $identity['id']) !== null) {
            $this->failedAudit($identity['login'], 'identity_conflict', $ip, $authType);
            return ['code' => 'failed', 'reason' => 'identity_conflict'];
        }
        return $this->createPending($authType, $issuer, $identity, $ip);
    }

    /**
     * SSO(OIDC P28C·SAML P28D) 판정 — 식별은 **(발급자, 외부 식별자)**가 기준이다(로그인 ID 클레임이 바뀌어도 같은 계정).
     *  ① (방식, 발급자, 식별자)로 연결된 계정이 있으면 그 계정(승인·상태 확인).
     *  ② 없으면 로그인 ID 후보(클레임)로: 같은 ID의 계정이 없으면 승인 대기 계정, 있고 같은 방식이며 아직 연결 전이면 연결(관리자가 미리 만든 계정),
     *     그 밖(로컬·LDAP·다른 식별자에 연결된 계정)은 거부 — 감사 reason=login_id_conflict + 관리자 알림. 빼앗거나 덮어쓰지 않는다.
     * 이메일이 같다는 이유만으로는 절대 연결하지 않는다(이메일은 판정에 쓰지 않음).
     *
     * @param array{login:string, id:string, name:?string, email:?string} $identity
     * @return array{code:string, user?:array<string,mixed>, reason?:string}
     */
    public function onFederated(string $authType, string $issuer, array $identity, ?string $ip): array
    {
        $owner = $this->identityOwner($authType, $issuer, $identity['id']);
        if ($owner !== null) {
            $user = $this->repo->findRaw($owner);
            if ($user !== null) {
                return $this->existing($authType, $issuer, $identity, $user, $ip);
            }
        }
        $user = $identity['login'] === '' ? null : $this->repo->findForAuth($identity['login']);
        if ($user !== null && ($user['auth_type'] !== $authType || $user['external_id'] !== null)) {
            $this->mismatch($user, $ip, $authType, 'login_id_conflict');
            return ['code' => 'failed', 'reason' => 'login_id_conflict'];
        }
        return $this->onAuthenticated($authType, $issuer, $identity, $user, $ip);
    }

    /** @param array{login:string, id:string, name:?string, email:?string} $identity @param array<string, mixed> $user */
    private function existing(string $authType, string $issuer, array $identity, array $user, ?string $ip): array
    {
        $login = (string) $user['login_id'];
        if ($user['auth_type'] !== $authType) {
            // 같은 ID의 로컬 계정(또는 다른 방식의 계정)은 외부 인증으로 로그인되지 않는다 — 관리자가 인증 방식을 바꿔야 한다
            $this->failedAudit($login, $user['auth_type'] === 'local' ? 'local_account' : 'auth_type_mismatch', $ip, $authType);
            return ['code' => 'failed', 'reason' => 'local_account'];
        }
        if ($user['approval_status'] === 'pending') {
            return ['code' => 'pending'];
        }
        if ($user['approval_status'] === 'rejected') {
            return ['code' => 'rejected'];
        }
        $uid = (int) $user['id'];
        if ($user['external_id'] === null) {
            $owner = $this->identityOwner($authType, $issuer, $identity['id']);
            if ($owner !== null && $owner !== $uid) {
                $this->mismatch($user, $ip, $authType, 'identity_conflict');
                return ['code' => 'failed', 'reason' => 'identity_conflict'];
            }
            // 관리자가 미리 만든 계정(CSV·인증 방식 전환): 첫 로그인 때 로그인 ID 일치로 연결만 — 이름·이메일은 건드리지 않는다
            $this->db->transaction(function () use ($uid, $authType, $issuer, $identity, $login): void {
                $this->db->execute('UPDATE users SET external_id = ?, external_issuer = ?, updated_at = ? WHERE id = ? AND external_id IS NULL',
                    [$identity['id'], $issuer === '' ? null : $issuer, $this->now(), $uid]);
                $this->audit->record(Actor::system(), 'user.external_link', 'user', (string) $uid, ['login_id' => $login, 'auth_type' => $authType]);
            });
            $user = $this->repo->findForAuth($login) ?? $user;
        } elseif (!hash_equals((string) $user['external_id'], $identity['id'])) {
            $this->mismatch($user, $ip, $authType, 'identity_mismatch');
            return ['code' => 'failed', 'reason' => 'identity_mismatch'];
        }
        if ($user['status'] !== 'active') {
            return ['code' => 'failed', 'reason' => 'inactive'];
        }
        return ['code' => 'ok', 'user' => $user];
    }

    /** @param array{login:string, id:string, name:?string, email:?string} $identity */
    private function createPending(string $authType, string $issuer, array $identity, ?string $ip): array
    {
        [$name] = UserProfileRules::displayName($identity['name'] ?? '');
        [$email, $emailReason] = UserProfileRules::email($identity['email'] ?? null);
        $result = $this->db->transaction(function () use ($authType, $issuer, $identity, $name, $email, $emailReason): array {
            $waiting = array_sum($this->repo->approvalCounts());
            if ($waiting >= $this->pendingMax) {
                return ['code' => 'limit'];
            }
            $id = $this->repo->insert([
                'login_id' => $identity['login'], 'display_name' => $name ?? $identity['login'], 'email' => $emailReason === null ? $email : null,
                'role' => 'user', 'status' => 'inactive', 'auth_type' => $authType, 'password_hash' => null, 'created_at' => $this->now(),
                'approval_status' => 'pending', 'external_id' => $identity['id'], 'external_issuer' => $issuer === '' ? null : $issuer,
            ]);
            $this->audit->record(Actor::system(), 'user.pending_create', 'user', (string) $id, ['login_id' => $identity['login'], 'auth_type' => $authType]);
            return ['code' => 'pending', 'id' => $id];
        });
        if ($result['code'] === 'limit') {
            $this->logger->warn('승인 대기 상한 — 계정 요청을 받지 않음', ['auth_type' => $authType, 'max' => $this->pendingMax]);
            $this->notifications->emit('account_pending_limit', $this->notifications->activeAdmins(), [], ['max' => $this->pendingMax],
                'account_pending_limit:' . substr($this->now(), 0, 10));
            return ['code' => 'limit'];
        }
        $this->logger->info('승인 대기 계정 생성', ['user_id' => $result['id'], 'auth_type' => $authType]);
        $this->notifyPending((int) $result['id'], $identity['login'], $authType);
        return ['code' => 'pending'];
    }

    /** 승인 대기 알림: 그날(UTC) 생긴 대기 계정이 5건 이하면 한 건씩, 넘으면 그날 요약 1건(건수 갱신) */
    private function notifyPending(int $userId, string $loginId, string $authType): void
    {
        $day = substr($this->now(), 0, 10);
        $today = (int) $this->db->value("SELECT COUNT(*) FROM users WHERE approval_status <> 'approved' AND created_at >= ?", [$day . 'T00:00:00Z']);
        $admins = $this->notifications->activeAdmins();
        if ($today <= self::DAILY_DETAIL) {
            $this->notifications->emit('account_pending', $admins, [], ['login_id' => $loginId, 'auth_type' => $authType], 'account_pending:' . $userId);
            return;
        }
        $this->notifications->emit('account_pending', $admins, [], ['count' => $today, 'day' => $day], 'account_pending_day:' . $day, true);
    }

    /** @param array<string, mixed> $user */
    private function mismatch(array $user, ?string $ip, string $authType, string $reason): void
    {
        $this->failedAudit((string) $user['login_id'], $reason, $ip, $authType);
        $this->notifications->emit('auth_identity_mismatch', $this->notifications->activeAdmins(), [], ['login_id' => (string) $user['login_id'], 'auth_type' => $authType, 'reason' => $reason],
            'auth_identity_mismatch:' . (int) $user['id'] . ':' . substr($this->now(), 0, 10));
    }

    /** 관리자용 감사 로그(화면에는 일반 실패만). 비밀번호·DN·필터는 넣지 않는다 */
    public function failedAudit(string $loginId, string $reason, ?string $ip, string $authType): void
    {
        $actor = $ip === null || $ip === '' ? Actor::labeled('login') : Actor::labeled('login@' . substr($ip, 0, 45));
        $this->audit->record($actor, 'auth.login_failed', null, null, ['login_id' => mb_substr($loginId, 0, 100), 'reason' => $reason, 'auth_type' => $authType]);
    }

    private function identityOwner(string $authType, string $issuer, string $externalId): ?int
    {
        $v = $this->db->value("SELECT id FROM users WHERE auth_type = ? AND COALESCE(external_issuer, '') = ? AND external_id = ?", [$authType, $issuer, $externalId]);
        return $v === null ? null : (int) $v;
    }

    // ---- 관리자 ----

    /**
     * 승인: 대기·거절 → 승인(활성, 역할 user 기본 또는 admin). $expectedUpdatedAt이 지금 값과 다르면 409(다른 관리자가 먼저 처리).
     *
     * @return array<string, mixed> 공개 사용자 정보
     */
    public function approve(Actor $actor, int $id, mixed $role, mixed $expectedUpdatedAt): array
    {
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new ApiException(400, 'invalid_role');
        }
        $row = $this->waiting($id);
        $this->db->transaction(function () use ($actor, $id, $row, $role, $expectedUpdatedAt): void {
            $n = $this->db->execute(
                "UPDATE users SET approval_status = 'approved', status = 'active', role = ?, updated_at = ? WHERE id = ? AND approval_status <> 'approved' AND updated_at = ?",
                [$role, $this->now(), $id, (string) $expectedUpdatedAt],
            );
            if ($n !== 1) {
                throw new ApiException(409, 'approval_conflict');
            }
            $this->audit->record($actor, 'user.approve', 'user', (string) $id, ['login_id' => $row['login_id'], 'role' => $role, 'from' => $row['approval_status'], 'auth_type' => $row['auth_type']]);
        });
        $this->logger->info('승인 대기 계정 승인', ['target_user_id' => $id, 'role' => $role, 'by' => $actor->label]);
        return UserRepository::toPublic((array) $this->repo->find($id), $this->now());
    }

    /** @return array<string, mixed> 거절: 대기 → 거절(계정은 남겨 같은 사람의 재요청이 쌓이지 않게) */
    public function reject(Actor $actor, int $id, mixed $expectedUpdatedAt): array
    {
        $row = $this->waiting($id);
        if ($row['approval_status'] !== 'pending') {
            throw new ApiException(409, 'approval_conflict');
        }
        $this->db->transaction(function () use ($actor, $id, $row, $expectedUpdatedAt): void {
            $n = $this->db->execute("UPDATE users SET approval_status = 'rejected', updated_at = ? WHERE id = ? AND approval_status = 'pending' AND updated_at = ?",
                [$this->now(), $id, (string) $expectedUpdatedAt]);
            if ($n !== 1) {
                throw new ApiException(409, 'approval_conflict');
            }
            $this->audit->record($actor, 'user.reject', 'user', (string) $id, ['login_id' => $row['login_id'], 'auth_type' => $row['auth_type']]);
        });
        return UserRepository::toPublic((array) $this->repo->find($id), $this->now());
    }

    /** 승인된 적 없는 계정(대기·거절) 삭제 */
    public function delete(Actor $actor, int $id, mixed $expectedUpdatedAt): void
    {
        $raw = $this->repo->findRaw($id) ?? throw new ApiException(404, 'user_not_found');
        if ($raw['approval_status'] === 'approved') {
            throw new ApiException(409, 'user_not_deletable'); // 승인된 계정은 지우지 않는다(비활성화만, 명세 13)
        }
        $row = $this->waiting($id);
        $this->db->transaction(function () use ($actor, $id, $row, $expectedUpdatedAt): void {
            $n = $this->db->execute("DELETE FROM users WHERE id = ? AND approval_status <> 'approved' AND updated_at = ?", [$id, (string) $expectedUpdatedAt]);
            if ($n !== 1) {
                throw new ApiException(409, 'approval_conflict');
            }
            $this->audit->record($actor, 'user.pending_delete', 'user', (string) $id, ['login_id' => $row['login_id'], 'from' => $row['approval_status']]);
        });
    }

    /** 보관 기간(마지막 상태 변경 updated_at 기준)이 지난 대기·거절 계정 정리(정리 작업, 매일). $dryRun이면 수만. @return int 지운(지울) 수 */
    public function purge(bool $dryRun = false): int
    {
        $cutoff = SystemClock::iso($this->clock->now()->modify('-' . $this->retentionDays . ' days'));
        if ($dryRun) {
            return (int) $this->db->value("SELECT COUNT(*) FROM users WHERE approval_status <> 'approved' AND updated_at < ?", [$cutoff]);
        }
        $n = 0;
        $this->db->transaction(function () use ($cutoff, &$n): void {
            $n = $this->db->execute("DELETE FROM users WHERE approval_status <> 'approved' AND updated_at < ?", [$cutoff]);
            if ($n > 0) {
                $this->audit->record(Actor::system(), 'user.pending_purge', null, null, ['count' => $n, 'retention_days' => $this->retentionDays]);
            }
        });
        return $n;
    }

    /** @return array<string, mixed> 대기·거절 계정 행(아니면 404·409) */
    private function waiting(int $id): array
    {
        $row = $this->repo->findRaw($id) ?? throw new ApiException(404, 'user_not_found');
        if ($row['approval_status'] === 'approved') {
            throw new ApiException(409, 'approval_conflict');
        }
        return $row;
    }
}
