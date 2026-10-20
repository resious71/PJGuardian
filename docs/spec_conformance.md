# 명세 일치표 (P32)

요구사항 명세 `PJGuardian_requirements.md`의 장·절마다 구현 상태와 근거를 적은 표다. 1.0.0 첫 릴리스 인수(P32)를 위해 만들었다. 작성일은 2026-10-14이다(같은 날 P32B에서 미구현 6건을 구현해 갱신). 명세는 고치지 않았고, 명세 19.10(프로토타입 검증 반영 사항)이 앞 장과 충돌하면 19.10을 기준으로 판정했다.

## 상태 정의

| 상태 | 뜻 |
| --- | --- |
| **구현됨** | 명세대로 구현했다. 근거 칸에 코드 경로, 시험, 단계 보고를 적는다. |
| **변경됨** | 결정 기록에 따라 명세 본문과 다르게 구현했다. 근거 칸에 결정 출처(단계 번호와 보고서 경로, 또는 명세 19.10·부록 항목)를 적는다. |
| **제외** | 결정 기록에 따라 일부러 만들지 않았다. |
| **미구현** | 명세에 있지만 구현하지 않았고 결정 기록도 없다. 아래 "미구현" 절에 무엇을 찾아봤는지 적었다. |

- **미확인**은 상태가 아니다. 구현은 했지만 실제 환경(Linux, 운영 Apache, 실제 LDAP·IdP·SMTP·Perforce 서버, 실제 Excel, 스크린 리더 등)에서 아직 확인하지 못한 항목이다. 비고 칸에 "미확인"으로 표시했고, 누가·어디서·어떻게 확인할지는 `docs/unverified.md`에 모아 두었다.
- 단계 보고는 지금 디스크에 있는 경로로 적었다. P07~P30B·X01~X20 보고는 `docs/results/finish/`에, P31 보고는 `docs/results/P31.md`에 있다.
- 근거로 적은 경로는 모두 작성 시점에 저장소에서 있는 것을 확인했다(`docs/release_checklist.md`처럼 P32에서 만들 예정인 문서는 근거로 쓰지 않았다).

## 요약

| 상태 | 행 수 |
| --- | --- |
| 구현됨 | 247 |
| 변경됨 | 16 |
| 제외 | 7 |
| 미구현 | 0 |
| **합계** | **270** |

- 행은 명세의 절을 요구사항 단위로 나눈 것이다. 같은 결정이 여러 행에 나오면 행마다 셌다(예: 도구 설정 정책 결정 하나가 5.3 두 행·10·15.2·부록의 다섯 행). 결정 단위로 세면 변경 결정 10개, 제외 결정 4개다(아래 "변경·제외 결정 목록").
- 변경·제외 항목은 모두 결정 기록이 있다.
- P32 검수 때 미구현이던 6건(명세 11·19.1·19.3·19.4·19.9·19.10 P10)은 P32B에서 구현해 "구현됨"으로 바꿨다(2026-10-14). 지금 미구현은 0건이다.

## 1. 목적 및 범위

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 1 | 설계 규모(사용자 1,000명, 프로젝트 200개, 분석 1회 결함 10만 건) | 구현됨 | `tools/seed_defects.php`, `tests/Integration/LargeApplyTest.php`, `tests/Integration/DefectLimitTest.php`, `docs/performance.md`, `docs/results/finish/P19C.md` | 프로젝트당 결함 수 경고·상한을 둔다(P19C). 숫자는 개발 PC 기준이며 운영 서버 숫자는 미확인 |
| 1 | UI 한국어, 문자열 리소스 분리 | 구현됨 | `src/Lang/ko.php`, `public/assets/js/i18n/`, `tests/Unit/I18nTest.php`, `tests/js/i18n.test.mjs` | 영어 화면도 추가했다(P27D, 명세 밖 확장) |
| 1 | 지원 브라우저(최신 Chrome·Edge·Firefox, IE 미지원), 데스크톱 전용 | 구현됨 | `docs/ui_conventions.md`, `docs/results/finish/P09.md` | 19.10 P09에 따라 Chrome·Edge 123 이상이 기준이다. 자동 점검은 헤드리스 Chrome으로만 했고, 창 있는 Chrome·Edge·Firefox는 미확인 |
| 1 | 시간은 UTC로 저장하고 KST로 표시(시간대 설정 가능) | 구현됨 | `src/Support/Clock.php`, `public/assets/js/core/time.js`, `config/config.ini.example`(`display_timezone`) | |
| 1 | 지원 OS Windows·Linux, macOS 미지원 | 구현됨 | `src/Services/Process/ProcessTree.php`, `src/Services/Process/ProcessRunner.php`, `docs/linux_verification.md` | Linux 전체가 미확인 |
| 1 | 용어 "결함"으로 통일 | 구현됨 | `docs/i18n_glossary.md`, `src/Lang/ko.php` | |

## 2. 기술 스택 및 배포 환경

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 2 | XAMPP(Apache + PHP), Windows·Linux 동일 구성 | 구현됨 | `docs/xampp_vhost.md`, `docs/install_guide.md` | PHP 8.2 이상(19.10 P05). 운영 Apache는 미확인 |
| 2 | SQLite WAL 모드, busy_timeout | 구현됨 | `src/Support/Db.php`, `tests/Integration/WebDbPragmasTest.php`, `tests/Unit/DbVersionTest.php` | SQLite 3.38 이상 검사(19.10 P07·P08). 쓰기 대기가 넘치면 503 `db_busy`(P31) |
| 2 | HTML·CSS·바닐라 JS, JSON 교환 | 구현됨 | `public/assets/js/`, `src/Http/Response.php` | |
| 2 | Python·Node.js 실행 환경, 필수 PHP 확장 문서화 | 구현됨 | `tools/check_env.php`, `docs/install_guide.md` | intl 필수(19.10 P03) |
| 2 | 외부 라이브러리 로컬 포함(Chart.js, CodeMirror 6) | 구현됨 | `public/assets/vendor/chartjs/`, `public/assets/vendor/codemirror/`, `public/assets/vendor/fflate/`, `tools/build_vendor.mjs`, `THIRD_PARTY_NOTICES` | CDN 없음 |
| 2 | Dompdf(PDF)·PhpSpreadsheet(Excel) 포함 | 제외 | 명세 15·15.1, `docs/results/finish/P23.md` | XLSX는 ZipArchive와 XMLWriter로 직접 쓴다. PDF는 만들지 않는다 |
| 2 | Composer는 빌드 때만 쓰고 vendor를 함께 배포 | 구현됨 | `composer.json`, `composer.lock`, `docs/vendor_build.md`, `tools/build_bundle.php`, `tools/verify_bundle.php` | P28D(SAML)에서 처음 도입했다 |
| 2 | 웹 루트에는 `public/`만 노출 | 구현됨 | `public/index.php`, `docs/xampp_vhost.md`, `tests/Integration/PathTraversalTest.php` | |
| 2 | 분석은 백그라운드 CLI 워커가 처리 | 구현됨 | `tools/worker.php`, `src/Services/Queue/Worker.php` | |

## 3. 사용자 및 인증

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 3 | 세션 인증, 로그인 때 세션 ID 재생성, 쿠키 HttpOnly·Secure·SameSite=Lax | 구현됨 | `src/Services/Auth/SessionService.php`, `src/Http/Middleware/SessionMiddleware.php`, `tests/Integration/SessionLifecycleTest.php`, `tests/Integration/SessionServiceTest.php` | |
| 3 | bcrypt, 비밀번호 정책(8자 이상, 2종 이상) | 구현됨 | `src/Services/Auth/PasswordPolicy.php`, `tests/Unit/PasswordPolicyTest.php` | 10자·3종에서 8자·2종으로 바꾼 결정(19.10 P10)이 본문에 반영되어 있다 |
| 3 | 5회 연속 실패 시 15분 잠금, 같은 IP의 반복 실패 제한 | 구현됨 | `src/Services/Auth/LoginService.php`, `tests/Integration/AuthHttpTest.php`, `tests/Integration/LoginServiceTest.php` | 신뢰 프록시 설정은 미구현(19.10 P10 행) |
| 3 | 30분 무활동 시 세션 만료 | 구현됨 | `src/Services/Auth/SessionService.php`, `tests/Integration/SessionLifecycleTest.php` | 고정 시계 시험으로만 확인. 실제 30분 대기는 미확인 |
| 3 | 임시 비밀번호 24시간 만료, 로그인하면 변경 강제 | 구현됨 | `src/Services/UserService.php`, `src/Http/Middleware/RequirePasswordChanged.php`, `tests/Integration/UserServiceTest.php` | |
| 3 | 관리자가 다른 사용자 비밀번호를 직접 정함(강제 없음, 다음 로그인 때 알림) | 구현됨 | `src/Services/UserService.php`, `tests/Integration/AdminPasswordTest.php` | |
| 3.1 | 그룹(이름 1~100자, 대소문자 무시 유일, 한 단계, 생성·변경·삭제는 관리자만) | 구현됨 | `src/Services/GroupService.php`, `src/Repositories/GroupRepository.php`, `tests/Integration/GroupServiceTest.php`, `docs/results/finish/P11A.md` | |
| 3.1 | 그룹 관리자(그룹별 지정, admin은 모든 그룹의 관리자로 간주) | 구현됨 | `src/Services/GroupPermission.php`, `tests/Integration/GroupHttpTest.php` | admin을 구성원으로 추가하면 409(19.10 P11A) |
| 3.1 | 그룹 관리자가 할 수 있는 일과 할 수 없는 일, 사용자 검색(2자 이상, 20건) | 구현됨 | `src/Services/GroupPermission.php`, `tests/Integration/GroupHttpTest.php`, `tests/Integration/RouteAuthMatrixTest.php` | |
| 3.1 | 프로젝트 매핑(그룹·사용자, 한 단계), 볼 수 있는 사람, 매핑 없으면 admin만 | 구현됨 | `src/Services/ProjectAccess.php`, `tests/Integration/ProjectAccessTest.php`, `docs/results/finish/P11B.md` | |
| 3.1 | 보이지 않는 프로젝트는 404, 판정은 `ProjectAccess` 한 곳 | 구현됨 | `src/Http/Middleware/RequireProjectAccess.php`, `tests/Integration/ProjectRouteAccessTest.php` | |
| 3.1 | 접근을 잃은 담당자 표시, 알림·"내 결함"에서 제외 | 구현됨 | `src/Services/Defects/DefectUpdate.php`, `tests/Integration/NotificationTest.php`, `docs/results/finish/P18.md` | |
| 3.1 | 변경은 다음 요청부터 반영, 그룹 삭제 때 매핑 삭제와 확인 화면 | 구현됨 | `src/Views/groups/delete.php`, `tests/Integration/GroupServiceTest.php` | |
| 3.1 | 그룹·구성원·매핑 변경 감사 로그 | 구현됨 | `src/Support/AuditActions.php`, `docs/audit_actions.md`, `tests/Integration/AuditDescriberTest.php` | |
| 3.1 | 범위 밖: LDAP 그룹 자동 매핑, CSV 그룹 열, 프로젝트별 조회 전용 단계 | 제외 | 명세 3.1 "범위 밖", 19.2 | |

## 4. 프로젝트 관리

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 4 | 매핑된 사람만 조회·업로드, 업로드 때 접근 가능한 프로젝트에서 선택 | 구현됨 | `src/Services/ProjectService.php`, `src/Views/upload.php`, `tests/Integration/ProjectHttpTest.php` | |
| 4 | 관리자만 생성, 언어 지정 후 변경 불가, 이름·설명 수정 | 구현됨 | `src/Services/ProjectService.php`, `tests/Integration/ProjectServiceTest.php`, `docs/results/finish/P11.md` | 이름 규칙은 19.10 P11 |
| 4 | 언어별로 정해진 도구(ruff 또는 ESLint)만 적용 | 구현됨 | `src/Support/ToolLanguage.php`, `tests/Unit/ToolLanguageTest.php` | |
| 4 | 제외 대상(서버 기본 목록, 관리자 추가 패턴, 업로드 설정의 제외) | 구현됨 | `src/Support/ExcludeMatcher.php`, `tests/Unit/ExcludeMatcherTest.php`, `src/Services/Tools/ScopeWriter.php` | `__MACOSX`·`.DS_Store` 추가(19.10 P05), 패턴 문법(19.10 P11). ESLint는 업로드 설정을 무시하고 `.eslintignore`만 반영(19.10 P03) |
| 4 | 프로젝트 삭제(즉시 영구, 분석 횟수·결함 건수·스냅샷 크기 표시, 이름 입력) | 구현됨 | `src/Views/projects/delete.php`, `public/assets/js/pages/project_delete.js`, `tests/Integration/ProjectServiceTest.php` | 대기·실행 중 분석이 있으면 거부(19.10 P11) |

