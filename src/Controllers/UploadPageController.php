<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\Lang;

/**
 * 업로드 화면(명세 5.4, 19.10). 프로젝트 선택 목록에는 **볼 수 있는 프로젝트만** 담는다(ProjectAccess::scopeFor).
 * 업로드 자체는 pages/upload.js가 API로 한다. ?project=<id>로 미리 고를 수 있다(보이지 않는 id면 무시).
 */
final class UploadPageController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /upload */
    public function show(Request $request): Response
    {
        $user = $request->attribute('user');
        [$projects] = $this->app->projects()->list(null, 200, 0, $user);
        $want = is_string($request->query['project'] ?? null) && ctype_digit($request->query['project']) ? (int) $request->query['project'] : 0;
        $selected = in_array($want, array_map(static fn (array $p): int => (int) $p['id'], $projects), true) ? $want : 0;
        $settings = $this->app->uploadSettings();
        $html = $this->app->view($request)->page('upload', [
            'title' => Lang::get('upload.title'),
            'user' => $user,
            'active' => 'nav.projects',
            'scripts' => ['js/pages/upload.js'],
        ], [
            'projects' => $projects,
            'selected' => $selected,
            'isAdmin' => ($user['role'] ?? '') === 'admin',
            'maxBytes' => $settings->maxBytes,
            'userId' => (int) $user['id'],
        ]);
        return Response::html($html);
    }
}
