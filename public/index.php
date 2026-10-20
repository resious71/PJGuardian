<?php

declare(strict_types=1);

// PJGuardian 프런트 컨트롤러. 웹 요청은 모두 여기로 온다(public/.htaccess 또는 vhost의 FallbackResource).

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Support\ConfigException;
use PJGuardian\Support\Lang;

try {
    $app = App::create(PJG_ROOT);
} catch (ConfigException $e) {
    // 설정 오류: 원인은 서버 로그에만 남기고(값은 적지 않음) 화면에는 일반 메시지만 보낸다.
    error_log('[PJGuardian] ' . $e->getMessage());
    $request = Request::fromGlobals();
    $response = $request->isApi()
        ? Response::error(503, 'config_invalid', Lang::get('error.config_invalid'), null)
        : Response::html('<!doctype html><meta charset="utf-8"><p>' . htmlspecialchars(Lang::get('error.config_invalid'), ENT_QUOTES, 'UTF-8') . '</p>', 503);
    $response->send();
    exit;
}

$request = Request::fromGlobals();
$app->handle($request)->send($request->method !== 'HEAD');
