<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Repo\SecretBox;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 앱 전역 비밀(P28B, 표 app_secrets): LDAP 검색 계정 비밀번호(ldap.bind_password), OIDC 클라이언트 비밀(oidc.client_secret, P28C), 관리자 이메일 SMTP 비밀번호(mail.smtp_password, P30B).
 * 허용 이름은 이 목록 한 곳(027부터 DB는 이름 형식만 검사). ldap 말고 다른 이름은 027이 적용돼야 넣을 수 있다.
 * P25의 SecretBox(AES-256-GCM, 키는 secrets.ini — DB·백업에 없음)로 암호화한 본문만 저장한다. AAD = "app_secret:<이름>".
 * 평문은 어떤 응답·로그·감사 로그에도 내리지 않는다 — 상태(set·none·unreadable)만.
 */
final class AppSecrets
{
    public const NAMES = ['ldap.bind_password', 'oidc.client_secret', 'saml.sp_private_key', 'saml.sp_cert', 'mail.smtp_password']; // P28D SP 키·인증서(인증서는 공개지만 키와 한 쌍으로), P30B SMTP 비밀번호
    public const MAX_BYTES = 1024;
    /** 이름별 상한(P28D SP 개인 키·인증서 PEM은 1 KiB를 넘음 — 암호문이 DB 상한 8000자 안에 들도록 4 KiB) */
    private const MAX_BYTES_BY_NAME = ['saml.sp_private_key' => 4096, 'saml.sp_cert' => 4096];

    private ?SecretBox $boxInstance = null;

    /** @param \Closure(): SecretBox $box 쓸 때만 만든다(키 형식 오류가 LDAP을 쓰지 않는 화면까지 막지 않게) */
    public function __construct(private readonly Db $db, private readonly \Closure $box, private readonly Clock $clock)
    {
    }

    private function box(): SecretBox
    {
        return $this->boxInstance ??= ($this->box)();
    }

    /** set(복호화 가능) | none | unreadable(키 교체·분실·변조) */
    public function state(string $name): string
    {
        $row = $this->row($name);
        if ($row === null) {
            return 'none';
        }
        try {
            $this->box()->decrypt($row['cipher'], $row['nonce'], $row['key_id'], self::aad($name));
            return 'set';
        } catch (RepoException | \InvalidArgumentException) { // 키 형식 오류도 읽을 수 없음
            return 'unreadable';
        }
    }

    /** 평문(없으면 ''). 복호화할 수 없으면 RepoException(repo_secret_unreadable) */
    public function get(string $name): string
    {
        $row = $this->row($name);
        return $row === null ? '' : $this->box()->decrypt($row['cipher'], $row['nonce'], $row['key_id'], self::aad($name));
    }

    public function put(string $name, string $plain, ?int $userId): void
    {
        self::assertName($name);
        $max = self::MAX_BYTES_BY_NAME[$name] ?? self::MAX_BYTES;
        if ($plain === '' || strlen($plain) > $max || preg_match('/[\x00]/', $plain) === 1) {
            throw new ApiException(400, 'app_secret_invalid', ['max' => $max]);
        }
        if ($name !== 'ldap.bind_password' && !\PJGuardian\Support\SchemaFeatures::appSecretNames($this->db)) {
            throw new ApiException(409, 'app_secret_migration_required'); // 027 전: 이름 제약이 ldap.bind_password만 받음
        }
        if (!$this->box()->available()) {
            throw new ApiException(409, 'repo_secret_key_missing');
        }
        $enc = $this->box()->encrypt($plain, self::aad($name));
        $this->db->execute(
            'INSERT INTO app_secrets (name, cipher, nonce, key_id, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (name) DO UPDATE SET cipher = excluded.cipher, nonce = excluded.nonce, key_id = excluded.key_id, updated_at = excluded.updated_at, updated_by = excluded.updated_by',
            [$name, $enc['cipher'], $enc['nonce'], $enc['key_id'], SystemClock::iso($this->clock->now()), $userId],
        );
    }

    public function delete(string $name): bool
    {
        self::assertName($name);
        return $this->db->execute('DELETE FROM app_secrets WHERE name = ?', [$name]) > 0;
    }

    /** @return array{cipher:string, nonce:string, key_id:string}|null */
    private function row(string $name): ?array
    {
        self::assertName($name);
        $r = $this->db->one('SELECT cipher, nonce, key_id FROM app_secrets WHERE name = ?', [$name]);
        return $r === null ? null : ['cipher' => (string) $r['cipher'], 'nonce' => (string) $r['nonce'], 'key_id' => (string) $r['key_id']];
    }

    public static function aad(string $name): string
    {
        return 'app_secret:' . $name;
    }

    private static function assertName(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException('알 수 없는 앱 비밀');
        }
    }
}
