<?php

declare(strict_types=1);

/**
 * 인수 시험(P32) 시나리오 10의 가짜 IdP 준비(시험 지원 코드 tests/Support의 FakeOidcProvider·FakeSamlIdp — 묶음에는 없음, 실제 IdP 연결 없음).
 *   php tools/acceptance/fake_idp.php oidc-init <폴더>   → keys.json(시험용 RSA·EC 키)·codes.json·used.json. 클라이언트 비밀은 <폴더>/secret(실행기가 씀)
 *   php tools/acceptance/fake_idp.php saml-init <폴더>   → saml_keys.json(시험용 키·인증서), 출력 {entity, sso, cert}(cert = IdP 서명 인증서 base64 — 공개 값)
 * 서명된 SAML 응답은 tests/Support/fake_saml_sign.php <폴더> <AuthnRequest 주소> <옵션 JSON>으로 만든다.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Tests\Support\FakeOidcProvider;
use PJGuardian\Tests\Support\FakeSamlIdp;

[, $cmd, $dir] = $argv + [1 => '', 2 => ''];
if ($dir === '' || !in_array($cmd, ['oidc-init', 'saml-init'], true)) {
    fwrite(STDERR, "사용법: php tools/acceptance/fake_idp.php oidc-init|saml-init <폴더>\n");
    exit(2);
}
@mkdir($dir, 0777, true);
$dir = str_replace('\\', '/', (string) realpath($dir));
if ($cmd === 'oidc-init') {
    new FakeOidcProvider('https://idp.example.test', 'pjg-client', 'unused', time(), $dir, 'basic', $dir);
    foreach (['codes.json', 'used.json'] as $f) {
        file_put_contents("{$dir}/{$f}", '{}');
    }
    echo json_encode(['issuer' => 'https://idp.example.test', 'client_id' => 'pjg-client']), "\n";
    exit(0);
}
$idp = new FakeSamlIdp($dir, 'https://idp.example.test/saml', 'https://idp.example.test/saml/sso', 0, $dir);
echo json_encode(['entity' => $idp->entityId, 'sso' => $idp->ssoUrl, 'cert' => $idp->certB64('current')]), "\n";
