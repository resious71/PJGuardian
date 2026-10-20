---
key: notifications-admin
title: 관리자 알림
order: 19
summary: 관리자에게만 오는 알림의 종류와 받았을 때 할 일, 알림 보관 기간 설정
keywords: 관리자 알림, 알림, 승인 대기, 승인 대기 상한, 외부 신원 불일치, SAML 인증서 만료, 주기 분석 실패, 결함 수 경고, 백업 실패, 백업 완료, 복원 리허설, 알림 보관 기간
related: accounts, external-auth, auth-saml, schedule, backup-restore, settings
audience: admin
---
## 무엇을 다루나요

관리자(admin)는 일반 사용자 알림 다섯 종류 말고도 **관리자 전용 알림**을 받습니다. 관리자 전용 알림은 일반 사용자에게는 만들어지지도 보이지도 않습니다. 알림 목록의 종류 조건과 알림 설정 탭에도 관리자에게만 나타납니다.

- 받는 사람: **활성 상태의 관리자 전원**입니다. 단, 백업 완료 알림은 지금 백업을 요청한 관리자 한 명만 받습니다.
- 알림은 앱 안에서만 보냅니다. 상단의 알림 아이콘과 알림 화면([/notifications](/notifications))에서 봅니다.
- 관리자도 종류마다 알림 설정 탭에서 끌 수 있습니다. 끈 동안의 알림은 만들어지지 않으므로, 모든 관리자가 같은 종류를 끄지 않도록 하세요.

## 계정·인증 알림

### {{ui:notifications.kind.account_pending}}

외부 인증(LDAP/AD, OIDC, SAML)으로 처음 로그인한 사람의 계정이 승인을 기다릴 때 옵니다. 그날 생긴 대기 계정이 5건 이하면 한 건씩, 넘으면 그날 요약 한 건에 건수가 갱신됩니다.

할 일:

1. 알림을 눌러 [사용자 관리](/admin/users)로 갑니다.
2. 승인 대기 계정의 이름을 확인하고 역할을 정해 승인하거나 거절합니다.

승인 전 계정은 늘 비활성이고 어떤 권한도 없습니다. 자세한 절차는 [외부 인증 개요](guide:external-auth)를 보세요.

### {{ui:notifications.kind.account_pending_limit}}

승인 대기·거절 계정 수가 상한({{limit:auth.pending_max}}건)에 이르러 새 계정 요청을 받지 않을 때 하루 한 번 옵니다. 이 동안 처음 로그인하는 사람은 계정 요청이 막힙니다.

할 일:

1. 사용자 관리에서 대기 계정을 승인하거나 거절하고, 필요 없는 대기·거절 계정을 지웁니다.
2. 정상적으로 요청이 많다면 **{{ui:config_settings.title}} > {{ui:config_settings.group.auth}}** 탭에서 상한을 올립니다.

대기·거절 계정은 {{limit:auth.pending_retention_days}}일이 지나면 정리 작업이 지웁니다.

### {{ui:notifications.kind.auth_identity_mismatch}}

로그인 ID는 같은데 외부 인증의 식별자가 등록된 값과 달라 로그인을 거부했을 때 옵니다. 같은 로그인 ID를 다른 사람이 다시 쓰는 경우일 수 있습니다. 같은 계정은 하루 한 건입니다.

할 일:

1. 감사 로그에서 그 로그인 ID의 `auth.login_failed` 기록과 사유를 봅니다([감사 로그](guide:audit-log)).
2. 디렉터리나 IdP 관리자에게 그 ID가 다른 사람에게 다시 배정됐는지 확인합니다.
3. 판단이 서기 전에는 계정을 연결하거나 바꾸지 마세요.

### {{ui:notifications.kind.saml_cert_expiring}}

SAML이 켜져 있을 때, 등록한 IdP 서명 인증서가 만료 경고 기간({{limit:notification.cert_expiry_warn}}) 안에 만료되거나 이미 만료됐으면 옵니다. 워커가 1시간마다 확인하고 인증서마다 하루 한 건입니다.

할 일: IdP에서 새 인증서를 받아 **{{ui:config_settings.group.auth_saml}}** 탭의 "{{ui:config_settings.key.saml.idp_cert_next.label}}" 칸에 넣습니다. 만료되면 SAML 로그인이 실패할 수 있습니다. 자세한 것은 [SSO(SAML)](guide:auth-saml)를 보세요.

## 분석 운영 알림

### {{ui:notifications.kind.schedule_failed}}

다음 경우에 옵니다.

- 주기 분석이 재시도 뒤에도 실패했을 때. 같은 프로젝트에서 연속으로 실패하면 새 알림 대신 한 건에 횟수·시각이 갱신됩니다.
- 디스크 차단이나 연결 문제로 주기 분석 회차를 건너뛰었을 때. 자격 증명을 풀 수 없어 건너뛴 경우(키 없이 복원 등)는 프로젝트마다 한 번만 알립니다.

