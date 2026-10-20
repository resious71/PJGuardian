# SSO 로그인 — OpenID Connect (P28C)

PJGuardian은 표준 OIDC 제공자(Entra ID·Okta·Keycloak·Google 등) **하나**로 SSO 로그인을 할 수 있다(명세 19.2).
흐름은 **Authorization Code + PKCE(S256)**, 계정 규칙은 P28B의 외부 인증 공통 규칙(`docs/auth_ldap.md` 1·4절 — 승인 대기·로컬 관리자 보존)을 따른다.

- 첫 SSO 로그인 → **승인 대기 계정**(비활성·세션 없음). 관리자가 사용자 관리에서 승인(역할 선택)·거절·삭제.
- 계정 식별은 **(발급자, sub)**. 이메일이 같다는 이유로 기존 계정에 연결하지 않는다.
- 이름·이메일은 계정을 만들 때 한 번만 제공자 값으로 채우고, 이후에는 갱신하지 않는다.
- 로그아웃은 기본으로 **앱 세션만** 끝낸다(제공자 로그아웃은 선택 설정).
- 장애 때에도 **로컬 관리자는 앱 비밀번호로 로그인**할 수 있다(활성 로컬 관리자는 늘 한 명 이상 — 마지막 로컬 관리자는 OIDC로 바꿀 수 없음).

## 1. 준비

1. 마이그레이션 **027**(`app_secrets` 이름 제약 넓힘)을 적용한다: `php tools/migrate.php`. 적용 전에는 OIDC가 꺼진 것처럼 동작하고, 설정 화면에 안내가 보인다.
2. 제공자에 앱(클라이언트)을 등록한다. 등록할 값은 시스템 관리 > 설정 관리 > **SSO(OIDC)** 탭에 그대로 보인다(복사 단추).
   - **리다이렉트 URI**: `<공개 주소>/auth/oidc/callback` (공개 주소 = `oidc.redirect_base`, 비우면 `app.base_url`. 요청의 Host 헤더는 쓰지 않는다)
   - 제공자 로그아웃을 켤 때만 **로그아웃 후 주소**: `<공개 주소>/login?logged_out=1`
   - 응답 유형 `code`, PKCE `S256`, scope `openid profile email`(설정 `oidc.scopes`)
3. 시스템 관리 > 설정 관리 > SSO(OIDC)에서 발급자 URL·클라이언트 ID를 넣고 **클라이언트 비밀**을 따로 저장한다(암호화 저장 — 다시 보여 주지 않음, 교체·삭제만). 공개 클라이언트면 `oidc.client_auth = none`(비밀 없음).
4. **연결 시험**: 디스커버리·issuer 일치 → 서명 키(JWKS) → 토큰 엔드포인트 도달(자격 증명 없이 보내 4xx면 도달). 통과하면 `oidc.enabled`를 켠다 — 로그인 화면에 단추가 생긴다.

## 2. 제공자별 예

| 제공자 | 발급자 URL(`oidc.issuer`) | 로그인 ID 클레임(`oidc.claim_login`) | 비고 |
| --- | --- | --- | --- |
| Entra ID(Azure AD) | `https://login.microsoftonline.com/<테넌트 ID>/v2.0` | `preferred_username`(UPN, 예: `kim@corp.example`) | 앱 등록 > 인증 > 웹 리다이렉트 URI. 클라이언트 비밀은 "인증서 및 비밀". 다중 테넌트(`common`)는 issuer가 테넌트마다 달라 쓰지 않는다 |
| Okta | `https://<조직>.okta.com/oauth2/default`(또는 `https://<조직>.okta.com`) | `preferred_username` | Web 앱, Grant type Authorization Code, PKCE 필수 켜기 가능 |
| Keycloak | `https://<호스트>/realms/<렐름>` | `preferred_username` | 클라이언트 인증 켜기(비밀) 또는 공개 클라이언트(`none`). 사내 주소면 사설 IP여도 된다(3절) |
| Google | `https://accounts.google.com` | `email_localpart`(검증된 이메일의 @ 앞) 또는 `email` | `preferred_username`이 없다. 로그인 ID가 `kim@gmail.com` 형식이어도 되면 `email` |

- 이름 클레임 `oidc.claim_name` 기본 `name`. ID 토큰에 이름·이메일이 없는 제공자만 `oidc.use_userinfo = true`.
- 사내 CA 인증서를 쓰는 제공자는 `repository.ca_cert_file`(저장소 연결과 같은 파일)을 쓴다. 인증서 검증을 끄는 설정은 없다.

## 3. 설정 키(`[oidc]`, 화면 "시스템 관리 > 설정 관리 > SSO(OIDC)", 재시작 없음)

