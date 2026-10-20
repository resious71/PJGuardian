<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Faq\FaqService;
use PJGuardian\Support\SchemaFeatures;

/**
 * FAQ API(P29B). 읽기는 로그인한 모든 사용자($auth — 비공개 항목은 admin만), 쓰기는 /api/v1/admin/faq…($admin — 그룹 관리자·일반 사용자 403, CSRF는 미들웨어).
 *   GET  /api/v1/faq?q=&category=&include_drafts=1     목록(분류 순서·분류 안 순서, 답변 원문 포함 — 항목 500개 상한)
 *   GET  /api/v1/faq/{id}                              한 항목(일반 사용자에게 비공개는 404)
 *   POST /api/v1/admin/faq                             추가 {category, question, answer, is_published?}
 *   PATCH /api/v1/admin/faq/{id}                       수정 {version, …} — version이 다르면 409 faq_conflict + details.current
 *   DELETE /api/v1/admin/faq/{id}                      삭제
 *   POST /api/v1/admin/faq/reorder                     같은 분류 안 순서 {category, ids:[…전체]} — 다르면 409 faq_order_conflict
 * 029 전(표 없음)에는 모두 503 faq_unavailable(화면은 안내).
 */
final class FaqController
{
    public function __construct(private readonly App $app)
    {
    }

    private function service(): FaqService
    {
        if (!SchemaFeatures::faq($this->app->db())) {
            throw new ApiException(503, 'faq_unavailable');
        }
        return $this->app->faq();
    }

    private static function isAdmin(Request $request): bool
    {
        return ($request->attribute('user')['role'] ?? '') === 'admin';
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $b = $request->json();
        if (array_is_list($b) && $b !== []) {
            throw new ApiException(400, 'invalid_faq', ['field' => 'body'], null, [], [], ['field' => 'body']);
        }
        return $b;
    }

    public function list(Request $request): Response
    {
        $q = $request->query['q'] ?? null;
        $category = $request->query['category'] ?? null;
        if (($q !== null && !is_string($q)) || ($category !== null && !is_string($category))) {
            throw new ApiException(400, 'invalid_faq', ['field' => 'query'], null, [], [], ['field' => 'query']);
        }
        $isAdmin = self::isAdmin($request);
        $flag = $request->query['include_drafts'] ?? '1';
        $drafts = $isAdmin && (!is_string($flag) || $flag !== '0');
        return Response::data($this->service()->list($isAdmin, (string) $q, $category, $drafts))->withHeader('Cache-Control', 'private, no-cache');
    }

    public function show(Request $request): Response
    {
        return Response::data($this->service()->get((int) $request->param('id'), self::isAdmin($request)))->withHeader('Cache-Control', 'private, no-cache');
    }

    public function create(Request $request): Response
    {
        return Response::data($this->service()->create(self::actor($request), self::body($request)), 201);
    }

    public function update(Request $request): Response
    {
        return Response::data($this->service()->update(self::actor($request), (int) $request->param('id'), self::body($request)));
    }

    public function delete(Request $request): Response
    {
        $this->service()->delete(self::actor($request), (int) $request->param('id'));
        return Response::data(['deleted' => true]);
    }

    public function reorder(Request $request): Response
    {
        $b = self::body($request);
        if (!is_string($b['category'] ?? null) || !is_array($b['ids'] ?? null) || !array_is_list($b['ids'])) {
            throw new ApiException(400, 'invalid_faq', ['field' => 'ids'], null, [], [], ['field' => 'ids']);
        }
        return Response::data(['items' => $this->service()->reorder(self::actor($request), $b['category'], $b['ids'])]);
    }
}
