<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Response;
use PJGuardian\Services\Export\ExportService;
use PJGuardian\Services\Export\SheetText;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\Support\RunFixture;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 내보내기(P23): 접근 통제(401·CSRF·보이지 않는 프로젝트 404·내 결함은 내 것만), 목록 API와 행·순서·값 완전 일치(조건·정렬·과거 Run 조합),
 * 열 값(줄임 없음·한국어 라벨·숫자·KST), 상한(경계·413·0건 409·설정), 동시 실행(429), 시간 초과 중단·임시 파일 삭제, 주입 방지(CSV ' 접두,
 * XLSX 수식 셀 없음), 파일 구조(zip·필수 부분·XML·두 번째 시트), CSV BOM·CRLF·인용, 파일 이름·헤더, 규칙 목록 내보내기.
 *
 * 프로젝트 P(Run 3개, RunFixture): kim·admin이 봄, lee는 못 봄. 프로젝트 V(값 시험용 결함): kim이 봄.
 */
final class ExportTest extends TestCase
{
    private App $app;
    private string $root;
    private int $p;
    private int $v;
    /** @var array<string, int> */
    private array $u = [];

    public function setUp(): void
    {
        $this->root = $this->tempDir('export');
        $this->app = TestApp::make($this->root, ['paths' => ['work_dir' => $this->root . '/work']]);
        mkdir($this->root . '/work');
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $db->execute("UPDATE users SET display_name = '김담당' WHERE id = ?", [$this->u['kim']]);
        $this->p = TestDb::project($db, '분석 프로젝트/"x"', 'python');
        $this->v = TestDb::project($db, 'values', 'python');
        $now = '2026-10-01T00:00:00Z';
        foreach ([$this->p, $this->v] as $pid) {
            $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, ?)", [$pid, $this->u['kim'], $now]);
        }
        // P: Run 1 a1 a2 b1 → Run 2 a1 a3 → (D1 진행 중·담당 kim) → Run 3 a1 a3
        $f = new RunFixture($this->app, $this->root, $this->p, $this->u['admin']);
        $f->analyze(['a.py' => 3, 'b.py' => 1], [['a.py', 1], ['a.py', 2], ['b.py', 1]]);
        $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 3]]);
        $d1 = $f->id('a.py', 1);
        $admin = $db->one('SELECT * FROM users WHERE id = ?', [$this->u['admin']]);
        $this->app->defectUpdate()->update($admin, $d1, ['version' => (int) $db->value('SELECT version FROM defects WHERE id = ?', [$d1]),
            'status' => 'in_progress', 'assignee_id' => $this->u['kim'], 'severity' => 'high']);
        $f->analyze(['a.py' => 3], [['a.py', 1], ['a.py', 3]]);
        // V: 값 시험(선두 = + - @ 탭 CR, 따옴표·쉼표·개행, 한글·이모지, 제어 문자, XML 특수문자, 32,767자 초과)
        $av = TestDb::analysis($db, $this->v, $this->u['admin'], ['run_number' => 1]);
        $msgs = ['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tTAB", "\rCR", "a,\"b\"\nc", '한글 메시지 😀', "ctrl\x01x\x0Bv", '<tag> & "q"', str_repeat('가', 40000)];
        foreach ($msgs as $i => $m) {
            TestDb::defect($db, $this->v, $av, ['message' => $m, 'line_no' => $i + 1, 'file_path' => $i === 0 ? '=cmd|calc/x.py' : 'src/' . $i . '.py',
                'rule_key' => 'ruff:E' . (100 + $i), 'assignee_id' => $i === 1 ? $this->u['kim'] : null]);
        }
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return list<list<string>> CSV 행(머리글 포함) */
    private static function csv(string $body): array
    {
        $h = fopen('php://memory', 'r+');
        fwrite($h, substr($body, 3));
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($h);
        return $rows;
    }

    /** @return array{0: list<list<array{t:string, v:string}>>, 1: string, 2: list<list<string>>} [시트1 행, 시트1 XML, 시트2 행] */
    private function xlsx(string $body): array
    {
        $tmp = $this->root . '/t' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($tmp, $body);
        $zip = new \ZipArchive();
        $this->assertSame(true, $zip->open($tmp));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
            $this->assertTrue($zip->locateName($part) !== false, "필수 부분 {$part}");
            $this->assertTrue(simplexml_load_string((string) $zip->getFromName($part)) !== false, "XML {$part}");
        }
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $info = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($tmp);
        $read = static function (string $xml): array {
            $doc = simplexml_load_string($xml);
            $rows = [];
            foreach ($doc->sheetData->row as $row) {
                $cells = [];
                $n = 0;
                foreach ($row->c as $c) {
                    // 빈 칸은 쓰지 않으므로 r(A1 형식)의 열 글자로 자리를 맞춘다
                    $col = 0;
                    foreach (str_split((string) preg_replace('/\d+/', '', (string) $c['r'])) as $ch) {
                        $col = $col * 26 + ord($ch) - 64;
                    }
                    for (; $n < $col - 1; $n++) {
                        $cells[] = ['t' => '', 'v' => ''];
                    }
                    $cells[] = isset($c->is) ? ['t' => 's', 'v' => (string) $c->is->t] : ['t' => 'n', 'v' => (string) $c->v];
                    $n++;
                }
                $rows[] = $cells;
            }
            return $rows;
        };
        return [$read($sheet), $sheet, array_map(static fn (array $r): array => array_column($r, 'v'), $read($info))];
    }

    private function export(TestClient $c, string $path, array $body): Response
    {
        return $c->post($path, $body);
    }

    /** 목록 API의 모든 쪽(size 200) → [id, 규칙, 파일, 줄, 메시지, 심각도 라벨, 상태 라벨, 담당자, 최근 감지 Run] */
    private function listAll(TestClient $c, array $q): array
    {
        $out = [];
        for ($page = 1; ; $page++) {
            $r = $c->get("/api/v1/projects/{$this->p}/defects", $q + ['page' => (string) $page, 'size' => '200']);
            $this->assertSame(200, $r->status, $r->body);
            foreach ($r->decoded()['data'] as $d) {
                $out[] = [(string) $d['id'], $d['rule_key'], $d['file_path'], (string) $d['line'], $d['message'], Lang::get('icon.severity.' . $d['severity']),
                    Lang::get('icon.defect_status.' . $d['status']), $d['assignee']['display_name'] ?? '', (string) ($d['last_detected_run'] ?? '')];
            }
            if ($page * 200 >= $r->decoded()['page']['total']) {
                return $out;
            }
        }
    }

    public function testAccessControl(): void
    {
        $path = "/api/v1/projects/{$this->p}/defects/export";
        $anon = new TestClient($this->app);
        $anon->fetchCsrf();
        $this->assertSame(401, $this->export($anon, $path, ['format' => 'csv'])->status);
        $this->assertSame(401, $this->export($anon, '/api/v1/rules/export', ['format' => 'csv'])->status);
        $kim = $this->as('kim');
        $this->assertSame(403, $kim->post($path, ['format' => 'csv'], false)->status, 'CSRF 없으면 거부');
        $this->assertSame(405, $kim->get($path)->status, 'GET 내보내기 없음(POST만)');
        $lee = $this->as('lee');
        $r = $this->export($lee, $path, ['format' => 'csv']);
        $this->assertSame([404, 'project_not_found'], [$r->status, $r->decoded()['error']['code']], '보이지 않는 프로젝트는 없는 것과 같은 404');
        $this->assertSame(404, $this->export($lee, '/api/v1/projects/999999/defects/export', ['format' => 'csv'])->status);
        // 내 결함: 담당자가 나이고 볼 수 있는 프로젝트만(lee는 kim의 결함을 받지 못함)
        $mine = $this->export($kim, '/api/v1/my-defects/export', ['format' => 'csv']);
        $this->assertSame(200, $mine->status, $mine->body);
        $rows = self::csv($mine->takeFile());
        $this->assertSame(Lang::get('export.col.project'), $rows[0][0]);
        $this->assertSame(['values', '분석 프로젝트/"x"'], array_values(array_unique(array_column(array_slice($rows, 1), 0))), '지정된 결함 2건(각 프로젝트 1건)');
        $this->assertSame(2, count($rows) - 1);
        $this->assertSame([409, 'export_empty'], [($e = $this->export($lee, '/api/v1/my-defects/export', ['format' => 'csv']))->status, $e->decoded()['error']['code']]);
        // 규칙 목록은 로그인한 누구나(적재 전 = 0건 → 409)
        $this->assertSame(409, $this->export($lee, '/api/v1/rules/export', ['format' => 'csv', 'language' => 'python'])->status);
        // 형식·알 수 없는 키·잘못된 조건
        $this->assertSame('invalid_export_format', $this->export($kim, $path, ['format' => 'pdf'])->decoded()['error']['code']);
        $this->assertSame('invalid_export_format', $this->export($kim, $path, [])->decoded()['error']['code']);
        $this->assertSame([400, 'invalid_defect_filter'], [($e = $this->export($kim, $path, ['format' => 'csv', 'page' => '2']))->status, $e->decoded()['error']['code']], '알 수 없는 키');
        $this->assertSame(400, $this->export($kim, $path, ['format' => 'csv', 'severity' => 'huge'])->status);
        $this->assertSame(400, $this->export($kim, $path, ['format' => 'csv', 'q' => ['x']])->status);
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'export.defects' AND target_type = 'project'"), '거부는 감사에 남기지 않음');
    }

    public function testRowsMatchListApi(): void
    {
        $kim = $this->as('kim');
        $cases = [
            [], ['sort' => 'rule', 'order' => 'desc'], ['status' => 'open,in_progress'], ['q' => 'a.py', 'sort' => 'run'], ['severity' => 'high'],
            ['assignee' => (string) $this->u['kim']], ['assignee' => 'none', 'sort' => 'last_seen', 'order' => 'desc'], ['file' => 'a', 'file_mode' => 'prefix'],
            ['in_run_from' => '1', 'in_run_to' => '2'], ['from_run' => '2'],
            ['run' => '1'], ['run' => '2', 'sort' => 'status'], ['run' => '1', 'status' => 'open', 'sort' => 'run', 'order' => 'desc'], ['run' => '3'],
        ];
        foreach ($cases as $q) {
            $expect = $this->listAll($kim, $q);
            $r = $this->export($kim, "/api/v1/projects/{$this->p}/defects/export", ['format' => 'csv'] + $q);
            if ($expect === []) {
                $this->assertSame(409, $r->status, json_encode($q));
                continue;
            }
            $this->assertSame(200, $r->status, json_encode($q) . ' ' . $r->body);
            $rows = self::csv($r->takeFile());
            $head = array_shift($rows);
            $this->assertSame(array_column(\PJGuardian\Controllers\ExportController::defectColumns(false), 0), $head);
            $got = array_map(static fn (array $c): array => [$c[0], $c[2], $c[6], $c[7], $c[8], $c[3], $c[4], $c[5], $c[9]], $rows);
            $this->assertSame($expect, $got, '목록 API와 같은 행·순서·값 ' . json_encode($q, JSON_UNESCAPED_UNICODE));
        }
        // 과거 Run: Run 1에서 D1은 열림·담당 없음·심각도 미지정(복원 값), 최신은 진행 중·김담당·높음
        $d1Latest = array_values(array_filter(self::csv($this->export($kim, "/api/v1/projects/{$this->p}/defects/export", ['format' => 'csv'])->takeFile()), static fn ($r) => $r[5] === '김담당'));
        $this->assertSame(['높음', '진행 중', '3'], [$d1Latest[0][3], $d1Latest[0][4], $d1Latest[0][9]]);
        $run1 = self::csv($this->export($kim, "/api/v1/projects/{$this->p}/defects/export", ['format' => 'csv', 'run' => '1'])->takeFile());
        $this->assertSame(['1'], array_values(array_unique(array_column(array_slice($run1, 1), 9))), 'Run 열은 N으로 한정');
        $this->assertSame([], array_values(array_filter(array_slice($run1, 1), static fn ($r) => $r[5] !== '')), 'Run 1 시점에는 담당자 없음');
        // 삭제된 Run은 410(화면과 같음)
        $this->app->db()->execute("UPDATE analyses SET run_detail_deleted_at = '2026-10-02T00:00:00Z', run_detail_deleted_reason = 'manual', run_detail_deleted_by = ? WHERE project_id = ? AND run_number = 1", [$this->u['admin'], $this->p]);
        $gone = $this->export($kim, "/api/v1/projects/{$this->p}/defects/export", ['format' => 'csv', 'run' => '1']);
        $this->assertSame([410, 'run_detail_deleted'], [$gone->status, $gone->decoded()['error']['code']]);
        // 감사: 형식·행 수·Run·조건(데이터 값 없음)
        $det = json_decode((string) $this->app->db()->value("SELECT details FROM audit_log WHERE action = 'export.defects' AND target_id = ? ORDER BY id DESC LIMIT 1", [(string) $this->p]), true);
        $this->assertSame('csv', $det['format']);
        $this->assertTrue(isset($det['rows'], $det['filter']));
    }

    public function testValuesInjectionAndCsvFormat(): void
    {
        $kim = $this->as('kim');
        $r = $this->export($kim, "/api/v1/projects/{$this->v}/defects/export", ['format' => 'csv', 'sort' => 'file']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('text/csv; charset=UTF-8', $r->header('Content-Type'));
        $this->assertSame('no-store', $r->header('Cache-Control'));
        $this->assertContains("filename*=UTF-8''PJGuardian_values_%EA%B2%B0%ED%95%A8_", (string) $r->header('Content-Disposition'));
        $path = $r->filePath();
        $body = $r->takeFile();
        $this->assertSame(false, is_file((string) $path), '읽은 뒤 임시 파일 없음');
        $this->assertSame("\xEF\xBB\xBF", substr($body, 0, 3), 'BOM');
        $this->assertSame(0, preg_match("/(?<!\r)\n(?![^\"]*\"[,\r])/", substr($body, 3, strpos($body, "\r\n") + 2)), '머리글 CRLF');
        $rows = self::csv($body);
        $byLine = [];
        foreach (array_slice($rows, 1) as $row) {
            $byLine[(int) $row[7]] = $row;
        }
        $this->assertSame("'=cmd|calc/x.py", $byLine[1][6], '파일 경로도 무력화');
        $this->assertSame(["'=1+1", "'+SUM(A1)", "'-2+3", "'@cmd", "'\tTAB", "'\rCR"], [$byLine[1][8], $byLine[2][8], $byLine[3][8], $byLine[4][8], $byLine[5][8], $byLine[6][8]]);
        $this->assertSame("a,\"b\"\nc", $byLine[7][8], 'RFC 4180 인용');
        $this->assertSame('한글 메시지 😀', $byLine[8][8]);
        $this->assertSame('ctrlxv', $byLine[9][8], '제어 문자 제거');
        $this->assertSame('<tag> & "q"', $byLine[10][8]);
        $this->assertSame(SheetText::CELL_MAX, mb_strlen($byLine[11][8]));
        // P27D: 잘림 표시는 문자열 리소스(export.truncated, 요청 언어 — 시험 기본 한국어 "…(생략)")로 옮김(의도된 변경)
        $this->assertTrue(str_ends_with($byLine[11][8], \PJGuardian\Support\Lang::get('export.truncated')) && str_ends_with($byLine[11][8], '…(생략)'), '32,767자 초과는 잘라 표시');
        $this->assertSame(['김담당', ''], [$byLine[2][5], $byLine[1][5]], '담당자 없음은 빈 칸');
        $fin = (string) $this->app->db()->value('SELECT finished_at FROM analyses WHERE project_id = ?', [$this->v]);
        $this->assertSame((new \DateTimeImmutable($fin))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i'), $byLine[1][10], '최근 감지 일시 KST');
        $this->assertSame(['ruff', '미지정', '열림'], [$byLine[1][1], $byLine[1][3], $byLine[1][4]]);
    }

    public function testXlsxStructureNoFormulas(): void
    {
        $kim = $this->as('kim');
        $r = $this->export($kim, "/api/v1/projects/{$this->v}/defects/export", ['format' => 'xlsx', 'sort' => 'file', 'q' => '']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $r->header('Content-Type'));
        [$rows, $xml, $info] = $this->xlsx($r->takeFile());
        $this->assertSame(0, substr_count($xml, '<f'), '수식 셀 없음');
        $this->assertContains('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>', $xml);
        $this->assertContains('<autoFilter ref="A1:L12"/>', $xml);
        $this->assertSame(12, count($rows), '머리글 + 11행');
        $this->assertSame(array_column(\PJGuardian\Controllers\ExportController::defectColumns(false), 0), array_column($rows[0], 'v'));
        $first = $rows[1];
        $this->assertSame(['n', 's', 's', 'n'], [$first[0]['t'], $first[2]['t'], $first[6]['t'], $first[7]['t']], '번호·줄은 숫자 셀, 규칙·파일은 문자열 셀');
        $this->assertSame('=cmd|calc/x.py', $first[6]['v'], 'XLSX는 문자열 셀 그대로(접두 없음, 수식 아님)');
        $this->assertSame('=1+1', $first[8]['v']);
        $this->assertSame('n', $first[10]['t'], '날짜는 일련값 셀');
        $fin = new \DateTimeImmutable((string) $this->app->db()->value('SELECT finished_at FROM analyses WHERE project_id = ?', [$this->v]));
        $this->assertSame(($fin->getTimestamp() + 9 * 3600) / 86400 + 25569, (float) $first[10]['v'], 'KST Excel 일련값');
        $this->assertSame([Lang::get('export.info.project'), 'values'], $info[1]);
        $this->assertSame([Lang::get('export.info.run'), Lang::get('export.info.latest')], $info[4]);
        $this->assertSame([Lang::get('export.info.rows'), '11'], $info[5]);
        // 조건 시트: 담당자는 표시 이름, 적용된 조건 요약
        [, , $info2] = $this->xlsx($this->export($kim, "/api/v1/projects/{$this->v}/defects/export", ['format' => 'xlsx', 'assignee' => (string) $this->u['kim'] . ',none', 'severity' => 'not_defined'])->takeFile());
        $this->assertContains('김담당', $info2[2][1]);
        $this->assertContains('미지정', $info2[2][1]);
        $this->assertNotContains('kim', $info2[2][1], '로그인 이름 없음');
    }

    public function testLimitsBusyTimeoutAndCleanup(): void
    {
        $root = $this->tempDir('export-limit');
        mkdir($root . '/work');
        $app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work'], 'export' => ['max_rows' => '11']]);
        $db = $app->db();
        $u = TestDb::user($db, 'kim', 'user');
        $pid = TestDb::project($db, 'big', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$pid, $u]);
        $a = TestDb::analysis($db, $pid, $u, ['run_number' => 1]);
        for ($i = 1; $i <= 12; $i++) {
            TestDb::defect($db, $pid, $a, ['line_no' => $i, 'severity' => $i === 12 ? 'high' : 'low']);
        }
        $c = new TestClient($app);
        $this->assertSame(200, $c->login('kim', 'Passw0rd!x')->status);
        $path = "/api/v1/projects/{$pid}/defects/export";
        $over = $c->post($path, ['format' => 'xlsx']);
        $this->assertSame([413, 'export_too_large', ['count' => 12, 'limit' => 11]], [$over->status, $over->decoded()['error']['code'], $over->decoded()['error']['details']]);
        $this->assertSame(null, $over->filePath(), '파일 없음');
        $this->assertContains('12건', $over->decoded()['error']['message']);
        $ok = $c->post($path, ['format' => 'csv', 'severity' => 'low']);
        $this->assertSame(200, $ok->status, '상한 정확히(11건)');
        $this->assertSame(12, count(self::csv($ok->takeFile())));
        // 동시 실행: 사용자 자리를 다른 요청이 잡고 있으면 429, 서버 자리 2개가 모두 차면 429
        $dir = $root . '/work/export/locks';
        @mkdir($dir, 0777, true);
        $held = FileLock::tryAcquire($dir . '/user-' . $u . '-1.lock');
        $busy = $c->post($path, ['format' => 'csv', 'severity' => 'low']);
        $this->assertSame([429, 'export_busy', 'user'], [$busy->status, $busy->decoded()['error']['code'], $busy->decoded()['error']['details']['scope']]);
        $held->release();
        $s1 = FileLock::tryAcquire($dir . '/slot-1.lock');
        $s2 = FileLock::tryAcquire($dir . '/slot-2.lock');
        $busy2 = $c->post($path, ['format' => 'csv', 'severity' => 'low']);
        $this->assertSame([429, 'server'], [$busy2->status, $busy2->decoded()['error']['details']['scope']]);
        $s1->release();
        $s2->release();
        $again = $c->post($path, ['format' => 'csv', 'severity' => 'low']);
        $this->assertSame(200, $again->status, '자리가 비면 다시 가능(이전 요청이 자리를 놓았음)');
        $again->takeFile();
        // 시간 초과: 아주 짧은 제한으로 중단 → 503, 쓰던 임시 파일 없음
        $svc = new ExportService($root . '/work/export', 50000, 2, 1, 0.000001, 128 * 1024 * 1024, new \DateTimeZone('Asia/Seoul'), $app->logger);
        $gen = (static function () {
            for ($i = 0; $i < 2000; $i++) {
                yield [$i, 'x'];
            }
        })();
        $e = $this->assertThrows(ApiException::class, fn () => $svc->write('xlsx', 'S', 'I', [['a', 'int', 5], ['b', 'string', 5]], $gen, []));
        $this->assertSame([503, 'export_timeout'], [$e->status, $e->errorCode]);
        $left = array_values(array_filter(scandir($root . '/work/export') ?: [], static fn (string $n): bool => !in_array($n, ['.', '..', 'locks'], true)));
        $this->assertSame([], $left, '중단 뒤 임시 파일 없음');
        // 응답 보내기: 보낸 뒤 임시 파일 삭제(본문 없이 보내는 경우도)
        $resp = $c->post($path, ['format' => 'csv', 'severity' => 'low']);
        $file = (string) $resp->filePath();
        $this->assertTrue(is_file($file));
        ob_start();
        @$resp->send(false); // CLI라 헤더는 못 보냄(경고만) — 파일 처리만 본다
        ob_end_clean();
        $this->assertSame(false, is_file($file), '보낸 뒤 삭제');
        // 잘못된 설정은 시작 때 거부
        foreach (['0', '200001', 'abc'] as $bad) {
            $this->assertThrows(\PJGuardian\Support\ConfigException::class, fn () => TestApp::config(['export' => ['max_rows' => $bad]]));
        }
    }

    public function testFilenameSanitizing(): void
    {
        $this->assertSame('분석_프로젝트_x', ExportService::namePart('분석 프로젝트/"x"', 'project'));
        $this->assertSame('project', ExportService::namePart('../\\/:*?"<>|', 'project'));
        $this->assertSame('a_b', ExportService::namePart("a\r\nb", 'p'));
        $d = ExportService::disposition('PJGuardian_한글_결함.csv');
        $this->assertSame('attachment; filename="PJGuardian______.csv"; filename*=UTF-8\'\'PJGuardian_%ED%95%9C%EA%B8%80_%EA%B2%B0%ED%95%A8.csv', $d);
        $this->assertSame(0, preg_match('/[\r\n]/', $d));
        $kim = $this->as('kim');
        $r = $this->export($kim, "/api/v1/projects/{$this->p}/defects/export", ['format' => 'xlsx', 'run' => '2']);
        $cd = (string) $r->header('Content-Disposition');
        $r->takeFile();
        $this->assertSame(1, preg_match("/filename\\*=UTF-8''PJGuardian_%EB%B6%84%EC%84%9D_%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8_x_%EA%B2%B0%ED%95%A8_\\d{8}-\\d{4}_Run2\\.xlsx$/", $cd), $cd);
    }

    public function testRulesExport(): void
    {
        $rule = static fn (string $r, array $o = []): array => array_merge(['rule' => $r, 'name' => "name-{$r}", 'summary' => "line1\nline2", 'description' => 'long', 'category' => 'x',
            'origin' => '', 'default_enabled' => false, 'fixable' => 'none', 'has_suggestions' => false, 'has_options' => null, 'status' => 'stable',
            'deprecation' => null, 'docs_url' => null, 'recommended' => false, 'requires_type_info' => false], $o);
        $data = ['ruff' => ['0.1.0' => ['F401' => $rule('F401', ['default_enabled' => true, 'fixable' => 'always']), 'D100' => $rule('D100', ['status' => 'deprecated'])]],
            'eslint' => ['2.0.0' => ['no-undef' => $rule('no-undef', ['default_enabled' => true, 'docs_url' => 'https://eslint.org/docs/latest/rules/no-undef', 'summary' => '=danger'])]]];
        $source = new class ($data) implements \PJGuardian\Services\Rules\RuleSource {
            public function __construct(private readonly array $data)
            {
            }

            public function extract(string $tool, string $version): array
            {
                return ['version' => $version, 'components' => [], 'rules' => $this->data[$tool][$version]];
            }
        };
        $loader = new \PJGuardian\Services\Rules\RuleLoader($this->app->db(), $source, $this->app->toolRegistry(), $this->app->severityMap(),
            new \PJGuardian\Services\Rules\RuleEnrichment(PJG_ROOT . '/resources/rules/enrichment'), $this->app->audit(), $this->app->clock);
        $report = ['tools' => [], 'changed' => false, 'errors' => []];
        $loader->loadVersions('ruff', ['0.1.0'], '0.1.0', true, $report);
        $loader->loadVersions('eslint', ['2.0.0'], '2.0.0', true, $report);
        $lee = $this->as('lee');
        $r = $this->export($lee, '/api/v1/rules/export', ['format' => 'csv', 'language' => 'python', 'sort' => 'rule']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertContains("filename*=UTF-8''PJGuardian_rules_python_", (string) $r->header('Content-Disposition'));
        $rows = self::csv($r->takeFile());
        $this->assertSame(array_column(\PJGuardian\Controllers\ExportController::ruleColumns(), 0), $rows[0]);
        $this->assertSame([['ruff', 'D100', 'name-D100', 'x', '낮음', '아니오', '아니오', '아니오', '아니오', '예', 'line1 line2', '0.1.0', '', ''],
            ['ruff', 'F401', 'name-F401', 'x', '보통', '예', '아니오', '예', '예', '아니오', 'line1 line2', '0.1.0', '', '']], array_slice($rows, 1), '탭의 도구만, 요약 줄바꿈은 공백');
        $es = self::csv($this->export($lee, '/api/v1/rules/export', ['format' => 'csv', 'language' => 'javascript'])->takeFile());
        $this->assertSame(["'=danger", 'https://eslint.org/docs/latest/rules/no-undef'], [$es[1][10], $es[1][12]]);
        $this->assertSame(400, $this->export($lee, '/api/v1/rules/export', ['format' => 'csv', 'language' => 'python', 'tool' => 'eslint'])->status);
        $this->assertSame(400, $this->export($lee, '/api/v1/rules/export', ['format' => 'csv', 'project' => '1'])->status, '알 수 없는 키');
        [$xr, $xml, $info] = $this->xlsx($this->export($lee, '/api/v1/rules/export', ['format' => 'xlsx', 'language' => 'javascript'])->takeFile());
        $this->assertSame(0, substr_count($xml, '<f'));
        $this->assertSame('=danger', $xr[1][10]['v']);
        $this->assertSame([Lang::get('export.info.versions'), 'ESLint 2.0.0'], $info[2]);
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'export.rules' AND details LIKE '%defect%'"));
        $this->assertSame(3, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'export.rules'"), '성공한 규칙 내보내기 3번(거부는 남기지 않음)');
    }
}
