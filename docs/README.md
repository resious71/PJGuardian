# docs/ 목차

운영자·개발자용 문서다. 버전별 변경 내역은 저장소 루트의 `CHANGELOG.md`. 사용자·관리자가 화면에서 읽는 가이드 본문은 `resources/guide/`(앱의 가이드 메뉴)에 따로 있다. 단계별 작업 보고는 `docs/results/`(과거 보고서는 고치지 않음)에 있다. 문서를 더하거나 이름을 바꾸면 이 목차도 고치고 `php tools/check_doc_links.php`(깨진 링크 검사)를 돌린다.

## 설치·배포

| 문서 | 내용 |
| --- | --- |
| [install_guide.md](install_guide.md) | 설치 가이드(새 설치·옛 PC에서 옮기기·점검 목록) |
| [server_sizing.md](server_sizing.md) · [server_sizing.en.md](server_sizing.en.md) | 권장 서버 사양·디스크 용량 계산식과 예시(점검 탭 용량 추정 도우미와 같은 식, P32B) |
| [release_checklist.md](release_checklist.md) | **릴리스 체크리스트**(묶음·마이그레이션 순서·설정·워커·HTTPS/HSTS·방화벽·백업·롤백, P32 인수 시험 결과) |
| [xampp_vhost.md](xampp_vhost.md) | Apache VirtualHost(DocumentRoot = `public/`), HSTS는 앱 한 곳 |
| [xampp_upload_settings.md](xampp_upload_settings.md) | 청크 업로드를 위한 PHP·Apache 설정 |
| [vendor_build.md](vendor_build.md) | 프런트엔드 번들·Composer 의존성, **배포 묶음 만들기**(`tools/build_bundle.php`)와 점검 |
| [install_quick.en.md](install_quick.en.md) · [install_quick.ko.md](install_quick.ko.md) | 빠른 설치(영어·한국어 — 7단계 요약, P33) |
| [bin_setup.md](bin_setup.md) | `bin/` 도구 준비 요약(영어) — 공식 내려받기 위치·체크섬 대조·라이선스 주의, 도우미 `tools/fetch_bin.php`(P33) |
| [tool_setup.md](tool_setup.md) | 분석 도구(ruff·ESLint) 설치 |
| [vcs_tools.md](vcs_tools.md) | git·p4 준비 |
| [worker_setup.md](worker_setup.md) | 분석 워커 기동과 운영 |
| [linux_verification.md](linux_verification.md) | Linux 검증 절차 |
| [third_party.md](third_party.md) | 외부 구성요소 버전·라이선스·검증 방법 |

## 운영·보안

| 문서 | 내용 |
| --- | --- |
| [hardening.md](hardening.md) | 하드닝 체크리스트(초기 관리자 비밀번호, PHP·Apache, 점검 탭 보안 줄, P31 보안 점검 결과) |
| [monitoring.md](monitoring.md) | 시스템 모니터링·오류/경고 판정·헬스체크·관리자 장애 이메일 |
| [performance.md](performance.md) | 성능·부하 기준과 권장 설정(개발 PC 기준 숫자) |
| [backup_restore.md](backup_restore.md) | 백업·복원 |
| [settings.md](settings.md) | 설정 관리(화면에서 바꾸는 항목·우선순위·키 등록 절차) |
| [runs_retention.md](runs_retention.md) | Run 상세 보관과 수동 정리 |
| [export.md](export.md) | 내보내기 운영 안내 |
| [error_codes.md](error_codes.md) | 오류·실패 코드표 |
| [handover_checklist.md](handover_checklist.md) · [handover_checklist.en.md](handover_checklist.en.md) | 운영 인수인계 점검표(계정·시크릿·백업·워커·알림·외부 연동·인증서·절차서, P32B) |
| [unverified.md](unverified.md) | **미확인 목록** — 누가·어디서·어떻게 확인하나, 운영 반영 순서 |
| [spec_conformance.md](spec_conformance.md) | 명세 일치표(항목별 구현됨·변경됨·제외·미구현과 근거, P32) |
| [development_history.md](development_history.md) | 개발 히스토리(단계 연표·주요 결정·문제와 해결·코드·시험·작업 시간 통계, 1.0.0까지) |

## 공개·라이선스(P33)

| 문서 | 내용 |
| --- | --- |
| [features.md](features.md) · [features.ko.md](features.ko.md) | 주요 기능 소개와 스크린샷(영어·한국어, 그림 `docs/screenshots/`) |
| [publish_checklist.md](publish_checklist.md) | 오픈소스 공개 절차(공개용 트리 → 비공개 저장소 → GitHub 설정 → 외부 비밀 검사 → 공개·릴리스) |
| [ci_status.md](ci_status.md) | CI(GitHub Actions) 상태 — Linux 첫 실행 전, 단계·`--exclude-needs-bin`·첫 실행 방침(continue-on-error 제거 조건)·로컬 실행 |
| [legal/DISCLAIMER.md](legal/DISCLAIMER.md) · [legal/DISCLAIMER.ko.md](legal/DISCLAIMER.ko.md) | 무보증·책임 제한·운영자 책임 |
| [legal/TRADEMARKS.md](legal/TRADEMARKS.md) | 제3자 상표, 이름 "PJGuardian" 사용 범위 |
| [legal/PRIVACY.md](legal/PRIVACY.md) | 텔레메트리 없음·외부 연결 지점·저장 데이터와 보존 기간 |
| [legal/AI_ASSISTED.md](legal/AI_ASSISTED.md) | AI 보조 개발 고지 |

저장소 루트의 `LICENSE`(Apache-2.0)·`NOTICE`·`THIRD_PARTY_NOTICES`·`README.md`(`README.ko.md`)·`CONTRIBUTING.md`·`SECURITY.md`·`CODE_OF_CONDUCT.md`도 함께 본다.

## 인증·연동

| 문서 | 내용 |
| --- | --- |
| [account_csv.md](account_csv.md) | 계정 CSV 일괄 처리 |
| [auth_ldap.md](auth_ldap.md) | LDAP/AD 로그인과 승인 대기 |
| [auth_oidc.md](auth_oidc.md) | SSO — OpenID Connect |
| [auth_saml.md](auth_saml.md) | SSO — SAML 2.0 |
| [repo_integration.md](repo_integration.md) | 소스 저장소 연동 |

## 개발 규약·설계

| 문서 | 내용 |
| --- | --- |
| [architecture.md](architecture.md) | 아키텍처 규약(디렉토리·계층·라우트·응답·설정·로그·시험·시간·경로 …) |
| [db_schema.md](db_schema.md) | DB 스키마 설계와 마이그레이션별 변경 |
| [openapi.yaml](openapi.yaml) | REST API 명세(라우트를 더하면 함께 고침 — `OpenApiTest`) |
| [audit_actions.md](audit_actions.md) | 감사 로그 작업 이름·세부 정보·설명 문장, 보존과 체인(DB 밖 기록) |
| [ui_conventions.md](ui_conventions.md) | 화면 규칙 |
| [icons.md](icons.md) | 아이콘 세트와 모듈 |
| [i18n_glossary.md](i18n_glossary.md) | 화면 영어 용어집 |
| [guide_authoring.md](guide_authoring.md) | 가이드 작성·유지 규칙 |
| [faq_sources.md](faq_sources.md) | FAQ 초안 출처 |
| [rules.md](rules.md) | 규칙 설명 데이터 |
| [severity_mapping.md](severity_mapping.md) | 규칙 기본 심각도 매핑 |
