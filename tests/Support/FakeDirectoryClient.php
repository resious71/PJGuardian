<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Services\Auth\Ldap\DirectoryClient;
use PJGuardian\Services\Auth\Ldap\DirectoryEntry;
use PJGuardian\Services\Auth\Ldap\DirectoryException;
use PJGuardian\Services\Auth\Ldap\LdapFilter;

/**
 * 가짜 디렉터리(P28B 시험·격리 점검 전용 — 실제 LDAP/AD 서버에 연결하지 않는다).
 * users: 로그인 ID(디렉터리 값) → {password, id, name?, email?, disabled?, dup?(같은 ID로 2건)}.
 * 모든 호출을 $log에 남긴다(검색 필터 원문은 이스케이프 확인용 — 시험 밖으로 출력하지 않는다).
 */
final class FakeDirectoryClient implements DirectoryClient
{
    /** @var list<array{op:string, arg:string}> */
    public static array $log = [];

    /** @param array<string, array<string, mixed>> $users */
    public function __construct(
        public array $users = [],
        public string $template = '(&(objectClass=user)(sAMAccountName=%s))',
        /** 이 이유로 검색·바인드가 실패(unavailable 등). null이면 정상 */
        public ?string $fail = null,
    ) {
    }

    /** JSON 파일(격리 점검 라우터용)에서 만든다 */
    public static function fromFile(string $file): self
    {
        $d = json_decode((string) @file_get_contents($file), true);
        return new self(is_array($d['users'] ?? null) ? $d['users'] : [], (string) ($d['template'] ?? '(&(objectClass=user)(sAMAccountName=%s))'), $d['fail'] ?? null);
    }

    public function search(string $loginId): ?DirectoryEntry
    {
        $filter = LdapFilter::build($this->template, $loginId);
        self::$log[] = ['op' => 'search', 'arg' => $filter];
        if ($this->fail !== null) {
            throw new DirectoryException($this->fail);
        }
        foreach ($this->users as $login => $u) {
            if (strtolower((string) $login) === strtolower($loginId)) {
                if (!empty($u['dup'])) {
                    throw new DirectoryException('ambiguous');
                }
                return new DirectoryEntry('CN=' . $login . ',OU=Users,DC=test', (string) $login, (string) $u['id'], $u['name'] ?? null, $u['email'] ?? null, (bool) ($u['disabled'] ?? false));
            }
        }
        return null;
    }

    public function bind(string $dn, string $password): bool
    {
        self::$log[] = ['op' => 'bind', 'arg' => $dn];
        if ($password === '') {
            throw new \LogicException('빈 비밀번호로 바인드를 부르면 안 된다');
        }
        if ($this->fail !== null) {
            throw new DirectoryException($this->fail);
        }
        foreach ($this->users as $login => $u) {
            if ($dn === 'CN=' . $login . ',OU=Users,DC=test') {
                return hash_equals((string) $u['password'], $password);
            }
        }
        return false;
    }

    public function probe(?string $loginId): array
    {
        self::$log[] = ['op' => 'probe', 'arg' => (string) $loginId];
        if ($this->fail !== null) {
            return [['step' => 'connect', 'ok' => false, 'code' => $this->fail, 'detail' => []],
                ['step' => 'service_bind', 'ok' => null, 'code' => 'skipped', 'detail' => []], ['step' => 'base_dn', 'ok' => null, 'code' => 'skipped', 'detail' => []],
                ['step' => 'user_search', 'ok' => null, 'code' => 'skipped', 'detail' => []]];
        }
        $steps = [['step' => 'connect', 'ok' => true, 'code' => null, 'detail' => ['server' => 'ldaps://fake.test', 'tls' => 'ldaps']],
            ['step' => 'service_bind', 'ok' => true, 'code' => null, 'detail' => []], ['step' => 'base_dn', 'ok' => true, 'code' => null, 'detail' => []]];
        if ($loginId === null || $loginId === '') {
            $steps[] = ['step' => 'user_search', 'ok' => null, 'code' => 'skipped', 'detail' => []];
            return $steps;
        }
        $e = $this->search($loginId);
        $steps[] = $e === null ? ['step' => 'user_search', 'ok' => false, 'code' => 'not_found', 'detail' => []]
            : ['step' => 'user_search', 'ok' => true, 'code' => null, 'detail' => ['dn' => \PJGuardian\Services\Auth\Ldap\PhpLdapClient::maskDn($e->dn), 'login' => $e->login,
                'id' => \PJGuardian\Services\Auth\Ldap\PhpLdapClient::mask($e->id), 'name' => $e->name, 'email' => $e->email === null ? null : \PJGuardian\Services\Auth\Ldap\PhpLdapClient::mask($e->email), 'disabled' => $e->disabled]];
        return $steps;
    }
}
