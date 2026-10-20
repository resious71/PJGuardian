<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Repo\GitClient;
use PJGuardian\Services\Repo\GitTarget;
use PJGuardian\Services\Repo\HostPolicy;
use PJGuardian\Services\Repo\P4Marshal;
use PJGuardian\Services\Repo\PerforceClient;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Repo\RepoInput;
use PJGuardian\Services\Repo\SecretBox;
use PJGuardian\Services\Repo\VcsProcess;
use PJGuardian\Services\Repo\VcsSandbox;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 저장소 연동 보안 단위 시험(P25): 입력 검증 표(허용/거부, 인자 주입·줄바꿈·사용자 정보·경로 이탈), 호스트 정규화·주소 판정
 * (대소문자·후행 점·IPv6·숫자 변형·사설·링크 로컬·메타데이터·IPv4 대응 IPv6), 허용 목록 판정(포트·HTTP·풀린 주소), 암호화 왕복·변조·키 없음·
 * 키 식별자·이전 키, 오류 분류, 마스킹, Git 강제 설정과 토큰 전달(인자에 없음), p4 marshal, git 진입점(Windows·Linux), 카탈로그 등록.
 */
final class RepoSecurityTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private static function rejects(callable $fn): bool
    {
        try {
            $fn();
            return false;
        } catch (ApiException) {
            return true;
        }
    }

    public function testGitUrlTable(): void
    {
        $ok = [
            'https://GitHub.com/Org/Repo.git' => 'https://github.com/Org/Repo.git',
            'https://ghe.corp.example./a/b' => 'https://ghe.corp.example/a/b',
            'http://git.local:8080/x.git' => 'http://git.local:8080/x.git',
            'https://[::1]:8443/r.git' => 'https://[::1]:8443/r.git',
            'https://10.1.2.3:443/r' => 'https://10.1.2.3/r',
        ];
        foreach ($ok as $in => $out) {
            $this->assertSame($out, RepoInput::gitUrl($in)['url'], $in);
        }
        $bad = ['ssh://git@host/r.git', 'git@host:r.git', 'file:///c:/repo', 'git://host/r', 'ext::sh -c touch% /tmp/x', '/local/path', '../r', 'C:\\repo',
            'https://user:pass@host/r.git', 'https://token@host/r.git', 'https://host/r.git?x=1', 'https://host/r.git#f', "https://host/r\n.git",
            "https://host/r\0", 'https://host/a b', '--upload-pack=touch /tmp/x', '-oProxyCommand=calc', 'https://host', 'https://host/', 'https://host/../x',
            'https://0x7f.1/r', 'https://2130706433/r', 'https://0177.0.0.1/r', 'https://127.1/r', 'https://ho_st/r', 'https://host:0/r', 'https://host:70000/r', '', ' '];
        foreach ($bad as $in) {
            $this->assertTrue(self::rejects(fn () => RepoInput::gitUrl($in)), 'URL 거부: ' . json_encode($in));
        }
        $this->assertThrows(ApiException::class, fn () => RepoInput::gitUrl('ssh://h/r'), 'invalid_repo_url_scheme');
    }

    public function testBranchPathDepotPortTables(): void
    {
        foreach (['main', 'release/1.2', 'feature-x_y'] as $b) {
            $this->assertSame($b, RepoInput::branch($b));
        }
        $this->assertSame(null, RepoInput::branch(''));
        foreach (['-x', '--upload-pack=x', 'a..b', 'a//b', '/a', 'a/', 'a.lock', 'a b', "a\nb", 'a@{1}', '.hidden', 'a/.b', 'x;y'] as $b) {
            $this->assertTrue(self::rejects(fn () => RepoInput::branch($b)), 'branch ' . json_encode($b));
        }
        $this->assertSame('src/app', RepoInput::subPath('/src/app/'));
        foreach (['..', 'a/../b', 'C:/x', 'a\\b', '-x', "a\0", './a', 'a//b', '*'] as $p) {
            $this->assertTrue(self::rejects(fn () => RepoInput::subPath($p)), 'path ' . json_encode($p));
        }
        $this->assertSame('//depot/proj/...', RepoInput::depotPath('//depot/proj/...'));
        $this->assertSame('//Depot_1/a-b/c.d/...', RepoInput::depotPath('//Depot_1/a-b/c.d/...'));
        foreach (['//depot/proj', '//depot/*/...', '//depot/a@1/...', '//depot/a#2/...', '//depot/../x/...', '/depot/proj/...', '//depot/a b/...', '//depot/a/.../b/...', "//depot/a\n/..."] as $d) {
            $this->assertTrue(self::rejects(fn () => RepoInput::depotPath($d)), 'depot ' . json_encode($d));
        }
        $this->assertSame(['ssl:p4.corp:1666', true], array_values(array_intersect_key(RepoInput::p4Port('SSL:P4.Corp.:1666'), ['port' => 1, 'ssl' => 1])));
        $this->assertSame(['p4.corp:1666', false], array_values(array_intersect_key(RepoInput::p4Port('tcp:p4.corp:1666'), ['port' => 1, 'ssl' => 1])));
        $this->assertSame('10.0.0.5:1666', RepoInput::p4Port('tcp4:10.0.0.5:1666')['port']);
        foreach (['p4.corp', 'rsh:p4d -r /x', 'ssl6:host:1', 'tcp6:host:1', 'host:0', 'host:99999', "host:1\n", 'host :1', '-p x:1', 'ssl:host'] as $p) {
            $this->assertTrue(self::rejects(fn () => RepoInput::p4Port($p)), 'p4port ' . json_encode($p));
        }
        foreach (["tok\nen", "tok\ren", "x\0", '', str_repeat('a', 4001)] as $s) {
            $this->assertTrue(self::rejects(fn () => RepoInput::secret($s)), '비밀 값 줄바꿈·제어 문자 거부 ' . json_encode(substr($s, 0, 10)));
        }
        $this->assertSame('AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01', RepoInput::fingerprint('ab:cd:ef:01:23:45:67:89:ab:cd:ef:01:23:45:67:89:ab:cd:ef:01'));
        $this->assertTrue(self::rejects(fn () => RepoInput::fingerprint('AB:CD')));
        $this->assertTrue(self::rejects(fn () => RepoInput::username('-u x', true)));
        $this->assertTrue(self::rejects(fn () => RepoInput::username(null, true)));
    }

    public function testHostNormalizationAndClassification(): void
    {
        $this->assertSame('ghe.corp', HostPolicy::normalizeHost('GHE.Corp.'));
        $this->assertSame('::1', HostPolicy::normalizeHost('[0:0:0:0:0:0:0:1]'));
        foreach (['0x7f.1', '2130706433', '0177.0.0.1', '127.1', '010.0.0.1', 'a_b', 'exa mple', ''] as $h) {
            $this->assertSame(null, HostPolicy::normalizeHost($h), $h);
        }
        $table = [
            '127.0.0.1' => 'loopback', '127.9.9.9' => 'loopback', '::1' => 'loopback', '::ffff:127.0.0.1' => 'loopback',
            '0.0.0.0' => 'blocked', '::' => 'blocked', '169.254.169.254' => 'blocked', '169.254.1.1' => 'blocked', 'fe80::1' => 'blocked',
            'fd00:ec2::254' => 'blocked', '224.0.0.1' => 'blocked', '255.255.255.255' => 'blocked', '100.100.100.200' => 'blocked', 'ff02::1' => 'blocked',
            '::ffff:169.254.169.254' => 'blocked',
            '10.1.2.3' => 'private', '172.16.0.1' => 'private', '172.31.255.255' => 'private', '192.168.1.1' => 'private', '100.64.0.1' => 'private', 'fd12::1' => 'private',
            '172.32.0.1' => 'public', '8.8.8.8' => 'public', '2001:db8::1' => 'public',
        ];
        foreach ($table as $ip => $class) {
            $this->assertSame($class, HostPolicy::classify($ip), $ip);
        }
    }

    public function testAllowListDecision(): void
    {
        $app = TestApp::make($this->tempDir('hostpol'));
        $db = $app->db();
        $now = '2026-10-02T12:00:00Z';
        foreach ([['ghe.corp', 0, 0], ['git.lan', 8080, 1], ['meta.corp', 0, 0], ['mixed.corp', 0, 0], ['127.0.0.1', 0, 1]] as [$h, $p, $http]) {
            $db->execute('INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES (?, ?, ?, 0, \'\', ?, ?)', [$h, $p, $http, $now, $now]);
        }
        $dns = ['ghe.corp' => ['10.9.8.7'], 'git.lan' => ['192.168.0.10'], 'meta.corp' => ['169.254.169.254'], 'mixed.corp' => ['10.0.0.1', '127.0.0.1'], 'other.corp' => ['10.1.1.1']];
        $pol = new \PJGuardian\Services\Repo\HostPolicy($db, $app->logger, false, static fn (string $h): array => $dns[$h] ?? []);
        $code = static function (callable $fn): string {
            try {
                return 'ok:' . $fn();
            } catch (RepoException $e) {
                return $e->errorCode;
            }
        };
        $this->assertSame('ok:10.9.8.7', $code(fn () => $pol->check('GHE.corp.', 443, 'https')), '사설 주소라도 목록에 있으면 허용(사내 서버)');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('ghe.corp', 443, 'http')), 'HTTP는 allow_http 항목만');
        $this->assertSame('ok:192.168.0.10', $code(fn () => $pol->check('git.lan', 8080, 'http')));
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('git.lan', 8081, 'http')), '포트가 다르면 없는 것');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('other.corp', 443, 'https')), '목록 밖');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('meta.corp', 443, 'https')), '메타데이터 주소로 풀리면 거부');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('mixed.corp', 443, 'https')), '풀린 주소 중 하나라도 loopback이면 거부');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('127.0.0.1', 80, 'http')), 'loopback은 개발 예외 없으면 거부');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $pol->check('0x7f.1', 80, 'http')));
        $dev = new \PJGuardian\Services\Repo\HostPolicy($db, $app->logger, true, static fn (string $h): array => $dns[$h] ?? []);
        $this->assertSame('ok:127.0.0.1', $code(fn () => $dev->check('127.0.0.1', 80, 'http')), '개발 예외 + 등록이면 loopback 허용');
        $this->assertSame('repo_host_not_allowed', $code(fn () => $dev->check('meta.corp', 443, 'https')), '개발 예외여도 메타데이터는 거부');
        $this->assertTrue($pol->githubLinks('github.com', 443));
        $this->assertFalse($pol->githubLinks('ghe.corp', 443));
    }

    public function testSecretBox(): void
    {
        $box = new SecretBox([self::KEY]);
        $e = $box->encrypt('s3cr3t', 'pjg-repo:1:git');
        $this->assertSame([16, 's3cr3t'], [strlen($e['key_id']), $box->decrypt($e['cipher'], $e['nonce'], $e['key_id'], 'pjg-repo:1:git')]);
        $this->assertFalse(str_contains(base64_decode($e['cipher']), 's3cr3t'), '암호문에 평문 없음');
        $e2 = $box->encrypt('s3cr3t', 'pjg-repo:1:git');
        $this->assertTrue($e['nonce'] !== $e2['nonce'] && $e['cipher'] !== $e2['cipher'], '행마다 새 nonce');
        $codeOf = static function (callable $fn): string {
            try {
                $fn();
                return 'ok';
            } catch (RepoException $x) {
                return $x->errorCode;
            }
        };
        $raw = base64_decode($e['cipher']);
        $raw[0] = chr(ord($raw[0]) ^ 1);
        $this->assertSame('repo_secret_unreadable', $codeOf(fn () => $box->decrypt(base64_encode($raw), $e['nonce'], $e['key_id'], 'pjg-repo:1:git')), '변조 감지(GCM)');
        $this->assertSame('repo_secret_unreadable', $codeOf(fn () => $box->decrypt($e['cipher'], $e['nonce'], $e['key_id'], 'pjg-repo:2:git')), '다른 행에 옮겨 붙이면 풀리지 않음(AAD)');
        $this->assertSame('repo_secret_key_missing', $codeOf(fn () => (new SecretBox([null, '']))->encrypt('x', 'a')), '키 없으면 저장 거부');
        $new = base64_encode(str_repeat('k', 32));
        $rotated = new SecretBox([$new, self::KEY]);
        $this->assertSame('s3cr3t', $rotated->decrypt($e['cipher'], $e['nonce'], $e['key_id'], 'pjg-repo:1:git'), '이전 키로 풀림');
        $this->assertSame(SecretBox::keyId(str_repeat('k', 32)), $rotated->encrypt('y', 'a')['key_id'], '새 키로 암호화');
        $this->assertSame('repo_secret_unreadable', $codeOf(fn () => (new SecretBox([$new]))->decrypt($e['cipher'], $e['nonce'], $e['key_id'], 'pjg-repo:1:git')), '키를 잃으면 복구 불가');
        $this->assertThrows(\InvalidArgumentException::class, fn () => new SecretBox(['c2hvcnQ=']), '32바이트');
    }

    public function testErrorClassificationAndMasking(): void
    {
        $git = [
            "fatal: Authentication failed for 'https://h/r.git/'" => 'repo_auth_failed',
            "fatal: could not read Username for 'https://h': terminal prompts disabled" => 'repo_auth_failed',
            "fatal: unable to access 'https://h/r.git/': The requested URL returned error: 403" => 'repo_auth_failed',
            "remote: Repository not found.\nfatal: repository 'https://h/r.git/' not found" => 'repo_not_found',
            'fatal: Remote branch nope not found in upstream origin' => 'repo_not_found',
            "fatal: unable to access 'https://h/': Could not resolve host: h" => 'repo_unreachable',
            "fatal: unable to access 'http://127.0.0.1:1/': Failed to connect to 127.0.0.1 port 1" => 'repo_unreachable',
            "fatal: unable to access 'https://h/': SSL certificate problem: unable to get local issuer certificate" => 'repo_tls_untrusted',
            "fatal: unable to access 'https://h/': The requested URL returned error: 302" => 'repo_host_not_allowed',
            'fatal: transport \'ext\' not allowed' => 'repo_host_not_allowed',
            'something odd' => 'repo_fetch_failed',
        ];
        foreach ($git as $err => $code) {
            $this->assertSame($code, GitClient::classify($err), $err);
        }
        $p4 = [
            'Perforce password (P4PASSWD) invalid or unset.' => 'repo_auth_failed',
            "Connect to server failed; check \$P4PORT.\nTCP connect to h:1666 failed." => 'repo_unreachable',
            "The authenticity of 'h:1666' can't be established" => 'repo_tls_untrusted',
            '******* WARNING P4PORT IDENTIFICATION HAS CHANGED! *******' => 'repo_tls_untrusted',
            '//depot/x/... - no such file(s).' => 'repo_not_found',
            "You don't have permission for this operation." => 'repo_auth_failed',
        ];
        foreach ($p4 as $err => $code) {
            $this->assertSame($code, PerforceClient::classify($err), $err);
        }
        $vp = new VcsProcess(new ProcessRunner([]), TestApp::make($this->tempDir('mask'))->logger, sys_get_temp_dir(), ['tok-SECRET-123']);
        $masked = $vp->mask("Authorization: Basic eDp5\nhttps://user:pw@h/r tok-SECRET-123 " . base64_encode('tok-SECRET-123'));
        $this->assertSame("Authorization: ***\nhttps://***@h/r *** ***", $masked);
        foreach (RepoException::FAILURE_CODES as $c) {
            $this->assertTrue(in_array($c, ErrorCatalog::ANALYSIS, true), "카탈로그 등록: {$c}");
        }
    }

    public function testGitSafetyArgsAndTokenOnlyInEnvironment(): void
    {
        $sb = new VcsSandbox($this->tempDir('gitsafe') . '/sb');
        $c = new GitClient(['git'], new VcsProcess(new ProcessRunner([]), TestApp::make($this->tempDir('gitsafe2'))->logger, sys_get_temp_dir()), 'C:/ca/corp.pem');
        $t = (new GitTarget('https://ghe.corp/o/r.git', 'https', 'ghe.corp', 443, 'main', null, 'bot', 'tok-SECRET-123'))->withPinnedIp('10.9.8.7');
        $args = $c->safetyArgs($t, $sb);
        $kv = [];
        for ($i = 0; $i < count($args); $i += 2) {
            $this->assertSame('-c', $args[$i]);
            [$k, $v] = explode('=', $args[$i + 1], 2);
            $kv[$k] = $v;
        }
        foreach ([
            'core.hooksPath' => $sb->hooks, 'init.templateDir' => $sb->hooks, 'core.fsmonitor' => 'false', 'submodule.recurse' => 'false', 'fetch.recurseSubmodules' => 'false',
            'filter.lfs.smudge' => '', 'filter.lfs.process' => '', 'filter.lfs.required' => 'false', 'protocol.allow' => 'never', 'protocol.https.allow' => 'always',
            'protocol.http.allow' => 'never', 'protocol.file.allow' => 'never', 'protocol.ext.allow' => 'never', 'http.followRedirects' => 'false',
            'http.sslVerify' => 'true', 'credential.helper' => '', 'core.autocrlf' => 'false', 'http.curloptResolve' => 'ghe.corp:443:10.9.8.7', 'http.sslCAInfo' => 'C:/ca/corp.pem',
        ] as $k => $v) {
            $this->assertSame($v, $kv[$k] ?? '(없음)', $k);
        }
        $this->assertNotContains('tok-SECRET', json_encode($args), '명령행에 토큰 없음');
        $env = $c->authEnv($t);
        $this->assertSame('Authorization: Basic ' . base64_encode('bot:tok-SECRET-123'), $env['GIT_CONFIG_VALUE_0']);
        $this->assertSame('http.https://ghe.corp:443/.extraHeader', $env['GIT_CONFIG_KEY_0'], '헤더는 이 저장소 주소에만');
        $this->assertSame('https', $env['GIT_ALLOW_PROTOCOL']);
        $genv = $sb->gitEnv($env);
        foreach (['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_LFS_SKIP_SMUDGE' => '1'] as $k => $v) {
            $this->assertSame($v, $genv[$k], $k);
        }
        $this->assertSame($sb->home, $genv['HOME']);
        $this->assertSame('1', $sb->gitEnv(['GIT_CONFIG_NOSYSTEM' => '0'])['GIT_CONFIG_NOSYSTEM'], '추가 변수로 안전 설정을 덮어쓸 수 없음');
        $sb->remove();
        // 진입점(X09 결과): Windows MinGit 런처, Linux 소스 빌드
        $this->assertSame('windows/cmd/git.exe', ToolRegistry::executableRel('git', 'windows'));
        $this->assertSame('linux/bin/git', ToolRegistry::executableRel('git', 'linux'));
        $this->assertSame('linux/p4', ToolRegistry::executableRel('p4', 'linux'));
    }

    public function testP4MarshalRoundTrip(): void
    {
        $bytes = P4Marshal::encode(['code' => 'stat', 'depotFile' => '//d/a.py', 'n' => 7]) . P4Marshal::encode(['code' => 'text', 'data' => "\0bin\xff"]);
        $all = P4Marshal::all($bytes);
        $this->assertSame([['code' => 'stat', 'depotFile' => '//d/a.py', 'n' => 7], ['code' => 'text', 'data' => "\0bin\xff"]], $all);
        $this->assertThrows(RepoException::class, fn () => P4Marshal::all('{s' . pack('V', 3) . 'ab'), 'repo_fetch_failed');
        $this->assertThrows(RepoException::class, fn () => P4Marshal::all('X'), 'repo_fetch_failed');
        $db = TestDb::migrated();
        $this->assertSame(true, $db->value("SELECT 1 FROM sqlite_master WHERE name = 'project_repo_connections'") !== null);
    }
}
