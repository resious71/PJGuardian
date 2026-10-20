---
key: troubleshooting
title: 문제 해결 모음
order: 24
summary: 로그인 안 됨, 분석이 대기에서 멈춤, 업로드 실패, SSO 오류, 설정 저장 실패, 디스크 부족을 증상별로 점검하는 순서
keywords: 문제 해결, 로그인, 잠김, 잠금 해제, 임시 비밀번호, 승인 대기, 대기, 워커, 업로드 실패, SSO, E-SSO, 설정 저장, 충돌, 디스크 부족, 정리
related: worker, accounts, external-auth, settings, retention-disk, monitoring, audit-log
audience: admin
---

## 먼저 볼 곳

증상과 상관없이 다음 세 곳을 먼저 봅니다.

- {{ui:icon.admin.tools}} > {{ui:admin_worker.title}}: 워커가 실행 중인지, 대기 중인 작업 수, 최근 로그
- {{ui:icon.admin.tools}} > {{ui:admin_checks.title}}: 설정·환경 점검 결과, 디스크 사용률, 정리 작업, 최근 실패한 분석
- 사용자가 본 오류 코드: 오류 코드의 뜻과 조치는 [오류 코드](/guide/errors) 탭에서 찾습니다.

## 로그인이 안 될 때

로그인 실패 문구는 일부러 하나로 같습니다. 없는 ID, 틀린 비밀번호, 잠긴 계정, 비활성 계정을 화면에서 구분하지 않으므로 관리자가 계정 상태를 확인해야 합니다.

1. {{ui:icon.admin.users}}에서 그 사용자를 찾아 상태를 봅니다.
2. "잠김"이면 로그인을 {{limit:auth.lockout_max_failures}}번 연속 실패해 {{limit:auth.lockout_duration}} 동안 잠긴 것입니다. 시간이 지나면 풀리고, 바로 풀려면 {{ui:users.action_unlock}}를 누릅니다.
3. 비밀번호를 잊었으면 {{ui:users.action_temp}}을 누릅니다. 임시 비밀번호는 {{limit:auth.temp_password_expiry}} 동안 유효하고 첫 로그인 때 바꿔야 합니다. 만료되었으면 다시 발급합니다.
4. 비활성 계정이면 활성으로 바꿔야 로그인할 수 있습니다.
5. 여러 계정이 한꺼번에 안 되면 IP 제한일 수 있습니다. 같은 IP에서 {{limit:auth.ip_failure_window}} 안에 {{limit:auth.ip_max_failures}}번 실패하면 {{limit:auth.ip_block_duration}} 동안 그 IP의 로그인을 막습니다. 시간이 지나면 풀립니다.
6. 오래 쓰지 않아 로그아웃된 경우는 고장이 아닙니다. 활동이 {{limit:auth.session_idle}} 없으면 세션이 끝납니다.

### 외부 인증 계정

- 외부 인증(LDAP/AD, SSO)으로 처음 로그인한 사람은 승인 대기 계정이 되고 세션이 생기지 않습니다. {{ui:icon.admin.users}}의 승인 대기 목록에서 {{ui:users.action_approve}}해야 로그인할 수 있습니다.
- 승인 대기 계정이 {{limit:auth.pending_max}}개에 이르면 새 요청을 받지 않습니다. 오래된 요청을 승인하거나 지우세요.
- 로컬 계정은 외부 인증으로 로그인되지 않습니다. 계정의 인증 방식을 확인합니다.
- 디렉터리에 닿지 않으면 LDAP/AD 로그인만 실패합니다. {{ui:config_settings.title}} > {{ui:config_settings.group.auth_ldap}} 탭의 {{ui:ldap.test.run}}으로 확인합니다. 자세한 내용은 [LDAP/AD 인증](guide:auth-ldap)을 보세요.

### 관리자가 아무도 로그인하지 못할 때

서버에서 계정 복구 명령을 씁니다. 작업은 감사 로그에 남습니다.

```
php tools/user_cli.php status <로그인 ID>
php tools/user_cli.php unlock <로그인 ID>
php tools/user_cli.php reset-password <로그인 ID>
```

`reset-password`는 임시 비밀번호를 화면에 한 번만 보여 줍니다. 그 계정의 세션은 모두 끊깁니다.

## 분석이 대기에서 멈춘 것 같을 때

1. {{ui:admin_worker.title}} 탭에서 워커 상태를 봅니다. 분석, 업로드 조립·해제, 주기 분석 등록, 관리 작업은 모두 워커가 해야 진행됩니다.
2. 중지 상태면 시작합니다. 화면의 시작 단추는 서버 설정에 시작 명령을 등록한 경우에만 보이고, 없으면 서버에서 워커를 실행합니다([분석 워커 운영](guide:worker)).
3. "응답 없음"(프로세스가 멈췄거나 생존 신호가 끊김)이면 서버에서 아래 명령으로 상태를 확인하고 죽은 워커가 남긴 작업을 정리합니다.
4. 워커가 정상인데 오래 기다리면 아래 대기열 규칙을 확인합니다.

