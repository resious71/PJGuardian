<?php

declare(strict_types=1);

namespace PJGuardian\Services\Guide;

use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigSchema;
use PJGuardian\Support\Icons;
use PJGuardian\Support\Lang;

/**
 * 가이드 본문 가공(P29): 자리표시자 채우기, 검사, 검색, 이미지 찾기.
 *
 * 자리표시자(렌더 전에 서버가 채움 — 모르는 형식·이름은 validate()가 실패):
 *   {{limit:<섹션.키>}}  지금 설정 값(설정 관리 해석 결과 — 환경 변수 > settings.local.ini > config.ini > 기본값)을 단위와 함께(1 GiB, 30분)
 *   {{version:app|ruff|eslint}}  앱 버전, 지금 분석에 쓰는 도구 버전(없으면 "설치 안 됨")
 *   {{ui:<문구 키>}}  화면 문구(PHP src/Lang 또는 JS i18n) — 단추 이름이 화면과 같게, 읽는 사람의 언어로
 *   {{legend:icons}}     아이콘 범례 표(X10, 사용자 가이드에서 혼자 한 줄 — 화면이 레지스트리에서 만든 범례로 바꾼다)
 *   {{icon:<의미 이름>}}  그대로 두고 화면 렌더러가 아이콘 + 접근 가능한 이름으로 바꾼다(이름은 여기서 검사)
 * 채운 값은 마크다운의 글자로 들어가고 화면 렌더러가 textContent로만 넣는다(HTML 주입 없음).
 */
final class GuideContent
{
    public const PLACEHOLDER_RE = '/\{\{([a-z]+):([A-Za-z0-9_.\-]+)\}\}/';
    public const IMAGE_EXT = ['png', 'webp', 'jpg', 'jpeg'];
    public const IMAGE_MAX_BYTES = 307200;
    public const IMAGE_MAX_WIDTH = 1600;

    /** @var array<string, array<string, string>> 언어 → JS 문구 */
    private static array $js = [];

    /** @param \Closure(string): ?string $toolVersion 도구 이름 → 지금 쓰는 버전(없으면 null) */
    public function __construct(
        private readonly GuideCatalog $catalog,
        private readonly Config $config,
        private readonly Icons $icons,
        private readonly string $appVersion,
        private readonly \Closure $toolVersion,
        private readonly string $root,
    ) {
    }

    public function catalog(): GuideCatalog
    {
        return $this->catalog;
    }

    /**
     * 화면·API용 절 목록(자리표시자 채움).
     *
     * @return list<array<string, mixed>>
     */
    public function rendered(string $audience, string $lang): array
    {
        $out = [];
        foreach ($this->catalog->sections($audience, $lang) as $s) {
            $useLang = $s['fallback'] ? 'ko' : $lang;
            $out[] = [
                'key' => $s['key'], 'title' => $s['title'], 'order' => $s['order'], 'summary' => $s['summary'], 'keywords' => $s['keywords'],
                'related' => $s['related'], 'fallback' => $s['fallback'], 'lang' => $s['lang'], 'body' => $this->fill($s['body'], $useLang),
            ];
        }
        return $out;
    }

    /** 아이콘만(이름 글자 없이) 그리라는 표시 — {{icon:<이름>.bare}}. 화면 렌더러(guide.js)와 같은 값 */
    public const BARE = '.bare';

    /** 자리표시자 채우기(icon은 그대로) */
    public function fill(string $body, string $lang): string
    {
        $prev = Lang::locale();
        Lang::setLocale($lang);
        try {
            // 아이콘 바로 뒤에 단추 이름({{ui:…}})이 오면 아이콘의 이름 글자를 숨긴다("⬆ 업로드 업로드 시작" 반복 방지) — 렌더러가 아이콘만 그린다
            $body = (string) preg_replace('/\{\{icon:([a-z0-9_.-]+)\}\}(?=[ \t]*\{\{ui:)/', '{{icon:$1' . self::BARE . '}}', $body);
            return (string) preg_replace_callback(self::PLACEHOLDER_RE, function (array $m) use ($lang): string {
                return match ($m[1]) {
                    'limit' => $this->limit($m[2]),
                    'version' => $this->version($m[2]),
                    'ui' => $this->ui($m[2], $lang) ?? $m[0],
                    default => $m[0],
                };
            }, $body);
        } finally {
            Lang::setLocale($prev);
        }
    }

