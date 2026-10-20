# FAQ 초안 출처 목록 (P29B)

FAQ 답을 쓸 때 근거로 삼을 확정 사실이 어느 문서의 어느 절에 있는지만 모은 목록이다. 내용(수치·문장)은 옮기지 않았다. FAQ 본문은 관리자가 화면에서 직접 쓴다.
가이드·운영 문서·설정 예시·명세의 절이 바뀌면 이 목록도 함께 고친다. `docs/results/`(단계 보고서)는 지난 기록이라 출처로 쓰지 않는다.

표기: 사용자 가이드 = `resources/guide/user/ko/`, 관리자 가이드 = `resources/guide/admin/ko/`(파일 이름 = 번호_key, 따옴표 안은 `##` 소제목). 명세 = `PJGuardian_requirements.md`(장·절 번호). 설정 = `config/config.ini.example`(섹션·키 이름).

## 1. 업로드 크기·파일 수 한도

- 출처: 사용자 가이드 `03_upload.md` — "한도"
- 출처: 사용자 가이드 `04_upload-errors.md` — "크기와 파일 수 한도", "업로드를 시작하지 못할 때"
- 출처: 설정 `[upload]` — `max_zip_size`, `chunk_size`, `max_concurrent_per_user`; `[extract]` — `max_extracted_size`, `max_files`
- 출처: `docs/xampp_upload_settings.md` — 1절 "청크를 받는 방식과 영향", 2절 "php.ini", 3절 "Apache", 4절 "임시 폴더와 디스크"
- 출처: 관리자 가이드 `01_install.md` — "php.ini와 Apache 한도"
- 출처: 명세 5.1, 5.4, 19.10 "청크 업로드 (P06)"·"업로드 API (P12 결정)"

## 2. 업로드 방법·이어 올리기·사전 검사 경고·최상위 폴더

- 출처: 사용자 가이드 `03_upload.md` — "올리는 방법", "이어 올리기와 일시 중지", "사전 검사와 경고 확인", "최상위 폴더 처리", "자주 하는 실수"
- 출처: 사용자 가이드 `04_upload-errors.md` — "ZIP 파일 자체의 문제", "허용하지 않는 항목", "파일 이름 문제", "서버 처리 중 문제"
- 출처: 설정 `[upload]` — `incomplete_chunk_retention`, `confirmation_retention`, `failed_zip_retention`
- 출처: 관리자 가이드 `22_troubleshooting.md` — "업로드가 실패할 때"
- 출처: 명세 5.1, 5.4, 19.10 "zip 해제 (P05)"·"안전 해제와 후처리 (P13 결정)"·"업로드 화면 보완 (X04 결정)"

## 3. 지원 언어·분석 도구(ruff·ESLint)와 버전

- 출처: 사용자 가이드 `02_projects.md` — "프로젝트란"
- 출처: 사용자 가이드 `10_history.md` — "버전/설정 변경 배지"
- 출처: 관리자 가이드 `04_tools-bin.md` — "분석 도구와 bin/", "버전을 고르는 방식과 검증", "버전 변경이 분석에 주는 영향", "Node.js 런타임"
- 출처: `docs/tool_setup.md` — 1절 "ruff", 2절 "Node.js", 3절 "ESLint", 5절 "검증과 선택"
- 출처: 설정 `[upload]` — `target_extensions_python`, `target_extensions_javascript`; `[tools]` — `node_path`, `node_min_version`
- 출처: 명세 2장, 12장, 19.1, 19.10 "분석 도구 (P03)"

## 4. Run 개념(Run 번호와 분석 번호)

- 출처: 사용자 가이드 `05_analysis-status.md` — "Run 번호"
- 출처: 사용자 가이드 `10_history.md` — "Run과 분석 번호", "과거 Run 보기"
- 출처: 사용자 가이드 `07_defects.md` — "과거 Run 보기"
- 출처: 관리자 가이드 `17_projects-admin.md` — "Run 상세 보관", "Run 상세 수동 삭제"
- 출처: `docs/runs_retention.md` — 1절 "무엇이 남고 무엇이 지워지나", 2절 "설정", 3절 "수동 삭제"
- 출처: `docs/architecture.md` — 30절 "Run(분석 회차)과 Run별 결함 현황", 35절 "Run 상세 보관과 관리자 삭제"
- 출처: 설정 `[runs]` — `detail_keep`
- 출처: 명세 19.10 "Run(분석 회차)과 Run별 결함 현황 (P20R)"

