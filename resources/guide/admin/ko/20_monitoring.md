---
key: monitoring
title: 시스템 점검과 모니터링
order: 20
summary: 시스템 관리 > 점검 탭, 시스템 모니터링 화면의 카드와 오류·경고, 관리자 배너, 경고 로그, 외부 감시용 헬스체크(/health)
keywords: 점검, 시스템 점검, 시스템 모니터링, 모니터링, 오류, 경고, 배너, 경고 로그, 헬스체크, health, 허용 IP, 큐 정체, 인증서 만료, 디스크 사용 현황, 정리 작업, 실패한 분석, 환경
related: admin-mail, audit-log, retention-disk, worker, tools-bin, settings, security, troubleshooting
audience: admin
---
## 무엇을 다루나요

- **점검 탭**: **시스템 관리 > {{ui:admin_checks.title}}**([/admin/checks](/admin/checks))은 디스크, 정리 작업, 최근 실패한 분석, 서버 환경, 주요 설정을 읽기 전용으로 보여 줍니다.
- **{{ui:monitoring.title}}**: 관리자 메뉴의 {{icon:admin.system}} {{ui:icon.admin.system}}([/admin/monitoring](/admin/monitoring))은 지금 운영 상태와 오류·경고를 한 화면에 모읍니다.
- **헬스체크**: 외부 감시 도구가 로그인 없이 상태만 묻는 주소(/health)입니다.

감사 로그는 [감사 로그](guide:audit-log) 절에서 설명합니다.

## 점검 탭 읽는 법

상태는 아이콘과 글자로 "{{ui:admin_checks.status.ok}}", "{{ui:admin_checks.status.warn}}", "{{ui:admin_checks.status.fail}}"로 표시되고, 허용된 방침을 알리는 줄은 "{{ui:admin_checks.status.info}}", 이 OS에서 확인할 수 없는 줄은 "{{ui:admin_checks.status.unknown}}"입니다. 경고·문제 항목부터 조치합니다.

- **{{ui:admin_checks.disk_title}}**: 사용률 막대와 경고({{limit:disk.warn_percent}})·차단({{limit:disk.block_percent}}) 표시, 여유·전체 크기. 차단이면 새 업로드를 받지 않습니다. {{ui:admin_checks.disk_scan}}을 누르면 워커가 구분별 사용량을 계산합니다. 조치는 [보존 기간과 디스크](guide:retention-disk)를 보세요.
- **{{ui:admin_checks.cleanup_title}}**: 마지막 정리 시각·출처·결과 건수. "실행 기록 없음"이면 서버 스케줄에 정리 작업이 등록되지 않은 것입니다. {{ui:admin_checks.cleanup_trash}}·{{ui:admin_checks.cleanup_pending}}이 계속 늘면 파일 잠금이나 권한 문제를 의심합니다. {{ui:admin_checks.cleanup_run}}은 워커에 정리를 요청합니다.
- **{{ui:admin_checks.failures_title}}**: 최근 7일 실패한 분석의 사유별 건수와 최근 20건. 관리자 조치가 필요한 사유에는 [분석 도구](/admin/tools)·[워커](/admin/worker)·디스크 영역 링크가 붙습니다.
- **{{ui:admin_checks.env_title}}**: 웹 서버 PHP 기준의 운영체제·PHP 버전·확장 모듈, SQLite·proc_open, php.ini 값, 데이터 폴더 쓰기 시험(웹 계정이 bin 폴더에 쓸 수 있으면 경고), 서버 시각·HTTPS·Node.js, 켜진 외부 인증의 연결·비밀·인증서 상태. 보안 줄(알리기만 하고 막지 않음): config 폴더 쓰기(쓸 수 있으면 허용된 방침이라 정보), config.ini·secrets.ini가 이 계정에 쓰기 가능하면 경고(파일 단위 읽기 전용 권장), secrets.ini가 웹 루트 밖인지와 권한(Linux는 다른 사용자 읽기 허용이면 경고, Windows는 확인 불가), public 폴더에 설정·.git·vendor·백업이 있으면 문제, display_errors·expose_php가 켜져 있으면 경고, 세션 저장 위치(DB).
- **{{ui:admin_checks.config_title}}**: 주요 설정의 지금 값과 기본값 대비 변경 여부. 비밀 값은 "설정됨/미설정"만 보입니다. 값은 [설정 관리](guide:settings)에서 바꿉니다.

워커가 쓰는 명령줄 PHP는 웹 PHP와 설정이 다를 수 있으므로 서버에서 따로 점검합니다.

```
php tools/check_env.php
```

## 시스템 모니터링 화면

