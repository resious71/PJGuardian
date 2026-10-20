<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/**
 * LDAP/AD 클라이언트(P28B).
 *  1. 서버 목록(1~3개)을 앞에서부터: 연결(+StartTLS) 실패만 다음 서버로 넘어간다. 검색 계정 바인드의 자격 증명 오류(49)는 넘어가지 않는다.
 *  2. 검색 계정으로 바인드 → 사용자 검색(필터 값 이스케이프, 최대 2건만 받아 2건 이상이면 ambiguous) → 찾은 DN + 입력 비밀번호로 바인드.
 *  3. 빈 비밀번호·256바이트를 넘는 비밀번호는 바인드를 부르지 않고 거부(익명 바인드 성공 취약점 방지).
 * 예외 메시지·로그에 DN·필터·비밀번호를 넣지 않는다.
 */
final class PhpLdapClient implements DirectoryClient
{
    public const PASSWORD_MAX_BYTES = 256;
    private const INVALID_CREDENTIALS = 49;

    private mixed $link = null;

    /** @param \Closure(): string $bindPassword 검색 계정 비밀번호(필요할 때만 복호화 — 실패하면 DirectoryException(secret_unreadable)) */
    public function __construct(
        private readonly LdapSettings $settings,
        private readonly LdapTransport $transport,
        private readonly \Closure $bindPassword,
    ) {
    }

    public function search(string $loginId): ?DirectoryEntry
    {
        $this->serviceConnect();
        $filter = LdapFilter::build($this->settings->userFilter, $loginId);
        $rows = $this->transport->search($this->link, $this->settings->baseDn, $filter, $this->settings->attributes(), 2, $this->settings->searchTimeout);
        if ($rows === null) {
            throw new DirectoryException('search_failed');
        }
        if (count($rows) > 1) {
            throw new DirectoryException('ambiguous');
        }
        return $rows === [] ? null : $this->entry($rows[0]);
    }

    public function bind(string $dn, string $password): bool
    {
        if ($password === '' || strlen($password) > self::PASSWORD_MAX_BYTES || $dn === '') {
            return false;
        }
        if ($this->link === null) {
            $this->connect();
        }
        if ($this->transport->bind($this->link, $dn, $password)) {
            return true;
        }
        if ($this->transport->errno($this->link) === self::INVALID_CREDENTIALS) {
            return false;
        }
        throw new DirectoryException('unavailable');
    }

    public function probe(?string $loginId): array
    {
        $steps = [];
        $skip = static fn (string $step): array => ['step' => $step, 'ok' => null, 'code' => 'skipped', 'detail' => []];
        // 1. 연결(TLS 포함)
        try {
            $server = $this->connect();
            $steps[] = ['step' => 'connect', 'ok' => true, 'code' => null, 'detail' => ['server' => $server, 'tls' => str_starts_with($server, 'ldaps://') ? 'ldaps' : 'starttls']];
        } catch (DirectoryException $e) {
            return [['step' => 'connect', 'ok' => false, 'code' => $e->reason, 'detail' => []], $skip('service_bind'), $skip('base_dn'), $skip('user_search')];
        }
        // 2. 검색 계정 바인드
        try {
            $this->serviceBind();
            $steps[] = ['step' => 'service_bind', 'ok' => true, 'code' => null, 'detail' => []];
        } catch (DirectoryException $e) {
            return [...$steps, ['step' => 'service_bind', 'ok' => false, 'code' => $e->reason, 'detail' => []], $skip('base_dn'), $skip('user_search')];
        }
        // 3. Base DN 읽기
        $base = $this->transport->search($this->link, $this->settings->baseDn, '(objectClass=*)', ['objectClass'], 1, $this->settings->searchTimeout, true);
        if ($base === null || $base === []) {
            return [...$steps, ['step' => 'base_dn', 'ok' => false, 'code' => 'search_failed', 'detail' => []], $skip('user_search')];
        }
        $steps[] = ['step' => 'base_dn', 'ok' => true, 'code' => null, 'detail' => []];
        // 4. 시험용 로그인 ID 검색(선택)
        if ($loginId === null || $loginId === '') {
            $steps[] = $skip('user_search');
            return $steps;
        }
        try {
            $e = $this->search($loginId);
            $steps[] = $e === null
                ? ['step' => 'user_search', 'ok' => false, 'code' => 'not_found', 'detail' => []]
                : ['step' => 'user_search', 'ok' => true, 'code' => null, 'detail' => [
                    'dn' => self::maskDn($e->dn), 'login' => $e->login, 'id' => self::mask($e->id), 'name' => $e->name, 'email' => $e->email === null ? null : self::mask($e->email),
                    'disabled' => $e->disabled]];
        } catch (DirectoryException $ex) {
            $steps[] = ['step' => 'user_search', 'ok' => false, 'code' => $ex->reason, 'detail' => []];
        }
        return $steps;
    }

