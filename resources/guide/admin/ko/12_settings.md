---
key: settings
title: 설정 관리
order: 12
summary: 운영 값을 config.ini를 열지 않고 화면에서 확인하고 바꾸는 방법, 출처와 우선순위, 저장·충돌·되돌리기
keywords: 설정 관리, 설정, config.ini, settings.local.ini, 환경 변수, 출처, 기본값, 변경 이력, 충돌, 경로 확인, 카테고리 탭
related: retention-disk, backup-restore, notifications-admin, monitoring, audit-log, repository, security
audience: admin
---
## 무엇을 하는 화면인가요

**시스템 관리 > {{ui:config_settings.title}}**([/admin/config-settings](/admin/config-settings))은 운영 중에 자주 바꾸는 값을 화면에서 확인하고 바꾸는 곳입니다. config.ini 전체를 고치는 편집기가 아닙니다. 허용 목록에 등록된 항목만 바꿀 수 있고, 비밀번호·암호화 키 같은 민감 값과 접속 주소·데이터 폴더 같은 운영 인프라 값은 보이지도 않습니다.

화면에서 바꾼 값은 config 폴더의 settings.local.ini에만 저장됩니다. config.ini, secrets.ini, DB는 바뀌지 않습니다.

## 화면 구성

![시스템 관리 > 설정 관리 화면. 위에 시스템 관리 탭 줄(설정 관리가 선택됨), 그 아래 검색 칸과 다시 불러오기 단추, 카테고리 탭(보존 기간·디스크·알림·모니터링·주기 분석·가져오기·저장소 연결·분석·백업·계정 승인·LDAP/AD·SSO(OIDC)·SSO(SAML))이 있고, 보존 기간 탭의 항목마다 이름·출처 배지(config.ini)·설명과 값 입력 칸·형식 안내가 보입니다.](guide-img:admin-config-settings)

화면 위에는 시스템 관리의 탭 줄이 있고, 그 아래에 한 단계 작은 카테고리 탭이 있습니다.

