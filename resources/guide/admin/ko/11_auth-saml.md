---
key: auth-saml
title: SSO(SAML) 로그인
order: 11
summary: SAML 2.0 IdP 하나로 SSO 로그인 — IdP 메타데이터 가져오기, SP 정보(EntityID·ACS 주소·메타데이터) 등록, 설정 관리 SSO(SAML) 탭 항목, SP 키, 설정 확인, 인증서 롤오버·만료 경고, 흔한 오류, 운영 점검표
keywords: SAML, SSO, ADFS, Entra ID, Okta, Keycloak, IdP, SP, EntityID, ACS, 메타데이터, NameID, 인증서, 롤오버, 만료, SP 키, 요청 서명, 암호화된 어서션, E-SSO-STATE, E-SSO-TOKEN
related: external-auth, auth-oidc, accounts, settings
audience: admin
---

## 무엇을 하는 기능인가요

SAML 2.0 IdP(ADFS, Entra ID, Okta, Keycloak 등) **하나**로 SSO 로그인을 합니다. 로그인 화면의 SAML 단추 → IdP 로그인 → 앱으로 돌아오는 **SP 시작 로그인만** 지원합니다.

- **IdP 포털에서 앱 타일로 들어오는 로그인(IdP 시작)은 거부합니다**(E-SSO-STATE). 사용자에게 앱의 로그인 화면에서 시작하라고 안내하세요.
- **단일 로그아웃(SLO)은 없습니다.** 로그아웃은 앱 세션만 끝냅니다.
- 계정은 **(IdP EntityID, NameID 또는 지정한 속성)**으로 구분합니다. 이메일이 같다는 이유로 연결하지 않습니다.
- 응답의 XML 서명 검증은 검증된 라이브러리(onelogin/php-saml)로만 합니다. 배포 묶음의 `vendor/` 폴더가 없으면 SAML은 꺼진 것처럼 동작하고 [점검](/admin/checks) 화면에 "SAML 라이브러리(vendor/)가 없습니다"가 나옵니다.
- 공통 규칙(승인 대기, 로컬 관리자 보존)은 [외부 인증 개요](guide:external-auth)를 보세요.

## 준비

1. **마이그레이션 027·028**을 적용합니다(`php tools/migrate.php`). 027 전에는 SAML이 꺼진 것처럼, 028 전에는 인증서 만료 알림만 만들지 않습니다.
2. **공개 주소**는 OIDC와 같습니다: `oidc.redirect_base`, 비우면 `app.base_url`. https여야 합니다(루프백 개발 주소만 http). 요청의 Host 헤더는 쓰지 않습니다.
3. IdP 관리자에게 IdP 메타데이터 주소(또는 XML)를 받습니다.

## IdP 값 넣기: 메타데이터 가져오기

시스템 관리 > [설정 관리](/admin/config-settings) > **{{ui:config_settings.group.auth_saml}}** 탭에서 합니다.

1. **{{ui:saml.import.title}}** 영역에 **{{ui:saml.import.url_label}}**(https)를 넣거나 **{{ui:saml.import.xml_label}}**에 XML을 붙여 넣습니다.
2. **{{ui:saml.import.run}}**을 누릅니다. IdP EntityID, IdP SSO 주소, 서명 인증서 칸이 채워집니다. 메타데이터에 인증서가 둘이면 다음 인증서 칸까지 채웁니다(`auth.saml_metadata_import`).
3. 가져오기는 **칸만 채우고 저장하지 않습니다.** 값을 확인한 뒤 아래 **설정 항목 변경 확인** 단추로 저장합니다.

가져오기는 DOCTYPE·엔티티가 든 XML을 거부합니다. 오류는 `saml_metadata_invalid`(XML이 아니거나 필요한 요소·인증서 없음, 1 MiB 초과), `saml_metadata_url_invalid`(https 아님), `saml_metadata_unreachable`(가져오지 못함)입니다.

## 탭의 설정 항목

