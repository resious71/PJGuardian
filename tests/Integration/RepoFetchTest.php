<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Repo\RepoImport;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\RepoFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 실제 git(bin/의 MinGit, 읽기·실행만) + 로컬 HTTP 저장소(git-http-backend)로 가져오기(P25). 외부 네트워크 없음.
 * 확인: 연결 테스트(기본 브랜치·지정 브랜치·없는 브랜치·잘못된 토큰·닫힌 포트·리다이렉트·느린 서버 시간 초과), 허용 목록·loopback 개발 예외,
 * 가져오기(얕은 복제 → zip → 안전 해제: .git 없음, 하위 경로, 제외 대상, 출처·커밋 기록, 임시 폴더 정리), 토큰은 헤더로만(URL·명령행 없음),
 * 저장소 안의 LFS·submodule·필터 속성이 아무것도 실행하지 않음, 대상 파일 0개 = repo_empty, 크기 한도.
 */
final class RepoFetchTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='; // 시험용 32바이트

    private App $app;
    private string $root;
    private RepoFixture $fx;
    private int $p;
    private int $admin;

    public function setUp(): void
    {
        $this->root = $this->tempDir('repofetch');
        Fs::ensureDir($this->root . '/work');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'repository' => ['allow_local_dev' => 'true', 'test_timeout' => '20s', 'test_per_minute' => '60']],
            ['encryption_key' => self::KEY]);
        $db = $this->app->db();
        $this->admin = TestDb::user($db, 'admin', 'admin');
        $this->p = TestDb::project($db, 'repo', 'python');
        $this->app->setDiskGuard(new DiskGuard($db, $this->app->uploadSettings(), $this->app->logger, static fn (): float => 1e12, static fn (): float => 2e12));
        $this->fx = new RepoFixture($this->root . '/fx');
    }

    public function tearDown(): void
    {
        $this->fx->release();
    }

    private function skip(): bool
    {
        if (RepoFixture::git() === null || RepoFixture::httpBackend() === null) {
            fwrite(STDOUT, "      [건너뜀] bin/git(MinGit) 없음\n");
            return true;
        }
        return false;
    }

    private function actor(): Actor
    {
        return Actor::fromUser(['id' => $this->admin, 'login_id' => 'admin'], '127.0.0.1');
    }

    private function allow(bool $http = true): void
    {
        $this->app->repoAllowedHosts()->add($this->actor(), $this->admin, ['host' => '127.0.0.1', 'port' => 0, 'allow_http' => $http]);
    }

    /** @param array<string, mixed> $extra */
    private function save(string $url, array $extra = []): void
    {
        $rev = $this->app->repoConnections()->row($this->p)['revision'] ?? null;
        $this->app->repoConnections()->save($this->actor(), $this->admin, $this->p, ['kind' => 'git', 'url' => $url] + $extra + ($rev === null ? [] : ['revision' => (int) $rev]));
    }

    /** 실행 중 분석 행 + 가져오기 */
    private function import(): array
    {
        $db = $this->app->db();
        $conn = $this->app->repoConnections()->row($this->p);
        // 프로젝트당 실행 중 하나(DB 고유 인덱스): 앞 시험 분석은 끝난 것으로
        $db->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-02T12:00:00Z' WHERE project_id = ? AND status = 'running'", [$this->p]);
        $id = TestDb::analysis($db, $this->p, $this->admin, ['status' => 'running', 'source_kind' => 'git', 'source_ref' => $conn['branch'], 'tool' => 'ruff',
            'tool_version' => null, 'config_source' => null, 'config_hash' => null]);
        $row = $db->one('SELECT * FROM analyses WHERE id = ?', [$id]);
        $work = $this->app->repoImport()->run($id, $row);
        return [$id, $work, $db->one('SELECT * FROM analyses WHERE id = ?', [$id])];
    }

    private static function codeOf(callable $fn): string
    {
        try {
            $fn();
            return 'ok';
        } catch (RepoException $e) {
            return $e->errorCode;
        }
    }

    private function importCode(): string
    {
        try {
            $this->import();
            return 'ok';
        } catch (RepoException $e) {
            return $e->errorCode;
        }
    }

    public function testConnectionTestOutcomes(): void
    {
        if ($this->skip()) {
            return;
        }
        $main = $this->fx->commit('app', ['src/a.py' => "import os\n"]);
        $dev = $this->fx->commit('app', ['src/b.py' => "x = 1\n"], 'dev');
        $this->fx->start();
        $svc = $this->app->repoService();
        $this->allow();
        $this->save($this->fx->url('app'));
        $r = $svc->test($this->actor(), $this->admin, $this->p, null);
        $this->assertSame([true, 'main', $main], [$r['ok'], $r['branch'], $r['commit']], json_encode($r));
        $this->assertSame('ok', $this->app->repoConnections()->row($this->p)['last_test_status'], '저장된 연결 테스트 결과 기록');
        $draft = ['kind' => 'git', 'url' => $this->fx->url('app'), 'branch' => 'dev'];
        $r = $svc->test($this->actor(), $this->admin, $this->p, $draft);
        $this->assertSame([true, 'dev', $dev], [$r['ok'], $r['branch'], $r['commit']]);
        $r = $svc->test($this->actor(), $this->admin, $this->p, ['branch' => 'nope'] + $draft);
        $this->assertSame([false, 'repo_not_found', 'branch'], [$r['ok'], $r['code'], $r['what'] ?? null]);
        $r = $svc->test($this->actor(), $this->admin, $this->p, ['url' => $this->fx->url('missing')] + $draft);
        $this->assertSame([false, 'repo_not_found'], [$r['ok'], $r['code']], json_encode($r));
        $r = $svc->test($this->actor(), $this->admin, $this->p, ['url' => $this->fx->url('app', '/redirect')] + $draft);
        $this->assertSame([false, 'repo_host_not_allowed'], [$r['ok'], $r['code']], '리다이렉트를 따라가지 않음 ' . json_encode($r));
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($closed, false), strrpos((string) stream_socket_get_name($closed, false), ':') + 1);
        fclose($closed);
        $r = $svc->test($this->actor(), $this->admin, $this->p, ['url' => 'http://127.0.0.1:' . $port . '/app.git'] + $draft);
        $this->assertSame([false, 'repo_unreachable'], [$r['ok'], $r['code']], json_encode($r));
        // 인증: 서버가 토큰을 요구 — 틀린 토큰·없는 토큰은 repo_auth_failed, 맞는 토큰은 통과(헤더로만 전달)
        $this->fx->start(['auth' => 'bot:s3cr3t-T0KEN']);
        $draft = ['kind' => 'git', 'url' => $this->fx->url('app')];
        $this->assertSame('repo_auth_failed', $svc->test($this->actor(), $this->admin, $this->p, $draft)['code']);
        $this->assertSame('repo_auth_failed', $svc->test($this->actor(), $this->admin, $this->p, $draft + ['username' => 'bot', 'secret' => 'wrong'])['code']);
        $r = $svc->test($this->actor(), $this->admin, $this->p, $draft + ['username' => 'bot', 'secret' => 's3cr3t-T0KEN']);
        $this->assertSame(true, $r['ok'], json_encode($r));
        foreach ($this->fx->requests() as $q) {
            $this->assertNotContains('s3cr3t', $q['uri'], '토큰이 URL에 없음');
        }
        $this->assertTrue(in_array('Basic ' . base64_encode('bot:s3cr3t-T0KEN'), array_column($this->fx->requests(), 'auth'), true), '토큰은 Authorization 헤더로');
        $log = (string) file_get_contents($this->app->logger->fileFor(new \DateTimeImmutable('2026-10-02T12:00:00Z')));
        $this->assertNotContains('s3cr3t-T0KEN', $log, '로그에 토큰 없음');
        $this->assertNotContains('wrong', $log, '틀린 토큰도 로그에 없음');
    }

    public function testHostPolicyIsEnforcedBeforeConnecting(): void
    {
        if ($this->skip()) {
            return;
        }
        $this->fx->commit('app', ['a.py' => "x = 1\n"]);
        $this->fx->start();
        $svc = $this->app->repoService();
        $draft = ['kind' => 'git', 'url' => $this->fx->url('app')];
        $this->assertThrows(\PJGuardian\Http\ApiException::class, fn () => $this->save($this->fx->url('app')), 'repo_host_not_listed');
        $this->allow(false);
        $this->assertThrows(\PJGuardian\Http\ApiException::class, fn () => $this->save($this->fx->url('app')), 'repo_host_not_listed'); // HTTP는 allow_http로만
        $this->app->db()->execute('UPDATE repo_allowed_hosts SET allow_http = 1');
        $this->assertSame(true, $svc->test($this->actor(), $this->admin, $this->p, $draft)['ok']);
        // 개발용 예외를 끄면 loopback은 등록돼 있어도 접속하지 않음
        $strict = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']], ['encryption_key' => self::KEY]);
        $this->assertSame(['ok' => false, 'code' => 'repo_host_not_allowed'], array_intersect_key($this->withDb($strict)->repoService()->test($this->actor(), $this->admin, $this->p, $draft), ['ok' => 1, 'code' => 1]));
        $n = count($this->fx->requests());
        $this->assertSame('repo_host_not_allowed', $this->withDb($strict)->repoService()->test($this->actor(), $this->admin, $this->p, $draft)['code']);
        $this->assertSame($n, count($this->fx->requests()), '거부하면 서버에 요청이 가지 않음');
    }

    private function withDb(App $other): App
    {
        // 같은 DB를 보는 다른 설정의 앱(메모리 DB라 객체를 옮긴다)
        $ref = new \ReflectionProperty(App::class, 'db');
        $ref->setValue($other, $this->app->db());
        return $other;
    }

    public function testImportExtractsLikeUploadAndRecordsSource(): void
    {
        if ($this->skip()) {
            return;
        }
        $commit = $this->fx->commit('app', [
            'pkg/mod.py' => "import os\n", 'pkg/sub/x.py' => "y = 2\n", 'node_modules/skip.py' => "z = 3\n", 'README.md' => "r\n",
            '.gitattributes' => "*.py filter=evil diff=lfs\n*.bin filter=lfs -text\n", '.lfsconfig' => "[lfs]\n\turl = http://127.0.0.1:1/lfs\n",
            '.gitmodules' => "[submodule \"m\"]\n\tpath = m\n\turl = http://127.0.0.1:1/evil.git\n", 'ruff.toml' => "line-length = 10\n",
        ]);
        $this->fx->start();
        $this->allow();
        $this->save($this->fx->url('app'));
        [$id, $work, $row] = $this->import();
        $this->assertTrue(is_file($work . '/pkg/mod.py') && is_file($work . '/pkg/sub/x.py'), '소스 풀림');
        $this->assertSame("import os\n", file_get_contents($work . '/pkg/mod.py'), '필터 속성이 있어도 내용 그대로(필터 실행 없음)');
        $this->assertFalse(is_dir($work . '/.git') || is_dir($work . '/m') || is_file($work . '/node_modules/skip.py'), '.git 없음, submodule 안 가져옴, 기본 제외 적용');
        $this->assertTrue(is_file($work . '/ruff.toml'), '저장소의 설정 파일은 업로드와 같은 정책으로(해제 단계에서는 둔다)');
        $this->assertSame([basename($work), 'main', $commit], [$row['work_dir'], $row['source_ref'], $row['source_revision']]);
        $this->assertTrue($row['fetched_at'] !== null);
        $this->assertFalse(is_dir(RepoImport::stageDir($this->root . '/work', $id)), '가져오기 임시 폴더 삭제');
        $conn = $this->app->repoConnections()->row($this->p);
        $this->assertSame(['ok', $commit], [$conn['last_fetch_status'], $conn['last_fetch_revision']]);
        // 하위 경로만
        $this->save($this->fx->url('app'), ['path' => 'pkg/sub']);
        [, $work2] = $this->import();
        $this->assertSame(['x.py'], Fs::listDir($work2));
        $this->save($this->fx->url('app'), ['path' => 'nope']);
        $this->assertSame('repo_not_found', $this->importCode());
        $this->assertSame('error', $this->app->repoConnections()->row($this->p)['last_fetch_status']);
        // 대상 확장자 0개 → repo_empty(기존 결함 보존은 RepoWorkerTest)
        $this->fx->commit('docs', ['a.md' => "x\n"]);
        $this->save($this->fx->url('docs'), ['path' => null]);
        $this->assertSame('repo_empty', $this->importCode());
    }

    public function testSizeLimitAndTimeout(): void
    {
        if ($this->skip()) {
            return;
        }
        $this->fx->commit('big', ['a.py' => str_repeat("x = 1\n", 300000)]); // 1.8MB(압축하면 작다 — 해제 단계의 실제 바이트로 걸린다)
        $this->fx->start();
        $this->allow();
        $this->save($this->fx->url('big'));
        $small = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'repository' => ['allow_local_dev' => 'true'],
            'extract' => ['max_extracted_size' => '1MB']], ['encryption_key' => self::KEY]);
        $small = $this->withDb($small);
        $small->setDiskGuard(new DiskGuard($this->app->db(), $small->uploadSettings(), $small->logger, static fn (): float => 1e12, static fn (): float => 2e12));
        $db = $this->app->db();
        $id = TestDb::analysis($db, $this->p, $this->admin, ['status' => 'running', 'source_kind' => 'git', 'tool' => 'ruff']);
        $code = self::codeOf(fn () => $small->repoImport()->run($id, $db->one('SELECT * FROM analyses WHERE id = ?', [$id])));
        $this->assertSame('repo_limit_exceeded', $code);
        $this->assertFalse(is_dir(RepoImport::stageDir($this->root . '/work', $id)));
        // 느린 서버 + 짧은 시간 → repo_timeout(가져오기 시간 최소 설정이 60초라 연결 테스트 시간(최소 5초)으로 확인)
        $this->fx->start(['slow' => 12]);
        $quick = $this->withDb(TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'],
            'repository' => ['allow_local_dev' => 'true', 'test_timeout' => '5s', 'test_per_minute' => '60']], ['encryption_key' => self::KEY]));
        $t0 = microtime(true);
        $r = $quick->repoService()->test($this->actor(), $this->admin, $this->p, ['kind' => 'git', 'url' => $this->fx->url('big')]);
        $this->assertSame([false, 'repo_timeout'], [$r['ok'], $r['code']], json_encode($r));
        $this->assertTrue(microtime(true) - $t0 < 11, '시간 초과로 끊김(서버 응답 12초를 기다리지 않음)');
        $this->assertSame([], glob($this->root . '/work/repo_test/*', GLOB_ONLYDIR) ?: [], '연결 테스트 임시 폴더 없음');
    }
}
