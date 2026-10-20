<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Backup\ConfigSanitizer;
use PJGuardian\Services\Backup\KeyVault;
use PJGuardian\Services\Backup\RestoreService;
use PJGuardian\Tests\TestCase;

/**
 * P32 인수 시험에서 찾은 결함: `/u` 없는 `\R`는 바이트 0x85(NEL)도 줄바꿈으로 본다. 한글 '명'(EB AA 85)처럼 0x85가 든 글자가 주석에 있으면
 * 줄 나누기가 글자를 쪼개 INI 문법이 깨졌다 — secrets.example을 복사한 secrets.ini에 키를 가져와 복원하면(설치 가이드 10.4) "시크릿 파일 문법 오류"로
 * 복원 실패·롤백. 같은 줄 나누기를 쓰던 설정 사본 정리(백업)·설정 값 바꾸기(--with-config 복원)·ruff 설정 읽기도 바이트 줄바꿈(\r\n|\n|\r)으로 고쳤다.
 * 정적 검사: src/의 preg_* 패턴 리터럴에 /u 없이 \R·\v·\h(0x85·0xA0을 공백으로 봄)가 없어야 한다(CLAUDE.md 정규식 규칙, P04).
 */
final class KoreanLineSplitTest extends TestCase
{
    private const INI = "; PJGuardian 시크릿 예시 (명세 18.4, 19.2)\n; 키를 잃으면 저장된 자격 증명은 복구할 수 없다 (명세 19.4)\n"
        . "smtp_password = \"\"\nencryption_key = \"old\"\n";

    public function testWriteSecretsKeepsKoreanCommentsIntact(): void
    {
        $this->assertContains("\x85", self::INI, '시료에 0x85 바이트가 있음(명)');
        $file = $this->tempDir('ksplit') . '/secrets.ini';
        file_put_contents($file, self::INI);
        KeyVault::writeSecrets($file, ['encryption_key' => 'new-key', 'encryption_key_previous' => null]);
        $text = (string) file_get_contents($file);
        $this->assertContains('; PJGuardian 시크릿 예시 (명세 18.4, 19.2)', $text, '주석 줄이 그대로');
        $parsed = parse_ini_string($text, false, INI_SCANNER_RAW);
        $this->assertTrue(is_array($parsed), 'INI 문법 유지');
        $this->assertSame(['', 'new-key', 'old'], [$parsed['smtp_password'], $parsed['encryption_key'], $parsed['encryption_key_previous']]);
        $this->assertSame(self::INI, (string) file_get_contents($file . '.before_restore'), '원래 파일 보관');
    }

    public function testConfigSanitizerAndSetIniValueKeepKoreanComments(): void
    {
        $ini = "[app]\n; 공개 주소 — 명세 19.7\nbase_url = \"https://x\"\n[smtp]\n; 비밀번호는 명세 19.3대로 시크릿 파일에\npassword = \"p\"\n";
        [$copy] = ConfigSanitizer::sanitize($ini);
        $this->assertContains('; 공개 주소 — 명세 19.7', $copy);
        $this->assertTrue(is_array(parse_ini_string($copy, true, INI_SCANNER_RAW)), '백업 설정 사본 INI 문법 유지');
        $set = RestoreService::setIniValue($ini, 'app.base_url', 'https://y');
        $this->assertContains('; 비밀번호는 명세 19.3대로 시크릿 파일에', $set);
        $p = parse_ini_string($set, true, INI_SCANNER_RAW);
        $this->assertSame('https://y', is_array($p) ? $p['app']['base_url'] : null, '--with-config 복원의 값 바꾸기');
    }

    public function testNoByteUnsafeLineBreakEscapesInSource(): void
    {
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '.php')) {
                continue;
            }
            $toks = token_get_all((string) file_get_contents($f->getPathname()));
            foreach ($toks as $i => $t) {
                if (!is_array($t) || $t[0] !== T_STRING || !str_starts_with($t[1], 'preg_')) {
                    continue;
                }
                for ($j = $i + 1; $j < $i + 4 && isset($toks[$j]); $j++) {
                    if (is_array($toks[$j]) && $toks[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $s = substr($toks[$j][1], 1, -1);
                        $d = $s[0] ?? '';
                        $end = strrpos($s, $d);
                        $mods = $end === false || $end === 0 ? '' : substr($s, $end + 1);
                        if (!str_contains($mods, 'u') && preg_match('/\\\\{1,2}[RvhVH]/', $end === false ? $s : substr($s, 1, $end - 1)) === 1) {
                            $bad[] = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', PJG_ROOT)) + 1) . ':' . $toks[$j][2];
                        }
                        break;
                    }
                }
            }
        }
        $this->assertSame([], $bad, '/u 없는 \\R·\\v·\\h(바이트 0x85·0xA0을 줄바꿈·공백으로 봄 — 한글이 깨짐)');
    }
}
