<?php

declare(strict_types=1);

/**
 * ProcessRunner 측정(P14 보고용): 출력 한도 초과 감지 지연(초과량)과 트리 종료 시간, 타임아웃 정확도.
 * 가짜 도구는 php -r(시험용 출력기)이고 출력은 임시 폴더에 쓴 뒤 지운다. 실제 도구를 실행하지 않는다.
 *
 * 사용법: php tools/bench_process.php [--repeat=3]
 *   출력기: fast = 1MB씩 최대 속도로 쓰기(최악), paced = 64KB씩 1ms마다(약 60MB/s, ESLint·ruff보다 빠른 편)
 *   폴링 간격 5/10/20ms × 한도 1MB/64MB
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\ProcessTree;

$opt = getopt('', ['repeat:']);
$repeat = max(1, (int) ($opt['repeat'] ?? 3));
$runner = new ProcessRunner([], [PHP_BINARY]);
$tmp = sys_get_temp_dir() . '/pjg_bench_' . bin2hex(random_bytes(4));
mkdir($tmp);
$writers = [
    'fast' => '$b = str_repeat("x", 1048576); while (true) { fwrite(STDOUT, $b); }',
    'paced' => '$b = str_repeat("x", 65536); while (true) { fwrite(STDOUT, $b); usleep(1000); }',
];
$mb = static fn (int $b): string => number_format($b / 1048576, 1) . 'MB';
printf("OS %s, PHP %s, 반복 %d회 (값은 중앙값, 괄호는 최대)\n\n", PHP_OS_FAMILY, PHP_VERSION, $repeat);
printf("%-6s %-6s %-6s | %-16s %-16s %-16s %-12s\n", '출력기', '한도', '폴링', '감지 시점', '최종 크기', '초과량', '종료 명령');
foreach ($writers as $name => $code) {
    foreach ([1, 64] as $limitMb) {
        foreach ([5_000, 10_000, 20_000] as $poll) {
            $det = $fin = $over = $kill = [];
            for ($i = 0; $i < $repeat; $i++) {
                $r = $runner->run([PHP_BINARY, '-r', $code], new ProcessOptions(timeoutSeconds: 60, outputLimit: $limitMb * 1048576, outputDir: $tmp, pollMicros: $poll));
                $det[] = (int) $r->detectedBytes;
                $fin[] = $r->outputBytes;
                $over[] = $r->overshoot();
                $kill[] = (int) $r->killMs;
                if (ProcessTree::aliveAmong([(int) $r->pid]) !== []) {
                    echo "  [경고] 종료 후 프로세스가 남음: {$r->pid}\n";
                }
                $r->cleanup();
            }
            sort($det);
            sort($fin);
            sort($over);
            sort($kill);
            $mid = intdiv($repeat, 2);
            printf("%-6s %-6s %-6s | %-16s %-16s %-16s %-12s\n", $name, $limitMb . 'MB', ($poll / 1000) . 'ms',
                $mb($det[$mid]) . ' (' . $mb(end($det)) . ')', $mb($fin[$mid]) . ' (' . $mb(end($fin)) . ')',
                $mb($over[$mid]) . ' (' . $mb(end($over)) . ')', $kill[$mid] . 'ms (' . end($kill) . ')');
        }
    }
}

// 타임아웃 정확도와 트리 종료(손자 포함)
echo "\n타임아웃 2초, 손자 프로세스가 있는 트리:\n";
for ($i = 0; $i < $repeat; $i++) {
    $pidFile = $tmp . '/pids' . $i;
    $code = '$c = proc_open([PHP_BINARY, "-r", "sleep(60);"], [], $p, null, null, ["bypass_shell" => true]);'
        . ' file_put_contents(' . var_export($pidFile, true) . ', getmypid() . " " . proc_get_status($c)["pid"]); sleep(60);';
    $t0 = microtime(true);
    $r = $runner->run([PHP_BINARY, '-r', $code], new ProcessOptions(timeoutSeconds: 2, outputDir: $tmp));
    $sec = microtime(true) - $t0;
    $pids = is_file($pidFile) ? array_map('intval', explode(' ', (string) file_get_contents($pidFile))) : [];
    printf("  %d회: 사유 %s, 총 %.2f초, 종료 명령 %dms, 남은 프로세스 %d개 (%s)\n", $i + 1, $r->reason, $sec, (int) $r->killMs,
        count(ProcessTree::aliveAmong($pids)), (string) $r->killMethod);
    $r->cleanup();
}
foreach (Fs_list($tmp) as $f) {
    @unlink($tmp . '/' . $f);
}
@rmdir($tmp);

/** @return list<string> */
function Fs_list(string $dir): array
{
    return \PJGuardian\Support\Fs::listDir($dir);
}
