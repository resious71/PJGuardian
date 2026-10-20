<?php

declare(strict_types=1);

namespace PJGuardian\Services\Process;

/**
 * 외부 프로세스 실행기(명세 5.2, 19.1, 19.10). 프로토타입 tools/proto/run_tool.php를 제품 코드로 다시 썼다(require하지 않음).
 *
 * - proc_open에 **인자 배열**(셸 없음, Windows bypass_shell). exec·shell_exec·system·passthru·백틱을 쓰지 않는다.
 * - 실행 파일은 허용 목록 안만: 허용 루트(기본 bin/) 아래이거나 명시적으로 허용한 실행 파일(설정 tools.node_path,
 *   워커의 PHP 자식은 PHP_BINARY). realpath로 비교하고 Windows는 대소문자를 구분하지 않는다(심볼릭 링크·../ 우회 차단).
 * - stdout·stderr는 **임시 파일**로 받는다(파이프 교착 방지, Windows에서는 proc_open 파이프에 stream_select를 못 쓴다).
 * - 타임아웃·출력 한도·취소(콜백) 시 **프로세스 트리**를 종료한다(ProcessTree). Linux는 setsid로 새 프로세스 그룹에서 실행한다.
 * - 메모리 한도는 Linux만 prlimit --as로 건다. Windows는 적용하지 않는다(시간·출력 한도로 대신, docs/worker_setup.md 5절).
 *
 * 사용:
 *   $r = $runner->run([$bin . '/ruff/0.16.10/windows/ruff.exe', 'check', ...], new ProcessOptions(cwd: $dir, timeoutSeconds: 14400));
 *   $json = $r->stdout(); $r->cleanup();
 *   $p = $runner->start($cmd, $opts, fn () => $cancelRequested());  // 워커: 여러 개를 poll()로 감독
 */
final class ProcessRunner
{
    /** @var list<string> */
    private array $roots;
    /** @var list<string> */
    private array $executables;

    /**
     * @param list<string> $allowedRoots 이 폴더 아래의 실행 파일만 허용(보통 bin/)
     * @param list<string> $allowedExecutables 루트 밖이지만 허용할 실행 파일(Node 런타임, PHP_BINARY)
     */
    public function __construct(array $allowedRoots, array $allowedExecutables = [])
    {
        $this->roots = array_values(array_filter(array_map(static fn (string $p): ?string => self::key($p), $allowedRoots)));
        $this->executables = array_values(array_filter(array_map(static fn (string $p): ?string => self::key($p), $allowedExecutables)));
    }

    /** 비교용 경로 키: realpath, '/' 구분, Windows는 소문자. 없으면 null */
    private static function key(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        $p = rtrim(str_replace('\\', '/', $real), '/');
        return ProcessTree::isWindows() ? strtolower($p) : $p;
    }

    /** 허용된 실행 파일이면 실제 경로, 아니면 null */
    public function resolveExecutable(string $exe): ?string
    {
        $real = realpath($exe);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $k = self::key($real);
        if ($k === null) {
            return null;
        }
        if (in_array($k, $this->executables, true)) {
            return $real;
        }
        foreach ($this->roots as $root) {
            if (str_starts_with($k, $root . '/')) {
                return $real;
            }
        }
        return null;
    }

    /**
     * 실행만 하고 바로 돌려준다(기다리지 않음). 시작하지 못하면 start_failed 결과를 담은 프로세스 대신 예외가 아니라
     * ProcessResult를 돌려준다.
     *
     * @param list<string> $cmd $cmd[0]은 실행 파일 경로
     * @param (\Closure(): bool)|null $shouldCancel 참을 돌려주면 트리를 종료(사유 cancelled). cancelCheckSeconds마다 부른다
     * @throws ProcessException 허용되지 않은 실행 파일·작업 디렉토리
     */
    public function start(array $cmd, ProcessOptions $options, ?\Closure $shouldCancel = null): RunningProcess|ProcessResult
    {
        if ($cmd === [] || !array_is_list($cmd)) {
            throw new ProcessException('명령이 비어 있음');
        }
        foreach ($cmd as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                throw new ProcessException('인자는 NUL 없는 문자열이어야 함');
            }
        }
        $real = $this->resolveExecutable($cmd[0]);
        if ($real === null) {
            throw new ProcessException('허용되지 않은 실행 파일: ' . basename(str_replace('\\', '/', $cmd[0])));
        }
        if ($options->cwd !== null && !is_dir($options->cwd)) {
            throw new ProcessException('작업 디렉토리가 없음');
        }
        $cmd[0] = $real;
        if (!ProcessTree::isWindows()) {
            if ($options->memoryLimit !== null) {
                // prlimit은 제한을 건 뒤 같은 PID로 exec한다(PGID = PID 유지)
                $cmd = ['/usr/bin/prlimit', '--as=' . $options->memoryLimit, '--', ...$cmd];
            }
            // 새 세션 = 새 프로세스 그룹(PGID = PID). PHP가 fork한 자식은 그룹 리더가 아니라 setsid가 fork 없이 exec한다
            $cmd = [is_file('/usr/bin/setsid') ? '/usr/bin/setsid' : '/bin/setsid', ...$cmd];
        }

