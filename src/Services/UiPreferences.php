<?php

declare(strict_types=1);

namespace PJGuardian\Services;

/**
 * 화면 언어·테마 결정(P27D) — 서버의 한 곳. JS는 서버가 정한 <html lang>·data-theme를 그대로 쓴다.
 *
 * 언어: ① 계정 users.ui_language → ② 쿠키 pjg_lang → ③ 브라우저 Accept-Language(가장 선호하는 언어가 ko면 ko, en이면 en, 그 밖은 ko) → ④ ko.
 * 테마: ① 계정 users.ui_theme → ② 쿠키 pjg_theme → ③ system(운영체제 설정을 CSS prefers-color-scheme으로 따름).
 * 값은 허용 목록만(언어 ko·en, 테마 light·dark·system). 쿠키·헤더의 허용 밖 값은 없는 것으로 본다.
 */
final class UiPreferences
{
    public const LANGUAGES = ['ko', 'en'];
    public const THEMES = ['light', 'dark', 'system'];
    public const LANG_COOKIE = 'pjg_lang';
    public const THEME_COOKIE = 'pjg_theme';

    public static function validLanguage(mixed $v): ?string
    {
        return is_string($v) && in_array($v, self::LANGUAGES, true) ? $v : null;
    }

    public static function validTheme(mixed $v): ?string
    {
        return is_string($v) && in_array($v, self::THEMES, true) ? $v : null;
    }

    public static function language(?string $account, ?string $cookie, ?string $acceptLanguage): string
    {
        return self::validLanguage($account) ?? self::validLanguage($cookie) ?? self::fromAcceptLanguage($acceptLanguage);
    }

    public static function theme(?string $account, ?string $cookie): string
    {
        return self::validTheme($account) ?? self::validTheme($cookie) ?? 'system';
    }

    /** Accept-Language에서 가장 선호하는(q가 큰, 같으면 앞) 언어 하나 — ko면 ko, en이면 en, 그 밖은 ko */
    public static function fromAcceptLanguage(?string $header): string
    {
        $best = null;
        $bestQ = -1.0;
        foreach (explode(',', (string) $header) as $i => $part) {
            $part = trim($part);
            if ($part === '' || strlen($part) > 64) {
                continue;
            }
            $bits = explode(';', $part);
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $p) {
                if (preg_match('/^\s*q\s*=\s*([01](?:\.\d{1,3})?)\s*$/D', $p, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($tag === '' || $tag === '*' || $q <= 0.0 || $q <= $bestQ) {
                continue;
            }
            $best = $tag;
            $bestQ = $q;
        }
        $primary = $best === null ? '' : explode('-', $best)[0];
        return $primary === 'en' ? 'en' : 'ko';
    }
}
