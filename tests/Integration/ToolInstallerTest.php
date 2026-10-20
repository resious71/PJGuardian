<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Tools\NodeRuntime;
use PJGuardian\Services\Tools\SelectedTool;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Config;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\Support\ZipBuilder;
use PJGuardian\Tests\TestCase;

/**
 * 서버 CLI 도구 설치·삭제(P20A, ToolInstaller · tools/tools_ctl.php install|remove). 임시 bin/과 가짜 스모크로 시험한다.
 * install: 정상, 레이아웃 불량, 경로 이탈, 버전 이름, 이미 있는 버전 거부, 체크섬·스모크 실패 시 bin/에 흔적 없음, 해시 불일치·파일 변경 거부.
 * remove: 고정·사용 중·마지막 유효 버전 거부, 정상 삭제(행은 이력으로, 선택에서 빠짐).
 */
final class ToolInstallerTest extends TestCase
{
    private App $app;
    private string $root;
    private string $bin;
    /** @var array<string, bool> 버전별 스모크 결과(없으면 통과) */
    private array $smoke = [];

    public function setUp(): void
    {
        $this->root = $this->tempDir('tinst');
        $this->bin = $this->root . '/bin';
        foreach (['bin', 'work', 'logs', 'db'] as $d) {
            mkdir($this->root . '/' . $d);
        }
        $this->app = $this->makeApp(TestDb::migrated());
        TestDb::user($this->app->db(), 'admin', 'admin');
    }

    private function overrides(): array
    {
        return ['paths' => ['bin_dir' => $this->bin, 'work_dir' => $this->root . '/work', 'logs_dir' => $this->root . '/logs',
            'db_file' => $this->root . '/db/t.sqlite'], 'auth' => ['bcrypt_cost' => '4']];
    }

    private function makeApp(\PJGuardian\Support\Db $db): App
    {
        $app = TestApp::make($this->root . '/logs', $this->overrides());
        $app = new App(PJG_ROOT, $app->config, $app->clock, $app->logger, $app->paths, $db);
        $app->setToolRegistry(new ToolRegistry($db, $app->clock, $app->logger, $this->bin, $this->root . '/work/tools', new NodeRuntime(null, '20.19.0'),
            fn (SelectedTool $t): array => ['ok' => $this->smoke[$t->version] ?? true, 'message' => ($this->smoke[$t->version] ?? true) ? '시료 통과' : '기대 결함 수 불일치']));
        return $app;
    }

    /** @param array<string, string> $files 상대 경로 → 내용. $manifest=false면 SHA256SUMS를 만들지 않음, 'bad'면 틀린 해시 */
    private function package(string $prefix, array $files, bool|string $manifest = true, array $extra = []): string
    {
        $zipPath = $this->root . '/pkg-' . bin2hex(random_bytes(3)) . '.zip';
        $z = new ZipBuilder($zipPath);
        $lines = [];
        foreach ($files as $rel => $data) {
            $z->add($prefix . $rel, $data);
            $lines[] = ($manifest === 'bad' ? str_repeat('0', 64) : hash('sha256', $data)) . ' *' . $rel;
        }
        if ($manifest !== false) {
            $z->add($prefix . 'SHA256SUMS', implode("\n", $lines) . "\n");
        }
        foreach ($extra as $name => $data) {
            $z->add($name, $data);
        }
        return $z->close();
    }

    private function ruffFiles(string $version): array
    {
        return [ToolRegistry::executableRel('ruff') => 'fake-ruff-' . $version, 'LICENSE' => 'MIT'];
    }

