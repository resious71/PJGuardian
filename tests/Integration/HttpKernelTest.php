<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/** 전역 미들웨어를 모두 거치는 요청 처리 시험 */
final class HttpKernelTest extends TestCase
{
    private string $logDir;
    private App $app;

    public function setUp(): void
    {
        $this->logDir = $this->tempDir('kernel');
        $this->app = TestApp::make($this->logDir);
        // 시험용 라우트(예외 발생)
        $this->app->router->get('/api/v1/test/boom', static function (): Response {
            throw new \RuntimeException('비밀 내부 정보 C:\\secret\\path.php password=hunter2');
        });
        $this->app->router->get('/test/html-boom', static function (): Response {
            throw new \RuntimeException('html boom');
        });
    }

    private function logText(): string
    {
        $text = '';
        foreach (glob($this->logDir . '/app-*.log') ?: [] as $f) {
            $text .= file_get_contents($f);
        }
        return $text;
    }

    public function testVersionEndpoint(): void
    {
        $res = $this->app->handle(TestApp::request('GET', '/api/v1/meta/version'));
        $this->assertSame(200, $res->status);
        $this->assertSame(['data' => ['app' => 'PJGuardian', 'version' => App::VERSION]], $res->decoded());
        $this->assertSame('application/json; charset=utf-8', $res->header('Content-Type'));
        $this->assertSame(1, preg_match('/^[0-9a-f]{16}$/', (string) $res->header('X-Request-Id')), 'X-Request-Id 헤더');
    }

    public function testUnknownApiPathIsJson404(): void
    {
        $res = $this->app->handle(TestApp::request('GET', '/api/v1/nope'));
        $this->assertSame(404, $res->status);
        $body = $res->decoded();
        $this->assertSame('not_found', $body['error']['code']);
        $this->assertSame('요청한 경로를 찾을 수 없습니다.', $body['error']['message']);
        $this->assertSame($res->header('X-Request-Id'), $body['error']['request_id'], '오류 본문의 request_id = 헤더 값');
    }

    public function testMethodNotAllowedIsJson405WithAllow(): void
    {
        // P10부터 상태 변경 요청은 라우팅 전에 CSRF를 검사한다(예외 경로 없음). 토큰을 실어 405 처리를 시험한다.
        $this->assertSame(403, $this->app->handle(TestApp::request('POST', '/api/v1/meta/version'))->status, '토큰 없으면 CSRF가 먼저');
        $client = new \PJGuardian\Tests\Support\TestClient($this->app);
        $client->fetchCsrf();
        $res = $client->post('/api/v1/meta/version');
        $this->assertSame(405, $res->status);
        $this->assertSame('method_not_allowed', $res->decoded()['error']['code']);
        $this->assertSame('GET, HEAD', $res->header('Allow'));
    }

    public function testUnexpectedExceptionIsGeneric500WithoutLeak(): void
    {
        $res = $this->app->handle(TestApp::request('GET', '/api/v1/test/boom'));
        $this->assertSame(500, $res->status);
        $body = $res->decoded();
        $this->assertSame('internal', $body['error']['code']);
        foreach (['비밀 내부 정보', 'secret', 'path.php', 'RuntimeException', 'HttpKernelTest', '#0', 'hunter2'] as $leak) {
            $this->assertNotContains($leak, $res->body, '응답에 내부 정보 노출');
        }
        $log = $this->logText();
        $this->assertContains('처리되지 않은 예외', $log);
        $this->assertContains('RuntimeException', $log, '상세는 로그에 남김');
        $this->assertContains($body['error']['request_id'], $log, '로그와 응답을 요청 ID로 연결');
        $this->assertNotContains('hunter2', $log, '로그에서도 비밀번호는 마스킹');
    }

    public function testHtmlErrorPageForNonApiPaths(): void
    {
        $res = $this->app->handle(TestApp::request('GET', '/no-such-page'));
        $this->assertSame(404, $res->status);
        $this->assertSame('text/html; charset=utf-8', $res->header('Content-Type'));
        $this->assertContains('페이지를 찾을 수 없습니다', $res->body);
        $res = $this->app->handle(TestApp::request('GET', '/test/html-boom'));
        $this->assertSame(500, $res->status);
        $this->assertNotContains('html boom', $res->body);
    }

    public function testSecurityHeadersOnEveryResponse(): void
    {
        $nonces = [];
        foreach (['/api/v1/meta/version', '/api/v1/nope', '/api/v1/test/boom', '/no-such-page'] as $path) {
            $res = $this->app->handle(TestApp::request('GET', $path));
            $csp = (string) $res->header('Content-Security-Policy');
            // P17: style-src에만 요청마다 다른 nonce(CodeMirror의 <style>). 나머지는 P07 정책 그대로
            $this->assertSame(1, preg_match("/style-src 'self' 'nonce-([A-Za-z0-9_-]{22})';/", $csp, $m), $path . ' ' . $csp);
            $nonces[] = $m[1];
            $this->assertSame(\PJGuardian\Http\Middleware\SecurityHeadersMiddleware::csp($m[1]), $csp, $path);
            $this->assertContains("script-src 'self';", $csp);
            $this->assertNotContains('unsafe-inline', $csp);
            $this->assertNotContains('unsafe-eval', $csp);
            $this->assertSame('DENY', $res->header('X-Frame-Options'), $path);
            $this->assertSame('nosniff', $res->header('X-Content-Type-Options'), $path);
            $this->assertSame('same-origin', $res->header('Referrer-Policy'), $path);
            $this->assertSame('max-age=31536000', $res->header('Strict-Transport-Security'), $path);
        }
        $this->assertSame(count($nonces), count(array_unique($nonces)), '요청마다 새 nonce');
    }

    public function testHttpGetRedirectsToConfiguredHttpsBase(): void
    {
        $req = new Request('GET', '/api/v1/meta/version', ['a' => '1'], ['host' => 'evil.example'], '', false, '127.0.0.1');
        $res = $this->app->handle($req);
        $this->assertSame(301, $res->status);
        $this->assertSame('https://pjguardian.local/api/v1/meta/version?a=1', $res->header('Location'), 'Host 헤더가 아니라 app.base_url로');
        $this->assertSame(null, $res->header('Strict-Transport-Security'), 'HSTS는 HTTPS 응답에만');
    }

    public function testHttpPostIsRefusedNotRedirected(): void
    {
        $res = $this->app->handle(new Request('POST', '/api/v1/meta/version', [], [], '{}', false, '127.0.0.1'));
        $this->assertSame(403, $res->status);
        $this->assertSame('https_required', $res->decoded()['error']['code']);
    }

    public function testHttpsCanBeDisabledForDevelopment(): void
    {
        $app = TestApp::make($this->tempDir('kernel'), [
            'security' => ['require_https' => 'false'],
            'app' => ['base_url' => 'http://localhost/JGuardian/public'],
        ]);
        $res = $app->handle(new Request('GET', '/api/v1/meta/version', [], [], '', false, '127.0.0.1'));
        $this->assertSame(200, $res->status);
    }

    public function testEachRequestGetsNewRequestIdInLogs(): void
    {
        $a = $this->app->handle(TestApp::request('GET', '/api/v1/test/boom'))->header('X-Request-Id');
        $b = $this->app->handle(TestApp::request('GET', '/api/v1/test/boom'))->header('X-Request-Id');
        $this->assertTrue($a !== $b);
        $log = $this->logText();
        $this->assertContains('"request_id":"' . $a . '"', $log);
        $this->assertContains('"request_id":"' . $b . '"', $log);
    }
}
