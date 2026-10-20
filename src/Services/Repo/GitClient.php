<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Services\Process\ProcessResult;

/**
 * Git 읽기 전용 접근(P25, 명세 18.1·18.3·18.4). `bin/`의 git(체크섬 검증된 진입점)만, 셸 없이 인자 배열로 실행한다.
 *
 * 강제 설정(명령행 `-c`, 저장소·사용자·시스템 설정보다 우선): hook 끔(core.hooksPath·init.templateDir = 빈 폴더), fsmonitor 끔,
 * submodule 재귀 끔, LFS 필터 비움(filter.lfs.*), 프로토콜 허용 목록(protocol.allow=never + https(+ http는 등록된 호스트만)),
 * 리다이렉트 따라가지 않음(http.followRedirects=false), 인증서 검증 켬(http.sslVerify=true, 사내 CA는 repository.ca_cert_file),
 * 자격 증명 도우미 끔(credential.helper=), 줄바꿈 변환 끔(core.autocrlf=false), 심볼릭 링크를 파일로(core.symlinks=false),
 * 판정한 주소로 고정(http.curloptResolve=host:port:ip — DNS 리바인딩 방지). 환경 변수: VcsSandbox::gitEnv(GIT_CONFIG_NOSYSTEM 등).
 *
 * 토큰: URL·명령행에 넣지 않는다. 환경 변수 GIT_CONFIG_COUNT/KEY/VALUE로 `http.<저장소 주소>.extraHeader = Authorization: Basic …`
 * (사용자명:토큰, 사용자명이 없으면 x-access-token — GitHub·GitHub Enterprise·대부분의 HTTP Git 서버가 받는 형식).
 *
 * 가져오기: `clone --bare --depth 1 --single-branch --no-tags [--branch B]`(작업 트리를 만들지 않으므로 체크아웃·필터·hook이 돌 일이 없다)
 * → `rev-parse` 커밋 → `archive --format=zip <커밋>[:<하위 경로>]`(zip으로 받아 업로드와 같은 안전 해제를 탄다).
 */
final class GitClient
{
    /** @param list<string> $command git 실행 명령(보통 [bin/git/<버전>/<os>/…/git(.exe)]) */
    public function __construct(
        private readonly array $command,
        private readonly VcsProcess $proc,
        private readonly ?string $caFile = null,
    ) {
    }

    /** @return list<string> 강제 설정(-c) */
    public function safetyArgs(GitTarget $t, VcsSandbox $sb): array
    {
        $c = [
            'core.hooksPath=' . $sb->hooks, 'init.templateDir=' . $sb->hooks, 'core.fsmonitor=false', 'core.autocrlf=false', 'core.symlinks=false',
            'core.longpaths=true', 'submodule.recurse=false', 'fetch.recurseSubmodules=false', 'filter.lfs.smudge=', 'filter.lfs.clean=',
            'filter.lfs.process=', 'filter.lfs.required=false', 'protocol.allow=never', 'protocol.https.allow=always',
            'protocol.http.allow=' . ($t->scheme === 'http' ? 'always' : 'never'), 'protocol.file.allow=never', 'protocol.ext.allow=never',
            'http.followRedirects=false', 'http.sslVerify=true', 'credential.helper=', 'core.askPass=', 'advice.detachedHead=false',
            'gc.auto=0', 'maintenance.auto=false',
        ];
        if ($t->pinnedIp !== null) {
            $c[] = 'http.curloptResolve=' . $t->host . ':' . $t->port . ':' . (str_contains($t->pinnedIp, ':') ? '[' . $t->pinnedIp . ']' : $t->pinnedIp);
        }
        if ($this->caFile !== null && $this->caFile !== '') {
            $c[] = 'http.sslCAInfo=' . $this->caFile;
        }
        $out = [];
        foreach ($c as $kv) {
            $out[] = '-c';
            $out[] = $kv;
        }
        return $out;
    }