## 5. 코드 업로드 및 분석 실행

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 5.1 | zip 2GB, 해제 후 10GB, 파일 100만 개 상한 | 구현됨 | `src/Services/Archive/UnzipLimits.php`, `src/Services/Archive/SafeUnzip.php`, `tests/Integration/SafeUnzipTest.php`, `config/config.ini.example` | 실제로 쓴 바이트로 검사한다(19.10 P05). 2GB 서비스 경로는 P19B에서 측정 |
| 5.1 | zip slip·절대 경로·심볼릭 링크·중첩 zip 차단 | 구현됨 | `src/Services/Archive/SafeUnzip.php`, `tests/Integration/SafeUnzipTest.php`, `samples/zips_bad/` | 역슬래시·Windows 이름 규칙·대소문자/NFC 충돌 추가(19.10 P05) |
| 5.1 | 파일명 UTF-8 플래그면 UTF-8, 없으면 CP949, 실패하면 오류 | 구현됨 | `src/Services/Archive/SafeUnzip.php`, `tests/Integration/SafeUnzipTest.php` | CP949 왕복 실패 시 UTF-8로 본다(19.10 P05·P13) |
| 5.1 | 제외 대상은 해제 단계에서 건너뜀 | 구현됨 | `src/Services/Archive/SafeUnzip.php`, `src/Support/ExcludeMatcher.php` | |
| 5.1 | 최상위 단일 폴더 제거 | 변경됨 | 명세 19.10 P04·P05, `src/Services/Upload/UploadProcessor.php`, `tests/Integration/UploadProcessorTest.php` | 제거 여부를 프로젝트 첫 업로드에서 정해 저장하고, 어긋나면 `top_folder_missing`·`top_folder_kept` 경고 후 확인받는다 |
| 5.2 | 업로드 완료 → DB 큐 queued → 워커 순서 처리 | 구현됨 | `src/Services/Queue/AnalysisQueue.php`, `src/Services/Queue/Worker.php`, `tests/Integration/AnalysisQueueTest.php` | |
| 5.2 | 상태 5종, 화면은 주기적으로 조회 | 구현됨 | `public/assets/js/core/poll.js`, `src/Views/analyses/show.php`, `tests/js/analyses.test.mjs` | "변경 없음"은 상태를 늘리지 않고 succeeded의 플래그로 표현(19.10 P07·P08, P27) |
| 5.2 | 동시 분석 2개, 초과는 대기열 | 구현됨 | `src/Services/Queue/AnalysisQueue.php`(`analysis.max_concurrent`), `tests/Integration/AnalysisQueueTest.php` | |
| 5.2 | 워커 기동(Linux systemd/cron, Windows 작업 스케줄러/서비스) | 구현됨 | `docs/worker_setup.md`, `resources/guide/admin/ko/03_worker.md` | 실제 작업 스케줄러·systemd 등록은 미확인 |
| 5.2 | 분석 타임아웃 4시간, 하위 프로세스까지 종료, 사유 기록 | 구현됨 | `src/Services/Process/ProcessRunner.php`, `src/Services/Process/ProcessTree.php`, `tests/Integration/ProcessRunnerTest.php`, `tests/Integration/WorkerProcessTest.php` | |
| 5.3 | 셸 없이 `proc_open` 인자 배열, 작업 디렉토리 안으로 제한 | 구현됨 | `src/Services/Process/ProcessRunner.php`, `src/Services/Tools/ToolExecution.php`, `tests/Integration/ProcessRunnerTest.php` | |
| 5.3 | ruff: 업로드된 pyproject.toml·ruff.toml 우선, 밖을 가리키는 extend 거부 | 변경됨 | 명세 19.10 P03·P15, `src/Services/Tools/RuffConfigResolver.php`, `tests/Integration/ToolRunnersTest.php` | 분석 루트의 설정만 쓰고 하위 폴더 설정은 무시·안내한다. `~`·`$`를 가리키는 extend도 거부한다. 늘 `--no-respect-gitignore`. Linux extend 경로는 미확인 |
| 5.3 | ESLint: 정적 설정(.eslintrc JSON/YAML) 우선, JS 설정 무시 | 변경됨 | 명세 19.10 P03, `src/Services/Tools/EslintRunner.php`, `tools/eslint/pjg.flat.config.cjs`, `config/eslint.default.json`, `docs/results/finish/X07.md` | ESLint 10.x와 서버가 만든 flat config를 쓰고, 업로드된 ESLint 설정은 통째로 무시한다. `.eslintignore`만 서버 ignore 패턴으로 바꾼다. 테스트 파일 전역은 X07 |
| 5.3 | 결과 JSON을 파싱해 DB 저장 | 구현됨 | `src/Services/Tools/ToolOutputParser.php`, `src/Services/Defects/DefectIngest.php` | |
| 5.3 | 분석마다 도구 버전·설정 출처·설정 해시 기록 | 구현됨 | `src/Services/Analysis/AnalysisEnvKey.php`, `tests/Integration/ToolRunnersTest.php` | |
| 5.4 | 폴더 또는 ZIP 지정, 프로젝트 필수, 프로젝트가 없으면 안내 | 구현됨 | `src/Views/upload.php`, `public/assets/js/pages/upload.js`, `docs/results/finish/X04.md` | |
| 5.4 | 폴더를 브라우저에서 zip으로 묶음, 2GB 초과 예상 시 사전 차단 | 구현됨 | `public/assets/vendor/fflate/`, `public/assets/js/lib/upload_core.js`, `tests/js/upload.test.mjs` | 무압축(STORE)만 쓰고 기본 제외 대상은 묶기 전에 뺀다(19.10) |
| 5.4 | 8MB 청크, 청크 해시 검증, 이어 올리기, 진행률, 미완료 조각 24시간 후 삭제 | 구현됨 | `src/Services/Upload/UploadService.php`, `src/Services/Upload/UploadAssembler.php`, `src/Services/Upload/CleanupService.php`, `tests/Integration/UploadTest.php` | 조립은 워커가 한다. 사용자당 동시 2개, 남의 `upload_id`는 404(19.10 P06·P12) |
| 5.4 | 대상 확장자 파일이 없으면 업로드 전 경고, 확인 후 진행 | 변경됨 | 명세 19.10 P06·P13, `src/Services/Archive/TargetScanner.php`, `src/Services/Upload/UploadProcessor.php`, `tests/Integration/UploadSummaryTest.php` | 업로드 전이 아니라 서버가 해제한 뒤, 큐에 넣기 전에 검사하고 확인을 받는다(확인 대기는 24시간 보관) |
| 5.4 | 같은 프로젝트는 한 번에 하나, 전체 2개 | 구현됨 | `src/Services/Queue/AnalysisQueue.php`, `tests/Integration/AnalysisQueueTest.php` | 19.8 행 참고 |
| 5.4 | 본인·관리자 취소, 하위 프로세스 종료, 결함·비교 기준에 영향 없음 | 구현됨 | `src/Services/AnalysisService.php`, `public/assets/js/ui/analysis_cancel.js`, `tests/Integration/AnalysisApiTest.php` | |
| 5.4 | 실패 분석 재시도 없음, 실패·취소 zip 1일 후 삭제 | 구현됨 | `src/Services/Upload/CleanupService.php`, `config/config.ini.example`(`failed_zip_retention`) | 저장소 일시 오류의 같은 행 재큐는 주기 분석 규칙(P26) |

## 6. 결함 관리

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 6.1 | 결함 레코드(도구·언어·규칙 키·경로·줄/열·메시지), 규칙 키에 도구 포함 | 구현됨 | `src/Services/Defects/DefectStore.php`, `migrations/001_initial_schema.sql`, `docs/db_schema.md` | |
| 6.1 | 시그니처(정규화 경로 + 규칙 키 + 정규화 코드 해시), 같은 파일 안 순번 | 구현됨 | `src/Services/Defects/SourceNormalizer.php`, `src/Services/Defects/DefectIngest.php`, `tests/Integration/DefectLifecycleTest.php` | 파일 단위로 처리하고 해시만 보관한다. 고유 키는 (project_id, signature, ordinal)(19.10 P04) |
| 6.1 | 파일 이동·이름 변경은 신규 + 해결(한계 수용) | 구현됨 | `docs/results/finish/P16.md`, `resources/guide/user/ko/07_defects.md` | 순번 밀림도 한계로 안내(19.10 P15·P16) |
| 6.1 | 문법 오류는 syntax-error 결함(분석 성공), 도구 실행 실패만 분석 실패 | 구현됨 | `src/Services/Tools/ToolOutputParser.php`, `tests/Integration/ToolRunnersTest.php`, `samples/python_syntax_error/` | |
| 6.2 | 담당자는 접근 가능한 활성 사용자만, 비활성 담당자 표시 | 구현됨 | `src/Services/Defects/DefectUpdate.php`, `tests/Integration/DefectUpdateTest.php` | |
| 6.2 | 심각도 5단계, 규칙별 기본 심각도 자동 부여, 수동 값 우선 | 구현됨 | `src/Services/Severity/SeverityMap.php`, `resources/severity/`, `tests/Integration/SeverityDefaultTest.php`, `docs/results/finish/P21S.md` | |
| 6.2 | 상태 6종, 신규는 open, 자유 변경, 일괄 변경 | 구현됨 | `src/Services/Defects/DefectUpdate.php`, `public/assets/js/lib/defect_edit_core.js`, `tests/Integration/DefectUpdateTest.php` | 일괄은 현재 페이지, 최대 500건(19.10 P18) |
| 6.2 | 변경 이력(변경자·시각·이전/이후·수동/자동·사유), 사유 선택 입력 | 구현됨 | `src/Services/Defects/DefectStore.php`, `tests/Integration/DefectApiTest.php` | 결함 변경은 감사 로그가 아니라 결함 이력에 남긴다(19.10 P18) |
| 6.2 | 낙관적 잠금(version, 충돌 시 최신 값 안내) | 구현됨 | `src/Services/Defects/DefectUpdate.php`, `tests/Integration/DefectUpdateTest.php`, `tests/Support/defect_update_race.php` | |
| 6.3 | 성공한 분석에서만 전환, 범위 안 미감지 → resolved | 구현됨 | `src/Services/Defects/AnalysisFinalizer.php`, `src/Services/Defects/ScopeList.php`, `tests/Integration/DefectLifecycleTest.php` | 범위는 도구가 실제로 분석한 파일 목록(19.10 P15·P16) |
| 6.3 | zip에 없는 파일 → resolved("파일 삭제"), 제외 대상 파일은 유지, 현재 상태와 무관 | 구현됨 | `src/Services/Defects/AnalysisFinalizer.php`, `tests/Integration/DefectLifecycleTest.php` | |
| 6.3 | resolved 결함이 다시 감지되면 open으로 재오픈(수동 resolved 포함) | 구현됨 | `tests/Integration/DefectLifecycleTest.php` | |
| 6.4 | 규칙·심각도·상태·담당자·파일 필터, 검색, 페이지 50건 | 구현됨 | `src/Services/Defects/DefectFilter.php`, `src/Services/Defects/DefectQuery.php`, `public/assets/js/lib/defect_search.js`, `tests/Integration/DefectApiTest.php` | |
| 6.4 | 상세 화면(담당자·심각도·상태·이력·관련 코드) | 구현됨 | `src/Views/defects/show.php`, `public/assets/js/pages/defect_detail.js` | |
| 6.4 | 규칙 코드를 누르면 규칙 설명으로 이동 | 구현됨 | `src/Support/RuleLinks.php` | |

