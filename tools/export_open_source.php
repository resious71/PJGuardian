<?php

declare(strict_types=1);

/**
 * 공개용 트리 내보내기(P33). 개발 저장소는 바꾸지 않고, 추적 파일(git ls-files)만 골라 별도 폴더에 복사한다.
 *   php tools/export_open_source.php [--out=dist/oss] [--force [--with-untracked]] [--ruff=<버전> --eslint=<버전>] [--no-scan] [--no-links]
 *                                    [--repo=<저장소 루트>] [--rules=<규칙 파일>]
 *
 * - 제외·바꾸기 규칙: tools/export_open_source.rules.php(사용자가 고치는 설정 파일). 제외한 항목은 화면에 출력한다.
 * - 내보낼 파일 중 커밋하지 않은 변경이 있으면 멈춘다(--force면 OSS_MANIFEST.json에 dirty=true로 남기고 계속, build_bundle.php와 같은 방식).
 *   제외 대상(prompts/·docs/results/ 등)의 변경은 보지 않는다. --with-untracked(--force와 함께)는 커밋 전 확인용으로 무시 목록에 없는 새 파일도 넣는다
 *   (규칙의 untracked_exclude — 사용자가 일부러 커밋하지 않는 파일 — 는 빼고, 넣은 새 파일도 dirty로 센다).
 * - bin/: 텍스트(매니페스트·VERSION.txt·package*.json·.gitkeep)만. ruff·ESLint는 현재 사용 버전 하나만 — 개발 DB의 tool_versions(이 OS의
 *   active·pinned, 읽기 전용으로 엶)에서 읽거나 --ruff·--eslint로 지정한다. 알 수 없거나 둘 이상이면 멈춘다. git·p4는 버전 폴더가 하나여야 한다.
 *   실행 파일(MZ·ELF·Mach-O·#! 머리)이 하나라도 있으면 멈춘다.
 * - 바꾸기(rewrite): CLAUDE.md 개발 PC 경로 일반화, .gitignore의 "공개할 때만" 주석 블록 정리 등 — 공개용 사본에만 적용하고 목록을 출력한다.
 * - 끝에 OSS_MANIFEST.json(파일 목록 + SHA-256)을 쓰고, 비밀·개인 정보 스캐너(tools/scan_release.php)와 링크 검사(<출력>/tools/check_doc_links.php)를
 *   돌린다. 둘 다 통과해야 종료 코드 0. 인터넷에 접속하지 않고, 푸시하지 않는다.
 * 종료 코드: 0 완료, 1 검사 실패(스캐너·링크·실행 파일), 2 사용 오류·멈춤(미커밋 변경, 활성 버전 불명, 규칙 불일치).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$opt = getopt('', ['out:', 'force', 'with-untracked', 'ruff:', 'eslint:', 'no-scan', 'no-links', 'repo:', 'rules:']);
$abs = static function (string $p): string {
    $p = str_replace('\\', '/', $p);
    return rtrim(preg_match('#^([A-Za-z]:/|/)#', $p) === 1 ? $p : str_replace('\\', '/', (string) getcwd()) . '/' . $p, '/');
};
$repo = $abs((string) ($opt['repo'] ?? PJG_ROOT));
$out = $abs((string) ($opt['out'] ?? $repo . '/dist/oss'));
$rulesFile = (string) ($opt['rules'] ?? __DIR__ . '/export_open_source.rules.php');

$fail = static function (string $msg, int $code = 2): never {
    fwrite(STDERR, $msg . "\n");
    exit($code);
};
if (!is_file($rulesFile)) {
    $fail("규칙 파일이 없습니다: {$rulesFile}");
}
/** @var array{exclude: list<string>, exclude_regex: list<string>, data_skeleton: bool, bin_current_only: list<string>, bin_single_version: list<string>, rewrite: array<string, list<array<string, string>>>} $rules */
$rules = require $rulesFile;

$git = static function (array $args) use ($repo, $fail): string {
    $p = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
    if (!is_resource($p)) {
        $fail('git을 실행할 수 없습니다');
    }
    $o = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
    return $o;
};
$commit = trim($git(['rev-parse', 'HEAD']));
if ($commit === '') {
    $fail("git 저장소가 아닙니다: {$repo}");
}

