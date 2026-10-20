<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Process\ProcessTree;
use PJGuardian\Services\Repo\HostPolicy;
use PJGuardian\Services\Repo\HostProbe;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\SystemClock;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 허용 호스트 연결 테스트(P26B 설계 12) — 로컬 시험 서버(tests/Support/probe_server.php)와 시험 안에서 만든 인증서(openssl 확장)로.
 * 외부 네트워크 없음. 이름 풀이는 주입(git.test·other.test → 127.0.0.1, 개발 loopback 예외 켬).
 * 결과 코드: ok(+ 401·403 인증 필요, 407 뺀 4xx = 루트에 페이지 없음(P27C), 리다이렉트 따라가지 않음, HEAD 405 → GET), fail_connect(거부·TLS 시간 초과), fail_tls_untrusted, fail_tls_expired,
 * fail_tls_name_mismatch, fail_http_status, warn_cert_expiring, fail_dns, fail_blocked_address(접속 시도 0), Perforce SSL 지문·평문 포트.
 * API: 401·403·CSRF·404·분당 제한 공유(전체 = 1회)·결과 저장·전체 상한·감사. 자격 증명·쿠키를 보내지 않고 본문을 읽지 않는다.
 */
final class HostProbeTest extends TestCase
{
    private string $root;
    /** @var list<resource> */
    private array $servers = [];
    private string $caPem = '';
    private string $srvPem = '';
    private int $srvNotAfter = 0;

    public function setUp(): void
    {
        $this->root = $this->tempDir('hostprobe');
        $this->makeCerts();
    }

    public function tearDown(): void
    {
        foreach ($this->servers as $p) {
            $st = proc_get_status($p);
            if ($st['running']) {
                ProcessTree::kill((int) $st['pid']);
            }
            proc_close($p);
        }
    }

