<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\Response;
use PJGuardian\Support\FixedClock;
use PJGuardian\Tests\Support\FakeSamlIdp;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * SSO 로그인 SAML 2.0(P28D, 모의 IdP — 시험용 키로 서명한 응답, 실제 IdP에 연결하지 않음). 서명 검증은 라이브러리(onelogin/php-saml)이고
 * 이 시험은 그 설정과 앱의 추가 검사를 확인한다: 첫 로그인 → 승인 대기 → 승인 → 로그인 → 재로그인(외부 값이 달라도 이름·이메일 그대로),
 * 6절 공격 사례 전부, 브라우저 묶기(마무리 GET은 시작한 세션만), 세션 고정, 인증서 롤오버·만료 경고·알림, 미리 만든 계정 연결·충돌,
 * 속성 URI·FriendlyName·속성 식별자, 메타데이터 가져오기(XML·주소), SP 키 생성·요청 서명·암호화된 어서션, SP 메타데이터, 비밀·응답 원문이 로그·감사에 없음.
 * 라이브러리는 현재 시각(time())으로 시간을 보므로 앱 시계도 지금 시각으로 고정한다.
 */
final class SamlLoginTest extends TestCase
{
    private const SP = 'https://pjg.example.test/auth/saml/metadata';
    private const ACS = 'https://pjg.example.test/auth/saml/acs';

    private App $app;
    private FakeSamlIdp $idp;
    private string $dir;
    private FixedClock $clock;

    public function setUp(): void
    {
        $this->dir = $this->tempDir('saml');
        $this->idp = new FakeSamlIdp($this->dir . '/idp');
        $this->clock = new FixedClock(gmdate('Y-m-d\TH:i:s\Z', $this->idp->now));
        $this->app = $this->make();
        TestDb::user($this->app->db(), 'admin', 'admin');
        TestDb::user($this->app->db(), 'kim', 'user');
    }

