---
key: auth-ldap
title: LDAP/AD 로그인
order: 9
summary: LDAP/AD 로그인 준비(ldap 확장·마이그레이션), 설정 관리 LDAP/AD 탭 항목, 검색 계정 비밀번호, 연결 시험, 로그인 동작, 흔한 오류, 운영 점검표
keywords: LDAP, AD, Active Directory, OpenLDAP, ldaps, StartTLS, CA 인증서, Base DN, 검색 필터, 검색 계정, 바인드, objectGUID, entryUUID, 연결 시험
related: external-auth, accounts, settings, security
audience: admin
---

## 무엇을 하는 기능인가요

사용자가 로그인 화면의 ID·비밀번호 칸에 조직 계정(Active Directory·OpenLDAP)을 넣으면, 서버가 디렉터리 서버에 직접 확인해 로그인시킵니다. 앱에 없는 사람이 처음 로그인하면 승인 대기 계정이 됩니다. 승인 대기·로컬 관리자 보존 같은 공통 규칙은 [외부 인증 개요](guide:external-auth)를 먼저 읽으세요.

## 준비

1. **PHP ldap 확장**: XAMPP는 `C:\xampp\php\php.ini`에서 `;extension=ldap` 줄의 `;`를 지우고 Apache를 다시 시작합니다. 워커와 명령줄도 같은 php.ini를 씁니다. `php -m`에 `ldap`가 보여야 합니다. 확장이 없으면 LDAP/AD 탭과 [점검](/admin/checks) 화면에 "PHP ldap 확장이 없습니다"가 나오고 LDAP 로그인은 되지 않습니다. Linux 서버의 확장 설치 방법은 운영 환경에서 확인할 것.
2. **마이그레이션**: `php tools/migrate.php`로 026까지 적용합니다. 적용 전에는 켜도 꺼진 것처럼 동작합니다.
3. 디렉터리 관리자에게 받을 것: 서버 주소(ldaps 권장), 사내 CA 인증서(PEM), 사용자를 찾을 Base DN, 읽기 전용 **검색 계정**의 DN과 비밀번호.

## 설정 관리 LDAP/AD 탭

시스템 관리 > [설정 관리](/admin/config-settings) > **{{ui:config_settings.group.auth_ldap}}** 탭에서 넣고, 아래 **설정 항목 변경 확인** 단추로 저장합니다. 모두 재시작 없이 다음 로그인부터 적용됩니다.

- **{{ui:config_settings.key.ldap.enabled.label}}** (`ldap.enabled`): 켜고 끕니다. 켜려면 서버 주소와 Base DN이 있어야 합니다.
- **{{ui:config_settings.key.ldap.servers.label}}** (`ldap.servers`): 쉼표로 1~3개. 앞에서부터 시도하고 **연결 실패일 때만** 다음 서버로 넘어갑니다(비밀번호 오류는 넘어가지 않음). `ldaps://` 주소를 권장합니다.
- **{{ui:config_settings.key.ldap.use_starttls.label}}** (`ldap.use_starttls`): `ldap://` 주소는 이것을 켜야 저장됩니다. 평문 전송은 허용하지 않습니다.
- **{{ui:config_settings.key.ldap.ca_file.label}}** (`ldap.ca_file`): 사내 CA 인증서(PEM) 절대 경로. 비우면 운영체제의 신뢰 목록을 씁니다. **인증서 검증(호스트 이름 포함)을 끄는 설정은 없습니다.**
- **{{ui:config_settings.key.ldap.base_dn.label}}** (`ldap.base_dn`): 사용자를 찾을 위치.
- **{{ui:config_settings.key.ldap.user_filter.label}}** (`ldap.user_filter`): `%s`가 정확히 하나 있어야 합니다. 로그인 ID를 이스케이프해 그 자리에 넣습니다.
- **{{ui:config_settings.key.ldap.bind_dn.label}}** (`ldap.bind_dn`): 검색 계정. 감사 로그·변경 이력에는 값 대신 가린 표시만 남습니다.
- **{{ui:config_settings.key.ldap.attr_login.label}}** (`ldap.attr_login`): 앱 로그인 ID가 될 속성.
- **{{ui:config_settings.key.ldap.attr_name.label}}** (`ldap.attr_name`), **{{ui:config_settings.key.ldap.attr_email.label}}** (`ldap.attr_email`): 승인 대기 계정을 만들 때 한 번만 가져옵니다.
- **{{ui:config_settings.key.ldap.attr_id.label}}** (`ldap.attr_id`): 이름이 바뀌어도 그대로인 값. DN은 바뀔 수 있어 쓰지 않습니다.
- **{{ui:config_settings.key.ldap.attr_disabled.label}}** (`ldap.attr_disabled`): AD는 `userAccountControl`(사용 안 함 비트가 켜지면 거부), OpenLDAP은 비웁니다.
- **{{ui:config_settings.key.ldap.connect_timeout.label}}** (`ldap.connect_timeout`, 지금 {{limit:ldap.connect_timeout}}), **{{ui:config_settings.key.ldap.search_timeout.label}}** (`ldap.search_timeout`, 지금 {{limit:ldap.search_timeout}}): 서버마다 기다리는 시간.

