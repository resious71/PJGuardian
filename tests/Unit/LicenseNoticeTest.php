<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Tests\TestCase;

/**
 * 라이선스·고지 일치(P33): LICENSE(Apache-2.0 전문)·NOTICE·composer.json 라이선스, composer.lock의 모든 패키지가
 * THIRD_PARTY_NOTICES·NOTICE·docs/third_party.md에 같은 버전으로 있음, 번들 JS가 SOURCE.txt의 크기·SHA-256과 같고 그 포함 패키지가
 * THIRD_PARTY_NOTICES에 있음, docs/legal/ 고지 문서가 있고 README(영어·한국어)에서 링크됨.
 */
final class LicenseNoticeTest extends TestCase
{
    private const LEGAL = ['DISCLAIMER.md', 'DISCLAIMER.ko.md', 'TRADEMARKS.md', 'PRIVACY.md', 'AI_ASSISTED.md'];

    private function read(string $rel): string
    {
        $this->assertTrue(is_file(PJG_ROOT . '/' . $rel), "{$rel} 없음");
        return (string) file_get_contents(PJG_ROOT . '/' . $rel);
    }

    public function testProjectLicenseFiles(): void
    {
        $license = $this->read('LICENSE');
        foreach (['Apache License', 'Version 2.0, January 2004', 'TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION', '4. Redistribution.', 'END OF TERMS AND CONDITIONS'] as $s) {
            $this->assertContains($s, $license, 'LICENSE는 Apache-2.0 전문');
        }
        $notice = $this->read('NOTICE');
        $this->assertContains('PJGuardian', $notice);
        $this->assertContains('Apache License, Version 2.0', $notice);
        $this->assertSame('Apache-2.0', json_decode($this->read('composer.json'), true)['license'] ?? null, 'composer.json license');
        $this->assertContains('Apache License 2.0', $this->read('THIRD_PARTY_NOTICES'), 'THIRD_PARTY_NOTICES 머리에 프로젝트 라이선스');
    }

    public function testComposerPackagesAreNoticed(): void
    {
        $lock = json_decode($this->read('composer.lock'), true);
        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
        $this->assertTrue($packages !== [], 'composer.lock 패키지');
        $tpn = $this->read('THIRD_PARTY_NOTICES');
        $notice = $this->read('NOTICE');
        $table = $this->read('docs/third_party.md');
        foreach ($packages as $p) {
            $name = (string) $p['name'];
            $version = ltrim((string) $p['version'], 'v');
            $licenses = implode(', ', (array) ($p['license'] ?? []));
            foreach (['THIRD_PARTY_NOTICES' => $tpn, 'docs/third_party.md' => $table] as $file => $text) {
                $this->assertContains($name, $text, "{$file}에 {$name}");
                $this->assertTrue(preg_match('/' . preg_quote($name, '/') . '[^\n]*' . preg_quote($version, '/') . '/', $text) === 1, "{$file}에 {$name} {$version}(같은 줄)");
            }
            $short = substr($name, (int) strrpos($name, '/') + 1);
            $this->assertTrue(stripos($notice, $short) !== false && str_contains($notice, $version), "NOTICE에 {$name} {$version}");
            foreach ((array) ($p['license'] ?? []) as $l) {
                $this->assertContains((string) $l, $tpn, "THIRD_PARTY_NOTICES에 {$name} 라이선스 {$licenses}");
            }
        }
    }

    public function testBundledAssetsMatchSourceFiles(): void
    {
        $tpn = $this->read('THIRD_PARTY_NOTICES');
        $checked = 0;
        foreach (glob(PJG_ROOT . '/public/assets/vendor/*/SOURCE.txt') ?: [] as $sourceFile) {
            $dir = dirname($sourceFile);
            $src = (string) file_get_contents($sourceFile);
            // 번들 파일(빌드한 것)은 크기·SHA-256이 적혀 있다 — 파일이 그대로인지
            if (preg_match('/^(\S+\.js) — .*?크기: (\d+) 바이트, SHA-256: ([0-9a-f]{64})/su', $src, $m) === 1) {
                $file = $dir . '/' . $m[1];
                $this->assertTrue(is_file($file), basename($dir) . "/{$m[1]} 없음");
                $this->assertSame([(int) $m[2], $m[3]], [(int) filesize($file), hash_file('sha256', $file)], basename($dir) . "/{$m[1]}이 SOURCE.txt와 다름");
                $checked++;
            }
            // 포함 패키지 줄 "  이름 버전 (라이선스)" — 모두 고지에
            if (preg_match_all('/^  (@?[a-z0-9][a-z0-9._\/-]*) (\d+\.\d+\.\d+) \(([A-Za-z0-9.\-]+)\)$/m', $src, $pm, PREG_SET_ORDER) > 0) {
                foreach ($pm as $p) {
                    $this->assertTrue(preg_match('/' . preg_quote($p[1], '/') . ' ' . preg_quote($p[2], '/') . '/', $tpn) === 1, "THIRD_PARTY_NOTICES에 {$p[1]} {$p[2]}");
                }
            }
            // 한 줄 요약 "이름 버전 (라이선스)"(fflate·lucide)
            if (preg_match('/^([a-z][a-z0-9-]*) (\d+\.\d+\.\d+) \(([A-Za-z0-9.\-]+)\)/', $src, $one) === 1) {
                $this->assertTrue(preg_match('/' . preg_quote($one[1] === 'lucide-static' ? 'lucide-static' : $one[1], '/') . '\)? ' . preg_quote($one[2], '/') . '/i', $tpn) === 1, "THIRD_PARTY_NOTICES에 {$one[1]} {$one[2]}");
                $this->assertContains($one[3], $tpn);
            }
        }
        $lucide = (string) file_get_contents(PJG_ROOT . '/public/assets/icons/lucide/SOURCE.txt');
        $this->assertTrue(preg_match('/^lucide-static (\d+\.\d+\.\d+) \(ISC\)/', $lucide, $lm) === 1, 'lucide SOURCE.txt 형식');
        $this->assertTrue(preg_match('/lucide-static\)? \|? ?' . preg_quote($lm[1], '/') . '/', $tpn) === 1, "THIRD_PARTY_NOTICES에 lucide-static {$lm[1]}");
        $this->assertSame(2, $checked, '크기·SHA-256을 확인한 번들(CodeMirror·Chart.js)');
    }

