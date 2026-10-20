<?php
// 가이드 캡처용 점검 라우터(P29, 개발 전용): PHP 내장 서버가 public/ 정적 파일은 그대로, 나머지는 앱으로. 설정은 PJG_ISO_INI(가짜 데이터 설치본).
//   PJG_ISO_INI=<설치본>/config.ini php -S 127.0.0.1:<포트> -t public tools/guide_demo/router.php
$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = dirname(__DIR__, 2) . '/public';
if ($uri !== '/' && !str_contains($uri, '..') && is_file($public . $uri) && !str_ends_with($uri, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$ini = (string) getenv('PJG_ISO_INI');
$app = PJGuardian\App::create(PJG_ROOT, PJGuardian\Support\Config::load($ini, dirname($ini) . '/secrets.ini', getenv()));
$request = PJGuardian\Http\Request::fromGlobals();
$app->handle($request)->send($request->method !== 'HEAD');
