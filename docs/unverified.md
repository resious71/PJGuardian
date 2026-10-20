# 미확인 목록 — 누가·어디서·어떻게 확인하나 (P31 C-5, P32 갱신)

지금까지의 단계 보고(`docs/results/`, `docs/results/finish/`)에 "미확인"으로 남은 항목을 종류별로 모았다(보고서의 "미확인" 표기 약 140곳).
대부분은 **개발 PC(Windows 11) 한 대에서 만든 앱을 다른 환경에서 아직 돌려 보지 않은 것**이다. 운영 반영 전에 아래 표를 위에서부터 확인하고, 확인하면 "결과" 칸에 날짜·사람·결과를 적는다.

| 기호 | 뜻 |
| --- | --- |
| 운영 | 운영 서버(Linux 또는 운영 Windows) 설치 담당자가 그 서버에서 |
| 조직 | 조직의 실제 외부 시스템 담당자와 함께(디렉터리·IdP·저장소·메일) |
| 사람 | 사람이 화면을 직접 보고·조작해서(자동 점검으로 대신할 수 없는 것) |
| 개발 PC | 이 PC에서 가능 — P31에서 확인한 것은 결과 칸에 적음 |

## 0. 운영 반영 순서 (P32) — 운영 서버에서 사용자가 직접 할 확인

P32 인수 시험은 개발 PC(Windows 11)에서 **릴리스 묶음을 새 폴더에 설치**해 16개 시나리오를 끝에서 끝까지 확인했다(`docs/results/P32.md`, 외부 시스템은 가짜). 아래는 그 시험으로 대신할 수 없어 운영 환경에서 확인할 것을 **이 순서대로** 적은 것이다. 앞 단계가 안 되면 뒤 단계를 확인할 수 없다.

1. **Linux 설치**(운영이 Linux면): 묶음을 풀어 `docs/install_guide.md` 1~9절 → `php tools/check_env.php` FAIL 0 → 워커 systemd 등록 → 작은 프로젝트 업로드·분석 → `php tools/run_all_tests.php`(저장소 사본으로) — 1절 첫 세 줄.
2. **Apache https·HSTS**: vhost·`FallbackResource`·HTTP→HTTPS 301, `curl -skI https://<주소>/login`의 `Strict-Transport-Security` 한 줄, `data/`·`config/`·`bin/`·백업 폴더 404 — 1절.
3. **방화벽·프록시 뒤 `/health`**: 허용 IP = 감시 도구(또는 프록시) 주소, 허용 밖 404, 워커 정지 때 503, 프록시 시간 상한(계정 CSV 1,000행 약 78초) — 1절.
4. **실제 LDAP/AD·IdP(OIDC·SAML)**: 설정 관리 탭의 연결 시험 → 시험 계정 첫 로그인 → 승인 → 로그인. IdP에 등록하는 콜백은 `base_url` 기준 — 2절.
5. **실제 SMTP·인증서**: 관리자 이메일 시험 메일(STARTTLS·SMTPS, 공인·사내 CA 인증서) → 받은 편지함·스팸함, 디스크 경고 같은 실제 경고 한 통 — 2절. HTTPS 인증서 만료 카드(`monitoring.tls_cert_file`).
6. **실제 Git 호스트·Perforce**: 허용 호스트 연결 테스트 → 프로젝트 연결 → 지금 가져와 분석 → 주기 분석 하루 — 2절.
7. **실제 Excel·LibreOffice로 내보낸 파일 열기**(수식 무력화·한글·긴 셀) — 3절.
8. **스크린 리더**(NVDA·내레이터)로 주요 화면 — 3절.
9. **운영 서버 성능**: `node tools/loadtest/run.mjs`로 동시 사용자 p95(명세 19.6 기준 3초 — 개발 PC 100명에서 대시보드 4.4초, `docs/performance.md`) — 1절 마지막 줄.

## 1. 운영 OS·웹 서버