예시 값:

- AD: 서버 `ldaps://dc1.example.local:636, ldaps://dc2.example.local:636`, Base DN `OU=Users,DC=example,DC=local`, 필터·속성은 기본값, 비활성 속성 `userAccountControl`
- OpenLDAP: 필터 `(&(objectClass=inetOrgPerson)(uid=%s))`, 로그인 ID 속성 `uid`, 안정 식별자 속성 `entryUUID`, 비활성 속성은 비움

### 검색 계정 비밀번호

같은 탭의 **{{ui:ldap.secret.label}}** 칸에 넣고 **{{ui:ldap.secret.save}}**를 누릅니다. 설정 저장과 따로 저장되며, 암호화해 DB에 두고 다시 보여 주지 않습니다. 상태는 저장됨 / 없음 / 읽을 수 없음으로만 보입니다. 바꿀 때는 **{{ui:ldap.secret.replace}}**를 씁니다(`auth.ldap_secret_set`, 삭제는 `auth.ldap_secret_delete`). 백업에는 암호화된 채 들어가고 암호화 키는 들어가지 않습니다.

## 연결 시험

탭 아래의 **{{ui:ldap.test.run}}**은 **저장한 설정**으로 다음을 차례로 확인합니다. 앞 단계가 실패하면 뒤는 건너뜁니다.

1. {{ui:ldap.test.step.connect}}
2. {{ui:ldap.test.step.service_bind}}
3. {{ui:ldap.test.step.base_dn}}
4. {{ui:ldap.test.step.user_search}} — **{{ui:ldap.test.login_label}}**를 넣었을 때만. 비밀번호는 묻지 않고, 찾은 DN·식별자·이메일은 가려서 보여 줍니다.

관리자마다 분당 {{limit:ldap.test_per_minute}}회까지 돌릴 수 있습니다. LDAP이 켜져 있으면 [점검](/admin/checks) 화면에도 LDAP/AD 항목(확장, 설정 빠짐, 검색 계정 비밀번호, CA 파일, 연결)이 나옵니다.

## 로그인 동작

- **로컬 계정**: 늘 앱 비밀번호로 확인합니다. 디렉터리에 묻지 않습니다.
- **앱에 없는 ID**: 디렉터리 인증에 성공하면 승인 대기 계정을 만듭니다(세션 없음).
- **승인된 LDAP 계정**: 디렉터리 인증 뒤 안정 식별자가 저장된 값과 같아야 로그인됩니다. 관리자가 미리 만든 계정은 첫 로그인 때 식별자를 연결합니다.
- **LDAP이 꺼져 있으면** LDAP 계정은 일반 실패입니다.

디렉터리 절차는 검색 계정 바인드 → 필터로 검색(정확히 1건일 때만) → 찾은 DN과 입력한 비밀번호로 바인드 → 비활성 확인 순서입니다. 빈 비밀번호와 지나치게 긴 비밀번호는 디렉터리에 묻지 않고 실패시킵니다(익명 바인드 오인 방지).

### 보안상 같은 문구

