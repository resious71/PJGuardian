<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Support\Fs;

/**
 * git·p4 한 번의 실행 묶음이 쓰는 격리 폴더와 환경 변수(P25, 명세 18.4, X09 4절).
 *
 *   <root>/home    HOME·USERPROFILE·TEMP — 사용자 전역 설정·자격 증명 도우미를 읽지 않게(빈 폴더)
 *   <root>/hooks   빈 폴더 — core.hooksPath·init.templateDir(저장소·템플릿의 hook을 실행하지 않음)
 *   <root>/p4      P4ENVIRO·P4TICKETS·P4TRUST 파일(0600, 끝나면 폴더째 삭제)
 *
 * PATH는 최소(Windows System32, Linux /usr/bin:/bin)만 — 다른 git·p4·도우미를 찾지 않게. 끝나면 remove()로 지운다
 * (git 객체 파일은 읽기 전용이라 권한을 준 뒤 지운다).
 */
final class VcsSandbox
{
    public readonly string $home;
    public readonly string $hooks;
    public readonly string $p4;

    public function __construct(public readonly string $root)
    {
        $this->home = $root . '/home';
        $this->hooks = $root . '/hooks';
        $this->p4 = $root . '/p4';
        foreach ([$this->home, $this->hooks, $this->p4] as $d) {
            Fs::ensureDir($d);
            @chmod($d, 0700);
        }
    }

    /** @param array<string, string> $extra @return array<string, string> */
    public function gitEnv(array $extra = []): array
    {
        return ProcessRunner::minimalEnv($this->home, [], [
            'HOME' => $this->home,
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->home . '/.gitconfig-none',
            'XDG_CONFIG_HOME' => $this->home . '/.config',
            'GIT_CEILING_DIRECTORIES' => dirname($this->root),
            'GIT_TERMINAL_PROMPT' => '0',
            'GCM_INTERACTIVE' => 'never',
            'GIT_LFS_SKIP_SMUDGE' => '1',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_PROTOCOL_FROM_USER' => '0',
            'GIT_ASKPASS' => '',
            'SSH_ASKPASS' => '',
        ] + $extra);
    }

    /** @param array<string, string> $extra @return array<string, string> */
    public function p4Env(array $extra = []): array
    {
        foreach (['p4enviro', 'p4tickets', 'p4trust'] as $f) {
            $file = $this->p4 . '/' . $f;
            if (!is_file($file)) {
                touch($file);
                @chmod($file, 0600);
            }
        }
        return ProcessRunner::minimalEnv($this->home, [], [
            'HOME' => $this->home,
            // 빈 값은 Windows에서 환경 변수가 사라져(레지스트리의 p4 set 값이 쓰일 수 있음) 없을 이름으로 둔다 — 설정 파일을 찾지 않는 효과
            'P4CONFIG' => 'pjguardian-no-p4config',
            'P4ENVIRO' => $this->p4 . '/p4enviro',
            'P4TICKETS' => $this->p4 . '/p4tickets',
            'P4TRUST' => $this->p4 . '/p4trust',
            'P4CHARSET' => 'auto',
            'P4CLIENT' => 'pjguardian-readonly-no-client',
        ] + $extra);
    }

    public function remove(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        // 시간 초과로 끝낸 프로세스가 잠깐 파일을 쥐고 있을 수 있어(Windows) 짧게 몇 번 다시 시도한다(최대 약 3초)
        for ($try = 0; $try < 15 && is_dir($this->root); $try++) {
            if ($try > 0) {
                usleep(200_000);
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
                @chmod($f->getPathname(), $f->isDir() ? 0700 : 0600);
            }
            Fs::removeTree($this->root);
        }
    }

    public static function exeSuffix(): string
    {
        return ProcessTree::isWindows() ? '.exe' : '';
    }
}
