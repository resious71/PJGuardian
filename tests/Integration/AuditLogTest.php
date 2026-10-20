<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\AuditActions;
use PJGuardian\Support\AuditChain;
use PJGuardian\Support\FixedClock;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 감사 로그 조회(P30): admin만(그룹 관리자·일반 사용자 403), 읽기 전용(쓰기 라우트 없음), 목록·조건(기간·행위자·작업 이름/접두사·대상·검색)·쪽·형식 오류 400,
 * 상세의 화면 단계 마스킹(비밀번호·토큰·이메일 원문 없음 — 기록 때 가리지 않은 예전 행도), 무결성 검사(조각 = 전체, 변조한 행을 지목),
 * 보존 정리(앞부분만·묶음·기준점(체크포인트) 이동·정리 전후 체인 검증 통과·audit.purge 기록·dry-run 건수), CSV 내보내기(수식 방지·BOM·audit.export),
 * 코드에 문자열로 쓴 작업 이름이 모두 AuditActions에 있음, 030 마이그레이션 열.
 */
final class AuditLogTest extends TestCase
{
    private App $app;
    private FixedClock $clock;

    public function setUp(): void
    {
        $dir = $this->tempDir('audit');
        $this->app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'log' => ['audit_retention' => '90d', 'audit_purge_batch' => '100']]);
        $this->clock = $this->app->clock;
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $t = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('팀', ?, ?)", [$t, $t]);
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, ?, 1, ?)', [$mgr, $t]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function rec(string $label, string $action, ?string $type = null, ?string $id = null, array $details = []): int
    {
        return $this->app->audit()->record(Actor::labeled($label), $action, $type, $id, $details);
    }

    public function testAccessAndReadOnly(): void
    {
        $this->rec('alice', 'user.create', 'user', '9');
        foreach (['kim', 'mgr'] as $login) {
            $c = $this->as($login);
            foreach (['/admin/audit-logs', '/api/v1/admin/audit-logs', '/api/v1/admin/audit-logs/1', '/api/v1/admin/audit-logs/integrity'] as $p) {
                $r = $c->get($p);
                $this->assertSame(403, $r->status, "{$login} {$p}");
                $this->assertNotContains('user.create', $r->body);
            }
            $this->assertSame(403, $c->post('/api/v1/admin/audit-logs/export', [])->status);
        }
        // 쓰기 메서드 라우트 없음(내보내기 POST 하나만 — 파일을 만들 뿐 감사 로그를 바꾸지 않음)
        $writes = [];
        foreach ($this->app->router->routes() as $r) {
            if (str_starts_with($r['path'], '/api/v1/admin/audit-logs') && $r['method'] !== 'GET') {
                $writes[] = $r['method'] . ' ' . $r['path'];
            }
        }
        $this->assertSame(['POST /api/v1/admin/audit-logs/export'], $writes);
        $admin = $this->as('admin');
        foreach (['PUT', 'PATCH', 'DELETE'] as $m) {
            $this->assertTrue(in_array($admin->request($m, '/api/v1/admin/audit-logs/1')->status, [404, 405], true), $m);
        }
        $page = $admin->get('/admin/audit-logs');
        $this->assertSame(200, $page->status);
        $this->assertContains('data-audit', $page->body);
        $this->assertContains('<option value="faq.">', $page->body, '작업 접두사 선택지');
    }

    public function testListFiltersAndPaging(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->rec('alice', 'user.update', 'user', (string) $i);
            $this->clock->advance(3600);
        }
        $this->rec('bob_admin', 'group.create', 'group', '7', ['name' => '50%_할인']);
        $this->clock->advance(86400 * 10);
        $this->rec('carol', 'faq.update', 'faq', '3', ['question' => '업로드 한도']);
        $admin = $this->as('admin'); // 로그인 자체는 감사 로그가 아님(명세 10장 결정)
        $all = $admin->get('/api/v1/admin/audit-logs')->decoded();
        $this->assertSame(7, $all['page']['total']);
        $this->assertSame('faq.update', $all['data'][0]['action'], '최신순');
        $this->assertSame(['alice', 'bob_admin', 'carol'], array_values(array_unique(array_merge(...array_map(static fn ($r) => [$r['actor']], array_reverse($all['data']))))));
        $q = static fn (array $p) => $admin->get('/api/v1/admin/audit-logs', $p)->decoded();
        $this->assertSame(5, $q(['action' => 'user.'])['page']['total'], '접두사');
        $this->assertSame(5, $q(['action' => 'user'])['page']['total'], '점 없는 접두사도');
        $this->assertSame(1, $q(['action' => 'group.create'])['page']['total'], '이름 하나');
        $this->assertSame(1, $q(['actor' => 'b_ad'])['page']['total'], '행위자 부분 일치(_는 글자)');
        $this->assertSame(0, $q(['actor' => '%'])['page']['total'], '%는 글자');
        $this->assertSame(1, $q(['target' => 'group:7'])['page']['total']);
        $this->assertSame(1, $q(['q' => '50%_할'])['page']['total'], '세부 정보 검색');
        $this->assertSame(1, $q(['days' => '1'])['page']['total'], '최근 1일');
        $this->assertSame(7, $q(['days' => '30'])['page']['total']);
        $this->assertSame(3, $q(['from' => '2026-10-02T14:00:00Z', 'to' => '2026-10-02T16:30:00Z'])['page']['total'], '직접 기간(14·15·16시)');
        $p2 = $q(['size' => '3', 'page' => '2']);
        $this->assertSame([2, 3, 3, 3], [$p2['page']['page'], $p2['page']['size'], $p2['page']['pages'], count($p2['data'])]);
        $this->assertSame(range(1, 7), array_merge(...array_map(fn ($p) => array_column(array_reverse($q(['size' => '3', 'page' => (string) $p])['data']), 'id'), [3, 2, 1])), '쪽을 이어 붙이면 전체');
        foreach ([['action' => 'nope.'], ['action' => 'user.nope'], ['days' => '2'], ['from' => '2026-10-02'], ['size' => '201'], ['page' => '0'],
            ['from' => '2026-10-03T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'], ['actor' => str_repeat('a', 101)]] as $bad) {
            $r = $admin->get('/api/v1/admin/audit-logs', $bad);
            $this->assertSame(400, $r->status, json_encode($bad));
            $this->assertSame('invalid_audit_filter', $r->decoded()['error']['code']);
        }
        $meta = $all['meta'];
        $this->assertSame(AuditActions::prefixes(), $meta['prefixes']);
        $this->assertSame(7, $meta['range']['rows']);
    }

    public function testDetailIsMaskedAgain(): void
    {
        // 기록 때 가리지 않은 예전 행을 흉내: 체인을 이어 직접 넣는다
        $db = $this->app->db();
        $details = json_encode(['password' => 'Sup3rSecret!', 'email' => 'kim.lee@example.com', 'note' => 'login with token=abcdef123456 ok',
            'nested' => ['api_key' => 'AKIA1234567890', 'contact' => 'mail park@example.org please']], JSON_UNESCAPED_UNICODE);
        $prev = (string) ($db->value('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1') ?? AuditChain::GENESIS);
        $row = ['occurred_at' => '2026-10-02T11:00:00Z', 'actor_user_id' => null, 'actor_label' => 'legacy', 'action' => 'user.update',
            'target_type' => 'user', 'target_id' => 'owner@example.com', 'details' => $details, 'ip' => '10.0.0.1'];
        $db->execute('INSERT INTO audit_log (occurred_at, actor_user_id, actor_label, action, target_type, target_id, details, ip, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [...array_values($row), $prev, AuditChain::hash($prev, $row)]);
        $id = (int) $db->lastInsertId();
        $admin = $this->as('admin');
        $r = $admin->get("/api/v1/admin/audit-logs/{$id}");
        $this->assertSame(200, $r->status);
        foreach (['Sup3rSecret!', 'kim.lee@example.com', 'abcdef123456', 'AKIA1234567890', 'park@example.org', 'owner@example.com'] as $secret) {
            $this->assertNotContains($secret, $r->body, $secret);
            $this->assertNotContains($secret, $admin->get('/api/v1/admin/audit-logs')->body, "목록 {$secret}");
        }
        $d = $r->decoded()['data'];
        $this->assertSame('k***@example.com', $d['details']['email']);
        $this->assertSame('***', $d['details']['password']);
        $this->assertSame(64, strlen($d['hash']));
        $this->assertTrue(AuditChain::verify($db)['ok'], '직접 넣은 행도 체인 정상');
        $this->assertSame(404, $admin->get('/api/v1/admin/audit-logs/99999')->status);
    }

    public function testIntegrityChunksAndTamper(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->rec('alice', 'project.update', 'project', (string) $i, ['n' => $i]);
        }
        $admin = $this->as('admin');
        $walk = function (int $limit) use ($admin): array {
            $after = 0;
            $checked = 0;
            for ($guard = 0; $guard < 100; $guard++) {
                $r = $admin->get('/api/v1/admin/audit-logs/integrity', ['after' => (string) $after, 'limit' => (string) $limit])->decoded()['data'];
                $checked += $r['checked'];
                if (!$r['ok'] || $r['done']) {
                    return $r + ['total' => $checked];
                }
                $after = $r['last_id'];
            }
            return ['ok' => false, 'reason' => 'loop'];
        };
        $full = $walk(20000);
        $this->assertSame([true, 25], [$full['ok'], $full['total']]);
        $this->assertSame([true, 25], [$walk(7)['ok'], $walk(7)['total']], '조각을 이어도 같음');
        $this->assertSame(25, $full['range']['rows']);
        // 변조: 트리거를 지우고(시험 DB) 한 행의 세부 정보를 바꾼다 → 그 행을 지목
        $db = $this->app->db();
        $db->pdo()->exec('DROP TRIGGER audit_log_no_update');
        $db->execute('UPDATE audit_log SET details = ? WHERE id = 13', ['{"n":999}']);
        $broken = $walk(5);
        $this->assertSame([false, 13, 'hash_mismatch'], [$broken['ok'], $broken['broken_at'], $broken['reason']]);
        $this->assertSame(['ok' => false, 'broken_at' => 13], array_intersect_key(AuditChain::verify($db), ['ok' => 1, 'broken_at' => 1]), '전체 검증과 같은 행');
        // 행을 지우면(체인 끊김) 다음 행을 지목
        $db->execute('UPDATE audit_log SET details = ? WHERE id = 13', ['{"n":12}']);
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 1');
        $db->execute('DELETE FROM audit_log WHERE id = 20');
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 0');
        $gap = $walk(4);
        $this->assertSame([false, 21, 'prev_hash_mismatch'], [$gap['ok'], $gap['broken_at'], $gap['reason']]);
        $this->assertSame(400, $admin->get('/api/v1/admin/audit-logs/integrity', ['limit' => '20001'])->status);
    }

    public function testRetentionPurgeKeepsChain(): void
    {
        // 100일 전 30건, 50일 전 5건
        $this->clock->advance(-100 * 86400);
        for ($i = 0; $i < 30; $i++) {
            $this->rec('old', 'repo.test', 'project', (string) $i);
            $this->clock->advance(60);
        }
        $this->clock->advance(50 * 86400);
        for ($i = 0; $i < 5; $i++) {
            $this->rec('mid', 'repo.save', 'project', (string) $i);
        }
        $this->clock->advance(50 * 86400 - 30 * 60);
        $db = $this->app->db();
        $this->assertTrue(AuditChain::verify($db)['ok'], '정리 전 체인');
        $ret = new \PJGuardian\Services\Audit\AuditRetention($db, $this->clock, $this->app->audit(), 90 * 86400, 7);
        $pre = $ret->preview();
        $this->assertSame([30, 30], [$pre['count'], $pre['through_id']], 'dry-run 건수');
        $this->assertSame(35, (int) $db->value('SELECT COUNT(*) FROM audit_log'), 'dry-run은 지우지 않음');
        $dry = $this->app->cleanup()->purgeAuditLog(true);
        $this->assertSame(30, $dry['audit_purged']);
        $r = $ret->purge();
        $this->assertSame(30, $r['deleted'], '묶음 7행씩 → 30행');
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE actor_label = 'old'"), '오래된 것만 사라짐');
        $this->assertSame(5, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE actor_label = 'mid'"), '보존 기간 안은 그대로');
        $meta = $db->one('SELECT * FROM audit_log_meta WHERE id = 1');
        $this->assertSame([30, $pre['through_at'], 0], [(int) $meta['purged_through'], $meta['purged_through_at'], (int) $meta['purge_enabled']], '체크포인트(번호·시각)');
        $this->assertSame($this->app->clock->now()->format('Y-m-d\TH:i:s\Z'), $meta['purged_at']);
        $v = AuditChain::verify($db);
        $this->assertTrue($v['ok'], '정리 후에도 체인 검증 통과');
        $purge = $db->one("SELECT * FROM audit_log WHERE action = 'audit.purge'");
        $this->assertSame(['deleted' => 30, 'through_id' => 30, 'through_at' => $pre['through_at'], 'cutoff' => $pre['cutoff']], json_decode((string) $purge['details'], true));
        $this->assertSame('system', $purge['actor_label']);
        $this->assertSame(0, $ret->purge()['deleted'], '다시 돌리면 0(기록도 없음)');
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'audit.purge'"));
        // 무결성 API도 경계를 보여 줌
        $admin = $this->as('admin');
        $i = $admin->get('/api/v1/admin/audit-logs/integrity')->decoded()['data'];
        $this->assertSame([true, 30, $pre['through_at']], [$i['ok'], $i['range']['purged_through'], $i['range']['purged_through_at']]);
    }

    public function testExportCsv(): void
    {
        $this->rec('=cmd|calc', 'user.create', 'user', '5', ['login_id' => '+evil']);
        $this->rec('bob', 'group.create', 'group', '1');
        $admin = $this->as('admin');
        $r = $admin->request('POST', '/api/v1/admin/audit-logs/export', [], ['action' => 'user.']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('1', $r->header('X-Export-Rows'));
        $csv = (string) file_get_contents($r->filePath());
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"), 'UTF-8 BOM');
        $this->assertContains("'=cmd|calc", $csv, '수식 시작 글자 앞 작은따옴표');
        $this->assertNotContains(',=cmd', $csv);
        $this->assertNotContains('group.create', $csv, '조건 적용');
        $exp = $this->app->db()->one("SELECT * FROM audit_log WHERE action = 'audit.export'");
        $this->assertSame('admin', $exp['actor_label']);
        $this->assertSame(1, json_decode((string) $exp['details'], true)['rows']);
        $this->assertSame(409, $admin->request('POST', '/api/v1/admin/audit-logs/export', [], ['action' => 'faq.'])->status, '0건');
    }

    public function testActionNamesInCodeAreListed(): void
    {
        $known = AuditActions::all();
        $this->assertSame(count($known), count(array_unique($known)), '중복 없음');
        $missing = [];
        $found = [];
        $files = [];
        foreach ([PJG_ROOT . '/src', PJG_ROOT . '/tools'] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && !str_contains(str_replace('\\', '/', $f->getPathname()), '/tools/proto/')) {
                    $files[] = $f->getPathname();
                }
            }
        }
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // record(…, '작업') / audit($request, '작업') / 기본값 string $action = '작업' / AUDIT_ACTION = '작업' / 'cli.…' 넘기기
            preg_match_all("/(?:->record\\([^;]*?|->audit\\(\\\$request, |\\\$action = |AUDIT_ACTION = |, \\\$id, )'([a-z_]+\\.[a-z_]+)'/", $src, $m);
            foreach ($m[1] as $name) {
                $found[$name] = true;
                if (!in_array($name, $known, true) && preg_match('/^(user|cli|auth|group|project|run|repo|repo_host|schedule|rules|export|tool|worker|disk|cleanup|config|setting|backup|restore|key|faq|audit)\./', $name)) {
                    $missing[] = basename($file) . ':' . $name;
                }
            }
        }
        $this->assertTrue(count($found) >= 60, '코드에서 작업 이름을 찾음: ' . count($found));
        $this->assertSame([], array_values(array_unique($missing)), '코드의 작업 이름이 AuditActions에 없음');
        // 030 열
        $cols = array_column($this->app->db()->all('PRAGMA table_info(audit_log_meta)'), 'name');
        $this->assertTrue(in_array('purged_through_at', $cols, true) && in_array('purged_at', $cols, true));
        $this->assertSame('030', (string) $this->app->db()->value("SELECT version FROM schema_migrations WHERE version = '030'"));
    }
}
