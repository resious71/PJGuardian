<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Tests\TestCase;

/**
 * 공개 전 비밀·개인 정보 스캐너(P33 2절, tools/scan_release.php): 종류마다 일부러 심은 임시 트리에서 잡는지, 허용 목록(파일·줄·이유)이 동작하고
 * 이유 없는 허용은 거부(종료 코드 2)하는지, 발견 값이 출력에 그대로 나오지 않는지(앞 3글자 + 길이), 깨끗한 트리는 0인지, 이진 파일은 목록으로만 나오는지.
 * 가짜 비밀 값은 이 파일 자체가 스캐너에 걸리지 않게 조각을 이어 붙여 만든다.
 */
final class ScanReleaseTest extends TestCase
{
    /** @return array{0: int, 1: string} */
    private function run(array $args, ?string $stdin = null): array
    {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/scan_release.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private function rules(string $dir, array $allow = []): array
    {
        file_put_contents($dir . '/rules.php', "<?php\nreturn " . var_export([
            'names' => ['zq' . 'personx', 'Other' . 'Proj'],
            'allowed_emails' => [],
            'generic_paths' => ['#^[A-Za-z]:[\\\\/]xampp([\\\\/]|$)#i'],
            'generic_home_names' => ['user'],
            'internal_host_patterns' => ['/\.(local|corp|internal)$/'],
            'allowed_hosts' => ['app.local'],
            'path_rules' => [['glob' => 'vendor/**', 'kinds' => ['token'], 'reason' => '번들']],
        ], true) . ";\n");
        file_put_contents($dir . '/allow.php', "<?php\nreturn " . var_export($allow, true) . ";\n");
        return ['--rules=' . $dir . '/rules.php', '--allow=' . $dir . '/allow.php'];
    }

    /** @return array<string, string> 종류 => 심은 값 */
    private static function planted(): array
    {
        return [
            'email' => 'jane.' . 'doe@' . 'realmail.co',
            'abs_path' => 'D:' . '\\Work\\secret_proj',
            'name' => 'zq' . 'personx',
            'private_key' => '-----BEGIN ' . 'RSA PRIVATE' . ' KEY-----',
            'token_gh' => 'gh' . 'p_' . str_repeat('A1b2C3d4E5', 4),
            'token_aws' => 'AK' . 'IA' . 'QWERTYUIOPASDFGH',
            'token_slack' => 'xo' . 'xb-' . '1234567890-abcdefghij',
            'token_entropy' => 'Zx9' . 'Qm2Lp7Vr4Tn8Kw1Hs6Jd3Fb5Gc0Ya7Ue2Io9Pq4',
            'private_ip' => '192.' . '168.10.20',
            'internal_host' => 'build01.' . 'corp',
            'password_line' => 'Hu' . 'nter2!x9',
        ];
    }

    private function tree(string $dir): string
    {
        $root = $dir . '/tree';
        mkdir($root . '/docs', 0700, true);
        mkdir($root . '/vendor/lib', 0700, true);
        $p = self::planted();
        $lines = [
            'mail: ' . $p['email'],
            'path: ' . $p['abs_path'],
            'owner ' . $p['name'] . ' wrote this',
            $p['private_key'],
            'gh token ' . $p['token_gh'],
            'aws ' . $p['token_aws'],
            'slack ' . $p['token_slack'],
            'random ' . $p['token_entropy'],
            'server ' . $p['private_ip'],
            'host https://' . $p['internal_host'] . '/x',
        ];
        file_put_contents($root . '/docs/notes.md', implode("\n", $lines) . "\n");
        file_put_contents($root . '/settings.ini', "[db]\npassword = \"" . $p['password_line'] . "\"\n");
        file_put_contents($root . '/vendor/lib/x.js', 'var k="' . $p['token_entropy'] . '";' . "\n");
        file_put_contents($root . '/shot.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));
        return $root;
    }

    public function testFindsEveryKindMasksValuesAndListsBinaries(): void
    {
        $dir = $this->tempDir('scanrel');
        $root = $this->tree($dir);
        [$code, $out] = $this->run([$root, '--json', ...$this->rules($dir)]);
        $this->assertSame(1, $code, $out);
        $j = json_decode($out, true);
        $this->assertTrue(is_array($j), $out);
        $kinds = array_count_values(array_column($j['findings'], 'kind'));
        foreach (['email', 'abs_path', 'name', 'private_key', 'private_ip', 'internal_host', 'password_line'] as $k) {
            $this->assertTrue(($kinds[$k] ?? 0) >= 1, "종류 {$k}를 찾음: " . json_encode($kinds));
        }
        $this->assertSame(4, $kinds['token'] ?? 0, 'GitHub·AWS·Slack·고엔트로피(번들 vendor/는 path_rules로 끔)');
        // 값 가림: 심은 값 원문은 어디에도 없다
        foreach (self::planted() as $k => $v) {
            $this->assertNotContains($v, $out, "{$k} 원문이 출력에 없음");
        }
        $this->assertContains('jan…(', $out, '앞 3글자 + 길이');
        $this->assertSame([['file' => 'shot.png', 'size' => 40, 'note' => 'binary']], $j['binaries'], '이진 파일은 목록으로만');
        // 표 출력도 같은 종료 코드·가림
        [$code2, $table] = $this->run([$root, ...$this->rules($dir)]);
        $this->assertSame(1, $code2);
        $this->assertContains('| docs/notes.md | 1 | email |', $table);
        $this->assertContains('shot.png', $table);
        foreach (self::planted() as $v) {
            $this->assertNotContains($v, $table);
        }
    }

    public function testCleanTreeAndReservedValuesPass(): void
    {
        $dir = $this->tempDir('scanrel');
        $root = $dir . '/clean';
        mkdir($root, 0700, true);
        file_put_contents($root . '/README.md', implode("\n", [
            'Contact admin@example.com or ops@pjg.example.invalid.',
            'Install to C:\\xampp\\htdocs, data under /home/user/pjg.',
            'Server https://app.local and http://127.0.0.1:8080, docs IP 192.0.2.10, range 10.0.0.0/8.',
            'sha256 ' . str_repeat('ab12', 16) . ' integrity sha512-' . str_repeat('Ab9+', 12),
            'function testExampleHasDefaultExcludesFromSpec1910() {}',
            'password = ""',
            '$t(\'error.internal\'); tags.local(x);',
        ]) . "\n");
        file_put_contents($root . '/code.php', "<?php\n\$cfg = ['password' => \$input, 'token' => getenv('X')];\n");
        [$code, $out] = $this->run([$root, ...$this->rules($dir)]);
        $this->assertSame(0, $code, $out);
        $this->assertContains('결과: 발견 없음', $out);
    }

    public function testAllowListNeedsReasonAndScopesByFileLineAndMatch(): void
    {
        $dir = $this->tempDir('scanrel');
        $root = $this->tree($dir);
        $all = [
            ['file' => 'docs/notes.md', 'line' => '*', 'reason' => '시험 문서'],
            ['file' => 'settings.ini', 'line' => 2, 'kind' => 'password_line', 'reason' => '시험 설정'],
        ];
        [$code, $out] = $this->run([$root, '--json', ...$this->rules($dir, $all)]);
        $this->assertSame(0, $code, $out);
        $j = json_decode($out, true);
        $this->assertSame([], $j['findings']);
        $this->assertSame(11, count($j['allowed']), '넘긴 것(문서 10 + 설정 1)은 allowed에 이유와 함께');
        $this->assertSame('시험 설정', $j['allowed'][count($j['allowed']) - 1]['reason']);
        // 줄이 다르면 허용되지 않음
        [$code, $out] = $this->run([$root, '--json', ...$this->rules($dir, [['file' => 'settings.ini', 'line' => 1, 'reason' => '다른 줄']])]);
        $this->assertSame(1, $code);
        $this->assertTrue(in_array('password_line', array_column(json_decode($out, true)['findings'], 'kind'), true));
        // match: 값 모양으로 좁힘(이 이메일만)
        [, $out] = $this->run([$root, '--json', ...$this->rules($dir, [['file' => 'docs/**', 'match' => '/^jane\./', 'reason' => '예시 주소']])]);
        $kinds = array_column(json_decode($out, true)['findings'], 'kind');
        $this->assertFalse(in_array('email', $kinds, true), 'match로 그 이메일만 허용');
        $this->assertTrue(in_array('name', $kinds, true), '같은 파일의 다른 발견은 그대로');
        // 이유 없는 허용·줄도 match도 없는 허용은 거부(2)
        foreach ([[['file' => 'docs/notes.md', 'line' => '*']], [['file' => 'docs/notes.md', 'line' => '*', 'reason' => '  ']], [['file' => 'docs/notes.md', 'reason' => '범위 없음']]] as $bad) {
            [$code, $out] = $this->run([$root, ...$this->rules($dir, $bad)]);
            $this->assertSame(2, $code, $out);
        }
    }

    public function testStdinAndUsageErrors(): void
    {
        $dir = $this->tempDir('scanrel');
        $rules = $this->rules($dir);
        $secret = 'gh' . 'o_' . str_repeat('Zz9Yy8Xx7W', 4);
        [$code, $out] = $this->run(['--stdin', '--json', '--label=dashboard.png', ...$rules], "Signed in as demo-admin\nkey " . $secret . "\n");
        $this->assertSame(1, $code, $out);
        $j = json_decode($out, true);
        $this->assertSame('stdin:dashboard.png', $j['source']);
        $this->assertSame([['file' => 'dashboard.png', 'line' => 2, 'kind' => 'token', 'masked' => 'gho…(44)', 'detail' => 'GitHub 토큰']], $j['findings']);
        $this->assertNotContains($secret, $out);
        [$code] = $this->run(['--stdin', ...$rules], "Dashboard\nDemo Admin admin@example.com\n");
        $this->assertSame(0, $code);
        // 사용법 오류
        $this->assertSame(2, $this->run([$dir . '/nope', ...$rules])[0], '없는 폴더');
        $this->assertSame(2, $this->run(['--bogus', ...$rules])[0], '모르는 옵션');
        $this->assertSame(2, $this->run([$dir, '--stdin', ...$rules])[0], '대상 둘');
        $this->assertSame(2, $this->run([$dir, '--rules=' . $dir . '/missing.php'])[0], '규칙 파일 없음');
    }

    /** 저장소의 기본 규칙·허용 목록 파일이 유효하다(이유 없는 허용이 없음) */
    public function testRepositoryRuleFilesAreValid(): void
    {
        $dir = $this->tempDir('scanrel');
        file_put_contents($dir . '/a.txt', "hello\n");
        [$code, $out] = $this->run([$dir]);
        $this->assertSame(0, $code, $out);
    }
}
