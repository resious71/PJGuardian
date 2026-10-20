<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Rules\RuleEnrichment;
use PJGuardian\Services\Rules\RuleLoader;
use PJGuardian\Services\Rules\RuleSource;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 규칙 설명(P22): 적재(고정 시료 데이터, 실제 도구는 RuleLoadTest), 목록·검색·필터, 상세(심각도 근거·변경 내역·활성 버전에 없는 규칙),
 * 볼 수 있는 프로젝트의 결함 수, admin 다시 불러오기, 결함 행의 rule_missing.
 * 프로젝트: py(kim 매핑), js(lee 매핑). mgr = 그룹 관리자(프로젝트 매핑 없음).
 */
final class RulesTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $u = [];
    private int $py;
    private int $js;
    /** @var array<string, string> 근거 단계 → ruff 규칙(시료) */
    private array $byLevel = [];

    public function setUp(): void
    {
        $root = $this->tempDir('rules');
        mkdir($root . '/work');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work']]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $admin = $this->app->users()->get($this->u['admin']);
        $g = (int) $this->app->groups()->create($admin, null, ['name' => 'G'])['id'];
        $this->app->groups()->addMember($admin, null, $g, 'mgr');
        $this->app->groups()->setManager($admin, null, $g, $this->u['mgr'], true);
        $this->py = TestDb::project($db, 'py-proj', 'python');
        $this->js = TestDb::project($db, 'secret-js', 'javascript');
        $actor = Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null);
        $this->app->projectAccess()->add($actor, $this->py, ['subject_type' => 'user', 'login_id' => 'kim']);
        $this->app->projectAccess()->add($actor, $this->js, ['subject_type' => 'user', 'login_id' => 'lee']);
        $ap = TestDb::analysis($db, $this->py, $this->u['admin']);
        $aj = TestDb::analysis($db, $this->js, $this->u['admin'], ['tool' => 'eslint']);
        $js = ['tool' => 'eslint', 'language' => 'javascript'];
        foreach ([['ruff:F401', 'open'], ['ruff:F401', 'in_progress'], ['ruff:F401', 'resolved']] as [$k, $st]) {
            TestDb::defect($db, $this->py, $ap, ['rule_key' => $k, 'status' => $st]);
        }
        foreach ([['eslint:no-undef', 'open'], ['eslint:no-undef', 'open'], ['eslint:gone-rule', 'open'], ['eslint:@typescript-eslint/no-unused-vars', 'open']] as [$k, $st]) {
            TestDb::defect($db, $this->js, $aj, ['rule_key' => $k, 'status' => $st] + $js);
        }
        $this->load();
    }

    /** 시료 데이터 적재: ruff 0.1.0(하나뿐 = 기준), ESLint 1.0.0(기준) → 2.0.0(활성) */
    private function load(): void
    {
        $map = $this->app->severityMap();
        // 기본 심각도 근거 단계마다 하나씩(P21S 매핑에서 찾음): 개별·접두사·카테고리·미정의
        foreach (array_keys($map->catalog('ruff')) as $code) {
            $level = $map->explain('ruff', 'ruff:' . $code)['level'];
            $this->byLevel[$level] ??= (string) $code;
        }
        $this->byLevel['none'] = 'ZZZ999';
        $rule = static fn (string $r, array $o = []): array => array_merge(['rule' => $r, 'name' => $r, 'summary' => "summary {$r}", 'description' => '', 'category' => null,
            'origin' => '', 'default_enabled' => false, 'fixable' => 'none', 'has_suggestions' => false, 'has_options' => null, 'status' => 'stable',
            'deprecation' => null, 'docs_url' => null, 'recommended' => false], $o);
        $ruff = ['F401' => $rule('F401', ['name' => 'unused-import', 'summary' => '`{name}` imported but unused', 'description' => "## What it does\nChecks for unused imports. 100%_sure \"quoted\"",
            'category' => 'suspicious', 'origin' => 'Pyflakes', 'default_enabled' => true, 'fixable' => 'sometimes'])];
        foreach ($this->byLevel as $level => $code) {
            $ruff[$code] ??= $rule($code, ['category' => $level === 'none' ? null : ($map->catalog('ruff')[$code]['group'] ?? null), 'summary' => "level {$level}"]);
        }
        $eslint1 = [
            'no-undef' => $rule('no-undef', ['summary' => 'Disallow undeclared', 'category' => 'problem', 'default_enabled' => true, 'docs_url' => 'https://eslint.org/docs/latest/rules/no-undef']),
            'old-rule' => $rule('old-rule', ['status' => 'deprecated', 'deprecation' => ['message' => null, 'since' => '1.0.0', 'replaced_by' => ['new-rule']], 'category' => 'suggestion']),
            'gone-rule' => $rule('gone-rule', ['category' => 'layout', 'summary' => 'gone summary']),
            '@typescript-eslint/no-unused-vars' => $rule('@typescript-eslint/no-unused-vars', ['origin' => '@typescript-eslint', 'category' => 'problem']),
        ];
        $eslint2 = [
            'no-undef' => $eslint1['no-undef'] + [],
            'new-rule' => $rule('new-rule', ['category' => 'suggestion']),
            '@typescript-eslint/no-unused-vars' => array_merge($eslint1['@typescript-eslint/no-unused-vars'], ['fixable' => 'always']),
            'brand-new' => $rule('brand-new', ['category' => 'problem', 'summary' => '한국어 설명 포함']),
        ];
        $eslint2['no-undef']['summary'] = 'Disallow the use of undeclared variables';
        $data = ['ruff' => ['0.1.0' => $ruff], 'eslint' => ['1.0.0' => $eslint1, '2.0.0' => $eslint2]];
        $source = new class ($data) implements RuleSource {
            public function __construct(private readonly array $data)
            {
            }

            public function extract(string $tool, string $version): array
            {
                return ['version' => $version, 'components' => $tool === 'eslint' ? ['@typescript-eslint/eslint-plugin' => '8.0.0'] : [], 'rules' => $this->data[$tool][$version]];
            }
        };
        $loader = $this->loader($source);
        $report = ['tools' => [], 'changed' => false, 'errors' => []];
        $loader->loadVersions('ruff', ['0.1.0'], '0.1.0', true, $report);
        $loader->loadVersions('eslint', ['1.0.0', '2.0.0'], '2.0.0', true, $report);
        $loader->rebuildIndex();
        $this->assertSame([], $report['errors']);
    }

    private function loader(RuleSource $source): RuleLoader
    {
        return new RuleLoader($this->app->db(), $source, $this->app->toolRegistry(), $this->app->severityMap(),
            new RuleEnrichment(PJG_ROOT . '/resources/rules/enrichment'), $this->app->audit(), $this->app->clock);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return list<string> 목록 결과의 key */
    private function keys(TestClient $c, array $q): array
    {
        $r = $c->get('/api/v1/rules', $q);
        $this->assertSame(200, $r->status, $r->body);
        return array_column($r->decoded()['data'], 'key');
    }

    public function testAuthListFiltersSortPaging(): void
    {
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get('/api/v1/rules')->status);
        $this->assertSame(401, $anon->get('/api/v1/rules/ruff/F401')->status);
        $this->assertSame(302, $anon->get('/rules')->status, '화면은 로그인으로');
        $kim = $this->as('kim');
        // 활성 버전만: ESLint는 2.0.0(old-rule·gone-rule 없음)
        $all = $this->keys($kim, ['size' => '200']);
        $this->assertTrue(in_array('eslint:brand-new', $all, true) && !in_array('eslint:gone-rule', $all, true) && !in_array('eslint:old-rule', $all, true), json_encode($all));
        $this->assertSame(['eslint:@typescript-eslint/no-unused-vars', 'eslint:brand-new', 'eslint:new-rule', 'eslint:no-undef'], $this->keys($kim, ['tool' => 'eslint']));
        $this->assertSame(['eslint:@typescript-eslint/no-unused-vars', 'eslint:brand-new', 'eslint:no-undef'], $this->keys($kim, ['tool' => 'eslint', 'category' => 'problem']));
        $this->assertSame(['ruff:F401'], $this->keys($kim, ['default_enabled' => '1', 'tool' => 'ruff']));
        $this->assertSame(['eslint:@typescript-eslint/no-unused-vars', 'ruff:F401'], $this->keys($kim, ['fixable' => '1']));
        $this->assertSame(['eslint:brand-new', 'eslint:new-rule'], $this->keys($kim, ['change' => 'new']));
        $this->assertSame(['eslint:@typescript-eslint/no-unused-vars', 'eslint:no-undef'], $this->keys($kim, ['change' => 'changed']));
        $this->assertSame([], array_values(array_filter($this->keys($kim, ['change' => 'unchanged', 'tool' => 'eslint']))), 'ESLint는 모두 신설·변경');
        // 기본 심각도(다중), 정렬, 쪽
        $sev = $this->app->severityMap()->explain('ruff', 'ruff:F401')['severity'];
        $this->assertTrue(in_array('ruff:F401', $this->keys($kim, ['severity' => $sev . ',not_defined']), true));
        $this->assertSame(['ruff:ZZZ999'], $this->keys($kim, ['severity' => 'not_defined', 'tool' => 'ruff']));
        $desc = $this->keys($kim, ['tool' => 'eslint', 'sort' => 'rule', 'order' => 'desc']);
        $this->assertSame('eslint:no-undef', $desc[0]);
        $page = $kim->get('/api/v1/rules', ['tool' => 'eslint', 'size' => '2', 'page' => '2'])->decoded();
        $this->assertSame([4, ['eslint:new-rule', 'eslint:no-undef']], [$page['page']['total'], array_column($page['data'], 'key')]);
        // 목록에는 설명 본문이 없다(요약만)
        $this->assertFalse(array_key_exists('description', $page['data'][0]));
        // 잘못된 값은 400
        foreach ([['tool' => 'x'], ['severity' => 'bad'], ['default_enabled' => '2'], ['change' => 'z'], ['sort' => 'id'], ['order' => 'up'], ['q' => str_repeat('a', 101)]] as $bad) {
            $r = $kim->get('/api/v1/rules', $bad);
            $this->assertSame([400, 'invalid_rule_filter'], [$r->status, $r->decoded()['error']['code']], json_encode($bad));
        }
        // 메타: 기준 버전·구성 요소·카테고리
        $meta = $kim->get('/api/v1/rules/meta')->decoded()['data'];
        $this->assertSame(['2.0.0', ['@typescript-eslint/eslint-plugin' => '8.0.0'], false, ['2.0.0', '1.0.0']],
            [$meta['tools']['eslint']['version'], $meta['tools']['eslint']['components'], $meta['tools']['eslint']['baseline'], $meta['tools']['eslint']['versions']]);
        $this->assertSame(true, $meta['tools']['ruff']['baseline']);
        $this->assertSame(['layout'], array_values(array_diff(['problem', 'suggestion', 'layout'], $meta['tools']['eslint']['categories'])), '활성 버전의 카테고리만');
    }

    public function testSearchFtsLikeAndSpecialCharacters(): void
    {
        $kim = $this->as('kim');
        $this->assertSame(['ruff:F401'], $this->keys($kim, ['q' => 'imported but']), '요약 부분 일치(대소문자 무시)');
        $this->assertSame(['ruff:F401'], $this->keys($kim, ['q' => 'CHECKS FOR']), '설명 본문 검색');
        $this->assertSame(['ruff:F401'], $this->keys($kim, ['q' => 'f4']), '2글자: 키 LIKE');
        $this->assertSame(['eslint:brand-new'], $this->keys($kim, ['q' => '한국어']), '한국어 입력');
        $this->assertSame(['eslint:@typescript-eslint/no-unused-vars'], $this->keys($kim, ['q' => '@typescript-eslint/no-un']));
        // 특수문자: 색인 구문 오류·LIKE 와일드카드 오동작 없음
        $this->assertSame(['ruff:F401'], $this->keys($kim, ['q' => '100%_sure']));
        $this->assertSame(['ruff:F401'], $this->keys($kim, ['q' => '"quoted"']));
        foreach (['%', '_', '%%', '__', '"', "'", 'a"b', 'NEAR(x y)', 'x OR y', '*', '-x', "'; DROP TABLE rules; --"] as $q) {
            $r = $kim->get('/api/v1/rules', ['q' => $q]);
            $this->assertSame(200, $r->status, $q);
            $this->assertSame(0, $r->decoded()['page']['total'], "특수문자 {$q}는 아무것도 맞지 않음(와일드카드로 쓰이지 않음)");
        }
    }

    public function testDetailSeverityHistoryMissingSpecialAndEncoding(): void
    {
        $kim = $this->as('kim');
        $map = $this->app->severityMap();
        foreach ($this->byLevel as $level => $code) {
            $d = $kim->get("/api/v1/rules/ruff/{$code}")->decoded()['data'];
            $e = $map->explain('ruff', 'ruff:' . $code);
            $this->assertSame([$e['severity'], $level, $e['reason']], [$d['severity']['value'], $d['severity']['level'], $d['severity']['reason']], "근거 {$level}: {$code}");
        }
        $f = $kim->get('/api/v1/rules/ruff/F401')->decoded()['data'];
        $this->assertSame([false, '0.1.0', [], '0.1.0', ['kind' => 'tool'], true, 'sometimes'], [$f['missing'], $f['version'], $f['history'], $f['baseline_version'], $f['source'], $f['default_enabled'], $f['fixable']]);
        $this->assertContains('## What it does', $f['description'], '설명은 마크다운 원문(화면이 안전하게 렌더링)');
        // 변경 내역(최신 버전부터): no-undef 설명 변경
        $n = $kim->get('/api/v1/rules/eslint/no-undef')->decoded()['data'];
        $this->assertSame([['from' => '1.0.0', 'to' => '2.0.0', 'kind' => 'description', 'before' => 'Disallow undeclared', 'after' => 'Disallow the use of undeclared variables']], $n['history']);
        $this->assertSame('https://eslint.org/docs/latest/rules/no-undef', $n['docs_url']);
        // 이름 변경된 규칙(활성 버전에 없음): 안내 + 마지막 버전 정보
        $o = $kim->get('/api/v1/rules/eslint/old-rule')->decoded()['data'];
        $this->assertSame([true, '1.0.0', 'renamed', 'new-rule', '/rules/eslint/new-rule'], [$o['missing'], $o['version'], $o['fate']['kind'], $o['fate']['to'], $o['fate']['href']]);
        $this->assertSame([['rule' => 'new-rule', 'href' => '/rules/eslint/new-rule']], $o['deprecation']['replaced_by']);
        $g = $kim->get('/api/v1/rules/eslint/gone-rule')->decoded()['data'];
        $this->assertSame([true, 'removed', '2.0.0', 'gone summary'], [$g['missing'], $g['fate']['kind'], $g['fate']['version'], $g['summary']]);
        // 새 규칙의 내역에는 이름 변경이 보인다
        $this->assertSame('renamed', $kim->get('/api/v1/rules/eslint/new-rule')->decoded()['data']['history'][0]['kind']);
        // 주소 인코딩: @와 /가 들어간 규칙. 실제 요청은 Request::fromGlobals가 %40을 풀어 같은 경로가 된다(시험 클라이언트는 푼 경로를 넘김,
        // 인코딩된 실제 HTTP 요청은 브라우저 점검 tools/check_rules_ui.mjs가 확인)
        foreach ([rawurldecode('/api/v1/rules/eslint/%40typescript-eslint/no-unused-vars'), '/api/v1/rules/eslint/@typescript-eslint/no-unused-vars'] as $url) {
            $r = $kim->get($url);
            $this->assertSame([200, 'eslint:@typescript-eslint/no-unused-vars'], [$r->status, $r->decoded()['data']['key'] ?? null], $url);
        }
        $this->assertSame(200, $kim->get('/rules/eslint/%40typescript-eslint/no-unused-vars')->status, '화면');
        // 없는 규칙·잘못된 경로 404
        foreach (['/api/v1/rules/eslint/nope', '/api/v1/rules/ruff/F401/../../x', '/api/v1/rules/ruff/%3Cscript%3E', '/api/v1/rules/pylint/x'] as $url) {
            $this->assertSame(404, $kim->get($url)->status, $url);
        }
        // 특수 키(문법 오류)
        $s = $kim->get('/api/v1/rules/syntax-error')->decoded()['data'];
        $this->assertSame([true, 'syntax-error'], [$s['special'], $s['key']]);
        $this->assertSame(200, $kim->get('/rules/syntax-error')->status);
    }

    public function testDefectCountsOnlyInVisibleProjects(): void
    {
        $kim = $this->as('kim');
        $f = $kim->get('/api/v1/rules/ruff/F401')->decoded()['data']['defects'];
        $this->assertSame(['total' => 2, 'projects' => [['id' => $this->py, 'name' => 'py-proj', 'count' => 2, 'href' => "/projects/{$this->py}?rule=ruff%3AF401"]]], $f, '열린 결함만(resolved 제외)');
        $body = $kim->get('/api/v1/rules/eslint/no-undef')->body;
        $this->assertSame(0, json_decode($body, true)['data']['defects']['total']);
        $this->assertNotContains('secret-js', $body, '보이지 않는 프로젝트 이름 없음');
        $this->assertSame(2, $this->as('lee')->get('/api/v1/rules/eslint/no-undef')->decoded()['data']['defects']['total']);
        $this->assertSame(0, $this->as('mgr')->get('/api/v1/rules/eslint/no-undef')->decoded()['data']['defects']['total']);
        $this->assertSame(4, $this->as('admin')->get('/api/v1/rules')->decoded()['page']['total'] >= 4 ? 4 : -1);
    }

    /** X08: 언어 탭 — API의 language(= tool), 탭 머리글의 도구별 총수, 화면의 탭 구조·상세의 탭 링크·목록으로 */
    public function testLanguageTabsApiAndPages(): void
    {
        $kim = $this->as('kim');
        $this->assertSame($this->keys($kim, ['tool' => 'ruff', 'size' => '200']), $this->keys($kim, ['language' => 'python', 'size' => '200']));
        $this->assertSame(['eslint:@typescript-eslint/no-unused-vars', 'eslint:brand-new', 'eslint:new-rule', 'eslint:no-undef'], $this->keys($kim, ['language' => 'javascript']));
        $this->assertSame(4, count($this->keys($kim, ['language' => 'javascript', 'tool' => 'eslint'])), '같은 뜻이면 함께 와도 됨');
        foreach ([['language' => 'python', 'tool' => 'eslint'], ['language' => 'typescript'], ['language' => 'ruff']] as $bad) {
            $r = $kim->get('/api/v1/rules', $bad);
            $this->assertSame([400, 'invalid_rule_filter', 'language'], [$r->status, $r->decoded()['error']['code'], $r->decoded()['error']['details']['field']], json_encode($bad));
        }
        $meta = $kim->get('/api/v1/rules/meta')->decoded()['data']['tools'];
        $this->assertSame([count($this->byLevel) + 1, 4], [$meta['ruff']['rules'], $meta['eslint']['rules']], '탭 머리글의 도구별 총 규칙 수(활성 버전)');
        // 목록 화면: ARIA 탭 두 개(Python · ruff, JavaScript/TypeScript · ESLint), 도구 필터·도구 열 없음
        $html = $kim->get('/rules')->body;
        $this->assertContains('role="tablist"', $html);
        $this->assertContains('id="rules-tab-python" aria-controls="rules-panel"', $html);
        $this->assertContains('id="rules-tab-javascript" aria-controls="rules-panel"', $html);
        $this->assertContains('role="tabpanel"', $html);
        $this->assertNotContains('id="rf-tool"', $html, '도구 필터 없음');
        $this->assertNotContains('rcol-tool', $html, '도구 열 없음');
        // 상세: 그 규칙의 언어 탭이 선택된 링크, 목록으로 = 그 탭
        $d = $kim->get('/rules/eslint/@typescript-eslint/no-unused-vars')->body;
        $this->assertContains('href="/rules?language=javascript" data-language="javascript" aria-current="true"', $d);
        $this->assertContains('href="/rules?language=python" data-language="python">', $d);
        $this->assertContains('href="/rules?language=javascript" data-back-to-list', $d);
        $this->assertContains('href="/rules?language=python" data-back-to-list', $kim->get('/rules/ruff/F401')->body);
        $s = $kim->get('/rules/syntax-error')->body;
        $this->assertNotContains('aria-current="true"', $s, '특수 키는 어느 탭도 선택하지 않음');
    }

    public function testReloadAdminOnlyAndDefectRowFlags(): void
    {
        foreach (['kim', 'mgr'] as $who) {
            $this->assertSame(403, $this->as($who)->post('/api/v1/admin/rules/reload')->status, $who);
        }
        $admin = $this->as('admin');
        $this->assertSame(403, $admin->request('POST', '/api/v1/admin/rules/reload', [], [], null, [], false)->status, 'CSRF');
        $r1 = $admin->post('/api/v1/admin/rules/reload');
        $r2 = $admin->post('/api/v1/admin/rules/reload');
        $this->assertSame([202, false, true], [$r1->status, $r1->decoded()['data']['merged'], $r2->decoded()['data']['merged']], '연타 = 요청 하나');
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM admin_tasks WHERE kind = 'rules_load'"));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'rules.reload_request'"));
        $this->assertSame('queued', $admin->get('/api/v1/rules/meta')->decoded()['data']['task']['status']);
        // 결함 표·상세: 활성 버전에 없는 규칙 표시, 링크
        $rows = $this->as('lee')->get("/api/v1/projects/{$this->js}/defects", ['size' => '50'])->decoded()['data'];
        $flags = [];
        foreach ($rows as $row) {
            $flags[$row['rule_key']] = [$row['rule_missing'], $row['rule_url']];
        }
        $this->assertSame([true, '/rules/eslint/gone-rule'], $flags['eslint:gone-rule']);
        $this->assertSame([false, '/rules/eslint/no-undef'], $flags['eslint:no-undef']);
        $this->assertSame([false, '/rules/eslint/%40typescript-eslint/no-unused-vars'], $flags['eslint:@typescript-eslint/no-unused-vars']);
        $gone = (int) $this->app->db()->value("SELECT id FROM defects WHERE rule_key = 'eslint:gone-rule'");
        $html = $this->as('lee')->get("/defects/{$gone}")->body;
        $this->assertContains('data-rule-missing', $html);
        $this->assertContains('href="/rules/eslint/gone-rule"', $html);
    }

    public function testLoaderIsIdempotentAndBaseline(): void
    {
        $db = $this->app->db();
        $before = $db->all('SELECT tool, version, content_hash, loaded_at FROM rule_versions ORDER BY tool, version');
        $changes = (int) $db->value('SELECT COUNT(*) FROM rule_changes');
        $this->load(); // 같은 입력으로 다시
        $this->assertSame($before, $db->all('SELECT tool, version, content_hash, loaded_at FROM rule_versions ORDER BY tool, version'), '해시가 같으면 다시 쓰지 않음');
        $this->assertSame($changes, (int) $db->value('SELECT COUNT(*) FROM rule_changes'));
        $this->assertSame([['eslint', '1.0.0', 1], ['eslint', '2.0.0', 0], ['ruff', '0.1.0', 1]],
            array_map(static fn (array $r): array => [$r['tool'], $r['version'], (int) $r['baseline']], $db->all('SELECT tool, version, baseline FROM rule_versions ORDER BY tool, version')));
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM rule_changes WHERE to_version IN ('1.0.0', '0.1.0')"), '기준 버전은 변경 내역 없음');
    }

    /**
     * P32B 쉬운 설명(명세 11): 규칙 상세 API의 guide(화면 언어 ko·en, 실제 도구로 확인한 것만)와 결함 상세 화면의 같은 구역. 설명이 없는 규칙은 없음.
     */
    public function testRuleGuideInDetailAndDefectPage(): void
    {
        $kim = $this->as('kim');
        $ko = $kim->get('/api/v1/rules/ruff/F401')->decoded()['data']['guide'];
        $this->assertSame(['py', 'ruff'], [$ko['lang'], $ko['checked']['tool']]);
        $this->assertContains('import os', $ko['bad']);
        $this->assertSame(1, preg_match('/[가-힣]/u', $ko['summary']), '한국어 화면 = 한국어 설명');
        $this->assertSame(200, $kim->put('/api/v1/users/me/preferences', ['language' => 'en'])->status);
        $en = $kim->get('/api/v1/rules/ruff/F401')->decoded()['data']['guide'];
        $this->assertSame(0, preg_match('/[가-힣]/u', $en['summary'] . $en['fix']), '영어 화면 = 영어 설명');
        $this->assertSame([$ko['bad'], $ko['good']], [$en['bad'], $en['good']], '코드 예시는 언어 공통');
        $lee = $this->as('lee');
        $this->assertSame('js', $lee->get('/api/v1/rules/eslint/no-undef')->decoded()['data']['guide']['lang']);
        $this->assertSame(null, $lee->get('/api/v1/rules/eslint/gone-rule')->decoded()['data']['guide'] ?? null, '설명이 없는 규칙');
        $f401 = (int) $this->app->db()->value("SELECT id FROM defects WHERE rule_key = 'ruff:F401' ORDER BY id LIMIT 1");
        $this->assertSame(200, $kim->put('/api/v1/users/me/preferences', ['language' => 'ko'])->status);
        $html = $kim->get("/defects/{$f401}")->body;
        $this->assertContains('data-rule-guide', $html);
        $this->assertContains('import os', $html, '나쁜 예(코드는 텍스트로 이스케이프)');
        $gone = (int) $this->app->db()->value("SELECT id FROM defects WHERE rule_key = 'eslint:gone-rule'");
        $this->assertNotContains('data-rule-guide', $lee->get("/defects/{$gone}")->body);
    }
}
