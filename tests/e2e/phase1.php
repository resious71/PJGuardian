<?php

declare(strict_types=1);

/**
 * 1단계 종합 E2E(P19): 실제 HTTP(PHP 내장 서버) + 실제 워커(ruff·ESLint) + 실제 DB로 업로드부터 결함 추적까지 12개 시나리오
 * (12: Run(P20R) — 같은 프로젝트에 zip 3번 → Run 1~3, Run별 건수).
 *
 *   php tests/e2e/phase1.php [--keep] [--only=1,2,…]
 *
 * 격리: data/work/e2e_<8자리>/ 아래에 설정(test.ini)·DB·업로드·작업·스냅샷·로그를 만들고, 빈 포트에 내장 서버를 띄운다.
 *   공유 config/config.ini와 개발 DB는 읽지도 쓰지도 않는다. 끝나면 서버를 멈추고 폴더를 지운다(--keep이면 남김).
 * 시나리오 사이에 상태가 이어진다(1이 사용자·프로젝트를 만들고 2가 첫 분석 …). 각 시나리오는 확인 결과를 출력하고,
 *   하나라도 실패하면 종료 코드 1. 필요: bin/ruff/0.16.10, bin/eslint/10.11.0, Node(tools.node_path 또는 PATH), curl 확장.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Support\Config;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\TestApp;

$opt = getopt('', ['keep', 'only:']);
$only = isset($opt['only']) ? array_map('intval', explode(',', (string) $opt['only'])) : null;
const PW = 'E2e-Pass-word1!';
const SAMPLES = PJG_ROOT . '/samples';

// ---------------------------------------------------------------- 환경
$root = str_replace('\\', '/', PJG_ROOT) . '/data/work/e2e_' . bin2hex(random_bytes(4));
foreach (['db', 'up', 'w', 'snap', 'logs', 'bak'] as $d) {
    Fs::ensureDir("{$root}/{$d}");
}
$port = 0;
for ($p = 8097; $p < 8200; $p++) {
    $s = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
    if ($s === false) {
        $port = $p;
        break;
    }
    fclose($s);
}
$base = "http://127.0.0.1:{$port}";
$node = is_file('C:/tools/nodejs/node.exe') ? 'C:/tools/nodejs/node.exe' : '';
$ini = TestApp::writeIni("{$root}/test.ini", [
    'app' => ['env' => 'prod', 'base_url' => $base],
    'security' => ['require_https' => 'false'],
    'paths' => ['db_file' => "{$root}/db/e2e.sqlite", 'uploads_dir' => "{$root}/up", 'work_dir' => "{$root}/w",
        'snapshots_dir' => "{$root}/snap", 'logs_dir' => "{$root}/logs", 'backups_dir' => "{$root}/bak"],
    'tools' => ['node_path' => $node],
    // 악의적 zip 시험용 한도(bomb_small 300MB, many_files 수천 개를 앞 단계에서 거부)
    'extract' => ['max_extracted_size' => '64MB', 'max_files' => '1000'],
    'auth' => ['bcrypt_cost' => '4'],
    'log' => ['level' => 'WARN'],
]);
$db = new Db("{$root}/db/e2e.sqlite", 10000);
(new Migrator($db, PJG_ROOT . '/migrations', new FixedClock('2026-10-04T00:00:00Z')))->migrate();
file_put_contents("{$root}/router.php", "<?php\n\$path = (string) parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);\n"
    . "if (\$path !== '/' && !str_contains(\$path, '..') && is_file(" . var_export(PJG_ROOT . '/public', true) . " . \$path)) { return false; }\n"
    . "require " . var_export(PJG_ROOT . '/src/bootstrap.php', true) . ";\n"
    . "\$app = PJGuardian\\App::create(PJG_ROOT, PJGuardian\\Support\\Config::load(" . var_export("{$root}/test.ini", true) . "));\n"
    . "\$r = PJGuardian\\Http\\Request::fromGlobals();\n\$app->handle(\$r)->send(\$r->method !== 'HEAD');\n");
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', PJG_ROOT . '/public', "{$root}/router.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "{$root}/server.log", 'a'], 2 => ['file', "{$root}/server.log", 'a']], $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
usleep(700_000);

// ---------------------------------------------------------------- 도구
final class Client
{
    public string $csrf = '';
    private string $jar;

    public function __construct(private readonly string $base, string $dir, public readonly string $name)
    {
        $this->jar = "{$dir}/cookies_{$name}_" . bin2hex(random_bytes(3)) . '.txt';
    }

    /** @return array{status:int, json:?array, body:string} */
    public function req(string $method, string $path, mixed $json = null, array $headers = [], ?string $raw = null, bool $csrf = true): array
    {
        $ch = curl_init($this->base . $path);
        $h = ['Accept: application/json'];
        if ($csrf && $method !== 'GET') {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($json !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        } elseif ($raw !== null) {
            $h[] = 'Content-Type: application/octet-stream';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [...$h, ...$headers],
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode($body, true);
        return ['status' => $status, 'json' => is_array($j) ? $j : null, 'body' => $body];
    }

    public function get(string $path): array
    {
        return $this->req('GET', $path);
    }

    public function data(string $method, string $path, mixed $json = null): mixed
    {
        $r = $this->req($method, $path, $json);
        if ($r['status'] >= 400) {
            throw new RuntimeException("{$this->name} {$method} {$path} → {$r['status']} {$r['body']}");
        }
        return $r['json']['data'] ?? $r['json'];
    }

    public function login(string $login, string $password): void
    {
        $this->csrf = (string) ($this->get('/api/v1/auth/csrf')['json']['data']['csrf_token'] ?? '');
        $r = $this->req('POST', '/api/v1/auth/login', ['login_id' => $login, 'password' => $password]);
        if ($r['status'] !== 200) {
            throw new RuntimeException("로그인 실패 {$login}: {$r['status']} {$r['body']}");
        }
        $this->csrf = (string) $r['json']['data']['csrf_token'];
    }
}

$results = [];
$current = 0;
function check(bool $ok, string $what, mixed $detail = null): void
{
    global $results, $current;
    $results[$current][] = [$ok, $what];
    printf("   %s %s%s\n", $ok ? 'ok ' : 'XX ', $what, $ok || $detail === null ? '' : ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)));
}