## 5. 분석 상태와 대기열

- 출처: 사용자 가이드 `05_analysis-status.md` — "분석 상태 보기", "상태의 뜻", "대기열 규칙", "분석 취소", "성공한 분석의 결과"
- 출처: 관리자 가이드 `03_worker.md` — "워커가 하는 일"
- 출처: 관리자 가이드 `22_troubleshooting.md` — "분석이 대기에서 멈춘 것 같을 때"
- 출처: 설정 `[analysis]` — `max_concurrent`, `timeout`
- 출처: 명세 5.2, 19.8, 19.10 "작업 큐와 워커 (P14 결정)"

## 6. 결함 상태·심각도·담당자

- 출처: 사용자 가이드 `07_defects.md` — "심각도", "상태", "담당자·심각도·상태 바꾸기", "일괄 변경", "변경 이력", "동시 수정 충돌", "검색과 필터", "내 결함"
- 출처: 관리자 가이드 `18_rules-admin.md` — "규칙별 기본 심각도"
- 출처: `docs/severity_mapping.md` — 1절 "심각도 5단계와 분류 기준", 2절 "정하는 순서(우선순위)", 3절 "자동 부여와 사람이 바꾼 값"
- 출처: 설정 `[severity]` — `auto_assign`; `[ui]` — `bulk_update_max`
- 출처: 명세 6.2, 6.4

## 7. 자동 resolved(자동 해결)와 재오픈

- 출처: 사용자 가이드 `07_defects.md` — "자동 해결과 재오픈"
- 출처: `docs/architecture.md` — 24절 "결함 저장·시그니처·자동 resolved·스냅샷"
- 출처: 명세 6.3, 19.10 "도구 실행과 결함 저장 구현 결과 (P15·P16)"

## 8. 시그니처(같은 결함으로 이어지는 규칙)

- 출처: 사용자 가이드 `07_defects.md` — "결함 화면 열기"(같은 결함으로 이어지는 설명), "자동 해결과 재오픈"
- 출처: 사용자 가이드 `03_upload.md` — "사전 검사와 경고 확인"(최상위 폴더가 달라질 때 경로 영향)
- 출처: `docs/architecture.md` — 24절 "결함 저장·시그니처·자동 resolved·스냅샷"
- 출처: 명세 6.1, 19.10 "결함 시그니처 (P04)", "도구 실행과 결함 저장 구현 결과 (P15·P16)"(알려진 한계)

## 9. 분석 실패와 문법 오류의 차이

- 출처: 사용자 가이드 `05_analysis-status.md` — "상태의 뜻"
- 출처: 사용자 가이드 `06_analysis-failures.md` — "실패 정보가 보이는 곳", "분석 범위가 너무 큼", "업로드한 설정 파일 문제", "분석 도구 문제", "서버 저장 공간과 결과 저장", "작업이 중간에 멈춤"
- 출처: 사용자 가이드 `09_rules.md` — "결함에서 규칙으로 가기"(`syntax-error` 특수 키)
- 출처: `docs/error_codes.md` — 2절 "분석 실패"
- 출처: 설정 `[analysis]` — `timeout`, `output_limit`, `process_memory_limit`; `[limits]` — `project_defect_warn`, `project_defect_max`
- 출처: 명세 6.1, 15.2

## 10. 도구 설정 파일 정책(ruff·ESLint)

- 출처: 사용자 가이드 `03_upload.md` — "도구 설정 파일 규칙"
- 출처: 사용자 가이드 `06_analysis-failures.md` — "업로드한 설정 파일 문제"
- 출처: `docs/architecture.md` — 23절 "분석 도구 실행과 결과 파일"
- 출처: 명세 5.3(19.10이 대체), 19.10 "분석 도구 (P03)"

