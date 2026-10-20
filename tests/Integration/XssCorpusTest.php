<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Services\Export\SheetText;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\Support\XssCorpus;
use PJGuardian\Tests\TestCase;

/**
 * 출력 이스케이프(P31 A-2): 사용자 입력이 들어가는 값(프로젝트 이름·설명, 결함 메시지·파일 경로·규칙, 사용자 이름, 그룹 이름·설명,
 * 업로드 파일 이름, 저장소 주소, 알림 값, 감사 로그 세부 값)에 XssCorpus 문자열을 넣고, 그 값을 보여 주는 서버 화면(HTML)·
 * JSON·CSV 내보내기에서 이스케이프되지 않은 조각이 나오지 않는지 본다. 화면의 대부분은 JS가 textContent로 그리고
 * (innerHTML 금지 — tests/js/xss_corpus.test.mjs가 정적 검사), 마크다운(FAQ·가이드)은 tests/js의 같은 모음으로 확인한다.
 */
final class XssCorpusTest extends TestCase
{
    public function testDetectorCatchesUnescapedCorpus(): void
    {
        // 검사기 자체 점검: 원문을 그대로 넣은 HTML은 모두 잡고, Html::e로 이스케이프한 HTML은 하나도 잡지 않는다
        $caught = 0;
        foreach (XssCorpus::html() as $s) {
            if (str_contains($s, '{{')) {
                continue; // 틀 문법 모양은 서버 HTML에서 코드가 아님(JS 틀 엔진 없음)
            }
            $this->assertTrue(XssCorpus::leak("<html><body><p>{$s}</p><input value=\"{$s}\"></body></html>") !== null, $s);
            $caught++;
            $this->assertSame(null, XssCorpus::leak('<html><body><p>' . \PJGuardian\Support\Html::e($s) . '</p><input value="' . \PJGuardian\Support\Html::e($s) . '"></body></html>'), $s);
        }
        $this->assertTrue($caught >= 7);
    }

    public function testServerRenderedPagesAndApiEscapeCorpus(): void
    {
        $dir = $this->tempDir('xss');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $corpus = XssCorpus::html();
        $pick = static fn (int $i): string => $corpus[$i % count($corpus)];
        $t = TestDb::T0;
        $db->execute('UPDATE users SET display_name = ? WHERE id = ?', [$pick(0), $admin]);
        $u = TestDb::user($db, 'kim', 'user');
        $db->execute('UPDATE users SET display_name = ?, email = ? WHERE id = ?', [$pick(1), 'kim@example.test', $u]);
        $p = TestDb::project($db, $pick(2));
        $db->execute('UPDATE projects SET description = ? WHERE id = ?', [$pick(3), $p]);
        $db->execute("INSERT INTO groups (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)", [mb_substr($pick(4), 0, 60), $pick(5), $t, $t]);
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, ?, 1, ?)', [$u, $t]);
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', 1, ?)", [$p, $t]);
        $an = TestDb::analysis($db, $p, $admin, ['total_count' => count($corpus)]);
        $ids = [];
        foreach ($corpus as $i => $s) {
            $ids[] = TestDb::defect($db, $p, $an, ['message' => $s, 'file_path' => 'src/' . preg_replace('/[\\\\\/]/', '_', $pick($i + 1)) . '.py',
                'assignee_id' => $u]);
        }
        $db->execute("INSERT INTO notifications (user_id, kind, project_id, analysis_id, params, dedupe_key, created_at) VALUES (?, 'analysis_failed', ?, ?, ?, 'x1', ?)",
            [$admin, $p, $an, json_encode(['project_name' => $pick(6), 'code' => $pick(7)]), $t]);
        $app->audit()->record(\PJGuardian\Services\Actor::fromUser(['id' => $admin, 'login_id' => 'admin', 'role' => 'admin'], '127.0.0.1'), 'project.update', 'project', (string) $p,
            ['name' => ['from' => $pick(0), 'to' => $pick(1)], 'description' => ['from' => $pick(2), 'to' => $pick(3)]]);

        $c = new TestClient($app);
        $this->assertSame(200, $c->login('admin', 'Passw0rd!x')->status);
        $pages = ['/dashboard', '/projects', "/projects/{$p}", "/projects/{$p}/edit", "/projects/{$p}/delete", "/projects/{$p}/history", "/defects/{$ids[0]}",
            "/analyses/{$an}", '/my-defects', '/groups', '/groups/1', '/groups/1/edit', '/groups/1/delete', '/admin/users', '/account', '/notifications', '/history',
            '/upload', '/defects', '/admin/audit-logs', '/admin/monitoring'];
        $checked = 0;
        foreach ($pages as $path) {
            $r = $c->get($path);
            $this->assertTrue(in_array($r->status, [200], true), "{$path} {$r->status}");
            $leak = XssCorpus::leak($r->body);
            $this->assertSame(null, $leak, "{$path}: 이스케이프되지 않은 조각");
            $checked++;
        }
        // JSON API: 값은 JSON 문자열로만(HTML로 해석될 응답 유형이 아님)
        foreach (["/api/v1/projects/{$p}", "/api/v1/projects/{$p}/defects", "/api/v1/defects/{$ids[1]}", '/api/v1/notifications', '/api/v1/groups/1', '/api/v1/admin/audit-logs',
            '/api/v1/users'] as $path) {
            $r = $c->get($path);
            $this->assertSame(200, $r->status, "{$path} {$r->body}");
            $this->assertTrue(str_starts_with((string) $r->header('Content-Type'), 'application/json'), "{$path} 응답 유형");
            $this->assertSame('nosniff', $r->header('X-Content-Type-Options'));
        }
        // 감사 로그 사람이 읽는 설명(서버가 만든 문장)도 HTML 조각 없이 글자로만 — 화면은 textContent
        $audit = $c->get('/api/v1/admin/audit-logs')->decoded()['data'][0];
        $this->assertTrue(str_contains((string) $audit['summary'], "<script>alert('pjg-xss-1')</script>") || str_contains((string) $audit['summary'], 'pjg-xss'),
            '설명에는 값이 글자 그대로(화면이 textContent로 그림)');
        $this->assertTrue($checked >= 20);

        // CSV 내보내기: 수식 시작 글자는 작은따옴표로 무력화
        $csv = $c->post("/api/v1/projects/{$p}/defects/export", ['format' => 'csv']);
        $this->assertSame(200, $csv->status, $csv->body);
        foreach (XssCorpus::all()['csv'] as $s) {
            $this->assertSame("'", SheetText::csv($s)[0], json_encode($s));
        }
    }

    public function testCsvExportNeutralizesFormulaCellsEndToEnd(): void
    {
        $dir = $this->tempDir('xss2');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        $p = TestDb::project($db, 'p');
        $an = TestDb::analysis($db, $p, $admin);
        foreach (XssCorpus::all()['csv'] as $s) {
            TestDb::defect($db, $p, $an, ['message' => $s]);
        }
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $r = $c->post("/api/v1/projects/{$p}/defects/export", ['format' => 'csv']);
        $this->assertSame(200, $r->status, $r->body);
        $body = $r->body !== '' ? $r->body : (string) file_get_contents((string) $r->filePath());
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $body))));
        $cells = array_merge(...array_slice($rows, 1));
        foreach ($cells as $cell) {
            if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                $this->fail('무력화되지 않은 수식 셀: ' . $cell);
            }
        }
        $this->assertTrue(in_array("'=HYPERLINK(\"http://evil.example\",\"x\")", $cells, true), '작은따옴표 붙은 값');
    }
}
