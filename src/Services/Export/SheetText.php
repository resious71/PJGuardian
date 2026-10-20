<?php

declare(strict_types=1);

namespace PJGuardian\Services\Export;

/**
 * 내보내기 셀 문자열 처리(P23, 한 곳). 수식·스프레드시트 주입 방지와 XML 안전.
 *
 * - XML에 쓸 수 없는 문자(U+0000~U+0008, U+000B, U+000C, U+000E~U+001F, U+FFFE·U+FFFF, 깨진 UTF-8·짝 없는 서로게이트)는 지운다.
 * - Excel 셀 한도 32,767자를 넘으면 잘라 표시(문자열 export.truncated — "…(생략)"·"…(truncated)")를 붙인다(한도 안에서).
 * - CSV: 값이 =, +, -, @, 탭, CR, LF로 시작하면 앞에 작은따옴표(')를 붙여 수식으로 해석되지 않게 한다(CLAUDE.md 코딩 규칙).
 *   XLSX는 모든 문자열을 인라인 문자열 셀(t="inlineStr")로만 쓰고 수식 셀(<f>)을 만들지 않으므로 접두가 필요 없다.
 */
final class SheetText
{
    public const CELL_MAX = 32767;
    /** 수식으로 해석될 수 있는 시작 글자 */
    private const DANGEROUS_START = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /** XML·셀 한도에 맞춘 문자열 */
    public static function clean(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); // 깨진 바이트 → ?
        }
        $s = (string) preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{FFFE}\x{FFFF}]/u', '', $s);
        if (mb_strlen($s) > self::CELL_MAX) {
            $tail = \PJGuardian\Support\Lang::get('export.truncated'); // 내보내는 화면 언어(P27D)
            $s = mb_substr($s, 0, self::CELL_MAX - mb_strlen($tail)) . $tail;
        }
        return $s;
    }

    /** 수식으로 해석될 수 있는가 */
    public static function dangerous(string $s): bool
    {
        return $s !== '' && in_array($s[0], self::DANGEROUS_START, true);
    }

    /** CSV 셀 값: 정리 + 위험한 시작 글자 무력화 */
    public static function csv(string $s): string
    {
        $s = self::clean($s);
        return self::dangerous($s) ? "'" . $s : $s;
    }
}
