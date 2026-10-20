<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

/**
 * 출력 이스케이프 시험 문자열 모음(P31 A-2). 내용은 tests/Support/xss_corpus.json 한 곳(JS 시험과 공유).
 */
final class XssCorpus
{
    /** @return array{html:list<string>, markers:list<string>, markdown:list<string>, csv:list<string>} */
    public static function all(): array
    {
        static $data = null;
        return $data ??= json_decode((string) file_get_contents(__DIR__ . '/xss_corpus.json'), true, 8, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public static function html(): array
    {
        return self::all()['html'];
    }

    /**
     * HTML을 실제로 해석해(DOMDocument) 모음 문자열이 **코드로 살아났는지** 본다 — 이스케이프되어 글자로 남은 것은 괜찮다.
     * 살아난 것: 표식이 든 script·style·iframe·svg·img·object·embed 요소, on… 속성, srcdoc, javascript:·vbscript:·data: 주소.
     * 없으면 null, 있으면 설명 한 줄.
     */
    public static function leak(string $html): ?string
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('//script | //style | //iframe | //svg | //img | //object | //embed') ?: [] as $n) {
            $src = $n instanceof \DOMElement ? $n->getAttribute('src') . ' ' . $n->getAttribute('srcdoc') : '';
            if (str_contains($n->textContent . $src, 'pjg-xss') || ($n->nodeName === 'img' && $n instanceof \DOMElement && $n->getAttribute('src') === 'x')) {
                return $n->nodeName . ' 요소: ' . mb_substr(trim($n->textContent . ' ' . $src), 0, 80);
            }
        }
        foreach ($xp->query('//@*') ?: [] as $attr) {
            $name = strtolower($attr->nodeName);
            $val = strtolower((string) preg_replace('/\s+/', '', (string) $attr->nodeValue));
            if (str_starts_with($name, 'on') || $name === 'srcdoc') {
                return "{$name} 속성: " . mb_substr((string) $attr->nodeValue, 0, 80);
            }
            if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true) && preg_match('/^(javascript|vbscript|data):/', $val) === 1) {
                return "{$name} 주소: " . mb_substr((string) $attr->nodeValue, 0, 80);
            }
        }
        return null;
    }
}
