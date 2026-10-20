<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * FAQ(P29B): 권한(읽기는 로그인 사용자, 쓰기는 admin만 — 그룹 관리자·일반 사용자 403, CSRF), 비공개 항목은 일반 사용자에게 없는 것과 같음(목록·검색·건수·직접 주소 404),
 * 데이터 규칙(길이·빈 값·공백 정리·500개 상한·DB CHECK), 동시 수정(version → 409 faq_conflict + 최신 값, 삭제된 항목 404), 순서 바꾸기(같은 분류만·목록이 다르면 409·다른 분류 영향 없음),
 * 감사(동작마다 한 번, 답변 전문 없음, 삭제에 질문 제목, 같은 값 저장은 기록 없음), 검색(대소문자·부분·한국어·특수문자 %·_·따옴표·\ 안전), 화면(편집 요소는 admin에게만), 029 전 안내·503.
 */
final class FaqTest extends TestCase
{
    private App $app;

    public function setUp(): void
    {
        $dir = $this->tempDir('faq');
        $this->app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'admin2', 'admin');
        TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $t = '2026-10-01T00:00:00Z';
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('팀', ?, ?)", [$t, $t]);
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, ?, 1, ?)', [$mgr, $t]);
    }

    private function as(string $login): TestClient
    {
        $c = new TestClient($this->app);
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    /** @return array<string, mixed> */
    private function add(TestClient $c, string $category, string $question, string $answer = '답변', bool $published = true): array
    {
        $r = $c->post('/api/v1/admin/faq', ['category' => $category, 'question' => $question, 'answer' => $answer, 'is_published' => $published]);
        $this->assertSame(201, $r->status, $r->body);
        return $r->decoded()['data'];
    }

    /** @return list<array<string, mixed>> */
    private function audits(string $action): array
    {
        return $this->app->db()->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    public function testPermissionsAndDraftsAreInvisible(): void
    {
        $admin = $this->as('admin');
        $pub = $this->add($admin, '업로드', '업로드 한도는?', '설정 값을 따릅니다.');
        $draft = $this->add($admin, '비밀 분류', '초안 질문 유니크', '초안 답변 유니크', false);
        $anon = new TestClient($this->app);
        $this->assertSame(401, $anon->get('/api/v1/faq')->status);
        $this->assertSame(401, $anon->get('/api/v1/faq/' . $pub['id'])->status);
        foreach (['kim', 'mgr'] as $login) {
            $c = $this->as($login);
            $list = $c->get('/api/v1/faq');
            $this->assertSame(200, $list->status, $login);
            $d = $list->decoded()['data'];
            $this->assertSame([[$pub['id']], 1, ['업로드']], [array_column($d['items'], 'id'), $d['total'], $d['categories']], "{$login} 공개 항목만");
            $this->assertFalse(str_contains($list->body, '유니크') || str_contains($list->body, '비밀 분류') || str_contains($list->body, 'is_published') || str_contains($list->body, 'updated_by'), "{$login} 비공개·계정 정보 없음");
            $this->assertSame(404, $c->get('/api/v1/faq/' . $draft['id'])->status, '비공개 직접 주소 404');
            $this->assertSame(404, $c->get('/api/v1/faq/99999')->status, '없는 항목도 같은 404');
            $this->assertSame([], $c->get('/api/v1/faq', ['q' => '유니크'])->decoded()['data']['items'], '검색에도 없음');
            $this->assertSame(1, $c->get('/api/v1/faq', ['include_drafts' => '1'])->decoded()['data']['total'], 'include_drafts는 admin만');
            // 쓰기 403
            $this->assertSame(403, $c->post('/api/v1/admin/faq', ['category' => 'a', 'question' => 'b', 'answer' => 'c'])->status, "{$login} 추가");
            $this->assertSame(403, $c->patch('/api/v1/admin/faq/' . $pub['id'], ['version' => 1, 'question' => 'x'])->status, "{$login} 수정");
            $this->assertSame(403, $c->request('DELETE', '/api/v1/admin/faq/' . $pub['id'])->status, "{$login} 삭제");
            $this->assertSame(403, $c->post('/api/v1/admin/faq/reorder', ['category' => '업로드', 'ids' => [$pub['id']]])->status, "{$login} 순서");
        }
        // admin: 비공개 포함, 표시 이름(이메일 없음)
        $all = $admin->get('/api/v1/faq')->decoded()['data'];
        $this->assertSame(2, $all['total']);
        $this->assertSame('admin', $all['items'][0]['updated_by_name'] !== null ? 'admin' : null);
        $this->assertFalse(str_contains($admin->get('/api/v1/faq')->body, '@'), '이메일 없음');
        $this->assertSame(1, $admin->get('/api/v1/faq', ['include_drafts' => '0'])->decoded()['data']['total'], 'admin이 공개만 보기');
        // CSRF 없으면 거부
        $this->assertSame(403, $admin->request('POST', '/api/v1/admin/faq', [], ['category' => 'a', 'question' => 'b', 'answer' => 'c'], null, [], false)->status);
        $this->assertSame(403, $admin->request('PATCH', '/api/v1/admin/faq/' . $pub['id'], [], ['version' => 1, 'question' => 'x'], null, [], false)->status);
        // 공개로 바꾸면 일반 사용자에게 보임
        $this->assertSame(200, $admin->patch('/api/v1/admin/faq/' . $draft['id'], ['version' => 1, 'is_published' => true])->status);
        $this->assertSame(2, $this->as('kim')->get('/api/v1/faq')->decoded()['data']['total']);
    }

    public function testDataRulesAndLimit(): void
    {
        $admin = $this->as('admin');
        $item = $this->add($admin, "  설치\t 와   환경 ", "  질문\n두 줄  ", "\r\n첫 줄  \r\n\r\n둘째 줄\u{0007}\r\n");
        $this->assertSame(['설치 와 환경', '질문 두 줄', "첫 줄\n\n둘째 줄"], [$item['category'], $item['question'], $item['answer']], '공백·줄바꿈·제어 문자 정리');
        $bad = [
            [['category' => '', 'question' => 'q', 'answer' => 'a'], 'category'],
            [['category' => '   ', 'question' => 'q', 'answer' => 'a'], 'category'],
            [['category' => str_repeat('가', 41), 'question' => 'q', 'answer' => 'a'], 'category'],
            [['category' => 'c', 'question' => str_repeat('a', 201), 'answer' => 'a'], 'question'],
            [['category' => 'c', 'question' => 'q', 'answer' => str_repeat('b', 10001)], 'answer'],
            [['category' => 'c', 'question' => 'q'], 'answer'],
            [['category' => 'c', 'question' => 'q', 'answer' => 'a', 'is_published' => 'yes'], 'is_published'],
            [['category' => 1, 'question' => 'q', 'answer' => 'a'], 'category'],
        ];
        foreach ($bad as [$body, $field]) {
            $r = $admin->post('/api/v1/admin/faq', $body);
            $this->assertSame(400, $r->status, json_encode($body));
            $this->assertSame($field, $r->decoded()['error']['details']['field'] ?? null, json_encode($body));
        }
        $this->assertSame(201, $admin->post('/api/v1/admin/faq', ['category' => str_repeat('가', 40), 'question' => str_repeat('나', 200), 'answer' => str_repeat('다', 10000)])->status, '상한 딱 맞음');
        // DB CHECK도 막는다(서버 검증을 건너뛴 쓰기)
        $threw = false;
        try {
            $this->app->db()->execute("INSERT INTO faq_items (category, question, answer, search_text, created_at, updated_at) VALUES ('', 'q', 'a', 'x', '2026-10-01T00:00:00Z', '2026-10-01T00:00:00Z')");
        } catch (\PDOException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'CHECK');
        // 항목 수 상한 500
        $db = $this->app->db();
        $now = '2026-10-01T00:00:00Z';
        $db->transaction(function () use ($db, $now): void {
            for ($i = 0; $i < 498; $i++) {
                $db->execute('INSERT INTO faq_items (category, question, answer, search_text, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', ['채움', "q{$i}", 'a', 'x', 1000 + $i, $now, $now]);
            }
        });
        $this->assertSame(500, (int) $db->value('SELECT COUNT(*) FROM faq_items'));
        $r = $admin->post('/api/v1/admin/faq', ['category' => 'c', 'question' => 'q', 'answer' => 'a']);
        $this->assertSame([400, 'faq_limit'], [$r->status, $r->decoded()['error']['code']]);
        $this->assertSame(500, (int) $db->value('SELECT COUNT(*) FROM faq_items'));
        $this->assertSame('029', $db->value("SELECT version FROM schema_migrations WHERE version = '029'"), '마이그레이션 029 적용(시험 DB)');
    }

    public function testConflictDeleteAndAudit(): void
    {
        $admin = $this->as('admin');
        $other = $this->as('admin2');
        $item = $this->add($admin, '계정', '비밀번호를 잊었어요', '관리자에게 임시 비밀번호를 요청하세요.');
        $this->assertSame(1, count($this->audits('faq.create')));
        $this->assertFalse(str_contains((string) $this->audits('faq.create')[0]['details'], '임시 비밀번호를 요청'), '답변 전문 없음');
        // 같은 값 저장: 기록·version 그대로
        $same = $admin->patch('/api/v1/admin/faq/' . $item['id'], ['version' => 1, 'question' => '비밀번호를 잊었어요'])->decoded()['data'];
        $this->assertSame([false, 1], [$same['changed'], $same['item']['version']]);
        $this->assertSame(0, count($this->audits('faq.update')));
        // 다른 관리자가 먼저 수정 → 같은 version으로 두 번째 저장은 409 + 최신 값
        $r1 = $other->patch('/api/v1/admin/faq/' . $item['id'], ['version' => 1, 'answer' => '새 답변 내용입니다.']);
        $this->assertSame([200, 2], [$r1->status, $r1->decoded()['data']['item']['version']]);
        $r2 = $admin->patch('/api/v1/admin/faq/' . $item['id'], ['version' => 1, 'question' => '내 수정']);
        $this->assertSame([409, 'faq_conflict'], [$r2->status, $r2->decoded()['error']['code']]);
        $this->assertSame(['새 답변 내용입니다.', 2], [$r2->decoded()['error']['details']['current']['answer'], $r2->decoded()['error']['details']['current']['version']]);
        $upd = $this->audits('faq.update');
        $this->assertSame(1, count($upd));
        $details = json_decode((string) $upd[0]['details'], true);
        $this->assertSame(['answer'], $details['fields']);
        $this->assertTrue(isset($details['answer_length'], $details['answer_sha256']) && !str_contains((string) $upd[0]['details'], '새 답변 내용'), '답변은 길이·해시만');
        // 공개 전환은 faq.publish
        $this->assertSame(200, $admin->patch('/api/v1/admin/faq/' . $item['id'], ['version' => 2, 'is_published' => false])->status);
        $this->assertSame(1, count($this->audits('faq.publish')));
        $this->assertSame(1, count($this->audits('faq.update')), '공개 전환만이면 update 기록 없음');
        $this->assertSame(400, $admin->patch('/api/v1/admin/faq/' . $item['id'], ['question' => 'version 없음'])->status);
        // 삭제: 질문 제목이 감사에, 삭제된 항목 수정·삭제는 404
        $this->assertSame(200, $admin->request('DELETE', '/api/v1/admin/faq/' . $item['id'])->status);
        $del = $this->audits('faq.delete');
        $this->assertTrue(count($del) === 1 && str_contains((string) $del[0]['details'], '비밀번호를 잊었어요'));
        $this->assertSame(404, $other->patch('/api/v1/admin/faq/' . $item['id'], ['version' => 3, 'question' => 'x'])->status);
        $this->assertSame(404, $admin->request('DELETE', '/api/v1/admin/faq/' . $item['id'])->status);
    }

    public function testReorderWithinCategory(): void
    {
        $admin = $this->as('admin');
        $a = $this->add($admin, '업로드', 'A');
        $b = $this->add($admin, '업로드', 'B');
        $c = $this->add($admin, '업로드', 'C', '답', false);
        $x = $this->add($admin, '계정', 'X');
        $y = $this->add($admin, '계정', 'Y');
        $order = static fn (array $items): array => array_map(static fn (array $i): string => $i['category'] . ':' . $i['question'], $items);
        $this->assertSame(['업로드:A', '업로드:B', '업로드:C', '계정:X', '계정:Y'], $order($admin->get('/api/v1/faq')->decoded()['data']['items']), '분류 순서 = 분류 안 첫 항목 순서');
        $r = $admin->post('/api/v1/admin/faq/reorder', ['category' => '업로드', 'ids' => [$c['id'], $a['id'], $b['id']]]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['업로드:C', '업로드:A', '업로드:B', '계정:X', '계정:Y'], $order($admin->get('/api/v1/faq')->decoded()['data']['items']));
        $this->assertSame(['업로드:A', '업로드:B', '계정:X', '계정:Y'], $order($this->as('kim')->get('/api/v1/faq')->decoded()['data']['items']), '일반 사용자: 비공개 C 없음');
        $before = $this->app->db()->all('SELECT id, sort_order, version FROM faq_items ORDER BY id');
        foreach ([[$a['id'], $b['id']], [$a['id'], $b['id'], $c['id'], $x['id']], [$a['id'], $b['id'], $x['id']], [$a['id'], $a['id'], $b['id']]] as $ids) {
            $r = $admin->post('/api/v1/admin/faq/reorder', ['category' => '업로드', 'ids' => $ids]);
            $this->assertSame([409, 'faq_order_conflict'], [$r->status, $r->decoded()['error']['code']], json_encode($ids));
        }
        $this->assertSame(400, $admin->post('/api/v1/admin/faq/reorder', ['category' => '업로드', 'ids' => ['x']])->status);
        $this->assertSame($before, $this->app->db()->all('SELECT id, sort_order, version FROM faq_items ORDER BY id'), '실패하면 아무것도 바뀌지 않음(version도 그대로)');
        $this->assertSame(1, count($this->audits('faq.reorder')));
        // 분류를 옮기면 새 분류의 맨 끝
        $this->assertSame(200, $admin->patch('/api/v1/admin/faq/' . $a['id'], ['version' => 1, 'category' => '계정'])->status);
        $this->assertSame(['업로드:C', '업로드:B', '계정:X', '계정:Y', '계정:A'], $order($admin->get('/api/v1/faq')->decoded()['data']['items']));
    }

    public function testSearchIsSafe(): void
    {
        $admin = $this->as('admin');
        $this->add($admin, '업로드', 'ZIP Upload limit', '크기 한도는 설정을 따릅니다.');
        $this->add($admin, '계정', '비밀번호 100% 안전?', "따옴표 ' 와 \" 그리고 역슬래시 \\ 와 밑줄 a_b");
        $this->add($admin, '기타', '아무 관련 없음', 'zzz');
        $kim = $this->as('kim');
        $q = static fn (string $s): array => array_column($kim->get('/api/v1/faq', ['q' => $s])->decoded()['data']['items'], 'question');
        $this->assertSame(['ZIP Upload limit'], $q('zip upload'), '대소문자 무시·낱말 AND');
        $this->assertSame(['ZIP Upload limit'], $q('한도'), '한국어·답변');
        $this->assertSame(['ZIP Upload limit'], $q('업로'), '분류·부분 일치');
        $this->assertSame(['비밀번호 100% 안전?'], $q('%'));
        $this->assertSame(['비밀번호 100% 안전?'], $q('a_b'));
        $this->assertSame([], $q('a%b'), '% 는 글자');
        $this->assertSame(['비밀번호 100% 안전?'], $q('_'), '_ 는 글자(와일드카드면 세 항목 모두 걸림)');
        $this->assertSame(['비밀번호 100% 안전?'], $q("'"));
        $this->assertSame(['비밀번호 100% 안전?'], $q('\\'));
        $this->assertSame([], $q('없는낱말'));
        $this->assertSame(['ZIP Upload limit'], array_column($kim->get('/api/v1/faq', ['category' => '업로드'])->decoded()['data']['items'], 'question'), '분류');
        $this->assertSame(400, $kim->get('/api/v1/faq', ['q' => ['x']])->status);
    }

    public function testPageAndUnavailable(): void
    {
        $kim = $this->as('kim')->get('/guide/faq');
        $this->assertSame(200, $kim->status);
        $this->assertTrue(str_contains($kim->body, 'data-guide-tab="faq" aria-current="page"') && str_contains($kim->body, 'data-admin="0"'));
        $this->assertFalse(str_contains($kim->body, 'data-faq-add'), '일반 사용자: 편집 단추가 DOM에 없음');
        $admin = $this->as('admin')->get('/guide/faq');
        $this->assertTrue(str_contains($admin->body, 'data-faq-add') && str_contains($admin->body, 'data-admin="1"'));
        $this->assertSame(302, (new TestClient($this->app))->get('/guide/faq')->status, '비로그인은 로그인으로');
        // 029 전(표 없음): 화면 안내, API 503 — 시험 DB에서만 표를 지운다
        $this->app->db()->execute('DROP TABLE faq_items');
        $fresh = TestApp::make($this->tempDir('faq2'), []);
        TestDb::user($fresh->db(), 'kim', 'user');
        $fresh->db()->execute('DROP TABLE faq_items');
        $c = new TestClient($fresh);
        $c->login('kim', 'Passw0rd!x');
        $this->assertTrue(str_contains($c->get('/guide/faq')->body, 'data-ready="0"'));
        $r = $c->get('/api/v1/faq');
        $this->assertSame([503, 'faq_unavailable'], [$r->status, $r->decoded()['error']['code']]);
    }
}