![시스템 모니터링 화면. 위에 전체 상태 한 줄, 마지막 갱신 시각, 지금 새로 고침 단추가 있고, 그 아래 활성 경고 목록과 워커·큐·디스크, 백업·bin/ 도구·HTTPS 인증서, 주기 분석·최근 실패 분석, 앱 정보 카드가 같은 모양으로 격자에 놓여 있습니다.](guide-img:admin-monitoring)

읽기 전용 화면입니다. 맨 위에 전체 상태("모든 항목 정상", "경고 N건", "오류 N건")와 마지막 갱신 시각, 그 아래 활성 경고 목록이 있습니다.

- **자동 갱신**: 브라우저 탭이 보이는 동안 {{limit:monitoring.refresh}}마다 다시 읽습니다. 포커스와 스크롤 위치는 그대로입니다.
- **{{ui:monitoring.refresh_now}}**: 캐시를 쓰지 않고 바로 다시 판정합니다. 그 밖에는 {{limit:monitoring.cache}} 동안 같은 판정 결과를 다시 씁니다.
- **확인 불가**: 값을 구하지 못한 카드는 "확인 불가: 이유"만 보이고 경고를 만들지 않습니다. 나머지 카드는 그대로 보입니다.

### 카드 읽는 법

모든 카드는 같은 구조입니다. **머리**에는 상태 아이콘(정상은 파랑, 경고·오류는 빨강)과 카드 이름, 오른쪽 끝에 상태 배지가 있습니다. 배지는 {{icon:system.ok}} {{ui:monitoring.badge.ok}}, {{icon:system.warn}} {{ui:monitoring.badge.warn}}(경고가 있음), {{icon:system.error}} {{ui:monitoring.badge.fail}}(장애), {{icon:system.unknown}} {{ui:monitoring.badge.unknown}}(값을 구하지 못함) 넷 중 하나이고, 판정이 없는 앱 정보 카드만 {{icon:ui.info}} {{ui:monitoring.badge.info}}입니다. 카드 왼쪽 띠의 색도 배지와 같지만, 상태는 늘 배지의 아이콘과 글자로 읽습니다. 그 아래 **핵심 값**(예: 대기 건수, 디스크 사용률, 인증서 남은 일수)이 크게, **세부**가 라벨-값 두 열이나 표로 이어집니다. 표는 최대 5행까지 보이고 넘으면 "외 N건"이 붙습니다. **바닥 줄**에는 자세히 볼 화면으로 가는 링크(없는 카드는 비어 있음)와 확인 시각이 늘 같은 자리에 있습니다. 카드 순서는 워커·큐·디스크 → 백업·bin/ 도구·HTTPS 인증서 → 주기 분석·최근 실패 분석 → 앱 정보이고, 한 줄의 카드는 높이가 같습니다.

### 카드

- **{{ui:monitoring.card.worker}}**: 상태, 마지막 생존 신호, 처리 중 작업 수. 워커가 멈췄거나 응답이 없으면 오류입니다.
- **{{ui:monitoring.card.queue}}**: 대기 수, 가장 오래된 대기 시간, 정체 판정, 실행 중인 분석과 경과 시간.
- **{{ui:monitoring.card.disk}}**: 사용률, 여유/전체, 경고·차단 기준.
- **{{ui:monitoring.card.backup}}**: 자동 백업 켜짐/꺼짐, 마지막 성공, 마지막 결과, 다음 예정. 백업 폴더가 운영 데이터와 같은 디스크면 안내가 붙습니다.
- **{{ui:monitoring.card.tools}}**: ruff·ESLint의 활성 버전, 고정·차단 표시, 체크섬·스모크 결과, 마지막 검증 시각. git·p4는 표시만 합니다.
- **{{ui:monitoring.card.cert}}**: 웹 서버 인증서의 만료일과 남은 일수. 서버가 자기 주소에 접속하지 않고 config.ini의 `monitoring.tls_cert_file`(PEM 파일 경로)을 읽습니다. 비어 있으면 "확인 불가: 설정되지 않음"입니다.
- **{{ui:monitoring.card.schedules}}**: 다음 실행 예정과 연속 실패한 프로젝트.
- **{{ui:monitoring.card.failures}}**: 최근 {{limit:monitoring.recent_failures}}건의 실패 분석. 핵심 값은 그 건수이고, 표에는 최신 5건(프로젝트·Run·사유 코드·시각, 프로젝트를 누르면 분석 화면)과 "외 N건"이 보입니다.
- **{{ui:monitoring.card.app}}**: 앱 버전, DB 스키마(마이그레이션), PHP, SQLite, 운영체제.

## 오류와 경고

경고 이름과 수준은 다음과 같습니다. 오류가 하나라도 있으면 전체 상태가 오류, 경고만 있으면 경고입니다.