없는 ID, 틀린 비밀번호, 검색 0건, 2건 이상, AD에서 사용 안 함인 계정은 모두 같은 실패 문구입니다. 디렉터리에 닿지 않으면 "{{ui:error.auth_service_unavailable}}"가 나옵니다.

### 실패 제한

- IP 차단 규칙은 LDAP에도 적용됩니다.
- 앱의 계정 잠금은 LDAP 계정에 적용하지 않습니다(디렉터리 정책을 따름). 대신 같은 로그인 ID로 {{limit:auth.ip_failure_window}} 안에 계정 잠금 기준({{limit:auth.lockout_max_failures}}회)의 두 배만큼 실패하면 잠시 디렉터리에 묻지 않고 거부합니다. 디렉터리 계정이 잠기는 것을 막기 위해서입니다.

## 흔한 오류

연결 시험의 실패 사유로 원인을 좁힙니다.

- "{{ui:ldap.reason.unavailable}}": 주소·포트·방화벽, 서버 인증서의 이름과 주소가 맞는지, CA 파일을 확인합니다. IP 주소로 넣으면 인증서 이름과 달라 실패할 수 있습니다.
- "{{ui:ldap.reason.service_bind_failed}}": 검색 계정 DN·비밀번호를 확인합니다. 이 오류는 다음 서버로 넘어가지 않습니다.
- "{{ui:ldap.reason.search_failed}}": Base DN과 속성 이름을 확인합니다.
- "{{ui:ldap.reason.ambiguous}}": 필터가 너무 넓습니다.
- "{{ui:ldap.reason.not_found}}": Base DN 범위나 필터의 속성 이름(AD `sAMAccountName`, OpenLDAP `uid`)을 확인합니다.
- "{{ui:ldap.reason.secret_unreadable}}": 서버 암호화 키가 바뀌었습니다. 비밀번호를 다시 저장합니다.

로그인 실패의 사유는 감사 로그 `auth.login_failed`에 남습니다: `local_account`(로컬 계정), `invalid_login_id`(로그인 ID 규칙에 맞지 않음), ambiguous(2건 이상), `directory_disabled`(디렉터리에서 사용 안 함), `identity_mismatch`(같은 ID인데 식별자가 다름 — ID 재사용 의심, 관리자 알림), `identity_conflict`(식별자가 다른 계정에 이미 연결됨). 비밀번호·DN·필터 원문은 남기지 않습니다.

## 운영 점검표

이 기능은 가짜 디렉터리로만 시험했습니다. 아래 항목은 운영 환경에서 확인할 것.

1. `php -m`에 ldap가 있고, Apache 재시작 뒤 LDAP/AD 탭에 확장 경고가 없다.
2. 서버 주소(ldaps)·CA 파일·Base DN·검색 계정 DN을 저장하고 검색 계정 비밀번호가 "저장됨"이다.
3. 시험용 로그인 ID를 넣은 연결 시험 4단계가 모두 통과하고, 찾은 DN·식별자 형식(AD는 GUID)이 맞다.
4. CA 파일을 비우거나 틀린 파일로 바꾸면 연결이 실패한다(검증이 켜져 있음). 인증서 이름과 다른 주소로 바꿔도 실패한다.
5. 앱에 없는 디렉터리 사용자로 로그인하면 승인 대기 안내가 나오고, 사용자 관리에 승인 대기 1건과 알림이 생긴다. 승인하면 그 사람이 로그인된다.
6. 틀린 비밀번호와 없는 ID가 같은 문구다. 빈 비밀번호는 로그인 단추가 막는다.
7. AD에서 계정을 사용 안 함으로 바꾸면 다음 로그인부터 거부된다.
8. 같은 ID를 지우고 다른 사람에게 다시 만들어 주면 로그인이 거부되고 "외부 신원 불일치" 알림이 간다.
9. 첫 서버를 멈추면 두 번째 서버로 로그인된다. 검색 계정 비밀번호를 틀리게 하면 넘어가지 않고 바인드 실패가 나온다.
10. 디렉터리 장애 중에도 로컬 관리자가 앱 비밀번호로 로그인된다.

## 관련 절

- [외부 인증 개요](guide:external-auth)
- [계정 관리](guide:accounts)
- [설정 관리](guide:settings)
- [보안](guide:security)