    /** 스테이징 행 + package.zip(웹이 올린 것과 같은 상태) */
    private function stage(string $zip, string $tool = 'ruff', ?string $expected = null): string
    {
        $id = bin2hex(random_bytes(16));
        $dir = $this->root . '/work/tool_staging/' . $id;
        mkdir($dir, 0700, true);
        copy($zip, $dir . '/package.zip');
        $uid = (int) $this->app->db()->value("SELECT id FROM users WHERE login_id = 'admin'");
        $now = '2026-10-04T00:00:00Z';
        $this->app->db()->execute(
            'INSERT INTO tool_staging (id, tool, file_name, total_size, received_bytes, chunk_size, total_chunks, status, sha256, expected_sha256, uploaded_by, created_at, updated_at)'
            . " VALUES (?, ?, 'p.zip', ?, ?, ?, 1, 'staged', ?, ?, ?, ?, ?)",
            [$id, $tool, filesize($zip), filesize($zip), filesize($zip), hash_file('sha256', $zip), $expected, $uid, $now, $now],
        );
        return $id;
    }

    private function snapshot(): array
    {
        $out = [];
        if (!is_dir($this->bin)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->bin, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($this->bin)));
        }
        sort($out);
        return $out;
    }

    public function testInstallsValidPackageAndVerifies(): void
    {
        $id = $this->stage($this->package('ruff/1.5.0/', $this->ruffFiles('1.5.0')));
        $r = $this->app->toolInstaller()->install($id);
        $this->assertSame([true, 'installed', 'ruff', '1.5.0', 'active'], [$r['ok'], $r['code'], $r['tool'], $r['version'], $r['status']], $r['message']);
        $this->assertTrue(is_file($this->bin . '/ruff/1.5.0/' . ToolRegistry::executableRel('ruff')));
        $this->assertSame('1.5.0', $this->app->toolRegistry()->select('ruff')?->version);
        $row = $this->app->db()->one('SELECT status, result FROM tool_staging WHERE id = ?', [$id]);
        $this->assertSame('installed', $row['status']);
        $this->assertSame(true, json_decode((string) $row['result'], true)['ok']);
        $this->assertSame(true, json_decode((string) file_get_contents($this->root . "/work/tool_staging/{$id}/result.json"), true)['ok'], '결과 파일(화면이 읽음)');
        $this->assertFalse(is_file($this->root . "/work/tool_staging/{$id}/package.zip"), '설치가 끝난 zip은 지움');
        $this->assertSame([], array_values(array_filter(Fs::listDir($this->root . '/work'), static fn (string $n): bool => str_starts_with($n, 'ti'))), '해제 임시 폴더 없음');
        $again = $this->app->toolInstaller()->install($id);
        $this->assertSame('staging_not_staged', $again['code'], '같은 스테이징을 두 번 설치하지 않음');
    }

    public function testRejectsBadPackagesWithoutTouchingBin(): void
    {
        $this->app->toolInstaller()->install($this->stage($this->package('ruff/1.0.0/', $this->ruffFiles('1.0.0'))));
        $before = $this->snapshot();
        $cases = [
            'bad_layout(최상위 폴더 이름)' => [$this->package('ruff-1.6.0/', $this->ruffFiles('1.6.0')), 'ruff', 'bad_layout'],
            'bad_layout(다른 도구)' => [$this->package('eslint/1.6.0/', $this->ruffFiles('1.6.0')), 'ruff', 'bad_layout'],
            'bad_layout(최상위 여분 파일)' => [$this->package('ruff/1.6.0/', $this->ruffFiles('1.6.0'), true, ['README.txt' => 'x']), 'ruff', 'bad_layout'],
            'bad_version' => [$this->package('ruff/latest/', $this->ruffFiles('x')), 'ruff', 'bad_version'],
            'no manifest' => [$this->package('ruff/1.6.0/', $this->ruffFiles('1.6.0'), false), 'ruff', 'bad_layout'],
            'no executable' => [$this->package('ruff/1.6.0/', ['LICENSE' => 'MIT']), 'ruff', 'bad_layout'],
            'checksum' => [$this->package('ruff/1.6.0/', $this->ruffFiles('1.6.0'), 'bad'), 'ruff', 'checksum_failed'],
            'path escape' => [$this->package('ruff/1.6.0/', $this->ruffFiles('1.6.0'), true, ['ruff/1.6.0/../../../evil.txt' => 'pwn']), 'ruff', 'unzip_forbidden_entry'],
            'absolute path' => [$this->package('ruff/1.6.0/', $this->ruffFiles('1.6.0'), true, ['/etc/evil.txt' => 'pwn']), 'ruff', 'unzip_forbidden_entry'],
            'existing version' => [$this->package('ruff/1.0.0/', ['LICENSE' => 'other'] + $this->ruffFiles('1.0.0-x')), 'ruff', 'version_exists'],
        ];
        foreach ($cases as $label => [$zip, $tool, $code]) {
            $id = $this->stage($zip, $tool);
            $r = $this->app->toolInstaller()->install($id);
            $this->assertSame([false, $code], [$r['ok'], $r['code']], "{$label}: {$r['message']}");
            $this->assertSame($before, $this->snapshot(), "{$label}: bin/에 흔적 없음");
            $this->assertSame('install_failed', $this->app->db()->value('SELECT status FROM tool_staging WHERE id = ?', [$id]), $label);
            $this->assertFalse(is_file($this->root . '/evil.txt') || is_file($this->root . '/work/evil.txt'), "{$label}: 밖에 쓰지 않음");
        }
        $this->assertSame('fake-ruff-1.0.0', file_get_contents($this->bin . '/ruff/1.0.0/' . ToolRegistry::executableRel('ruff')), '이미 있는 버전을 덮어쓰지 않음');
    }

    public function testSmokeFailureLeavesNoTrace(): void
    {
        $this->smoke['1.7.0'] = false;
        $id = $this->stage($this->package('ruff/1.7.0/', $this->ruffFiles('1.7.0')));
        $r = $this->app->toolInstaller()->install($id);
        $this->assertSame([false, 'verify_failed', 'invalid'], [$r['ok'], $r['code'], $r['status']]);
        $this->assertContains('기대 결함 수 불일치', $r['message']);
        $this->assertFalse(file_exists($this->bin . '/ruff/1.7.0'), '스모크 실패 → bin/에서 다시 뺌');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM tool_versions WHERE version = '1.7.0'"), '기록도 지움');
    }

    public function testRefusesHashMismatchAndChangedPackage(): void
    {
        $zip = $this->package('ruff/1.8.0/', $this->ruffFiles('1.8.0'));
        $id = $this->stage($zip, 'ruff', str_repeat('b', 64));
        $this->assertSame('hash_mismatch', $this->app->toolInstaller()->install($id)['code']);
        $this->assertSame('staged', $this->app->db()->value('SELECT status FROM tool_staging WHERE id = ?', [$id]), '거부만 하고 상태는 그대로');
        $id2 = $this->stage($zip);
        file_put_contents($this->root . "/work/tool_staging/{$id2}/package.zip", 'tampered', FILE_APPEND);
        $this->assertSame('package_changed', $this->app->toolInstaller()->install($id2)['code'], '올린 뒤 바뀐 파일 거부');
        $this->assertSame('staging_not_found', $this->app->toolInstaller()->install(str_repeat('0', 32))['code']);
        $this->assertSame([], $this->snapshot());
    }

    public function testRemoveRules(): void
    {
        foreach (['2.0.0', '2.1.0'] as $v) {
            $this->assertTrue($this->app->toolInstaller()->install($this->stage($this->package("ruff/{$v}/", $this->ruffFiles($v))))['ok']);
        }
        $reg = $this->app->toolRegistry();
        $inst = $this->app->toolInstaller();
        // 고정된 버전
        $this->assertTrue($reg->pin('ruff', '2.0.0')['ok']);
        $this->assertSame('pinned', $inst->remove('ruff', '2.0.0')['code']);
        $reg->unpin('ruff');
        // 실행 중인 분석이 쓰는 버전(기록된 버전, 또는 아직 기록 전이면 지금 선택될 버전)
        $db = $this->app->db();
        $pid = TestDb::project($db, 'p', 'python');
        $uid = (int) $db->value("SELECT id FROM users WHERE login_id = 'admin'");
        $aid = TestDb::analysis($db, $pid, $uid, ['status' => 'running', 'tool_version' => '2.0.0']);
        $this->assertSame('in_use', $inst->remove('ruff', '2.0.0')['code']);
        $db->execute('UPDATE analyses SET tool_version = NULL WHERE id = ?', [$aid]);
        $this->assertSame('in_use', $inst->remove('ruff', '2.1.0')['code'], '기록 전: 지금 선택될 버전(2.1.0)은 거부');
        $db->execute("UPDATE analyses SET status = 'succeeded', finished_at = started_at WHERE id = ?", [$aid]);
        // 정상 삭제
        $r = $inst->remove('ruff', '2.0.0');
        $this->assertSame([true, 'removed'], [$r['ok'], $r['code']], $r['message']);
        $this->assertFalse(file_exists($this->bin . '/ruff/2.0.0'));
        $this->assertSame([], Fs::listDir($this->bin . '/.trash'), '휴지통 비움');
        $row = $db->one("SELECT removed_at, removal_requested_at FROM tool_versions WHERE version = '2.0.0'");
        $this->assertTrue($row['removed_at'] !== null && $row['removal_requested_at'] !== null, '행은 이력으로 남김');
        $listed = array_column($reg->list('ruff'), null, 'version');
        $this->assertSame([false, true], [$listed['2.0.0']['on_disk'], $listed['2.1.0']['on_disk']]);
        // 마지막 유효 버전
        $this->assertSame('last_valid', $inst->remove('ruff', '2.1.0')['code']);
        $this->assertSame('not_installed', $inst->remove('ruff', '2.0.0')['code']);
        $this->assertSame('2.1.0', $reg->select('ruff')?->version);
        // 지운 버전을 다시 설치하면 새로 검증(이력 행을 지움)
        $r = $inst->install($this->stage($this->package('ruff/2.0.0/', $this->ruffFiles('2.0.0'))));
        $this->assertSame([true, 'active'], [$r['ok'], $r['status']]);
        $this->assertSame(null, $db->value("SELECT removed_at FROM tool_versions WHERE version = '2.0.0'"));
        // 쓸 수 없는 버전(차단)은 마지막 유효 버전 검사와 무관하게 지울 수 있다
        $reg->block('ruff', '2.0.0');
        $this->assertSame('removed', $inst->remove('ruff', '2.0.0')['code']);
    }

    public function testCliCommands(): void
    {
        $ini = TestApp::writeIni($this->root . '/config.ini', $this->overrides());
        TestDb::migrated($this->root . '/db/t.sqlite');
        $app = App::create(PJG_ROOT, Config::load($ini));
        TestDb::user($app->db(), 'admin', 'admin');
        $this->app = $app; // stage()가 같은 DB에 쓰게
        $run = static function (array $args) use ($ini): array {
            $r = (new ProcessRunner([], [PHP_BINARY]))->run([PHP_BINARY, PJG_ROOT . '/tools/tools_ctl.php', '--config=' . $ini, ...$args], new ProcessOptions(timeoutSeconds: 60));
            $out = $r->stdout() . $r->stderr();
            $code = $r->exitCode;
            $r->cleanup();
            return [$code, $out];
        };
        [$code, $out] = $run(['install']);
        $this->assertSame(2, $code, '사용법 오류');
        [$code, $out] = $run(['install', '--staging=' . str_repeat('0', 32)]);
        $this->assertSame(1, $code);
        $this->assertContains('staging_not_found', $out);
        $id = $this->stage($this->package('ruff-9.0.0/', $this->ruffFiles('9.0.0')));
        [$code, $out] = $run(['install', '--staging=' . $id]);
        $this->assertSame(1, $code, $out);
        $this->assertContains('bad_layout', $out);
        $this->assertSame('install_failed', $app->db()->value('SELECT status FROM tool_staging WHERE id = ?', [$id]));
        [$code, $out] = $run(['remove', 'ruff']);
        $this->assertSame(2, $code);
        [$code, $out] = $run(['remove', 'ruff', '9.9.9']);
        $this->assertSame([1, true], [$code, str_contains($out, 'not_installed')], $out);
        $this->assertSame([], $this->snapshot());
    }
}
