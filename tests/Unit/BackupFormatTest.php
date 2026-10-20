<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Backup\BackupException;
use PJGuardian\Services\Backup\BundleFormat;
use PJGuardian\Services\Backup\ConfigSanitizer;
use PJGuardian\Services\Backup\KeyVault;
use PJGuardian\Services\Backup\RestoreService;
use PJGuardian\Support\FixedClock;
use PJGuardian\Tests\TestCase;

/**
 * 백업 묶음 형식(P27B): 이름·허용 항목 규칙, 민감 설정 키 판정과 비우기, 키 파일(.pjgkey) 암호화·가져오기·실패 구분 없음, INI 값 바꾸기.
 */
final class BackupFormatTest extends TestCase
{
    public function testBundleNamesAndAllowedEntries(): void
    {
        foreach (['pjguardian-20261009-023000-daily.pjgbak', 'pjguardian-20261009-023000-manual-12.pjgbak', 'pjguardian-20261009-023000-pre_restore.pjgbak'] as $ok) {
            $this->assertTrue(BundleFormat::validName($ok), $ok);
        }
        foreach (['pjguardian-20261009-023000-other.pjgbak', '../pjguardian-20261009-023000-daily.pjgbak', 'pjguardian-20261009-023000-daily.zip',
            'pjguardian-2026109-023000-daily.pjgbak', "pjguardian-20261009-023000-daily.pjgbak\n"] as $bad) {
            $this->assertFalse(BundleFormat::validName($bad), $bad);
        }
        $this->assertSame('manual', BundleFormat::kindOf('pjguardian-20261009-023000-manual-2.pjgbak'));
        foreach (['manifest.json', 'db/pjguardian.sqlite', 'config/config.ini', 'config/settings.local.ini', 'bin/manifest.json', 'snapshots/3/41/source.zip'] as $e) {
            $this->assertTrue(BundleFormat::allowedEntry($e), $e);
        }
        foreach (['../x', 'snapshots/../../x/source.zip', 'snapshots/0/1/source.zip', 'config/secrets.ini', 'db/pjguardian.sqlite-wal', '/abs', 'snapshots/1/2/other.zip',
            'C:/x', 'snapshots\\1\\2\\source.zip', 'evil.php'] as $e) {
            $this->assertFalse(BundleFormat::allowedEntry($e), $e);
        }
    }

    public function testSensitiveKeysAreBlankedButPoliciesKept(): void
    {
        foreach (['password', 'smtp_password', 'bind_password', 'api_token', 'encryption_key', 'client_secret', 'apikey', 'passphrase', 'pass'] as $k) {
            $this->assertTrue(BundleFormat::sensitiveKey($k), $k);
        }
        foreach (['password_min_length', 'config_machine_keys', 'keyword', 'token_ttl', 'host', 'username'] as $k) {
            $this->assertFalse(BundleFormat::sensitiveKey($k), $k);
        }
        $ini = "[smtp]\nhost = \"mail.example\"\npassword = \"marker-pw\"\n; 주석은 그대로\n[auth]\npassword_min_length = \"12\"\napi_token=raw-token\n";
        [$text, $blanked] = ConfigSanitizer::sanitize($ini);
        $this->assertNotContains('marker-pw', $text);
        $this->assertNotContains('raw-token', $text);
        $this->assertContains('password_min_length = "12"', $text);
        $this->assertContains('mail.example', $text);
        $this->assertContains('; 주석은 그대로', $text);
        $this->assertSame(['smtp.password', 'auth.api_token'], $blanked);
        $flat = ConfigSanitizer::flatten($text);
        $this->assertSame('mail.example', $flat['smtp.host']);
        $this->assertSame('', $flat['smtp.password']);
    }

    public function testKeyFileRoundTripAndUniformFailure(): void
    {
        $vault = new KeyVault(new FixedClock('2026-10-09T00:00:00Z'), $this->tempDir('keyvault'));
        $k1 = base64_encode(random_bytes(32));
        $k0 = base64_encode(random_bytes(32));
        $content = $vault->export($k1, $k0, 'correct horse battery');
        $this->assertNotContains($k1, $content, '평문 키 없음');
        $this->assertNotContains($k0, $content);
        $this->assertNotContains('correct horse battery', $content, '암호문구 없음');
        $file = json_decode($content, true);
        $this->assertSame(KeyVault::fingerprint($k1), $file['fingerprint'], '겉에 키 지문');
        $this->assertTrue((int) $file['kdf']['iterations'] >= 200000, 'PBKDF2 20만 회 이상');
        $keys = $vault->import($content, 'correct horse battery');
        $this->assertSame($k1, $keys['encryption_key']);
        $this->assertSame($k0, $keys['encryption_key_previous']);
        // 틀린 암호문구와 변조는 같은 오류(어느 쪽인지 알려 주지 않음)
        $codes = [];
        $tampered = $file;
        $tampered['ciphertext'] = base64_encode(strrev((string) base64_decode($file['ciphertext'])));
        foreach ([[$content, 'wrong passphrase!!'], [(string) json_encode($tampered), 'correct horse battery']] as [$c, $p]) {
            try {
                $vault->import($c, $p);
                $codes[] = 'ok';
            } catch (BackupException $e) {
                $codes[] = $e->errorCode;
            }
        }
        $this->assertSame(['key_import_failed', 'key_import_failed'], $codes);
        $this->assertThrowsCode('key_file_invalid', fn () => $vault->import('not json', 'correct horse battery'));
        $this->assertThrowsCode('key_passphrase_weak', fn () => $vault->export($k1, null, 'short'));
    }

    public function testIniValueReplaceOrAppend(): void
    {
        $ini = "[app]\nenv = \"production\"\n\n[worker]\nmax_concurrent = \"2\"\n";
        $out = RestoreService::setIniValue($ini, 'worker.max_concurrent', '3');
        $this->assertContains('max_concurrent = "3"', $out);
        $this->assertNotContains('max_concurrent = "2"', $out);
        $out = RestoreService::setIniValue($out, 'app.base_url', 'https://x');
        $this->assertSame('https://x', parse_ini_string($out, true, INI_SCANNER_RAW)['app']['base_url']);
        $this->assertSame('production', parse_ini_string($out, true, INI_SCANNER_RAW)['app']['env']);
    }

    private function assertThrowsCode(string $code, \Closure $f): void
    {
        try {
            $f();
            $this->fail("{$code} 예외가 나야 함");
        } catch (BackupException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