    private function make(array $over = [], ?\PJGuardian\Support\Db $db = null): App
    {
        $base = TestApp::make($this->dir, array_replace_recursive([
            'saml' => ['enabled' => 'true', 'idp_entity_id' => $this->idp->entityId, 'idp_sso_url' => $this->idp->ssoUrl, 'idp_cert' => $this->idp->certB64('current'),
                'attr_login' => 'uid', 'attr_name' => 'displayName', 'attr_email' => 'mail',
                // 시험이 요청을 많이 보내므로 제한을 넉넉히(제한 동작은 testLoginButtons…에서 작은 값으로 따로 확인)
                'start_per_minute' => '600', 'test_per_minute' => '60'],
            'oidc' => ['redirect_base' => 'https://pjg.example.test'],
            'auth' => ['pending_max' => '10'],
            'paths' => ['work_dir' => $this->dir . '/work'],
        ], $over), ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        $app = new App(PJG_ROOT, $base->config, $this->clock, $base->logger, $base->paths, $db ?? ($this->app ?? null)?->db() ?? TestDb::migrated());
        $app->useOidcTransport($this->idp, static fn (string $h): array => $h === 'idp.example.test' ? ['203.0.113.10'] : ($h === 'internal.test' ? ['10.0.0.5'] : []));
        return $app;
    }

    /** @return array{id:string, relay:string, acs:string, issuer:string, signed:bool} */
    private function start(TestClient $c, array $query = []): array
    {
        $r = $c->get('/auth/saml/start', $query);
        $this->assertSame(302, $r->status, '시작 = 302');
        $loc = (string) $r->header('Location');
        $this->assertTrue(str_starts_with($loc, $this->idp->ssoUrl . '?SAMLRequest='), 'IdP SSO 주소로: ' . substr($loc, 0, 60));
        return FakeSamlIdp::parseRequest($loc);
    }

    private static function attrs(array $over = []): array
    {
        return $over + ['uid' => ['lee'], 'displayName' => ['Lee SAML'], 'mail' => ['lee@corp.test']];
    }

    /** IdP 응답을 ACS로 POST @param array<string, mixed> $o */
    private function post(TestClient $c, array $req, array $o = [], ?string $relay = null, ?string $raw = null): Response
    {
        $o += ['in_response_to' => $req['id'], 'acs' => self::ACS, 'audience' => self::SP, 'name_id' => 'nid-lee', 'attributes' => self::attrs()];
        $body = $raw ?? $this->idp->response($o);
        return $c->form('/auth/saml/acs', ['SAMLResponse' => $body, 'RelayState' => $relay ?? $req['relay']]);
    }

    /** 시작 → 응답 POST → (303이면) 마무리 GET */
    private function sso(TestClient $c, array $o = [], array $startQuery = []): Response
    {
        $req = $this->start($c, $startQuery);
        $r = $this->post($c, $req, $o);
        if ($r->status !== 303) {
            return $r;
        }
        $this->assertTrue(str_starts_with((string) $r->header('Location'), '/auth/saml/finish?t='), 'ACS → 마무리');
        parse_str((string) parse_url((string) $r->header('Location'), PHP_URL_QUERY), $q);
        return $c->get('/auth/saml/finish', ['t' => $q['t']]);
    }

    private function loc(Response $r): string
    {
        return (string) $r->header('Location');
    }

    private function user(string $login): ?array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE login_id = ?', [$login]);
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

    private function approve(string $login): void
    {
        $u = $this->user($login);
        $this->assertSame(200, $this->admin()->post("/api/v1/users/{$u['id']}/approve", ['role' => 'user', 'updated_at' => $u['updated_at']])->status);
    }

    public function testFirstLoginPendingApprovalLoginAndRelogin(): void
    {
        $c = new TestClient($this->app);
        $req = $this->start($c);
        $this->assertSame([self::ACS, self::SP, false], [$req['acs'], $req['issuer'], $req['signed']], 'AuthnRequest: ACS·SP EntityID, 서명 안 함(기본)');
        $this->assertTrue(str_starts_with($req['id'], 'ONELOGIN_') && strlen($req['id']) > 40 && strlen($req['relay']) === 32);
        $r = $this->post($c, $req);
        $this->assertSame(303, $r->status, 'ACS = 303(마무리로)');
        parse_str((string) parse_url($this->loc($r), PHP_URL_QUERY), $q);
        $r = $c->get('/auth/saml/finish', ['t' => $q['t']]);
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($r));
        $this->assertSame(401, $c->get('/api/v1/auth/me')->status, '세션 없음');
        $u = $this->user('lee');
        $this->assertSame(['saml', 'pending', 'inactive', $this->idp->entityId, 'nid-lee', 'Lee SAML', 'lee@corp.test', null],
            [$u['auth_type'], $u['approval_status'], $u['status'], $u['external_issuer'], $u['external_id'], $u['display_name'], $u['email'], $u['password_hash']]);
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE kind = 'account_pending'"));
        $this->assertSame('/login?sso_error=E-SSO-STATE', $this->loc($c->get('/auth/saml/finish', ['t' => $q['t']])), '마무리 토큰 재사용');
        $this->approve('lee');
        $c2 = new TestClient($this->app);
        $c2->get('/login');
        $before = $c2->sessionCookie();
        $r = $this->sso($c2, [], ['return' => '/projects/3']);
        $this->assertSame('/projects/3', $this->loc($r), '돌아갈 곳(서버에 저장한 값)');
        $this->assertTrue($c2->sessionCookie() !== null && $c2->sessionCookie() !== $before, '세션 ID 바뀜(세션 고정 방지)');
        $this->assertSame(['lee', 'saml'], [$c2->get('/api/v1/auth/me')->decoded()['data']['user']['login_id'], $c2->last->decoded()['data']['user']['auth_type']]);
        // 재로그인: IdP 값(이름·이메일·로그인 ID 속성)이 바뀌어도 (EntityID, NameID)로 같은 계정, 이름·이메일·updated_at 그대로
        $u = $this->user('lee');
        $r = $this->sso(new TestClient($this->app), ['attributes' => ['uid' => ['lee.renamed'], 'displayName' => ['Changed'], 'mail' => ['changed@corp.test']]]);
        $this->assertSame('/projects', $this->loc($r));
        $after = $this->user('lee');
        $this->assertSame([$u['display_name'], $u['email'], $u['updated_at']], [$after['display_name'], $after['email'], $after['updated_at']]);
        $this->assertSame(null, $this->user('lee.renamed'));
        // 비밀번호 폼으로는 saml 계정에 로그인되지 않음
        $this->assertSame(401, (new TestClient($this->app))->login('lee', 'x')->status);
    }