| 항목 | 누가·어디서 | 방법 | 결과 |
| --- | --- | --- | --- |
| Linux 전체(제품 시험·E2E·워커·도구 실행) — 모든 단계 보고의 "Linux 미확인" | 운영(Linux) | `docs/linux_verification.md` 순서대로 `php tools/run_all_tests.php`, `php tests/e2e/phase1.php` | |
| Linux 파일 권한(`config/` 쓰기, `secrets.ini` 0640, bin/ 읽기 전용 0555), 점검 탭 보안 줄의 "비밀 파일 권한"(Windows는 확인 불가) | 운영(Linux) | `docs/install_guide.md` 5.1 명령 뒤 시스템 관리 > 점검 탭에서 보안 줄 확인 | |
| Linux `prlimit` 메모리·파일 크기 제한, ruff `extend` 경로, Node 실행, 임시 SQLite 정리·캐시 설정 실측 | 운영(Linux) | 분석 실행 후 분석 상세·워커 로그, `php tools/check_env.php` | |
| 운영 Apache https(vhost·`FallbackResource`·HSTS **한 번**·보안 헤더·HTTP→HTTPS 301·`data/`·`config/`·`bin/` 404) | 운영 | `docs/xampp_vhost.md` 검증 절 — `curl -skI https://<주소>/login`에서 `Strict-Transport-Security`가 1줄인지(P31: 앱 한 곳에서만 보냄, vhost의 옛 `Header always set` 줄은 지움) | P31: 개발 Apache가 꺼져 있어 이중 송출 구조는 코드·문서로만 판단 |
| Apache에서 설정 관리 저장(settings.local.ini 쓰기), 백업 내려받기, 큰 업로드 | 운영 | 화면에서 저장·내려받기·업로드 | |
| 배포 묶음으로 새 서버 설치(Linux·Apache) | 운영 | `php tools/build_bundle.php --zip`(빌드 PC) → 운영 서버에 풀고 설치 가이드 | 개발 PC: P31에서 zip을 새 폴더에 풀어 설치·로그인까지 확인(Windows·내장 서버). **P32: 인수 시험이 묶음 설치 → 16개 시나리오(분석·저장소·외부 인증(가짜)·백업 → 다른 새 폴더에 키와 함께 복원)까지 확인** — 그 과정에서 묶음에 `samples/`(도구 스모크 시료)가 빠진 결함, 키를 가져오는 복원이 secrets.ini를 깨뜨린 결함, 화면 설정 덧씌우기 순서 결함을 고침 |
| 프록시 뒤 `/health`(허용 IP = 프록시 주소, 프록시에서 접근 제한), 프록시 시간 상한과 계정 CSV 1,000행(약 78초) | 운영 | `docs/monitoring.md` 6절, `docs/performance.md` 6절 | |
| 운영 서버 성능(부하 시험·반영 대기·조회) — `docs/performance.md`의 숫자는 개발 PC 기준 | 운영 | `node tools/loadtest/run.mjs`, `php tools/seed_defects.php --bench`, `php tools/perf/query_bench.php`를 운영 서버(또는 같은 사양)에서 | |

## 2. 실제 외부 시스템

