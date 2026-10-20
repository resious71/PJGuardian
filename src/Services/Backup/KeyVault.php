<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

use PJGuardian\Services\Repo\SecretBox;
use PJGuardian\Support\Clock;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Fs;

/**
 * 암호화 키 내보내기·가져오기(P27B, 명세 19.4 — 키는 DB 백업과 분리). 키를 평문으로 내려받는 기능은 없다.
 *
 * .pjgkey(JSON): {format: "pjguardian-key", version: 1, fingerprint(키 지문 = SHA-256 앞 8자), key_id(SecretBox 식별자), created_at,
 *   kdf: {alg: "pbkdf2-sha256", iterations, salt}, cipher: "aes-256-gcm", nonce, ciphertext(태그 포함)}.
 * 내용 = {encryption_key, encryption_key_previous}를 암호문구에서 PBKDF2-HMAC-SHA256(310,000회, 무작위 16바이트 salt)로 만든 키로
 * AES-256-GCM 암호화(무작위 12바이트 nonce). 머리 필드(형식·버전·지문·KDF)를 추가 인증 데이터로 묶어 머리를 바꾸면 풀리지 않는다.
 * PHP 내장 openssl·hash만 쓴다(sodium 확장 없음 — Argon2id 대신 PBKDF2, 보고서).
 * 암호문구는 어디에도 저장·기록하지 않는다. 틀린 암호문구와 변조 파일은 같은 오류(key_import_failed)로 구분하지 않는다.
 */
final class KeyVault
{
    public const FORMAT = 'pjguardian-key';
    public const VERSION = 1;
    public const ITERATIONS = 310000;
    public const PASSPHRASE_MIN = 12;
    public const IMPORT_PER_MINUTE = 5;

    public function __construct(private readonly Clock $clock, private readonly string $rateDir)
    {
    }

    /** 키 지문(앞 8자) — 화면·파일 겉에 보여 줘도 되는 값 */
    public static function fingerprint(string $base64Key): string
    {
        $raw = base64_decode(trim($base64Key), true);
        return substr(hash('sha256', $raw === false ? '' : $raw), 0, 8);
    }

