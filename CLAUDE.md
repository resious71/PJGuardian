# CLAUDE.md

> 공개 저장소 안내: 이 파일은 AI 보조 개발(Claude Code) 규칙이다. 개발 기록인 `prompts/`(단계별 프롬프트), 단계 보고(docs/results), 요구사항 명세 `PJGuardian_requirements.md`는 공개 저장소에 없다. 아래에서 그것들을 가리키는 문장은 개발 저장소 기준이다. 규칙 자체(코딩·보안·시험)는 그대로 적용된다.

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 프로젝트 개요

- PJGuardian은 ruff(Python)와 ESLint(JavaScript/TypeScript) 정적 분석 결과를 여러 사용자가 웹으로 함께 관리하는 애플리케이션이다.
- 요구사항 명세는 `PJGuardian_requirements.md`이며 **이 파일은 수정하지 않는다.** 명세를 인용할 때는 "명세 6장 6.3"처럼 장·절 번호로 말한다.
- **명세 19.10(프로토타입 검증 반영 사항)은 앞 장과 충돌하면 우선한다.** 아래 "프로토타입에서 확정된 규칙"은 19.10을 요약한 것이며, 세부 사항은 19.10을 직접 읽는다.
- 구현은 `prompts/`의 단계별 프롬프트로 진행한다(`prompts/00_사용방법.md`, P07 이후는 순서를 바꾸지 않는다). P01~P06(준비·프로토타입)의 결과는 P32에서 `tools/proto/`와 함께 지웠다(git 기록에 남음, 확정 규칙은 명세 19.10과 아래 요약).
- **아키텍처 규약은 `docs/architecture.md`를 따른다**(디렉토리·네임스페이스, 계층, 라우트 등록, 응답·오류 형식, 설정 키 추가, 로그, 테스트, OpenAPI, 시간·경로, 마이그레이션).

## 기술 스택

- 백엔드 **PHP 8.2 이상**(19.10: `ZipArchive::getStreamIndex()` 사용), SQLite(PDO), 웹 서버는 XAMPP(Apache + PHP). 지원 OS는 Windows와 Linux(macOS 미지원)
- 필수 PHP 확장: pdo_sqlite, zip, mbstring, openssl, fileinfo, **intl**(경로·코드 NFC 정규화). 점검은 `tools/check_env.php`
- ESLint 실행용 Node.js는 `bin/` 밖의 서버 런타임이다. 설정 `tools.node_path`로 지정하고 기동 시 최소 버전(`tools.node_min_version`)을 검사한다(19.10)
- 프론트엔드는 HTML, CSS, 바닐라 JavaScript(ES 모듈), 서버-클라이언트 교환은 JSON
- 외부 라이브러리는 모두 로컬에 포함한다(CDN 금지): Chart.js, CodeMirror 6, fflate, 아이콘 SVG 세트(Dompdf·PhpSpreadsheet는 쓰지 않음 — PDF 내보내기 제외, Excel은 자체 작성, P23). 포함하면 `THIRD_PARTY_NOTICES`에 기재한다. Composer는 P28D(SAML: onelogin/php-saml·xmlseclibs)에서 처음 도입했고 P30B에서 PHPMailer를 더했다 — 빌드 시에만 쓰고 `vendor/`(git 무시)를 배포 묶음에 함께 넣는다(`composer.json`·`composer.lock` 커밋, `docs/vendor_build.md` 3절). 앱 코드는 `src/bootstrap.php`의 자체 PSR-4 오토로더.
- UI 언어는 한국어이며 문자열은 리소스 파일로 분리한다(PHP `src/Lang/ko.php`, JS `public/assets/js/i18n/ko.js`).

## 디렉토리 구조

**웹에 노출되는 폴더는 `public/` 하나뿐이다.** Apache 설정은 `docs/xampp_vhost.md` 참고.

```
PJGuardian_requirements.md   요구사항 명세 (수정 금지)
CLAUDE.md, THIRD_PARTY_NOTICES, LICENSE·NOTICE(Apache-2.0), README.md(한국어)·README.en.md(영어), CONTRIBUTING·SECURITY·CODE_OF_CONDUCT.md, CHANGELOG.md, .github/(CI·템플릿, P33)
public/      웹 루트: index.php(프런트 컨트롤러), .htaccess, assets/ (css, js, vendor, icons)
src/         PHP 소스(PJGuardian\): bootstrap.php, App.php, routes.php,
             Controllers/, Services/, Repositories/, Http/, Support/, Lang/
bin/         ruff/<버전>/<windows|linux>/, eslint/<버전>/, git/, p4/  (sha256 매니페스트 동반)
config/      config.ini.example(모든 기본값, 명세 절 번호 주석), secrets.example
data/        db/, uploads/, work/, snapshots/, backups/, logs/
migrations/  DB 마이그레이션 SQL
tests/       run.php(러너), TestCase.php, Unit/, Integration/, Support/, e2e/, js/
tools/       CLI 스크립트(워커, 스케줄러, 설치, 점검, 배포 묶음), acceptance/(인수 시험, P32)
samples/     샘플 코드와 zip (samples/EXPECTED.md가 기대 결함의 기준)
docs/        architecture.md, openapi.yaml, results/(단계 보고), 운영·설치 문서, legal/(면책·상표·개인정보·AI 보조), screenshots/(README·기능 소개 그림, P33)
resources/   severity/(규칙 기본 심각도 매핑과 규칙 카탈로그, P21S), guide/(가이드 본문 ko·en·그림·screenshots.json, P29). 웹 비노출
backups/     백업 묶음 기본 폴더([backup] dir, P27B. git 무시·웹 비노출, 운영은 다른 디스크로)
prompts/     구현 프롬프트
```

## 업무 흐름 핵심 (여러 장에 걸친 내용)

- **분석 흐름**: zip 청크 업로드(8MB, 이어 올리기) 또는 Git/Perforce 가져오기 → (업로드는 `complete`가 "조립 대기"로 응답하고 워커가 조립) → 안전 해제 → 대상 파일 사전 검사·경고 확인 → DB 큐에 `queued` 등록 → CLI 워커가 FIFO로 처리(전체 동시 2개, 프로젝트당 1개) → 도구 JSON 결과를 공통 형식으로 정규화 → 시그니처로 기존 결함과 매칭 → 자동 resolved/재오픈 → 스냅샷 보존. (명세 5, 6, 18, 19.8, 19.10)
- **자동 resolved**: 분석 성공 시에만. 이번 범위 파일에서 미감지 → resolved, zip에 없는 파일 → resolved(사유 "파일 삭제"), 제외 대상 파일 → 상태 유지(삭제와 구분하려면 **분석마다 제외 규칙과 대상 파일 목록을 저장**한다). 재감지 시 open으로 재오픈. 취소·실패 분석은 결함과 비교 기준(직전 성공 분석)에 영향 없음. (명세 5.4, 6.3, 8, 19.10)
- **문법 오류**는 `syntax-error` 결함으로 기록하고 분석은 성공(ruff는 `code`가 null 또는 `"invalid-syntax"`, ESLint는 `ruleId` null + `fatal`). 도구 크래시·타임아웃·설정 오류만 분석 실패. (명세 6.1)

## 프로토타입에서 확정된 규칙 (명세 19.10 요약)

