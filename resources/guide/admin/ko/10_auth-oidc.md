---
key: auth-oidc
title: SSO(OIDC) 로그인
order: 10
summary: OpenID Connect 제공자 하나로 SSO 로그인 — 제공자 등록(리다이렉트 URI), 설정 관리 SSO(OIDC) 탭 항목, 클라이언트 비밀, 연결 시험, 계정 결정, 흔한 오류, 운영 점검표
keywords: OIDC, OpenID Connect, SSO, Entra ID, Azure AD, Okta, Keycloak, Google, 리다이렉트 URI, 클라이언트 ID, 클라이언트 비밀, 발급자, issuer, PKCE, JWKS, E-SSO-STATE, E-SSO-TOKEN, E-SSO-DENIED
related: external-auth, auth-saml, accounts, settings
audience: admin
---

## 무엇을 하는 기능인가요

표준 OIDC 제공자(Entra ID, Okta, Keycloak, Google 등) **하나**로 SSO 로그인을 합니다. 로그인 화면의 SSO 단추를 누르면 제공자의 로그인 화면으로 갔다가 돌아옵니다. 흐름은 Authorization Code + PKCE(S256)입니다.

- 처음 로그인한 사람은 승인 대기 계정이 됩니다.
- 계정은 **(발급자, sub)**로 구분합니다. 이메일이 같다는 이유로 기존 계정에 연결하지 않습니다.
- 로그아웃은 기본적으로 앱 세션만 끝냅니다.
- 공통 규칙은 [외부 인증 개요](guide:external-auth)를 보세요.

## 준비

1. **마이그레이션 027**을 적용합니다(`php tools/migrate.php`). 적용 전에는 OIDC가 꺼진 것처럼 동작하고 설정 탭에 안내가 나옵니다.
2. **공개 주소**를 정합니다. 리다이렉트 URI는 **{{ui:config_settings.key.oidc.redirect_base.label}}**(`oidc.redirect_base`)로 만들고, 비우면 `app.base_url`을 씁니다. https여야 합니다(루프백 개발 주소만 http 허용). 요청의 Host 헤더는 쓰지 않습니다.
3. 제공자에 앱(클라이언트)을 등록합니다. 등록할 값은 SSO(OIDC) 탭에 그대로 보이고 **{{ui:oidc.redirect.copy}}** 단추로 복사할 수 있습니다.
   - **{{ui:oidc.redirect.label}}**: `<공개 주소>/auth/oidc/callback`
   - 제공자 로그아웃을 켤 때만 **{{ui:oidc.redirect.logout_label}}**: `<공개 주소>/login?logged_out=1`
   - 응답 유형 code, PKCE S256, scope는 기본 openid profile email
4. 제공자에서 받은 발급자 URL, 클라이언트 ID, 클라이언트 비밀을 준비합니다.

제공자별 발급자와 로그인 ID 클레임 예:

- **Entra ID**: 발급자 `https://login.microsoftonline.com/<테넌트 ID>/v2.0`, 로그인 ID 클레임 preferred_username(UPN). 다중 테넌트(common)는 발급자가 테넌트마다 달라 쓰지 않습니다.
- **Okta**: 발급자 `https://<조직>.okta.com/oauth2/default`(또는 `https://<조직>.okta.com`), preferred_username.
- **Keycloak**: 발급자 `https://<호스트>/realms/<렐름>`, preferred_username. 사내 주소(사설 IP)여도 됩니다.
- **Google**: 발급자 `https://accounts.google.com`. preferred_username이 없으므로 email_localpart(검증된 이메일의 @ 앞) 또는 email을 씁니다.

## 설정 관리 SSO(OIDC) 탭

시스템 관리 > [설정 관리](/admin/config-settings) > **{{ui:config_settings.group.auth_oidc}}** 탭에서 넣고 아래 **설정 항목 변경 확인** 단추로 저장합니다. 재시작은 필요 없습니다.

