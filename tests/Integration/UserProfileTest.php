<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Controllers\AccountController;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\UserService;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 사용자 정보(표시 이름·이메일) 수정(X05, 명세 3.1, 10, 13, 19.10).
 * 사용자: admin, kim(일반, 그룹 team의 관리자), lee(일반, team 구성원), park(일반), ldapu(LDAP 행), off(비활성), temp(비밀번호 변경 강제).
 */
final class UserProfileTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $id = [];

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('uprof'));
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'park' => 'user'] as $login => $role) {
            $this->id[$login] = TestDb::user($db, $login, $role);
        }
        $this->id['off'] = TestDb::user($db, 'off', 'user', 'inactive');
        $this->id['temp'] = TestDb::user($db, 'temp', 'user');
        $db->execute('UPDATE users SET must_change_password = 1 WHERE id = ?', [$this->id['temp']]);
        $t0 = '2026-10-02T12:00:00Z';
        $db->execute(
            "INSERT INTO users (login_id, display_name, email, role, status, auth_type, password_hash, created_at, updated_at)"
            . " VALUES ('ldapu', 'LDAP 사용자', 'ldap@corp.example', 'user', 'active', 'ldap', NULL, ?, ?)",
            [$t0, $t0],
        );
        $this->id['ldapu'] = $db->lastInsertId();
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('team', ?, ?)", [$t0, $t0]);
        $g = $db->lastInsertId();
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?), (?, ?, 0, ?)',
            [$g, $this->id['kim'], $t0, $g, $this->id['lee'], $t0]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return array<string, mixed> */
    private function row(string $login): array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$this->id[$login]]);
    }

    private function auditCount(): int
    {
        return (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.update'");
    }

    private function code(\PJGuardian\Http\Response $r): ?string
    {
        return $r->decoded()['error']['code'] ?? null;
    }

    // ---- 권한 ----

    public function testWhoMayEditWhom(): void
    {
        $admin = $this->as('admin');
        $r = $admin->patch("/api/v1/users/{$this->id['lee']}", ['display_name' => '이개발', 'email' => 'lee@example.com']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['이개발', 'lee@example.com'], [$r->decoded()['data']['display_name'], $r->decoded()['data']['email']], 'admin → 타인');
        $this->assertSame(200, $admin->patch("/api/v1/users/{$this->id['admin']}", ['display_name' => '관리자'])->status, 'admin → 본인');
        $this->assertSame(200, $admin->patch('/api/v1/users/me', ['email' => 'root@example.com'])->status, 'admin → 내 정보');
        $this->assertSame(404, $admin->patch('/api/v1/users/999999', ['display_name' => 'x'])->status, '없는 대상');

        $lee = $this->as('lee');
        $this->assertSame(200, $lee->patch('/api/v1/users/me', ['display_name' => '이본인'])->status, '일반 사용자 → 본인');
        $r = $lee->patch("/api/v1/users/{$this->id['park']}", ['display_name' => '해킹']);
        $this->assertSame(403, $r->status, '일반 사용자 → 타인');
        $kim = $this->as('kim');
        $r = $kim->patch("/api/v1/users/{$this->id['lee']}", ['display_name' => '관리자가바꿈']);
        $this->assertSame(403, $r->status, '그룹 관리자 → 구성원(명세 3.1)');
        $this->assertSame('이본인', $this->row('lee')['display_name']);
        $this->assertSame('park', $this->row('park')['display_name']);

        // 서비스 수준: 같은 함수를 다른 경로로 불러도 본인·admin만
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->users()->updateProfile($this->row('kim'), null, $this->id['lee'], ['display_name' => 'x']));
        $this->assertSame([403, 'user_profile_forbidden'], [$e->status, $e->errorCode]);

        $this->assertTrue($this->as_fails('off'), '비활성 사용자는 로그인 자체가 막힘');
    }

    private function as_fails(string $login): bool
    {
        return (new TestClient($this->app))->login($login, 'Passw0rd!x')->status !== 200;
    }

    public function testAuthCsrfAndForcedPasswordChange(): void
    {
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get('/api/v1/users/me')->status);
        // 상태 변경 요청은 전역 CSRF 검사가 라우트(로그인 확인)보다 먼저라 세션 없는 PATCH는 403 csrf_invalid(기존 순서, App::handle)
        $this->assertSame([403, 'csrf_invalid'], [$anon->patch('/api/v1/users/me', ['display_name' => 'x'])->status, $this->code($anon->patch('/api/v1/users/me', ['display_name' => 'x']))]);
        $lee = $this->as('lee');
        $r = $lee->request('PATCH', '/api/v1/users/me', [], ['display_name' => 'x'], null, [], false);
        $this->assertSame([403, 'csrf_invalid'], [$r->status, $this->code($r)]);
        $temp = $this->as('temp');
        $r = $temp->get('/api/v1/users/me');
        $this->assertSame([403, 'password_change_required'], [$r->status, $this->code($r)], '비밀번호 변경이 강제되면 내 정보도 못 씀');
        $this->assertSame(403, $temp->patch('/api/v1/users/me', ['display_name' => 'x'])->status);
        $this->assertTrue(in_array($temp->get('/account')->status, [302, 303], true), '화면은 비밀번호 변경으로 보냄');
        $this->assertSame('lee', $this->row('lee')['display_name']);
    }

    // ---- 검증 ----

    public function testValidationRules(): void
    {
        $lee = $this->as('lee');
        $cases = [
            [['display_name' => ''], 'display_name_required'],
            [['display_name' => "   \t "], 'display_name_required'],
            [['display_name' => str_repeat('가', 101)], 'display_name_too_long'],
            [['display_name' => "이\x07름"], 'display_name_invalid'],
            [['display_name' => "a\u{202E}nimda"], 'display_name_invalid'],
            [['display_name' => ['배열']], 'display_name_invalid'],
            [['display_name' => 'KIM'], 'display_name_reserved'],
            [['display_name' => 'park'], 'display_name_reserved'],
            [['email' => 'not-mail'], 'email_invalid'],
            [['email' => 'a@b'], 'email_invalid'],
            [['email' => 'a@@b.com'], 'email_invalid'],
            [['email' => 'a b@c.com'], 'email_invalid'],
            [['email' => '@c.com'], 'email_invalid'],
            [['email' => 'a@.com'], 'email_invalid'],
            [['email' => str_repeat('a', 250) . '@x.com'], 'email_too_long'],
        ];
        foreach ($cases as [$body, $code]) {
            $r = $lee->patch('/api/v1/users/me', $body);
            $this->assertSame([400, $code], [$r->status, $this->code($r)], json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(0, $this->auditCount(), '거부된 요청은 기록하지 않음');

        $ok = static fn ($r): array => $r->decoded()['data'];
        $this->assertSame(str_repeat('가', 100), $ok($lee->patch('/api/v1/users/me', ['display_name' => str_repeat('가', 100)]))['display_name'], '100자는 허용');
        $this->assertSame('lee', $ok($lee->patch('/api/v1/users/me', ['display_name' => ' lee ']))['display_name'], '본인 로그인 ID는 허용, 앞뒤 공백 제거');
        $this->assertSame("\u{D55C}\u{AE00}", $ok($lee->patch('/api/v1/users/me', ['display_name' => "\u{1112}\u{1161}\u{11AB}\u{1100}\u{1173}\u{11AF}"]))['display_name'], 'NFC 정규화');
        $this->assertSame('Lee.Dev@Example.COM', $ok($lee->patch('/api/v1/users/me', ['email' => ' Lee.Dev@Example.COM ']))['email'], '이메일 대소문자 그대로');
        $this->assertSame(null, $ok($lee->patch('/api/v1/users/me', ['email' => '']))['email'], '빈 이메일 → NULL');
        $this->assertSame(null, $this->row('lee')['email']);
        $this->assertSame('park', $ok($this->as('admin')->patch("/api/v1/users/{$this->id['park']}", ['email' => 'lee.dev@example.com']))['login_id'], '이메일 중복 허용');
    }

    public function testOnlyNameAndEmailChangeOtherFieldsIgnored(): void
    {
        $before = $this->row('lee');
        $evil = ['display_name' => '이개발', 'role' => 'admin', 'login_id' => 'root', 'status' => 'inactive', 'auth_type' => 'ldap',
            'password' => 'NewPassw0rd!x', 'password_hash' => 'x', 'must_change_password' => 1, 'id' => $this->id['park']];
        $this->assertSame(200, $this->as('lee')->patch('/api/v1/users/me', $evil)->status);
        $this->assertSame(200, $this->as('admin')->patch("/api/v1/users/{$this->id['lee']}", $evil + ['email' => 'x@y.zz'])->status);
        $after = $this->row('lee');
        foreach (['login_id', 'role', 'status', 'auth_type', 'password_hash', 'must_change_password'] as $k) {
            $this->assertSame($before[$k], $after[$k], "{$k} 불변");
        }
        $this->assertSame('이개발', $after['display_name']);
        $this->assertSame('park', $this->row('park')['display_name'], 'id를 넣어도 다른 사용자를 바꾸지 않음');
        $this->assertSame(200, $this->as('lee')->login('lee', 'Passw0rd!x')->status, '비밀번호 불변');
    }

    public function testUnchangedValuesAreNotSavedOrAudited(): void
    {
        $this->app->clock->advance(60);
        $before = $this->row('lee');
        $r = $this->as('lee')->patch('/api/v1/users/me', ['display_name' => 'lee', 'email' => '']);
        $this->assertSame(200, $r->status);
        $this->assertSame([0, $before['updated_at']], [$this->auditCount(), $this->row('lee')['updated_at']], '같은 값: 저장·감사 로그 없음');
        $this->as('lee')->patch('/api/v1/users/me', ['display_name' => '새이름']);
        $this->assertSame('2026-10-02T12:01:00Z', $this->row('lee')['updated_at'], 'updated_at 갱신');
    }

    /**
     * P28B(의도된 단언 변경, 사용자 결정 2026-10-10): 외부 인증 계정의 이름·이메일은 외부 값으로 갱신하지 않으므로 앱에서 고친다 —
     * X05의 ldap_managed(409, 읽기 전용)는 없앴다. 규칙(X05·X06 검증·감사 로그)은 로컬 계정과 같다.
     */
    public function testLdapAccountsAreEditableLikeLocalAccounts(): void
    {
        $r = $this->as('admin')->patch("/api/v1/users/{$this->id['ldapu']}", ['display_name' => '바꿈']);
        $this->assertSame(200, $r->status, $r->body);
        $this->app->users()->updateProfile($this->row('ldapu'), null, $this->id['ldapu'], ['email' => 'x@y.zz']);
        $this->assertSame(['바꿈', 'x@y.zz'], [$this->row('ldapu')['display_name'], $this->row('ldapu')['email']], 'LDAP 본인도');
        $bad = $this->as('admin')->patch("/api/v1/users/{$this->id['ldapu']}", ['display_name' => '']);
        $this->assertSame([400, 'display_name_required'], [$bad->status, $this->code($bad)], '같은 검증');
        // 화면: 읽기 전용 안내 없이 저장 버튼, 비밀번호는 디렉터리에서 바꾸라는 안내
        $user = \PJGuardian\Repositories\UserRepository::toPublic($this->row('ldapu'), '2026-10-02T12:00:00Z');
        $html = $this->app->view(TestApp::request('GET', '/account'))->page('account', ['title' => 't', 'user' => $user, 'active' => 'account'], ['me' => AccountController::me($user)]);
        $this->assertContains('data-readonly="0"', $html);
        $this->assertContains('id="account-save"', $html);
        $this->assertNotContains('readonly aria-readonly="true"', $html);
        $this->assertSame(true, AccountController::me($user)['editable']);
        $this->assertSame(2, $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.update' AND target_id = ?", [(string) $this->id['ldapu']]) === null ? 0 : (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.update' AND target_id = ?", [(string) $this->id['ldapu']]));
    }
    // ---- 감사 로그 ----

    public function testAuditLogMasksEmailAndKeepsChain(): void
    {
        $this->as('admin')->patch("/api/v1/users/{$this->id['lee']}", ['display_name' => '이개발', 'email' => 'lee.dev@example.com']);
        $this->as('lee')->patch('/api/v1/users/me', ['email' => 'second@corp.example']);
        $rows = $this->app->db()->all("SELECT actor_user_id, actor_label, target_id, details FROM audit_log WHERE action = 'user.update' ORDER BY id");
        $this->assertSame(2, count($rows));
        $d0 = json_decode((string) $rows[0]['details'], true);
        $this->assertSame([
            'actor_role' => 'admin', 'self' => false,
            'display_name' => ['from' => 'lee', 'to' => '이개발'],
            'email' => ['from' => null, 'to' => 'l***@example.com'],
        ], $d0);
        $d1 = json_decode((string) $rows[1]['details'], true);
        $this->assertSame(['actor_role' => 'user', 'self' => true, 'email' => ['from' => 'l***@example.com', 'to' => 's***@corp.example']], $d1);
        $this->assertSame([$this->id['lee'], 'lee', (string) $this->id['lee']], [(int) $rows[1]['actor_user_id'], $rows[1]['actor_label'], $rows[1]['target_id']], '본인 수정: 행위자 = 대상');
        $all = (string) $this->app->db()->value('SELECT group_concat(details) FROM audit_log');
        $this->assertNotContains('lee.dev@', $all, '감사 로그에 이메일 평문 없음');
        $this->assertNotContains('second@', $all);
        $this->assertTrue($this->app->audit()->verify()['ok'], '해시 체인 정상');
        $this->assertSame('k***@a.b', UserService::maskEmail('kim@a.b'));
        $this->assertSame('한***@x.kr', UserService::maskEmail('한글@x.kr'));
        $this->assertSame(null, UserService::maskEmail(null));
    }

    // ---- 노출 범위 ----

    public function testEmailIsNotExposedInSearchOrGroupMembers(): void
    {
        $admin = $this->as('admin');
        $admin->patch("/api/v1/users/{$this->id['lee']}", ['email' => 'secret.lee@example.com']);
        $kim = $this->as('kim'); // 그룹 관리자: 검색·구성원 조회 가능
        $search = $kim->get('/api/v1/users/search', ['q' => 'le']);
        $this->assertSame(200, $search->status, $search->body);
        $this->assertTrue(in_array('lee', array_column($search->decoded()['data'], 'login_id'), true));
        $this->assertNotContains('secret.lee', $search->body, '사용자 검색에 이메일 없음');
        $this->assertNotContains('"email"', $search->body);
        $gid = (int) $this->app->db()->value("SELECT id FROM groups WHERE name = 'team'");
        $members = $kim->get("/api/v1/groups/{$gid}/members");
        $this->assertSame(200, $members->status, $members->body);
        $this->assertNotContains('secret.lee', $members->body, '그룹 구성원에 이메일 없음');
        $this->assertNotContains('"email"', $members->body);
    }

    // ---- 내 정보 화면·상단 바 ----

    public function testMeApiAndAccountPageAndTopbar(): void
    {
        $lee = $this->as('lee');
        $me = $lee->get('/api/v1/users/me');
        $this->assertSame(200, $me->status);
        // P27D: 화면 언어·테마 4개를 더함(의도된 변경 — 비밀번호·잠금 값은 여전히 없음)
        $this->assertSame(['id', 'login_id', 'display_name', 'email', 'role', 'auth_type', 'editable', 'ui_language', 'ui_theme', 'effective_language', 'effective_theme'], array_keys($me->decoded()['data']));
        $this->assertNotContains('password', $me->body);
        $this->assertNotContains('locked', $me->body);

        $this->assertSame(200, $lee->patch('/api/v1/users/me', ['display_name' => '<b>x</b>'])->status);
        $page = $lee->get('/account');
        $this->assertSame(200, $page->status);
        $this->assertContains('&lt;b&gt;x&lt;/b&gt;', $page->body, '이름은 이스케이프해서 표시');
        $this->assertNotContains('<b>x</b>', $page->body);
        $this->assertContains('<span class="topbar__username">&lt;b&gt;x&lt;/b&gt;</span>', $page->body, '다음 요청부터 상단 바에 새 이름');
        $this->assertContains('class="topbar__account"', $page->body);
        $this->assertContains('aria-label="내 정보"', $page->body);
        $this->assertContains('aria-current="page"', $page->body, '내 정보 화면에서 링크가 현재 위치');
        $this->assertContains('id="account-save"', $page->body);
        $this->assertContains('href="/password"', $page->body, '기존 비밀번호 변경 화면으로 연결만');
        // 다른 화면의 상단 바에도 링크(새 메뉴 항목은 없음)
        $up = $lee->get('/upload');
        $this->assertContains('class="topbar__account" href="/account"', $up->body);
        $this->assertNotContains('data-nav="account"', $up->body);
    }
}
