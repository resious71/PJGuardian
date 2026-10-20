<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Analysis\AnalysisComparison;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 대시보드(명세 9, P20): 집계 정확성(결함 목록·필터 요약·내 결함과 일치), 접근 범위(admin / 그룹 구성원 / 다른 그룹 / 매핑 없는 사용자),
 * 보이지 않는 project_id 404, 접근·데이터가 바뀐 뒤 다음 요청 반영(TTL 무관), 캐시 키 분리, 추이(최근 N건, 실패·취소·변경 없음 제외, 변경 표식),
 * 상위 10의 합치기·정렬 안정성, 언어·도구별, 빈 상태와 0/null 구분.
 *
 * 데이터: py(python, 그룹 A) · js(javascript, 그룹 B) · secret(python, 매핑 없음 → admin만).
 */
final class DashboardTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $u = [];
    /** @var array<string, int> */
    private array $p = [];
    /** @var array<string, int> 프로젝트별 마지막 성공 분석 */
    private array $a = [];

    public function setUp(): void
    {
        $root = $this->tempDir('dash');
        mkdir($root . '/work');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work'], 'dashboard' => ['cache_ttl' => '60s']]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'nobody' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $admin = $this->app->users()->get($this->u['admin']);
        $ga = (int) $this->app->groups()->create($admin, null, ['name' => 'A'])['id'];
        $gb = (int) $this->app->groups()->create($admin, null, ['name' => 'B'])['id'];
        $this->app->groups()->addMember($admin, null, $ga, 'kim');
        $this->app->groups()->addMember($admin, null, $gb, 'lee');
        $this->p['py'] = TestDb::project($db, 'py', 'python');
        $this->p['js'] = TestDb::project($db, 'js', 'javascript');
        $this->p['secret'] = TestDb::project($db, 'secret<b>', 'python');
        $actor = Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null);
        $this->app->projectAccess()->add($actor, $this->p['py'], ['subject_type' => 'group', 'group_id' => $ga]);
        $this->app->projectAccess()->add($actor, $this->p['js'], ['subject_type' => 'group', 'group_id' => $gb]);

        // 분석: py 12건 성공(도구 버전이 9번째에서 바뀜) + 실패·취소·변경 없음, js 1건 성공, secret 1건 성공
        for ($i = 1; $i <= 12; $i++) {
            $this->a['py'] = TestDb::analysis($db, $this->p['py'], $this->u['admin'], [
                'finished_at' => sprintf('2026-09-%02dT00:00:00Z', $i), 'total_count' => 100 + $i, 'tool_version' => $i < 9 ? '0.15.0' : '0.16.10',
                'severity_counts' => json_encode(['critical' => 1, 'high' => $i, 'medium' => 0, 'low' => 0, 'not_defined' => 99]),
            ]);
        }
        TestDb::analysis($db, $this->p['py'], $this->u['admin'], ['status' => 'failed', 'failure_code' => 'timeout', 'finished_at' => '2026-10-01T00:00:00Z']);
        TestDb::analysis($db, $this->p['py'], $this->u['admin'], ['status' => 'cancelled', 'finished_at' => '2026-10-01T01:00:00Z']);
        TestDb::analysis($db, $this->p['py'], $this->u['admin'], ['status' => 'succeeded', 'no_change' => 1, 'total_count' => 999, 'finished_at' => '2026-10-01T02:00:00Z']);
        $this->a['js'] = TestDb::analysis($db, $this->p['js'], $this->u['admin'], ['tool' => 'eslint', 'tool_version' => '10.11.0 (Node 24.21.0)', 'finished_at' => '2026-10-01T03:00:00Z',
            'total_count' => 4, 'severity_counts' => json_encode(['critical' => 0, 'high' => 4, 'medium' => 0, 'low' => 0, 'not_defined' => 0])]);
        $this->a['secret'] = TestDb::analysis($db, $this->p['secret'], $this->u['admin'], ['finished_at' => '2026-10-01T04:00:00Z']);

        // 결함
        $d = function (string $proj, array $over) use ($db): int {
            $tool = $proj === 'js' ? ['tool' => 'eslint', 'language' => 'javascript'] : [];
            return TestDb::defect($db, $this->p[$proj], $this->a[$proj], $over + $tool);
        };
        foreach ([['ruff:F401', 'src/a.py', 'high', 'open'], ['ruff:F401', 'src/a.py', 'high', 'in_progress'], ['ruff:F401', 'src/b.py', 'low', 'open'],
            ['ruff:E501', 'src/b.py', 'medium', 'open'], ['ruff:E501', 'src/c.py', 'medium', 'resolved'], ['syntax-error', 'src/c.py', 'critical', 'not_fix']] as [$rule, $file, $sev, $st]) {
            $d('py', ['rule_key' => $rule, 'file_path' => $file, 'severity' => $sev, 'status' => $st, 'assignee_id' => $rule === 'ruff:E501' ? $this->u['kim'] : null]);
        }
        foreach ([['eslint:no-unused-vars', 'src/a.py', 'high', 'open'], ['eslint:no-unused-vars', 'lib/x.js', 'high', 'open'], ['syntax-error', 'lib/x.js', 'critical', 'open'],
            ['eslint:eqeqeq', 'lib/y.js', 'low', 'resolved']] as [$rule, $file, $sev, $st]) {
            $d('js', ['rule_key' => $rule, 'file_path' => $file, 'severity' => $sev, 'status' => $st, 'assignee_id' => $this->u['lee']]);
        }
        $d('secret', ['rule_key' => 'ruff:S105', 'file_path' => 'secret/key.py', 'severity' => 'critical', 'status' => 'open']);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return array<string, mixed> */
    private function api(TestClient $c, string $section, array $query = []): array
    {
        $r = $c->get('/api/v1/dashboard/' . $section, $query);
        $this->assertSame(200, $r->status, $section . ' ' . $r->body);
        return $r->decoded()['data'];
    }

    public function testSummaryMatchesDefectListAndScopes(): void
    {
        $admin = $this->api($this->as('admin'), 'summary');
        $this->assertSame(['visible' => 3, 'with_analysis' => 3, 'without_analysis' => 0, 'without_defects' => 0], $admin['projects']);
        $this->assertSame(9, $admin['open']['total'], '열린 결함 = resolved 아님(전체 11 - resolved 2)');
        $this->assertSame(['critical' => 3, 'high' => 4, 'medium' => 1, 'low' => 1, 'not_defined' => 0], $admin['open']['by_severity']);
        $this->assertSame(['open' => 7, 'in_progress' => 1, 'postponed' => 0, 'intentional' => 0, 'not_fix' => 1, 'resolved' => 2], $admin['by_status']);
        $this->assertTrue(isset($admin['as_of']) && array_key_exists('cached', $admin));

        // 결함 목록의 필터 요약(DefectQuery::summary)과 같은 정의: 프로젝트별 합 = 대시보드
        $sumStatus = array_fill_keys(DefectFilter::STATUSES, 0);
        $sumOpenSev = array_fill_keys(DefectFilter::SEVERITIES, 0);
        foreach ($this->p as $pid) {
            $all = $this->app->defectQuery()->summary($pid, DefectFilter::fromQuery([]), 50);
            $open = $this->app->defectQuery()->summary($pid, DefectFilter::fromQuery(['status' => 'open,in_progress,postponed,intentional,not_fix']), 50);
            foreach ($all['status'] as $k => $n) {
                $sumStatus[$k] += $n;
            }
            foreach ($open['severity'] as $k => $n) {
                $sumOpenSev[$k] += $n;
            }
        }
        $this->assertSame($sumStatus, $admin['by_status'], '상태별 = 결함 목록 요약');
        $this->assertSame($sumOpenSev, $admin['open']['by_severity'], '열린 결함 심각도별 = 결함 목록 요약(열린 상태 필터)');

        $kim = $this->api($this->as('kim'), 'summary');
        $lee = $this->api($this->as('lee'), 'summary');
        $nobody = $this->api($this->as('nobody'), 'summary');
        $this->assertSame([1, 5, 1], [$kim['projects']['visible'], $kim['open']['total'], $kim['analyses_7d']['failed']], '그룹 A: py만');
        $this->assertSame([1, 3, 0], [$lee['projects']['visible'], $lee['open']['total'], $lee['analyses_7d']['failed']], '그룹 B: js만');
        $this->assertSame([0, 0], [$nobody['projects']['visible'], $nobody['open']['total']], '매핑 없음');
        // P27C(사용자 결정 2026-10-08)로 바뀜 — P20에서는 "실패 뒤 변경 없음이면 마지막은 성공"이었다. 변경 없음은 실제 분석이 아니므로 건너뛰고,
        // 마지막 실제 분석(실패)으로 판정한다(실제 분석이 성공해야 목록에서 빠진다)
        $this->assertSame([[$this->p['py'], 'timeout']], array_map(static fn (array $f): array => [$f['project_id'], $f['failure_code']], $kim['failed_projects']), 'py는 실패 뒤 변경 없음 확인만 있어 실패 유지');
        TestDb::analysis($this->app->db(), $this->p['js'], $this->u['admin'], ['tool' => 'eslint', 'status' => 'failed', 'failure_code' => 'tool_unavailable', 'finished_at' => '2026-10-02T06:00:00Z']);
        $failed = $this->api($this->as('lee'), 'summary')['failed_projects'];
        $this->assertSame([['js', 'tool_unavailable']], array_map(static fn (array $f): array => [$f['project_name'], $f['failure_code']], $failed), '마지막 분석이 실패한 프로젝트');
        $this->assertTrue($failed[0]['failure_title'] !== '' && $failed[0]['analysis_id'] > 0, '실패 사유 제목(ErrorCatalog)');
        $this->assertSame(1, $this->api($this->as('admin'), 'summary')['analyses_7d']['cancelled']);
        // 내게 지정된 결함 = 내 결함 화면 요약(mineSummary)
        $this->assertSame(1, $kim['mine']['open'], 'kim: E501 열린 1건(resolved 1건 제외)');
        $this->assertSame(3, $lee['mine']['open']);
        $mine = $this->as('kim')->get('/api/v1/my-defects/summary')->decoded()['data'];
        $this->assertSame($mine['total'] - $mine['status']['resolved'], $kim['mine']['open']);
        foreach ([$kim, $lee, $nobody] as $s) {
            $this->assertNotContains('secret', (string) json_encode($s), '보이지 않는 프로젝트 이름 없음');
        }
    }

    public function testInvisibleProjectIs404AndInputValidated(): void
    {
        $kim = $this->as('kim');
        foreach (['trend', 'top-rules', 'top-files'] as $s) {
            $r = $kim->get('/api/v1/dashboard/' . $s, ['project_id' => (string) $this->p['js']]);
            $this->assertSame([404, 'project_not_found'], [$r->status, $r->decoded()['error']['code']], "{$s}: 다른 그룹 프로젝트");
            $this->assertSame(404, $kim->get('/api/v1/dashboard/' . $s, ['project_id' => '99999'])->status, '없는 프로젝트와 같은 응답');
            $this->assertSame(400, $kim->get('/api/v1/dashboard/' . $s, ['project_id' => '1 OR 1=1'])->status);
            $this->assertSame(200, $kim->get('/api/v1/dashboard/' . $s, ['project_id' => (string) $this->p['py']])->status);
        }
        $this->assertSame(401, (new TestClient($this->app))->get('/api/v1/dashboard/summary')->status);
    }

    public function testAccessAndDataChangesAreReflectedImmediately(): void
    {
        $kim = $this->as('kim');
        $this->assertSame(5, $this->api($kim, 'summary')['open']['total']);
        $again = $this->api($kim, 'summary');
        $this->assertSame(true, $again['cached'], '같은 범위·데이터면 캐시');
        // 다른 사용자(같은 범위)도 같은 캐시를 쓸 수 있지만, 범위가 다른 사용자에게는 가지 않는다
        $this->assertSame(3, $this->api($this->as('lee'), 'summary')['open']['total'], '캐시 키 분리(범위)');
        // 결함 상태 변경 → TTL 안이어도 다음 요청에 반영(데이터 표지)
        $id = (int) $this->app->db()->value("SELECT id FROM defects WHERE project_id = ? AND status = 'open' ORDER BY id LIMIT 1", [$this->p['py']]);
        $this->app->defectUpdate()->update($this->app->users()->get($this->u['admin']), $id, ['status' => 'resolved', 'version' => 1]);
        $after = $this->api($kim, 'summary');
        $this->assertSame([4, false], [$after['open']['total'], $after['cached']], '결함 변경 반영');
        // 그룹 구성원 제거 → 범위가 바뀌어 즉시 0
        $admin = $this->app->users()->get($this->u['admin']);
        $ga = (int) $this->app->db()->value("SELECT id FROM groups WHERE name = 'A'");
        $this->app->groups()->removeMember($admin, null, $ga, $this->u['kim']);
        $this->assertSame([0, 0], [$this->api($kim, 'summary')['projects']['visible'], $this->api($kim, 'summary')['open']['total']]);
        $this->assertSame(404, $kim->get('/api/v1/dashboard/trend', ['project_id' => (string) $this->p['py']])->status);
        // 새 분석 반영 → 추이에 즉시
        $lee = $this->as('lee');
        $this->assertSame(1, count($this->api($lee, 'trend', ['project_id' => (string) $this->p['js']])['series'][0]['points']));
        TestDb::analysis($this->app->db(), $this->p['js'], $this->u['admin'], ['tool' => 'eslint', 'finished_at' => '2026-10-02T05:00:00Z', 'total_count' => 3]);
        $this->assertSame(2, count($this->api($lee, 'trend', ['project_id' => (string) $this->p['js']])['series'][0]['points']));
    }

    public function testTrendLimitsExclusionsAndChangeMarkers(): void
    {
        $admin = $this->as('admin');
        $t = $this->api($admin, 'trend', ['project_id' => (string) $this->p['py']]);
        $s = $t['series'][0];
        $this->assertSame(['ok', 10], [$s['state'], count($s['points'])], '최근 10건만');
        $this->assertSame(range(103, 112), array_column($s['points'], 'total'), '오래된 순, 실패·취소·변경 없음(999) 제외');
        $changed = array_values(array_filter($s['points'], static fn (array $p): bool => $p['changed']));
        $this->assertSame([109], array_column($changed, 'total'), '도구 버전이 바뀐 지점 하나');
        $this->assertSame(['tool_version' => ['from' => '0.15.0', 'to' => '0.16.10']], $changed[0]['changes']);
        $this->assertSame(['critical' => 1, 'high' => 12, 'medium' => 0, 'low' => 0, 'not_defined' => 99], end($s['points'])['by_severity']);
        $this->assertSame([null, true], [end($s['points'])['run'], isset(end($s['points'])['analysis_id'])], 'P20R: run 추가(시험 분석은 번호 없음), analysis_id 유지');
        // 프로젝트를 정하지 않으면 가장 최근에 분석한 보이는 프로젝트(최대 5개)
        $this->assertSame(['secret<b>', 'js', 'py'], array_column($this->api($admin, 'trend')['series'], 'project_name'));
        $this->assertSame(['py'], array_column($this->api($this->as('kim'), 'trend')['series'], 'project_name'));
        // 첫 분석은 표식 없음
        $this->assertSame(false, $this->api($admin, 'trend', ['project_id' => (string) $this->p['js']])['series'][0]['points'][0]['changed']);
    }

    public function testChangedBetweenRules(): void
    {
        $base = ['tool' => 'ruff', 'tool_version' => '0.16.10', 'config_source' => 'server_default', 'config_hash' => str_repeat('a', 64)];
        $this->assertFalse(AnalysisComparison::changedBetween(null, $base), '첫 분석');
        $this->assertFalse(AnalysisComparison::changedBetween($base, $base), '둘 다 같음');
        $this->assertTrue(AnalysisComparison::changedBetween($base, ['tool_version' => '0.17.0'] + $base), '도구 버전만');
        $this->assertTrue(AnalysisComparison::changedBetween($base, ['config_source' => 'upload'] + $base), '설정 출처만');
        $this->assertTrue(AnalysisComparison::changedBetween($base, ['config_hash' => str_repeat('b', 64)] + $base), '설정 해시만');
        $this->assertFalse(AnalysisComparison::changedBetween($base, ['tool_version' => null] + $base), '모르는 값(null)은 비교하지 않음');
        $this->assertSame(['config_source' => ['from' => 'server_default', 'to' => 'upload']], AnalysisComparison::differences($base, ['config_source' => 'upload'] + $base));
    }

    public function testTopRulesFilesMergingOrderAndLinks(): void
    {
        $admin = $this->as('admin');
        $rules = $this->api($admin, 'top-rules')['items'];
        $this->assertSame([
            ['ruff', 'ruff:F401', 3], ['eslint', 'eslint:no-unused-vars', 2], ['ruff', 'ruff:E501', 1], ['ruff', 'ruff:S105', 1], ['eslint', 'syntax-error', 1], ['ruff', 'syntax-error', 1],
        ], array_map(static fn (array $r): array => [$r['tool'], $r['rule_key'], $r['count']], $rules), '건수 순, 같으면 규칙 키·도구 순(안정), 열린 결함만, syntax-error는 도구별로 따로');
        $this->assertSame(['high' => 2, 'low' => 1], array_filter($rules[0]['by_severity']));
        $this->assertSame('/projects/' . $this->p['py'] . '?rule=ruff%3AF401&status=open%2Cin_progress%2Cpostponed%2Cintentional%2Cnot_fix', $rules[0]['link']);
        // 결함 목록의 링크 필터로 센 건수와 같다
        parse_str((string) parse_url($rules[0]['link'], PHP_URL_QUERY), $q);
        $this->assertSame(3, $this->app->defectQuery()->list($this->p['py'], DefectFilter::fromQuery($q), 50, 0)[1]);

        $files = $this->api($admin, 'top-files')['items'];
        $this->assertSame([[$this->p['js'], 'lib/x.js', 2], [$this->p['py'], 'src/a.py', 2], [$this->p['py'], 'src/b.py', 2], [$this->p['secret'], 'secret/key.py', 1], [$this->p['js'], 'src/a.py', 1], [$this->p['py'], 'src/c.py', 1]],
            array_map(static fn (array $f): array => [$f['project_id'], $f['file_path'], $f['count']], $files), '같은 경로라도 프로젝트가 다르면 따로, 동률은 경로·프로젝트 순');
        $this->assertSame('secret<b>', $files[3]['project_name'], '이름은 그대로(화면이 textContent로)');
        $this->assertSame(['critical' => 1, 'high' => 1], array_filter($files[0]['by_severity']));
        $one = $this->api($admin, 'top-files', ['project_id' => (string) $this->p['js']])['items'];
        $this->assertSame(['lib/x.js', 'src/a.py'], array_column($one, 'file_path'));
        $kimRules = $this->api($this->as('kim'), 'top-rules')['items'];
        $this->assertSame(['ruff:F401', 'ruff:E501', 'syntax-error'], array_column($kimRules, 'rule_key'), '범위: py만');
    }

    public function testToolsByLanguage(): void
    {
        $g = array_column($this->api($this->as('admin'), 'tools')['groups'], null, 'tool');
        $this->assertSame([2, 2, 6], [$g['ruff']['projects'], $g['ruff']['projects_with_analysis'], $g['ruff']['open']]);
        $this->assertSame([1, 1, 3], [$g['eslint']['projects'], $g['eslint']['projects_with_analysis'], $g['eslint']['open']]);
        $this->assertSame([['version' => '0.16.10', 'projects' => 2]], $g['ruff']['versions'], '최근 성공 분석 기준(변경 없음 분석의 기본 버전도 0.16.10)');
        $this->assertSame([['version' => '10.11.0 (Node 24.21.0)', 'projects' => 1]], $g['eslint']['versions']);
        $kim = array_column($this->api($this->as('kim'), 'tools')['groups'], null, 'tool');
        $this->assertSame([1, 0, []], [$kim['ruff']['projects'], $kim['eslint']['projects'], $kim['eslint']['versions']]);
    }

    public function testEmptyStatesDistinguishZeroAndUnknown(): void
    {
        $root = $this->tempDir('dashempty');
        mkdir($root . '/work');
        $app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work']]);
        TestDb::user($app->db(), 'admin', 'admin');
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $s = $c->get('/api/v1/dashboard/summary')->decoded()['data'];
        $this->assertSame([0, 0], [$s['projects']['visible'], $s['open']['total']], '프로젝트 없음');
        $this->assertSame([], $c->get('/api/v1/dashboard/trend')->decoded()['data']['series']);
        $pid = TestDb::project($app->db(), 'fresh', 'python');
        $s = $c->get('/api/v1/dashboard/summary')->decoded()['data'];
        $this->assertSame(['visible' => 1, 'with_analysis' => 0, 'without_analysis' => 1, 'without_defects' => 0], $s['projects'], '분석 전');
        $t = $c->get('/api/v1/dashboard/trend', ['project_id' => (string) $pid])->decoded()['data']['series'][0];
        $this->assertSame(['no_analysis', []], [$t['state'], $t['points']]);
        TestDb::analysis($app->db(), $pid, 1, ['status' => 'failed', 'failure_code' => 'tool_crash']);
        $this->assertSame('failed_only', $c->get('/api/v1/dashboard/trend', ['project_id' => (string) $pid])->decoded()['data']['series'][0]['state']);
        TestDb::analysis($app->db(), $pid, 1, ['finished_at' => '2026-10-02T11:00:00Z', 'severity_counts' => null, 'total_count' => 0]);
        $s = $c->get('/api/v1/dashboard/summary')->decoded()['data'];
        $this->assertSame([1, 1], [$s['projects']['with_analysis'], $s['projects']['without_defects']], '결함 0건');
        $p = $c->get('/api/v1/dashboard/trend', ['project_id' => (string) $pid])->decoded()['data']['series'][0]['points'][0];
        $this->assertSame([0, 0], [$p['total'], $p['by_severity']['high']], '0은 0(심각도별 기록이 없어도 키는 0으로)');
        $this->assertSame(null, $p['tool_version'] === '0.16.10' ? null : 'x', '도구 버전 기록');
        $this->assertSame([], $c->get('/api/v1/dashboard/top-rules')->decoded()['data']['items']);
        $tools = array_column($c->get('/api/v1/dashboard/tools')->decoded()['data']['groups'], null, 'tool');
        $this->assertSame([0, []], [$tools['eslint']['projects'], $tools['eslint']['versions']]);
    }

    public function testPageAndMenu(): void
    {
        $body = $this->as('kim')->get('/dashboard')->body;
        $this->assertContains('data-dashboard', $body);
        $this->assertSame(1, preg_match('#href="/dashboard" data-nav="nav\.dashboard"[^>]*aria-current="page"#', $body), '주 메뉴 "대시보드" 활성');
        $this->assertContains('vendor', (string) file_get_contents(PJG_ROOT . '/public/assets/js/pages/dashboard.js'));
        $this->assertNotContains('secret&lt;b&gt;', $body, '보이지 않는 프로젝트는 선택 목록에도 없음');
        $this->assertContains('secret&lt;b&gt;', $this->as('admin')->get('/dashboard')->body, '이름은 이스케이프');
        $this->assertSame('/projects', $this->as('kim')->get('/')->header('Location'), '로그인 후 첫 화면은 그대로(프로젝트 목록)');
    }
}