## 11. 제외 대상

- 출처: 사용자 가이드 `03_upload.md` — "기본 제외 대상"
- 출처: 관리자 가이드 `17_projects-admin.md` — "프로젝트 만들기", "프로젝트 고치기"
- 출처: 설정 `[extract]` — `default_excludes`
- 출처: `docs/architecture.md` — 17절 "프로젝트와 제외 패턴"(17.1 제외 판정)
- 출처: 명세 19.10 "프로젝트 관리 (P11)"(제외 패턴 문법), "도구 실행과 결함 저장 구현 결과 (P15·P16)"(분석 범위)

## 12. 코드 뷰어·스냅샷 보관

- 출처: 사용자 가이드 `08_code-viewer.md` — "무엇을 보여 주나", "결함 위치 표시", "보기 범위와 줄 바꿈", "코드 스냅샷과 발췌본", "과거 Run에서 보기", "자주 묻는 점"
- 출처: 관리자 가이드 `13_retention-disk.md` — "정리 작업이 지우는 것"
- 출처: 설정 `[snapshot]` — `full_keep_count`, `excerpt_context_lines`; `[viewer]` — `code_window_lines`, `code_max_lines`, `code_max_line_length`, `code_max_file_size`
- 출처: `docs/architecture.md` — 25절 "결함 목록·상세·코드 뷰어"
- 출처: 명세 7장, 19.10 "도구 실행과 결함 저장 구현 결과 (P15·P16)"(스냅샷)

## 13. 히스토리·비교·추이

- 출처: 사용자 가이드 `10_history.md` — "히스토리 열기", "추이 차트", "직전 Run과 비교하기", "버전/설정 변경 배지", "주의"
- 출처: `docs/architecture.md` — 32절 "히스토리와 분석 비교"
- 출처: 설정 `[history]` — `scope_change_ratio`; `[ui]` — `history_page_size`
- 출처: 명세 8장

## 14. 내보내기

- 출처: 사용자 가이드 `13_export.md` — "내보내기 단계", "무엇이 내보내지나요", "Excel과 CSV의 차이", "수식 문자 보호", "한도와 오류"
- 출처: `docs/export.md` — 1절 "설정", 2절 "임시 파일과 잠금", 3절 "감사 로그", 4절 "파일"
- 출처: 설정 `[export]` — `max_rows`, `max_concurrent`, `max_per_user`, `timeout_seconds`
- 출처: 명세 15.1

## 15. 알림

- 출처: 사용자 가이드 `12_notifications.md` — "알림 종류", "헤더 배지와 알림 패널", "알림 목록에서 정리하기", "알림 켜기와 끄기", "내게 지정된 결함", "보관 기간"
- 출처: 관리자 가이드 `19_notifications-admin.md` — "계정·인증 알림", "분석 운영 알림", "백업 알림", "알림 보관 기간"
- 출처: 설정 `[notification]` — `retention`, `admin_alert_dedupe`, `cert_expiry_warn`; `[ui]` — `notification_poll_seconds`
- 출처: `docs/architecture.md` — 40절 "앱 안 알림"
- 출처: 명세 17장

## 16. 대시보드

- 출처: 사용자 가이드 `11_dashboard.md` — "화면 구성", "필터와 프로젝트 선택", "표로 보기", "새로 고침", "주의"
- 출처: 설정 `[dashboard]` — `cache_ttl`; `[ui]` — `dashboard_trend_count`, `dashboard_top_n`
- 출처: `docs/architecture.md` — 29절 "대시보드 — 집계 정의"
- 출처: 명세 9장

## 17. 규칙 설명과 규칙 켜기·끄기