// 출력 폴더: 저장소 밖이거나 저장소 안이면 dist/ 아래만. 이미 있으면 우리가 만든 트리(OSS_MANIFEST.json)이거나 빈 폴더일 때만 지운다
$inRepo = str_starts_with(strtolower($out) . '/', strtolower($repo) . '/');
if (str_contains($out, '/../') || ($inRepo && !str_starts_with(strtolower($out) . '/', strtolower($repo) . '/dist/') && !str_starts_with(strtolower($out) . '/', strtolower($repo) . '/data/work/'))) {
    $fail("저장소 안에서는 dist/ 또는 data/work/(시험·임시) 아래에만 만듭니다: {$out}");
}
if (is_dir($out)) {
    $entries = PJGuardian\Support\Fs::listDir($out);
    if ($entries !== [] && !is_file($out . '/OSS_MANIFEST.json')) {
        $fail("출력 폴더가 비어 있지 않고 이전 공개용 트리도 아닙니다(지우지 않음): {$out}");
    }
    PJGuardian\Support\Fs::removeTree($out);
}

// 1. 추적 파일과 제외
$tracked = array_values(array_filter(explode("\0", $git(['ls-files', '-z'])), static fn (string $f): bool => $f !== ''));
// --with-untracked: 커밋 전 확인용 — 무시 목록에 없는 새 파일도 넣는다(dirty로 표시, 규칙의 untracked_exclude는 빼기)
$untracked = [];
if (isset($opt['with-untracked'])) {
    if (!isset($opt['force'])) {
        $fail('--with-untracked는 --force와 함께만 씁니다(커밋 전 확인용)');
    }
    foreach (explode("\0", $git(['ls-files', '-z', '--others', '--exclude-standard'])) as $u) {
        if ($u !== '' && !in_array($u, $rules['untracked_exclude'] ?? [], true)) {
            $untracked[] = $u;
        }
    }
    $tracked = array_merge($tracked, $untracked);
}
$excluded = [];
$isExcluded = static function (string $f) use ($rules): ?string {
    foreach ($rules['exclude'] as $p) {
        if ($f === $p || (str_ends_with($p, '/') && str_starts_with($f, $p))) {
            return $p;
        }
    }
    foreach ($rules['exclude_regex'] as $re) {
        if (preg_match($re, $f) === 1) {
            return $re;
        }
    }
    if (($rules['data_skeleton'] ?? true) && str_starts_with($f, 'data/') && basename($f) !== '.gitkeep') {
        return 'data/ 뼈대만';
    }
    return null;
};
$files = [];
foreach ($tracked as $f) {
    $why = $isExcluded($f);
    if ($why !== null) {
        $excluded[$why] = ($excluded[$why] ?? 0) + 1;
        continue;
    }
    $files[] = $f;
}