    /** 연결 + 검색 계정 바인드 */
    private function serviceConnect(): void
    {
        if ($this->link === null) {
            $this->connect();
        }
        $this->serviceBind();
    }

    private function serviceBind(): void
    {
        $password = ($this->bindPassword)();
        if ($this->settings->bindDn === '' || $password === '') {
            throw new DirectoryException('not_configured');
        }
        if (!$this->transport->bind($this->link, $this->settings->bindDn, $password)) {
            throw new DirectoryException($this->transport->errno($this->link) === self::INVALID_CREDENTIALS ? 'service_bind_failed' : 'unavailable');
        }
    }

    /**
     * 서버를 차례로 연결(익명 상태). 연결·TLS가 되는 첫 서버를 쓴다 — 서버가 실제로 응답하는지는 첫 바인드에서 드러나므로
     * 여기서 검색 계정 바인드까지 해 보고, 연결 오류(서버 없음 등)면 다음 서버, 자격 증명 오류면 그대로 멈춘다.
     *
     * @return string 연결한 서버 주소
     */
    private function connect(): string
    {
        if (!$this->transport->available()) {
            throw new DirectoryException('extension_missing');
        }
        if (!$this->settings->complete()) {
            throw new DirectoryException('not_configured');
        }
        foreach ($this->settings->servers as $url) {
            $link = $this->transport->open($url, $this->settings->caFile, $this->settings->connectTimeout, $this->settings->searchTimeout);
            if ($link === null) {
                continue;
            }
            if ($this->settings->startTls && str_starts_with($url, 'ldap://') && !$this->transport->startTls($link)) {
                $this->transport->close($link);
                continue;
            }
            if (!str_starts_with($url, 'ldaps://') && !$this->settings->startTls) {
                $this->transport->close($link); // 평문 연결은 쓰지 않는다(설정 검사가 먼저 막지만 한 번 더)
                continue;
            }
            // 서버가 응답하는지: 익명 상태 확인 대신 검색 계정 바인드로 본다(연결 오류면 다음 서버)
            $password = ($this->bindPassword)();
            if ($this->settings->bindDn !== '' && $password !== '' && !$this->transport->bind($link, $this->settings->bindDn, $password)) {
                $errno = $this->transport->errno($link);
                if ($errno === self::INVALID_CREDENTIALS) {
                    $this->link = $link;
                    return $url; // 자격 증명 문제 — 다음 서버로 넘어가지 않고 serviceBind가 service_bind_failed로 보고
                }
                $this->transport->close($link);
                continue;
            }
            $this->link = $link;
            return $url;
        }
        throw new DirectoryException('unavailable');
    }

    /** @param array{dn:string, attrs:array<string, list<string>>} $row */
    private function entry(array $row): DirectoryEntry
    {
        $s = $this->settings;
        $first = static fn (string $attr): ?string => $attr === '' ? null : ($row['attrs'][strtolower($attr)][0] ?? null);
        $login = (string) ($first($s->attrLogin) ?? '');
        $id = $first($s->attrId);
        if ($login === '' || $id === null || $id === '') {
            throw new DirectoryException('search_failed'); // 매핑 속성이 없음 — 로그인 불가
        }
        return new DirectoryEntry($row['dn'], $login, DirectoryEntry::normalizeId($id), $first($s->attrName), $first($s->attrEmail),
            DirectoryEntry::isDisabledValue($first($s->attrDisabled)));
    }

    /** 연결 시험 표시용 가림: 앞 2글자 + *** */
    public static function mask(string $v): string
    {
        return mb_strlen($v) <= 2 ? '***' : mb_substr($v, 0, 2) . '***';
    }

    /** DN 가림: 첫 RDN의 값만 가리고 나머지 구조(OU·DC)는 보인다 — "CN=ki***,OU=Users,DC=example,DC=com" */
    public static function maskDn(string $dn): string
    {
        $parts = explode(',', $dn, 2);
        $rdn = explode('=', $parts[0], 2);
        $head = count($rdn) === 2 ? $rdn[0] . '=' . self::mask($rdn[1]) : self::mask($parts[0]);
        return $head . (isset($parts[1]) ? ',' . $parts[1] : '');
    }
}
