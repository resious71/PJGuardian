<?php

declare(strict_types=1);

/**
 * 모의 SAML IdP의 "로그인 완료" 단계(P28D 브라우저 점검 tools/check_saml_ui.mjs 전용 — 실제 IdP 없음).
 * 브라우저가 IdP로 보낸 AuthnRequest 주소를 받아, 상태 폴더의 시험용 키(saml_keys.json — 격리 설치 때 만듦)로 서명한 SAMLResponse를 만든다.
 *   php tests/Support/fake_saml_sign.php <상태 폴더> <AuthnRequest 주소> <옵션 JSON>
 * 출력(JSON): {response, relay, acs}. 옵션은 FakeSamlIdp::response()와 같다(attributes, name_id, tamper 등). 응답 원문은 표준 출력으로만(점검 스크립트가 바로 폼에 넣음).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use PJGuardian\Tests\Support\FakeSamlIdp;

[, $stateDir, $requestUrl, $optJson] = $argv + [1 => '', 2 => '', 3 => '{}'];
$opt = json_decode($optJson, true);
if (!is_dir($stateDir) || !is_array($opt)) {
    fwrite(STDERR, "사용법: php tests/Support/fake_saml_sign.php <상태 폴더> <AuthnRequest 주소> <옵션 JSON>\n");
    exit(2);
}
$idp = new FakeSamlIdp($stateDir, 'https://idp.example.test/saml', 'https://idp.example.test/saml/sso', 0, $stateDir);
$req = FakeSamlIdp::parseRequest($requestUrl);
$o = $opt + ['in_response_to' => $req['id'], 'acs' => $req['acs'], 'audience' => $req['issuer']];
echo json_encode(['response' => $idp->response($o), 'relay' => $req['relay'], 'acs' => $req['acs']]);
