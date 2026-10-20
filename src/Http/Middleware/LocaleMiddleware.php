<?php

declare(strict_types=1);

namespace PJGuardian\Http\Middleware;

use PJGuardian\Http\Middleware;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\UiPreferences;
use PJGuardian\Support\Lang;

/**
 * 요청의 화면 언어·테마를 정한다(P27D, 결정은 Services\UiPreferences 한 곳).
 * 두 번 둔다: 유지보수 안내(세션 전)를 위해 쿠키·Accept-Language만으로 한 번, 세션 뒤에 계정 값을 더해 한 번.
 * 결과는 Lang::setLocale()과 요청 속성 ui_lang·ui_theme(뷰의 <html lang>·data-theme).
 * 로그인한 계정 값이 비어 있고 유효한 쿠키가 있으면 그 값을 계정에 한 번 옮겨 저장한다($adopt).
 */
final class LocaleMiddleware implements Middleware
{
    /** @param (\Closure(int, ?string, ?string): void)|null $adopt 계정에 쿠키 값 저장(사용자 id, 언어, 테마 — 옮길 것만) */
    public function __construct(private readonly bool $withAccount, private readonly ?\Closure $adopt = null)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $cookieLang = UiPreferences::validLanguage($request->cookie(UiPreferences::LANG_COOKIE));
        $cookieTheme = UiPreferences::validTheme($request->cookie(UiPreferences::THEME_COOKIE));
        $account = $this->withAccount ? $request->attribute('user_ui') : null;
        $user = $this->withAccount ? $request->attribute('user') : null;
        $accLang = is_array($account) ? UiPreferences::validLanguage($account['language'] ?? null) : null;
        $accTheme = is_array($account) ? UiPreferences::validTheme($account['theme'] ?? null) : null;
        if (is_array($user) && $this->adopt !== null && (($accLang === null && $cookieLang !== null) || ($accTheme === null && $cookieTheme !== null))) {
            try {
                ($this->adopt)((int) $user['id'], $accLang === null ? $cookieLang : null, $accTheme === null ? $cookieTheme : null);
                $accLang ??= $cookieLang;
                $accTheme ??= $cookieTheme;
            } catch (\Throwable) {
                // 옮기기 실패는 화면에 영향 없음(다음 요청에 다시)
            }
        }
        $lang = UiPreferences::language($accLang, $cookieLang, $request->header('accept-language'));
        $theme = UiPreferences::theme($accTheme, $cookieTheme);
        Lang::setLocale($lang);
        return $next($request->withAttribute('ui_lang', $lang)->withAttribute('ui_theme', $theme)
            ->withAttribute('ui_account', ['language' => $accLang, 'theme' => $accTheme]));
    }
}