- **청크 업로드**: 청크는 원시 본문(PUT)으로 받고 청크마다 SHA-256 검증, 경로에는 서버가 만든 `upload_id`만 쓴다. `upload_id`는 사용자와 묶어 다른 사용자에게는 404, 청크 요청은 세션 활동으로 취급, 사용자당 동시 업로드 2개, `init`의 디스크 검사는 진행 중 업로드의 예약 크기를 합산한다. 조립은 요청 안에서 하지 않고 워커가 한다. 브라우저 폴더 묶기는 **STORE만**(Deflate 제공 안 함), 기본 제외 대상은 묶기 전에 뺀다(옵션, 기본 켜짐).
- **zip 해제**: 항목별로 검증하고 `getStreamIndex()`로 읽어 직접 쓰며(`extractTo()` 금지) **실제로 쓴 바이트**로 한도를 검사한다(선언 크기는 위조 가능). 경로 조작·절대 경로·역슬래시는 제외 대상 안이어도 거부, 심볼릭 링크·중첩 zip(`.zip` 확장자)·Windows 이름 규칙(`unsupported_name`)은 제외 대상 밖에서만 검사, 대소문자·NFC 충돌은 `name_conflict`. 파일명은 UTF-8 플래그 → UTF-8, 없으면 CP949(왕복 실패 시 UTF-8, 둘 다 실패면 `filename_encoding`). 기본 제외 목록에 `__MACOSX`, `.DS_Store` 포함. **최상위 단일 폴더 제거는 해제 단계에서 한 번만**, 제거 여부는 프로젝트 첫 업로드에서 정해 저장하고 어긋나면(`top_folder_missing`, `top_folder_kept`) 경고 후 확인받는다. 최종 경로는 259자 이하(작업 디렉토리는 `data/work/<8자리 ID>`).
- **결함 시그니처**: SHA-256(NFC 정규화 경로 + 도구 포함 규칙 키(`ruff:F401`, `eslint:no-unused-vars`, `syntax-error`) + 소스에서 직접 읽어 정규화한 코드 내용) + 같은 파일 내 순번. **파일 단위로 처리**하고 해시만 보관하며 정규화 코드 원문은 저장하지 않는다. 고유 키는 `(project_id, signature CHAR(64), ordinal)`. 줄 번호만으로 식별하지 않는다. 파일 이동·순번 밀림은 한계로 수용.
- **도구 설정 정책**(명세 5.3을 대체):
  - ruff: 분석 루트의 설정(`.ruff.toml` > `ruff.toml` > `pyproject.toml`의 ruff 설정)만 `--config`로 넘기고, 프로젝트 밖·`~`·`$`를 가리키는 `extend`는 거부해 서버 기본 설정으로 분석한다. 하위 폴더 설정은 쓰지 않고 화면에 안내한다. 항상 `--no-respect-gitignore`(업로드된 `.gitignore`는 무시).
  - ESLint: **10.x + 서버가 생성한 flat config**(`-c`). 업로드된 ESLint 설정은 **통째로 무시**하고, 업로드된 JS 설정 파일(`eslint.config.*`, `.eslintrc.*`)은 분석 대상에서 제외한다. `.eslintignore`만 서버 ignore 패턴으로 변환한다.
- **도구 버전**: `bin/`에서 OS별로 semver 최신 + sha256 체크섬 일치(ESLint도 활성화 전 검증) + 스모크 테스트 통과 버전만 사용. 관리자가 차단·고정 가능. 각 분석에 도구 버전·설정 출처·설정 해시 기록. 자원 제한: 시간·출력 한도는 두 OS, ruff 메모리 제한은 Linux `prlimit`만.
- **Windows 파일 처리**: 파일 잠금은 잠금 전용 `.lock` 파일만 잠근다(`Support\FileLock`, Windows `flock`은 강제 잠금이라 잠근 파일을 읽거나 `rename`할 수 없다). 디렉토리 열거는 `scandir`(`Support\Fs::listDir`, `glob('*')`은 점 파일을 빠뜨린다).
- **intl 필수**: 경로·코드 NFC 정규화에 `Normalizer`를 쓴다.
- **정규식**: `/u` 없이 `\v`·`\R`·`\h`·`\s`를 쓰지 않는다(`\v`·`\R`은 한글 안의 0x85 바이트를 줄바꿈·공백으로 보고 글자를 깨뜨림, P04 — P32에서 `\R` 줄 나누기가 secrets.ini 한글 주석을 깨뜨려 키 복원이 실패, `KoreanLineSplitTest`가 src/를 검사). 줄 나누기는 `/\r\n|\n|\r/`. ASCII 공백만 필요하면 `[ \t\x0B\x0C]`처럼 바이트로 쓴다.

## 프로젝트 접근 범위 (명세 3.1, P11B)

- 프로젝트를 볼 수 있는 사람은 admin, 직접 매핑된 사용자, 매핑된 그룹의 구성원이다(매핑이 없으면 admin만). **판정은 `Services\ProjectAccess` 한 곳**에서만 한다.
- 프로젝트 id(또는 업로드·분석·결함·스냅샷 같은 자식 리소스 id)를 받는 라우트는 **`RequireProjectAccess` 미들웨어**를 붙인다. 자식 리소스는 소유 프로젝트를 구해 같은 판정을 한다. 목록·집계 쿼리는 `ProjectAccess::scopeFor`로 거른다. `tests/Integration/ProjectRouteAccessTest`가 등록된 경로를 검사한다(`docs/architecture.md` 19절).
- 보이지 않는 프로젝트는 **없는 것과 같은 404**로 응답한다(403 금지). 접근 검사가 권한 검사보다 먼저다. 각 단계 시험에 "보이지 않는 프로젝트는 404"를 넣는다.

## 외부 인증 (P28B~D, `docs/auth_ldap.md`·`docs/auth_oidc.md`·`docs/auth_saml.md`, architecture 49·50·51절)

- 계정은 인증 방식을 하나 가진다(local·ldap·oidc·saml). 켜진 방식 목록은 `AuthProviderRegistry` 한 곳, 로그인 분기는 `LoginService` 한 곳, 승인 대기·연결·식별자 확인은 `ExternalAccountProvisioner` 한 곳(새 제공자는 호출만). 로컬 계정은 외부 인증으로 로그인되지 않는다.
- 외부 인증 첫 로그인은 **승인 대기 계정(늘 비활성, 세션 없음)** — 관리자 승인 전에는 어떤 권한도 없다. 이름·이메일은 생성 때 한 번만 외부 값, 이후 갱신하지 않는다. 활성 로컬 관리자는 늘 한 명 이상.
- SSO(OIDC, P28C)는 프로토콜 `Oidc\OidcLogin`(PKCE·state는 서버 쪽 파일·한 번만) → `LoginService::attemptExternal` → `ExternalAccountProvisioner::onFederated`((발급자, sub) 먼저, 같은 ID 다른 계정은 거부). JWS 검증은 `Oidc\Jose`(RS256·ES256만, 새 패키지 없음), 외부 요청은 `Oidc\OidcHttp`(https·주소 판정·크기·시간 상한). 앱 비밀 이름은 `AppSecrets::NAMES` 한 곳(027).
- SSO(SAML, P28D)는 **XML 서명 검증을 라이브러리(onelogin/php-saml)로만** — 직접 구현·보안 옵션 완화 금지(`Saml\SamlSettings::library()` 한 곳). SP 시작만(IdP 시작 거부), SLO 없음. ACS(cross-site POST)는 CSRF 미들웨어의 유일한 예외 — 요청 ID·RelayState·한 번 사용으로 검증하고 로그인은 같은 사이트 GET(`/auth/saml/finish`)에서 시작한 세션과 맞을 때만. 세션 쿠키 SameSite=Lax를 바꾸지 않는다.
- 디렉터리·IdP 비밀은 `AppSecrets`(SecretBox)에만, 비밀번호·DN·필터·토큰 원문은 로그·감사·오류·시험 출력에 넣지 않는다. 실제 LDAP/AD·IdP에 연결하는 시험·점검은 하지 않는다(가짜 클라이언트).

