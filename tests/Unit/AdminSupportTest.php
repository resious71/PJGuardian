<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\Admin\AdminTasks;
use PJGuardian\Support\EnvironmentCheck;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\LogTail;
use PJGuardian\Support\Redact;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * P20A 지원 코드: 경로·비밀값 가리기(Redact), 로그 끝 읽기(LogTail), 환경 점검(EnvironmentCheck, 프로세스 없음),
 * 관리 작업 큐(중복 합침, 원자적 가져오기, 죽은 워커의 작업 정리), 웹 코드 경로 정적 점검(프로세스·bin/ 쓰기 없음).
 */
final class AdminSupportTest extends TestCase
{
    public function testRedactMasksSecretsAndPaths(): void
    {
        $r = new Redact(['app' => 'D:/Work/PJGuardian', 'work' => 'D:/Work/PJGuardian/data/work', 'tmp' => 'C:/Users/me/AppData/Local/Temp']);
        $cases = [
            'open D:\\Work\\PJGuardian\\data\\work\\ab12cd34\\x.py' => 'open <work>\\ab12cd34\\x.py',
            '{"path":"D:\\/Work\\/PJGuardian\\/bin\\/ruff"}' => '{"path":"<app>\\/bin\\/ruff"}',
            '"C:\\\\tools\\\\nodejs\\\\node.exe" failed' => '"<path>" failed',
            'file C:/Users/bob/secret.txt' => 'file <path>',
            'unc \\\\fileserver\\share\\x.zip' => 'unc <path>',
            'linux /home/deploy/pjg/data/x and /var/log/syslog' => 'linux <path> and <path>',
            'temp C:/Users/me/AppData/Local/Temp/pjg123' => 'temp <tmp>/pjg123',
            'password=hunter2 token: abc123' => 'password=*** token: ***',
            'https://user:pw@example.com/repo' => 'https://user:***@example.com/repo',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, $r->text($in), $in);
        }
        $keep = 'GET /api/v1/admin/tools 200 https://pjguardian.local/projects/3';
        $this->assertSame($keep, $r->text($keep), 'API 경로·URL은 그대로');
    }

    public function testLogTailReadsFromEndOnly(): void
    {
        $dir = $this->tempDir('tail');
        $f = $dir . '/a.log';
        $h = fopen($f, 'wb');
        for ($i = 1; $i <= 100000; $i++) {
            fwrite($h, "line {$i} " . str_repeat('y', 60) . "\n");
        }
        fwrite($h, str_repeat('z', 10000) . "\n");
        fclose($h);
        $t = LogTail::read($f, 3, 100);
        $this->assertSame(3, count($t['lines']));
        $this->assertSame('line 99999 ', substr($t['lines'][0], 0, 11));
        $this->assertSame(1, $t['truncated_lines'], '긴 줄은 잘라 표시');
        $this->assertTrue($t['bytes_read'] <= 2 * 65536, "읽은 바이트 {$t['bytes_read']}(파일 약 7MB)");
        $this->assertSame([], LogTail::read($dir . '/none.log', 5)['lines']);
        file_put_contents($dir . '/s.log', "a\r\nb\r\n");
        $this->assertSame(['a', 'b'], LogTail::read($dir . '/s.log', 10)['lines']);
    }

    public function testEnvironmentChecksWithoutProcesses(): void
    {
        $ids = array_column(EnvironmentCheck::platform(), 'id');
        foreach (['os', 'php_version', 'ext', 'sqlite_version', 'proc_open'] as $id) {
            $this->assertTrue(in_array($id, $ids, true), $id);
        }
        $this->assertSame([1024 ** 3, 8 * 1024 ** 2, -1, 512], [EnvironmentCheck::iniBytes('1G'), EnvironmentCheck::iniBytes('8M'), EnvironmentCheck::iniBytes('-1'), EnvironmentCheck::iniBytes('512')]);
        $dir = $this->tempDir('envdirs');
        mkdir($dir . '/bin');
        $items = EnvironmentCheck::directories(['logs' => $dir, 'nope' => $dir . '/missing'], $dir . '/bin', true);
        $this->assertSame(['ok', 'fail'], [$items[0]['status'], $items[1]['status']]);
        $this->assertSame([], array_values(array_filter(scandir($dir), static fn (string $n): bool => str_starts_with($n, '.write_probe'))), '쓰기 점검 파일은 지움');
        $this->assertTrue(in_array($items[2]['note'], ['bin_writable_web', 'bin_readonly_web'], true));
        $d = EnvironmentCheck::disk($dir, 80, 90);
        $this->assertTrue($d['used_percent'] !== null && in_array($d['status'], ['ok', 'warn', 'fail'], true));
    }

    public function testAdminTaskQueue(): void
    {
        $db = TestDb::migrated();
        $q = new AdminTasks($db, new FixedClock('2026-10-04T00:00:00Z'));
        $a = $q->request('tool_verify', 'ruff@1.0.0', null);
        $b = $q->request('tool_verify', 'ruff@1.0.0', null);
        $c = $q->request('tool_verify', 'ruff@1.1.0', null);
        $this->assertSame([false, true, false], [$a['merged'], $b['merged'], $c['merged']]);
        $this->assertSame($a['task']['id'], $b['task']['id']);
        $first = $q->claimNext();
        $this->assertSame([$a['task']['id'], 'running'], [$first['id'], $first['status']]);
        $this->assertSame(true, $q->request('tool_verify', 'ruff@1.0.0', null)['merged'], '실행 중에도 합침');
        $this->assertSame(1, $q->recoverRunning(), '죽은 워커가 남긴 실행 중 작업 → 실패');
        $this->assertSame(['failed', 'worker_crashed'], [$q->get($first['id'])['status'], $q->get($first['id'])['result']['code']]);
        $this->assertSame(false, $q->request('tool_verify', 'ruff@1.0.0', null)['merged'], '끝난 뒤에는 새 요청');
        $next = $q->claimNext();
        $q->finish($next['id'], true, ['status' => 'active']);
        $this->assertSame('succeeded', $q->get($next['id'])['status']);
        $threw = false;
        try {
            $q->request('rm_rf', '', null);
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        $this->assertTrue($threw, '알 수 없는 작업 종류 거부');
    }

    /**
     * 웹 요청이 지나는 코드(컨트롤러, 관리자 서비스)는 프로세스를 띄우거나 bin/에 쓰거나 도구를 실행·검증하지 않는다(P20A 완료 기준).
     * 예외는 WorkerControl::start 하나(설정의 고정 시작 명령, 인자 배열).
     */
    public function testWebCodePathsDoNotSpawnOrWriteBin(): void
    {
        $files = [...glob(PJG_ROOT . '/src/Controllers/*.php'), ...glob(PJG_ROOT . '/src/Services/Admin/*.php')];
        $forbidden = ['toolInstaller', 'ToolInstaller', '->ensureVerified(', '->select(', 'SafeUnzip', 'paths->bin()', 'internalRunner', 'tools/worker.php',
            'ProcessTree::kill', 'posix_kill', 'taskkill', '->extractTo('];
        $calls = '/(?<![\w>:$])(proc_open|shell_exec|passthru|popen|exec|system|pcntl_exec)\s*\(/';
        $hits = [];
        foreach ($files as $f) {
            $code = (string) file_get_contents($f);
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code); // 주석 제외
            $code = (string) preg_replace('#//[^\n]*#', '', $code);
            foreach ($forbidden as $needle) {
                if (str_contains($code, $needle)) {
                    $hits[] = basename($f) . ': ' . $needle;
                }
            }
            if (preg_match($calls, $code, $m) === 1) {
                $hits[] = basename($f) . ': ' . $m[1] . '()';
            }
            if (str_contains($code, 'ProcessRunner') && basename($f) !== 'WorkerControl.php') {
                $hits[] = basename($f) . ': ProcessRunner';
            }
        }
        // bin/ 경로는 점검 화면에 넘겨 쓰기 권한만 본다(AdminCheckController → EnvironmentCheck::directories, 쓰지 않음)
        $hits = array_values(array_filter($hits, static fn (string $h): bool => $h !== 'AdminCheckController.php: paths->bin()'));
        $this->assertSame([], $hits);
        $wc = (string) file_get_contents(PJG_ROOT . '/src/Services/Admin/WorkerControl.php');
        $this->assertSame(1, substr_count($wc, '->run('), 'WorkerControl의 실행은 시작 명령 하나');
        $this->assertContains("->pin(\$tool, \$version, false)", (string) file_get_contents(PJG_ROOT . '/src/Services/Admin/ToolAdmin.php'), '웹 고정은 검증 없이');
    }
}