```
php tools/worker.php --status
php tools/worker.php --check
```

- 대기열: 전체 동시 분석 {{limit:analysis.max_concurrent}}개, 같은 프로젝트는 앞 분석이 끝나야 다음이 시작됩니다. 분석 하나는 최대 {{limit:analysis.timeout}}까지 실행될 수 있습니다.
- 주기 분석이 "재시도 대기"면 {{limit:repository.connect_retry_interval}} 뒤에 다시 시도합니다([주기 분석](guide:schedule)).
- 워커가 비정상 종료되면 다시 시작할 때 실행 중이던 분석을 `worker_crashed`로 실패 처리합니다. 자동 재시도는 없으므로 사용자가 다시 업로드해야 합니다.

## 업로드가 실패할 때

업로드 오류는 대부분 zip 내용 문제라 사용자가 화면의 코드와 "자세히 보기"를 보고 고쳐 다시 올립니다. 관리자가 볼 것은 다음입니다.

- 시작부터 거부됨: 업로드 크기 한도 {{limit:upload.max_zip_size}}, 사용자당 동시 업로드 {{limit:upload.max_concurrent_per_user}}개, 디스크 기준(`disk_usage_blocked`, `insufficient_storage`)을 확인합니다.
- "조립 대기"에서 진행되지 않음: 조립과 해제는 워커가 하므로 워커 상태를 확인합니다.
- `io_error`: 서버가 파일을 쓰지 못했습니다. 디스크 여유 공간과 데이터 폴더 권한을 확인합니다.
- `extract_timeout`, `extract_crashed`: 파일이 아주 많거나 메모리가 부족한 경우입니다. 최근 로그({{ui:admin_worker.title}} 탭)를 봅니다.
- `extract_limit`: 해제한 크기 {{limit:extract.max_extracted_size}} 또는 파일 {{limit:extract.max_files}}개를 넘었습니다. 사용자에게 빌드 결과·의존성 폴더를 빼도록 안내합니다. 한도가 실제 소스보다 작으면 **{{ui:config_settings.title}} > {{ui:config_settings.group.analysis}}** 탭에서 늘립니다(업로드 zip 한도 포함).

받다가 끊긴 업로드 조각은 {{limit:upload.incomplete_chunk_retention}} 동안 남아 이어 올릴 수 있고, 그 뒤 정리 작업이 지웁니다.

## SSO 로그인 오류

SSO 로그인이 실패하면 사용자는 "SSO 로그인에 실패했습니다"와 함께 `E-SSO-STATE` 같은 코드를 봅니다. 자세한 사유는 감사 로그(`auth.login_failed`)에만 남고 화면에는 나오지 않습니다.

- `E-SSO-STATE`: 로그인 요청이 만료됐거나 다른 브라우저에서 돌아왔습니다. 같은 브라우저에서 처음부터 다시 하도록 안내합니다.
- `E-SSO-IDP`: 인증 제공자(IdP)가 거절했습니다(동의 거부, 앱 사용 권한 없음 등). IdP 쪽 설정과 사용자 권한을 확인합니다.
- `E-SSO-TOKEN`: 응답 검증에 실패했습니다(서명, 유효 시간, 대상). 서버 시계와 IdP 인증서·설정을 확인합니다.
- `E-SSO-CONFIG`: SSO가 꺼져 있거나 설정이 끝나지 않았습니다. OIDC 클라이언트 비밀이 없거나 읽을 수 없는 경우도 여기에 들어갑니다.
- `E-SSO-UNAVAILABLE`: IdP에 닿지 않습니다. 네트워크와 IdP 상태를 확인합니다.
- `E-SSO-RATE`: 짧은 시간에 시도가 너무 많았습니다. 잠시 뒤 다시 시도합니다.
- `E-SSO-DENIED`: 인증은 됐지만 계정과 연결할 수 없습니다(같은 로그인 ID를 다른 계정이 씀, 비활성 계정 등). {{ui:icon.admin.users}}에서 계정을 확인합니다.

점검 순서:

1. {{ui:config_settings.title}}의 {{ui:config_settings.group.auth_oidc}} 탭은 {{ui:oidc.test.run}}, {{ui:config_settings.group.auth_saml}} 탭은 {{ui:saml.test.run}}으로 설정과 연결을 확인합니다.
2. {{ui:admin_checks.title}} 탭의 SSO 항목에서 경고가 있는지 봅니다.
3. IdP 장애 중에도 로컬 관리자는 로그인 화면의 ID·비밀번호로 로그인할 수 있습니다.

