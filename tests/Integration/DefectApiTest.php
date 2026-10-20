<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Lang;
use PJGuardian\Support\RuleLinks;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 결함 조회 API와 화면(명세 3.1, 6.4, 7, 8, P17).
 * - 필터 조합 정확성(시드 7건과 기대 건수 대조), 검색어의 %·_·따옴표·SQL 구문 안전성, 정렬 허용 목록, 페이지 경계
 * - 접근: 매핑된 kim(가능), 매핑 없는 park(404, 결함 id를 알아도 없는 결함과 같은 응답), admin, 비로그인 401
 * - 상세: 처음 발생 분석의 도구 버전, 비활성 담당자 표시, 이력(최신순, 자동/수동, 담당자 이름)
 * - 코드: 전체 스냅샷(창·전체·줄 수 한도), 발췌, 둘 다 없음, 파일 없음, 줄 범위 초과, 긴 줄, UTF-8 아님, 바이너리,
 *   <script>·엔티티가 든 코드는 JSON 텍스트 그대로(HTML로 렌더링하지 않음), 조작된 storage_path
 */
final class DefectApiTest extends TestCase
{
    private App $app;
    private string $snap;
    private int $py;
    private int $other;
    private int $kim;
    private int $ghost;
    private int $a1;
    private int $a2;
    /** @var array<string, int> */
    private array $d = [];