- **{{ui:config_settings.key.saml.idp_entity_id.label}}** (`saml.idp_entity_id`): 응답의 Issuer와 정확히 같아야 합니다.
- **{{ui:config_settings.key.saml.idp_sso_url.label}}** (`saml.idp_sso_url`): HTTP-Redirect 바인딩의 SSO 주소(https).
- **{{ui:config_settings.key.saml.idp_cert.label}}** (`saml.idp_cert`): PEM을 붙여 넣으면 본문만 남깁니다. 지문이 아니라 인증서 전체로 검증합니다.
- **{{ui:config_settings.key.saml.idp_cert_next.label}}** (`saml.idp_cert_next`): 인증서 교체 기간에 씁니다(아래 롤오버).
- **{{ui:config_settings.key.saml.sp_entity_id.label}}** (`saml.sp_entity_id`): 비우면 `<공개 주소>/auth/saml/metadata`.
- **{{ui:config_settings.key.saml.name_id_format.label}}** (`saml.name_id_format`): 기본 persistent. 식별자로 NameID를 쓰면 transient는 쓸 수 없습니다(로그인마다 바뀜).
- **{{ui:config_settings.key.saml.id_source.label}}** (`saml.id_source`): nameid 또는 바뀌지 않는 속성 이름.
- **{{ui:config_settings.key.saml.attr_login.label}}** (`saml.attr_login`), **{{ui:config_settings.key.saml.attr_name.label}}** (`saml.attr_name`), **{{ui:config_settings.key.saml.attr_email.label}}** (`saml.attr_email`): 속성의 Name(URI 가능) 또는 FriendlyName. 로그인 ID는 앱의 로그인 ID 규칙에 맞아야 하고, 이름·이메일은 계정을 만들 때 한 번만 씁니다.
- **{{ui:config_settings.key.saml.sign_requests.label}}** (`saml.sign_requests`): IdP가 요구할 때만 켭니다. SP 키가 필요합니다.
- **{{ui:config_settings.key.saml.want_encrypted.label}}** (`saml.want_encrypted`): 켜면 암호화되지 않은 어서션을 거부합니다. SP 키가 필요하고, IdP가 SP 인증서로 암호화하도록 설정해야 합니다.
- **{{ui:config_settings.key.saml.button_label.label}}** (`saml.button_label`): 40자 이내. 비우면 "SAML로 로그인".
- **{{ui:config_settings.key.saml.enabled.label}}** (`saml.enabled`): 마지막에 켭니다. 켜려면 IdP EntityID, SSO 주소, 인증서, 로그인 ID 속성과 https 공개 주소가 있어야 합니다.

제공자별 참고:

- **ADFS**: 메타데이터 `https://<adfs>/FederationMetadata/2007-06/FederationMetadata.xml`. 신뢰 당사자 클레임 규칙으로 NameID(persistent)를 내보내거나 objectGUID를 속성으로 내보내 식별자로 지정합니다.
- **Entra ID**: 엔터프라이즈 앱 > SAML의 "앱 페더레이션 메타데이터 URL". 식별자로 objectidentifier 클레임 속성을 권장합니다.
- **Okta**: 앱 > Sign On의 "Metadata URL". NameID 형식 Persistent, Attribute Statements에 uid·displayName·mail을 정의합니다.
- **Keycloak**: `https://<호스트>/realms/<렐름>/protocol/saml/descriptor`. 클라이언트의 Name ID format을 persistent로, 매퍼로 uid·displayName·mail을 만듭니다.

## IdP에 SP 등록

같은 탭의 SP 정보를 IdP에 등록합니다. 각 값 옆에 복사 단추가 있습니다.

- **{{ui:saml.sp.entity_id}}**: `<공개 주소>/auth/saml/metadata`(또는 설정한 SP EntityID)
- **{{ui:saml.sp.acs_url}}**: `<공개 주소>/auth/saml/acs`
- **{{ui:saml.sp.metadata_url}}**: IdP가 메타데이터 주소로 등록을 받으면 이 주소를 줍니다. 파일이 필요하면 **{{ui:saml.sp.metadata_download}}**를 누릅니다. IdP 설정이 저장돼 있어야 메타데이터가 만들어집니다.

ACS 주소는 IdP가 다른 사이트에서 POST로 보내는 유일한 경로입니다. 앱은 요청 ID, RelayState, 한 번 사용 규칙으로 확인한 뒤 같은 브라우저에서 로그인을 마무리합니다. 앞단 프록시가 이 경로를 막거나 바꾸지 않는지는 운영 환경에서 확인할 것.

## SP 키

요청 서명이나 암호화된 어서션을 쓸 때만 필요합니다.

- **{{ui:saml.key.generate}}**: RSA 2048 개인 키와 자체 서명 인증서(10년)를 만들어 암호화 저장합니다. 개인 키는 화면·API·로그·백업 평문 어디에도 나오지 않습니다(`auth.saml_sp_key_generate`, 삭제는 `auth.saml_sp_key_delete`).
- 키가 있으면 SP 메타데이터에 인증서가 실립니다.
- **{{ui:saml.key.replace}}**를 하면 인증서가 바뀌므로 **IdP에 SP 메타데이터를 다시 등록**해야 합니다.
- 암호화 키가 바뀌어 "읽을 수 없음"이면 다시 만들고 IdP에 다시 등록합니다.

## 설정 확인

**{{ui:saml.test.run}}**은 저장한 설정으로 다음을 차례로 확인합니다. 실제 로그인은 하지 않습니다.

1. {{ui:saml.test.step.idp_cert}} — 형식과 만료(남은 날)
2. {{ui:saml.test.step.sso_url}}
3. {{ui:saml.test.step.sp}}
4. {{ui:saml.test.step.sp_metadata}}

설정 확인과 메타데이터 가져오기를 합쳐 관리자마다 분당 {{limit:saml.test_per_minute}}회까지입니다(넘으면 `saml_test_rate_limited`). 통과하면 사용을 켭니다. OIDC도 켜져 있으면 로그인 화면에 단추가 둘 생깁니다. SAML이 켜져 있으면 [점검](/admin/checks) 화면에 SSO(SAML) 항목이 나옵니다.

## 인증서 롤오버와 만료 경고

IdP가 서명 인증서를 바꿀 때:

