---
key: retention-disk
title: 보존 기간과 디스크
order: 13
summary: 무엇이 언제 지워지는지, 정리 작업을 매일 돌리는 방법, 디스크 경고·차단 기준과 로그 확인
keywords: 보존 기간, 정리 작업, cleanup, 디스크, 경고 비율, 차단 비율, 스냅샷, 작업 폴더, 휴지통, 로그, Run 상세 보관
related: settings, monitoring, audit-log, backup-restore, projects-admin, worker
audience: admin
---
## 무엇을 다루나요

PJGuardian은 업로드 조각, 압축 파일, 작업 폴더, 로그, 코드 스냅샷처럼 시간이 지나면 필요 없는 파일을 정해진 기간 뒤에 지웁니다. 이 절은 무엇이 언제 지워지는지, 정리 작업을 어떻게 돌리는지, 디스크가 찼을 때 무엇이 막히는지를 설명합니다.

보존 기간 값은 **시스템 관리 > {{ui:config_settings.title}} > {{ui:config_settings.group.retention}}** 탭([/admin/config-settings#retention](/admin/config-settings#retention))에서 바꿉니다. 아래 괄호 안의 값은 지금 설정 값입니다.

## 정리 작업이 지우는 것

정리 작업(tools/cleanup.php)이 한 번 돌 때 다음을 처리합니다.

- **끝나지 않은 업로드 조각**: 마지막 활동 뒤 {{limit:upload.incomplete_chunk_retention}}이 지나면 지우고 업로드를 중단 상태로 바꿉니다.
- **실패·취소된 업로드와 분석의 zip**: {{limit:upload.failed_zip_retention}} 동안 원인 확인용으로 남겼다가 지웁니다. 분석이 성공한 업로드의 남은 zip은 바로 지웁니다.
- **경고 확인을 기다리는 업로드**: {{limit:upload.confirmation_retention}}이 지나도록 확인하지 않으면 취소합니다.
- **작업 폴더와 휴지통**: 비정상 종료로 남은 작업 폴더와 휴지통을 비웁니다.
- **지우지 못한 파일 목록**: 잠겨 있어 지우지 못한 파일(cleanup_pending)을 다시 지워 봅니다.
- **에러·접근 로그**: {{limit:log.error_access_retention}}이 지난 로그 파일(경고 로그 포함)을 지웁니다. DB의 감사 로그는 아래 항목으로 따로 정리합니다.
- **분석 결과 파일과 결함 임시 저장소**: 하루가 지난 것을 지웁니다.
- **코드 스냅샷**: 프로젝트마다 최근 {{limit:snapshot.full_keep_count}}회 분석의 전체 스냅샷만 남깁니다. 그 전 분석은 결함 주변 발췌만 남기고 전체 스냅샷을 지웁니다.
- **도구 패키지 스테이징**: 관리자 화면에서 올린 도구 패키지의 임시 보관본(설치하지 않았거나 설치가 끝난 것)을 {{limit:tools.staging_retention}} 뒤에 지웁니다.
- **감사 로그**: {{limit:log.audit_retention}}보다 오래된 항목을 지우고 해시 체인 기준점을 옮깁니다. 보존 기간은 {{ui:config_settings.group.retention}} 탭의 "{{ui:config_settings.key.log.audit_retention.label}}"에서 바꾸며, 90일보다 짧게 줄이면 저장 전에 한 번 더 확인합니다. 자세한 것은 [감사 로그](guide:audit-log)를 보세요.
- **승인 대기·거절 계정**: 외부 인증 첫 로그인으로 생긴 대기·거절 계정을 {{limit:auth.pending_retention_days}}일 뒤에 지웁니다.
- **죽은 워커의 작업**: 워커 생존 신호가 끊기고 잠금도 풀렸으면 남은 분석을 실패로 정리합니다.

정리 작업이 지우지 않는 것도 있습니다.

- **알림**: 워커가 1시간마다 따로 정리합니다. 기간은 [관리자 알림](guide:notifications-admin)의 알림 보관 기간입니다.
- **백업 묶음**: 자동 백업이 성공한 뒤 보관 규칙으로 정리합니다. [백업과 복원](guide:backup-restore)을 보세요.
- **과거 Run 상세**: 분석이 성공할 때마다 프로젝트별 보관 수({{limit:runs.detail_keep}})를 넘는 오래된 Run의 상세 보기를 닫습니다. 수동 삭제는 [프로젝트 관리](guide:projects-admin)에서 설명합니다.
- **결함과 결함 이력**: 지우지 않습니다.

## 정리 작업 돌리기

정리 작업은 **매일 한 번** 돌아야 합니다. 앱이 스스로 예약하지 않으므로 서버의 작업 스케줄러에 등록합니다.

1. Windows는 작업 스케줄러, Linux는 cron이나 systemd 타이머에 다음 명령을 매일 실행하도록 등록합니다. 실행 계정은 데이터 폴더에 쓰기 권한이 있어야 합니다(워커와 같은 계정 권장).

```
php tools/cleanup.php
```

2. 무엇이 지워질지 먼저 보려면 `--dry-run`을 붙입니다. 아무것도 지우지 않고 건수만 보여 줍니다.

```
php tools/cleanup.php --dry-run
```

3. 등록이 잘 됐는지는 **시스템 관리 > {{ui:admin_checks.title}}** 탭([/admin/checks](/admin/checks))의 "{{ui:admin_checks.cleanup_title}}"에서 마지막 실행 시각과 결과로 확인합니다. "실행 기록 없음"이면 스케줄 등록을 확인합니다.
4. 지금 바로 정리하려면 같은 곳의 {{ui:admin_checks.cleanup_run}}을 누릅니다. 워커가 같은 작업을 실행하므로 워커가 켜져 있어야 합니다. 지운 파일은 되돌릴 수 없습니다.

종료 코드는 성공 0, 오류 2입니다. 운영 환경에서 확인할 것: Linux에서 cron·systemd 타이머 등록과 실행 계정 권한.

## 디스크 경고와 차단

디스크 사용률(전체 대비 사용 중인 비율)을 두 기준으로 봅니다. 값은 {{ui:config_settings.group.disk}} 탭에서 바꾸며, 경고 비율은 차단 비율보다 작아야 합니다.

- **경고**({{limit:disk.warn_percent}} 이상): 서버 로그에 경고가 남고, 점검 탭의 디스크 막대가 경고로 바뀝니다. 작업은 계속됩니다.
- **차단**({{limit:disk.block_percent}} 이상): 새 업로드를 받지 않습니다(507 `disk_usage_blocked`). 저장소 가져오기도 디스크 검사에 걸리고, 주기 분석은 그 회차를 건너뛰고 관리자에게 알립니다. 백업 폴더가 있는 디스크가 이 비율을 넘으면 백업도 하지 않습니다(`backup_disk_full`).

사용률과 별도로, 업로드를 시작할 때 압축을 풀 공간까지 예약해 계산합니다. 여유가 모자라면 `insufficient_storage`로 거부하거나 분석이 이 코드로 실패합니다.

### 공간이 부족할 때

1. 점검 탭에서 {{ui:admin_checks.disk_scan}}을 눌러 구분별 사용량(업로드 조각, zip, 작업 폴더, 결과 파일, 스냅샷, 휴지통, 로그, 백업, DB 등)을 계산합니다. 파일이 많으면 몇 분 걸립니다.
2. 큰 구분부터 봅니다. 업로드·작업 폴더·휴지통이 크면 {{ui:admin_checks.cleanup_run}}을 누릅니다.
3. 스냅샷이 크면 {{ui:config_settings.group.retention}} 탭에서 스냅샷 보존 횟수를 줄입니다. 다음 정리부터 반영됩니다.
4. 백업이 크면 백업 폴더를 다른 디스크로 옮기거나 보관 규칙을 줄입니다([백업과 복원](guide:backup-restore)).
5. 로그가 크면 로그 보존 기간을 줄입니다.

Run 상세를 삭제해도 디스크 공간은 거의 줄지 않습니다. DB 파일을 줄이는 수동 절차는 [프로젝트 관리](guide:projects-admin)를 보세요.

## 로그 확인

- 앱 로그는 데이터 폴더의 logs 아래 app-YYYYMMDD.log(UTC 날짜)에 한 줄 JSON으로 쌓입니다. 웹과 워커가 같은 파일에 씁니다. 줄마다 시각, 수준, 요청 ID, 분석 작업 ID, 사용자 ID, 메시지가 있고 비밀번호·토큰은 가려집니다.
- 최근 로그는 **시스템 관리 > {{ui:admin_worker.title}}** 탭의 "{{ui:admin_worker.log_title}}"에서도 볼 수 있습니다. 워커만 볼지 웹까지 볼지 고를 수 있습니다.
- 기록 수준은 config.ini의 `log.level`로 정합니다(화면에서 바꿀 수 없음).
- 로그 파일은 정리 작업이 {{limit:log.error_access_retention}} 뒤에 지웁니다. 더 오래 보관하려면 정리 전에 다른 곳으로 옮기세요.

## 주의

- 보존 기간을 줄이면 다음 정리 작업에서 기준을 넘은 파일이 한꺼번에 지워집니다. 먼저 `--dry-run`으로 건수를 확인하세요.
- 실패한 업로드의 zip은 원인을 확인하는 데 씁니다. 너무 짧게 잡으면 사용자 문의를 조사할 수 없습니다.
- 기본 백업 폴더는 운영 데이터와 같은 디스크에 있습니다. 디스크가 차면 백업도 멈추므로 운영에서는 다른 디스크로 옮기세요.

## 관련 절

- [설정 관리](guide:settings)
- [시스템 점검과 모니터링](guide:monitoring)
- [감사 로그](guide:audit-log)
- [백업과 복원](guide:backup-restore)
- [프로젝트 관리](guide:projects-admin)
- [워커](guide:worker)
