<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Auth\Ldap\DirectoryEntry;
use PJGuardian\Services\Auth\Ldap\DirectoryException;
use PJGuardian\Services\Auth\Ldap\ExtLdapTransport;
use PJGuardian\Services\Auth\Ldap\LdapFilter;
use PJGuardian\Services\Auth\Ldap\LdapSettings;
use PJGuardian\Services\Auth\Ldap\LdapTransport;
use PJGuardian\Services\Auth\Ldap\PhpLdapClient;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Tests\TestCase;

/**
 * LDAP 클라이언트 규칙(P28B, 실제 서버 없이 가짜 전송으로): 서버 넘김(연결 실패만, 자격 증명 오류는 넘어가지 않음), TLS(ldaps·StartTLS, 평문 거부,
 * CA 파일·시간 상한 전달), 필터 이스케이프(주입 시도), 정확히 1건, 빈 비밀번호는 바인드하지 않음, 식별자·비활성 값, 가림, 설정 검사.
 */
final class LdapClientTest extends TestCase
{
    private static function settings(array $over = []): LdapSettings
    {
        $d = ['enabled' => true, 'servers' => ['ldaps://dc1.test', 'ldaps://dc2.test'], 'startTls' => false, 'caFile' => 'C:/ca/corp.pem', 'baseDn' => 'DC=test',
            'userFilter' => '(&(objectClass=user)(sAMAccountName=%s))', 'bindDn' => 'CN=svc,DC=test', 'attrLogin' => 'sAMAccountName', 'attrName' => 'displayName',
            'attrEmail' => 'mail', 'attrId' => 'objectGUID', 'attrDisabled' => 'userAccountControl', 'connectTimeout' => 5, 'searchTimeout' => 10] + [];
        $v = $over + $d;
        return new LdapSettings($v['enabled'], $v['servers'], $v['startTls'], $v['caFile'], $v['baseDn'], $v['userFilter'], $v['bindDn'], $v['attrLogin'],
            $v['attrName'], $v['attrEmail'], $v['attrId'], $v['attrDisabled'], $v['connectTimeout'], $v['searchTimeout']);
    }

    /** 가짜 전송: down = 연결 실패 서버, tlsFail = StartTLS 실패 서버, servicePw = 검색 계정 비밀번호, users = DN → 비밀번호, rows = 검색 결과 */
    private static function transport(array $o = []): LdapTransport
    {
        return new class ($o) implements LdapTransport {
            /** @var list<string> */
            public array $calls = [];
            public array $opened = [];
            private int $errno = 0;

            public function __construct(private array $o)
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function open(string $url, string $caFile, int $connectTimeout, int $searchTimeout): mixed
            {
                $this->calls[] = "open {$url} ca={$caFile} ct={$connectTimeout} st={$searchTimeout}";
                $this->opened[] = $url;
                return ['url' => $url];
            }

            public function startTls(mixed $link): bool
            {
                $this->calls[] = 'starttls ' . $link['url'];
                return !in_array($link['url'], $this->o['tlsFail'] ?? [], true);
            }

            public function bind(mixed $link, string $dn, string $password): bool
            {
                $this->calls[] = 'bind ' . $dn;
                if (in_array($link['url'], $this->o['down'] ?? [], true)) {
                    $this->errno = -1;
                    return false;
                }
                $ok = $dn === 'CN=svc,DC=test' ? $password === ($this->o['servicePw'] ?? 'svc-pw') : ($this->o['users'][$dn] ?? null) === $password;
                $this->errno = $ok ? 0 : 49;
                return $ok;
            }

            public function errno(mixed $link): int
            {
                return $this->errno;
            }

            public function search(mixed $link, string $baseDn, string $filter, array $attributes, int $sizeLimit, int $timeLimit, bool $baseOnly = false): ?array
            {
                $this->calls[] = "search {$filter} size={$sizeLimit} time={$timeLimit}";
                return $this->o['rows'] ?? [];
            }

            public function close(mixed $link): void
            {
                $this->calls[] = 'close ' . $link['url'];
            }
        };
    }

