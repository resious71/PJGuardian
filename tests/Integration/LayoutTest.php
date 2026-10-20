<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/**
 * 공통 레이아웃과 개발 전용 스타일 가이드(P09).
 * - 관리자 메뉴는 role=admin일 때만 HTML에 존재한다(명세 9장 · 20.3 관리자 메뉴).
 * - /dev/styleguide는 app.env=dev에서만 열리고, 운영(prod)에서는 404.
 */
final class LayoutTest extends TestCase
{
    private function get(string $env, string $query = ''): \PJGuardian\Http\Response
    {
        $app = TestApp::make($this->tempDir('layout'), ['app' => ['env' => $env]]);
        parse_str($query, $q);
        return $app->handle(TestApp::request('GET', '/dev/styleguide', $q));
    }

    public function testStyleguideIsHiddenInProd(): void
    {
        $res = $this->get('prod');
        $this->assertSame(404, $res->status);
        $this->assertNotContains('styleguide', strtolower($res->body), '운영에서는 존재를 드러내지 않음');
    }

    public function testAdminSeesAdminMenu(): void
    {
        $res = $this->get('dev', 'as=admin');
        $this->assertSame(200, $res->status);
        $this->assertContains('text/html', (string) $res->header('Content-Type'));
        $this->assertContains('data-nav="admin.users"', $res->body);
        $this->assertContains('사용자 관리', $res->body);
        $this->assertContains('topbar__nav--admin', $res->body);
    }

    public function testUserDoesNotGetAdminMenuInHtml(): void
    {
        $res = $this->get('dev', 'as=user');
        $this->assertSame(200, $res->status);
        $this->assertContains('data-nav="nav.dashboard"', $res->body, '주 메뉴는 있음');
        $this->assertNotContains('data-nav="admin.', $res->body, '관리자 메뉴 항목이 HTML에 없음(숨김이 아니라 미출력)');
        $this->assertNotContains('topbar__nav--admin', $res->body);
        // 아이콘 범례 표에는 관리자 아이콘이 있으므로 '사용자 관리' 문자열 대신 메뉴 표지로 확인한다.
    }

    public function testGuestLayoutHasNoMenus(): void
    {
        $res = $this->get('dev', 'as=guest');
        $this->assertSame(200, $res->status);
        $this->assertNotContains('data-nav=', $res->body);
    }

    public function testPageUsesSpriteAndNoInlineScriptOrStyle(): void
    {
        $body = $this->get('dev')->body;
        $this->assertContains('<meta name="csrf-token"', $body);
        $this->assertSame(1, preg_match('#<use href="/assets/v/[0-9a-f]{12}/icons/sprite\.svg\#i-#', $body), '스프라이트 참조 + 캐시 무효화 해시(P21: 경로 버전)');
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $body), 'CSP: 인라인 스크립트 없음');
        $this->assertSame(0, preg_match('/<style\b|\sstyle="/', $body), 'CSP: 인라인 스타일 없음');
        $this->assertSame(0, preg_match('/<path\b|<circle\b|<rect\b/', $body), 'SVG 도형을 직접 쓰지 않음');
    }

    public function testIconOnlyButtonsHaveAccessibleName(): void
    {
        $body = $this->get('dev')->body;
        preg_match_all('#<button\b[^>]*class="[^"]*btn--icon[^"]*"[^>]*>#', $body, $m);
        $this->assertTrue(count($m[0]) > 0, '아이콘 버튼 시연이 있음');
        foreach ($m[0] as $tag) {
            $this->assertSame(1, preg_match('/aria-label="[^"]+"/', $tag), '아이콘 전용 버튼에 aria-label: ' . $tag);
        }
    }
}
