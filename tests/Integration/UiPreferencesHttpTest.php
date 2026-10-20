<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\Lang;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 화면 언어·테마(P27D) HTTP: 결정 순서(계정 > 쿠키 > Accept-Language > ko)가 <html lang>·data-theme에 반영, 본인만 바꾸는 API(CSRF·허용 값·401),
 * 로그인 뒤 쿠키 → 계정 한 번 이전, 서버 문구(API 오류·분석 실패·내보내기 머리글)가 요청 언어를 따름.
 */
final class UiPreferencesHttpTest extends TestCase
{
    private App $app;
    /** @var array<string, int> */
    private array $u = [];
    private int $pid;

    public function setUp(): void
    {
        $root = $this->tempDir('uipref');
        $this->app = TestApp::make($root, ['paths' => ['work_dir' => $root . '/work']]);
        mkdir($root . '/work');
        $db = $this->app->db();
        foreach (['admin' => 'admin', 'kim' => 'user', 'lee' => 'user'] as $login => $role) {
            $this->u[$login] = TestDb::user($db, $login, $role);
        }
        $this->pid = TestDb::project($db, '프로젝트 원래 이름', 'python');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'user', ?, '2026-10-01T00:00:00Z')", [$this->pid, $this->u['kim']]);
    }

    public function tearDown(): void
    {
        Lang::setLocale('ko');
    }

    private function as(string $login, array $cookies = []): TestClient
    {
        $c = new TestClient($this->app);
        $c->cookies += $cookies;
        $this->assertSame(200, $c->login($login, 'Passw0rd!x')->status, $login);
        return $c;
    }

    private static function htmlAttrs(string $html): array
    {
        preg_match('/<html lang="([a-z]+)"( data-theme="([a-z]+)")?>/', $html, $m);
        return [$m[1] ?? null, $m[3] ?? null];
    }

    public function testLanguageAndThemeDecisionOnPages(): void
    {
        $anon = new TestClient($this->app);
        $page = static fn (array $headers = []) => $anon->request('GET', '/login', [], null, null, $headers);
        $this->assertSame(['ko', null], self::htmlAttrs($page()->body), '기본: ko, 시스템 따라가기(속성 없음)');
        $en = $page(['accept-language' => 'en-US,en;q=0.9']);
        $this->assertSame('en', self::htmlAttrs($en->body)[0], '브라우저 언어');
        $this->assertContains('data-ui-switch', $en->body, '로그인 화면에도 전환 버튼');
        $this->assertNotContains('로그인', strip_tags((string) preg_replace('#<script.*?</script>#s', '', $en->body)), '영어 화면에 한국어 문구 없음');
        $anon->cookies = ['pjg_lang' => 'ko', 'pjg_theme' => 'dark'];
        $this->assertSame(['ko', 'dark'], self::htmlAttrs($page(['accept-language' => 'en'])->body), '쿠키 > 브라우저, 다크는 서버가 속성으로(깜빡임 없음)');
        $anon->cookies = ['pjg_lang' => 'jp', 'pjg_theme' => 'neon'];
        $this->assertSame(['en', null], self::htmlAttrs($page(['accept-language' => 'en'])->body), '허용 밖 쿠키는 무시');
    }

    public function testPreferencesApiOwnAccountOnlyWithValidation(): void
    {
        $anon = new TestClient($this->app);
        $anon->fetchCsrf();
        $this->assertSame(401, $anon->put('/api/v1/users/me/preferences', ['language' => 'en'])->status);
        $kim = $this->as('kim');
        $this->assertSame(403, $kim->request('PUT', '/api/v1/users/me/preferences', [], ['language' => 'en'], null, [], false)->status, 'CSRF 없으면 거부');
        foreach ([['language' => 'fr'], ['theme' => 'neon'], [], ['language' => 'en', 'user_id' => $this->u['lee']], ['language' => 1]] as $bad) {
            $r = $kim->put('/api/v1/users/me/preferences', $bad);
            $this->assertSame([400, 'invalid_preferences'], [$r->status, $r->decoded()['error']['code'] ?? null], json_encode($bad));
        }
        $r = $kim->put('/api/v1/users/me/preferences', ['language' => 'en', 'theme' => 'dark']);
        $this->assertSame(200, $r->status, (string) $r->body);
        $this->assertSame(['en', 'dark', 'en', 'dark'], [$r->decoded()['data']['ui_language'], $r->decoded()['data']['ui_theme'], $r->decoded()['data']['effective_language'], $r->decoded()['data']['effective_theme']]);
        $me = $kim->request('GET', '/api/v1/users/me', [], null, null, ['accept-language' => 'ko'])->decoded()['data'];
        $this->assertSame(['en', 'dark', 'en', 'dark'], [$me['ui_language'], $me['ui_theme'], $me['effective_language'], $me['effective_theme']], '계정 > 브라우저');
        $this->assertSame(['en', 'dark'], self::htmlAttrs($kim->request('GET', '/account', [], null, null, ['accept-language' => 'ko'])->body));
        // 다른 사용자는 그대로, 다른 사용자 것을 바꾸는 경로는 없음
        $this->assertSame([null, null], array_values($this->app->db()->one('SELECT ui_language, ui_theme FROM users WHERE id = ?', [$this->u['lee']])));
        $this->assertTrue(in_array($kim->put("/api/v1/users/{$this->u['lee']}/preferences", ['language' => 'en'])->status, [404, 405], true));
        // null = 자동으로 되돌리기(하나만 보내도 됨)
        $r = $kim->put('/api/v1/users/me/preferences', ['language' => null]);
        $this->assertSame([null, 'dark'], [$r->decoded()['data']['ui_language'], $r->decoded()['data']['ui_theme']]);
        $this->assertSame('ko', self::htmlAttrs($kim->get('/account')->body)[0]);
    }

    public function testCookieMovesToAccountOnceAfterLogin(): void
    {
        $kim = $this->as('kim', ['pjg_lang' => 'en', 'pjg_theme' => 'dark']);
        $this->assertSame(['en', 'dark'], self::htmlAttrs($kim->get('/account')->body));
        $row = $this->app->db()->one('SELECT ui_language, ui_theme FROM users WHERE id = ?', [$this->u['kim']]);
        $this->assertSame(['en', 'dark'], [$row['ui_language'], $row['ui_theme']], '비어 있던 계정 값으로 한 번 옮김');
        // 이후 다른 브라우저 쿠키(ko)는 계정 값을 바꾸지 않는다(계정 우선)
        $kim->cookies['pjg_lang'] = 'ko';
        $this->assertSame('en', self::htmlAttrs($kim->get('/account')->body)[0]);
        $this->assertSame('en', $this->app->db()->value('SELECT ui_language FROM users WHERE id = ?', [$this->u['kim']]));
    }

    public function testServerTextFollowsRequestLanguage(): void
    {
        $kim = $this->as('kim');
        // API 오류 문구
        $ko = $kim->request('GET', '/api/v1/projects/999999', [], null, null, ['accept-language' => 'ko'])->decoded()['error'];
        $kim->cookies['pjg_lang'] = 'en';
        $en = $kim->get('/api/v1/projects/999999')->decoded()['error'];
        $this->assertSame($ko['code'], $en['code']);
        $this->assertFalse($ko['message'] === $en['message'], '같은 오류, 다른 언어');
        $this->assertSame(0, preg_match('/[\x{AC00}-\x{D7A3}]/u', $en['message']), $en['message']);
        // 분석 실패 상자: 저장된 코드로 읽을 때 번역(DB 문장은 그대로)
        $aid = TestDb::analysis($this->app->db(), $this->pid, $this->u['kim'], ['status' => 'failed', 'failure_code' => 'timeout', 'finished_at' => '2026-10-01T00:00:00Z']);
        $a = $kim->get("/api/v1/analyses/{$aid}")->decoded()['data'];
        $this->assertSame(0, preg_match('/[\x{AC00}-\x{D7A3}]/u', $a['failure']['title'] . $a['failure_message']), $a['failure']['title'] . ' / ' . $a['failure_message']);
        $kim->cookies['pjg_lang'] = 'ko';
        $this->app->db()->execute('UPDATE users SET ui_language = NULL WHERE id = ?', [$this->u['kim']]);
        $aKo = $kim->get("/api/v1/analyses/{$aid}")->decoded()['data'];
        $this->assertSame(1, preg_match('/[\x{AC00}-\x{D7A3}]/u', $aKo['failure']['title']));
        // 내보내기 머리글: 내보내는 시점의 화면 언어(값·열 순서는 같음), 사용자 입력(프로젝트 이름)은 그대로
        $ok = TestDb::analysis($this->app->db(), $this->pid, $this->u['kim'], ['finished_at' => '2026-10-01T01:00:00Z']);
        TestDb::defect($this->app->db(), $this->pid, $ok);
        $headers = function (string $lang) use ($kim): array {
            $kim->cookies['pjg_lang'] = $lang;
            $this->app->db()->execute('UPDATE users SET ui_language = NULL WHERE id = ?', [$this->u['kim']]);
            $r = $kim->post("/api/v1/projects/{$this->pid}/defects/export", ['format' => 'csv']);
            $this->assertSame(200, $r->status, (string) $r->body);
            $lines = preg_split('/\r?\n/', ltrim($r->takeFile(), "\xEF\xBB\xBF")) ?: [];
            return [str_getcsv($lines[0] ?? ''), implode("\n", $lines)];
        };
        [$koHead] = $headers('ko');
        [$enHead] = $headers('en');
        $this->assertSame(count($koHead), count($enHead), '열 수 같음');
        $this->assertFalse($koHead === $enHead, '머리글이 언어를 따름');
        $this->assertSame(0, preg_match('/[\x{AC00}-\x{D7A3}]/u', implode(',', $enHead)), implode(',', $enHead));
    }
}