- **카테고리 탭**: {{ui:config_settings.group.retention}} · {{ui:config_settings.group.disk}} · {{ui:config_settings.group.notification}} · {{ui:config_settings.group.monitoring}} · {{ui:config_settings.group.schedule}} · {{ui:config_settings.group.repository}} · {{ui:config_settings.group.analysis}} · {{ui:config_settings.group.backup}} · {{ui:config_settings.group.auth}} · {{ui:config_settings.group.auth_ldap}} · {{ui:config_settings.group.auth_oidc}} · {{ui:config_settings.group.auth_saml}}. 주소 끝에 `#backup`처럼 탭 이름을 붙이면 그 탭이 바로 열립니다(예: [/admin/config-settings#backup](/admin/config-settings#backup)).
- **항목 한 줄**: 왼쪽에 이름·출처 배지·도움말, 오른쪽에 입력 칸·형식 안내·범위·"기본값으로 하면 …" 안내·반영 상태·{{ui:icon.action.reset_default}} 단추가 있습니다.
- **검색**: 모든 탭의 이름·도움말·설정 키를 찾습니다. 지금 탭에 없고 다른 탭에 있으면 그 탭으로 가는 단추가 나옵니다.
- **탭 머리 표시**: 바꾼 항목 수와 입력 오류 수가 탭 이름 옆에 붙습니다.
- **아래 단추**: "설정 항목 변경 확인(N건)"과 {{ui:config_settings.discard}}. 모든 탭의 변경을 한꺼번에 다룹니다.
- **{{ui:config_settings.history_title}}**: 탭 아래에 최근 변경이 시각·사용자·이전 값·이후 값으로 보입니다.

### 저장 방식이 다른 구역 두 개

두 구역은 settings.local.ini가 아니라 DB에 저장하므로, 아래 "설정 항목 변경 확인" 단추와 따로 **자기 단추로 바로 저장**합니다.

- **{{ui:admin_notifications.title}}**: {{ui:config_settings.group.notification}} 탭 끝. 읽은·안 읽은 알림 보관 일수와 사용자당 최대 개수입니다. [관리자 알림](guide:notifications-admin)에서 설명합니다.
- **{{ui:repo_hosts.title}}**: {{ui:config_settings.group.repository}} 탭 맨 위. 저장소 접속을 허용할 호스트의 추가·연결 테스트·삭제입니다. [저장소 연동](guide:repository)에서 설명합니다.

구역에 저장하지 않은 입력이 있으면 구역 제목 옆에 "저장하지 않은 변경 N건"이 붙고, 탭 머리 건수와 바깥 탭 이동 확인에도 더해집니다. "설정 항목 변경 확인(N건)"의 N은 설정 항목만 셉니다.

## 값 바꾸기

1. 카테고리 탭을 고르고 값을 바꿉니다. 탭을 옮겨도 입력은 남아 있습니다.
2. 형식이나 범위가 틀리면 칸 아래에 이유가 바로 보입니다.
3. 아래의 "설정 항목 변경 확인(N건)"을 누릅니다. 고쳐야 할 항목이 있으면 대화상자 대신 첫 오류가 있는 탭으로 옮겨 그 항목에 포커스합니다.
4. 대화상자에서 항목·지금 값·저장 후 값을 확인하고 {{ui:config_settings.save}}를 누릅니다.
5. 저장은 **모두 성공하거나 모두 실패**합니다. 일부만 저장되는 일은 없습니다.

입력한 것을 버리려면 {{ui:config_settings.discard}}를 누릅니다. 화면 설정을 지우고 config.ini 값(없으면 기본값)으로 돌아가려면 그 항목의 {{ui:icon.action.reset_default}}를 누릅니다.

### 반영 시점

- **웹**: 요청마다 설정을 다시 읽으므로 저장하자마자 반영됩니다. 이미 열려 있는 화면(예: 알림 개수 갱신 주기)은 새로 열 때 적용됩니다.
- **워커**: 루프마다 settings.local.ini가 바뀌었는지 보고 다시 읽습니다. 재시작은 필요 없고, 바뀐 값은 **다음 작업부터** 씁니다. 실행 중인 분석은 시작할 때의 값으로 끝까지 갑니다.
- 항목 옆 반영 상태는 "{{ui:config_settings.status.applied}}", "{{ui:config_settings.status.pending_reload}}", "{{ui:config_settings.status.worker_off}}" 가운데 하나입니다.
- 워커를 다시 시작해야 반영되는 항목이 생기면 화면 위에 배너와 워커 화면 링크가 나옵니다. 지금 허용 목록에는 그런 항목이 없습니다.

## 출처 배지와 우선순위

항목마다 지금 값이 어디서 왔는지 배지로 보입니다.

- **{{ui:config_settings.source.env}}**: 환경 변수 `PJG_<섹션>_<키>`(예: `PJG_DISK_WARN_PERCENT`)가 정한 값입니다. 화면에서 바꿀 수 없고 읽기 전용으로 보입니다.
- **{{ui:config_settings.source.screen}}**: 이 화면에서 저장한 값(settings.local.ini)입니다.
- **{{ui:config_settings.source.config}}**: 서버의 config.ini에 적힌 값입니다.
- **{{ui:config_settings.source.default}}**: 어디에도 없어 기본값을 씁니다.

우선순위는 **환경 변수 > 화면 설정 > config.ini > 기본값**입니다. 여러 서버를 같은 값으로 운영하려면 공통 값은 config.ini에, 서버마다 다른 값은 화면 설정이나 환경 변수에 둡니다. 서비스 관리자로 값을 고정하려면 환경 변수를 씁니다.

"{{ui:config_settings.machine_specific}}" 배지가 붙은 항목(`backup.dir`, `oidc.redirect_base`)은 서버마다 다른 값입니다. 백업으로 설정을 복원할 때 기본으로 해제됩니다.

## 바꿀 수 없는 항목

다음은 화면에 없습니다. 바꾸려면 서버 관리자가 서버에서 config.ini나 secrets.ini를 고치고 워커를 다시 시작합니다(secrets.ini는 [보안 운영](guide:security) 참고).

- 접속 주소·보안 헤더·HTTPS 강제, 비밀번호 정책·잠금·세션(`app.base_url`, `security.require_https`, `auth.session_idle` 등) — 잘못 바꾸면 로그인이 막힙니다. 예외로 신뢰 프록시(`security.trusted_proxies`)는 {{ui:config_settings.group.monitoring}} 탭에서 바꿉니다.
- DB·데이터 폴더 위치, SQLite 설정 — 운영 인프라입니다.
- 암호화 키·SMTP·LDAP 비밀번호 같은 비밀 값(secrets.ini) — 민감 값입니다.
- 도구·Node 경로, 워커 시작 명령, 업로드 청크 크기와 압축 해제 한도 — 서버 실행과 보안 한도에 맞물려 있습니다.

LDAP/AD 검색 계정 비밀번호, OIDC 클라이언트 비밀, SAML SP 키는 각 탭의 별도 입력으로 암호화해 저장합니다. 설정 파일에는 쓰지 않습니다.

## 경로 항목: 경로 확인과 폴더 만들기

백업 폴더(`backup.dir`) 같은 경로 항목은 저장하기 전에 {{ui:icon.action.check_path}}를 통과해야 합니다.

1. 절대 경로, 네트워크 공유 경로, 또는 프로젝트 기준 상대 경로를 입력합니다.
2. {{ui:icon.action.check_path}}를 누릅니다. 서버가 위치 규칙·쓰기 시험·여유 공간을 확인합니다.
3. 폴더가 없으면 "만들까요?"를 묻습니다. 확인하면 {{ui:config_settings.path_create}}로 폴더를 만듭니다.
4. 운영 데이터와 같은 디스크면 저장은 되지만 경고가 보입니다.

프로젝트 폴더 안(웹 루트·데이터 폴더 포함), 운영체제 폴더, 드라이브 루트는 쓸 수 없습니다. 예외로 프로젝트 바로 아래 backups 폴더만 허용합니다. 네트워크 공유가 5초 안에 응답하지 않으면 실패로 봅니다. 백업·복원이 진행 중이면 백업 폴더를 바꿀 수 없습니다(409 `setting_busy`).

## 충돌, 무시한 값, 바깥 탭 이동

- **충돌(409)**: 화면을 연 뒤 다른 관리자가 먼저 저장했으면 저장이 거부됩니다(`settings_conflict`). {{ui:config_settings.reload}}로 최신 값을 보고 다시 바꿉니다. 바꿨다가 되돌려 내용이 같아진 경우는 충돌로 보지 않습니다.
- **무시한 값**: settings.local.ini가 깨졌거나 값이 범위를 벗어나면 그 값만 무시하고 config.ini·기본값으로 동작합니다. 화면 위에 무시한 값과 이유가 보입니다. 파일이 깨졌으면 다음 저장 때 새로 씁니다.
- **바깥 탭 이동**: 저장하지 않은 변경이 있는 채로 시스템 관리의 다른 탭을 누르면 확인 대화상자가 나옵니다. 취소하면 그대로 남고, {{ui:config_settings.leave_confirm}}를 누르면 변경을 버리고 이동합니다.

## 변경 이력과 백업

- 변경 이력은 감사 로그의 `config.update`에서 읽습니다. 항목마다 한 줄이 남습니다([감사 로그](guide:audit-log) 화면에서도 볼 수 있습니다). LDAP 바인드 DN 같은 값은 가려서 남습니다.
- settings.local.ini는 저장할 때마다 이전 파일을 다섯 개까지 순환 보관합니다(settings.local.ini.bak.1이 가장 최근).
- 백업 묶음에는 settings.local.ini 사본이 민감 값을 비운 채 들어갑니다. 복원할 때 설정 복원은 기본으로 꺼져 있고, 켜면 항목을 골라 적용합니다. 자세한 것은 [백업과 복원](guide:backup-restore)을 보세요.
- 서버에서 설정을 점검하려면 다음을 실행합니다. config.ini와 settings.local.ini를 함께 읽어 무시한 값을 경고로 보여 줍니다.

```
php tools/config_check.php
```

## 문제 해결

- **저장이 실패하고 서버 로그에 쓰기 실패가 남음**: 웹 서버 계정에 config 폴더 쓰기 권한이 있어야 합니다. config.ini와 secrets.ini에는 웹 계정 쓰기 권한이 필요 없습니다. 운영 환경에서 확인할 것: Linux에서 파일 권한과 그룹(웹 서버 계정이 쓰고 워커 계정이 읽음).
- **값을 바꿨는데 워커 동작이 그대로임**: 반영 상태가 "{{ui:config_settings.status.worker_off}}"인지 봅니다. 워커가 꺼져 있으면 [워커](/admin/worker) 탭에서 시작합니다.
- **화면 설정을 모두 버리고 싶음**: 웹과 워커가 쓰지 않는 시간에 settings.local.ini를 지웁니다. 이전 내용은 .bak 파일에 남습니다.

## 관련 절

- [보존 기간과 디스크](guide:retention-disk)
- [백업과 복원](guide:backup-restore)
- [관리자 알림](guide:notifications-admin)
- [시스템 점검과 모니터링](guide:monitoring): {{ui:config_settings.group.monitoring}} 탭의 헬스체크 허용 IP·큐 정체 기준
- [보안 운영](guide:security)
