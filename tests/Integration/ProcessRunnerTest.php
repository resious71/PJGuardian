<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Process\ProcessException;
use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Tests\TestCase;

/**
 * 프로세스 실행기(명세 5.2, 19.1, 19.10, P14): 허용 목록, 셸 없음, 최소 환경, 타임아웃·취소 시 트리 종료(손자까지, 남은 프로세스 0개),
 * 출력 한도와 초과량. 실제 프로세스를 띄운다(시험용 스크립트는 tests/Support/proc/, 실행 파일은 PHP_BINARY만 허용).
 */
final class ProcessRunnerTest extends TestCase
{
    private const PROC = PJG_ROOT . '/tests/Support/proc';

    private function runner(): ProcessRunner
    {
        return new ProcessRunner([PJG_ROOT . '/bin'], [PHP_BINARY]);
    }

    /** @return list<int> 트리 스크립트가 쓴 [자식, 손자] PID */
    private function waitPids(string $file): array
    {
        $deadline = microtime(true) + 15;
        while (!is_file($file)) {
            if (microtime(true) > $deadline) {
                $this->fail('트리 스크립트가 PID 파일을 쓰지 않음');
            }
            usleep(20_000);
        }
        return array_map('intval', explode(' ', trim((string) file_get_contents($file))));
    }

    public function testRejectsExecutablesOutsideAllowList(): void
    {
        $dir = $this->tempDir('prun');
        mkdir($dir . '/bin');
        file_put_contents($dir . '/bin/tool.exe', 'x');
        file_put_contents($dir . '/outside.exe', 'x');
        $r = new ProcessRunner([$dir . '/bin']);
        $this->assertTrue($r->resolveExecutable($dir . '/bin/tool.exe') !== null, 'bin/ 아래는 허용');
        $this->assertSame(null, $r->resolveExecutable($dir . '/bin/../outside.exe'), '../ 로 bin/ 밖');
        $this->assertSame(null, $r->resolveExecutable($dir . '/outside.exe'));
        $this->assertSame(null, $r->resolveExecutable($dir . '/bin'), '폴더는 실행 파일이 아님');
        $this->assertSame(null, $r->resolveExecutable($dir . '/bin/none.exe'), '없는 파일');
        $this->assertSame(null, $r->resolveExecutable(PHP_BINARY), '명시하지 않은 PHP');
        if (ProcessTree::isWindows()) {
            $this->assertTrue($r->resolveExecutable(strtoupper($dir) . '/BIN/TOOL.EXE') !== null, 'Windows는 대소문자 비구분');
        }
        $this->assertThrows(ProcessException::class, static fn () => $r->run([PHP_BINARY, '-v'], new ProcessOptions()));
        $this->assertThrows(ProcessException::class, static fn () => $r->run([(getenv('SystemRoot') ?: 'C:\\Windows') . '/System32/cmd.exe', '/c', 'echo'], new ProcessOptions()));
        $this->assertThrows(ProcessException::class, fn () => $this->runner()->run([PHP_BINARY, '-v'], new ProcessOptions(cwd: $dir . '/none')));
    }

    public function testNoShellAndMinimalEnvironment(): void
    {
        putenv('PJG_PROBE=secret-value');
        try {
            $tricky = 'a & echo injected | b > c "q" %PATH% $HOME `id`';
            $r = $this->runner()->run([PHP_BINARY, self::PROC . '/echo_exit.php', $tricky, '한글 인자', '3'], new ProcessOptions(timeoutSeconds: 30));
            $this->assertSame(ProcessResult::NORMAL, $r->reason);
            $this->assertSame(3, $r->exitCode);
            $lines = explode("\n", str_replace("\r", '', $r->stdout()));
            $this->assertSame($tricky, $lines[0], '셸 메타 문자가 그대로 인자로 전달');
            $this->assertSame('한글 인자', $lines[1]);
            $this->assertSame('probe=none', $lines[3], '부모 환경 변수를 넘기지 않음(env=null → 최소 환경)');
            $this->assertSame("err-line\n", str_replace("\r", '', $r->stderr()));
            $this->assertFalse($r->ok());
            $r->cleanup();
            $this->assertFalse(is_file($r->stdoutPath), 'cleanup이 출력 파일을 지움');
            $r2 = $this->runner()->run([PHP_BINARY, self::PROC . '/echo_exit.php', '0'], new ProcessOptions(env: ProcessRunner::minimalEnv(sys_get_temp_dir(), [], ['PJG_PROBE' => 'x'])));
            $this->assertTrue($r2->ok());
            $this->assertContains('probe=set', $r2->stdout(), '명시한 변수만 전달');
            $r2->cleanup();
        } finally {
            putenv('PJG_PROBE');
        }
    }

