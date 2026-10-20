---
key: overview
title: 관리자 가이드 안내
order: 0
summary: 관리자가 할 수 있는 일, 관리자 메뉴와 시스템 관리 탭의 위치, 이 가이드의 절 안내
keywords: 관리자, 안내, 메뉴, 시스템 관리, 설정 관리, 탭, 권한
related: install, settings, troubleshooting
audience: admin
---

## 이 가이드는

관리자 가이드는 관리자(admin)에게만 보입니다. 다른 사용자에게는 메뉴, 목차, 검색 결과 어디에도 나오지 않습니다. 주소로 직접 열어도 볼 수 없습니다.

이 가이드는 설치, 운영, 계정·권한, 저장소 연동, 백업처럼 관리자만 하는 일을 다룹니다. 결함 보기, 업로드, 분석 상태처럼 모든 사용자가 쓰는 기능은 [사용자 가이드](/guide/user)에 있습니다.

각 절은 화면이 하는 일 → 절차 → 주의할 점 → 문제 해결 순서로 씁니다. 화면에 보이는 수치(한도, 시간, 기본값)는 지금 서버 설정 값으로 채워 보여 줍니다. 설정을 바꾸면 가이드의 수치도 함께 바뀝니다.

## 관리자가 할 수 있는 일

관리자는 모든 프로젝트를 볼 수 있고, 일반 사용자에게 없는 다음 일을 합니다.

- 계정: 사용자 만들기, 역할·상태 바꾸기, 잠금 해제, 임시 비밀번호 발급, 외부 인증 첫 로그인 계정 승인, 계정 CSV 일괄 처리
- 그룹과 접근 범위: 그룹 만들기, 프로젝트에 그룹·사용자 연결
- 프로젝트: 만들기, 고치기, 삭제, 분석 제외 패턴, 소스 저장소 연결, 주기 분석
- 분석 환경: 분석 도구 버전 고정·차단, 워커 상태 확인과 시작·정지 요청, 규칙 켜기·끄기
- 서버 설정: 보존 기간, 디스크 기준, 알림, 저장소 연결, 인증(LDAP/AD, SSO) 같은 설정을 화면에서 바꾸기
- 데이터 보호: 백업 만들기·검증, 복원, Run 상세 정리

활성 로컬 관리자는 늘 한 명 이상 남아야 합니다. 마지막 활성 로컬 관리자는 비활성화하거나 일반 사용자로 바꿀 수 없습니다.

## 관리자 메뉴

관리자로 로그인하면 위쪽에 관리자 메뉴가 따로 보입니다.

1. {{icon:admin.users}} {{ui:icon.admin.users}}: 계정 목록, 승인 대기 계정, 계정 CSV 일괄 처리
2. {{icon:admin.groups}} {{ui:icon.admin.groups}}: 그룹과 구성원, 그룹 관리자
3. {{icon:admin.projects}} {{ui:icon.admin.projects}}: 프로젝트 목록. 여기서 새 프로젝트를 만들고 각 프로젝트의 수정·삭제 화면으로 갑니다.
4. {{icon:admin.tools}} {{ui:icon.admin.tools}}: 서버 운영 화면 묶음(아래 탭). 메뉴를 누르면 첫 탭인 {{ui:admin_tools.title}}가 열립니다.
5. {{icon:admin.system}} {{ui:icon.admin.system}}: 워커·큐·디스크·백업·도구·인증서 상태와 장애 경고(읽기 전용)
6. {{icon:admin.audit}} {{ui:icon.admin.audit}}: 감사 로그 조회·무결성 검사·CSV 내보내기(읽기 전용)
7. {{icon:admin.guide}} {{ui:icon.admin.guide}}: 지금 보고 있는 이 가이드

## 시스템 관리의 탭

{{ui:icon.admin.tools}} 화면 위쪽의 탭으로 옮겨 다닙니다.