function worker(string $ini, array $env = []): string
{
    $e = $env + getenv();
    $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', "--config={$ini}", '--once'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, PJG_ROOT, $env === [] ? null : $e, ['bypass_shell' => true]);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return (string) $out;
}

function upload(Client $c, int $projectId, string $bytes, string $name): string
{
    $init = $c->data('POST', "/api/v1/projects/{$projectId}/uploads", ['file_name' => $name, 'total_size' => strlen($bytes)]);
    foreach (str_split($bytes, (int) $init['chunk_size']) as $n => $part) {
        $r = $c->req('PUT', "/api/v1/uploads/{$init['upload_id']}/chunks/{$n}", null, ['X-Chunk-Sha256: ' . hash('sha256', $part)], $part);
        if ($r['status'] !== 200) {
            throw new RuntimeException("청크 실패 {$r['status']} {$r['body']}");
        }
    }
    $c->data('POST', "/api/v1/uploads/{$init['upload_id']}/complete");
    return (string) $init['upload_id'];
}

/** 폴더 → zip 바이트(바꿀 파일·지울 파일·더할 파일) */
function zipOf(string $dir, array $replace = [], array $remove = [], array $add = []): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'e2e');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if (in_array($rel, $remove, true) || str_contains($rel, '__pycache__')) {
            continue;
        }
        $z->addFromString($rel, $replace[$rel] ?? (string) file_get_contents($f->getPathname()));
    }
    foreach ($add as $rel => $content) {
        $z->addFromString($rel, $content);
    }
    $z->close();
    $b = (string) file_get_contents($tmp);
    unlink($tmp);
    return $b;
}

function defectsHash(Db $db, int $projectId): string
{
    return hash('sha256', json_encode($db->all('SELECT id, status, version, last_seen_analysis_id FROM defects WHERE project_id = ? ORDER BY id', [$projectId])));
}