할 일: 알림의 사유를 보고 [주기 분석](/admin/schedules) 탭에서 프로젝트의 상태를 확인합니다. 연결 문제면 프로젝트의 연결 테스트를, 디스크 문제면 [보존 기간과 디스크](guide:retention-disk)를 봅니다. 자세한 것은 [주기 분석](guide:schedule)을 보세요.

### {{ui:notifications.kind.defect_count_warn}}

분석이 끝난 뒤 프로젝트의 현재 결함(해결되지 않은 것)이 경고 기준({{limit:limits.project_defect_warn}}건)을 넘으면 옵니다. 0이면 끈 것입니다. 같은 프로젝트는 한 건에 건수가 갱신됩니다.

할 일: 생성 파일·압축(minified) 파일·외부 라이브러리 폴더가 분석 대상에 들어갔는지 봅니다. 그렇다면 프로젝트 제외 패턴에 넣고 다시 분석합니다. 결함 수가 상한({{limit:limits.project_defect_max}}건)을 넘을 분석은 `defect_limit_exceeded`로 실패합니다. 기준은 **{{ui:config_settings.title}} > {{ui:config_settings.group.analysis}}** 탭에서 바꿉니다.

## 백업 알림

- **{{ui:notifications.kind.backup_failed}}**: 자동 백업이 재시도 뒤에도 실패했을 때. 같은 사유는 1시간에 한 번입니다. 자동 백업이 꺼져 있으면 보내지 않습니다. [백업·복원](/admin/backups) 화면의 경고와 마지막 실패 사유를 보세요.
- **{{ui:notifications.kind.backup_done}}**: {{ui:icon.action.backup_now}}을 요청한 관리자에게만, 백업이 끝났을 때 옵니다.
- **{{ui:notifications.kind.restore_rehearsal_due}}**: 마지막 복원 리허설에서 {{limit:backup.rehearsal_interval_days}}일이 지났을 때 기한마다 한 번 옵니다. 백업·복원 화면에서 {{ui:icon.action.rehearse}}를 실행하세요.

자세한 것은 [백업과 복원](guide:backup-restore)을 보세요.

## 알림 보관 기간

알림은 정해진 기간이 지나면 지워집니다. 값은 **시스템 관리 > {{ui:config_settings.title}} > {{ui:config_settings.group.notification}}** 탭([/admin/config-settings#notification](/admin/config-settings#notification)) 끝의 "{{ui:admin_notifications.title}}" 구역에서 정합니다. 모든 사용자에게 공통입니다.

- **{{ui:admin_notifications.retain_read_days}}**: 읽은 알림은 읽은 뒤 이 일수가 지나면 지웁니다.
- **{{ui:admin_notifications.retain_unread_days}}**: 안 읽은 알림은 만든 뒤 이 일수가 지나면 지웁니다.
- **{{ui:admin_notifications.max_per_user}}**: 한 사용자의 알림이 이 수를 넘으면 읽은 알림 중 오래된 것부터, 그래도 넘으면 안 읽은 알림 중 오래된 것부터 지웁니다.

절차:

1. 값을 바꿉니다. 범위와 기본값이 칸 옆에 보입니다.
2. 이 구역의 {{ui:admin_notifications.save}} 단추를 누릅니다. 이 구역은 DB에 저장되므로 아래 "설정 항목 변경 확인" 단추와 따로 저장합니다.
3. 변경은 다음 정리부터 적용됩니다. 워커가 1시간마다 정리합니다.

저장 기록은 감사 로그에 `setting.update`로 남습니다.

같은 탭의 다른 항목도 알림과 관련됩니다.

- **{{ui:config_settings.key.ui.notification_poll_seconds.label}}**(지금 {{limit:ui.notification_poll_seconds}}): 화면이 안 읽은 알림 수를 다시 확인하는 간격입니다. 열려 있는 화면은 새로 열 때 적용됩니다.
- **{{ui:config_settings.key.notification.cert_expiry_warn.label}}**(지금 {{limit:notification.cert_expiry_warn}}): SAML 인증서 알림과 허용 호스트 표의 인증서 만료 경고가 이 값을 씁니다. 허용 호스트의 인증서 만료는 알림이 아니라 표의 경고로만 보입니다.

## 문제 해결

- **관리자 알림이 오지 않음**: 알림 화면의 설정 탭에서 그 종류가 켜져 있는지 봅니다. 계정이 활성 관리자인지도 확인합니다. 워커가 만드는 알림(백업·주기 분석·SAML 인증서)은 워커가 실행 중이어야 생깁니다.
- **같은 알림이 계속 쌓이지 않고 한 건만 바뀜**: 주기 분석 연속 실패와 결함 수 경고는 한 건을 갱신하도록 되어 있습니다. 읽음 상태는 그대로 유지됩니다.

## 관련 절

- [계정 관리](guide:accounts)
- [외부 인증 개요](guide:external-auth)
- [주기 분석](guide:schedule)
- [백업과 복원](guide:backup-restore)
- [설정 관리](guide:settings)