| 키 | 기본값 | 설명 |
| --- | --- | --- |
| `oidc.enabled` | false | 켜면 로그인 화면에 SSO 단추, 인증 방식 목록(CSV·인증 방식 전환)에 `oidc` |
| `oidc.issuer` | "" | https, 디스커버리의 `issuer`와 **정확히** 같아야 함(끝의 `/`까지) |
| `oidc.client_id` | "" | 공백 없는 ASCII 255자 |
| `oidc.client_auth` | basic | `basic`(client_secret_basic)·`post`(client_secret_post)·`none`(공개 클라이언트 + PKCE) |
| `oidc.scopes` | openid profile email | 공백 구분, `openid` 필수. 리프레시 토큰은 쓰지 않는다 |
| `oidc.claim_login` | preferred_username | 로그인 ID가 될 클레임(X06 규칙 검사). `email_localpart` = 검증된 이메일의 @ 앞 |
| `oidc.claim_name` | name | 표시 이름(생성 때 한 번) |
| `oidc.use_userinfo` | false | UserInfo 한 번 더 호출(sub가 ID 토큰과 같아야 함) |
| `oidc.allowed_algs` | RS256, ES256 | ID 토큰 서명 알고리즘 — RS256·ES256 중에서만(none·HS*는 설정할 수 없음) |
| `oidc.button_label` | "" | 로그인 단추 문구(40자, 비우면 화면 언어의 "SSO로 로그인") |
| `oidc.provider_logout` | false | 켜면 OIDC 계정 로그아웃 뒤 제공자의 `end_session_endpoint`로(client_id·post_logout_redirect_uri) |
| `oidc.redirect_base` | "" | 리다이렉트 URI를 만들 공개 주소. https(루프백 개발 주소만 http). 비우면 `app.base_url`(그때 https여야 함) |
| `oidc.jwks_cache_ttl` | 1h | 디스커버리·JWKS 캐시(5분~24시간) |
| `oidc.jwks_stale_max` | 1h | 제공자에 닿지 않을 때 캐시 시간이 지난 값을 더 쓰는 상한(0~24시간) |
| `oidc.start_per_minute`※ | 20 | 로그인 시작 IP당 분당 |
| `oidc.test_per_minute`※ | 6 | 연결 시험 관리자당 분당 |
| `oidc.allow_local_dev`※ | false | 루프백 주소의 IdP 허용(개발·시험용) |

※ 화면에서 편집하지 않음(`config.ini`). 클라이언트 비밀은 설정 파일이 아니라 `app_secrets`(`oidc.client_secret`, SecretBox 암호화, 키는 secrets.ini — DB·백업에 없음).

## 4. 계정 결정

| 상황 | 결과 |
| --- | --- |
| (발급자, sub)로 연결된 계정이 있음 | 그 계정(승인 대기 → 안내, 거절 → 안내, 비활성 → 일반 실패, 활성 → 로그인). 로그인 ID 클레임이 바뀌어도 같은 계정 |
| 없음 + 로그인 ID 후보의 계정 없음 | **승인 대기 계정** 생성(auth_type=oidc, 이름·이메일(검증된 경우만)·external_* 한 번), 세션 없이 "승인 대기 중" |
| 없음 + 같은 ID의 oidc 계정, 아직 연결 전(CSV·인증 방식 전환으로 미리 만듦) | 이 로그인으로 **연결**(`user.external_link`) 후 로그인 |
| 없음 + 같은 ID의 계정이 local·ldap·다른 sub에 연결된 oidc | **거부**(일반 실패 E-SSO-DENIED, 감사 `reason=login_id_conflict`, 관리자 알림 `auth_identity_mismatch`) |
| 로그인 ID 클레임이 없거나 X06에 맞지 않음 | 거부(`invalid_login_id`) |
| 이메일만 같음 | 연결하지 않음 — 위 규칙대로(새 승인 대기 계정이 생김) |
| `email_verified`가 false | 이메일을 비움(`email_localpart`도 쓰지 않음) |

제공자 쪽에서 사용자를 비활성화해도 앱은 알 수 없다(주기 동기화 없음) — 퇴사자는 앱에서도 비활성화한다. ID·액세스 토큰은 로그인 요청 안에서만 쓰고 저장하지 않는다.

## 5. 보안 동작

