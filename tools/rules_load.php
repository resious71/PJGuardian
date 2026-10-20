<?php

declare(strict_types=1);

/**
 * 규칙 데이터 적재(P22, docs/rules.md). 설치된 도구(bin/)에서 규칙 정보를 꺼내 도구 버전별로 DB에 넣고 버전 사이 변경 내역·검색 색인을 다시 만든다.
 *
 *   php tools/rules_load.php [--config=<ini>] [--tool=ruff|eslint] [--version=<버전>] [--all-installed] [--apply]
 *     기본은 미리보기(도구 실행 + 건수, DB는 바꾸지 않음). --apply로 반영. 같은 입력이면 다시 실행해도 결과가 같다(내용 해시).
 *     대상: 활성 버전(분석이 쓰는 버전) + 아직 적재하지 않은 설치 버전. --all-installed는 설치된 모든 버전을 다시 확인, --version은 그 버전만.
 * 종료 코드: 0 정상, 1 일부 도구·버전 실패(나머지는 적재), 2 오류.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Config;

$opt = getopt('', ['config:', 'tool:', 'version:', 'all-installed', 'apply', 'dry-run']);
try {
    $app = App::create(PJG_ROOT, isset($opt['config']) ? Config::load((string) $opt['config']) : null);
    $tools = isset($opt['tool']) ? [(string) $opt['tool']] : SeverityMap::TOOLS;
    foreach ($tools as $t) {
        if (!in_array($t, SeverityMap::TOOLS, true)) {
            throw new RuntimeException("알 수 없는 도구: {$t}");
        }
    }
    $version = isset($opt['version']) ? (string) $opt['version'] : null;
    if ($version !== null && (!ToolRegistry::validVersion($version) || count($tools) !== 1)) {
        throw new RuntimeException('--version은 올바른 버전과 --tool 하나가 필요함');
    }
    $apply = isset($opt['apply']);
    $start = hrtime(true);
    $loader = $app->ruleLoader();
    $report = $loader->load(isset($opt['all-installed']) ? 'all' : 'default', $tools, $version, $apply);
    foreach ($report['tools'] as $tool => $t) {
        printf("== %s (활성 버전 %s)\n", $tool, $t['active'] ?? '없음');
        foreach ($t['versions'] as $v => $x) {
            if (isset($x['error'])) {
                printf("  %s: 실패 — %s\n", $v, $x['error']);
                continue;
            }
            printf("  %s: 규칙 %d개(설명 %d, 문서 주소 %d), %s, %dms\n", $v, $x['rules'], $x['with_description'], $x['with_docs_url'],
                $x['unchanged'] ? '변경 없음' : ($apply ? '적재함' : '적재 예정'), $x['ms']);
        }
        if (isset($t['changes'])) {
            printf("  변경 내역: %s\n", $t['changes'] === [] ? '없음' : implode(', ', array_map(static fn ($k, $n) => "{$k} {$n}", array_keys($t['changes']), $t['changes'])));
        }
    }
    printf("%s, %.1f초%s\n", $apply ? '반영함' : '미리보기(--apply로 반영)', (hrtime(true) - $start) / 1e9, $report['errors'] === [] ? '' : ', 실패 ' . count($report['errors']) . '건');
    if ($apply) {
        $loader->audit(Actor::cli(), $report);
    }
    exit($report['errors'] === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