- 출처: 사용자 가이드 `09_rules.md` — "언어별 탭", "규칙 찾기", "규칙 상세", "버전별 변경 내역", "결함에서 규칙으로 가기", "꺼진 규칙", "주의"
- 출처: 관리자 가이드 `18_rules-admin.md` — "규칙 하나 켜기·끄기", "목록에서 바꾸기와 일괄 변경", "규칙 설정 탭", "개정 번호와 충돌"
- 출처: `docs/rules.md` — 1절 "데이터 출처", 3절 "자동 갱신", 4절 "버전 변경 내역 판정", 5a절 "관리자 규칙 켜기·끄기", 6절 "설명 없음 기준"
- 출처: 설정 `[rules]` — `auto_load`, `load_interval`, `override_reset_max`, `bulk_max`
- 출처: 명세 11장

## 18. 저장소 연동·주기 분석·성공(변경 없음)

- 출처: 사용자 가이드 `05_analysis-status.md` — "주기 분석과 재시도"
- 출처: 사용자 가이드 `06_analysis-failures.md` — "저장소에서 가져오기 실패(repo_ 로 시작하는 코드)"
- 출처: 사용자 가이드 `10_history.md` — "성공(변경 없음)"
- 출처: 관리자 가이드 `15_repository.md` — "준비: 암호화 키와 허용 호스트", "허용 호스트 연결 테스트", "프로젝트에 저장소 연결하기", "지금 가져와 분석", "실패했을 때", "심볼릭 링크와 안전 규칙"
- 출처: 관리자 가이드 `16_schedule.md` — "주기 켜기", "일정은 어떻게 정해지나", "실패와 재시도", "성공(변경 없음)", "연속 실패가 이어질 때"
- 출처: `docs/repo_integration.md` — 1절 "보안 모델", 5절 "허용 호스트", 6절 "오류 코드", 9절 "주기 분석", 10절 "허용 호스트 연결 테스트", 11절 "저장소 심볼릭 링크 건너뛰기", 13절 "성공(변경 없음)"
- 출처: 설정 `[repository]` — `fetch_timeout`, `min_interval`, `default_interval`; `[scheduler]` — `tick_seconds`, `max_per_tick`
- 출처: 명세 18장(18.1~18.5)

## 19. 로그인·비밀번호·잠금·임시 비밀번호·세션

- 출처: 사용자 가이드 `01_getting-started.md` — "로그인하기", "비밀번호 규칙", "임시 비밀번호", "세션 만료", "계정 잠금"
- 출처: 사용자 가이드 `15_login-errors.md` — "아이디 또는 비밀번호가 올바르지 않을 때", "계정 잠금", "로그인이 잠시 제한될 때", "임시 비밀번호가 만료됐을 때", "세션이 만료됐을 때"
- 출처: 사용자 가이드 `14_account.md` — "비밀번호 바꾸기"
- 출처: 관리자 가이드 `05_accounts.md` — "비밀번호: 임시 비밀번호와 직접 지정", "잠금 해제"
- 출처: 관리자 가이드 `21_security.md` — "세션과 로그인 잠금"
- 출처: 관리자 가이드 `01_install.md` — "최초 관리자"
- 출처: `docs/hardening.md` — 0절 "가장 먼저: 관리자 초기 비밀번호 변경"
- 출처: 설정 `[auth]` — `password_min_length`, `password_min_char_classes`, `lockout_max_failures`, `lockout_duration`, `ip_max_failures`, `ip_block_duration`, `session_idle`, `temp_password_expiry`
- 출처: 명세 3장, 13장, 19.10 "인증·세션·권한 (P10)"

## 20. 외부 인증(LDAP/AD·OIDC·SAML)과 승인 대기