| 항목 | 누가·어디서 | 방법 | 결과 |
| --- | --- | --- | --- |
| 실제 LDAP/AD(TLS·검색 계정·속성 매핑·비활성 플래그) — 시험은 가짜 디렉터리 | 조직 | 설정 관리 > LDAP/AD 연결 시험 → 시험 계정 첫 로그인 → 승인 | |
| 실제 OIDC IdP(Entra ID·Keycloak 등: 디스커버리·PKCE·클레임·제공자 로그아웃) | 조직 | `docs/auth_oidc.md` 순서, 연결 시험 → 로그인 | |
| 실제 SAML IdP(서명·암호화 어서션·cross-site POST·303·Lax 쿠키) | 조직 | `docs/auth_saml.md` 순서, 설정 확인 4단계 → 로그인 | |
| 실제 Git 호스트(GitHub Enterprise 등 https·토큰)와 사내 Perforce(`p4 trust` 지문·`changes` 출력·권한) | 조직 | 허용 호스트 등록 → 연결 테스트 → 지금 가져와 분석 | |
| 실제 SMTP 서버(사내 중계·클라우드 메일, 공인 인증서의 STARTTLS·SMTPS) — 시험은 가짜 수신기 | 조직 | 설정 관리 > 관리자 이메일 > 시험 메일 → 받은 편지함(스팸함) | |
| UNC 네트워크 드라이브를 백업 폴더로 | 운영 | 백업 폴더를 `\\server\share\...`로 설정 → 지금 백업 → 검증 → 복원 리허설 | P27B: 경로 검사만 확인 |
| 실제 리버스 프록시 뒤(Apache·nginx·부하 분산기)에서 신뢰 프록시(`security.trusted_proxies`)로 사용자 주소·https가 맞게 잡히는지(로그인 시도 제한·감사 IP·/health·HSTS) — 시험은 가짜 헤더로만(P32B) | 운영 | 프록시 주소 등록 → 점검 탭 "신뢰 프록시" 줄 → 다른 PC에서 로그인 실패를 일으켜 감사·앱 로그의 IP 확인 | |
| 업그레이드 유지보수 모드를 실제 업그레이드에 써 보기(사전 공지 → 켜기 = 직전 백업 → 분석 끝나기를 기다림 → 교체·마이그레이션 → 끄기) — 개발 PC 시험은 단계별로만(P32B) | 운영 | 관리자 가이드 "배포 묶음 > 업그레이드 때" 순서대로 | |
| 권장 사양·용량(`docs/server_sizing.md`)의 추정값(압축 해제 4배, 감사 로그 하루 1,000행, 8코어 서버의 동시 100명 응답) | 운영 | 몇 주 운영 뒤 점검 탭 디스크 사용 현황·감사 로그 건수로 바꿔 다시 계산, 부하 시험 | |

## 2a. 규칙 쉬운 설명에 넣지 않은 규칙 (P32B)

서버 기본 설정에서 켜지는 규칙 중 쉬운 설명(`resources/rules/enrichment`)을 넣지 않은 것 — 확신할 수 있는 짧은 예시를 만들 수 없었거나 드문 규칙이다. 넣으려면 도구 원문을 읽고 쓴 뒤 `node tools/rules_guide_check.mjs --write`로 확인한다(`docs/rules.md` 5절).

| 도구 | 규칙 | 이유 |
| --- | --- | --- |
| ruff | E902 | 파일을 읽을 수 없을 때(권한·깨진 링크)만 나와 코드 예시로 재현할 수 없음 |
| ruff | F621 | 별표 할당 앞 대상이 256개를 넘어야 나와 짧은 예시가 불가 |
| ESLint | no-octal, no-with, no-delete-var, no-dupe-args | 엄격 모드(모듈)에서는 문법 오류라 검사기로 확인할 예시를 만들 수 없음 |
| ESLint | no-irregular-whitespace, no-misleading-character-class, no-nonoctal-decimal-escape, no-control-regex | 보이지 않는 문자·특수 유니코드가 필요해 예시로 읽기 어려움 |
| ESLint | no-useless-backreference, no-empty-static-block, no-empty-character-class, no-shadow-restricted-names, no-empty-pattern, no-unused-labels, no-regex-spaces, no-compare-neg-zero | 일상 코드에서 드묾(목표 개수 조정) |
| ESLint | @typescript-eslint/no-unsafe-declaration-merging, prefer-namespace-keyword, triple-slash-reference | 드묾 |

## 2b. 오픈소스 공개 (P33)

