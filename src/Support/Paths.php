<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * data/ 하위 경로 (설정 [paths]).
 * 사용자 입력으로 경로를 만들지 않는다. 하위 경로는 서버가 만든 ID나 검증된 정수로만 만든다.
 */
final class Paths
{
    /** 작업 디렉토리 ID 길이(16진수). 최종 경로 259자 제한 때문에 짧게 둔다(명세 19.10). */
    public const WORK_ID_LENGTH = 8;

    public function __construct(private readonly string $root, private readonly Config $config)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /** [paths]의 상대 경로를 프로젝트 루트 기준 절대 경로로 바꾼다. */
    public function resolve(string $configKey): string
    {
        $p = str_replace('\\', '/', $this->config->string('paths.' . $configKey));
        $isAbsolute = preg_match('#^([A-Za-z]:)?/#', $p) === 1;
        return rtrim($isAbsolute ? $p : $this->root . '/' . $p, '/');
    }

    public function logs(): string
    {
        return $this->resolve('logs_dir');
    }

    public function uploads(): string
    {
        return $this->resolve('uploads_dir');
    }

    public function snapshots(): string
    {
        return $this->resolve('snapshots_dir');
    }

    public function backups(): string
    {
        return $this->resolve('backups_dir');
    }

    public function dbFile(): string
    {
        return $this->resolve('db_file');
    }

    public function bin(): string
    {
        return $this->resolve('bin_dir');
    }

    public function work(): string
    {
        return $this->resolve('work_dir');
    }

    /**
     * 새 작업 디렉토리를 만든다: <work_dir>/<8자리 16진수>. 겹치면 다시 뽑는다.
     */
    public function newWorkDir(): string
    {
        Fs::ensureDir($this->work());
        for ($i = 0; $i < 10; $i++) {
            $dir = $this->work() . '/' . bin2hex(random_bytes(self::WORK_ID_LENGTH / 2));
            if (@mkdir($dir, 0700)) {
                return $dir;
            }
        }
        throw new \RuntimeException('작업 디렉토리를 만들 수 없음: ' . $this->work());
    }
}