        $dir = $options->outputDir ?? sys_get_temp_dir();
        $out = @tempnam($dir, 'pjo');
        $err = @tempnam($dir, 'pje');
        if ($out === false || $err === false) {
            return new ProcessResult(ProcessResult::START_FAILED, null, 0, null, (string) $out, (string) $err, 0, $options->outputLimit);
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']];
        $env = $options->env ?? self::minimalEnv(sys_get_temp_dir());
        $other = ['bypass_shell' => true];
        if ($options->newProcessGroup && ProcessTree::isWindows()) {
            $other['create_process_group'] = true;
        }
        $proc = @proc_open($cmd, $spec, $pipes, $options->cwd, $env, $other);
        if (!is_resource($proc)) {
            return new ProcessResult(ProcessResult::START_FAILED, null, 0, null, $out, $err, 0, $options->outputLimit);
        }
        if ($options->stdin !== null && $options->stdin !== '') {
            @fwrite($pipes[0], substr($options->stdin, 0, 65536));
        }
        fclose($pipes[0]);
        $pid = (int) proc_get_status($proc)['pid'];
        return new RunningProcess($proc, $pid, $out, $err, $options, $shouldCancel);
    }

    /**
     * 끝날 때까지 실행한다.
     *
     * @param list<string> $cmd
     * @param (\Closure(): bool)|null $shouldCancel
     */
    public function run(array $cmd, ProcessOptions $options, ?\Closure $shouldCancel = null): ProcessResult
    {
        $p = $this->start($cmd, $options, $shouldCancel);
        return $p instanceof ProcessResult ? $p : $p->wait();
    }

    /**
     * 도구 실행용 최소 환경 변수. 사용자 설정(ruff 사용자 설정, npm 설정 등)을 읽지 않도록 HOME 계열을 $homeDir로 돌리고,
     * NODE_OPTIONS·RUFF_*·ESLINT_* 같은 변수는 넘기지 않는다. 자격 증명은 $extra로만 넘긴다(P25, 명령행 인자 금지).
     *
     * @param list<string> $extraPathDirs PATH 앞에 넣을 폴더(예: node 실행 파일 폴더)
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    public static function minimalEnv(string $homeDir, array $extraPathDirs = [], array $extra = []): array
    {
        if (ProcessTree::isWindows()) {
            $sysRoot = getenv('SystemRoot') ?: 'C:\\Windows';
            $env = [
                'SystemRoot' => $sysRoot,
                'SystemDrive' => getenv('SystemDrive') ?: 'C:',
                'PATH' => implode(';', [...$extraPathDirs, $sysRoot . '\\System32']),
                'PATHEXT' => '.COM;.EXE;.BAT;.CMD',
                'TEMP' => $homeDir,
                'TMP' => $homeDir,
                'USERPROFILE' => $homeDir,
                'APPDATA' => $homeDir,
                'LOCALAPPDATA' => $homeDir,
            ];
        } else {
            $env = [
                'PATH' => implode(':', [...$extraPathDirs, '/usr/bin', '/bin']),
                'HOME' => $homeDir,
                'TMPDIR' => $homeDir,
                'XDG_CONFIG_HOME' => $homeDir,
                'XDG_CACHE_HOME' => $homeDir,
                'LANG' => 'C.UTF-8',
            ];
        }
        return array_merge($env, $extra);
    }
}