실제 IdP와의 연동 동작은 운영 환경에서 확인할 것. 자세한 내용은 [SSO(OIDC)](guide:auth-oidc), [SSO(SAML)](guide:auth-saml)를 보세요.

## 설정이 저장되지 않을 때

{{ui:config_settings.title}}의 저장은 전체 성공 또는 전체 실패입니다(하나라도 문제가 있으면 아무것도 바뀌지 않음).

- 형식·범위 오류: 화면이 첫 오류가 있는 탭의 그 항목으로 옮겨 이유를 보여 줍니다. 고쳐 다시 저장합니다.
- `setting_cross_check`: 항목끼리 맞지 않습니다. 예를 들어 디스크 경고 기준은 차단 기준보다 낮아야 하고, Run 상세 보관 개수는 0 또는 3 이상이어야 합니다.
- `settings_conflict`: 다른 관리자가 먼저 저장했습니다. 화면을 다시 불러온 뒤 바꿀 값을 다시 입력합니다.
- `setting_env_locked`: 서버 환경 변수가 정한 항목이라 화면에서는 읽기 전용입니다. 서버의 환경 변수를 바꿉니다.
- `setting_path_invalid`: 경로 확인에 실패했습니다. 절대 경로인지, 폴더가 있는지, 웹 서버 계정이 쓸 수 있는지 확인합니다.
- `setting_busy`: 백업·복원이 진행 중이라 백업 폴더를 바꿀 수 없습니다. 끝난 뒤 다시 시도합니다.
- 저장 자체가 실패: 화면 설정 파일(`config/settings.local.ini`)을 쓰지 못한 것입니다. 방침대로 웹 서버·워커 계정에 `config/` 폴더 쓰기 권한을 주었는지 확인합니다([설치와 환경](guide:install)).

허용 호스트와 알림 보관 기간은 각 구역의 단추로 저장합니다. 재시작이 필요한 항목은 저장 뒤 안내가 보입니다([설정 관리](guide:settings)).

## 디스크가 부족할 때

사용률이 {{limit:disk.warn_percent}}를 넘으면 경고, {{limit:disk.block_percent}}를 넘으면 새 업로드를 받지 않습니다(`disk_usage_blocked`). 여유 공간이 모자라면 분석 시작도 실패하고(`insufficient_storage`) 주기 분석은 그 회차를 건너뜁니다.

1. {{ui:admin_checks.title}} 탭에서 사용률과 여유 공간을 보고, {{ui:admin_checks.disk_scan}}으로 구분별 사용량을 계산합니다(워커가 실행 중이어야 함).
2. 정리 작업의 마지막 실행 시각을 봅니다. 기록이 없으면 서버의 매일 정리 스케줄 등록을 확인하고, 바로 정리하려면 {{ui:admin_checks.cleanup_run}}을 누릅니다.
3. 많이 차지하는 구분에 맞게 보존 기간을 줄입니다({{ui:config_settings.title}} > {{ui:config_settings.group.retention}}). 백업이 크면 백업 보관 개수를 줄이거나 백업 폴더를 다른 디스크로 옮깁니다.
4. 프로젝트를 지운 뒤에도 DB 파일이 크면 서비스를 멈추고 수동으로 DB를 줄여야 합니다. Run 상세 삭제로는 공간이 거의 줄지 않습니다.

항목별 읽는 법은 [시스템 점검과 모니터링](guide:monitoring), 기준과 보존 항목은 [보존 기간과 디스크](guide:retention-disk)를 보세요.

## 저장이 잠시 안 될 때(`db_busy`)

아주 큰 프로젝트(결함 수십만 건)의 분석 결과를 반영하는 몇 초 동안 다른 쓰기(결함 수정·로그인)가 {{limit:database.busy_timeout}} 넘게 기다리면 "다른 큰 작업이 끝나기를 기다리다 시간이 지났습니다"(`db_busy`, 503)가 보입니다. 변경은 저장되지 않았으므로 몇 초 뒤 같은 작업을 다시 하면 됩니다. 자주 보이면 프로젝트의 결함 수를 줄이고(제외 패턴, 결함 수 경고 기준), 그래도 잦으면 서버 관리자가 `database.busy_timeout`을 10s로 늘리는 것을 검토합니다. 측정과 권장 설정은 서버의 `docs/performance.md`에 있습니다.

## 그래도 해결되지 않으면

- {{ui:admin_worker.title}} 탭의 최근 로그에서 오류 줄을 찾습니다. 서버 경로와 비밀 값은 가려져 보입니다.
- 서버 설정 파일을 직접 고쳤다면 아래 명령으로 설정을 검증하고(오류면 원인을 출력) 환경을 점검합니다.

```
php tools/config_check.php
php tools/check_env.php
```

- 오류 코드와 발생 시각을 기록해 두면 서버 로그에서 같은 요청을 찾기 쉽습니다.