## 7. 코드 뷰어 연동

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 7 | CodeMirror 6 읽기 전용, 결함 줄 강조, Python·JS·TS 하이라이팅 | 구현됨 | `public/assets/vendor/codemirror/`, `public/assets/js/lib/code_viewer.js`, `docs/results/finish/P17.md` | |
| 7 | 코드는 항상 텍스트로 렌더링 | 구현됨 | `public/assets/js/lib/code_view_core.js`, `tests/Integration/XssCorpusTest.php`, `tests/js/xss_corpus.test.mjs` | |
| 7 | 최근 5회 전체 스냅샷 보존(설정 가능) | 구현됨 | `src/Services/Defects/SnapshotService.php`, `config/config.ini.example`(`full_keep_count`) | |
| 7 | 그 이전은 앞뒤 20줄 발췌만 보관, 발췌본 표시 | 구현됨 | `src/Services/Defects/SnapshotService.php`, `config/config.ini.example`(`excerpt_context_lines`) | UTF-8 아닌 원본은 U+FFFD로 바꾸고 표시(19.10 P07·P08) |
| 7 | 결함이 마지막으로 감지된 분석의 코드 기준 | 구현됨 | `src/Services/Defects/DefectCode.php` | |

## 8. 히스토리 관리

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 8 | 프로젝트별 현황 요약(심각도·상태·담당자별 건수) | 구현됨 | `src/Views/projects/summary.php`, `src/Services/Defects/DefectQuery.php` | |
| 8 | 분석 이력(날짜·결함 수·도구 버전·상태) | 구현됨 | `src/Services/History/HistoryService.php`, `tests/Integration/HistoryTest.php`, `docs/results/finish/P21.md` | |
| 8 | 비교 기준은 직전 성공 분석, 실패 분석 제외, 최초는 "비교 대상 없음" | 구현됨 | `src/Services/Analysis/AnalysisComparison.php`, `tests/Integration/HistoryTest.php` | "변경 없음" 분석도 기준이 되지 않는다(19.10 P19·P27) |
| 8 | 결과가 같은 재업로드도 이력으로 기록 | 구현됨 | `src/Services/History/HistoryService.php`, `tests/Integration/HistoryTest.php` | |
| 8 | 버전/설정 변경 배지, 결함 상세에 처음 발생한 분석의 도구 버전 | 구현됨 | `src/Services/Analysis/AnalysisEnvKey.php`, `src/Services/Defects/DefectQuery.php`(`first_tool_version`) | |

## 9. 대시보드

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 9 | 전체 현황 요약, 접근 가능한 프로젝트만 집계(admin은 전체) | 구현됨 | `src/Services/Dashboard/DashboardService.php`, `tests/Integration/DashboardTest.php`, `docs/results/finish/P20.md` | |
| 9 | 프로젝트별 추이(최근 10건 성공 분석), 버전·설정 변경 표식 | 구현됨 | `src/Services/Dashboard/DashboardService.php`, `public/assets/js/lib/dashboard_core.js`, `tests/js/dashboard.test.mjs` | 표시할 프로젝트 수는 관리자가 정한다(P21C) |
| 9 | 규칙별 상위 10, 파일별 상위 10 | 구현됨 | `src/Controllers/DashboardController.php`, `tests/Integration/DashboardTest.php` | 저장 집계 대신 조회 계산 + 인덱스 + 캐시(19.10 P20) |
| 9 | 언어/도구별 통계 | 구현됨 | `src/Controllers/DashboardController.php` | |
| 9 | 모든 차트는 로컬 Chart.js | 구현됨 | `public/assets/vendor/chartjs/`, `public/assets/js/ui/chart_table.js` | 차트마다 "표로 보기" 제공 |

## 10. 보안 및 안정성

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 10 | 업로드 2GB, 해제 10GB·100만 개 제한 | 구현됨 | `src/Services/Archive/UnzipLimits.php`, `tests/Integration/SafeUnzipTest.php` | 5.1과 같다 |
| 10 | 외부 명령 셸 없이 실행, 프로젝트 디렉토리로 제한, 비권한 계정·네트워크 차단(가능한 환경에서) | 구현됨 | `src/Services/Process/ProcessRunner.php`, `docs/tool_setup.md` | 비권한 계정과 네트워크 차단은 운영 설정 권고이며 앱이 강제하지 않는다 |
| 10 | 업로드 설정 파일은 5장 정책대로 처리 | 변경됨 | 명세 19.10 P03 | 5.3 행과 같다 |
| 10 | bin/ 경로와 체크섬 검증을 통과한 실행 파일만 | 구현됨 | `src/Services/Tools/ToolRegistry.php`, `src/Services/Tools/ToolManifest.php`, `tests/Integration/ToolRegistryTest.php` | |
| 10 | SQLite DB는 웹 루트 밖 | 구현됨 | `config/config.ini.example`(`data/db`), `docs/xampp_vhost.md` | |
| 10 | 모든 폼에 CSRF 토큰 | 구현됨 | `src/Http/Middleware/CsrfMiddleware.php`, `tests/Integration/RouteAuthMatrixTest.php` | SAML ACS만 예외이며 요청 ID·RelayState·한 번 사용으로 검증(P28D) |
| 10 | 사용자 입력 출력 이스케이프 | 구현됨 | `src/Support/Html.php`, `tests/Integration/XssCorpusTest.php` | |
| 10 | 모든 DB 쿼리는 Prepared Statement | 구현됨 | `src/Support/Db.php`, `docs/architecture.md`, `tests/Integration/DefectApiTest.php`(검색어 SQL 구문 안전성) | |
| 10 | HTTPS 강제(Apache 리다이렉트와 HSTS, 앱 이중 검사) | 변경됨 | `docs/results/P31.md`, `src/Http/Middleware/HttpsMiddleware.php`, `src/Http/Middleware/SecurityHeadersMiddleware.php`, `tests/Integration/SecurityHeadersTest.php` | HSTS는 Apache가 아니라 앱 한 곳에서만 보낸다(P31 결정, 이중 송출 방지). Apache는 리다이렉트만. 운영 Apache는 미확인 |
| 10 | 비밀번호 정책·잠금·세션 만료는 3장 값 | 구현됨 | `config/config.ini.example`(`lockout_max_failures`, `session_idle`) | |
| 10 | 관리자 작업 감사 로그(그룹·매핑·계정·비밀번호·프로젝트 삭제·저장소 연결) | 구현됨 | `src/Services/AuditLog.php`, `src/Support/AuditActions.php`, `docs/audit_actions.md`, `tests/Integration/AuditLogTest.php` | |
| 10 | 로그인 성공·실패의 감사 로그 기록 | 변경됨 | 명세 19.10 P10, `docs/results/finish/P10.md`(결정 3절 끝), `docs/results/finish/P30.md` | 감사 로그에 넣지 않고 앱 로그에만 남긴다(10장의 감사 대상은 관리자 작업). 로그인 실패 로그에서 ID 원문도 뺐다(P31) |

## 11. 규칙(Rule) 설명

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 11 | 규칙 설명 별도 페이지(코드·설명·카테고리·예시) | 구현됨 | `src/Controllers/RuleController.php`, `src/Views/rules/index.php`, `src/Views/rules/show.php`, `tests/Integration/RulesTest.php`, `docs/results/finish/P22.md` | 언어별 탭(X08), 영어 메뉴 이름 "Rules"(X13) |
| 11 | ruff 규칙과 ESLint(코어 + @typescript-eslint) 규칙 포함 | 구현됨 | `src/Services/Rules/RuleExtractor.php`, `docs/rules.md` | |
| 11 | 도구 내장 정보에서 추출해 버전별로 DB에 적재, 새 버전 감지 시 자동 갱신 | 구현됨 | `src/Services/Rules/RuleLoader.php`, `tools/rules_load.php`, `tests/Integration/RuleLoadTest.php` | 워커가 시작 때와 1시간마다 요청(P22) |
| 11 | 예시·상세 설명 보강을 공식 문서 기반으로 사전 작성하고 출처 표시 | 구현됨 | `resources/rules/enrichment/ruff.json`·`eslint.json`(`guide`), `src/Services/Rules/RuleEnrichment.php`, `tools/rules_guide_check.mjs`, `tests/Unit/RuleGuideDataTest.php`, `tests/Integration/RulesTest.php`, `docs/results/P32B.md` | P32B: 쉬운 설명·나쁜 예·고친 코드·고치는 법(ko·en) — ruff 59·ESLint 65개. 공식 문서를 내려받을 수 없어 도구 원문(로컬)과 대조해 직접 쓰고, 실제 도구로 예시를 확인한 것만 보인다(`checked`). 외부 문서 출처 주소는 붙이지 않음(도구 원문이 근거) |
| 11 | 어디에도 없으면 "설명 없음" | 구현됨 | `src/Services/Rules/RuleQuery.php`, `src/Lang/ko.php` | |
| 11 | 규칙 코드·키워드 검색과 필터, 결함 상세에서 바로 이동 | 구현됨 | `src/Services/Rules/RuleQuery.php`(FTS5), `public/assets/js/lib/rule_search.js`, `src/Support/RuleLinks.php` | |

## 12. 분석 도구 배포 및 버전 관리

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 12 | bin/ 버전별 디렉토리(ruff 단일 실행 파일, ESLint + node_modules + 서버 Node.js) | 구현됨 | `src/Services/Tools/ToolRegistry.php`, `src/Services/Tools/NodeRuntime.php`, `docs/tool_setup.md` | Node 최소 버전 검사(19.10 P03) |
| 12 | OS 판별 후 자동 선택, semver 최신, 스모크 테스트 통과 시에만 | 구현됨 | `src/Services/Tools/ToolRegistry.php`, `src/Services/Tools/ToolSmokeTest.php`, `tests/Integration/ToolRegistryTest.php` | |
| 12 | sha256 매니페스트, 불일치하면 건너뛰고 직전 유효 버전, 관리자 로그 | 구현됨 | `src/Services/Tools/ToolManifest.php`, `tests/Integration/ToolRegistryTest.php` | |
| 12 | 매니페스트·bin/에 웹/앱 계정 쓰기 권한 없음 | 구현됨 | `docs/install_guide.md` 5.1, `src/Services/Admin/SystemChecks.php`, `tools/tools_ctl.php` | 웹은 bin/에 쓰지 않는다(19.10 P20A). Linux 파일 권한은 미확인 |
| 12 | git·p4도 bin/에 OS별, 같은 체크섬 검증, VERSION.txt | 구현됨 | `src/Services/Repo/RepoTools.php`, `tools/check_bin_vcs.php`, `docs/vcs_tools.md`, `docs/results/finish/X09.md` | Linux git은 운영 서버에서 `tools/build_git_linux.sh`로 빌드 — 미확인 |
| 12 | 분석마다 실제 사용한 버전·설정 출처·설정 해시 기록 | 구현됨 | `src/Services/Analysis/AnalysisEnvKey.php` | |

## 13. 권한 관리(RBAC) 및 계정 운영

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 13 | 관리자 전용 작업(프로젝트·매핑·그룹·저장소·계정·가이드·시스템 화면) | 구현됨 | `src/Http/Middleware/RequireAdmin.php`, `tests/Integration/RouteAuthMatrixTest.php` | 라우트 256개 × 5종 사용자 행렬(P31) |
| 13 | 일반 사용자·그룹 관리자의 권한 | 구현됨 | `src/Services/GroupPermission.php`, `tests/Integration/RouteAuthMatrixTest.php` | |
| 13 | 최초 관리자는 설치 스크립트(CLI)로 생성, CLI 잠금 해제·비밀번호 재설정(감사 로그) | 구현됨 | `tools/create_admin.php`, `tools/user_cli.php`, `tests/Integration/InitialAdminTest.php` | |
| 13 | 비밀번호 초기화는 임시 비밀번호, 다음 로그인 때 변경 | 구현됨 | `src/Services/UserService.php`, `tests/Integration/UserServiceTest.php` | |
| 13 | 관리자의 비밀번호 직접 설정(정책 적용, 세션 끊음, 알림, 감사에 값 없음) | 구현됨 | `tests/Integration/AdminPasswordTest.php` | |
| 13 | 계정은 비활성화만, 마지막 활성 관리자 보호 | 구현됨 | `src/Services/UserService.php`, `tests/Integration/UserServiceTest.php` | 외부 인증 도입 뒤 "활성 로컬 관리자 1명 이상"(P28B) |
| 13 | 담당자는 접근 가능한 활성 사용자만 | 구현됨 | `src/Services/Defects/DefectUpdate.php` | 6.2와 같다 |

