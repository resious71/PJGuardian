<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

/**
 * 분석 대상 파일 목록(도구가 실제로 분석한 파일, 명세 19.10). 정렬한 NFC 경로 배열 + 이진 탐색.
 * 저장 형식 `zlib-lines-v1`(analysis_file_lists.files_blob): 정렬한 경로를 "\n"으로 이어 gzcompress.
 * **길이 0인 BLOB(x'')은 "정리됨(목록 없음)"**이다(스냅샷 정리로 비운 분석, docs/db_schema.md 3.6).
 */
final class ScopeList
{
    /** @param list<string> $paths 정렬·중복 제거된 경로 */
    private function __construct(private readonly array $paths)
    {
    }

    /** @param list<string> $paths 아무 순서·형식(정규화한다) */
    public static function fromPaths(array $paths): self
    {
        foreach ($paths as $i => $p) {
            $paths[$i] = SourceNormalizer::path($p);
        }
        return new self(self::sortUnique(array_values($paths)));
    }

    /**
     * 범위 파일(한 줄에 경로 하나, ScopeWriter가 NFC로 정규화해 씀). 없으면 빈 목록.
     * 메모리: 경로 배열 하나만 만든다(정렬은 제자리, 중복 제거도 같은 배열 안에서).
     */
    public static function fromFile(string $file): self
    {
        $paths = [];
        $h = @fopen($file, 'rb');
        if ($h !== false) {
            while (($line = fgets($h)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    $paths[] = $line;
                }
            }
            fclose($h);
        }
        return new self(self::sortUnique($paths));
    }

    /**
     * @param list<string> $a
     * @return list<string>
     */
    private static function sortUnique(array $a): array
    {
        sort($a, SORT_STRING);
        $n = count($a);
        $j = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($j === 0 || $a[$i] !== $a[$j - 1]) {
                $a[$j++] = $a[$i];
            }
        }
        for ($k = $n - 1; $k >= $j; $k--) {
            unset($a[$k]); // 뒤에서부터 지우면 packed 배열을 유지한다(복사 없음)
        }
        return $a;
    }

    /** files_blob → 목록. 빈 BLOB(정리됨)이면 null */
    public static function decode(string $blob): ?self
    {
        if ($blob === '') {
            return null;
        }
        $raw = @gzuncompress($blob);
        if ($raw === false) {
            throw new \RuntimeException('파일 목록 BLOB을 풀 수 없음');
        }
        return new self($raw === '' ? [] : explode("\n", $raw));
    }

    public function encode(): string
    {
        return (string) gzcompress(implode("\n", $this->paths), 6);
    }

    public function count(): int
    {
        return count($this->paths);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function contains(string $path): bool
    {
        $lo = 0;
        $hi = count($this->paths) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            $c = strcmp($this->paths[$mid], $path);
            if ($c === 0) {
                return true;
            }
            if ($c < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return false;
    }
}
