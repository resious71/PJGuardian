<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Tests\Support\FakeDirectoryClient;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * LDAP/AD 로그인과 승인 대기(P28B, 가짜 디렉터리 — 실제 서버에 연결하지 않음): 첫 로그인 → 승인 대기(세션 없음) → 승인 → 로그인,
 * 거절·상한·정리, 관리자가 미리 만든 외부 계정 연결, 같은 ID 로컬 계정 보호, 식별자 불일치, 이름·이메일 비갱신, 열거 방지,
 * 속도 제한(앱 계정 잠금 미적용), 인증 방식 전환, 검색 계정 비밀번호 저장·연결 시험, 로그·감사 로그에 비밀 없음.
 */
final class LdapLoginTest extends TestCase
{
    private App $app;
    private string $logDir;
    private FakeDirectoryClient $dir;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('ldaplogin');
        $this->app = $this->make();
        TestDb::user($this->app->db(), 'admin', 'admin');
        TestDb::user($this->app->db(), 'kim', 'user'); // 같은 ID의 로컬 계정
        FakeDirectoryClient::$log = [];
    }

    private function make(array $over = []): App
    {
        $app = TestApp::make($this->logDir, array_replace_recursive([
            'ldap' => ['enabled' => 'true', 'servers' => 'ldaps://dir.test', 'base_dn' => 'DC=test', 'bind_dn' => 'CN=svc,DC=test'],
            'auth' => ['pending_max' => '3', 'pending_retention_days' => '30'],
            // 연결 시험 횟수 기록(ldap_test/) 등 작업 폴더를 시험마다 따로 — 공유 data/work를 쓰지 않게
            'paths' => ['work_dir' => $this->logDir . '/work'],
        ], $over), ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        $this->dir = new FakeDirectoryClient([
            'lee' => ['password' => 'Dir-Pw-1', 'id' => 'guid-lee', 'name' => 'Lee Dir', 'email' => 'lee@corp.test'],
            'kim' => ['password' => 'Dir-Pw-2', 'id' => 'guid-kim', 'name' => 'Kim Dir'],
            'park' => ['password' => 'Dir-Pw-3', 'id' => 'guid-park', 'name' => 'admin'], // 이름이 다른 사용자 로그인 ID와 같음
            'off' => ['password' => 'Dir-Pw-4', 'id' => 'guid-off', 'disabled' => true],
            'twin' => ['password' => 'x', 'id' => 'g', 'dup' => true],
            'bad id!' => ['password' => 'Dir-Pw-5', 'id' => 'guid-bad'],
        ]);
        $dir = &$this->dir;
        $app->useDirectoryClient(static function () use (&$dir): FakeDirectoryClient {
            return $dir;
        });
        if (isset($this->app)) {
            return $app;
        }
        return $app;
    }

    private function login(string $id, string $pw, ?App $app = null): array
    {
        $c = new TestClient($app ?? $this->app);
        $r = $c->login($id, $pw);
        return [$r->status, $r->decoded()['error']['code'] ?? null, $c, $r];
    }

    private function admin(): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login('admin', 'Passw0rd!x')->status);
        return $c;
    }

    private function user(string $login): ?array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE login_id = ?', [$login]);
    }

    private function count(string $sql, array $p = []): int
    {
        return (int) $this->app->db()->value($sql, $p);
    }

    public function testFirstLoginCreatesPendingThenApprovalAllowsLogin(): void
    {
        [$status, $code, $c] = $this->login('lee', 'Dir-Pw-1');
        $this->assertSame([403, 'account_pending'], [$status, $code], '첫 로그인 = 승인 대기(세션 없음)');
        $this->assertSame(401, $c->get('/api/v1/auth/me')->status, '세션을 만들지 않음');
        $u = $this->user('lee');
        $this->assertSame(['pending', 'inactive', 'ldap', null, 'Lee Dir', 'lee@corp.test', 'guid-lee', 'user'],
            [$u['approval_status'], $u['status'], $u['auth_type'], $u['password_hash'], $u['display_name'], $u['email'], $u['external_id'], $u['role']]);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM audit_log WHERE action = 'user.pending_create'"));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE kind = 'account_pending'"), '관리자 알림');
        // 다시 로그인해도 대기(계정이 늘지 않음), 틀린 비밀번호는 일반 실패
        $this->assertSame([403, 'account_pending'], array_slice($this->login('lee', 'Dir-Pw-1'), 0, 2));
        $this->assertSame([401, 'login_failed'], array_slice($this->login('lee', 'nope'), 0, 2));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM users WHERE login_id = 'lee'"));
        // 승인 대기 계정은 권한·목록·검색에서 빠진다(역할 열 값과 무관)
        $a = $this->admin();
        $this->assertFalse(str_contains($a->get('/api/v1/users')->body, '"lee"'), '기본 목록(승인된 계정)에 없음');
        $this->assertFalse(str_contains($a->get('/api/v1/users/search', ['q' => 'lee'])->body, 'lee'), '사용자 검색(그룹 추가 등)에 없음');
        $this->assertSame([409, 'account_not_approved'], [$a->put("/api/v1/users/{$u['id']}/status", ['status' => 'active'])->status, $a->last->decoded()['error']['code']]);
        $list = $a->get('/api/v1/users', ['approval' => 'pending'])->decoded();
        $this->assertSame(['lee'], array_column($list['data'], 'login_id'));
        $this->assertSame(['pending' => 1, 'rejected' => 0], $list['approval_counts']);
        $this->assertSame(['local', 'ldap'], $list['auth_types']);
        // 승인(동시 처리 충돌은 409) → 로그인 성공(역할 적용), 이름·이메일은 처음 값 그대로
        $this->assertSame([409, 'approval_conflict'], [$a->post("/api/v1/users/{$u['id']}/approve", ['role' => 'user', 'updated_at' => '2000-01-01T00:00:00Z'])->status, $a->last->decoded()['error']['code']]);
        $r = $a->post("/api/v1/users/{$u['id']}/approve", ['role' => 'admin', 'updated_at' => $u['updated_at']]);
        $this->assertSame([200, 'approved', 'active', 'admin'], [$r->status, $r->decoded()['data']['approval_status'], $r->decoded()['data']['status'], $r->decoded()['data']['role']]);
        $this->assertSame(409, $a->post("/api/v1/users/{$u['id']}/approve", ['role' => 'user', 'updated_at' => $u['updated_at']])->status, '같은 요청 두 번째 = 409');
        $this->assertSame('admin', json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'user.approve'"), true)['role'], '감사 로그에 역할');
        $this->dir->users['lee']['name'] = 'Changed In Directory';
        $this->dir->users['lee']['email'] = 'new@corp.test';
        [$status, , $c] = $this->login('lee', 'Dir-Pw-1');
        $this->assertSame(200, $status);
        $this->assertSame(['lee', 'admin', 'Lee Dir', 'lee@corp.test'], [$c->get('/api/v1/auth/me')->decoded()['data']['user']['login_id'], $c->get('/api/v1/auth/me')->decoded()['data']['user']['role'],
            $this->user('lee')['display_name'], $this->user('lee')['email']], '이미 있는 계정의 이름·이메일은 외부 값으로 갱신하지 않음');
        // LDAP 계정의 이름·이메일은 앱에서 고친다(ldap_managed 폐지)
        $this->assertSame(200, $c->patch('/api/v1/users/me', ['display_name' => 'Lee App'])->status);
        $this->assertSame('Lee App', $this->user('lee')['display_name']);
        // 앱 계정 잠금은 LDAP 계정에 적용하지 않는다
        for ($i = 0; $i < 6; $i++) {
            $this->login('lee', 'wrong');
        }
        $this->assertSame([0, null], [(int) $this->user('lee')['failed_login_count'], $this->user('lee')['locked_until']]);
    }

    public function testRejectLimitPurgeAndNameConflict(): void
    {
        $this->login('lee', 'Dir-Pw-1');
        $this->login('park', 'Dir-Pw-3');
        $a = $this->admin();
        $rows = array_column($a->get('/api/v1/users', ['approval' => 'pending'])->decoded()['data'], null, 'login_id');
        $this->assertSame([false, true], [$rows['lee']['name_conflict'], $rows['park']['name_conflict']], '이름이 다른 사용자 로그인 ID와 같으면 경고');
        $park = $this->user('park');
        $this->assertSame(200, $a->post("/api/v1/users/{$park['id']}/reject", ['updated_at' => $park['updated_at']])->status);
        $this->assertSame([403, 'account_rejected'], array_slice($this->login('park', 'Dir-Pw-3'), 0, 2), '거절 뒤 재로그인 = 안내만');
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM users WHERE login_id = 'park'"), '대기가 늘지 않음');
        // 상한 3: 대기+거절이 3이면 새 사람은 만들지 않음 + 알림 하루 한 번
        $this->dir->users['choi'] = ['password' => 'p1', 'id' => 'guid-choi'];
        $this->dir->users['han'] = ['password' => 'p2', 'id' => 'guid-han'];
        $this->dir->users['yoo'] = ['password' => 'p3', 'id' => 'guid-yoo'];
        $this->assertSame('account_pending', $this->login('choi', 'p1')[1]);
        $this->assertSame([403, 'account_requests_closed'], array_slice($this->login('han', 'p2'), 0, 2));
        $this->assertSame('account_requests_closed', $this->login('yoo', 'p3')[1]);
        $this->assertSame(null, $this->user('han'));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE kind = 'account_pending_limit'"), '관리자 1명에게 하루 1건');
        // 삭제: 승인된 계정은 지우지 않음, 대기 계정은 지움
        $this->assertSame([409, 'user_not_deletable'], [$a->request('DELETE', '/api/v1/users/' . $this->user('kim')['id'], ['updated_at' => 'x'])->status, $a->last->decoded()['error']['code']]);
        $choi = $this->user('choi');
        $this->assertSame(200, $a->request('DELETE', "/api/v1/users/{$choi['id']}", ['updated_at' => $choi['updated_at']])->status);
        $this->assertSame(null, $this->user('choi'));
        // 보관 기간(30일)이 지난 대기·거절은 정리 작업이 지운다(감사 로그에 건수만)
        $this->app->db()->execute("UPDATE users SET updated_at = '2026-08-01T00:00:00Z' WHERE login_id IN ('lee', 'park')");
        $this->assertSame(2, $this->app->cleanup()->runAll(true)['pending_accounts_purged'], 'dry-run은 세기만');
        $this->assertSame(2, $this->app->cleanup()->runAll()['pending_accounts_purged']);
        $this->assertSame([null, null], [$this->user('lee'), $this->user('park')]);
        $this->assertContains('"count":2', (string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'user.pending_purge'"));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM (SELECT 1 FROM pragma_foreign_key_check)'));
    }

    public function testPrecreatedAccountsLinkOnFirstLoginAndLocalAccountsStayLocal(): void
    {
        // 같은 ID의 로컬 계정: 디렉터리 비밀번호로는 로그인되지 않고(디렉터리에 묻지도 않음) 로컬 비밀번호로만
        $this->assertSame([401, 'login_failed'], array_slice($this->login('kim', 'Dir-Pw-2'), 0, 2));
        $this->assertSame([], FakeDirectoryClient::$log, '로컬 계정은 디렉터리에 묻지 않음');
        $this->assertSame(200, $this->login('kim', 'Passw0rd!x')[0]);
        // 관리자가 CSV로 미리 만든 LDAP 계정: 승인 없이 활성, 비밀번호 없음, 첫 로그인 때 로그인 ID로 연결(이름·이메일 그대로)
        $a = $this->admin();
        $csv = "login_id,display_name,email,auth_type\nlee,Lee From CSV,lee.csv@corp.test,ldap\n";
        $p = $a->raw('POST', '/api/v1/admin/account-import/preview', $csv)->decoded()['data'];
        $this->assertSame(['ok', 1], [$p['rows'][0]['status'], $p['summary']['create']], 'LDAP이 켜져 있으면 auth_type=ldap 허용');
        $r = $a->raw('POST', '/api/v1/admin/account-import/execute', $csv, [], true, ['digest' => $p['digest']])->decoded()['data'];
        $this->assertSame(['created', null], [$r['rows'][0]['result'], $r['rows'][0]['temp_password']], '임시 비밀번호 없음');
        $u = $this->user('lee');
        $this->assertSame(['approved', 'active', null, null, 0], [$u['approval_status'], $u['status'], $u['password_hash'], $u['external_id'], (int) $u['must_change_password']]);
        $this->assertSame(200, $this->login('lee', 'Dir-Pw-1')[0], '승인 없이 로그인');
        $u = $this->user('lee');
        $this->assertSame(['guid-lee', 'Lee From CSV', 'lee.csv@corp.test'], [$u['external_id'], $u['display_name'], $u['email']]);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM audit_log WHERE action = 'user.external_link'"));
        // 같은 로그인 ID인데 식별자가 다름(다른 사람이 ID를 다시 씀) → 거부 + 감사 + 관리자 알림
        $this->dir->users['lee']['id'] = 'guid-someone-else';
        $this->assertSame([401, 'login_failed'], array_slice($this->login('lee', 'Dir-Pw-1'), 0, 2));
        $this->assertContains('identity_mismatch', (string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1"));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE kind = 'auth_identity_mismatch'"));
        // LDAP이 꺼지면 ldap 계정은 일반 실패(디렉터리에 묻지 않음), CSV의 ldap은 다시 "켜지지 않은 방식"
        $off = $this->make(['ldap' => ['enabled' => 'false']]);
        $off2 = new \PJGuardian\App(PJG_ROOT, $off->config, $off->clock, $off->logger, $off->paths, $this->app->db());
        FakeDirectoryClient::$log = [];
        $this->assertSame([401, 'login_failed'], array_slice($this->login('lee', 'Dir-Pw-1', $off2), 0, 2));
        $this->assertSame([], FakeDirectoryClient::$log);
    }

    public function testDirectoryFailuresEnumerationAndRateLimit(): void
    {
        $unknown = $this->login('nobody', 'whatever');
        $wrong = $this->login('lee', 'wrong');
        $this->assertSame([401, 'login_failed'], array_slice($unknown, 0, 2));
        $this->assertSame([$unknown[3]->decoded()['error']['message'], 401], [$wrong[3]->decoded()['error']['message'], $wrong[0]], '없는 ID·틀린 비밀번호 같은 문구');
        $this->assertSame([401, 'login_failed'], array_slice($this->login('twin', 'x'), 0, 2), '2건 이상 = 실패');
        $this->assertContains('ambiguous', (string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1"));
        $this->assertSame([401, 'login_failed'], array_slice($this->login('off', 'Dir-Pw-4'), 0, 2), 'AD 비활성 거부');
        $this->assertContains('directory_disabled', (string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1"));
        $this->assertSame([401, 'login_failed'], array_slice($this->login('bad id!', 'Dir-Pw-5'), 0, 2), '로그인 ID 규칙 위반은 만들지 않음');
        $this->assertSame(null, $this->user('bad id!'));
        // 필터 주입 시도는 이스케이프된다
        $this->login('*)(uid=*', 'x');
        $this->assertSame('(&(objectClass=user)(sAMAccountName=\2a\29\28uid=\2a))', end(FakeDirectoryClient::$log)['arg']);
        // 디렉터리 장애 = "인증 서비스에 문제"(503), 로컬 관리자 로그인 안내는 하지 않음
        $this->dir->fail = 'unavailable';
        [$status, $code, , $res] = $this->login('lee', 'Dir-Pw-1');
        $this->assertSame([503, 'auth_service_unavailable'], [$status, $code]);
        $this->assertFalse(str_contains($res->body, 'admin'), '안내에 로컬 관리자 언급 없음');
        $this->dir->fail = null;
        // 빈 비밀번호는 디렉터리에 묻지 않음(API 400, 서비스도 바인드하지 않음 — 가짜 bind는 빈 값이면 예외)
        $c = new TestClient($this->app);
        $c->fetchCsrf();
        $this->assertSame(400, $c->post('/api/v1/auth/login', ['login_id' => 'lee', 'password' => ''])->status);
        $this->assertSame(401, $this->app->login()->attempt('lee', '', '10.0.0.9')->code === 'login_failed' ? 401 : 0);
        // 같은 로그인 ID 반복 실패(lockout_max_failures × 2 = 10) → 디렉터리에 묻지 않고 429
        for ($i = 0; $i < 10; $i++) {
            (new TestClient($this->app, '10.1.0.' . $i))->login('lee', 'wrong-' . $i);
        }
        FakeDirectoryClient::$log = [];
        $c = new TestClient($this->app, '10.2.0.1');
        $this->assertSame([429, 'too_many_attempts'], [$c->login('lee', 'Dir-Pw-1')->status, $c->last->decoded()['error']['code']]);
        $this->assertSame([], FakeDirectoryClient::$log, '요청 제한 중엔 디렉터리 호출 없음');
        // 로그·감사 로그에 비밀번호·바인드 DN·필터 원문 없음
        $logs = implode("\n", array_map('file_get_contents', glob($this->logDir . '/*') ?: []));
        $audit = (string) json_encode($this->app->db()->all('SELECT * FROM audit_log'));
        foreach (['Dir-Pw-1', 'Dir-Pw-4', 'wrong-3', 'CN=svc', 'objectClass=user', 'CN=lee'] as $secret) {
            $this->assertFalse(str_contains($logs, $secret), "로그에 {$secret}");
            $this->assertFalse(str_contains($audit, $secret), "감사 로그에 {$secret}");
        }
    }

    public function testAuthTypeChangeAndProtections(): void
    {
        $a = $this->admin();
        $kim = $this->user('kim');
        $vic = new TestClient($this->app);
        $vic->login('kim', 'Passw0rd!x');
        // 로컬 → LDAP: 비밀번호 사라짐·세션 폐기·첫 로그인 때 연결
        $r = $a->put("/api/v1/users/{$kim['id']}/auth-type", ['auth_type' => 'ldap'])->decoded()['data'];
        $this->assertSame(['ldap', null], [$r['user']['auth_type'], $r['temp_password']]);
        $this->assertSame(401, $vic->get('/api/v1/auth/me')->status, '세션 즉시 폐기');
        $this->assertSame([null, null], [$this->user('kim')['password_hash'], $this->user('kim')['external_id']]);
        $this->assertSame(401, $this->login('kim', 'Passw0rd!x')[0], '앱 비밀번호 로그인 사라짐');
        $this->assertSame(200, $this->login('kim', 'Dir-Pw-2')[0]);
        $this->assertSame('guid-kim', $this->user('kim')['external_id']);
        // LDAP → 로컬: 임시 비밀번호 한 번(24시간, 변경 강제)
        $r = $a->put("/api/v1/users/{$kim['id']}/auth-type", ['auth_type' => 'local'])->decoded()['data'];
        $this->assertTrue(is_string($r['temp_password']) && strlen($r['temp_password']) >= 12);
        $this->assertSame(['local', 1, null], [$this->user('kim')['auth_type'], (int) $this->user('kim')['must_change_password'], $this->user('kim')['external_id']]);
        $this->assertSame(200, $this->login('kim', $r['temp_password'])[0]);
        $this->assertSame(2, $this->count("SELECT COUNT(*) FROM audit_log WHERE action = 'user.auth_type_change'"));
        // 자기 자신 금지, 마지막 활성 로컬 관리자 보호, 꺼진·모르는 방식 거부
        $admin = $this->user('admin');
        $this->assertSame([400, 'self_auth_type_change'], [$a->put("/api/v1/users/{$admin['id']}/auth-type", ['auth_type' => 'ldap'])->status, $a->last->decoded()['error']['code']]);
        // kim을 LDAP 관리자로(디렉터리 로그인) — 활성 로컬 관리자는 admin 한 명뿐이라 admin을 LDAP으로 바꿀 수 없다
        $this->app->db()->execute("UPDATE users SET role = 'admin', auth_type = 'ldap', password_hash = NULL, temp_password_expires_at = NULL, must_change_password = 0 WHERE login_id = 'kim'");
        [, , $boss] = $this->login('kim', 'Dir-Pw-2');
        $this->assertSame([409, 'last_admin'], [$boss->put("/api/v1/users/{$admin['id']}/auth-type", ['auth_type' => 'ldap'])->status, $boss->last->decoded()['error']['code'] ?? null], '마지막 활성 로컬 관리자');
        $this->assertSame([409, 'last_admin'], [$boss->put("/api/v1/users/{$admin['id']}/status", ['status' => 'inactive'])->status, $boss->last->decoded()['error']['code'] ?? null], '마지막 로컬 관리자 비활성화도');
        $this->assertSame(400, $a->put("/api/v1/users/{$kim['id']}/auth-type", ['auth_type' => 'oidc'])->status, '꺼진 방식');
        // LDAP 계정의 비밀번호 화면은 디렉터리에서 바꾸라는 안내
        $this->app->db()->execute("UPDATE users SET auth_type = 'ldap', password_hash = NULL, temp_password_expires_at = NULL, must_change_password = 0, role = 'user' WHERE login_id = 'kim'");
        [, , $kc] = $this->login('kim', 'Dir-Pw-2');
        $this->assertContains('id="password-external"', $kc->get('/password')->body);
    }

    public function testBindPasswordStorageAndConnectionTest(): void
    {
        $a = $this->admin();
        $this->assertSame('none', $a->get('/api/v1/admin/auth/ldap')->decoded()['data']['bind_password']);
        $this->assertSame(200, $a->put('/api/v1/admin/auth/ldap/bind-password', ['password' => 'Svc-Secret-77'])->status);
        $st = $a->get('/api/v1/admin/auth/ldap');
        $this->assertSame('set', $st->decoded()['data']['bind_password']);
        $this->assertFalse(str_contains($st->body, 'Svc-Secret-77'));
        $row = $this->app->db()->one("SELECT * FROM app_secrets WHERE name = 'ldap.bind_password'");
        $this->assertFalse(str_contains((string) json_encode($row), 'Svc-Secret-77'), 'DB에는 암호문만');
        $this->assertSame('Svc-Secret-77', $this->app->appSecrets()->get('ldap.bind_password'));
        // 다른 키로는 읽을 수 없음
        $other = TestApp::make($this->logDir, [], ['encryption_key' => base64_encode(str_repeat('z', 32))]);
        $other2 = new \PJGuardian\App(PJG_ROOT, $other->config, $other->clock, $other->logger, $other->paths, $this->app->db());
        $this->assertSame('unreadable', $other2->appSecrets()->state('ldap.bind_password'));
        $this->assertSame(400, $a->put('/api/v1/admin/auth/ldap/bind-password', ['password' => ''])->status);
        $this->assertSame([200, 'none'], [$a->request('DELETE', '/api/v1/admin/auth/ldap/bind-password')->status, $a->last->decoded()['data']['bind_password']]);
        $this->assertFalse(str_contains((string) json_encode($this->app->db()->all("SELECT * FROM audit_log WHERE action LIKE 'auth.ldap_secret%'")), 'Svc-Secret'));
        // 연결 시험: 단계별, 분당 제한(ldap.test_per_minute 기본 6)
        $t = $a->post('/api/v1/admin/auth/ldap/test', ['login_id' => 'lee'])->decoded()['data'];
        $this->assertSame([['connect', true], ['service_bind', true], ['base_dn', true], ['user_search', true]], array_map(static fn (array $s): array => [$s['step'], $s['ok']], $t['steps']));
        $this->assertSame('le***', $t['steps'][3]['detail']['email']);
        for ($i = 0; $i < 5; $i++) {
            $a->post('/api/v1/admin/auth/ldap/test', []);
        }
        $this->assertSame([429, 'ldap_test_rate_limited'], [$a->post('/api/v1/admin/auth/ldap/test', [])->status, $a->last->decoded()['error']['code']]);
        // 일반 사용자 403, CSRF 없는 요청 거부
        $k = new TestClient($this->app);
        $k->login('kim', 'Passw0rd!x');
        $this->assertSame(403, $k->get('/api/v1/admin/auth/ldap')->status);
        $this->assertSame(403, $k->post('/api/v1/users/1/approve', ['role' => 'user', 'updated_at' => 'x'])->status);
        $this->assertSame(403, $a->request('PUT', '/api/v1/admin/auth/ldap/bind-password', [], ['password' => 'x'], null, [], false)->status);
        // 시스템 점검: LDAP이 켜져 있으면 항목(검색 계정 비밀번호 없음 → 문제)
        $items = array_column($a->get('/api/v1/admin/checks')->decoded()['data']['environment']['items'], null, 'id');
        $this->assertSame(['fail', 'ldap_secret_missing'], [$items['ldap']['status'], $items['ldap']['note']]);
        // 로그인 화면: 승인 대기 안내 상자(오류가 아님)
        $form = new TestClient($this->app);
        $page = $form->get('/login');
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
        $res = $form->form('/login', ['_csrf' => $m[1] ?? '', 'login_id' => 'lee', 'password' => 'Dir-Pw-1']);
        $this->assertSame(200, $res->status);
        $this->assertContains('data-account-state="account_pending"', $res->body);
        $this->assertNotContains('id="login-error"', $res->body);
    }
}
