<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Settings\PathPolicy;
use PJGuardian\Tests\TestCase;

/**
 * 경로 검증(P27A PathPolicy — P27B 백업 폴더가 그대로 쓴다): 절대·상대·UNC, ".."·정션·심볼릭 링크로 금지 위치에 닿는 우회(정규화 후 비교),
 * 없는 폴더(만들기 확인 → 생성), 쓸 수 없는 위치, 너무 긴 경로, 같은 볼륨 경고, UNC 시간 상한, 결과 코드.
 * 허용 위치는 프로젝트 밖(운영체제 임시 폴더)이어야 한다 — 프로젝트 폴더 전체가 금지라서.
 */
final class PathPolicyTest extends TestCase
{
    private string $base;
    private string $root;
    private string $data;
    /** @var list<string> 만든 링크·정션(지울 때 대상 폴더를 따라 들어가지 않게 먼저 끊는다) */
    private array $links = [];

    public function setUp(): void
    {
        // 가짜 프로젝트 루트와 허용 위치를 모두 운영체제 임시 폴더 아래에 둔다(실제 프로젝트 폴더는 금지 위치)
        $this->base = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/pjg_pathpol_' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/app';
        $this->data = $this->root . '/data';
        foreach ([$this->data . '/backups', $this->root . '/config', $this->root . '/public', $this->base . '/ok'] as $d) {
            mkdir($d, 0700, true);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->links as $l) {
            PHP_OS_FAMILY === 'Windows' ? @rmdir($l) : @unlink($l);
        }
        $this->rmTree($this->base);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $n) {
            $this->rmTree($dir . '/' . $n);
        }
        @rmdir($dir);
    }

    private function policy(float $uncTimeout = 5.0): PathPolicy
    {
        return new PathPolicy($this->root, [$this->root . '/public', $this->data], [$this->data . '/backups'], $this->data,
            new ProcessRunner([], [PHP_BINARY]), $uncTimeout, sys_get_temp_dir());
    }

    public function testCodesForAbsoluteRelativeAndForbidden(): void
    {
        $p = $this->policy();
        $ok = $p->check($this->base . '/ok');
        $this->assertSame('warn_same_volume', $ok['code'], '임시 폴더 = 같은 볼륨(가짜 data와 같은 디스크)');
        $this->assertTrue(is_int($ok['free_bytes']) && $ok['free_bytes'] > 0);
        $this->assertSame('fail_forbidden_location', $p->check('config')['code'], '프로젝트 기준 상대 경로 → 프로젝트 안');
        $this->assertSame('fail_forbidden_location', $p->check($this->root)['code'], '프로젝트 폴더 자체');
        $this->assertSame('fail_forbidden_location', $p->check($this->root . '/public/../config')['code'], '.. 우회');
        $this->assertSame('fail_forbidden_location', $p->check($this->base . '/ok/../app/data/x')['code'], '.. 우회로 data');
        $this->assertSame('warn_same_volume', $p->check($this->data . '/backups')['code'], '예외(백업 폴더)는 data 안이어도 허용');
        $this->assertSame('fail_not_absolute', $p->check('')['code']);
        $this->assertSame('fail_too_long', $p->check($this->base . '/' . str_repeat('a', 210))['code']);
        $this->assertSame('fail_not_absolute', $p->check("bad\"name")['code'], '따옴표·제어 문자');
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame('fail_not_absolute', $p->check('C:abc')['code'], '드라이브 기준 상대');
            $this->assertSame('fail_not_absolute', $p->check('\\abc')['code'], '드라이브 없는 루트');
            $this->assertSame('fail_forbidden_location', $p->check('C:\\')['code'], '드라이브 루트');
            $this->assertSame('fail_forbidden_location', $p->check((string) (getenv('SystemRoot') ?: 'C:\\Windows') . '\\Temp')['code'], '운영체제 폴더');
            $this->assertSame('fail_forbidden_location', $p->check(strtoupper($this->root) . '\\CONFIG')['code'], '대소문자 무시');
        } else {
            $this->assertSame('fail_forbidden_location', $p->check('/')['code']);
            $this->assertSame('fail_forbidden_location', $p->check('/etc/pjg')['code']);
        }
    }

    public function testLinkOrJunctionIntoForbiddenIsRejectedAfterNormalization(): void
    {
        $link = $this->base . '/ok/link';
        $this->links[] = $link;
        if (PHP_OS_FAMILY === 'Windows') {
            // 정션은 관리자 권한 없이 만들 수 있다(mklink /J — cmd 내장 명령이라 인자 배열이 아니라 한 줄로. 시험 전용)
            $proc = proc_open(sprintf('mklink /J "%s" "%s"', str_replace('/', '\\', $link), str_replace('/', '\\', $this->root . '/config')), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($proc);
        } else {
            symlink($this->root . '/config', $link);
        }
        $this->assertTrue(is_dir($link), '링크·정션 준비');
        $r = $this->policy()->check($link . '/backups');
        $this->assertSame('fail_forbidden_location', $r['code'], '문자열은 허용 위치지만 실제 위치는 config/');
        $this->assertTrue(str_contains(str_replace('\\', '/', strtolower((string) $r['path'])), '/app/config'), '실제 위치를 알려 줌');
        $this->assertFalse(is_dir($this->root . '/config/backups'));
        $r = $this->policy()->check($link . '/backups', true);
        $this->assertSame('fail_forbidden_location', $r['code']);
        $this->assertFalse(is_dir($this->root . '/config/backups'), '금지 위치에는 만들지 않는다');
    }

    public function testMissingFolderCreateAndNotWritable(): void
    {
        $p = $this->policy();
        $target = $this->base . '/ok/new/deeper';
        $r = $p->check($target);
        $this->assertSame(['fail_not_found', true, false], [$r['code'], $r['can_create'], $r['created']]);
        $this->assertFalse(is_dir($target));
        $r = $p->check($target, true);
        $this->assertSame(['warn_same_volume', true], [$r['code'], $r['created']]);
        $this->assertTrue(is_dir($target));
        $this->assertSame([], array_values(array_diff((array) scandir($target), ['.', '..'])), '쓰기 시험 파일은 지운다');
        // 같은 이름의 파일이 있어 폴더를 만들 수 없음 → 쓸 수 없음
        file_put_contents($this->base . '/ok/afile', 'x');
        $this->assertSame('fail_not_writable', $p->check($this->base . '/ok/afile/sub', true)['code']);
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            mkdir($this->base . '/ok/ro', 0555);
            $this->assertSame('fail_not_writable', $p->check($this->base . '/ok/ro')['code']);
            chmod($this->base . '/ok/ro', 0755);
        }
    }

    public function testUncUnreachableWithinTimeLimit(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('fail_not_found', $this->policy()->check('//pjg-no-such/share/x')['code'], 'Linux에는 UNC가 없다(일반 절대 경로)');
            return;
        }
        $t = microtime(true);
        $r = $this->policy(0.5)->check('\\\\pjg-no-such-host-p27a\\share\\backups');
        $this->assertSame('fail_unc_unreachable', $r['code']);
        $this->assertTrue(microtime(true) - $t < 3.0, '시간 상한(0.5초) 안에서 끝남: ' . round(microtime(true) - $t, 2));
        // 응답하지 않는 주소(라우팅 안 되는 IP)는 SMB 연결이 수십 초 걸린다 → 자식 프로세스를 상한(1초)에서 끊는다(실측 5초 상한 5.08초)
        $t = microtime(true);
        $r = $this->policy(1.0)->check('\\\\10.255.255.1\\share\\backups');
        $elapsed = microtime(true) - $t;
        $this->assertSame('fail_unc_unreachable', $r['code']);
        $this->assertTrue($elapsed < 3.0, '상한에서 끊음: ' . round($elapsed, 2) . '초');
        $this->assertSame('fail_not_absolute', $this->policy()->check('\\\\server')['code'], '공유 이름 없는 UNC');
        $this->assertSame('fail_not_absolute', $this->policy()->check('\\\\server\\share\\..\\..\\x')['code'], '공유 위로 올라감');
    }
}
