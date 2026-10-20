<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/** HTML 출력 이스케이프(명세 10: 화면에 출력하는 모든 사용자 입력은 이스케이프). */
final class Html
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
