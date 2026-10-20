<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * Perforce 가져오기(P25) — 이 PC에 p4d가 없어 **가짜 p4**(tests/Support/fake_p4.php)로 인자·환경 변수·종료 코드·-G 출력 처리만 확인한다.
 * 실제 bin/p4는 -V와 접속 실패만 확인(마지막 시험). 확인: 티켓은 P4PASSWD, 비밀번호는 login 표준 입력(명령행에 없음), SSL 지문 등록·불일치·미등록
 * (서버 지문을 결과로), 최신 changelist·print로 파일(삭제·심볼릭 링크 제외), P4CONFIG 비움·임시 P4TICKETS/P4TRUST/P4ENVIRO(끝나면 삭제),
 * 판정한 주소로 P4PORT 고정, client(workspace)를 만들지 않음(쓰기 명령 없음), 경로 없음·인증 실패·접속 실패 코드.
 */
final class RepoPerforceTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
    private const FP = 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01';

    private App $app;
    private string $root;
    private string $state;
    private string $log;
    private int $p;
    private int $admin;

    public function setUp(): void
    {
        $this->root = $this->tempDir('repop4');
        Fs::ensureDir($this->root . '/work');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'repository' => ['test_per_minute' => '60']], ['encryption_key' => self::KEY]);
        $this->state = $this->root . '/fake_p4.json';
        $this->log = $this->root . '/p4.log';
        $this->writeState([]);
        // 가짜 p4 명령, 이름 풀이: p4.example.test → 사설 주소(사내 서버처럼)
        $this->app->setRepoTesting(['p4' => [PHP_BINARY, PJG_ROOT . '/tests/Support/fake_p4.php', $this->state]], static fn (string $h): array => $h === 'p4.example.test' ? ['10.20.30.40'] : []);
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        $this->p = TestDb::project($db, 'p4proj', 'python');
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
        $this->app->repoAllowedHosts()->add($this->actor(), $this->admin, ['host' => 'p4.example.test', 'port' => 1666]);
    }

    /** @param array<string, mixed> $over */
    private function writeState(array $over): void
    {
        file_put_contents($this->state, json_encode($over + [
            'password' => 'pw-SECRET-1', 'ticket' => 'TICKET-ABCDEF123456', 'fingerprint' => self::FP, 'change' => 4321, 'depot' => '//depot/proj/',
            'files' => ['main.py' => "import os\n", 'pkg/a b.py' => "x = 1\n", 'link.lnk' => 'target'], 'deleted' => ['old.py'], 'log' => $this->log,
        ]));
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->admin, 'login_id' => 'admin'], '127.0.0.1');
    }

    /** @param array<string, mixed> $in */
    private function save(array $in): void
    {
        $rev = $this->app->repoConnections()->row($this->p)['revision'] ?? null;
        $this->app->repoConnections()->save($this->actor(), $this->admin, $this->p, $in + ['kind' => 'perforce', 'port' => 'p4.example.test:1666',
            'path' => '//depot/proj/...', 'username' => 'reader'] + ($rev === null ? [] : ['revision' => (int) $rev]));
    }

    /** @return list<array<string, mixed>> */
    private function calls(): array
    {
        return array_map(static fn (string $l): array => json_decode($l, true), file($this->log) ?: []);
    }

    private function run(): array
    {
        $db = $this->app->db();
        $db->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-02T12:00:00Z' WHERE project_id = ? AND status = 'running'", [$this->p]);
        $id = TestDb::analysis($db, $this->p, $this->admin, ['status' => 'running', 'source_kind' => 'perforce', 'tool' => 'ruff']);
        $work = $this->app->repoImport()->run($id, $db->one('SELECT * FROM analyses WHERE id = ?', [$id]));
        return [$id, $work, $db->one('SELECT * FROM analyses WHERE id = ?', [$id])];
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
            return 'ok';
        } catch (RepoException $e) {
            return $e->errorCode;
        }
    }

    public function testTicketFetchWritesFilesAndRecordsChangelist(): void
    {
        $this->save(['secret' => 'TICKET-ABCDEF123456', 'secret_kind' => 'ticket']);
        [$id, $work, $row] = $this->run();
        $this->assertSame(['main.py', 'pkg'], Fs::listDir($work));
        $this->assertSame("x = 1\n", file_get_contents($work . '/pkg/a b.py'));
        $this->assertFalse(is_file($work . '/link.lnk') || is_file($work . '/old.py'), '심볼릭 링크·삭제된 파일은 쓰지 않음');
        // P26B: 건너뛴 심볼릭 링크는 경고로 남는다(링크 대상 내용 'target'은 어디에도 쓰지 않음)
        $this->assertSame([['code' => 'symlinks_skipped', 'count' => 1, 'paths' => ['link.lnk']]], json_decode((string) $row['source_warnings'], true));
        $this->assertSame(['//depot/proj/...', '4321'], [$row['source_ref'], $row['source_revision']]);
        $calls = $this->calls();
        $this->assertSame([['-G', 'changes', '-m', '1', '-s', 'submitted', '//depot/proj/...'], ['-G', 'print', '//depot/proj/...@4321']], array_column($calls, 'args'),
            '쓰기 명령·client 생성 없이 changes·print만');
        foreach ($calls as $c) {
            $this->assertSame(['10.20.30.40:1666', 'reader', 'pjguardian-no-p4config', true], [$c['P4PORT'], $c['P4USER'], $c['P4CONFIG'], $c['has_P4PASSWD']],
                '판정한 주소로 고정, P4CONFIG 무력화(없는 파일 이름 — 빈 값은 Windows에서 사라짐), 티켓은 환경 변수');
            $this->assertNotContains('TICKET-ABCDEF', json_encode($c['args']), '명령행에 티켓 없음');
            $this->assertTrue(str_contains((string) $c['P4TICKETS'], '/fetch/' . $id . '/') && str_contains((string) $c['P4TRUST'], '/fetch/' . $id . '/'), '설정·티켓 파일은 임시 폴더');
            $this->assertSame(1, preg_match('/System32$/i', (string) $c['PATH']), 'PATH 최소');
        }
        $this->assertFalse(is_dir($this->root . '/work/fetch/' . $id), '임시 폴더(P4TICKETS·P4TRUST 포함) 삭제');
    }

    public function testPasswordGoesThroughLoginStdinOnly(): void
    {
        $this->save(['secret' => 'pw-SECRET-1', 'secret_kind' => 'password']);
        $this->run();
        $calls = $this->calls();
        $this->assertSame(['login'], $calls[0]['args']);
        $this->assertFalse($calls[0]['has_P4PASSWD'], '비밀번호는 환경 변수·명령행이 아니라 login 표준 입력으로');
        $this->assertNotContains('pw-SECRET', (string) file_get_contents($this->log));
        $this->save(['secret' => 'wrong-password', 'secret_kind' => 'password']);
        $this->assertSame('repo_auth_failed', $this->code(fn () => $this->run()));
        $this->assertSame('error', $this->app->repoConnections()->row($this->p)['last_fetch_status']);
        $logText = (string) file_get_contents($this->app->logger->fileFor(new \DateTimeImmutable('2026-10-02T12:00:00Z')));
        $this->assertNotContains('wrong-password', $logText);
        $this->assertNotContains('pw-SECRET', $logText);
    }

    public function testSslFingerprintMustMatchAndIsShownWhenMissing(): void
    {
        $this->save(['port' => 'ssl:p4.example.test:1666', 'secret' => 'TICKET-ABCDEF123456', 'secret_kind' => 'ticket']);
        $r = $this->app->repoService()->test($this->actor(), $this->admin, $this->p, null);
        $this->assertSame([false, 'repo_tls_untrusted', self::FP], [$r['ok'], $r['code'], $r['fingerprint'] ?? null], '지문 미등록 → 서버 지문을 보여 주고 신뢰하지 않음');
        $this->save(['port' => 'ssl:p4.example.test:1666', 'fingerprint' => 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:99']);
        $this->assertSame('repo_tls_untrusted', $this->code(fn () => $this->run()), '다른 지문은 거부');
        $this->save(['port' => 'ssl:p4.example.test:1666', 'fingerprint' => strtolower(self::FP)]);
        $r = $this->app->repoService()->test($this->actor(), $this->admin, $this->p, null);
        $this->assertSame([true, 4321], [$r['ok'], $r['change'] ?? null], json_encode($r));
        $trust = array_values(array_filter($this->calls(), static fn (array $c): bool => $c['args'][0] === 'trust'));
        $this->assertSame(['trust', '-i', self::FP], end($trust)['args'], '입력한 지문만 등록(자동 신뢰 없음)');
        $this->assertSame('ssl:10.20.30.40:1666', end($trust)['P4PORT']);
    }

    public function testErrorsAndHostPolicy(): void
    {
        $this->save(['secret' => 'TICKET-ABCDEF123456', 'secret_kind' => 'ticket', 'path' => '//depot/other/...']);
        $this->assertSame('repo_not_found', $this->code(fn () => $this->run()));
        $this->writeState(['unreachable' => true]);
        $this->assertSame('repo_unreachable', $this->code(fn () => $this->run()));
        $this->writeState(['files' => []]);
        $this->save(['path' => '//depot/proj/...']);
        $this->assertSame('repo_empty', $this->code(fn () => $this->run()));
        $this->writeState(['files' => ['README.txt' => 'x']]);
        $this->assertSame('repo_empty', $this->code(fn () => $this->run()), '대상 확장자 0개');
        // P26B: 경로 이탈 후보는 건너뛰지 않고 실패(안전 규칙), 링크만 있는 저장소는 대상 0개
        $this->writeState(['files' => ['main.py' => "x = 1\n", '../escape.py' => "y = 2\n"]]);
        $this->assertSame('repo_unsafe_content', $this->code(fn () => $this->run()), '경로 이탈');
        $this->writeState(['files' => ['only.lnk' => 'main.py']]);
        $this->assertSame('repo_empty', $this->code(fn () => $this->run()), '링크만 있으면 대상 0개');
        // 이름이 금지 주소로 풀리면 접속하지 않음(목록에 있어도)
        $this->app->setRepoTesting(['p4' => [PHP_BINARY, PJG_ROOT . '/tests/Support/fake_p4.php', $this->state]], static fn (): array => ['169.254.169.254']);
        $n = count($this->calls());
        $this->assertSame('repo_host_not_allowed', $this->code(fn () => $this->run()));
        $this->assertSame($n, count($this->calls()), 'p4를 실행하지 않음');
        $this->app->setRepoTesting(['p4' => [PHP_BINARY, PJG_ROOT . '/tests/Support/fake_p4.php', $this->state]], static fn (): array => ['10.0.0.1', '127.0.0.1']);
        $this->assertSame('repo_host_not_allowed', $this->code(fn () => $this->run()), '풀린 주소 중 하나라도 loopback이면 거부(개발 예외 꺼짐)');
    }

    /** @needs-bin bin/p4 실행 파일 */
    public function testRealP4BinaryVersionAndRefusedConnection(): void
    {
        $exe = PJG_ROOT . '/bin/p4/2026.1.0/' . (PHP_OS_FAMILY === 'Windows' ? 'windows/p4.exe' : 'linux/p4');
        if (!is_file($exe)) {
            fwrite(STDOUT, "      [건너뜀] bin/p4 없음\n");
            return;
        }
        $this->app->setRepoTesting([], static fn (string $h): array => $h === 'p4.example.test' ? ['127.0.0.1'] : []);
        $sel = $this->app->toolRegistry()->select('p4');
        $this->assertTrue($sel !== null && str_ends_with(str_replace('\\', '/', $sel->executable), '/p4.exe'), '체크섬·스모크(p4 -V) 통과한 bin/p4 선택');
        $row = $this->app->db()->one("SELECT smoke_ok, smoke_message, checksum_ok FROM tool_versions WHERE tool = 'p4'");
        $this->assertSame([1, 1], [(int) $row['checksum_ok'], (int) $row['smoke_ok']]);
        $this->assertContains('P4/NTX64/2026.1/', (string) $row['smoke_message']);
        // 실제 p4로 닫힌 로컬 포트에 접속 → repo_unreachable(개발 예외로 loopback 허용한 앱)
        $dev = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'repository' => ['allow_local_dev' => 'true', 'test_per_minute' => '60']], ['encryption_key' => self::KEY]);
        (new \ReflectionProperty(App::class, 'db'))->setValue($dev, $this->app->db());
        $dev->setRepoTesting([], static fn (): array => ['127.0.0.1']);
        $this->app->db()->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('127.0.0.1', 1, 0, 0, '', '2026-10-02T12:00:00Z', '2026-10-02T12:00:00Z')");
        $r = $dev->repoService()->test($this->actor(), $this->admin, $this->p, ['kind' => 'perforce', 'port' => '127.0.0.1:1', 'path' => '//depot/proj/...', 'username' => 'reader']);
        $this->assertSame([false, 'repo_unreachable'], [$r['ok'], $r['code']], json_encode($r));
    }
}
