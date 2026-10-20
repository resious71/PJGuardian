<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Http\ApiException;
use PJGuardian\Repositories\UserRepository;
use PJGuardian\Services\Auth\AuthProviderRegistry;
use PJGuardian\Services\Auth\AuthSettings;
use PJGuardian\Services\Auth\PasswordPolicy;
use PJGuardian\Services\Auth\SessionService;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 계정 관리(명세 3, 13, 10, 19.2).
 * - 계정은 삭제하지 않고 비활성화만 한다. 마지막 활성 관리자는 비활성화·강등 불가(서비스가 먼저 검사, DB 트리거가 한 번 더).
 * - 비활성화·역할 변경·임시 비밀번호 발급 시 해당 사용자의 세션을 즉시 폐기한다.
 * - 관리자 작업은 감사 로그에 남긴다. 비밀번호·해시는 감사 로그와 로그에 넣지 않는다.
 * - 오류는 ApiException(상태, 코드)으로 던진다. CLI도 같은 서비스를 쓰고 userMessage()를 출력한다.
 */
final class UserService
{
    private const LOGIN_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._@-]{0,99}$/';
    /** 최초 관리자의 고정 초기 비밀번호(X02). createInitialAdmin()에서만 쓴다. 로그·감사 로그·오류 메시지에 넣지 않는다 */
    private const INITIAL_ADMIN_PASSWORD = 'admin';
    private readonly UserRepository $repo;
    private readonly PasswordPolicy $policy;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuthSettings $settings,
        private readonly SessionService $sessions,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        // 켜진 인증 방식(P28A). 생성 요청의 auth_type은 이 목록에 있어야 한다 — 이 경로가 만드는 계정은 지금 local뿐
        private readonly AuthProviderRegistry $authTypes = new AuthProviderRegistry(),
    ) {
        $this->repo = new UserRepository($db);
        $this->policy = $settings->passwordPolicy();
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    /** @return array<string, mixed> */
    private function publicOf(int $id): array
    {
        $row = $this->repo->find($id) ?? throw new ApiException(404, 'user_not_found');
        return UserRepository::toPublic($row, $this->now());
    }

    /**
     * @param array{q?:?string, role?:?string, status?:?string} $filters
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(array $filters, int $limit, int $offset): array
    {
        if (($filters['role'] ?? '') !== '' && !in_array($filters['role'], ['admin', 'user'], true)) {
            throw new ApiException(400, 'invalid_role');
        }
        if (($filters['status'] ?? '') !== '' && !in_array($filters['status'], ['active', 'inactive'], true)) {
            throw new ApiException(400, 'invalid_status');
        }
        if (($filters['approval'] ?? '') !== '' && !in_array($filters['approval'], ['pending', 'rejected'], true)) {
            throw new ApiException(400, 'invalid_approval');
        }
        [$rows, $total] = $this->repo->search($filters, $limit, $offset);
        $now = $this->now();
        return [array_map(function (array $r) use ($now): array {
            $u = UserRepository::toPublic($r, $now);
            // P28B: 승인 대기 계정의 이름(외부 값)이 다른 사용자의 로그인 ID와 같으면 승인 화면에 경고 배지(승인 전에 이름을 고칠 수 있다)
            if ($u['approval_status'] !== 'approved') {
                $u['name_conflict'] = UserProfileRules::reserved($this->db, $u['display_name'], $u['id']);
            }
            return $u;
        }, $rows), $total];
    }

    /** @return array{pending:int, rejected:int} 승인 대기·거절 계정 수(사용자 관리 배지) */
    public function approvalCounts(): array
    {
        return $this->repo->approvalCounts();
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->publicOf($id);
    }

    /**
     * 로컬 계정 생성. 임시 비밀번호를 발급해 돌려준다(한 번만 보여 줌, 24시간 만료, 다음 로그인 때 변경 강제).
     * P28B: auth_type이 켜진 외부 방식(ldap)이면 비밀번호 없이 만들고 temp_password는 null.
     *
     * @param array<string, mixed> $data login_id, display_name, email?, role?, auth_type?
     * @return array{user: array<string, mixed>, temp_password: ?string}
     */
    public function create(Actor $actor, array $data, array $auditExtra = [], ?\Closure $within = null): array
    {
        // $auditExtra: user.create 감사 로그에 덧붙일 정보(그룹 관리자의 계정 생성: group_id, actor_role, 명세 3.1)
        // $within(int $newUserId): 같은 트랜잭션 안에서 이어서 할 일(그룹 구성원 추가). 실패하면 계정 생성도 함께 취소된다
        $loginId = $this->validLoginId($data['login_id'] ?? null);
        $name = $this->validDisplayName($data['display_name'] ?? null);
        $email = $this->validEmail($data['email'] ?? null);
        $role = $this->validRole($data['role'] ?? 'user');
        $authType = $data['auth_type'] ?? $this->authTypes->defaultType();
        if (!is_string($authType) || !$this->authTypes->isEnabled($authType)) {
            throw new ApiException(400, 'auth_type_not_enabled');
        }
        // P28B: 외부 인증 계정은 비밀번호·임시 비밀번호 없이 승인된 상태로(첫 로그인 때 로그인 ID로 외부 신원과 연결)
        $local = $authType === 'local';
        $temp = $local ? $this->policy->generateTemporary() : null;
        $expires = $local ? $this->tempExpiry() : null;
        $id = $this->db->transaction(function () use ($actor, $loginId, $name, $email, $role, $temp, $expires, $auditExtra, $within, $authType, $local): int {
            if ($this->repo->loginIdExists($loginId)) {
                throw new ApiException(409, 'login_id_taken');
            }
            $this->assertNotReserved($name, null);
            $id = $this->repo->insert([
                'login_id' => $loginId, 'display_name' => $name, 'email' => $email, 'role' => $role, 'auth_type' => $authType,
                'password_hash' => $local ? $this->settings->hash((string) $temp) : null, 'temp_password_expires_at' => $expires,
                'must_change_password' => $local ? 1 : 0, 'password_kind' => $local ? 'temporary' : 'user', 'created_at' => $this->now(),
            ]);
            $this->audit->record($actor, 'user.create', 'user', (string) $id, ['login_id' => $loginId, 'role' => $role, 'auth_type' => $authType] + $auditExtra);
            if ($within !== null) {
                $within($id);
            }
            return $id;
        });
        $this->logger->info('계정 생성', ['target_user_id' => $id, 'role' => $role, 'by' => $actor->label]);
        return ['user' => $this->publicOf($id), 'temp_password' => $temp];
    }

    /**
     * 최초 관리자를 고정 초기 비밀번호로 만든다(tools/create_admin.php 기본 동작, X02).
     *
     * ▶ 비밀번호 정책(명세 3장: 8자 이상, 2종 이상)의 **유일한 예외**가 여기에만 있다.
     *   사용자 확정 결정(X02 프롬프트, 명세 19.10에 반영 예정): 모든 환경(개발·운영)에서 최초 관리자의 초기 비밀번호는 고정값.
     *   - 첫 로그인 때 변경을 강제하지 않는다(must_change_password = 0, 2026-10-13 사용자 결정 — X02의 변경 강제를 없앰). 바꾸면 새 비밀번호에는 정책을 그대로 적용한다.
     *   - 임시 비밀번호의 24시간 만료는 적용하지 않는다(만료되면 고정값의 의미가 없다).
     *   - 로그인 잠금·IP 제한은 그대로 적용된다(LoginService).
     *   - password_kind = 'initial'로 표시해 하드닝 점검이 "초기 비밀번호 미변경"을 경고한다.
     *   다른 경로(사용자 생성, 비밀번호 변경, 임시 비밀번호 발급, CLI 재설정, --password-stdin)는 이 예외를 쓰지 않는다.
     * 활성 관리자가 이미 있으면 만들지 않는다(409 admin_exists).
     *
     * @return array<string, mixed> 공개 사용자 정보
     */
    public function createInitialAdmin(Actor $actor, string $loginId, string $displayName, ?string $email): array
    {
        $loginId = $this->validLoginId($loginId);
        $name = $this->validDisplayName($displayName);
        $email = $this->validEmail($email);
        $id = $this->db->transaction(function () use ($actor, $loginId, $name, $email): int {
            if ($this->repo->countActiveAdmins() > 0) {
                throw new ApiException(409, 'admin_exists');
            }
            if ($this->repo->loginIdExists($loginId)) {
                throw new ApiException(409, 'login_id_taken');
            }
            $this->assertNotReserved($name, null);
            $id = $this->repo->insert([
                'login_id' => $loginId, 'display_name' => $name, 'email' => $email, 'role' => 'admin',
                'password_hash' => $this->settings->hash(self::INITIAL_ADMIN_PASSWORD),
                'temp_password_expires_at' => null, 'must_change_password' => 0, 'password_kind' => 'initial',
                'created_at' => $this->now(),
            ]);
            // 감사 로그에는 방식만 남기고 비밀번호 값은 넣지 않는다
            $this->audit->record($actor, 'user.create', 'user', (string) $id, [
                'login_id' => $loginId, 'role' => 'admin', 'auth_type' => 'local', 'initial_admin' => true, 'auth_setup' => 'initial_fixed',
            ]);
            return $id;
        });
        return $this->publicOf($id);
    }

    /**
     * 최초 관리자(CLI, 명세 13)를 표준 입력으로 받은 비밀번호로 만든다(--password-stdin). 정책을 적용하고 변경을 강제하지 않는다.
     * $password가 없으면 무작위 임시 비밀번호를 발급한다.
     *
     * @return array{user: array<string, mixed>, temp_password: ?string}
     */
    public function createAdmin(Actor $actor, string $loginId, string $displayName, ?string $email, ?string $password): array
    {
        $loginId = $this->validLoginId($loginId);
        $name = $this->validDisplayName($displayName);
        $email = $this->validEmail($email);
        if ($password !== null) {
            $this->assertPolicy($password);
        }
        $temp = $password === null ? $this->policy->generateTemporary() : null;
        $id = $this->db->transaction(function () use ($actor, $loginId, $name, $email, $password, $temp): int {
            if ($this->repo->loginIdExists($loginId)) {
                throw new ApiException(409, 'login_id_taken');
            }
            $this->assertNotReserved($name, null);
            $id = $this->repo->insert([
                'login_id' => $loginId, 'display_name' => $name, 'email' => $email, 'role' => 'admin',
                'password_hash' => $this->settings->hash($password ?? (string) $temp),
                'temp_password_expires_at' => $temp === null ? null : $this->tempExpiry(),
                'must_change_password' => $temp === null ? 0 : 1, 'password_kind' => $temp === null ? 'user' : 'temporary',
                'created_at' => $this->now(),
            ]);
            $this->audit->record($actor, 'user.create', 'user', (string) $id, ['login_id' => $loginId, 'role' => 'admin', 'auth_type' => 'local', 'initial_admin' => true]);
            return $id;
        });
        return ['user' => $this->publicOf($id), 'temp_password' => $temp];
    }

    /**
     * 표시 이름·이메일 수정(X05, 명세 19.10 "사용자 정보 수정"). 관리자 화면(PATCH /users/{id})과 "내 정보"(PATCH /users/me)가
     * **이 함수 하나**를 쓴다(검증·권한·감사 로그를 한 곳에).
     *
     * - 권한: 행위자가 admin이거나 본인일 때만(그 밖 403 user_profile_forbidden). 그룹 관리자도 구성원은 못 고친다(명세 3.1). 대상이 없으면 404.
     * - 외부 인증(LDAP 등) 계정도 같다(P28B: 이름·이메일은 외부 값으로 갱신하지 않으므로 앱에서 고친다 — 예전 409 ldap_managed는 없음).
     * - 바꾸는 항목은 표시 이름·이메일뿐. $data의 다른 키(role, login_id, status, auth_type, password …)는 무시한다.
     *   보낸 필드만 바꾼다(없는 키는 현재 값 유지).
     * - 둘 다 현재 값과 같으면 저장·감사 로그 없이 현재 정보를 돌려준다. 동시 수정은 마지막 저장이 이긴다(낙관적 잠금 없음).
     * - 감사 로그 user.update: {actor_role, self, display_name:{from,to}, email:{from,to}(가림: a***@도메인)}.
     *
     * @param array<string, mixed> $actorUser 로그인한 사용자(공개 정보, 요청마다 DB에서 읽은 값)
     * @param array<string, mixed> $data
     * @return array<string, mixed> 대상 사용자 공개 정보
     */
    public function updateProfile(array $actorUser, ?string $ip, int $targetId, array $data): array
    {
        $isAdmin = ($actorUser['role'] ?? '') === 'admin';
        $isSelf = (int) ($actorUser['id'] ?? 0) === $targetId;
        if (!$isAdmin && !$isSelf) {
            throw new ApiException(403, 'user_profile_forbidden');
        }
        $before = $this->publicOf($targetId);
        // P28B: 외부 인증 계정도 이름·이메일을 앱에서 고친다(외부 값으로 갱신하지 않음 — X05의 ldap_managed 폐지). 승인 대기 계정도 승인 전에 고칠 수 있다
        $name = array_key_exists('display_name', $data) ? $this->profileDisplayName($data['display_name'], $targetId) : $before['display_name'];
        $email = array_key_exists('email', $data) ? $this->profileEmail($data['email']) : $before['email'];
        $changes = [];
        if ($before['display_name'] !== $name) {
            $changes['display_name'] = ['from' => $before['display_name'], 'to' => $name];
        }
        if ($before['email'] !== $email) {
            $changes['email'] = ['from' => self::maskEmail($before['email']), 'to' => self::maskEmail($email)];
        }
        if ($changes === []) {
            return $before;
        }
        $actor = Actor::fromUser($actorUser, $ip);
        $this->db->transaction(function () use ($actor, $targetId, $name, $email, $changes, $isAdmin, $isSelf): void {
            $this->repo->updateProfile($targetId, $name, $email, $this->now());
            $this->audit->record($actor, 'user.update', 'user', (string) $targetId, ['actor_role' => $isAdmin ? 'admin' : 'user', 'self' => $isSelf] + $changes);
        });
        $this->logger->info('사용자 정보 수정', ['target_user_id' => $targetId, 'fields' => array_keys($changes), 'self' => $isSelf]);
        return $this->publicOf($targetId);
    }

    /** 감사 로그용 이메일 가림: kim.dev@example.com → k***@example.com. NULL은 NULL */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        return mb_substr(substr($email, 0, $at), 0, 1) . '***' . substr($email, $at);
    }

    /** 표시 이름(수정 경로, X05 오류 코드). 규칙은 UserProfileRules */
    private function profileDisplayName(mixed $v, int $targetId): string
    {
        [$name, $reason] = UserProfileRules::displayName($v);
        if ($reason !== null) {
            $code = 'display_name_' . $reason;
            throw new ApiException(400, $code, $reason === 'too_long' ? ['max' => UserProfileRules::NAME_MAX] : []);
        }
        $this->assertNotReserved((string) $name, $targetId);
        return (string) $name;
    }

    /** 이메일(수정 경로, X05 오류 코드) */
    private function profileEmail(mixed $v): ?string
    {
        [$email, $reason] = UserProfileRules::email($v);
        if ($reason !== null) {
            $code = 'email_' . $reason;
            throw new ApiException(400, $code, $reason === 'too_long' ? ['max' => UserProfileRules::EMAIL_MAX] : []);
        }
        return $email;
    }

    /** 다른 사용자의 로그인 ID와 같은 표시 이름 거부(수정·생성 공통 코드 display_name_reserved). $selfId: 수정 대상, 생성은 null */
    private function assertNotReserved(string $name, ?int $selfId): void
    {
        if (UserProfileRules::reserved($this->db, $name, $selfId)) {
            throw new ApiException(400, 'display_name_reserved');
        }
    }

    /** @return array<string, mixed> */
    public function changeRole(Actor $actor, int $id, mixed $role): array
    {
        $role = $this->validRole($role);
        $user = $this->publicOf($id);
        self::assertApproved($user);
        if ($user['role'] === $role) {
            return $user;
        }
        $this->db->transaction(function () use ($actor, $id, $user, $role): void {
            $this->guardLastAdmin($user, $role, $user['status']);
            $this->repo->updateRole($id, $role, $this->now());
            $this->sessions->destroyForUser($id);
            $this->audit->record($actor, 'user.role_change', 'user', (string) $id, ['from' => $user['role'], 'to' => $role]);
        });
        $this->logger->info('역할 변경', ['target_user_id' => $id, 'to' => $role, 'by' => $actor->label]);
        return $this->publicOf($id);
    }

    /** 활성화/비활성화 @return array<string, mixed> */
    public function setStatus(Actor $actor, int $id, mixed $status): array
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new ApiException(400, 'invalid_status');
        }
        $user = $this->publicOf($id);
        self::assertApproved($user);
        if ($user['status'] === $status) {
            return $user;
        }
        $this->db->transaction(function () use ($actor, $id, $user, $status): void {
            $this->guardLastAdmin($user, $user['role'], $status);
            $this->repo->updateStatus($id, $status, $this->now());
            if ($status === 'inactive') {
                $this->sessions->destroyForUser($id);
            }
            $this->audit->record($actor, $status === 'inactive' ? 'user.deactivate' : 'user.activate', 'user', (string) $id, ['login_id' => $user['login_id']]);
        });
        $this->logger->info($status === 'inactive' ? '계정 비활성화' : '계정 활성화', ['target_user_id' => $id, 'by' => $actor->label]);
        return $this->publicOf($id);
    }

    /**
     * 임시 비밀번호 발급(비밀번호 초기화, 명세 13). 잠금도 풀고 세션을 폐기한다.
     *
     * @return array{temp_password: string, expires_at: string, user: array<string, mixed>}
     */
    public function issueTempPassword(Actor $actor, int $id, string $action = 'user.temp_password'): array
    {
        $user = $this->publicOf($id);
        if ($user['auth_type'] !== 'local') {
            throw new ApiException(409, 'not_local_account');
        }
        $temp = $this->policy->generateTemporary();
        $expires = $this->tempExpiry();
        $this->db->transaction(function () use ($actor, $id, $user, $temp, $expires, $action): void {
            $this->repo->setPassword($id, $this->settings->hash($temp), $expires, $this->now());
            $this->sessions->destroyForUser($id);
            $this->audit->record($actor, $action, 'user', (string) $id, ['login_id' => $user['login_id'], 'expires_at' => $expires]);
        });
        $this->logger->info('임시 비밀번호 발급', ['target_user_id' => $id, 'by' => $actor->label]);
        return ['temp_password' => $temp, 'expires_at' => $expires, 'user' => $this->publicOf($id)];
    }

    /**
     * 관리자가 다른 사용자의 비밀번호를 정한다(사용자 관리 화면, 명세 13장·19.10).
     * - 정책(명세 3장)을 그대로 적용한다. 로컬 계정만. 본인 비밀번호는 현재 비밀번호를 확인하는 /password에서 바꾼다.
     * - 변경을 강제하지 않는다. 대상은 다음 로그인 때 "관리자가 비밀번호를 변경했다"는 알림을 한 번 받는다.
     * - 대상의 세션은 즉시 폐기, 잠금·임시 비밀번호 상태는 해제. 감사 로그(user.password_set)에 값은 남기지 않는다.
     *
     * @return array<string, mixed> 공개 사용자 정보
     */
    public function setPasswordByAdmin(Actor $actor, int $id, mixed $password): array
    {
        $user = $this->publicOf($id);
        if ($actor->userId === $id) {
            throw new ApiException(400, 'self_password_change');
        }
        if ($user['auth_type'] !== 'local') {
            throw new ApiException(409, 'not_local_account');
        }
        if (!is_string($password)) {
            throw new ApiException(400, 'invalid_input');
        }
        $this->assertPolicy($password);
        $this->db->transaction(function () use ($actor, $id, $user, $password): void {
            $this->repo->setPasswordByAdmin($id, $this->settings->hash($password), $this->now());
            $this->sessions->destroyForUser($id);
            $this->audit->record($actor, 'user.password_set', 'user', (string) $id, ['login_id' => $user['login_id']]);
        });
        $this->logger->info('관리자가 비밀번호 변경', ['target_user_id' => $id, 'by' => $actor->label]);
        return $this->publicOf($id);
    }

    /** 잠금 해제(명세 19.5: 관리자가 즉시 해제) @return array<string, mixed> */
    public function unlock(Actor $actor, int $id, string $action = 'user.unlock'): array
    {
        $user = $this->publicOf($id);
        $this->db->transaction(function () use ($actor, $id, $user, $action): void {
            $this->repo->clearFailures($id);
            $this->audit->record($actor, $action, 'user', (string) $id, ['login_id' => $user['login_id'], 'was_locked' => $user['locked']]);
        });
        $this->logger->info('잠금 해제', ['target_user_id' => $id, 'by' => $actor->label]);
        return $this->publicOf($id);
    }

    /**
     * 본인 비밀번호 변경(임시 비밀번호 강제 변경 포함). 정책 적용, 같은 비밀번호 재사용 금지.
     * 현재 세션만 남기고 같은 사용자의 다른 세션은 폐기한다.
     */
    public function changeOwnPassword(int $id, string $current, string $new, ?string $currentSessionIdHash): void
    {
        $user = $this->repo->findRaw($id) ?? throw new ApiException(404, 'user_not_found');
        if ($user['auth_type'] !== 'local') {
            throw new ApiException(409, 'not_local_account');
        }
        // P31: 현재 비밀번호를 틀린 횟수는 로그인 실패와 같은 계정 잠금 규칙(auth.lockout_max_failures·lockout_duration)을 쓴다 —
        // 로그인된 브라우저를 잠깐 얻은 사람이 현재 비밀번호를 무제한 대입하지 못하게. 잠기면 그 사용자의 세션을 모두 끊는다.
        if ($user['locked_until'] !== null && (string) $user['locked_until'] > $this->now()) {
            throw new ApiException(429, 'too_many_attempts');
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            $count = (int) $user['failed_login_count'] + 1;
            if ($count >= $this->settings->lockoutMaxFailures) {
                $until = SystemClock::iso($this->clock->now()->modify('+' . $this->settings->lockoutDuration . ' seconds'));
                $this->repo->recordFailure($id, 0, $until);
                $this->sessions->destroyForUser($id);
                $this->logger->warn('계정 잠금: 비밀번호 변경에서 현재 비밀번호 반복 불일치', ['user_id' => $id, 'until' => $until]);
                throw new ApiException(429, 'too_many_attempts');
            }
            $this->repo->recordFailure($id, $count, null);
            $this->logger->warn('비밀번호 변경 실패: 현재 비밀번호 불일치', ['user_id' => $id, 'failures' => $count]);
            throw new ApiException(400, 'current_password_incorrect');
        }
        $this->repo->clearFailures($id);
        $this->assertPolicy($new);
        if (hash_equals($current, $new)) {
            throw new ApiException(400, 'password_reused');
        }
        $this->db->transaction(function () use ($id, $new, $currentSessionIdHash): void {
            $this->repo->setPassword($id, $this->settings->hash($new), null, $this->now());
            $this->sessions->destroyForUser($id, $currentSessionIdHash);
        });
        $this->logger->info('비밀번호 변경', ['user_id' => $id]);
    }

    public function findIdByLogin(string $loginId): int
    {
        $row = $this->repo->findForAuth($loginId) ?? throw new ApiException(404, 'user_not_found');
        return (int) $row['id'];
    }

    public function activeAdminCount(): int
    {
        return $this->repo->countActiveAdmins();
    }

    /** @return list<string> 초기 비밀번호를 아직 바꾸지 않은 활성 관리자(하드닝 점검) */
    public function initialPasswordPendingAdmins(): array
    {
        return $this->repo->initialPasswordPendingAdmins();
    }

    private function assertPolicy(string $password): void
    {
        $v = $this->policy->violations($password);
        if ($v !== []) {
            throw new ApiException(400, 'password_policy', [
                'min' => $this->policy->minLength(), 'classes' => $this->policy->minClasses(), 'max' => PasswordPolicy::MAX_BYTES,
            ], logContext: ['violations' => implode(',', $v)]);
        }
    }

    /** @param array<string, mixed> $user */
    private function guardLastAdmin(array $user, string $newRole, string $newStatus): void
    {
        $losesAdmin = $user['role'] === 'admin' && $user['status'] === 'active' && ($newRole !== 'admin' || $newStatus !== 'active');
        if ($losesAdmin && $this->repo->countActiveAdmins((int) $user['id']) === 0) {
            throw new ApiException(409, 'last_admin');
        }
        // P28B: 로컬(앱 비밀번호) 관리자는 늘 한 명 이상(외부 인증 관리자만 남지 않게 — 디렉터리 장애 때도 들어올 수 있게)
        if ($losesAdmin && $user['auth_type'] === 'local' && $this->repo->countActiveLocalAdmins((int) $user['id']) === 0) {
            throw new ApiException(409, 'last_admin');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertApproved(array $user): void
    {
        if (($user['approval_status'] ?? 'approved') !== 'approved') {
            throw new ApiException(409, 'account_not_approved');
        }
    }

    /**
     * 인증 방식 전환(P28B 5절, 관리자): local ↔ 켜진 외부 방식(ldap).
     *  - 외부로: 비밀번호·임시 비밀번호·잠금 정보를 지우고 external_id는 비워 둔다(첫 로그인 때 로그인 ID로 연결).
     *  - local로: 임시 비밀번호(24시간, 첫 로그인 때 변경)를 만들어 한 번만 돌려준다. external_id도 지운다.
     *  - 대상 세션 즉시 폐기, 감사 user.auth_type_change(이전 → 이후). 자기 자신·마지막 활성 로컬 관리자·승인되지 않은 계정은 거부.
     *
     * @return array{user: array<string, mixed>, temp_password: ?string, temp_password_expires_at: ?string}
     */
    public function changeAuthType(Actor $actor, int $id, mixed $target): array
    {
        if (!is_string($target) || !($target === 'local' || ($this->authTypes->isEnabled($target) && $target !== 'local'))) {
            throw new ApiException(400, 'auth_type_not_enabled');
        }
        if (!\PJGuardian\Support\SchemaFeatures::externalAuth($this->db)) {
            throw new ApiException(409, 'auth_type_not_enabled'); // 마이그레이션 026 전
        }
        if ($actor->userId === $id) {
            throw new ApiException(400, 'self_auth_type_change');
        }
        $user = $this->publicOf($id);
        self::assertApproved($user);
        $from = $user['auth_type'];
        if ($from === $target) {
            return ['user' => $user, 'temp_password' => null, 'temp_password_expires_at' => null];
        }
        if ($from === 'local' && $user['role'] === 'admin' && $user['status'] === 'active' && $this->repo->countActiveLocalAdmins($id) === 0) {
            throw new ApiException(409, 'last_admin');
        }
        $temp = $target === 'local' ? $this->policy->generateTemporary() : null;
        $expires = $temp === null ? null : $this->tempExpiry();
        $hash = $temp === null ? null : $this->settings->hash($temp);
        $this->db->transaction(function () use ($actor, $id, $user, $from, $target, $hash, $expires): void {
            $this->db->execute(
                'UPDATE users SET auth_type = ?, password_hash = ?, temp_password_expires_at = ?, must_change_password = ?, password_kind = ?,'
                . ' failed_login_count = 0, locked_until = NULL, admin_password_set_at = NULL, external_id = NULL, external_issuer = NULL, updated_at = ? WHERE id = ?',
                [$target, $hash, $expires, $hash === null ? 0 : 1, $hash === null ? 'user' : 'temporary', $this->now(), $id],
            );
            $this->sessions->destroyForUser($id);
            $this->audit->record($actor, 'user.auth_type_change', 'user', (string) $id, ['login_id' => $user['login_id'], 'from' => $from, 'to' => $target]);
        });
        $this->logger->info('인증 방식 전환', ['target_user_id' => $id, 'from' => $from, 'to' => $target, 'by' => $actor->label]);
        return ['user' => $this->publicOf($id), 'temp_password' => $temp, 'temp_password_expires_at' => $expires];
    }

    private function tempExpiry(): string
    {
        return SystemClock::iso($this->clock->now()->modify('+' . $this->settings->tempPasswordExpiry . ' seconds'));
    }

    private function validLoginId(mixed $v): string
    {
        if (!is_string($v) || preg_match(self::LOGIN_ID_RE, $v) !== 1) {
            throw new ApiException(400, 'invalid_login_id');
        }
        return $v;
    }

    /** 표시 이름(생성 경로, X06): 규칙은 수정과 같은 UserProfileRules, 오류 코드는 P10 계약(invalid_display_name) 유지 */
    private function validDisplayName(mixed $v): string
    {
        [$name, $reason] = UserProfileRules::displayName($v);
        if ($reason !== null) {
            throw new ApiException(400, 'invalid_display_name');
        }
        return (string) $name;
    }

    /** 이메일(생성 경로, X06): 규칙은 UserProfileRules, 오류 코드는 P10 계약(invalid_email) 유지 */
    private function validEmail(mixed $v): ?string
    {
        [$email, $reason] = UserProfileRules::email($v);
        if ($reason !== null) {
            throw new ApiException(400, 'invalid_email');
        }
        return $email;
    }

    private function validRole(mixed $v): string
    {
        if (!in_array($v, ['admin', 'user'], true)) {
            throw new ApiException(400, 'invalid_role');
        }
        return $v;
    }
}
