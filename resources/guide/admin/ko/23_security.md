---
key: security
title: 보안 운영
order: 23
summary: 비밀 값과 암호화 키 보관, TLS 검증 정책, 세션·잠금 정책, 되돌릴 수 없는 작업의 확인 절차, 하드닝 점검
keywords: 보안, 암호화 키, secrets.ini, 키 교체, 키 분실, TLS, 인증서 검증, 사내 CA, 세션, 계정 잠금, IP 차단, 비밀번호 정책, 재인증, 하드닝, create_admin
related: backup-restore, settings, monitoring, audit-log, install, external-auth, repository
audience: admin
---
## 무엇을 다루나요

운영자가 지켜야 할 보안 규칙을 한곳에 모았습니다. 비밀 값을 어디에 두는지, 암호화 키를 어떻게 만들고 바꾸는지, 외부 연결의 인증서를 어떻게 검증하는지, 로그인 잠금과 세션, 되돌릴 수 없는 작업의 확인 절차, 운영 전 하드닝 점검을 다룹니다.

## 비밀 값과 암호화 키

### 어디에 두나요

- 비밀 값은 config 폴더의 secrets.ini 또는 환경 변수에만 둡니다. 환경 변수(`PJG_ENCRYPTION_KEY`처럼 `PJG_` + 키 이름 대문자)가 파일보다 우선합니다.
- secrets.ini에는 암호화 키(현재 키와 교체 중인 이전 키), SMTP 비밀번호, LDAP 비밀번호가 들어갑니다. 이 파일은 백업 묶음에 들어가지 않고, 설정 관리 화면에도 보이지 않습니다.
- 파일 권한은 웹 서버 계정(과 워커 계정)만 읽을 수 있게 좁힙니다. 웹 루트(public) 안에 두면 안 됩니다.

### 암호화 키가 지키는 것

암호화 키는 DB에 저장하는 다음 비밀을 암호화합니다(AES-256-GCM). DB에는 암호문만 있고, 화면·로그·감사 로그에는 "설정됨/없음/읽을 수 없음" 상태만 나옵니다.

- 프로젝트별 저장소 연결의 자격 증명(토큰·비밀번호)
- LDAP/AD 검색 계정 비밀번호, OIDC 클라이언트 비밀, SAML SP 개인 키와 인증서

### 키 만들기와 교체

키가 없으면 서버에서 만듭니다. 키 값은 화면이나 로그에 출력하지 않고 파일에만 씁니다. 이미 키가 있으면 거부합니다(덮어쓰면 저장된 비밀을 풀 수 없기 때문).

```
php tools/make_secret_key.php
```

키를 바꿀 때는 `--rotate`를 씁니다.

```
php tools/make_secret_key.php --rotate
```

1. 새 키가 현재 키가 되고, 지금 키는 이전 키로 옮겨집니다. 두 키로 모두 풀 수 있으므로 바로 동작합니다.
2. 웹 서버와 워커를 다시 시작해 새 키를 읽게 합니다.
3. 프로젝트마다 저장소 연결을 **다시 저장**하면 새 키로 다시 암호화됩니다. LDAP/AD·OIDC·SAML의 비밀도 다시 입력해 저장합니다.
4. 모두 다시 저장한 뒤 secrets.ini에서 이전 키를 지우고 다시 시작합니다.

키를 바꾼 뒤에는 [백업과 복원](guide:backup-restore)의 {{ui:icon.action.export_key}}로 키 파일을 새로 받아 두세요.

### 키를 잃으면

저장된 비밀은 **복구할 수 없습니다.**

- 저장소 연결은 "읽을 수 없음"으로 보이고 가져오기가 `repo_secret_unreadable`로 실패합니다. 주기 분석은 건너뛰고 관리자에게 알립니다.
- LDAP/AD·OIDC·SAML의 비밀도 읽을 수 없어 점검 탭에 경고·문제로 나옵니다.

복구 절차: 새 키를 만들고, 각 프로젝트의 저장소 자격 증명과 외부 인증 비밀을 다시 입력합니다. 이를 막으려면 키 파일(.pjgkey)과 암호문구를 백업 묶음과 **다른 곳**에 보관하세요.

## TLS 인증서 검증

외부 연결은 인증서를 검증하며, **검증을 끄는 설정은 없습니다.**

