<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 화면(JS) 문구 사전 읽기(X17): 설정 관리 항목 이름(config_settings.key.<키>.label)처럼 JS 쪽에만 있는 문구를 서버가 같은 글자로 보여 줄 때.
 * public/assets/js/i18n/<언어>.js의 `'키': '값',` 줄만 읽는다(요청마다 한 번, 파일 수정 시각으로 캐시).
 */
final class JsStrings
{
    /** @var array<string, array{0:int, 1:array<string, string>}> */
    private static array $cache = [];

    public static function get(string $key, ?string $locale = null): ?string
    {
        $locale = in_array($locale ?? Lang::locale(), ['ko', 'en'], true) ? ($locale ?? Lang::locale()) : 'ko';
        return self::all($locale)[$key] ?? null;
    }

    /** @return array<string, string> */
    public static function all(string $locale): array
    {
        $file = PJG_ROOT . '/public/assets/js/i18n/' . ($locale === 'en' ? 'en' : 'ko') . '.js';
        $mtime = (int) @filemtime($file);
        if (!isset(self::$cache[$locale]) || self::$cache[$locale][0] !== $mtime) {
            $map = [];
            $src = (string) @file_get_contents($file);
            preg_match_all("/^\\s*(['\"])([A-Za-z0-9_.\\-]+)\\1:\\s*(['\"])((?:\\\\.|(?!\\3).)*)\\3,?\\s*$/mu", $src, $mm, PREG_SET_ORDER);
            foreach ($mm as $x) {
                $map[$x[2]] = stripcslashes($x[4]);
            }
            self::$cache[$locale] = [$mtime, $map];
        }
        return self::$cache[$locale][1];
    }
}