- 출처: 사용자 가이드 `01_getting-started.md` — "승인 대기 계정"
- 출처: 사용자 가이드 `14_account.md` — "조직 계정(LDAP·SSO)의 비밀번호"
- 출처: 사용자 가이드 `15_login-errors.md` — "조직 계정 안내 상자", "SSO 오류 코드"
- 출처: 관리자 가이드 `08_external-auth.md` — "모든 방식에 공통인 규칙", "승인 대기 흐름", "인증 방식 전환", "로컬 관리자 비상 로그인", "공통 문제 해결"
- 출처: 관리자 가이드 `05_accounts.md` — "승인 대기 계정", "인증 방식 변경"
- 출처: 관리자 가이드 `09_auth-ldap.md`, `10_auth-oidc.md`, `11_auth-saml.md` — 각 "준비", 설정 탭 절, "연결 시험"/"설정 확인", "흔한 오류", "운영 점검표"
- 출처: `docs/auth_ldap.md` — 4절 "승인 대기 계정", 5절 "인증 방식 변경", 7절 "실제 서버 점검표"
- 출처: `docs/auth_oidc.md` — 4절 "계정 결정", 5절 "보안 동작", 6절 "장애 대응"
- 출처: `docs/auth_saml.md` — 5절 "인증서 롤오버·만료 경고", 6절 "검증 규칙", 7절 "장애 대응"
- 출처: 설정 `[auth]` — `pending_max`, `pending_retention_days`; `[ldap]`, `[oidc]`, `[saml]` 섹션
- 출처: 명세 19.2

## 21. 계정 관리·계정 CSV 일괄 처리

- 출처: 사용자 가이드 `14_account.md` — "계정 정보 확인", "이름과 이메일 바꾸기"
- 출처: 관리자 가이드 `05_accounts.md` — "계정 추가", "정보 수정", "역할 변경과 비활성화"
- 출처: 관리자 가이드 `07_account-csv.md` — "CSV 형식", "인코딩", "미리보기에서 실행까지", "임시 비밀번호와 결과 CSV", "비활성화 행", "흔한 오류"
- 출처: `docs/account_csv.md` — 1절 "형식", 2절 "인코딩", 4절 "검증 규칙"
- 출처: 명세 13장, 19.2, 19.10 "사용자 정보 수정 (X05 결정)"

## 22. 권한(admin·그룹 관리자·일반 사용자)과 보이지 않는 프로젝트 404

- 출처: 사용자 가이드 `02_projects.md` — "내가 볼 수 있는 프로젝트", "그룹 관리자가 할 수 있는 일"
- 출처: 관리자 가이드 `00_overview.md` — "관리자가 할 수 있는 일"
- 출처: 관리자 가이드 `06_groups-access.md` — "그룹 만들기와 관리", "그룹 관리자가 할 수 있는 일", "프로젝트 접근 매핑", "접근이 바뀌면 생기는 일"
- 출처: `docs/architecture.md` — 16절 "인증과 권한", 18절 "사용자 그룹", 19절 "프로젝트 접근 범위"
- 출처: 명세 3.1, 13장, 19.10 "사용자 그룹과 프로젝트 접근 범위 (P11A·P11B)"

## 23. 프로젝트 만들기·삭제

- 출처: 사용자 가이드 `02_projects.md` — "프로젝트란", "프로젝트 목록", "프로젝트 상세"
- 출처: 관리자 가이드 `17_projects-admin.md` — "프로젝트 만들기", "프로젝트 고치기", "프로젝트 삭제"
- 출처: `docs/architecture.md` — 17절(17.2 프로젝트 삭제 순서)
- 출처: 명세 4장, 19.10 "프로젝트 관리 (P11)"

## 24. 화면 언어·테마

- 출처: 사용자 가이드 `01_getting-started.md` — "화면 언어와 테마 바꾸기"
- 출처: 사용자 가이드 `14_account.md` — "화면 언어와 테마"
- 출처: 사용자 가이드 `17_shortcuts-and-icons.md` — "화면 언어와 테마"
- 출처: `docs/i18n_glossary.md` — 2절 "핵심 용어", 4절 "번역하지 않는 구간(알려진 한계)"
- 출처: `docs/architecture.md` — 47절 "화면 언어·테마"

## 25. 화면 읽는 법·아이콘·키보드

- 출처: 사용자 가이드 `17_shortcuts-and-icons.md` — "아이콘 읽는 법", "아이콘 범례", "도움말 링크", "키보드로 다루기"
- 출처: 명세 20장(20.3 의미별 아이콘 정의, 20.4 접근성과 직관성 보완)

## 26. 백업·복원