- **저장소(Git)**: https를 씁니다. http는 허용 호스트에 "HTTP 허용"으로 등록한 호스트만 쓸 수 있고 화면에 경고가 나옵니다. 사내 CA는 config.ini의 `repository.ca_cert_file`에 지정합니다(지정하면 그 파일만 신뢰).
- **Perforce SSL**: 서버 지문을 자동으로 신뢰하지 않습니다. 연결 테스트가 보여 주는 지문을 서버 관리자에게 확인한 뒤 연결 설정에 넣은 값만 신뢰합니다.
- **허용 호스트 연결 테스트**: 인증서 만료일·신뢰·이름 일치를 확인하고, 만료가 {{limit:notification.cert_expiry_warn}} 안이면 표에 경고합니다.
- **LDAP/AD**: `ldaps://`를 쓰거나, `ldap://`이면 `ldap.use_starttls`를 켜야 합니다(평문 금지). 사내 CA는 `ldap.ca_file`, 비우면 운영체제 신뢰 목록을 씁니다. 호스트 이름 검증도 꺼지지 않습니다.
- **OIDC**: 발급자와 모든 요청이 https여야 합니다. 사내 CA는 저장소와 같은 `repository.ca_cert_file`을 씁니다.
- **SAML**: IdP 응답의 XML 서명은 라이브러리로만 검증합니다. IdP 인증서 만료가 다가오면 관리자 알림이 옵니다.

개발용 예외 `repository.allow_local_dev`, `ldap.allow_local_dev`, `oidc.allow_local_dev`, `saml.allow_local_dev`는 루프백 주소 접속을 허용합니다. **운영에서는 모두 꺼 둡니다.**

운영 환경에서 확인할 것: 실제 사내 CA·LDAP/AD·IdP와의 인증서 검증. 각 탭의 연결 시험·설정 확인으로 점검합니다.

## 세션과 로그인 잠금

다음 값은 config.ini에서만 바꿉니다(설정 관리 화면에 없음). 괄호 안은 지금 값입니다.

- **세션 만료**: 활동이 없으면 {{limit:auth.session_idle}} 뒤에 로그아웃됩니다.
- **계정 잠금**: 연속 {{limit:auth.lockout_max_failures}}회 로그인에 실패하면 {{limit:auth.lockout_duration}} 동안 잠깁니다.
- **IP 차단**: 같은 IP에서 {{limit:auth.ip_failure_window}} 안에 {{limit:auth.ip_max_failures}}회 실패하면 {{limit:auth.ip_block_duration}} 동안 막습니다.
- **비밀번호 정책(로컬 계정)**: {{limit:auth.password_min_length}}자 이상, 대문자·소문자·숫자·특수문자 중 {{limit:auth.password_min_char_classes}}종 이상.
- **임시 비밀번호**: 관리자가 발급한 임시 비밀번호는 {{limit:auth.temp_password_expiry}} 뒤에 만료되고, 첫 로그인 때 변경해야 합니다.

잠긴 계정은 사용자 관리에서 풀거나, 관리자 자신이 잠겼으면 서버에서 복구합니다(감사 로그에 `cli.user_unlock`, `cli.user_password_reset`).

```
php tools/user_cli.php status <로그인 ID>
php tools/user_cli.php unlock <로그인 ID>
php tools/user_cli.php reset-password <로그인 ID>
```

복원하면 모든 세션이 끊기고 모든 사용자가 다시 로그인합니다.

## 되돌릴 수 없는 작업의 확인

위험한 작업은 실수로 실행되지 않도록 한 번 더 확인합니다.

- **비밀번호 재입력**: 백업 내려받기·삭제, 키 내보내기, 복원. 사용자당 분당 5회까지입니다.
- **확인 문구 입력**: 복원("{{ui:backups.restore.confirm_word}}"), 프로젝트 삭제와 Run 상세 삭제(프로젝트 이름), 규칙 전체 되돌리기와 일괄 변경(도구 이름).
- **확인 대화상자**: 정리 작업 실행, 허용 호스트 삭제, 외부 인증 비밀 삭제, 백업 취소 등.
- **보호 규칙**: 마지막 성공 백업은 지울 수 없고, 활성 로컬 관리자는 늘 한 명 이상 남아야 합니다(`last_admin`).

