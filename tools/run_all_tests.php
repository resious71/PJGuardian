<?php

declare(strict_types=1);

/**
 * 전체 시험 한 번에 실행(P19): 제품 테스트(PHP) → JS 테스트 → 아이콘 점검 → 1단계 E2E(실제 워커·HTTP).
 * JS 테스트 앞에 시험 전용 의존성(tests/tools, 엄격한 YAML 파서)이 없으면 npm ci로 설치한다(P20).
 *
 *   php tools/run_all_tests.php [--skip-e2e] [--node=<node 경로>]
 *
 * 각 단계의 마지막 출력 몇 줄과 통과 여부·시간을 요약한다. 하나라도 실패하면 종료 코드 1.
 * E2E는 data/work/e2e_*에 격리 환경을 만들고 끝나면 지운다(공유 config.ini·개발 DB를 쓰지 않음).
 * 큰 시험(--big 2GB, --bigfiles 100만 항목)과 Apache·Linux 검증은 포함하지 않는다(P19B).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$opt = getopt('', ['skip-e2e', 'node:']);
$node = (string) ($opt['node'] ?? (is_file('C:/tools/nodejs/node.exe') ? 'C:/tools/nodejs/node.exe' : 'node'));
$steps = [
    ['PHP 제품 테스트(Unit + Integration)', [PHP_BINARY, PJG_ROOT . '/tests/run.php']],
    ['JS 테스트(node --test)', [$node, '--test', 'tests/js/*.test.mjs']],
    ['아이콘 점검', [$node, PJG_ROOT . '/tools/check_icons.mjs']],
];
if (!isset($opt['skip-e2e'])) {
    $steps[] = ['1단계 E2E(tests/e2e/phase1.php)', [PHP_BINARY, PJG_ROOT . '/tests/e2e/phase1.php']];
}

// 시험 전용 개발 의존성(tests/tools: 엄격한 YAML 파서, P20). 없으면 npm ci로 설치한다(배포물에는 넣지 않음)
if (!is_file(PJG_ROOT . '/tests/tools/node_modules/yaml/package.json')) {
    $nodeDir = dirname((string) (realpath($node) ?: $node));
    $npmCli = null;
    foreach ([$nodeDir . '/node_modules/npm/bin/npm-cli.js', $nodeDir . '/../lib/node_modules/npm/bin/npm-cli.js'] as $cand) {
        if (is_file($cand)) {
            $npmCli = $cand;
            break;
        }
    }
    if ($npmCli !== null) {
        echo "
=== 시험 도구 설치(tests/tools, npm ci) ===
";
        $p = proc_open([$node, $npmCli, 'ci', '--no-audit', '--no-fund'], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r']], $pipes, PJG_ROOT . '/tests/tools', null, ['bypass_shell' => true]);
        if (is_resource($p)) {
            proc_close($p);
        }
    }
}

$summary = [];
$allOk = true;
foreach ($steps as [$name, $cmd]) {
    echo "\n=== {$name} ===\n";
    $t = microtime(true);
    // 출력은 파일로 받는다(파이프를 쓰면 자식·손자 프로세스가 큰 출력을 낼 때 Windows에서 교착될 수 있다)
    $log = tempnam(sys_get_temp_dir(), 'pjgtest');
    $p = proc_open($cmd, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        $summary[] = [$name, false, 0.0, ['실행할 수 없음']];
        $allOk = false;
        continue;
    }
    $code = proc_close($p);
    $out = (string) file_get_contents($log);
    @unlink($log);
    echo $out;
    $ok = $code === 0;
    $allOk = $allOk && $ok;
    $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== ''));
    $tail = array_values(array_filter($lines, static fn (string $l): bool => preg_match('/결과:|ℹ (pass|fail)|통과|시나리오 통과|\[OK\]|점검 통과|실패/u', $l) === 1));
    $summary[] = [$name, $ok, microtime(true) - $t, array_slice($tail, -4)];
}

echo "\n=== 요약 (" . PHP_OS_FAMILY . ', PHP ' . PHP_VERSION . ") ===\n";
foreach ($summary as [$name, $ok, $sec, $tail]) {
    printf("%s %-36s %6.0f초  %s\n", $ok ? '통과' : '실패', $name, $sec, implode(' / ', $tail));
}
echo $allOk ? "전체 통과\n" : "실패한 단계가 있습니다\n";
exit($allOk ? 0 : 1);
