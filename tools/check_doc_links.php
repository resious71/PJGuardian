<?php

declare(strict_types=1);

/**
 * 문서·가이드 깨진 링크 검사(P31 C-4, 읽기 전용, 인터넷 접속 없음).
 *   php tools/check_doc_links.php
 *
 * 훑는 것: docs/*.md·docs/legal/*.md(단계 보고 docs/results/ 제외 — 과거 보고서는 고치지 않음), resources/guide/**\/*.md,
 *   저장소 루트의 *.md(README·CONTRIBUTING 등, 요구사항 명세 제외 — P33), CLAUDE.md, THIRD_PARTY_NOTICES.
 * 확인하는 것:
 *  - 마크다운 링크 [글](대상)의 상대 경로 파일이 있는지(#조각 제외). http(s)·mailto·앱 화면 주소(/로 시작)는 건너뜀.
 *  - 가이드 링크 guide:<절>이 사용자·관리자 가이드에 있는 절인지(guide-img:는 그림 목록).
 *  - 본문에 적은 저장소 경로 `docs/….md`·`tools/….php|mjs`·`src/….php`·`migrations/….sql`(역따옴표 안)가 실제로 있는지.
 * 결과: 문제 없으면 0, 있으면 목록과 1.
 */

$root = str_replace('\\', '/', dirname(__DIR__));
// P33: 저장소 루트의 공개 문서(README·CONTRIBUTING·SECURITY 등, 요구사항 명세 제외)와 docs/legal/도 훑는다
$rootDocs = array_filter(glob($root . '/*.md') ?: [], static fn (string $f): bool => !in_array(basename($f), ['CLAUDE.md', 'PJGuardian_requirements.md'], true));
$files = array_merge(glob($root . '/docs/*.md') ?: [], glob($root . '/docs/legal/*.md') ?: [], array_values($rootDocs), [$root . '/CLAUDE.md', $root . '/THIRD_PARTY_NOTICES']);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources/guide', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), '.md')) {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}
// 가이드 절 키(front matter key:)
$keys = [];
foreach ($files as $f) {
    if (str_contains($f, '/resources/guide/') && preg_match('/^key:\s*([a-z0-9-]+)\s*$/m', (string) file_get_contents($f), $m) === 1) {
        $keys[$m[1]] = true;
    }
}
$shots = json_decode((string) @file_get_contents($root . '/resources/guide/screenshots.json'), true);
$images = [];
foreach ((array) ($shots['images'] ?? []) as $img) {
    $images[(string) ($img['slug'] ?? '')] = true;
}

$problems = [];
$checked = 0;
foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    $text = (string) file_get_contents($file);
    // 코드 블록 안은 링크로 보지 않는다
    $plain = (string) preg_replace('/```.*?```/s', '', $text);
    foreach (explode("\n", $plain) as $no => $line) {
        if (preg_match_all('/\]\(([^)\s]+)\)/u', $line, $mm) > 0) {
            foreach ($mm[1] as $target) {
                $checked++;
                if (preg_match('#^(https?:|mailto:|/|\#)#', $target) === 1 || str_contains($target, '<')) { // 설명용 자리 표시(guide:<key>) 포함
                    continue;
                }
                if (str_starts_with($target, 'guide:')) {
                    $k = strtok(substr($target, 6), '#');
                    if (!isset($keys[$k])) {
                        $problems[] = "{$rel}:" . ($no + 1) . " 가이드 절 없음 {$target}";
                    }
                    continue;
                }
                if (str_starts_with($target, 'guide-img:')) {
                    if (!isset($images[substr($target, 10)])) {
                        $problems[] = "{$rel}:" . ($no + 1) . " 가이드 그림 없음 {$target}";
                    }
                    continue;
                }
                $path = (string) strtok($target, '#');
                $abs = $path === '' ? $file : dirname($file) . '/' . rawurldecode($path);
                if (!file_exists($abs)) {
                    $problems[] = "{$rel}:" . ($no + 1) . " 파일 없음 {$target}";
                }
            }
        }
        // 역따옴표 안의 저장소 경로
        if (preg_match_all('/`((?:docs|tools|src|migrations|tests)\/[A-Za-z0-9_\/.\-]+\.(?:md|php|mjs|sql|json|sh))`/', $line, $pm) > 0) {
            foreach ($pm[1] as $p) {
                $checked++;
                if (str_contains($p, '*') || str_contains($p, '<') || preg_match('#(^|/)[A-Z](/|\.php$)#', $p) === 1) { // 설명용 자리 표시(src/X/Y.php)
                    continue;
                }
                if (!file_exists($root . '/' . $p) && !str_starts_with($p, 'docs/results/')) {
                    $problems[] = "{$rel}:" . ($no + 1) . " 저장소 경로 없음 {$p}";
                }
            }
        }
    }
}
printf("훑은 파일 %d개, 확인한 링크·경로 %d개\n", count($files), $checked);
foreach ($problems as $p) {
    echo "FAIL {$p}\n";
}
echo $problems === [] ? "깨진 링크 없음\n" : '문제 ' . count($problems) . "개\n";
exit($problems === [] ? 0 : 1);
