<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\AssetVersion;
use PJGuardian\Support\Fs;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 정적 파일 버전(P21 0절): 묶음 버전 = assets 내용 해시, 경로 버전 /assets/v/<해시>/…, 캐시 헤더, 경로 이탈 차단, 템플릿 정적 검사.
 */
final class StaticAssetsTest extends TestCase
{
    public function testVersionFollowsContentNotMtime(): void
    {
        $dir = $this->tempDir('assetver');
        Fs::ensureDir($dir . '/a/js');
        file_put_contents($dir . '/a/js/x.js', 'export const a = 1;');
        file_put_contents($dir . '/a/app.css', 'body{}');
        $v = fn (): string => (new AssetVersion($dir . '/a', $dir . '/cache.json'))->hash();
        $h1 = $v();
        $this->assertSame(1, preg_match('/^[0-9a-f]{12}$/', $h1));
        $this->assertSame($h1, $v(), '파일이 그대로면 같은 값(캐시 사용)');
        touch($dir . '/a/js/x.js', time() + 100);
        clearstatcache();
        $this->assertSame($h1, $v(), '수정 시각만 바뀌고 내용이 같으면 같은 값');
        file_put_contents($dir . '/a/js/x.js', 'export const a = 2;');
        clearstatcache();
        $h2 = $v();
        $this->assertTrue($h2 !== $h1, '내용이 바뀌면 다른 값');
        file_put_contents($dir . '/a/js/new.js', '');
        clearstatcache();
        $this->assertTrue($v() !== $h2, '파일이 늘어도 다른 값');
    }

    private function app(): App
    {
        $app = TestApp::make($this->tempDir('staticassets'));
        TestDb::user($app->db(), 'kim', 'user');
        return $app;
    }

    public function testServesVersionedFilesWithCacheHeaders(): void
    {
        $app = $this->app();
        $h = $app->assetVersion()->hash();
        $c = new TestClient($app);
        $r = $c->get("/assets/v/{$h}/js/core/i18n.js");
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=31536000, immutable', $r->headers()['Cache-Control'] ?? null);
        $this->assertSame('text/javascript; charset=utf-8', $r->headers()['Content-Type'] ?? null);
        $this->assertSame((string) file_get_contents(PJG_ROOT . '/public/assets/js/core/i18n.js'), $r->body);
        $this->assertTrue(isset($r->headers()['Content-Security-Policy']) && ($r->headers()['X-Content-Type-Options'] ?? '') === 'nosniff', '보안 헤더');
        $this->assertSame('application/json; charset=utf-8', $c->get("/assets/v/{$h}/icons/icons.json")->headers()['Content-Type'] ?? null, 'JSON 모듈');
        // 같은 ETag면 304
        $etag = $r->headers()['ETag'];
        $this->assertSame(304, $c->request('GET', "/assets/v/{$h}/js/core/i18n.js", [], null, null, ['if-none-match' => $etag])->status);
        // 옛 해시(배포 전 HTML): 지금 파일을 주되 오래 캐시하지 않는다
        $old = $c->get('/assets/v/000000000000/js/core/i18n.js');
        $this->assertSame([200, 'no-cache'], [$old->status, $old->headers()['Cache-Control'] ?? null]);
    }

    public function testRejectsMissingTraversalAndUnknownTypes(): void
    {
        $c = new TestClient($this->app());
        $h = '0123456789ab';
        foreach (["/assets/v/{$h}/js/none.js", "/assets/v/{$h}/../../src/App.php", "/assets/v/{$h}/js/../../../config/config.ini.example",
            "/assets/v/{$h}/js//core/i18n.js", "/assets/v/{$h}/./js/core/i18n.js", '/assets/v/xyz/js/core/i18n.js', "/assets/v/{$h}/index.php",
            "/assets/v/{$h}/js/core/i18n.js%00.css", "/assets/v/{$h}/%2e%2e/%2e%2e/src/App.php"] as $path) {
            $r = $c->get($path);
            $this->assertSame([404, 'no-store'], [$r->status, $r->headers()['Cache-Control'] ?? null], $path);
            $this->assertFalse(str_contains($r->body, '<?php'), $path);
        }
        $this->assertSame(404, $c->request('POST', "/assets/v/{$h}/js/core/i18n.js", [], [])->status, '읽기만');
    }

    public function testPagesUseVersionedUrlsAndNoCache(): void
    {
        $app = $this->app();
        $c = new TestClient($app);
        $html = $c->get('/login')->body;
        $h = $app->assetVersion()->hash();
        preg_match_all('#(?:src|href)="([^"]*/assets/[^"]*)"#', $html, $m);
        $this->assertTrue(count($m[1]) >= 5, '정적 파일 주소 ' . count($m[1]));
        foreach ($m[1] as $url) {
            $this->assertTrue(str_starts_with($url, "/assets/v/{$h}/"), "버전 경로: {$url}");
        }
        $this->assertSame('no-store', $c->get('/login')->headers()['Cache-Control'] ?? null, '로그인 화면은 기존대로 no-store');
        $this->assertTrue($c->login('kim', 'Passw0rd!x')->status === 200);
        // P31: 화면 HTML은 매번 재검증(no-cache) + 공유 캐시(프록시) 금지(private) — 의도된 단언 변경(더 엄격)
        $this->assertSame('private, no-cache', $c->get('/projects')->headers()['Cache-Control'] ?? null, '화면 HTML은 private, no-cache');
        $this->assertSame('no-store', $c->get('/api/v1/projects')->headers()['Cache-Control'] ?? null, 'API는 no-store');
    }

    public function testTemplatesBuildAssetUrlsOnlyThroughHelper(): void
    {
        // 템플릿에 정적 파일 주소를 손으로 쓴 곳이 없어야 한다(Assets::url만)
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PJG_ROOT . '/src/Views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match('#(?:src|href)="[^"]*/?assets/#', $src) === 1 || preg_match('#\.(?:js|css)\?v=#', $src) === 1) {
                $bad[] = $f->getFilename();
            }
        }
        $this->assertSame([], $bad);
    }
}
