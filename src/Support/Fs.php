<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 파일시스템 유틸 (명세 19.10의 Windows 규칙 반영)
 * - 디렉토리 열거는 scandir로 한다. glob('*')은 점(.)으로 시작하는 파일(.lock 등)을 빼먹는다.
 * - 재귀 삭제는 심볼릭 링크를 따라가지 않는다(링크 자체만 지운다).
 * - 원자적 교체: 같은 디렉토리의 임시 파일에 쓴 뒤 rename.
 */
final class Fs
{
    /**
     * 디렉토리의 항목 이름(점 파일 포함, '.'·'..' 제외). 디렉토리가 없으면 빈 목록.
     *
     * @return list<string>
     */
    public static function listDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }
        return array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..'));
    }

    /** 디렉토리를 통째로 지운다. 이미 없으면 true. */
    public static function removeTree(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path) || self::removeLinkDir($path);
        }
        if (!is_dir($path)) {
            return true;
        }
        foreach (self::listDir($path) as $name) {
            self::removeTree($path . DIRECTORY_SEPARATOR . $name);
        }
        return @rmdir($path) || !file_exists($path);
    }

    /** Windows의 디렉토리 심볼릭 링크·정션은 unlink 대신 rmdir로 지워야 한다(대상 내용은 건드리지 않음). */
    private static function removeLinkDir(string $path): bool
    {
        return is_link($path) && @rmdir($path);
    }

    /** 원자적으로 파일 내용을 바꾼다(쓰는 도중에 다른 프로세스가 반쯤 쓴 파일을 보지 않게). */
    public static function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('디렉토리를 만들 수 없음: ' . $dir);
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('임시 파일을 쓸 수 없음: ' . $tmp);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('파일을 교체할 수 없음: ' . $path);
        }
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('디렉토리를 만들 수 없음: ' . $dir);
        }
    }
}
