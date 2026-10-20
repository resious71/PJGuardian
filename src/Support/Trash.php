<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 작업 디렉토리 휴지통: <work_dir>/trash/. 큰 디렉토리(해제한 파일 100만 개)를 지우는 데는 수 분이 걸릴 수 있어,
 * 워커 부모(감독 루프)는 지우지 않고 같은 볼륨 안의 rename으로 여기로 옮기기만 한다(즉시). 비우기는 워커가 띄우는
 * 자식(tools/empty_trash.php)과 정리 작업(tools/cleanup.php)이 한다. rename이 실패하면 호출자가 정리 대상 목록에 남긴다.
 */
final class Trash
{
    public function __construct(private readonly string $dir)
    {
    }

    public static function forWork(string $workRoot): self
    {
        return new self(rtrim(str_replace('\\', '/', $workRoot), '/') . '/trash');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** 옮겼으면 true(없는 경로도 true). 옮기지 못하면 false */
    public function move(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }
        Fs::ensureDir($this->dir);
        $target = $this->dir . '/' . basename(str_replace('\\', '/', $path)) . '-' . bin2hex(random_bytes(4));
        return @rename($path, $target);
    }

    public function isEmpty(): bool
    {
        return !is_dir($this->dir) || Fs::listDir($this->dir) === [];
    }

    /** @return int 지운 항목 수(지우지 못한 것은 남겨 다음에 다시) */
    public function empty(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $n = 0;
        foreach (Fs::listDir($this->dir) as $name) {
            if (Fs::removeTree($this->dir . '/' . $name)) {
                $n++;
            }
        }
        return $n;
    }
}