    /** CA + 서버 인증서(git.test, 365일) — PHP openssl 확장만 */
    private function makeCerts(): void
    {
        $cnf = $this->root . '/o.cnf';
        file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ v3_ca ]\nbasicConstraints = critical,CA:true\nkeyUsage = keyCertSign, cRLSign\n"
            . "[ v3_srv ]\nbasicConstraints = CA:false\nsubjectAltName = DNS:git.test\nextendedKeyUsage = serverAuth\n");
        $opt = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $caKey = openssl_pkey_new($opt);
        $caCsr = openssl_csr_new(['commonName' => 'PJG Test CA'], $caKey, $opt);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 3650, $opt + ['x509_extensions' => 'v3_ca'], 1);
        openssl_x509_export($ca, $caOut);
        $this->caPem = $this->root . '/ca.pem';
        file_put_contents($this->caPem, $caOut);
        $key = openssl_pkey_new($opt);
        $csr = openssl_csr_new(['commonName' => 'git.test'], $key, $opt);
        $crt = openssl_csr_sign($csr, $ca, $caKey, 365, $opt + ['x509_extensions' => 'v3_srv'], 2);
        openssl_x509_export($crt, $crtOut);
        openssl_pkey_export($key, $keyOut, null, $opt);
        $this->srvPem = $this->root . '/srv.pem';
        file_put_contents($this->srvPem, $crtOut . $keyOut);
        $this->srvNotAfter = (int) openssl_x509_parse($crtOut)['validTo_time_t'];
    }

    /** @param array<string, mixed> $cfg @return array{port:int, log:string} */
    private function serve(array $cfg): array
    {
        $n = count($this->servers);
        $file = "{$this->root}/s{$n}.json";
        $cfg += ['pem' => $this->srvPem, 'port_file' => "{$this->root}/s{$n}.port", 'log' => "{$this->root}/s{$n}.log"];
        file_put_contents($file, json_encode($cfg));
        $p = proc_open([PHP_BINARY, PJG_ROOT . '/tests/Support/probe_server.php', $file], [0 => ['pipe', 'r'], 1 => ['file', "{$this->root}/s{$n}.out", 'a'], 2 => ['file', "{$this->root}/s{$n}.out", 'a']],
            $pipes, PJG_ROOT, null, ['bypass_shell' => true]);
        $this->servers[] = $p;
        for ($i = 0; $i < 100 && !is_file($cfg['port_file']); $i++) {
            usleep(50_000);
        }
        return ['port' => (int) file_get_contents($cfg['port_file']), 'log' => $cfg['log']];
    }

    /** @return list<array<string, mixed>> */
    private static function requests(string $log): array
    {
        // 인증서 확인·신뢰 검증 연결(TLS만 열고 요청 없음)은 서버 기록에서 빈 요청 줄로 남는다 — 실제 HTTP 요청만
        return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), is_file($log) ? (file($log) ?: []) : []),
            static fn (mixed $r): bool => is_array($r) && ($r['request'] ?? '') !== ''));
    }

    private function probe(?string $ca = null, ?int $at = null, ?\Closure $resolve = null, float $tls = 5.0, float $tcp = 5.0): HostProbe
    {
        $app = TestApp::make($this->root);
        $policy = new HostPolicy($app->db(), $app->logger, true, $resolve ?? static fn (string $h): array => in_array($h, ['git.test', 'other.test'], true) ? ['127.0.0.1'] : []);
        return new HostProbe($policy, new FixedClock(SystemClock::iso(new \DateTimeImmutable('@' . ($at ?? time())))), $app->logger, $ca, 30 * 86400, $tcp, $tls, 3.0);
    }

    private static function row(string $host, int $port, bool $github = true, bool $http = false): array
    {
        return ['host' => $host, 'port' => $port, 'allow_http' => $http, 'github_links' => $github];
    }

    public function testTlsHttpOutcomes(): void
    {
        $ok = $this->serve(['mode' => 'https', 'status' => 200, 'body' => 5_000_000]);
        // 정상: 사내 CA를 ca_cert_file로 → 신뢰, 이름 일치, HTTP 200(본문 5MB는 읽지 않음)
        $t = microtime(true);
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $ok['port']));
        $this->assertSame('ok', $r['code'], json_encode($r));
        $this->assertTrue(microtime(true) - $t < 3, '본문을 읽지 않아 빠름');
        $tg = $r['targets'][0];
        $this->assertSame([true, true, true, 200, 'HEAD'], [$tg['tcp']['ok'], $tg['tls']['trusted'], $tg['tls']['cert']['name_match'], $tg['http']['status'], $tg['http']['method']]);
        $this->assertSame(SystemClock::iso(new \DateTimeImmutable('@' . $this->srvNotAfter)), $r['cert']['not_after']);
        $this->assertSame(['PJG Test CA', ['git.test'], '127.0.0.1'], [$r['cert']['issuer'], $r['cert']['names'], $r['dns']['pinned']]);
        $req = self::requests($ok['log']);
        $this->assertSame([['request' => 'HEAD / HTTP/1.1', 'auth' => false, 'cookie' => false]], $req, 'HEAD 한 번, 자격 증명·쿠키 없음');
        // 사내 CA 미등록 → 신뢰 안 됨
        $this->assertSame('fail_tls_untrusted', $this->probe(null)->probe(self::row('git.test', $ok['port']))['code']);
        // 이름 불일치(other.test로 등록 — 같은 서버, 인증서는 git.test)
        $this->assertSame('fail_tls_name_mismatch', $this->probe($this->caPem)->probe(self::row('other.test', $ok['port']))['code']);
        // 만료(시계를 만료 뒤로) / 만료 임박(15일 남음)
        $this->assertSame('fail_tls_expired', $this->probe($this->caPem, $this->srvNotAfter + 86400)->probe(self::row('git.test', $ok['port']))['code']);
        $soon = $this->probe($this->caPem, $this->srvNotAfter - 15 * 86400)->probe(self::row('git.test', $ok['port']));
        $this->assertSame(['warn_cert_expiring', 15], [$soon['code'], $soon['cert']['days_left']]);
        // HTTP 상태(P27C 사용자 결정으로 바뀜 — P26B는 404 = 오류였다): 상태 줄이 오면 도달 성공. 401·403 = 인증 필요(정상),
        // 그 밖의 4xx = 루트에 페이지 없음(정상 + 안내), 407 = 프록시 인증·5xx = 오류. 302는 따라가지 않음, HEAD 405 → GET
        $cases = [401 => ['ok', 'auth'], 403 => ['ok', 'auth'], 404 => ['ok', 'no_page'], 410 => ['ok', 'no_page'], 429 => ['ok', 'no_page'],
            451 => ['ok', 'no_page'], 407 => ['fail_http_status', 'proxy'], 500 => ['fail_http_status', null], 502 => ['fail_http_status', null], 503 => ['fail_http_status', null]];
        foreach ($cases as $status => $want) {
            $s = $this->serve(['mode' => 'https', 'status' => $status]);
            $r = $this->probe($this->caPem)->probe(self::row('git.test', $s['port']));
            $this->assertSame($want, [$r['code'], $r['targets'][0]['http']['note']], (string) $status);
            $this->assertSame(['HEAD / HTTP/1.1'], array_column(self::requests($s['log']), 'request'), "{$status}: 상태 줄 한 번(본문·재시도 없음)");
        }
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $this->serve(['mode' => 'https', 'status' => 301, 'location' => 'https://elsewhere.test/'])['port']));
        $this->assertSame(['ok', true, null], [$r['code'], $r['targets'][0]['http']['redirect'], $r['targets'][0]['http']['note']], '3xx = 도달 성공(따라가지 않음)');
        $other = $this->serve(['mode' => 'https', 'status' => 200]);
        $redir = $this->serve(['mode' => 'https', 'status' => 302, 'location' => "https://git.test:{$other['port']}/"]);
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $redir['port']));
        $this->assertSame(['ok', 302, true], [$r['code'], $r['targets'][0]['http']['status'], $r['targets'][0]['http']['redirect']]);
        $this->assertSame([], self::requests($other['log']), '리다이렉트 대상에 접속하지 않음');
        $m = $this->serve(['mode' => 'https', 'status' => 200, 'head_status' => 405]);
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $m['port']));
        $this->assertSame(['ok', 'GET'], [$r['code'], $r['targets'][0]['http']['method']]);
        $this->assertSame(['HEAD / HTTP/1.1', 'GET / HTTP/1.1'], array_column(self::requests($m['log']), 'request'));
        // 평문 HTTP(포트 80처럼 — 여기서는 HTTP 허용 행의 임의 포트를 http로 직접 확인)
        $h = $this->serve(['mode' => 'http', 'status' => 200]);
        $probe = $this->probe(null);
        $r = (new \ReflectionMethod($probe, 'target'))->invoke($probe, 'git.test', '127.0.0.1', $h['port'], 'http', true);
        $this->assertSame(['ok', 200, null], [$r['code'], $r['http']['status'], $r['tls']]);
        // 비표준 포트의 평문 HTTP(사내 HTTP Git — "HTTP 허용" 행): TLS가 아니면 평문으로 보고 HTTP 상태 줄 확인(이전에는 신뢰 안 됨으로 잘못 분류)
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $h['port'], false, true));
        $this->assertSame(['ok', true, 200], [$r['code'], $r['targets'][0]['plaintext'], $r['targets'][0]['http']['status']], json_encode($r));
        // P19C 문구 분기 근거: 평문 HTTP는 상태 줄이 있고(화면 "평문 HTTP로 확인"), 평문 TCP(비HTTP)는 HTTP 결과가 없다(화면 "TCP 접속만")
        $tcp = $this->serve(['mode' => 'plain']);
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $tcp['port'], false, true));
        $this->assertSame(['ok', true, null], [$r['code'], $r['targets'][0]['plaintext'], $r['targets'][0]['http']], json_encode($r));
        $bad = $this->serve(['mode' => 'http', 'status' => 500]);
        $this->assertSame('fail_http_status', $this->probe($this->caPem)->probe(self::row('git.test', $bad['port'], false, true))['code']);
    }

    public function testConnectFailuresPerforceAndAddressPolicy(): void
    {
        // 닫힌 포트 → 거부
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
        fclose($s);
        $r = $this->probe()->probe(self::row('git.test', $port));
        $this->assertSame(['fail_connect', 'refused'], [$r['code'], $r['targets'][0]['tcp']['error']]);
        // TCP는 받지만 TLS를 끝내지 않음 → TLS 시간 초과(1초)
        $hang = $this->serve(['mode' => 'hang']);
        $t = microtime(true);
        $r = $this->probe(null, null, null, 1.0)->probe(self::row('git.test', $hang['port'], false));
        $this->assertSame(['fail_connect', 'timeout'], [$r['code'], $r['targets'][0]['tls']['error'] ?? null], json_encode($r));
        $this->assertTrue(microtime(true) - $t < 3, 'TLS 시간 상한');
        // HTTP 허용·GitHub 호환 행: TLS가 답하지 않으면 평문 HTTP도 보고(HTTP 상한 3초), 그것도 없으면 fail_connect
        $t = microtime(true);
        $r = $this->probe(null, null, null, 1.0)->probe(self::row('git.test', $hang['port'], true));
        $this->assertSame(['fail_connect', 'timeout', null], [$r['code'], $r['targets'][0]['tls']['error'] ?? null, $r['targets'][0]['http']], json_encode($r));
        $this->assertTrue(microtime(true) - $t < 6, 'TLS + HTTP 시간 상한');
        // Perforce SSL 포트(HTTP 아님): 지문 표시 — 공개 키 SHA-1(20바이트)·인증서 SHA-256(32바이트), 콜론 구분 대문자
        $p4 = $this->serve(['mode' => 'tls-silent']);
        $r = $this->probe($this->caPem)->probe(self::row('git.test', $p4['port'], false));
        $c = $r['targets'][0]['tls']['cert'];
        $this->assertSame(['ok', true, null], [$r['code'], $r['targets'][0]['p4'] ?? false, $r['targets'][0]['http']]);
        $this->assertSame(1, preg_match('/^([0-9A-F]{2}:){19}[0-9A-F]{2}$/', $c['key_sha1']));
        $this->assertSame(1, preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $c['fingerprint_sha256']));
        $this->assertSame(HostProbe::colon((string) openssl_x509_fingerprint((string) file_get_contents($this->srvPem), 'sha256')), $c['fingerprint_sha256']);
        $this->assertSame($c['key_sha1'], \PJGuardian\Services\Repo\RepoInput::fingerprint($c['key_sha1']), 'P25 지문 입력 형식과 같음');
        // Perforce 평문 포트: TLS가 아니면 TCP 성공만
        $plain = $this->serve(['mode' => 'plain']);
        $r = $this->probe()->probe(self::row('git.test', $plain['port'], false));
        $this->assertSame(['ok', true, null], [$r['code'], $r['targets'][0]['plaintext'], $r['targets'][0]['tls']]);
        // 이름 풀이 실패 / 금지 주소(메타데이터·loopback 예외 끔) → 접속 시도 없음
        $watch = $this->serve(['mode' => 'https', 'status' => 200]);
        $this->assertSame('fail_dns', $this->probe(null, null, static fn (): array => [])->probe(self::row('git.test', $watch['port']))['code']);
        $r = $this->probe(null, null, static fn (): array => ['169.254.169.254'])->probe(self::row('git.test', $watch['port']));
        $this->assertSame(['fail_blocked_address', [['ip' => '169.254.169.254', 'class' => 'blocked']], []], [$r['code'], $r['dns']['ips'], $r['targets']]);
        $r = $this->probe(null, null, static fn (): array => ['10.0.0.5', '127.0.0.1']);
        $app = TestApp::make($this->root);
        $strict = new HostProbe(new HostPolicy($app->db(), $app->logger, false, static fn (): array => ['127.0.0.1']), new FixedClock(), $app->logger, null, 86400, 1.0, 1.0, 1.0);
        $this->assertSame('fail_blocked_address', $strict->probe(self::row('git.test', $watch['port']))['code'], 'loopback은 개발 예외일 때만');
        $this->assertSame([], self::requests($watch['log']), '금지·풀리지 않는 이름에는 접속하지 않음');
        // 포트 0: https 443 + (HTTP 허용이면) 80
        $this->assertSame([[443, 'https'], [80, 'http']], HostProbe::targets(self::row('h', 0, false, true)));
        $this->assertSame([[443, 'https']], HostProbe::targets(self::row('h', 0, false, false)));
        $this->assertSame([[1666, 'tls?']], HostProbe::targets(self::row('h', 1666, false)));
        // 이름 비교(와일드카드는 한 단계)
        $this->assertTrue(HostProbe::nameMatches('a.example.com', ['*.example.com']));
        $this->assertFalse(HostProbe::nameMatches('a.b.example.com', ['*.example.com']));
        $this->assertFalse(HostProbe::nameMatches('example.com', ['*.example.com']));
        $this->assertTrue(HostProbe::nameMatches('GIT.Test.', ['git.test']));
    }

    public function testApiAccessRateLimitStorageAndAudit(): void
    {
        // 인증서는 지금(실제 시각) 만들었으므로 이 시험은 실제 시계로(고정 시계 10-02는 인증서 시작 전이라 "유효 기간 밖")
        mkdir($this->root . '/work');
        $cfg = TestApp::config(['paths' => ['logs_dir' => $this->root, 'work_dir' => $this->root . '/work'], 'auth' => ['bcrypt_cost' => '4'],
            'repository' => ['allow_local_dev' => 'true', 'test_per_minute' => '3', 'host_test_max' => '2', 'ca_cert_file' => $this->caPem]]);
        $clock = new \PJGuardian\Support\SystemClock();
        $app = new App(PJG_ROOT, $cfg, $clock, new \PJGuardian\Support\Logger($this->root, $clock, 'INFO'), new \PJGuardian\Support\Paths(PJG_ROOT, $cfg), TestDb::migrated());
        $srv = $this->serve(['mode' => 'https', 'status' => 401]);
        $app->setRepoTesting([], static fn (string $h): array => $h === 'git.test' ? ['127.0.0.1'] : ($h === 'meta.test' ? ['169.254.169.254'] : []));
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $actor = Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], '127.0.0.1');
        $h1 = $app->repoAllowedHosts()->add($actor, $admin, ['host' => 'git.test', 'port' => $srv['port'], 'github_links' => true]);
        $app->repoAllowedHosts()->add($actor, $admin, ['host' => 'meta.test', 'port' => 443]);
        $app->repoAllowedHosts()->add($actor, $admin, ['host' => 'zz.test', 'port' => 443]);
        $anon = new TestClient($app);
        $this->assertSame(401, $anon->get('/api/v1/admin/repo-hosts')->status);
        $this->assertSame(403, $anon->post("/api/v1/admin/repo-hosts/{$h1['id']}/test")->status, '비로그인 POST는 CSRF 검사가 먼저(기존 동작)');
        $kim = new TestClient($app);
        $kim->login('kim', 'Passw0rd!x');
        $this->assertSame(403, $kim->post("/api/v1/admin/repo-hosts/{$h1['id']}/test")->status);
        $this->assertSame(403, $kim->post('/api/v1/admin/repo-hosts/test-all')->status);
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $this->assertSame(403, $c->post("/api/v1/admin/repo-hosts/{$h1['id']}/test", [], false)->status, 'CSRF');
        $this->assertSame(404, $c->post('/api/v1/admin/repo-hosts/9999/test')->status, '등록되지 않은 호스트');
        // 한 호스트: 401은 "응답함(인증 필요)" = 정상, 결과·인증서 만료일 저장
        $r = $c->post("/api/v1/admin/repo-hosts/{$h1['id']}/test");
        $d = $r->decoded()['data'];
        $this->assertSame([200, 'ok', 401], [$r->status, $d['code'], $d['targets'][0]['http']['status']], $r->body);
        $saved = $db->one('SELECT * FROM repo_allowed_hosts WHERE id = ?', [$h1['id']]);
        $this->assertSame(['ok', SystemClock::iso(new \DateTimeImmutable('@' . $this->srvNotAfter)), 'PJG Test CA'], [$saved['last_test_code'], $saved['cert_not_after'], $saved['cert_issuer']]);
        $list = $c->get('/api/v1/admin/repo-hosts')->decoded()['data'];
        $row = array_values(array_filter($list, static fn (array $x): bool => $x['id'] === $h1['id']))[0];
        $this->assertSame(['ok', false, false], [$row['last_test']['code'], $row['cert']['expired'], $row['cert']['expiring']]);
        // 전체: 상한 2개(호스트 순 git.test, meta.test), 나머지 1개 안내, 분당 제한은 1회로
        $all = $c->post('/api/v1/admin/repo-hosts/test-all')->decoded()['data'];
        $this->assertSame([3, 2, 1, 2, ['ok', 'fail_blocked_address']], [$all['total'], $all['tested'], $all['truncated'], $all['max'], array_column($all['results'], 'code')]);
        // 지금까지 3회(한 호스트 1 + 전체 1 + 위 404·CSRF는 세지 않음 = 2) → 한 번 더 되고 그다음 429
        $this->assertSame(200, $c->post("/api/v1/admin/repo-hosts/{$h1['id']}/test")->status);
        $r = $c->post('/api/v1/admin/repo-hosts/test-all');
        $this->assertSame([429, 'repo_test_rate_limited'], [$r->status, $r->decoded()['error']['code']]);
        // 감사: repo.host_test(호스트·코드), 저장소·자격 증명 없음
        $audit = $db->all("SELECT target_id, details FROM audit_log WHERE action = 'repo.host_test' ORDER BY id");
        $this->assertSame(["git.test:{$srv['port']}", "git.test:{$srv['port']}", 'meta.test:443', "git.test:{$srv['port']}"], array_column($audit, 'target_id'));
        $this->assertSame('fail_blocked_address', json_decode($audit[2]['details'], true)['code']);
        foreach (self::requests($srv['log']) as $q) {
            $this->assertSame([false, false], [$q['auth'], $q['cookie']], '자격 증명·쿠키를 보내지 않음');
        }
    }
}