- **{{ui:config_settings.key.oidc.issuer.label}}** (`oidc.issuer`): https 주소. 제공자 디스커버리 문서의 issuer와 끝의 `/`까지 **정확히** 같아야 합니다.
- **{{ui:config_settings.key.oidc.client_id.label}}** (`oidc.client_id`)
- **{{ui:config_settings.key.oidc.client_auth.label}}** (`oidc.client_auth`): basic(기본), post, none(비밀 없는 공개 클라이언트 + PKCE).
- **{{ui:config_settings.key.oidc.scopes.label}}** (`oidc.scopes`): 공백으로 나누며 openid가 꼭 있어야 합니다.
- **{{ui:config_settings.key.oidc.claim_login.label}}** (`oidc.claim_login`): 앱 로그인 ID가 될 클레임. 로그인 ID 규칙에 맞아야 합니다.
- **{{ui:config_settings.key.oidc.claim_name.label}}** (`oidc.claim_name`): 계정을 만들 때 한 번만 씁니다.
- **{{ui:config_settings.key.oidc.use_userinfo.label}}** (`oidc.use_userinfo`): ID 토큰에 이름·이메일이 없는 제공자만 켭니다.
- **{{ui:config_settings.key.oidc.allowed_algs.label}}** (`oidc.allowed_algs`): RS256·ES256 중에서만 고릅니다.
- **{{ui:config_settings.key.oidc.button_label.label}}** (`oidc.button_label`): 40자 이내. 비우면 "SSO로 로그인".
- **{{ui:config_settings.key.oidc.provider_logout.label}}** (`oidc.provider_logout`): 켜면 OIDC 계정이 로그아웃할 때 제공자의 로그아웃 화면으로 보냅니다.
- **{{ui:config_settings.key.oidc.jwks_cache_ttl.label}}** (`oidc.jwks_cache_ttl`, 지금 {{limit:oidc.jwks_cache_ttl}}), **{{ui:config_settings.key.oidc.jwks_stale_max.label}}** (`oidc.jwks_stale_max`, 지금 {{limit:oidc.jwks_stale_max}}): 디스커버리·서명 키 캐시와, 제공자에 닿지 않을 때 오래된 캐시를 더 쓰는 상한.
- **{{ui:config_settings.key.oidc.enabled.label}}** (`oidc.enabled`): 마지막에 켭니다. 켜면 로그인 화면에 단추가 생기고 인증 방식 목록(CSV, 인증 방식 변경)에 OIDC가 들어갑니다.

사내 CA 인증서를 쓰는 제공자는 저장소 연결과 같은 `repository.ca_cert_file`을 씁니다. 인증서 검증을 끄는 설정은 없습니다.

### 클라이언트 비밀

같은 탭의 **{{ui:oidc.secret.label}}**에 넣고 **{{ui:oidc.secret.save}}**를 누릅니다. 설정과 따로 암호화해 저장하며 다시 보여 주지 않습니다(**{{ui:oidc.secret.replace}}**·삭제만, `auth.oidc_secret_set`·`auth.oidc_secret_delete`). 클라이언트 인증 방식이 none이면 필요 없습니다. Entra ID처럼 비밀에 만료일이 있는 제공자는 만료일을 따로 기록해 두고 만료 전에 교체하세요.

## 연결 시험

**{{ui:oidc.test.run}}**은 저장한 설정으로 다음을 차례로 확인합니다. 자격 증명은 보내지 않습니다.

1. {{ui:oidc.test.step.discovery}}
2. {{ui:oidc.test.step.jwks}} — 키 수와 쓸 수 있는 알고리즘을 보여 줍니다.
3. {{ui:oidc.test.step.token_endpoint}} — 자격 증명 없이 보내 4xx 응답이 오면 도달한 것으로 봅니다.

관리자마다 분당 {{limit:oidc.test_per_minute}}회까지입니다(넘으면 `oidc_test_rate_limited`). 통과하면 사용을 켭니다. OIDC가 켜져 있으면 [점검](/admin/checks) 화면에 SSO(OIDC) 항목이 나옵니다.

## 계정 결정

- (발급자, sub)로 연결된 계정이 있으면 그 계정입니다. 로그인 ID 클레임 값이 바뀌어도 같은 계정입니다.
- 없고 같은 로그인 ID의 계정도 없으면 **승인 대기 계정**을 만듭니다. 이메일은 제공자가 검증했다고 할 때만 채웁니다.
- 같은 로그인 ID의 OIDC 계정이 아직 연결 전이면(CSV나 인증 방식 변경으로 미리 만든 계정) 이 로그인으로 연결합니다(`user.external_link`).
- 같은 로그인 ID의 계정이 로컬·LDAP이거나 다른 sub에 연결된 OIDC 계정이면 **거부**합니다(E-SSO-DENIED, 관리자 알림).
- 로그인 ID 클레임이 없거나 규칙에 맞지 않으면 거부합니다.

제공자에서 사용자를 막아도 앱은 알 수 없습니다. 퇴사자는 앱에서도 비활성화하세요. 토큰은 로그인 요청 안에서만 쓰고 저장하지 않습니다.