1. 새 인증서를 **{{ui:config_settings.key.saml.idp_cert_next.label}}**에 먼저 넣고 저장합니다(메타데이터 가져오기가 인증서 둘을 읽으면 자동으로 채움). 이 기간에는 **두 인증서 중 하나로 서명된 응답을 모두 받습니다.**
2. IdP가 새 인증서로 바꿉니다.
3. 로그인이 되는지 확인한 뒤 새 인증서를 **{{ui:config_settings.key.saml.idp_cert.label}}**로 옮기고 다음 인증서 칸을 비웁니다.

등록한 인증서(현재·다음)가 {{limit:notification.cert_expiry_warn}} 안에 만료되면:

- SAML 탭에 만료일과 남은 날이 경고로 나오고, 점검 화면에 경고가 나옵니다. 만료되면 실패로 바뀝니다.
- 워커가 시간마다 확인해 관리자에게 앱 알림을 보냅니다(인증서마다 하루 한 건).

경고 기간은 설정 관리 알림 탭의 `notification.cert_expiry_warn`(허용 호스트의 인증서 경고와 같은 값)입니다.

## 흔한 오류

로그인 화면의 코드와 감사 로그 `auth.login_failed`의 사유(saml_로 시작)로 원인을 찾습니다. 응답 원문·속성 값은 남기지 않습니다.

- **E-SSO-STATE**: 요청 기록 없음·재사용·만료(10분), IdP 시작 로그인, RelayState 불일치, 다른 브라우저에서 마무리(사유 예: saml_request_unknown, saml_unsolicited, saml_relay_mismatch, saml_finish_invalid).
- **E-SSO-IDP**: IdP가 성공이 아닌 상태를 돌려줌(saml_response.idp_status).
- **E-SSO-TOKEN**: 응답 검증 실패 — 서명 없음·틀림, 어서션 개수, Destination·Audience·Issuer 불일치, 시간 범위 밖(오차 60초), 재사용, SHA-1 같은 약한 알고리즘, transient NameID, 식별자 없음(사유 예: saml_response.bad_signature, saml_response.audience, saml_response.expired). 인증서, SP EntityID·ACS 주소 등록, 서버 시계를 확인합니다.
- **E-SSO-CONFIG**: 꺼짐·설정 빠짐·SP 키 없음·인증서 문제(saml_not_configured, saml_sp_key_missing).
- **E-SSO-RATE**: 로그인 시작이 IP당 분당 {{limit:saml.start_per_minute}}회를 넘었거나 IP가 차단됨(saml_start_rate).
- **E-SSO-DENIED**: 같은 로그인 ID의 다른 계정, 비활성 계정, 잘못된 로그인 ID.

설정 확인의 실패 사유 예:

- "{{ui:saml.reason.not_configured}}"
- "{{ui:saml.reason.cert_expired}}" — IdP 관리자에게 새 인증서를 받아 넣습니다.
- "{{ui:saml.reason.sp_key_missing}}"
- "{{ui:saml.reason.unavailable}}"
- "{{ui:saml.reason.tls}}"

IdP 장애나 인증서 문제가 있어도 SAML 로그인만 안 되고, 로컬 관리자는 ID·비밀번호로 로그인할 수 있습니다.

## 운영 점검표

이 기능은 모의 IdP로만 시험했고, Apache·https 환경의 실제 흐름도 확인하지 않았습니다. 아래 항목은 운영 환경에서 확인할 것.

1. 메타데이터 가져오기로 칸이 채워지고, 저장 뒤 설정 확인 4단계가 통과한다.
2. IdP에 SP 메타데이터(EntityID, ACS, 필요하면 SP 인증서)를 등록했다.
3. 처음 로그인하는 사람에게 승인 대기 안내가 나오고, 사용자 관리에 인증 방식 {{ui:account.auth_saml}}인 승인 대기 1건이 생긴다.
4. 승인한 뒤 로그인하면 내 정보의 인증 방식이 {{ui:account.auth_saml}}이고 이름·이메일이 IdP 속성 값이다.
5. IdP에서 이름을 바꾼 뒤 다시 로그인해도 앱 이름은 그대로이고 같은 계정이다.
6. 같은 로그인 ID의 로컬 계정이 있으면 E-SSO-DENIED로 거부되고 로컬 계정은 그대로다.
7. IdP 포털의 앱 타일로 들어가면 E-SSO-STATE로 거부된다 — 사용자 안내를 준비한다.
8. 브라우저 뒤로 가기로 ACS에 다시 보내면 거부된다.
9. 인증서 롤오버를 미리 연습한다: 다음 인증서 등록 → IdP 인증서 교체 → 로그인 → 현재 칸으로 옮김.
10. 요청 서명·암호화를 쓰면 IdP 쪽 설정과 함께 로그인을 확인한다.
11. 로그아웃하면 앱만 로그아웃되고 IdP 세션은 남는다.
12. 감사 로그 `auth.login_failed`에는 사유만 있고 응답·속성 값은 없다.

## 관련 절

- [외부 인증 개요](guide:external-auth)
- [SSO(OIDC) 로그인](guide:auth-oidc)
- [계정 관리](guide:accounts)
- [설정 관리](guide:settings)