- 출처: 관리자 가이드 `14_backup-restore.md` — "백업 묶음에 들어가는 것", "백업하기", "보관 규칙", "암호화 키 내보내기", "복원하기(화면)", "명령줄로 백업·복원(다른 PC로 옮기기)", "복원 리허설", "복원 뒤 확인할 것", "문제 해결"
- 출처: `docs/backup_restore.md` — 1절 "무엇을 백업하는가", 2절 "백업 폴더와 보관", 3절 "복원", 4절 "암호화 키(.pjgkey)", 5절 "명령줄", 7절 "문제 해결"
- 출처: 설정 `[backup]` — `dir`, `enabled`, `schedule_mode`, `daily_at`, `retention_days`, `retention_min_count`, `retention_max_count`
- 출처: 명세 14장, 19.4

## 27. 설정 관리(관리자 화면)

- 출처: 관리자 가이드 `12_settings.md` — "화면 구성", "값 바꾸기", "출처 배지와 우선순위", "바꿀 수 없는 항목", "경로 항목: 경로 확인과 폴더 만들기", "변경 이력과 백업"
- 출처: `docs/settings.md` — 1절 "저장 위치와 우선순위", 2절 "반영 시점", 3절 "허용 목록", 4절 "제외(편집·표시 안 함)와 이유"
- 출처: 관리자 가이드 `22_troubleshooting.md` — "설정이 저장되지 않을 때"

## 28. 보존 기간·디스크

- 출처: 관리자 가이드 `13_retention-disk.md` — "정리 작업이 지우는 것", "정리 작업 돌리기", "디스크 경고와 차단", "로그 확인"
- 출처: 관리자 가이드 `22_troubleshooting.md` — "디스크가 부족할 때"
- 출처: 설정 `[disk]` — `warn_percent`, `block_percent`; `[log]` — `error_access_retention`, `audit_retention`
- 출처: 명세 19.3

## 29. 워커·분석 도구 운영(관리자)

- 출처: 관리자 가이드 `03_worker.md` — "명령", "서비스로 등록하기", "관리자 화면: 워커 탭", "워커를 다시 시작해야 하는 경우", "장애 대응"
- 출처: 관리자 가이드 `04_tools-bin.md` — "새 버전 설치", "옛 버전 삭제", "git·p4 배치", "문제 해결"
- 출처: `docs/worker_setup.md` — 5절 "자원 제한과 OS별 차이", 7절 "장애 대응", 8절 "관리자 화면에서 워커 다루기"
- 출처: `docs/tool_setup.md` — 6절 "설치·삭제 절차"
- 출처: 명세 12장, 19.1

## 30. 설치·보안 운영·점검·감사 로그

- 출처: 관리자 가이드 `01_install.md` — "필요한 것", "설치 순서", "배포 후 확인"
- 출처: 관리자 가이드 `20_monitoring.md` — "점검 탭 읽는 법", "감사 로그"
- 출처: 관리자 가이드 `21_security.md` — "비밀 값과 암호화 키", "TLS 인증서 검증", "하드닝 점검"
- 출처: 관리자 가이드 `23_linux-ops.md` — "Windows와 다르게 동작하는 부분", "운영 환경에서 확인할 것"
- 출처: `docs/install_guide.md` — 1절 "준비물", 9절 "설치 확인", 11절 "업그레이드", 12절 "체크리스트"
- 출처: `docs/hardening.md` — 1~5절
- 출처: 명세 14장, 19.5, 19.9

## 31. 오류 코드

- 출처: 사용자 가이드 `16_errors-help.md` — "오류 메시지 읽는 법", "오류 코드 탭", "자세히 보기 링크", "먼저 해 볼 일", "관리자에게 문의할 때"
- 출처: 사용자 가이드 `04_upload-errors.md`(업로드 오류), `06_analysis-failures.md`(분석 실패), `15_login-errors.md` — "SSO 오류 코드"
- 출처: `docs/error_codes.md` — 1절 "업로드 오류", 2절 "분석 실패", 3절 "업로드 시작 단계", 4절 "결함 수정·조회"
- 출처: `docs/repo_integration.md` — 6절 "오류 코드"
- 출처: 관리자 가이드 `22_troubleshooting.md` — "SSO 로그인 오류"
- 출처: 명세 15.2

