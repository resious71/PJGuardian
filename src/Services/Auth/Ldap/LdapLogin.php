<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

use PJGuardian\Services\Auth\ExternalAccountProvisioner;
use PJGuardian\Support\Logger;

/**
 * LDAP/AD 로그인 판정(P28B). LoginService가 "계정이 있고 auth_type=ldap" 또는 "계정이 없고 LDAP이 켜짐"일 때 부른다.
 *  1. 빈 비밀번호·256바이트 초과는 디렉터리에 묻지 않고 실패(익명 바인드 성공 취약점 방지)
 *  2. 검색 계정 바인드 → 로그인 ID 검색(이스케이프, 정확히 1건) → 비활성(AD userAccountControl) 거부 → 찾은 DN으로 바인드
 *  3. 성공하면 ExternalAccountProvisioner(승인 대기 생성·연결·식별자 확인)
 * 결과 code: ok | pending | rejected | limit | failed(reason) | unavailable(디렉터리에 닿지 않음 — "인증 서비스에 문제")
 */
final class LdapLogin
{
    /** @param \Closure(): DirectoryClient $client 요청마다 새 클라이언트(연결을 요청 안에서만 씀) */
    public function __construct(
        private readonly LdapSettings $settings,
        private readonly \Closure $client,
        private readonly ExternalAccountProvisioner $provisioner,
        private readonly Logger $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    /**
     * @param array<string, mixed>|null $user 입력한 로그인 ID로 찾은 계정(auth_type=ldap) 또는 null
     * @return array{code:string, user?:array<string,mixed>, reason?:string}
     */
    public function authenticate(string $loginId, string $password, ?array $user, ?string $ip): array
    {
        if ($password === '' || strlen($password) > PhpLdapClient::PASSWORD_MAX_BYTES) {
            return ['code' => 'failed', 'reason' => 'bad_password'];
        }
        $client = ($this->client)();
        try {
            $entry = $client->search($loginId);
        } catch (DirectoryException $e) {
            if ($e->reason === 'ambiguous') {
                $this->provisioner->failedAudit($loginId, 'ambiguous', $ip, 'ldap');
                return ['code' => 'failed', 'reason' => 'ambiguous'];
            }
            $this->logger->warn('LDAP 인증 서비스 오류', ['reason' => $e->reason]);
            return ['code' => 'unavailable', 'reason' => $e->reason];
        }
        if ($entry === null) {
            return ['code' => 'failed', 'reason' => 'unknown_user'];
        }
        try {
            $ok = $client->bind($entry->dn, $password);
        } catch (DirectoryException $e) {
            $this->logger->warn('LDAP 인증 서비스 오류', ['reason' => $e->reason]);
            return ['code' => 'unavailable', 'reason' => $e->reason];
        }
        if (!$ok) {
            return ['code' => 'failed', 'reason' => 'bad_password'];
        }
        // 비활성은 비밀번호 확인 뒤에 판정(비밀번호를 모르는 사람에게 계정 상태가 드러나지 않게)
        if ($entry->disabled) {
            $this->provisioner->failedAudit($entry->login, 'directory_disabled', $ip, 'ldap');
            return ['code' => 'failed', 'reason' => 'directory_disabled'];
        }
        return $this->provisioner->onAuthenticated('ldap', '', ['login' => $entry->login, 'id' => $entry->id, 'name' => $entry->name, 'email' => $entry->email], $user, $ip);
    }
}
