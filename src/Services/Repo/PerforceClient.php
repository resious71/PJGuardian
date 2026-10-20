<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Support\Fs;

/**
 * Perforce 읽기 전용 접근(P25, 명세 18.1·18.3·18.4). `bin/`의 p4만, 셸 없이 인자 배열로.
 *
 * - **client(workspace)를 만들지 않는다**: 서버에 흔적을 남기지 않으려고 `p4 -G print <depot 경로>@<changelist>`로 파일 내용을 받는다
 *   (sync는 client가 필요하다). 최신 submitted changelist는 `p4 -G changes -m 1 -s submitted <경로>`.
 * - 환경: P4CONFIG 비움, P4ENVIRO·P4TICKETS·P4TRUST는 임시 폴더(0600, 끝나면 삭제), P4PORT는 판정한 주소로 고정, P4USER.
 *   자격 증명: 티켓은 P4PASSWD 환경 변수, 비밀번호는 `p4 login`에 **표준 입력**으로(명령행 인자 금지) → 티켓은 임시 P4TICKETS에만.
 * - SSL: 관리자가 입력한 서버 지문만 `p4 trust -i <지문>`으로 임시 P4TRUST에 등록한다. 지문이 없거나 다르면 repo_tls_untrusted
 *   (서버가 보낸 지문을 params.fingerprint로 돌려 연결 테스트 화면에 보여 준다 — 자동으로 신뢰하지 않는다).
 * - 쓰기 명령(submit·edit·client·sync 등)은 쓰지 않는다. login은 인증 티켓 발급뿐이다.
 */
final class PerforceClient
{
    /** @param list<string> $command p4 실행 명령(보통 [bin/p4/<버전>/<os>/p4(.exe)], 시험은 가짜 스크립트) */
    public function __construct(private readonly array $command, private readonly VcsProcess $proc)
    {
    }

    /** @return array{change:int} 경로의 최신 submitted changelist(읽기 권한·경로 존재 확인) */
    public function test(P4Target $t, VcsSandbox $sb, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $env = $this->prepare($t, $sb, $deadline, null);
        return ['change' => $this->latestChange($t, $sb, $env, $deadline, null)];
    }

    /**
     * 최신 changelist의 파일을 $stage/files 아래에 쓴다. 파일 수·크기 한도를 넘으면 repo_limit_exceeded.
     *
     * @param (\Closure(): bool)|null $cancel
     * @return array{change:int, files:int, bytes:int, dir:string}
     */
    public function fetch(P4Target $t, VcsSandbox $sb, string $stage, int $maxBytes, int $maxFiles, float $deadline, ?\Closure $cancel): array
    {
        $env = $this->prepare($t, $sb, $deadline, $cancel);
        $change = $this->latestChange($t, $sb, $env, $deadline, $cancel);
        $r = $this->p4(['-G', 'print', $t->depotPath . '@' . $change], $env, $sb, $deadline, 'print', $cancel,
            outputLimit: $maxBytes + intdiv($maxBytes, 4) + 64 * 1024 * 1024);
        $dir = $stage . '/files';
        Fs::ensureDir($dir);
        $prefix = substr($t->depotPath, 0, -3); // '//depot/a/' (끝의 ... 제외)
        $h = fopen($r->stdoutPath, 'rb');
        $files = 0;
        $bytes = 0;
        $out = null;
        $links = ['count' => 0, 'paths' => []]; // P26B: 건너뛴 심볼릭 링크(링크 대상 내용은 쓰지 않는다)
        try {
            while (($d = P4Marshal::next($h)) !== null) {
                $code = (string) ($d['code'] ?? '');
                if ($code === 'stat') {
                    if ($out !== null) {
                        fclose($out);
                        $out = null;
                    }
                    $depot = (string) ($d['depotFile'] ?? '');
                    $action = (string) ($d['action'] ?? '');
                    $type = (string) ($d['type'] ?? '');
                    if (!str_starts_with($depot, $prefix) || in_array($action, ['delete', 'move/delete', 'purge', 'archive'], true)) {
                        continue;
                    }
                    $rel = rawurldecode(substr($depot, strlen($prefix))); // p4 표기의 %40 %23 %25 %2A
                    if ($rel === '' || str_contains($rel, '\\') || preg_match('#(^|/)\.\.?(/|$)|^/|[\x00-\x1F]#', $rel) === 1) {
                        // 경로 이탈 후보: 안전 규칙이므로 건너뛰지 않고 실패(P26B — 업로드·Git과 같게)
                        throw new RepoException('repo_unsafe_content', ['what' => 'path']);
                    }
                    if (str_contains($type, 'symlink')) {
                        // P26B: 심볼릭 링크는 따라가지도 파일로 만들지도 않고 건너뛴다(분석 대상·파일 수·크기에 넣지 않음) — 개수와 처음 20개 경로만
                        if (++$links['count'] <= 20) {
                            $links['paths'][] = $rel;
                        }
                        continue;
                    }
                    if (++$files > $maxFiles) {
                        throw new RepoException('repo_limit_exceeded');
                    }
                    Fs::ensureDir(dirname($dir . '/' . $rel));
                    $out = fopen($dir . '/' . $rel, 'wb');
                } elseif ($code === 'text' || $code === 'binary') {
                    if ($out !== null) {
                        $data = (string) ($d['data'] ?? '');
                        $bytes += strlen($data);
                        if ($bytes > $maxBytes) {
                            throw new RepoException('repo_limit_exceeded');
                        }
                        fwrite($out, $data);
                    }
                } elseif ($code === 'error') {
                    $msg = (string) ($d['data'] ?? '');
                    if (stripos($msg, 'no such file') !== false || stripos($msg, 'no file(s)') !== false) {
                        continue; // 빈 폴더 등 — 파일이 하나도 없으면 아래 repo_empty
                    }
                    $code = self::classify($msg);
                    $this->proc->logFailure('p4 print', $code, $r);
                    throw new RepoException($code);
                }
            }
        } finally {
            if ($out !== null) {
                fclose($out);
            }
            fclose($h);
            $r->cleanup();
        }
        if ($files === 0) {
            throw new RepoException('repo_empty');
        }
        return ['change' => $change, 'files' => $files, 'bytes' => $bytes, 'dir' => $dir, 'symlinks' => $links];
    }