    public function testTimeoutKillsWholeTree(): void
    {
        $dir = $this->tempDir('prun');
        $t0 = microtime(true);
        $r = $this->runner()->start([PHP_BINARY, self::PROC . '/tree.php', $dir . '/pids', '60'], new ProcessOptions(timeoutSeconds: 2, outputDir: $dir));
        $this->assertTrue(!$r instanceof ProcessResult);
        $pids = $this->waitPids($dir . '/pids');
        $this->assertSame(2, count(ProcessTree::aliveAmong($pids)), '자식·손자 실행 중');
        $res = $r->wait();
        $sec = microtime(true) - $t0;
        $this->assertSame(ProcessResult::TIMEOUT, $res->reason);
        $this->assertSame(null, $res->exitCode);
        $this->assertTrue($sec >= 2 && $sec < 10, "타임아웃 2초 → 종료까지 {$sec}초");
        $this->assertSame([], ProcessTree::aliveAmong($pids), '타임아웃 후 남은 프로세스 0개(손자 포함)');
        $res->cleanup();
    }

    public function testCancelCallbackKillsWholeTree(): void
    {
        $dir = $this->tempDir('prun');
        $flag = false;
        $calls = 0;
        $p = $this->runner()->start(
            [PHP_BINARY, self::PROC . '/tree.php', $dir . '/pids', '60'],
            new ProcessOptions(outputDir: $dir, cancelCheckSeconds: 0.2),
            static function () use (&$flag, &$calls): bool {
                $calls++;
                return $flag;
            },
        );
        $pids = $this->waitPids($dir . '/pids');
        usleep(500_000);
        $this->assertSame(null, $p->poll(), '취소 전에는 계속 실행');
        $this->assertTrue($calls >= 1, '취소 콜백을 주기적으로 부름');
        $flag = true;
        $t0 = microtime(true);
        $res = $p->wait();
        $this->assertSame(ProcessResult::CANCELLED, $res->reason);
        $this->assertTrue(microtime(true) - $t0 < 5, '취소 요청 후 곧 종료');
        $this->assertSame([], ProcessTree::aliveAmong($pids), '취소 후 남은 프로세스 0개');
        $this->assertContains(ProcessTree::isWindows() ? 'taskkill' : 'SIGKILL', (string) $res->killMethod);
        $res->cleanup();
    }

    public function testOutputLimitStopsProcessAndReportsOvershoot(): void
    {
        $dir = $this->tempDir('prun');
        $limit = 4 * 1024 * 1024;
        $res = $this->runner()->run([PHP_BINARY, self::PROC . '/spew.php'], new ProcessOptions(timeoutSeconds: 60, outputLimit: $limit, outputDir: $dir));
        $this->assertSame(ProcessResult::OUTPUT_LIMIT, $res->reason);
        $this->assertTrue($res->detectedBytes > $limit, '감지 시점에 한도 초과');
        $this->assertTrue($res->outputBytes >= $res->detectedBytes);
        // 초과량은 폴링 간격(10ms) 동안 쓴 양 + 종료에 걸린 시간 동안 쓴 양. 보고서에 실측(tools/bench_process.php)
        $this->assertTrue($res->overshoot() < 1024 * 1024 * 1024, '초과량 ' . $res->overshoot() . ' bytes');
        $this->assertSame($limit, strlen($res->stdout($limit)), '읽기는 한도까지만');
        $this->assertSame([], ProcessTree::aliveAmong([(int) $res->pid]));
        $res->cleanup();
    }

    public function testTerminateIsIdempotentAndPollAfterExit(): void
    {
        $p = $this->runner()->start([PHP_BINARY, self::PROC . '/echo_exit.php', '0'], new ProcessOptions());
        $r1 = $p->wait();
        $this->assertSame(ProcessResult::NORMAL, $r1->reason);
        $this->assertTrue($p->poll() === $r1, '끝난 뒤 poll은 같은 결과');
        $this->assertTrue($p->terminate() === $r1, '끝난 뒤 terminate는 아무것도 하지 않음');
        $r1->cleanup();
    }

    public function testImageNameAndAliveHelpers(): void
    {
        $this->assertTrue(ProcessTree::isAlive(getmypid()), '현재 프로세스는 살아 있음');
        $this->assertContains('php', (string) ProcessTree::imageName(getmypid()));
        $this->assertSame([], ProcessTree::aliveAmong([0, -1]));
    }
}