    /** @return array<string, string> 인증 헤더(환경 변수로만) */
    public function authEnv(GitTarget $t): array
    {
        $env = ['GIT_ALLOW_PROTOCOL' => $t->scheme === 'http' ? 'https:http' : 'https'];
        if ($t->token === null) {
            return $env;
        }
        $scope = $t->scheme . '://' . (str_contains($t->host, ':') ? '[' . $t->host . ']' : $t->host) . ':' . $t->port . '/';
        $cred = base64_encode(($t->username ?? 'x-access-token') . ':' . $t->token);
        // 기본 포트는 git이 주소에서 포트를 빼므로 두 형태를 모두 등록
        $env['GIT_CONFIG_COUNT'] = '2';
        $env['GIT_CONFIG_KEY_0'] = 'http.' . $scope . '.extraHeader';
        $env['GIT_CONFIG_VALUE_0'] = 'Authorization: Basic ' . $cred;
        $env['GIT_CONFIG_KEY_1'] = 'http.' . $t->scheme . '://' . (str_contains($t->host, ':') ? '[' . $t->host . ']' : $t->host) . '/.extraHeader';
        $env['GIT_CONFIG_VALUE_1'] = 'Authorization: Basic ' . $cred;
        return $env;
    }

    /**
     * 연결 테스트(읽기만): ls-remote로 브랜치(또는 기본 브랜치)와 그 커밋을 확인한다.
     *
     * @return array{branch:string, commit:string}
     */
    public function test(GitTarget $t, VcsSandbox $sb, float $timeout): array
    {
        $args = $t->branch !== null ? ['ls-remote', '--', $t->url, 'refs/heads/' . $t->branch] : ['ls-remote', '--symref', '--', $t->url, 'HEAD'];
        $r = $this->git($t, $sb, $args, $sb->home, $timeout, 'ls-remote');
        $out = $r->stdout(1024 * 1024);
        $r->cleanup();
        $branch = $t->branch;
        $commit = null;
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            if (preg_match('#^ref: refs/heads/(\S+)\tHEAD$#', $line, $m) === 1) {
                $branch = $m[1];
            } elseif (preg_match('#^([0-9a-f]{40}|[0-9a-f]{64})\t(HEAD|refs/heads/\S+)$#', $line, $m) === 1) {
                $commit = $m[1];
            }
        }
        if ($commit === null || $branch === null) {
            throw new RepoException($out === '' && $t->branch === null ? 'repo_empty' : 'repo_not_found', ['what' => $t->branch !== null ? 'branch' : 'repo']);
        }
        return ['branch' => $branch, 'commit' => $commit];
    }

    /**
     * 가져오기: 얕은 bare 복제 → 커밋 → zip. 복제 폴더·zip 크기가 $maxBytes를 넘으면 repo_limit_exceeded.
     *
     * @param (\Closure(): bool)|null $cancel
     * @return array{branch:string, commit:string}
     */
    public function fetch(GitTarget $t, VcsSandbox $sb, string $stage, string $zip, int $maxBytes, float $deadline, ?\Closure $cancel): array
    {
        $bare = $stage . '/repo.git';
        $args = ['clone', '--bare', '--depth', '1', '--single-branch', '--no-tags', '--quiet'];
        if ($t->branch !== null) {
            array_push($args, '--branch', $t->branch);
        }
        array_push($args, '--', $t->url, $bare);
        $r = $this->git($t, $sb, $args, $stage, self::left($deadline), 'clone', $cancel, static fn (): bool => self::dirSize($bare) > $maxBytes);
        $r->cleanup();
        $r = $this->git($t, $sb, ['--git-dir=' . $bare, 'rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], $stage, self::left($deadline), 'rev-parse', $cancel);
        $commit = trim($r->stdout(4096));
        $r->cleanup();
        if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/', $commit) !== 1) {
            throw new RepoException('repo_empty');
        }
        $branch = $t->branch;
        if ($branch === null) {
            $r = $this->git($t, $sb, ['--git-dir=' . $bare, 'symbolic-ref', '--quiet', '--short', 'HEAD'], $stage, self::left($deadline), 'symbolic-ref', $cancel);
            $branch = trim($r->stdout(4096)) ?: 'HEAD';
            $r->cleanup();
        }
        $treeish = $commit . ($t->subPath !== null ? ':' . $t->subPath : '');
        $r = $this->git($t, $sb, ['--git-dir=' . $bare, 'archive', '--format=zip', '-o', $zip, $treeish], $stage, self::left($deadline), 'archive', $cancel,
            static fn (): bool => is_file($zip) && (int) @filesize($zip) > $maxBytes, notFound: $t->subPath !== null ? 'path' : 'repo');
        $r->cleanup();
        if (!is_file($zip)) {
            throw new RepoException('repo_fetch_failed');
        }
        return ['branch' => $branch, 'commit' => $commit];
    }

    /**
     * @param list<string> $args
     * @param (\Closure(): bool)|null $cancel
     * @param (\Closure(): bool)|null $overLimit
     */
    private function git(GitTarget $t, VcsSandbox $sb, array $args, string $cwd, float $timeout, string $what, ?\Closure $cancel = null,
        ?\Closure $overLimit = null, string $notFound = 'repo'): ProcessResult
    {
        $r = $this->proc->run([...$this->command, ...$this->safetyArgs($t, $sb), ...$args], $sb->gitEnv($this->authEnv($t)), $cwd, $timeout, $cancel, $overLimit);
        if ($r->exitCode !== 0) {
            $code = self::classify($r->stderr(65536), $what);
            $this->proc->logFailure('git ' . $what, $code, $r);
            $r->cleanup();
            throw new RepoException($code, $code === 'repo_not_found' ? ['what' => $notFound] : []);
        }
        return $r;
    }

    /** stderr → 오류 코드(원문은 화면에 내지 않는다) */
    public static function classify(string $stderr, string $what = ''): string
    {
        $s = strtolower($stderr);
        return match (true) {
            str_contains($s, 'ssl certificate') || str_contains($s, 'certificate verify') || str_contains($s, 'server certificate verification')
                || str_contains($s, 'unable to get local issuer') || str_contains($s, 'schannel') => 'repo_tls_untrusted',
            str_contains($s, 'authentication failed') || str_contains($s, 'could not read username') || str_contains($s, 'could not read password')
                || str_contains($s, 'access denied') || str_contains($s, 'returned error: 401') || str_contains($s, 'returned error: 403')
                || str_contains($s, 'invalid username or password') || str_contains($s, 'terminal prompts disabled') => 'repo_auth_failed',
            preg_match('/returned error: 30[1278]/', $s) === 1 || str_contains($s, 'redirect') => 'repo_host_not_allowed',
            str_contains($s, 'repository not found') || str_contains($s, 'returned error: 404') || str_contains($s, 'not found')
                || str_contains($s, 'does not appear to be a git repository') || str_contains($s, 'not a valid object name')
                || str_contains($s, 'not a tree object') || str_contains($s, 'pathspec') || str_contains($s, "couldn't find remote ref")
                || str_contains($s, 'remote branch') => 'repo_not_found',
            str_contains($s, 'could not resolve') || str_contains($s, 'failed to connect') || str_contains($s, 'connection refused')
                || str_contains($s, 'timed out') || str_contains($s, 'connection reset') || str_contains($s, 'could not connect')
                || str_contains($s, 'network is unreachable') || str_contains($s, 'empty reply') || str_contains($s, 'returned error: 5') => 'repo_unreachable',
            str_contains($s, 'protocol') && str_contains($s, 'not allowed') || str_contains($s, 'transport') && str_contains($s, 'not allowed') => 'repo_host_not_allowed',
            str_contains($s, 'empty repository') || str_contains($s, 'you appear to have cloned an empty') => 'repo_empty',
            default => 'repo_fetch_failed',
        };
    }

    private static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        // git이 쓰는 동안 재므로 잠깐 있다 사라지는 파일(*.lock 등)은 건너뛴다
        $n = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $size = @filesize($f->getPathname());
                $n += $size === false ? 0 : $size;
            }
        } catch (\UnexpectedValueException) {
            // 열거 중 폴더가 사라짐 — 이번 측정은 건너뛴다
        }
        return $n;
    }

    private static function left(float $deadline): float
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new RepoException('repo_timeout');
        }
        return $left;
    }
}
