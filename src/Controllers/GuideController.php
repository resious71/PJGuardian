<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Guide\GuideContent;
use PJGuardian\Services\Guide\GuideTabs;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Lang;

/**
 * 가이드(P29, 명세 16장). 로그인한 사용자만(라우트의 RequireLogin). 관리자 가이드는 admin만 — 화면·API는 RequireAdmin(403, 본문·제목·건수 없음),
 * 이미지는 이 컨트롤러가 직접 판정(비로그인 401, 비관리자 403 — 파일이 있는지 보기 전에).
 *   화면  GET /guide(= 사용자 가이드), /guide/user, /guide/admin, /guide/errors, /guide/licenses
 *   API   GET /api/v1/guide/user, /user/search?q=, /errors, /admin, /admin/search?q=   (읽기 전용, Cache-Control: private)
 *   이미지 GET /guide/img/{user|admin}/{slug}   (내용으로 판정한 PNG·WebP·JPEG만, nosniff, ETag)
 * 본문 언어는 화면 언어(P27D — Lang::locale, 계정 > 쿠키 > Accept-Language). 렌더는 브라우저의 안전한 마크다운 렌더러(textContent만).
 */
final class GuideController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function isAdmin(Request $request): bool
    {
        return ($request->attribute('user')['role'] ?? '') === 'admin';
    }

    // ---- 화면 ----

    public function userPage(Request $request): Response
    {
        return $this->page($request, 'user');
    }

    public function adminPage(Request $request): Response
    {
        return $this->page($request, 'admin');
    }

    public function errorsPage(Request $request): Response
    {
        return $this->page($request, 'errors');
    }

    /** P29B FAQ 탭: 목록·검색은 화면 스크립트가 /api/v1/faq로. 편집 요소는 admin에게만 그린다(서버도 쓰기 API를 다시 검사) */
    public function faqPage(Request $request): Response
    {
        return $this->page($request, 'faq', ['faqReady' => \PJGuardian\Support\SchemaFeatures::faq($this->app->db())], ['js/pages/faq.js']);
    }

    public function licensesPage(Request $request): Response
    {
        $text = (string) @file_get_contents($this->app->root . '/THIRD_PARTY_NOTICES');
        return $this->page($request, 'licenses', ['licenses' => $text]);
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>|null $scripts 탭 화면 스크립트(기본 가이드 스크립트)
     */
    private function page(Request $request, string $tab, array $extra = [], ?array $scripts = null): Response
    {
        $isAdmin = self::isAdmin($request);
        $html = $this->app->view($request)->page('guide/index', [
            'title' => Lang::get('guide.title'), 'user' => $request->attribute('user'), 'active' => $tab === 'admin' ? 'admin.guide' : 'nav.guide',
            'scripts' => $scripts ?? ['js/pages/guide.js'], 'body_class' => 'page-guide',
        ], $extra + [
            'tab' => $tab, 'tabs' => GuideTabs::visible($isAdmin), 'isAdmin' => $isAdmin,
            'versions' => ['app' => App::VERSION, 'ruff' => $this->content()->version('ruff'), 'eslint' => $this->content()->version('eslint')],
        ]);
        return Response::html($html)->withHeader('Cache-Control', 'private, no-cache');
    }

    // ---- API ----

    public function apiUser(Request $request): Response
    {
        return $this->apiSections('user');
    }

    public function apiAdmin(Request $request): Response
    {
        return $this->apiSections('admin');
    }

    public function apiUserSearch(Request $request): Response
    {
        return $this->apiSearch($request, 'user');
    }

    public function apiAdminSearch(Request $request): Response
    {
        return $this->apiSearch($request, 'admin');
    }

    public function apiErrors(Request $request): Response
    {
        return self::private(Response::data(['groups' => ErrorCatalog::all()]));
    }

    private function apiSections(string $audience): Response
    {
        $lang = Lang::locale();
        $sections = $this->content()->rendered($audience, $lang);
        $images = [];
        foreach ($sections as $s) {
            preg_match_all('/!\[[^\]]*\]\(guide-img:([a-z0-9-]+)\)/', $s['body'], $m);
            foreach ($m[1] as $slug) {
                $path = $this->content()->imagePath($audience, $slug, $lang);
                $size = $path === null ? false : @getimagesize($path);
                if (is_array($size)) {
                    $images[$slug] = ['width' => $size[0], 'height' => $size[1]];
                }
            }
        }
        return self::private(Response::data(['audience' => $audience, 'lang' => $lang, 'sections' => $sections, 'images' => $images]));
    }

    private function apiSearch(Request $request, string $audience): Response
    {
        $q = $request->query['q'] ?? '';
        if (!is_string($q)) {
            throw new ApiException(400, 'invalid_input');
        }
        return self::private(Response::data(['q' => $q, 'results' => $this->content()->search($audience, Lang::locale(), $q)]));
    }

    private static function private(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'private, no-cache');
    }

    // ---- 이미지 ----

    public function image(Request $request): Response
    {
        $user = $request->attribute('user');
        if ($user === null) {
            return new Response(401, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Unauthorized');
        }
        $audience = (string) $request->param('audience');
        if ($audience === 'admin' && ($user['role'] ?? '') !== 'admin') {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Forbidden'); // 있는지도 알리지 않는다
        }
        $slug = (string) $request->param('slug');
        $path = $this->content()->imagePath($audience, $slug, Lang::locale());
        $type = $path === null ? null : GuideContent::imageType($path);
        if ($type === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], 'Not Found');
        }
        $etag = '"' . substr(hash('sha256', basename((string) $path) . ':' . filemtime((string) $path) . ':' . filesize((string) $path)), 0, 32) . '"';
        // 주소에 언어가 없으므로(화면 언어로 고름) 매번 재검증 — 언어를 바꾼 뒤 이전 언어 그림이 캐시에서 보이지 않게(ETag가 언어별 파일로 달라 304는 그대로)
        $headers = ['Content-Type' => $type, 'Cache-Control' => 'private, no-cache', 'ETag' => $etag, 'X-Content-Type-Options' => 'nosniff'];
        if (($request->header('if-none-match') ?? '') === $etag) {
            return new Response(304, $headers, '');
        }
        return new Response(200, $headers, (string) file_get_contents((string) $path));
    }

    private function content(): GuideContent
    {
        return $this->app->guideContent();
    }
}
