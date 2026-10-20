<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;

/**
 * 외부 인증 관리(P28B, admin만 — 라우트의 RequireAdmin, 쓰기는 CSRF). 연결 설정 값은 설정 관리(/api/v1/admin/config-settings)에서.
 *   GET    /api/v1/admin/auth/ldap                    상태(켜짐·확장·설정 채움·검색 계정 비밀번호 set|none|unreadable·CA 파일)
 *   PUT    /api/v1/admin/auth/ldap/bind-password      {password} 저장·교체(값은 다시 내리지 않음)
 *   DELETE /api/v1/admin/auth/ldap/bind-password      삭제
 *   POST   /api/v1/admin/auth/ldap/test               {login_id?} 연결 시험(단계별, 분당 횟수 제한)
 * SSO(OIDC, P28C):
 *   GET    /api/v1/admin/auth/oidc                    상태(켜짐·027 적용·설정 채움·클라이언트 비밀 set|none|unreadable·리다이렉트 URI)
 *   PUT    /api/v1/admin/auth/oidc/client-secret      {secret} 저장·교체(값은 다시 내리지 않음)
 *   DELETE /api/v1/admin/auth/oidc/client-secret      삭제
 *   POST   /api/v1/admin/auth/oidc/test               연결 시험(디스커버리·JWKS·토큰 엔드포인트 도달, 분당 횟수 제한)
 * SSO(SAML, P28D):
 *   GET    /api/v1/admin/auth/saml                    상태(SP EntityID·ACS·메타데이터 주소, SP 키 set|none|unreadable·인증서 만료일, IdP 인증서 만료일)
 *   POST   /api/v1/admin/auth/saml/sp-key             SP 키·자체 서명 인증서 생성(있으면 교체, 개인 키는 내리지 않음)
 *   DELETE /api/v1/admin/auth/saml/sp-key             삭제
 *   POST   /api/v1/admin/auth/saml/metadata-import    {xml?|url?} IdP 메타데이터에서 값만 읽어 돌려줌(저장은 설정 화면이)
 *   POST   /api/v1/admin/auth/saml/test               설정 확인(인증서·SSO 주소 도달·SP·SP 메타데이터, 분당 횟수 제한)
 */
final class AdminAuthController
{
    public function __construct(private readonly App $app)
    {
    }

    private static function actor(Request $request): Actor
    {
        return Actor::fromUser($request->attribute('user'), $request->clientIp);
    }

    public function ldap(Request $request): Response
    {
        return Response::data($this->app->ldapAdmin()->status());
    }

    public function putBindPassword(Request $request): Response
    {
        return Response::data(['bind_password' => $this->app->ldapAdmin()->setBindPassword(self::actor($request), $request->json()['password'] ?? null)]);
    }

    public function deleteBindPassword(Request $request): Response
    {
        return Response::data(['bind_password' => $this->app->ldapAdmin()->deleteBindPassword(self::actor($request))]);
    }

    public function test(Request $request): Response
    {
        $body = $request->body() === '' ? [] : $request->json();
        return Response::data($this->app->ldapAdmin()->test(self::actor($request), $body['login_id'] ?? null));
    }

    public function oidc(Request $request): Response
    {
        return Response::data($this->app->oidcAdmin()->status());
    }

    public function putClientSecret(Request $request): Response
    {
        return Response::data(['client_secret' => $this->app->oidcAdmin()->setClientSecret(self::actor($request), $request->json()['secret'] ?? null)]);
    }

    public function deleteClientSecret(Request $request): Response
    {
        return Response::data(['client_secret' => $this->app->oidcAdmin()->deleteClientSecret(self::actor($request))]);
    }

    public function oidcTest(Request $request): Response
    {
        return Response::data($this->app->oidcAdmin()->test(self::actor($request), $this->app->oidcSettings()->testPerMinute));
    }

    public function saml(Request $request): Response
    {
        return Response::data($this->app->samlAdmin()->status());
    }

    public function generateSpKey(Request $request): Response
    {
        return Response::data($this->app->samlAdmin()->generateSpKey(self::actor($request)));
    }

    public function deleteSpKey(Request $request): Response
    {
        return Response::data($this->app->samlAdmin()->deleteSpKey(self::actor($request)));
    }

    public function importSamlMetadata(Request $request): Response
    {
        $body = $request->json();
        return Response::data($this->app->samlAdmin()->importMetadata(self::actor($request), $body['xml'] ?? null, $body['url'] ?? null));
    }

    public function samlTest(Request $request): Response
    {
        return Response::data($this->app->samlAdmin()->test(self::actor($request)));
    }
}