    public function testAttacksAreRejected(): void
    {
        $users = (int) $this->app->db()->value('SELECT COUNT(*) FROM users');
        $cases = [
            '서명 없음' => [['sign' => 'none'], 'E-SSO-TOKEN', 'saml_response.no_signature'],
            '서명 변조(서명 뒤 속성 값 바꿈)' => [['tamper' => true], 'E-SSO-TOKEN', 'saml_response.bad_signature'],
            '다른 키로 서명' => [['key' => 'other'], 'E-SSO-TOKEN', 'saml_response.bad_signature'],
            '서명 래핑(위조 어서션 + 서명된 어서션)' => [['wrap' => 'two'], 'E-SSO-TOKEN', 'saml_response.assertion_count'],
            '서명 래핑(서명된 어서션을 위조 어서션 안에)' => [['wrap' => 'nested'], 'E-SSO-TOKEN', 'saml_response.assertion_count'],
            'Destination 다름' => [['destination' => 'https://evil.example.test/acs'], 'E-SSO-TOKEN', 'saml_response.destination'],
            'Destination 앞부분만 같음' => [['destination' => self::ACS . 'x'], 'E-SSO-TOKEN', 'saml_response.destination'],
            'Recipient 다름' => [['recipient' => 'https://evil.example.test/acs'], 'E-SSO-TOKEN', 'saml_response.subject_confirmation'],
            'Audience 다름' => [['audience' => 'https://other-sp.example.test'], 'E-SSO-TOKEN', 'saml_response.audience'],
            'Issuer 다름' => [['issuer' => 'https://evil-idp.example.test'], 'E-SSO-TOKEN', 'saml_response.issuer'],
            '만료' => [['not_on_or_after' => -600, 'scd_not_on_or_after' => -600], 'E-SSO-TOKEN', 'saml_response.expired'],
            '만료(라이브러리 오차 180초 안, 앱 60초 밖)' => [['not_on_or_after' => -120], 'E-SSO-TOKEN', 'saml_response.expired'],
            '아직 유효하지 않음' => [['not_before' => 600], 'E-SSO-TOKEN', 'saml_response.not_yet_valid'],
            '세션 만료' => [['session_not_on_or_after' => -600], 'E-SSO-TOKEN', 'saml_response.expired'],
            'SHA-1 서명' => [['sha1' => true], 'E-SSO-TOKEN', 'saml_response.weak_algorithm'],
            'DOCTYPE·외부 엔티티(XXE)' => [['doctype' => true], 'E-SSO-TOKEN', 'saml_response.invalid_xml'],
            'IdP 오류 상태' => [['status' => 'Requester', 'sign' => 'none'], 'E-SSO-IDP', 'saml_response.idp_status'],
            'transient NameID' => [['name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient'], 'E-SSO-TOKEN', 'saml_response.transient_nameid'],
        ];
        foreach ($cases as $label => [$o, $code, $reason]) {
            $r = $this->sso(new TestClient($this->app), $o);
            $this->assertSame('/login?sso_error=' . $code, $this->loc($r), $label);
            $this->assertSame($reason, $this->lastFailReason(), $label);
        }
        $c = new TestClient($this->app);
        // IdP 시작(요청 ID 없는 응답) 거부
        $req = $this->start($c);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $this->loc($this->post($c, $req, ['in_response_to' => null])), 'IdP 시작');
        $this->assertSame('saml_unsolicited', $this->lastFailReason());
        // 서버가 발급하지 않은 요청 ID
        $this->assertSame('/login?sso_error=E-SSO-STATE', $this->loc($this->post($c, $this->start($c), ['in_response_to' => 'ONELOGIN_forged'])));
        $this->assertSame('saml_request_unknown', $this->lastFailReason());
        // InResponseTo(응답)는 맞고 SubjectConfirmationData만 다른 요청 — 라이브러리가 거부
        $req = $this->start($c);
        $raw = base64_encode(str_replace('" InResponseTo="' . $req['id'] . '"/>', '" InResponseTo="ONELOGIN_other"/>', base64_decode($this->idp->response(['in_response_to' => $req['id'], 'acs' => self::ACS, 'audience' => self::SP, 'attributes' => self::attrs(), 'sign' => 'response']))));
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->post($c, $req, [], null, $raw)), 'SCD InResponseTo 다름');
        // RelayState가 다르면(열린 리다이렉트 시도 포함) 거부 — 돌아갈 곳은 서버에 저장한 값만
        $req = $this->start($c, ['return' => '/projects/5']);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $this->loc($this->post($c, $req, [], '//evil.example.test/x')));
        $this->assertSame('saml_relay_mismatch', $this->lastFailReason());
        // 요청 ID 재사용(같은 요청으로 두 번) — 두 번째는 요청 기록이 없음
        $req = $this->start($c);
        $this->assertSame(303, $this->post($c, $req)->status);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $this->loc($this->post($c, $req)), '요청 ID 한 번만');
        // 어서션 ID 재사용(다른 요청에 같은 어서션 ID) — 리플레이
        $this->assertSame(303, $this->post($c, $this->start($c), ['assertion_id' => '_replay1'])->status);
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->post($c, $this->start($c), ['assertion_id' => '_replay1'])), '어서션 리플레이');
        $this->assertSame('saml_response.replay', $this->lastFailReason());
        // 잘못된 base64·너무 큰 응답·빈 응답
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->post($c, $this->start($c), [], null, '!!!not-base64!!!')));
        $this->assertSame('saml_response.bad_base64', $this->lastFailReason());
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->post($c, $this->start($c), [], null, base64_encode(str_repeat('a', 600000)))));
        $this->assertSame('saml_response.too_large', $this->lastFailReason());
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($c->form('/auth/saml/acs', ['RelayState' => 'x'])));
        // 로그인 CSRF: 다른 브라우저(공격자)가 시작한 로그인의 마무리 주소를 피해자 브라우저에서 열면 거부
        $attacker = new TestClient($this->app);
        $req = $this->start($attacker);
        $r = $this->post($attacker, $req);
        $victim = new TestClient($this->app);
        $victim->get('/login');
        parse_str((string) parse_url($this->loc($r), PHP_URL_QUERY), $q);
        $this->assertSame('/login?sso_error=E-SSO-STATE', $this->loc($victim->get('/auth/saml/finish', ['t' => $q['t']])), '다른 브라우저의 마무리');
        $this->assertSame(401, $victim->get('/api/v1/auth/me')->status);
        // 로그인 ID 속성 없음 → 계정 결정에서 거부
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['attributes' => ['displayName' => ['No Login']], 'name_id' => 'nid-x'])));
        $this->assertSame('invalid_login_id', $this->lastFailReason());
        // 공격으로 계정이 생기지 않음(정상 응답도 마무리 GET 전에는 계정을 만들지 않는다 — ACS는 검증·토큰만)
        $this->assertSame($users, (int) $this->app->db()->value('SELECT COUNT(*) FROM users'));
        // CSRF 예외는 ACS 하나뿐: 다른 POST는 토큰 없으면 403
        $this->assertSame(403, (new TestClient($this->app))->form('/login', ['login_id' => 'a', 'password' => 'b'])->status);
    }

    public function testCertificateRolloverExpiryWarningAndNotification(): void
    {
        $this->sso(new TestClient($this->app));
        $this->approve('lee');
        // 다음 인증서를 등록하지 않았으면 그 키로 서명한 응답은 거부
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->sso(new TestClient($this->app), ['key' => 'next'])));
        $roll = $this->make(['saml' => ['idp_cert_next' => $this->idp->certB64('next')]]);
        $this->assertSame('/projects', $this->loc($this->sso(new TestClient($roll), ['key' => 'next'])), '롤오버: 다음 인증서로 서명');
        $this->assertSame('/projects', $this->loc($this->sso(new TestClient($roll), ['key' => 'current'])), '롤오버: 현재 인증서도 그대로');
        $this->assertSame('/projects', $this->loc($this->sso(new TestClient($roll), ['sign' => 'both', 'key' => 'next'])), '응답·어서션 모두 서명');
        // 만료 임박(10일 남은 인증서): 점검 경고 + 관리자 알림(인증서마다 하루 한 건), 설정 상태에 남은 날
        $soon = FakeSamlIdp::makeKey($this->dir . '/idp/openssl-saml.cnf', 'Soon Expiring', 10);
        $soonB64 = (string) preg_replace('/-----[^-]+-----|\s+/', '', $soon['cert']);
        $exp = $this->make(['saml' => ['idp_cert' => $soonB64, 'idp_cert_next' => $this->idp->certB64('next')]]);
        $items = $exp->samlAdmin()->checkItems();
        $this->assertSame(['warn', 'saml_cert_expiring'], [$items[0]['status'], $items[0]['note']]);
        $this->assertTrue(in_array($items[0]['params']['days'], [9, 10], true));
        $this->assertSame(1, $exp->samlAdmin()->notifyCertExpiry(), '알림 1건(다음 인증서는 멀어서 없음)');
        $this->assertSame(0, $exp->samlAdmin()->notifyCertExpiry(), '같은 날 다시 = 없음(중복 방지)');
        $p = json_decode((string) $this->app->db()->value("SELECT params FROM notifications WHERE kind = 'saml_cert_expiring'"), true);
        $this->assertSame('current', $p['which']);
        $st = $exp->samlAdmin()->status();
        $this->assertSame(['current', 'next'], array_column($st['idp_certs'], 'which'));
        // 시간이 흘러 만료되면 점검 실패, 알림은 다음 날 다시
        $this->clock->advance(11 * 86400);
        $this->assertSame(['fail', 'saml_cert_expired'], [$exp->samlAdmin()->checkItems()[0]['status'], $exp->samlAdmin()->checkItems()[0]['note']]);
        $this->assertSame(1, $exp->samlAdmin()->notifyCertExpiry());
    }

    public function testPrecreatedLinkConflictsAttributeFormsAndIdentifierAttribute(): void
    {
        $a = $this->admin();
        $csv = "login_id,display_name,email,auth_type\npark,Park From CSV,park.csv@corp.test,saml\n";
        $p = $a->raw('POST', '/api/v1/admin/account-import/preview', $csv)->decoded()['data'];
        $this->assertSame(['ok', 1], [$p['rows'][0]['status'], $p['summary']['create']], 'SAML이 켜져 있으면 auth_type=saml 허용');
        $a->raw('POST', '/api/v1/admin/account-import/execute', $csv, [], true, ['digest' => $p['digest']]);
        $r = $this->sso(new TestClient($this->app), ['name_id' => 'nid-park', 'attributes' => ['uid' => ['park'], 'displayName' => ['Park IdP']]]);
        $this->assertSame('/projects', $this->loc($r), '미리 만든 계정 연결');
        $this->assertSame(['nid-park', 'Park From CSV'], [$this->user('park')['external_id'], $this->user('park')['display_name']]);
        // 같은 로그인 ID, 다른 NameID → 거부
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['name_id' => 'nid-other', 'attributes' => ['uid' => ['park']]])));
        $this->assertSame('login_id_conflict', $this->lastFailReason());
        // 로컬 kim과 겹침 → 거부, 이메일이 같아도 연결하지 않음
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['name_id' => 'nid-kim', 'attributes' => ['uid' => ['kim']]])));
        $this->assertSame(['local', null], [$this->user('kim')['auth_type'], $this->user('kim')['external_id']]);
        // 속성 이름이 URI 형식·FriendlyName, 식별자를 속성으로
        $uri = $this->make(['saml' => ['attr_login' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name', 'attr_name' => 'givenName',
            'id_source' => 'http://schemas.microsoft.com/identity/claims/objectidentifier', 'name_id_format' => 'transient']]);
        $r = $this->sso(new TestClient($uri), ['name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient', 'name_id' => 'changes-each-time', 'attributes' => [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name' => ['choi'], 'urn:oid:2.5.4.42' => ['Choi Given'],
            'http://schemas.microsoft.com/identity/claims/objectidentifier' => ['oid-choi-1'],
        ], 'friendly' => ['urn:oid:2.5.4.42' => 'givenName']]);
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($r), 'transient NameID여도 식별자가 속성이면 됨');
        $this->assertSame(['oid-choi-1', 'Choi Given'], [$this->user('choi')['external_id'], $this->user('choi')['display_name']]);
        // 식별자 속성이 없으면 거부
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->sso(new TestClient($uri), ['attributes' => ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name' => ['choi']]])));
        $this->assertSame('saml_response.no_identifier', $this->lastFailReason());
        // 거절·비활성
        $choi = $this->user('choi');
        $a->post("/api/v1/users/{$choi['id']}/reject", ['updated_at' => $choi['updated_at']]);
        $this->assertSame('/login?sso_notice=account_rejected', $this->loc($this->sso(new TestClient($uri), ['attributes' => [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name' => ['choi'], 'http://schemas.microsoft.com/identity/claims/objectidentifier' => ['oid-choi-1']]])));
        $this->assertSame(200, $a->put("/api/v1/users/{$this->user('park')['id']}/status", ['status' => 'inactive'])->status);
        $this->assertSame('/login?sso_error=E-SSO-DENIED', $this->loc($this->sso(new TestClient($this->app), ['name_id' => 'nid-park', 'attributes' => ['uid' => ['park']]])));
        // 인증 방식 전환에 saml
        $this->assertTrue(in_array('saml', $a->get('/api/v1/users')->decoded()['auth_types'] ?? [], true));
        $this->assertSame([200, 'saml'], [$a->put("/api/v1/users/{$this->user('kim')['id']}/auth-type", ['auth_type' => 'saml'])->status, $a->last->decoded()['data']['user']['auth_type']]);
    }

    public function testMetadataImportSpKeySignedRequestsEncryptionAndSpMetadata(): void
    {
        $a = $this->admin();
        // 메타데이터 가져오기(XML·주소) — 값만 돌려주고 저장하지 않음
        $r = $a->post('/api/v1/admin/auth/saml/metadata-import', ['xml' => $this->idp->metadataXml()])->decoded()['data'];
        $this->assertSame([$this->idp->entityId, $this->idp->ssoUrl, $this->idp->certB64('current'), $this->idp->certB64('next'), 2],
            [$r['idp_entity_id'], $r['idp_sso_url'], $r['idp_cert'], $r['idp_cert_next'], $r['certs']]);
        $r = $a->post('/api/v1/admin/auth/saml/metadata-import', ['url' => 'https://idp.example.test/saml/metadata'])->decoded()['data'];
        $this->assertSame($this->idp->entityId, $r['idp_entity_id']);
        $this->assertSame('', (string) $this->app->config->get('saml.idp_cert_next'), '저장은 하지 않음');
        foreach ([['xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///c:/windows/win.ini">]><x>&e;</x>'], ['xml' => '<not-metadata/>'], ['xml' => str_repeat('a', 1_100_000)]] as $i => $bad) {
            $this->assertSame([400, 'saml_metadata_invalid'], [$a->post('/api/v1/admin/auth/saml/metadata-import', $bad)->status, $a->last->decoded()['error']['code'] ?? null], "나쁜 메타데이터 #{$i}");
        }
        $this->assertSame(400, $a->post('/api/v1/admin/auth/saml/metadata-import', ['url' => 'http://idp.example.test/saml/metadata'])->status, 'http 주소');
        $this->assertSame([502, 'saml_metadata_unreachable'], [$a->post('/api/v1/admin/auth/saml/metadata-import', ['url' => 'https://internal.test/md'])->status, $a->last->decoded()['error']['code'] ?? null], '사설 주소(가져오는 주소 자신이 기준이라 허용) → 응답 없음');
        // SP 키 생성 — 개인 키는 응답·로그·감사·DB 평문에 없음
        $st = $a->post('/api/v1/admin/auth/saml/sp-key', [])->decoded()['data'];
        $this->assertSame('set', $st['sp_key']);
        $this->assertTrue(isset($st['sp_cert']['fingerprint']));
        $key = $this->app->appSecrets()->get('saml.sp_private_key');
        $cert = $this->app->appSecrets()->get('saml.sp_cert');
        $keyBody = substr((string) preg_replace('/-----[^-]+-----|\s+/', '', $key), 40, 60);
        $this->assertSame('{"replaced":false}', (string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'auth.saml_sp_key_generate'"));
        // SP 메타데이터(공개): EntityID·ACS·SP 인증서, 개인 키 없음
        $md = (new TestClient($this->app))->get('/auth/saml/metadata');
        $this->assertSame(200, $md->status);
        $this->assertTrue(str_contains($md->body, 'entityID="' . self::SP . '"') && str_contains($md->body, 'Location="' . self::ACS . '"'));
        $this->assertTrue(str_contains($md->body, substr((string) preg_replace('/-----[^-]+-----|\s+/', '', $cert), 0, 40)), 'SP 인증서');
        $this->assertSame('attachment; filename="pjguardian-sp-metadata.xml"', (new TestClient($this->app))->get('/auth/saml/metadata', ['download' => '1'])->header('Content-Disposition'));
        // 요청 서명
        $signed = $this->make(['saml' => ['sign_requests' => 'true']]);
        $this->assertTrue($this->start(new TestClient($signed))['signed'], 'AuthnRequest 서명(Signature·SigAlg)');
        // 암호화된 어서션 요구: 암호화 안 된 응답 거부, SP 인증서로 암호화한 응답 통과
        $enc = $this->make(['saml' => ['want_encrypted' => 'true']]);
        $this->assertSame('/login?sso_error=E-SSO-TOKEN', $this->loc($this->sso(new TestClient($enc))));
        $this->assertSame('saml_response.not_encrypted', $this->lastFailReason());
        $this->assertSame('/login?sso_notice=account_pending', $this->loc($this->sso(new TestClient($enc), ['encrypt_with' => $cert])), '암호화된 어서션');
        // 설정 확인 단계
        $t = $a->post('/api/v1/admin/auth/saml/test', [])->decoded()['data'];
        $this->assertSame([true, ['idp_cert', 'sso_url', 'sp', 'sp_metadata'], 400], [$t['ok'], array_column($t['steps'], 'step'), $t['steps'][1]['detail']['status']]);
        $items = array_column($a->get('/api/v1/admin/checks')->decoded()['data']['environment']['items'], null, 'id');
        $this->assertSame(['ok', 'saml_ok'], [$items['saml']['status'], $items['saml']['note']]);
        // 일반 사용자 403
        $kim = new TestClient($this->app);
        $kim->login('kim', 'Passw0rd!x');
        foreach ([['GET', '/api/v1/admin/auth/saml'], ['POST', '/api/v1/admin/auth/saml/sp-key'], ['POST', '/api/v1/admin/auth/saml/test'], ['POST', '/api/v1/admin/auth/saml/metadata-import']] as [$m, $path]) {
            $this->assertSame(403, $kim->request($m, $path, [], $m === 'GET' ? null : [])->status, "{$m} {$path}");
        }
        // 키·응답 원문이 로그·감사·API 응답에 없음
        $logs = '';
        foreach (glob($this->dir . '/*.log') ?: [] as $f) {
            $logs .= file_get_contents($f);
        }
        $audit = json_encode($this->app->db()->all('SELECT * FROM audit_log'));
        $resp = $a->get('/api/v1/admin/auth/saml')->body;
        foreach (['로그' => $logs, '감사' => $audit, '상태 API' => $resp, 'SP 메타데이터' => $md->body] as $where => $hay) {
            $this->assertFalse(str_contains($hay, $keyBody), "{$where}에 SP 개인 키");
            $this->assertFalse(str_contains($hay, 'PHNhbWxwOlJlc3BvbnNl') || str_contains($hay, 'samlp:Response'), "{$where}에 응답 원문");
            $this->assertFalse(str_contains($hay, 'lee@corp.test'), "{$where}에 속성 값");
        }
        $this->assertSame([200, 'none'], [$a->request('DELETE', '/api/v1/admin/auth/saml/sp-key')->status, $a->last->decoded()['data']['sp_key']]);
    }

    public function testLoginButtonsSettingsValidationAndMigrationGuard(): void
    {
        // OIDC와 SAML이 둘 다 켜지면 단추 둘
        $both = $this->make(['oidc' => ['enabled' => 'true', 'issuer' => 'https://idp.example.test', 'client_id' => 'pjg', 'client_auth' => 'none']]);
        $html = (new TestClient($both))->get('/login', ['next' => '/projects/2'])->body;
        $this->assertTrue(str_contains($html, 'data-sso-provider="oidc">SSO로 로그인</a>') && str_contains($html, 'href="/auth/saml/start?return=%2Fprojects%2F2" data-sso-provider="saml">SAML로 로그인</a>'));
        $off = $this->make(['saml' => ['enabled' => 'false']]);
        $this->assertFalse(str_contains((new TestClient($off))->get('/login')->body, 'data-sso-provider'));
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($off))->get('/auth/saml/start')));
        $this->assertSame('/login?sso_error=E-SSO-CONFIG', $this->loc((new TestClient($off))->form('/auth/saml/acs', ['SAMLResponse' => 'x'])));
        $this->assertFalse(in_array('saml', $off->authProviders()->enabledTypes(), true));
        // 로그인 시작 요청 제한(IP당 분당)·설정 확인 횟수 제한
        $lim = $this->make(['saml' => ['start_per_minute' => '2', 'test_per_minute' => '1'], 'paths' => ['work_dir' => $this->dir . '/work-lim']]);
        $c = new TestClient($lim, '198.51.100.30');
        $c->get('/auth/saml/start');
        $c->get('/auth/saml/start');
        $this->assertSame('/login?sso_error=E-SSO-RATE', $this->loc($c->get('/auth/saml/start')), '세 번째 = 제한');
        $this->assertSame(302, (new TestClient($lim, '198.51.100.31'))->get('/auth/saml/start')->status, '다른 IP는 영향 없음');
        $ad = new TestClient($lim);
        $ad->login('admin', 'Passw0rd!x');
        $ad->post('/api/v1/admin/auth/saml/test', []);
        $this->assertSame([429, 'saml_test_rate_limited'], [$ad->post('/api/v1/admin/auth/saml/test', [])->status, $ad->last->decoded()['error']['code'] ?? null]);
        // 설정 검사
        $ok = ['enabled' => true, 'idp_entity_id' => 'https://idp', 'idp_sso_url' => 'https://idp.example.test/sso', 'idp_cert' => $this->idp->certB64(), 'idp_cert_next' => '',
            'sp_entity_id' => '', 'name_id_format' => 'persistent', 'id_source' => 'nameid', 'attr_login' => 'uid', 'attr_name' => '', 'attr_email' => '', 'button_label' => ''];
        $this->assertSame([], \PJGuardian\Support\ConfigSchema::samlErrors($ok, 'https://pjg.example.test'));
        $bad = [
            'saml.idp_sso_url' => [['idp_sso_url' => 'http://idp/sso']],
            'saml.idp_cert' => [['idp_cert' => 'not-a-cert'], ['idp_cert' => base64_encode('garbage')]],
            'saml.idp_cert_next' => [['idp_cert_next' => '-----BEGIN CERTIFICATE-----']],
            'saml.attr_login' => [['attr_login' => 'has space']],
            'saml.id_source' => [['id_source' => '1bad']],
            'saml.name_id_format' => [['name_id_format' => 'transient']],
            'saml.button_label' => [['button_label' => str_repeat('가', 41)]],
            'saml.idp_entity_id' => [['idp_entity_id' => 'has space']],
            'saml.enabled' => [['attr_login' => ''], ['idp_cert' => '']],
        ];
        foreach ($bad as $key => $list) {
            foreach ($list as $i => $over) {
                $errs = \PJGuardian\Support\ConfigSchema::samlErrors($over + $ok, 'https://pjg.example.test');
                $this->assertTrue($errs !== [] && str_starts_with($errs[0], $key . ':'), "{$key} #{$i}: " . implode(' / ', $errs));
            }
        }
        $this->assertTrue(\PJGuardian\Support\ConfigSchema::samlErrors($ok, 'http://pjg.local') !== [], 'https 공개 주소 필요');
        // 027 전 DB: SAML은 꺼진 것처럼, 메타데이터 404
        $db = TestDb::migrated();
        $db->execute('DROP TABLE app_secrets');
        $db->execute("CREATE TABLE app_secrets (name TEXT PRIMARY KEY CHECK (name IN ('ldap.bind_password')), cipher TEXT NOT NULL, nonce TEXT NOT NULL, key_id TEXT NOT NULL,"
            . ' updated_at TEXT NOT NULL, updated_by INTEGER NULL) WITHOUT ROWID');
        $old = $this->make([], $db);
        $this->assertSame([false, []], [$old->samlEnabled(), $old->ssoProviders()]);
        $this->assertSame(404, (new TestClient($old))->get('/auth/saml/metadata')->status);
        $this->assertSame('saml_migration_required', $old->samlAdmin()->checkItems()[0]['note']);
    }
}
