<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 파일의 마지막 N줄을 끝에서부터 읽는다(P20A 로그 보기). 큰 로그 파일도 전체를 메모리에 올리지 않는다:
 * 64KB씩 뒤에서 앞으로 읽어 줄 수를 채우면 멈춘다. 한 줄이 너무 길면 maxLineBytes에서 자르고 표시한다.
 */
final class LogTail
{
    private const BLOCK = 65536;

    /**
     * @return array{lines: list<string>, bytes_read: int, truncated_lines: int}
     */
    public static function read(string $file, int $lines, int $maxLineBytes = 4096, int $maxBytes = 8 * 1024 * 1024): array
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return ['lines' => [], 'bytes_read' => 0, 'truncated_lines' => 0];
        }
        try {
            fseek($h, 0, SEEK_END);
            $pos = (int) ftell($h);
            $buf = '';
            $read = 0;
            // 마지막 줄바꿈 뒤의 빈 꼬리는 줄로 세지 않는다
            while ($pos > 0 && substr_count($buf, "\n") <= $lines && $read < $maxBytes) {
                $len = min(self::BLOCK, $pos);
                $pos -= $len;
                fseek($h, $pos);
                $chunk = (string) fread($h, $len);
                $read += strlen($chunk);
                $buf = $chunk . $buf;
            }
        } finally {
            fclose($h);
        }
        $all = preg_split('/\r?\n/', rtrim($buf, "\r\n")) ?: [];
        if ($pos > 0 && count($all) > 0) {
            array_shift($all); // 블록 경계에서 잘린 첫 줄
        }
        $all = array_slice($all, -$lines);
        $cut = 0;
        foreach ($all as &$l) {
            if (strlen($l) > $maxLineBytes) {
                $l = mb_strcut($l, 0, $maxLineBytes, 'UTF-8') . ' …';
                $cut++;
            }
        }
        unset($l);
        return ['lines' => array_values(array_filter($all, static fn (string $l): bool => $l !== '')), 'bytes_read' => $read, 'truncated_lines' => $cut];
    }
}
