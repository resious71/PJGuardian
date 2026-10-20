<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

/**
 * 저장소 자격 증명 인증 암호화(P25, 명세 18.4·19.4): AES-256-GCM(OpenSSL 내장 확장, 새 패키지 없음).
 *
 * - 키: 32바이트(base64로 config/secrets.ini의 encryption_key 또는 환경 변수 PJG_ENCRYPTION_KEY). **DB·저장소에 두지 않는다.**
 *   키가 없으면 암호화(저장)를 거부한다(자동 생성하지 않는다 — tools/make_secret_key.php가 지정한 파일에만 쓴다).
 * - 행마다 임의 nonce(12바이트), 태그 16바이트를 암호문 뒤에 붙여 base64로 저장. 추가 인증 데이터(AAD)에 "프로젝트 id:종류"를 넣어
 *   다른 행·다른 종류로 옮겨 붙인 암호문은 풀리지 않는다.
 * - 키 식별자 = SHA-256(키) 앞 16자리. 키 교체: 새 키를 encryption_key, 옛 키를 encryption_key_previous에 두면 둘 다로 풀 수 있고,
 *   저장할 때 새 키로 다시 암호화된다. 키를 잃으면 복구할 수 없다(관리자가 자격 증명을 다시 입력).
 * - 평문·키는 로그·예외 메시지에 넣지 않는다.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';

    /** @var array<string, string> 키 식별자 → 원시 키(32바이트). 첫 항목이 암호화에 쓰는 현재 키 */
    private array $keys = [];

    /** @param list<?string> $base64Keys 현재 키, 이전 키 순(빈 값은 무시) */
    public function __construct(array $base64Keys)
    {
        foreach ($base64Keys as $b64) {
            if ($b64 === null || trim($b64) === '') {
                continue;
            }
            $raw = base64_decode(trim($b64), true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new \InvalidArgumentException('암호화 키 형식 오류(32바이트 base64)'); // 키 값은 메시지에 넣지 않는다
            }
            $this->keys[self::keyId($raw)] = $raw;
        }
    }

    public static function keyId(string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 16);
    }

    public function available(): bool
    {
        return $this->keys !== [];
    }

    public function currentKeyId(): ?string
    {
        return array_key_first($this->keys);
    }

    /** @return array{cipher:string, nonce:string, key_id:string} */
    public function encrypt(string $plain, string $aad): array
    {
        $id = $this->currentKeyId();
        if ($id === null) {
            throw new RepoException('repo_secret_key_missing');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, self::CIPHER, $this->keys[$id], OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($c === false) {
            throw new \RuntimeException('암호화 실패');
        }
        return ['cipher' => base64_encode($c . $tag), 'nonce' => base64_encode($nonce), 'key_id' => $id];
    }

    /** 복호화. 키가 없거나(교체·분실) 변조되었으면 RepoException(repo_secret_unreadable) */
    public function decrypt(string $cipherB64, string $nonceB64, string $keyId, string $aad): string
    {
        $key = $this->keys[$keyId] ?? null;
        $raw = base64_decode($cipherB64, true);
        $nonce = base64_decode($nonceB64, true);
        if ($key === null || $raw === false || $nonce === false || strlen($raw) < 17 || strlen($nonce) !== 12) {
            throw new RepoException('repo_secret_unreadable');
        }
        $plain = openssl_decrypt(substr($raw, 0, -16), self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, substr($raw, -16), $aad);
        if ($plain === false) {
            throw new RepoException('repo_secret_unreadable');
        }
        return $plain;
    }
}
