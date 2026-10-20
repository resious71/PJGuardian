<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Services\Repo\VcsSandbox;
use PJGuardian\Support\Fs;

/**
 * P25 시험 도우미: 로컬 Git 저장소(시험 코드 안에서만 bin/의 MinGit을 격리 환경으로 실행 — bin/은 읽기·실행만)와
 * 로컬 Git HTTP 서버(PHP 내장 서버 + git-http-backend, tests/Support/git_http_router.php). 외부 네트워크를 쓰지 않는다.
 */
final class RepoFixture
{
    public string $served;
    public string $log;
    /** @var resource|null */
    private $server = null;
    public int $port = 0;
    private VcsSandbox $sb;

    public function __construct(public readonly string $root)
    {
        $this->served = $root . '/served';
        $this->log = $root . '/http.log';
        Fs::ensureDir($this->served);
        $this->sb = new VcsSandbox($root . '/fixture-sb');
    }

    public static function git(): ?string
    {
        $exe = PJG_ROOT . '/bin/git/2.56.0/' . (ProcessTree::isWindows() ? 'windows/cmd/git.exe' : 'linux/bin/git');
        return is_file($exe) ? $exe : null;
    }

    /**
     * 시험 서버 쪽 git-http-backend. MinGit에는 없어서 이 PC에 설치된 Git의 것을 **시험 코드 안에서만** 쓴다(제품 코드는 bin/만).
     * 환경 변수 PJG_GIT_HTTP_BACKEND로 바꿀 수 있다. 없으면 HTTP 시험을 건너뛴다.
     */
    public static function httpBackend(): ?string
    {
        $candidates = array_filter([getenv('PJG_GIT_HTTP_BACKEND') ?: null, 'C:/Program Files/Git/mingw64/libexec/git-core/git-http-backend.exe',
            '/usr/lib/git-core/git-http-backend', '/usr/libexec/git-core/git-http-backend']);
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /** 시험 저장소 명령(격리 환경, 저자 고정) @return string stdout */
    public function run(array $args, string $cwd): string
    {
        $env = $this->sb->gitEnv(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@example.invalid', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@example.invalid',
            'GIT_AUTHOR_DATE' => '2026-10-01T00:00:00Z', 'GIT_COMMITTER_DATE' => '2026-10-01T00:00:00Z']);
        $env['GIT_PROTOCOL_FROM_USER'] = '1'; // 시험 저장소를 만들 때만 로컬 경로 복제 허용(제품 환경은 안전 키를 덮어쓸 수 없다)
        $p = proc_open([(string) self::git(), '-c', 'core.autocrlf=false', '-c', 'init.defaultBranch=main', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env, ['bypass_shell' => true]);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ': ' . $err);
        }
        return $out;
    }

    /**
     * 작업 저장소를 만들고(또는 이어서) 파일을 바꿔 커밋한 뒤 served/<이름>.git에 bare로 반영한다. 커밋 해시.
     *
     * @param array<string, string|null> $files 경로 → 내용(null = 삭제)
     */
    public function commit(string $name, array $files, string $branch = 'main', string $message = 'c'): string
    {
        $work = $this->root . '/work-' . $name;
        $bare = $this->served . '/' . $name . '.git';
        if (!is_dir($work)) {
            Fs::ensureDir($work);
            $this->run(['init', '-q', '-b', 'main'], $work);
        }
        $current = trim($this->run(['symbolic-ref', '--short', 'HEAD'], $work));
        if ($current !== $branch) {
            $has = trim($this->run(['branch', '--list', $branch], $work)) !== '';
            $this->run($has ? ['checkout', '-q', $branch] : ['checkout', '-q', '-b', $branch], $work);
        }
        foreach ($files as $rel => $content) {
            if ($content === null) {
                @unlink($work . '/' . $rel);
                continue;
            }
            Fs::ensureDir(dirname($work . '/' . $rel));
            file_put_contents($work . '/' . $rel, $content);
        }
        $this->run(['add', '-A'], $work);
        $this->run(['commit', '-q', '--allow-empty', '-m', $message], $work);
        $hash = trim($this->run(['rev-parse', 'HEAD'], $work));
        if (!is_dir($bare)) {
            $this->run(['clone', '-q', '--bare', $work, $bare], $this->root);
        } else {
            $this->run(['push', '-q', '-f', $bare, $branch], $work);
        }
        $this->run(['--git-dir=' . $bare, 'symbolic-ref', 'HEAD', 'refs/heads/main'], $this->root);
        return $hash;
    }

    /**
     * 작업 트리 없이 색인에 항목을 직접 넣어 커밋(P26B): 심볼릭 링크(모드 120000, 내용 = 링크 대상 경로), 대소문자만 다른 이름 등
     * Windows 작업 트리로 만들 수 없는 항목. commit()으로 저장소를 먼저 만든 뒤 쓴다. 커밋 해시.
     *
     * @param list<array{0: string, 1: string, 2: string}> $entries [모드(100644|120000), 경로, 내용]
     */
    public function commitIndex(string $name, array $entries, string $message = 'raw'): string
    {
        $work = $this->root . '/work-' . $name;
        $bare = $this->served . '/' . $name . '.git';
        $tmp = $this->root . '/blob.tmp';
        $lines = [];
        foreach ($entries as [$mode, $path, $content]) {
            file_put_contents($tmp, $content);
            $sha = trim($this->run(['hash-object', '-w', $tmp], $work));
            $lines[] = [$mode, $sha, $path];
        }
        @unlink($tmp);
        foreach ($lines as [$mode, $sha, $path]) {
            $this->run(['update-index', '--add', '--cacheinfo', "{$mode},{$sha},{$path}"], $work);
        }
        $this->run(['commit', '-q', '-m', $message], $work);
        $this->run(['push', '-q', '-f', $bare, 'main'], $work);
        return trim($this->run(['rev-parse', 'HEAD'], $work));
    }

    /** 로컬 HTTP 서버 시작. @param array<string, mixed> $opts auth("user:token"), slow(초) */
    public function start(array $opts = []): int
    {
        $this->stop();
        $cfg = $this->root . '/git_http.json';
        file_put_contents($cfg, json_encode(['root' => $this->served, 'backend' => self::httpBackend(), 'log' => $this->log] + $opts));
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
        fclose($s);
        $env = getenv() + ['PJG_GIT_HTTP_CONFIG' => $cfg];
        $env['PJG_GIT_HTTP_CONFIG'] = $cfg;
        $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->port, PJG_ROOT . '/tests/Support/git_http_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.out', 'a'], 2 => ['file', $this->root . '/server.out', 'a']], $pipes, $this->root, $env, ['bypass_shell' => true]);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $en, $es, 0.2);
            if ($c !== false) {
                fclose($c);
                return $this->port;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('시험 HTTP 서버가 뜨지 않음');
    }

    public function url(string $name, string $prefix = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . $prefix . '/' . $name . '.git';
    }

    /** @return list<array{method:string, uri:string, auth:string}> */
    public function requests(): array
    {
        return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), file(is_file($this->log) ? $this->log : 'php://memory') ?: [])));
    }

    public function stop(): void
    {
        if (is_resource($this->server)) {
            $st = proc_get_status($this->server);
            if ($st['running']) {
                ProcessTree::kill((int) $st['pid']);
            }
            proc_close($this->server);
        }
        $this->server = null;
    }

    /** 서버를 멈추고 읽기 전용 git 객체 파일에 권한을 준다(시험 임시 폴더를 지울 수 있게) */
    public function release(): void
    {
        $this->stop();
        if (is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
                @chmod($f->getPathname(), $f->isDir() ? 0777 : 0666);
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