    /** 설정 값 + 단위(현재 언어) */
    public function limit(string $key): string
    {
        $spec = self::spec($key);
        if ($spec === null) {
            return '?';
        }
        $v = $this->config->get($key);
        return match ($spec['type']) {
            'size' => self::size((int) $v),
            'duration' => self::duration((int) $v),
            'percent' => (int) $v . '%',
            'int' => number_format((int) $v),
            'bool' => Lang::get($v ? 'guide.value.on' : 'guide.value.off'),
            'list', 'email_list', 'ip_list' => implode(', ', (array) $v),
            default => (string) $v,
        };
    }

    public static function size(int $bytes): string
    {
        foreach (['TiB' => 1024 ** 4, 'GiB' => 1024 ** 3, 'MiB' => 1024 ** 2, 'KiB' => 1024] as $u => $n) {
            if ($bytes >= $n) {
                $q = $bytes / $n;
                return (fmod($q, 1.0) === 0.0 ? (string) (int) $q : rtrim(rtrim(number_format($q, 1, '.', ''), '0'), '.')) . ' ' . $u;
            }
        }
        return $bytes . ' B';
    }

    public static function duration(int $sec): string
    {
        foreach ([86400, 3600, 60] as $n) {
            if ($sec >= $n && $sec % $n === 0) {
                $v = ['n' => intdiv($sec, $n)];
                return match ($n) {
                    86400 => Lang::get('guide.unit.days', $v),
                    3600 => Lang::get('guide.unit.hours', $v),
                    default => Lang::get('guide.unit.minutes', $v),
                };
            }
        }
        return Lang::get('guide.unit.seconds', ['n' => $sec]);
    }

    public function version(string $name): string
    {
        if ($name === 'app') {
            return $this->appVersion;
        }
        try {
            $v = ($this->toolVersion)($name);
        } catch (\Throwable) {
            $v = null;
        }
        return $v ?? Lang::get('guide.not_installed');
    }

    /** 화면 문구(PHP 문구 우선, 없으면 JS 문구). 없으면 null */
    public function ui(string $key, string $lang): ?string
    {
        $prev = Lang::locale();
        Lang::setLocale($lang);
        try {
            if (Lang::has($key)) {
                return Lang::get($key);
            }
        } finally {
            Lang::setLocale($prev);
        }
        return $this->jsStrings($lang)[$key] ?? null;
    }

    /** @return array<string, string> JS 문구 파일(public/assets/js/i18n/<lang>.js)의 키 → 값 */
    private function jsStrings(string $lang): array
    {
        if (isset(self::$js[$lang])) {
            return self::$js[$lang];
        }
        $out = [];
        $src = (string) @file_get_contents($this->root . '/public/assets/js/i18n/' . $lang . '.js');
        preg_match_all('/^\s*(?:\'((?:[^\'\\\\]|\\\\.)+)\'|"((?:[^"\\\\]|\\\\.)+)"):\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"),\s*$/m', $src, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $key = $m[1] !== '' ? stripcslashes($m[1]) : (string) json_decode('"' . $m[2] . '"');
            $val = ($m[3] ?? '') !== '' || !isset($m[4]) ? stripcslashes($m[3] ?? '') : (string) json_decode('"' . $m[4] . '"');
            $out[$key] = $val;
        }
        return self::$js[$lang] = $out;
    }

