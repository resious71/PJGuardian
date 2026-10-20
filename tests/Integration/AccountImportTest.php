<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Auth\AuthProviderRegistry;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 계정 CSV 일괄 생성·비활성화(P28A): 접근(admin만·CSRF), 미리보기(저장 없음·행별 판정), 실행(digest 확인·전부 또는 하나도·
 * 미리보기 뒤 충돌 → 아무것도 반영 안 함·오류 행 제외 명시), 임시 비밀번호(24시간·첫 로그인 변경 강제·응답에 한 번),
 * 비활성화 세션 즉시 폐기, 감사 로그(bulk_import + 사용자별 source csv)와 로그에 비밀번호·이메일·CSV 원문 없음, 마지막 관리자 보호.
 */
final class AccountImportTest extends TestCase
{
    private App $app;
    private string $logDir;
    private int $adminId;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('acctcsv');
        $this->app = TestApp::make($this->logDir);
        $db = $this->app->db();
        $this->adminId = TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        TestDb::user($db, 'old.user', 'user');
        TestDb::user($db, 'gone', 'user', 'inactive');
        $db->execute("UPDATE users SET email = 'kim@example.com' WHERE login_id = 'kim'");
    }

    private function as(string $login, string $password = 'Passw0rd!x'): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, $password)->status, $login);
        return $c;
    }

    /** @return array<string, mixed> 미리보기 data */
    private function preview(TestClient $c, string $csv, string $encoding = 'auto'): array
    {
        $r = $c->raw('POST', '/api/v1/admin/account-import/preview', $csv, [], true, ['encoding' => $encoding]);
        $this->assertSame(200, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    private function execute(TestClient $c, string $csv, string $digest, bool $skip = false, string $encoding = 'auto'): \PJGuardian\Http\Response
    {
        return $c->raw('POST', '/api/v1/admin/account-import/execute', $csv, [], true, ['encoding' => $encoding, 'digest' => $digest, 'skip_errors' => $skip ? '1' : '0']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $db = $this->app->db();
        return [
            'users' => (int) $db->value('SELECT COUNT(*) FROM users'),
            'inactive' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'inactive'"),
            'audit' => (int) $db->value('SELECT COUNT(*) FROM audit_log'),
            'sessions' => (int) $db->value('SELECT COUNT(*) FROM sessions'),
        ];
    }

    /** @return array<string, array<string, mixed>> 로그인 ID → 행 판정 */
    private static function byLogin(array $preview): array
    {
        return array_column($preview['rows'], null, 'login_id');
    }

    public function testAccessAdminOnlyAndCsrf(): void
    {
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get('/api/v1/admin/account-import/template')->status);
        $kim = $this->as('kim');
        $csv = "login_id,display_name\nnew1,New One\n";
        $this->assertSame(403, $kim->get('/api/v1/admin/account-import/template')->status);
        $this->assertSame(403, $kim->raw('POST', '/api/v1/admin/account-import/preview', $csv)->status);
        $this->assertSame(403, $kim->raw('POST', '/api/v1/admin/account-import/execute', $csv, [], true, ['digest' => str_repeat('a', 64)])->status);
        $this->assertSame(403, $kim->get('/admin/users/import')->status, '화면 직접 주소도 서버에서 차단');
        $admin = $this->as('admin');
        $this->assertSame(403, $admin->raw('POST', '/api/v1/admin/account-import/preview', $csv, [], false)->status, 'CSRF 없음');
        $this->assertSame(403, $admin->raw('POST', '/api/v1/admin/account-import/execute', $csv, [], false, ['digest' => str_repeat('a', 64)])->status, 'CSRF 없음(실행)');
        $page = $admin->get('/admin/users/import');
        $this->assertSame(200, $page->status);
        $this->assertContains('data-account-import', $page->body);
        $this->assertContains('/admin/users/import', $admin->get('/admin/users')->body, '사용자 관리 화면에 일괄 처리 링크');
        // 양식: UTF-8 BOM + 머리글 + 예시 2행, 그대로 올리면 읽힘(예시 deactivate 대상 old.user는 이 시험의 고정 계정이라 정상)
        $tpl = $admin->get('/api/v1/admin/account-import/template');
        $this->assertSame(200, $tpl->status);
        $this->assertTrue(str_starts_with($tpl->body, "\xEF\xBB\xBFaction,login_id,display_name,email,role,auth_type\r\n"), '양식 머리글');
        $p = $this->preview($admin, $tpl->body);
        $this->assertSame(['utf-8', true, 2], [$p['encoding'], $p['bom'], $p['summary']['rows']]);
        $this->assertSame([['create', 'ok', 'kim.dev'], ['deactivate', 'ok', 'old.user']], array_map(static fn (array $r): array => [$r['action'], $r['status'], $r['login_id']], $p['rows']));
    }

    public function testPreviewJudgesRowsAndWritesNothing(): void
    {
        $admin = $this->as('admin');
        $before = $this->counts();
        $csv = implode("\n", [
            'action,login_id,display_name,email,role,auth_type,group',
            ',ok.user,Ok User,ok@example.com,,,dev',          // 2 ok(그룹 열은 파일 경고)
            'create,KIM,Kim Again,,,,',                        // 3 기존 계정(대소문자 무시)
            'create,dup,Dup One,,,,',                          // 4 ok
            'create,DUP,Dup Two,,,,',                          // 5 파일 안 중복
            'create,-bad,Bad,,,,',                             // 6 형식
            'create,noname,,,,,',                              // 7 표시 이름 없음
            'create,mimic,admin,,,,',                          // 8 다른 사용자의 로그인 ID와 같은 이름
            'create,mail1,Mail,not-an-email,,,',               // 9 이메일 형식
            'create,mail2,Mail Two,KIM@example.com,,,',        // 10 이메일 중복 → 경고
            'create,boss,Boss,,ADMIN,,',                       // 11 관리자 → 경고
            'create,role1,Role,,owner,,',                      // 12 역할 값
            'create,ld1,Ldap,,,ldap,',                         // 13 켜지지 않은 방식
            'create,xx1,Xx,,,kerberos,',                       // 14 모르는 방식
            'deactivate,nobody,,,,,',                          // 15 없는 계정
            'deactivate,gone,,,,,',                            // 16 이미 비활성 → 경고·건너뜀
            'deactivate,admin,,,,,',                           // 17 자기 자신
            'deactivate,old.user,Ignored Name,,,,',            // 18 비활성화(이름 무시 경고)
            'remove,zed,,,,,',                                 // 19 동작 값
            '',
        ]);
        $p = $this->preview($admin, $csv);
        $this->assertSame($before, $this->counts(), '미리보기는 아무것도 저장하지 않음(감사 로그도)');
        $this->assertSame([['group_column_ignored', 'group']], array_map(static fn (array $w): array => [$w['code'], $w['params']['column']], $p['file_warnings']));
        $rows = array_column($p['rows'], null, 'row');
        $expect = [
            2 => ['ok', []], 3 => ['error', ['login_id_taken']], 4 => ['ok', []], 5 => ['error', ['duplicate_in_file']],
            6 => ['error', ['login_id_invalid']], 7 => ['error', ['display_name_required']], 8 => ['error', ['display_name_reserved']],
            9 => ['error', ['email_invalid']], 10 => ['warning', ['email_in_use']], 11 => ['warning', ['role_admin']],
            12 => ['error', ['role_invalid']], 13 => ['error', ['auth_type_not_enabled']], 14 => ['error', ['auth_type_invalid']],
            15 => ['error', ['user_not_found']], 16 => ['warning', ['already_inactive']], 17 => ['error', ['self_deactivate']],
            18 => ['warning', ['fields_ignored']], 19 => ['error', ['action_invalid']],
        ];
        foreach ($expect as $line => [$status, $codes]) {
            $this->assertSame([$status, $codes], [$rows[$line]['status'], $rows[$line]['codes']], "행 {$line}");
        }
        $this->assertSame(['rows' => 18, 'create' => 4, 'deactivate' => 1, 'skip' => 1, 'error' => 12, 'warning' => 4, 'ok' => 2, 'admin_create' => 1], $p['summary']);
        $this->assertSame(['local'], $p['auth_types']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $p['digest']));
        $this->assertFalse(str_contains(json_encode($p), '$2y$'), '응답에 비밀번호 해시 없음');
        $this->assertFalse(str_contains(json_encode($p), 'temp_password'), '미리보기에 임시 비밀번호 없음');
        // 오류가 있으면 오류 행 제외를 명시해야 실행
        $r = $this->execute($admin, $csv, $p['digest']);
        $this->assertSame([422, 'import_has_errors'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertSame($before, $this->counts());
    }

    public function testExecuteCreatesAndDeactivatesWithTempPasswordsAndAudit(): void
    {
        $victim = $this->as('old.user');
        $admin = $this->as('admin');
        $sessionsBefore = (int) $this->app->db()->value('SELECT COUNT(*) FROM sessions WHERE user_id = (SELECT id FROM users WHERE login_id = ?)', ['old.user']);
        $this->assertSame(1, $sessionsBefore);
        $csv = "action,login_id,display_name,email,role\ncreate,lee,Lee Dev,lee.private@example.com,user\ncreate,boss2,Boss Two,,admin\ndeactivate,old.user,,,\ncreate,bad id,X,,\n";
        $p = $this->preview($admin, $csv);
        $this->assertSame(['create' => 2, 'deactivate' => 1, 'error' => 1], array_intersect_key($p['summary'], ['create' => 0, 'deactivate' => 0, 'error' => 0]));
        $this->assertSame([400, 'import_digest_required'], [$this->execute($admin, $csv, 'x')->status, $this->execute($admin, $csv, 'x')->decoded()['error']['code']]);
        $wrong = $this->execute($admin, $csv, str_repeat('0', 64), true);
        $this->assertSame([409, 'import_preview_stale'], [$wrong->status, $wrong->decoded()['error']['code']]);
        $changed = $this->execute($admin, $csv . "create,more,More,,\n", $p['digest'], true);
        $this->assertSame(409, $changed->status, '다른 파일(미리보기와 다름)');

        $r = $this->execute($admin, $csv, $p['digest'], true);
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->decoded()['data'];
        $this->assertSame([2, 1, 1], [$d['summary']['created'], $d['summary']['deactivated'], $d['summary']['error']]);
        $this->assertSame(24, $d['temp_password_hours']);
        $res = array_column($d['rows'], null, 'login_id');
        $this->assertSame(['created', 'created', 'deactivated', 'error'], [$res['lee']['result'], $res['boss2']['result'], $res['old.user']['result'], $res['bad id']['result']]);
        $temp = $res['lee']['temp_password'];
        $this->assertTrue(is_string($temp) && strlen($temp) >= 12);
        $this->assertFalse(isset($res['old.user']['temp_password']));
        $this->assertSame('2026-10-03T12:00:00Z', $res['lee']['temp_password_expires_at'], '24시간(주입한 시계 기준)');
        $this->assertFalse(str_contains($r->body, '$2y$'), '실행 응답에 해시 없음');

        $db = $this->app->db();
        $lee = $db->one("SELECT * FROM users WHERE login_id = 'lee'");
        $this->assertSame(['user', 'local', 1, 'temporary', '2026-10-03T12:00:00Z', 'lee.private@example.com', 'Lee Dev'],
            [$lee['role'], $lee['auth_type'], (int) $lee['must_change_password'], $lee['password_kind'], $lee['temp_password_expires_at'], $lee['email'], $lee['display_name']]);
        $this->assertTrue(password_verify($temp, (string) $lee['password_hash']));
        $this->assertSame('admin', $db->value("SELECT role FROM users WHERE login_id = 'boss2'"));
        $this->assertSame('inactive', $db->value("SELECT status FROM users WHERE login_id = 'old.user'"));
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM sessions WHERE user_id = (SELECT id FROM users WHERE login_id = 'old.user')"), '비활성화 사용자 세션 즉시 폐기');
        $this->assertSame(401, $victim->get('/api/v1/users/me')->status, '비활성화된 사용자는 바로 로그아웃');
        // 새 계정: 임시 비밀번호로 로그인되고 변경이 강제된다
        $newbie = $this->as('lee', $temp);
        $this->assertSame([403, 'password_change_required'], [$newbie->get('/api/v1/projects')->status, $newbie->get('/api/v1/projects')->decoded()['error']['code']]);

        // 감사 로그: 묶음 1건 + 사용자별 source csv, 비밀번호·이메일·CSV 원문 없음
        $audit = $db->all("SELECT action, target_id, details FROM audit_log WHERE action IN ('user.create','user.deactivate','user.bulk_import') ORDER BY id");
        $this->assertSame(['user.create', 'user.create', 'user.deactivate', 'user.bulk_import'], array_column($audit, 'action'));
        $bulk = json_decode((string) $audit[3]['details'], true);
        $this->assertSame([2, 1, 1, 0, 4, 'utf-8', substr(hash('sha256', $csv), 0, 12), true],
            [$bulk['created'], $bulk['deactivated'], $bulk['errors_skipped'], $bulk['skipped'], $bulk['rows'], $bulk['encoding'], $bulk['file_sha256'], $bulk['skip_errors']]);
        foreach (array_slice($audit, 0, 3) as $a) {
            $this->assertSame('csv', json_decode((string) $a['details'], true)['source']);
        }
        $auditText = json_encode($db->all('SELECT * FROM audit_log'));
        $logText = implode("\n", array_map('file_get_contents', glob($this->logDir . '/*') ?: []));
        foreach ([$temp, $res['boss2']['temp_password'], 'lee.private', 'Lee Dev,lee'] as $secret) {
            $this->assertFalse(str_contains((string) $auditText, $secret), "감사 로그에 없음: {$secret}");
            $this->assertFalse(str_contains($logText, $secret), "로그에 없음: {$secret}");
        }
        // 같은 파일을 다시 실행하면 이제 판정이 달라(이미 있는 계정) 409 — 두 번 반영되지 않음
        $this->assertSame(409, $this->execute($admin, $csv, $p['digest'], true)->status);
    }

    public function testConflictAfterPreviewAppliesNothing(): void
    {
        $admin = $this->as('admin');
        $csv = "login_id,display_name\nnewbie,Newbie\nother,Other\n";
        $p = $this->preview($admin, $csv);
        $this->assertSame(0, $p['summary']['error']);
        // 미리보기 뒤 다른 관리자가 같은 ID(대소문자 다름)를 만듦
        $this->assertSame(201, $admin->post('/api/v1/users', ['login_id' => 'NEWBIE', 'display_name' => 'Someone'])->status);
        $before = $this->counts();
        $r = $this->execute($admin, $csv, $p['digest']);
        $this->assertSame([409, 'import_preview_stale'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertSame($before, $this->counts(), '아무것도 반영하지 않음(other도 만들지 않음)');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM users WHERE login_id = 'other'"));
        // 다시 미리보기하면 오류로 보인다
        $again = $this->preview($admin, $csv);
        $this->assertSame(['login_id_taken'], self::byLogin($again)['newbie']['codes']);
    }

    public function testFailureInsideTransactionAppliesNothing(): void
    {
        $victim = $this->as('old.user');
        $admin = $this->as('admin');
        $db = $this->app->db();
        // 중간 행에서 실패를 주입(시험 DB에만 트리거) — 앞 행의 생성과 비활성화까지 모두 취소되어야 한다
        $db->execute("CREATE TRIGGER test_boom BEFORE INSERT ON users WHEN NEW.login_id = 'boom' BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $csv = "action,login_id,display_name\ncreate,first,First\ndeactivate,old.user,\ncreate,boom,Boom\ncreate,last,Last\n";
        $p = $this->preview($admin, $csv);
        $this->assertSame(0, $p['summary']['error']);
        $before = $this->counts();
        $r = $this->execute($admin, $csv, $p['digest']);
        $this->assertSame(500, $r->status);
        $this->assertSame($before, $this->counts(), '0건 반영(계정·상태·감사·세션 그대로)');
        $this->assertSame('active', $db->value("SELECT status FROM users WHERE login_id = 'old.user'"));
        $this->assertSame(200, $victim->get('/api/v1/users/me')->status, '세션도 그대로');
        $this->assertFalse(str_contains($r->body, 'boom') && str_contains($r->body, 'RAISE'), '내부 오류 문구를 응답에 넣지 않음');
    }

    public function testLastLocalAdminAndEncodingAndLimitsViaApi(): void
    {
        $db = $this->app->db();
        TestDb::user($db, 'admin2', 'admin');
        // 행위자가 활성 앱 관리자가 아닌 경우(시스템 작업): 관리자 두 명을 모두 비활성화하면 뒤 행이 last_admin
        $svc = $this->app->accountImport();
        $p = $svc->preview(Actor::system(), "action,login_id\ndeactivate,admin\ndeactivate,admin2\n", 'auto');
        $this->assertSame([['ok', []], ['error', ['last_admin']]], array_map(static fn (array $r): array => [$r['status'], $r['codes']], $p['rows']));
        $admin = $this->as('admin');
        $this->assertSame(['ok'], array_column($this->preview($admin, "action,login_id\ndeactivate,admin2\n")['rows'], 'status'), '본인이 남으므로 다른 관리자 비활성화는 가능');
        // CP949 파일(한국어 Excel 저장)
        $name = "\u{C774}\u{C11C}\u{C900}";
        $cp = mb_convert_encoding("login_id,display_name\nseo,{$name}\n", 'CP949', 'UTF-8');
        $pc = $this->preview($admin, $cp);
        $this->assertSame(['cp949', $name, 'ok'], [$pc['encoding'], $pc['rows'][0]['display_name'], $pc['rows'][0]['status']]);
        $this->assertSame('encoding_mismatch', $this->preview($admin, $cp, 'utf-8')['file_errors'][0]['code']);
        // 실행 때 인코딩을 다르게 고르면 다른 글로 읽혀 실행하지 않는다(UTF-8로는 읽을 수 없음 → 파일 오류)
        $x = $this->execute($admin, $cp, $pc['digest'], false, 'utf-8');
        $this->assertSame([400, 'import_file_invalid'], [$x->status, $x->decoded()['error']['code']]);
        $ok = $this->execute($admin, $cp, $pc['digest'], false, 'auto');
        $this->assertSame(200, $ok->status, $ok->body);
        $this->assertSame($name, $db->value("SELECT display_name FROM users WHERE login_id = 'seo'"));
        // 한도: 2 MiB 넘는 본문은 413, 빈 본문 400, 잘못된 인코딩 400, 파일 오류 파일은 실행 400
        $big = str_repeat('a', 2 * 1024 * 1024 + 1);
        $this->assertSame(413, $admin->raw('POST', '/api/v1/admin/account-import/preview', $big)->status);
        $this->assertSame([400, 'import_file_required'], [$admin->raw('POST', '/api/v1/admin/account-import/preview', '')->status, $admin->last->decoded()['error']['code'] ?? null]);
        $this->assertSame(400, $admin->raw('POST', '/api/v1/admin/account-import/preview', "login_id\na\n", [], true, ['encoding' => 'latin1'])->status);
        $empty = $this->preview($admin, "login_id\n");
        $this->assertSame('no_rows', $empty['file_errors'][0]['code']);
        $this->assertSame([400, 'import_file_invalid'], [$this->execute($admin, "login_id\n", $empty['digest'])->status, $admin->last->decoded()['error']['code']]);
    }

    public function testTemporaryPasswordsNeverStartWithFormulaCharacter(): void
    {
        $svc = $this->app->accountImport();
        $m = new \ReflectionMethod($svc, 'temporaryPassword');
        for ($i = 0; $i < 300; $i++) {
            $this->assertFalse(in_array($m->invoke($svc)[0], ['=', '+', '-', '@'], true), '결과 CSV에서 작은따옴표가 붙지 않게');
        }
        $this->assertSame(['local'], (new AuthProviderRegistry())->enabledTypes());
        $this->assertSame(['local', 'ldap'], (new AuthProviderRegistry(['ldap', 'local']))->enabledTypes(), '등록 순서와 무관하게 KNOWN 순서');
        // 사용자 생성 API도 같은 목록: 켜지지 않은 방식은 400
        $admin = $this->as('admin');
        $r = $admin->post('/api/v1/users', ['login_id' => 'ld9', 'display_name' => 'Ld', 'auth_type' => 'ldap']);
        $this->assertSame([400, 'auth_type_not_enabled'], [$r->status, $r->decoded()['error']['code']]);
    }
}
