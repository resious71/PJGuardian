<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 화면·오류 문자열 리소스(명세 1: 문자열은 리소스 파일로 분리, P27D: 한국어·영어).
 * PHP는 src/Lang/{ko,en}.php, JS는 public/assets/js/i18n/{ko,en}.js. 코드에 한글 메시지를 직접 쓰지 않고 키로 참조한다.
 *
 * - 현재 언어는 요청마다 `setLocale()`(LocaleMiddleware가 UiLanguage 결정으로). 기본 ko. 워커·명령줄은 ko.
 * - 영어에 키가 없으면 한국어로 대체한다(화면에 키 이름이 나오지 않게) — 키 불일치는 LangTest가 실패로 잡는다.
 * - 자리표시자: `{name}` → $params['name']. 복수형 `{name:단수|복수}` → 값이 1이면 단수, 아니면 복수(천 단위 쉼표 무시, docs/i18n_glossary.md 3절).
 */
final class Lang
{
    public const LOCALES = ['ko', 'en'];

    /** @var array<string, array<string, string>> */
    private static array $loaded = [];
    private static string $locale = 'ko';

    /** @param array<string, string|int|float> $params */
    public static function get(string $key, array $params = []): string
    {
        $messages = self::messages();
        if (!isset($messages[$key])) {
            // 키 누락은 프로그래밍 오류지만 화면을 깨지 않도록 키를 그대로 돌려준다(LangTest가 누락을 잡는다).
            return $key;
        }
        return self::format($messages[$key], $params);
    }

    /** 자리표시자·복수형 채우기(JS core/i18n.js format과 같은 규칙) @param array<string, string|int|float> $params */
    public static function format(string $text, array $params): string
    {
        if ($params === [] || !str_contains($text, '{')) {
            return $text;
        }
        return (string) preg_replace_callback('/\{(\w+)(?::([^{}|]*)\|([^{}]*))?\}/u', static function (array $m) use ($params): string {
            if (!array_key_exists($m[1], $params)) {
                return $m[0];
            }
            if (!isset($m[2])) {
                return (string) $params[$m[1]];
            }
            return self::isOne($params[$m[1]]) ? $m[2] : $m[3];
        }, $text);
    }

    public static function has(string $key): bool
    {
        return isset(self::messages()[$key]);
    }

    /** 현재 언어(ko·en). 허용 밖이면 ko */
    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::LOCALES, true) ? $locale : 'ko';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @return array<string, string> 현재 언어의 문구(영어는 한국어 위에 덧씌움 — 없는 키는 한국어) */
    public static function messages(): array
    {
        $ko = self::load('ko');
        return self::$locale === 'ko' ? $ko : (self::$loaded['merged:' . self::$locale] ??= self::load(self::$locale) + $ko);
    }

    /** @return array<string, string> 한 언어 파일 그대로(시험·키 비교용) */
    public static function load(string $locale): array
    {
        return self::$loaded[$locale] ??= require dirname(__DIR__) . '/Lang/' . $locale . '.php';
    }

    private static function isOne(mixed $v): bool
    {
        $n = str_replace([',', ' '], '', (string) $v);
        return is_numeric($n) && (float) $n == 1.0;
    }
}