// 2. bin/: 도구별 버전 고르기
$versionsIn = static function (string $tool) use ($files): array {
    $v = [];
    foreach ($files as $f) {
        if (preg_match('#^bin/' . preg_quote($tool, '#') . '/([^/]+)/#u', $f, $m) === 1) {
            $v[$m[1]] = true;
        }
    }
    return array_keys($v);
};
$active = [];
foreach ($rules['bin_current_only'] as $tool) {
    if (isset($opt[$tool])) {
        $active[$tool] = (string) $opt[$tool];
    }
}
$missing = array_diff($rules['bin_current_only'], array_keys($active));
if ($missing !== []) {
    // 개발 DB의 도구 상태(이 OS)에서 활성·고정 버전을 읽는다(읽기 전용)
    try {
        $config = PJGuardian\Support\Config::fromRoot($repo);
        $dbFile = (new PJGuardian\Support\Paths($repo, $config))->dbFile();
        if (!is_file($dbFile)) {
            throw new RuntimeException('DB 없음');
        }
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $st = $pdo->prepare("SELECT version FROM tool_versions WHERE tool = ? AND os = ? AND status IN ('active', 'pinned')");
        foreach ($missing as $tool) {
            $st->execute([$tool, PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux']);
            $vs = array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
            if (count($vs) !== 1) {
                $fail("{$tool}의 현재 사용 버전을 하나로 정할 수 없습니다(개발 DB: " . ($vs === [] ? '없음' : implode(', ', $vs)) . "). --{$tool}=<버전>으로 지정하세요");
            }
            $active[$tool] = $vs[0];
        }
    } catch (Throwable $e) {
        $fail('현재 사용 버전을 개발 DB에서 읽을 수 없습니다(' . $e->getMessage() . '). --' . implode('=<버전> --', $missing) . '=<버전>으로 지정하세요');
    }
}
$binNote = [];
foreach ($active as $tool => $v) {
    $all = $versionsIn($tool);
    if (!in_array($v, $all, true)) {
        $fail("{$tool} {$v}의 매니페스트가 저장소에 없습니다(있는 버전: " . implode(', ', $all) . ')');
    }
    $drop = array_diff($all, [$v]);
    if ($drop !== []) {
        $files = array_values(array_filter($files, static function (string $f) use ($tool, $drop): bool {
            foreach ($drop as $d) {
                if (str_starts_with($f, "bin/{$tool}/{$d}/")) {
                    return false;
                }
            }
            return true;
        }));
        $excluded["bin/{$tool} 지난 버전(" . implode(', ', $drop) . ')'] = count($drop);
    }
    $binNote[$tool] = $v;
}
foreach ($rules['bin_single_version'] as $tool) {
    $all = $versionsIn($tool);
    if (count($all) > 1) {
        $fail("bin/{$tool}에 버전 폴더가 둘 이상입니다(" . implode(', ', $all) . ') — 공개할 하나를 정해 규칙(bin_current_only)에 넣으세요');
    }
    $binNote[$tool] = $all[0] ?? null;
}

// 3. 미커밋 변경(내보낼 파일만)
$dirtyFiles = [];
$fileIndex = array_flip($files);
foreach (explode("\0", $git(['status', '--porcelain', '-z', '--untracked-files=no'])) as $entry) {
    // 형식 "XY 경로"(이름 바꾸기는 다음 항목이 원래 경로 — 그것도 경로로 보고 확인)
    $path = strlen($entry) > 3 && $entry[2] === ' ' ? substr($entry, 3) : $entry;
    if ($path !== '' && isset($fileIndex[$path])) {
        $dirtyFiles[] = $path;
    }
}
foreach ($untracked as $u) {
    if (isset($fileIndex[$u])) {
        $dirtyFiles[] = $u;
    }
}
$dirty = $dirtyFiles !== [];
if ($dirty && !isset($opt['force'])) {
    $fail("내보낼 파일에 커밋하지 않은 변경이 있습니다(" . count($dirtyFiles) . '개: ' . implode(', ', array_slice($dirtyFiles, 0, 5)) . (count($dirtyFiles) > 5 ? ' …' : '') . "). 커밋한 뒤 만들거나 --force(OSS_MANIFEST.json에 dirty 표시)");
}

// 4. 복사(+ 공개용 사본 바꾸기), 실행 파일 검사
$fileSet = array_flip($files);
$applied = [];
$appliedAll = [];
$appliedAllFiles = [];
$executables = [];
mkdir($out, 0777, true);
foreach ($files as $f) {
    $src = $repo . '/' . $f;
    if (!is_file($src)) {
        continue; // git이 아는데 작업 트리에서 지운 파일(--force일 때)
    }
    $dst = $out . '/' . $f;
    if (!is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0777, true);
    }
    $data = (string) file_get_contents($src);
    if (str_starts_with($f, 'bin/')) {
        $head = substr($data, 0, 4);
        if ($head === "MZ\x90\x00" || str_starts_with($head, 'MZ') || $head === "\x7fELF" || in_array(bin2hex($head), ['cffaedfe', 'cefaedfe', 'feedface', 'feedfacf', 'cafebabe'], true) || str_starts_with($data, '#!')) {
            $executables[] = $f;
        }
    }
    foreach ($rules['rewrite'][$f] ?? [] as $i => $rw) {
        if (isset($rw['when_missing']) && isset($fileSet[$rw['when_missing']])) {
            continue;
        }
        // 이미 공개용인 원본(공개 저장소에서 다시 내보낼 때): 바꾼 결과가 이미 있으면 건너뛴다
        $present = $rw['already_present'] ?? (isset($rw['from']) && $rw['to'] !== '' ? $rw['to'] : null);
        if (($present !== null && str_contains($data, $present)) || (isset($rw['already_absent']) && !str_contains($data, $rw['already_absent']))
            || (isset($rw['already_absent_regex']) && preg_match($rw['already_absent_regex'], $data) !== 1)) {
            $applied[] = "{$f}#{$i}: 이미 적용됨";
            continue;
        }
        $before = $data;
        if (isset($rw['regex'])) {
            $data = (string) preg_replace($rw['regex'], $rw['to'], $data, -1, $n);
        } else {
            $n = substr_count($data, $rw['from']);
            $data = str_replace($rw['from'], $rw['to'], $data);
        }
        if ($n === 0 || $before === $data) {
            PJGuardian\Support\Fs::removeTree($out);
            $fail("바꾸기 규칙 {$f}#{$i}가 맞는 글을 찾지 못했습니다(원본이 바뀜 — 규칙을 고치세요)");
        }
        $applied[] = "{$f}#{$i}: {$n}곳";
    }
    foreach ($rules['rewrite_all'] ?? [] as $i => $rw) {
        if ((isset($rw['when_missing']) && isset($fileSet[$rw['when_missing']])) || preg_match($rw['path_regex'], $f) !== 1) {
            continue;
        }
        $n = substr_count($data, $rw['from']);
        if ($n > 0) {
            $data = str_replace($rw['from'], $rw['to'], $data);
            $appliedAll[$i] = ($appliedAll[$i] ?? 0) + $n;
            $appliedAllFiles[$i][] = $f;
        }
    }
    file_put_contents($dst, $data);
}
if ($executables !== []) {
    PJGuardian\Support\Fs::removeTree($out);
    $fail("bin/에 실행 파일이 있습니다(공개용 트리에 넣지 않음): " . implode(', ', $executables), 1);
}

// 5. 매니페스트
$manifest = [];
$bytes = 0;
foreach ($files as $f) {
    if (is_file($out . '/' . $f)) {
        $size = (int) filesize($out . '/' . $f);
        $bytes += $size;
        $manifest[] = ['path' => $f, 'size' => $size, 'sha256' => hash_file('sha256', $out . '/' . $f)];
    }
}
file_put_contents($out . '/OSS_MANIFEST.json', json_encode([
    'name' => 'PJGuardian',
    'version' => PJGuardian\App::VERSION,
    'source_commit' => $commit,
    'dirty' => $dirty,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'bin_versions' => $binNote,
    'excluded' => $excluded,
    'rewrites' => $applied,
    'rewrites_all' => array_map(static fn ($i) => ['rule' => $i, 'files' => $appliedAllFiles[$i], 'count' => $appliedAll[$i]], array_keys($appliedAll)),
    'files' => $manifest,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

printf("공개용 트리: %s\n원본 커밋 %s%s\n파일 %d개, %.1f MB\n", $out, substr($commit, 0, 12), $dirty ? ' (dirty — 미커밋 변경 ' . count($dirtyFiles) . '개 포함)' : '', count($manifest), $bytes / 1048576);
// 제외: 규칙에 적힌 것은 모두(이번에 걸린 것이 없어도 0으로), 그 밖에 걸린 것(data/ 뼈대·지난 도구 버전)
echo "제외(규칙 tools/export_open_source.rules.php — 이번에 뺀 파일 수):\n";
foreach (array_merge($rules['exclude'], $rules['exclude_regex']) as $rule) {
    printf("  %-48s %d\n", $rule, $excluded[$rule] ?? 0);
}
foreach ($excluded as $why => $n) {
    if (!in_array($why, $rules['exclude'], true) && !in_array($why, $rules['exclude_regex'], true)) {
        printf("  %-48s %d\n", $why, $n);
    }
}
echo 'bin/ 버전: ' . implode(', ', array_map(static fn ($t, $v) => "{$t} " . ($v ?? '-'), array_keys($binNote), $binNote)) . "\n";
echo $applied === [] ? "바꾸기 없음\n" : "바꾸기(공개용 사본만):\n  " . implode("\n  ", $applied) . "\n";
foreach ($appliedAll as $i => $n) {
    $rw = $rules['rewrite_all'][$i];
    printf("전체 바꾸기 #%d \"%s\" → \"%s\": 파일 %d개 %d곳\n", $i, $rw['from'], $rw['to'], count($appliedAllFiles[$i]), $n);
}

// 6. 스캐너·링크 검사
// 자식 출력은 파이프로 받아 이 프로세스가 출력한다(STDOUT 핸들을 넘기면 파일로 돌렸을 때 서로 덮어씀 — Windows)
$run = static function (array $cmd): int {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return 2;
    }
    echo (string) stream_get_contents($pipes[1]);
    fwrite(STDERR, (string) stream_get_contents($pipes[2]));
    return proc_close($p);
};
$status = 0;
if (!isset($opt['no-scan'])) {
    echo "\n== 비밀·개인 정보 스캔(tools/scan_release.php) ==\n";
    $scanner = PJG_ROOT . '/tools/scan_release.php';
    if (!is_file($scanner)) {
        echo "스캐너가 없습니다 — 실패로 봅니다\n";
        $status = 1;
    } elseif (($code = $run([PHP_BINARY, $scanner, $out])) !== 0) {
        echo "스캔 실패(종료 코드 {$code})\n";
        $status = 1;
    }
}
if (!isset($opt['no-links'])) {
    echo "\n== 링크 검사(공개용 트리 기준) ==\n";
    if (!is_file($out . '/tools/check_doc_links.php')) {
        echo "공개용 트리에 tools/check_doc_links.php가 없어 건너뜀\n";
    } elseif (($code = $run([PHP_BINARY, $out . '/tools/check_doc_links.php'])) !== 0) {
        echo "링크 검사 실패(종료 코드 {$code})\n";
        $status = 1;
    }
}
echo $status === 0 ? "\n완료 — 검사 통과\n" : "\n검사 실패 — 위 목록을 고친 뒤 다시 만드세요\n";
exit($status);
