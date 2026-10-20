<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/**
 * 청크 업로드 API(명세 5.4, 19.10). 로그인 필수, 상태를 바꾸는 요청은 CSRF(전역 미들웨어).
 * 프로젝트 접근은 라우트의 RequireProjectAccess가 먼저 판정하고(init = 프로젝트 id, 나머지 = upload_id → 소유 프로젝트),
 * 업로드 소유자 확인은 UploadService가 한다(다른 사용자 404). 요청마다 세션 활동이 갱신된다(SessionMiddleware → SessionService::touch).
 */
final class UploadController
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    private static function user(Request $request): array
    {
        return $request->attribute('user');
    }

    /** POST /api/v1/projects/{id}/uploads {file_name, total_size} → 201 */
    public function init(Request $request): Response
    {
        return Response::data($this->app->uploads()->init(self::user($request), (int) $request->attribute('project_id'), $request->json()), 201);
    }

    /** PUT /api/v1/uploads/{id}/chunks/{index}: 본문 = 청크 그대로, 헤더 X-Chunk-Sha256 */
    public function chunk(Request $request): Response
    {
        $len = $request->header('content-length');
        return Response::data($this->app->uploads()->chunk(
            self::user($request),
            (string) $request->param('id'),
            $request->param('index'),
            $request->header('x-chunk-sha256'),
            $request->bodyStream(),
            $len !== null && ctype_digit($len) ? (int) $len : null,
        ));
    }

    /** GET /api/v1/uploads/{id}/status: 받은 청크와 해시(이어 올리기) */
    public function status(Request $request): Response
    {
        return Response::data($this->app->uploads()->status(self::user($request), (string) $request->param('id')));
    }

    /** POST /api/v1/uploads/{id}/complete → 202 조립 대기 */
    public function complete(Request $request): Response
    {
        return Response::data($this->app->uploads()->complete(self::user($request), (string) $request->param('id')), 202);
    }

    /** POST /api/v1/uploads/{id}/confirm: 경고 확인 → 분석 대기열(업로드한 사용자 본인만, P13) */
    public function confirm(Request $request): Response
    {
        $row = $this->app->uploads()->owned(self::user($request), (string) $request->param('id'));
        return Response::data($this->app->uploadProcessor()->confirm($row));
    }

    /** DELETE /api/v1/uploads/{id}: 취소(조각 삭제) */
    public function abort(Request $request): Response
    {
        return Response::data($this->app->uploads()->abort(self::user($request), (string) $request->param('id')));
    }

    /** GET /api/v1/uploads/{id}: 상태(화면이 폴링) */
    public function show(Request $request): Response
    {
        return Response::data($this->app->uploads()->get(self::user($request), (string) $request->param('id')));
    }
}
