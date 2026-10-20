# LDAP/AD 로그인과 승인 대기 (P28B, 명세 19.2)

외부 인증의 공통 기반(인증 방식 목록·승인 대기 계정·관리자 승인·인증 방식 전환·로그인 화면 SSO 자리) 위에 LDAP/AD 로그인을 붙였다.
OIDC(P28C)·SAML(P28D)은 같은 기반(`Services\Auth\ExternalAccountProvisioner`, `AuthProviderRegistry`, `App::ssoProviders()`)에 제공자만 더한다.

## 1. 설치 — PHP ldap 확장

- XAMPP: `C:\xampp\php\php.ini`에서 `;extension=ldap`의 `;`를 지우고 Apache를 다시 시작한다(워커·명령줄도 같은 php.ini). `php -m`에 `ldap`가 보여야 한다.
- 확장이 없으면 LDAP을 켜도 로그인할 수 없고(인증 서비스 오류), 설정 관리 "LDAP/AD" 탭과 시스템 점검 화면이 "PHP ldap 확장이 없습니다"를 보인다. LDAP을 쓰지 않으면 확장은 없어도 된다(시험은 가짜 디렉터리로 돈다).
- 마이그레이션 `026_external_auth` 적용이 필요하다(`php tools/migrate.php`). 적용 전에는 LDAP이 켜져 있어도 꺼진 것처럼 동작한다(로그인·세션은 그대로).

## 2. 설정 키 — 시스템 관리 > 설정 관리 > LDAP/AD 탭(허용 목록 묶음 auth_ldap), 계정 승인 탭(auth)

| 키 | 기본 | 설명 |
|---|---|---|
| `ldap.enabled` | false | LDAP/AD 로그인 사용 |
| `ldap.servers` | "" | 서버 1~3개(쉼표, 앞에서부터 — **연결 실패만** 다음 서버로, 자격 증명 오류는 넘어가지 않음). `ldaps://` 권장, `ldap://`는 `use_starttls`가 켜져 있어야 함(평문 금지) |
| `ldap.use_starttls` | false | `ldap://`에서 StartTLS |
| `ldap.ca_file` | "" | 사내 CA 인증서(PEM) 절대 경로. 비우면 운영체제 신뢰 목록. **인증서 검증을 끄는 설정은 없다**(호스트 이름 검증 포함, `LDAP_OPT_X_TLS_DEMAND`) |
| `ldap.base_dn` | "" | 사용자 검색 위치 |
| `ldap.user_filter` | `(&(objectClass=user)(sAMAccountName=%s))` | `%s` 정확히 하나(로그인 ID를 RFC 4515로 이스케이프해 넣음), `(`로 시작·괄호 짝 |
| `ldap.bind_dn` | "" | 검색 계정 DN. 화면에서 고치지만 **감사 로그·변경 이력에는 `***`만** |
| `ldap.attr_login` | sAMAccountName | 앱 로그인 ID(OpenLDAP `uid`) |
| `ldap.attr_name` / `attr_email` | displayName / mail | 승인 대기 계정을 만들 때 **한 번만** 가져옴 |
| `ldap.attr_id` | objectGUID | 안정 식별자(OpenLDAP `entryUUID`). DN은 쓰지 않는다(이름이 바뀔 수 있음) |
| `ldap.attr_disabled` | "" | AD `userAccountControl`(비트 2 ACCOUNTDISABLE면 거부). OpenLDAP은 비움 |
| `ldap.connect_timeout` / `search_timeout` | 5s / 10s | 서버별 연결·검색 시간 상한 |
| `auth.pending_max` | 50 | 승인 대기·거절 계정 수 상한(넘으면 새 요청을 받지 않고 관리자에게 하루 한 번 알림) |
| `auth.pending_retention_days` | 30 | 상태가 이 기간 동안 바뀌지 않은 대기·거절 계정은 정리 작업(`tools/cleanup.php`, 매일)이 지움(감사 로그에 건수만) |

- 모두 `restart = none`(로그인 때마다 설정에서 읽음), `machine_specific = false`. 저장 때와 기동 때 `ConfigSchema::ldapErrors()`가 검사한다.
- 예전 키(`server_url`, `attr_user_id`, `ca_cert_file`)는 config.ini 호환으로 남아 있고 새 키가 비었을 때만 쓴다(화면 편집 대상 아님).
- **검색 계정 비밀번호**는 설정 파일에 두지 않는다. 같은 탭의 별도 입력에서 저장하면 P25 `SecretBox`(AES-256-GCM, 키는 `secrets.ini`의 `encryption_key`)로 암호화해 DB 표 `app_secrets`(`ldap.bind_password`)에 둔다. 화면에는 저장됨/없음/읽을 수 없음만 보이고 값은 다시 보여 주지 않는다(교체·삭제만). 키가 바뀌거나 없으면 "읽을 수 없음" — 다시 입력한다. 백업(P27B)에는 암호화된 채 들어가고 키는 들어가지 않는다(키 가져오기 확인도 이 값을 함께 본다).

