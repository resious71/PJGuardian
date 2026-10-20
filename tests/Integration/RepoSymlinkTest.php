<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Archive\SafeUnzip;
use PJGuardian\Services\Archive\UnzipException;
use PJGuardian\Services\Archive\UnzipLimits;
use PJGuardian\Services\Repo\RepoException;
use PJGuardian\Services\Repo\RepoImport;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\RepoFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 저장소 심볼릭 링크 건너뛰기(P26B 설계 14) — 실제 git(bin/의 MinGit) + 로컬 HTTP 저장소.
 * - 링크(파일·폴더·저장소 밖을 가리키는 링크)가 있어도 가져오기 **성공**, 링크는 작업 디렉토리·분석 대상·파일 수에 없음, 링크 대상 내용이 읽히지 않음,
 *   경고(개수·처음 20개 경로) 기록, 분석 API에 source_warnings.
 * - 안전 규칙은 그대로: 대소문자만 다른 이름 충돌은 여전히 repo_unsafe_content, 업로드 zip의 링크는 여전히 forbidden_entry(symlink).
 * - zip 필터(RepoImport::dropSymlinks)는 항목 속성만 보고 내용을 읽지 않는다.
 */
final class RepoSymlinkTest extends TestCase
{
    private const KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private App $app;
    private string $root;
    private RepoFixture $fx;
    private int $p;
    private int $admin;