- **state**(256비트): 로그인 전 세션 ID 해시에 묶어 서버 쪽 파일(`<작업 폴더>/oidc/state/`, 파일 이름 = state의 해시)에 10분, **한 번만**(원자적 이름 바꾸기로 꺼냄). 다른 브라우저·재사용·만료는 E-SSO-STATE.
- **PKCE S256**·**nonce**(ID 토큰과 상수 시간 비교). 코드 교환은 서버가 토큰 엔드포인트로.
- **ID 토큰**: RS256·ES256만(none·HS* 거부 — 키 혼동), JWKS 키만 사용(토큰의 jwk·jku 무시), kid 선택, RSA 2048비트 이상, crit 거부, iss 정확히·aud(여럿이면 azp)·exp/iat/nbf(오차 60초)·sub. 서명 검증은 `openssl_verify` 직접 구현(`Services\Auth\Oidc\Jose`, RFC 7515 예시 벡터로 시험 — 새 패키지 없음).
- **JWKS**: 캐시, 모르는 kid면 한 번 새로 받기(60초에 한 번까지), 실패 때 오래된 캐시를 `oidc.jwks_stale_max`까지.
- **외부 요청**: https만, 연결 5초·전체 10초·응답 1 MiB, 리다이렉트는 GET에서 2번까지(매번 같은 검사), POST는 따라가지 않음. 주소 판정은 P25/P26B 규칙(`HostPolicy::classify`) — 링크 로컬·메타데이터·멀티캐스트는 늘 거부, 루프백은 `oidc.allow_local_dev`일 때만, **발급자보다 안쪽 주소로 가지 않음**(발급자가 공인이면 엔드포인트·리다이렉트도 공인만, 사내 IdP(사설 주소)면 사설까지). 판정한 주소로 고정해 접속(DNS 리바인딩 방지).
- **세션 고정 방지**: 로그인 성공 때 세션 ID·CSRF 토큰을 새로 만든다. **열린 리다이렉트 방지**: 돌아갈 곳(`return`)은 앱 안 경로만(`Navigation::safeNext`).
- **요청 제한**: 로그인 시작 IP당 분당 20회(E-SSO-RATE), IP 차단(P10) 중이면 콜백도 거부.
- **CSP**: SSO 시작은 링크(GET → 302)라 영향 없음. 제공자 로그아웃을 켜면 로그아웃 폼의 302가 IdP로 가도록 `form-action`에 그 출처 하나만 더한다.
- 클라이언트 비밀·코드·state·nonce·토큰은 로그·감사 로그·오류·시험 출력에 남기지 않는다.

### 로그인 화면 오류 코드(문구는 하나 — "SSO 로그인에 실패했습니다", 사유는 감사 로그 `auth.login_failed`의 `reason`)

| 코드 | 뜻 | 감사 reason 예 |
| --- | --- | --- |
| E-SSO-STATE | state 없음·모름·재사용·만료·다른 브라우저 | `oidc_state_invalid`, `oidc_state_missing` |
| E-SSO-IDP | 제공자가 오류를 돌려줌(동의 거부 등) | `oidc_idp_error.access_denied` |
| E-SSO-TOKEN | 코드 교환 실패·ID 토큰 검증 실패 | `oidc_token_error.invalid_grant`, `oidc_id_token.nonce`, `oidc_id_token.bad_signature` |
| E-SSO-CONFIG | 설정 빠짐·비밀 없음·디스커버리 불일치·금지 주소 | `oidc_issuer_mismatch`, `oidc_secret_missing`, `oidc_blocked_address` |
| E-SSO-UNAVAILABLE | 제공자에 닿지 않음·시간 초과·응답 너무 큼 | `oidc_unavailable`, `oidc_timeout`, `oidc_too_large` |
| E-SSO-RATE | 요청 제한·IP 차단 | `oidc_start_rate` |
| E-SSO-DENIED | 계정 결정에서 거부(충돌·비활성·잘못된 로그인 ID) | `login_id_conflict`, `invalid_login_id` |

## 6. 장애 대응

- 제공자 장애: SSO 로그인만 안 된다. **로컬 관리자는 로그인 화면의 ID·비밀번호로 로그인**한다. 연결 시험·점검 화면(SSO(OIDC) 항목)으로 원인을 본다.
- 클라이언트 비밀 만료(Entra ID는 최대 2년): 제공자에서 새 비밀을 만들고 설정 화면에서 "비밀 교체".
- 키 교체(제공자 서명 키 회전): 자동(모르는 kid면 JWKS를 새로 받음).
- 암호화 키를 바꿨거나 잃음: 점검 화면 "클라이언트 비밀을 읽을 수 없습니다" → 비밀을 다시 입력.

## 7. 실제 IdP 점검표(P28C는 가짜 IdP로만 시험 — 운영 전 확인)

- [ ] 연결 시험 3단계 통과(디스커버리 issuer, JWKS 키 수·알고리즘, 토큰 엔드포인트 4xx)
- [ ] 처음 로그인하는 사람 → "승인 대기" 안내, 사용자 관리에 승인 대기 1건(인증 방식 OIDC(SSO))
- [ ] 승인 → 로그인 → 내 정보 인증 방식 OIDC(SSO), 이름·이메일이 제공자 값
- [ ] 제공자에서 이름을 바꾼 뒤 재로그인 → 앱 이름 그대로
- [ ] 로그인 ID가 같은 로컬 계정이 있으면 거부(E-SSO-DENIED), 로컬 계정은 그대로
- [ ] 로그아웃 → 앱만 로그아웃(제공자 세션 유지) / 제공자 로그아웃을 켜면 제공자 로그아웃 화면 → 로그인 화면으로 돌아옴
- [ ] 브라우저 뒤로 가기로 콜백 주소를 다시 열면 E-SSO-STATE
- [ ] 제공자 장애 흉내(방화벽으로 막기) → E-SSO-UNAVAILABLE, 로컬 관리자 로그인 가능
- [ ] Entra ID: 클라이언트 비밀 만료일 기록, Google: `email_verified` 확인
- [ ] 감사 로그 `auth.login_failed`에 사유만, 코드·토큰 없음
