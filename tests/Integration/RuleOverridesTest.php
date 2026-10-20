<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Rules\RuleEnrichment;
use PJGuardian\Services\Rules\RuleLoader;
use PJGuardian\Services\Rules\RuleSource;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 관리자 규칙 켜기·끄기(P22B): 접근 통제(admin만, 401·403·CSRF), 켜기·끄기·되돌리기·멱등, 409 동시성, 422 이유 6종, 404, 감사 로그,
 * 읽기 응답의 사용자별 차이(변경자·사유 숨김), 목록 필터, 분석 스냅샷(버전에 없는 규칙·켤 수 없게 된 규칙 건너뜀), 전체 되돌리기(확인값·상한).
 * 시료 데이터는 고정(실제 도구 실행은 RuleOverrideToolsTest).
 */
final class RuleOverridesTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $u = [];
    private int $py;

    public function setUp(): void
    {
        $root = $this->tempDir('rovr');
        mkdir($root . '/work');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work'], 'rules' => ['override_reset_max' => '2']]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $admin = $this->app->users()->get($this->u['admin']);
        $g = (int) $this->app->groups()->create($admin, null, ['name' => 'G'])['id'];
        $this->app->groups()->addMember($admin, null, $g, 'mgr');
        $this->app->groups()->setManager($admin, null, $g, $this->u['mgr'], true);
        $this->py = TestDb::project($db, 'py-proj', 'python');
        $js = TestDb::project($db, 'js-proj', 'javascript');
        $actor = Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null);
        $this->app->projectAccess()->add($actor, $this->py, ['subject_type' => 'user', 'login_id' => 'kim']);
        $ap = TestDb::analysis($db, $this->py, $this->u['admin']);
        $aj = TestDb::analysis($db, $js, $this->u['admin'], ['tool' => 'eslint']);
        // 열린 결함: F401 2건(py — kim이 봄), no-undef 3건(js — kim은 못 봄)
        TestDb::defect($db, $this->py, $ap, ['rule_key' => 'ruff:F401', 'status' => 'open']);
        TestDb::defect($db, $this->py, $ap, ['rule_key' => 'ruff:F401', 'status' => 'open']);
        foreach ([1, 2, 3] as $_) {
            TestDb::defect($db, $js, $aj, ['rule_key' => 'eslint:no-undef', 'status' => 'open', 'tool' => 'eslint', 'language' => 'javascript']);
        }
        $this->load();
    }

    /** 시료: ruff 0.1.0, ESLint 1.0.0 → 2.0.0(활성). 상태·막힘이 다른 규칙을 하나씩 */
    private function load(): void
    {
        $rule = static fn (string $r, array $o = []): array => array_merge(['rule' => $r, 'name' => $r, 'summary' => "summary {$r}", 'description' => '', 'category' => 'x',
            'origin' => '', 'default_enabled' => false, 'fixable' => 'none', 'has_suggestions' => false, 'has_options' => null, 'status' => 'stable',
            'deprecation' => null, 'docs_url' => null, 'recommended' => false, 'requires_type_info' => false], $o);
        $ruff = [
            'F401' => $rule('F401', ['default_enabled' => true]),
            'E501' => $rule('E501', ['default_enabled' => true]),
            'D100' => $rule('D100'),
            'PRV001' => $rule('PRV001', ['status' => 'preview']),
            'RMV001' => $rule('RMV001', ['status' => 'removed']),
            'DEP001' => $rule('DEP001', ['status' => 'deprecated']),
            'DEP002' => $rule('DEP002', ['status' => 'deprecated', 'default_enabled' => true]),
            'TID251' => $rule('TID251'), // resources/rules/requires_options.json
        ];
        $eslint1 = ['no-undef' => $rule('no-undef', ['default_enabled' => true]), 'gone-rule' => $rule('gone-rule')];
        $eslint2 = [
            'no-undef' => $rule('no-undef', ['default_enabled' => true]),
            'eqeqeq' => $rule('eqeqeq'),
            '@typescript-eslint/await-thenable' => $rule('@typescript-eslint/await-thenable', ['requires_type_info' => true, 'origin' => '@typescript-eslint']),
            'id-match' => $rule('id-match'),
        ];
        $data = ['ruff' => ['0.1.0' => $ruff], 'eslint' => ['1.0.0' => $eslint1, '2.0.0' => $eslint2]];
        $source = new class ($data) implements RuleSource {
            public function __construct(private readonly array $data)
            {
            }

            public function extract(string $tool, string $version): array
            {
                return ['version' => $version, 'components' => [], 'rules' => $this->data[$tool][$version]];
            }
        };
        $loader = new RuleLoader($this->app->db(), $source, $this->app->toolRegistry(), $this->app->severityMap(),
            new RuleEnrichment(PJG_ROOT . '/resources/rules/enrichment'), $this->app->audit(), $this->app->clock, PJG_ROOT . '/resources/rules/requires_options.json');
        $report = ['tools' => [], 'changed' => false, 'errors' => []];
        $loader->loadVersions('ruff', ['0.1.0'], '0.1.0', true, $report);
        $loader->loadVersions('eslint', ['1.0.0', '2.0.0'], '2.0.0', true, $report);
        $loader->rebuildIndex();
        $this->assertSame([], $report['errors']);
        $this->assertSame(1, $report['tools']['ruff']['versions']['0.1.0']['blocks'], 'TID251(옵션 필요)');
        $this->assertSame(2, $report['tools']['eslint']['versions']['2.0.0']['blocks'], 'await-thenable(타입 정보) + id-match(옵션 필요)');
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function put(TestClient $c, string $tool, string $rule, array $body, bool $csrf = true): Response
    {
        return $c->raw('PUT', "/api/v1/admin/rules/{$tool}/{$rule}/override", (string) json_encode($body), ['content-type' => 'application/json'], $csrf);
    }

    private function del(TestClient $c, string $tool, string $rule): Response
    {
        return $c->raw('DELETE', "/api/v1/admin/rules/{$tool}/{$rule}/override", '', ['content-type' => 'application/json']);
    }

    /** @return array<string, mixed> 목록에서 한 규칙 */
    private function item(TestClient $c, string $tool, string $rule): array
    {
        $r = $c->get('/api/v1/rules', ['tool' => $tool, 'size' => '200']);
        $this->assertSame(200, $r->status, $r->body);
        foreach ($r->decoded()['data'] as $it) {
            if ($it['rule'] === $rule) {
                return $it;
            }
        }
        $this->fail("{$tool}:{$rule} 없음");
    }

    private function auditCount(string $action): int
    {
        return (int) $this->app->db()->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    public function testAccessControl(): void
    {
        $anon = new TestClient($this->app);
        $anon->fetchCsrf();
        $this->assertSame(401, $this->put($anon, 'ruff', 'F401', ['enabled' => false, 'expected_enabled' => true])->status);
        $this->assertSame(401, $this->del($anon, 'ruff', 'F401')->status);
        $this->assertSame(401, $anon->get('/api/v1/admin/rules/overrides')->status);
        $this->assertSame(401, $anon->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'ruff', 'confirm' => 'ruff'])->status);
        foreach (['kim', 'mgr'] as $login) {
            $c = $this->as($login);
            $this->assertSame(403, $this->put($c, 'ruff', 'F401', ['enabled' => false, 'expected_enabled' => true])->status, $login);
            $this->assertSame(403, $this->del($c, 'ruff', 'F401')->status, $login);
            $this->assertSame(403, $c->get('/api/v1/admin/rules/overrides')->status, $login);
            $this->assertSame(403, $c->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'ruff', 'confirm' => 'ruff'])->status, $login);
            $this->assertTrue($c->get('/admin/rule-settings')->status !== 200, $login . ': 관리 화면 없음');
        }
        $admin = $this->as('admin');
        $this->assertSame(403, $this->put($admin, 'ruff', 'F401', ['enabled' => false, 'expected_enabled' => true], false)->status, 'CSRF 없으면 거부');
        $this->assertSame(0, $this->auditCount('rules.override_set'));
        $this->assertSame(200, $admin->get('/admin/rule-settings')->status);
        $this->assertContains('data-admin-rule-settings', $admin->get('/admin/rule-settings')->body);
    }

    public function testSetClearIdempotentRevisionAndAudit(): void
    {
        $admin = $this->as('admin');
        $kim = $this->as('kim');
        $o = $this->app->ruleOverrides();
        $this->assertSame(0, $o->revision('ruff'));
        // 끄기(기본 켜짐) → 변경 행
        $r = $this->put($admin, 'ruff', 'F401', ['enabled' => false, 'expected_enabled' => true, 'reason' => "  too noisy\n here "]);
        $this->assertSame(200, $r->status, $r->body);
        $d = $r->decoded()['data'];
        $this->assertSame([false, true, true, 1], [$d['enabled'], $d['base_enabled'], $d['overridden'], $d['revision']]);
        $this->assertSame('too noisy here', $d['override']['reason'], '제어 문자는 공백으로, 앞뒤 공백 제거');
        $it = $this->item($kim, 'ruff', 'F401');
        $this->assertSame([false, true, true, true], [$it['analysis_enabled'], $it['default_enabled'], $it['overridden'], $it['enableable']]);
        $this->assertFalse(array_key_exists('reason', $it), '목록에는 사유 없음');
        $row = $this->app->db()->one("SELECT details FROM audit_log WHERE action = 'rules.override_set' ORDER BY id DESC LIMIT 1");
        $det = json_decode((string) $row['details'], true);
        $this->assertSame(['ruff', 'F401', true, false, 'too noisy here'], [$det['tool'], $det['rule'], $det['before'], $det['after'], $det['reason']]);
        // 같은 값 다시(멱등): 감사·개정 번호 그대로
        $this->assertSame(200, $this->put($admin, 'ruff', 'F401', ['enabled' => false, 'expected_enabled' => false])->status);
        $this->assertSame([1, 1], [$this->auditCount('rules.override_set'), $o->revision('ruff')]);
        // 다시 켜기 = 기본값 → 행 삭제
        $d = $this->put($admin, 'ruff', 'F401', ['enabled' => true, 'expected_enabled' => false])->decoded()['data'];
        $this->assertSame([true, false, 2], [$d['enabled'], $d['overridden'], $d['revision']]);
        $this->assertSame(null, $this->app->db()->value("SELECT 1 FROM rule_overrides WHERE rule = 'F401'"), '저장은 차이만');
        // 켜기(기본 꺼짐) → DELETE로 되돌리기, 다시 DELETE는 cleared = false
        $this->assertSame(200, $this->put($admin, 'ruff', 'D100', ['enabled' => true, 'expected_enabled' => false])->status);
        $c1 = $this->del($admin, 'ruff', 'D100');
        $this->assertSame([200, true], [$c1->status, $c1->decoded()['data']['cleared']], $c1->body);
        $c2 = $this->del($admin, 'ruff', 'D100');
        $this->assertSame([200, false], [$c2->status, $c2->decoded()['data']['cleared']]);
        $this->assertSame(1, $this->auditCount('rules.override_clear'));
        $this->assertSame(4, $o->revision('ruff'));
        $this->assertSame(0, $o->revision('eslint'), '도구별 개정 번호');
        // 규칙 키에 /·@: 인코딩한 경로
        $ts = $admin->raw('PUT', '/api/v1/admin/rules/eslint/@typescript-eslint/await-thenable/override', (string) json_encode(['enabled' => false, 'expected_enabled' => false]), ['content-type' => 'application/json']);
        $this->assertSame(200, $ts->status, $ts->body);
    }

    public function testConflictAndValidation(): void
    {
        $admin = $this->as('admin');
        $r = $this->put($admin, 'ruff', 'E501', ['enabled' => false, 'expected_enabled' => false]);
        $this->assertSame(409, $r->status, $r->body);
        $err = $r->decoded()['error'];
        $this->assertSame('rule_override_conflict', $err['code']);
        $this->assertTrue($err['details']['current']['enabled'], '지금 상태를 돌려줌');
        $this->assertSame(400, $this->put($admin, 'ruff', 'E501', ['enabled' => 'no', 'expected_enabled' => true])->status);
        $this->assertSame(400, $this->put($admin, 'ruff', 'E501', ['enabled' => false])->status);
        $this->assertSame(400, $this->put($admin, 'ruff', 'E501', ['enabled' => false, 'expected_enabled' => true, 'reason' => str_repeat('가', 201)])->status, '사유 200자 초과');
        $this->assertSame(200, $this->put($admin, 'ruff', 'E501', ['enabled' => false, 'expected_enabled' => true, 'reason' => str_repeat('가', 200)])->status);
        // 404: 알 수 없는 키, 활성 버전에 없는 규칙, 이상한 글자
        foreach ([['ruff', 'ZZZ999'], ['eslint', 'gone-rule'], ['eslint', 'a..b']] as [$tool, $rule]) {
            $this->assertSame(404, $this->put($admin, $tool, $rule, ['enabled' => true, 'expected_enabled' => false])->status, $rule);
        }
        $this->assertSame(404, $this->put($admin, 'pylint', 'X1', ['enabled' => true, 'expected_enabled' => false])->status, '라우트 없음');
    }

    public function testNotEnableableReasons(): void
    {
        $admin = $this->as('admin');
        $cases = [['ruff', 'PRV001', 'preview'], ['ruff', 'RMV001', 'removed'], ['ruff', 'DEP001', 'deprecated'], ['ruff', 'TID251', 'requires_options'],
            ['eslint', '@typescript-eslint/await-thenable', 'requires_type_info'], ['eslint', 'id-match', 'requires_options']];
        foreach ($cases as [$tool, $rule, $reason]) {
            $r = $this->put($admin, $tool, $rule, ['enabled' => true, 'expected_enabled' => false]);
            $this->assertSame(422, $r->status, $rule . ' ' . $r->body);
            $this->assertSame(['rule_not_enableable', $reason], [$r->decoded()['error']['code'], $r->decoded()['error']['details']['reason']], $rule);
            $it = $this->item($admin, $tool, $rule);
            $this->assertSame([false, $reason], [$it['enableable'], $it['enable_block']], $rule);
        }
        foreach (['/api/v1/admin/rules/syntax-error/override', '/api/v1/admin/rules/eslint/no-rule-id/override'] as $path) {
            $r = $admin->raw('PUT', $path, (string) json_encode(['enabled' => true, 'expected_enabled' => false]), ['content-type' => 'application/json']);
            $this->assertSame(422, $r->status, $path);
            $this->assertSame('special_key', $r->decoded()['error']['details']['reason'], $path);
        }
        $this->assertSame(422, $admin->raw('DELETE', '/api/v1/admin/rules/syntax-error/override', '', ['content-type' => 'application/json'])->status);
        // 폐기 예정은 끌 수만 있다(기본 켜짐 DEP002)
        $r = $this->put($admin, 'ruff', 'DEP002', ['enabled' => false, 'expected_enabled' => true]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(0, $this->auditCount('rules.override_reset_all'));
    }

    public function testReadVisibilityAndFilters(): void
    {
        $admin = $this->as('admin');
        $kim = $this->as('kim');
        $this->assertSame(200, $this->put($admin, 'eslint', 'no-undef', ['enabled' => false, 'expected_enabled' => true, 'reason' => 'legacy code'])->status);
        $this->assertSame(200, $this->put($admin, 'eslint', 'eqeqeq', ['enabled' => true, 'expected_enabled' => false])->status);
        $a = $admin->get('/api/v1/rules/eslint/no-undef')->decoded()['data']['analysis'];
        $this->assertSame([false, true, true, 'all'], [$a['enabled'], $a['base_enabled'], $a['overridden'], $a['scope']]);
        $this->assertSame('legacy code', $a['override']['reason']);
        $this->assertSame('admin', $a['override']['changed_by_name']);
        $k = $kim->get('/api/v1/rules/eslint/no-undef');
        $this->assertSame(200, $k->status);
        $ka = $k->decoded()['data']['analysis'];
        $this->assertSame([false, true, null], [$ka['enabled'], $ka['overridden'], $ka['override']], '일반 사용자: 변경자·사유 없음');
        $this->assertNotContains('legacy code', $k->body);
        $this->assertSame('server_default', $kim->get('/api/v1/rules/ruff/F401')->decoded()['data']['analysis']['scope']);
        // 상세의 열린 결함 수: admin = 서버 전체 3, kim = 볼 수 있는 프로젝트만 0
        $this->assertSame(3, $admin->get('/api/v1/rules/eslint/no-undef')->decoded()['data']['defects']['total']);
        $this->assertSame(0, $k->decoded()['data']['defects']['total']);
        // 필터: 분석 적용·관리자 변경
        $keys = static fn (Response $r): array => array_column($r->decoded()['data'], 'rule');
        $this->assertSame(['eqeqeq', 'no-undef'], $keys($kim->get('/api/v1/rules', ['tool' => 'eslint', 'overridden' => '1'])));
        $this->assertSame(['eqeqeq'], $keys($kim->get('/api/v1/rules', ['tool' => 'eslint', 'analysis' => '1'])));
        $this->assertSame(['no-undef'], $keys($kim->get('/api/v1/rules', ['tool' => 'eslint', 'default_enabled' => '1'])), '서버 기본값 조건도 그대로');
        $this->assertSame(400, $kim->get('/api/v1/rules', ['overridden' => '0'])->status);
        $this->assertSame(400, $kim->get('/api/v1/rules', ['analysis' => 'x'])->status);
        // 관리 목록(admin): 요약·상태
        $l = $admin->get('/api/v1/admin/rules/overrides', ['tool' => 'eslint']);
        $this->assertSame(200, $l->status, $l->body);
        $doc = $l->decoded();
        $this->assertSame(2, $doc['page']['total']);
        $this->assertSame(['active', 'active'], array_column($doc['data'], 'state'));
        $this->assertSame([2, 1, 1], [$doc['summary']['eslint']['count'], $doc['summary']['eslint']['enabled'], $doc['summary']['eslint']['disabled']]);
        $this->assertSame(12, strlen($doc['summary']['eslint']['config_hash']));
        $this->assertSame(400, $admin->get('/api/v1/admin/rules/overrides', ['state' => 'bogus'])->status);
    }

    public function testSnapshotSkipsMissingAndBlocked(): void
    {
        $admin = $this->as('admin');
        $o = $this->app->ruleOverrides();
        $this->assertSame(null, $o->snapshotFor('ruff', '0.1.0'), '변경 없음 = null(지금과 같은 실행)');
        $this->assertSame(200, $this->put($admin, 'ruff', 'E501', ['enabled' => false, 'expected_enabled' => true])->status);
        $this->assertSame(200, $this->put($admin, 'ruff', 'D100', ['enabled' => true, 'expected_enabled' => false])->status);
        // 활성 버전에 없는 규칙·켤 수 없게 된 규칙(직접 넣어 흉내: 도구 버전이 바뀐 경우)
        $db = $this->app->db();
        $db->execute("INSERT INTO rule_overrides (tool, rule, enabled, base_enabled, tool_version, changed_at) VALUES ('ruff', 'OLD001', 1, 0, '0.0.9', '2026-10-06T00:00:00Z')");
        $db->execute("INSERT INTO rule_overrides (tool, rule, enabled, base_enabled, tool_version, changed_at) VALUES ('ruff', 'PRV001', 1, 0, '0.0.9', '2026-10-06T00:00:00Z')");
        $s = $o->snapshotFor('ruff', '0.1.0');
        $this->assertSame(['D100'], $s['enable']);
        $this->assertSame(['E501'], $s['disable']);
        $this->assertSame([['rule' => 'OLD001', 'why' => 'missing'], ['rule' => 'PRV001', 'why' => 'preview']], $s['skipped']);
        $this->assertSame('0.1.0', $s['version']);
        $this->assertSame('0.1.0', $o->snapshotFor('ruff', '9.9.9')['version'], '적재 전 도구 버전이면 활성 적재 버전으로 확인');
        $list = $admin->get('/api/v1/admin/rules/overrides', ['tool' => 'ruff'])->decoded()['data'];
        $states = array_column($list, 'state', 'rule');
        $this->assertSame(['D100' => 'active', 'E501' => 'active', 'OLD001' => 'missing', 'PRV001' => 'not_enableable'], $states);
        $this->assertSame(['OLD001'], array_column($admin->get('/api/v1/admin/rules/overrides', ['tool' => 'ruff', 'state' => 'missing'])->decoded()['data'], 'rule'));
        // 활성 버전에 없는 규칙의 변경도 되돌릴 수 있다
        $this->assertSame(true, $this->del($admin, 'ruff', 'OLD001')->decoded()['data']['cleared']);
        // 스냅샷은 그때의 값: 이후 변경은 이미 읽은 스냅샷에 영향 없음(분석은 시작할 때 한 번 읽는다)
        $before = $o->snapshotFor('ruff', '0.1.0');
        $this->assertSame(200, $this->put($admin, 'ruff', 'E501', ['enabled' => true, 'expected_enabled' => false])->status);
        $this->assertSame(['E501'], $before['disable']);
        $this->assertSame([], $o->snapshotFor('ruff', '0.1.0')['disable']);
    }

    public function testResetAllConfirmAndLimit(): void
    {
        $admin = $this->as('admin');
        foreach (['E501' => true, 'F401' => true, 'D100' => false] as $rule => $base) {
            $this->assertSame(200, $this->put($admin, 'ruff', $rule, ['enabled' => !$base, 'expected_enabled' => $base])->status, $rule);
        }
        $this->assertSame(200, $this->put($admin, 'eslint', 'eqeqeq', ['enabled' => true, 'expected_enabled' => false])->status);
        $bad = $admin->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'ruff', 'confirm' => 'eslint']);
        $this->assertSame([400, 'rule_reset_confirm_mismatch'], [$bad->status, $bad->decoded()['error']['code']]);
        $this->assertSame(400, $admin->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'pylint', 'confirm' => 'pylint'])->status);
        // 상한 2(시험 설정): 첫 번째 2건, 남은 1건
        $r1 = $admin->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'ruff', 'confirm' => 'RUFF']);
        $this->assertSame(200, $r1->status, $r1->body);
        $this->assertSame([2, 1], [$r1->decoded()['data']['reset'], $r1->decoded()['data']['remaining']]);
        $r2 = $admin->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'ruff', 'confirm' => 'ruff'])->decoded()['data'];
        $this->assertSame([1, 0], [$r2['reset'], $r2['remaining']]);
        $r3 = $admin->post('/api/v1/admin/rules/overrides/reset', ['tool' => 'ruff', 'confirm' => 'ruff'])->decoded()['data'];
        $this->assertSame([0, 0], [$r3['reset'], $r3['remaining']], '멱등');
        $this->assertSame(2, $this->auditCount('rules.override_reset_all'), '바꾼 것이 있을 때만 감사');
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM rule_overrides WHERE tool = 'eslint'"), '다른 도구는 그대로');
    }
}
