<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 가이드 화면·API 접근 통제와 동작(P29): 비로그인 401(화면은 로그인으로), 관리자 가이드 화면·API·검색·이미지는 일반 사용자·그룹 관리자 403(본문·제목·건수 없음),
 * admin 200, 관리자 가이드는 사용자 가이드 검색·결함·규칙 검색에 나오지 않음, 화면 언어로 본문(ko/en), 오류 코드 탭(카탈로그 전체·내부 정보 없음),
 * 이미지(내용 판정 형식·nosniff·ETag·304·경로 조작·없는 파일 404), 도움말 링크·메뉴·바닥글, 원본은 정적 URL로 접근 불가.
 */
final class GuideHttpTest extends TestCase
{
    private App $app;

    public function setUp(): void
    {
        $dir = $this->tempDir('guidehttp');
        $this->app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']]);
        $db = $this->app->db();
        TestDb::user($db, 'admin', 'admin');
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

    public function testAccessControl(): void
    {
        $anon = new TestClient($this->app);
        foreach (['/api/v1/guide/user', '/api/v1/guide/errors', '/api/v1/guide/admin', '/api/v1/guide/user/search'] as $p) {
            $this->assertSame(401, $anon->get($p, ['q' => 'zip'])->status, "비로그인 API {$p}");
        }
        foreach (['/guide', '/guide/user', '/guide/admin', '/guide/errors', '/guide/licenses'] as $p) {
            $r = $anon->get($p);
            $this->assertTrue(in_array($r->status, [302, 303], true) && str_contains((string) $r->header('Location'), '/login'), "비로그인 화면 {$p} → 로그인");
        }
        $this->assertSame(401, $anon->get('/guide/img/user/anything')->status, '비로그인 이미지 401');
        $admin = $this->as('admin');
        $adminTitle = (string) $admin->get('/api/v1/guide/admin')->decoded()['data']['sections'][0]['title'];
        $this->assertTrue($adminTitle !== '');
        foreach (['kim', 'mgr'] as $login) {
            $c = $this->as($login);
            foreach (['/api/v1/guide/admin', '/api/v1/guide/admin/search', '/guide/admin', '/guide/img/admin/overview', '/guide/img/admin/no-such-image'] as $p) {
                $r = $c->get($p, ['q' => '관리자']);
                $this->assertSame(403, $r->status, "{$login} {$p}");
                $this->assertFalse(str_contains($r->body, $adminTitle) || str_contains($r->body, 'overview'), "{$login} {$p}: 제목·key 노출 없음");
            }
            $page = $c->get('/guide')->body;
            $this->assertFalse(str_contains($page, 'data-guide-tab="admin"') || str_contains($page, '/guide/admin'), "{$login}: 관리자 가이드 탭·메뉴·주소 없음");
            $this->assertSame(200, $c->get('/api/v1/guide/user')->status);
        }
        $this->assertSame(200, $admin->get('/guide/admin')->status);
        $this->assertTrue(str_contains($admin->get('/guide')->body, 'data-guide-tab="admin"'), 'admin에게는 관리자 가이드 탭');
        // P29C(의도된 단언 변경): 관리자 가이드 본문 23절 — 첫 절은 overview
        // X14(의도된 단언 변경): 24절, 정확한 목록·순서(install 뒤 deploy-bundle)
        // P30(의도된 단언 변경): 25절 — monitoring 뒤 audit-log
        $adminKeys = array_column($admin->get('/api/v1/guide/admin')->decoded()['data']['sections'], 'key');
        $this->assertSame(['overview', 'install', 'deploy-bundle', 'worker', 'tools-bin', 'accounts', 'groups-access', 'account-csv', 'external-auth', 'auth-ldap', 'auth-oidc', 'auth-saml',
            'settings', 'retention-disk', 'backup-restore', 'repository', 'schedule', 'projects-admin', 'rules-admin', 'notifications-admin', 'monitoring', 'admin-mail', 'audit-log', 'security', 'troubleshooting', 'linux-ops'], $adminKeys);
        $this->assertSame('private, no-cache', $admin->get('/api/v1/guide/user')->header('Cache-Control'));
    }

    public function testSearchSeparationAndLanguage(): void
    {
        $admin = $this->as('admin');
        // 관리자 가이드만의 문장 → 사용자 가이드 검색에는 없음, 관리자 가이드 검색에는 있음
        // P29C(의도된 단언 변경 — P29의 빈 틀 문장 대신 실제 본문의 관리자 전용 어구)
        $adminOnly = '복원 리허설';
        $this->assertSame([], $admin->get('/api/v1/guide/user/search', ['q' => $adminOnly])->decoded()['data']['results']);
        $this->assertSame(['backup-restore', 'notifications-admin'], array_column($admin->get('/api/v1/guide/admin/search', ['q' => $adminOnly])->decoded()['data']['results'], 'key'));
        $this->assertSame([], $admin->get('/api/v1/guide/user/search', ['q' => '마지막 활성 로컬 관리자'])->decoded()['data']['results'], '관리자 가이드 고유 어구 2');
        $this->assertSame(403, $this->as('kim')->get('/api/v1/guide/admin/search', ['q' => $adminOnly])->status, '비관리자 403');
        $both = $admin->get('/api/v1/guide/user/search', ['q' => '관리자'])->decoded()['data']['results'];
        $this->assertFalse(in_array('overview', array_column($both, 'key'), true), '겹치는 낱말에도 관리자 절 없음');
        $this->assertSame([], $admin->get('/api/v1/guide/user/search', ['q' => 'z'])->decoded()['data']['results'], '1자');
        $this->assertSame(400, $admin->request('GET', '/api/v1/guide/user/search', ['q' => ['a']])->status);
        // 결함·규칙 검색에 가이드 본문이 섞이지 않음(다른 데이터)
        $rules = $admin->get('/api/v1/rules', ['q' => $adminOnly]);
        $this->assertFalse(str_contains($rules->body, $adminOnly));
        // 화면 언어: 영어로 바꾸면 영어 본문
        $ko = $admin->get('/api/v1/guide/user')->decoded()['data'];
        $this->assertSame('ko', $ko['lang']);
        $admin->put('/api/v1/users/me/preferences', ['language' => 'en']);
        $en = $admin->get('/api/v1/guide/user')->decoded()['data'];
        $this->assertSame('en', $en['lang']);
        $this->assertSame(array_column($ko['sections'], 'key'), array_column($en['sections'], 'key'));
        $this->assertTrue($ko['sections'][0]['title'] !== $en['sections'][0]['title'], '제목이 언어별');
        $this->assertFalse(str_contains(json_encode($en['sections']), '{{limit:'), '설정 값 자리표시자는 서버가 채움');
    }

    public function testErrorsTabAndLinks(): void
    {
        $c = $this->as('kim');
        $groups = $c->get('/api/v1/guide/errors')->decoded()['data']['groups'];
        $this->assertSame(['upload', 'analysis', 'sso'], array_column($groups, 'kind'));
        $this->assertSame(['upload-errors', 'analysis-failures', 'login-errors'], array_column($groups, 'guide_key'));
        $this->assertSame([11, 27, 7], array_map(static fn (array $g): int => count($g['items']), $groups));
        $json = json_encode($groups, JSON_UNESCAPED_UNICODE);
        foreach (['data/', 'C:\\', 'Exception', 'stack', 'reason'] as $bad) {
            $this->assertFalse(str_contains($json, $bad), "오류 코드 탭에 내부 정보({$bad}) 없음");
        }
        $page = $c->get('/guide/errors')->body;
        $this->assertTrue(str_contains($page, 'data-guide-errors') && str_contains($page, 'aria-current="page"'));
        // 메뉴·바닥글·도움말 링크
        $proj = $c->get('/projects')->body;
        $this->assertTrue(str_contains($proj, 'href="/guide" data-nav="nav.guide"'), '메뉴 가이드');
        $this->assertTrue(str_contains($proj, 'data-footer-guide') && str_contains($proj, 'href="/guide/licenses"'), '바닥글 가이드·라이선스');
        $this->assertTrue(str_contains($proj, 'href="/guide/user#projects" data-guide="projects"'), '프로젝트 목록 도움말 → projects');
        $this->assertTrue(str_contains($c->get('/upload')->body, 'href="/guide/user#upload" data-guide="upload"'));
        $this->assertTrue(str_contains($c->get('/account')->body, 'data-guide="account"'));
        // 라이선스: 글자 그대로(이스케이프)
        $lic = $c->get('/guide/licenses')->body;
        $this->assertTrue(str_contains($lic, 'onelogin/php-saml') && str_contains($lic, 'data-licenses'));
        // X10(의도된 단언 변경 — 위치만 옮김): 바닥은 모든 탭에서 한 줄(버전·라이선스·아이콘 뜻 링크), 아이콘 범례는 사용자 가이드의 "화면 읽는 법" 절에만
        // (서버는 사용자 가이드 화면에만 범례 원본을 넣고 관리자 메뉴 그룹은 뺀다 — 화면이 {{legend:icons}} 자리에 복제), 관리자 가이드·오류 코드·라이선스에는 범례 없음
        $admin = $this->as('admin');
        $userPage = $c->get('/guide/user')->body;
        foreach (['/guide/user' => $userPage, '/guide/licenses' => $lic, '/guide/errors' => $c->get('/guide/errors')->body, '/guide/admin' => $admin->get('/guide/admin')->body] as $p => $html) {
            $this->assertSame(1, substr_count($html, 'class="guide-footer__line"'), "{$p} 한 줄 바닥글");
            $this->assertTrue(str_contains($html, 'data-guide-licenses-link') && str_contains($html, '/guide/user#shortcuts-and-icons" data-guide-legend-link'), "{$p} 라이선스·아이콘 뜻 링크");
            if ($p !== '/guide/user') {
                $this->assertFalse(str_contains($html, 'data-legend-group='), "{$p} 범례 없음");
            }
        }
        $this->assertTrue(str_contains($userPage, 'data-guide-legend-source') && str_contains($userPage, 'data-legend="nav.guide"') && !str_contains($userPage, 'data-legend-group="admin"'), '사용자 쪽 범례에 관리자 메뉴 그룹 없음');
        $icons = json_decode((string) file_get_contents(PJG_ROOT . '/public/assets/icons/icons.json'), true);
        $want = count(array_filter($icons['icons'] ?? $icons['map'] ?? [], static fn (array $i): bool => ($i['group'] ?? '') !== 'admin'));
        $this->assertSame($want, substr_count($userPage, 'data-legend="'), '범례 행 수 = 레지스트리(관리자 그룹 제외)');
        // X12: 가이드 범례는 행마다 아이콘 하나(큰 24px), 카테고리마다 구역·표·caption 하나, 제목은 가이드 소제목(md-heading). 스타일 가이드 범례는 세 크기 그대로
        preg_match_all('#<tr data-legend="[^"]+">\s*<td class="icon-legend__icons">(.*?)</td>#s', $userPage, $cells);
        $this->assertSame($want, count($cells[1]));
        foreach ($cells[1] as $cell) {
            $this->assertSame([1, 1], [substr_count($cell, '<svg'), substr_count($cell, 'width="24"')], '아이콘 하나·큰 크기');
        }
        $groups = substr_count($userPage, 'data-legend-group="');
        $this->assertTrue($groups > 3 && $groups === substr_count($userPage, '<caption class="sr-only">') && $groups === substr_count($userPage, 'class="icon-legend__scroll"')
            && $groups === preg_match_all('#data-legend-group="[^"]+">\s*<h3 class="md-heading" data-toc="skip">#', $userPage), '카테고리마다 제목(X13: 미니 목차 제외 표시)·caption·표 하나');
        $body17 = array_column($c->get('/api/v1/guide/user')->decoded()['data']['sections'], 'body', 'key');
        $this->assertSame(['shortcuts-and-icons'], array_keys(array_filter($body17, static fn (string $b): bool => str_contains($b, '{{legend:icons}}'))), '범례 자리는 17절에만');
    }

    public function testImagesAndStaticAccess(): void
    {
        $c = $this->as('kim');
        foreach (['/guide/img/user/../admin', '/guide/img/user/UPPER', '/guide/img/user/a%00b', '/guide/img/other/x'] as $p) {
            $this->assertTrue(in_array($c->get($p)->status, [404, 405], true), "경로 조작 {$p}");
        }
        $this->assertSame(404, $c->get('/guide/img/user/no-such-image')->status);
        $shots = json_decode((string) file_get_contents(PJG_ROOT . '/resources/guide/screenshots.json'), true);
        $slug = $shots['images'][0]['slug'];
        $r = $c->get('/guide/img/user/' . $slug);
        $this->assertSame([200, 'image/png', 'nosniff'], [$r->status, $r->header('Content-Type'), $r->header('X-Content-Type-Options')]);
        // 주소에 언어가 없으므로 매번 재검증(언어를 바꾼 뒤 이전 언어 그림이 보이지 않게, 브라우저 점검에서 발견)
        $this->assertSame('private, no-cache', $r->header('Cache-Control'));
        $this->assertTrue($r->header('ETag') !== null);
        $this->assertSame(304, $c->request('GET', '/guide/img/user/' . $slug, [], null, null, ['if-none-match' => (string) $r->header('ETag')])->status);
        // 원본은 웹 루트 밖 — 앱 경로로도 정적 경로로도 없음
        foreach (['/resources/guide/admin/ko/00_overview.md', '/guide/admin/ko/00_overview.md', '/resources/guide/user/img/' . $slug . '.ko.png'] as $p) {
            $this->assertSame(404, $c->get($p)->status, $p);
        }
        $this->assertFalse(is_file(PJG_ROOT . '/public/resources/guide/admin/ko/00_overview.md'));
    }
}