## 14. 운영/관리

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 14 | DB 매일 1회 백업, 60일 보관 | 구현됨 | `src/Services/Backup/BackupService.php`, `src/Services/Backup/BackupSchedule.php`, `tests/Integration/BackupRestoreTest.php`, `docs/results/finish/P27B.md` | 켜기/끄기와 매일·매주·매월 주기 선택(P27E, 기본 매일) |
| 14 | 에러·접근 로그, 로테이션, 민감정보 제외 | 구현됨 | `src/Support/Logger.php`(`app-YYYYMMDD.log`), `src/Support/Redact.php`, `tests/Unit/LoggerTest.php` | 접근 로그는 Apache 로그(`docs/install_guide.md`) |
| 14 | online backup 또는 VACUUM INTO, 복원 절차 문서 | 구현됨 | `src/Services/Backup/BackupRunner.php`, `tools/restore.php`, `docs/backup_restore.md` | 화면 복원도 지원(P27B) |
| 14 | 분석 임시 디렉토리 삭제, 잔여물 매일 정리 | 구현됨 | `tools/cleanup.php`, `src/Services/Upload/CleanupService.php` | |
| 14 | 스냅샷 보존 정책대로 정리 | 구현됨 | `src/Services/Defects/SnapshotService.php` | |
| 14 | 동시 분석 2개 | 구현됨 | `src/Services/Queue/AnalysisQueue.php` | 5.2와 같다 |
| 14 | 워커 비정상 종료 시 running 작업 복구 | 구현됨 | `src/Services/Queue/WorkerRecovery.php`, `tests/Integration/WorkerRecoveryTest.php` | failed(`worker_crashed`)로만 복구, 자동 재대기 없음(19.10 P07·P08) |
| 14 | 보존 기간(실패·취소 zip 1일, 감사 90일, 에러/접근 로그 30일), 설정 변경 가능 | 구현됨 | `src/Support/ConfigSchema.php`(`log.audit_retention`, `log.error_access_retention`), `src/Services/Audit/AuditRetention.php` | |
| 14 | 관리자 시스템 현황 화면(큐 길이·대기 순번·실행 중·워커·디스크·최근 실패·백업·도구 체크섬) | 변경됨 | `docs/results/finish/X10.md`, `docs/results/finish/P30.md`, `src/Controllers/MonitoringController.php`, `src/Services/Monitoring/SystemHealth.php`, `src/Views/admin/monitoring.php`, `tests/Integration/MonitoringTest.php` | 화면 이름이 "시스템 모니터링"(카드 9개)으로 바뀌었고, 도구·워커 조작은 "시스템 관리" 탭에 있다(X10) |
| 14 | 일반 사용자에게 메뉴 숨김, 서버에서 admin 검사 | 구현됨 | `src/Support/Navigation.php`, `src/Http/Middleware/RequireAdmin.php`, `tests/Integration/RouteAuthMatrixTest.php` | |

## 15. 리포트 및 예외 처리

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 15 | PDF 내보내기(Dompdf) | 제외 | 명세 15("PDF 내보내기는 만들지 않는다, 사용자 결정, P23"), `docs/results/finish/P23.md` | 사용자 가이드에도 "PDF는 제외"(16.2) |
| 15.1 | 결함 현황·규칙 설명 목록을 CSV·Excel(.xlsx)로 내보내기, 50,000행·동시·시간·메모리 제한 | 구현됨 | `src/Services/Export/ExportService.php`, `src/Services/Export/XlsxWriter.php`, `src/Services/Export/CsvWriter.php`, `tests/Integration/ExportTest.php`, `docs/export.md` | "내 결함"도 내보낸다. 실제 Excel·LibreOffice로 열기는 미확인 |
| 15.1 | CSV UTF-8 BOM, RFC 4180 | 구현됨 | `src/Services/Export/CsvWriter.php`, `tests/Integration/ExportTest.php` | |
| 15.1 | `=`·`+`·`-`·`@`로 시작하면 작은따옴표(수식 주입 방지), XLSX는 인라인 문자열만 | 구현됨 | `src/Services/Export/SheetText.php`, `tests/Integration/ExportTest.php` | |
| 15.2 | 도구 실행 실패 사유 안내, 문법 오류는 결함 | 구현됨 | `src/Support/ErrorCatalog.php`, `src/Views/analyses/show.php`, `docs/error_codes.md`, `tests/Integration/AnalysisResultTest.php` | 실패 코드 카탈로그(19.10 P19) |
| 15.2 | 업로드 오류 원인별 메시지 | 구현됨 | `src/Support/ErrorCatalog.php`, `src/Lang/ko.php`, `tests/Integration/UploadProcessorTest.php` | `unsupported_name`·`name_conflict`·`filename_encoding` 등 추가(19.10 P05) |
| 15.2 | JS 설정 파일이 무시되어 서버 기본 설정으로 분석한 경우 표시 | 변경됨 | 명세 19.10 P03, `src/Services/Tools/ToolProjectScan.php`, `src/Services/Tools/EslintRunner.php` | 업로드된 ESLint 설정은 항상 무시하므로 그 사실과 ruff 하위 폴더 설정 무시를 화면에 안내한다 |

## 16. 가이드 페이지

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 16.1 | 상단 "가이드", 사용자/관리자 가이드 구분, 관리자 가이드는 관리자에게만 | 구현됨 | `src/Controllers/GuideController.php`, `src/Services/Guide/GuideCatalog.php`, `tests/Integration/GuideHttpTest.php`, `docs/results/finish/P29.md` | |
| 16.1 | 한국어, 목차, 페이지 내 검색 | 구현됨 | `resources/guide/user/ko/`, `resources/guide/admin/ko/`, `public/assets/js/lib/guide_core.js`, `tests/js/guide.test.mjs` | 영어 본문도 있다(P29, P27D) |
| 16.1 | 정적 콘텐츠, 출력 시 이스케이프 | 구현됨 | `src/Services/Guide/GuideContent.php`, `public/assets/js/lib/markdown.js` | |
| 16.1 | 하단에 앱 버전·ruff/ESLint 버전, 서드파티 라이선스 열람, 아이콘 범례 | 구현됨 | `src/Views/guide/index.php`, `src/Views/partials/icon_legend.php`, `THIRD_PARTY_NOTICES` | 라이선스 화면 `/guide/licenses` |
| 16.1 | 오류 메시지에서 가이드 절로 바로 이동 | 구현됨 | `src/Support/ErrorCatalog.php`, `src/Support/GuideLinks.php` | 오류 코드표 `/guide/errors` |
| 16.2 | 사용자 가이드(시작하기, 업로드, 분석 상태, 결함, 알림·코드 뷰어·규칙·히스토리·대시보드, 내보내기) | 구현됨 | `resources/guide/user/ko/`(17절), `docs/guide_authoring.md` | |
| 16.2 | 자주 묻는 질문과 오류 해결 방법 | 구현됨 | `src/Services/Faq/FaqService.php`, `migrations/029_faq_items.sql`, `resources/faq/seed.ko.json`, `tools/faq_import.php`, `tests/Integration/FaqTest.php`, `docs/results/finish/P29B.md` | FAQ는 관리자가 화면에서 편집(P29B) |
| 16.3 | 관리자 가이드(설치·워커·bin/·계정·그룹·설정·백업·로그·프로젝트·시스템 화면·감사 로그) | 구현됨 | `resources/guide/admin/ko/`(25절), `docs/results/finish/P29C.md` | 권장 서버 사양·용량 산정식은 19.1·19.3 행 |
| 16.4 | 관리자 가이드는 서버에서 admin 검사, 비관리자는 403 | 구현됨 | `src/Controllers/GuideController.php`, `tests/Integration/GuideHttpTest.php` | |
| 16.4 | 원본은 웹 루트 밖, PHP로만 제공 | 구현됨 | `resources/guide/admin/` | |
| 16.4 | 관리자 가이드는 다른 검색 결과에 포함하지 않음 | 구현됨 | `src/Controllers/GuideController.php`, `tests/Integration/GuideHttpTest.php` | |

## 17. 앱 내 알림

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 17 | 사용자 알림은 앱 안에서만(이메일은 관리자 장애 알림만) | 구현됨 | `src/Services/Notifications/NotificationService.php`, `tests/Integration/NotificationTest.php`, `docs/results/finish/P24.md` | |
| 17 | 읽지 않은 수 배지, 목록, 읽음 처리, 해당 화면 이동 | 구현됨 | `public/assets/js/ui/notification_bell.js`, `src/Views/notifications/index.php`, `src/Services/Notifications/NotificationInbox.php` | |
| 17 | 종류와 수신자, 접근 가능한 사용자에게만 | 구현됨 | `src/Services/Notifications/NotificationService.php`, `tests/Integration/NotificationTest.php` | 취소는 `analysis_failed` 종류에 상태 cancelled로 알린다(P24) |
| 17 | "내게 지정된 결함" | 구현됨 | `src/Views/defects/mine.php`, `src/Controllers/DefectController.php` | |
| 17 | 폴링(기본 60초, 최소 15초, 보이는 탭만, 세션 연장 안 함), 종류별 끄기, 사용자당 최대 보관 | 구현됨 | `config/config.ini.example`(`notification_poll_seconds`), `tests/Unit/NotificationRetentionTest.php`, `public/assets/js/core/poll.js` | |
| 17 | 보관 기간을 관리자가 "도구·워커 관리 > 알림 보관"에서 변경 | 변경됨 | `docs/results/finish/X10.md`, `docs/results/finish/X12.md`, `src/Views/admin/_notif_retention.php` | 메뉴 개편으로 "시스템 관리 > 설정 관리"의 알림 탭으로 옮겼다. 저장 위치(`app_settings`)와 값 범위는 같다 |

