<?php

declare(strict_types=1);

/**
 * 가이드 캡처용 가짜 데이터 설치본(P29, tools/make_guide_screenshots.mjs가 부른다 — 개발 전용).
 *   php tools/guide_demo/setup.php <빈 폴더>   (비밀번호는 환경 변수 PJG_DEMO_PASSWORD — 출력하지 않음)
 * 새 DB(마이그레이션)·자체 config·가짜 사용자(Alex Kim 등)·가짜 프로젝트 2개·합성 결함(tools/seed_defects.php)·알림 몇 건.
 * 실제 개발 DB·공유 config·실제 사용자·경로·호스트를 쓰지 않는다. 시각은 고정 값(같은 입력이면 같은 화면).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Support\Db;

[, $dir, $port] = $argv + [1 => '', 2 => '8098'];
$pw = (string) getenv('PJG_DEMO_PASSWORD');
if ($dir === '' || $pw === '' || (is_dir($dir) && array_diff(scandir($dir) ?: [], ['.', '..']) !== [])) {
    fwrite(STDERR, "사용법: PJG_DEMO_PASSWORD=... php tools/guide_demo/setup.php <빈 폴더> [포트]\n");
    exit(2);
}
$dir = str_replace('\\', '/', $dir);
foreach (['db', 'work', 'logs', 'uploads', 'snapshots', 'backups'] as $d) {
    @mkdir("{$dir}/{$d}", 0700, true);
}
// 예시 설정(모든 기본값)을 바탕으로 이 설치본의 경로·주소만 바꾼다
$raw = parse_ini_file(PJG_ROOT . '/config/config.ini.example', true, INI_SCANNER_RAW);
$raw['app']['env'] = 'dev';
$raw['app']['base_url'] = "http://127.0.0.1:{$port}";
$raw['security']['require_https'] = 'false';
$raw['rules']['auto_load'] = 'false';
foreach (['db_file' => 'db/demo.sqlite', 'uploads_dir' => 'uploads', 'work_dir' => 'work', 'logs_dir' => 'logs', 'snapshots_dir' => 'snapshots'] as $k => $rel) {
    $raw['paths'][$k] = "{$dir}/{$rel}";
}
$raw['backup']['dir'] = "{$dir}/backups";
$lines = [];
foreach ($raw as $section => $keys) {
    $lines[] = "[{$section}]";
    foreach ($keys as $k => $v) {
        $lines[] = $k . ' = "' . trim((string) $v, '"') . '"';
    }
    $lines[] = '';
}
file_put_contents("{$dir}/config.ini", implode("
", $lines));
file_put_contents("{$dir}/secrets.ini", 'encryption_key = "' . base64_encode(random_bytes(32)) . "\"\n");

$php = PHP_BINARY;
$run = static function (array $cmd): void {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) {
        fwrite(STDERR, "실패: " . implode(' ', array_map('basename', $cmd)) . "\n" . $out);
        exit(1);
    }
};
$run([$php, PJG_ROOT . '/tools/migrate.php', "--db={$dir}/db/demo.sqlite"]);

$db = new Db("{$dir}/db/demo.sqlite", 5000);
$t = '2026-10-01T01:00:00Z';
$hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]);
$user = static function (string $login, string $name, string $role) use ($db, $hash, $t): int {
    $db->execute("INSERT INTO users (login_id, display_name, email, role, status, auth_type, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', 'local', ?, ?, ?)",
        [$login, $name, $login . '@example.invalid', $role, $hash, $t, $t]);
    return $db->lastInsertId();
};
$admin = $user('demo-admin', 'Demo Admin', 'admin');
$alex = $user('alex', 'Alex Kim', 'user');
$user('jordan', 'Jordan Lee', 'user');
$projects = [['sample-web', 'javascript', 'Sample web front end'], ['sample-api', 'python', 'Sample API service']];
foreach ($projects as [$name, $lang, $desc]) {
    $db->execute('INSERT INTO projects (name, language, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$name, $lang, $desc, $t, $t]);
    $pid = $db->lastInsertId();
    $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at, created_by) VALUES (?, 'user', ?, ?, ?)", [$pid, $alex, $t, $admin]);
}
unset($db);
foreach ([1 => 140, 2 => 60] as $pid => $n) {
    $run([$php, PJG_ROOT . '/tools/seed_defects.php', "--db={$dir}/db/demo.sqlite", "--project={$pid}", "--defects={$n}", '--files=12', "--snapshots-dir={$dir}/snapshots"]);
}
$db = new Db("{$dir}/db/demo.sqlite", 5000);
// 담당자·상태를 조금 섞는다(결함 표·대시보드가 비어 보이지 않게, 고정 규칙)
$db->execute("UPDATE defects SET assignee_id = ? WHERE id % 5 = 0", [$alex]);
$db->execute("UPDATE defects SET status = 'in_progress' WHERE id % 7 = 0");
$db->execute("UPDATE defects SET status = 'postponed' WHERE id % 11 = 0");
foreach ([['analysis_done', '{"analysis_id":1,"run":1,"new":140,"resolved":0,"reopened":0}', 'a1'], ['defect_assigned', '{"defect_id":5}', 'd5'], ['defect_assigned', '{"defect_id":10}', 'd10']] as $i => [$kind, $params, $key]) {
    $db->execute('INSERT INTO notifications (user_id, kind, project_id, params, dedupe_key, created_at) VALUES (?, ?, 1, ?, ?, ?)', [$alex, $kind, $params, $key, '2026-10-01T0' . (2 + $i) . ':00:00Z']);
}
// P29C 관리자 그림(허용 호스트): 가짜 호스트 두 개(.example 도메인 — 접속하지 않는다)
foreach ([['git.example.com', 0, 0, 1, 'GitHub Enterprise'], ['p4.example.com', 1666, 0, 0, 'Perforce']] as [$host, $port, $http, $gh, $note]) {
    $db->execute('INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$host, $port, $http, $gh, $note, $t, $t]);
}
echo "준비: {$dir}\n";