    public function setUp(): void
    {
        $this->root = $this->tempDir('reposym');
        Fs::ensureDir($this->root . '/work');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work'], 'repository' => ['allow_local_dev' => 'true']], ['encryption_key' => self::KEY]);
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

    private function connect(string $name): void
    {
        $this->fx->start();
        $actor = Actor::fromUser(['id' => $this->admin, 'login_id' => 'admin'], '127.0.0.1');
        $this->app->repoAllowedHosts()->add($actor, $this->admin, ['host' => '127.0.0.1', 'allow_http' => true]);
        $this->app->repoConnections()->save($actor, $this->admin, $this->p, ['kind' => 'git', 'url' => $this->fx->url($name)]);
    }

    /** @return array{0:int, 1:string, 2:array<string,mixed>} */
    private function import(): array
    {
        $db = $this->app->db();
        $db->execute("UPDATE analyses SET status = 'cancelled', finished_at = '2026-10-02T12:00:00Z' WHERE project_id = ? AND status = 'running'", [$this->p]);
        $id = TestDb::analysis($db, $this->p, $this->admin, ['status' => 'running', 'source_kind' => 'git', 'tool' => 'ruff', 'tool_version' => null, 'config_source' => null, 'config_hash' => null]);
        $work = $this->app->repoImport()->run($id, $db->one('SELECT * FROM analyses WHERE id = ?', [$id]));
        return [$id, $work, $db->one('SELECT * FROM analyses WHERE id = ?', [$id])];
    }

    /** @return list<string> 작업 디렉토리 안의 모든 항목(상대 경로) */
    private static function tree(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1)) . ($f->isDir() ? '/' : '');
        }
        sort($out);
        return $out;
    }

    public function testSymlinksAreSkippedWithWarningAndTargetsNeverRead(): void
    {
        if ($this->skip()) {
            return;
        }
        // 저장소 밖의 비밀 파일(링크가 가리키는 대상) — 가져온 소스 어디에도 내용이 나오면 안 된다
        $secret = $this->root . '/outside-secret.txt';
        file_put_contents($secret, 'TOP-SECRET-OUTSIDE');
        $this->fx->commit('app', ['app/main.py' => "import os\n", 'app/lib/util.py' => "x = 1\n"]);
        $this->fx->commitIndex('app', [
            ['120000', 'app/link_file.py', 'main.py'],                                      // 저장소 안 파일을 가리킴
            ['120000', 'app/link_dir', 'lib'],                                              // 폴더를 가리킴
            ['120000', 'app/outside.py', str_replace('\\', '/', $secret)],                   // 저장소 밖 절대 경로
            ['120000', 'app/up.py', '../../../../outside-secret.txt'],                      // 저장소 밖 상대 경로
        ]);
        $this->connect('app');
        [$id, $work, $row] = $this->import();
        $this->assertSame(['app/', 'app/lib/', 'app/lib/util.py', 'app/main.py'], self::tree($work), '링크는 파일로도 폴더로도 만들지 않음');
        foreach (self::tree($work) as $rel) {
            if (!str_ends_with($rel, '/')) {
                $this->assertNotContains('TOP-SECRET', (string) file_get_contents($work . '/' . $rel), '링크 대상 내용이 읽히지 않음');
            }
        }
        $w = json_decode((string) $row['source_warnings'], true);
        $this->assertSame([['code' => 'symlinks_skipped', 'count' => 4, 'paths' => ['app/link_dir', 'app/link_file.py', 'app/outside.py', 'app/up.py']]], $w);
        $api = $this->app->analyses()->get($id);
        $this->assertSame(4, $api['source_warnings'][0]['count']);
        $this->assertSame([], is_dir($this->root . '/work/fetch') ? Fs::listDir($this->root . '/work/fetch') : [], '임시 폴더 정리');
        // 경고 경로는 처음 20개만(개수는 전부)
        $many = [];
        for ($i = 0; $i < 25; $i++) {
            $many[] = ['120000', sprintf('links/l%02d.py', $i), 'app/main.py'];
        }
        $this->fx->commitIndex('app', $many);
        [, $work2, $row2] = $this->import();
        $w2 = json_decode((string) $row2['source_warnings'], true)[0];
        $this->assertSame([29, 20], [$w2['count'], count($w2['paths'])]);
        $this->assertFalse(is_dir($work2 . '/links'), '링크만 있던 폴더는 만들지 않음');
    }

    public function testSafetyRulesStillFail(): void
    {
        if ($this->skip()) {
            return;
        }
        // 대소문자만 다른 이름 충돌(링크 아님) → 여전히 실패
        $this->fx->commit('case', ['a/Main.py' => "x = 1\n"]);
        $this->fx->commitIndex('case', [['100644', 'a/main.py', "y = 2\n"], ['120000', 'a/l.py', 'Main.py']]);
        $this->connect('case');
        try {
            $this->import();
            $this->assertTrue(false, '대소문자 충돌인데 성공');
        } catch (RepoException $e) {
            $this->assertSame('repo_unsafe_content', $e->errorCode);
        }
        // 업로드 zip의 링크 규칙은 그대로(SafeUnzip이 forbidden_entry/symlink로 거부)
        $zip = $this->root . '/up.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('a.py', "x = 1\n");
        $z->addFromString('l.py', 'a.py');
        $z->setExternalAttributesName('l.py', \ZipArchive::OPSYS_UNIX, (0120777) << 16);
        $z->close();
        try {
            (new SafeUnzip(new UnzipLimits(1 << 20, 1 << 20, 100), new ExcludeMatcher([], [])))->extract($zip, $this->root . '/upx', false);
            $this->assertTrue(false, '업로드 링크가 통과');
        } catch (UnzipException $e) {
            $this->assertSame(['forbidden_entry', 'symlink'], [$e->errorCode, $e->detail['reason'] ?? null]);
        }
    }

    public function testDropSymlinksReadsAttributesOnly(): void
    {
        $zip = $this->root . '/d.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('keep.py', "x = 1\n");
        $z->addEmptyDir('dir');
        $z->addEmptyDir('used');
        $z->addFromString('used/a.py', "y = 2\n");
        $z->addFromString('link.py', '/etc/passwd');
        $z->setExternalAttributesName('link.py', \ZipArchive::OPSYS_UNIX, (0120777) << 16);
        $z->addFromString('win.lnk.py', 'C:/x');
        $z->setExternalAttributesName('win.lnk.py', \ZipArchive::OPSYS_WINDOWS_NTFS, 0x400);
        $z->close();
        $this->assertSame(['count' => 2, 'paths' => ['link.py', 'win.lnk.py']], RepoImport::dropSymlinks($zip));
        $z = new \ZipArchive();
        $z->open($zip);
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }
        $z->close();
        $this->assertSame(['keep.py', 'used/', 'used/a.py'], $names, '링크와 함께 남은 파일이 없는 폴더 항목도 뺀다(빈 폴더를 만들지 않음), 파일이 있는 폴더는 그대로');
        $this->assertSame(['count' => 0, 'paths' => []], RepoImport::dropSymlinks($zip), '링크가 없으면 그대로');
    }
}