## 18. 소스 저장소 연동 및 주기 분석

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 18.1 | 소스 유형 Git/Perforce/연결 없음, 프로젝트마다 하나 | 구현됨 | `src/Services/Repo/RepoConnections.php`, `src/Services/Repo/RepoService.php`, `tests/Integration/RepoApiTest.php`, `docs/repo_integration.md`, `docs/results/finish/P25.md` | |
| 18.1 | Git: HTTPS/HTTP URL, 브랜치, 하위 경로, 읽기 전용 토큰 | 구현됨 | `src/Services/Repo/GitClient.php`, `src/Services/Repo/GitTarget.php`, `tests/Integration/RepoFetchTest.php` | 실제 GHE·GitHub는 미확인 |
| 18.1 | Perforce: P4PORT, depot 경로, 사용자·비밀번호/티켓(stream·client view 미지원) | 구현됨 | `src/Services/Repo/PerforceClient.php`, `src/Services/Repo/P4Target.php`, `tests/Integration/RepoPerforceTest.php` | 가짜 p4로만 시험했다(p4d 없음). 실제 Perforce는 미확인 |
| 18.1 | latest 기준, 저장 전 연결 테스트(쓰기 없음) | 구현됨 | `src/Services/Repo/RepoHead.php`, `src/Controllers/RepoController.php` | |
| 18.1 | 제외 대상·대상 확장자 사전 검사 동일 적용 | 구현됨 | `src/Services/Repo/RepoImport.php` | |
| 18.2 | 주기(사용 안 함 기본, N일 간격 최소 1일, KST 시각) | 구현됨 | `src/Services/Schedule/ScheduleService.php`, `src/Services/Schedule/ScheduleCalc.php`, `tests/Unit/ScheduleCalcTest.php`, `docs/results/finish/P26.md` | |
| 18.2 | 스케줄러가 큐에 등록, queued/running이 있으면 건너뜀 | 구현됨 | `src/Services/Schedule/ScheduleTicker.php`, `tests/Integration/ScheduleTickerTest.php` | 스케줄러는 워커 안에서 돈다(P26) |
| 18.2 | 같은 커밋/changelist면 생략하고 "변경 없음" | 구현됨 | `src/Services/Repo/UnchangedSourceCheck.php`, `tests/Integration/UnchangedSourceTest.php`, `tests/Integration/RepoUnchangedFlowTest.php`, `docs/results/finish/P27.md` | 변경 없음에는 Run 번호를 만들지 않는다(19.10 P27 결정) |
| 18.2 | 관리자만 "지금 가져와 분석" | 구현됨 | `src/Controllers/RepoController.php`, `tests/Integration/RouteAuthMatrixTest.php` | 변경 없음이면 확인 후 강제 분석(P27) |
| 18.3 | Git 얕은 가져오기, Perforce 읽기 전용 동기화, LFS·submodule 미지원 | 구현됨 | `src/Services/Repo/GitClient.php`, `src/Services/Repo/PerforceClient.php`, `src/Services/Repo/VcsSandbox.php` | |
| 18.3 | 가져온 뒤는 해제 이후 단계와 동일, 항상 전체 코드 | 구현됨 | `src/Services/Repo/RepoImport.php`, `tests/Integration/RepoWorkerTest.php`, `tests/Integration/RepoSymlinkTest.php` | 심볼릭 링크 항목은 건너뛰고 경고(P26B) |
| 18.3 | 가져오기 타임아웃 30분, 끝나면 임시 디렉토리 삭제 | 구현됨 | `config/config.ini.example`(`fetch_timeout`), `src/Services/Repo/RepoImport.php` | |
| 18.3 | 출처·브랜치/경로·커밋/changelist·실행 주체 기록 | 구현됨 | `src/Services/History/HistoryService.php`, `docs/db_schema.md` | |
| 18.3 | 주기 분석에서 대상 파일이 없으면 실패 처리 | 구현됨 | `src/Services/Repo/RepoImport.php`(`no_target`) | |
| 18.3 | 가져오기 실패 기록, 연속 3회 실패면 그 회차 중단, 관리자 알림 | 구현됨 | `src/Services/Schedule/ScheduleRetry.php`, `src/Services/Schedule/ScheduleOutcomes.php`, `tests/Integration/ScheduleWorkerTest.php` | `schedule_failed` 알림은 admin 전용(P26) |
| 18.4 | 자격 증명 암호화(키는 웹 루트 밖), 다시 표시하지 않음, 로그 제외 | 구현됨 | `src/Services/Repo/SecretBox.php`, `tools/make_secret_key.php`, `tests/Unit/RepoSecurityTest.php`, `tests/Integration/SecretLeakScanTest.php` | |
| 18.4 | git·p4는 bin/ 경로·체크섬, `GIT_CONFIG_NOSYSTEM`·임시 HOME, P4CONFIG 비움, 자격 증명은 환경 변수 | 구현됨 | `src/Services/Repo/VcsSandbox.php`, `src/Services/Repo/VcsProcess.php`, `src/Services/Repo/RepoTools.php` | |
| 18.4 | 허용 호스트 목록만 접속(SSRF 방지) | 구현됨 | `src/Services/Repo/HostPolicy.php`, `src/Services/Repo/AllowedHosts.php`, `tests/Unit/SsrfRulesTest.php` | 호스트 연결 테스트(P26B), NAT64·6to4 보강(P31) |
| 18.4 | HTTP는 명시 등록 호스트만·평문 경고, hook·submodule·외부 필터 비활성 | 구현됨 | `src/Services/Repo/HostPolicy.php`, `src/Services/Repo/GitClient.php`, `tests/Unit/RepoSecurityTest.php` | |
| 18.4 | 저장소 연결·주기·자격 증명 변경 감사 로그 | 구현됨 | `src/Support/AuditActions.php`, `docs/audit_actions.md` | |
| 18.5 | 프로젝트 화면에 연결 상태·마지막 가져오기·마지막 리비전·다음 예정 | 구현됨 | `src/Views/projects/_repo_status.php`, `src/Services/Schedule/ScheduleStatus.php` | |
| 18.5 | 이력·비교에 출처와 리비전, GitHub 커밋 링크 | 구현됨 | `src/Services/Repo/RepoLinks.php`, `public/assets/js/ui/source_cell.js` | |
| 18.5 | 관리자 시스템 화면에 주기 분석 다음 실행·최근 실패 | 구현됨 | `src/Views/admin/schedules.php` | "시스템 관리 > 주기 분석" 탭 |
| 18.5 | 주기 분석 완료 알림 없음, 실패만 관리자에게 | 구현됨 | `src/Services/Notifications/NotificationService.php` | |
| 18.5 | 관리자 가이드에 저장소 연결·자격 증명·주기·연속 실패 대응 | 구현됨 | `resources/guide/admin/ko/15_repository.md`, `resources/guide/admin/ko/16_schedule.md` | |

## 19. 구현·운영 보완 요구사항

