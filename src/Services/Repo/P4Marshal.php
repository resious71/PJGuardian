<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

/**
 * `p4 -G` 출력(파이썬 marshal 버전 0의 사전 목록) 읽기·쓰기(P25). p4가 쓰는 형식만 다룬다:
 *   '{' 사전 시작, 키·값 쌍, '0' 사전 끝 / 's' 문자열(int32 LE 길이 + 바이트) / 'i' int32 / 'T' 'F' 'N'.
 * 그 밖의 형식이 나오면 RepoException(repo_fetch_failed) — 손상된 출력을 추측해 읽지 않는다.
 * 큰 출력(`p4 -G print`)은 파일 핸들에서 사전 하나씩 읽는다(전체를 메모리에 올리지 않음).
 */
final class P4Marshal
{
    /** 문자열 하나의 최대 길이(p4 print는 파일 내용을 조각으로 나눠 보낸다 — 조각보다 충분히 크게) */
    private const MAX_STRING = 256 * 1024 * 1024;

    /**
     * 다음 사전. 입력 끝이면 null.
     *
     * @param resource $h
     * @return array<string, string|int|bool|null>|null
     */
    public static function next($h): ?array
    {
        $t = fread($h, 1);
        if ($t === '' || $t === false) {
            return null;
        }
        if ($t !== '{') {
            throw new RepoException('repo_fetch_failed', ['what' => 'marshal']);
        }
        $out = [];
        while (true) {
            $kt = self::read($h, 1);
            if ($kt === '0') {
                return $out;
            }
            $key = self::value($h, $kt);
            if (!is_string($key)) {
                throw new RepoException('repo_fetch_failed', ['what' => 'marshal']);
            }
            $out[$key] = self::value($h, self::read($h, 1));
        }
    }

    /** @param resource $h */
    private static function value($h, string $type): string|int|bool|null
    {
        switch ($type) {
            case 's':
            case 'u':
            case 't':
                $len = unpack('V', self::read($h, 4))[1];
                if ($len > self::MAX_STRING) {
                    throw new RepoException('repo_fetch_failed', ['what' => 'marshal']);
                }
                return $len === 0 ? '' : self::read($h, $len);
            case 'i':
                $v = unpack('V', self::read($h, 4))[1];
                return $v >= 0x80000000 ? $v - 0x100000000 : $v;
            case 'T':
                return true;
            case 'F':
                return false;
            case 'N':
                return null;
            default:
                throw new RepoException('repo_fetch_failed', ['what' => 'marshal']);
        }
    }

    /** @param resource $h */
    private static function read($h, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($h, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                throw new RepoException('repo_fetch_failed', ['what' => 'marshal']);
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** 사전 하나를 marshal로(시험용 가짜 p4가 쓴다) @param array<string, string|int> $d */
    public static function encode(array $d): string
    {
        $out = '{';
        foreach ($d as $k => $v) {
            $out .= 's' . pack('V', strlen((string) $k)) . $k;
            $out .= is_int($v) ? 'i' . pack('V', $v & 0xFFFFFFFF) : 's' . pack('V', strlen((string) $v)) . $v;
        }
        return $out . '0';
    }

    /** 문자열 전체에서 사전 목록(작은 출력용) @return list<array<string, mixed>> */
    public static function all(string $bytes): array
    {
        $h = fopen('php://memory', 'w+b');
        fwrite($h, $bytes);
        rewind($h);
        $out = [];
        try {
            while (($d = self::next($h)) !== null) {
                $out[] = $d;
            }
        } finally {
            fclose($h);
        }
        return $out;
    }
}