예시 — AD: `servers = ldaps://dc1.corp.example.com:636, ldaps://dc2.corp.example.com:636`, `base_dn = OU=Users,DC=corp,DC=example,DC=com`, 필터·속성은 기본값, `attr_disabled = userAccountControl`.
OpenLDAP: `user_filter = (&(objectClass=inetOrgPerson)(uid=%s))`, `attr_login = uid`, `attr_id = entryUUID`, `attr_disabled = ""`.

## 3. 로그인 분기 (`LoginService` + `Ldap\LdapLogin` + `ExternalAccountProvisioner`)

| 입력 ID의 앱 계정 | LDAP | 처리 |
|---|---|---|
| local | 무관 | 앱 비밀번호(기존 규칙 — 잠금 5회/15분, IP 차단). 디렉터리에 묻지 않는다(같은 ID 디렉터리 계정으로 **탈취·자동 전환 금지**) |
| ldap, 승인됨 | 켜짐 | 디렉터리 인증 → external_id가 비었으면 이 로그인으로 연결(관리자가 미리 만든 계정), 다르면 **거부**(ID 재사용 — 감사·알림) → 세션 |
| ldap, 승인 대기 | 켜짐 | 디렉터리 인증을 통과해야 "관리자 승인 대기 중" 안내(세션 없음) |
| ldap, 거절됨 | 켜짐 | 통과해야 "승인되지 않았습니다. 관리자에게 문의하세요"(세션 없음) |
| 없음 | 켜짐 | 디렉터리 인증 성공 → 로그인 ID 규칙(X06) 통과·상한 안이면 **승인 대기 계정 생성**(세션 없음) |
| ldap(또는 없음) | 꺼짐 | 일반 실패(디렉터리에 묻지 않음) |

- 디렉터리 절차: 검색 계정 바인드 → 필터로 검색(최대 2건 받아 **정확히 1건**일 때만, 2건 이상은 실패 + 감사 `ambiguous`) → 찾은 DN + 입력 비밀번호로 바인드 → 비활성(AD) 거부 → 공통 서비스.
- **빈 비밀번호·256바이트 초과는 디렉터리에 묻지 않고 실패**(익명 바인드를 성공으로 처리하는 서버 취약점 방지). 레퍼럴 따라가기 끔, 프로토콜 3.
- **열거 방지**: 없는 ID·틀린 비밀번호·검색 0건·2건 이상·비활성은 같은 문구(`login_failed`). 디렉터리에 닿지 않으면 "인증 서비스에 문제가 있습니다"(503, 로컬 관리자 로그인 안내는 하지 않음). 승인 대기·거절·상한 안내는 디렉터리 인증을 통과한 사람에게만 나온다.
- **속도 제한**: IP 차단(기존)은 LDAP에도. 앱의 계정 잠금은 LDAP 계정에 적용하지 않는다(디렉터리 정책). 대신 같은 로그인 ID로 `auth.ip_failure_window` 안에 `lockout_max_failures × 2`(기본 10)번 실패하면 **디렉터리에 묻지 않고** 429(디렉터리 계정 잠금 폭주 방지). 실패 기록은 로그인 ID의 해시(앞 32자)로만 남긴다.
- 관리자용 감사 로그 `auth.login_failed {login_id, reason}` — reason: `local_account`, `invalid_login_id`, `ambiguous`, `directory_disabled`, `identity_mismatch`, `identity_conflict`. 화면에는 구분하지 않는다. 비밀번호·DN·필터 원문은 로그·감사 로그·오류 메시지에 넣지 않는다.

## 4. 승인 대기 계정 (4A, 공통)