### 19.1 ~ 19.9

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 19.1 | 새 버전 스모크 테스트(결함 예제·문법 오류 예제), 통과만 활성화, 실패 시 유지·관리자 알림 | 구현됨 | `src/Services/Tools/ToolSmokeTest.php`, `tests/Integration/ToolRegistryTest.php`, `src/Services/Mail/AlertMailer.php` | 알림은 모니터링 경고와 관리자 이메일(`tool` 종류) |
| 19.1 | 관리자가 버전 차단·고정, 고정 시 자동 선택 중단 | 구현됨 | `src/Services/Admin/ToolAdmin.php`, `src/Views/admin/tools.php`, `tests/Integration/AdminToolsHttpTest.php` | |
| 19.1 | 결과 파서는 알 수 없는 필드 무시, 필수 필드 없을 때만 오류 | 구현됨 | `src/Services/Tools/ToolOutputParser.php`, `tests/Integration/ToolRunnersTest.php` | |
| 19.1 | 프로세스 메모리 8GB·출력 1GB(설정), Node 메모리 옵션 | 구현됨 | `config/config.ini.example`(`process_memory_limit`, `output_limit`), `src/Services/Process/ProcessOptions.php`, `src/Services/Tools/EslintRunner.php` | 메모리 제한은 Linux `prlimit`와 Node `--max-old-space-size`만, Windows는 없음(19.10 P03·P14). Linux 실측은 미확인 |
| 19.1 | 권장 서버 사양(메모리 32GB, CPU 8코어 이상)을 설치 가이드에 | 구현됨 | `docs/server_sizing.md`·`server_sizing.en.md` 1절, `resources/guide/admin/ko/01_install.md`·`en` "권장 서버 사양과 디스크 용량" | P32B. 명세 값 + 개발 PC 측정(50명 p95 0.5초 안, 100명 CPU 포화) — 측정하지 않은 사양은 "추정" 표시. 운영 서버 확인은 미확인 목록 |
| 19.2 | CSV 일괄 생성·비활성화, 미리보기, 로컬 계정 임시 비밀번호(24시간) | 구현됨 | `src/Services/Accounts/AccountImportService.php`, `src/Services/Accounts/AccountCsvReader.php`, `tests/Integration/AccountImportTest.php`, `docs/account_csv.md`, `docs/results/finish/P28A.md` | |
| 19.2 | LDAP/AD 인증 지원, 접속 허용 그룹 제한 없음 | 구현됨 | `src/Services/Auth/Ldap/LdapLogin.php`, `tests/Integration/LdapLoginTest.php`, `tests/Unit/LdapClientTest.php`, `docs/auth_ldap.md` | 가짜 디렉터리로만 시험했다. 실제 LDAP/AD는 미확인 |
| 19.2 | 디렉터리 인증 성공 + 앱 계정 없음 → 자동 생성(기본 역할 user) | 변경됨 | `docs/results/finish/P28B.md`(사용자 결정 2026-10-10, 11절), `src/Services/Auth/ExternalAccountProvisioner.php`, `tests/Integration/LdapLoginTest.php` | 역할 효력이 없는 비활성 "승인 대기" 계정을 만들고 관리자가 승인해야 쓸 수 있다(세션 없음). 이름·이메일은 생성 때 한 번만 외부 값 |
| 19.2 | LDAP 계정의 비밀번호 정책·잠금은 디렉터리가 담당, 관리자 승격은 앱에서만 | 구현됨 | `src/Services/Auth/LoginService.php`, `src/Services/Auth/Ldap/LdapLogin.php` | 같은 로그인 ID의 반복 실패는 앱이 제한(P28B) |
| 19.2 | LDAP 주소·Base DN·검색 계정은 설정, 검색 계정 비밀번호 암호화, LDAPS 권장 | 구현됨 | `src/Services/Auth/Ldap/LdapSettings.php`, `src/Services/Auth/AppSecrets.php`, `src/Views/admin/config_settings.php` | TLS 검증을 끄는 설정은 없다 |
| 19.2 | LDAP 장애 대비 로컬 관리자 유지 | 구현됨 | `src/Services/Auth/ExternalAccountProvisioner.php`, `src/Services/Auth/LoginService.php` | 활성 로컬 관리자는 늘 1명 이상 |
| 19.2 | LDAP 그룹을 앱 그룹에 자동 매핑 | 제외 | 명세 19.2("이번 범위에서 제외") | |
| 19.3 | 인증 없는 `/health`(허용 IP), 워커·DB·디스크·백업·큐 정체 JSON | 구현됨 | `src/Controllers/MonitoringController.php`, `src/Services/Monitoring/SystemHealth.php`, `tests/Integration/MonitoringTest.php`, `docs/monitoring.md`, `docs/results/finish/P30.md` | 접속 주소(REMOTE_ADDR)만 본다. 프록시 뒤는 미확인 |
| 19.3 | 임계치 초과 시 경고 로그 파일, 관리자 배너 | 구현됨 | `src/Services/Monitoring/LogAlertSink.php`, `src/Services/Monitoring/AdminAlertSink.php`, `src/Views/layout.php` | |
| 19.3 | 관리자 장애 이메일(워커·큐·디스크·백업·스모크·저장소, 1시간 1회) | 구현됨 | `src/Services/Mail/AlertMailer.php`, `src/Services/Mail/SmtpMailSender.php`, `tests/Integration/AlertMailTest.php`, `docs/results/finish/P30B.md` | 인증서 종류 추가, 복구 메일. 실제 SMTP는 미확인(가짜 수신기 시험) |
| 19.3 | 업로드·가져오기 시작 때 예상 필요 용량 사전 검사 | 구현됨 | `src/Services/Upload/DiskGuard.php`, `tests/Integration/UploadTest.php` | 진행 중 업로드 예약 합산(19.10 P06·P12) |
| 19.3 | 디스크 80% 경고, 90% 업로드·가져오기 차단(설정) | 구현됨 | `src/Services/Upload/DiskGuard.php`, `src/Support/ConfigSchema.php`(`disk.warn_percent`, `disk.block_percent`) | |
| 19.3 | 설치 가이드에 권장 용량 산정식(동시 분석 수 × (업로드 + 해제 상한) + 스냅샷 + 백업) | 구현됨 | `docs/server_sizing.md` 2·3절, `public/assets/js/lib/capacity.js`, `src/Views/admin/checks.php`(용량 추정 도우미), `tests/js/capacity.test.mjs`, 관리자 가이드 설치와 환경 | P32B. 명세 식 + DB(결함 560B·감사 로그 412B 측정)·Run 요약·로그 항, 경고 비율로 나눔, 백업 디스크 따로. 점검 탭 도우미는 읽기 전용 계산 |
| 19.4 | 백업 대상(DB·스냅샷·설정·bin/ 체크섬 매니페스트), 매일, 60일 | 구현됨 | `src/Services/Backup/BackupService.php`, `src/Services/Backup/BundleFormat.php`, `src/Services/Backup/ConfigSanitizer.php`, `docs/backup_restore.md` | 설정 사본은 민감 값을 비운다(P27B) |
| 19.4 | 암호화 키는 DB 백업과 분리 보관 | 구현됨 | `src/Services/Backup/KeyVault.php`, `src/Controllers/AdminBackupController.php` | `.pjgkey` 내보내기, 암호문구 보호 |
| 19.4 | 운영 디스크와 다른 저장 위치 | 구현됨 | `config/config.ini.example`(`[backup] dir`), `docs/backup_restore.md` | 같은 디스크면 화면 경고. UNC 경로는 미확인 |
| 19.4 | 분기 1회 복원 리허설·결과 기록 | 구현됨 | `tools/backup.php`(`--rehearse`), `src/Services/Backup/RestoreService.php`, `config/config.ini.example`(`rehearsal_interval_days = 90`) | |
| 19.4 | 업그레이드 순서(유지보수 모드·사전 안내 배너 → 진행 중 분석 완료 대기 → 자동 DB 백업 → 스키마 버전 기반 마이그레이션 → 실패 시 롤백) | 구현됨 | `src/Http/Middleware/UpgradeMaintenanceMiddleware.php`, `src/Services/UpgradeMaintenance.php`, `src/Services/Settings/SettingsService.php`(`commitWithMaintenance`), `src/App.php`(`preMaintenanceBackup`), `src/Services/Queue/Worker.php`, `tests/Integration/UpgradeMaintenanceTest.php`, 관리자 가이드 배포 묶음 "업그레이드 때", `docs/release_checklist.md` | P32B: 설정 `[maintenance]`(사전 공지 예정 시각·안내 문구·켜기), 켜는 순간 `pre_upgrade` DB 백업(실패하면 켜지 않음), 켜진 동안 admin 아닌 사용자 쓰기 503·워커 새 분석 시작 안 함(진행 중은 끝냄)·`/health`에 표시, 감사 `maintenance.on/off`. 실패 시: 마이그레이션 파일별 트랜잭션 롤백(자동) + 이전 묶음·`pre_upgrade` 복원 절차(수동) |
| 19.5 | XAMPP 하드닝 체크리스트 문서 | 구현됨 | `docs/hardening.md`, `resources/guide/admin/ko/23_security.md` | |
| 19.5 | 설치 스크립트가 체크리스트 점검, 미충족은 경고 | 구현됨 | `src/Services/HardeningCheck.php`, `tools/create_admin.php`(`--check-only`), `tests/Unit/HardeningCheckTest.php` | 시스템 관리 > 점검 탭에도 보안 줄(P31) |
| 19.5 | PHP·Apache·Node.js·Python 보안 패치 분기 점검 | 구현됨 | `docs/hardening.md` | 운영 절차(문서) |
| 19.5 | 같은 IP 10분 20회 실패 → 15분 차단, 관리자 즉시 잠금 해제 | 구현됨 | `src/Services/Auth/LoginService.php`, `src/Services/UserService.php`(`unlock`), `tests/Integration/AuthHttpTest.php` | |
| 19.6 | 성능 목표(동시 100명 p95 3초, 업로드 시작·상태 1초, 코드 뷰어 2초) | 구현됨 | `tools/loadtest/run.mjs`, `tools/perf/query_bench.php`, `docs/performance.md`, `docs/results/P31.md` | 측정 도구와 최적화는 있다. 개발 PC 한 대(부하 발생기 포함)에서 50명은 p95 0.5초 안이지만 100명은 CPU 포화로 대시보드 p95 4.4초였다. 목표 충족은 운영 서버에서 미확인 |
| 19.6 | 릴리스 수용 시험(시그니처 회귀, 자동 resolved·재오픈, 보안, RBAC, Git/Perforce E2E, 100명 부하) | 구현됨 | `tests/Integration/DefectLifecycleTest.php`, `tests/Integration/SafeUnzipTest.php`, `tests/Integration/XssCorpusTest.php`, `tests/Integration/RouteAuthMatrixTest.php`, `tests/e2e/phase1.php`, `tools/acceptance/run.mjs`, `tools/loadtest/run.mjs` | Perforce는 가짜 p4로만 확인 |
| 19.7 | REST `/api/v1`, OpenAPI, 공통 오류 형식(코드·메시지·요청 ID), page/size, 결함 409, AJAX CSRF 헤더 | 구현됨 | `src/Http/ApiException.php`, `src/Http/Pagination.php`, `docs/openapi.yaml`, `tests/Integration/OpenApiTest.php`, `tests/Unit/PaginationTest.php` | |
| 19.7 | 한 줄 JSON 로그(UTC·레벨·요청 ID·분석 ID·사용자 ID), 마스킹 | 구현됨 | `src/Support/Logger.php`, `src/Http/Middleware/RequestIdMiddleware.php`, `tests/Unit/LoggerTest.php` | |
| 19.7 | INI 하나 + 시크릿 분리, 시작 시 검증·기동 거부, 웹 즉시·워커 재시작 후 반영 | 구현됨 | `src/Support/Config.php`, `src/Support/ConfigSchema.php`, `tools/config_check.php`, `tests/Unit/ConfigTest.php` | 화면 설정 관리(`settings.local.ini`, P27A)도 추가 |
| 19.7 | THIRD_PARTY_NOTICES 포함, 가이드에서 열람, 출시 전 라이선스 검토 | 구현됨 | `THIRD_PARTY_NOTICES`, `docs/third_party.md` | 알려진 취약점 점검(인터넷 필요)은 미확인 |
| 19.7 | HTTPS 인증서 만료 30일 전 경고(헬스체크·배너·이메일), 만료일 표시, 사내 CA 등록·검증 끄기 금지 | 구현됨 | `src/Services/Monitoring/SystemHealth.php`, `src/Services/Mail/AlertMailer.php`, `config/config.ini.example`(`ca_cert_file`), `src/Support/ConfigSchema.php`(`ldap.ca_file`) | |
| 19.7 | 감사 로그 90일, append-only, 해시 체인, 읽기 전용 조회 화면 | 구현됨 | `src/Services/AuditLog.php`, `src/Support/AuditChain.php`, `src/Services/Audit/AuditAnchor.php`, `migrations/001_initial_schema.sql`(수정·삭제 금지 트리거), `src/Views/admin/audit_logs.php`, `tests/Integration/AuditLogTest.php`, `tests/Integration/AuditAnchorTest.php` | DB 밖 기록과 대조(P31) |
| 19.8 | FIFO, 사용자·즉시·주기 요청 같은 큐, 프로젝트당 1·전체 2, 막히면 뒤 프로젝트 먼저 | 구현됨 | `src/Services/Queue/AnalysisQueue.php`, `tests/Integration/AnalysisQueueTest.php`, `tests/Support/claim_race.php` | |
| 19.8 | 프로젝트별 주기 시각 분산 안내 | 구현됨 | `resources/guide/admin/ko/16_schedule.md`, `src/Views/projects/_schedule_form.php` | 명세는 설치 가이드라고 적었지만 관리자 가이드 주기 분석 절과 설정 화면(같은 시각 프로젝트 수)에서 안내 |
| 19.9 | 설치 가이드 | 구현됨 | `docs/install_guide.md`, `resources/guide/admin/ko/01_install.md` | |
| 19.9 | 운영 런북(장애 유형별 점검·조치, 워커 재기동, 큐 정체, 백업 복원) | 구현됨 | `resources/guide/admin/ko/24_troubleshooting.md`, `docs/worker_setup.md`, `docs/backup_restore.md`, `docs/monitoring.md` | "런북"이라는 이름의 단일 문서는 없고 여러 문서에 나뉘어 있다 |
| 19.9 | 업그레이드 가이드 | 구현됨 | `docs/install_guide.md` 11절, `resources/guide/admin/ko/02_deploy-bundle.md` | 짧은 참고 절. 19.4 순서와의 차이는 19.4 행 |
| 19.9 | 보안 점검표 | 구현됨 | `docs/hardening.md`, `resources/guide/admin/ko/23_security.md` | |
| 19.9 | 인수인계 체크리스트 | 구현됨 | `docs/handover_checklist.md`·`handover_checklist.en.md` | P32B. 계정·시크릿·키 보관 위치, 백업·리허설 기록, 워커 등록, 알림 수신자, 외부 연동 담당, 인증서 만료일, 절차서 위치(체크 칸·작성 칸, 화면이 판정하는 항목은 화면 주소) |
| 19.9 | 장애 연락 체계 | 제외 | 명세 19.9("운영팀이 작성하는 항목") | |

### 19.10 프로토타입 검증 반영 사항

