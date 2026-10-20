<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\UiPreferences;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\TestCase;

/**
 * 화면 언어(P27D): ko·en 리소스 일치(키·자리표시자·빈 값·영어에 한글 없음), 복수형·대체, 언어·테마 결정 순서, 하드코딩 한국어 점검.
 * JS 리소스는 tests/js/i18n.test.mjs가 같은 검사를 한다.
 */
final class I18nTest extends TestCase
{
    /**
     * 화면에 나가지 않는 한글(개발자 오류·내부 확인)만 허용 — 파일과 사유를 함께 둔다(docs/ui_conventions.md).
     * 새 화면 문구는 여기에 더하지 말고 ko·en 리소스로.
     */
    public const JS_HANGUL_ALLOW = [
        'public/assets/js/core/dom.js' => '개발자 오류(잘못된 속성·주소를 el()에 넘김 — 화면 문구 아님)',
        'public/assets/js/core/time.js' => '개발자 오류(UTC ISO 형식이 아닌 값)',
        'public/assets/js/lib/defect_search.js' => '개발자 오류(검색 영역 키가 아님)',
        'public/assets/js/ui/icons.js' => '개발자 오류·콘솔 경고(매핑에 없는 아이콘 이름·크기)',
    ];

    public function setUp(): void
    {
        Lang::setLocale('ko');
    }

    public function tearDown(): void
    {
        Lang::setLocale('ko');
    }

    /** @return list<string> 자리표시자 이름(복수형 {n:…|…}은 n) */
    private static function names(string $s): array
    {
        preg_match_all('/\{(\w+)(?::[^{}|]*\|[^{}]*)?\}/u', $s, $m);
        $n = array_values(array_unique($m[1]));
        sort($n);
        return $n;
    }