    /** @return string .pjgkey 내용 */
    public function export(string $current, ?string $previous, string $passphrase): string
    {
        self::checkPassphrase($passphrase);
        $raw = base64_decode(trim($current), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new BackupException('key_missing');
        }
        $salt = random_bytes(16);
        $nonce = random_bytes(12);
        $head = ['format' => self::FORMAT, 'version' => self::VERSION, 'fingerprint' => self::fingerprint($current), 'key_id' => SecretBox::keyId($raw),
            'created_at' => $this->clock->now()->format('Y-m-d\TH:i:s\Z'), 'kdf' => ['alg' => 'pbkdf2-sha256', 'iterations' => self::ITERATIONS, 'salt' => base64_encode($salt)],
            'cipher' => 'aes-256-gcm'];
        $plain = (string) json_encode(['encryption_key' => trim($current), 'encryption_key_previous' => $previous === null || trim($previous) === '' ? null : trim($previous)]);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::derive($passphrase, $salt, self::ITERATIONS), OPENSSL_RAW_DATA, $nonce, $tag, self::aad($head), 16);
        if ($c === false) {
            throw new \RuntimeException('암호화 실패');
        }
        return (string) json_encode($head + ['nonce' => base64_encode($nonce), 'ciphertext' => base64_encode($c . $tag)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * .pjgkey + 암호문구 → 키. 형식이 아니면 key_file_invalid, 암호문구가 틀리거나 변조면 key_import_failed(구분하지 않음).
     *
     * @return array{encryption_key: string, encryption_key_previous: ?string, fingerprint: string}
     */
    public function import(string $content, string $passphrase): array
    {
        $d = json_decode($content, true);
        if (!is_array($d) || ($d['format'] ?? null) !== self::FORMAT || !is_int($d['version'] ?? null) || !is_array($d['kdf'] ?? null)
            || ($d['kdf']['alg'] ?? null) !== 'pbkdf2-sha256' || !is_int($d['kdf']['iterations'] ?? null) || !is_string($d['nonce'] ?? null) || !is_string($d['ciphertext'] ?? null)) {
            throw new BackupException('key_file_invalid');
        }
        if ($d['version'] > self::VERSION || $d['kdf']['iterations'] < 100000 || $d['kdf']['iterations'] > 10000000) {
            throw new BackupException('key_file_invalid');
        }
        $salt = base64_decode((string) ($d['kdf']['salt'] ?? ''), true);
        $nonce = base64_decode($d['nonce'], true);
        $raw = base64_decode($d['ciphertext'], true);
        if ($salt === false || strlen($salt) < 16 || $nonce === false || strlen($nonce) !== 12 || $raw === false || strlen($raw) < 17) {
            throw new BackupException('key_file_invalid');
        }
        $head = array_intersect_key($d, array_flip(['format', 'version', 'fingerprint', 'key_id', 'created_at', 'kdf', 'cipher']));
        $plain = openssl_decrypt(substr($raw, 0, -16), 'aes-256-gcm', self::derive($passphrase, $salt, $d['kdf']['iterations']), OPENSSL_RAW_DATA, $nonce, substr($raw, -16), self::aad($head));
        $keys = $plain === false ? null : json_decode($plain, true);
        if (!is_array($keys) || !is_string($keys['encryption_key'] ?? null)) {
            throw new BackupException('key_import_failed');
        }
        $k = base64_decode($keys['encryption_key'], true);
        if ($k === false || strlen($k) !== 32 || self::fingerprint($keys['encryption_key']) !== ($d['fingerprint'] ?? null)) {
            throw new BackupException('key_import_failed');
        }
        return ['encryption_key' => $keys['encryption_key'], 'encryption_key_previous' => is_string($keys['encryption_key_previous'] ?? null) ? $keys['encryption_key_previous'] : null,
            'fingerprint' => self::fingerprint($keys['encryption_key'])];
    }

    /**
     * 키로 DB의 저장소 자격 증명 하나를 실제로 풀어 본다. 자격 증명이 없으면 null(시험할 것이 없음), 풀리면 true, 아니면 false.
     *
     * @param list<?string> $keys base64 키들
     */
    public static function testAgainstDb(array $keys, string $dbFile): ?bool
    {
        $rows = self::encryptedRows($dbFile, 20);
        if ($rows === []) {
            return null;
        }
        try {
            $box = new SecretBox($keys);
        } catch (\InvalidArgumentException) {
            return false;
        }
        foreach ($rows as $r) {
            try {
                $box->decrypt($r['cipher'], $r['nonce'], $r['key_id'], $r['aad']);
            } catch (\Throwable) {
                return false;
            }
        }
        return true;
    }

    /** DB에서 지금 키로 풀 수 없는 암호화 비밀 수(저장소 연결 + P28B 앱 비밀 — 키 없이 복원한 뒤 "다시 입력" 대상) @param list<?string> $keys */
    public static function unreadableCount(array $keys, string $dbFile): int
    {
        $rows = self::encryptedRows($dbFile, 0);
        try {
            $box = new SecretBox($keys);
        } catch (\InvalidArgumentException) {
            return count($rows);
        }
        $n = 0;
        foreach ($rows as $r) {
            try {
                $box->decrypt($r['cipher'], $r['nonce'], $r['key_id'], $r['aad']);
            } catch (\Throwable) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * 암호화된 비밀: 저장소 연결 자격 증명(P25)과 앱 비밀(P28B app_secrets — LDAP 검색 계정 비밀번호). 표가 없으면(옛 DB) 건너뛴다.
     *
     * @return list<array{cipher:string, nonce:string, key_id:string, aad:string}>
     */
    private static function encryptedRows(string $dbFile, int $limit): array
    {
        $pdo = new \PDO('sqlite:' . $dbFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
        $out = [];
        try {
            $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $has = static function (string $t) use ($exists): bool {
                $exists->execute([$t]);
                return $exists->fetchColumn() !== false;
            };
            $lim = $limit > 0 ? $limit : -1; // SQLite LIMIT -1 = 제한 없음
            if ($has('project_repo_connections')) {
                $st = $pdo->prepare('SELECT project_id, kind, secret_cipher, secret_nonce, secret_key_id FROM project_repo_connections WHERE secret_cipher IS NOT NULL ORDER BY project_id LIMIT ?');
                $st->execute([$lim]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['cipher' => (string) $r['secret_cipher'], 'nonce' => (string) $r['secret_nonce'], 'key_id' => (string) $r['secret_key_id'], 'aad' => 'pjg-repo:' . (int) $r['project_id'] . ':' . (string) $r['kind']];
                }
            }
            if ($has('app_secrets')) {
                foreach ($pdo->query('SELECT name, cipher, nonce, key_id FROM app_secrets ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['cipher' => (string) $r['cipher'], 'nonce' => (string) $r['nonce'], 'key_id' => (string) $r['key_id'], 'aad' => \PJGuardian\Services\Auth\AppSecrets::aad((string) $r['name'])];
                }
            }
        } finally {
            $pdo = null;
        }
        return $out;
    }

    /**
     * secrets.ini에 가져온 키를 쓴다: encryption_key = 가져온 키, encryption_key_previous = 가져온 이전 키(있으면, 복원한 DB의 자격 증명이 그 키일 수 있음)
     * 없으면 지금 키(P25 교체 규칙). smtp·ldap 비밀번호 줄은 그대로. 원래 파일은 secrets.ini.before_restore로 보관.
     *
     * @param array{encryption_key: string, encryption_key_previous: ?string} $keys
     */
    public static function writeSecrets(string $secretsFile, array $keys): void
    {
        $old = is_file($secretsFile) ? (string) file_get_contents($secretsFile) : '';
        $parsed = $old === '' ? [] : (array) @parse_ini_string($old, false, INI_SCANNER_RAW);
        $current = isset($parsed['encryption_key']) ? trim((string) $parsed['encryption_key'], " \"'") : null;
        $previous = $keys['encryption_key_previous'] ?? ($current !== null && $current !== $keys['encryption_key'] ? $current : null);
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $old) ?: [] as $line) {
            if (preg_match('/^\s*(encryption_key|encryption_key_previous)\s*=/', $line) !== 1 && $line !== '') {
                $lines[] = $line;
            }
        }
        $lines[] = 'encryption_key = "' . $keys['encryption_key'] . '"';
        if ($previous !== null && $previous !== '') {
            $lines[] = 'encryption_key_previous = "' . $previous . '"';
        }
        if (is_file($secretsFile)) {
            @copy($secretsFile, $secretsFile . '.before_restore');
        }
        Fs::atomicWrite($secretsFile, implode("\n", $lines) . "\n");
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($secretsFile, 0600);
        }
    }

    /** 키 가져오기 시도 제한(사용자당 분당 5회, 실패·성공 모두 셈) — 초과면 key_import_rate_limited */
    public function consumeImportQuota(int $userId): void
    {
        $this->consumeQuota('key', $userId, self::IMPORT_PER_MINUTE, 'key_import_rate_limited');
    }

    /** 사용자당 분당 횟수(잠금 전용 파일 + 시각 목록 파일 — P25 연결 테스트와 같은 방식). 비밀번호 재확인(reauth)도 이것으로 */
    public function consumeQuota(string $bucket, int $userId, int $perMinute, string $code): void
    {
        Fs::ensureDir($this->rateDir);
        $lock = FileLock::acquire($this->rateDir . '/' . $bucket . '_rate-' . $userId . '.lock');
        try {
            $file = $this->rateDir . '/' . $bucket . '_rate-' . $userId . '.json';
            $now = $this->clock->now()->getTimestamp();
            $list = array_values(array_filter((array) json_decode((string) @file_get_contents($file), true), static fn ($t): bool => is_int($t) && $t > $now - 60));
            if (count($list) >= $perMinute) {
                throw new BackupException($code, ['n' => $perMinute]);
            }
            $list[] = $now;
            Fs::atomicWrite($file, (string) json_encode($list));
        } finally {
            $lock->release();
        }
    }

    public static function checkPassphrase(string $p): void
    {
        if (mb_strlen($p, 'UTF-8') < self::PASSPHRASE_MIN || mb_strlen($p, 'UTF-8') > 1024) {
            throw new BackupException('key_passphrase_weak', ['min' => self::PASSPHRASE_MIN]);
        }
    }

    private static function derive(string $passphrase, string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    }

    /** @param array<string, mixed> $head */
    private static function aad(array $head): string
    {
        return (string) json_encode([$head['format'] ?? null, $head['version'] ?? null, $head['fingerprint'] ?? null, $head['key_id'] ?? null, $head['kdf'] ?? null]);
    }
}
