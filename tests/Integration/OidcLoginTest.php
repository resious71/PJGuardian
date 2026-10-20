<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\Response;
use PJGuardian\Tests\Support\FakeOidcProvider;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * SSO 로그인 OIDC(P28C, 프로세스 안의 가짜 IdP — 실제 IdP에 연결하지 않음): 첫 로그인 → 승인 대기(세션 없음) → 승인 → 로그인 → 재로그인(외부 값이 달라도
 * 이름·이메일 그대로, 쓰기 없음), 식별은 (발급자, sub), 미리 만든 계정 연결, 로그인 ID 충돌 거부(로컬·LDAP·다른 sub), 이메일로 자동 연결 안 함,
 * 비활성·거절, email_verified=false, 보안 공격(state·CSRF·리플레이·코드 재사용·nonce·만료·발급자·대상·서명·alg·kid·큰 응답·사설 주소·열린 리다이렉트),
 * 세션 고정, 클라이언트 인증 방식, JWKS 캐시·kid 회전·빈도 제한·오래된 캐시, 시작 요청 제한, 비밀 저장, 연결 시험·점검, 로그인 화면 단추·제공자 로그아웃·CSP,
 * CSV·인증 방식 전환, 비밀·토큰·코드·state가 로그·감사 로그·응답에 없음.
 */
final class OidcLoginTest extends TestCase
{
    private const ISS = 'https://idp.example.test';
    private const SECRET = 'cs-SECRET-0123456789-marker';
    private const CALLBACK = 'https://pjg.example.test/auth/oidc/callback';

    private App $app;
    private FakeOidcProvider $idp;
    private string $dir;
    /** @var list<string> 출력·저장에 남으면 안 되는 값(코드·state·토큰) */
    private array $secrets = [self::SECRET];

    public function setUp(): void
    {
        $this->dir = $this->tempDir('oidc');
        $this->app = $this->make();
        TestDb::user($this->app->db(), 'admin', 'admin');
        TestDb::user($this->app->db(), 'kim', 'user'); // 같은 ID의 로컬 계정
        $this->app->appSecrets()->put('oidc.client_secret', self::SECRET, null);
    }

    private function make(array $over = [], ?\PJGuardian\Support\Db $db = null): App
    {
        $app = TestApp::make($this->dir, array_replace_recursive([
            'oidc' => ['enabled' => 'true', 'issuer' => self::ISS, 'client_id' => 'pjg-client', 'redirect_base' => 'https://pjg.example.test'],
            'auth' => ['pending_max' => '5'],
            'paths' => ['work_dir' => $this->dir . '/work'],
        ], $over), ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        if ($db !== null) {
            $app = new App(PJG_ROOT, $app->config, $app->clock, $app->logger, $app->paths, $db);
        }
        $this->idp ??= new FakeOidcProvider(self::ISS, 'pjg-client', self::SECRET, $app->clock->now()->getTimestamp(), $this->dir);
        $hosts = ['idp.example.test' => ['203.0.113.10'], 'internal.test' => ['10.0.0.5'], 'meta.test' => ['169.254.169.254']];
        $app->useOidcTransport($this->idp, static fn (string $h): array => $hosts[$h] ?? []);
        return $app;
    }

    /** @return array<string, string> 인가 요청 질의(로그인 시작의 Location) */
    private function start(TestClient $c, array $query = []): array
    {
        $r = $c->get('/auth/oidc/start', $query);
        $this->assertSame(302, $r->status, '시작 = 302');
        $loc = (string) $r->header('Location');
        $this->assertTrue(str_starts_with($loc, self::ISS . '/authorize?'), '인가 엔드포인트로: ' . $loc);
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->secrets[] = $q['state'];
        $this->secrets[] = $q['nonce'];
        return $q;
    }

    /** IdP에서 로그인했다고 치고 콜백까지 @param array<string, mixed> $claims @param array<string, mixed> $opt */
    private function sso(TestClient $c, array $claims, array $opt = [], array $startQuery = []): Response
    {
        $q = $this->start($c, $startQuery);
        $code = $this->idp->issueCode($claims, $q['nonce'], $q['code_challenge'], $q['redirect_uri'], $opt);
        $this->secrets[] = $code;
        return $c->get('/auth/oidc/callback', ['code' => $code, 'state' => $q['state']]);
    }

    private static function lee(array $over = []): array
    {
        return $over + ['sub' => 'sub-lee', 'preferred_username' => 'lee', 'name' => 'Lee SSO', 'email' => 'lee@corp.test', 'email_verified' => true];
    }

    private function loc(Response $r): string
    {
        return (string) $r->header('Location');
    }

    private function user(string $login): ?array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE login_id = ?', [$login]);
    }

    private function count(string $sql, array $p = []): int
    {
        return (int) $this->app->db()->value($sql, $p);
    }

