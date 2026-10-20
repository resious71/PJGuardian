<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 잠금 전용 파일(.lock)로만 하는 프로세스 간 잠금 (명세 19.10)
 *
 * Windows의 flock은 강제 잠금이라, 데이터 파일 자체를 잠그면 같은 요청에서도 그 파일을 읽거나
 * rename으로 바꿀 수 없다(P06에서 확인). 그래서 보호할 대상 옆의 별도 ".lock" 파일만 잠근다.
 *
 * 사용:
 *   $lock = FileLock::acquire($dir . '/.lock');           // 기다려서 잠금
 *   $lock = FileLock::tryAcquire($dir . '/.lock');        // 못 잡으면 null
 *   try { ... } finally { $lock->release(); }
 */
final class FileLock
{
    /** @param resource $handle */
    private function __construct(private $handle, public readonly string $path)
    {
    }

    public static function acquire(string $lockFile): self
    {
        $lock = self::open($lockFile, LOCK_EX);
        if ($lock === null) {
            throw new \RuntimeException('잠금을 얻을 수 없음: ' . $lockFile);
        }
        return $lock;
    }

    public static function tryAcquire(string $lockFile): ?self
    {
        return self::open($lockFile, LOCK_EX | LOCK_NB);
    }

    private static function open(string $lockFile, int $mode): ?self
    {
        if (!str_ends_with($lockFile, '.lock')) {
            throw new \InvalidArgumentException('잠금 파일 이름은 .lock으로 끝나야 합니다(데이터 파일을 직접 잠그지 않는다): ' . $lockFile);
        }
        Fs::ensureDir(dirname($lockFile));
        $h = @fopen($lockFile, 'c');
        if ($h === false) {
            throw new \RuntimeException('잠금 파일을 열 수 없음: ' . $lockFile);
        }
        if (!flock($h, $mode)) {
            fclose($h);
            return null;
        }
        return new self($h, $lockFile);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
