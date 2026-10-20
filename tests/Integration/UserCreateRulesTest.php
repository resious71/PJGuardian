<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\UserProfileRules;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 사용자 생성 경로의 표시 이름·이메일 규칙(X06): 관리자 생성(POST /api/v1/users)과 그룹 관리자 생성(POST /api/v1/groups/{id}/accounts)이
 * 수정 경로(X05)와 같은 규칙(UserProfileRules)을 쓴다. 오류 코드는 P10 계약(invalid_display_name, invalid_email)을 유지하고
 * 다른 사용자의 로그인 ID와 같은 이름만 display_name_reserved. 실패하면 계정·구성원 행·임시 비밀번호가 하나도 생기지 않는다.
 */
final class UserCreateRulesTest extends TestCase
{
    private App $app;
    private int $group;

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('ucreate'));
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $t0 = '2026-10-02T12:00:00Z';
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('team', ?, ?)", [$t0, $t0]);
        $this->group = $db->lastInsertId();
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (?, ?, 1, ?)', [$this->group, $mgr, $t0]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return array{0: TestClient, 1: string} 경로별 [클라이언트, 생성 URL] */
    private function paths(): array
    {
        return [
            'admin' => [$this->as('admin'), '/api/v1/users'],
            'group_manager' => [$this->as('mgr'), "/api/v1/groups/{$this->group}/accounts"],
        ];
    }

    private function counts(): array
    {
        $db = $this->app->db();
        return [(int) $db->value('SELECT COUNT(*) FROM users'), (int) $db->value('SELECT COUNT(*) FROM group_members'),
            (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.create'")];
    }

    public function testRejectedInputsCreateNothing(): void
    {
        $long = str_repeat('가', 101);
        $cases = [
            ['display_name' => 'kim', 'expect' => 'display_name_reserved'],
            ['display_name' => 'KIM', 'expect' => 'display_name_reserved'],
            ['display_name' => ' Mgr ', 'expect' => 'display_name_reserved'],
            ['display_name' => "a\u{202E}nimda", 'expect' => 'invalid_display_name'],
            ['display_name' => "이\u{2066}름", 'expect' => 'invalid_display_name'],
            ['display_name' => "이\x07름", 'expect' => 'invalid_display_name'],
            ['display_name' => "이\u{0085}름", 'expect' => 'invalid_display_name'],
            ['display_name' => '', 'expect' => 'invalid_display_name'],
            ['display_name' => $long, 'expect' => 'invalid_display_name'],
            ['display_name' => '정상', 'email' => 'not-mail', 'expect' => 'invalid_email'],
            ['display_name' => '정상', 'email' => 'a@b', 'expect' => 'invalid_email'],
            ['display_name' => '정상', 'email' => str_repeat('a', 250) . '@x.com', 'expect' => 'invalid_email'],
        ];
        $n = 0;
        foreach ($this->paths() as $path => [$c, $url]) {
            foreach ($cases as $case) {
                $before = $this->counts();
                $body = ['login_id' => 'new' . ++$n, 'display_name' => $case['display_name']] + (isset($case['email']) ? ['email' => $case['email']] : []);
                $r = $c->post($url, $body);
                $label = $path . ' ' . json_encode($case, JSON_UNESCAPED_UNICODE);
                $this->assertSame([400, $case['expect']], [$r->status, $r->decoded()['error']['code'] ?? null], $label);
                $this->assertNotContains('temp_password', $r->body, $label . ': 임시 비밀번호 없음');
                $this->assertSame($before, $this->counts(), $label . ': 계정·구성원 행·감사 로그 불변');
            }
        }
    }

    public function testAcceptedInputsAndNormalization(): void
    {
        $db = $this->app->db();
        foreach ($this->paths() as $path => [$c, $url]) {
            $p = $path === 'admin' ? 'a' : 'g';
            $hundred = str_repeat('나', 100);
            $ok = [
                ["{$p}self", "{$p}SELF", null, "{$p}SELF"],                         // 새 계정 자기 로그인 ID와 같은 이름(대소문자 달라도) 허용
                ["{$p}100", $hundred, null, $hundred],                            // 100자 경계
                ["{$p}nfc", "\u{1112}\u{1161}\u{11AB}", '', "\u{D55C}"],           // NFC 정규화 저장, 빈 이메일 → NULL
                ["{$p}mail", '메일', ' Mixed.Case@Example.COM ', '메일'],          // 이메일 앞뒤 공백 제거, 대소문자 그대로
            ];
            foreach ($ok as [$login, $name, $email, $stored]) {
                $before = $this->counts();
                $r = $c->post($url, ['login_id' => $login, 'display_name' => $name] + ($email === null ? [] : ['email' => $email]));
                $this->assertSame(201, $r->status, "{$path} {$login}: " . $r->body);
                $this->assertTrue(is_string($r->decoded()['data']['temp_password'] ?? null), '임시 비밀번호는 응답에 한 번');
                $row = $db->one('SELECT display_name, email, role FROM users WHERE login_id = ?', [$login]);
                $this->assertSame($stored, $row['display_name'], "{$path} {$login}");
                $this->assertSame($before[0] + 1, $this->counts()[0]);
                if ($login === "{$p}nfc") {
                    $this->assertSame(null, $row['email'], '빈 이메일 → NULL');
                }
                if ($login === "{$p}mail") {
                    $this->assertSame('Mixed.Case@Example.COM', $row['email']);
                }
                if ($path === 'group_manager') {
                    $this->assertSame('user', $row['role'], '그룹 관리자 생성은 역할 user 고정');
                    $this->assertSame($before[1] + 1, $this->counts()[1], '구성원 행 동반');
                }
            }
        }
    }

    public function testSharedRuleFunctions(): void
    {
        $this->assertSame(['홍길동', null], UserProfileRules::displayName(" 홍길동\t"));
        $this->assertSame([null, 'required'], UserProfileRules::displayName('  '));
        $this->assertSame([null, 'too_long'], UserProfileRules::displayName(str_repeat('x', 101)));
        $this->assertSame([null, 'invalid'], UserProfileRules::displayName("\u{202A}x"));
        $this->assertSame([null, 'invalid'], UserProfileRules::displayName(['x']));
        $this->assertSame([null, 'invalid'], UserProfileRules::displayName("\xff\xfe"));
        $this->assertSame([null, null], UserProfileRules::email(''));
        $this->assertSame([null, null], UserProfileRules::email(null));
        $this->assertSame(['a@b.co', null], UserProfileRules::email(' a@b.co '));
        $this->assertSame([null, 'invalid'], UserProfileRules::email('a@b.'));
        $this->assertSame([null, 'too_long'], UserProfileRules::email(str_repeat('a', 255)));
        $db = $this->app->db();
        $kim = (int) $db->value("SELECT id FROM users WHERE login_id = 'kim'");
        $this->assertTrue(UserProfileRules::reserved($db, 'Kim', null), '생성: 기존 모든 사용자와 비교');
        $this->assertFalse(UserProfileRules::reserved($db, 'Kim', $kim), '수정: 본인 로그인 ID는 허용');
        $this->assertFalse(UserProfileRules::reserved($db, '김', null));
    }

    public function testCliAdminCreationUsesSameRules(): void
    {
        $e = $this->assertThrows(\PJGuardian\Http\ApiException::class, fn () => $this->app->users()->createAdmin(\PJGuardian\Services\Actor::cli(), 'root2', 'KIM', null, null));
        $this->assertSame('display_name_reserved', $e->errorCode);
        $e = $this->assertThrows(\PJGuardian\Http\ApiException::class, fn () => $this->app->users()->createAdmin(\PJGuardian\Services\Actor::cli(), 'root2', "x\u{202E}", null, null));
        $this->assertSame('invalid_display_name', $e->errorCode);
        $this->assertSame(null, $this->app->db()->value("SELECT id FROM users WHERE login_id = 'root2'"));
    }
}