function lastAnalysis(Db $db, int $projectId): array
{
    return (array) $db->one('SELECT * FROM analyses WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
}

$S = [];
$fail = false;
$t0 = microtime(true);
$run = static function (int $n, string $title, callable $fn) use ($only, &$current, &$fail): void {
    if ($only !== null && !in_array($n, $only, true)) {
        return;
    }
    $current = $n;
    $t = microtime(true);
    echo "\n[{$n}] {$title}\n";
    try {
        $fn();
    } catch (\Throwable $e) {
        check(false, '예외', $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
    printf("   (%.1f초)\n", microtime(true) - $t);
};

try {
    // ------------------------------------------------------------ 1
    $run(1, '관리자 생성(CLI) → 로그인 → 프로젝트(Python, JS/TS) → 사용자·그룹', function () use (&$S, $ini, $base, $root, $db): void {
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/create_admin.php', "--config={$ini}", '--login=admin', '--name=관리자', '--password-stdin', '--skip-http'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        fwrite($pipes[0], PW . "\n");
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($p);
        check($code === 0, 'create_admin.php 종료 코드 0', $out);
        $admin = new Client($base, $root, 'admin');
        $admin->login('admin', PW);
        check(true, 'admin 로그인');
        foreach ([['py', 'python'], ['js', 'javascript'], ['ts', 'javascript'], ['jscfg', 'javascript'], ['bad', 'python']] as [$name, $lang]) {
            $S['p'][$name] = (int) $admin->data('POST', '/api/v1/projects', ['name' => $name, 'language' => $lang])['id'];
        }
        check(count($S['p']) === 5, '프로젝트 5개 생성(py, js, ts, jscfg, bad)', $S['p']);
        foreach (['dev1', 'dev2', 'mgr1', 'mem1', 'out1'] as $login) {
            $u = $admin->data('POST', '/api/v1/users', ['login_id' => $login, 'display_name' => $login]);
            $S['u'][$login] = (int) $u['user']['id'];
            $admin->data('PUT', "/api/v1/users/{$S['u'][$login]}/password", ['password' => PW]);
        }
        check(count($S['u']) === 5, '일반 사용자 5명(임시 비밀번호 → 관리자가 비밀번호 지정)');
        $g = $admin->data('POST', '/api/v1/groups', ['name' => 'team', 'description' => 'e2e']);
        $S['g'] = (int) $g['id'];
        foreach (['mgr1', 'mem1'] as $login) {
            $admin->data('POST', "/api/v1/groups/{$S['g']}/members", ['login_id' => $login]);
        }
        $admin->data('PUT', "/api/v1/groups/{$S['g']}/members/{$S['u']['mgr1']}/manager", ['is_manager' => true]);
        foreach ($S['p'] as $pid) {
            $admin->data('POST', "/api/v1/projects/{$pid}/access", ['subject_type' => 'user', 'login_id' => 'dev1']);
            $admin->data('POST', "/api/v1/projects/{$pid}/access", ['subject_type' => 'user', 'login_id' => 'dev2']);
        }
        $admin->data('POST', "/api/v1/projects/{$S['p']['py']}/access", ['subject_type' => 'group', 'group_id' => $S['g']]);
        check(true, '그룹 team(관리자 mgr1, 구성원 mem1) → py 매핑, dev1·dev2 → 모든 프로젝트, out1 매핑 없음');
        $S['admin'] = $admin;
        foreach (['dev1', 'dev2', 'mgr1', 'mem1', 'out1'] as $login) {
            $c = new Client($base, $root, $login);
            $c->login($login, PW);
            $S['c'][$login] = $c;
        }
        check(true, '사용자 5명 로그인');
    });

    // ------------------------------------------------------------ 2
    $run(2, 'dev1: python_sample.zip 청크 업로드 → 해제 → queued → running → succeeded, 결함 수 = EXPECTED(19)', function () use (&$S, $ini, $db): void {
        $dev = $S['c']['dev1'];
        $id = upload($dev, $S['p']['py'], (string) file_get_contents(SAMPLES . '/zips/python_sample.zip'), 'python_sample.zip');
        $st = $dev->data('GET', "/api/v1/uploads/{$id}")['status'];
        check($st === 'assemble_pending', "complete 직후 업로드 상태 assemble_pending(조립 대기)", $st);
        $out = worker($ini);
        check(str_contains($out, '종료: succeeded'), '워커: 조립 → 해제 → 분석 succeeded', $out);
        $u = $dev->data('GET', "/api/v1/uploads/{$id}");
        $a = $dev->data('GET', "/api/v1/analyses/{$u['analysis_id']}");
        check($u['status'] === 'queued' && $a['status'] === 'succeeded', '업로드 queued(분석으로 넘김), 분석 succeeded', [$u['status'], $a['status']]);
        check($a['counts']['total'] === 19 && $a['counts']['new'] === 19 && $a['first_analysis'] === true, '결함 19건, 신규 19, 비교 대상 없음(최초)', $a['counts']);
        check($a['tool'] === 'ruff' && str_starts_with((string) $a['tool_version'], '0.16'), "도구 {$a['tool']} {$a['tool_version']}");
        $S['a1'] = (int) $a['id'];
    });

    // ------------------------------------------------------------ 3
    $run(3, '결함 목록·필터·검색·상세·코드', function () use (&$S): void {
        $dev = $S['c']['dev1'];
        $py = $S['p']['py'];
        $all = $dev->get("/api/v1/projects/{$py}/defects?size=200")['json'];
        check($all['page']['total'] === 19, '목록 19건', $all['page']);
        $f401 = $dev->get("/api/v1/projects/{$py}/defects?rule=ruff:F401")['json']['page']['total'];
        $q = $dev->get('/api/v1/projects/' . $py . '/defects?q=' . rawurlencode('imported but unused'))['json']['page']['total'];
        check($f401 > 0 && $f401 === $q, "규칙 필터 ruff:F401 {$f401}건 = 검색 'imported but unused' {$q}건");
        $folder = $dev->get("/api/v1/projects/{$py}/defects?file=app/")['json']['page']['total'];
        check($folder > 0 && $folder < 19, "폴더 접두사 app/ {$folder}건");
        $d = $all['data'][0];
        $detail = $dev->data('GET', "/api/v1/defects/{$d['id']}");
        check($detail['first_seen']['analysis_id'] === $S['a1'] && $detail['version'] === 1, '상세: 처음 감지 분석·version');
        $code = $dev->data('GET', "/api/v1/defects/{$d['id']}/code");
        $line = $code['lines'][$d['line'] - $code['start_line']] ?? null;
        check($code['source'] === 'snapshot' && is_string($line), "코드(스냅샷): {$d['file_path']}:{$d['line']} = " . json_encode($line, JSON_UNESCAPED_UNICODE));
        $html = $dev->req('GET', "/defects/{$d['id']}")['body'];
        check(str_contains($html, 'data-edit-form') && str_contains($html, 'js/pages/defect_detail.js'), '상세 화면 HTML');
        $S['defects'] = $all['data'];
    });

    // ------------------------------------------------------------ 4
    $run(4, '담당자·심각도·상태 변경, 이력, 일괄 변경, 동시 수정 충돌', function () use (&$S): void {
        $dev = $S['c']['dev1'];
        $dev2 = $S['c']['dev2'];
        $d = $S['defects'][0];
        $r = $dev->data('PATCH', "/api/v1/defects/{$d['id']}", ['version' => 1, 'assignee_id' => $S['u']['dev2'], 'severity' => 'high', 'status' => 'in_progress', 'memo' => 'E2E']);
        check($r['version'] === 2 && $r['assignee']['login_id'] === 'dev2', '담당자 dev2·심각도 high·상태 in_progress, version 2');
        $h = $dev->data('GET', "/api/v1/defects/{$d['id']}/history");
        check(count($h) === 3 && $h[0]['memo'] === 'E2E', '이력 3행(필드별, 사유)', array_column($h, 'field'));
        // 동시 수정: dev1과 dev2가 version 2로 저장
        $ok = $dev->req('PATCH', "/api/v1/defects/{$d['id']}", ['version' => 2, 'status' => 'postponed']);
        $conflict = $dev2->req('PATCH', "/api/v1/defects/{$d['id']}", ['version' => 2, 'severity' => 'low']);
        check($ok['status'] === 200 && $conflict['status'] === 409 && $conflict['json']['error']['code'] === 'version_conflict'
            && $conflict['json']['error']['details']['current']['status'] === 'postponed', '동시 수정: 한쪽 200, 다른 쪽 409 + 최신 값', [$ok['status'], $conflict['status']]);
        $items = array_map(static fn (array $x): array => ['id' => $x['id'], 'version' => 1], array_slice($S['defects'], 1, 4));
        $items[1]['version'] = 99; // 일부러 낡은 version → 충돌
        // P21S: 신규 결함은 규칙 기본 심각도를 가지므로(샘플 python 규칙에 critical은 없음) 모두와 다른 critical로 바꾼다
        $b = $dev->data('POST', "/api/v1/projects/{$S['p']['py']}/defects/bulk", ['items' => $items, 'changes' => ['severity' => 'critical'], 'memo' => '일괄']);
        check(count($b['updated']) === 3 && count($b['conflicts']) === 1, '일괄 변경 4건: 3건 변경, 1건 충돌(부분 성공)', [count($b['updated']), count($b['conflicts'])]);
        $src = array_map(static fn (array $u): string => (string) $dev->data('GET', "/api/v1/defects/{$u['id']}")['severity_source'], $b['updated']);
        check($src === ['manual', 'manual', 'manual'], '일괄 변경한 심각도의 출처 = 수동 변경(P21S)', $src);
        // 다음 시나리오(재오픈)용: config_loader.py 결함 하나를 수동 resolved
        foreach ($S['defects'] as $x) {
            if ($x['file_path'] === 'config_loader.py') {
                $cur = $dev->data('GET', "/api/v1/defects/{$x['id']}")['version'];
                $dev->data('PATCH', "/api/v1/defects/{$x['id']}", ['version' => $cur, 'status' => 'resolved', 'memo' => '고쳤다고 생각함']);
                $S['manualResolved'] = $x['id'];
                break;
            }
        }
        check(isset($S['manualResolved']), '수동 resolved 1건(코드는 그대로 → 다음 분석에서 재오픈)');
    });

    // ------------------------------------------------------------ 5
    $run(5, '수정한 zip 재업로드 → 신규·해결 수, 자동 resolved, 재오픈, 이력', function () use (&$S, $ini, $db): void {
        $dev = $S['c']['dev1'];
        $helpers = (string) file_get_contents(SAMPLES . '/python_sample/utils/helpers.py');
        $changed = str_replace('return value == None', 'return value == None  # changed', $helpers);
        $bytes = zipOf(SAMPLES . '/python_sample', ['utils/helpers.py' => $changed], ['main.py'], ['extra/new_mod.py' => "import json\n"]);
        $id = upload($dev, $S['p']['py'], $bytes, 'python_sample_v2.zip');
        worker($ini);
        $a = $dev->data('GET', '/api/v1/analyses/' . $dev->data('GET', "/api/v1/uploads/{$id}")['analysis_id']);
        check($a['status'] === 'succeeded' && $a['baseline']['id'] === $S['a1'], '성공, 비교 대상 = 첫 분석', [$a['status'], $a['baseline']]);
        check($a['counts']['new'] === 2 && $a['counts']['resolved'] === 4, "신규 2(helpers 줄 변경 1 + 새 파일 1), 해결 4(main.py 3 + helpers 1)", $a['counts']);
        check($a['counts']['reopened'] === 1, '재오픈 1(수동 resolved였던 결함이 계속 감지)', $a['counts']);
        $re = $db->one('SELECT status, version FROM defects WHERE id = ?', [$S['manualResolved']]);
        check($re['status'] === 'open', '수동 resolved → open 재오픈', $re);
        $hist = $db->all("SELECT reason_code, COUNT(*) AS n FROM defect_history WHERE analysis_id = ? GROUP BY reason_code ORDER BY reason_code", [$a['id']]);
        check(array_column($hist, 'n', 'reason_code') == ['file_deleted' => 3, 'not_detected' => 1, 'redetected' => 1], '자동 이력: file_deleted 3, not_detected 1, redetected 1', $hist);
        $new = $dev->get("/api/v1/projects/{$S['p']['py']}/defects?analysis={$a['id']}&change=new")['json']['page']['total'];
        $res = $dev->get("/api/v1/projects/{$S['p']['py']}/defects?analysis={$a['id']}&change=resolved")['json']['page']['total'];
        check($new === 2 && $res === 4, "결과 요약 링크(분석 필터): 신규 {$new}건, 해결 {$res}건");
        $S['a2'] = (int) $a['id'];
    });

    // ------------------------------------------------------------ 6
    $run(6, '대상 파일 없는 zip → 경고 → 확인 → 기존 결함 전체 resolved', function () use (&$S, $ini, $db): void {
        $dev = $S['c']['dev1'];
        $id = upload($dev, $S['p']['py'], (string) file_get_contents(SAMPLES . '/zips/empty_no_target.zip'), 'empty_no_target.zip');
        worker($ini);
        $u = $dev->data('GET', "/api/v1/uploads/{$id}");
        check($u['status'] === 'needs_confirmation' && in_array('no_target', $u['warnings'], true), '확인 대기 + 경고 no_target', [$u['status'], $u['warnings']]);
        check(str_contains(implode(' ', array_column($u['warning_messages'], 'message')), 'resolved') && $u['warning_messages'][array_search('no_target', array_column($u['warning_messages'], 'code'), true)]['danger'] === true, '경고 문구에 "모두 해결(resolved)로 바뀔 수 있음", 위험 표시', $u['warning_messages']);
        $dev->data('POST', "/api/v1/uploads/{$id}/confirm");
        worker($ini);
        $open = (int) $db->value("SELECT COUNT(*) FROM defects WHERE project_id = ? AND status <> 'resolved'", [$S['p']['py']]);
        $a = $dev->data('GET', '/api/v1/analyses/' . $dev->data('GET', "/api/v1/uploads/{$id}")['analysis_id']);
        check($a['status'] === 'succeeded' && $open === 0, "분석 성공, 해결되지 않은 결함 0건(해결 {$a['counts']['resolved']}건)", [$a['status'], $open]);
        check(in_array('no_target', $a['upload']['warnings'], true) && $a['upload']['confirmed_at'] !== null, '분석 상세에 "대상 없음 경고 확인" 기록(안내 배너 입력)');
    });

    // ------------------------------------------------------------ 7
    $run(7, '악의적·비정상 zip 각 종류 → 코드별 오류 메시지, 작업 디렉토리 밖 파일 없음', function () use (&$S, $ini, $root): void {
        $dev = $S['c']['dev1'];
        $expect = [
            'zip_slip' => ['forbidden_entry'], 'absolute_path' => ['forbidden_entry'], 'backslash_path' => ['forbidden_entry'], 'symlink' => ['forbidden_entry'],
            'nested' => ['forbidden_entry'], 'reserved_names' => ['unsupported_name'], 'long_path' => ['unsupported_name'], 'corrupt' => ['invalid_zip'],
            'not_a_zip' => ['invalid_zip'], 'case_conflict' => ['name_conflict'], 'bomb_small' => ['extract_limit'], 'many_files' => ['extract_limit'],
            'lying_size' => ['invalid_zip', 'extract_limit'],
            // 정상으로 풀려야 하는 것(이름 인코딩·기본 제외)
            'cp949_names' => ['ok'], 'utf8_names' => ['ok'], 'utf8_noflag' => ['ok'], 'excluded_dirs' => ['ok'],
        ];
        $ids = [];
        foreach ($expect as $name => $_) {
            $ids[$name] = upload($dev, $S['p']['bad'], (string) file_get_contents(SAMPLES . "/zips_bad/{$name}.zip"), "{$name}.zip");
            worker($ini); // 사용자당 동시 업로드 2개 제한 때문에 하나씩 처리
        }
        foreach ($expect as $name => $codes) {
            $u = $dev->data('GET', "/api/v1/uploads/{$ids[$name]}");
            if ($codes === ['ok']) {
                check($u['status'] !== 'failed', "{$name}.zip → {$u['status']}(정상 해제)", $u['error_code']);
                continue;
            }
            $ok = $u['status'] === 'failed' && in_array($u['error_code'], $codes, true) && ($u['error']['known'] ?? false)
                && !str_contains((string) $u['error_message'], $root) && !str_contains((string) $u['error_message'], 'Stack');
            check($ok, sprintf('%s.zip → %s: %s / 조치: %s', $name, $u['error_code'], $u['error_message'], $u['error']['action'] ?? '-'), $u);
        }
        $evil = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($root), FilesystemIterator::SKIP_DOTS)) as $f) {
            if (preg_match('/evil|passwd|hosts/i', $f->getFilename()) === 1) {
                $evil[] = $f->getPathname();
            }
        }
        check($evil === [], 'data/work 아래 어디에도 evil·passwd·hosts 파일이 생기지 않음(작업 디렉토리 밖 쓰기 없음)', $evil);
    });

    // ------------------------------------------------------------ 8
    $run(8, '분석 취소(queued, running) → 직전 성공 분석·결함 불변', function () use (&$S, $ini, $db): void {
        $dev = $S['c']['dev1'];
        $py = $S['p']['py'];
        $before = defectsHash($db, $py);
        $lastOk = (int) $db->value("SELECT MAX(id) FROM analyses WHERE project_id = ? AND status = 'succeeded'", [$py]);
        // queued: 해제까지만(process_upload.php) → 분석 queued → 취소
        upload($dev, $py, (string) file_get_contents(SAMPLES . '/zips/python_sample.zip'), 'q.zip');
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/process_upload.php', '--all', "--config={$ini}"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        stream_get_contents($pipes[1]);
        proc_close($p);
        $q = lastAnalysis($db, $py);
        $r = $dev->data('POST', "/api/v1/analyses/{$q['id']}/cancel");
        check($q['status'] === 'queued' && $r['status'] === 'cancelled', 'queued 분석 즉시 cancelled', [$q['status'], $r['status']]);
        // running: 가짜 분석기(hang)로 오래 실행되게 하고 실행 중에 취소
        $rid = upload($dev, $py, (string) file_get_contents(SAMPLES . '/zips/python_sample.zip'), 'r.zip');
        // P21C: 바로 앞(q.zip)과 같은 파일이라 해제 뒤 "직전 업로드와 같은 파일" 확인을 받는다 → 계속 진행해 분석 대기열로
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tools/process_upload.php', '--all', "--config={$ini}"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        stream_get_contents($pipes[1]);
        proc_close($p);
        $u = $dev->data('GET', "/api/v1/uploads/{$rid}");
        check($u['status'] === 'needs_confirmation' && in_array('same_as_previous', $u['warnings'], true) && ($u['summary']['previous']['same'] ?? null) === true,
            '같은 파일을 다시 올림 → 확인 대기(same_as_previous)', [$u['status'], $u['warnings']]);
        $dev->data('POST', "/api/v1/uploads/{$rid}/confirm");
        $env = ['PJG_FAKE_ANALYSIS' => json_encode(['mode' => 'hang'])] + getenv();
        $w = proc_open([PHP_BINARY, PJG_ROOT . '/tools/worker.php', "--config={$ini}", '--once'], [1 => ['file', "{$ini}.worker.log", 'w'], 2 => ['file', "{$ini}.worker.log", 'a']], $pipes, PJG_ROOT, $env, ['bypass_shell' => true]);
        $running = null;
        for ($i = 0; $i < 200 && $running === null; $i++) {
            usleep(100_000);
            $x = lastAnalysis($db, $py);
            if (($x['status'] ?? '') === 'running') {
                $running = $x;
            }
        }
        check($running !== null, '분석이 running이 됨');
        $c = $dev->data('POST', "/api/v1/analyses/{$running['id']}/cancel");
        check($c['status'] === 'running' && $c['cancel_requested'] === true, '취소 요청(실행 중이라 바로는 running + cancel_requested)');
        $end = null;
        for ($i = 0; $i < 300; $i++) {
            usleep(100_000);
            $end = $db->value('SELECT status FROM analyses WHERE id = ?', [$running['id']]);
            if ($end !== 'running') {
                break;
            }
        }
        $wst = proc_get_status($w);
        for ($i = 0; $i < 100 && $wst['running']; $i++) {
            usleep(100_000);
            $wst = proc_get_status($w);
        }
        proc_close($w);
        check($end === 'cancelled', "워커가 프로세스 트리를 멈추고 cancelled로 기록({$end})");
        $after = (int) $db->value("SELECT MAX(id) FROM analyses WHERE project_id = ? AND status = 'succeeded'", [$py]);
        check($after === $lastOk && defectsHash($db, $py) === $before, '직전 성공 분석과 결함(상태·version) 그대로');
        $api = $dev->data('GET', "/api/v1/analyses/{$running['id']}");
        check($api['status'] === 'cancelled' && $api['can_cancel'] === false, '분석 상세: cancelled, 취소 버튼 없음');
    });

    // ------------------------------------------------------------ 9
    $run(9, '도구 실패(잘못된 ruff 설정) → failed, 실패 코드 메시지, 결함 불변', function () use (&$S, $ini, $db): void {
        $dev = $S['c']['dev1'];
        $py = $S['p']['py'];
        $before = defectsHash($db, $py);
        $bytes = zipOf(SAMPLES . '/python_sample', [], [], ['ruff.toml' => "[lint]\nselect = [\"NOPE999\"]\n"]);
        $id = upload($dev, $py, $bytes, 'bad_config.zip');
        worker($ini);
        $a = $dev->data('GET', '/api/v1/analyses/' . $dev->data('GET', "/api/v1/uploads/{$id}")['analysis_id']);
        check($a['status'] === 'failed' && $a['failure']['code'] === 'tool_config_error', "failed {$a['failure']['code']}: {$a['failure']['title']} / 원인: {$a['failure']['cause']}", $a['failure']);
        check($a['failure']['guide_key'] === 'analysis-failures' && $a['counts'] === null, '가이드 키(P29: 사용자 가이드 절 key), 결과 없음');
        check(defectsHash($db, $py) === $before, '결함 그대로(실패한 분석은 아무것도 바꾸지 않음)');
    });

    // ------------------------------------------------------------ 10
    $run(10, 'JS/TS 프로젝트(js_sample, ts_sample, js_with_js_config) 같은 흐름, "설정 무시됨"', function () use (&$S, $ini): void {
        $dev = $S['c']['dev1'];
        foreach ([['js', 'js_sample', 5], ['ts', 'ts_sample', 6], ['jscfg', 'js_with_js_config', 4]] as [$proj, $sample, $count]) {
            $id = upload($dev, $S['p'][$proj], (string) file_get_contents(SAMPLES . "/zips/{$sample}.zip"), "{$sample}.zip");
            worker($ini);
            $u = $dev->data('GET', "/api/v1/uploads/{$id}");
            if ($u['status'] === 'needs_confirmation') { // 최상위 폴더 경고가 있으면 확인
                $dev->data('POST', "/api/v1/uploads/{$id}/confirm");
                worker($ini);
                $u = $dev->data('GET', "/api/v1/uploads/{$id}");
            }
            $a = $dev->data('GET', "/api/v1/analyses/{$u['analysis_id']}");
            $reasons = array_values(array_unique(array_column($a['ignored_configs'], 'reason')));
            check($a['status'] === 'succeeded' && $a['counts']['total'] === $count, "{$sample}: ESLint {$a['tool_version']}, 결함 {$a['counts']['total']}건(기대 {$count}), 설정 출처 {$a['config_source']}, 무시 " . json_encode($reasons), $a['counts']);
            if ($sample !== 'ts_sample') {
                check(in_array('eslint_config_ignored', $reasons, true), "{$sample}: 업로드 ESLint 설정 무시 기록(화면 \"설정 무시됨\")");
            }
        }
    });

    // ------------------------------------------------------------ 11
    $run(11, '권한·접근 통제: 관리자 기능 403, 다른 사용자의 업로드 404, CSRF 거부, 역할별 경로와 변경 즉시 반영', function () use (&$S, $db): void {
        $dev = $S['c']['dev1'];
        $admin = $S['admin'];
        $py = $S['p']['py'];
        check($dev->get('/api/v1/users')['status'] === 403, '일반 사용자의 관리자 API(사용자 목록) 403');
        check($dev->req('GET', '/admin/users')['status'] === 403, '일반 사용자의 관리자 화면 403');
        $adminUpload = $admin->data('POST', "/api/v1/projects/{$py}/uploads", ['file_name' => 'x.zip', 'total_size' => 10])['upload_id'];
        $r = $dev->get("/api/v1/uploads/{$adminUpload}");
        check($r['status'] === 404 && $r['json']['error']['code'] === 'upload_not_found', '다른 사용자(admin)의 업로드 404');
        $admin->data('DELETE', "/api/v1/uploads/{$adminUpload}");
        $noCsrf = $dev->req('PATCH', "/api/v1/defects/{$S['defects'][0]['id']}", ['version' => 1, 'status' => 'open'], [], null, false);
        check($noCsrf['status'] === 403 && $noCsrf['json']['error']['code'] === 'csrf_invalid', 'CSRF 토큰 없는 PATCH 403 csrf_invalid');
        // 역할별: 업로드 시작 → 분석 목록 → 결함 목록 → 결함 상세 → 화면
        $defect = $S['defects'][1]['id'];
        $analysis = $S['a2'];
        $paths = static fn () => [
            ['GET', "/api/v1/projects/{$py}"], ['GET', "/api/v1/projects/{$py}/analyses"], ['GET', "/api/v1/analyses/{$analysis}"],
            ['GET', "/api/v1/projects/{$py}/defects"], ['GET', "/api/v1/defects/{$defect}"], ['GET', "/api/v1/defects/{$defect}/code"],
            ['GET', "/projects/{$py}"], ['GET', "/analyses/{$analysis}"], ['GET', "/defects/{$defect}"],
        ];
        $statuses = static function (Client $c) use ($paths, $py): array {
            $out = [];
            foreach ($paths() as [$m, $p]) {
                $out[] = $c->req($m, $p)['status'];
            }
            $init = $c->req('POST', "/api/v1/projects/{$py}/uploads", ['file_name' => 'x.zip', 'total_size' => 10]);
            if ($init['status'] === 201) {
                $c->req('DELETE', '/api/v1/uploads/' . $init['json']['data']['upload_id']);
            }
            $out[] = $init['status'];
            return $out;
        };
        $all200 = array_merge(array_fill(0, 9, 200), [201]);
        $all404 = array_fill(0, 10, 404);
        foreach (['admin' => $admin, 'mgr1' => $S['c']['mgr1'], 'mem1' => $S['c']['mem1'], 'dev2' => $S['c']['dev2']] as $who => $c) {
            $st = $statuses($c);
            check($st === $all200, "{$who}: 프로젝트·분석·결함·코드·화면 200, 업로드 시작 201", $st);
        }
        $st = $statuses($S['c']['out1']);
        check($st === $all404, 'out1(매핑 없음): 모든 경로 404(없는 것과 같은 응답)', $st);
        // 구성원 변경이 다음 요청부터 반영
        $admin->data('DELETE', "/api/v1/groups/{$S['g']}/members/{$S['u']['mem1']}");
        $st = $statuses($S['c']['mem1']);
        check($st === $all404, 'mem1을 그룹에서 빼면 다음 요청부터 404', $st);
        // 매핑 변경이 다음 요청부터 반영
        $admin->data('DELETE', "/api/v1/projects/{$py}/access/group/{$S['g']}");
        $st = $statuses($S['c']['mgr1']);
        check($st === $all404, '그룹 매핑을 지우면 그룹 관리자 mgr1도 다음 요청부터 404', $st);
        $admin->data('POST', "/api/v1/projects/{$py}/access", ['subject_type' => 'user', 'login_id' => 'out1']);
        check($statuses($S['c']['out1']) === $all200, 'out1을 직접 매핑하면 다음 요청부터 200');
    });

    // ------------------------------------------------------------ 12
    $run(12, 'Run(P20R)·히스토리(P21): 같은 프로젝트에 zip 3번 → Run 1~3, Run별 건수, 최신 Run = 결함 목록, 비교 패널·링크·버전 변경 배지', function () use (&$S, $ini, $db): void {
        $admin = $S['admin'];
        $pid = (int) $admin->data('POST', '/api/v1/projects', ['name' => 'runs', 'language' => 'python'])['id'];
        $admin->data('POST', "/api/v1/projects/{$pid}/access", ['subject_type' => 'user', 'login_id' => 'dev1']);
        $admin->data('POST', "/api/v1/projects/{$pid}/access", ['subject_type' => 'group', 'group_id' => $S['g']]);
        $dev = $S['c']['dev1'];
        $orig = (string) file_get_contents(SAMPLES . '/zips/python_sample.zip');
        $helpers = (string) file_get_contents(SAMPLES . '/python_sample/utils/helpers.py');
        $v2 = zipOf(SAMPLES . '/python_sample', ['utils/helpers.py' => str_replace('return value == None', 'return value == None  # changed', $helpers)],
            ['main.py'], ['extra/new_mod.py' => "import json\n"]);
        $runs = [];
        foreach ([['v1.zip', $orig], ['v2.zip', $v2], ['v3.zip', $orig]] as [$name, $bytes]) {
            $id = upload($dev, $pid, $bytes, $name);
            worker($ini);
            $u = $dev->data('GET', "/api/v1/uploads/{$id}");
            $a = $dev->data('GET', "/api/v1/analyses/{$u['analysis_id']}");
            $runs[] = [$a['status'], $a['run'], $u['run']];
        }
        check($runs === [['succeeded', 1, 1], ['succeeded', 2, 2], ['succeeded', 3, 3]], 'Run 1~3(분석·업로드 상태에 run)', $runs);
        $list = $dev->data('GET', "/api/v1/projects/{$pid}/runs");
        check(array_column($list, 'run') === [3, 2, 1] && array_column($list, 'total') === [19, 17, 19], 'Run 목록(최신순) 존재 결함 19 → 17(해결 4·신규 2) → 19', $list);
        $total = static fn (array $q) => $dev->get("/api/v1/projects/{$pid}/defects?" . http_build_query($q))['json']['page']['total'] ?? null;
        check([$total(['run' => 1]), $total(['run' => 2])] === [19, 17], 'Run 1·2 목록 건수 19, 17');
        $open = $total(['status' => 'open,in_progress,postponed,intentional,not_fix']);
        check($total(['run' => 3]) === $total([]) && $open === 19, "최신 Run(?run=3) = 결함 목록(run 생략), 해결 안 된 결함 {$open} = Run 3 존재 19");
        $r = $dev->get("/api/v1/projects/{$pid}/defects?run=9");
        check($r['status'] === 404 && $r['json']['error']['code'] === 'run_not_found', '없는 Run 404 run_not_found');
        check($S['c']['out1']->get("/api/v1/projects/{$pid}/defects?run=1")['status'] === 404, '보이지 않는 프로젝트의 Run 404');
        // P21 히스토리: 비교 패널 건수 = 분석 상세 = 링크 목록, 첫 Run "비교 대상 없음", 버전 변경 배지(도구 버전 기록을 바꾼 시료)
        $hist = $dev->data('GET', "/api/v1/projects/{$pid}/history");
        check(array_column($hist, 'run') === [3, 2, 1], '히스토리 이력(최신순) Run 3·2·1', array_column($hist, 'run'));
        $a2 = (int) $hist[1]['analysis_id'];
        $cmp = $dev->data('GET', "/api/v1/analyses/{$a2}/comparison");
        $detail = $dev->data('GET', "/api/v1/analyses/{$a2}");
        check([$cmp['counts']['new'], $cmp['counts']['resolved']] === [2, 4] && [$detail['counts']['new'], $detail['counts']['resolved']] === [2, 4],
            'Run 2 비교: 신규 2·해결 4 = 분석 상세', $cmp['counts']);
        $ok = true;
        foreach (['new' => 2, 'resolved' => 4, 'reopened' => 0] as $change => $n) {
            $got = $dev->get("/api/v1/projects/{$pid}/defects?" . http_build_query(array_map('strval', $cmp['filters'][$change])))['json']['page']['total'];
            $ok = $ok && $got === $n;
        }
        check($ok, '카드 링크(analysis+change) 목록 건수 = 비교 건수');
        check($dev->data('GET', '/api/v1/analyses/' . $hist[2]['analysis_id'] . '/comparison')['baseline'] === null, 'Run 1: 비교 대상 없음');
        $db->execute("UPDATE analyses SET tool_version = '0.99.0' WHERE id = ?", [(int) $hist[0]['analysis_id']]);
        $cmp3 = $dev->data('GET', '/api/v1/analyses/' . $hist[0]['analysis_id'] . '/comparison');
        $row3 = $dev->data('GET', "/api/v1/projects/{$pid}/history")[0];
        check($cmp3['changed'] && isset($cmp3['changes']['tool_version']) && in_array('history.warn_tool_change', $cmp3['warnings'], true) && $row3['changed'],
            '도구 버전을 바꾼 Run 3: 변경 배지·근거·주의 문구', $cmp3['changes']);
        check(str_contains($dev->get("/projects/{$pid}/history")['body'] ?? '', 'data-history-page'), '히스토리 화면');
        $S['runsProject'] = $pid;
    });
} finally {
    proc_terminate($server);
    proc_close($server);
    $sum = ['pass' => 0, 'fail' => 0];
    echo "\n== 요약 ==\n";
    foreach ($results as $n => $checks) {
        $bad = count(array_filter($checks, static fn (array $c): bool => !$c[0]));
        $sum[$bad === 0 ? 'pass' : 'fail']++;
        printf("  시나리오 %2d: %s (확인 %d개, 실패 %d개)\n", $n, $bad === 0 ? '통과' : '실패', count($checks), $bad);
    }
    printf("시나리오 통과 %d, 실패 %d, %.0f초. 격리 폴더 %s %s\n", $sum['pass'], $sum['fail'], microtime(true) - $t0, $root, isset($opt['keep']) ? '(남김)' : '(삭제)');
    if (!isset($opt['keep'])) {
        unset($db);
        gc_collect_cycles();
        usleep(300_000);
        Fs::removeTree($root);
    }
    exit($sum['fail'] === 0 && $results !== [] ? 0 : 1);
}
