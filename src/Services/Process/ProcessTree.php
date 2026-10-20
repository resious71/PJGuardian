<?php

declare(strict_types=1);

namespace PJGuardian\Services\Process;

/**
 * 프로세스 트리 종료와 생존 확인(명세 5.2: 타임아웃이면 하위 프로세스까지 종료).
 *
 * - Windows: System32의 taskkill.exe /PID <pid> /T /F. 실행 중인 부모에서 자식을 찾아 함께 끝낸다.
 *   **한계(고아 자식)**: 부모가 자식을 띄우고 먼저 끝나면 그 자식은 트리에서 떨어져 나가 /T로 잡히지 않는다(P03 확인).
 *   ruff·ESLint는 정상 실행 중 이런 자식을 만들지 않는다. 워커는 분석 자식(run_analysis.php)이 살아 있는 동안
 *   트리째 끝내므로 도구 프로세스도 같이 끝난다. Job Object로 묶으려면 PHP 밖의 도우미가 필요해 쓰지 않는다.
 * - Linux: ProcessRunner가 setsid로 새 세션(프로세스 그룹, PGID = PID)에서 실행하므로 posix_kill(-PGID, SIGKILL).
 *   그룹 단위라 중간 부모가 먼저 끝난 손자도 같은 그룹이면 함께 끝난다. posix가 없으면 /bin/kill -KILL -- -PGID.
 *
 * 여기의 OS 기본 도구(taskkill, tasklist, kill)는 고정 경로로만 부르고 셸을 거치지 않는다(bin/ 허용 목록과 별개).
 */
final class ProcessTree
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private static function system32(string $exe): string
    {
        return (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\' . $exe;
    }

    /** 프로세스 트리를 강제 종료한다. 사용한 방법(로그·보고용)을 돌려준다 */
    public static function kill(int $pid): string
    {
        if ($pid <= 0) {
            return 'none';
        }
        if (self::isWindows()) {
            $r = self::exec([self::system32('taskkill.exe'), '/PID', (string) $pid, '/T', '/F'], 30);
            return 'taskkill /PID ' . $pid . ' /T /F (exit ' . $r['exit'] . ')';
        }
        if (function_exists('posix_kill')) {
            posix_kill(-$pid, 9); // SIGKILL
            return 'posix_kill(-' . $pid . ', SIGKILL)';
        }
        $r = self::exec(['/bin/kill', '-KILL', '--', '-' . $pid], 10);
        return '/bin/kill -KILL -- -' . $pid . ' (exit ' . $r['exit'] . ')';
    }

    /** 프로세스 하나만 강제 종료(트리 아님). 시험에서 "워커 부모만 죽이기"에 쓴다 */
    public static function killOne(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        if (self::isWindows()) {
            self::exec([self::system32('taskkill.exe'), '/PID', (string) $pid, '/F'], 30);
        } elseif (function_exists('posix_kill')) {
            posix_kill($pid, 9);
        } else {
            self::exec(['/bin/kill', '-KILL', (string) $pid], 10);
        }
    }

    public static function isAlive(int $pid): bool
    {
        return $pid > 0 && self::aliveAmong([$pid]) !== [];
    }

    /**
     * 주어진 PID 중 살아 있는 것(테스트 도우미: "종료 후 남은 프로세스 0개" 확인). Windows는 tasklist 한 번으로 본다.
     *
     * @param list<int> $pids
     * @return list<int>
     */
    public static function aliveAmong(array $pids): array
    {
        $pids = array_values(array_unique(array_filter(array_map('intval', $pids), static fn (int $p): bool => $p > 0)));
        if ($pids === []) {
            return [];
        }
        if (!self::isWindows()) {
            $alive = [];
            foreach ($pids as $p) {
                // 좀비(Z)는 끝난 프로세스로 본다
                $stat = @file_get_contents('/proc/' . $p . '/stat');
                if ($stat !== false && preg_match('/^\d+ \(.*\) ([A-Za-z])/s', $stat, $m) === 1 && $m[1] !== 'Z') {
                    $alive[] = $p;
                }
            }
            return $alive;
        }
        $list = self::windowsProcesses();
        return array_values(array_filter($pids, static fn (int $p): bool => isset($list[$p])));
    }

    /**
     * 실행 파일 이름(소문자, 예: php.exe / php). 비정상 종료 복구에서 PID 재사용을 피하려고 확인한다. 없으면 null.
     */
    public static function imageName(int $pid): ?string
    {
        if ($pid <= 0) {
            return null;
        }
        if (self::isWindows()) {
            return self::windowsProcesses()[$pid] ?? null;
        }
        $comm = @file_get_contents('/proc/' . $pid . '/comm');
        return $comm === false ? null : strtolower(trim($comm));
    }

    /** Linux: 명령행(인자를 공백으로 이음). Windows는 null(확인 안 함) */
    public static function commandLine(int $pid): ?string
    {
        if (self::isWindows() || $pid <= 0) {
            return null;
        }
        $c = @file_get_contents('/proc/' . $pid . '/cmdline');
        return $c === false ? null : trim(str_replace("\0", ' ', $c));
    }

    /** @return array<int, string> PID → 실행 파일 이름(소문자) */
    private static function windowsProcesses(): array
    {
        $r = self::exec([self::system32('tasklist.exe'), '/FO', 'CSV', '/NH'], 30);
        $out = [];
        foreach (preg_split('/\r?\n/', $r['stdout']) ?: [] as $line) {
            // "php.exe","1234","Console","1","12,345 K"
            if (preg_match('/^"([^"]*)","(\d+)"/', $line, $m) === 1) {
                $out[(int) $m[2]] = strtolower($m[1]);
            }
        }
        return $out;
    }

    /**
     * OS 기본 도구 실행(셸 없음, 출력은 임시 파일). 짧은 명령에만 쓴다.
     *
     * @param list<string> $cmd
     * @return array{exit:int, stdout:string}
     */
    private static function exec(array $cmd, int $timeoutSec): array
    {
        $out = tempnam(sys_get_temp_dir(), 'pjt');
        $spec = [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', self::isWindows() ? 'NUL' : '/dev/null', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($out);
            return ['exit' => -1, 'stdout' => ''];
        }
        fclose($pipes[0]);
        $deadline = hrtime(true) + $timeoutSec * 1_000_000_000;
        $exit = -1;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $exit = (int) $st['exitcode'];
                break;
            }
            if (hrtime(true) > $deadline) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(5_000);
        }
        proc_close($proc);
        $stdout = (string) @file_get_contents($out);
        @unlink($out);
        // tasklist는 콘솔 코드 페이지로 출력한다. 이름 비교는 ASCII(php.exe 등)만 하므로 변환하지 않는다
        return ['exit' => $exit, 'stdout' => $stdout];
    }
}