    private static function row(string $login = 'kim', string $guid = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10", string $uac = '512'): array
    {
        return ['dn' => "CN={$login},OU=Users,DC=test", 'attrs' => ['samaccountname' => [$login], 'displayname' => ['Kim'], 'mail' => ['kim@corp.test'], 'objectguid' => [$guid], 'useraccountcontrol' => [$uac]]];
    }

    public function testFailoverOnlyOnConnectionErrors(): void
    {
        // 첫 서버가 응답하지 않으면 둘째 서버로
        $t = self::transport(['down' => ['ldaps://dc1.test'], 'rows' => [self::row()], 'users' => ['CN=kim,OU=Users,DC=test' => 'pw']]);
        $c = new PhpLdapClient(self::settings(), $t, static fn (): string => 'svc-pw');
        $e = $c->search('kim');
        $this->assertSame(['ldaps://dc1.test', 'ldaps://dc2.test'], $t->opened);
        $this->assertSame(['kim', '04030201-0605-0807-090a-0b0c0d0e0f10', 'Kim', 'kim@corp.test', false], [$e->login, $e->id, $e->name, $e->email, $e->disabled], 'AD objectGUID → 표준 GUID 표기');
        $this->assertTrue($c->bind($e->dn, 'pw'));
        $this->assertFalse($c->bind($e->dn, 'wrong'), '틀린 비밀번호 = false(예외 아님)');
        // 검색 계정 자격 증명 오류(49)는 다음 서버로 넘어가지 않는다
        $t2 = self::transport(['servicePw' => 'other']);
        $c2 = new PhpLdapClient(self::settings(), $t2, static fn (): string => 'svc-pw');
        $ex = $this->assertThrows(DirectoryException::class, fn () => $c2->search('kim'));
        $this->assertSame(['service_bind_failed', ['ldaps://dc1.test']], [$ex->reason, $t2->opened]);
        // 모든 서버 연결 실패 = unavailable
        $t3 = self::transport(['down' => ['ldaps://dc1.test', 'ldaps://dc2.test']]);
        $ex = $this->assertThrows(DirectoryException::class, fn () => (new PhpLdapClient(self::settings(), $t3, static fn (): string => 'svc-pw'))->search('kim'));
        $this->assertSame('unavailable', $ex->reason);
        $this->assertFalse(str_contains($ex->getMessage(), 'svc') || str_contains($ex->getMessage(), 'DC='), '예외 메시지에 DN·비밀번호 없음');
    }

    public function testTlsOptionsAndPlainConnectionsRefused(): void
    {
        $t = self::transport(['rows' => [self::row()]]);
        (new PhpLdapClient(self::settings(['servers' => ['ldaps://dc1.test']]), $t, static fn (): string => 'svc-pw'))->search('kim');
        $this->assertSame('open ldaps://dc1.test ca=C:/ca/corp.pem ct=5 st=10', $t->calls[0], 'CA 파일·연결·검색 시간 상한 전달');
        $this->assertTrue(in_array('search (&(objectClass=user)(sAMAccountName=kim)) size=2 time=10', $t->calls, true), '최대 2건만 받아 2건 이상을 가려냄');
        // StartTLS: ldap:// + StartTLS 실패 서버는 건너뛰고 다음으로
        $t2 = self::transport(['tlsFail' => ['ldap://dc1.test'], 'rows' => [self::row()]]);
        (new PhpLdapClient(self::settings(['servers' => ['ldap://dc1.test', 'ldap://dc2.test'], 'startTls' => true]), $t2, static fn (): string => 'svc-pw'))->search('kim');
        $this->assertSame(['starttls ldap://dc1.test', 'close ldap://dc1.test'], array_values(array_filter($t2->calls, static fn (string $c): bool => str_contains($c, 'dc1') && !str_starts_with($c, 'open'))));
        // StartTLS 없이 ldap://(평문)는 쓰지 않는다
        $t3 = self::transport();
        $ex = $this->assertThrows(DirectoryException::class, fn () => (new PhpLdapClient(self::settings(['servers' => ['ldap://dc1.test']]), $t3, static fn (): string => 'svc-pw'))->search('kim'));
        $this->assertSame('unavailable', $ex->reason);
        $this->assertFalse(in_array('bind CN=svc,DC=test', $t3->calls, true), '평문 연결로 바인드하지 않음');
        // ExtLdapTransport는 TLS 검증을 끄는 옵션을 받지 않는다(코드에 DEMAND만)
        $src = (string) file_get_contents(PJG_ROOT . '/src/Services/Auth/Ldap/ExtLdapTransport.php');
        $this->assertFalse(str_contains($src, 'X_TLS_NEVER') || str_contains($src, 'X_TLS_ALLOW') || str_contains($src, 'X_TLS_TRY'), '검증 끄기 값 없음');
        $this->assertTrue(substr_count($src, 'LDAP_OPT_X_TLS_DEMAND') >= 2);
    }

    public function testFilterEscapingAmbiguityAndEmptyPassword(): void
    {
        $t = self::transport(['rows' => []]);
        $c = new PhpLdapClient(self::settings(['servers' => ['ldaps://dc1.test']]), $t, static fn (): string => 'svc-pw');
        $this->assertSame(null, $c->search('*)(uid=*'));
        $this->assertTrue(in_array('search (&(objectClass=user)(sAMAccountName=\2a\29\28uid=\2a)) size=2 time=10', $t->calls, true), '필터 주입 이스케이프: ' . implode(' | ', $t->calls));
        $this->assertSame('a\5cb\00', LdapFilter::escape("a\\b\0"));
        $this->assertSame('(uid=x)', LdapFilter::build('(uid=%s)', 'x'));
        $this->assertThrows(DirectoryException::class, fn () => LdapFilter::build('(uid=%s)(cn=%s)', 'x'));
        // 2건 이상 = ambiguous
        $t2 = self::transport(['rows' => [self::row('kim'), self::row('kim')]]);
        $ex = $this->assertThrows(DirectoryException::class, fn () => (new PhpLdapClient(self::settings(), $t2, static fn (): string => 'svc-pw'))->search('kim'));
        $this->assertSame('ambiguous', $ex->reason);
        // 빈 비밀번호·너무 긴 비밀번호는 바인드를 부르지 않는다(익명 바인드 성공 취약점)
        $t3 = self::transport();
        $c3 = new PhpLdapClient(self::settings(), $t3, static fn (): string => 'svc-pw');
        $this->assertFalse($c3->bind('CN=kim,OU=Users,DC=test', ''));
        $this->assertFalse($c3->bind('CN=kim,OU=Users,DC=test', str_repeat('a', 257)));
        $this->assertSame([], $t3->calls, '전송 호출 0');
        // 매핑 속성이 없으면 로그인 불가(검색 실패)
        $t4 = self::transport(['rows' => [['dn' => 'CN=x', 'attrs' => ['samaccountname' => ['x']]]]]);
        $this->assertSame('search_failed', $this->assertThrows(DirectoryException::class, fn () => (new PhpLdapClient(self::settings(), $t4, static fn (): string => 'svc-pw'))->search('x'))->reason);
        // 설정·확장 없음
        $this->assertSame('not_configured', $this->assertThrows(DirectoryException::class, fn () => (new PhpLdapClient(self::settings(['baseDn' => '']), self::transport(), static fn (): string => 'svc-pw'))->search('x'))->reason);
    }

    public function testIdentifierDisabledAndMasking(): void
    {
        $this->assertSame('0d1a3f8b-2c4e-1d4f-9a8b-7c6d5e4f3a2b', DirectoryEntry::normalizeId('0D1A3F8B-2C4E-1D4F-9A8B-7C6D5E4F3A2B'), 'entryUUID는 소문자 그대로');
        $this->assertTrue(DirectoryEntry::isDisabledValue('514'), 'AD 514 = 512 + ACCOUNTDISABLE(2)');
        $this->assertFalse(DirectoryEntry::isDisabledValue('512'));
        $this->assertTrue(DirectoryEntry::isDisabledValue('TRUE'));
        $this->assertFalse(DirectoryEntry::isDisabledValue(null));
        $t = self::transport(['rows' => [self::row('kim', 'guid-string-xyz', '514')]]);
        $this->assertTrue((new PhpLdapClient(self::settings(), $t, static fn (): string => 'svc-pw'))->search('kim')->disabled);
        $this->assertSame('CN=ki***,OU=Users,DC=test', PhpLdapClient::maskDn('CN=kim.dev,OU=Users,DC=test'));
        $this->assertSame('ki***', PhpLdapClient::mask('kim@corp.test'));
        // 연결 시험: 단계별, 비밀번호·바인드 DN 원문 없음
        $steps = (new PhpLdapClient(self::settings(), self::transport(['rows' => [self::row()]]), static fn (): string => 'svc-pw'))->probe('kim');
        $this->assertSame(['connect', 'service_bind', 'base_dn', 'user_search'], array_column($steps, 'step'));
        $this->assertSame([true, true, true, true], array_column($steps, 'ok'));
        $json = (string) json_encode($steps);
        $this->assertFalse(str_contains($json, 'svc-pw') || str_contains($json, 'CN=svc'), '연결 시험 결과에 비밀번호·바인드 DN 없음');
        $this->assertSame('CN=ki***,OU=Users,DC=test', $steps[3]['detail']['dn']);
        $bad = (new PhpLdapClient(self::settings(), self::transport(['servicePw' => 'x']), static fn (): string => 'svc-pw'))->probe(null);
        $this->assertSame([['service_bind', false, 'service_bind_failed'], ['base_dn', null, 'skipped']], array_map(static fn (array $s): array => [$s['step'], $s['ok'], $s['code']], array_slice($bad, 1, 2)));
    }

    public function testSettingsValidation(): void
    {
        $base = ['enabled' => true, 'servers' => 'ldaps://dc1.test', 'use_starttls' => false, 'ca_file' => '', 'base_dn' => 'DC=test',
            'user_filter' => '(&(objectClass=user)(sAMAccountName=%s))', 'bind_dn' => 'CN=svc,DC=test', 'attr_login' => 'sAMAccountName', 'attr_name' => 'displayName',
            'attr_email' => 'mail', 'attr_id' => 'objectGUID', 'attr_disabled' => '', 'server_url' => null, 'ca_cert_file' => null];
        $this->assertSame([], ConfigSchema::ldapErrors($base));
        $err = static fn (array $over): string => implode(' | ', ConfigSchema::ldapErrors($over + $base));
        $this->assertContains('user_filter', $err(['user_filter' => '(uid=x)']), '%s 0개');
        $this->assertContains('user_filter', $err(['user_filter' => '(|(uid=%s)(cn=%s))']), '%s 2개');
        $this->assertContains('user_filter', $err(['user_filter' => '(uid=%s']), '괄호 짝');
        $this->assertContains('3개까지', $err(['servers' => 'ldaps://a.t,ldaps://b.t,ldaps://c.t,ldaps://d.t']));
        $this->assertContains('use_starttls', $err(['servers' => 'ldap://a.t']), '평문 ldap://');
        $this->assertSame('', $err(['servers' => 'ldap://a.t', 'use_starttls' => true]));
        $this->assertContains('ldaps://', $err(['servers' => 'http://a.t']));
        $this->assertContains('servers와 base_dn', $err(['servers' => '']));
        $this->assertSame('', $err(['servers' => '', 'enabled' => false]), '꺼져 있으면 비어도 됨');
        $this->assertContains('ca_file', $err(['ca_file' => 'certs/ca.pem']), '상대 경로 거부');
        $this->assertSame('', $err(['ca_file' => 'C:\\certs\\ca.pem']));
        $this->assertContains('attr_id', $err(['attr_id' => 'bad name']));
        $this->assertSame(['ldaps://old.t'], ConfigSchema::ldapServers(['servers' => '', 'server_url' => 'ldaps://old.t']), '예전 server_url');
    }

    public function testRealExtensionConnectionFailurePath(): void
    {
        if (!extension_loaded('ldap')) {
            $this->assertTrue(true); // 확장이 없는 환경: 실제 클래스 시험은 건너뜀(가짜 전송으로 위에서 확인)
            return;
        }
        // 닫힌 포트의 로컬 주소 — 외부 서버에 연결하지 않는다
        $c = new PhpLdapClient(self::settings(['servers' => ['ldaps://127.0.0.1:1'], 'connectTimeout' => 2, 'caFile' => '']), new ExtLdapTransport(), static fn (): string => 'svc-pw');
        $t = microtime(true);
        $ex = $this->assertThrows(DirectoryException::class, fn () => $c->search('kim'));
        $this->assertSame('unavailable', $ex->reason);
        $this->assertTrue(microtime(true) - $t < 10, '연결 시간 상한 안에');
    }
}