- `worker_down`(오류): 워커 생존 신호가 없거나 멈춤. [워커](guide:worker)를 보세요.
- `queue_stalled`(경고): 워커가 살아 있고 빈 자리가 있는데, **시작할 수 있는**(그 프로젝트에 실행 중인 분석이 없는) 가장 오래된 대기 분석이 {{limit:monitoring.queue_stall}} 넘게 시작되지 않음. 프로젝트당 1개 규칙 때문에 기다리는 것은 정체가 아닙니다.
- `disk_warn`(경고)·`disk_block`(오류): 디스크 경고·차단 기준을 넘음.
- `backup_failed`(경고): 마지막 백업이 실패. `backup_stale`(경고): 자동 백업이 켜져 있는데 마지막 성공이 백업 화면의 "오래됨" 기준보다 오래됨.
- `tool_unhealthy`(경고): ruff·ESLint에 쓸 버전이 없거나 체크섬·스모크가 실패.
- `repo_fetch_failing`(경고): 주기 분석이 {{limit:monitoring.repo_failures}}회 이상 연속 실패한 프로젝트가 있음.
- `cert_expiring`(경고): 인증서가 {{limit:notification.cert_expiry_warn}} 안에 만료. `cert_expired`(오류): 이미 만료.

정체 기준은 **시스템 관리 > {{ui:config_settings.title}} > {{ui:config_settings.group.monitoring}}** 탭의 "{{ui:config_settings.key.monitoring.queue_stall.label}}"에서 바꿉니다.

### 관리자 배너와 경고 로그

- 활성 경고가 있으면 관리자에게만 모든 화면 위에 "오류·경고 N건: 첫 경고"와 "{{ui:monitoring.banner_link}}" 링크가 보입니다. 일반 사용자에게는 보이지 않습니다. 화면·배너·헬스체크는 같은 판정을 씁니다.
- 워커가 {{limit:monitoring.cache}}마다 판정해 데이터 폴더 logs 아래 alert-YYYYMMDD.log에 한 줄씩 남깁니다. 경고가 생길 때, 이어지는 동안 {{limit:monitoring.log_repeat}}마다, 풀릴 때 기록합니다. 보존 기간은 앱 로그와 같습니다({{limit:log.error_access_retention}}).
- **이메일**: 오류·경고를 관리자에게 메일로 받으려면 [관리자 이메일 알림](guide:admin-mail)을 켜세요(기본 꺼짐). 워커가 멈추면 메일도 가지 않으므로, 워커 정지를 바로 알아야 하면 아래 헬스체크를 외부 감시 도구에 함께 연결하세요.

## 헬스체크(/health)

외부 감시 도구용 주소입니다. 로그인이 필요 없고, **허용한 IP에서만** 응답합니다. 그 밖의 주소에는 없는 주소와 같은 404를 돌려줍니다.

1. **시스템 관리 > {{ui:config_settings.title}} > {{ui:config_settings.group.monitoring}}** 탭의 "{{ui:config_settings.key.health.allowed_ips.label}}"(`health.allowed_ips`)에 감시 서버 주소를 쉼표로 적습니다(CIDR 가능). 비어 있으면(기본) 모두 거부합니다.
2. 감시 서버에서 상태 코드를 확인합니다.

```
curl -s -o /dev/null -w "%{http_code}" https://<서버 주소>/health
```

- 200: 정상 또는 경고, 503: 오류(DB에 접근할 수 없을 때 포함), 429: 허용 IP당 분당 {{limit:health.per_minute}}회 초과.
- 본문은 JSON: status(ok·warn·fail), time, maintenance(업그레이드 유지보수 모드 — 판정에는 넣지 않음), checks(worker·db·disk·backup·queue). 프로젝트·사용자 이름, 경로, 비밀은 없습니다. 같은 응답을 {{limit:health.cache}} 동안 다시 씁니다.
- **프록시 뒤에서**: 기본은 접속한 주소만 보고 X-Forwarded-For 같은 헤더는 믿지 않습니다. 역방향 프록시 뒤라면 같은 탭의 "{{ui:config_settings.key.security.trusted_proxies.label}}"에 프록시 주소를 넣으세요. 그러면 그 프록시를 거친 요청만 X-Forwarded-For의 주소로 허용 IP를 판정합니다([보안 운영](guide:security)). 프록시에서도 /health 접근을 감시 서버로만 제한하세요. 운영 환경에서 확인할 것: 프록시 구성에서 /health 제한.

## 관련 절

- [관리자 이메일 알림](guide:admin-mail)
- [감사 로그](guide:audit-log)
- [보존 기간과 디스크](guide:retention-disk)
- [워커](guide:worker)
- [분석 도구와 bin 폴더](guide:tools-bin)
- [설정 관리](guide:settings)
- [보안 운영](guide:security)
- [문제 해결](guide:troubleshooting)
