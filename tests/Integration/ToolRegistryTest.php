<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolManifest;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 도구 레지스트리(명세 12, 19.10, P15): 가짜 bin/ 디렉토리로 최신 선택, 체크섬 불일치 건너뜀, 스모크 실패 시 직전 버전 유지,
 * 지문이 같으면 다시 검증하지 않음, 파일이 바뀌면 다시 검증, 고정·차단.
 */
final class ToolRegistryTest extends TestCase
{
    private string $bin;
    private string $state;
    private Db $db;
    /** @var list<string> 스모크 테스트가 불린 버전 */
    private array $smokeCalls = [];
    /** @var array<string, bool> 버전별 스모크 결과(없으면 통과) */
    private array $smokeResult = [];

    public function setUp(): void
    {
        $root = $this->tempDir('treg');
        $this->bin = $root . '/bin';
        $this->state = $root . '/state';
        mkdir($root . '/logs');
        $this->db = TestDb::migrated();
    }

    private function registry(): ToolRegistry
    {
        $clock = new FixedClock('2026-10-04T00:00:00Z');
        return new ToolRegistry(
            $this->db, $clock, new Logger(dirname($this->bin) . '/logs', $clock, 'INFO'), $this->bin, $this->state,
            new NodeRuntime(null, '20.19.0'),
            function (SelectedTool $t): array {
                $this->smokeCalls[] = $t->version;
                $ok = $this->smokeResult[$t->version] ?? true;
                return ['ok' => $ok, 'message' => $ok ? '통과' : '기대 결함 수 불일치'];
            },
        );
    }

    /** 가짜 ruff 버전(실행 파일 + 매니페스트) */
    private function fakeRuff(string $version, string $content = 'fake-ruff'): string
    {
        $os = ToolRegistry::os();
        $dir = "{$this->bin}/ruff/{$version}";
        mkdir("{$dir}/{$os}", 0700, true);
        $rel = ToolRegistry::executableRel('ruff');
        file_put_contents("{$dir}/{$rel}", $content . $version);
        file_put_contents("{$dir}/SHA256SUMS", hash_file('sha256', "{$dir}/{$rel}") . " *{$rel}\n");
        return "{$dir}/{$rel}";
    }

    private function status(string $version): ?string
    {
        $v = $this->db->value("SELECT status FROM tool_versions WHERE tool = 'ruff' AND version = ? AND os = ?", [$version, ToolRegistry::os()]);
        return $v === null ? null : (string) $v;
    }

    public function testPicksHighestSemverNotLexicographic(): void
    {
        foreach (['0.9.0', '0.16.10', '0.16.2'] as $v) {
            $this->fakeRuff($v);
        }
        mkdir("{$this->bin}/ruff/latest"); // 버전 형식이 아닌 폴더는 무시
        $r = $this->registry();
        $this->assertSame(['0.16.10', '0.16.2', '0.9.0'], $r->versionsOnDisk('ruff'));
        $sel = $r->select('ruff');
        $this->assertSame('0.16.10', $sel?->version);
        $this->assertSame(realpath("{$this->bin}/ruff/0.16.10/" . ToolRegistry::executableRel('ruff')), $sel->executable);
        $this->assertSame('active', $this->status('0.16.10'));
        $this->assertSame(['0.16.10'], $this->smokeCalls, '새 버전은 체크섬 → 스모크');
        $this->assertSame(null, $this->status('0.16.2'), '쓰지 않은 버전은 검증하지 않음');
    }

    public function testChecksumMismatchSkipsToPreviousVersion(): void
    {
        $this->fakeRuff('1.0.0');
        $exe = $this->fakeRuff('1.1.0');
        file_put_contents($exe, 'tampered'); // 매니페스트와 다름
        $sel = $this->registry()->select('ruff');
        $this->assertSame('1.0.0', $sel?->version, '체크섬이 틀린 최신 버전은 건너뛰고 직전 유효 버전');
        $this->assertSame('invalid', $this->status('1.1.0'));
        $row = $this->db->one("SELECT checksum_ok, smoke_ok, smoke_message FROM tool_versions WHERE version = '1.1.0'");
        $this->assertSame([0, null], [(int) $row['checksum_ok'], $row['smoke_ok']], '체크섬 실패면 스모크를 하지 않음');
        $this->assertContains('해시 불일치', (string) $row['smoke_message']);
        $this->assertSame(['1.0.0'], $this->smokeCalls);
        // 매니페스트에 실행 파일이 없는 경우도 거부
        $dir = "{$this->bin}/ruff/1.2.0";
        mkdir($dir . '/' . ToolRegistry::os(), 0700, true);
        file_put_contents($dir . '/' . ToolRegistry::executableRel('ruff'), 'x');
        file_put_contents($dir . '/SHA256SUMS', hash('sha256', 'y') . " *other.txt\n");
        $this->assertSame('1.0.0', $this->registry()->select('ruff')?->version);
        $this->assertSame('invalid', $this->status('1.2.0'));
    }

    public function testSmokeFailureKeepsPreviousValidVersion(): void
    {
        $this->fakeRuff('2.0.0');
        $r = $this->registry();
        $this->assertSame('2.0.0', $r->select('ruff')?->version);
        $this->fakeRuff('2.1.0');
        $this->smokeResult['2.1.0'] = false;
        $this->assertSame('2.0.0', $r->select('ruff')?->version, '새 버전 스모크 실패 → 직전 유효 버전 유지');
        $this->assertSame(['invalid', 'active'], [$this->status('2.1.0'), $this->status('2.0.0')]);
        $log = (string) file_get_contents((new Logger(dirname($this->bin) . '/logs', new FixedClock('2026-10-04T00:00:00Z'), 'INFO'))->fileFor(new \DateTimeImmutable('2026-10-04')));
        $this->assertContains('도구 버전 검증 실패', $log);
        $this->assertContains('직전 유효 버전을 사용', $log);
    }

