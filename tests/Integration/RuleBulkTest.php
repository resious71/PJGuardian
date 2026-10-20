<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Rules\RuleEnrichment;
use PJGuardian\Services\Rules\RuleLoader;
use PJGuardian\Services\Rules\RuleOverrides;
use PJGuardian\Services\Rules\RuleSource;
use PJGuardian\Support\Logger;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 규칙 목록 일괄 변경(P22C): 접근 통제, 일반 사용자 화면에 변경 UI 없음, 미리보기 수치 = 적용 결과, 한 트랜잭션(중간 실패면 전부 되돌림),
 * 개정 번호 한 번 +1, 미리보기 뒤 다른 변경이면 409, 상한(bulk_too_large, 경계), 키 목록(없는 키·다른 도구 키·특수 키는 건너뜀, 잘못된 글자 400),
 * 검색 조건(서버 재조회), 규칙 키 접두사 조건, 감사 로그(100개 상한).
 * 시료: ruff 0.1.0(F401·E501·E502 켜짐, D100~D102 꺼짐, D999 미리보기, 삭제·폐기 예정·옵션 필요, X001~X125 꺼짐), ESLint 2.0.0.
 */
final class RuleBulkTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $root = $this->tempDir('rbulk');
        mkdir($root . '/work');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work'], 'rules' => ['bulk_max' => '130']]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $admin = $this->app->users()->get($this->u['admin']);
        $g = (int) $this->app->groups()->create($admin, null, ['name' => 'G'])['id'];
        $this->app->groups()->addMember($admin, null, $g, 'mgr');
        $this->app->groups()->setManager($admin, null, $g, $this->u['mgr'], true);
        $py = TestDb::project($db, 'py-proj', 'python');
        $this->app->projectAccess()->add(Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null), $py, ['subject_type' => 'user', 'login_id' => 'kim']);
        $ap = TestDb::analysis($db, $py, $this->u['admin']);
        foreach (['ruff:D100', 'ruff:D100', 'ruff:D101', 'ruff:E501'] as $k) {
            TestDb::defect($db, $py, $ap, ['rule_key' => $k, 'status' => 'open']);
        }
        TestDb::defect($db, $py, $ap, ['rule_key' => 'ruff:D102', 'status' => 'resolved']);
        $this->load();
    }

    private function load(): void
    {
        $rule = static fn (string $r, array $o = []): array => array_merge(['rule' => $r, 'name' => "name-{$r}", 'summary' => "summary {$r}", 'description' => '', 'category' => 'x',
            'origin' => '', 'default_enabled' => false, 'fixable' => 'none', 'has_suggestions' => false, 'has_options' => null, 'status' => 'stable',
            'deprecation' => null, 'docs_url' => null, 'recommended' => false, 'requires_type_info' => false], $o);
        $ruff = [
            'F401' => $rule('F401', ['default_enabled' => true]), 'E501' => $rule('E501', ['default_enabled' => true]), 'E502' => $rule('E502', ['default_enabled' => true]),
            'D100' => $rule('D100', ['category' => 'docs']), 'D101' => $rule('D101', ['category' => 'docs']), 'D102' => $rule('D102', ['category' => 'docs']),
            'D999' => $rule('D999', ['status' => 'preview', 'category' => 'docs']),
            'RMV001' => $rule('RMV001', ['status' => 'removed']), 'UP002' => $rule('UP002', ['status' => 'deprecated', 'default_enabled' => true]), 'TID251' => $rule('TID251'),
        ];
        for ($i = 1; $i <= 125; $i++) {
            $code = sprintf('X%03d', $i);
            $ruff[$code] = $rule($code, ['category' => 'many']);
        }
        $eslint = [
            'no-undef' => $rule('no-undef', ['default_enabled' => true]), 'eqeqeq' => $rule('eqeqeq'), 'id-match' => $rule('id-match'),
            '@typescript-eslint/await-thenable' => $rule('@typescript-eslint/await-thenable', ['requires_type_info' => true]),
            '@typescript-eslint/no-explicit-any' => $rule('@typescript-eslint/no-explicit-any', ['default_enabled' => true]),
        ];
        $data = ['ruff' => ['0.1.0' => $ruff], 'eslint' => ['2.0.0' => $eslint]];
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
        $loader->loadVersions('eslint', ['2.0.0'], '2.0.0', true, $report);
        $loader->rebuildIndex();
        $this->assertSame([], $report['errors']);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private function preview(TestClient $c, array $body): Response
    {
        return $c->post('/api/v1/admin/rules/overrides/bulk/preview', $body);
    }

    private function apply(TestClient $c, array $body): Response
    {
        return $c->post('/api/v1/admin/rules/overrides/bulk', $body);
    }

    private function enabledRuff(): array
    {
        return array_column($this->app->db()->all("SELECT rule, enabled FROM rule_overrides WHERE tool = 'ruff' ORDER BY rule"), 'enabled', 'rule');
    }

    public function testAccessControlAndAdminOnlyMarkup(): void
    {
        $body = ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'keys', 'keys' => ['D100']]];
        $anon = new TestClient($this->app);
        $anon->fetchCsrf();
        $this->assertSame(401, $this->preview($anon, $body)->status);
        $this->assertSame(401, $this->apply($anon, $body + ['expected_revision' => 0])->status);
        foreach (['kim', 'mgr'] as $login) {
            $c = $this->as($login);
            $this->assertSame(403, $this->preview($c, $body)->status, $login);
            $this->assertSame(403, $this->apply($c, $body + ['expected_revision' => 0])->status, $login);
            $page = $c->get('/rules')->body;
            foreach (['data-bulk-bar', 'data-select-all', 'rules-table--admin'] as $marker) {
                $this->assertNotContains($marker, $page, "{$login}: 변경 UI({$marker}) 없음");
            }
            $this->assertContains('data-admin="0"', $page);
        }
        $admin = $this->as('admin');
        $this->assertSame(403, $admin->post('/api/v1/admin/rules/overrides/bulk/preview', $body, false)->status, 'CSRF 없으면 거부');
        $page = $admin->get('/rules')->body;
        foreach (['data-bulk-bar', 'data-select-all', 'rules-table--admin', 'data-admin="1"'] as $marker) {
            $this->assertContains($marker, $page, "admin: {$marker}");
        }
        $this->assertSame([], $this->enabledRuff());
    }

    public function testPreviewMatchesApplyRevisionOnceAndAudit(): void
    {
        $admin = $this->as('admin');
        $o = $this->app->ruleOverrides();
        $target = ['mode' => 'filter', 'filter' => ['language' => 'python', 'prefix' => 'd', 'q' => '', 'sort' => 'name', 'page' => '3']];
        $p = $this->preview($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => $target]);
        $this->assertSame(200, $p->status, $p->body);
        $pv = $p->decoded()['data'];
        $this->assertSame([4, 3, 3, 0, 0, 1, 1], [$pv['total'], $pv['changes'], $pv['turn_on'], $pv['turn_off'], $pv['unchanged'], $pv['skipped']['preview'], $pv['skipped_total']]);
        $this->assertSame(3, $pv['open_defects'], 'D100 2 + D101 1(해결된 D102는 빼고), 서버 전체');
        $this->assertSame(['D100', 'D101', 'D102'], array_column($pv['examples'], 'rule'));
        $this->assertSame(['ruff:D100', 'name-D100', false, true], [$pv['examples'][0]['key'], $pv['examples'][0]['name'], $pv['examples'][0]['from'], $pv['examples'][0]['to']]);
        $this->assertSame(0, $pv['revision']);
        $this->assertSame([], $this->enabledRuff(), '미리보기는 쓰지 않음');

        $a = $this->apply($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => $target, 'reason' => '문서화 규칙 일괄', 'expected_revision' => $pv['revision']]);
        $this->assertSame(200, $a->status, $a->body);
        $ad = $a->decoded()['data'];
        $this->assertSame([$pv['changes'], $pv['unchanged'], $pv['skipped'], 1], [$ad['changes'], $ad['unchanged'], $ad['skipped'], $ad['revision']], '미리보기 수치 = 적용 결과, 개정 번호 한 번 +1');
        $this->assertSame(['D100' => 1, 'D101' => 1, 'D102' => 1], $this->enabledRuff());
        $this->assertSame('문서화 규칙 일괄', $this->app->db()->value("SELECT reason FROM rule_overrides WHERE rule = 'D100'"));
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'rules.override_set'"), '일괄은 개별 감사를 남기지 않음');
        $det = json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'rules.override_bulk'"), true);
        $this->assertSame(['ruff', 'enable', 'filter', 'D', 3, ['preview' => 1], ['from' => 0, 'to' => 1], '문서화 규칙 일괄', ['D100', 'D101', 'D102'], 0],
            [$det['tool'], $det['action'], $det['target']['mode'], $det['target']['filter']['prefix'], $det['changed'], $det['skipped'], $det['revision'], $det['reason'], $det['rules'], $det['rules_more']]);
        // 같은 일괄 다시: 바뀌는 것 없음 → 개정 번호·감사 그대로
        $again = $this->apply($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => $target, 'expected_revision' => 1])->decoded()['data'];
        $this->assertSame([0, 3, 1], [$again['changes'], $again['unchanged'], $again['revision']]);
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'rules.override_bulk'"));
        $this->assertSame(1, $o->revision('ruff'));
        // 기본값으로(관리자 변경 = 조건 overridden=1)
        $reset = $this->apply($admin, ['tool' => 'ruff', 'action' => 'reset', 'target' => ['mode' => 'filter', 'filter' => ['language' => 'python', 'overridden' => '1']], 'expected_revision' => 1]);
        $this->assertSame([200, 3, 2], [$reset->status, $reset->decoded()['data']['changes'], $reset->decoded()['data']['revision']], $reset->body);
        $this->assertSame([], $this->enabledRuff());
    }

    public function testKeysModeSkipsUnknownOtherToolAndSpecial(): void
    {
        $admin = $this->as('admin');
        $pv = $this->preview($admin, ['tool' => 'ruff', 'action' => 'disable', 'target' => ['mode' => 'keys', 'keys' => ['E501', 'F401', 'E501', 'NOPE1', 'no-undef', 'UP002', 'D100']]])->decoded()['data'];
        $this->assertSame([6, 3, 1, 2], [$pv['total'], $pv['changes'], $pv['unchanged'], $pv['skipped']['missing']], '중복 키는 하나, 없는 키·다른 도구 키는 활성 버전에 없음으로 건너뜀, 폐기 예정은 끄기 가능');
        $es = $this->preview($admin, ['tool' => 'eslint', 'action' => 'enable', 'target' => ['mode' => 'keys', 'keys' => ['no-rule-id', 'eqeqeq', '@typescript-eslint/await-thenable', 'id-match']]])->decoded()['data'];
        $this->assertSame([1, 1, 1, 1], [$es['changes'], $es['skipped']['special_key'], $es['skipped']['requires_type_info'], $es['skipped']['requires_options']], '켤 수 없는 규칙은 422가 아니라 건너뜀');
        foreach ([['a b'], ['../x'], [], [1]] as $bad) {
            $r = $this->preview($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'keys', 'keys' => $bad]]);
            $this->assertSame(400, $r->status, json_encode($bad));
        }
        $this->assertSame(400, $this->preview($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'keys', 'keys' => array_map(static fn (int $i): string => sprintf('X%03d', $i), range(1, 201))]])->status, 'ui.page_size_max 초과');
        $this->assertSame(404, $this->preview($admin, ['tool' => 'pylint', 'action' => 'enable', 'target' => ['mode' => 'keys', 'keys' => ['D100']]])->status);
        $this->assertSame(400, $this->preview($admin, ['tool' => 'ruff', 'action' => 'toggle', 'target' => ['mode' => 'keys', 'keys' => ['D100']]])->status);
        $this->assertSame(400, $this->preview($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'filter', 'filter' => ['language' => 'javascript']]])->status, '탭(도구)과 다른 조건');
        $this->assertSame(400, $this->apply($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'keys', 'keys' => ['D100']]])->status, 'expected_revision 필수');
        $this->assertSame([], $this->enabledRuff());
    }

    public function testConflictWhenRevisionChangedAfterPreview(): void
    {
        $admin = $this->as('admin');
        $target = ['mode' => 'filter', 'filter' => ['language' => 'python', 'prefix' => 'D']];
        $pv = $this->preview($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => $target])->decoded()['data'];
        // 다른 관리자(같은 서버 함수)가 그 사이 한 건을 바꿈
        $this->assertSame(200, $admin->raw('PUT', '/api/v1/admin/rules/ruff/E501/override', (string) json_encode(['enabled' => false, 'expected_enabled' => true]), ['content-type' => 'application/json'])->status);
        $r = $this->apply($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => $target, 'expected_revision' => $pv['revision']]);
        $this->assertSame(409, $r->status, $r->body);
        $err = $r->decoded()['error'];
        $this->assertSame(['rule_override_conflict', 'revision_changed', 1], [$err['code'], $err['details']['reason'], $err['details']['revision']]);
        $this->assertSame(['E501' => 0], $this->enabledRuff(), '일괄은 하나도 적용되지 않음');
        $ok = $this->apply($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => $target, 'expected_revision' => 1]);
        $this->assertSame([200, 2], [$ok->status, $ok->decoded()['data']['revision']]);
    }

    public function testLimitBoundaryAndAuditCap(): void
    {
        $admin = $this->as('admin');
        // ruff 전체 = 135개 > 130 → 400(잘라서 적용하지 않음)
        $r = $this->preview($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'filter', 'filter' => ['language' => 'python']]]);
        $this->assertSame(400, $r->status);
        $this->assertSame(['bulk_too_large', 135, 130], [$r->decoded()['error']['code'], $r->decoded()['error']['details']['total'], $r->decoded()['error']['details']['max']]);
        $this->assertContains('검색 조건을 좁혀', $r->decoded()['error']['message']);
        // 경계: 접두사 X = 125개. 상한 125면 통과, 124면 400(같은 서버 함수, 상한만 다르게)
        $resolve = fn (): array => $this->app->ruleQuery()->matching(\PJGuardian\Services\Rules\RuleQuery::filter(['tool' => 'ruff', 'prefix' => 'X']), 125);
        $mk = fn (int $max): RuleOverrides => new RuleOverrides($this->app->db(), $this->app->audit(), $this->app->clock, $this->app->logger, 1000, $max);
        $this->assertSame(125, $mk(125)->bulkPreview('ruff', 'enable', $resolve)['changes']);
        $this->assertThrows(ApiException::class, fn () => $mk(124)->bulkPreview('ruff', 'enable', $resolve), 'bulk_too_large');
        // 적용(125개) — 감사 로그의 규칙 키는 100개까지 + 나머지 수
        $a = $this->apply($admin, ['tool' => 'ruff', 'action' => 'enable', 'target' => ['mode' => 'filter', 'filter' => ['language' => 'python', 'prefix' => 'x']], 'expected_revision' => 0]);
        $this->assertSame([200, 125], [$a->status, $a->decoded()['data']['changes']], $a->body);
        $det = json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'rules.override_bulk'"), true);
        $this->assertSame([100, 25, 'X001', 'X100'], [count($det['rules']), $det['rules_more'], $det['rules'][0], $det['rules'][99]]);
    }

    public function testSingleTransactionRollsBackOnFailure(): void
    {
        $admin = $this->app->users()->get($this->u['admin']);
        $db = $this->app->db();
        // D102 쓰기에서 실패하게 만든다 → D100·D101도 되돌아가야 한다
        $db->pdo()->exec("CREATE TEMP TRIGGER fail_d102 BEFORE INSERT ON rule_overrides WHEN NEW.rule = 'D102' BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $o = $this->app->ruleOverrides();
        $resolve = fn (): array => $this->app->ruleQuery()->matching(\PJGuardian\Services\Rules\RuleQuery::filter(['tool' => 'ruff', 'prefix' => 'D']), 1000);
        $this->assertThrows(\PDOException::class, fn () => $o->bulkApply($admin, null, 'ruff', 'enable', $resolve, ['mode' => 'filter'], null, 0), 'boom');
        $this->assertSame([], $this->enabledRuff(), '전부 되돌아감');
        $this->assertSame(0, $o->revision('ruff'));
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'rules.override_bulk'"));
        $db->pdo()->exec('DROP TRIGGER fail_d102');
        $this->assertSame(3, $o->bulkApply($admin, null, 'ruff', 'enable', $resolve, ['mode' => 'filter'], null, 0)['changes']);
    }

    public function testPrefixFilter(): void
    {
        $kim = $this->as('kim');
        $keys = fn (array $q): array => array_column($kim->get('/api/v1/rules', $q + ['size' => '200'])->decoded()['data'], 'rule');
        $this->assertSame(['D100', 'D101', 'D102', 'D999'], $keys(['tool' => 'ruff', 'prefix' => 'd']), 'ruff는 대문자로 맞춤');
        $this->assertSame(['E501', 'E502'], $keys(['tool' => 'ruff', 'prefix' => 'E5']));
        $this->assertSame(['@typescript-eslint/await-thenable', '@typescript-eslint/no-explicit-any'], $keys(['tool' => 'eslint', 'prefix' => '@typescript-eslint/']));
        $this->assertSame(['no-undef'], $keys(['tool' => 'eslint', 'prefix' => 'NO-']), 'ESLint는 소문자로 맞춤');
        $this->assertSame(['D100', 'D101', 'D102'], $keys(['tool' => 'ruff', 'prefix' => 'D1', 'category' => 'docs']), '다른 조건과 함께');
        $this->assertSame([], $keys(['tool' => 'ruff', 'prefix' => 'ZZ']));
        foreach (['a b', str_repeat('A', 65), 'D%', "D'"] as $bad) {
            $this->assertSame(400, $kim->get('/api/v1/rules', ['tool' => 'ruff', 'prefix' => $bad])->status, $bad);
        }
        $this->assertSame(['D100', 'D101', 'D102', 'D999'], $keys(['prefix' => 'D', 'tool' => 'ruff', 'q' => '']), '빈 검색어는 조건 없음');
    }
}
