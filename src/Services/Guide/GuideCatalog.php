<?php

declare(strict_types=1);

namespace PJGuardian\Services\Guide;

use PJGuardian\Support\Fs;

/**
 * 가이드 본문 색인(P29, 사용자 결정 2026-10-11 — 본문은 코드에 포함된 읽기 전용 마크다운, 화면에서 편집하지 않음).
 *
 * 위치(모두 public/ 밖 — 정적 URL로 접근할 수 없고 PHP로만 제공): resources/guide/<audience>/<ko|en>/<번호>_<key>.md, 이미지는 resources/guide/<audience>/img/.
 * 파일 하나 = 절 하나. 맨 앞 머리말(--- 사이, "이름: 값" 한 줄씩 — 새 패키지 없이 직접 해석):
 *   key(영문 소문자·숫자·-, ko·en 같은 값), title, order(정수), summary(한 줄), keywords(쉼표), related(쉼표 — 다른 key), audience(user|admin).
 * 본문 허용 문법은 화면 렌더러(lib/markdown.js, P22) 범위 + guide:<key> 링크 + guide-img:<slug> 이미지. 원시 HTML·javascript: 링크는 validate()가 거부.
 *
 * sections(): 언어별 절 목록(order 순). 그 언어에 없는 절은 한국어로 대신하고 fallback=true(화면이 "번역 준비 중" 한 줄).
 * 색인은 파일 수정 시각·크기의 지문으로 프로세스 안에 캐시한다(파일이 바뀌면 다시 읽음).
 */
final class GuideCatalog
{
    public const AUDIENCES = ['user', 'admin'];
    public const LANGS = ['ko', 'en'];
    public const KEY_RE = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    private const REQUIRED = ['key', 'title', 'order', 'summary', 'audience'];

    /** @var array<string, array{sig:string, sections:list<array<string,mixed>>}> */
    private static array $cache = [];

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return list<array{key:string, title:string, order:int, summary:string, keywords:list<string>, related:list<string>, audience:string, body:string, lang:string, fallback:bool, file:string}>
     */
    public function sections(string $audience, string $lang): array
    {
        self::assertAudience($audience);
        $own = $this->load($audience, in_array($lang, self::LANGS, true) ? $lang : 'ko');
        if ($lang === 'ko') {
            return $own;
        }
        $byKey = [];
        foreach ($own as $s) {
            $byKey[$s['key']] = $s;
        }
        $out = [];
        foreach ($this->load($audience, 'ko') as $ko) {
            $out[] = $byKey[$ko['key']] ?? ['fallback' => true] + $ko;
        }
        foreach ($own as $s) {
            if (!in_array($s['key'], array_column($out, 'key'), true)) {
                $out[] = $s; // 한국어에 없는 절(검사에서 실패하지만 화면은 보여 준다)
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function section(string $audience, string $lang, string $key): ?array
    {
        foreach ($this->sections($audience, $lang) as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }
        return null;
    }

    /** @return list<string> 그 언어에 있는 key */
    public function keys(string $audience, string $lang = 'ko'): array
    {
        return array_column($this->load($audience, $lang), 'key');
    }

    /** @return list<array<string, mixed>> */
    private function load(string $audience, string $lang): array
    {
        $dir = $this->root . '/' . $audience . '/' . $lang;
        $files = is_dir($dir) ? array_values(array_filter(Fs::listDir($dir), static fn (string $n): bool => str_ends_with($n, '.md'))) : [];
        sort($files);
        $sig = '';
        foreach ($files as $f) {
            $sig .= $f . ':' . (int) @filemtime($dir . '/' . $f) . ':' . (int) @filesize($dir . '/' . $f) . ';';
        }
        $ck = $dir;
        if (isset(self::$cache[$ck]) && self::$cache[$ck]['sig'] === $sig) {
            return self::$cache[$ck]['sections'];
        }
        $out = [];
        foreach ($files as $f) {
            try {
                $out[] = self::parse((string) file_get_contents($dir . '/' . $f), $f) + ['lang' => $lang, 'fallback' => false, 'file' => $audience . '/' . $lang . '/' . $f];
            } catch (\InvalidArgumentException) {
                continue; // 잘못된 파일은 화면에서 빼고 validate()가 알린다
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);
        self::$cache[$ck] = ['sig' => $sig, 'sections' => $out];
        return $out;
    }

    /**
     * 머리말 + 본문 해석. 형식이 틀리면 InvalidArgumentException(메시지에 파일 이름).
     *
     * @return array{key:string, title:string, order:int, summary:string, keywords:list<string>, related:list<string>, audience:string, body:string}
     */
    public static function parse(string $text, string $name = ''): array
    {
        $text = str_replace("\r\n", "\n", $text);
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $m) !== 1) {
            throw new \InvalidArgumentException("{$name}: 머리말(--- … ---)이 없습니다");
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([a-z]+):\s*(.*)$/', $line, $kv) !== 1) {
                throw new \InvalidArgumentException("{$name}: 머리말 " . ($i + 2) . '번째 줄 형식');
            }
            if (isset($meta[$kv[1]])) {
                throw new \InvalidArgumentException("{$name}: 머리말 {$kv[1]} 중복");
            }
            $meta[$kv[1]] = trim($kv[2]);
        }
        foreach (self::REQUIRED as $k) {
            if (($meta[$k] ?? '') === '') {
                throw new \InvalidArgumentException("{$name}: 머리말 {$k}가 없습니다");
            }
        }
        $unknown = array_diff(array_keys($meta), [...self::REQUIRED, 'keywords', 'related']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$name}: 모르는 머리말 " . implode(', ', $unknown));
        }
        if (preg_match(self::KEY_RE, $meta['key']) !== 1) {
            throw new \InvalidArgumentException("{$name}: key 형식(영문 소문자·숫자·-)");
        }
        if (!in_array($meta['audience'], self::AUDIENCES, true)) {
            throw new \InvalidArgumentException("{$name}: audience는 user|admin");
        }
        if (preg_match('/^\d{1,4}$/', $meta['order']) !== 1) {
            throw new \InvalidArgumentException("{$name}: order는 정수");
        }
        $list = static fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $s): bool => $s !== ''));
        return [
            'key' => $meta['key'], 'title' => $meta['title'], 'order' => (int) $meta['order'], 'summary' => $meta['summary'],
            'keywords' => $list($meta['keywords'] ?? ''), 'related' => $list($meta['related'] ?? ''), 'audience' => $meta['audience'], 'body' => rtrim($m[2]) . "\n",
        ];
    }

    private static function assertAudience(string $audience): void
    {
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new \InvalidArgumentException('audience');
        }
    }
}
