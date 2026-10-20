<?php

declare(strict_types=1);

/**
 * 공개 전 비밀·개인 정보 스캐너(P33 2절). 읽기만 한다.
 *
 *   php tools/scan_release.php [폴더]                 폴더 아래 모든 파일(기본 dist/oss/, .git 폴더 제외)
 *   php tools/scan_release.php --git=<저장소 루트>     그 저장소의 git ls-files(추적 파일 전부)
 *   php tools/scan_release.php --stdin [--label=<이름>] 표준 입력의 글자(README 캡처의 화면 글자 등)
 *   공통 옵션: --json(결과를 JSON 한 덩어리로), --rules=<파일>(기본 tools/scan_release.rules.php), --allow=<파일>(기본 tools/scan_release.allow.php),
 *              --no-allow(허용 목록 없이 — 무엇이 허용되고 있는지 볼 때)
 *
 * 결과: 표(파일·줄·종류·가린 값·설명). 발견 값은 앞 3글자 + 길이로만 보인다. 이미지·PDF·zip 등 이진 파일은 훑지 않고 목록(파일·크기)으로 따로 보인다
 * — 사람이 눈으로 확인할 대상.
 * 종료 코드: 0 발견 없음, 1 발견 있음, 2 사용법·규칙·허용 목록 오류(이유 없는 허용 포함).
 *
 * JSON 형식(--json):
 *   {"source": "<폴더|git:<루트>|stdin:<이름>>", "files_scanned": N,
 *    "findings": [{"file","line","kind","masked","detail"}], "allowed": [{"file","line","kind","reason"}],
 *    "binaries": [{"file","size","note"}], "summary": {"<kind>": N}, "ok": true|false}
 */

require __DIR__ . '/lib/ReleaseScanner.php';

use PJGuardian\Tools\ReleaseScanner;

$root = dirname(__DIR__);
$opts = ['json' => false, 'stdin' => false, 'git' => null, 'label' => '<stdin>', 'rules' => $root . '/tools/scan_release.rules.php',
    'allow' => $root . '/tools/scan_release.allow.php', 'no-allow' => false];
$positional = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--json' || $a === '--stdin' || $a === '--no-allow') {
        $opts[substr($a, 2)] = true;
    } elseif (preg_match('/^--(git|label|rules|allow)=(.*)$/', $a, $m) === 1) {
        $opts[$m[1]] = $m[2];
    } elseif ($a === '-h' || $a === '--help') {
        fwrite(STDOUT, "사용법: php tools/scan_release.php [폴더 | --git=<저장소> | --stdin [--label=이름]] [--json] [--rules=파일] [--allow=파일] [--no-allow]\n");
        exit(0);
    } elseif (str_starts_with($a, '--')) {
        fwrite(STDERR, "모르는 옵션: {$a}\n");
        exit(2);
    } else {
        $positional[] = $a;
    }
}
$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(2);
};
if (count($positional) > 1 || ($positional !== [] && ($opts['stdin'] || $opts['git'] !== null)) || ($opts['stdin'] && $opts['git'] !== null)) {
    $fail('대상은 폴더 하나, --git, --stdin 중 하나만 줍니다');
}

$load = static function (string $file, string $what) use ($fail): array {
    if (!is_file($file)) {
        $fail("{$what} 파일이 없습니다: {$file}");
    }
    $v = require $file;
    if (!is_array($v)) {
        $fail("{$what} 파일은 배열을 돌려줘야 합니다: {$file}");
    }
    return $v;
};
try {
    $scanner = new ReleaseScanner($load((string) $opts['rules'], '규칙'), $opts['no-allow'] ? [] : array_values($load((string) $opts['allow'], '허용 목록')));
} catch (\InvalidArgumentException $e) {
    $fail('규칙·허용 목록 오류: ' . $e->getMessage());
}

if ($opts['stdin']) {
    $text = (string) stream_get_contents(STDIN);
    $r = $scanner->scanText($text, (string) $opts['label']);
    $res = ['source' => 'stdin:' . $opts['label'], 'files_scanned' => 1, 'findings' => $r['findings'], 'allowed' => $r['allowed'], 'binaries' => []];
} elseif ($opts['git'] !== null) {
    $repo = realpath((string) $opts['git']);
    if ($repo === false || !is_dir($repo)) {
        $fail('저장소 폴더가 없습니다: ' . $opts['git']);
    }
    $p = proc_open(['git', '-C', $repo, 'ls-files', '-z'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        $fail('git을 실행할 수 없습니다');
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) {
        $fail('git ls-files 실패(저장소가 아님?)');
    }
    $files = array_values(array_filter(explode("\0", $out), static fn (string $f): bool => $f !== ''));
    $res = ['source' => 'git:' . str_replace('\\', '/', $repo)] + $scanner->scanFiles($repo, $files);
} else {
    $dir = $positional[0] ?? $root . '/dist/oss';
    $real = realpath($dir);
    if ($real === false || !is_dir($real)) {
        $fail("폴더가 없습니다: {$dir}");
    }
    $res = ['source' => str_replace('\\', '/', $real)] + $scanner->scanFiles($real, ReleaseScanner::listTree($real));
}

$summary = [];
foreach ($res['findings'] as $f) {
    $summary[$f['kind']] = ($summary[$f['kind']] ?? 0) + 1;
}
ksort($summary);
$res['summary'] = $summary;
$res['ok'] = $res['findings'] === [];

if ($opts['json']) {
    fwrite(STDOUT, (string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit($res['ok'] ? 0 : 1);
}

$w = static fn (string $s) => fwrite(STDOUT, $s . "\n");
$w("대상: {$res['source']}  (훑은 텍스트 파일 {$res['files_scanned']}개, 허용으로 넘긴 것 " . count($res['allowed']) . '건)');
if ($res['findings'] !== []) {
    $w('');
    $w('| 파일 | 줄 | 종류 | 값(가림) | 설명 |');
    $w('| --- | ---: | --- | --- | --- |');
    foreach ($res['findings'] as $f) {
        $w("| {$f['file']} | {$f['line']} | {$f['kind']} | {$f['masked']} | {$f['detail']} |");
    }
    $w('');
    $w('종류별: ' . implode(', ', array_map(static fn ($k, $n) => "{$k} {$n}", array_keys($summary), $summary)));
}
if ($res['binaries'] !== []) {
    $total = array_sum(array_column($res['binaries'], 'size'));
    $w('');
    $w('이진·큰 파일(글자로 훑지 않음 — 눈으로 확인): ' . count($res['binaries']) . '개, ' . number_format($total) . ' bytes');
    foreach ($res['binaries'] as $b) {
        $w("  {$b['file']}  " . number_format($b['size']) . ' bytes' . ($b['note'] === 'too_large' ? '  (크기 초과로 건너뜀)' : ''));
    }
}
$w('');
$w($res['ok'] ? '결과: 발견 없음' : '결과: 발견 ' . count($res['findings']) . '건');
exit($res['ok'] ? 0 : 1);