- {{ui:admin_tools.title}}(/admin/tools): ruff·ESLint 버전, 체크섬·스모크 결과, 고정·차단, Node.js 상태, git·p4(읽기 전용)
- {{ui:admin_worker.title}}(/admin/worker): 워커 상태, 정지·재시작·시작 요청, 최근 로그
- {{ui:admin_checks.title}}(/admin/checks): 설정·환경 점검, 디스크 사용 현황, 정리 작업, 최근 실패한 분석
- {{ui:admin_runs.title}}(/admin/runs): 프로젝트별 Run 목록과 Run 상세 삭제
- {{ui:admin_rule_settings.title}}(/admin/rule-settings): 관리자가 켜거나 끈 규칙 목록과 되돌리기
- {{ui:config_settings.title}}(/admin/config-settings): 화면에서 바꿀 수 있는 서버 설정
- {{ui:admin_schedules.subnav}}(/admin/schedules): 스케줄러 상태, 프로젝트별 일정, 최근 실패·건너뜀
- {{ui:admin_backups.subnav}}(/admin/backups): 백업 목록, 지금 백업, 검증, 복원

### 설정 관리의 카테고리 탭

{{ui:config_settings.title}} 안에는 카테고리 탭이 한 줄 더 있습니다. 주소 끝의 해시로 바로 열 수도 있습니다.

- {{ui:config_settings.group.retention}}(#retention), {{ui:config_settings.group.disk}}(#disk)
- {{ui:config_settings.group.notification}}(#notification): 끝에 알림 보관 기간 구역이 있고, 구역마다 자기 저장 단추가 있습니다.
- {{ui:config_settings.group.monitoring}}(#monitoring): 헬스체크 허용 IP, 큐 정체 기준
- {{ui:config_settings.group.schedule}}(#schedule)
- {{ui:config_settings.group.repository}}(#repository): 맨 위에 허용 호스트 구역(추가·연결 테스트·삭제), 그 아래 연결 테스트 설정
- {{ui:config_settings.group.analysis}}(#analysis), {{ui:config_settings.group.backup}}(#backup)
- {{ui:config_settings.group.auth}}(#auth), {{ui:config_settings.group.auth_ldap}}(#auth_ldap), {{ui:config_settings.group.auth_oidc}}(#auth_oidc), {{ui:config_settings.group.auth_saml}}(#auth_saml)

화면 아래의 설정 항목 변경 확인 단추는 설정 항목만 저장합니다. 허용 호스트와 알림 보관 기간처럼 별도 구역에 있는 값은 그 구역의 단추로 저장합니다. 자세한 내용은 [설정 관리](guide:settings)를 보세요.

## 이 가이드의 절

설치와 운영

- [설치와 첫 설정](guide:install)
- [배포 묶음](guide:deploy-bundle)
- [분석 워커 운영](guide:worker)
- [분석 도구(bin) 관리](guide:tools-bin)
- [Linux 운영](guide:linux-ops)

계정과 권한

- [계정 관리](guide:accounts)
- [그룹과 프로젝트 접근 범위](guide:groups-access)
- [계정 CSV 일괄 처리](guide:account-csv)
- [외부 인증 개요](guide:external-auth)
- [LDAP/AD 인증](guide:auth-ldap)
- [SSO(OIDC)](guide:auth-oidc)
- [SSO(SAML)](guide:auth-saml)

서버 설정과 데이터

- [설정 관리](guide:settings)
- [보존 기간과 디스크](guide:retention-disk)
- [백업과 복원](guide:backup-restore)
- [알림 관리](guide:notifications-admin)
- [시스템 점검과 모니터링](guide:monitoring)
- [감사 로그](guide:audit-log)
- [보안 점검](guide:security)

프로젝트와 분석

- [프로젝트 생성·삭제](guide:projects-admin)
- [소스 저장소 연동](guide:repository)
- [주기 분석](guide:schedule)
- [규칙 관리](guide:rules-admin)

문제가 생겼을 때

- [문제 해결 모음](guide:troubleshooting): 로그인, 대기 중인 분석, 업로드, SSO, 설정 저장, 디스크 문제를 증상별로 점검합니다.

## 가이드 보는 법

- 목차에서 절을 고르면 그 절만 보입니다. 주소의 해시(#절 이름)를 북마크해 두면 그 절로 바로 돌아옵니다.
- 가이드 검색은 관리자 가이드 안에서만 찾습니다. 사용자 가이드 검색 결과와 섞이지 않습니다.
- 관리 화면의 도움말 링크를 누르면 그 화면을 설명하는 절이 열립니다.
- 오류 코드의 뜻과 조치는 [오류 코드](/guide/errors) 탭에서 찾을 수 있습니다.
