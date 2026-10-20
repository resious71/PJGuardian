<?php

declare(strict_types=1);

// 인수 시험(P32) 라우터 — PHP 내장 서버가 설치본의 public/ 정적 파일은 그대로, 나머지는 설치본의 프런트 컨트롤러로 보낸다.
//   PJG_ACC_APP=<설치본> [PJG_ACC_FAKES=<가짜 폴더>] php -S 127.0.0.1:<포트> -t <설치본>/public tools/acceptance/router.php
// 가짜 폴더에 파일이 있을 때만(시나리오 10) 시험 지원 코드(tests/Support — 묶음에는 없음)의 가짜 디렉터리·OIDC 제공자를 끼운다:
//   ldap.json → FakeDirectoryClient::fromFile, oidc/(keys·codes·used.json + secret) → FakeOidcProvider::fromDir. 실제 LDAP·IdP에 연결하지 않는다.
// 그 밖에는 설치본의 public/index.php를 그대로 실행한다(운영과 같은 경로).
$appRoot = rtrim(str_replace('\\', '/', (string) getenv('PJG_ACC_APP')), '/');
$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri !== '/' && !str_contains($uri, '..') && is_file($appRoot . '/public' . $uri) && !str_ends_with($uri, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$fakes = rtrim(str_replace('\\', '/', (string) getenv('PJG_ACC_FAKES')), '/');
if ($fakes === '' || (!is_file("{$fakes}/ldap.json") && !is_file("{$fakes}/oidc/secret"))) {
    require $appRoot . '/public/index.php';
    return true;
}

require $appRoot . '/src/bootstrap.php';
$support = dirname(__DIR__, 2) . '/tests/Support';
$app = PJGuardian\App::create(PJG_ROOT);
if (is_file("{$fakes}/ldap.json")) {
    require_once $support . '/FakeDirectoryClient.php';
    $app->useDirectoryClient(static fn (): PJGuardian\Tests\Support\FakeDirectoryClient => PJGuardian\Tests\Support\FakeDirectoryClient::fromFile("{$fakes}/ldap.json"));
}
if (is_file("{$fakes}/oidc/secret")) {
    require_once $support . '/FakeOidcProvider.php';
    $idp = PJGuardian\Tests\Support\FakeOidcProvider::fromDir("{$fakes}/oidc", 'https://idp.example.test', 'pjg-client', trim((string) file_get_contents("{$fakes}/oidc/secret")));
    $app->useOidcTransport($idp, static fn (string $h): array => $h === 'idp.example.test' ? ['203.0.113.10'] : []);
}
$request = PJGuardian\Http\Request::fromGlobals();
$app->handle($request)->send($request->method !== 'HEAD');
return true;