## 흔한 오류

로그인 화면의 코드와 감사 로그 `auth.login_failed`의 사유(oidc_로 시작)로 원인을 찾습니다.

- **E-SSO-STATE**: 로그인 시작 뒤 10분이 지났거나, 뒤로 가기로 콜백 주소를 다시 열었거나, 다른 브라우저에서 끝남(사유 예: oidc_state_invalid, oidc_state_missing).
- **E-SSO-IDP**: 제공자가 오류를 돌려줌 — 동의 거부, 앱 미할당(예: oidc_idp_error.access_denied).
- **E-SSO-TOKEN**: 코드 교환이나 ID 토큰 검증 실패(예: oidc_token_error.invalid_grant, oidc_id_token.nonce, oidc_id_token.bad_signature). 클라이언트 비밀 만료, 리다이렉트 URI 불일치, 서버 시계를 확인합니다.
- **E-SSO-CONFIG**: 설정 빠짐, 비밀 없음, 디스커버리의 issuer 불일치, 금지 주소(예: oidc_issuer_mismatch, oidc_secret_missing, oidc_blocked_address).
- **E-SSO-UNAVAILABLE**: 제공자에 닿지 않음·시간 초과·응답이 너무 큼.
- **E-SSO-RATE**: 로그인 시작이 IP당 분당 {{limit:oidc.start_per_minute}}회를 넘었거나 IP가 차단됨(oidc_start_rate).
- **E-SSO-DENIED**: 로그인 ID 충돌(login_id_conflict), 비활성 계정, 잘못된 로그인 ID(invalid_login_id).

연결 시험의 실패 사유:

- "{{ui:oidc.reason.issuer_mismatch}}" — 발급자 URL 끝의 `/`를 맞춥니다.
- "{{ui:oidc.reason.tls}}"
- "{{ui:oidc.reason.blocked_address}}" — 링크 로컬·메타데이터 주소는 늘 거부되고, 발급자가 공인 주소면 엔드포인트도 공인 주소여야 합니다.
- "{{ui:oidc.reason.jwks_no_usable_key}}"

### 장애와 교체

- **제공자 장애**: SSO만 안 됩니다. 로컬 관리자는 로그인 화면의 ID·비밀번호로 들어옵니다.
- **클라이언트 비밀 만료**: 제공자에서 새 비밀을 만들고 **{{ui:oidc.secret.replace}}**합니다.
- **제공자 서명 키 교체**: 모르는 키가 오면 키 목록을 자동으로 다시 받으므로 할 일이 없습니다.
- **암호화 키를 바꿨거나 잃음**: 클라이언트 비밀이 "읽을 수 없음"이 됩니다. 다시 저장합니다.

## 운영 점검표

이 기능은 가짜 IdP로만 시험했습니다. 아래 항목은 운영 환경에서 확인할 것.

1. 연결 시험 3단계가 모두 통과한다(디스커버리 issuer, 키 수·알고리즘, 토큰 엔드포인트 4xx).
2. 처음 로그인하는 사람에게 승인 대기 안내가 나오고, 사용자 관리에 인증 방식 {{ui:account.auth_oidc}}인 승인 대기 1건이 생긴다.
3. 승인한 뒤 로그인하면 내 정보의 인증 방식이 {{ui:account.auth_oidc}}이고 이름·이메일이 제공자 값이다.
4. 제공자에서 이름을 바꾼 뒤 다시 로그인해도 앱의 이름은 그대로다.
5. 같은 로그인 ID의 로컬 계정이 있으면 E-SSO-DENIED로 거부되고 로컬 계정은 그대로다.
6. 로그아웃하면 앱만 로그아웃된다. 제공자 로그아웃을 켜면 제공자 로그아웃 화면을 거쳐 로그인 화면으로 돌아온다.
7. 브라우저 뒤로 가기로 콜백 주소를 다시 열면 E-SSO-STATE가 나온다.
8. 방화벽으로 제공자를 막으면 E-SSO-UNAVAILABLE이 나오고 로컬 관리자는 로그인된다.
9. Entra ID는 클라이언트 비밀 만료일을 기록했고, Google은 이메일 검증 여부를 확인했다.
10. 감사 로그 `auth.login_failed`에는 사유만 있고 코드·토큰은 없다.

## 관련 절

- [외부 인증 개요](guide:external-auth)
- [SSO(SAML) 로그인](guide:auth-saml)
- [계정 관리](guide:accounts)
- [설정 관리](guide:settings)