| 항목 | 누가·어디서 | 방법 | 결과 |
| --- | --- | --- | --- |
| GitHub Actions CI 첫 실행(Linux, PHP 8.2·8.3) — 이 PC에서는 YAML 구문만 확인, 작업 전체가 `continue-on-error: true` | 사용자(공개 저장소) | 비공개 저장소에 올린 뒤 Actions 결과 확인 → 녹색이면 `continue-on-error` 줄과 `tests/js/ci_workflow.test.mjs`의 해당 단언을 함께 고침(`docs/ci_status.md`) | |
| `tools/fetch_bin.php --download`(ruff·git)의 실제 내려받기(공식 주소·리다이렉트·해시 대조·해제) — 인터넷 금지로 미실행, `--verify`·`--plan`만 확인 | 운영(인터넷 되는 PC) | 빈 `bin/ruff/<버전>/windows/`에서 `--download=ruff` → `--verify` | |
| 외부 비밀 스캐너(gitleaks·trufflehog)와 `composer audit`·`npm audit` | 사용자 PC | `docs/publish_checklist.md` 4절 | |
| 공개용 트리에서 `composer install`(인터넷 필요) — 이 PC에서는 개발 저장소의 `vendor/`를 복사해 시험 | 사용자 PC | `dist/oss`에서 `composer install` → `php tests/run.php --exclude-needs-bin` | |
| 그림(`docs/screenshots/`·가이드 그림)에 실제 정보가 없는지 사람이 눈으로 | 사람 | `docs/screenshots/SCREENSHOTS.json`의 장면 설명과 대조 | |

## 3. 사람이 보는 것

| 항목 | 누가·어디서 | 방법 | 결과 |
| --- | --- | --- | --- |
| 스크린 리더(NVDA·내레이터)로 주요 화면 읽기(결함 표·대화상자·탭·알림 패널·배너) | 사람 | Windows 내레이터로 로그인 → 결함 목록 → 상세 → 대화상자 | |
| 내보낸 Excel·CSV를 실제 Excel·LibreOffice로 열기(수식 무력화 작은따옴표·한글·긴 셀) | 사람 | 결함 목록 내보내기 → Excel·LibreOffice로 열기 | |
| 창 있는 Chrome·Edge(123+)·Firefox에서 화면(자동 점검은 헤드리스 Chrome) | 사람 | 주요 화면을 직접 열어 보기 | |
| 자동 점검이 재지 않은 화면 수치(일부 1280/1024/640 넘침 px, 캡처하지 않은 화면) | 사람 또는 개발 PC | 해당 단계 보고의 점검 스크립트를 다시 실행 | |

## 4. 다시 돌리지 않은 점검 스크립트(개발 PC에서 가능)

여러 단계 보고에 "기대값만 고치고 다시 돌리지 않음"으로 남은 브라우저 점검이 있다. 대부분 개발 DB **사본**과 격리 서버를 준비해야 해서 단계마다 일부만 돌렸다. P31에서 스스로 설치본을 띄우는 점검은 다시 돌렸다.

| 점검 | 준비 | P31 결과 |
| --- | --- | --- |
| `check_guide_ui.mjs` | 스스로 설치본 | **통과**(P30B에서 빠뜨린 관리자 목차 기대값 `admin-mail`을 고친 뒤. `--no-shots`일 때 캡처를 남기던 문제도 고침) |
| `check_p31_ui.mjs`(새) | 스스로 설치본 | **통과** |
| `check_admin_mail_ui.mjs` | 스스로 설치본 | P30B에서 통과 |
| `check_monitoring_ui.mjs`, `check_audit_ui.mjs` | 스스로 설치본 | 아래 P31 보고서 C절에 결과 |
| `check_settings_ui.mjs`(탭 수·항목 수), `check_i18n_theme_ui.mjs`(화면 32개), `check_ldap_ui`·`check_oidc_ui`·`check_saml_ui`, `check_backup_ui`, `check_repo_ui`·`check_schedule_ui`·`check_host_test_ui`, `check_account_import_ui` 등 | 개발 DB 사본 + 점검 라우터·격리 워커(각 스크립트 머리말) | 미실행 — P32는 이 스크립트들 대신 같은 영역(계정 CSV 제외)을 **새 설치본의 인수 시험**(`tools/acceptance/run.mjs`)으로 확인. 스크립트 자체는 다음 화면 변경 단계에서 |

## 5. 인터넷이 필요한 것

| 항목 | 누가·어디서 | 방법 |
| --- | --- | --- |
| 알려진 취약점 점검 | 인터넷이 되는 빌드 PC | `php composer.phar audit`, `npm audit`(`tests/tools`, `tools/vendor/*`, `bin/eslint/*`) — 결과는 `docs/third_party.md` 3절에 |
| 도구 새 버전 확인(ruff·ESLint·Git·p4) | 빌드 PC | `docs/tool_setup.md` |