## 하드닝 점검

운영을 시작하기 전과 설정을 바꾼 뒤 서버에서 점검합니다.

```
php tools/create_admin.php --check-only
```

결과는 `[OK]` 충족, `[WARN]` 미충족(운영 전에 고침), `[INFO]` 안내입니다. 주요 항목:

1. **관리자 초기 비밀번호**: 최초 관리자의 고정 초기 비밀번호를 바꿨는지. 변경을 강제하지 않으므로, 외부에 공개하는 서버라면 직접 바꿉니다.
2. **PHP**: display_errors Off, expose_php Off, log_errors On.
3. **Apache**: public 폴더만 웹에 노출, 보안 헤더, 서버 버전 숨김, HTTP를 HTTPS로 리다이렉트, 쓰지 않는 모듈과 /server-status 제거.
4. **XAMPP 샘플·관리 페이지**: dashboard, phpMyAdmin, webdav 등 지우기.
5. **앱 설정**: `app.env`는 prod, `security.require_https`는 켜기, `security.hsts_max_age`는 180일 이상, `auth.bcrypt_cost`는 10 이상.
6. **데이터 위치와 public 폴더**: DB·로그·업로드가 public 밖에 있고, public에 불필요한 파일(phpinfo, sqlite, ini, log, zip, bak)이 없는지.

옵션: `--resolve-local`(호스트 이름 대신 127.0.0.1로 접속), `--skip-http`(HTTP 점검 생략), `--insecure`(인증서 검증 끔 — 자체 서명 인증서를 쓰는 개발 PC에서만). 서버 자신에서 점검하면 로컬 전용으로 막은 페이지도 열려 보이므로, HTTP 항목은 다른 PC에서 한 번 더 확인합니다.

운영 환경에서 확인할 것: Linux·Apache 운영 서버에서 점검 결과와 HTTP 항목. PHP·Apache·Node.js·Python 보안 패치는 분기마다 점검합니다.

## 감사 로그와 헬스체크

- 관리자 작업은 [감사 로그](guide:audit-log)에 해시 체인으로 남습니다. 정기적으로 {{ui:audit.integrity}}을 돌리고 마지막 해시를 DB 밖에 기록해 두세요.
- 헬스체크(/health)는 로그인 없이 응답하므로 허용 IP(`health.allowed_ips`)를 감시 서버로만 좁힙니다. 비워 두면 모두 거부합니다. 프록시 뒤에서는 프록시에서도 막습니다([시스템 점검과 모니터링](guide:monitoring)).

## 프록시 뒤에서 운영할 때(신뢰 프록시)

앞단에 역방향 프록시나 부하 분산기를 두면 앱이 보는 접속 주소가 프록시 주소가 되고, https는 프록시에서 끝날 수 있습니다.

- {{ui:admin_system.title}} > {{ui:config_settings.title}} > {{ui:config_settings.group.monitoring}}의 "{{ui:config_settings.key.security.trusted_proxies.label}}"(`security.trusted_proxies`)에 프록시 주소를 쉼표로 넣습니다(CIDR 가능, IPv6 가능).
- 그 주소에서 온 요청만 X-Forwarded-For(오른쪽 — 가까운 쪽부터 프록시를 건너뛴 첫 주소)와 X-Forwarded-Proto를 믿습니다. 이 값이 로그인 시도 제한, 감사 로그의 IP, 헬스체크 허용 IP, HTTPS 강제와 HSTS 판정에 똑같이 쓰입니다.
- 목록 밖에서 온 같은 헤더는 무시합니다(위조 방지). 프록시가 아닌 주소를 넣으면 사용자가 주소를 꾸밀 수 있으니 프록시 주소만 넣습니다.
- 비워 두면(기본) 헤더를 믿지 않습니다. 프록시 뒤인데 비어 있으면 {{ui:admin_checks.title}} 탭의 "{{ui:env.item.trusted_proxies}}" 줄이 "확인 필요"를 보여 줍니다.

## 관련 절

- [백업과 복원](guide:backup-restore)
- [설정 관리](guide:settings)
- [시스템 점검과 모니터링](guide:monitoring)
- [감사 로그](guide:audit-log)
- [설치](guide:install)
- [외부 인증 개요](guide:external-auth)
- [저장소 연동](guide:repository)