19.10은 단계마다 확정한 결정을 모은 절이다. 단계 묶음별로 한 행씩 적었고, 앞 장 행에서 이미 다룬 내용은 그 행을 가리킨다.

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 19.10 P03 | ESLint 10.x·서버 flat config·Node 최소 버전·업로드 설정 무시·`.gitignore` 무시·`.eslintignore` 변환·ESLint 체크섬 사전 검증 | 구현됨 | `src/Services/Tools/EslintRunner.php`, `src/Services/Tools/NodeRuntime.php`, `tools/eslint/pjg.flat.config.cjs`, `tests/Integration/ToolRunnersTest.php` | 5.3 행 |
| 19.10 P03 | ruff 하위 폴더 설정 무시·안내, 메모리 제한 Linux `prlimit`만, intl 필수 | 구현됨 | `src/Services/Tools/RuffConfigResolver.php`, `src/Services/Tools/ToolProjectScan.php`, `tools/check_env.php` | Linux 미확인 |
| 19.10 P04 | 최상위 폴더 제거 1회, 대소문자 충돌 거부, 분석마다 제외 규칙·파일 목록 저장, 해시만 보관, 고유 키 | 구현됨 | `src/Services/Tools/ScopeWriter.php`, `src/Services/Defects/DefectIngest.php`, `tests/Integration/DefectLifecycleTest.php` | Linux 시그니처 시나리오 미확인 |
| 19.10 P05 | 기본 제외 `__MACOSX`·`.DS_Store`, 최상위 폴더 규칙 저장, 오류 종류 추가, CP949, PHP 8.2, 역슬래시 거부, 실제 쓴 바이트, 259자 | 구현됨 | `src/Services/Archive/SafeUnzip.php`, `tests/Integration/SafeUnzipTest.php` | |
| 19.10 P06 | 청크 업로드(세션 활동, `upload_id` 소유·404, 조립은 워커, 묶기 전 제외, 예약 합산, 사용자당 2개, `.lock` 잠금·`scandir`) | 구현됨 | `src/Services/Upload/UploadService.php`, `src/Support/FileLock.php`, `src/Support/Fs.php`, `tests/Integration/UploadTest.php`, `tests/Unit/FsTest.php` | |
| 19.10 P06 | 프로토타입 코드(`public/proto/`, `tools/proto/`) 삭제 | 구현됨 | `docs/results/finish/P07.md`(`public/proto/` 삭제) | `tools/proto/`·`tests/proto/`는 P32에서 삭제했다(웹 비노출 CLI 참고 코드라 P32까지 둠). 제품 코드는 `tools/proto/`를 불러오지 않았다 |
| 19.10 P07·P08 | 파일 목록 최근 N회 보관, "변경 없음" 플래그, `worker_crashed`, 발췌 U+FFFD, SQLite 3.38, 분석 행 삭제 금지, CSP, 대시보드 집계 방식 | 구현됨 | `docs/results/finish/P07.md`, `docs/results/finish/P08.md`, `src/Support/Db.php`, `src/Http/Middleware/SecurityHeadersMiddleware.php` | 대시보드는 저장 집계 대신 조회 계산 + 캐시(P20 측정 근거) |
| 19.10 P09 | Lucide 아이콘, 심각도 텍스트 동반, Chrome·Edge 123+, 정적 자산 해시 주소, URL 경로 계산 규칙 | 구현됨 | `public/assets/icons/lucide/`, `src/Support/AssetVersion.php`, `src/Http/StaticAssets.php`, `tests/Integration/StaticAssetsTest.php`, `docs/icons.md` | |
| 19.10 P10 | 최초 관리자 초기 비밀번호 "admin" 고정 | 구현됨 | `tools/create_admin.php`, `tests/Integration/InitialAdminTest.php`, `docs/results/finish/X02.md` | 하드닝 점검이 미변경을 경고 |
| 19.10 P10 | 최초 관리자 첫 로그인 때 비밀번호 변경 강제 | 변경됨 | `docs/results/finish/X17A.md`, `tests/Integration/InitialPasswordBannerTest.php`, `src/Services/HardeningCheck.php`, `docs/results/P31.md` | 첫 로그인 강제 변경을 없앴다(X17A, 사용자 결정). 바꾸기 전까지 경고 배너·모니터링 경고·하드닝 경고를 보인다(P31). 다른 계정의 임시 비밀번호는 지금도 변경 강제 |
| 19.10 P10 | 비밀번호 정책 8자·2종, 72바이트 초과 거부, 관리자 비밀번호 직접 설정 | 구현됨 | `src/Services/Auth/PasswordPolicy.php`, `tests/Unit/PasswordPolicyTest.php`, `tests/Integration/AdminPasswordTest.php` | |
| 19.10 P10 | IP 제한용 신뢰 프록시 목록(`trusted_proxies`, 목록 프록시에서만 `X-Forwarded-For`) | 구현됨 | `src/Http/ForwardedHeaders.php`, `src/Support/IpList.php`, `src/App.php`(`handle` 맨 앞), `src/Support/EnvironmentCheck.php`(점검 줄), `tests/Integration/TrustedProxiesTest.php`, `docs/hardening.md` 4a절, 관리자 가이드 보안 운영 | P32B. 기본(빈 목록) = 이전과 같음. 로그인 시도 제한·감사 IP·/health 허용 IP·HTTPS 강제·HSTS가 한 함수의 결과를 씀. 화면 설정 관리 > 모니터링(`security.*` 중 유일한 예외) |
| 19.10 X05·X06 | 표시 이름·이메일 수정(관리자·본인), 이름 규칙 | 구현됨 | `src/Services/UserProfileRules.php`, `tests/Integration/UserProfileTest.php`, `tests/Integration/UserCreateRulesTest.php` | |
| 19.10 X05 | LDAP 계정의 이름·이메일은 앱에서 수정 불가(409 `ldap_managed`) | 변경됨 | `docs/results/finish/P28B.md` 11절("X05 정정") | 외부 값으로 갱신하지 않으므로 외부 계정도 관리자·본인이 앱에서 고친다. `src/Lang/`에 옛 문구 키만 남아 있다 |
| 19.10 P11·P11A·P11B | 제외 패턴 문법, 삭제 규칙, 이름 규칙, 홈 = 프로젝트 목록, 그룹·접근 범위, 그룹 메뉴 위치 | 구현됨 | `src/Services/ProjectService.php`, `src/Services/ProjectAccess.php`, `src/Support/Navigation.php`, `tests/Integration/ProjectRouteAccessTest.php` | 3.1·4 행 |
| 19.10 P12·P13·X04 | 업로드 API 결정(조립본 위치, 예약식, 429, 404), 해제 결정(확인 대기 24시간, zip 정리), 폴더 선택 화면 | 구현됨 | `src/Services/Upload/UploadService.php`, `src/Services/Upload/UploadProcessor.php`, `docs/results/finish/P12.md`, `docs/results/finish/P13.md`, `docs/results/finish/X04.md` | Firefox·창 있는 브라우저는 미확인 |
| 19.10 P14 | 워커 선점·FIFO, 하트비트·복구, 프로세스 트리 종료, Windows 메모리 제한 없음, 큰 디렉토리 삭제는 워커 | 구현됨 | `src/Services/Queue/Worker.php`, `src/Services/Queue/WorkerState.php`, `src/Support/Trash.php`, `tests/Integration/WorkerRecoveryTest.php` | |
| 19.10 P15·P16 | 도구 선택·검증, 설정 정책, 실패 코드, 결과 파일, 분석 범위, 원자적 반영, 스냅샷, 알려진 한계 | 구현됨 | `src/Services/Tools/ToolAnalysisRunner.php`, `src/Services/Tools/DefectWriter.php`, `src/Services/Defects/AnalysisFinalizer.php`, `docs/results/finish/P15.md`, `docs/results/finish/P16.md` | |
| 19.10 P17·P18·P19 | CSP nonce, 필터 칩, 코드 뷰어 ±200줄, 404 숨김, 동시 수정·일괄, "내 결함", 분석 화면·실패 카탈로그 | 구현됨 | `src/Http/Middleware/SecurityHeadersMiddleware.php`, `src/Views/defects/mine.php`, `src/Support/ErrorCatalog.php`, `docs/results/finish/P17.md`, `docs/results/finish/P18.md`, `docs/results/finish/P19.md` | |
| 19.10 P20A | 관리자 메뉴 "도구·워커 관리"(분석 도구·워커·점검), 웹의 권한 경계 | 변경됨 | `docs/results/finish/X10.md`, `src/Support/Navigation.php`, `src/Views/admin/_subnav.php` | 기능과 권한 경계는 그대로이고, 메뉴 이름이 "시스템 관리"로 바뀌었으며 "설정 관리"가 그 아래 첫 탭이 되었다(X10). 알림 보관·허용 호스트는 설정 관리 안으로(X12) |
| 19.10 P20R | Run(성공 분석 회차), Run별 결함 현황, 과거 Run 읽기 전용, 되채움 | 구현됨 | `src/Services/Runs/RunService.php`, `src/Services/Runs/RunTracker.php`, `tools/backfill_runs.php`, `tests/Integration/RunApiTest.php`, `docs/results/finish/P20R.md` | |
| 19.10 P20R·P20S | 임의의 두 Run을 비교하는 "Run 비교" 기능·메뉴 | 제외 | 명세 19.10 P20R "결정 (사용자)"·P20S 결과, `docs/results/finish/P20S.md` | 메뉴·화면·API·문자열·시험에서 모두 지웠다. 직전 Run 대비 비교는 히스토리(8장)에 있다 |
| 19.10 P20R·P21B | 과거 Run 상세 최근 15개 보관, 관리자 수동 삭제 | 구현됨 | `src/Services/Runs/RunDetails.php`, `tests/Integration/RunDetailsTest.php`, `docs/runs_retention.md`, `docs/results/finish/P21B.md` | |
| 19.10 P20·P20S | 대시보드 구성·캐시·접근성, 과거 Run 조회 최적화, WAL 체크포인트 분리 | 구현됨 | `src/Services/Dashboard/DashboardService.php`, `src/Support/WalCheckpoint.php`, `tests/Integration/WalCheckpointTest.php`, `docs/results/finish/P20.md` | |
| 19.10 P21·P21U·P21V·P21C | 정적 파일 버전, 히스토리·비교 패널, 결함 표 열·말줄임·검색 영역, 대시보드 보완, 같은 파일 업로드 안내 | 구현됨 | `src/Services/History/HistoryService.php`, `public/assets/js/ui/defect_table.js`, `public/assets/js/lib/defect_search.js`, `docs/results/finish/P21.md`, `docs/results/finish/P21U.md`, `docs/results/finish/P21V.md`, `docs/results/finish/P21C.md` | |
| 19.10 P21S·X07 | 규칙 기본 심각도 매핑, 테스트 파일 ESLint 전역 | 구현됨 | `src/Services/Severity/SeverityMap.php`, `tools/severity_apply.php`, `config/eslint.default.json`, `docs/severity_mapping.md`, `docs/results/finish/X07.md` | |
| 19.10 P22·X08·P22B·P22C | 규칙 설명의 활성 도구 버전 기준, 검색, 언어 탭, 관리자 규칙 켜기·끄기·일괄 | 구현됨 | `src/Services/Rules/RuleQuery.php`, `src/Services/Rules/RuleOverrides.php`, `tests/Integration/RuleOverridesTest.php`, `tests/Integration/RuleBulkTest.php`, `docs/results/finish/P22B.md`, `docs/results/finish/P22C.md` | ruff 비호환 규칙 쌍은 화면 표시와 실제 적용이 다를 수 있음(P22C 열린 항목) |
| 19.10 P23 | 결함 표 오른쪽 위 "Excel로 내보내기", 화면과 같은 조건 | 구현됨 | `src/Views/partials/export_bar.php`, `public/assets/js/ui/export_buttons.js` | 15.1 행 |
| 19.10 P24 | 알림 종류 5개·보관·폴링 | 구현됨 | `src/Services/Notifications/NotificationService.php`, `tests/Integration/NotificationTest.php` | 17 행 |
| 19.10 P25·P26·P26B·P27C·X09 | 저장소 연동, 주기 분석·재시도, 허용 호스트 연결 테스트·심볼릭 링크 건너뜀, 4xx 도달 판정·인덱스, bin/ git·p4 | 구현됨 | `src/Services/Repo/HostProbe.php`, `src/Services/Repo/HostTests.php`, `tests/Integration/HostProbeTest.php`, `docs/results/finish/P25.md`, `docs/results/finish/P26B.md`, `docs/results/finish/P27C.md` | 18 행 |
| 19.10 P27 | 변경 없음은 Run 번호를 만들지 않음(P20R의 "no_change도 Run 번호" 문장 대체), 강제 분석 | 구현됨 | `src/Services/Repo/UnchangedSourceCheck.php`, `tests/Integration/RepoUnchangedFlowTest.php`, `docs/results/finish/P27.md` | 명세 19.10 안에서 이미 대체를 기록 |
| 19.10 P19B | 대용량·Apache·Linux 검증 결과와 제안 | 구현됨 | `docs/results/finish/P19B.md`, `docs/results/finish/P19C.md`, `tools/backfill_seen.php` | 반영 중 쓰기 대기 문제는 P19C·P31에서 줄였다. Linux 미확인 |
| 19.10 끝 | 폴더 묶기는 STORE만(Deflate 없음), STORE 기준 2GB 판단 | 구현됨 | `public/assets/js/lib/upload_core.js`, `tests/js/upload.test.mjs` | Edge·Firefox 실제 조작은 미확인 |

## 20. UI 아이콘 및 직관성

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 20.1 | 아이콘은 텍스트와 함께, 아이콘 단독은 툴팁·키보드 설명 | 구현됨 | `src/Support/Icons.php`, `docs/icons.md`, `docs/ui_conventions.md` | |
| 20.1 | 같은 개념은 같은 아이콘, 한 아이콘 = 한 뜻 | 구현됨 | `public/assets/icons/icons.json`, `tools/check_icons.mjs`, `tests/Unit/IconsTest.php` | |
| 20.1 | 색에만 의존하지 않음, 비보편 아이콘은 텍스트 필수 | 구현됨 | `src/Views/partials/badge.php`, `tools/check_theme_contrast.mjs` | 다크 테마도 명암비 검사(P27D) |
| 20.2 | 오픈소스 SVG 세트(Lucide, ISC) 로컬 포함, currentColor, 16/20/24px, 스프라이트 | 구현됨 | `public/assets/icons/lucide/`, `public/assets/icons/sprite.svg`, `tools/build_icons.php` | |
| 20.2 | 세트에 없는 아이콘(도구·출처·로고)은 자체 제작, 외부 제품 로고 금지 | 구현됨 | `public/assets/icons/custom/`, `src/Views/partials/lang_badge.php` | |
| 20.3 | 의미별 아이콘(심각도·결함 상태·분석 상태·언어/도구·소스 출처·주 메뉴·주요 동작·시스템 상태) | 구현됨 | `public/assets/icons/icons.json`, `src/Views/partials/icon_legend.php`, `tools/check_icons.mjs` | |
| 20.3 | 관리자 메뉴 이름(사용자 관리, 그룹 관리, 프로젝트 관리, 시스템 현황, 감사 로그, 관리자 가이드) | 변경됨 | `docs/results/finish/X10.md`, `docs/results/finish/X13.md`, `src/Support/Navigation.php` | "도구·워커 관리" → "시스템 관리"(설정 관리는 그 아래 첫 탭), "시스템 현황" → "시스템 모니터링"(X10). 영어 주 메뉴 "Rule descriptions" → "Rules"(X13) |
| 20.4 | 의미 아이콘 aria-label, 장식 아이콘 숨김, 아이콘 버튼 32px 이상 | 구현됨 | `src/Support/Icons.php`, `docs/ui_conventions.md` | 실제 스크린 리더 낭독은 미확인 |
| 20.4 | 파괴적 동작은 경고 색·전용 아이콘·확인 단계 | 구현됨 | `public/assets/js/ui/dialog.js`, `src/Views/projects/delete.php` | |
| 20.4 | 빈 상태 화면에 아이콘과 다음 행동 안내 | 구현됨 | `src/Views/partials/empty_state.php` | |
| 20.4 | 스프라이트로 한 번 내려받고 캐시 | 구현됨 | `public/assets/icons/sprite.svg`, `src/Http/StaticAssets.php` | 내용 해시 주소 + 장기 캐시 |
| 20.5 | UI 점검을 릴리스 수용 기준에 추가 | 구현됨 | `tools/check_icons.mjs`, `tools/check_i18n_theme_ui.mjs`, `tools/check_theme_contrast.mjs` | |