    public function testKoEnResourcesHaveSameKeysPlaceholdersAndNoHangulInEnglish(): void
    {
        $ko = Lang::load('ko');
        $en = Lang::load('en');
        $this->assertSame([], array_values(array_diff(array_keys($ko), array_keys($en))), 'en에 없는 키');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($ko))), 'ko에 없는 키');
        $bad = [];
        foreach ($ko as $k => $v) {
            $e = $en[$k];
            if (self::names($v) !== self::names($e)) {
                $bad[] = "자리표시자 {$k}";
            }
            if (($v === '') !== ($e === '')) {
                $bad[] = "빈 값 {$k}";
            }
            if (preg_match('/[\x{AC00}-\x{D7A3}]/u', $e) === 1) {
                $bad[] = "영어에 한글 {$k}";
            }
            if (preg_match_all('/\{(\w+):/u', $e, $m) > 0 && array_diff($m[1], self::names($v)) !== []) {
                $bad[] = "복수형 이름 {$k}";
            }
        }
        $this->assertSame([], $bad);
        $this->assertTrue(count($ko) > 1900);
    }

    public function testLocaleFallbackAndPluralForms(): void
    {
        $this->assertSame('ko', Lang::locale());
        $ko = Lang::get('ui_switch.theme_menu');
        Lang::setLocale('en');
        $this->assertSame('Theme', Lang::get('ui_switch.theme_menu'));
        $this->assertSame('nope.missing.key', Lang::get('nope.missing.key'), '없는 키는 키 그대로(시험이 잡음)');
        Lang::setLocale('fr');
        $this->assertSame('ko', Lang::locale(), '허용 밖이면 ko');
        $this->assertSame($ko, Lang::get('ui_switch.theme_menu'));
        $this->assertSame('1 defect', Lang::format('{n} {n:defect|defects}', ['n' => 1]));
        $this->assertSame('1,234 defects', Lang::format('{n} {n:defect|defects}', ['n' => '1,234']));
        $this->assertSame('0 defects', Lang::format('{n} {n:defect|defects}', ['n' => 0]));
        $this->assertSame('{n} {n:defect|defects}', Lang::format('{n} {n:defect|defects}', []), '값이 없으면 그대로');
        $this->assertSame('결함 3건', Lang::format('결함 {n}건', ['n' => 3]));
    }

    public function testLanguageAndThemeDecisionOrder(): void
    {
        $this->assertSame('en', UiPreferences::language('en', 'ko', 'ko-KR'), '계정 > 쿠키 > 브라우저');
        $this->assertSame('ko', UiPreferences::language(null, 'ko', 'en-US,en;q=0.9'), '쿠키 > 브라우저');
        $this->assertSame('en', UiPreferences::language(null, null, 'en-US,en;q=0.9,ko;q=0.8'));
        $this->assertSame('ko', UiPreferences::language(null, null, 'ko-KR,ko;q=0.9,en;q=0.8'));
        $this->assertSame('ko', UiPreferences::language(null, null, 'fr-FR,fr;q=0.9'), '그 밖의 언어는 ko');
        $this->assertSame('en', UiPreferences::language(null, null, 'fr;q=0.5, en;q=0.8'), 'q가 큰 것');
        $this->assertSame('ko', UiPreferences::language(null, null, null));
        $this->assertSame('ko', UiPreferences::language(null, null, ''));
        $this->assertSame('en', UiPreferences::language('xx', 'zz', 'en'), '허용 밖 계정·쿠키 값은 무시');
        $this->assertSame('ko', UiPreferences::language(null, '<script>', '*'));
        $this->assertSame('dark', UiPreferences::theme('dark', 'light'));
        $this->assertSame('light', UiPreferences::theme(null, 'light'));
        $this->assertSame('system', UiPreferences::theme(null, null), '기본은 시스템 따라가기');
        $this->assertSame('system', UiPreferences::theme('purple', 'neon'));
    }

    public function testNoHardcodedHangulInViewsAndScripts(): void
    {
        $found = [];
        $hangul = '/[\x{AC00}-\x{D7A3}\x{3131}-\x{318E}]/u';
        // 뷰: PHP 주석 밖의 문자열·HTML(화면에 나가는 것)
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src/Views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            foreach (token_get_all((string) file_get_contents((string) $f)) as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) && preg_match($hangul, $tok[1]) === 1) {
                    $found[] = str_replace('\\', '/', substr((string) $f, strlen(PJG_ROOT) + 1)) . ':' . $tok[2];
                }
            }
        }
        // 컨트롤러: 화면 응답은 문자열 리소스로(로그 메시지 줄은 제외 — 운영자용)
        foreach (glob(PJG_ROOT . '/src/Controllers/*.php') ?: [] as $f) {
            foreach (token_get_all((string) file_get_contents($f)) as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match($hangul, $tok[1]) === 1) {
                    $line = explode("\n", (string) file_get_contents($f))[$tok[2] - 1] ?? '';
                    if (preg_match('/->(info|warn|error|debug)\(/', $line) !== 1) {
                        $found[] = 'src/Controllers/' . basename($f) . ':' . $tok[2];
                    }
                }
            }
        }
        // 화면 스크립트: 주석을 걷어 낸 코드의 한글(i18n·vendor 제외, 허용 목록은 개발자 오류만)
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/public/assets/js', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr((string) $f, strlen(PJG_ROOT) + 1));
            if (!str_ends_with($rel, '.js') || str_contains($rel, '/i18n/') || str_contains($rel, '/vendor/') || isset(self::JS_HANGUL_ALLOW[$rel])) {
                continue;
            }
            $src = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents((string) $f));
            foreach (explode("\n", $src) as $i => $line) {
                if (preg_match($hangul, (string) preg_replace('#(^|[^:])//.*$#', '$1', $line)) === 1) {
                    $found[] = "{$rel}:" . ($i + 1);
                }
            }
        }
        $this->assertSame([], $found, '하드코딩 한국어(ko·en 리소스로 옮길 것)');
        foreach (array_keys(self::JS_HANGUL_ALLOW) as $rel) {
            $this->assertTrue(is_file(PJG_ROOT . '/' . $rel), "허용 목록 파일 있음: {$rel}");
        }
    }
}