    public function setUp(): void
    {
        $this->snap = $this->tempDir('snap');
        $this->app = TestApp::make($this->tempDir('defapi'), [
            'paths' => ['snapshots_dir' => $this->snap],
            'viewer' => ['code_window_lines' => '5', 'code_max_lines' => '100', 'code_max_line_length' => '200'],
        ]);
        $db = $this->app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $this->kim = TestDb::user($db, 'kim', 'user');
        TestDb::user($db, 'park', 'user');
        $this->ghost = TestDb::user($db, 'ghost', 'user');
        $this->py = TestDb::project($db, 'py', 'python');
        $this->other = TestDb::project($db, 'other', 'python');
        $actor = Actor::fromUser(['id' => $admin, 'login_id' => 'admin'], null);
        foreach (['kim', 'ghost'] as $u) {
            $this->app->projectAccess()->add($actor, $this->py, ['subject_type' => 'user', 'login_id' => $u]);
        }
        $db->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->ghost]);

        $this->a1 = TestDb::analysis($db, $this->py, $this->kim, ['tool_version' => '0.16.9', 'finished_at' => '2026-10-01T01:00:00Z']);
        $this->a2 = TestDb::analysis($db, $this->py, $this->kim, ['tool_version' => '0.16.10', 'finished_at' => '2026-10-02T03:00:00Z']);
        $mk = fn (string $k, array $over) => $this->d[$k] = TestDb::defect($db, $this->py, $this->a1, $over);
        $mk('d1', ['rule_key' => 'ruff:F401', 'severity' => 'high', 'status' => 'open', 'file_path' => 'src/app/main.py', 'line_no' => 3, 'message' => '`os` imported but unused', 'assignee_id' => $this->kim]);
        $mk('d2', ['rule_key' => 'ruff:F401', 'severity' => 'low', 'status' => 'open', 'file_path' => 'src/app/util.py', 'line_no' => 1, 'message' => '`re` imported but unused', 'last_seen_analysis_id' => $this->a2]);
        $mk('d3', ['rule_key' => 'ruff:E711', 'severity' => 'high', 'status' => 'resolved', 'file_path' => 'src/app/main.py', 'line_no' => 10, 'col_no' => 5, 'end_line_no' => 10, 'end_col_no' => 9,
            'message' => 'Comparison to `None` should be `cond is None`', 'assignee_id' => $this->ghost, 'last_seen_analysis_id' => $this->a2]);
        $mk('d4', ['rule_key' => 'syntax-error', 'severity' => 'critical', 'status' => 'in_progress', 'file_path' => 'src/legacy/old.py', 'line_no' => 5, 'message' => 'Expected an expression']);
        $mk('d5', ['rule_key' => 'ruff:F821', 'severity' => 'not_defined', 'status' => 'open', 'file_path' => 'docs/conf.py', 'line_no' => 2, 'message' => 'Undefined name `100%_done`', 'assignee_id' => $this->kim]);
        $mk('d6', ['rule_key' => 'ruff:F821', 'severity' => 'not_defined', 'status' => 'postponed', 'file_path' => 'src/app_extra/x.py', 'line_no' => 7, 'message' => "Undefined name `it's`", 'last_seen_analysis_id' => $this->a2]);
        $mk('d7', ['rule_key' => 'ruff:E501', 'severity' => 'medium', 'status' => 'not_fix', 'file_path' => 'src/한글/모듈.py', 'line_no' => 4, 'message' => 'Line too long (120 > 88)']);
        // 다른 프로젝트의 결함(목록에 섞이면 안 됨)
        $oa = TestDb::analysis($db, $this->other, $this->kim);
        for ($i = 0; $i < 5; $i++) {
            TestDb::defect($db, $this->other, $oa, ['rule_key' => 'ruff:F401', 'severity' => 'high', 'message' => 'imported but unused']);
        }
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @param array<string, string> $query @return list<int> */
    private function ids(TestClient $c, array $query): array
    {
        $r = $c->get("/api/v1/projects/{$this->py}/defects", $query);
        $this->assertSame(200, $r->status, json_encode($query) . ' ' . $r->body);
        return array_column($r->decoded()['data'], 'id');
    }

    /** @param list<string> $keys */
    private function set(array $keys): array
    {
        $out = array_map(fn (string $k): int => $this->d[$k], $keys);
        sort($out);
        return $out;
    }

    /** @param array<string, string> $query */
    private function total(TestClient $c, array $query): int
    {
        $r = $c->get("/api/v1/projects/{$this->py}/defects", $query);
        $this->assertSame(200, $r->status, json_encode($query) . ' ' . $r->body);
        return $r->decoded()['page']['total'];
    }

    public function testFilterCombinationsMatchSeed(): void
    {
        $kim = $this->as('kim');
        $cases = [
            [[], ['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7']],
            [['severity' => 'high'], ['d1', 'd3']],
            [['severity' => 'high,low'], ['d1', 'd2', 'd3']],
            [['status' => 'open'], ['d1', 'd2', 'd5']],
            [['severity' => 'high', 'status' => 'open'], ['d1']],
            [['rule' => 'ruff:F401'], ['d1', 'd2']],
            [['rule' => 'ruff:F401,syntax-error'], ['d1', 'd2', 'd4']],
            [['assignee' => 'none'], ['d2', 'd4', 'd6', 'd7']],
            [['assignee' => (string) $this->kim], ['d1', 'd5']],
            [['assignee' => 'none,' . $this->kim], ['d1', 'd2', 'd4', 'd5', 'd6', 'd7']],
            [['assignee' => (string) $this->ghost], ['d3']],
            [['file' => 'src/app/'], ['d1', 'd2', 'd3']],
            [['file' => 'src/app'], ['d1', 'd2', 'd3', 'd6']],
            [['file' => '/src\\app/main.py'], ['d1', 'd3']],
            [['file' => 'main', 'file_mode' => 'contains'], ['d1', 'd3']],
            [['file' => 'src/한글/'], ['d7']],
            [['file' => "src/\u{1112}\u{1161}\u{11AB}\u{1100}\u{1173}\u{11AF}/"], ['d7']], // NFD로 입력해도 NFC로 비교
            [['q' => 'imported'], ['d1', 'd2']],
            [['q' => 'F401'], ['d1', 'd2']],
            [['q' => 'IMPORTED'], ['d1', 'd2']],
            [['q' => '한글'], ['d7']],
            [['severity' => 'high,low', 'status' => 'open', 'q' => 'imported'], ['d1', 'd2']],
            [['rule' => 'ruff:F821', 'status' => 'open,postponed', 'file' => 'src/', 'q' => 'it'], ['d6']],
        ];
        foreach ($cases as [$query, $expected]) {
            $got = $this->ids($kim, $query + ['size' => '200']);
            sort($got);
            $this->assertSame($this->set($expected), $got, json_encode($query, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testSearchWildcardsQuotesAndSqlAreLiteral(): void
    {
        $kim = $this->as('kim');
        $before = (int) $this->app->db()->value('SELECT COUNT(*) FROM defects');
        $this->assertSame(1, $this->total($kim, ['q' => '%']), '% 는 글자 그대로(d5 메시지)');
        $this->assertSame(2, $this->total($kim, ['q' => '_']), '_ 는 글자 그대로(d5 메시지, d6 경로)');
        $this->assertSame(1, $this->total($kim, ['q' => '100%_d']));
        $this->assertSame(0, $this->total($kim, ['q' => '100%x']));
        $this->assertSame(1, $this->total($kim, ['q' => "it's"]), '작은따옴표');
        $this->assertSame(0, $this->total($kim, ['q' => '\\']), '역슬래시(ESCAPE 문자 자체)');
        foreach (["' OR 1=1 --", '"; DROP TABLE defects; --', "') UNION SELECT * FROM users --", '%\' OR \'1\'=\'1'] as $evil) {
            $this->assertSame(0, $this->total($kim, ['q' => $evil]), $evil);
            $this->assertSame(0, $this->total($kim, ['file' => $evil, 'file_mode' => 'contains']), 'file ' . $evil);
            $this->assertSame(0, $this->total($kim, ['rule' => $evil]), 'rule ' . $evil);
        }
        $this->assertSame(0, $this->total($kim, ['file' => '%', 'file_mode' => 'prefix']), '접두사 % 는 글자 그대로(0건)');
        $this->assertSame(1, $this->total($kim, ['file' => '_', 'file_mode' => 'contains']), '경로의 _ 그대로(src/app_extra)');
        $this->assertSame($before, (int) $this->app->db()->value('SELECT COUNT(*) FROM defects'), '결함 표 그대로');
        // 제어 문자·너무 긴 값·배열·모르는 열거 값은 400
        $bad = [
            ['q' => "a\x00b"], ['q' => str_repeat('가', 201)], ['file' => str_repeat('a', 1001)], ['severity' => 'urgent'],
            ['status' => 'open,closed'], ['assignee' => 'kim'], ['assignee' => '0'], ['rule' => implode(',', range(1, 51))], ['q' => ['x']],
            ['file_mode' => 'regex'],
        ];
        foreach ($bad as $query) {
            $r = $kim->get("/api/v1/projects/{$this->py}/defects", $query);
            $this->assertSame([400, 'invalid_defect_filter'], [$r->status, $r->decoded()['error']['code'] ?? null], json_encode($query, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testSortWhitelistAndOrder(): void
    {
        $kim = $this->as('kim');
        $d = $this->d;
        $this->assertSame([$d['d5'], $d['d1'], $d['d3'], $d['d2'], $d['d6'], $d['d4'], $d['d7']], $this->ids($kim, []), '기본: 파일·줄 순(바이트 순, / < _)');
        $this->assertSame([$d['d4'], $d['d1'], $d['d3'], $d['d7'], $d['d2'], $d['d5'], $d['d6']], $this->ids($kim, ['sort' => 'severity']), '심각도: 치명적부터, 같으면 id');
        $this->assertSame([$d['d6'], $d['d5'], $d['d2'], $d['d7'], $d['d3'], $d['d1'], $d['d4']], $this->ids($kim, ['sort' => 'severity', 'order' => 'desc']));
        $this->assertSame([$d['d1'], $d['d2'], $d['d5'], $d['d4'], $d['d6'], $d['d7'], $d['d3']], $this->ids($kim, ['sort' => 'status']), '상태: open → … → resolved');
        $this->assertSame([$d['d7'], $d['d3'], $d['d1'], $d['d2'], $d['d5'], $d['d6'], $d['d4']], $this->ids($kim, ['sort' => 'rule']), '규칙 키 순(ruff:E501 < ruff:E711 < ruff:F401 < ruff:F821 < syntax-error)');
        $last = $this->ids($kim, ['sort' => 'last_seen', 'order' => 'desc']);
        $this->assertSame([$d['d6'], $d['d3'], $d['d2']], array_slice($last, 0, 3), '최근 감지(마지막 감지 분석이 a2인 것) 먼저');
        foreach (['id', 'file_path', 'd.id; DROP TABLE defects', 'severity desc', '1'] as $s) {
            $r = $kim->get("/api/v1/projects/{$this->py}/defects", ['sort' => $s]);
            $this->assertSame([400, 'invalid_sort'], [$r->status, $r->decoded()['error']['code'] ?? null], $s);
        }
        $r = $kim->get("/api/v1/projects/{$this->py}/defects", ['order' => 'sideways']);
        $this->assertSame([400, 'invalid_sort'], [$r->status, $r->decoded()['error']['code'] ?? null]);
    }

    public function testPaginationBoundaries(): void
    {
        $kim = $this->as('kim');
        $all = $this->ids($kim, ['size' => '200']);
        $pages = [];
        foreach (['1', '2', '3'] as $p) {
            $r = $kim->get("/api/v1/projects/{$this->py}/defects", ['size' => '3', 'page' => $p])->decoded();
            $this->assertSame(['page' => (int) $p, 'size' => 3, 'total' => 7], $r['page']);
            $pages = array_merge($pages, array_column($r['data'], 'id'));
        }
        $this->assertSame($all, $pages, '페이지를 이어 붙이면 전체와 같고 겹침·빠짐 없음');
        $this->assertSame(1, count($kim->get("/api/v1/projects/{$this->py}/defects", ['size' => '3', 'page' => '3'])->decoded()['data']), '마지막 페이지 1건');
        $out = $kim->get("/api/v1/projects/{$this->py}/defects", ['size' => '3', 'page' => '4']);
        $this->assertSame([200, [], 7], [$out->status, $out->decoded()['data'], $out->decoded()['page']['total']], '범위 밖 페이지는 빈 목록 + 전체 건수');
        foreach ([['page' => '0'], ['size' => '0'], ['size' => '201'], ['page' => '-1'], ['page' => 'x']] as $q) {
            $this->assertSame(400, $kim->get("/api/v1/projects/{$this->py}/defects", $q)->status, json_encode($q));
        }
        $this->assertSame(50, $kim->get("/api/v1/projects/{$this->py}/defects")->decoded()['page']['size'], '기본 50(ui.defect_page_size)');
    }

    public function testListItemShape(): void
    {
        $row = null;
        foreach ($this->as('kim')->get("/api/v1/projects/{$this->py}/defects", ['assignee' => (string) $this->ghost])->decoded()['data'] as $r) {
            $row = $r;
        }
        $this->assertSame($this->d['d3'], $row['id']);
        $this->assertSame(['ruff:E711', '/rules/ruff/E711', 'src/app/main.py', 10, 5, 10, 9, 'high', 'resolved', 1], [
            $row['rule_key'], $row['rule_url'], $row['file_path'], $row['line'], $row['column'], $row['end_line'], $row['end_column'], $row['severity'], $row['status'], $row['version'],
        ]);
        // P18: has_access(담당자가 이 프로젝트에 접근할 수 있는지, ProjectAccess 판정. 비활성 사용자는 false)
        $this->assertSame(['id' => $this->ghost, 'login_id' => 'ghost', 'display_name' => 'ghost', 'active' => false, 'has_access' => false], $row['assignee'], '비활성 담당자 표시');
        // P20R: last_seen.run(TestDb 분석은 Run 번호가 없어 null)
        $this->assertSame(['analysis_id' => $this->a2, 'finished_at' => '2026-10-02T03:00:00Z', 'run' => null], $row['last_seen']);
        $this->assertFalse(array_key_exists('email', $row['assignee']), '담당자에 이메일 없음');
    }

    public function testSummaryFacetsExcludeOwnFilter(): void
    {
        $kim = $this->as('kim');
        $s = $kim->get("/api/v1/projects/{$this->py}/defects/summary", ['severity' => 'high'])->decoded()['data'];
        $this->assertSame(2, $s['total']);
        $this->assertSame(['critical' => 1, 'high' => 2, 'medium' => 1, 'low' => 1, 'not_defined' => 2], $s['severity'], '심각도 칩: 심각도 필터는 빼고 셈');
        $this->assertSame(['open' => 1, 'in_progress' => 0, 'postponed' => 0, 'intentional' => 0, 'not_fix' => 0, 'resolved' => 1], $s['status'], '상태 칩: 심각도 필터 적용');
        $this->assertSame([['rule_key' => 'ruff:E711', 'count' => 1], ['rule_key' => 'ruff:F401', 'count' => 1]], $s['rules']);
        $this->assertSame([[$this->ghost, 1, false], [$this->kim, 1, true]], array_map(static fn (array $a): array => [$a['id'], $a['count'], $a['active']],
            array_values(array_filter($s['assignees'], static fn (array $a): bool => $a['id'] !== null))));
        $this->assertSame(['has_analysis' => true, 'total' => 7], $s['project']);
        $sel = $kim->get("/api/v1/projects/{$this->py}/defects/summary", ['rule' => 'ruff:NOPE'])->decoded()['data'];
        $this->assertSame(0, $sel['total']);
        $this->assertTrue(in_array(['rule_key' => 'ruff:NOPE', 'count' => 0], $sel['rules'], true), '선택한 규칙은 0건이어도 남는다(선택 해제용)');
        $this->assertSame(['file' => 'src/한글/', 'file_mode' => 'prefix'],
            array_intersect_key($kim->get("/api/v1/projects/{$this->py}/defects/summary", ['file' => "\\src\\\u{1112}\u{1161}\u{11AB}\u{1100}\u{1173}\u{11AF}\\"])->decoded()['data']['filter'], ['file' => 1, 'file_mode' => 1]),
            '정규화한 필터를 되돌려 준다');
        // 분석 전 프로젝트
        $e = $this->as('admin')->get("/api/v1/projects/{$this->other}/defects/summary")->decoded()['data']['project'];
        $this->assertSame(['has_analysis' => true, 'total' => 5], $e);
        $empty = TestDb::project($this->app->db(), 'empty', 'python');
        $this->assertSame(['has_analysis' => false, 'total' => 0], $this->as('admin')->get("/api/v1/projects/{$empty}/defects/summary")->decoded()['data']['project']);
    }

    /** 요약의 칩 숫자 = 그 칩 하나만 고른 목록 건수(자기 필터 대신 그 값). 여러 필터 조합에서 목록 API와 대조한다 */
    public function testSummaryCountsAgreeWithList(): void
    {
        $kim = $this->as('kim');
        $combos = [[], ['status' => 'open'], ['severity' => 'high,low', 'q' => 'imported'], ['rule' => 'ruff:F821,ruff:F401', 'assignee' => 'none'],
            ['assignee' => 'none,' . $this->kim, 'status' => 'open,postponed', 'file' => 'src/'], ['file' => 'app', 'file_mode' => 'contains', 'severity' => 'high'],
            // P31: 검색어·경로 포함일 때 한 번 훑기 경로 — 규칙·담당자 필터를 PHP에서 거르는 조합
            ['q' => 'imported', 'rule' => 'ruff:F401', 'assignee' => 'none'], ['file' => 'a', 'file_mode' => 'contains', 'assignee' => 'none,' . $this->kim, 'rule' => 'ruff:F821,ruff:F401'],
            ['q' => 'a', 'status' => 'open', 'severity' => 'high,low,medium', 'assignee' => (string) $this->kim]];
        foreach ($combos as $q) {
            $s = $kim->get("/api/v1/projects/{$this->py}/defects/summary", $q)->decoded()['data'];
            $this->assertSame($this->total($kim, $q), $s['total'], 'total ' . json_encode($q));
            foreach ($s['severity'] as $v => $n) {
                $this->assertSame($this->total($kim, ['severity' => $v] + $q), $n, "severity={$v} " . json_encode($q));
            }
            foreach ($s['status'] as $v => $n) {
                $this->assertSame($this->total($kim, ['status' => $v] + $q), $n, "status={$v} " . json_encode($q));
            }
            foreach ($s['rules'] as $r) {
                $this->assertSame($this->total($kim, ['rule' => $r['rule_key']] + $q), $r['count'], "rule={$r['rule_key']} " . json_encode($q));
            }
            foreach ($s['assignees'] as $a) {
                $this->assertSame($this->total($kim, ['assignee' => $a['id'] === null ? 'none' : (string) $a['id']] + $q), $a['count'], 'assignee ' . json_encode($q));
            }
        }
    }

    public function testAccessScopeAndAuthentication(): void
    {
        $d1 = $this->d['d1'];
        $kim = $this->as('kim');
        $park = $this->as('park');
        $admin = $this->as('admin');
        foreach (['/defects', '/defects/summary'] as $suffix) {
            $path = "/api/v1/projects/{$this->py}{$suffix}";
            $this->assertSame(200, $kim->get($path)->status, "kim {$path}");
            $this->assertSame(200, $admin->get($path)->status, "admin {$path}");
            $hidden = $park->get($path);
            $missing = $park->get("/api/v1/projects/999999{$suffix}");
            $this->assertSame([404, 'project_not_found'], [$hidden->status, $hidden->decoded()['error']['code']], "park {$path}");
            $this->assertSame([$missing->status, $missing->decoded()['error']['code'], $missing->decoded()['error']['message']],
                [$hidden->status, $hidden->decoded()['error']['code'], $hidden->decoded()['error']['message']], '없는 프로젝트와 같은 응답');
        }
        foreach (["/api/v1/defects/{$d1}", "/api/v1/defects/{$d1}/history", "/api/v1/defects/{$d1}/code"] as $path) {
            $this->assertSame(200, $kim->get($path)->status, "kim {$path}");
            $this->assertSame(200, $admin->get($path)->status, "admin {$path}");
            $hidden = $park->get($path);
            $missing = $kim->get(str_replace("/{$d1}", '/999999', $path));
            $bad = $kim->get(str_replace("/{$d1}", '/abc', $path));
            $this->assertSame([404, 'defect_not_found'], [$hidden->status, $hidden->decoded()['error']['code']], "park는 결함 id를 알아도 404 {$path}");
            $this->assertSame([404, 'defect_not_found', Lang::get('error.defect_not_found')], [$missing->status, $missing->decoded()['error']['code'], $missing->decoded()['error']['message']]);
            $this->assertSame($missing->decoded()['error']['message'], $hidden->decoded()['error']['message'], '없는 결함과 같은 문구');
            $this->assertSame(404, $bad->status, '형식이 틀린 id');
            $this->assertSame(401, (new TestClient($this->app))->get($path)->status, "비로그인 {$path}");
        }
        $this->assertSame(401, (new TestClient($this->app))->get("/api/v1/projects/{$this->py}/defects")->status);
        // 매핑을 지우면 다음 요청부터 404(캐시 없음)
        $this->app->db()->execute("DELETE FROM project_access WHERE subject_type = 'user' AND subject_id = ?", [$this->kim]);
        $this->assertSame(404, $kim->get("/api/v1/defects/{$d1}")->status);
        // 화면도 같은 판정
        $this->assertSame(404, $park->get("/defects/{$d1}")->status);
        $this->assertSame(404, $kim->get("/defects/{$d1}")->status);
        $this->assertSame(200, $admin->get("/defects/{$d1}")->status);
    }

    public function testDetailFirstSeenToolVersionAndHistory(): void
    {
        $db = $this->app->db();
        $d3 = $this->d['d3'];
        TestDb::autoHistory($db, $d3, $this->a2, 'open', 'resolved', 'not_detected');
        $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind, memo) VALUES (?, 'assignee', NULL, ?, ?, '2026-10-03T00:00:00Z', 'manual', ?)",
            [$d3, (string) $this->ghost, $this->kim, '<b>인수인계</b>']);
        $db->execute("INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_by, changed_at, kind) VALUES (?, 'severity', 'not_defined', 'high', ?, '2026-10-03T00:01:00Z', 'manual')", [$d3, $this->kim]);
        $kim = $this->as('kim');
        $d = $kim->get("/api/v1/defects/{$d3}")->decoded()['data'];
        $this->assertSame(['analysis_id' => $this->a1, 'run' => null, 'tool_version' => '0.16.9', 'finished_at' => '2026-10-01T01:00:00Z'], $d['first_seen'], '처음 발생한 분석의 도구 버전(명세 8)');
        $this->assertSame(['analysis_id' => $this->a2, 'run' => null, 'tool_version' => '0.16.10', 'finished_at' => '2026-10-02T03:00:00Z'], $d['last_seen']);
        $this->assertSame(false, $d['assignee']['active']);
        $this->assertSame(1, $d['version']);

        $h = $kim->get("/api/v1/defects/{$d3}/history", ['size' => '2'])->decoded();
        $this->assertSame(3, $h['page']['total']);
        $this->assertSame(['severity', 'assignee'], array_column($h['data'], 'field'), '최신순');
        $this->assertSame(['kim', 'manual', null], [$h['data'][0]['changed_by']['login_id'], $h['data'][0]['kind'], $h['data'][0]['old_user']]);
        $this->assertSame([null, ['id' => $this->ghost, 'login_id' => 'ghost', 'display_name' => 'ghost', 'active' => false], '<b>인수인계</b>'],
            [$h['data'][1]['old_user'], $h['data'][1]['new_user'], $h['data'][1]['memo']], '담당자 이력은 사용자 정보로(비활성 표시), 메모는 텍스트 그대로');
        $p2 = $kim->get("/api/v1/defects/{$d3}/history", ['size' => '2', 'page' => '2'])->decoded()['data'];
        $this->assertSame([['status', 'auto', null, 'not_detected', $this->a2, 'open', 'resolved']],
            array_map(static fn (array $x): array => [$x['field'], $x['kind'], $x['changed_by'], $x['reason_code'], $x['analysis_id'], $x['old_value'], $x['new_value']], $p2), '자동 전환: 변경자 없음, 사유·분석');

        // 화면: 서버가 그린 요약(이스케이프), 처음 발생 도구 버전, 비활성 표시, 규칙 키는 규칙 설명 링크(P22부터. 전에는 텍스트)
        $html = $kim->get("/defects/{$d3}");
        $this->assertSame(200, $html->status);
        $this->assertContains('도구 버전 0.16.9', $html->body);
        $this->assertContains('data-inactive', $html->body);
        $this->assertContains('Comparison to `None` should be `cond is None`', $html->body);
        $this->assertContains('href="/rules/ruff/E711"', $html->body, '규칙 설명 링크(P22)');
        $this->assertContains('data-edit-slot', $html->body, '수정 컨트롤 자리(P18이 폼을 넣음)');
        $this->assertContains('data-edit-form', $html->body);
    }

    public function testDetailPageEscapesValues(): void
    {
        $db = $this->app->db();
        $id = TestDb::defect($db, $this->py, $this->a1, ['rule_key' => 'ruff:F401', 'file_path' => 'src/<img src=x onerror=alert(1)>.py', 'message' => '<script>alert("x")</script> &amp;']);
        $html = $this->as('kim')->get("/defects/{$id}")->body;
        $this->assertNotContains('<script>alert', $html);
        $this->assertNotContains('<img src=x', $html);
        $this->assertContains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp;amp;', $html);
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $html), '인라인 스크립트 없음');
    }

    public function testProjectPageDefectsTabAndPicker(): void
    {
        $kim = $this->as('kim');
        $page = $kim->get("/projects/{$this->py}");
        $this->assertSame(200, $page->status);
        $this->assertContains('data-defects data-project-id="' . $this->py . '"', $page->body);
        $this->assertContains('js/pages/defects.js', $page->body);
        // 빈 상태: 분석 전 / 결함 0건
        $admin = $this->as('admin');
        $empty = TestDb::project($this->app->db(), 'empty', 'python');
        $this->assertContains(Lang::get('defects.no_analysis_title'), $admin->get("/projects/{$empty}")->body);
        TestDb::analysis($this->app->db(), $empty, $this->kim);
        $this->assertContains(Lang::get('defects.none_title'), $admin->get("/projects/{$empty}")->body);
        // 결함 메뉴: 볼 수 있는 프로젝트만(kim은 py만), 해결되지 않은 결함 수
        $picker = $kim->get('/defects');
        $this->assertSame(200, $picker->status);
        $this->assertContains('>py</a>', $picker->body);
        $this->assertNotContains('>other</a>', $picker->body);
        $this->assertContains('<td>6</td>', $picker->body, 'py: resolved 1건을 뺀 6건');
        $this->assertContains('>other</a>', $admin->get('/defects')->body);
        $this->assertContains('href="/defects"', $kim->get('/projects')->body, '"결함" 메뉴 활성');
    }

    // ---- 코드 조회 ----

    private function snapshot(int $analysisId, array $files, string $status = 'full'): void
    {
        $dir = $this->snap . '/' . $this->py . '/' . $analysisId;
        @mkdir($dir, 0777, true);
        $zip = new \ZipArchive();
        $zip->open($dir . '/source.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $this->app->db()->execute('INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at, pruned_at) VALUES (?, ?, 1, ?, ?, ?, ?)',
            [$analysisId, $this->py . '/' . $analysisId, count($files), $status, TestDb::T0, $status === 'pruned' ? TestDb::T0 : null]);
    }

    /** @return array<string, mixed> */
    private function code(TestClient $c, int $id, string $mode = 'window'): array
    {
        $r = $c->get("/api/v1/defects/{$id}/code", ['mode' => $mode]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertContains('application/json', (string) $r->header('Content-Type'));
        $this->assertSame('nosniff', $r->header('X-Content-Type-Options'));
        return $r->decoded()['data'];
    }

    public function testCodeFromFullSnapshotIsPlainTextLines(): void
    {
        $lines = [];
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = "line_{$i} = {$i}";
        }
        $lines[9] = 'if x == None: html = "<script>alert(1)</script></script>&amp;&lt;b&gt;"';
        $this->snapshot($this->a2, [
            'src/app/main.py' => implode("\r\n", $lines) . "\r\n",
            'src/app_extra/x.py' => "a = 1\nb = 2\nc = 3\n",
        ]);
        $kim = $this->as('kim');
        $w = $this->code($kim, $this->d['d3']);
        $this->assertSame(['snapshot', false, null, 5, 15, 30, true, $this->a2], [$w['source'], $w['excerpt'], $w['reason'], $w['start_line'], $w['end_line'], $w['total_lines'], $w['partial'], $w['analysis_id']],
            '결함 줄(10) 앞뒤 5줄, 마지막 감지 분석(a2) 기준, CRLF → 줄 목록');
        $this->assertSame($lines[9], $w['lines'][5], '코드 안의 <script>·엔티티는 바꾸지 않은 텍스트 그대로');
        $this->assertSame(['line' => 10, 'column' => 5, 'end_line' => 10, 'end_column' => 9], $w['highlight']);
        $raw = $kim->get("/api/v1/defects/{$this->d['d3']}/code")->body;
        $this->assertSame(1, substr_count($raw, '"lines":['), 'HTML이 아니라 JSON 줄 목록');
        $f = $this->code($kim, $this->d['d3'], 'full');
        $this->assertSame([1, 30, 30, false, 30], [$f['start_line'], $f['end_line'], $f['total_lines'], $f['partial'], count($f['lines'])], '전체 파일(끝 줄바꿈 뒤 빈 줄은 줄이 아님)');
        // 줄 범위 초과: 결함 줄(7)이 3줄짜리 파일보다 뒤 → 창(2~12)을 파일 끝(3)에서 자름, 오류 없음
        $x = $this->code($kim, $this->d['d6']);
        $this->assertSame(['snapshot', 2, 3, ['b = 2', 'c = 3']], [$x['source'], $x['start_line'], $x['end_line'], $x['lines']]);
        $far = TestDb::defect($this->app->db(), $this->py, $this->a1, ['file_path' => 'src/app_extra/x.py', 'line_no' => 50, 'last_seen_analysis_id' => $this->a2]);
        $y = $this->code($kim, $far);
        $this->assertSame(['snapshot', 1, 3, 3], [$y['source'], $y['start_line'], $y['end_line'], count($y['lines'])], '결함 줄이 파일 끝보다 훨씬 뒤 → 파일 끝 부분');
        // 스냅샷에 없는 파일
        $this->assertSame(['none', 'file_missing', []], array_values(array_intersect_key($this->code($kim, $this->d['d2']), ['source' => 1, 'reason' => 1, 'lines' => 1])));
        $this->assertSame(400, $kim->get("/api/v1/defects/{$this->d['d3']}/code", ['mode' => 'raw'])->status);
        $this->assertSame('invalid_code_mode', $kim->get("/api/v1/defects/{$this->d['d3']}/code", ['mode' => '../x'])->decoded()['error']['code']);
    }

    public function testCodeLimitsLongLinesEncodingAndBinary(): void
    {
        $long = [];
        for ($i = 1; $i <= 150; $i++) {
            $long[] = $i === 10 ? str_repeat('가', 300) : "v{$i} = {$i}";
        }
        $this->snapshot($this->a2, [
            'src/app/main.py' => implode("\n", $long),
            'src/app/util.py' => "# \xB0\xA1\xB3\xAA CP949 주석 아님\nimport re\n",
            'src/app_extra/x.py' => "a = 1\x00\x01\x02",
        ]);
        $kim = $this->as('kim');
        $f = $this->code($kim, $this->d['d3'], 'full');
        $this->assertSame([true, 5, 15, 150], [$f['partial'], $f['start_line'], $f['end_line'], $f['total_lines']], '줄 수 한도(100) 초과 → 결함 주변 창');
        $this->assertSame([10], $f['truncated_lines'], '긴 줄 자름');
        $this->assertSame(200, mb_strlen($f['lines'][5]), '200자(문자 단위, 한글이 깨지지 않음)');
        $this->assertTrue(mb_check_encoding($f['lines'][5], 'UTF-8'));
        $u = $this->code($kim, $this->d['d2']);
        $this->assertSame(true, $u['content_replaced'], 'UTF-8 아님 → 대체 문자 표시');
        $this->assertContains("\u{FFFD}", $u['lines'][0]);
        $this->assertSame('import re', $u['lines'][1]);
        $b = $this->code($kim, $this->d['d6']);
        $this->assertSame(['none', 'binary', []], [$b['source'], $b['reason'], $b['lines']]);
    }

    public function testCodeFromExcerptOrNothing(): void
    {
        $db = $this->app->db();
        // a1 스냅샷은 정리됨(pruned): d1은 발췌가 있고 d4는 없다
        $db->execute('INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at, pruned_at) VALUES (?, ?, 0, 3, ?, ?, ?)',
            [$this->a1, $this->py . '/' . $this->a1, 'pruned', TestDb::T0, TestDb::T0]);
        $db->execute('INSERT INTO defect_excerpts (defect_id, analysis_id, file_path, start_line, end_line, content, created_at, content_replaced) VALUES (?, ?, ?, 1, 5, ?, ?, 1)',
            [$this->d['d1'], $this->a1, 'src/app/main.py', "import sys\nimport os\n<script>x</script>\n\nprint(sys.argv)", TestDb::T0]);
        $kim = $this->as('kim');
        foreach (['window', 'full'] as $mode) {
            $e = $this->code($kim, $this->d['d1'], $mode);
            $this->assertSame(['excerpt', true, 1, 5, null, true], [$e['source'], $e['excerpt'], $e['start_line'], $e['end_line'], $e['total_lines'], $e['content_replaced']], "발췌({$mode}): 범위만");
            $this->assertSame('<script>x</script>', $e['lines'][2]);
            $this->assertSame(5, count($e['lines']), '발췌 범위 밖은 돌려주지 않음');
        }
        $n = $this->code($kim, $this->d['d4']);
        $this->assertSame(['none', 'excerpt_missing'], [$n['source'], $n['reason']], '정리됐고 발췌도 없음');
        // 스냅샷 행이 없는 분석
        $a3 = TestDb::analysis($db, $this->py, $this->kim);
        $id = TestDb::defect($db, $this->py, $a3, ['file_path' => 'src/z.py']);
        $this->assertSame(['none', 'snapshot_missing'], array_values(array_intersect_key($this->code($kim, $id), ['source' => 1, 'reason' => 1])));
        // DB의 storage_path가 조작되어도 스냅샷 폴더 밖을 읽지 않는다
        $a4 = TestDb::analysis($db, $this->py, $this->kim);
        $id4 = TestDb::defect($db, $this->py, $a4, ['file_path' => 'source.zip']);
        $db->execute("INSERT INTO snapshots (analysis_id, storage_path, size_bytes, file_count, status, created_at) VALUES (?, '../../config', 1, 1, 'full', ?)", [$a4, TestDb::T0]);
        $this->assertSame(['none', 'snapshot_missing'], array_values(array_intersect_key($this->code($kim, $id4), ['source' => 1, 'reason' => 1])));
    }

    public function testRuleLinkTargetEnabledSinceP22(): void
    {
        // P22에서 규칙 설명 페이지가 생겨 링크를 켰다(P17~P21은 꺼 둔 상태를 시험했다)
        $this->assertTrue(RuleLinks::ENABLED);
        $this->assertSame('/rules/ruff/F401', RuleLinks::path('ruff:F401'));
        $this->assertSame('/rules/ruff/F401', RuleLinks::target('ruff:F401'));
        $this->assertSame('/rules/eslint/%40typescript-eslint/no-explicit-any', RuleLinks::target('eslint:@typescript-eslint/no-explicit-any'));
        $this->assertSame('/rules/syntax-error', RuleLinks::target('syntax-error'));
        $this->assertSame(null, RuleLinks::target('ruff:../../x'));
        $this->assertSame(null, RuleLinks::target('<script>'));
    }

    public function testDynamicLanguageKeysExist(): void
    {
        foreach (\PJGuardian\Services\Defects\DefectFilter::SORTS as $s) {
            $this->assertTrue(Lang::has('defects.sort.' . $s), "defects.sort.{$s}");
        }
        foreach (['asc', 'desc'] as $o) {
            $this->assertTrue(Lang::has('defects.order.' . $o));
        }
        foreach (['python', 'javascript', 'typescript'] as $l) {
            $this->assertTrue(Lang::has('defects.language.' . $l));
        }
    }
}