## 부록. 확정된 결정 사항 및 확인 필요 항목

| 절 | 항목 | 상태 | 근거(파일·시험·보고서) | 비고 |
| --- | --- | --- | --- | --- |
| 부록 | 결정 표의 "JS 설정 파일: 정적 설정(JSON/YAML)만 허용" | 변경됨 | 명세 19.10 P03 | 5.3 행과 같다 |
| 부록 | 결정 표의 "라이브러리: Dompdf, PhpSpreadsheet 로컬 포함" | 제외 | 명세 15·15.1, `docs/results/finish/P23.md` | 2장 행과 같다 |
| 부록 | 결정 표·추가 결정의 나머지 항목(자동 resolved, 재감지, 시그니처, 큐, 스냅샷, 계정 보안, 비교 기준, 저장소, 헬스체크, 백업, 하드닝, API·로그·설정·라이선스·인증서·감사 로그, FIFO, 아이콘) | 구현됨 | 각 본문 행 | 업그레이드 자동화·문서 일부는 19.4·19.9 행 |
| 부록 | 주요 기본값(zip 2GB, 타임아웃 4시간, 동시 2개, 백업 매일·60일, 전체 스냅샷 5회, 페이지 50건) | 구현됨 | `config/config.ini.example`, `src/Support/ConfigSchema.php` | |
| 부록 | 확인 필요 항목의 가정(청크 8MB·24시간, 제외 패턴은 관리자만, 대상 확장자, 가져오기 30분·재시도 5분, SSH·LFS·submodule 미지원, HTTP는 명시 등록 호스트만, Perforce 단일 depot, LDAP 속성 매핑은 설정) | 구현됨 | `config/config.ini.example`, `docs/repo_integration.md`, `docs/auth_ldap.md` | 알림 보존 30일 가정은 17장 본문(읽음 7일·안 읽음 30일)으로 확정. 네트워크 환경(방화벽·프록시) 확인은 운영 몫(`docs/unverified.md`) |

## 변경·제외 결정 목록

본문 표에서 "변경됨"·"제외"로 적은 행과 그 결정 출처다. 같은 결정이 여러 행에 걸치면 "결정" 칸에 같은 기호를 적었다.

### 변경됨 (16행, 결정 10개)

| 결정 | 명세 행 | 무엇이 다른가 | 결정 출처 |
| --- | --- | --- | --- |
| A | 5.3 ruff 설정 | 분석 루트 설정만 쓰고 하위 폴더 설정은 무시·안내, `~`·`$`를 가리키는 extend도 거부, 늘 `--no-respect-gitignore` | 명세 19.10 P03(5.3을 대체), P15 |
| A | 5.3 ESLint 설정 | ESLint 10.x + 서버가 만든 flat config, 업로드된 ESLint 설정은 통째로 무시, `.eslintignore`만 변환 | 명세 19.10 P03 |
| A | 10 업로드 설정 파일 정책 | 위 두 행과 같다 | 명세 19.10 P03 |
| A | 15.2 JS 설정 무시 표시 | 업로드 ESLint 설정은 항상 무시하므로 그 사실과 ruff 하위 설정 무시를 안내 | 명세 19.10 P03 |
| A | 부록 결정 표 "JS 설정 파일" | 위와 같다 | 명세 19.10 P03 |
| B | 5.1 최상위 단일 폴더 제거 | 프로젝트 첫 업로드에서 제거 여부를 정해 저장하고, 어긋나면 경고 후 확인 | 명세 19.10 P04·P05 |
| C | 5.4 업로드 전 대상 확장자 검사 | 서버 해제 뒤·큐 등록 전에 검사하고 확인을 받음 | 명세 19.10 P06·P13 |
| D | 10 HTTPS(Apache HSTS) | HSTS는 Apache가 아니라 앱 한 곳(`SecurityHeadersMiddleware`)에서만 보냄 | P31(`docs/results/P31.md`) |
| E | 10 감사 로그 범위 | 로그인 성공·실패는 감사 로그가 아니라 앱 로그에만 | 명세 19.10 P10, `docs/results/finish/P10.md`, `docs/results/finish/P30.md` |
| F | 14 관리자 시스템 현황 화면 | "시스템 모니터링" 화면(카드 9개)과 "시스템 관리" 탭으로 나뉨 | X10(`docs/results/finish/X10.md`), P30(`docs/results/finish/P30.md`) |
| F | 17 알림 보관 설정 위치 | "도구·워커 관리 > 알림 보관"에서 "시스템 관리 > 설정 관리"의 알림 탭으로 | X10, X12(`docs/results/finish/X12.md`) |
| F | 19.10 P20A "도구·워커 관리" 메뉴 | "시스템 관리"로 이름 변경, 설정 관리가 그 아래 첫 탭 | X10, X12 |
| F·G | 20.3 관리자 메뉴 이름 | "시스템 현황" → "시스템 모니터링", "도구·워커 관리" → "시스템 관리". 영어 주 메뉴 "Rule descriptions" → "Rules" | X10, X13(`docs/results/finish/X13.md`) |
| H | 19.2 LDAP 첫 로그인 자동 생성(역할 user) | 비활성 "승인 대기" 계정을 만들고 관리자가 승인(LDAP·OIDC·SAML 공통) | P28B 사용자 결정 2026-10-10(`docs/results/finish/P28B.md`) |
| I | 19.10 P10 최초 관리자 첫 로그인 변경 강제 | 강제하지 않음. 바꾸기 전까지 경고 배너·하드닝·모니터링 경고 | X17A 사용자 결정(`docs/results/finish/X17A.md`). 초기 비밀번호 "admin" 고정은 X02(`docs/results/finish/X02.md`)이며 19.10 P10에 반영되어 "구현됨" |
| J | 19.10 X05 LDAP 계정 이름·이메일 읽기 전용(`ldap_managed`) | 외부 값으로 갱신하지 않으므로 외부 계정도 앱에서 수정 | P28B 11절 "X05 정정" |

결정 G(X13 영어 메뉴 이름)는 20.3 행에 F와 함께 들어 있다.

참고: 비밀번호 정책 변경(10자·3종 → 8자·2종, 19.10 P10)과 PDF 제외 문구처럼 결정이 이미 명세 본문(3장·15장)에 반영된 것은 본문대로 판정했다.

### 제외 (7행, 결정 4개)

| 결정 | 명세 행 | 결정 출처 |
| --- | --- | --- |
| K | 2 Dompdf·PhpSpreadsheet 포함 | 명세 15·15.1, P23(`docs/results/finish/P23.md`) — XLSX는 직접 작성 |
| K | 15 PDF 내보내기 | 명세 15 "사용자 결정, P23", `docs/results/finish/P23.md` |
| K | 부록 결정 표 "Dompdf, PhpSpreadsheet" | 위와 같다 |
| L | 3.1 범위 밖(LDAP 그룹 자동 매핑, CSV 그룹 열, 조회 전용 단계) | 명세 3.1 "범위 밖" |
| L | 19.2 LDAP 그룹 자동 매핑 | 명세 19.2 "이번 범위에서 제외" |
| M | 19.9 장애 연락 체계 | 명세 19.9 "운영팀이 작성하는 항목" |
| N | 19.10 "Run 비교"(임의의 두 Run 비교) | 명세 19.10 P20R "결정 (사용자)", P20S(`docs/results/finish/P20S.md`) |

### 프로토타입 코드 삭제 (참고)

- `public/proto/`는 로그인 없이 웹에 노출되므로 P07에서 지웠다(`docs/results/finish/P07.md`).
- `tools/proto/`(CLI 프로토타입·결과 문서)와 `tests/proto/`는 P32에서 지웠다. 제품 코드는 이 폴더를 불러오지 않았다(CLAUDE.md 규칙). 상태는 "구현됨"(19.10 P06 행)이다.

## 미구현

없음. P32 검수에서 남았던 6건(명세 11 규칙 설명 보강, 19.1 권장 사양, 19.3 용량 산정식, 19.4 업그레이드 유지보수 모드, 19.9 인수인계 점검표, 19.10 P10 신뢰 프록시)은 **P32B에서 구현**했다(`docs/results/P32B.md`). 위 표의 해당 행이 근거다.

- 명세 19.6 성능 목표(동시 100명 p95 3초)는 측정 도구·최적화를 갖춰 "구현됨"으로 셌지만, 개발 PC 100명에서는 대시보드 p95가 4.4초였다(CPU 포화). 목표 충족 여부는 운영 서버 측정(`docs/unverified.md` 1절)으로 판단한다.

## 명세 밖 추가 기능 (집계에 넣지 않음)

명세에 없지만 결정에 따라 더한 기능이다. 근거만 적는다.

| 기능 | 근거 |
| --- | --- |
| SSO — OpenID Connect | `src/Services/Auth/Oidc/OidcLogin.php`, `tests/Integration/OidcLoginTest.php`, `docs/auth_oidc.md`, `docs/results/finish/P28C.md` |
| SSO — SAML 2.0 | `src/Services/Auth/Saml/SamlLogin.php`, `tests/Integration/SamlLoginTest.php`, `docs/auth_saml.md`, `docs/results/finish/P28D.md` |
| 화면 언어 한국어·영어, 라이트·다크·시스템 테마 | `src/Lang/en.php`, `public/assets/css/tokens.css`, `docs/results/finish/P27D.md` |
| 화면 설정 관리(카테고리 탭, 미리보기, 출처, 충돌 안내) | `src/Services/Settings/SettingsService.php`, `tests/Integration/ConfigSettingsTest.php`, `docs/settings.md`, `docs/results/finish/P27A.md`, `docs/results/finish/P27F.md` |
| 백업·복원 화면, 새 PC 이전 | `src/Controllers/AdminBackupController.php`, `docs/backup_restore.md`, `docs/results/finish/P27B.md` |
| 관리자 규칙 켜기·끄기·일괄 변경 | `src/Services/Rules/RuleOverrides.php`, `docs/results/finish/P22B.md`, `docs/results/finish/P22C.md` |
| 프로젝트당 결함 수 경고·상한 | `tests/Integration/DefectLimitTest.php`, `docs/results/finish/P19C.md` |
| 감사 로그 설명 문장, DB 밖 기록 대조 | `src/Services/Audit/AuditDescriber.php`, `src/Services/Audit/AuditAnchor.php`, `docs/results/finish/X17.md`, `docs/results/P31.md` |
| 배포 묶음 만들기·검증 | `tools/build_bundle.php`, `tools/verify_bundle.php`, `tests/Integration/VerifyBundleTest.php` |

## 집계 방법

- 본문 표(1장~부록)의 행마다 상태 하나를 매겼다. 같은 결정이 여러 행에 나오면(예: 5.3·10·15.2·부록의 도구 설정 정책) 행마다 센다.
- 요약 표의 숫자는 본문 표의 상태 칸을 센 값이다. "변경·제외 결정 목록"은 같은 행을 다시 적고 결정마다 기호(A~N)를 붙여 결정 수를 셀 수 있게 했다.