    public function testFingerprintAvoidsReverifyUntilFilesChange(): void
    {
        $exe = $this->fakeRuff('3.0.0');
        $r = $this->registry();
        $r->select('ruff');
        $r->select('ruff');
        $this->registry()->select('ruff');
        $this->assertSame(['3.0.0'], $this->smokeCalls, '지문이 같으면 다시 검증하지 않음');
        // 실행 파일을 바꾸면(크기·시각) 지문이 달라져 전체 검증 → 체크섬 실패로 invalid, 쓸 버전 없음
        file_put_contents($exe, 'replaced-binary-longer');
        $this->assertSame(null, $this->registry()->select('ruff'));
        $this->assertSame('invalid', $this->status('3.0.0'));
        // 매니페스트까지 새로 맞추면 다시 검증해 활성화
        $manifest = dirname($exe, 2) . '/SHA256SUMS';
        file_put_contents($manifest, hash_file('sha256', $exe) . ' *' . ToolRegistry::executableRel('ruff') . "\n");
        // 지문은 크기·수정 시각(초)이라 같은 초 안에 같은 크기로 바꾸면 못 알아챈다(알려진 절충). 시각을 옮겨 실제 교체를 흉내 낸다
        touch($manifest, time() + 10);
        $this->assertSame('3.0.0', $this->registry()->select('ruff')?->version);
        $this->assertSame(['3.0.0', '3.0.0'], $this->smokeCalls);
    }

    public function testPinAndBlock(): void
    {
        foreach (['4.0.0', '4.1.0', '4.2.0'] as $v) {
            $this->fakeRuff($v);
        }
        $r = $this->registry();
        $this->assertSame('4.2.0', $r->select('ruff')?->version);
        $this->assertTrue($r->pin('ruff', '4.0.0')['ok']);
        $this->assertSame('pinned', $this->status('4.0.0'));
        $this->assertSame('4.0.0', $r->select('ruff')?->version, '고정하면 자동 최신 선택 중단');
        $this->assertTrue($r->pin('ruff', '4.1.0')['ok']);
        $this->assertSame(['active', 'pinned'], [$this->status('4.0.0'), $this->status('4.1.0')], '고정은 하나만');
        $r->unpin('ruff');
        $this->assertSame('4.2.0', $r->select('ruff')?->version);
        $r->block('ruff', '4.2.0');
        $this->assertSame('4.1.0', $r->select('ruff')?->version, '차단한 버전은 건너뜀');
        $this->assertFalse($r->pin('ruff', '4.2.0')['ok'], '차단한 버전은 고정할 수 없음');
        $r->unblock('ruff', '4.2.0');
        $this->assertSame('4.2.0', $r->select('ruff')?->version, '차단 해제 뒤 다시 검증해 사용');
        $this->smokeResult['4.0.0'] = false;
        $this->fakeRuff('4.3.0');
        $this->smokeResult['4.3.0'] = false;
        $this->assertSame('4.2.0', $r->select('ruff')?->version);
        $this->assertFalse($r->pin('ruff', '4.3.0')['ok'], '검증에 실패한 버전은 고정할 수 없음');
        $this->assertThrows(\InvalidArgumentException::class, fn () => $r->block('ruff', '../x'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $r->select('python'));
    }

    public function testEslintOnlyMajorTen(): void
    {
        foreach (['9.39.5', '10.11.0', '11.0.0'] as $v) {
            $dir = "{$this->bin}/eslint/{$v}/node_modules/eslint/bin";
            mkdir($dir, 0700, true);
            file_put_contents("{$dir}/eslint.js", '// fake ' . $v);
            file_put_contents("{$this->bin}/eslint/{$v}/SHA256SUMS", hash_file('sha256', "{$dir}/eslint.js") . " *node_modules/eslint/bin/eslint.js
");
        }
        $r = $this->registry();
        foreach (['9.39.5', '11.0.0'] as $v) {
            $res = $r->ensureVerified('eslint', $v, true);
            $this->assertSame([false, false], [$res['ok'], $res['verified']], "eslint {$v}");
            $this->assertContains('10.x', $res['message']);
        }
    }

    public function testManifestParsingRejectsEscapes(): void
    {
        $dir = $this->tempDir('mani');
        file_put_contents($dir . '/SHA256SUMS', str_repeat('a', 64) . " *../outside\n");
        $this->assertSame(false, ToolManifest::verify($dir)['ok']);
        file_put_contents($dir . '/SHA256SUMS', "not a manifest\n");
        $this->assertSame(false, ToolManifest::verify($dir)['ok']);
        file_put_contents($dir . '/a.txt', 'A');
        file_put_contents($dir . '/SHA256SUMS', hash('sha256', 'A') . "  ./a.txt\r\n");
        $this->assertSame(['ok' => true, 'files' => 1, 'problems' => []], ToolManifest::verify($dir, ['a.txt']));
        $this->assertSame(null, ToolManifest::fingerprint($dir . '/none'));
        $this->assertTrue(is_string(ToolManifest::fingerprint($dir)));
    }
}