    public function testCurrentBinToolsAreNoticed(): void
    {
        $tpn = $this->read('THIRD_PARTY_NOTICES');
        foreach (['ruff', 'eslint'] as $tool) {
            $versions = array_map(static fn (string $p): string => basename(dirname($p)), glob(PJG_ROOT . "/bin/{$tool}/*/SHA256SUMS") ?: []);
            if ($versions === []) {
                continue; // bin/ 매니페스트가 없는 사본
            }
            usort($versions, 'version_compare');
            $latest = end($versions);
            $this->assertTrue(preg_match('/^\| ' . ($tool === 'ruff' ? 'ruff' : 'ESLint') . '\b[^\n]*' . preg_quote($latest, '/') . '/mi', $tpn) === 1, "THIRD_PARTY_NOTICES 표에 {$tool} {$latest}");
        }
        $this->assertContains('공개 묶음 금지', $tpn, 'p4는 공개 묶음에 넣지 않음');
    }

    /** X22: 공개 문서의 자리표시자(저작권자·보안 연락처·행동 강령 연락처)가 남아 있지 않음. 단계 보고·프롬프트(공개 제외)는 보지 않는다 */
    public function testNoPublicationPlaceholdersRemain(): void
    {
        $needles = ['<' . 'copyright-holder>', '<' . 'security-contact>', '<' . 'conduct-contact>'];
        $skipDirs = ['.git', 'vendor', 'data', 'dist', 'bin', 'node_modules', 'docs/results', 'prompts', 'backups'];
        $found = [];
        $walk = function (string $dir, string $rel) use (&$walk, &$found, $needles, $skipDirs): void {
            foreach (\PJGuardian\Support\Fs::listDir($dir) as $e) {
                $r = $rel === '' ? $e : $rel . '/' . $e;
                $p = $dir . '/' . $e;
                if (is_dir($p)) {
                    if (!in_array($r, $skipDirs, true) && !str_ends_with($r, '/node_modules')) {
                        $walk($p, $r);
                    }
                    continue;
                }
                if (preg_match('/\.(png|jpe?g|gif|ico|zip|pdf|woff2?|ttf|sqlite|exe|pptx)$/i', $e) === 1 || $r === 'PJGuardian_requirements.md' || filesize($p) > 4 << 20) {
                    continue;
                }
                $text = (string) file_get_contents($p);
                foreach ($needles as $n) {
                    if (str_contains($text, $n)) {
                        $found[] = "{$r}: {$n}";
                    }
                }
            }
        };
        $walk(PJG_ROOT, '');
        $this->assertSame([], $found, '남은 자리표시자');
        $this->assertContains('Copyright (c) 2026 resious71', $this->read('NOTICE'), 'NOTICE 저작권 문구(사용자 결정 그대로)');
    }

    public function testLegalDocsExistAndAreLinkedFromReadmes(): void
    {
        foreach (self::LEGAL as $doc) {
            $this->assertTrue(is_file(PJG_ROOT . '/docs/legal/' . $doc), "docs/legal/{$doc} 없음");
        }
        foreach (['README.md', 'README.en.md'] as $readme) {
            $text = $this->read($readme);
            foreach (['LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', 'docs/legal/DISCLAIMER', 'docs/legal/TRADEMARKS.md', 'docs/legal/PRIVACY.md', 'docs/legal/AI_ASSISTED.md', 'SECURITY.md'] as $target) {
                $this->assertTrue(str_contains($text, '](' . $target), "{$readme}에서 {$target} 링크");
            }
        }
    }
}
