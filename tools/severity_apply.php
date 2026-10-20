<?php

declare(strict_types=1);

/**
 * 기존 결함에 규칙 기본 심각도 채우기(P21S, docs/severity_mapping.md 4절). CLI 전용(웹에서 실행할 수 없다).
 *   php tools/severity_apply.php [--config=<ini> | --db=<sqlite>] [--project=<id>] [--reapply-auto] [--apply] [--chunk=500]
 *
 * 기본은 미리보기(DB를 바꾸지 않는다). --apply를 붙여야 바꾼다.
 * 대상: 출처 none이고 심각도 not_defined인 결함 중 매핑이 있는 규칙. --reapply-auto면 출처 auto인 결함도 지금 매핑으로 다시 계산한다.
 *   출처 manual(사람이 바꾼 값)은 어떤 옵션으로도 건드리지 않는다.
 * 변경마다 defect_history에 자동 이력(field severity, reason_code rule_default, 메모 "규칙 기본 심각도 적용(매핑 <도구> v<버전>)")을 남긴다.
 *   과거 Run 보기는 이 이력으로 적용 전 Run의 값(not_defined)을 복원한다(P20R). version을 올려 열려 있던 편집 화면은 409로 최신 값을 보게 한다.
 * 결함 id 순서로 청크(기본 500건)마다 짧은 쓰기 트랜잭션을 쓰고 청크 사이에 잠깐 쉰다(분석 반영에 양보).
 * WAL 체크포인트는 P20S 정책대로: 이 연결의 자동 체크포인트를 끄고(청크마다 COMMIT 직후 체크포인트·fsync를 하면 큰 DB에서 커밋이 0.7~7초,
 *   P21S 측정) 100청크마다 PASSIVE(다른 연결을 막지 않음), 끝나면 WAL이 database.wal_truncate_threshold를 넘었을 때 TRUNCATE.
 *   각 행은 "지금도 대상 조건을 만족할 때만" 바꾸므로 중간에 멈춰도 다시 실행하면 남은 것만 이어서 끝난다(멱등, 두 번째 실행은 0건).
 * 종료 코드: 0 정상, 2 오류
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\WalCheckpoint;

/** PASSIVE 체크포인트 간격(청크 수). 체크포인트는 다른 연결을 막지 않지만 큰 DB에서 한 번에 수백 ms~수 초(P21S 측정) */
const CHECKPOINT_EVERY = 100;
$opt = getopt('', ['config:', 'db:', 'project:', 'reapply-auto', 'apply', 'chunk:', 'sleep-ms:']);
$apply = isset($opt['apply']);
$reapply = isset($opt['reapply-auto']);
$chunk = max(1, (int) ($opt['chunk'] ?? 500));
$sleepMs = max(0, (int) ($opt['sleep-ms'] ?? 20));
$project = isset($opt['project']) ? (int) $opt['project'] : null;
if (isset($opt['project']) && ($project === null || $project < 1)) {
    fwrite(STDERR, "--project는 1 이상의 정수\n");
    exit(2);
}