    /** @return array<string, mixed>|null 설정 스키마의 항목 */
    public static function spec(string $key): ?array
    {
        [$section, $name] = explode('.', $key, 2) + [1 => ''];
        $def = ConfigSchema::definition();
        $spec = $def[$section][$name] ?? null;
        return is_array($spec) ? $spec : null;
    }

    /**
     * 절 검색(최소 2자, 대소문자 무시, 한글 포함). 제목·요약·키워드·본문(채운 뒤)에서 찾는다. 관리자 가이드는 audience=admin일 때만.
     *
     * @return list<array{key:string, title:string, summary:string, hits:int}>
     */
    public function search(string $audience, string $lang, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return [];
        }
        $needle = mb_strtolower($q);
        $out = [];
        foreach ($this->rendered($audience, $lang) as $s) {
            $hay = mb_strtolower($s['title'] . "\n" . $s['summary'] . "\n" . implode(' ', $s['keywords']) . "\n" . self::plain($s['body']));
            $hits = mb_substr_count($hay, $needle);
            if ($hits > 0) {
                $out[] = ['key' => $s['key'], 'title' => $s['title'], 'summary' => $s['summary'], 'hits' => $hits];
            }
        }
        return $out;
    }

    /** 검색용 글자(마크다운 기호·자리표시자 떼기) */
    public static function plain(string $md): string
    {
        $md = (string) preg_replace('/\{\{(?:icon|legend):[^}]+\}\}/', '', $md);
        $md = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $md);
        $md = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $md);
        return (string) preg_replace('/[#*_`]+/', '', $md);
    }

    // ---- 이미지 ----

    /**
     * 이미지 파일: <slug>.<lang>.<확장자>(언어별) → <slug>.<확장자>(공통) → 다른 언어. 없으면 null.
     */
    public function imagePath(string $audience, string $slug, string $lang): ?string
    {
        if (!in_array($audience, GuideCatalog::AUDIENCES, true) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) !== 1) {
            return null;
        }
        $dir = $this->catalog->root() . '/' . $audience . '/img';
        $other = $lang === 'ko' ? 'en' : 'ko';
        foreach ([$slug . '.' . $lang, $slug, $slug . '.' . $other] as $base) {
            foreach (self::IMAGE_EXT as $ext) {
                if (is_file($dir . '/' . $base . '.' . $ext)) {
                    return $dir . '/' . $base . '.' . $ext;
                }
            }
        }
        return null;
    }

    /** 파일 내용으로 판정한 형식(png·webp·jpeg), 아니면 null — 확장자를 믿지 않는다. SVG는 허용하지 않는다 */
    public static function imageType(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    // ---- 검사(시험·도구) ----

    /**
     * 본문 전체 검사 — 오류 문장 목록(비면 정상). 시험(GuideCatalogTest)이 쓴다.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        $usedImages = [];
        foreach (GuideCatalog::AUDIENCES as $aud) {
            $keysBy = [];
            $phBy = [];
            foreach (GuideCatalog::LANGS as $lang) {
                $dir = $this->catalog->root() . '/' . $aud . '/' . $lang;
                $files = is_dir($dir) ? array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => str_ends_with($n, '.md'))) : [];
                $keys = [];
                foreach ($files as $f) {
                    try {
                        $s = GuideCatalog::parse((string) file_get_contents($dir . '/' . $f), "{$aud}/{$lang}/{$f}");
                    } catch (\InvalidArgumentException $e) {
                        $errors[] = $e->getMessage();
                        continue;
                    }
                    $where = "{$aud}/{$lang}/{$f}";
                    if (isset($keys[$s['key']])) {
                        $errors[] = "{$where}: key {$s['key']} 중복";
                    }
                    $keys[$s['key']] = $s;
                    if ($s['audience'] !== $aud) {
                        $errors[] = "{$where}: audience {$s['audience']}가 폴더와 다름";
                    }
                    if (preg_match('/^\d+_' . preg_quote($s['key'], '/') . '\.md$/', $f) !== 1) {
                        $errors[] = "{$where}: 파일 이름은 <번호>_<key>.md";
                    }
                    foreach ($this->bodyErrors($s['body'], $aud, $lang, $usedImages) as $e) {
                        $errors[] = "{$where}: {$e}";
                    }
                    preg_match_all(self::PLACEHOLDER_RE, $s['body'], $pm);
                    preg_match_all('/\]\((guide:[a-z0-9-]+|guide-img:[a-z0-9-]+)\)/', $s['body'], $lm);
                    $ph = array_values(array_unique([...$pm[0], ...$lm[1]]));
                    sort($ph);
                    $phBy[$s['key']][$lang] = $ph;
                }
                $keysBy[$lang] = $keys;
            }
            if (array_keys($keysBy['ko']) != array_keys($keysBy['en']) && array_diff_key($keysBy['ko'], $keysBy['en']) + array_diff_key($keysBy['en'], $keysBy['ko']) !== []) {
                $errors[] = "{$aud}: ko·en key 집합이 다름 — " . implode(', ', array_keys(array_diff_key($keysBy['ko'], $keysBy['en']) + array_diff_key($keysBy['en'], $keysBy['ko'])));
            }
            foreach (GuideCatalog::LANGS as $lang) {
                foreach ($keysBy[$lang] as $key => $s) {
                    foreach ($s['related'] as $r) {
                        if (!isset($keysBy[$lang][$r])) {
                            $errors[] = "{$aud}/{$lang}/{$key}: related {$r}가 없는 key";
                        }
                    }
                    preg_match_all('/\]\(guide:([a-z0-9-]+)\)/', $s['body'], $gm);
                    foreach ($gm[1] as $g) {
                        if (!isset($keysBy[$lang][$g])) {
                            $errors[] = "{$aud}/{$lang}/{$key}: guide:{$g}가 없는 key";
                        }
                    }
                }
            }
            foreach ($phBy as $key => $byLang) {
                if (isset($byLang['ko'], $byLang['en']) && $byLang['ko'] !== $byLang['en']) {
                    $errors[] = "{$aud}/{$key}: ko·en의 자리표시자·가이드 링크·이미지가 다름";
                }
            }
            // 이미지 폴더: 형식·크기·폭, 쓰이지 않는 파일
            $imgDir = $this->catalog->root() . '/' . $aud . '/img';
            foreach (is_dir($imgDir) ? array_diff(scandir($imgDir) ?: [], ['.', '..', '.gitkeep']) : [] as $f) {
                $path = $imgDir . '/' . $f;
                if (preg_match('/^([a-z0-9][a-z0-9-]*)(\.(ko|en))?\.(png|webp|jpe?g)$/', $f, $im) !== 1) {
                    $errors[] = "{$aud}/img/{$f}: 파일 이름·형식(<slug>[.ko|.en].png|webp|jpg, SVG 금지)";
                    continue;
                }
                if (!isset($usedImages[$aud][$im[1]])) {
                    $errors[] = "{$aud}/img/{$f}: 본문에서 쓰지 않는 이미지";
                }
                if (self::imageType($path) === null) {
                    $errors[] = "{$aud}/img/{$f}: 내용이 PNG·WebP·JPEG가 아님";
                }
                if (filesize($path) > self::IMAGE_MAX_BYTES) {
                    $errors[] = "{$aud}/img/{$f}: " . filesize($path) . '바이트(최대 ' . self::IMAGE_MAX_BYTES . ')';
                }
                $size = @getimagesize($path);
                if (!is_array($size) || $size[0] > self::IMAGE_MAX_WIDTH) {
                    $errors[] = "{$aud}/img/{$f}: 가로 " . (is_array($size) ? $size[0] : '?') . 'px(최대 ' . self::IMAGE_MAX_WIDTH . ')';
                }
            }
        }
        return $errors;
    }

    /**
     * @param array<string, array<string, bool>> $usedImages
     * @return list<string>
     */
    private function bodyErrors(string $body, string $aud, string $lang, array &$usedImages): array
    {
        $errors = [];
        $inFence = false;
        foreach (explode("\n", $body) as $n => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            $ln = 'L' . ($n + 1);
            $noCode = (string) preg_replace('/`[^`]*`/', '', $line);
            if (preg_match('/<\/?[A-Za-z!][^>]*>/', $noCode) === 1) {
                $errors[] = "{$ln} 원시 HTML 금지";
            }
            if (preg_match('/^#\s/', $line) === 1) {
                $errors[] = "{$ln} # 제목 금지(##부터 — 절 제목은 머리말 title)";
            }
            if (preg_match('/^\s*\|/', $line) === 1 || preg_match('/^\s*>/', $line) === 1) {
                $errors[] = "{$ln} 표·인용은 렌더러가 지원하지 않음";
            }
            preg_match_all('/(!?)\[([^\]]*)\]\(([^)\s]*)\)/', $noCode, $links, PREG_SET_ORDER);
            foreach ($links as $l) {
                $href = $l[3];
                if ($l[1] === '!') {
                    if (preg_match('/^guide-img:([a-z0-9][a-z0-9-]*)$/', $href, $im) !== 1) {
                        $errors[] = "{$ln} 이미지는 guide-img:<slug>만({$href})";
                        continue;
                    }
                    if (trim($l[2]) === '') {
                        $errors[] = "{$ln} 이미지 대체 텍스트가 비었음";
                    }
                    $usedImages[$aud][$im[1]] = true;
                    if ($this->imagePath($aud, $im[1], $lang) === null) {
                        $errors[] = "{$ln} 이미지 {$im[1]} 파일 없음";
                    }
                    continue;
                }
                if (preg_match('#^(guide:[a-z0-9-]+|/[A-Za-z0-9/_\-?=&%.\#]*|https://[^\s]+)$#', $href) !== 1 || str_starts_with($href, '//')) {
                    $errors[] = "{$ln} 허용하지 않는 링크({$href}) — guide:<key>, /앱 경로, https:// 만";
                }
            }
            if (preg_match('/javascript:|data:|vbscript:/i', $noCode) === 1) {
                $errors[] = "{$ln} 위험한 주소 형식";
            }
            preg_match_all('/\{\{([^}]*)\}\}/', $line, $all);
            foreach ($all[0] as $i => $whole) {
                if (preg_match(self::PLACEHOLDER_RE, $whole, $pm) !== 1) {
                    $errors[] = "{$ln} 모르는 자리표시자 {$whole}";
                    continue;
                }
                $err = match ($pm[1]) {
                    'limit' => self::spec($pm[2]) === null ? "설정 키 {$pm[2]} 없음" : null,
                    'version' => in_array($pm[2], ['app', 'ruff', 'eslint'], true) ? null : "버전 이름 {$pm[2]}",
                    'icon' => $this->icons->has($pm[2]) ? null : "아이콘 {$pm[2]} 없음",
                    'ui' => $this->ui($pm[2], 'ko') !== null && $this->ui($pm[2], 'en') !== null ? null : "문구 키 {$pm[2]}가 ko·en에 다 있지 않음",
                    // X10: 아이콘 범례(레지스트리에서 자동) — 사용자 가이드에서 혼자 한 줄로만(화면이 그 문단을 범례 표로 바꿈)
                    'legend' => $pm[2] === 'icons' && $aud === 'user' && trim($line) === $whole ? null : "범례 자리표시자는 사용자 가이드에서 {{legend:icons}} 한 줄로만",
                    default => "모르는 자리표시자 {$whole}",
                };
                if ($err !== null) {
                    $errors[] = "{$ln} {$err}";
                }
            }
        }
        return $errors;
    }
}