    /**
     * 신뢰 등록(SSL)·로그인(비밀번호) 뒤 쓸 환경 변수.
     *
     * @param (\Closure(): bool)|null $cancel
     * @return array<string, string>
     */
    private function prepare(P4Target $t, VcsSandbox $sb, float $deadline, ?\Closure $cancel): array
    {
        $env = $sb->p4Env(['P4PORT' => $t->connectPort(), 'P4USER' => $t->username] + ($t->secretKind === 'ticket' && $t->secret !== null ? ['P4PASSWD' => $t->secret] : []));
        if ($t->ssl && $t->fingerprint !== null) {
            $this->p4(['trust', '-i', $t->fingerprint], $env, $sb, $deadline, 'trust', $cancel)->cleanup();
        }
        if ($t->secretKind === 'password' && $t->secret !== null) {
            $this->p4(['login'], $env, $sb, $deadline, 'login', $cancel, stdin: $t->secret . "\n")->cleanup();
        }
        return $env;
    }

    /** @param array<string, string> $env @param (\Closure(): bool)|null $cancel */
    private function latestChange(P4Target $t, VcsSandbox $sb, array $env, float $deadline, ?\Closure $cancel): int
    {
        $r = $this->p4(['-G', 'changes', '-m', '1', '-s', 'submitted', $t->depotPath], $env, $sb, $deadline, 'changes', $cancel);
        $dicts = P4Marshal::all($r->stdout(4 * 1024 * 1024));
        $r->cleanup();
        foreach ($dicts as $d) {
            if (($d['code'] ?? null) === 'error') {
                $code = self::classify((string) ($d['data'] ?? ''));
                throw new RepoException($code === 'repo_fetch_failed' ? 'repo_not_found' : $code, ['what' => 'path']);
            }
            if (($d['code'] ?? null) === 'stat' && preg_match('/^[1-9][0-9]{0,11}$/', (string) ($d['change'] ?? '')) === 1) {
                return (int) $d['change'];
            }
        }
        throw new RepoException('repo_not_found', ['what' => 'path']); // 경로에 submitted changelist가 없음(경로 오타·권한 없음 포함)
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @param (\Closure(): bool)|null $cancel
     */
    private function p4(array $args, array $env, VcsSandbox $sb, float $deadline, string $what, ?\Closure $cancel,
        int $outputLimit = VcsProcess::SMALL_OUTPUT, ?string $stdin = null): ProcessResult
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new RepoException('repo_timeout');
        }
        $r = $this->proc->run([...$this->command, ...$args], $env, $sb->home, $left, $cancel, null, $outputLimit, $stdin);
        if ($r->exitCode !== 0) {
            $text = $r->stderr(65536) . "\n" . $r->stdout(65536);
            $code = self::classify($text);
            $this->proc->logFailure('p4 ' . $what, $code, $r);
            $params = [];
            if ($code === 'repo_tls_untrusted' && preg_match('/\b([0-9A-F]{2}(?::[0-9A-F]{2}){19,63})\b/', $text, $m) === 1) {
                $params['fingerprint'] = $m[1]; // 서버가 보낸 지문(관리자가 확인 후 입력하도록 화면에 보여 줌)
            }
            $r->cleanup();
            throw new RepoException($code, $params);
        }
        return $r;
    }

    public static function classify(string $text): string
    {
        $s = strtolower($text);
        return match (true) {
            str_contains($s, 'authenticity of') || str_contains($s, 'identification has changed') || str_contains($s, 'fingerprint')
                || str_contains($s, 'ssl') && (str_contains($s, 'handshake') || str_contains($s, 'trust')) => 'repo_tls_untrusted',
            str_contains($s, 'password') && (str_contains($s, 'invalid') || str_contains($s, 'unset')) || str_contains($s, 'session has expired')
                || str_contains($s, "doesn't exist") && str_contains($s, 'user') || str_contains($s, 'access for user')
                || str_contains($s, "don't have permission") || str_contains($s, 'protections table') || str_contains($s, 'login failed')
                || str_contains($s, 'please login again') => 'repo_auth_failed',
            str_contains($s, 'connect to server failed') || str_contains($s, 'tcp connect') || str_contains($s, 'connection refused')
                || str_contains($s, 'wsaeconnrefused') || str_contains($s, 'timed out') || str_contains($s, 'name or service not known')
                || str_contains($s, 'host not found') => 'repo_unreachable',
            str_contains($s, 'no such file') || str_contains($s, 'no file(s)') || str_contains($s, 'not in client view')
                || str_contains($s, 'must refer to client') || str_contains($s, 'invalid depot') => 'repo_not_found',
            default => 'repo_fetch_failed',
        };
    }
}