try {
    $configPath = isset($opt['config']) ? (string) realpath((string) $opt['config']) : null;
    $app = App::create(PJG_ROOT, $configPath !== null ? Config::load($configPath) : null);
    if (isset($opt['db'])) {
        $path = (string) realpath((string) $opt['db']);
        if ($path === '') {
            throw new RuntimeException('DB 파일 없음: ' . $opt['db']);
        }
        $db = new Db($path, $app->config->int('database.busy_timeout') * 1000);
    } else {
        $db = $app->db();
        $path = (string) $db->value('SELECT file FROM pragma_database_list WHERE name = ?', ['main']);
    }
    // 큰 결함 표에서 인덱스(심각도·필터 요약·이력) 갱신이 기본 페이지 캐시(2MB)를 넘어 디스크를 오가지 않게 이 연결만 128MB
    $db->pdo()->exec('PRAGMA cache_size = -131072');
    $checkpoint = new WalCheckpoint($db, $app->logger, $app->config->int('database.wal_truncate_threshold'));
    if ($apply) {
        WalCheckpoint::disableAuto($db);
    }
    $map = $app->severityMap();
    $map->validate();
    $version = $map->versionLabel();
    $now = SystemClock::iso($app->clock->now());

    echo "DB: {$path}\n매핑: {$version}\n";
    echo $apply ? "모드: 적용(--apply)\n" : "모드: 미리보기(바꾸지 않음. 적용은 --apply)\n";
    if ($apply) {
        $slash = str_replace('\\', '/', $path);
        $backup = (string) preg_replace('/\.sqlite$/', '', $slash) . '-before-severity-' . gmdate('Ymd-His') . '.sqlite';
        echo "백업(먼저 실행 권장, 워커를 멈춘 뒤): php -r \"(new PDO('sqlite:{$slash}'))->exec(\\\"VACUUM INTO '{$backup}'\\\");\"\n";
    }

    $cond = "((severity_source = 'none' AND severity = 'not_defined')" . ($reapply ? " OR severity_source = 'auto'" : '') . ')';
    $scope = $project !== null ? ' AND project_id = ?' : '';
    $scopeParams = $project !== null ? [$project] : [];
    $memo = static fn (string $tool): string => '규칙 기본 심각도 적용(매핑 ' . $tool . ' v' . $map->version($tool) . ')';

    $stats = ['scanned' => 0, 'changed' => 0, 'same' => 0, 'skipped_unmapped' => 0, 'raced' => 0];
    $byProject = [];
    $unmapped = [];
    $before = array_fill_keys(['critical', 'high', 'medium', 'low', 'not_defined'], 0);
    $after = $before;
    $lastId = 0;
    $stmts = null; // 적용 문장(청크마다 다시 준비하지 않음)
    $chunks = 0;
    $cpMs = 0;
    $t0 = hrtime(true);
    $maxTx = 0;
    while (true) {
        $rows = $db->all("SELECT id, project_id, tool, rule_key, severity, severity_source FROM defects WHERE id > ? AND {$cond}{$scope} ORDER BY id LIMIT ?",
            [$lastId, ...$scopeParams, $chunk]);
        if ($rows === []) {
            break;
        }
        $lastId = (int) end($rows)['id'];
        $changes = [];
        foreach ($rows as $r) {
            $stats['scanned']++;
            $new = $map->forRule((string) $r['tool'], (string) $r['rule_key']);
            $old = (string) $r['severity'];
            if ($new === 'not_defined' && $r['severity_source'] === 'none') {
                $stats['skipped_unmapped']++;
                $unmapped[(string) $r['rule_key']] = ($unmapped[(string) $r['rule_key']] ?? 0) + 1;
                continue;
            }
            if ($new === $old) {
                $stats['same']++;
                continue;
            }
            $changes[] = [(int) $r['id'], (int) $r['project_id'], (string) $r['tool'], $old, (string) $r['severity_source'], $new];
        }
        if ($apply && $changes !== []) {
            $tx = hrtime(true);
            $done = $db->transaction(static function (Db $db) use ($changes, $now, $memo, &$stmts): array {
                $stmts ??= [
                    $db->pdo()->prepare('UPDATE defects SET severity = ?, severity_source = ?, version = version + 1, updated_at = ? WHERE id = ? AND severity = ? AND severity_source = ?'),
                    $db->pdo()->prepare("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, reason_code, memo, analysis_id) VALUES (?, ?, ?, ?, NULL, ?, 'auto', 'rule_default', ?, NULL)"),
                ];
                [$upd, $hist] = $stmts;
                $applied = [];
                foreach ($changes as $c) {
                    [$id, , $tool, $old, $source, $new] = $c;
                    // 지금도 같은 값·출처일 때만(그사이 사람이 바꿨으면 건너뜀)
                    $upd->execute([$new, $new === 'not_defined' ? 'none' : 'auto', $now, $id, $old, $source]);
                    if ($upd->rowCount() === 1) {
                        $hist->execute([$id, 'severity', $old, $new, $now, $memo($tool)]);
                        $applied[] = $c;
                    }
                }
                return $applied;
            }, write: true);
            $maxTx = max($maxTx, (int) ((hrtime(true) - $tx) / 1e6));
            if (++$chunks % CHECKPOINT_EVERY === 0) {
                $cpMs += $checkpoint->run(false)['ms'];
            }
            $stats['raced'] += count($changes) - count($done);
            $changes = $done; // 집계는 실제로 바꾼 것만(그사이 사람이 바꾼 결함은 raced)
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        foreach ($changes as [, $pid, , $old, , $new]) {
            $stats['changed']++;
            $before[$old]++;
            $after[$new]++;
            $byProject[$pid][$new] = ($byProject[$pid][$new] ?? 0) + 1;
        }
    }
    if ($apply && $chunks > 0) {
        $cpMs += $checkpoint->run(true)['ms'];
    }
    $ms = (int) ((hrtime(true) - $t0) / 1e6);

    $names = [];
    foreach ($db->all('SELECT id, name FROM projects') as $p) {
        $names[(int) $p['id']] = (string) $p['name'];
    }
    echo "\n대상 조건: " . ($reapply ? '출처 none(심각도 not_defined) + auto(다시 계산)' : '출처 none·심각도 not_defined') . ($project !== null ? ", 프로젝트 #{$project}" : ', 전체 프로젝트') . "\n";
    printf("살펴본 결함 %d건 → %s %d건, 이미 같은 값 %d건, 매핑 없는 규칙이라 건너뜀 %d건%s\n", $stats['scanned'], $apply ? '변경' : '변경 예정',
        $stats['changed'], $stats['same'], $stats['skipped_unmapped'], $stats['raced'] > 0 ? ", 그사이 바뀌어 건너뜀 {$stats['raced']}건" : '');
    echo "\n| 프로젝트 | critical | high | medium | low | not_defined | 합계 |\n| --- | --- | --- | --- | --- | --- | --- |\n";
    ksort($byProject);
    foreach ($byProject as $pid => $c) {
        $row = array_map(static fn (string $s): int => $c[$s] ?? 0, array_keys($before));
        printf("| %s (#%d) | %s | %d |\n", $names[$pid] ?? '?', $pid, implode(' | ', $row), array_sum($row));
    }
    echo "\n변경 전후(바뀌는 결함만):\n| 심각도 | 전 | 후 |\n| --- | --- | --- |\n";
    foreach ($before as $s => $n) {
        printf("| %s | %d | %d |\n", $s, $n, $after[$s]);
    }
    arsort($unmapped);
    echo "\n매핑에 없는 규칙(상위 20, not_defined로 남음): " . ($unmapped === [] ? '없음' : '') . "\n";
    foreach (array_slice($unmapped, 0, 20, true) as $k => $n) {
        echo "  {$k}\t{$n}\n";
    }
    printf("\n소요 %dms%s\n", $ms, $apply ? ", 가장 긴 청크 트랜잭션 {$maxTx}ms (청크 {$chunk}건, {$chunks}개), WAL 체크포인트 합계 {$cpMs}ms" : '');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