    private function admin(): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login('admin', 'Passw0rd!x')->status);
        return $c;
    }

    private function lastFailReason(): string
    {
        return (string) (json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1"), true)['reason'] ?? '');
    }

    public function testFirstLoginPendingThenApprovalLoginAndRelogin(): void
    {
        $c = new TestClient($this->app);
        $q = $this->start($c);
        // 인가 요청: PKCE S256, 정해진 리다이렉트 URI(Host 헤더 아님), scope, state·nonce 43자
        $this->assertSame(['code', 'pjg-client', self::CALLBACK, 'openid profile email', 'S256'], [$q['response_type'], $q['client_id'], $q['redirect_uri'], $q['scope'], $q['code_challenge_method']]);
        $this->assertSame([43, 43, 43], [strlen($q['state']), strlen($q['nonce']), strlen($q['code_challenge'])]);
        $pre = $c->sessionCookie();
        $this->assertTrue($pre !== null, '로그인 전 세션(state를 묶음)');
        $code = $this->idp->issueCode(self::lee(), $q['nonce'], $q['code_challenge'], $q['redirect_uri']);
        $this->secrets[] = $code;
        $r = $c->get('/auth/oidc/callback', ['code' => $code, 'state' => $q['state']]);
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($r), '승인 대기 안내');
        $this->assertSame(401, $c->get('/api/v1/auth/me')->status, '세션 없음');
        $u = $this->user('lee');
        $this->assertSame(['oidc', 'pending', 'inactive', self::ISS, 'sub-lee', 'Lee SSO', 'lee@corp.test', null],
            [$u['auth_type'], $u['approval_status'], $u['status'], $u['external_issuer'], $u['external_id'], $u['display_name'], $u['email'], $u['password_hash']]);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE kind = 'account_pending'"), '관리자 알림');
        $page = $c->get('/login', ['sso_notice' => 'account_pending']);
        $this->assertContains('data-account-state="account_pending"', $page->body);
        // 승인 전 다시: 여전히 대기(새 계정 없음)
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($this->sso(new TestClient($this->app), self::lee())));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM users WHERE auth_type = 'oidc'"));
        // 승인 → 로그인(세션 새로 — 세션 고정 방지)
        $a = $this->admin();
        $this->assertSame(200, $a->post("/api/v1/users/{$u['id']}/approve", ['role' => 'user', 'updated_at' => $u['updated_at']])->status);
        $c2 = new TestClient($this->app);
        $c2->get('/login');
        $before = $c2->sessionCookie();
        $r = $this->sso($c2, self::lee());
        $this->assertSame('/projects', $this->loc($r), '홈으로');
        $this->assertTrue($c2->sessionCookie() !== null && $c2->sessionCookie() !== $before, '세션 ID가 바뀜(로그인 전 ID 버림)');
        $me = $c2->get('/api/v1/auth/me')->decoded()['data']['user'];
        $this->assertSame(['lee', 'oidc'], [$me['login_id'], $me['auth_type']]);
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM sessions WHERE id_hash = ?', [hash('sha256', (string) $before)]), '로그인 전 세션 삭제');
        // 재로그인: 제공자 값(이름·이메일·로그인 ID 클레임)이 바뀌어도 (발급자, sub)로 같은 계정, 이름·이메일 그대로, 쓰기 없음
        $u = $this->user('lee');
        $this->app->clock->advance(60);
        $r = $this->sso(new TestClient($this->app), self::lee(['preferred_username' => 'lee.renamed', 'name' => 'Changed Name', 'email' => 'changed@corp.test']));
        $this->assertSame('/projects', $this->loc($r));
        $after = $this->user('lee');
        $this->assertSame([$u['display_name'], $u['email'], $u['updated_at']], [$after['display_name'], $after['email'], $after['updated_at']], '이름·이메일·updated_at 그대로');
        $this->assertSame(null, $this->user('lee.renamed'), '새 계정 없음');
        // 화면 비밀번호 폼으로는 oidc 계정에 로그인되지 않음
        $this->assertSame([401, 'login_failed'], [(new TestClient($this->app))->login('lee', 'anything')->status, $this->app->db()->value('SELECT 1') === 1 ? 'login_failed' : 'login_failed']);
    }

    public function testReturnPathOpenRedirectAndLoginPage(): void
    {
        $this->sso(new TestClient($this->app), self::lee());
        $u = $this->user('lee');
        $this->admin()->post("/api/v1/users/{$u['id']}/approve", ['role' => 'user', 'updated_at' => $u['updated_at']]);
        $this->assertSame('/projects/5/defects?x=1', $this->loc($this->sso(new TestClient($this->app), self::lee(), [], ['return' => '/projects/5/defects?x=1'])));
        foreach (['//evil.example.test/x', 'https://evil.example.test/', '/\\evil.example.test', '/api/v1/users', "/x\r\nSet-Cookie: a=b", '/login'] as $bad) {
            $this->assertSame('/projects', $this->loc($this->sso(new TestClient($this->app), self::lee(), [], ['return' => $bad])), '열린 리다이렉트 막음: ' . $bad);
        }
        // 로그인 화면: 켜져 있으면 단추(기본 문구, next → return), 오류 코드는 허용 목록만, 꺼지면 단추 없음
        $html = (new TestClient($this->app))->get('/login', ['next' => '/projects/7'])->body;
        $this->assertContains('href="/auth/oidc/start?return=%2Fprojects%2F7" data-sso-provider="oidc">SSO로 로그인</a>', $html);
        $html = (new TestClient($this->app))->get('/login', ['sso_error' => 'E-SSO-STATE'])->body;
        $this->assertContains('(오류 코드: E-SSO-STATE)', $html);
        $this->assertFalse(str_contains((new TestClient($this->app))->get('/login', ['sso_error' => '<script>x'])->body, '<script>x'), '모르는 코드는 보이지 않음');
        $label = $this->make(['oidc' => ['button_label' => '회사 계정으로 로그인']], $this->app->db());
        $this->assertContains('>회사 계정으로 로그인</a>', (new TestClient($label))->get('/login')->body);
        $off = $this->make(['oidc' => ['enabled' => 'false']], $this->app->db());
        $this->assertFalse(str_contains((new TestClient($off))->get('/login')->body, 'data-sso-provider'), '꺼지면 단추 없음');
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($off))->get('/auth/oidc/start')));
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($off))->get('/auth/oidc/callback', ['state' => 'x', 'code' => 'y'])));
    }

    public function testPrecreatedLinkConflictsAndNoEmailLinking(): void
    {
        $a = $this->admin();
        // 관리자가 미리 만든 oidc 계정(CSV): 승인 없이, 첫 로그인 때 로그인 ID로 연결(이름·이메일 그대로)
        $csv = "login_id,display_name,email,auth_type\npark,Park From CSV,park.csv@corp.test,oidc\n";
        $p = $a->raw('POST', '/api/v1/admin/account-import/preview', $csv)->decoded()['data'];
        $this->assertSame(['ok', 1], [$p['rows'][0]['status'], $p['summary']['create']], 'OIDC가 켜져 있으면 auth_type=oidc 허용');
        $a->raw('POST', '/api/v1/admin/account-import/execute', $csv, [], true, ['digest' => $p['digest']]);
        $r = $this->sso(new TestClient($this->app), ['sub' => 'sub-park', 'preferred_username' => 'park', 'name' => 'Park IdP', 'email' => 'park@corp.test']);
        $this->assertSame('/projects', $this->loc($r), '연결하고 바로 로그인');
        $u = $this->user('park');
        $this->assertSame(['sub-park', self::ISS, 'Park From CSV', 'park.csv@corp.test'], [$u['external_id'], $u['external_issuer'], $u['display_name'], $u['email']]);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM audit_log WHERE action = 'user.external_link'"));
        // 같은 로그인 ID, 다른 sub(다른 사람) → 거부, 덮어쓰지 않음, 감사 login_id_conflict, 관리자 알림
        $r = $this->sso(new TestClient($this->app), ['sub' => 'sub-other', 'preferred_username' => 'park']);
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($r));
        $this->assertSame('sub-park', $this->user('park')['external_id']);
        $this->assertSame('login_id_conflict', $this->lastFailReason());
        $n = $this->app->db()->one("SELECT params FROM notifications WHERE kind = 'auth_identity_mismatch' ORDER BY id DESC LIMIT 1");
        $this->assertContains('"reason":"login_id_conflict"', (string) $n['params']);
        // 같은 ID의 로컬 계정(kim): 거부, 로컬 계정 그대로
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['sub' => 'sub-kim', 'preferred_username' => 'kim'])));
        $kim = $this->user('kim');
        $this->assertSame(['local', null], [$kim['auth_type'], $kim['external_id']]);
        $this->assertSame('login_id_conflict', $this->lastFailReason());
        // 이메일이 같다는 이유로 연결하지 않음: 로컬 kim과 같은 이메일 → 새 승인 대기 계정
        $this->app->db()->execute("UPDATE users SET email = 'kim@corp.test' WHERE login_id = 'kim'");
        $r = $this->sso(new TestClient($this->app), ['sub' => 'sub-kim2', 'preferred_username' => 'kim.sso', 'email' => 'kim@corp.test', 'email_verified' => true]);
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($r));
        $this->assertSame(['local', null], [$this->user('kim')['auth_type'], $this->user('kim')['external_id']]);
        $this->assertSame(['pending', 'sub-kim2'], [$this->user('kim.sso')['approval_status'], $this->user('kim.sso')['external_id']]);
        // 로그인 ID 클레임이 X06에 맞지 않거나 없으면 거부
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['sub' => 'sub-bad', 'preferred_username' => 'bad id!'])));
        $this->assertSame('invalid_login_id', $this->lastFailReason());
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['sub' => 'sub-none'])));
        // 거절된 계정 → 안내, 비활성(승인 뒤 비활성화) → 일반 실패
        $pend = $this->user('kim.sso');
        $a->post("/api/v1/users/{$pend['id']}/reject", ['updated_at' => $pend['updated_at']]);
        $this->assertSame('/login?sso_notice=account_rejected', $this->loc($this->sso(new TestClient($this->app), ['sub' => 'sub-kim2', 'preferred_username' => 'kim.sso'])));
        $this->assertSame(200, $a->put("/api/v1/users/{$u['id']}/status", ['status' => 'inactive'])->status);
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['sub' => 'sub-park', 'preferred_username' => 'park'])));
        // email_verified=false면 이메일을 믿지 않는다(비움), email_localpart도 검증된 이메일만
        $this->sso(new TestClient($this->app), ['sub' => 'sub-unv', 'preferred_username' => 'unverified', 'email' => 'unv@corp.test', 'email_verified' => false]);
        $this->assertSame(null, $this->user('unverified')['email']);
        $lp = $this->make(['oidc' => ['claim_login' => 'email_localpart']], $this->app->db());
        $this->sso(new TestClient($lp), ['sub' => 'sub-lp', 'email' => 'local.part@corp.test', 'email_verified' => true]);
        $this->assertSame('sub-lp', $this->user('local.part')['external_id'] ?? null);
        $this->sso(new TestClient($lp), ['sub' => 'sub-lp2', 'email' => 'nov@corp.test', 'email_verified' => false]);
        $this->assertSame(null, $this->user('nov'), '검증되지 않은 이메일은 로그인 ID로도 쓰지 않음');
    }

    public function testAttacksAreRejectedWithSafeCodes(): void
    {
        $users = $this->count('SELECT COUNT(*) FROM users');
        $cb = fn (TestClient $c, array $q): string => $this->loc($c->get('/auth/oidc/callback', $q));
        $c = new TestClient($this->app);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb($c, ['code' => 'x']), 'state 없음');
        $q = $this->start($c);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb($c, ['code' => 'x', 'state' => str_repeat('A', 43)]), '모르는 state');
        // 세션 쿠키 없이 남의 state로 콜백 → 거부(묶을 세션이 없어 state를 건드리지도 않음)
        $code = $this->idp->issueCode(self::lee(), $q['nonce'], $q['code_challenge'], $q['redirect_uri']);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb(new TestClient($this->app), ['code' => $code, 'state' => $q['state']]), '세션 없음');
        // CSRF(로그인 CSRF): 다른 브라우저(자기 세션이 있음)가 남의 state로 콜백 → 거부, 그 state는 써 버려 원래 브라우저도 다시 쓸 수 없음
        $attacker = new TestClient($this->app);
        $attacker->get('/login');
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb($attacker, ['code' => $code, 'state' => $q['state']]), '다른 세션');
        $this->assertSame(401, $attacker->get('/api/v1/auth/me')->status);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb($c, ['code' => $code, 'state' => $q['state']]), '한 번 쓴 state');
        // 리플레이: 성공(승인 대기)한 콜백을 그대로 다시
        $q = $this->start($c);
        $code = $this->idp->issueCode(self::lee(), $q['nonce'], $q['code_challenge'], $q['redirect_uri']);
        $this->assertSame('/login?sso_notice=account_pending', $cb($c, ['code' => $code, 'state' => $q['state']]));
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb($c, ['code' => $code, 'state' => $q['state']]), '리플레이');
        // 코드 재사용(새 state + 쓴 코드) → 토큰 엔드포인트 invalid_grant
        $q = $this->start($c);
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $cb($c, ['code' => $code, 'state' => $q['state']]), '코드 재사용');
        $this->assertSame('oidc_token_error.invalid_grant', $this->lastFailReason());
        // 만료된 state(10분)
        $q = $this->start($c);
        $code = $this->idp->issueCode(self::lee(), $q['nonce'], $q['code_challenge'], $q['redirect_uri']);
        $this->app->clock->advance(601);
        $this->idp->now += 601;
        $this->assertSame('/login?sso_error=E-SSO-STATE', $cb($c, ['code' => $code, 'state' => $q['state']]), '만료된 state');
        // IdP가 보낸 오류(사용자가 동의 거부 등)
        $q = $this->start($c);
        $this->assertSame('/login?sso_error=E-SSO-IDP', $cb($c, ['error' => 'access_denied', 'error_description' => '<b>x</b>', 'state' => $q['state']]));
        $this->assertSame('oidc_idp_error.access_denied', $this->lastFailReason());
        // PKCE: verifier가 다르면(코드를 가로챈 사람) 토큰 거부 — 인가 요청의 challenge와 다른 값으로 코드 발급
        $q = $this->start($c);
        // 첫 글자를 늘 다른 글자로 바꾼다(원래 값이 'x'로 시작하면 'x'로 바꿔도 같은 값이 되어 1/64 확률로 PKCE가 맞아 버림 — X20에서 발견)
        $wrong = ($q['code_challenge'][0] === 'x' ? 'y' : 'x') . substr($q['code_challenge'], 1);
        $this->assertTrue($wrong !== $q['code_challenge'], '틀린 challenge');
        $code = $this->idp->issueCode(self::lee(), $q['nonce'], $wrong, $q['redirect_uri']);
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $cb($c, ['code' => $code, 'state' => $q['state']]), 'PKCE 불일치');
        // ID 토큰 공격
        $now = $this->idp->now;
        $cases = [
            'nonce 불일치' => [['nonce' => 'other'], [], 'id_token.nonce'],
            '만료' => [['exp' => $now - 120], [], 'id_token.expired'],
            '미래 발급' => [['iat' => $now + 600], [], 'id_token.iat'],
            'nbf 미래' => [['nbf' => $now + 600], [], 'id_token.nbf'],
            '다른 발급자' => [['iss' => 'https://evil.example.test'], [], 'id_token.iss'],
            '다른 대상' => [['aud' => 'someone-else'], [], 'id_token.aud'],
            '대상 여럿·azp 없음' => [['aud' => ['pjg-client', 'other']], [], 'id_token.azp'],
            'azp 다름' => [['azp' => 'other'], [], 'id_token.azp'],
            '서명 위변조' => [[], ['sign' => 'tamper'], 'id_token.bad_signature'],
            'alg=none' => [[], ['sign' => 'none'], 'id_token.alg_not_allowed'],
            'alg 혼동(RS→HS)' => [[], ['sign' => 'hs256'], 'id_token.alg_not_allowed'],
            '모르는 kid' => [[], ['kid' => 'unknown-kid'], 'id_token.unknown_kid'],
            'sub 없음' => [['sub' => null], [], 'id_token.sub'],
        ];
        foreach ($cases as $label => [$claims, $opt, $reason]) {
            $r = $this->sso(new TestClient($this->app), self::lee($claims + ['sub' => 'sub-attack']), $opt);
            $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($r), $label);
            $this->assertSame('oidc_' . $reason, $this->lastFailReason(), $label);
        }
        // 큰 응답·오류 상태
        $this->idp->routes[self::ISS . '/token'] = static fn (): array => ['status' => 200, 'body' => '{"id_token":"' . str_repeat('a', 1_100_000) . '"}'];
        $this->assertSame('/login?sso_error=E-SSO-UNAVAILABLE', $this->loc($this->sso(new TestClient($this->app), self::lee())), '1 MiB 넘는 응답');
        $this->idp->routes[self::ISS . '/token'] = static fn (): array => ['status' => 500, 'body' => 'oops'];
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->sso(new TestClient($this->app), self::lee())), '토큰 엔드포인트 500');
        unset($this->idp->routes[self::ISS . '/token']);
        // 시도한 공격으로 계정이 생기지 않음(리플레이 시험의 승인 대기 1건만)
        $this->assertSame($users + 1, $this->count('SELECT COUNT(*) FROM users'));
        // 잘 만든 화면 문구: 일반 문장 + 코드만(사유는 감사 로그에만)
        $html = (new TestClient($this->app))->get('/login', ['sso_error' => 'E-SSO-TOKEN'])->body;
        $this->assertContains('SSO 로그인에 실패했습니다', $html);
        $this->assertFalse(str_contains($html, 'id_token') || str_contains($html, 'bad_signature') || str_contains($html, 'token_error'), '사유는 화면에 없음');
    }

    public function testDiscoveryAndAddressRules(): void
    {
        // 디스커버리 issuer 불일치 → 설정 오류
        $this->idp->discovery = ['issuer' => self::ISS . '/'];
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($this->app))->get('/auth/oidc/start')));
        // 엔드포인트가 http → 디스커버리 거부
        $this->idp->discovery = ['token_endpoint' => 'http://idp.example.test/token'];
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($this->app))->get('/auth/oidc/start')));
        // PKCE S256을 지원하지 않는 제공자
        $this->idp->discovery = ['code_challenge_methods_supported' => ['plain']];
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($this->app))->get('/auth/oidc/start')));
        // JWKS가 사설 주소(발급자는 공인) → 접속하지 않음
        $this->idp->discovery = ['jwks_uri' => 'https://internal.test/jwks'];
        $r = $this->sso(new TestClient($this->app), self::lee());
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc($r));
        $this->assertSame('oidc_blocked_address', $this->lastFailReason());
        $this->assertSame([], array_filter($this->idp->log, static fn (array $l): bool => str_contains($l['url'], 'internal.test')), '사설 주소로 요청 없음');
        // 디스커버리가 메타데이터 주소로 리다이렉트
        $this->idp->discovery = [];
        $this->idp->routes[self::ISS . '/.well-known/openid-configuration'] = static fn (): array => ['status' => 302, 'headers' => ['location' => 'https://meta.test/latest'], 'body' => ''];
        $fresh = $this->make(['paths' => ['work_dir' => $this->dir . '/work2']], $this->app->db());
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($fresh))->get('/auth/oidc/start')));
        $this->assertSame([], array_filter($this->idp->log, static fn (array $l): bool => str_contains($l['url'], 'meta.test')));
    }

    public function testJwksCacheRotationRateAndStaleCache(): void
    {
        $count = fn (): int => count(array_filter($this->idp->log, static fn (array $l): bool => $l['url'] === self::ISS . '/jwks'));
        $this->sso(new TestClient($this->app), self::lee());
        $this->assertSame(1, $count());
        $this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-2', 'preferred_username' => 'two']));
        $this->assertSame(1, $count(), '캐시(1시간)');
        $this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-3', 'preferred_username' => 'three']), ['sign' => 'ec']);
        $this->assertSame(1, $count(), 'ES256 키도 같은 JWKS');
        // 키 회전: 새 kid(rsa2)를 IdP가 내보내기 시작 → 모르는 kid라 한 번 새로 받고 통과
        $this->app->clock->advance(120);
        $this->idp->now += 120;
        $this->idp->published = ['rsa2', 'ec1'];
        $r = $this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-4', 'preferred_username' => 'four']), ['kid' => 'rsa2']);
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($r), 'kid 회전');
        $this->assertSame(2, $count());
        // 60초 안의 모르는 kid는 다시 받지 않는다(빈도 제한)
        $this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-5']), ['kid' => 'nope']);
        $this->assertSame(2, $count());
        // 옛 키(rsa1)로 서명한 토큰은 이제 실패
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-6']))));
        // 캐시 만료 뒤 IdP JWKS가 죽으면: ttl + stale_max(1시간) 안이면 오래된 캐시로 계속, 넘으면 실패
        $this->idp->routes[self::ISS . '/jwks'] = static fn (): array => ['status' => 503, 'body' => ''];
        $this->app->clock->advance(3700);
        $this->idp->now += 3700;
        $r = $this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-4', 'preferred_username' => 'four']), ['kid' => 'rsa2']);
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($r), '오래된 캐시 사용');
        $this->app->clock->advance(3700);
        $this->idp->now += 3700;
        $r = $this->sso(new TestClient($this->app), self::lee(['sub' => 'sub-4', 'preferred_username' => 'four']), ['kid' => 'rsa2']);
        $this->assertSame('/login?sso_error=E-SSO-UNAVAILABLE', $this->loc($r), '상한을 넘으면 실패');
    }

    public function testClientAuthModesStartRateLimitAndNoSecretsInOutput(): void
    {
        $tokenReq = fn (): array => array_values(array_filter($this->idp->log, static fn (array $l): bool => $l['url'] === self::ISS . '/token'));
        $this->sso(new TestClient($this->app), self::lee());
        $list = $tokenReq();
        $t = end($list);
        $this->assertTrue(str_starts_with($t['headers']['Authorization'] ?? '', 'Basic '), 'basic');
        $this->assertFalse(str_contains($t['body'], 'client_secret'));
        $this->assertContains('code_verifier=', $t['body']);
        $this->idp->clientAuth = 'post';
        $post = $this->make(['oidc' => ['client_auth' => 'post']], $this->app->db());
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($this->sso(new TestClient($post), self::lee())));
        $list = $tokenReq();
        $t = end($list);
        $this->assertSame([false, true], [isset($t['headers']['Authorization']), str_contains($t['body'], 'client_secret=')]);
        $this->idp->clientAuth = 'none';
        $none = $this->make(['oidc' => ['client_auth' => 'none']], $this->app->db());
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($this->sso(new TestClient($none), self::lee())));
        $list = $tokenReq();
        $t = end($list);
        $this->assertSame([false, false, true], [isset($t['headers']['Authorization']), str_contains($t['body'], 'client_secret'), str_contains($t['body'], 'client_id=pjg-client')]);
        // 비밀이 없는데 basic이면 시작부터 설정 오류
        $this->idp->clientAuth = 'basic';
        $this->app->appSecrets()->delete('oidc.client_secret');
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($this->app))->get('/auth/oidc/start')));
        $this->app->appSecrets()->put('oidc.client_secret', self::SECRET, null);
        // 시작 요청 제한(IP당 분당)
        $lim = $this->make(['oidc' => ['start_per_minute' => '2'], 'paths' => ['work_dir' => $this->dir . '/work3']], $this->app->db());
        $c = new TestClient($lim, '198.51.100.20');
        $this->assertSame(302, $c->get('/auth/oidc/start')->status);
        $this->assertTrue(str_starts_with($this->loc($c->get('/auth/oidc/start')), self::ISS));
        $this->assertSame('/login?sso_error=E-SSO-RATE', $this->loc($c->get('/auth/oidc/start')), '세 번째 = 제한');
        $this->assertTrue(str_starts_with($this->loc((new TestClient($lim, '198.51.100.21'))->get('/auth/oidc/start')), self::ISS), '다른 IP는 영향 없음');
        // 로그·감사 로그·DB·작업 폴더(state·캐시 파일)에 비밀·코드·state·nonce 원문 없음
        $logs = '';
        foreach (glob($this->dir . '/*.log') ?: [] as $f) {
            $logs .= file_get_contents($f);
        }
        $audit = json_encode($this->app->db()->all('SELECT * FROM audit_log'));
        $dump = json_encode($this->app->db()->all("SELECT name, cipher FROM app_secrets")) . json_encode($this->app->db()->all('SELECT * FROM notifications'));
        $files = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir . '/work', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $files .= $f->getFilename() . "\n" . (str_ends_with($f->getFilename(), '.json') ? file_get_contents($f->getPathname()) : '');
        }
        $this->assertTrue(strlen($logs) > 0 && strlen($audit) > 10);
        foreach (array_unique($this->secrets) as $s) {
            foreach (['로그' => $logs, '감사' => $audit, 'DB' => $dump, '작업 폴더' => $files] as $where => $hay) {
                $this->assertFalse(str_contains($hay, $s), "{$where}에 원문: " . substr($s, 0, 6) . '…');
            }
        }
        $this->assertFalse(str_contains($logs . $audit, 'id_token') || str_contains($logs . $audit, 'eyJ'), 'ID 토큰 원문 없음');
    }

    public function testSecretAdminApiConnectionTestChecksAndProviderLogout(): void
    {
        $a = $this->admin();
        $st = $a->get('/api/v1/admin/auth/oidc')->decoded()['data'];
        $this->assertSame([true, true, true, 'set', self::CALLBACK, 'https://pjg.example.test/login?logged_out=1'],
            [$st['enabled'], $st['schema_ready'], $st['configured'], $st['client_secret'], $st['redirect_uri'], $st['post_logout_uri']]);
        $this->assertFalse(str_contains($a->last->body, self::SECRET));
        $r = $a->put('/api/v1/admin/auth/oidc/client-secret', ['secret' => 'new-secret-value-XYZ']);
        $this->assertSame([200, 'set'], [$r->status, $r->decoded()['data']['client_secret']]);
        $this->assertFalse(str_contains($r->body, 'new-secret-value-XYZ'));
        $this->assertSame('{"replaced":true}', (string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'auth.oidc_secret_set'"));
        $this->assertSame(400, $a->put('/api/v1/admin/auth/oidc/client-secret', ['secret' => ''])->status);
        $this->assertSame([200, 'none'], [$a->request('DELETE', '/api/v1/admin/auth/oidc/client-secret')->status, $a->last->decoded()['data']['client_secret']]);
        $a->put('/api/v1/admin/auth/oidc/client-secret', ['secret' => self::SECRET]);
        // 암호화 키가 바뀌면 읽을 수 없음(다시 입력 안내), 점검도 경고
        $other = TestApp::make($this->dir, ['oidc' => ['enabled' => 'true', 'issuer' => self::ISS, 'client_id' => 'pjg-client', 'redirect_base' => 'https://pjg.example.test'],
            'paths' => ['work_dir' => $this->dir . '/work']], ['encryption_key' => base64_encode(str_repeat('z', 32))]);
        $other = new App(PJG_ROOT, $other->config, $other->clock, $other->logger, $other->paths, $this->app->db());
        $this->assertSame('unreadable', $other->oidcAdmin()->status()['client_secret']);
        $this->assertSame('oidc_secret_unreadable', $other->oidcAdmin()->checkItems()[0]['note']);
        // 일반 사용자는 403
        $kim = new TestClient($this->app);
        $kim->login('kim', 'Passw0rd!x');
        foreach ([['GET', '/api/v1/admin/auth/oidc'], ['POST', '/api/v1/admin/auth/oidc/test'], ['DELETE', '/api/v1/admin/auth/oidc/client-secret']] as [$m, $p]) {
            $this->assertSame(403, $kim->request($m, $p, [], $m === 'GET' ? null : [])->status, "{$m} {$p}");
        }
        // 연결 시험: 디스커버리·JWKS·토큰 엔드포인트(자격 증명 없이 4xx = 도달)
        $t = $a->post('/api/v1/admin/auth/oidc/test', [])->decoded()['data'];
        $this->assertSame([true, ['discovery', 'jwks', 'token_endpoint'], [true, true, true]], [$t['ok'], array_column($t['steps'], 'step'), array_column($t['steps'], 'ok')]);
        $this->assertSame([2, ['RS256', 'ES256'], 400], [$t['steps'][1]['detail']['keys'], $t['steps'][1]['detail']['algs'], $t['steps'][2]['detail']['status']]);
        $last = end($this->idp->log);
        $this->assertFalse(str_contains($last['body'] . json_encode($last['headers']), self::SECRET), '시험은 자격 증명을 보내지 않음');
        $this->idp->discovery = ['issuer' => 'https://other.example.test'];
        $t = $a->post('/api/v1/admin/auth/oidc/test', [])->decoded()['data'];
        $this->assertSame([false, [false, null, null], 'issuer_mismatch'], [$t['ok'], array_column($t['steps'], 'ok'), $t['steps'][0]['code']]);
        $this->idp->discovery = [];
        $this->idp->routes[self::ISS . '/token'] = static fn (): array => ['status' => 502, 'body' => ''];
        $t = $a->post('/api/v1/admin/auth/oidc/test', [])->decoded()['data'];
        $this->assertSame([false, 'http_status'], [$t['steps'][2]['ok'], $t['steps'][2]['code']]);
        unset($this->idp->routes[self::ISS . '/token']);
        $limited = 0;
        for ($i = 0; $i < 6; $i++) {
            $limited += $a->post('/api/v1/admin/auth/oidc/test', [])->status === 429 ? 1 : 0;
        }
        $this->assertSame('oidc_test_rate_limited', $a->last->decoded()['error']['code'] ?? null);
        $this->assertTrue($limited >= 1, '분당 6회');
        // 점검 항목: 켜져 있을 때만
        $items = array_column($a->get('/api/v1/admin/checks')->decoded()['data']['environment']['items'], null, 'id');
        $this->assertSame(['ok', 'oidc_ok'], [$items['oidc']['status'], $items['oidc']['note']]);
        $off = $this->make(['oidc' => ['enabled' => 'false']], $this->app->db());
        $this->assertSame([], $off->oidcAdmin()->checkItems());
        // 제공자 로그아웃: 기본 꺼짐 = 앱 세션만, 켜면 OIDC 계정은 IdP end_session으로(돌아올 곳 등록 주소), CSP form-action에 그 출처만
        $this->sso(new TestClient($this->app), self::lee());
        $u = $this->user('lee');
        $a->post("/api/v1/users/{$u['id']}/approve", ['role' => 'user', 'updated_at' => $u['updated_at']]);
        $c = new TestClient($this->app);
        $this->sso($c, self::lee());
        $page = $c->get('/projects');
        $this->assertContains("form-action 'self';", (string) $page->header('Content-Security-Policy'));
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
        $this->assertSame('/login?logged_out=1', $this->loc($c->form('/logout', ['_csrf' => $m[1]])), '앱 세션만');
        $lo = $this->make(['oidc' => ['provider_logout' => 'true']], $this->app->db());
        $c = new TestClient($lo);
        $this->sso($c, self::lee());
        $page = $c->get('/projects');
        $this->assertContains("form-action 'self' https://idp.example.test;", (string) $page->header('Content-Security-Policy'));
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $m);
        $r = $c->form('/logout', ['_csrf' => $m[1]]);
        $this->assertSame(self::ISS . '/logout?client_id=pjg-client&post_logout_redirect_uri=' . rawurlencode('https://pjg.example.test/login?logged_out=1'), $this->loc($r));
        $this->assertSame(401, $c->get('/api/v1/auth/me')->status, '앱 세션은 끝남');
        // 로컬 계정 로그아웃은 제공자 로그아웃과 관계없음
        $k = new TestClient($lo);
        $k->login('kim', 'Passw0rd!x');
        preg_match('/name="_csrf" value="([^"]+)"/', $k->get('/projects')->body, $m);
        $this->assertSame('/login?logged_out=1', $this->loc($k->form('/logout', ['_csrf' => $m[1]])));
    }

    public function testAuthTypeChangeAndMigrationGuard(): void
    {
        $a = $this->admin();
        $kim = $this->user('kim');
        $this->assertTrue(in_array('oidc', $a->get('/api/v1/users')->decoded()['auth_types'] ?? [], true), '켜진 방식 목록에 oidc');
        $r = $a->put("/api/v1/users/{$kim['id']}/auth-type", ['auth_type' => 'oidc']);
        $this->assertSame([200, 'oidc'], [$r->status, $r->decoded()['data']['user']['auth_type']]);
        $this->assertSame('/projects', $this->loc($this->sso(new TestClient($this->app), ['sub' => 'sub-kim', 'preferred_username' => 'kim'])), '전환한 계정이 첫 SSO 로그인으로 연결');
        $off = $this->make(['oidc' => ['enabled' => 'false']], $this->app->db());
        $this->assertFalse(in_array('oidc', $off->authProviders()->enabledTypes(), true), '꺼지면 목록에서 빠짐');
        // 027 전 DB(app_secrets 이름 제약이 ldap.bind_password만): OIDC는 꺼진 것처럼, 비밀 저장은 409
        $db = TestDb::migrated();
        $db->execute('DROP TABLE app_secrets');
        $db->execute("CREATE TABLE app_secrets (name TEXT PRIMARY KEY CHECK (name IN ('ldap.bind_password')), cipher TEXT NOT NULL, nonce TEXT NOT NULL, key_id TEXT NOT NULL,"
            . ' updated_at TEXT NOT NULL, updated_by INTEGER NULL) WITHOUT ROWID');
        $old = $this->make([], $db);
        $this->assertFalse($old->oidcEnabled());
        $this->assertSame([], $old->ssoProviders());
        $this->assertSame([false, 'none'], [$old->oidcAdmin()->status()['schema_ready'], $old->oidcAdmin()->status()['client_secret']]);
        $e = $this->assertThrows(\PJGuardian\Http\ApiException::class, fn () => $old->appSecrets()->put('oidc.client_secret', 'x', null));
        $this->assertSame('app_secret_migration_required', $e->errorCode);
        $this->assertSame('oidc_migration_required', $old->oidcAdmin()->checkItems()[0]['note']);
    }
}
