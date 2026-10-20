# SSO 로그인 — SAML 2.0 (P28D)

PJGuardian은 SAML 2.0 IdP(ADFS·Entra ID·Okta·Keycloak 등) **하나**로 SSO 로그인을 할 수 있다(명세 19.2). 웹 브라우저 SSO 중 **SP 시작**만 지원한다:
로그인 화면 단추 → `AuthnRequest`(HTTP-Redirect) → IdP 로그인 → `SAMLResponse`(HTTP-POST) → 앱.
계정 규칙은 P28B·P28C와 같다(`docs/auth_ldap.md` 1·4절, `docs/auth_oidc.md` 4절).

- 첫 SAML 로그인 → **승인 대기 계정**(비활성·세션 없음). 관리자가 사용자 관리에서 승인(역할)·거절·삭제.
- 계정 식별은 **(IdP EntityID, NameID 또는 지정한 속성)**. 이메일이 같다는 이유로 기존 계정에 연결하지 않는다.
- 이름·이메일은 계정을 만들 때 한 번만 IdP 값으로 채우고, 이후에는 갱신하지 않는다.
- **IdP 시작 로그인은 지원하지 않는다**(요청 ID 없는 응답은 거부). **단일 로그아웃(SLO)은 없다** — 로그아웃은 앱 세션만 끝낸다.
- 장애 때에도 **로컬 관리자는 앱 비밀번호로 로그인**할 수 있다(활성 로컬 관리자는 늘 한 명 이상).

## 0. 서명 검증 라이브러리

SAML 응답의 XML 서명 검증은 직접 구현하지 않고 검증된 오픈소스를 쓴다.

| 라이브러리 | 버전 | 라이선스 | 위치 |
| --- | --- | --- | --- |
| onelogin/php-saml (SAML-Toolkits) | 4.3.2 | MIT | `vendor/onelogin/php-saml/` |
| robrichards/xmlseclibs | 3.1.5 | BSD-3-Clause | `vendor/robrichards/xmlseclibs/` |

- `composer.json`·`composer.lock`은 커밋하고 `vendor/`는 git에서 제외한다. **배포 묶음에는 `vendor/`를 함께 넣는다**(빌드 때 `composer install --no-dev`, `docs/vendor_build.md` 3절). 라이선스 원문은 `THIRD_PARTY_NOTICES`.
- `vendor/`가 없으면 SAML은 꺼진 것처럼 동작하고(로그인 단추 없음) 점검 화면에 "SAML 라이브러리(vendor/)가 없습니다"가 보인다. 나머지 기능은 그대로.
- 업데이트: 개발 PC에서 `composer update onelogin/php-saml --with-dependencies` → `composer audit` → 전체 시험(`php tools/run_all_tests.php`) → `THIRD_PARTY_NOTICES`·이 표의 버전 갱신 → 커밋(composer.lock). 보안 공지는 https://github.com/SAML-Toolkits/php-saml/security 와 `composer audit`로 확인한다.

## 1. 준비

1. 마이그레이션 **027**(SP 키 저장)·**028**(인증서 만료 알림 종류)을 적용한다: `php tools/migrate.php`. 027 전에는 SAML이 꺼진 것처럼, 028 전에는 만료 알림만 만들지 않는다(점검·설정 화면 경고는 그대로).
2. 시스템 관리 > 설정 관리 > **SSO(SAML)** 탭에서 IdP 값을 넣는다. 가장 쉬운 방법은 **IdP 메타데이터 가져오기**(메타데이터 주소(https) 또는 XML 붙여넣기) — EntityID·SSO 주소·서명 인증서(둘이면 다음 인증서까지) 칸을 채운다. **저장은 하지 않으므로** "설정 항목 변경 확인"으로 확인하고 저장한다.
3. 같은 탭의 **SP 정보**를 IdP에 등록한다(복사 단추, 또는 "SP 메타데이터 내려받기" → `GET /auth/saml/metadata`):
   - SP EntityID: `<공개 주소>/auth/saml/metadata`(설정 `saml.sp_entity_id`로 바꿀 수 있음)
   - ACS 주소(HTTP-POST): `<공개 주소>/auth/saml/acs`
   - 공개 주소는 OIDC와 같다: `oidc.redirect_base`, 비우면 `app.base_url`(https — 루프백 개발 주소만 http). 요청의 Host 헤더는 쓰지 않는다.
