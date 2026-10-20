<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 보안 헤더 일괄 점검(P31 A-2): 응답 유형마다(HTML 화면·JSON API·API 오류·화면 오류(403·404)·내려받기·버전 경로 정적 파일·/health·로그인 화면·리다이렉트)
 * CSP(script-src 'self', unsafe-inline 없음, frame-ancestors 'none'), X-Frame-Options DENY, X-Content-Type-Options nosniff,
 * Referrer-Policy, Permissions-Policy가 모두 있고, 캐시 제어는 API·오류 no-store·화면 private(공유 캐시 금지), HSTS는 HTTPS 응답에 한 번만(HTTP에는 없음).
 * HSTS는 앱 한 곳에서만 보낸다(Apache vhost에 넣지 않음 — docs/xampp_vhost.md, docs/hardening.md).
 */
final class SecurityHeadersTest extends TestCase
{
    private const COMMON = ['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy'];

    private function assertSecure(Response $r, string $what, bool $noStore = true, bool $https = true): void
    {
        foreach (self::COMMON as $h) {
            $this->assertTrue($r->header($h) !== null && $r->header($h) !== '', "{$what}: {$h} 없음");
        }
        $csp = (string) $r->header('Content-Security-Policy');
        $this->assertTrue(str_contains($csp, "script-src 'self';") && !str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'unsafe-eval')
            && str_contains($csp, "frame-ancestors 'none'") && str_contains($csp, "object-src 'none'"), "{$what}: CSP {$csp}");
        $this->assertSame('DENY', $r->header('X-Frame-Options'), $what);
        $this->assertSame('nosniff', $r->header('X-Content-Type-Options'), $what);
        $this->assertSame('same-origin', $r->header('Referrer-Policy'), $what);
        $this->assertTrue(str_contains((string) $r->header('Permissions-Policy'), 'camera=()'), "{$what}: Permissions-Policy");
        if ($noStore) {
            // 공유 캐시(프록시)에 남지 않게: API·오류는 no-store, 화면은 private(뒤로 가기 복원은 브라우저에 맡김)
            $cc = (string) $r->header('Cache-Control');
            $this->assertTrue(str_contains($cc, 'no-store') || str_contains($cc, 'private'), "{$what}: Cache-Control {$cc}");
        }
        $hsts = $r->header('Strict-Transport-Security');
        if ($https) {
            $this->assertSame('max-age=31536000', $hsts, "{$what}: HSTS 한 번(값 하나)");
        } else {
            $this->assertSame(null, $hsts, "{$what}: HTTP 응답에는 HSTS 없음");
        }
    }

    public function testEveryResponseKindCarriesTheSameHeaders(): void
    {
        $dir = $this->tempDir('sech');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'health' => ['allowed_ips' => '127.0.0.1']]);
        TestDb::user($app->db(), 'admin', 'admin');
        TestDb::user($app->db(), 'kim', 'user');
        $anon = new TestClient($app);
        $this->assertSecure($anon->get('/login'), '로그인 화면');
        $this->assertSecure($anon->get('/dashboard'), '로그인 리다이렉트(302)');
        $this->assertSecure($anon->get('/api/v1/users/me'), 'API 401');
        $this->assertSecure($anon->get('/api/v1/nope'), 'API 404');
        $this->assertSecure($anon->get('/health'), '/health');
        $this->assertSecure($anon->get('/api/v1/meta/version'), 'API 공개');

        $kim = new TestClient($app);
        $kim->login('kim', 'Passw0rd!x');
        $this->assertSecure($kim->get('/dashboard'), 'HTML 화면');
        $this->assertSecure($kim->get('/admin/users'), '화면 403');
        $this->assertSecure($kim->get('/no-such-page'), '화면 404');
        $this->assertSecure($kim->get('/api/v1/admin/monitoring'), 'API 403');
        $this->assertSecure($kim->post('/api/v1/projects/999999/defects/export', ['format' => 'csv']), 'API 404(내려받기 경로)');

        $admin = new TestClient($app);
        $admin->login('admin', 'Passw0rd!x');
        $this->assertSame(201, $admin->post('/api/v1/projects', ['name' => 'p1', 'language' => 'python'])->status); // 감사 한 줄
        $csv = $admin->post('/api/v1/admin/audit-logs/export', ['format' => 'csv']);
        $this->assertSame(200, $csv->status, $csv->body);
        $this->assertSecure($csv, '내려받기(CSV)');
        $this->assertTrue(str_contains((string) $csv->header('Content-Disposition'), 'attachment'), '내려받기는 attachment');

        // 버전 경로 정적 파일(앱이 직접 응답) — 캐시는 길게(불변), 보안 헤더는 같음
        $hash = $app->assetVersion()->hash();
        $static = $app->handle(new Request('GET', "/assets/v/{$hash}/css/tokens.css", [], ['user-agent' => 't'], '', true, '127.0.0.1'));
        $this->assertSame(200, $static->status);
        $this->assertSecure($static, '정적 파일', false);

        // HTTP(개발) 응답에는 HSTS 없음
        $plain = $app->handle(new Request('GET', '/api/v1/meta/version', [], ['user-agent' => 't'], '', false, '127.0.0.1'));
        $this->assertTrue(in_array($plain->status, [200, 301, 302, 308], true));
        $this->assertSecure($plain, 'HTTP 응답', false, false);
    }
}
