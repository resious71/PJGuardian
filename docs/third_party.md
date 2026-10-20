# 사용하는 외부 구성요소 목록 (P31 A-6)

배포에 들어가는 것과 빌드·시험에만 쓰는 것을 나눠 적는다. 라이선스 원문·저작권 고지는 `THIRD_PARTY_NOTICES`가 기준이고, 이 문서는 **버전·위치·검증 방법**을 한눈에 보는 표다. 버전을 바꾸면 이 표와 `THIRD_PARTY_NOTICES`를 함께 고친다.

## 0. 프로젝트 라이선스와 공개 범위(P33)

PJGuardian은 **Apache-2.0**(`LICENSE`, `NOTICE`)이다. 라이선스 호환 판정과 저장소·배포 묶음 포함 여부는 `THIRD_PARTY_NOTICES` 머리 표와 `docs/results/P33.md` 1절 표가 기준이다. 요약:

- 저장소(공개 저장소 포함)에 파일로 들어가는 서드파티는 브라우저 자산(Chart.js·CodeMirror·fflate·Lucide)뿐이다. PHP 라이브러리는 `composer.lock`만, `bin/` 도구는 체크섬 매니페스트만 있다.
- Perforce `p4`는 독점 소프트웨어라 저장소와 공개 묶음에 넣지 않는다(`build_bundle.php --with-bin` 묶음은 사내 배포 전용). MinGit(GPL-2.0)을 묶음에 넣으면 GPL 소스 입수처를 함께 안내한다(`THIRD_PARTY_NOTICES`).
- 이 표와 `THIRD_PARTY_NOTICES`·`NOTICE`·`composer.lock`·번들 `SOURCE.txt`의 일치는 `tests/Unit/LicenseNoticeTest.php`가 검사한다.

## 1. 배포 묶음에 들어가는 것

| 구성요소 | 버전 | 라이선스 | 위치 | 고정·검증 |
| --- | --- | --- | --- | --- |
| onelogin/php-saml | 4.3.2 | MIT | `vendor/onelogin/php-saml/` | `composer.json` 정확한 버전, `composer.lock` |
| robrichards/xmlseclibs | 3.1.5 | BSD-3-Clause | `vendor/robrichards/xmlseclibs/` | `composer.lock`(php-saml 의존성) |
| PHPMailer (phpmailer/phpmailer) | 7.1.1 | LGPL-2.1-only | `vendor/phpmailer/phpmailer/` | `composer.json` 정확한 버전, `composer.lock`(고치지 않고 그대로 사용) |
| CodeMirror 6 번들(패키지 15개) | @codemirror/view 6.43.13 외 | MIT | `public/assets/vendor/codemirror/codemirror.js` | `SOURCE.txt`의 SHA-256·크기, `tools/vendor/codemirror/package-lock.json` |
| Chart.js 번들 | chart.js 4.5.1, @kurkle/color 0.3.4 | MIT | `public/assets/vendor/chartjs/chart.js` | `SOURCE.txt`의 SHA-256·크기, `tools/vendor/chartjs/package-lock.json` |
| fflate | 0.8.3 | MIT | `public/assets/vendor/fflate/fflate.esm.js` | `SOURCE.txt`의 npm 무결성 값 |
| Lucide 아이콘(lucide-static) | 1.50.0 | ISC | `public/assets/icons/lucide/`(쓰는 것만) → `sprite.svg` | `docs/icons.md`, `tools/check_icons.mjs` |
| ruff | 0.16.10 | MIT | `bin/ruff/<버전>/<OS>/` | `bin/ruff/<버전>/SHA256SUMS`(+ 스모크) |
| ESLint | 8.57.1 · 9.39.5 · 10.11.0 | MIT | `bin/eslint/<버전>/` | `SHA256SUMS`(파일 2,6xx~2,8xx개), `package-lock.json` |
| Git (MinGit, Windows) | 2.56.0.windows.2 | GPL-2.0(+ 구성요소별) | `bin/git/2.56.0/windows/` | `SHA256SUMS` |
| Perforce p4 | 2026.1/3062361 | Perforce 사용 약관(재배포 확인 필요) | `bin/p4/2026.1.0/` | `SHA256SUMS` |

Node.js는 `bin/` 밖의 서버 런타임(설정 `tools.node_path`, 최소 버전 검사)이라 이 표에 넣지 않는다. PHP·Apache·SQLite도 서버 구성요소다.

## 2. 빌드·시험에만 쓰는 것(배포에 넣지 않음)

| 구성요소 | 버전 | 쓰는 곳 |
| --- | --- | --- |
| Composer | 개발 PC `C:\tools\composer\composer.phar` | `vendor/` 만들기(`docs/vendor_build.md` 3절) |
| esbuild | 0.28.2 | `tools/build_vendor.mjs`(CodeMirror·Chart.js 번들) |
| yaml | 2.9.1 | `tests/tools`(OpenAPI 엄격 파싱 시험) |
| puppeteer-core | `data/work/puppeteer`(git 무시) | 브라우저 점검 스크립트 `tools/check_*_ui.mjs` |

## 3. 점검 방법(P31 결과 — Windows, 2026-10-14)

| 점검 | 명령(오프라인) | 결과 |
| --- | --- | --- |
| Composer 설정·잠금 일관성 | `php composer.phar validate --no-check-publish` | 통과(경고: 정확한 버전 고정 — 의도한 것) |
| 설치된 vendor = 잠금 파일 | `vendor/composer/installed.json` ↔ `composer.lock` 버전 대조 | 3개 모두 일치 |
| 번들 JS 무결성 | `sha256sum public/assets/vendor/*/…` ↔ `SOURCE.txt` | CodeMirror·Chart.js 일치(fflate는 npm 무결성 값으로 받음) |
| bin/ 매니페스트 | `ToolManifest::verify`(읽기만 — `tools_ctl.php verify`는 상태를 기록하므로 대신) | ruff·ESLint 3개·Git·p4 6개 모두 일치(파일 8,582개) |
| 알려진 취약점 | `php composer.phar audit` · `npm audit` | **미확인**(인터넷 접속 금지). 운영 반영 전 인터넷이 되는 빌드 PC에서 실행 — `docs/unverified.md` |