4. IdP에서 NameID(persistent 권장)·속성(로그인 ID·이름·이메일)을 내보내게 하고, 앱 설정의 `saml.attr_login` 등에 그 속성 이름을 넣는다.
5. **설정 확인**: IdP 인증서(형식·만료) → SSO 주소 도달 → SP 설정 → SP 메타데이터. 통과하면 `saml.enabled`를 켠다 — 로그인 화면에 단추가 생긴다(OIDC도 켜져 있으면 단추 둘).

## 2. 제공자별 예

| 제공자 | IdP 메타데이터(가져오기) | NameID·식별자 | 로그인 ID·이름·이메일 속성 |
| --- | --- | --- | --- |
| ADFS | `https://<adfs>/FederationMetadata/2007-06/FederationMetadata.xml` | 신뢰 당사자 클레임 규칙으로 NameID(persistent) 또는 objectGUID 속성 → `id_source` | `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/windowsaccountname`(또는 sAMAccountName을 uid로 내보내기), `.../claims/name`, `.../claims/emailaddress` |
| Entra ID | 엔터프라이즈 앱 > SAML > "앱 페더레이션 메타데이터 URL" | 식별자에 `http://schemas.microsoft.com/identity/claims/objectidentifier`(`id_source`) 권장 | `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name`(UPN), `.../claims/givenname`, `.../claims/emailaddress` |
| Okta | 앱 > Sign On > "Metadata URL" | NameID 형식 Persistent | Attribute Statements에 `uid`(user.login 앞부분), `displayName`, `mail` 정의 |
| Keycloak | `https://<호스트>/realms/<렐름>/protocol/saml/descriptor` | 클라이언트의 "Name ID format" persistent | 매퍼로 `uid`(username), `displayName`, `mail` |

- 속성 이름은 Name(URI 가능) 또는 FriendlyName으로 찾는다.
- 요청 서명(`saml.sign_requests`)은 IdP가 요구할 때만 켠다. 암호화된 어서션(`saml.want_encrypted`)을 켜면 SP 인증서로 암호화하도록 IdP를 설정한다. 둘 다 **SP 키**가 필요하다(4절).

## 3. 설정 키(`[saml]`, 화면 "시스템 관리 > 설정 관리 > SSO(SAML)", 재시작 없음)

| 키 | 기본값 | 설명 |
| --- | --- | --- |
| `saml.enabled` | false | 켜면 로그인 단추·인증 방식 목록(CSV·인증 방식 전환)에 `saml`. 켜려면 IdP EntityID·SSO 주소·인증서·로그인 ID 속성과 https 공개 주소 필요 |
| `saml.idp_entity_id` | "" | 응답 Issuer와 정확히 같아야 함 |
| `saml.idp_sso_url` | "" | HTTP-Redirect SingleSignOnService(https) |
| `saml.idp_cert` | "" | IdP 서명 인증서(PEM 본문 base64 한 줄 — 화면에 PEM을 붙여 넣으면 본문만 남김). 지문이 아니라 인증서 전체로 검증 |
| `saml.idp_cert_next` | "" | 롤오버용 다음 인증서(5절) |
| `saml.sp_entity_id` | "" | 비우면 `<공개 주소>/auth/saml/metadata` |
| `saml.name_id_format` | persistent | persistent·unspecified·emailAddress·transient(식별자가 nameid면 transient 금지) |
| `saml.id_source` | nameid | `nameid` 또는 바뀌지 않는 속성 이름 |
| `saml.attr_login`·`attr_name`·`attr_email` | "" | 속성 Name 또는 FriendlyName(URI 가능). 로그인 ID는 X06 규칙 검사 |
| `saml.sign_requests` | false | AuthnRequest 서명(SHA-256, SP 키) |
| `saml.want_encrypted` | false | 암호화되지 않은 어서션 거부(SP 키로 복호화) |
| `saml.button_label` | "" | 40자, 비우면 "SAML로 로그인" |
| `saml.start_per_minute`※ | 20 | 로그인 시작 IP당 분당 |
| `saml.test_per_minute`※ | 6 | 설정 확인·메타데이터 가져오기 관리자당 분당 |
| `saml.allow_local_dev`※ | false | 루프백 IdP 허용(메타데이터 가져오기·설정 확인, 개발) |

※ 화면에서 편집하지 않음. 인증서 만료 경고 기간은 `notification.cert_expiry_warn`(기본 30일, P26B와 같은 키)을 쓴다.

## 4. SP 키·인증서

