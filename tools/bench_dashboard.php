<?php

declare(strict_types=1);

/**
 * 대시보드 집계 측정(P20, 명세 9, 19.10). 임시 DB(data/work/bench_dash_*)에 프로젝트·분석·결함을 만들고 구역별 응답 시간을 잰 뒤 지운다.
 *
 *   php tools/bench_dashboard.php [--defects=100000] [--projects=200] [--with-defects=40] [--rules=80] [--files=1000] [--repeat=3] [--keep]
 *   php tools/bench_dashboard.php --db=<sqlite 사본> [--repeat=3]     기존 DB 사본(개발 DB를 VACUUM INTO로 복사한 것)에서 측정만
 *
 * 프로젝트 --projects개(메타데이터), 그중 --with-defects개에 결함 --defects건을 고르게 나눈다(규칙 --rules종, 프로젝트마다 파일 --files개,
 * 상태·심각도 무작위, 열린 결함 약 70%). 분석은 프로젝트마다 성공 10건(심각도별 건수 포함) + 실패 1건.
 * 사용자: admin(전체), member(그룹으로 절반 프로젝트), outsider(매핑 없음).
 * 측정: 캐시 끔(조회 시 계산)으로 구역별 --repeat회 중앙값, 캐시 켬(60초)으로 첫 호출·두 번째 호출.
 * 개발 DB에는 쓰지 말 것(--db에는 사본만).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Dashboard\DashboardService;
use PJGuardian\Services\Defects\DefectQuery;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Migrator;
use PJGuardian\Support\SystemClock;

ini_set('memory_limit', '1024M');
$opt = getopt('', ['defects:', 'projects:', 'with-defects:', 'rules:', 'files:', 'repeat:', 'keep', 'db:']);
$repeat = max(1, (int) ($opt['repeat'] ?? 3));
$clock = new SystemClock();
$tmp = str_replace('\\', '/', PJG_ROOT) . '/data/work/bench_dash_' . bin2hex(random_bytes(3));
Fs::ensureDir($tmp);

if (isset($opt['db'])) {
    $db = new Db((string) $opt['db']);
} else {
    $nDefects = max(1, (int) ($opt['defects'] ?? 100000));
    $nProjects = max(1, (int) ($opt['projects'] ?? 200));
    $withDefects = min($nProjects, max(1, (int) ($opt['with-defects'] ?? 40)));
    $nRules = max(1, (int) ($opt['rules'] ?? 80));
    $nFiles = max(1, (int) ($opt['files'] ?? 1000));
    $db = new Db($tmp . '/bench.sqlite');
    (new Migrator($db, PJG_ROOT . '/migrations', $clock))->migrate();
    $t0 = microtime(true);
    seed($db, $nDefects, $nProjects, $withDefects, $nRules, $nFiles);
    printf("시드: 프로젝트 %d개(결함 있는 %d개), 결함 %d건, %.1f초, DB %.1fMB\n", $nProjects, $withDefects, $nDefects, microtime(true) - $t0, filesize($tmp . '/bench.sqlite') / 1048576);
}

function seed(Db $db, int $nDefects, int $nProjects, int $withDefects, int $nRules, int $nFiles): void
{
    mt_srand(20);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $pdo = $db->pdo();
    $pdo->beginTransaction();
    $hash = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    foreach ([['admin', 'admin'], ['member', 'user'], ['outsider', 'user']] as [$login, $role]) {
        $db->execute("INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at) VALUES (?, ?, ?, 'active', 'local', ?, ?, ?)",
            [$login, $login, $role, $hash, $now, $now]);
    }
    $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('g', ?, ?)", [$now, $now]);
    $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, 2, 0, ?)', [$now]);
    $sev = ['critical', 'high', 'medium', 'low', 'not_defined'];
    $st = ['open', 'open', 'open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved', 'resolved', 'resolved'];
    $insA = $pdo->prepare("INSERT INTO analyses (project_id, status, source_kind, trigger_kind, requested_by, tool, tool_version, config_source, config_hash, queued_at, started_at, finished_at,"
        . " total_count, new_count, resolved_count, reopened_count, unchanged_count, severity_counts, failure_code) VALUES (?, ?, 'upload', 'user', 1, ?, ?, 'server_default', ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)");
    $insD = $pdo->prepare('INSERT INTO defects (project_id, signature, ordinal, tool, language, rule_key, file_path, line_no, message, severity, status, first_seen_analysis_id, last_seen_analysis_id, created_at, updated_at)'
        . ' VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $per = intdiv($nDefects, $withDefects);
    for ($p = 1; $p <= $nProjects; $p++) {
        $lang = $p % 2 === 0 ? 'javascript' : 'python';
        $tool = $lang === 'python' ? 'ruff' : 'eslint';
        $db->execute('INSERT INTO projects (name, language, created_at, updated_at) VALUES (?, ?, ?, ?)', [sprintf('proj-%03d', $p), $lang, $now, $now]);
        if ($p % 2 === 0) {
            $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', 1, ?)", [$p, $now]);
        }
        $last = null;
        for ($a = 0; $a < 10; $a++) {
            $t = gmdate('Y-m-d\TH:i:s\Z', time() - (10 - $a) * 86400 - $p);
            $counts = array_combine($sev, array_map(static fn () => mt_rand(0, $per), $sev));
            $insA->execute([$p, 'succeeded', $tool, $a < 7 ? '1.0.0' : '1.1.0', str_repeat($a < 5 ? 'a' : 'b', 64), $t, $t, $t, array_sum($counts), json_encode($counts), null]);
            $last = (int) $pdo->lastInsertId();
        }
        $insA->execute([$p, 'failed', $tool, null, null, $now, $now, $now, null, null, $p % 7 === 0 ? 'tool_unavailable' : 'timeout']);
        if ($p > $withDefects) {
            continue;
        }
        for ($i = 0; $i < $per; $i++) {
            $file = sprintf('src/pkg%02d/module_%04d.%s', $i % 17, mt_rand(0, $nFiles - 1), $lang === 'python' ? 'py' : 'js');
            $rule = $tool . ':R' . mt_rand(0, $nRules - 1) % max(1, (int) round($nRules * (mt_rand(1, 100) / 100)));
            $insD->execute([$p, hash('sha256', $p . ':' . $i), $tool, $lang, $rule, $file, $i + 1, 'message ' . $i, $sev[mt_rand(0, 4)], $st[mt_rand(0, 9)], $last, $last, $now, $now]);
        }
    }
    $pdo->commit();
    $db->execute('ANALYZE');
}

$logger = new Logger($tmp, $clock, 'ERROR');
$access = new ProjectAccess($db, $clock, new AuditLog($db, $clock), $logger);
$query = new DefectQuery($db, $access);
$users = [];
foreach ($db->all("SELECT * FROM users WHERE status = 'active' ORDER BY id LIMIT 3") as $u) {
    $users[] = $u;
}
$admin = array_values(array_filter($users, static fn (array $u): bool => $u['role'] === 'admin'))[0] ?? $users[0];
$member = array_values(array_filter($users, static fn (array $u): bool => $u['role'] !== 'admin'))[0] ?? null;

function svc(Db $db, SystemClock $clock, ProjectAccess $access, DefectQuery $q, string $dir, int $ttl): DashboardService
{
    return new DashboardService($db, $clock, $access, $q, $dir, $ttl, 10, 10);
}

$sections = [
    'summary' => static fn (DashboardService $s, array $u) => $s->summary($u),
    'trend(5개)' => static fn (DashboardService $s, array $u) => $s->trend($u, null),
    'top-rules(전체)' => static fn (DashboardService $s, array $u) => $s->top($u, 'rules', null),
    'top-files(전체)' => static fn (DashboardService $s, array $u) => $s->top($u, 'files', null),
    'top-files(프로젝트 2)' => static fn (DashboardService $s, array $u) => $s->top($u, 'files', '2'),
    'tools' => static fn (DashboardService $s, array $u) => $s->tools($u),
];
$total = (int) $db->value('SELECT COUNT(*) FROM defects');
$projects = (int) $db->value('SELECT COUNT(*) FROM projects');
printf("\n대상: 결함 %d건, 프로젝트 %d개, 분석 %d건 (%s, PHP %s, SQLite %s)\n", $total, $projects, (int) $db->value('SELECT COUNT(*) FROM analyses'),
    PHP_OS_FAMILY, PHP_VERSION, $db->sqliteVersion());
foreach (['admin' => $admin, 'member' => $member] as $label => $user) {
    if ($user === null) {
        continue;
    }
    printf("\n[%s] 보이는 프로젝트 %d개\n", $label, count(svc($db, $clock, $access, $query, $tmp . '/c0', 0)->visibleProjects($user)));
    printf("%-22s %12s %12s %12s\n", '구역', '계산(중앙값)', '캐시 첫 호출', '캐시 적중');
    $sum = 0.0;
    foreach ($sections as $name => $fn) {
        $times = [];
        for ($i = 0; $i < $repeat; $i++) {
            $t = microtime(true);
            $fn(svc($db, $clock, $access, $query, $tmp . '/c0', 0), $user);
            $times[] = (microtime(true) - $t) * 1000;
        }
        sort($times);
        $med = $times[intdiv(count($times), 2)];
        $sum += $med;
        $cdir = $tmp . '/c_' . $label;
        $t = microtime(true);
        $fn(svc($db, $clock, $access, $query, $cdir, 60), $user);
        $first = (microtime(true) - $t) * 1000;
        $t = microtime(true);
        $r = $fn(svc($db, $clock, $access, $query, $cdir, 60), $user);
        $hit = (microtime(true) - $t) * 1000;
        printf("%-22s %10.1fms %10.1fms %10.1fms%s\n", $name, $med, $first, $hit, ($r['cached'] ?? false) ? '' : ' (적중 안 됨)');
    }
    printf("%-22s %10.1fms\n", '합계(5구역 순차)', $sum);
}
if (!isset($opt['keep'])) {
    $db = null;
    gc_collect_cycles();
    Fs::removeTree($tmp);
} else {
    echo "\n남김: {$tmp}\n";
}
