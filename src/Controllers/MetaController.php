<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;

/** 앱 정보 API. 인증이 필요 없는 공개 정보만 둔다. */
final class MetaController
{
    /** GET /api/v1/meta/version */
    public function version(Request $request): Response
    {
        return Response::data(['app' => App::NAME, 'version' => App::VERSION]);
    }
}