- "SP 키 만들기": RSA 2048 개인 키 + 자체 서명 인증서(10년, SHA-256)를 만들어 `app_secrets`(`saml.sp_private_key`, `saml.sp_cert`)에 **암호화 저장**한다(키는 secrets.ini — DB·백업에 없음). 개인 키는 화면·API·로그·백업 평문 어디에도 나오지 않는다. 감사 `auth.saml_sp_key_generate`(값 없음).
- 키가 있으면 SP 메타데이터에 인증서가 실린다. "다시 만들기"는 인증서가 바뀌므로 **IdP에 SP 메타데이터를 다시 등록**해야 한다.
- 요청 서명·암호화를 쓰지 않으면 키가 필요 없다.

## 5. 인증서 롤오버·만료 경고

- IdP가 서명 인증서를 바꿀 때: 새 인증서를 **`saml.idp_cert_next`**에 먼저 넣는다(메타데이터 가져오기가 인증서 두 개를 읽으면 자동으로 채움). 이 기간에는 **두 인증서 중 하나로 서명된 응답을 모두 받는다**. IdP가 새 인증서로 바꾼 뒤 새 인증서를 `idp_cert`로 옮기고 `idp_cert_next`를 비운다.
- 등록한 인증서(현재·다음)가 `notification.cert_expiry_warn`(기본 30일) 안에 만료되면:
  - 설정 화면 SAML 탭에 만료일·남은 날(경고 아이콘), 점검 화면 "IdP 인증서가 …에 만료됩니다"(경고), 만료되면 실패.
  - 워커가 시간마다 보고 **관리자 앱 알림**(`saml_cert_expiring`, 인증서마다 하루 한 건, 마이그레이션 028).

## 6. 검증 규칙

라이브러리(strict 모드 — 보안 옵션은 `SamlSettings::library()` 한 곳에서 정하고 완화하지 않음):

- **서명 필수**: 응답 또는 어서션 중 최소 하나가 IdP 인증서(현재·다음)로 검증돼야 함. 서명 없는 응답 거부. 서명된 요소가 실제로 쓰는 어서션인지(참조 ID·개수) 확인 — 서명 래핑 방지.
- 어서션은 정확히 1개(암호화 포함). 스키마 검사. `Issuer` = IdP EntityID, `Audience`에 SP EntityID, `InResponseTo` = 저장한 요청 ID, 시간(라이브러리 오차 180초), SubjectConfirmation(bearer·Recipient·NotOnOrAfter·InResponseTo).
- XML은 DOCTYPE·엔티티가 있으면 거부(XXE), 메타데이터도 같은 로더.

앱이 더 엄격하게:

- 응답 크기 512 KiB, base64 형식. (HTTP-POST 응답은 압축하지 않는 바인딩이라 압축 해제가 없다 — 앱이 보내는 요청만 deflate.)
- **요청 기록**: AuthnRequest ID(라이브러리, `random_bytes`)를 키로 서버 쪽 파일(`<작업 폴더>/saml/requests/`, 이름은 해시)에 10분·한 번만. ACS는 응답의 `InResponseTo`로 꺼낸다 — 없음·재사용·만료·IdP 시작은 거부. **RelayState**는 요청마다 만든 무작위 토큰이고 저장한 값과 같아야 한다(돌아갈 곳은 RelayState가 아니라 서버에 저장한 값 — 열린 리다이렉트 방지).
- `Destination`(있으면)·`Recipient`는 ACS 주소와 **정확히** 같아야(라이브러리는 앞부분 비교). 라이브러리의 "현재 주소"는 검증하는 동안 설정한 공개 주소로 고정한다(Host·X-Forwarded 헤더를 믿지 않음).
- 시간을 **오차 60초**로 다시(주입한 시계): Conditions NotBefore·NotOnOrAfter, SubjectConfirmationData NotOnOrAfter, SessionNotOnOrAfter.
- **SHA-1 서명·다이제스트 거부**. 식별자가 nameid인데 transient NameID면 거부.
- **어서션 ID 재사용 거부**: (EntityID, 어서션 ID)를 만료 시각까지 기억(`<작업 폴더>/saml/assertions/`, 배타적 생성).
- **브라우저 묶기(로그인 CSRF 방지)**: 세션 쿠키는 SameSite=Lax라 IdP의 cross-site POST(ACS)에는 실리지 않는다(SameSite=None으로 바꾸지 않음). 그래서 ACS는 검증만 하고 **2분·한 번 쓰는 마무리 토큰**으로 `303 /auth/saml/finish?t=…`를 돌려준다. 이 GET은 같은 사이트의 최상위 이동이라 Lax 쿠키가 실리고, **로그인을 시작한 브라우저의 로그인 전 세션과 같을 때만** 계정 결정·세션 발급을 한다(다른 브라우저면 E-SSO-STATE). 헤드리스 Chrome으로 실제 흐름(cross-site POST → 303 → 쿠키)을 확인했다.
- CSRF 미들웨어의 예외는 `POST /auth/saml/acs` 하나뿐이다(위 검증이 대신).
- 세션 고정 방지(로그인 때 세션 ID 새로), 로그인 시작 IP당 분당 제한, IP 차단(P10) 중이면 마무리도 거부.
- 응답 원문·어서션·속성 값·SP 개인 키는 로그·감사·오류에 남기지 않는다(사유 코드만).

