<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Migrator;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 대시보드 언어 필터와 "프로젝트별 추이" 프로젝트 수 설정(P21C 4·5절), 화면 설정 저장소(014 app_settings).
 * 프로젝트: py1·py2·py3(python), js1·js2(javascript) — kim은 py1·py2·js1만, lee는 js2만 본다. 각 프로젝트에 성공 분석과 열린 결함.
 */
final class DashboardFilterSettingsTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $u = [];
    /** @var array<string, int> */
    private array $p = [];

    public function setUp(): void
    {
        $root = $this->tempDir('dashfs');
        mkdir($root . '/work');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work'], 'dashboard' => ['cache_ttl' => '60s']]);
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user', 'mgr' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $admin = $this->app->users()->get($this->u['admin']);
        $g = (int) $this->app->groups()->create($admin, null, ['name' => 'G'])['id'];
        $this->app->groups()->addMember($admin, null, $g, 'mgr');
        $this->app->groups()->setManager($admin, null, $g, $this->u['mgr'], true);
        $actor = Actor::fromUser(['id' => $this->u['admin'], 'login_id' => 'admin'], null);
        $i = 0;
        foreach (['py1' => 'python', 'py2' => 'python', 'py3' => 'python', 'js1' => 'javascript', 'js2' => 'javascript'] as $name => $lang) {
            $pid = $this->p[$name] = TestDb::project($db, $name, $lang);
            $i++;
            $tool = $lang === 'javascript' ? ['tool' => 'eslint'] : [];
            $a = TestDb::analysis($db, $pid, $this->u['admin'], $tool + ['finished_at' => sprintf('2026-10-0%dT00:00:00Z', $i), 'total_count' => $i]);
            for ($k = 0; $k < $i; $k++) {
                TestDb::defect($db, $pid, $a, ['rule_key' => $lang === 'javascript' ? 'eslint:no-undef' : 'ruff:F401', 'file_path' => "f{$k}.x"]
                    + ($lang === 'javascript' ? ['tool' => 'eslint', 'language' => 'javascript'] : []));
            }
        }
        foreach (['py1', 'py2', 'js1'] as $n) {
            $this->app->projectAccess()->add($actor, $this->p[$n], ['subject_type' => 'user', 'login_id' => 'kim']);
        }
        $this->app->projectAccess()->add($actor, $this->p['js2'], ['subject_type' => 'user', 'login_id' => 'lee']);
        $this->app->projectAccess()->add($actor, $this->p['py1'], ['subject_type' => 'group', 'group_id' => $g]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return list<string> 추이의 프로젝트 이름(순서대로) */
    private function trend(TestClient $c, array $q = []): array
    {
        $r = $c->get('/api/v1/dashboard/trend', $q);
        $this->assertSame(200, $r->status, $r->body);
        return array_column($r->decoded()['data']['series'], 'project_name');
    }

    /** @return array<string, int> 규칙 상위(도구:규칙 → 건수) */
    private function rules(TestClient $c, array $q = []): array
    {
        $out = [];
        foreach ($c->get('/api/v1/dashboard/top-rules', $q)->decoded()['data']['items'] as $it) {
            $out[$it['rule_key']] = $it['count'];
        }
        return $out;
    }

    public function testLanguageFilterScopesTrendAndTopRulesWithinVisibleProjects(): void
    {
        $admin = $this->as('admin');
        // 기본 5개(최근 분석 순): js2(5) js1(4) py3(3) py2(2) py1(1)
        $this->assertSame(['js2', 'js1', 'py3', 'py2', 'py1'], $this->trend($admin));
        $this->assertSame(['py3', 'py2', 'py1'], $this->trend($admin, ['language' => 'python']));
        $this->assertSame(['js2', 'js1'], $this->trend($admin, ['language' => 'javascript']));
        // 직접 센 값과 같음: python 결함 1+2+3, javascript 4+5
        $this->assertSame(['ruff:F401' => 6], $this->rules($admin, ['language' => 'python']));
        $this->assertSame(['eslint:no-undef' => 9], $this->rules($admin, ['language' => 'javascript']));
        $this->assertSame(['eslint:no-undef' => 9, 'ruff:F401' => 6], $this->rules($admin));
        // 잘못된 값은 전체
        foreach (['', 'typescript', 'PYTHON', '1'] as $bad) {
            $this->assertSame(['js2', 'js1', 'py3', 'py2', 'py1'], $this->trend($admin, ['language' => $bad]), $bad);
        }
        // 보이는 범위 안에서만: kim은 py1·py2·js1, lee는 js2
        $kim = $this->as('kim');
        $this->assertSame(['py2', 'py1'], $this->trend($kim, ['language' => 'python']));
        $this->assertSame(['ruff:F401' => 3], $this->rules($kim, ['language' => 'python']));
        $this->assertSame(['eslint:no-undef' => 4], $this->rules($kim, ['language' => 'javascript']));
        $this->assertSame([], $this->trend($this->as('lee'), ['language' => 'python']));
        $body = $kim->get('/api/v1/dashboard/top-rules', ['language' => 'javascript'])->body;
        $this->assertNotContains('js2', $body, '보이지 않는 프로젝트 이름 없음');
        // 프로젝트를 고른 경우: 언어가 다르면 비어 있음(오류 아님), 보이지 않는 프로젝트는 404
        $this->assertSame([], $this->trend($kim, ['project_id' => (string) $this->p['py1'], 'language' => 'javascript']));
        $this->assertSame(['py1'], $this->trend($kim, ['project_id' => (string) $this->p['py1'], 'language' => 'python']));
        $this->assertSame(404, $kim->get('/api/v1/dashboard/trend', ['project_id' => (string) $this->p['js2'], 'language' => 'javascript'])->status);
        // 파일별 상위는 언어 필터 대상이 아니다(요청 범위)
        $files = $admin->get('/api/v1/dashboard/top-files', ['language' => 'python'])->decoded()['data'];
        $this->assertSame(null, $files['language']);
        $this->assertSame(10, count($files['items']));
    }

    public function testTrendProjectLimitSettingAdminOnlyAudited(): void
    {
        $admin = $this->as('admin');
        $this->assertSame(['value' => 5, 'default' => 5, 'min' => 1, 'max' => 50], $admin->get('/api/v1/admin/settings')->decoded()['data']['dashboard.trend_projects']);
        // 권한: 일반·그룹 관리자 403, 비로그인 401, CSRF 없음 거부
        foreach (['kim', 'mgr'] as $who) {
            $c = $this->as($who);
            $this->assertSame(403, $c->get('/api/v1/admin/settings')->status, $who);
            $this->assertSame(403, $c->request('PATCH', '/api/v1/admin/settings', [], ['dashboard.trend_projects' => 2])->status, $who);
        }
        $this->assertSame(401, (new TestClient($this->app))->get('/api/v1/admin/settings')->status);
        $this->assertSame(403, $admin->request('PATCH', '/api/v1/admin/settings', [], ['dashboard.trend_projects' => 2], null, [], false)->status, 'CSRF');
        // 범위 검증
        foreach ([0, 51, -1, 'abc', '2.5', 1.5, null, true] as $bad) {
            $r = $admin->request('PATCH', '/api/v1/admin/settings', [], ['dashboard.trend_projects' => $bad]);
            $this->assertSame([400, 'invalid_setting_value'], [$r->status, $r->decoded()['error']['code']], json_encode($bad));
        }
        foreach ([['unknown.key' => 3], [], [3]] as $bad) {
            $r = $admin->request('PATCH', '/api/v1/admin/settings', [], $bad);
            $this->assertSame([400, 'invalid_setting'], [$r->status, $r->decoded()['error']['code']], json_encode($bad));
        }
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.update'"));
        // 2개로 → 모든 사용자의 추이에 적용, 언어 필터 뒤에 개수 적용
        $r = $admin->request('PATCH', '/api/v1/admin/settings', [], ['dashboard.trend_projects' => '2']);
        $this->assertSame(2, $r->decoded()['data']['dashboard.trend_projects']['value']);
        $this->assertSame(['js2', 'js1'], $this->trend($admin));
        $this->assertSame(['py3', 'py2'], $this->trend($admin, ['language' => 'python']));
        $this->assertSame(['js1', 'py2'], $this->trend($this->as('kim')), 'kim: py1·py2·js1 중 최근 2개');
        $this->assertSame(2, $admin->get('/api/v1/dashboard/trend')->decoded()['data']['project_limit']);
        // 감사 로그 한 번(같은 값으로 다시 저장하면 기록 없음)
        $admin->request('PATCH', '/api/v1/admin/settings', [], ['dashboard.trend_projects' => 2]);
        $audit = $this->app->db()->all("SELECT target_id, details FROM audit_log WHERE action = 'setting.update'");
        $this->assertSame(1, count($audit));
        $this->assertSame(['key' => 'dashboard.trend_projects', 'from' => 5, 'to' => 2], json_decode($audit[0]['details'], true));
        // 화면: admin만 설정 버튼
        $this->assertContains('data-trend-limit', $admin->get('/dashboard')->body);
        $this->assertContains('최근 분석한 프로젝트 2개', $admin->get('/dashboard')->body);
        $this->assertNotContains('data-trend-limit', $this->as('kim')->get('/dashboard')->body);
        $this->assertNotContains('data-trend-limit', $this->as('mgr')->get('/dashboard')->body);
    }

    public function testMigration014AndMissingTableFallback(): void
    {
        // 014 전 DB(표 없음): 읽기는 기본값, 쓰기는 503
        $db = $this->app->db();
        $db->pdo()->exec('DROP TABLE app_settings');
        $this->assertSame(5, $this->app->appSettings()->int('dashboard.trend_projects'));
        $admin = $this->as('admin');
        $this->assertSame(200, $admin->get('/api/v1/dashboard/trend')->status);
        $r = $admin->request('PATCH', '/api/v1/admin/settings', [], ['dashboard.trend_projects' => 3]);
        $this->assertSame([503, 'settings_unavailable'], [$r->status, $r->decoded()['error']['code']]);
        // 013까지 적용한 DB에 014: 표가 생기고 행은 없음(= 기본값)
        $dir = $this->tempDir('m014');
        mkdir("{$dir}/m");
        foreach (glob(PJG_ROOT . '/migrations/0*.sql') as $f) {
            if (basename($f) < '014') {
                copy($f, "{$dir}/m/" . basename($f));
            }
        }
        $fresh = new \PJGuardian\Support\Db("{$dir}/x.sqlite");
        (new Migrator($fresh, "{$dir}/m", $this->app->clock))->migrate();
        copy(PJG_ROOT . '/migrations/014_app_settings.sql', "{$dir}/m/014_app_settings.sql");
        $this->assertSame(['014'], (new Migrator($fresh, "{$dir}/m", $this->app->clock))->migrate());
        $this->assertSame(0, (int) $fresh->value('SELECT COUNT(*) FROM app_settings'));
        $this->assertSame(1, (int) $fresh->value("SELECT COUNT(*) FROM pragma_table_info('uploads') WHERE name = 'summary'"), 'uploads.summary(업로드 내용 요약)');
        // CHECK: 키 형식
        $this->assertThrows(\PDOException::class, static fn () => $fresh->execute("INSERT INTO app_settings (key, value, updated_at) VALUES ('Bad Key', '1', '2026-10-06T00:00:00Z')"));
    }
}
