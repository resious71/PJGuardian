<?php

declare(strict_types=1);

/**
 * 아이콘 스프라이트 생성 (명세 20.2, docs/icons.md)
 *
 * 사용법: php tools/build_icons.php
 * public/assets/icons/icons.json에서 쓰는 아이콘만 골라 public/assets/icons/lucide/*.svg(Lucide)와
 * custom/*.svg(자체 제작)에서 읽어 public/assets/icons/sprite.svg 하나로 묶는다.
 * - 각 아이콘은 <symbol id="i-<아이콘>" viewBox="0 0 24 24" ...>. 화면은 <use href="sprite.svg?v=<해시>#i-<아이콘>">로 쓴다.
 * - 결과는 결정적이다(이름 순, 주석·공백 정리). 매핑에 있는데 파일이 없으면 종료 코드 1.
 * - 쓰지 않는 SVG 파일이 있으면 경고만 한다.
 */

$root = dirname(__DIR__);
$iconsDir = $root . '/public/assets/icons';
$map = json_decode((string) file_get_contents($iconsDir . '/icons.json'), true, 512, JSON_THROW_ON_ERROR);

$used = [];
foreach ($map['icons'] as $name => $entry) {
    $used[$entry['icon']][] = $name;
}
ksort($used);

$symbols = [];
$errors = [];
foreach (array_keys($used) as $id) {
    $file = null;
    foreach (['lucide', 'custom'] as $sub) {
        if (is_file("{$iconsDir}/{$sub}/{$id}.svg")) {
            $file = "{$iconsDir}/{$sub}/{$id}.svg";
            break;
        }
    }
    if ($file === null) {
        $errors[] = "아이콘 파일 없음: {$id} (쓰는 곳: " . implode(', ', $used[$id]) . ')';
        continue;
    }
    $svg = (string) file_get_contents($file);
    $svg = (string) preg_replace('/<!--.*?-->/s', '', $svg);
    if (preg_match('/<svg\b([^>]*)>(.*)<\/svg>/s', $svg, $m) !== 1) {
        $errors[] = "SVG 형식 오류: {$file}";
        continue;
    }
    preg_match('/viewBox="([^"]+)"/', $m[1], $vb);
    if (($vb[1] ?? '') !== '0 0 24 24') {
        $errors[] = "viewBox가 0 0 24 24가 아님: {$file}";
        continue;
    }
    if (!str_contains($m[1], 'stroke="currentColor"')) {
        $errors[] = "currentColor를 쓰지 않음: {$file}";
        continue;
    }
    if (preg_match('/<(script|foreignObject|image)\b|\son\w+=|href="(?!#)/i', $m[2]) === 1) {
        $errors[] = "허용하지 않는 요소·속성(script, 이벤트, 외부 참조): {$file}";
        continue;
    }
    $inner = trim((string) preg_replace('/\s+/', ' ', $m[2]));
    $inner = (string) preg_replace('/>\s+</', '><', $inner);
    $symbols[] = sprintf(
        '<symbol id="i-%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</symbol>',
        $id,
        $inner,
    );
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

$sprite = "<svg xmlns=\"http://www.w3.org/2000/svg\" aria-hidden=\"true\">\n"
    . "<!-- 생성 파일: php tools/build_icons.php 로 만든다(직접 고치지 않는다). Lucide(ISC, lucide/LICENSE) + PJGuardian 자체 제작(custom/) -->\n"
    . implode("\n", $symbols) . "\n</svg>\n";
file_put_contents($iconsDir . '/sprite.svg', $sprite);

$unused = [];
foreach (['lucide', 'custom'] as $sub) {
    foreach (glob("{$iconsDir}/{$sub}/*.svg") ?: [] as $f) {
        if (!isset($used[basename($f, '.svg')])) {
            $unused[] = "{$sub}/" . basename($f);
        }
    }
}
printf("sprite.svg: 아이콘 %d개, %d bytes, sha256 %s\n", count($symbols), strlen($sprite), substr(hash('sha256', $sprite), 0, 12));
if ($unused !== []) {
    echo '경고: 쓰지 않는 SVG ' . implode(', ', $unused) . "\n";
}