### 로그인 화면 오류 코드(P28C와 같은 체계 — 사유는 감사 로그 `auth.login_failed`의 `reason`, 접두사 `saml_`)

| 코드 | 뜻 | 감사 reason 예 |
| --- | --- | --- |
| E-SSO-STATE | 요청 기록 없음·재사용·만료·IdP 시작·RelayState 다름·다른 브라우저의 마무리 | `saml_request_unknown`, `saml_unsolicited`, `saml_relay_mismatch`, `saml_finish_invalid` |
| E-SSO-IDP | IdP가 Success가 아닌 상태를 돌려줌 | `saml_response.idp_status` |
| E-SSO-TOKEN | 응답 검증 실패 | `saml_response.no_signature`, `.bad_signature`, `.assertion_count`, `.destination`, `.audience`, `.issuer`, `.expired`, `.not_yet_valid`, `.replay`, `.weak_algorithm`, `.invalid_xml`, `.transient_nameid`, `.no_identifier` |
| E-SSO-CONFIG | 꺼짐·설정 빠짐·SP 키 없음·인증서 문제 | `saml_not_configured`, `saml_sp_key_missing` |
| E-SSO-RATE | 요청 제한·IP 차단 | `saml_start_rate` |
| E-SSO-DENIED | 계정 결정에서 거부(로그인 ID 충돌·비활성·잘못된 로그인 ID) | `login_id_conflict`, `invalid_login_id` |

## 7. 장애 대응

- IdP 장애·인증서 문제: SAML 로그인만 안 된다. **로컬 관리자는 ID·비밀번호로 로그인**한다. 설정 확인·점검 화면(SSO(SAML) 항목)으로 원인을 본다.
- 인증서가 만료됐는데 새 인증서를 아직 못 받음: IdP 관리자에게 받아 `idp_cert`에 넣는다(앱이 만료 인증서로 서명된 응답을 받는지는 라이브러리 동작 — 만료일은 점검에서 실패로 보인다).
- 암호화 키를 바꿨거나 잃음: SP 키를 "읽을 수 없음" → 다시 만들고 IdP에 SP 메타데이터 재등록.

## 8. 실제 IdP 점검표(P28D는 모의 IdP로만 시험 — 운영 전 확인)

- [ ] 메타데이터 가져오기로 칸이 채워지고 저장 뒤 설정 확인 4단계 통과
- [ ] IdP에 SP 메타데이터 등록(EntityID·ACS·필요하면 SP 인증서)
- [ ] 처음 로그인하는 사람 → "승인 대기" 안내, 사용자 관리에 승인 대기 1건(SAML(SSO))
- [ ] 승인 → 로그인 → 내 정보 인증 방식 SAML(SSO), 이름·이메일이 IdP 속성 값
- [ ] IdP에서 이름을 바꾼 뒤 재로그인 → 앱 이름 그대로, 같은 계정
- [ ] 같은 로그인 ID의 로컬 계정이 있으면 거부(E-SSO-DENIED), 로컬 계정 그대로
- [ ] IdP 포털에서 앱 타일로 들어가기(IdP 시작) → 거부(E-SSO-STATE) 확인 — 사용자 안내 필요
- [ ] 브라우저 뒤로 가기로 ACS를 다시 보내면 거부
- [ ] 인증서 롤오버 리허설: 다음 인증서 등록 → IdP 인증서 교체 → 로그인 → 현재로 옮김
- [ ] 요청 서명·암호화를 쓰면 IdP 쪽 설정과 함께 로그인 확인
- [ ] 로그아웃 → 앱만 로그아웃(IdP 세션 유지 — SLO 없음)
- [ ] 감사 로그 `auth.login_failed`에 사유만, 응답·속성 값 없음