## 코딩 규칙

- 모든 PHP 파일은 `declare(strict_types=1);`로 시작하고 네임스페이스는 `PJGuardian\`을 쓴다.
- 모든 DB 쿼리는 PDO Prepared Statement만 사용한다. 문자열 연결로 SQL을 만들지 않는다.
- SQLite는 WAL 모드와 busy_timeout을 설정하고 트랜잭션은 짧게 유지한다. 시간은 UTC(ISO 8601 문자열)로 저장하고 화면에서 KST로 변환한다. 현재 시각은 주입받은 `Clock`으로 얻는다.
- 외부 명령은 `proc_open`에 **인자 배열**로 실행하고 셸을 거치지 않는다(`exec`, `shell_exec`, `system`, `passthru`, 백틱 금지). 실행 파일 경로는 `bin/` 하위만 허용한다(Node.js 런타임은 설정 경로로 예외). 자격 증명은 명령행 인자가 아니라 환경 변수로 전달한다. 타임아웃 시 프로세스 트리 전체를 종료한다.
- 파일 경로는 OS에 독립적으로 다룬다(`DIRECTORY_SEPARATOR` 또는 `/`로 통일 후 변환). Windows 경로 길이 제한(260자)과 대소문자 비구분을 고려한다.
- 사용자 입력(파일명, 청크 번호, upload_id 등)으로 파일 경로를 직접 만들지 않는다.
- 화면에 출력하는 모든 사용자 입력은 이스케이프한다(`Support\Html::e`). 모든 POST/PUT/DELETE에는 CSRF 토큰을 요구한다(AJAX는 요청 헤더).
- API는 `/api/v1/...` REST JSON. 성공 `{"data":...}`, 목록 `{"data":[...],"page":{...}}`, 오류 `{"error":{"code","message","request_id"}}`(`ApiException`). 목록은 page/size(기본 50, 최대 200), 결함 수정은 `version` 필드 낙관적 잠금(충돌 시 409). **라우트를 추가하면 `docs/openapi.yaml`도 고친다**(OpenApiTest가 검사).
- 오류·화면 문구는 코드에 한글로 쓰지 않고 문자열 리소스 키로 참조한다. 오류 응답에 스택 트레이스·내부 경로·예외 메시지를 넣지 않는다.
- **화면 언어 한국어·영어(P27D)**: 새 문구는 `ko`·`en`(PHP `src/Lang/`, JS `public/assets/js/i18n/`)에 **함께** 더한다(키·자리표시자 일치·하드코딩 한글은 `I18nTest`·`tests/js/i18n.test.mjs`가 잡음, 용어 `docs/i18n_glossary.md`, 복수형 `{n} {n:defect|defects}`). **색은 `tokens.css` 토큰만**(하드코딩 금지 — 다크 테마가 토큰을 바꿈), 바꾸면 `node tools/check_theme_contrast.mjs`(라이트·다크 명암비 실패 0).
- **FAQ(P29B)**는 가이드의 탭(`/guide/faq`, `GuideTabs`)이고 본문은 DB `faq_items`(029)에 admin만 화면에서 쓴다(쓰기 API `/api/v1/admin/faq…` = `$admin`, 비공개 항목은 일반 사용자 응답에 존재·건수 없음, 답변은 안전한 마크다운 부분집합). FAQ 내용을 코드·마이그레이션에 넣지 않는다 — 초기 질문은 시드 파일 `resources/faq/seed.ko.json`을 관리자가 `tools/faq_import.php`로 넣는다(X16, 멱등·덮어쓰기 없음, 링크·용어는 `FaqSeedTest`가 검사, 출처 `docs/faq_sources.md`).
- **사용자에게 보이는 기능을 바꾸면 해당 가이드 절(`resources/guide/user/{ko,en}`, P29)을 같이 고친다**(설정 값은 `{{limit:…}}`, 단추 이름은 `{{ui:…}}`, 규칙은 `docs/guide_authoring.md`). 새 화면은 `Support\GuideLinks`에 도움말 절을 더한다.
- 설정값을 코드에 하드코딩하지 않는다(테스트용 한도도 주입). `config/config.ini`에서 읽고(`Support\Config`, 시작 시 전체 검증), 비밀번호·암호화 키는 별도 시크릿 파일 또는 환경 변수에서 읽는다. 키를 추가하면 `ConfigSchema`와 `config.ini.example`에 함께 등록한다.
- 로그는 한 줄 JSON(UTC 시각, 레벨, 요청 ID, 분석 작업 ID, 사용자 ID, 메시지)으로 기록하고(`Support\Logger`) 비밀번호와 토큰은 마스킹한다.
- **새 감사 작업(`AuditLog::record`의 작업 이름)을 만들면 설명도 함께 만든다(X17)**: `Support\AuditActions`, ko·en `audit.desc.<작업>`·`audit.act.<작업>`(필요하면 `audit.fname.*`·`audit.val.*`), `docs/audit_actions.md` 표, `tests/Support/AuditSamples.php` 샘플과 `AuditDescriberTest` 기대 문장. 설명은 보여 줄 때만 만들고(`Services\Audit\AuditDescriber`) 저장 행에 문구를 넣지 않는다.
- **관리자 장애 이메일(P30B)**은 워커의 판정 회차(`Worker::evaluateHealth` → `Services\Mail\AlertMailer::tick`)에서만 보낸다(웹은 시험 메일만). SMTP는 PHPMailer(`vendor/`, LGPL-2.1)로만 — 직접 구현·인증서 검증 끄기 금지, SMTP 비밀번호는 `AppSecrets`(`mail.smtp_password`). 시험은 실제 메일 서버 대신 로컬 가짜 수신기(`tests/Support/FakeSmtp.php`)·`App::useMailSender`로 한다(`docs/monitoring.md` 4절).
- **P31 하드닝 규칙**: 새 라우트는 `tests/Integration/RouteAuthMatrixTest`의 손으로 적은 표(누가 쓸 수 있나)에 함께 적는다(빠지면 실패). HSTS·보안 헤더는 앱 한 곳(`SecurityHeadersMiddleware`) — Apache vhost에 또 넣지 않는다. 감사 로그 최신 해시는 워커가 DB 밖(`logs/audit-anchor.jsonl`)에도 남긴다(`AuditAnchor`). 웹 연결은 SQLite 자동 체크포인트를 끄고(`database.web_wal_autocheckpoint`) 워커가 체크포인트한다. 쓰기 잠금 대기 초과는 503 `db_busy`. 문서를 더하면 `docs/README.md` 목차에(`DocLinksTest`).
- **P32B**: 접속 주소·https 판정은 `Http\ForwardedHeaders::resolve` 한 곳(`App::handle` 맨 앞, 설정 `security.trusted_proxies` — 목록의 프록시를 거친 요청만 X-Forwarded-For·Proto)이며 그 뒤는 `$request->clientIp`·`secure`만 쓴다(`$_SERVER`의 REMOTE_ADDR·전달 헤더를 직접 읽지 않음). 업그레이드 유지보수 모드(`maintenance.enabled`)는 켜기 = 직전 백업 성공(`SettingsService::commitWithMaintenance`), 차단 = `UpgradeMaintenanceMiddleware`, 워커는 새 분석을 시작하지 않음. 규칙 쉬운 설명은 도구 원문과 대조해 쓰고 `tools/rules_guide_check.mjs --write`로 확인한 것만 보인다.
- **P33(오픈소스 공개)**: 프로젝트 라이선스는 Apache-2.0(`LICENSE`·`NOTICE`). 서드파티를 더하거나 버전을 바꾸면 `THIRD_PARTY_NOTICES`·`NOTICE`·`docs/third_party.md`를 함께 고친다(`LicenseNoticeTest`). 공개는 개발 저장소가 아니라 `tools/export_open_source.php`가 만든 공개용 트리(`dist/oss/`, 규칙 `tools/export_open_source.rules.php`)로 하며, 비밀·개인 정보(개발 PC 경로·사용자 이름·실제 이메일·사설 주소)는 코드·문서·시험·그림에 넣지 않는다 — `php tools/scan_release.php --git=.`(허용은 `tools/scan_release.allow.php`에 이유와 함께). 실제 `bin/` 도구(ruff·ESLint·git·p4 실행 파일·Node)를 실행하는 시험은 docblock에 `@needs-bin <이유>`를 붙인다(CI는 `--exclude-needs-bin`, `docs/ci_status.md`). 루트 문서(README·CONTRIBUTING 등)·`docs/legal/`도 링크 검사 대상이다.
- 프론트엔드에서 `innerHTML`로 사용자 입력을 넣지 않는다. 코드 뷰어는 항상 텍스트로 렌더링한다. CSP는 `script-src 'self'`(인라인 스크립트 금지).
- CSV/Excel 내보내기에서 `=`, `+`, `-`, `@`로 시작하는 셀 값 앞에 작은따옴표를 붙인다.

## 작업 방식 규칙

- 한 번에 한 단계(프롬프트)의 범위만 작업한다. 범위 밖 파일을 수정하지 않는다. 각 프롬프트의 "금지 사항"을 지킨다.
- DB 스키마는 `migrations/NNN_이름.sql` 파일로만 변경한다(설계 문서 `docs/db_schema.md`도 함께 고친다). 적용한 파일은 고치지 않고 새 파일을 추가한다. 기존 스키마를 바꿔야 하면 먼저 사용자에게 알린다.
- 시그니처, 자동 resolved, 권한, zip 보안처럼 위험한 로직은 테스트를 먼저 쓴다. 테스트를 통과시키려고 기대값을 바꾸지 않는다. **앞 단계의 테스트(`php tests/run.php`)가 계속 통과해야 한다.**
- 명세가 모호하면 임의로 정하지 말고 질문한다. 명세에 없는 기본값은 "가정"으로 표시해 알린다.
- 결과를 직접 실행해 확인하지 않은 항목은 "통과"가 아니라 "미확인"으로 적는다. 실행한 OS(Windows/Linux)를 함께 기록한다.
- **직접 확인은 격리해서 한다**(P11 점검 사고 이후): 점검(curl, 헤드리스 Chrome, 시험 서버)을 위해 공유 설정 `config/config.ini`(DB 경로, 데이터 폴더, `app.env`, `base_url` 등)를 바꾸지 않는다. 사용자가 같은 시간에 브라우저로 쓰고 있을 수 있다. 점검은 별도 포트(예: PHP 내장 서버 127.0.0.1:8099)·별도 DB·임시 폴더로 같은 앱 코드를 실행하고, 끝나면 서버를 멈추고 점검용 DB·파일을 지운다. 개발 DB(`data/db/pjguardian.sqlite`)와 개발용 `config.ini`는 읽기만 하며, 부득이 바꿔야 하면 먼저 사용자에게 알리고 백업한 뒤 같은 작업 안에서 되돌리고 보고에 적는다. Apache로 확인할 항목은 읽기 전용으로 하고, 쓰기가 필요하면 시험 데이터를 만들었다가 지우는 절차를 보고에 적는다.
- **모든 단계는 끝날 때 `docs/results/<단계번호>.md`(예: `docs/results/P07.md`)에 보고를 남긴다**: 변경 파일 목록, 실행 방법, 직접 확인한 결과(실제 출력), 테스트 결과, 가정한 사항, 이후 단계에 영향을 줄 발견 사항.
- 비밀번호, 토큰, 암호화 키를 코드나 로그, 커밋에 넣지 않는다. `bin/`의 실행 파일은 커밋하지 않는다.
- 커밋 메시지를 제안하고 사용자가 확인한 뒤에 커밋한다.

## 명령

Windows 개발 PC에서는 XAMPP의 PHP(`<XAMPP 폴더>\php\php.exe`)와 Node.js(`node`, 포터블이면 그 폴더의 `node.exe`)를 쓴다. 현재 `php.ini`에서 intl이 꺼져 있으면 `-d extension=intl`을 붙인다.

```
php tests/run.php [필터] [--big] [--bigfiles]             # 제품 테스트(Unit + Integration). 필터 예: Config, RouterTest::testMiddleware. --big: 2GB 업로드, --bigfiles: 항목 100만 개 해제 측정
php tests/run.php --exclude-needs-bin                     # bin/ 도구 없이(CI): @needs-bin 표시 시험은 건너뜀(이름·개수 출력, 옵션 없으면 기존과 같음, P33)
php tools/run_all_tests.php [--skip-e2e]                 # 전체 시험 요약(PHP·JS·아이콘·1단계 E2E)
php tests/e2e/phase1.php [--keep] [--only=1,2]           # 1단계 E2E 12개 시나리오(격리 data/work/e2e_*, 실제 워커·HTTP, 끝나면 지움. 12 = Run)
php tools/worker.php [--once] [--force-stop] [--config=<ini>] # 분석 워커(큐·업로드 후처리, 자식 프로세스로 실행). --stop 정상 종료 요청, --status, --check(죽은 워커 정리). docs/worker_setup.md
php tools/process_upload.php --once|--all [--config=<ini>] # 업로드 후처리를 워커 없이 수동으로(워커가 실행 중이면 종료 코드 3)
php tools/cleanup.php [--dry-run] [--config=<ini>]        # 정리 작업(매일): 죽은 워커 정리, 미완료 조각 24시간, 실패 zip 1일, 확인 대기 만료, 작업 디렉토리·휴지통, cleanup_pending 재시도, 로그 보존
php tools/bench_process.php [--repeat=3]                  # 프로세스 실행기 측정(출력 한도 초과량, 종료 시간)
php tools/tools_ctl.php list|select|verify|pin|unpin|block|unblock|node [도구] [버전]   # bin/ 도구 레지스트리(체크섬·스모크·고정·차단)
php tools/tools_ctl.php install --staging=<id> | remove <도구> <버전>   # 관리자 화면에서 올린 패키지 설치·버전 삭제(bin/ 쓰기 권한 있는 서버 관리자 계정, docs/tool_setup.md 6절)
PJG_PASSWORD=... node tools/check_admin_ui.mjs <주소> tools|tools-after|worker|worker-idle|checks|forbidden   # 관리자 도구·워커 화면 점검(격리 환경 전용)
php tools/seed_defects.php --bench [--severity=off] [--cases=A,B,C,D] [--ckpt=prod] [--spill=N] | --db=<시험 DB> --project=<id> --defects=N [--snapshots-dir=<폴더>]   # 결함 10만 건 측정 / 시험 데이터(+코드 스냅샷) 넣기(개발 DB 금지). --ckpt=prod 운영처럼 자동 체크포인트 끔·경우 사이 체크포인트·반영 연결 캐시(--cache-mb, 기본 128), --spill 한 파일 임시 SQLite 임계(기본 2만, 0 끔), PJG_PROBE_NOAUTO=1 동시 쓰기 측정 연결의 자동 체크포인트 끔(P19C)
php tools/config_check.php [설정 파일]                    # 설정 검증(오류면 원인 출력, 종료 코드 1)
php tools/migrate.php [--status] [--db=<경로>]            # DB 마이그레이션 적용·현황(설계: docs/db_schema.md)
php tools/backfill_runs.php [--db=<경로>|--config=<ini>] [--dry-run] [--project=<id>] [--force]   # Run 되채움(011 뒤 한 번, 멱등, Run별 검증표. docs/results/P20R.md 적용 절차)
php tools/backfill_seen.php [--db=<경로>|--config=<ini>] [--project=<id>] [--batch=20000] [--apply]   # 마지막 감지 되채움(023 뒤 선택, 기본 미리보기, 멱등·이어서, 짧은 쓰기 묶음. docs/architecture.md 44절)
php tools/bench_runs.php [--defects=100000 --runs=50] [--change=1-2] [--synthetic] [--keep --users=100] [--cache-mb=128] | --measure=<DB 사본>   # Run·히스토리 측정(반영 시간·쓰기 대기, 과거 Run·히스토리 조회, 임시 DB. --synthetic은 분석 수백 건을 직접 채움. --cache-mb 반영 연결 캐시(0 = SQLite 기본), 조회는 기본 캐시로 잼)
php tools/severity_coverage.php [--write-catalog] [--markdown=<파일>] [--strict]   # 규칙 기본 심각도 적용 범위(bin/ 도구의 규칙 목록 ↔ resources/severity, 읽기만)
php tools/severity_apply.php [--config=<ini>|--db=<sqlite>] [--project=<id>] [--reapply-auto] [--apply]   # 기존 결함에 규칙 기본 심각도 채우기(기본 미리보기, 멱등, 이력 rule_default. docs/severity_mapping.md)
php tools/rules_load.php [--config=<ini>] [--tool=ruff|eslint] [--version=<버전>] [--all-installed] [--apply]   # 규칙 설명 데이터 적재(bin/ 도구에서 추출, 기본 미리보기, 멱등, 버전 변경 내역·검색 색인. docs/rules.md)
php tools/bench_schema.php [결함 수]                      # 스키마 참고 측정(임시 DB, 실행 계획·시간·크기)
php tools/bench_dashboard.php [--defects=500000] [--db=<DB 사본>]   # 대시보드 구역별 응답 시간(임시 DB 200개 프로젝트, 캐시 끔/켬)
PJG_PASSWORD=... node tools/check_dashboard_ui.mjs <점검 서버> [로그인…]   # 대시보드 브라우저 점검(차트, 표로 보기, 클릭 이동, CSP 위반)
php tools/check_env.php                                   # 환경 점검, FAIL 시 비정상 종료 코드
php tools/create_admin.php --login=<ID> --name=<이름> [--password-stdin] [--resolve-local --insecure] [--config=<ini>]   # 최초 관리자(초기 비밀번호 admin — 개발·운영 모두, 변경 강제 없음, 바꾸기 전까지 하드닝 경고) + 하드닝 점검(docs/hardening.md)
php tools/create_admin.php --check-only [...]             # 하드닝 점검만
php tools/user_cli.php status|unlock|reset-password <ID>  # 계정 복구(감사 로그 기록)
php tools/audit_verify.php [--db=<경로>]                  # 감사 로그 해시 체인 검증(정상 0, 변조 2). 화면은 시스템 관리 옆 감사 로그 > 무결성 검사(P30)
PJG_ADMIN_PASSWORD=... node tools/check_auth_ui.mjs       # 인증 화면 브라우저 점검(puppeteer-core, Apache 필요)
python tools/make_sample_zips.py                          # samples/zips/ 생성
python tools/make_bad_zips.py                             # samples/zips_bad/ 생성(비정상 zip 17종)
node --test "tests/js/*.test.mjs"                         # JS 테스트(폴더 경로만 주면 Node 24에서 실패, 글로브로)
node tools/build_vendor.mjs [codemirror] [chartjs]        # CodeMirror 6·Chart.js 4 번들 빌드(npm·esbuild, 빌드 시에만. docs/vendor_build.md)
PJG_PASSWORD=... node tools/load_basic.mjs --base=<점검 서버[,…]> --users=100 --seconds=60 --projects=<id:결함 수,…> [--scenario=dashboard|runs|history]   # 동시 사용자 p95(P17·P20·P20R·P21, 정식은 P31)
PJG_PASSWORD=... node tools/check_defects_ui.mjs <점검 서버> <프로젝트> <결함> <발췌 결함> <TS 결함>   # 결함 화면 브라우저 점검(CSP 위반, 악성 문자열)
PJG_PASSWORD=... PJG_REANALYZE='[[…명령…]]' node tools/check_defect_edit_ui.mjs <점검 서버> <프로젝트>   # 결함 수정 브라우저 점검(충돌·재시도, 일괄, 비활성 담당자)
PJG_PASSWORD=... PJG_ANALYSES='{"failed":9,…}' node tools/check_analysis_ui.mjs <점검 서버> <프로젝트>   # 분석 상태별 화면 점검(P19)
PJG_PASSWORD=... node tools/check_history_ui.mjs <점검 서버> <프로젝트> <긴 셀 결함> [로그인]   # 히스토리·비교 패널·전체 이력 창·긴 셀 툴팁·대시보드 표 모드·정적 파일 갱신(P21, ko.js를 잠깐 바꿨다 되돌림)
PJG_PASSWORD=... node tools/check_severity_ui.mjs <점검 서버> <프로젝트> <과거 Run> [로그인]   # 규칙 기본 심각도 화면(출처 툴팁·수동 변경·일괄 안내·전체 이력·과거 Run·대시보드, P21S. 012 + severity_apply 적용한 사본)
PJG_PASSWORD=... node tools/check_defect_search_ui.mjs <점검 서버> <프로젝트> [로그인]   # 결함 검색 영역(정한 뒤 검색·요청 수·변경 표시·뒤로 가기)·배치·열 너비(P21V)
PJG_PASSWORD=... node tools/check_defect_table_ui.mjs <점검 서버> <프로젝트> <과거 Run> [로그인] [관리자]   # 결함 표 Run·최근 감지 열·머리글 한 줄(1280·1024)·정렬, 표 머리글, 대시보드 범례·막대 색(P21U)
PJG_PASSWORD=... node tools/check_run_ui.mjs <점검 서버> <프로젝트> <성공 분석 id> [로그인]   # Run 선택기·과거 Run 읽기 전용·없던 결함·분석 제목·추이 링크(P20R)
PJG_PASSWORD=... node tools/check_run_admin_ui.mjs <점검 서버> <프로젝트> <일반 사용자> [관리자]   # Run 관리·상세 삭제(확인 대화상자 키보드)·삭제된 Run 410 안내·결함 표 가로 스크롤 0(P21B, 실제로 지우므로 DB 사본 전용)
PJG_PASSWORD=... node tools/check_p21c_ui.mjs <점검 서버> <IP 주소로 연 같은 서버> <일반 사용자> <Python 프로젝트> [관리자]   # Node 상태 갱신·점검 탭 색·언어 배지·대시보드 콤보박스/언어 필터/표시 개수·업로드 같은 파일·IP 메뉴(P21C, 격리 워커 필요)
PJG_PASSWORD=... node tools/check_rules_ui.mjs <점검 서버> <일반 사용자> <JS 프로젝트> [관리자]   # 규칙 설명(메뉴·목록 검색/필터/쪽·상세·인코딩 주소·활성 버전에 없는 규칙·결함 링크·admin 다시 불러오기, P22. 015 + rules_load 적재한 사본·격리 워커)
PJG_PASSWORD=... node tools/check_rule_override_ui.mjs <점검 서버> <일반 사용자> [관리자]   # 관리자 규칙 켜기·끄기(상세 버튼·확인 창·배지·목록 열/필터·켤 수 없는 규칙·규칙 설정 탭·되돌리기·일반 사용자에게 숨김, P22B. 016 + rules_load 적재한 사본, 실제로 바꾸므로 사본 전용)
PJG_PASSWORD=... node tools/check_rule_bulk_ui.mjs <점검 서버> <일반 사용자> [관리자]   # 규칙 목록 스위치·선택·일괄 변경(미리보기·도구 이름 입력·409 다시 미리보기)·접두사 조건·규칙 설정 빈 상태(P22C. 016 + rules_load 적재한 사본, 실제로 바꾸고 되돌림)
PJG_PASSWORD=... node tools/check_export_ui.mjs <점검 서버> <일반 사용자> <결함 프로젝트(과거 Run 있음)> <상한 초과 프로젝트> [관리자]   # 내보내기(버튼 위치·실제 다운로드·행 수 = 화면 건수·미적용 조건 안내·과거 Run 파일 이름·0건·상한 초과·내 결함·규칙, P23. 상한(50,000) 넘는 합성 프로젝트를 넣은 사본)
PJG_PASSWORD=... node tools/check_rules_tabs.mjs <점검 서버> <일반 사용자> [관리자]   # 규칙 설명 언어 탭(기본 탭·전환·키보드·주소/뒤로 가기/새로 고침·예전 tool= 주소·탭별 독립·상세 링크 탭·목록으로·빠른 전환·1280/1024, X08)
PJG_PASSWORD=... node tools/check_notifications_ui.mjs <점검 서버> <일반 사용자(알림 100건 이상)> <그 사용자의 프로젝트> [관리자] [--poll]   # 앱 안 알림(헤더 배지·패널·키보드·목록 조건/읽음/삭제·설정 탭·관리자 알림 보관 기간(X12부터 설정 관리 알림 탭 구역)·접근 회수·1280/1024, --poll 분당 1회·숨김 정지, P24. 017 적용한 사본, 실제로 바꿈)
php tools/check_bin_vcs.php [--git=2.56.0] [--p4=2026.1.0] [--no-tamper]   # bin/의 git·p4 점검(매니페스트·변조 감지 사본·ELF·Windows 격리 실행, X09. docs/vcs_tools.md)
tools/build_git_linux.sh [--version=…] [--sha256=…] [--gpg]   # 운영 Linux에서 git 공식 소스 빌드 → bin/git/<버전>/linux/ + 매니페스트(X09)
php tools/backup.php [--kind=manual|pre_upgrade] [--no-snapshots] | --list | --verify[=<묶음>] | --prune | --rehearse[=<묶음>] [--config=<ini>]   # 백업(DB·설정 민감 값 제외·스냅샷·bin 체크섬 묶음, 만든 직후 검증, P27B. docs/backup_restore.md)
php tools/restore.php --file=<묶음> [--dry-run] [--check-bin] [--key=<.pjgkey>] [--with-config] [--yes] [--config=<ini>] | --leave-maintenance   # 복원(웹 서버 없이·새 PC, 워커 실행 중이면 거부, 암호문구는 PJG_KEY_PASSPHRASE 또는 프롬프트)
PJG_PASSWORD=... node tools/check_backup_ui.mjs <점검 서버> <일반 사용자> [관리자]   # 백업·복원(요약·지금 백업 진행·키 내보내기·묶음 올리기→검증·복원 대화 흐름·독립 진행 페이지·복원 뒤 세션 무효·일반 사용자 403, P27B. 자동 백업 켜기/끄기·주기 설정·흐리게·요약 문구·보관 추정, P27E. 024 적용한 사본·격리 워커·임시 백업 폴더, 실제로 복원하므로 사본 전용, 격리 설정은 daily_at을 늦춰 둠)
php tools/make_secret_key.php [--out=<secrets.ini>] [--rotate]   # 저장소 자격 증명 암호화 키 생성(키 값은 출력하지 않고 파일에만, --rotate는 이전 키 보관, P25. docs/repo_integration.md)
PJG_PASSWORD=... PJG_REPO_URL=<로컬 Git HTTP 저장소> PJG_REPO_TOKEN=... node tools/check_repo_ui.mjs <점검 서버> <프로젝트> <일반 사용자> [관리자]   # 소스 저장소 연동(허용 호스트·연결 설정·연결 테스트·지금 가져와 분석·커밋 링크·일반 사용자에게 비밀 없음, P25. 018 적용한 사본·격리 워커·로컬 Git 서버)
PJG_PASSWORD=... node tools/check_schedule_ui.mjs <점검 서버> <정상 연결 프로젝트> <연결 없는 프로젝트> <연속 실패 프로젝트> <일반 사용자> [관리자]   # 주기 분석(설정 검증·같은 시각 안내·켤 수 없는 이유·목록/프로젝트 화면 표시·관리자 주기 분석 탭·schedule_failed는 admin만·분석 탭 실행 주체 조건, P26. 019 적용한 사본·격리 워커·로컬 Git 서버)
PJG_PASSWORD=... PJG_HOSTS='{"ok":…,"soon":…,"self":…,"p4":…,"closed":…}' PJG_REPO_URL=<링크가 든 로컬 Git 저장소> node tools/check_host_test_ui.mjs <점검 서버> <링크 프로젝트> <일반 사용자> [관리자]   # 허용 호스트 연결 테스트(행별·전체·단계별 결과·인증서 만료 경고·Perforce 지문·일반 사용자 403)와 저장소 심볼릭 링크 건너뜀 경고(P26B. 020 적용한 사본·tests/Support/probe_server.php 시험 서버·격리 워커, 외부 호스트 등록 금지. PJG_HOSTS에 nopage·gone·proxy·bad(루트 404·410·407·502 평문 HTTP 서버) + PJG_ONLY_4XX=1이면 4xx 판정만, P27C)
PJG_PASSWORD=... node tools/check_unchanged_ui.mjs <점검 서버> <저장소 프로젝트(분석 없음)> <일반 사용자> [관리자] --phase=1|2|3   # 성공(변경 없음)(지금 가져와 분석 → 변경 없음 확인 대화상자 Esc·강제 분석, 이력 배지·접기·숨기기 필터, 상세 기준 Run, 마지막 확인, 추이 제외, P27. 021 적용한 사본·격리 워커·로컬 Git 서버, 1단계 뒤 주기 확인은 시계 주입으로. 3단계 = 실패 뒤 변경 없음에도 대시보드 실패·최근 분석 카드 유지, P27C)
PJG_PASSWORD=... node tools/check_defect_limit_ui.mjs <점검 서버> <경고 프로젝트> <상한 초과 분석 id> <일반 사용자> [관리자]   # 결함 수 경고 한 줄·관리자 알림·상한 초과 실패 화면·최근 감지 열 값과 정렬(P19C. 023 적용한 사본, [limits]를 낮춘 점검 설정·격리 워커)
PJG_PASSWORD=... PJG_PATH_NEW=<아직 없는 프로젝트 밖 폴더> node tools/check_settings_ui.mjs <점검 서버> <일반 사용자> [관리자] --phase=1|2|tabs   # 설정 관리(X12 알림 보관 기간·허용 호스트 구역 통합·구역 변경 표시·검색, 메뉴·카테고리 탭·탭을 가로지르는 검색·형식 오류(저장 시도 = 첫 오류 탭으로)·변경 미리보기·저장·출처 배지·워커 반영 "적용됨"·경로 확인 실패/만들기/통과·기본값으로·409 충돌, 2단계 재시작 필요 배너·변경 이력·일반 사용자 403, P27A. config 사본 폴더·DB 사본, 점검 라우터가 더미 키 test.dummy_dir·test.dummy_worker 등록, 1단계 격리 워커 실행 중. tabs 단계 = P27F 탭 이름·키보드·해시(예전 #group-…)·탭 간 입력 유지·백업 탭·백업 화면 링크, 한/영 × 라이트/다크 × 1280/1024, 관리자 화면 설정을 바꿨다 비움)
PJG_PASSWORD=... node tools/check_account_import_ui.mjs <점검 서버> <일반 사용자> [관리자]   # 계정 CSV 일괄 처리(사용자 관리 링크·양식 내려받기·키보드로 미리보기·판정 표/필터/쪽 나누기·오류 행 제외·확인 문구·결과 임시 비밀번호 한 번/결과 CSV 한 번(수식 문자)·새 계정 첫 로그인 변경 강제·새로 고침 뒤 사라짐·미리보기 뒤 충돌 반영 없음·CP949·1,000행·일반 사용자 403, 한/영 × 라이트/다크 × 1280/1024, P28A. DB 사본 전용 — 실제로 계정을 만듦, 출력·캡처에 비밀번호를 가림)
PJG_PASSWORD=... PJG_DIR_PASSWORD=... node tools/check_ldap_ui.mjs <점검 서버> <일반 사용자> [관리자]   # LDAP/AD·승인 대기(디렉터리 첫 로그인 → 승인 대기 안내·세션 없음, 사용자 관리 승인 대기 배지·이름 확인·승인(역할)/거절, 승인 뒤 로그인·내 정보·비밀번호 안내, 같은 ID 로컬 계정 보호, 인증 방식 변경, 설정 관리 LDAP/AD 탭 검색 계정 비밀번호·연결 시험, 점검 항목, 일반 사용자 403, 한/영·라이트/다크, P28B. 026 적용한 사본 + 가짜 디렉터리를 끼운 점검 라우터 — 실제 서버 연결 없음, 실제로 계정을 바꿈)
PJG_PASSWORD=... PJG_OIDC_SECRET=... PJG_IDP_DIR=<격리 폴더>/idp node tools/check_oidc_ui.mjs <점검 서버> <일반 사용자> [관리자]   # SSO(OIDC)(로그인 화면 SSO 단추·키보드 → 가짜 IdP(브라우저 요청 가로채기) → 첫 로그인 승인 대기·세션 없음, 승인 대기 행 OIDC·승인, 로그인·세션 ID 바뀜·내 정보·비밀번호 안내, 로그아웃 앱 세션만, 재로그인 이름 그대로, 위조 state·IdP 오류 안전한 코드, 같은 ID 로컬 계정 충돌 거부, 설정 관리 SSO(OIDC) 탭 클라이언트 비밀·리다이렉트 URI 복사·연결 시험, 점검 항목, 인증 방식 변경에 OIDC, 제공자 로그아웃(form-action), 일반 사용자 403, 한/영·라이트/다크·1280/1024, P28C. 027 적용한 사본 + 가짜 IdP를 끼운 점검 라우터 — 실제 IdP 연결 없음, 실제로 계정을 바꿈)
PJG_PASSWORD=... PJG_IDP_DIR=<격리 폴더>/idp node tools/check_saml_ui.mjs <점검 서버> <일반 사용자> [관리자]   # SSO(SAML)(로그인 화면 단추 둘·키보드 → 모의 IdP(요청 가로채기 + tests/Support/fake_saml_sign.php가 서명한 응답의 자동 제출 폼 — 실제 cross-site POST·303·Lax 쿠키) → 첫 로그인 승인 대기·세션 없음, 서명 변조 E-SSO-TOKEN, 같은 ID 로컬 계정 충돌, 승인 대기 행 SAML·승인, 로그인·세션 ID 바뀜·돌아갈 곳·내 정보, 로그아웃 앱 세션만, 재로그인 이름 그대로, 설정 관리 SSO(SAML) 탭 SP 정보 복사·메타데이터 가져오기(칸만 채움)·SP 키 생성·인증서 만료일·설정 확인 4단계, 점검 항목, SP 메타데이터, 일반 사용자 403, 한/영·라이트/다크·1280/1024, P28D. 027·028 적용한 사본 + 모의 IdP를 끼운 점검 라우터 — 실제 IdP 연결 없음, 실제로 계정을 바꿈)
node tools/make_guide_screenshots.mjs [--only=slug,…] [--dry-run] [--port=8098]   # 가이드 그림 다시 만들기(resources/guide/screenshots.json 목록, 가짜 데이터 설치본 tools/guide_demo — 실제 DB·사용자 없음, ko·en 1280px, 300KB 이하, 사용자 6장·관리자 8장 이하·그림마다 로그인 계정, P29·P29C. docs/guide_authoring.md)
node tools/check_guide_ui.mjs [--port=8097] [--no-shots] [--keep]   # 가이드 화면(목차·해시·검색·이전/다음·이미지 확대/Esc·오류 코드 탭·자세히 보기·도움말 링크·관리자 가이드 403/admin·라이선스·한 줄 바닥글·범례는 "화면 읽는 법" 절에만·불릿 색 = 글자색, X11 절 단위 화면, X10 시스템 관리 > 설정 관리 탭(X12 규칙 설정 뒤)·바깥 탭 이동 확인·일반 사용자 403, P29C 관리자 화면 도움말 링크(설정 관리는 탭별)·X13 범례 표(같은 폭·열 경계·여백·바탕·선), 한/영·라이트/다크·1280/1024, P29. 가짜 데이터 설치본을 스스로 띄우고 끝나면 지움 — 실제 DB·설정 무관, 캡처 앞부분 PJG_SHOT_PREFIX)
node tools/check_monitoring_ui.mjs [--port=8095] [--keep] [--no-shots]   # 시스템 모니터링(일반 사용자 403·메뉴/배너 없음, 카드 9개·확인 불가, 화면=API=배너=/health 판정 일치, 자동 갱신 포커스·스크롤 유지, 디스크 경고·워커 정지 흉내 → 배너·503, 한/영·라이트/다크·1280/1024, API·/health p95, P30. 가짜 데이터 설치본 + 격리 워커를 스스로 띄우고 지움)
node tools/check_audit_ui.mjs [--port=8096] [--keep] [--no-shots]   # 감사 로그(일반 사용자 403, 목록·조건(조회 단추만)·주소 공유·새로 고침·뒤로 가기·검색 %_ 글자·기간 오류, 상세 마스킹·Esc·포커스, 무결성 검사, CSV·audit.export, 수정·삭제 없음, 한/영·라이트/다크·1280/1024, P30. 가짜 데이터 설치본)
php tools/bench_audit.php [--rows=1000000] [--repeat=20] [--batch=5000]   # 감사 로그 측정(임시 DB: 조건별 목록 p95·무결성 검사·보존 정리 묶음 시간, P30)
node tools/check_admin_mail_ui.mjs [--port=8094] [--keep] [--no-shots]   # 관리자 이메일(일반 사용자 403, 설정 관리 관리자 이메일 탭·발송 상태·평문 경고·SMTP 비밀번호 저장/지우기(값 없음)·저장 전 변경이면 시험 막힘·시험 메일 성공/인증 실패/분당 3회·사용 켜기 → 워커 경고 메일 한 통·간격 안 재발송 없음·모니터링 앱 카드 링크, 한/영·라이트/다크·1280/1024, P30B. 가짜 데이터 설치본 + 격리 워커 + 로컬 가짜 SMTP 수신기를 스스로 띄우고 지움 — 실제 메일 서버 연결 없음)
node tools/check_p31_ui.mjs [--port=8093] [--keep] [--no-shots]   # P31 하드닝 화면(초기 비밀번호 배너·모니터링 경고, 점검 탭 보안 줄·정보/확인 불가, 감사 무결성 + DB 밖 기록, 한/영·라이트/다크. 가짜 데이터 설치본 + 격리 워커)
node tools/loadtest/run.mjs [--users=20,50,100] [--seconds=60] [--big=50000] [--port=8099]   # 부하 시험(P31 B-6: 가짜 데이터 설치본 + 임시 설정의 별도 Apache(사용자 Apache 설정 무관) + 격리 워커, 시나리오 혼합 p50/p95/p99·오류율·SQLite 잠금, 큰 쓰기 동시 단계. docs/performance.md)
php tools/perf/query_bench.php --db=<DB> [--project=<id>] [--plans]   # 결함 조회 측정(사본에서 5회 중앙값 — 목록·요약·깊은 쪽·과거 Run, P31 B-3)
php tools/perf/export_bench.php [--rows=50000]   # 내보내기 시간·메모리·동시 3건·임시 파일 정리(P31 B-4, 임시 DB)
php tools/build_bundle.php [--out=dist] [--zip] [--with-bin] [--force]   # 배포 묶음 만들기(git 파일 + vendor/ + data/ 빈 뼈대 + 도구 스모크 시료 samples/(zip 시료 제외, P32), BUNDLE.json·SHA256SUMS, 만든 직후 verify_bundle. dist/는 git 무시, P31 C-3)
node tools/rules_guide_check.mjs [--tool=ruff|eslint] [--rule=<규칙>] [--write]   # 규칙 쉬운 설명(resources/rules/enrichment의 guide) 검증 — bin/의 실제 도구로 나쁜 예는 걸리고 고친 코드는 안 걸리는지, --write면 checked 기록(없으면 화면에 안 보임, P32B. docs/rules.md 5절)
php tools/check_doc_links.php                              # 문서·가이드 깨진 링크·없는 저장소 경로(P31 C-4, DocLinksTest)
php tools/faq_import.php [--file=resources/faq/seed.ko.json] [--dry-run] [--draft] [--config=<ini>]   # FAQ 시드 가져오기(X16: 같은 분류·질문은 건너뜀·덮어쓰기 없음, 검증 실패면 0건·종료 코드 1, 029 없으면 2, 감사 faq.import 한 줄. 기본 설정은 개발·운영 DB — 시험은 --config 격리 설치본)
node tools/check_faq_ui.mjs [--port=8093] [--keep] [--no-perf]   # FAQ 탭(일반 사용자 편집 요소 DOM 없음·비공개 숨김, admin 추가·수정 대화상자(미리보기·바뀐 내용 확인·Esc·포커스 복귀)·동시 수정 409·순서·공개 전환·삭제 확인, 검색 Enter만·항목 주소·링크 복사·키보드, 1280/1024, 500개 성능, P29B. 가짜 데이터 설치본을 스스로 띄우고 끝나면 지움 — 실제 DB·설정 무관)
node tools/acceptance/run.mjs [--zip=<묶음 zip>] [--only=1,2] [--keep] [--reuse=<설치 폴더>] [--no-shots]   # 인수 시험 16개 시나리오(릴리스 묶음을 data/work/acc_*에 새로 설치 — 가짜 설정·새 DB·별도 포트·별도 워커, 가짜 Git·LDAP·OIDC·SAML·SMTP, 다른 새 폴더에 복원. 결과·캡처 docs/results/P32_acceptance/, 끝나면 지움, P32)
php tools/verify_bundle.php <묶음 폴더>                 # 배포 묶음 점검(vendor/·가이드 본문·그림·FAQ 시드 resources/faq/seed.ko.json(X16)·도구 스모크 시료 samples/(P32) 있음, 비밀·개발 파일·설치마다 다른 설정 config.ini·settings.local.ini* 없음(X14) — 읽기 전용, P29. docs/vendor_build.md 4절)
php tools/export_open_source.php [--out=dist/oss] [--force [--with-untracked]] [--ruff=<버전> --eslint=<버전>]   # 공개용 트리(P33): git 추적 파일만, 개발 기록·명세·비밀·DB 제외, bin/는 매니페스트만·ruff·ESLint 현재 버전 하나, 공개용 사본 바꾸기(CLAUDE.md 경로 등), OSS_MANIFEST.json, 스캐너·링크 검사 자동(docs/publish_checklist.md)
php tools/scan_release.php [폴더] | --git=<저장소> | --stdin [--json]   # 비밀·개인 정보 스캐너(P33: 이메일·절대 경로·이름·키·토큰·사설 IP·내부 호스트·비밀번호 줄, 값은 가림, 규칙 tools/scan_release.rules.php·허용 tools/scan_release.allow.php, 발견 1)
php tools/fetch_bin.php --verify [도구 [버전]] | --plan | --download=ruff|git   # bin/ 매니페스트 대조(오프라인)·받을 것 안내·공식 위치에서 받아 SHA-256 대조(실제 내려받기는 미확인, docs/bin_setup.md)
node tools/make_readme_screenshots.mjs [--port=8091] [--only=dashboard,…] [--keep]   # README·docs/features 그림(P33: 가짜 데이터 설치본·격리 워커, 화면 글자 개인 정보 검사, 장당 300KB·합계 8MB) → php tests/run.php ReadmeAssets
node tools/check_theme_contrast.mjs [--json]              # 테마 명암비(라이트·다크 토큰 쌍 WCAG AA, 다크 두 블록 같은 값, P27D)
PJG_PASSWORD=... node tools/check_i18n_theme_ui.mjs <점검 서버> [관리자] [--shots=화면,…]   # 화면 언어·테마(화면 32개 × ko/en × 라이트/다크 × 1280/1024: 가로 스크롤·영어 화면 틀의 한글·글자 잘림·헤더 겹침·테마 바탕·시스템 따라가기·테마 메뉴 키보드·입력 중 언어 변경 확인, P27D. 025 적용한 사본, 관리자 화면 설정을 바꿨다 되돌림)
php tools/build_icons.php                                 # icons.json → public/assets/icons/sprite.svg (docs/icons.md)
node tools/check_icons.mjs                                # 아이콘 매핑 점검(명세 20.3 항목, 스프라이트, 라벨, 한 아이콘 = 한 뜻)
```

제품 테스트는 `tests/run.php`가 `tests/Unit/`, `tests/Integration/`의 `*Test.php`를 실행한다(작성법은 `docs/architecture.md` 11절). 시험용 파일은 `data/work/`(git 무시)에 만들고 끝나면 지운다.

웹: vhost `https://pjguardian.local`(`public/`만 노출, 프런트 컨트롤러 `public/index.php`, `docs/xampp_vhost.md`). 로컬 실행에는 `config/config.ini`(예시 복사)와 `php tools/migrate.php`로 만든 DB(`data/db/pjguardian.sqlite`, git 무시)가 필요하다. 브라우저 접속에는 hosts에 `127.0.0.1 pjguardian.local`이 필요하다.
