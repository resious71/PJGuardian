<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 사용자 그룹과 그룹 관리자(명세 3장 3.1, 19.10).
 * 역할(admin, 그룹 관리자, 일반 구성원, 비구성원, 다른 그룹 관리자) × 동작 허용·거부 표와 세부 규칙.
 */
final class GroupServiceTest extends TestCase
{
    private App $app;
    /** @var array<string, array<string, mixed>> 공개 사용자 정보(세션의 user와 같은 모양) */
    private array $u = [];
    private int $g1;
    private int $g2;

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('groups'));
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'mgrA' => 'user', 'memberA' => 'user', 'outsider' => 'user', 'mgrB' => 'user', 'target' => 'user', 'other' => 'user'] as $login => $role) {
            $id = TestDb::user($db, $login, $role);
            $this->u[$login] = $this->app->users()->get($id);
        }
        $admin = $this->u['admin'];
        $this->g1 = (int) $this->app->groups()->create($admin, '10.0.0.1', ['name' => '개발1팀', 'description' => 'd'])['id'];
        $this->g2 = (int) $this->app->groups()->create($admin, '10.0.0.1', ['name' => '개발2팀'])['id'];
        $g = $this->app->groups();
        $g->addMember($admin, null, $this->g1, 'mgrA');
        $g->setManager($admin, null, $this->g1, $this->u['mgrA']['id'], true);
        $g->addMember($admin, null, $this->g1, 'memberA');
        $g->addMember($admin, null, $this->g1, 'other');
        $g->addMember($admin, null, $this->g2, 'mgrB');
        $g->setManager($admin, null, $this->g2, $this->u['mgrB']['id'], true);
    }

    /** 동작을 실행해 허용(true)·거부(상태 코드) 결과를 돌려준다 */
    private function attempt(callable $fn): true|int
    {
        try {
            $fn();
            return true;
        } catch (ApiException $e) {
            return $e->status;
        }
    }

    public function testPermissionMatrix(): void
    {
        $actions = [
            '그룹 생성' => fn (array $u) => $this->app->groups()->create($u, null, ['name' => 'new-' . bin2hex(random_bytes(3))]),
            '이름 변경' => fn (array $u) => $this->app->groups()->update($u, null, $this->g1, ['name' => '개발1팀-' . bin2hex(random_bytes(2))]),
            '삭제 정보' => fn (array $u) => $this->app->groups()->deletionInfo($u, $this->g1),
            '구성원 보기' => fn (array $u) => $this->app->groups()->members($u, $this->g1),
            '구성원 추가' => function (array $u): void {
                $this->app->groups()->addMember($u, null, $this->g1, 'target');
                $this->app->groups()->removeMember($this->u['admin'], null, $this->g1, $this->u['target']['id']); // 다음 동작을 위해 원상복구
            },
            '구성원 제거' => function (array $u): void {
                $this->app->groups()->removeMember($u, null, $this->g1, $this->u['other']['id']);
                $this->app->groups()->addMember($this->u['admin'], null, $this->g1, 'other');
            },
            '관리자 지정·해제' => function (array $u): void {
                $this->app->groups()->setManager($u, null, $this->g1, $this->u['memberA']['id'], true);
                $this->app->groups()->setManager($u, null, $this->g1, $this->u['memberA']['id'], false);
            },
            '계정 생성' => fn (array $u) => $this->app->groups()->createAccount($u, null, $this->g1, ['login_id' => 'n' . bin2hex(random_bytes(3)), 'display_name' => 'N']),
            '사용자 검색' => fn (array $u) => $this->app->groups()->searchUsers($u, 'ta'),
            '그룹 목록' => fn (array $u) => $this->app->groups()->list($u),
        ];
        // 기대값: true = 허용, 숫자 = 거부 상태 코드(관리 대상이 아닌 그룹은 404로 존재를 숨김, 관리자 전용 작업은 403)
        $expected = [
            'admin' => array_fill_keys(array_keys($actions), true),
            'mgrA' => ['그룹 생성' => 403, '이름 변경' => 403, '삭제 정보' => 403, '구성원 보기' => true, '구성원 추가' => true, '구성원 제거' => true, '관리자 지정·해제' => true, '계정 생성' => true, '사용자 검색' => true, '그룹 목록' => true],
            'memberA' => ['그룹 생성' => 403, '이름 변경' => 404, '삭제 정보' => 404, '구성원 보기' => 404, '구성원 추가' => 404, '구성원 제거' => 404, '관리자 지정·해제' => 404, '계정 생성' => 404, '사용자 검색' => 403, '그룹 목록' => 404],
            'outsider' => ['그룹 생성' => 403, '이름 변경' => 404, '삭제 정보' => 404, '구성원 보기' => 404, '구성원 추가' => 404, '구성원 제거' => 404, '관리자 지정·해제' => 404, '계정 생성' => 404, '사용자 검색' => 403, '그룹 목록' => 404],
            'mgrB' => ['그룹 생성' => 403, '이름 변경' => 404, '삭제 정보' => 404, '구성원 보기' => 404, '구성원 추가' => 404, '구성원 제거' => 404, '관리자 지정·해제' => 404, '계정 생성' => 404, '사용자 검색' => true, '그룹 목록' => true],
        ];
        $actual = [];
        foreach (array_keys($expected) as $who) {
            foreach ($actions as $name => $fn) {
                $actual[$who][$name] = $this->attempt(fn () => $fn($this->u[$who]));
            }
        }
        foreach ($expected as $who => $row) {
            $this->assertSame($row, $actual[$who], "역할 {$who}의 허용·거부 표");
        }
    }

    public function testPermissionHelpers(): void
    {
        $p = $this->app->groupPermission();
        $this->assertTrue($p->canManage($this->u['admin'], $this->g1), 'admin은 모든 그룹의 관리자(구성원 행 없음)');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM group_members WHERE user_id = ?', [$this->u['admin']['id']]));
        $this->assertTrue($p->canManage($this->u['mgrA'], $this->g1));
        $this->assertFalse($p->canManage($this->u['mgrA'], $this->g2), '다른 그룹은 관리 못 함');
        $this->assertFalse($p->canManage($this->u['memberA'], $this->g1), '일반 구성원');
        $this->assertTrue($p->managesAny($this->u['mgrB']));
        $this->assertFalse($p->managesAny($this->u['memberA']));
        $this->assertTrue($p->canAdministrate($this->u['admin']));
        $this->assertFalse($p->canAdministrate($this->u['mgrA']));
        $inactive = $this->u['mgrA'];
        $inactive['status'] = 'inactive';
        $this->assertFalse($p->canManage($inactive, $this->g1), '비활성 사용자는 아무것도 못 함');
        $this->assertFalse($p->managesAny($inactive));
        $this->assertFalse($p->canManage(null, $this->g1), '비로그인');
    }

    public function testInactiveManagerCannotLogIn(): void
    {
        $this->app->users()->setStatus(\PJGuardian\Services\Actor::fromUser($this->u['admin'], null), $this->u['mgrA']['id'], 'inactive');
        $this->assertFalse($this->app->login()->attempt('mgrA', 'Passw0rd!x', '10.0.0.9')->ok, '로그인 자체가 막힘');
    }

    public function testGroupNameRules(): void
    {
        $admin = $this->u['admin'];
        $g = $this->app->groups()->create($admin, null, ['name' => '  QA  ', 'description' => '  품질  ']);
        $this->assertSame(['QA', '품질'], [$g['name'], $g['description']]);
        $cases = [
            [['name' => 'qa'], 'group_name_taken', 409],
            [['name' => ''], 'invalid_group_name', 400],
            [['name' => str_repeat('가', 101)], 'invalid_group_name', 400],
            [['name' => "a\tb"], 'invalid_group_name', 400],
            [['name' => 'ok', 'description' => str_repeat('a', 2001)], 'invalid_description', 400],
        ];
        foreach ($cases as [$data, $code, $status]) {
            $e = $this->assertThrows(ApiException::class, fn () => $this->app->groups()->create($admin, null, $data));
            $this->assertSame([$code, $status], [$e->errorCode, $e->status], json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->groups()->update($admin, null, $this->g1, ['name' => '개발2팀']));
        $this->assertSame('group_name_taken', $e->errorCode, '이름 변경도 중복 검사');
    }

    public function testMemberRules(): void
    {
        $mgr = $this->u['mgrA'];
        $g = $this->app->groups();
        $e = $this->assertThrows(ApiException::class, fn () => $g->addMember($mgr, null, $this->g1, 'memberA'));
        $this->assertSame(['already_member', 409], [$e->errorCode, $e->status]);
        $e = $this->assertThrows(ApiException::class, fn () => $g->addMember($mgr, null, $this->g1, 'nobody'));
        $this->assertSame('user_not_found', $e->errorCode);
        $this->app->users()->setStatus(\PJGuardian\Services\Actor::fromUser($this->u['admin'], null), $this->u['target']['id'], 'inactive');
        $e = $this->assertThrows(ApiException::class, fn () => $g->addMember($mgr, null, $this->g1, 'target'));
        $this->assertSame(['user_inactive', 409], [$e->errorCode, $e->status], '활성 사용자만');
        $e = $this->assertThrows(ApiException::class, fn () => $g->addMember($mgr, null, $this->g1, 'admin'));
        $this->assertSame('admin_implicit_member', $e->errorCode, 'admin은 구성원 행을 만들지 않음');

        $e = $this->assertThrows(ApiException::class, fn () => $g->removeMember($mgr, null, $this->g1, $mgr['id']));
        $this->assertSame(['cannot_remove_self', 403], [$e->errorCode, $e->status]);
        $e = $this->assertThrows(ApiException::class, fn () => $g->setManager($mgr, null, $this->g1, $mgr['id'], false));
        $this->assertSame(['cannot_unset_self', 403], [$e->errorCode, $e->status]);
        $e = $this->assertThrows(ApiException::class, fn () => $g->setManager($mgr, null, $this->g1, $this->u['outsider']['id'], true));
        $this->assertSame(['member_not_found', 404], [$e->errorCode, $e->status], '구성원만 관리자로 지정');
        $e = $this->assertThrows(ApiException::class, fn () => $g->removeMember($mgr, null, $this->g1, $this->u['outsider']['id']));
        $this->assertSame('member_not_found', $e->errorCode);

        // admin은 그룹 관리자를 해제·제거할 수 있다(그룹 관리자가 0명이어도 admin이 관리)
        $g->setManager($this->u['admin'], null, $this->g1, $mgr['id'], false);
        $g->removeMember($this->u['admin'], null, $this->g1, $mgr['id']);
        $this->assertFalse($this->app->groupPermission()->canManage($mgr, $this->g1), '변경은 다음 판정부터 바로');
        $this->assertTrue($this->app->groupPermission()->canManage($this->u['admin'], $this->g1));
    }

    public function testMembersListAndCounts(): void
    {
        $members = $this->app->groups()->members($this->u['mgrA'], $this->g1);
        $this->assertSame(['memberA', 'mgrA', 'other'], array_column($members, 'login_id'));
        $row = $members[1];
        $this->assertSame(['login_id', 'display_name', 'user_id', 'is_manager', 'active', 'added_at'], array_keys($row));
        $this->assertTrue($row['is_manager']);
        $this->assertTrue($row['active']);
        $list = $this->app->groups()->list($this->u['mgrA']);
        $this->assertSame(['개발1팀'], array_column($list, 'name'), '그룹 관리자는 자기 그룹만');
        $this->assertSame(3, $list[0]['member_count']);
        $this->assertSame(['개발1팀', '개발2팀'], array_column($this->app->groups()->list($this->u['admin']), 'name'), 'admin은 전체');
    }

    public function testCreateAccountByGroupManagerIsAlwaysPlainUserInThatGroup(): void
    {
        $r = $this->app->groups()->createAccount($this->u['mgrA'], '10.0.0.5', $this->g1, [
            'login_id' => 'newbie', 'display_name' => '신입', 'role' => 'admin', 'group_id' => $this->g2, 'auth_type' => 'ldap',
        ]);
        $id = (int) $r['user']['id'];
        $this->assertSame(['user', 'local', true], [$r['user']['role'], $r['user']['auth_type'], $r['user']['must_change_password']], '요청의 role·auth_type 무시');
        $this->assertSame('2026-10-03T12:00:00Z', $r['user']['temp_password_expires_at'], '24시간');
        $this->assertSame([$this->g1], array_map('intval', array_column($this->app->db()->all('SELECT group_id FROM group_members WHERE user_id = ?', [$id]), 'group_id')), '그 그룹에만, 일반 구성원');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT is_manager FROM group_members WHERE user_id = ?', [$id]));
        $this->assertTrue($this->app->login()->attempt('newbie', $r['temp_password'], '10.0.0.6')->ok);
        // 중복·형식 검증은 사용자 생성 로직 그대로
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->groups()->createAccount($this->u['mgrA'], null, $this->g1, ['login_id' => 'NEWBIE', 'display_name' => 'x']));
        $this->assertSame('login_id_taken', $e->errorCode);
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->groups()->createAccount($this->u['mgrA'], null, $this->g1, ['login_id' => 'a b', 'display_name' => 'x']));
        $this->assertSame('invalid_login_id', $e->errorCode);
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM users WHERE login_id IN ('a b')"), '실패하면 계정도 구성원도 없음');
    }

    public function testSearchReturnsOnlyLoginIdAndNameOfActiveUsers(): void
    {
        $this->app->users()->setStatus(\PJGuardian\Services\Actor::fromUser($this->u['admin'], null), $this->u['other']['id'], 'inactive');
        $r = $this->app->groups()->searchUsers($this->u['mgrA'], 'Ot');
        $this->assertSame([], $r, '비활성 사용자 제외(other)');
        $r = $this->app->groups()->searchUsers($this->u['mgrA'], 'mgr');
        $this->assertSame([['login_id' => 'mgrA', 'display_name' => 'mgrA'], ['login_id' => 'mgrB', 'display_name' => 'mgrB']], $r, '로그인 ID와 이름만');
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->groups()->searchUsers($this->u['mgrA'], 'm'));
        $this->assertSame(['search_query_too_short', 400], [$e->errorCode, $e->status], '최소 2자');
        for ($i = 0; $i < 25; $i++) {
            TestDb::user($this->app->db(), sprintf('bulk%02d', $i), 'user');
        }
        $this->assertSame(20, count($this->app->groups()->searchUsers($this->u['mgrA'], 'bulk')), '20건까지');
        $this->assertSame([], $this->app->groups()->searchUsers($this->u['mgrA'], '%_'), 'LIKE 특수문자는 글자 그대로');
    }

    public function testDeleteGroupRequiresNameAndRemovesMembers(): void
    {
        $admin = $this->u['admin'];
        $this->assertSame(['members' => 3, 'projects' => 0], $this->app->groups()->deletionInfo($admin, $this->g1));
        $e = $this->assertThrows(ApiException::class, fn () => $this->app->groups()->delete($admin, null, $this->g1, '개발1'));
        $this->assertSame('group_name_mismatch', $e->errorCode);
        $this->app->groups()->delete($admin, null, $this->g1, '개발1팀');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM group_members WHERE group_id = ?', [$this->g1]), '구성원 행도 삭제');
        $this->assertSame(7, (int) $this->app->db()->value('SELECT COUNT(*) FROM users'), '사용자는 그대로');
    }

    public function testAuditTrailWithoutTempPassword(): void
    {
        $r = $this->app->groups()->createAccount($this->u['mgrA'], '10.0.0.5', $this->g1, ['login_id' => 'newbie', 'display_name' => '신입']);
        $this->app->groups()->update($this->u['admin'], null, $this->g1, ['name' => '개발1팀(신)']);
        $this->app->groups()->removeMember($this->u['mgrA'], null, $this->g1, $this->u['other']['id']);
        $this->app->groups()->setManager($this->u['mgrA'], null, $this->g1, $this->u['memberA']['id'], true);
        $this->app->groups()->setManager($this->u['mgrA'], null, $this->g1, $this->u['memberA']['id'], false);
        $this->app->groups()->delete($this->u['admin'], null, $this->g2, '개발2팀');

        $actions = array_column($this->app->db()->all("SELECT action FROM audit_log WHERE action LIKE 'group.%' OR action = 'user.create' ORDER BY id"), 'action');
        foreach (['group.create', 'group.member_add', 'group.manager_set', 'user.create', 'group.rename', 'group.member_remove', 'group.manager_unset', 'group.delete'] as $a) {
            $this->assertTrue(in_array($a, $actions, true), "감사 로그 {$a}");
        }
        $create = json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'user.create' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame($this->g1, $create['group_id'] ?? null);
        $this->assertSame('group_manager', $create['actor_role'] ?? null, '행위자 역할');
        $this->assertSame('user', $create['role']);
        $all = implode("\n", array_column($this->app->db()->all('SELECT details FROM audit_log'), 'details'));
        $this->assertNotContains($r['temp_password'], $all, '감사 로그에 임시 비밀번호 없음');
        $logs = '';
        foreach (glob($this->app->paths->logs() . '/*.log') ?: [] as $f) {
            $logs .= (string) file_get_contents($f);
        }
        $this->assertNotContains($r['temp_password'], $logs, '로그에 임시 비밀번호 없음');
        $this->assertSame(true, $this->app->audit()->verify()['ok']);
    }
}