- `users.approval_status` approved(기존 모든 계정) | pending | rejected. **승인되지 않은 계정은 늘 비활성**(DB 제약) — 기존의 모든 "활성 사용자" 조건(권한·목록·검색·알림 대상·담당자)이 저절로 뺀다. 세션 미들웨어도 한 번 더 막는다. 역할 열 값은 효력 없음.
- 생성 때 이름·이메일·외부 식별자를 디렉터리에서 **한 번만** 가져온다. 이후 로그인에서 이름·이메일을 갱신하지 않는다(외부 값 동기화 경로 없음). 이름이 다른 사용자의 로그인 ID와 같으면 승인 화면에 "이름 확인" 배지 — 승인 전에 "정보 수정"으로 고친다.
- 관리자 화면(사용자 관리): "승인 대기 N건" 배지·보기(계정/승인 대기/거절됨), 행마다 로그인 ID·이름·이메일·인증 방식·요청 시각, **승인**(역할 user 기본, admin은 확인 대화상자를 한 번 더), **거절**(rejected로 남겨 같은 사람의 재요청이 쌓이지 않게), **삭제**(승인된 적 없는 계정만 — 승인된 계정은 지우지 않는다). 일괄 승인 없음. 동시 처리는 `updated_at`으로 막는다(409 `approval_conflict`).
- 알림(관리자): `account_pending`(하루 5건까지 한 건씩, 넘으면 그날 요약 1건), `account_pending_limit`(상한 도달, 하루 1회), `auth_identity_mismatch`. 종류별 켜기/끄기는 알림 설정에서.
- 감사: `user.pending_create`, `user.approve`(역할), `user.reject`, `user.pending_delete`, `user.pending_purge`(건수), `user.external_link`. 이메일 원문은 남기지 않는다.
- **관리자가 미리 만든 외부 계정은 승인 대상이 아니다**: CSV(P28A, `auth_type=ldap` — LDAP이 켜져 있을 때만)나 사용자 생성 API(`auth_type`), "인증 방식 변경"으로 만든 계정은 승인된 상태(비밀번호 없음)이고 첫 로그인 때 로그인 ID 일치로 연결만 한다.

## 5. 인증 방식 변경 (사용자 관리, 외부 방식이 켜져 있을 때만)

- `local → ldap`: 비밀번호·임시 비밀번호·잠금 정보를 지우고 external_id는 비워 둔다(첫 로그인 때 연결). `ldap → local`: 임시 비밀번호(24시간, 첫 로그인 때 변경)를 한 번만 보인다.
- 대상 세션 즉시 폐기, 감사 `user.auth_type_change {from, to}`. 자기 자신 금지, **마지막 활성 로컬 관리자는 바꿀 수 없다**(활성·비활성화·강등도 같은 규칙 — 로컬 관리자는 늘 한 명 이상, 디렉터리 장애 때도 들어올 수 있게).
- 외부 인증 계정의 이름·이메일은 앱에서 고친다(X05의 `ldap_managed` 폐지). 비밀번호 화면은 "조직의 디렉터리에서 변경하세요" 안내만.

## 6. 연결 시험 (시스템 관리 > 설정 관리 > LDAP/AD 탭 아래)

① 서버 연결(TLS) ② 검색 계정 바인드 ③ Base DN 읽기 ④ 시험용 로그인 ID 검색(선택, 비밀번호는 묻지 않음 — 찾은 DN·식별자·이메일은 가려서 보임). 앞 단계가 실패하면 뒤는 건너뜀. 사용자당 분당 `ldap.test_per_minute`(기본 6)회. 결과에 비밀번호·바인드 DN 원문은 없다.
시스템 점검 화면은 LDAP이 켜져 있을 때만 "LDAP/AD" 항목(확장 없음·설정 빠짐·검색 계정 비밀번호 없음/읽을 수 없음·CA 파일 없음·연결 불가)을 보인다.

## 7. 실제 서버 점검표 (운영 서버에서 관리자가 확인 — 이 단계에서는 가짜 디렉터리로만 확인했다)

1. `php -m`에 ldap, Apache 다시 시작 뒤 설정 관리 LDAP/AD 탭에 확장 경고가 없다.
2. 서버 주소(ldaps://)·CA 파일·Base DN·검색 계정 DN을 저장하고 검색 계정 비밀번호를 저장한다 → "저장됨".
3. 연결 시험(시험용 로그인 ID 넣고): 4단계 모두 통과, 찾은 DN·식별자 형식(AD는 GUID 표기)이 맞다.
4. CA 파일을 비우거나 틀린 파일로 바꾸면 연결이 **실패**한다(검증이 켜져 있음). 서버 인증서의 이름과 다른 주소(IP 등)로 바꿔도 실패한다.
5. 앱에 없는 디렉터리 사용자로 로그인 → "승인 대기" 안내, 관리자 사용자 관리에 승인 대기 1건·알림 → 승인 → 그 사람이 로그인된다.
6. 틀린 비밀번호·없는 ID → 같은 문구. 빈 비밀번호는 로그인 단추가 막는다(서버도 거부).
7. AD에서 계정을 사용 안 함으로 바꾸면 다음 로그인부터 거부된다(주기 동기화 없음 — 앱 계정은 관리자가 따로 비활성화).
8. 같은 ID를 디렉터리에서 지우고 다른 사람에게 다시 만들어 주면 로그인이 거부되고 관리자에게 "외부 신원 불일치" 알림이 간다.
9. 서버 1을 멈추면 서버 2로 로그인된다. 검색 계정 비밀번호를 틀리게 하면 서버 2로 넘어가지 않고 "검색 계정 바인드 실패".
10. 로컬 관리자(최초 관리자)가 디렉터리 장애 중에도 앱 비밀번호로 로그인된다.