## 시드 파일과 가져오기 도구 (X16)

- 시드: `resources/faq/seed.ko.json`(한국어, `{"version":1,"items":[{category,question,answer}]}` — 파일 순서 = 표시 순서). 웹 루트 밖이고 배포 묶음에 있어야 한다(`tools/verify_bundle.php`가 검사).
- 넣기: `php tools/faq_import.php --dry-run` → `php tools/faq_import.php --draft`(비공개로 넣고 화면에서 검토·공개 전환) 또는 `php tools/faq_import.php`(바로 공개). 같은 (분류, 질문)은 건너뛰고 덮어쓰지 않으며, 검증 실패가 하나라도 있으면 아무것도 쓰지 않는다. 감사 `faq.import` 한 줄.
- 낡음 방지: `tests/Integration/FaqSeedTest`가 길이·중복·분량, 답변 링크가 실제 사용자 가이드 절로 풀리는지(FAQ 화면에서 링크가 되는 글자인지), 큰따옴표로 인용한 화면 문구가 실제 ko 문구인지, 금지어를 검사한다. **사용자 가이드 절 key·화면 문구를 바꾸면 시드도 고친다.** 이미 DB에 들어간 FAQ는 관리자가 화면에서 고친다(도구는 덮어쓰지 않음).
- 수치: 설정으로 바뀌는 값(잠금 횟수·세션·보관 기간·내보내기 행 수 등)은 숫자 대신 "관리자가 정한 기준"으로 쓰고 가이드 절로 보낸다.

| 번호 | 분류 | 질문 | 근거(답변 끝 링크 = 사용자 가이드 절) |
| --- | --- | --- | --- |
| 1 | 계정·로그인 | 비밀번호를 잊었어요. | `/guide/user#getting-started` — 01_getting-started.md (시작하기) |
| 2 | 계정·로그인 | 비밀번호를 바꾸고 싶어요. | `/guide/user#account` — 14_account.md (내 정보) |
| 3 | 계정·로그인 | 비밀번호가 맞는데 로그인이 안 돼요. 계정이 잠긴 건가요? | `/guide/user#login-errors` — 15_login-errors.md (로그인 오류 해결) |
| 4 | 계정·로그인 | 잠시 자리를 비웠더니 다시 로그인하라고 해요. | `/guide/user#login-errors` — 15_login-errors.md (로그인 오류 해결) |
| 5 | 계정·로그인 | 회사 계정(SSO·LDAP)으로 로그인했는데 승인 대기라고 나와요. | `/guide/user#getting-started` — 01_getting-started.md (시작하기) |
| 6 | 계정·로그인 | 화면 언어나 테마(다크 모드)는 어떻게 바꾸나요? | `/guide/user#getting-started` — 01_getting-started.md (시작하기) |
| 7 | 프로젝트·업로드 | 내 프로젝트가 목록에 안 보여요. | `/guide/user#projects` — 02_projects.md (프로젝트 보기) |
| 8 | 프로젝트·업로드 | 폴더와 ZIP 파일 중 무엇을 올려야 하나요? | `/guide/user#upload` — 03_upload.md (업로드) |
| 9 | 프로젝트·업로드 | 올리다가 끊겼어요. 처음부터 다시 해야 하나요? | `/guide/user#upload` — 03_upload.md (업로드) |
| 10 | 프로젝트·업로드 | 업로드 뒤 "확인이 필요합니다"가 나왔어요. 계속 진행해도 되나요? | `/guide/user#upload` — 03_upload.md (업로드) |
| 11 | 프로젝트·업로드 | 어떤 파일이 분석에서 제외되나요? | `/guide/user#upload` — 03_upload.md (업로드) |
| 12 | 프로젝트·업로드 | 직전 업로드와 같은 파일이라고 나와요. | `/guide/user#upload` — 03_upload.md (업로드) |
| 13 | 분석 | 분석이 대기 상태에서 오래 머물러요. | `/guide/user#analysis-status` — 05_analysis-status.md (분석 상태) |
| 14 | 분석 | 분석 상태는 각각 무슨 뜻인가요? | `/guide/user#analysis-status` — 05_analysis-status.md (분석 상태) |
| 15 | 분석 | 분석을 취소하려면 어떻게 하나요? | `/guide/user#analysis-status` — 05_analysis-status.md (분석 상태) |
| 16 | 분석 | 분석이 실패했어요. 어떻게 하나요? | `/guide/user#analysis-failures` — 06_analysis-failures.md (분석 실패 해결) |
| 17 | 분석 | 내 ESLint 설정 파일이 분석에 적용되지 않았어요. | `/guide/user#upload` — 03_upload.md (업로드) |
| 18 | 분석 | 분석 결과가 성공(변경 없음)이라고 나와요. | `/guide/user#history` — 10_history.md (히스토리) |
| 19 | 결함 관리 | 심각도와 상태는 무슨 뜻인가요? | `/guide/user#defects` — 07_defects.md (결함 관리) |
| 20 | 결함 관리 | 결함이 저절로 해결됨으로 바뀌거나 다시 열렸어요. | `/guide/user#defects` — 07_defects.md (결함 관리) |
| 21 | 결함 관리 | 결함에 담당자를 지정하려면 어떻게 하나요? | `/guide/user#defects` — 07_defects.md (결함 관리) |
| 22 | 결함 관리 | 저장하려는데 "다른 사용자가 먼저 변경했습니다"라고 나와요. | `/guide/user#defects` — 07_defects.md (결함 관리) |
| 23 | 결함 관리 | 내게 지정된 결함은 어디서 보나요? | `/guide/user#defects` — 07_defects.md (결함 관리) |
| 24 | 결함 관리 | 결함이 난 코드 위치는 어떻게 확인하나요? | `/guide/user#code-viewer` — 08_code-viewer.md (코드 뷰어) |
| 25 | 결함 관리 | 여러 결함을 한 번에 바꾸려면 어떻게 하나요? | `/guide/user#defects` — 07_defects.md (결함 관리) |
| 26 | 규칙·히스토리·대시보드 | 규칙 설명 페이지는 무엇인가요? | `/guide/user#rules` — 09_rules.md (규칙 설명) |
| 27 | 규칙·히스토리·대시보드 | 새로 생긴 결함과 해결된 결함은 어디서 비교하나요? | `/guide/user#history` — 10_history.md (히스토리) |
| 28 | 규칙·히스토리·대시보드 | 대시보드 숫자는 어떻게 읽나요? | `/guide/user#dashboard` — 11_dashboard.md (대시보드) |
| 29 | 규칙·히스토리·대시보드 | 지난 Run 시점의 결함 현황은 어디서 보나요? | `/guide/user#history` — 10_history.md (히스토리) |
| 30 | 내보내기·알림 | 결함 목록을 Excel이나 CSV로 내보내려면 어떻게 하나요? | `/guide/user#export` — 13_export.md (내보내기) |
| 31 | 내보내기·알림 | 어떤 알림이 오고, 끌 수 있나요? | `/guide/user#notifications` — 12_notifications.md (앱 내 알림) |
| 32 | 내보내기·알림 | 받았던 알림이 사라졌어요. | `/guide/user#notifications` — 12_notifications.md (앱 내 알림) |
| 33 | 오류·문의 | 오류 메시지의 뜻은 어디서 찾나요? | `/guide/errors`<br>`/guide/user#errors-help` — 16_errors-help.md (오류 메시지 해결) |
| 34 | 오류·문의 | 화면이 이상하거나 최신 내용이 보이지 않아요. | `/guide/user#errors-help` — 16_errors-help.md (오류 메시지 해결) |
| 35 | 오류·문의 | 관리자에게 문의할 때 무엇을 알려야 하나요? | `/guide/user#errors-help` — 16_errors-help.md (오류 메시지 해결) |
