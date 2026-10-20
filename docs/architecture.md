# PJGuardian 아키텍처 규약

P07 이후 모든 단계는 이 문서의 규약을 따른다. 규약을 바꿀 때는 이 문서를 먼저 고치고, 이유를 해당 단계의 `docs/results/P번호.md`에 적는다. 명세 19.10의 구현 규칙과 충돌하면 명세가 우선한다.

## 1. 디렉토리와 네임스페이스

| 위치 | 내용 | 네임스페이스 |
| --- | --- | --- |
| `public/` | 웹 루트. `index.php`(프런트 컨트롤러), `.htaccess`, `assets/`(css, js, vendor, icons) | - |
| `src/bootstrap.php` | 오토로더, PHP 기본 설정 | - |
| `src/App.php` | 앱 조립과 요청 처리(전역 미들웨어) | `PJGuardian` |
| `src/routes.php` | 라우트 등록 | - |
| `src/Controllers/` | HTTP 입출력만 다루는 컨트롤러 | `PJGuardian\Controllers` |
| `src/Services/` | 업무 규칙(트랜잭션 경계 포함) | `PJGuardian\Services` |
| `src/Repositories/` | DB 접근(Prepared Statement만) | `PJGuardian\Repositories` |
| `src/Http/` | Request, Response, Router, ApiException, Pagination, 미들웨어 | `PJGuardian\Http` |
| `src/Support/` | Config, Logger, Clock, Paths, Fs, FileLock, Lang, Html 등 공통 유틸 | `PJGuardian\Support` |
| `src/Lang/ko.php`, `src/Lang/en.php` | PHP 문자열 리소스(한국어·영어, P27D) | - |
| `public/assets/js/i18n/ko.js`, `en.js` | JS 문자열 리소스(한국어·영어, P27D) | - |
| `tests/Unit/`, `tests/Integration/` | 테스트(`*Test.php`) | `PJGuardian\Tests\...` |
| `tools/` | CLI 스크립트(워커, 스케줄러, 설치, 점검) | 파일마다 다름 |
| `tools/acceptance/` | 인수 시험(P32, 릴리스 묶음을 새 폴더에 설치해 시나리오 실행) | - |

- 오토로더는 PSR-4다. `PJGuardian\X\Y` → `src/X/Y.php`, `PJGuardian\Tests\X` → `tests/X.php`. 파일 하나에 클래스 하나.
- 모든 PHP 파일은 `declare(strict_types=1);`로 시작한다.
- Composer는 P28D(SAML — onelogin/php-saml·xmlseclibs)에서 처음 도입했다(P23은 PHP 의존성 없이 끝남). `composer.json`·`composer.lock` 커밋, `vendor/`는 git 무시·배포 묶음에 포함(`docs/vendor_build.md` 3절), 부트스트랩이 `vendor/autoload.php`를 함께 불러온다. 라이브러리가 없으면 그 기능만 꺼진 것처럼(예: `SamlSettings::libraryAvailable()`).
- 프로토타입(`tools/proto/`·`tests/proto/`, P02~P06)은 P32에서 지웠다(git 기록에 남음). 확정 규칙은 명세 19.10.

## 2. 계층 구조

```
HTTP 요청 → public/index.php → App::handle
  → 전역 미들웨어: RequestId → SecurityHeaders → Error → Https → Session → Csrf (16절)
  → Router → 라우트 미들웨어(로그인·임시 비밀번호·관리자 권한, 16절)
  → Controller → Service → Repository → SQLite
```

- **Controller**: 입력 꺼내기·검증 호출·응답 만들기만 한다. 업무 규칙과 SQL을 두지 않는다.
- **Service**: 업무 규칙, 권한 판단에 필요한 조회, 트랜잭션 경계. 다른 Service를 불러도 된다.
- **Repository**: 테이블 단위 CRUD. PDO Prepared Statement만 쓴다. 문자열 연결로 SQL을 만들지 않는다.
- 의존성은 생성자로 주입한다(`App`에서 연결). 전역 상태·정적 싱글턴을 새로 만들지 않는다(Lang의 메시지 캐시는 예외).
- 워커·CLI도 같은 Service를 쓴다. 웹 전용 코드(Request 등)에 Service가 의존하지 않는다.

## 3. 라우트 등록

`src/routes.php`에서 등록한다.

```php
$router->get('/api/v1/projects', $projects->list(...));
$router->get('/api/v1/projects/{id:\d+}', $projects->show(...), [$auth]);
$router->post('/api/v1/projects', $projects->create(...), [$auth, $admin, $csrf]);
```

- API는 `/api/v1/...`, 그 밖은 HTML 페이지다. 같은 경로·메서드를 두 번 등록하면 예외가 난다.
- 경로 파라미터는 `{이름}` 또는 `{이름:정규식}`. 컨트롤러에서 `$request->param('id')`로 읽는다(문자열이므로 정수로 변환·검증).
- 일치하는 경로가 없으면 404, 경로는 있으나 메서드가 다르면 405(`Allow` 헤더). HEAD는 GET 라우트로 처리한다.
- **라우트를 추가하면 `docs/openapi.yaml`의 `paths`에 같은 경로·메서드를 넣는다.** `tests/Integration/OpenApiTest.php`가 양쪽 일치를 검사한다. 경로 파라미터 표기는 OpenAPI와 같게 `{id}`로 쓴다(제약은 라우터에만).

## 4. 응답과 오류 형식 (명세 19.7)

| 경우 | 형식 | 만드는 방법 |
| --- | --- | --- |
| 성공 | `{"data": ...}` | `Response::data($data, 200)` |
| 목록 | `{"data": [...], "page": {"page": 1, "size": 50, "total": 123}}` | `Response::page($items, $pagination, $total)` |
| 오류 | `{"error": {"code": "snake_case", "message": "한국어", "request_id": "..."}}` | `throw new ApiException(상태, '코드', [자리표시자])` |

- 오류 메시지는 `src/Lang/ko.php`의 `error.<코드>` 키로 찾는다(다른 키는 `messageKey`로 지정). **코드에 한글 문장을 직접 쓰지 않는다.** `LangTest`가 참조된 키의 누락을 검사한다.
- 로그에만 남길 정보는 `ApiException`의 `logContext`에 넣는다(응답에는 나가지 않음).
- 다시 시도에 필요한 공개 값은 `ApiException`의 `details`에 넣으면 응답 `error.details`로 나간다(P18: 409 `version_conflict`의 `{"current": 최신 결함}`). JS는 `ApiError.details`. 내부 경로·예외 메시지는 넣지 않는다.
- 예상하지 못한 예외는 `ErrorMiddleware`가 500 `internal`과 일반 메시지로 바꾸고, 예외 종류·메시지·스택은 로그에만 남긴다. **응답에 스택 트레이스, 파일 경로, 예외 메시지를 넣지 않는다.**
- API가 아닌 경로의 오류는 같은 정보를 HTML 오류 페이지로 낸다.
- 낙관적 잠금 충돌은 409(명세 6.2, 19.7). 권한 없음은 403, 로그인 필요는 401, 존재를 숨겨야 하면 404(예: 다른 사용자의 업로드, 명세 19.10).
- 목록 API는 `Pagination::fromRequest($req, 기본 크기, 최대 크기)`로 `page`/`size`를 검증한다. 기본값은 설정 `ui.defect_page_size`(50), 최대는 `ui.page_size_max`(200). 틀리면 400 `invalid_pagination`.

## 5. HTTP 공통 처리

- **요청 ID**: 요청마다 16자리 16진수 ID를 만들어 로거 문맥과 응답 헤더 `X-Request-Id`에 넣는다. 클라이언트가 보낸 값은 쓰지 않는다.
- **HTTPS 강제**(명세 10): `security.require_https = true`(기본)이면 HTTP GET/HEAD는 `app.base_url`의 HTTPS 주소로 301, 그 밖의 메서드는 403 `https_required`. Host 헤더로 리다이렉트 주소를 만들지 않는다. 개발용으로만 false.
- **보안 헤더**(모든 응답): CSP(`script-src 'self'`, `style-src 'self' 'nonce-<요청마다 새 값>'`, 인라인 스크립트·스타일 금지. nonce는 코드 뷰어 CodeMirror의 `<style>`에만 쓴다, P17·25절), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, HTTPS 응답에 HSTS(`security.hsts_max_age`). CSP 예외가 필요하면 `SecurityHeadersMiddleware`에서만 이유와 함께 추가한다.
- 화면 출력은 `Html::e()`로 이스케이프한다. JS에서 사용자 입력을 `innerHTML`로 넣지 않는다.

## 6. 설정

- 설정 파일은 `config/config.ini`(git 무시), 예시는 `config/config.ini.example`. 시크릿은 `config/secrets.ini` 또는 환경 변수 `PJG_<키 대문자>`(환경 변수 우선).
- 시작할 때 `Support\Config`가 모든 키를 검사한다. 모르는 키·없는 키·형식 오류·범위 오류가 하나라도 있으면 `ConfigException`으로 **기동을 거부**한다. 웹은 503 `config_invalid`(원인은 서버 로그), CLI는 `php tools/config_check.php`로 원인을 본다.
- 값 형식: 크기 `8MB`(→ 바이트 정수), 기간 `30m`(→ 초 정수), 목록은 쉼표 구분, 참/거짓 `true`/`false`.
- 코드에서 읽기: `$config->int('upload.chunk_size')`, `$config->get('extract.default_excludes')`, `$config->secret('encryption_key')`. **설정값을 코드에 하드코딩하지 않는다.** 테스트용 한도도 설정으로 주입한다.

### 설정 키 추가

1. `src/Support/ConfigSchema.php`의 `definition()`에 키와 형식·범위를 등록한다(여러 키 사이의 조건은 `crossCheck()`).
2. `config/config.ini.example`에 명세 절 번호 주석과 함께 기본값을 넣는다. 명세에 없는 값은 `[가정]`이라고 적는다.
3. **이미 설치된 곳의 `config.ini`에 없을 키는 스키마에 `'default' => '값'`을 둔다**(P13부터). 키가 없으면 기본값을 쓰고, 값을 주면 검증한다. default가 없는 키는 빠지면 기동이 거부되므로 개발 PC의 `config/config.ini`에도 넣어야 한다(점검 규칙상 공유 설정은 직접 바꾸지 않으므로 사용자에게 알린다).
4. `tests/Unit/ConfigTest.php`에 형식·범위 시험이 필요하면 추가한다.

## 7. 로그

- `Support\Logger`: 한 줄 JSON(`ts`(UTC), `level`, `request_id`, `job_id`, `user_id`, `message`, `context`), 파일은 `<logs_dir>/app-YYYYMMDD.log`.
- 레벨: `ERROR`(처리 실패), `WARN`(거부·이상 징후), `INFO`(주요 동작). 최소 레벨은 설정 `log.level`.
- `$logger->info('메시지', ['키' => 값])`. 메시지는 짧게, 값은 `context`에 넣는다.
- 웹은 요청 ID가 자동으로 붙는다. 워커는 작업을 처리하는 동안 `withJobId($id)`, 로그인 후에는 `withUserId($id)`를 설정한다.
- 비밀번호·토큰·시크릿·Authorization·쿠키는 키 이름과 메시지 문자열 양쪽에서 마스킹된다. 그래도 **민감 값을 일부러 로그에 넣지 않는다.**
- 보존 기간 삭제는 `purgeOlderThan(log.error_access_retention)`(정리 작업 `CleanupService::purgeLogs`, P14).
- 워커 자식은 `job_id`로 분석 id(또는 `upload:<upload_id>`)를 쓴다. 부모 워커는 여러 작업을 동시에 감독하므로 `job_id` 대신 `context`에 `analysis_id`·`upload_id`를 넣는다.

## 8. 시간

- 내부와 DB는 항상 UTC. 현재 시각은 `Clock`을 주입받아 `$clock->now()`로 얻는다(`time()`·`new DateTime()`을 직접 쓰지 않는다. 테스트는 `FixedClock`).
- DB에는 `SystemClock::iso($t)` 형식(`2026-10-02T13:45:00Z`)의 TEXT로 저장한다. 문자열 비교로 정렬된다.
- 표시 시간대(`app.display_timezone`, 기본 Asia/Seoul)로의 변환은 화면 계층(템플릿·JS)에서만 한다.

## 9. 경로와 파일 (명세 19.10)

- `data/` 하위 경로는 `Support\Paths`로 얻는다(설정 `[paths]`, 상대 경로는 프로젝트 루트 기준).
- 작업 디렉토리는 `$paths->newWorkDir()`(`data/work/<8자리 16진수>`)로 만든다. zip 안 경로까지 더한 최종 경로가 259자를 넘지 않게 짧게 둔다.
- **사용자 입력(파일명, 번호, ID)으로 경로를 만들지 않는다.** 서버가 만든 ID나 검증한 정수만 쓴다.
- 디렉토리 열거는 `Fs::listDir()`(scandir). `glob('*')`은 점 파일을 빠뜨리므로 쓰지 않는다.
- 재귀 삭제는 `Fs::removeTree()`, 원자적 교체는 `Fs::atomicWrite()`(같은 디렉토리 임시 파일 + rename).
- 프로세스 간 잠금은 `FileLock::acquire($dir . '/.lock')`. **데이터 파일을 직접 잠그지 않는다**(Windows flock은 강제 잠금이라 잠근 파일을 읽거나 rename할 수 없다). `.lock`이 아닌 이름은 예외가 난다.

## 10. 문자열 리소스와 화면 문구

- PHP: `Lang::get('키', ['이름' => 값])`, 자리표시자 `{이름}`. JS: `import { t } from '../core/i18n.js'`(언어는 `<html lang>`, 영어면 `en.js`를 읽고 없는 키는 한국어).
- 키 규칙: `<영역>.<이름>`. 오류는 `error.<오류 코드>`, 페이지 제목은 `page.<이름>`.
- 같은 문구가 PHP와 JS 양쪽에 필요하면 두 파일에 같은 키로 둔다.
- **P27D: 새 문구는 `ko`·`en`을 함께 더한다**(키 집합·자리표시자 이름이 같아야 함 — `tests/Unit/I18nTest`, `tests/js/i18n.test.mjs`). 수와 결합하는 문구는 영어에 복수형 `{n} {n:defect|defects}`. 용어는 `docs/i18n_glossary.md`. 뷰·컨트롤러·화면 스크립트에 한글 문구를 직접 쓰지 않는다(하드코딩 점검 시험, 허용 목록은 개발자 오류만).

## 11. 테스트

- 실행: `php tests/run.php [필터]`(필터는 `클래스::메서드` 부분 문자열). 실패가 있으면 종료 코드 1.
- 위치: 순수 로직은 `tests/Unit/`, 여러 부품을 함께 쓰는 시험(앱 전체 요청 처리, DB)은 `tests/Integration/`.
- 작성: `PJGuardian\Tests\TestCase`를 상속하고 `test`로 시작하는 public 메서드를 만든다. 단언은 `assertSame`, `assertTrue`, `assertContains`, `assertThrows` 등. 임시 파일은 `$this->tempDir()`(테스트 후 자동 삭제).
- 앱 수준 시험은 `Tests\Support\TestApp::make($로그디렉토리, [설정 덮어쓰기])`와 `TestApp::request(...)`를 쓴다.
- 위험한 로직(시그니처, 자동 resolved, 권한, zip 보안)은 **테스트를 먼저 쓴다.** 테스트를 통과시키려고 기대값을 바꾸지 않는다.
- 앞 단계의 테스트가 계속 통과해야 한다.

## 12. OpenAPI

- `docs/openapi.yaml`이 API 명세다(명세 19.7). 라우트를 추가·변경·삭제하면 같은 커밋에서 문서를 고친다.
- `paths` 아래는 2칸 들여쓴 `/경로:`, 4칸 들여쓴 메서드(`get:` 등) 형식을 지킨다(OpenApiTest가 이 형식으로 읽는다).
- 공통 스키마(`Error`, `PageInfo`)와 파라미터(`Page`, `Size`)는 `components`에 있다.
- `info.version`은 `App::VERSION`과 같게 둔다(OpenApiTest가 검사).

## 13. DB와 마이그레이션 (설계: `docs/db_schema.md`)

- 접속: `Support\Db::fromConfig($config, $paths)`. WAL, `synchronous=NORMAL`, `foreign_keys=ON`, `busy_timeout`(설정 `database.busy_timeout`)을 접속할 때 켠다.
- 쿼리: `$db->all()`, `one()`, `value()`, `execute()`(영향 행 수 반환). 모두 Prepared Statement다. 문자열 연결로 SQL을 만들지 않는다(동적 컬럼 목록이 필요하면 허용 목록에서 고른 식별자만).
- 트랜잭션: `$db->transaction(fn (Db $db) => ..., write: true)`. 쓰기는 `BEGIN IMMEDIATE`, 중첩은 SAVEPOINT. 짧게 유지하고, 트랜잭션 안에서 외부 명령·파일 대량 처리를 하지 않는다.
- 낙관적 잠금: `UPDATE ... SET ..., version = version + 1 WHERE id = ? AND version = ?`의 영향 행이 0이면 409(`version_conflict`).
- 스키마는 `migrations/NNN_이름.sql`(3자리 순번)로만 바꾼다. 적용: `php tools/migrate.php`, 현황: `--status`. 한 번 적용한 파일은 고치지 않고 새 파일을 추가한다(실행기가 SHA-256으로 변경을 탐지해 거부). 파일 안에 PRAGMA·BEGIN·COMMIT을 쓰지 않는다.
- 기존 스키마를 바꿔야 하면 먼저 사용자에게 알린다.
- 시각은 UTC ISO 8601 TEXT(8절, 형식 CHECK가 있다). 불리언은 0/1, 열거형은 CHECK.
- 감사 로그는 `Support\AuditChain::append()`로만 쓴다(해시 체인). 직접 INSERT하지 않는다.
- 테스트는 `Tests\Support\TestDb::migrated()`(마이그레이션을 적용한 메모리 DB)와 데이터 도우미(`user`, `project`, `analysis`, `defect`)를 쓴다. 파일 DB 동작(WAL 등)이 필요한 시험만 파일을 쓴다(Windows에서 새 DB 파일 생성이 약 1초 걸림).

## 14. 단계 보고

- 모든 단계는 끝날 때 `docs/results/P번호.md`에 보고를 남긴다: 변경 파일, 실행 방법, 직접 확인한 결과(실제 출력), 테스트 결과, 가정한 사항, 이후 단계에 영향을 줄 발견 사항.
- 확인하지 못한 항목은 "미확인"으로 적고 실행한 OS를 함께 적는다.
- 커밋 메시지는 제안만 하고 사용자의 확인을 받은 뒤 커밋한다.

## 15. 화면(템플릿)과 프런트엔드 (P09)

- **템플릿**: `src/Views/*.php`(순수 PHP 템플릿). 컨트롤러는 `$app->view($request)->page('템플릿', $page, $vars)`로 그린다. `$page['user']`가 있으면 `layout`(메뉴 포함), 없으면 `layout_guest`. 템플릿 안에서 쓰는 도구: `$e`(이스케이프), `$t`(문자열 리소스), `$icons`, `$assets`, `$view->partial()`.
- 공통 부분 템플릿: `partials/head`(meta: csrf-token, app-timezone, app-base, app-env, icon-sprite), `partials/badge`(심각도·상태 배지), `partials/empty_state`, `partials/icon_legend`(아이콘 범례, 가이드 16.1에서 재사용).
- **관리자 메뉴는 `role=admin`일 때만 서버에서 출력한다**(CSS로 숨기지 않는다). 메뉴 정의는 `Support\Navigation`. 화면이 아직 없는 메뉴는 `href=null` → 비활성 표시, 링크 없음.
- **아이콘**: 의미 이름 → 아이콘 매핑은 `public/assets/icons/icons.json` 하나. PHP `Support\Icons`, JS `ui/icons.js`만 SVG를 만든다. 템플릿·화면 JS에 SVG를 직접 쓰지 않는다. 자세한 내용은 `docs/icons.md`.
- **자산 주소**: `Support\Assets::url('css/base.css')` → `<배포 경로>/assets/css/base.css?v=<내용 해시 12자>`. 앱 안 페이지 주소는 `Assets::page()`. 배포 경로 접두사는 `Request::basePathFromScript()`로 구한다(vhost는 '').
- **CSP**: `script-src 'self'`, `style-src 'self' 'nonce-…'`(P17). 인라인 `<script>`, `<style>`, `style="..."` 속성, `element.style` 조작을 쓰지 않는다. nonce(`<meta name="csp-nonce">`)는 서드파티가 넣는 `<style>`(CodeMirror)에만 넘기고 앱 코드는 쓰지 않는다. 동적 표시는 클래스와 속성(`hidden`, `aria-*`, `data-*`)으로 한다.
- **JS 구조**(ES 모듈, 빌드 없음): `public/assets/js/core/`(api, i18n, dom, time, poll — DOM 없이 테스트 가능한 기반), `ui/`(icons, toast, dialog, tabs, pagination, mono), `pages/<화면>.js`(화면별 동작, `$page['scripts']`로 불러옴), `app.js`(모든 화면 공통 초기화).
  - DOM은 `core/dom.js`의 `el()`/`setText()`로 만든다. `innerHTML` 등 HTML 해석 API를 쓰지 않는다.
  - API 호출은 `core/api.js`의 `createApi()`(CSRF 헤더, `ApiError`, 401 → 로그인, 409 훅).
  - 시각: 서버는 `<time datetime="UTC ISO">`에 표시 시간대로 그린 텍스트를 넣고, `localizeTimes()`가 다시 맞춘다.
- **스타일**: `css/tokens.css`(토큰, 시스템 글꼴), `css/base.css`(레이아웃, 최소 너비 1280px, `body.mono` 흑백 점검), `css/components.css`(컴포넌트). 반응형은 만들지 않는다. 심각도·상태는 아이콘 + 텍스트 + 색을 함께 쓴다.
- **스타일 가이드**: `/dev/styleguide`(`app.env=dev`에서만, 운영은 404). `?as=admin|user|guest`로 레이아웃 미리보기. 공통 컴포넌트를 추가하면 여기에도 시연을 넣는다.
- **JS 테스트**: `node --test "tests/js/*.test.mjs"`(가짜 DOM `tests/js/fake-dom.mjs`). 폴더 경로만 주면 Node 24에서 실패하므로 글로브로 준다.

## 16. 인증과 권한 (P10)

- **전역 미들웨어**: `… → Https → Session → Csrf → Router`.
  - `SessionMiddleware`: 쿠키 `pjg_session`으로 세션과 사용자를 찾아 요청 속성 `session_ctx`(SessionContext), `user`(공개 사용자 정보 또는 null), `csrf_token`을 붙인다. 쿠키가 없으면 DB를 열지 않는다. 사용자를 매 요청 DB에서 다시 읽어 비활성 계정의 세션은 그 자리에서 지운다.
  - `CsrfMiddleware`: GET·HEAD·OPTIONS 외의 모든 요청에서 `X-CSRF-Token` 헤더(AJAX) 또는 폼 숨은 필드 `_csrf`를 세션 토큰과 `hash_equals`로 비교한다. **예외 경로가 없다**(로그인 포함). 라우팅보다 먼저 검사하므로 없는 경로도 토큰이 없으면 403이다.
- **라우트 미들웨어**(`src/routes.php`): `$login`(RequireLogin), `$auth`(+ RequirePasswordChanged), `$admin`(+ RequireAdmin). 새 기능은 기본으로 `$auth`, 관리자 기능은 `$admin`을 붙인다. 화면에서 메뉴를 숨겨도 서버 검사는 항상 한다.
  - RequireLogin: API 401(`unauthenticated`, 무활동 만료면 `session_expired`), 화면은 `/login?next=<경로>`로.
  - RequirePasswordChanged: 임시 비밀번호 상태면 API 403(`password_change_required`), 화면은 `/password`로. 허용 경로(이 미들웨어를 붙이지 않음): `/password`, `/api/v1/auth/password`, `/api/v1/auth/me`, 로그아웃.
  - RequireAdmin: API 403(`forbidden`), 화면 403 오류 페이지.
- **현재 사용자**: 컨트롤러는 `$request->attribute('user')`(배열: id, login_id, display_name, role, status, must_change_password …)를 쓴다. 감사 로그 행위자는 `Actor::fromUser($user, $request->clientIp)`, CLI는 `Actor::cli()`.
- **세션 조작**: `SessionContext::ensure()`(로그인 전 세션), `login($user)`(세션 ID·CSRF 재발급), `logout()`, `setFlash()`·`takeFlash()`(다음 화면에 한 번 보일 알림, `sessions.flash`. `App::view()`가 꺼내 배너로 그림). Set-Cookie는 SessionMiddleware가 붙인다. 청크 업로드(P12)의 활동 갱신은 `SessionService::touch()`를 쓴다.
- **홈 경로**: `Support\Navigation::HOME` 한 곳(P10 `/home`). 로그인 후 `next`는 `Navigation::safeNext()`를 통과한 앱 안 경로만 허용한다(열린 리다이렉트 방지).
- **인증 제공자**: `Services\Auth\AuthProvider`(로컬: `LocalAuthProvider`). LDAP(P28)는 같은 인터페이스로 추가하고 `appliesLockout()`을 false로 둔다.
- **비밀번호 정책의 유일한 예외**(X02): 최초 관리자의 고정 초기 비밀번호는 `UserService::createInitialAdmin()` 한 곳에서만 정책을 건너뛴다. 다른 경로에 예외를 넓히지 않는다. 상태는 `users.password_kind`(`user`·`temporary`·`initial`). 최초 관리자는 첫 로그인 때 변경을 강제하지 않는다(`must_change_password = 0`, 2026-10-13 사용자 결정) — `initial`인 동안 하드닝 점검이 경고만 한다.
- **비밀번호 정책**(명세 3장): 8자 이상, 대문자·소문자·숫자·특수문자 중 2종 이상(설정 `auth.password_min_length`, `auth.password_min_char_classes`, 2026-10-03 변경). 정책 검사는 `PasswordPolicy` 하나로만 한다.
- **관리자의 비밀번호 직접 변경**(명세 13장): `UserService::setPasswordByAdmin()`, `PUT /api/v1/users/{id}/password`. 정책 적용, 변경 강제 없음, 대상 세션 폐기. `users.admin_password_set_at`에 시각을 남기고, 다음 로그인 성공 때 `LoginService`가 알림(`password_set_by_admin`)을 꺼내 화면은 세션 flash → 배너, API는 로그인 응답 `notice`로 한 번 보여 준다. 본인·LDAP 계정은 거부.
- **비밀값**: 비밀번호·임시 비밀번호·세션 ID·CSRF 토큰은 로그·감사 로그·URL·오류 메시지에 넣지 않는다. 임시 비밀번호는 발급 응답(또는 CLI 출력)에 한 번만 담는다.
- **감사 로그 작업 이름**: `user.create`, `user.update`(X05: 이메일 가림), `user.role_change`, `user.deactivate`, `user.activate`, `user.temp_password`, `user.password_set`, `user.unlock`, `cli.user_unlock`, `cli.user_password_reset`, `user.bulk_import`(P28A 계정 CSV — 사용자별 `user.create`·`user.deactivate`에 `source: csv`), `user.pending_create`·`user.approve`·`user.reject`·`user.pending_delete`·`user.pending_purge`·`user.external_link`·`user.auth_type_change`·`auth.login_failed`(사유)·`auth.ldap_secret_set`·`auth.ldap_secret_delete`(P28B, 값·이메일 원문 없음), `auth.oidc_secret_set`·`auth.oidc_secret_delete`·`auth.login_failed`의 `oidc_*`·`login_id_conflict` 사유(P28C, 비밀·코드·토큰 없음), `auth.saml_sp_key_generate`·`auth.saml_sp_key_delete`·`auth.saml_metadata_import`·`auth.login_failed`의 `saml_*` 사유(P28D, 키·응답 원문 없음). 새 관리자 작업을 만들면 `<대상>.<동작>` 형식으로 추가하고 여기에 적는다.
- **오류 코드**: `csrf_invalid`(403), `unauthenticated`·`session_expired`·`login_failed`·`temp_password_expired`(401), `self_password_change`(400), `forbidden`·`password_change_required`(403), `too_many_attempts`(429), `last_admin`·`login_id_taken`·`not_local_account`(409).
- **사용자 정보 수정**(X05, 명세 19.10): 표시 이름·이메일만. `UserService::updateProfile($actorUser, $ip, $targetId, $data)` **한 곳**을 관리자(`PATCH /api/v1/users/{id}`, 사용자 관리 화면 "정보 수정")와 본인(`PATCH /api/v1/users/me`, `/account` "내 정보")이 같이 쓴다.
  - 권한: admin이거나 본인. 그룹 관리자도 구성원은 못 고친다(403 `user_profile_forbidden`, 관리자 경로는 RequireAdmin이 먼저 403 `forbidden`). LDAP 계정은 409 `ldap_managed`(P28 동기화가 값을 쓴다).
  - 검증: 표시 이름 앞뒤 공백 제거·NFC·1~100자·제어 문자와 양방향 서식 문자 금지·다른 사용자의 로그인 ID와 같으면 거부(`display_name_required`·`_too_long`·`_invalid`·`_reserved`), 이메일 선택·비면 NULL·254자·단순 형식(`email_invalid`·`email_too_long`)·중복 허용. 다른 필드는 무시. 같은 값이면 저장·감사 로그 없음.
  - **생성 경로도 같은 규칙**(X06): 관리자 생성(`POST /users`), 그룹 관리자 생성(`POST /groups/{id}/accounts`), CLI 관리자 생성이 `Services\UserProfileRules`(수정 경로와 공용)로 검증한다. 오류 코드는 P10 계약(`invalid_display_name`, `invalid_email`)을 유지하고 사칭 이름만 `display_name_reserved`. 생성에서는 기존 모든 사용자의 로그인 ID와 비교한다(새 계정 자기 로그인 ID와 같은 이름은 허용). 이름 검사는 생성 트랜잭션 안(로그인 ID 중복 검사 다음)이라 실패하면 계정·구성원 행·감사 로그가 남지 않는다.
  - 감사 로그 `user.update` details: `{"actor_role":"admin|user","self":bool,"display_name":{"from","to"},"email":{"from","to"}}`, 이메일은 `UserService::maskEmail`(`k***@example.com`). 사용자 생성은 아래 X06 항목.
  - 이메일은 admin(사용자 관리)과 본인(내 정보)에게만 보인다. 사용자 검색·그룹 구성원·담당자 목록에 넣지 않는다(`UserProfileTest`가 검사).
  - 상단 바의 사용자 이름이 "내 정보" 링크(`.topbar__account`, 접근 이름 "내 정보")다. 사용자 정보는 요청마다 DB에서 읽으므로 다음 요청부터 새 이름이 보이고, "내 정보" 화면은 저장 직후 상단 바 글자도 바꾼다.

## 17. 프로젝트와 제외 패턴 (P11)

### 17.1 제외 판정은 `Support\ExcludeMatcher` 하나로

zip 해제(P13), 신규/해결/범위 외 판정(P16), 브라우저 사전 제외(P12, 기본 목록만)는 **모두 `ExcludeMatcher`를 쓴다.** 다른 곳에서 제외 규칙을 따로 구현하지 않는다. 프로젝트의 판정기는 `ProjectService::matcherFor($projectId)`(서버 기본 목록 + 그 프로젝트의 추가 패턴)로 얻는다.

- `matches($path): bool`, `explain($path): ?string`(`default:<이름>` 또는 `pattern:<패턴>`, 화면은 `exclude.reason.*` 문구로 바꿔 보여 주고 로그에는 그대로 남긴다).
- 입력 경로: 분석 루트 기준 상대 경로, `/` 구분(역슬래시는 `/`로 바꿔 본다), NFC로 정규화해 비교.

**서버 기본 목록**(설정 `extract.default_excludes`, 항상 적용): `node_modules`, `.venv`, `venv`, `__pycache__`, `.git`, `dist`, `build`, `__MACOSX`, `.DS_Store`. 경로 조각 하나가 이름과 **정확히 같을 때만** 제외한다(대소문자 구분). `builder/`, `src/dist.py`, `my_dist/`는 제외되지 않는다.

**프로젝트 추가 패턴 문법**(관리자가 프로젝트 수정 화면에서 한 줄에 하나):

| 문법 | 뜻 | 예 |
| --- | --- | --- |
| `*` | `/`를 넘지 않는 0자 이상 | `*.log`, `gen/*.py` |
| `?` | `/`가 아닌 한 글자 | `tmp?` |
| `**` | 경로 조각 0개 이상. **조각 전체로만** 쓴다(`a**b` 금지). 끝의 `**`는 "그 아래 전부"(그 이름의 파일 자체는 제외하지 않음) | `docs/**`, `tests/**/fixtures` |
| 앞의 `/` | 루트 기준. 없으면 어느 깊이에서든 시작(앞에 `**` 조각을 붙인 것과 같음) | `/setup.py` |
| 끝의 `/` | 폴더에만 맞음(같은 이름의 파일은 제외하지 않음) | `out/` |

- 패턴은 경로 자체와 그 경로의 **모든 상위 폴더**에 맞춰 본다. 폴더가 맞으면 그 아래 전부가 제외된다(`vendor/` → `vendor/a/b.js` 제외).
- 대소문자를 구분하고, 패턴과 경로를 모두 NFC로 정규화해 비교한다(macOS zip의 NFD 한글 경로도 일치).
- **저장 시 거부**(`ExcludeMatcher::validatePattern`, 오류 `invalid_exclude_pattern`): 빈 값, `.`·`..` 조각, 절대 경로(드라이브 문자, `//`), 역슬래시, 제어 문자, 조각 일부로 쓴 `**`, 지원하지 않는 문법(`[` `]` `{` `}` `!`), 빈 조각(`a//b`), 200자 초과. 프로젝트당 100개까지(`too_many_exclude_patterns`). 저장할 때 앞뒤 공백을 지우고 빈 줄·중복을 없앤다.
- 이유: 명세 4장은 "제외 패턴 추가"만 정하고 문법이 없다. gitignore와 비슷하되, 부정(`!`)·문자 집합(`[...]`)처럼 결과를 예측하기 어려운 문법은 빼고 "무엇이 빠지는지" 관리자가 바로 알 수 있는 최소 문법으로 정했다. 업로드된 설정 파일의 제외 설정(ruff `exclude`, `.eslintignore`)은 도구 단계(P15)에서 따로 적용한다.

### 17.2 프로젝트 삭제 순서

1. 이름 확인: 요청의 `confirm_name`이 프로젝트 이름과 **정확히** 같아야 한다(서버 검사, `project_name_mismatch`). 화면 버튼 비활성화는 편의일 뿐이다.
2. 쓰기 트랜잭션 안에서: 대기·실행 중 분석(`queued`, `running`) 또는 워커가 처리 중인 업로드(`assembling`, `extracting`)가 있으면 거부(409 `project_busy`, 가정) → 지울 파일 위치를 DB에서 모음 → `DELETE FROM projects`(외래 키 연쇄로 업로드·분석·파일 목록·스냅샷 행·결함·이력·발췌 삭제) → 감사 로그 `project.delete`.
3. 커밋 뒤 파일 삭제: 스냅샷(`snapshots/<storage_path>`, `snapshots/<프로젝트 id>/`), 업로드 조각(`uploads/<upload_id>/`), 작업 디렉토리(`work/<8자리>`). 경로는 DB에 서버가 기록한 값으로만 만들고 각 기준 폴더 밖은 건드리지 않는다.
4. 파일 삭제에 실패하면 WARN 로그와 정리 대상 목록(`work/cleanup_pending.jsonl`, `Support\CleanupQueue`)에 남기고 삭제는 성공으로 응답한다(`file_cleanup_failures`). 정리 작업(P14)이 목록을 다시 처리한다.

**이후 단계가 지킬 파일 배치**: 업로드 조각·조립본은 `uploads/<upload_id>/` 아래에만(P12), 스냅샷은 `snapshots/<프로젝트 id>/<분석 id>/`(P16), 해제 작업 디렉토리는 `uploads.work_dir`에 기록한 `work/<8자리>`(P13). 이 배치를 벗어나면 프로젝트 삭제가 파일을 찾지 못한다.

### 17.3 기타

- 홈(`Navigation::HOME`)은 P11부터 프로젝트 목록(`/projects`). 옛 `/home`은 홈으로 이동한다.
- 프로젝트 상세의 탭은 구현된 단계가 `ProjectPageController::show()`의 `$tabs`에 넣는다. 비어 있으면 탭 자체를 숨긴다(미완성 화면 노출 방지).
- 감사 로그 작업 이름: `project.create`, `project.update`(변경 필드별 from/to), `project.delete`(이름·언어·삭제된 분석·결함·스냅샷 크기).
- 오류 코드: `project_not_found`(404), `invalid_project_name`·`invalid_description`·`invalid_language`·`invalid_exclude_pattern`·`too_many_exclude_patterns`·`project_name_mismatch`(400), `project_name_taken`·`language_immutable`·`project_busy`(409).

## 18. 사용자 그룹 (P11A, 명세 3.1)

### 18.1 권한 판정은 `Services\GroupPermission` 하나로

그룹 관련 권한은 **모두 `GroupPermission`을 거친다.** 컨트롤러·템플릿·다른 서비스에서 `role === 'admin'`이나 `group_members`를 직접 보고 그룹 권한을 판정하지 않는다(P11B의 프로젝트 접근 판정도 같은 클래스를 확장한다).

- `canAdministrate($user)`: 활성 admin. `canManage($user, $groupId)`: admin 또는 그 그룹의 관리자. `managesAny($user)`: admin 또는 어느 그룹이든 관리자(메뉴 표시, 사용자 검색).
- 비활성·null 사용자는 항상 false. **캐시하지 않고 매 호출 DB를 읽는다**(구성원·관리자 변경이 다음 요청부터 바로 반영).
- 라우트는 `$auth`만 붙이고 그룹별 권한은 `GroupService`가 판정한다. 생성·수정·삭제 **화면**만 `$admin`.

| 동작 | admin | 이 그룹의 관리자 | 다른 그룹의 관리자 | 구성원·그 밖의 사용자 |
| --- | --- | --- | --- | --- |
| 그룹 목록 `/groups` | 전체 | 자기 그룹만 | 자기 그룹만 | 404 |
| 그룹 상세·구성원 목록 | ○ | ○ | 404 `group_not_found` | 404 |
| 그룹 생성·이름/설명 수정·삭제 | ○ | 403 `forbidden` | 404(대상 그룹) / 403 | 404 / 403 |
| 구성원 추가·제거, 관리자 지정·해제 | ○ | ○(자기 자신 제거·해제 불가) | 404 | 404 |
| 그룹 안 계정 생성(역할 `user` 고정) | ○ | ○ | 404 | 404 |
| 사용자 검색 `/api/v1/users/search` | ○ | ○ | ○ | 403 |

- 관리 대상이 아닌 그룹은 존재를 숨기기 위해 404로 답한다. 관리할 수 있는 그룹에서 admin 전용 동작을 하면 403.
- 사용자 검색은 활성 사용자만, 2자 이상, 최대 20명, 로그인 ID와 표시 이름만 돌려준다(이메일·역할·상태를 그룹 관리자에게 노출하지 않음).
- 그룹 안 계정 생성은 요청의 `role`·`group_id`·`auth_type`을 무시하고 `UserService::create($actor, $data, $auditExtra, $within)`로 계정 생성과 구성원 추가를 한 트랜잭션에서 한다.

### 18.2 기타

- 감사 로그 작업 이름: `group.create`, `group.rename`(이름이 바뀐 수정), `group.update`(설명만 바뀐 수정), `group.delete`(이름, 구성원 수, 매핑된 프로젝트 수), `group.member_add`, `group.member_remove`, `group.manager_set`, `group.manager_unset`. 그룹에서 만든 계정은 `user.create`에 `group_id`와 `actor_role`(`admin`·`group_manager`)을 함께 남긴다. 임시 비밀번호는 넣지 않는다.
- 오류 코드: `group_not_found`·`member_not_found`(404), `invalid_group_name`·`invalid_description`·`group_name_mismatch`·`search_query_too_short`(400), `cannot_remove_self`·`cannot_unset_self`(403), `group_name_taken`·`already_member`·`user_inactive`·`admin_implicit_member`(409).
- 메뉴(P12에서 바꿈, 사용자 결정): admin은 관리자 메뉴의 "그룹 관리"(`admin.groups`, "사용자 관리"와 "프로젝트 관리" 사이)로, admin이 아닌 그룹 관리자(`managesAny`)는 일반 메뉴의 "그룹"(`nav.groups`, "프로젝트" 다음)으로 `/groups`에 간다(중복 방지). 일반 구성원·비구성원은 메뉴가 없고 주소는 404. 사용자 관리 화면은 소속 그룹 이름을 읽기 전용 열로 보여 준다(`GET /api/v1/users` 항목의 `groups`).
- 그룹 삭제의 "매핑된 프로젝트 수"는 `project_access`의 그룹 매핑 수다(P11B). 그룹을 지우면 트리거가 그 매핑을 함께 지운다.

## 19. 프로젝트 접근 범위 (P11B, 명세 3.1) — 이후 모든 단계가 지킨다

### 19.1 판정은 `Services\ProjectAccess` 한 곳

- **볼 수 있는 사람**: 활성 admin, 프로젝트에 직접 매핑된 사용자, 매핑된 그룹의 구성원(그룹 관리자 포함). 매핑이 없으면 admin만.
- 접근 단계는 하나다. 매핑되면 조회·업로드·결함 관리를 모두 한다. 프로젝트 생성·수정·삭제와 매핑 설정은 admin만(그룹 관리자도 못 함).
- **보이지 않는 프로젝트는 존재를 숨긴다**: 목록에서 빼고, 직접 접근하면 실제로 없는 프로젝트와 **같은 응답**(404 `project_not_found`, 같은 문구)을 준다. 403이나 "권한이 없습니다"를 쓰지 않는다.
- **접근 검사가 권한 검사보다 먼저**다: 보이지 않는 프로젝트의 수정·삭제 요청도 404, 보이지만 권한이 없는 동작(일반 사용자의 수정)은 403.
- 캐시하지 않는다. 구성원·매핑 변경은 다음 요청부터 바로 반영된다.

| 메서드 | 쓰임 |
| --- | --- |
| `canView($user, $projectId)`, `requireView(...)` | 판정. `requireView`는 404 `project_not_found` |
| `userCanView($userId, $projectId)` | 사용자 id로 판정(담당자 "접근 권한 없음" 표시, 알림 대상 거르기) |
| `scopeFor($user, 'p')`, `visibleProjectsSql($userId, 'p')` | 목록 쿼리에 붙이는 조건과 매개변수(한 쿼리, N+1 없음). admin은 `1 = 1` |
| `assignableUsers($projectId)` | 담당자 후보(P18): 접근 가능한 활성 사용자(admin 포함) |
| `usersWithAccess($projectId)` | 알림 수신자 후보: 같은 집합의 id(중복 없음) |
| `mappings`, `summary`, `mappingCount`, `add`, `remove` | 매핑 조회·변경(admin, 감사 로그 `project.access_add`·`project.access_remove`) |

### 19.2 라우트 규칙 (경로 점검 시험 `ProjectRouteAccessTest`)

- 프로젝트 id를 받는 모든 API·화면은 라우트 미들웨어 **`RequireProjectAccess`**를 붙인다(`src/routes.php`의 `$projectView`, `$projectAdmin`). 순서: 로그인 → 비밀번호 변경 → **접근 판정** → (관리자 확인).
- **자식 리소스**(업로드 id, 분석 id, 결함 id, 스냅샷 파일 …)는 소유 프로젝트를 구해서 같은 판정을 한다. 자식 id만으로 접근하는 경로를 만들지 않는다.
  ```php
  new RequireProjectAccess(static fn () => $app->projectAccess(), 'id',
      static fn (string $id): ?int => ctype_digit($id) ? $app->analyses()->projectIdOf((int) $id) : null, 'analysis_not_found');
  ```
  자식이 없거나 프로젝트를 볼 수 없으면 둘 다 자식의 404(`analysis_not_found`)로 같게 응답한다.
- 경로 점검 시험은 `RESOURCE_PREFIXES`(`/api/v1/projects`, `/projects`, `/api/v1/uploads`, `/api/v1/analyses`, `/api/v1/defects`, `/api/v1/snapshots`, `/api/v1/reports`, `/api/v1/repositories` 등)로 시작하는 모든 라우트가 판정을 선언했는지 검사한다. 판정을 붙이지 않는 경로(목록, 생성, 전역 기본 제외 목록)는 시험의 `EXCEPTIONS`에 이유와 함께 넣는다. **목록·집계 경로**(내게 지정된 결함, 대시보드 등)는 쿼리에 `scopeFor`를 붙인다.
- 새 자식 리소스 접두어를 만들면 `RESOURCE_PREFIXES`에 추가한다.
- 각 단계(P12~P19)의 시험에 "보이지 않는 프로젝트는 404(없는 id와 같은 응답)"를 넣는다.

### 19.3 기타

- 상세·목록 응답의 `access`(그룹 n, 사용자 m)는 관리자에게만 준다. 일반 사용자 화면에는 매핑 정보를 보여 주지 않는다.
- "볼 수 있는 사람 수"(`viewer_count`)는 admin을 뺀 활성 사용자 수다(직접 + 그룹, 중복 제거).
- 매핑 추가 오류: `access_exists`·`user_inactive`·`admin_implicit_access`(409), `group_not_found`·`user_not_found`(404), `invalid_input`(400). 제거: `access_not_found`(404).
- 그룹 관리자 배지: `App::view()`의 `isGroupManager`(managesAny이고 admin이 아님)일 때 상단 역할 옆에 `ui.group_manager` 아이콘 + "그룹 관리자".

## 20. 청크 업로드 (P12, 명세 5.4, 19.3, 19.10)

- 코드: `Services/Upload/`(`UploadService`, `UploadAssembler`, `CleanupService`, `DiskGuard`, `UploadFiles`, `UploadSettings`). 프로토타입(P06, P32에서 지움)은 참고만 하고 다시 썼다.
- **API**: `POST /api/v1/projects/{id}/uploads`(init, 201) → `PUT /api/v1/uploads/{id}/chunks/{n}`(원시 본문, `X-Chunk-Sha256`) → `POST /api/v1/uploads/{id}/complete`(202, 조립 대기) / `GET /api/v1/uploads/{id}/status`(받은 청크와 해시) / `GET /api/v1/uploads/{id}`(상태 폴링) / `DELETE /api/v1/uploads/{id}`(취소).
- **접근**: init은 프로젝트 id, 나머지는 upload_id → 소유 프로젝트로 `RequireProjectAccess`(보이지 않으면 404). 그다음 `UploadService`가 **업로드 소유자**를 확인한다(다른 사용자는 관리자라도 404 `upload_not_found`, 없는 id·형식이 틀린 id와 같은 응답).
- **파일 배치**: `uploads/<upload_id>/` 아래에만(`UploadFiles`). 경로 요소는 서버가 만든 upload_id(32자리 16진수)와 정수 청크 번호뿐이다. 파일명은 표시용.
- **청크**: 본문을 `Request::bodyStream()`으로 1MB씩 읽으며 SHA-256 계산 → 임시 파일 → 일치하면 잠금(`.lock`) 안에서 상태를 다시 확인하고 `<n>.part`로 교체(멱등). 선언 길이(Content-Length)와 실제 길이를 모두 검사한다.
- **조립**: 요청 안에서 하지 않는다. `UploadAssembler::assembleNext()`가 `assemble_pending` 하나를 조건부 UPDATE로 가져와 1MB씩 이어 붙이고 SHA-256·크기를 확인한 뒤 `upload.zip`으로 옮기고 청크를 지운다 → `extracting`(해제 대기, P13이 이어받음). 실패하면 `failed`(`assemble_failed`)와 폴더 삭제.
- **디스크 가드**(`DiskGuard`, P25도 사용): 예약 = S + max(S, X)(X = 해제 상한). 여유 = 실제 여유 − 진행 중 예약 합계. 사용률 90% 차단·80% 경고(설정 `disk.*`). 근거는 클래스 주석과 `docs/xampp_upload_settings.md` 4절.
- **정리**: `tools/cleanup.php` → `CleanupService::purgeIncompleteUploads()`(받는 중이고 24시간 무활동 → aborted·삭제, DB 행이 없거나 끝난 업로드의 남은 폴더 삭제). P14·P30이 정리 항목을 추가한다.
- **세션**: 업로드 요청도 SessionMiddleware가 활동을 갱신한다(`SessionService::touch`). 세션이 만료되어 다시 로그인해도 같은 사용자는 `status`로 이어 올린다.
- **브라우저**(`pages/upload.js`, 순수 함수 `lib/upload_core.js`): 폴더는 로컬 fflate **STORE만**(경로 정렬 + mtime 고정 → 같은 zip 바이트), 기본 제외 대상은 묶기 전에 뺀다(기본 켜짐). 2GB 초과는 업로드 전에 차단(폴더는 STORE 기준 예상 크기). 이어 올리기 키는 `localStorage`의 `pjg-upload-resume:<사용자 ID>`. 청크 재시도 3회(네트워크·5xx·422만).
- 오류 코드: `upload_not_found`(404), `invalid_upload_size`·`invalid_file_name`·`invalid_chunk_index`·`invalid_chunk_hash`·`invalid_chunk_length`(400), `upload_too_large`(413), `chunk_hash_mismatch`(422), `upload_not_receiving`·`chunks_missing`·`upload_not_abortable`(409), `too_many_uploads`(429), `insufficient_storage`·`disk_usage_blocked`(507), 조립 실패 `assemble_failed`(업로드 상태).

## 21. 안전 해제와 업로드 후처리 (P13, 명세 5.1, 5.4, 15.2, 19.10)

- **해제는 `Services\Archive\SafeUnzip` 하나로**(저장소 가져오기 P25도 zip을 받으면 이것). `extractTo()`·외부 명령(unzip, 7z)을 쓰지 않는다.
  - 순서: zip 크기 → `ZipArchive::open(RDONLY|CHECKCONS)` → 1차 훑기(이름 해석·NFC, 경로 검사, 기본 제외, 최상위 폴더 감지) → 최상위 폴더 결정(**여기서 한 번만**) → 2차 훑기(제거 뒤 경로로 전체 제외 판정, 남은 항목만 심볼릭 링크·중첩 zip·Windows 이름·충돌·259자) → 선언 크기·파일 수 사전 검사 → `getStreamIndex()` + `fopen('xb')`로 64KB씩 쓰며 **실제로 쓴 바이트**로 한도 검사 → 실패하면 작업 디렉토리 삭제.
  - 제외 판정은 프로젝트의 `ExcludeMatcher`(`ProjectService::matcherFor`). 제외 대상 안이어도 경로 조작·절대 경로·역슬래시는 거부하고, 심볼릭 링크·중첩 zip·Windows 이름은 보지 않는다. 앵커 패턴(`/docs/**`)은 최상위 폴더 제거 뒤의 경로 기준.
  - 이름: UTF-8 플래그 → UTF-8, 없으면 CP949(왕복 일치), CP949 실패 + UTF-8 유효 → UTF-8, 둘 다 실패 → `filename_encoding`. libzip이 열 때 거부하는 경우(플래그가 있는데 UTF-8이 아닌 이름, 바이트까지 같은 중복 이름)는 `invalid_zip`.
  - 메모리: 항목 이름을 들고 있지 않고(번호·크기만 정수 배열, 해제할 때 다시 해석), 충돌 키는 md5 16바이트. 실측: 항목 100만 개에서 PHP 최대 172MB, 프로세스 486MB(libzip 포함). 워커 `memory_limit`는 설정 `extract.worker_memory_limit`(512MB).
  - 오류: `UnzipException`(코드, 자리표시자, 상세 `entry`·`reason`·`stage`). `userMessage()`는 오류 설명 + 항목 이름·사유(항목 이름은 zip 안의 값이라 화면은 textContent로만). `guideKey()`는 가이드 절 키 `upload.error.<코드>`(P29가 링크).
- **후처리 `Services\Upload\UploadProcessor`**(워커 P14, 지금은 `tools/process_upload.php`가 조립 다음에 부름):
  - `extracting`이고 `work_dir`가 빈 업로드를 조건부 UPDATE(`work_dir` 기록)로 가져간다 → `Paths::newWorkDir()`에 해제 → `TargetScanner`(언어별 대상 확장자, JS는 업로드된 ESLint 설정 파일 제외)로 사전 검사.
  - 경고(`no_target`, `top_folder_missing`, `top_folder_kept`) 없음 → `analyses`(queued, source upload, trigger user, requested_by, tool, assembled_sha256, work_dir) 생성, 업로드 queued(예약 0), 프로젝트 `strip_top_folder`가 NULL이면 확정.
  - 경고 있음 → `needs_confirmation`(작업 디렉토리 보존, 요약은 `uploads/<id>/extract.json`). `POST /api/v1/uploads/{id}/confirm`(업로드한 사용자 본인, 두 번 눌러도 분석 하나) → enqueue. 취소(`DELETE`)는 작업 디렉토리와 업로드 폴더를 지운다.
  - 실패 → `failed`(오류 코드·사용자 문구), 작업 디렉토리 삭제, zip은 `upload.failed_zip_retention`(1일) 뒤 정리 작업이 지운다. queued 업로드의 zip은 분석이 성공하면 워커가 바로 지우고, 분석이 실패·취소되면 1일 뒤 정리 작업이 지운다(P14).
- **정리**(`CleanupService::runAll`): 미완료 조각(24시간), 실패·취소 zip(1일), 확인 대기 만료(`upload.confirmation_retention`, 24시간, 가정), 아무도 가리키지 않는 오래된 작업 디렉토리. 중단된 해제 되돌리기는 P14부터 워커 생존 신호 기준(22절, 신호 파일이 없을 때만 24시간 안전망).
- 사용자당 동시 진행 업로드는 `receiving`·`assemble_pending`·`assembling`·`extracting`을 센다(`UploadService::ACTIVE_FOR_LIMIT`, 확인 대기·queued 제외).

## 22. 작업 큐와 워커 (P14, 명세 5.2, 5.4, 19.8, 19.10)

- **프로세스 실행은 `Services\Process\ProcessRunner` 하나로**(P15 도구 실행, P25 git·p4도 이것). `proc_open` + 인자 배열(셸 없음), 실행 파일 허용 목록(realpath 비교, Windows 대소문자 무시), stdout·stderr는 임시 파일, 타임아웃·출력 한도·취소 콜백 → `ProcessTree::kill`(Windows `taskkill /T /F`, Linux `setsid` 그룹에 `SIGKILL`). 메모리 한도는 Linux만 `prlimit --as`. 결과는 `ProcessResult`(사유 normal·timeout·output_limit·cancelled·start_failed, 종료 코드, 소요·종료 시간, 출력 파일, 초과량).
  - 실행기 두 가지: `App::toolRunner()`(bin/ + `tools.node_path`, 분석 도구용), `App::internalRunner()`(지금 실행 중인 `PHP_BINARY`만, 워커의 PHP 자식용). 다른 실행 파일은 `ProcessException`.
  - 여러 프로세스를 한 루프에서 감독할 때는 `start()` → `RunningProcess::poll()`(기다리지 않음). 한 번에 끝까지는 `run()`.
  - 남은 프로세스 확인은 `ProcessTree::aliveAmong($pids)`(시험 도우미), PID 재사용 확인은 `imageName()`.
- **큐**(`Services\Queue\AnalysisQueue`): 큐 = `analyses.status='queued'`의 id 순서. `claimNext($max, $workerPid)`는 `BEGIN IMMEDIATE` 안에서 "running 수 < 한도 → 같은 프로젝트에 running이 없는 가장 앞 queued(취소 요청 제외) → running". 프로젝트당 1개는 고유 인덱스 `analyses_one_running_per_project`로도 보장. 결과 기록 `complete()`·`fail()`·`markCancelled()`는 모두 `status='running'` 조건부(취소 요청이 있으면 `complete()`는 거절). 대기 순번 `position()`은 앞에 대기 중인 수 + 1(건너뛰기 때문에 근사값).
- **분석 실행기 인터페이스**(`Services\Analysis\AnalysisRunner::run(AnalysisJob): AnalysisOutcome`): 분석 자식(`tools/run_analysis.php <id>`)이 부른다. 실패는 예외가 아니라 `AnalysisOutcome::failed(코드)`. 실패 코드의 사용자 문구는 `analysis.failure.<코드>`(`AnalysisQueue::failureMessage`). P14 구현은 `FakeAnalysisRunner`(환경 변수 `PJG_FAKE_ANALYSIS`, 시험·시연 전용), P15가 ruff/ESLint로 바꾼다.
- **워커**(`tools/worker.php` → `Services\Queue\Worker`): 부모는 감독만(0.1초 루프), 무거운 일은 자식 프로세스: 분석 `tools/run_analysis.php`, 조립·해제 `tools/run_upload.php assemble|extract <id>`(부모가 `claimNext()`로 가져온 뒤), 큰 디렉토리 삭제 `tools/empty_trash.php`(부모는 `Support\Trash`로 rename만). 자식 PHP는 같은 php.ini(`-c`), `memory_limit = extract.worker_memory_limit`, 최소 환경 + `PJG_*` 환경 변수, Windows는 새 프로세스 그룹(콘솔 Ctrl+C가 자식에게 가지 않음).
  - 취소: API가 queued는 바로 cancelled, running은 `cancel_requested=1` → 감독 루프가 `worker.cancel_check_interval`(1초)마다 확인해 트리 종료 → cancelled. 타임아웃 → failed `timeout`. 자식이 결과 없이 끝남 → failed `analysis_crashed`.
  - 분석 종료: 작업 디렉토리를 휴지통으로, 성공이면 업로드 폴더(zip) 즉시 삭제. 지우지 못하면 `work/cleanup_pending.jsonl`(정리 작업이 재시도).
  - 기동: 단일 인스턴스 잠금 → `WorkerRecovery::recoverAll()`(running → failed `worker_crashed`, 재시도 없음 / assembling → assemble_pending / extracting + work_dir → 되돌림, 남은 자식 트리 종료).
  - 정지: `stop.request`(`--stop`), Ctrl+C, SIGTERM → 새 선점 중단, 자식 대기. 강제 정지는 분석 failed `worker_stopped`.
- **생존 신호**(`Services\Queue\WorkerState`, P30 시스템 모니터링·헬스체크가 읽는다): `<work_dir>/worker/heartbeat.json`을 `worker.heartbeat_interval`(10초)마다 원자적 교체.
  `{"pid":123,"host":"PC","version":"0.1.0-dev","started_at":"…Z","beat_at":"…Z","state":"running|stopping|stopped","analyses":[{"id":5,"pid":456}],"uploads":[{"id":"<32자리>","pid":457,"stage":"assemble|extract"}]}`.
  같은 폴더에 `worker.lock`(단일 인스턴스), `stop.request`, `tmp/`.
- **죽은 워커 판정**(`WorkerRecovery::recoverIfStale`, 정리 작업과 `worker.php --check`): 잠금을 잡을 수 있고(살아 있는 워커 없음) 신호가 `worker.heartbeat_stale`(3분) 넘게 끊겼을 때만 되돌린다. 잠금이 잡혀 있으면 오래 걸리는 정상 해제·분석을 되돌리지 않는다(신호도 끊겼으면 경고만). 신호 파일이 없으면 `worker.stale_safety`(24시간)보다 오래된 것만.
- 실패 코드: `timeout`, `output_limit`, `worker_crashed`, `worker_stopped`, `analysis_crashed`, `analysis_start_failed`, `tool_failed`(+ P15가 추가). 업로드 후처리 자식 실패: `assemble_failed`, `extract_timeout`, `extract_crashed`.
- API: `GET /api/v1/projects/{id}/analyses`(상태 필터·페이지), `GET /api/v1/analyses/{id}`, `POST /api/v1/analyses/{id}/cancel`(요청자 본인·admin, 다른 사용자 403, 끝난 분석 409). 분석 id → 소유 프로젝트로 `RequireProjectAccess`(보이지 않으면 404 `analysis_not_found`).
- 기동·등록·OS별 자원 제한 표: `docs/worker_setup.md`.

## 23. 분석 도구 실행과 결과 파일 (P15, 명세 5.3, 6.1, 12, 19.1, 19.10)

- **도구 레지스트리**(`Services\Tools\ToolRegistry`, `App::toolRegistry()`, CLI `tools/tools_ctl.php`): `bin/<도구>/<버전>/`을 semver 내림차순으로 보고 고정(pinned) → 그 버전만, 차단(blocked) → 건너뜀. 처음 보는 버전이나 크기·수정 시각 **지문**(`ToolManifest::fingerprint`)이 바뀐 버전은 **전체 체크섬(SHA256SUMS) → 스모크 테스트**(`ToolSmokeTest`, samples/ 기대 결함 수)를 통과해야 active. 실패하면 invalid + WARN, 직전 유효 버전을 쓴다. ESLint는 10.x만(`SUPPORTED_MAJOR`). 상태는 `tool_versions`, 지문 캐시는 `<work_dir>/tools/<도구>-<OS>-<버전>.json`, 동시 검증은 `<work_dir>/tools/registry-<도구>.lock`으로 직렬화.
- **실행**(`RuffRunner`, `EslintRunner`, 공통 `ToolExecution`): `App::toolRunner()`(bin/ + Node만), 한도 `analysis.timeout`·`analysis.output_limit`, 메모리 `analysis.process_memory_limit`(ruff는 Linux만 prlimit, ESLint는 `--max-old-space-size`). 실행 디렉토리는 분석 작업 디렉토리, 환경 변수는 최소(HOME·TEMP는 실행마다 빈 임시 폴더).
  - ruff: `check --output-format json-lines --no-cache --no-respect-gitignore --exit-zero --config <설정> --extend-exclude <기본 제외 이름> .` 설정은 `RuffConfigResolver`(루트 `.ruff.toml` > `ruff.toml` > `pyproject.toml`의 ruff 설정, extend가 프로젝트 밖·`~`·`$`·`%`면 거부 → 서버 기본 `config/ruff.default.toml`). 하위 폴더 설정은 쓰지 않고 `ruff_nested_config`로 기록.
  - ESLint: `node … eslint.js -c tools/eslint/pjg.flat.config.cjs --format tools/eslint/ndjson-formatter.cjs --no-error-on-unmatched-pattern --no-warn-ignored .` 서버 flat config가 `config/eslint.default.json`을 FlatCompat으로 쓰고, 업로드된 ESLint 설정은 **통째로 무시**(`eslint_config_ignored`), JS 설정 파일은 ignores로 분석 대상에서 뺀다. 루트 `.eslintignore`만 `EslintRunner::eslintIgnorePatterns`로 바꿔 적용, `.gitignore`는 무시.
    - **테스트 파일 전역(X07)**: `config/eslint.default.json`의 마지막 `overrides` 하나가 테스트 파일에만 테스트 프레임워크 전역을 켠다(목록은 이 한 곳). 파일 패턴 `**/*.test.*`, `**/*.spec.*`, `**/test/**`, `**/tests/**`, `**/__tests__/**`, `**/spec/**`. 전역 = eslintrc 환경 `jasmine`·`mocha`·`jest`(설치된 `@eslint/eslintrc`가 `globals` 패키지로 정의, 네트워크 없음) + `vi`·`vitest`(vitest, 읽기 전용). 테스트가 아닌 파일에는 켜지 않아 진짜 `no-undef`는 그대로 잡는다. 규칙 수준은 그대로다. 이 파일이 설정 해시에 들어가므로 바꾼 뒤 첫 분석은 "버전/설정 변경"으로 보이고, 사라진 오탐 `no-undef`는 그 분석에서 자동 해결(not_detected)된다(`docs/results/X07.md`).
  - 제외: 서버 기본 목록(이름)은 도구 인자·ignores로, 프로젝트 추가 패턴은 해제 단계에서 빠졌고 결과를 쓸 때 `ExcludeMatcher`로 한 번 더 거른다(제외 판정 구현은 하나, 17.1). 폴더 건너뛰기용 `ExcludeMatcher::matchesDir()`.
  - 실패 코드: `tool_crash`(비정상 종료·출력 형식 오류), `tool_config_error`(ruff 종료 코드 2, ESLint 설정 검증 오류), `timeout`, `output_limit`(첫 폴링 전에 끝났어도 크기 초과면), `memory_limit`(Rust 할당 실패, Node 힙 부족), `tool_unavailable`(검증 통과 버전 없음·Node 없음), `insufficient_storage`(시작 전 여유 < 출력 한도 + `analysis.output_margin`). 문법 오류는 결함(`syntax-error`)이고 분석은 성공.
- **분석 기록**(`AnalysisQueue::complete`): `tool_version`(ESLint는 `10.11.0 (Node 24.21.0)`), `config_source`(`upload`/`server_default`, ESLint는 항상 server_default), `config_hash`(실제 적용한 설정의 정규화된 내용 SHA-256), `ignored_configs`(JSON 배열 `[{"file","reason"[,"detail"][,"count"]}]`, 사유 `eslint_config_ignored`·`ruff_nested_config`·`ruff_config_rejected`·`eslintignore_converted`(P19: 루트 `.eslintignore`를 서버 패턴으로 바꿈, count = 패턴 수), 100개 넘으면 `{"file":"…","count":n}`). P15의 성공 분석은 `total_count` = 감지 건수, 나머지 통계 0(P16이 채운다).
- **결과 파일**(P16이 읽는다): `<work_dir>/results/<분석 id>.ndjson`과 분석 범위 `<분석 id>.scope`(도구가 분석한 파일, 한 줄에 NFC 상대 경로 하나: ruff `--show-files`, ESLint 포매터의 `{"kind":"file"}` 줄). 성공한 분석만 생기고(실패면 쓰던 `.part`를 지움), P16이 결함을 커밋한 직후 지운다. 반영이 실패해 남은 파일은 1일(`upload.failed_zip_retention`) 뒤 정리 작업이 지운다(`CleanupService::purgeResults`). 한 줄 = 결함 하나(UTF-8, LF), 키 순서 고정:

  ```json
  {"v":1,"tool":"ruff","language":"python","rule_key":"ruff:F401","file":"app/handlers/api.py","line":2,"column":8,"end_line":2,"end_column":10,"message":"`re` imported but unused","syntax_error":false,"tool_severity":"error"}
  ```

  | 키 | 뜻 |
  | --- | --- |
  | `v` | 형식 버전(1) |
  | `tool` | `ruff` / `eslint` |
  | `language` | `python` / `javascript` / `typescript`(.ts, .tsx) |
  | `rule_key` | 도구 포함 규칙 키(`ruff:F401`, `eslint:no-unused-vars`, `eslint:@typescript-eslint/no-explicit-any`), 문법 오류는 `syntax-error`, 규칙 없는 ESLint 메시지는 `eslint:no-rule-id` |
  | `file` | 작업 디렉토리 기준 상대 경로, `/` 구분, NFC. 실제 있는 파일만(루트 밖·없는 파일·제외 대상은 버림 + WARN) |
  | `line`, `column`, `end_line`, `end_column` | 1부터. `column`·`end_*`는 없을 수 있음(null) |
  | `message` | 도구 메시지(화면은 텍스트로만) |
  | `syntax_error` | 문법 오류 여부 |
  | `tool_severity` | 도구의 원래 심각도(ruff 문자열, ESLint 정수). 앱 심각도는 규칙 매핑(P16 이후) |

  같은 파일의 결함은 이어서 나온다(도구가 파일 단위로 출력). P16은 파일 단위로 읽어 시그니처를 계산하고, 같은 줄·같은 코드의 중복 결함(예: ts_sample `src/api.ts` 2줄의 두 건)도 순서대로 들어 있다.
- 분석 실행기 연결: `App::analysisRunner()` = `ToolAnalysisRunner`. 환경 변수 `PJG_FAKE_ANALYSIS`가 있으면 가짜 분석기(P14 시험·시연 전용).

## 24. 결함 저장·시그니처·자동 resolved·스냅샷 (P16, 명세 6, 7, 8, 19.10)

- **흐름**(분석 자식 `tools/run_analysis.php` 안, 작업 디렉토리가 있을 때): 도구 실행(P15, 결과 NDJSON + 분석 범위 `.scope`) → `Defects\AnalysisFinalizer::finalize()`
  1. `DefectIngest`: NDJSON을 한 줄씩 읽어 **파일 단위로** 소스를 한 번 읽고 시그니처·순번을 계산해 연결 전용 임시 표 `temp.cur_defects`에 넣는다(본 DB 쓰기 잠금 없음, 정규화 코드 원문은 버리고 해시만).
  2. 범위(`ScopeList`, 도구가 분석한 파일) 읽기 → 취소 요청 확인 → 스냅샷 zip(`SnapshotService::create`).
  3. `DefectStore::apply()` **하나의 쓰기 트랜잭션**: 재오픈 → 감지된 결함 갱신 → 신규 삽입 → 미감지 분류(자동 resolved) → 발췌 폐기 → `analysis_file_lists`·`snapshots` 행 → 통계·비교 기준과 함께 분석 succeeded(**조건부: running이고 취소 요청 없음**, 아니면 전체 되돌림).
  4. 커밋 뒤: 결과·범위 파일 삭제(실패는 로그만), 이벤트(`AnalysisSucceeded`, `AssignedDefectsChanged`), 오래된 스냅샷 정리(실패는 로그만).
  - 3이 실패하면 결함은 하나도 바뀌지 않고 만든 스냅샷을 지우며 분석은 failed(`result_save_failed`), 결과 파일은 남긴다(정리 작업이 1일 뒤).
- **시그니처**(`Defects\SourceNormalizer`): SHA-256(NFC 경로 \0 규칙 키 \0 정규화 코드). 코드 = 소스의 line~end_line(BOM 제거, 도구 기준 줄 나누기, 줄마다 앞뒤 공백 제거·연속 공백 하나·LF). UTF-8이 아니면 바이트 그대로(결정적). 순번 = 파일 안 (줄, 열, 끝 줄, 끝 열, 규칙) 순. 최상위 폴더는 해제 단계에서 이미 제거된 경로를 그대로 쓴다. 정규식에 `/u` 없는 `\v`·`\s` 금지(P04).
- **판정**: 고유 키 (project_id, signature, ordinal). 감지 + 없음 → 신규(not_defined·open·담당자 없음), 감지 + resolved → 재오픈(이력 auto `redetected`), 감지 + 그 밖 → 유지. 미감지(resolved 아닌 것): ① 이번 분석의 `ExcludeMatcher`에 해당 → 범위 밖(유지) ② 범위 목록에 있음 → resolved `not_detected` ③ 작업 디렉토리에 있지만 도구가 안 봄(업로드 설정의 제외) → 범위 밖 ④ 없음 → resolved `file_deleted`. 자동 전환은 모든 상태에서 하고 이전 상태를 이력에 남기며 `version` + 1.
- **비교 기준**: `baseline_analysis_id` = 같은 프로젝트에서 이 분석보다 앞선 가장 최근 성공 분석(실패·취소 건너뜀, 최초는 NULL = "비교 대상 없음").
- **스냅샷**: `<snapshots_dir>/<프로젝트>/<분석>/source.zip`(분석 범위 파일, Deflate). 최근 `snapshot.full_keep_count`(5)회만 full. 그보다 오래된 것은 그 분석이 마지막 감지인 결함마다 앞뒤 `snapshot.excerpt_context_lines`(20)줄 발췌(`defect_excerpts`, UTF-8이 아니면 U+FFFD로 바꾸고 `content_replaced = 1`) → 행 pruned·`files_blob = x''` → zip 삭제. 파일 목록 BLOB도 최근 5회 성공 분석만 남긴다. 결함이 다시 감지되면 발췌 폐기.
- **이벤트**(`Services\Events`, 구독자는 P24): `AnalysisSucceeded`, `AnalysisFailed`(자식·워커가 실패를 기록할 때), `AssignedDefectsChanged`(담당자가 있는 결함의 자동 resolved·재오픈). 커밋 뒤에만 보내고 구독자 예외는 로그만.
- **측정·시드**: `php tools/seed_defects.php --bench`(결함 10만 건·파일 100만 개, 동시 결함 수정 대기 측정), `--db=<시험 DB> --project=<id> --defects=N`(P17 화면 시험용).

## 25. 결함 목록·상세·코드 뷰어 (P17, 명세 6.4, 7, 8, 19.6)

- **API**(읽기 전용, 수정은 P18): `GET /api/v1/projects/{id}/defects`(목록), `…/defects/summary`(필터 요약 + 빈 상태 구분), `GET /api/v1/defects/{id}`(상세), `…/history`(이력, 최신순), `…/code?mode=window|full`(코드). 목록·요약은 프로젝트 id, 나머지는 결함 id → 소유 프로젝트로 `RequireProjectAccess`(보이지 않으면 404 `defect_not_found`, 없는 결함과 같은 응답).
- **필터는 `Services\Defects\DefectFilter` 하나로**: 쿼리 `rule`·`severity`·`status`·`assignee`(쉼표 구분 다중 선택, `none` = 미지정), `file` + `file_mode`(prefix|contains), `q`(메시지·규칙 키·파일 경로 부분 일치), `sort`(file|rule|severity|status|last_seen, 기본 file), `order`.
  - SQL에 들어가는 문자열은 클래스 상수(열·정렬식 허용 목록)뿐이다. 다중 값은 JSON 배열 매개변수 하나 + `IN (SELECT value FROM json_each(?))`.
  - LIKE는 `%`·`_`·`\`를 이스케이프하고 `ESCAPE '\'`. 파일 접두사는 범위 조건(`>= p AND < p의 마지막 바이트+1`)으로 인덱스를 쓴다. 경로 입력은 `\` → `/`, 앞의 `./`·`/` 제거, NFC.
  - 오류: `invalid_defect_filter`(400, 자리표시자 field), `invalid_sort`(400). 정렬이 같은 값끼리는 id 순(페이지가 바뀌어도 순서 고정).
- **목록 조회는 두 단계**(`DefectQuery::list`): 조건·정렬·페이지로 id만 → 그 id들의 행 + 담당자 + 마지막 감지 분석. 범위 밖 페이지는 빈 목록 + 전체 건수(200).
- **필터 요약**(`DefectQuery::summary`): 각 칩의 숫자는 **자기 필터만 뺀** 조건의 건수. (심각도, 상태, 규칙)과 (심각도, 상태, 담당자)로 두 번만 GROUP BY하고 PHP에서 나눠 센다(인덱스 `defects_project_facets`, 마이그레이션 008). 규칙은 건수 많은 순 상위 `ui.page_size_max`개 + 선택한 규칙.
- **코드 조회**(`DefectCode`): 결함의 **마지막 감지 분석** 기준. 스냅샷 full → zip에서 `defects.file_path` 항목(결함 줄 앞뒤 `viewer.code_window_lines`줄 또는 전체), pruned → `defect_excerpts`(excerpt: true, 범위 밖 없음), 둘 다 없으면 `source: none` + `reason`(`snapshot_missing`·`excerpt_missing`·`file_missing`·`binary`·`file_too_large`). 응답은 JSON 텍스트 줄 목록이다(HTML 아님).
  - 경로는 DB 값으로만(`snapshots.storage_path`는 `<숫자>/<숫자>` 형식만 읽는다). 줄 나누기는 시그니처와 같은 `SourceNormalizer::splitLines`(도구 줄 번호와 일치), UTF-8이 아니면 U+FFFD + `content_replaced`.
  - 한도(설정 `[viewer]`, 모두 기본값 있음): `code_window_lines`(200), `code_max_lines`(20000, 넘으면 window + partial), `code_max_line_length`(2000자, 넘으면 자르고 `truncated_lines`), `code_max_file_size`(20MB).
- **규칙 설명 링크는 `Support\RuleLinks` 한 곳**: 대상 `/rules/<도구>/<규칙>`(`syntax-error`는 `/rules/syntax-error`). P22 전에는 `ENABLED = false`라 `path()`가 null이고, 화면은 규칙 키를 텍스트로만 보여 준다. API의 `rule_url`도 이 함수 값이다(JS는 앞에 app-base를 붙인다). P22가 `ENABLED`를 켠다.
- **화면**: 프로젝트 상세의 "결함" 탭(`defects/panel.php` + `pages/defects.js`, 필터 상태는 URL 쿼리), "결함" 메뉴 `/defects`(볼 수 있는 프로젝트 선택 → 같은 탭, 경로 점검 시험의 예외), 결함 상세 `/defects/{id}`(`defects/show.php` + `pages/defect_detail.js`). 빈 상태는 분석 전 / 결함 0건 / 필터 결과 없음(필터 지우기)으로 나눈다.
- **검색 영역의 적용 방식**(P21V, `lib/defect_search.js`): 검색 영역의 입력(검색어·파일·파일 조건·Run 필터·심각도·상태 칩·규칙·담당자·정렬·순서)은 **고르기만** 하고 요청하지 않는다. 맨 아래 오른쪽 "검색" 버튼, 또는 검색어·파일·Run 숫자 입력칸의 Enter로 한꺼번에 적용한다(선택 상자의 Enter는 목록 선택용이라 검색하지 않음). 고른 뒤 아직 검색하지 않았으면 버튼 옆에 "● 조건이 변경되었습니다"(글자, `role=status`). **바로 적용**하는 것은 결과 표 동작(쪽 이동·머리글 정렬)과 필터 지우기·분석 필터 해제·뒤로 가기다. 쪽 이동·머리글 정렬은 마지막 적용 조건에 그 변경만 더하고(고르던 입력은 폼에 남음) 머리글 정렬은 정렬·순서 입력칸도 같은 값으로 맞춘다. 칩 숫자는 마지막 적용 조건 기준이다(정의는 그대로). 주소에는 적용된 조건만 들어가고, 화면을 열면(새로 고침·뒤로 가기·대시보드·히스토리 링크) 주소의 조건이 폼에 채워진다. 배치는 격자(`--filter-col-wide`·`--filter-col-narrow`): 규칙·담당자는 같은 크기의 다중 선택 상자, 규칙·담당자·정렬·순서의 라벨과 입력칸은 같은 줄·같은 위쪽. P18은 상세의 `[data-edit-slot]`에 수정 컨트롤을 넣고 `data-version`을 낙관적 잠금 값으로 쓴다. 목록의 일괄 선택 열도 P18.
- **코드 뷰어**: CodeMirror 6 로컬 번들(`public/assets/vendor/codemirror/`, 빌드 `docs/vendor_build.md`). `lib/code_viewer.js`만 번들을 쓰고, 순수 계산은 `lib/code_view_core.js`(언어 선택, 결함 위치, 안내 문구; Node 시험). 읽기 전용, 줄 번호는 원본 기준, 결함 줄 배경 + 열 범위 물결 밑줄 + 그 줄로 스크롤, 줄 바꿈 토글. 코드는 문서 텍스트로만 넣는다.
- **CSP nonce**: `SecurityHeadersMiddleware`가 요청마다 nonce를 만들어 `style-src`에 넣고 요청 속성 `csp_nonce` → `App::view` → `<meta name="csp-nonce">` → `EditorView.cspNonce`. 스크립트에는 nonce를 쓰지 않는다(`script-src 'self'` 그대로).
- 측정·점검 도구: `tools/load_basic.mjs`(동시 사용자 p95), `tools/check_defects_ui.mjs`(브라우저: CSP 위반·악성 문자열·캡처), `tools/seed_defects.php --snapshots-dir`(코드 스냅샷까지 시드).

## 26. 결함 추적: 담당자·심각도·상태·이력 (P18, 명세 6.2, 6.3, 17)

- **결함 수동 변경은 `Services\Defects\DefectUpdate` 하나로**(자동 전환은 P16 `DefectStore`). 둘 다 `version`을 올린다.
  - `PATCH /api/v1/defects/{id}` {version, assignee_id?, severity?, status?, memo?}: 바꿀 필드만 보낸다(`assignee_id: null` = 해제).
  - 쓰기 트랜잭션(BEGIN IMMEDIATE) 안에서 현재 행을 읽어 version을 비교 → 바뀐 필드만 `UPDATE … version = version + 1 WHERE id = ? AND version = ?` → 필드마다 `defect_history`(manual, changed_by, memo). 결함과 이력이 한 트랜잭션.
  - version이 다르면 409 `version_conflict` + `error.details.current`(최신 결함, 상세 형식). 값이 모두 같으면 아무것도 쓰지 않고 `changed: false`.
  - 상태 전이 제한 없음. 담당자는 `ProjectAccess::assignableUsers`(접근 가능한 활성 사용자)만. 이미 담당인 사용자가 비활성화·접근 상실해도 담당은 그대로이고, 다른 필드만 바꿀 때는 담당자를 다시 검사하지 않는다.
  - 사유: 선택, 앞뒤 공백 제거, 1000자(스키마 CHECK), 줄바꿈·탭 외 제어 문자 거부. 원문 저장, 화면은 textContent.
  - 오류: `invalid_version`·`no_change_fields`·`invalid_severity`·`invalid_status`·`invalid_assignee`·`invalid_memo`(400), `version_conflict`(409).
- **일괄**: `POST /api/v1/projects/{id}/defects/bulk` {items:[{id, version}], changes, memo?}. 모든 항목이 이 프로젝트의 결함이어야 한다(아니면 전체 거부 400 `defect_not_in_project`). 한 트랜잭션에서 결함마다 version을 검사해 `updated`(새 version)·`unchanged`·`conflicts`(최신 값)로 나눠 돌려준다(부분 성공). 최대 `ui.bulk_update_max`(500)건, 넘으면 `bulk_too_large`, 형식 오류 `invalid_bulk`.
- **담당자 후보**: `GET /api/v1/projects/{id}/assignees?q=` — id·로그인 ID·표시 이름만(이메일·역할 없음).
- **담당자 표시**: 결함 응답의 `assignee.active`(비활성)와 `assignee.has_access`(그 프로젝트에 접근 가능, `ProjectAccess::userCanView`). 화면은 "비활성" 또는 "접근 권한 없음" 꼬리표. `DefectQuery`는 요청 안에서 (담당자, 프로젝트) 판정을 캐시한다.
- **내게 지정된 결함**: `GET /api/v1/my-defects`(상태·프로젝트 필터, 상태 순), `…/summary`(상태별·프로젝트별 건수), 화면 `/my-defects`(주 메뉴 `nav.my_defects`). 쿼리는 `ProjectAccess::scopeFor`로 볼 수 있는 프로젝트만. 프로젝트 id를 경로로 받지 않아 경로 점검 대상이 아니다.
- **이벤트**(커밋 뒤, 구독자는 P24): `DefectAssigned`(새 담당자 지정, 해제는 아님), `DefectStatusChanged`(상태 변경). P24는 보내기 전에 담당자의 접근을 다시 확인한다.
- **화면**: 상세의 수정 폼(값이 같으면 저장 비활성, 선택값 배지 미리보기, 사유), 충돌 대화상자(세 필드의 "내가 입력한 값 / 최신 값", 다른 사용자·분석이 바꾼 필드 표시, "최신 값으로 다시 시도" = 최신 기준 + 내가 바꾸려던 필드만 유지, 사유 보존). 목록의 일괄 변경(현재 페이지 선택만, 확인 대화상자, 결과 요약과 충돌 목록). 행 그리기는 `ui/defect_table.js`(목록·내 결함 공용), 순수 계산은 `lib/defect_edit_core.js`.
- 점검: `tools/check_defect_edit_ui.mjs`(두 브라우저 충돌·재시도, 일괄 부분 성공, 비활성 담당자, 자동 resolved 직후 충돌, 내 결함).

## 27. 분석 상태·결과·실패 화면과 오류 카탈로그 (P19, 명세 5.2, 5.3, 8, 15.2)

- **오류·실패 코드는 `Support\ErrorCatalog` 하나로**: 업로드 오류 11종(`UPLOAD`), 분석 실패 13종(`ANALYSIS`). `describe(종류, 코드)` → `{code, known, title, cause, action, guide_key}`. 문구 `failure.<종류>.<코드>.title|cause|action`, 가이드 키는 업로드 `upload.error.<코드>`, 분석 `analysis.failure.<코드>`(P29가 연결). 표는 `docs/error_codes.md`(시험이 일치 검사). 새 실패 코드를 만들면 카탈로그·문구·문서를 함께 고친다(`AnalysisResultTest`가 분석 실행기·워커·반영 코드에서 쓰는 코드를 찾아 대조).
- **분석 API 확장**(`AnalysisService::format`): `failure`(카탈로그), `severity_counts`, `baseline {id, finished_at}`·`first_analysis`(비교 대상 없음), `duration_seconds`, `config_source`, `ignored_configs`, `upload {file_name, warnings, confirmed_at}`(확인하고 진행한 경고), `can_cancel`(queued·running이고 취소 요청 전, 요청자 본인 또는 admin. 서버가 cancel에서 다시 검사). 업로드 상태 API에는 `error`(카탈로그).
- **오류 응답의 공개 값**: `ApiException::details` → `error.details`(4절).
- **화면**: 프로젝트 상세 위쪽 요약(결함 심각도·상태·담당자별 건수, 최근 분석 카드) + 탭 "결함"·"분석"(`#analyses`로 바로 열림, `ui/tabs.js`가 주소 해시와 동기화). 분석 상세 `/analyses/{id}`(분석 → 프로젝트 판정, 보이지 않으면 404)는 상태 배지, 대기 순번, 정보, 안내(취소됨·설정 무시됨·경고 확인·대상 없음으로 전부 해결), 실패 원인·조치·코드와 "다시 업로드", 결과 요약(신규·해결·재오픈 링크, 심각도별, 비교 대상)을 보여 준다. 진행 중이면 3초마다 갱신(`core/poll.js`, 화면이 숨겨지면 멈춤). 실패 재시도 기능은 없다.
  - 순수 계산 `lib/analysis_view.js`(안내 목록, 소요 시간, 링크, 비교 대상), 취소 대화상자 `ui/analysis_cancel.js`.
  - 업로드 화면: queued가 되면 분석 상세로 가는 링크, 실패면 카탈로그의 제목·조치·코드.
- **결함 목록의 분석 변경 필터**: `analysis=<분석 id>&change=new|resolved|reopened`(둘이 함께). 신규 = `first_seen_analysis_id`, 해결 = 그 분석의 자동 이력 `not_detected`·`file_deleted`, 재오픈 = 이력 사유 `redetected`. 분석 통계(`new_count` 등)와 같은 정의다.
- **집계 정의(P20A 사용자 확정)**: 신규 = 처음 감지(`first_seen`) 결함, 재오픈 = 이력 사유 `redetected`. 수동 resolved 뒤 계속 감지돼도 재오픈으로 센다(신규 아님). `still_detected`는 쓰지 않는다(CHECK에는 남아 있음, `docs/db_schema.md` 4.2).
- **비교 기준**: `DefectStore`가 "변경 없음"(no_change) 분석을 건너뛴다(P19 수정, 회귀 시험 `testNoChangeAnalysisIsNeverBaseline`).
- **E2E와 전체 시험**: `php tests/e2e/phase1.php`(격리 환경·실제 워커·HTTP로 11개 시나리오, 끝나면 지움), `php tools/run_all_tests.php`(PHP·JS·아이콘·E2E 요약). `tools/create_admin.php`는 `--config=<ini>`로 격리 DB를 쓸 수 있다.

## 28. 관리자 시스템 관리 (P20A — X10 전 이름 "도구·워커 관리", 명세 5.2, 12, 14, 19.1, 19.3)

**화면 이름과 내부 이름(X10)** — 화면 이름만 바꾸고 식별자·주소는 그대로 둔다(기존 기록·북마크·클라이언트 호환):

| 화면 이름(ko / en) | 내부 이름(그대로) |
| --- | --- |
| 시스템 관리 / System admin(X12에서 영어 단축) | 메뉴·아이콘 `admin.tools`, 문구 `admin_system.*`, 컨트롤러 `AdminSystemPageController`, 탭 `admin/_subnav`, 하위 주소 `/admin/tools`·`/admin/worker`·… |
| 설정 관리 / Settings (시스템 관리의 탭 — X12부터 규칙 설정 바로 뒤) | 주소 `/admin/config-settings`, API `/api/v1/admin/config-settings`, 문구 `config_settings.*`, 아이콘 `admin.settings`(메뉴에서는 빠졌고 범례에만), 감사 `config.update` 등 |
| 시스템 모니터링 / Monitoring (P30, 아직 비활성 메뉴) | 메뉴·아이콘 `admin.system` |

- 메뉴 "시스템 관리"는 첫 탭 분석 도구(`/admin/tools`)를 연다(X12). 탭 순서: 분석 도구 · 워커 · 점검 · Run 관리 · 규칙 설정 · 설정 관리 · 주기 분석 · 백업·복원(`admin/_subnav` 한 곳).
- **X12 통합**: 알림 보관 기간(P24, `app_settings` — `GET/PATCH /api/v1/admin/settings`)과 허용 호스트(P25·P26B, 표 `repo_allowed_hosts` — `/api/v1/admin/repo-hosts`)는 별도 탭 대신 설정 관리의 "알림"·"저장소 연결"(예전 "저장소 연결 시험") 카테고리 탭 안 **구역**이다. 서버가 설정 관리 화면에 두 구역(`admin/_notif_retention`, `admin/_repo_hosts`)을 숨겨 싣고 화면 스크립트가 탭 패널로 옮긴다(`data-merge-into`·`data-merge-pos`). **저장소가 달라 각 구역이 자기 단추·API로 저장**하고(한 트랜잭션 없음), 저장 전 변경 수(`data-dirty` + `config-merge-dirty` 이벤트)는 구역 제목 옆·탭 머리 건수·바깥 탭 이동 확인에 더해진다. 탭 간 검색은 구역의 글자(제목·라벨·호스트)도 찾는다. 옛 주소 `/admin/notifications`·`/admin/repo-hosts`는 `$admin` 판정 뒤 `/admin/config-settings#notification`·`#repository`로 302(비관리자 403). 카테고리 id `repository`는 새 이름과 맞아 그대로.
- **화면**: 관리자 메뉴 "시스템 관리"(`admin.tools`, 아이콘 wrench) → `/admin/tools`(분석 도구), `/admin/worker`(워커), `/admin/checks`(점검, 읽기 전용). **admin만**(라우트 `$admin`, 그룹 관리자·일반 사용자 403, 비로그인 401·로그인 화면). API는 `/api/v1/admin/...`(`docs/openapi.yaml`). 시스템 모니터링(P30)이 점검 화면을 확장한다.
- **웹이 하지 않는 것(완료 기준, `AdminSupportTest::testWebCodePathsDoNotSpawnOrWriteBin`이 정적 검사)**: `bin/`에 쓰기, zip 풀기, 도구 실행·검증(`ToolRegistry::select`·`ensureVerified`), `tools/worker.php` 실행, PID로 종료. 예외는 `WorkerControl::start` 하나(설정의 고정 시작 명령).
- **도구**: `Services\Admin\ToolAdmin`이 P15 레지스트리 함수를 그대로 쓴다(차단 `block`, 해제 `unblock`, 고정 `pin(…, allowVerify: false)` = 이미 검증된·지문이 같은 버전만, 아니면 409 `tool_needs_verification`). 선택 예상은 `ToolRegistry::explain`(검증 없이 기록·지문만 읽음, 이유 pinned·latest·fallback·pinned_unusable·pending·none). **삭제 예정**(마이그레이션 009 `removal_requested_at`)은 차단처럼 선택에서 빠지고, 실제 삭제는 CLI `remove`(지운 행은 `removed_at`으로 이력 유지).
- **관리 작업 큐 `admin_tasks`**(`Services\Admin\AdminTasks`): 웹은 넣기만(`tool_verify '<도구>@<버전>'`, `cleanup_run`, `disk_scan`), 같은 종류·대상의 대기·실행 중 요청은 하나(부분 고유 인덱스 → `merged`). 워커가 분석 슬롯과 따로 **한 번에 하나** 자식 `tools/run_admin_task.php <id>`로 실행(상한 `worker.admin_task_timeout`), 워커 기동 때 running으로 남은 것은 failed(`worker_crashed`). 재검증은 `ToolRegistry::reverify`(도구별 잠금 안, 다른 도구의 분석은 계속).
- **스테이징**(`Services\Admin\ToolStaging`, 표 `tool_staging`): `<work_dir>/tool_staging/<32자리 id>/package.zip`. 전용 순차 청크 업로드(청크 SHA-256, 재전송은 건너뜀, 순서를 건너뛰면 409 `details.next`), 크기 `tools.package_max_size`, 받는 중 2개, `DiskGuard` 사전 검사, complete에서 전체 SHA-256. 배포처 해시가 다르면 설치 명령을 보여 주지 않는다(CLI도 거부). 보관 `tools.staging_retention`(정리 작업 `purgeToolStaging`).
- **CLI 설치·삭제**(`Services\Tools\ToolInstaller`, 서버 관리자 계정): `tools_ctl.php install --staging=<id>`(SafeUnzip·기본 제외 없음 → 레이아웃 `<도구>/<버전>/` + SHA256SUMS + 실행 파일 → 옮기기 전 체크섬 → 덮어쓰기 금지 → bin/으로 이동 → 잠금 안에서 체크섬 + 스모크, 실패하면 bin/에서 다시 뺌 → 결과를 `result.json`과 DB에), `remove <도구> <버전>`(고정·실행 중 분석·마지막 유효 버전 거부, `bin/.trash`로 rename 후 자식 `purge-trash`가 지움). 규칙과 권한은 `docs/tool_setup.md` 6절·5.1.
- **워커 제어**(`Services\Admin\WorkerControl`): 상태는 생존 신호 파일과 큐만 읽는다(**워커 잠금 파일은 잡지 않는다**: 잠깐이라도 잡으면 그 순간 뜨는 워커가 단일 인스턴스 검사에 걸린다). 정지·재시작 = `stop.request` JSON(`kind`, `requested_at`) → 워커는 진행 중인 작업을 끝내고 종료, 재시작이면 종료 코드 **75**(`Worker::RESTART_EXIT_CODE`), 워커 시작 시각보다 오래된 요청은 무시·삭제, 처리한 요청은 종료 때 삭제. `worker.supervised=false`면 재시작 409. 시작 = `worker.start_command`를 `parseCommand`(따옴표만, 셸 문법 없음) → 첫 단어는 절대 경로 또는 허용 이름(schtasks·systemctl·sudo, 고정 시스템 폴더에서 찾음), PHP·worker.php 거부 → `ProcessRunner` 인자 배열, 제한 `worker.start_timeout`, `start.lock` + 성공 뒤 30초 재요청 거부, 결과는 종료 코드와 출력 첫 줄(Redact). 감사에는 성공·종료 코드만.
- **생존 신호 추가 정보**: `node`(검사 결과, 경로 없이 출처만), `last_recovery`, `admin_tasks`, `stop_kind`, `exit`. 웹은 Node를 실행하지 않고 이것을 읽는다.
- **점검**(`Services\Admin\SystemChecks`): 설정(비밀값은 set/unset, 절대 경로·시작 명령·Node 경로는 값 숨김, 기본값은 `config.ini.example`과 비교), 환경(`Support\EnvironmentCheck` — `tools/check_env.php`와 같은 함수, 프로세스 없음, 웹은 bin/ 쓰기 가능하면 경고), 디스크(전체 여유는 매번, 구분별은 `disk_scan` 캐시 `<work>/admin/disk_usage.json` + 정리 대기 dry-run), 정리 작업(`<work>/admin/cleanup_last.json`, 휴지통·cleanup_pending 수), 최근 7일 실패 분석(관리자 조치 사유는 관리 화면 링크).
- **가리기**(`Support\Redact`): `Logger::maskString`(비밀값) + 알려진 폴더 → `<app>`·`<work>`… 이름표 + 남은 절대 경로 → `<path>`. 로그 보기는 `Support\LogTail`(끝에서 64KB씩, 메모리에 전체를 올리지 않음), `scope=worker`는 request_id 없는 줄.
- **감사 로그**: `tool.block|unblock|pin|unpin|reverify_request|mark_removal|unmark_removal`(대상 `tool`, `<도구>@<버전>`, 사유), `tool.package_staged|package_hash_set|package_discarded`(대상 `tool_staging`), `worker.stop_request|restart_request|start_request`, `cleanup.run_request`, `disk.scan_request`, `run.detail_delete`(P21B, 35절), `setting.update`(P21C, 36절), `rules.load`·`rules.reload_request`(P22, 37절), `rules.override_set`·`rules.override_clear`·`rules.override_reset_all`(P22B, 38절), `rules.override_bulk`(P22C, 38절), `export.defects`·`export.rules`(P23, 39절), `config.update`(P27A, 45절 — 키마다 한 줄, 대상 `config`/키). 서버 경로·명령 문자열은 넣지 않는다.

## 29. 대시보드 (P20, 명세 9) — 집계 정의

- **범위**: 모든 집계는 사용자가 볼 수 있는 프로젝트만(`ProjectAccess::scopeFor`, admin = 전체). 보이지 않는 프로젝트는 이름·건수 어디에도 없다. `project_id` 쿼리는 `requireView`(없거나 보이지 않으면 404, 정수가 아니면 400). 로그인한 모든 사용자, 화면 `/dashboard`(주 메뉴 "대시보드"). 로그인 후 첫 화면은 그대로 프로젝트 목록(`Navigation::HOME`, P11 결정).
- **열린 결함** = 상태가 `resolved`가 아닌 결함. 상태별 건수는 전체 상태, 심각도별은 열린 결함. `DefectQuery::summary`(결함 목록의 필터 요약)와 같은 정의다(시험이 프로젝트별 합으로 일치 검사). 내게 지정된 결함은 `DefectQuery::mineSummary` 재사용(열린 = 전체 − resolved).
- **최근 7일 분석**: `finished_at`이 7일 안인 succeeded·failed·cancelled. **마지막 분석이 실패한 프로젝트**: 마지막으로 끝난 분석(성공·실패, 취소 제외. 변경 없음 성공 포함)이 실패인 프로젝트, 최근 순 5개, 실패 사유 제목은 `ErrorCatalog`.
- **추이**: 프로젝트별 최근 `ui.dashboard_trend_count`(10)건의 **성공 분석(no_change 제외)**, 값은 분석 행에 저장된 `total_count`·`severity_counts`(**분석 시점 기준**, 이후 수동 상태 변경으로 다시 계산하지 않음. 화면에 표시). 프로젝트를 정하지 않으면 가장 최근에 성공 분석한 보이는 프로젝트 5개. **변경 표식** = 직전 성공 분석(no_change 제외)과 도구·도구 버전 문자열(ESLint는 Node 버전 포함)·설정 출처·설정 해시 중 하나라도 다름(`Services\Analysis\AnalysisComparison::changedBetween`/`differences`, P21도 이것을 쓴다). 값이 null인 항목은 비교하지 않고, 첫 분석은 표식 없음. **P20R**: 점마다 `run`(Run 번호), 값은 Run 요약(그 Run에 존재한 결함, 없으면 감지 건수), 가로축·표는 "Run N", 점·표 링크는 그 Run 시점의 결함 목록(`?run=N`). 데이터 표지에 Run 번호가 붙은 분석 수를 더했다(되채움이 번호·요약만 써도 캐시가 바뀜).
- **규칙별·파일별 상위 `ui.dashboard_top_n`(10)**: 열린 결함 기준. 범위 "전체(보이는 프로젝트)" 또는 프로젝트 하나. 전체 범위의 규칙은 **(도구, 규칙 키)**로 합친다(`syntax-error`는 ruff·ESLint 따로). 파일은 (프로젝트, 경로)별(같은 경로라도 프로젝트가 다르면 따로, 프로젝트 이름 포함). 동률은 규칙 키(→ 도구)·경로(→ 프로젝트 id) 바이트 순으로 안정 정렬. 항목의 `link`는 결함 탭 쿼리(`/projects/{id}?rule=…|file=…&status=open,in_progress,postponed,intentional,not_fix`); 전체 범위 규칙은 건수가 가장 많은 프로젝트로. 파일 링크는 접두사 필터라 같은 접두사의 다른 파일(`a.py`와 `a.pyi`)도 함께 나올 수 있다(가정, P17 필터 그대로).
- **언어·도구별**: 프로젝트의 언어는 하나(스키마 CHECK: `python` 또는 `javascript`=JS/TS)라 python → ruff, javascript → ESLint로 묶는다(언어 "둘 다"인 프로젝트는 없다). 프로젝트 수(분석함), 열린 결함·심각도별, **최근 성공 분석 기준** 도구 버전 분포(프로젝트 수).
- **빈 상태**(화면): 프로젝트 없음(admin) / 볼 수 있는 프로젝트 없음 / 분석 전 / 결함 0건. 응답은 0과 null을 구분한다(건수는 0, 모르는 값만 null — 예: 분석 전 프로젝트의 추이는 `state: no_analysis`, 실패만 있으면 `failed_only`).
- **계산과 캐시**: 조회 시 계산. 결함 집계는 project_id부터 **인덱스 열 순서대로 GROUP BY**해 커버링 인덱스만 읽는다(`defects_project_facets`(008), 파일은 `defects_project_file_status`(010)). 파일 순위는 SQL에서 상위 N만 뽑고 그 N개의 심각도만 따로 센다. 도구는 결함 행이 아니라 프로젝트 언어에서 정한다. 캐시는 `dashboard.cache_ttl`(기본 60초, 0이면 끔), `<work_dir>/cache/dashboard/<키>.json`. **키 = SHA-256(구역, 인자, 보이는 프로젝트 id 집합, 데이터 표지)** — 데이터 표지는 끝난 분석 수·마지막 끝난 시각, `defect_history` 마지막 id, 프로젝트 수·마지막 변경 시각(모두 PK·작은 표). 그래서 분석 반영·결함 변경(이력)·프로젝트 변경은 TTL을 기다리지 않고 다음 요청에 반영되고, 접근 범위가 바뀌면 키가 달라진다. 사용자마다 다른 값(내 결함)은 캐시하지 않는다. "새로 고침"은 캐시를 우회하지 않는다. 오래된 캐시 파일은 50번에 한 번 쓰기 때 정리. 측정과 근거는 `docs/results/P20.md`(결함 50만 건 전체 5구역 261ms, 동시 100명 p95 351ms. 캐시를 끄면 3.7s).
- **화면**: 구역(요약·추이·규칙·파일·언어/도구)마다 API를 따로 불러 그리고, 실패한 구역에만 "다시 시도". Chart.js 4(로컬 번들 `public/assets/vendor/chartjs/chart.js`, 선·막대만 등록), CSP 그대로(인라인 스타일 속성 없이 CSSOM만, `unsafe-inline` 없음, 브라우저 위반 0건 확인). 팔레트는 `tokens.css`의 `--chart-*`(Okabe–Ito) 한 곳, 심각도는 선 모양(점선 패턴)·점 모양도 달리 하고 변경 표식은 마름모 전용. 모든 차트에 요약 `aria-label`과 "표로 보기"(같은 데이터, 키보드 버튼, `aria-pressed`). 라벨·이름·경로는 캔버스에 글자로 그리거나 `textContent`(HTML 해석 없음). 규칙 키는 링크가 아니다(`RuleLinks::ENABLED`, P22). 순수 계산 `lib/dashboard_core.js`.

## 30. Run(분석 회차)과 Run별 결함 현황 (P20R, 사용자 결정 2026-10-05)

용어: **분석 = 과정**(대기·실행 중·실패·취소, 분석 탭·취소 버튼·분석 상세의 상태), **Run = 성공한 분석의 결과 회차**(프로젝트별 Run 1, 2, …).

| 화면·자리 | 표기 |
| --- | --- |
| 성공한 분석의 이름(분석 목록·상세 제목·프로젝트 요약 "최근 분석") | `Run N · 분석 #id` |
| 실패·취소·대기·실행 중, 되채움 전 | `Run —(실패)` 등 + `분석 #id` |
| 결함 처음 발생·마지막 감지, 목록의 "마지막 감지" 열, 자동 이력 사유 | `Run N`(그 Run 시점의 결함 목록 링크) · `분석 #id` |
| 분석 결과의 비교 대상 | `Run N(분석 #id, 직전 Run)` |
| 결과 요약 링크 필터 안내(P19 `analysis`+`change`) | `Run N(분석 #id)에서 새로 감지된 결함만…`(주소의 `analysis=<id>`는 그대로) |
| 대시보드 추이 가로축·툴팁·표 | `Run N`(점·표 → `?run=N`) |
| 코드 출처 | `Run N 시점의 코드` |

- **Run 번호** `analyses.run_number`: 성공한 분석에만, 분석 반영(`DefectStore`)이 성공을 기록하는 **같은 쓰기 트랜잭션**에서 `RunTracker::nextRun` = 그 프로젝트의 성공 분석 수 + 1(쓰기 트랜잭션은 `BEGIN IMMEDIATE`로 직렬화되어 중복·건너뜀 없음, 유일 인덱스 `analyses_project_run`). 실패·취소는 NULL이라 번호가 건너뛰지 않는다. `no_change` 성공(P25)도 Run이다.
- **존재(열림 구간)** `defect_runs(defect_id, project_id, from_run, to_run)`: 결함이 그 Run에서 감지됐거나 **분석 범위 밖이라 직전 상태를 유지**했으면 존재. 범위 안 미감지·파일 삭제는 존재하지 않음(`to_run` = 직전 Run으로 닫음), 다시 감지되면 새 구간. 판정은 P16 `DefectStore`의 분류를 그대로 옮긴다(코드를 바꾸지 않음): 신규 → 구간 시작, 자동 resolved(not_detected·file_deleted) → 닫기, 재오픈(redetected) → 새 구간, 유지 → 쓰지 않음. **P16이 이력을 남기지 않는 경우** — 수동 `resolved`인 결함이 이번에 감지되지 않음: 같은 분류(범위 안 미감지·파일 삭제면 존재 끝, 범위 밖이면 유지)로 구간만 닫는다(상태·이력은 그대로). 수동 resolved인데 계속 감지되면 존재(P16은 이를 redetected로 재오픈한다). 줄·메시지는 Run별로 저장하지 않는다(결함 표의 마지막 감지 값, 화면에 "최신 감지 위치").
- **Run 종료 표지** `analyses.run_history_mark`: 반영 트랜잭션 끝의 `MAX(defect_history.id)`. 자동 이력은 그 Run에 속하고(같은 트랜잭션), 수동 이력은 커밋 순서(id)로 정확히 갈린다(같은 초에 일어나도). 되채움은 시각으로 근사한다(아래).
- **과거 Run N의 값 복원**: 결함·필드(status·assignee·severity)마다 **표지보다 큰 첫 이력의 `old_value`**, 없으면 현재 값(`RunService::restoreSql`, 인덱스 `defect_history_field(defect_id, field, id)`). 심각도 변경도 이력이 있으므로 같은 방식으로 복원한다.
- **최신 Run** = `run` 생략 또는 최신 번호: 지금처럼 **결함 표 현재 값** 그대로(필터·건수·링크 모두 기존과 같음). 최신 Run 보기는 해결되어 더는 존재하지 않는 결함(상태 resolved)도 목록에 남는다(기존 동작). 그래서 Run 선택기의 "존재 결함 N건"(그 Run에 존재)과 최신 목록 건수는 해결된 결함만큼 다를 수 있다. 최신 Run의 복원 결과 = 현재 값(시험 고정).
- **과거 Run**: 그 Run에 존재한 결함만 + 복원 값. 필터(상태·담당자는 복원 값 기준)·검색·정렬·페이지는 최신과 같은 의미(같은 `DefectQuery`에 원본 부분 질의만 바꿔 끼움). **P20S 최적화**: 필터·정렬이 상태·담당자·심각도를 쓰지 않으면(`DefectFilter::usesRunValues()` 거짓) 복원 없는 존재 집합(`RunService::memberSource`)으로 고르고 세어 그 페이지 행만 복원한다(결과가 전체 복원과 같음을 `RunIntervalsTest`가 모든 필터·정렬·페이지 조합에서 대조). 필터 없는 요약은 저장된 Run 요약. **읽기 전용**: 화면은 선택 열·일괄 변경·수정 폼을 그리지 않고 안내(판정은 요약 API의 `run_view.read_only` 한 곳). 쓰기 API(`PATCH`, bulk)는 `run`을 받지 않는다.
- **Run 요약** `analyses.run_summary`: Run 종료 때 그 Run에 존재한 결함의 상태별·심각도별·담당자별 건수(JSON). 이후 수동 변경으로 갱신하지 않는다. Run 목록·대시보드 추이 값이 이것이다(없으면 = 되채움 전, 분석의 감지 건수). `severity_counts`(감지 건수, 분석 결과 화면)와는 정의가 달라 둘 다 둔다 — 범위 밖으로 존재를 유지한 결함이 있을 때만 값이 다르다.
- **결함 상세의 `run`**: 그 Run에 존재하지 않았으면 404가 아니라 **200 + `run_view.in_run=false`**(화면은 "이 Run에 존재하지 않습니다 + 존재한 Run 구간 링크"). 없는 id는 404. **코드**: 그 Run 이하에서 결함이 마지막으로 존재한 Run의 스냅샷(정리됐으면 마지막 감지 분석의 발췌, `note=excerpt_latest`), 과거 Run이면 `note=latest_location`, 한 번도 없었으면 `source=none, reason=not_in_run`.
- **입력 검증**: `run`은 1~999999999 정수(그 밖 422 `invalid_run`), 이 프로젝트에 없는 번호 404 `run_not_found`. 보이지 않는 프로젝트는 run과 관계없이 먼저 404(라우트의 `RequireProjectAccess`).
- **되채움** `tools/backfill_runs.php`(`RunBackfill`): 성공 분석을 종료 시각(같으면 id) 순으로 번호, 구간은 first_seen과 자동 이력(not_detected·file_deleted → 닫기, redetected → 열기)으로, 표지는 "그 Run까지의 자동 이력 + 그 Run 종료 시각 이전 수동 이력"의 최대 id(근사). 이력이 없는 수동 resolved 미감지는 마지막 감지 Run에서 닫는다(근사), 그 뒤 재감지는 이어진 구간으로 남는다(근사). 되채움 전 프로젝트는 번호만 붙고(`RunTracker::ready` = 번호 없는 성공 분석이 없음) 구간은 건드리지 않으며 화면은 "되채움 필요" 안내. 멱등(다시 실행하면 대상 없음).
- **Run 비교는 제거되었다(2026-10-05 사용자 결정, P20S).** 화면·API·메뉴·권한 함수가 없다. `history.scope_change_ratio` 설정은 P21(히스토리)이 쓸 예정이라 남겼다.
- **인접 Run과 분석 집계(P19 신규·해결·재오픈)의 관계**(P21의 "직전 Run 대비"가 쓴다): 분석 N의 "신규"는 Run N−1에 없고 Run N에 있는 결함, "해결(자동 resolved)"은 Run N−1에 있고 Run N에 없는 결함이다. 존재 집합의 차이와 다른 경우: ① 재오픈은 P19에서 따로 세지만 존재 기준으로는 "새로 생김"(직전 Run에 없었음), ② 수동 resolved 결함이 미감지되면 P19 해결 수에는 없지만 존재는 끝남, ③ 범위 밖 유지는 둘 다 변화 없음.
- **화면**: Run 선택기(`ui/run_selector.js`, 결함 탭·결함 상세 위) — 최신순 `<select>`(항목: `Run 7 (최신) · 시각(KST) · 존재 결함 N건 · 신규·해결 · 버전/설정 변경`), 마우스는 고르면 이동, 키보드는 화살표로 고르고 Enter, 주소 `?run=N`(최신이면 run 없음 = 기존 링크), Run이 하나면 읽기 전용 글자. 과거 Run 안내 "Run 3 시점 보기(읽기 전용) — 최신 Run 7로 이동"(`ui.read_only` 눈 아이콘 + 글자). 순수 계산 `lib/run_core.js`.

## 31. WAL 체크포인트 정책 (P20S)

- 분석 반영 연결(분석 자식 프로세스, `AnalysisFinalizer`)에서만 `Support\WalCheckpoint::disableAuto()`로 자동 체크포인트를 끈다. 체크포인트는 워커가 시작할 때와 분석이 끝날 때마다 `WalCheckpoint::run()`(PASSIVE, WAL이 `database.wal_truncate_threshold` 초과 + 한가하면 TRUNCATE). 실패해도 경고 로그만(분석 결과 무관), 다음 기회에 다시.
- 웹·CLI·다른 연결의 기본 체크포인트, 저널 모드(WAL), `busy_timeout`은 바꾸지 않는다. 측정·근거는 `docs/results/P20S.md`. P16 `update_seen`(감지된 결함 전부 갱신)은 P19C에서 없앴다(44절 — 읽는 값은 같다). 체크포인트 순서도 P19C에서 PASSIVE 우선으로 바뀌었다(44절).

## 32. 히스토리와 분석 비교 (P21, 명세 8) — 정의

- **화면 역할**: 프로젝트의 "분석" 탭(P19)은 진행·취소·실패 처리, "히스토리"(`/projects/{id}/history`, 주 메뉴 → 프로젝트 선택 `/history`)는 시간 흐름과 변화 해석. 같은 표를 두 번 만들지 않는다: 히스토리 이력 표의 Run 이름은 분석 상세로, 화면 위 버튼은 분석 탭으로 간다. 프로젝트 상세 머리에 "히스토리" 버튼(탭은 늘리지 않음).
- **비교 기준** = 그 분석의 **직전 성공 분석**(`analyses.baseline_analysis_id`, P19가 반영 때 저장. `no_change` 분석은 기준이 아님). 실패·취소는 대상도 기준도 아니다. 첫 성공은 "비교 대상 없음"(모든 결함이 신규). 비교 API는 성공이 아니면 409 `not_succeeded`.
- **건수**(반영이 저장한 값 = 결함 목록 `analysis`+`change` 필터 = 비교 집계, `HistoryTest`가 고정): 신규 = `first_seen`이 이 분석, 해결 = 이 분석의 자동 이력 `not_detected`·`file_deleted`, 재오픈 = 이 분석의 `redetected`, 유지 = `unchanged_count`(감지 − 신규 − 재오픈). 수동 resolved는 해결 수에 없다. **범위 밖 유지** = 그 Run에 존재한 결함(Run 요약 total) − 이번 감지(total_count). Run 요약이 없으면(되채움 전) null.
- **해결 목록**은 "이 분석에서 해결된 결함"이다. 그 뒤 재오픈되어 지금 열려 있는 결함도 들어 있고, 목록의 상태 열은 현재 상태다(화면에 안내).
- **변경 판정**: `AnalysisComparison::differences(기준, 이 분석)`(P20 대시보드 추이·Run 목록과 같은 함수, 시험으로 세 경로 일치 확인). 근거 항목(도구·도구 버전·설정 출처·설정 해시의 이전 → 이후)을 돌려준다. 바뀌면 주의 문구 키 `history.warn_tool_change`.
- **범위 변화**: 두 분석의 `analysis_file_lists.file_count` 차이 비율(|B−A| / max(A,B))이 `history.scope_change_ratio`(기본 0.5) **이상**이면 `history.warn_scope_change`("분석 범위가 달라졌습니다(파일 N → M)"). 파일 수가 없으면 판단하지 않는다(scope null).
- **이력 행**의 열린 결함 = 그 Run 요약 total − by_status.resolved(그 Run 종료 시점), 증감 = 기준 Run과의 차이.
- **카드·상위 항목 링크**: 결함 탭의 `analysis=<id>&change=…`(+규칙·파일). P20R의 `run=N`은 그 Run에 존재한 결함만 보이므로 "해결"(그 Run에 없는 결함)에는 쓸 수 없어, 세 카드 모두 같은 규칙(`analysis`+`change`, 현재 표)으로 통일했다. 필터 안내 문구는 "Run N(분석 #id)".
- **결함 전체 이력**(`GET /defects/{id}/timeline`, 결함 상세 "전체 이력 보기" 창): 발생(처음 감지한 Run) → 수동 변경("Run N 이후" = Run N 표지보다 뒤의 이력) → 자동 해결·재오픈(그 분석의 Run) → 사라짐(이력 없이 존재가 끝난 구간 = 다음 Run). 순서는 시각이 아니라 Run 흐름(같은 Run 안: 발생 → 자동 → 사라짐, 그 다음 "Run N 이후" 수동). `defect_history`·`defect_runs`만 읽는다.
- **결함 목록 Run 필터**: `from_run=N`(처음 감지가 Run N), `in_run_from`·`in_run_to`(존재 구간이 [N, M]과 겹침 = 그 사이 Run 중 한 번이라도 존재, 한쪽만이면 그 Run 하나). 신규·`from_run`은 `defects_first_seen` 인덱스로 먼저 고른다.
- **정적 파일 버전**(P21 0절): 템플릿은 `Assets::url()`만 쓰고 주소는 `/assets/v/<묶음 버전>/<경로>`. 묶음 버전 = `public/assets` 전체 내용 해시(`Support\AssetVersion`, 파일 목록·크기·수정 시각 서명이 같으면 캐시 파일 `<work_dir>/cache/assets_version.json`의 값). 경로에 버전이 들어 있어 ES 모듈의 상대 import·JSON 모듈도 같은 버전 경로로 풀린다(모듈 그래프 전체가 함께 바뀜). 응답은 `Http\StaticAssets`(세션 없이 `App::handle` 맨 앞): 버전이 맞으면 `public, max-age=31536000, immutable` + ETag, 옛 버전이면 지금 파일 + `no-cache`, 경로 이탈·없는 파일·허용 밖 확장자는 404. 화면 HTML은 `no-cache`, API는 `no-store`.
- **공통 UI**: 긴 셀 말줄임·툴팁 `ui/clip.js`(경로는 앞부분만 줄이고 파일명:줄 유지, 마우스·키보드 포커스, Esc, `[data-tip]`은 항상), 차트 ↔ 표 `ui/chart_table.js`(표는 그 차트의 제목 바로 아래), 내용 창 `ui/dialog.js`의 `contentDialog`.

## 33. 결함 표 열·표 머리글·차트 모양 (P21U)

- **결함 표 열**(결함 메뉴 = 프로젝트 결함 탭, 내 결함. 같은 행 코드 `ui/defect_table.js`): 심각도, 상태, 규칙, 파일:줄, 메시지, 담당자, **Run**, **최근 감지**(내 결함은 맨 앞에 프로젝트). P21U 전에는 "최근 감지" 한 칸에 Run 번호와 전체 일시가 함께 있었고, 이를 두 열로 나눴다.
  - **Run** = 마지막으로 감지한 분석의 Run 번호(`last_detected_run`). 마지막 감지 분석은 항상 성공 분석이다(실패·취소·진행 중 분석은 결함을 감지·갱신하지 않음). Run 번호가 없으면(되채움 전) "—". 값은 그 분석의 히스토리 비교 패널 링크(`/projects/{id}/history?analysis=<id>`).
  - **최근 감지** = 그 분석의 종료 날짜 `yy-mm-dd`(표시 시간대, 기본 KST). 전체 일시(초까지)는 공통 툴팁(`data-tip`). 날짜 변환은 `core/time.js`의 `formatShortDate` 한 곳. 결함 상세·히스토리의 시각 표기는 바꾸지 않았다.
  - **과거 Run 보기(run=N)의 한정 규칙**: 결함 표에는 최신 감지만 있어 Run N 시점의 마지막 감지를 복원할 수 없다. 그 결함은 Run N에 존재했으므로 마지막 감지 Run이 N보다 크면 **N으로 한정**한다("Run N 이하의 마지막 감지", 날짜도 Run N 분석의 종료 시각). 서버(`DefectQuery::lastDetected`)가 한정한 값을 주고, 화면은 한정된 행의 Run 툴팁에 한 줄로 알린다. 한계: N 이전에 범위 밖 유지로 감지되지 않은 Run이 있었어도 N으로 보인다. 기존 `last_seen`은 최신 값 그대로다.
  - **정렬**: 목록 API `sort=run`(Run 번호), `sort=last_seen`(종료 시각, P21U 전에는 분석 id 순). 둘 다 마지막 감지 분석을 PK로 읽는 상관 부분 질의이고 과거 Run 보기에서는 `MIN(값, N의 값)`으로 같은 한정을 한다. 같은 값은 id 순. 허용 목록 밖 값은 400 `invalid_sort`. 화면은 정렬 상자와 함께 **열 머리글 버튼**(다른 열 → 그 열 오름차순, 같은 열 → 순서 뒤집기, `aria-sort`와 ▲▼)으로 고른다. 내 결함 표는 기존대로 정렬 고정(상태 → 최근 감지)이다.
- **표 머리글**: 모든 표(`.table`)의 `th`는 한 줄(`white-space: nowrap`, `components.css` 한 곳). 넓으면 줄바꿈 대신 `.table-scroll` 상자만 가로 스크롤한다. 데이터 셀 규칙은 표마다 그대로다.
- **결함 표 열 너비**(P21V에서 바꿈, `components.css` 한 곳): 짧은 열은 내용 너비(`width: 1%`)로 줄이고 남는 너비는 **파일:줄과 메시지가 1:1**로 나눈다(최소 너비도 둘 다 23.5rem). 규칙 열은 최대 210px(P21U 180px). 두 열의 말줄임 글은 `contain: inline-size`로 긴 값이 열을 넓히지 않게 한다. 최소 너비는 사용자 결정(2026-10-05 "파일명:줄 보임 우선")으로 실제 데이터의 가장 긴 파일명:줄(약 312px)과 줄인 폴더 표시(…)가 들어가는 값이라, 1280px 창에서도 결함 표 상자만 가로 스크롤한다(페이지는 스크롤 없음). 경로가 칸보다 길면 앞부분(폴더)을 먼저 줄이고 그래도 길면 파일명:줄 끝을 `…`으로 줄인다(전체 값은 툴팁). 내 결함 표도 같은 규칙이다.
- **결함 표 화면의 최소 너비**(사용자 결정 2026-10-05): 앱 본문 최소 너비는 1280px(명세 1 데스크톱 전용)이지만 결함 표가 있는 화면(`body:has(.defects-table)`)만 풀어 1024px 창에서도 페이지 가로 스크롤이 생기지 않고 표 상자만 스크롤한다.
- **차트 모양**(`ui/chart_style.js`, 대시보드 추이·상위 10, 히스토리): 범례 표식은 글자 크기(12px) 안(`boxHeight` 8 → 표식 8√2 ≈ 11.3px, 시리즈 선 굵기는 범례에서 1px), 항목 간격 12px. 상위 10 막대는 파스텔 채움 `--chart-bar` + 진한 테두리 `--chart-bar-border`와 막대 끝 건수 글자(색에만 의존하지 않게). 대비 기준(테두리:배경 3:1, 글자 4.5:1)은 `tests/js/defect_table.test.mjs`가 토큰 값으로 계산해 확인한다. 앱에 다크 테마는 없다(라이트 값만, 사용자 결정 2026-10-05).

## 34. 규칙 기본 심각도 (P21S, 사용자 결정 2026-10-05)

- **매핑**: 규칙 키 → 기본 심각도(5단계 그대로). 데이터는 `resources/severity/<도구>.json`(사람이 고침, `mapping_version`)과 `<도구>.catalog.json`(도구가 주는 규칙 목록·분류군, `tools/severity_coverage.php --write-catalog`가 만듦). 코드에 매핑을 두지 않는다. 기준과 전체 표는 `docs/severity_mapping.md`.
- **우선순위**: 개별 규칙 > 접두사(가장 긴 것, ruff 접두사가 글자로 끝나면 다음 글자가 숫자일 때만) > 분류군 기본(ruff `category`, ESLint `meta.type`) > not_defined. 규칙 키 null·`syntax-error`는 각 도구의 `syntax-error` 항목.
- **적용**: `Services\Severity\SeverityMap`(앱 객체당 한 번 읽음). 분석 반영(`DefectStore`)은 이번 결과의 규칙 종류만 매핑해 임시 표 `temp.rule_severity`에 두고 신규 INSERT가 조인한다(결함마다 계산·조회 없음). 설정 `severity.auto_assign`(기본 true)이 false면 매핑을 넘기지 않는다. 매핑 파일이 틀리면 워커 시작 때 `SeverityMapException`으로 멈춘다.
- **자동/수동**: `defects.severity_source` none·auto·manual(마이그레이션 012). 분석은 **신규 결함에만** 값을 넣고(출처 auto, 이력 없음 — 생성 시점 값이라 과거 Run 복원과 맞다), 재감지·유지·재오픈 결함의 심각도·출처는 바꾸지 않는다. 사람이 바꾸면(`DefectUpdate`, 단건·일괄, not_defined 포함) manual이 되고 그 뒤 어떤 자동 처리도 바꾸지 않는다.
- **재적용**: 분석은 기존 결함을 다시 계산하지 않는다. 기존 결함 채움·매핑 변경 반영은 `tools/severity_apply.php`(CLI 전용, 기본 미리보기, `--apply`, `--reapply-auto`는 auto만)로만 한다. 변경마다 자동 이력(field severity, `reason_code = rule_default`, 메모 "규칙 기본 심각도 적용(매핑 <도구> v<버전>)", 분석 없음)을 남겨 과거 Run 보기가 적용 전 값(not_defined)을 복원한다. 이를 위해 012가 `defect_history`의 CHECK를 넓혔다(표 다시 만들기, `docs/db_schema.md` 3.9).
- **과거 Run의 출처**: `RunService::restoredValues`가 그 Run 표지까지 사람이 심각도를 바꾼 이력이 있으면 manual, 아니면 복원한 심각도가 not_defined면 none·그 밖은 auto로 정한다(manual은 수동 이력으로만 생기므로 정확하다).
- **화면**: 결함 목록 심각도 배지에 출처 툴팁(manual은 작은 "수동 변경" 글자도), 결함 상세 심각도 옆 "규칙 기본값"/"수동 변경", 일괄 변경 영역·확인 창 안내 한 줄, 전체 이력의 `severity_default` 항목("Run N 이후", 변경한 사람 "시스템(규칙 기본값)"). 새 열은 없다. 대시보드·필터·칩·요약은 코드 변경 없이 새 값을 센다(저장된 과거 Run 요약은 그 Run 시점 값 그대로).

## 35. Run 상세 보관과 관리자 삭제 (P21B, 사용자 결정 2026-10-05)

- **상세 삭제 = 표식만**: Run마다 따로 저장된 것은 `analyses`의 Run 번호·종료 표지·Run 요약뿐이다(`docs/results/P21B.md` 0절). 존재 구간(`defect_runs`)·결함 이력·결함 표는 다른 Run의 보기와 결함 전체 이력이 함께 쓰므로 **지우지 않는다**. 삭제는 `analyses.run_detail_deleted_at/_by/_reason`(마이그레이션 013)만 채우고, Run 번호·종료 표지·Run 요약(건수)은 히스토리 표·대시보드 추이를 위해 그대로 둔다. 그래서 용량은 거의 줄지 않는다(VACUUM도 자동으로 하지 않음, 수동 절차는 `docs/runs_retention.md`).
- **자동 보관**: `runs.detail_keep`(기본 15, 0 = 끔, 1·2는 설정 오류)개를 넘으면 오래된 Run부터 `retention`으로 표시한다. 워커가 **성공한 분석 반영·체크포인트 뒤**에 `Services\Runs\RunDetails::retain`을 부른다(한 번에 `runs.retention_batch`개, 남으면 다음 분석에서 이어서). 실패·취소 분석은 부르지 않는다. 수동 삭제된 Run은 보관 개수에 세지 않는다(이미 상세 없음). 최신 Run은 언제나 남는다(트랜잭션 안에서 다시 확인). 실패해도 분석 결과에는 영향 없음(경고 로그).
- **수동 삭제**: admin만(`$projectAdmin` = 접근 검사 → admin 검사, 보이지 않는 프로젝트 404). `RunDetails::deleteManual` — Run 번호 정수 1~100개(`invalid_run_set` 400), 없는 Run `run_not_found` 404(details.runs), 최신 Run 포함 `run_latest_not_deletable` 409(아무것도 바꾸지 않음), 한 트랜잭션, 이미 삭제된 Run은 건너뛰고 `already`로 알린다. 화면(관리자 → 시스템 관리 → Run 관리)은 프로젝트 이름을 입력해야 확인 단추가 켜지는 대화상자(`ui/dialog.js` `typedConfirmDialog`, 취소가 기본 포커스)를 거친다.
- **감사 로그**: `run.detail_delete`(대상 `project`, details `{runs, reason: manual|retention, already?}`). 자동 보관의 행위자는 `Actor::system()`(사용자 없음).
- **삭제된 Run의 동작**: 과거 Run 보기(`run=N` — 결함 목록·요약·상세·코드 API) **410 `run_detail_deleted`**(details `run`, `latest`), 결함 화면(`/defects/{id}?run=N`)도 410 안내 화면, 결함 목록 화면은 표 대신 안내 + 최신 Run 링크. Run 선택기는 보이되 고를 수 없음("상세 삭제됨"). 히스토리 표는 번호·건수·도구 버전·변경 배지를 그대로 보이고 "상세 삭제됨" 표시, 비교 패널은 건수만 보이고 카드 링크를 끈다. 전체 이력은 "Run N(상세 삭제됨)". 결함 표 Run 열은 링크를 유지하고 툴팁으로 안내(`last_detected_detail_deleted`). 대시보드 추이 점은 계속 나오고 누르면 이동 대신 안내. 대시보드 캐시는 데이터 표지(`dataStamp`)에 삭제 수를 넣어 바로 바뀐다.
- **불변**: 삭제된 Run이 아닌 다른 Run의 보기·히스토리 표·추이·결함 이력은 삭제 전후 같다(`RunDetailsTest`; 다른 값은 표시용 `last_detected_detail_deleted`뿐). Run 번호는 재사용·재번호 매김을 하지 않는다. 분석 행은 지우지 않는다(아래 절).

## 36. 화면 보완: 화면 설정·대시보드 필터·콤보박스·Node 상태 갱신·업로드 확인·상단 바 (P21C)

- **화면 설정 저장소**(`Services\AppSettings`, 마이그레이션 014 `app_settings`): admin이 화면에서 바꾸는 전체 공통 표시 설정. 허용 키·형식·범위·기본값은 `AppSettings::SCHEMA` 한 곳, 행이 없으면 기본값. API `GET/PATCH /api/v1/admin/settings`(admin만, CSRF), 감사 `setting.update({key, from, to})`, 같은 값이면 쓰지 않음. 014 전 DB(표 없음)는 읽기 기본값·쓰기 503 `settings_unavailable`. 운영·보안 한도는 계속 `config.ini`(이 표에 두지 않는다).
  - 키 `dashboard.trend_projects`(정수 1~50, 기본 5 = P20부터의 고정값): 대시보드 "프로젝트별 추이"에서 프로젝트를 고르지 않았을 때 보여 줄 프로젝트 수(최근 성공 분석 순, 변경 없음 제외). 대시보드 캐시 키에 들어간다.
- **대시보드 언어 필터**(`language=python|javascript`, 그 밖은 전체): `DashboardService::trend`·`top('rules')`가 **보이는 프로젝트를 먼저 언어로 거른 뒤**(범위 판정은 그대로 `visibleProjects`) 집계한다. 개수 제한은 필터 뒤. 고른 프로젝트의 언어가 다르면 빈 결과(오류 아님). 파일별 상위·요약·언어별 도구 구역은 대상이 아니다(화면에 범위 안내). 주소 `?language=`로 유지, 캐시 키에 언어 포함.
- **콤보박스**(`ui/combobox.js` + 계산 `lib/combobox_core.js`): `comboboxFromSelect(select)` — 기존 `<select>`를 숨긴 채 값의 원천으로 두고(고르면 `select.value` + `change`), 입력칸(role=combobox)과 목록(role=listbox)을 씌운다. 목록에 있는 값만 고른다(입력 글자는 값이 아님), 포커스하면 전체 목록, ↓↑ Home End Enter Esc, 한국어 조합 중 Enter는 확정하지 않음, 지우기 = 전체(빈 값). 숨긴(hidden) 선택지는 목록에서 빠지고 `refresh()`로 다시 읽는다. 이 단계에서는 대시보드의 선택 상자 4개(언어·추이 프로젝트·규칙 범위·파일 범위)에만 쓴다.
- **Node.js "상태 갱신"**: 웹은 node를 실행하지 않는다(P20A 원칙 유지 — 웹(Apache)과 워커의 실행 계정·PATH가 다를 수 있다). `POST /api/v1/admin/tools/node/refresh`가 워커 폴더에 `node_check.request`(요청 시각·요청자만)를 쓰고, 워커가 루프에서 보면 **설정 파일을 다시 읽어**(`Worker::reloadConfigFrom`, tools/worker.php가 정함) 고정 명령 `[node, --version]`(시간 제한 5초)으로 확인한 뒤 생존 신호의 `node`(checked_at, request_at, reason: node_not_found·node_not_runnable·node_timeout·node_too_old, config_error)를 바로 쓴다. 화면은 `request_at`이 같아질 때까지 최대 10초 기다린다(넘으면 "응답 없음"). 10초 안의 재요청은 같은 요청(merged). 워커가 멈췄으면 409 `worker_not_running`(마지막 확인 결과 포함). ruff·ESLint 버전은 이미 "재검증"(관리 작업 tool_verify)이 있어 따로 두지 않았다.
- **점검 탭 상태 색**: 토큰 `--status-icon-ok/warn/fail`(녹색·주황·빨강, 흰 배경 대비 3:1 이상), `.status-cell[data-status]`. 아이콘 모양(badge-check·badge-alert·badge-x)과 글자(정상·경고·문제)가 함께 있다. 적용: 점검 탭 "환경" 표(Node 행 포함). 다른 탭의 상태는 이미 배지(색 배경 + 테두리 + 아이콘 + 글자)라 그대로.
- **언어 배지 너비**: 토큰 `--lang-badge-width`(5.5rem), `.lang-badge`는 언어와 관계없이 같은 폭·가운데 정렬(프로젝트 표 등 배지를 쓰는 모든 곳).
- **업로드 확인**: 해제(SafeUnzip)·사전 검사가 이미 구하는 값과 조립 때 스트리밍으로 구한 `uploads.sha256`을 그대로 쓴다(검사를 두 번 하지 않음, 해시 열 추가 없음). `extract.json`과 `uploads.summary`(014 — 분석이 성공하면 업로드 폴더가 바로 지워지므로 DB에도 남김)에 확장자별 대상 수(`TargetScanner::scan`), 대상에서 뺀 업로드 ESLint 설정 수, 직전 업로드 비교를 더하고, `GET /api/v1/uploads/{id}`가 `summary`로 숫자만 돌려준다(경로·폴더 이름 없음). **직전 업로드** = 같은 프로젝트·같은 사용자의 업로드 중 분석 대기열에 들어간(status queued) 가장 최근 것(분석이 끝나지 않았어도). 해시가 같으면 경고 `same_as_previous`(위험 아님) → 기존 확인 단계(계속/취소)로 간다 — 막지 않는다. 암호화된 항목은 `invalid_zip`(사유 encrypted)으로 손상과 구분해 알린다.
- **상단 바(외부 IP 접속 보고)**: 원인은 IP가 아니라 화면 폭이었다 — 1279px 이하에서 메뉴 글자를 숨기는 규칙(P12)이 화면 배율 125~150%인 PC(CSS 폭 1024~1093px)에서 켜졌다. 1279px 이하에서는 주 메뉴를 둘째 줄로 내려 글자를 그대로 보이고(필요하면 메뉴 안에서 줄바꿈), 899px 이하에서만 아이콘만 남긴다. 준비 중 메뉴의 툴팁에도 메뉴 이름을 넣었다. 정적 파일 주소·CSP·쿠키는 Host와 무관하다(`docs/results/P21C.md` 8절).

## 37. 규칙 설명 (P22, 명세 11장)

- **데이터**: 설치된 도구가 내놓는 정보만(`Services\Rules\RuleExtractor` — ruff `rule --all`·`check --show-settings`, ESLint meta를 읽는 `tools/severity/eslint_rules.cjs`). 런타임 네트워크 없음, 지어낸 설명 없음. 출처·판정 규칙·보강 자료 형식은 `docs/rules.md`.
- **저장**(마이그레이션 015): `rule_versions`(도구·버전, `is_active` = 기준 버전, `baseline` = 가장 오래된 적재 버전), `rules`(버전별 규칙 + 기본 심각도·근거 + 내용 해시), `rules_fts`(FTS5 trigram, 외부 내용 표), `rule_changes`(인접 버전 변경 내역, 적재 때 계산).
- **적재**: `Services\Rules\RuleLoader`(활성 버전 + 아직 적재하지 않은 설치 버전, 내용 해시로 멱등, 한 도구가 실패해도 나머지 적재). CLI `tools/rules_load.php`, 자동은 관리 작업 `rules_load`(워커가 시작 때·`rules.load_interval`마다·도구 고정/차단/재검증 뒤 요청, 자식 프로세스에서 실행 — 분석을 막지 않음). admin 수동 `POST /api/v1/admin/rules/reload`.
- **조회**: `Services\Rules\RuleQuery` — 기준은 `is_active` 버전. 목록에서는 결함을 세지 않고, 상세의 열린 결함 수·프로젝트 이름은 `ProjectAccess::scopeFor` 범위만. 활성 버전에 없는 규칙(예전 결함)은 404가 아니라 마지막으로 있던 버전·삭제/이름 변경 안내(`missing`, `fate`), 한 번도 적재되지 않은 키만 404.
- **검색**: 3글자 이상은 `rules_fts MATCH '"…"'`(입력을 따옴표 구절 하나로 — 색인 구문으로 해석되지 않음), 그 미만은 키·이름·요약 `LIKE`(와일드카드 이스케이프).
- **화면**: `/rules`(검색 영역은 결함 검색 P21V 규칙 — 정한 뒤 검색, 주소에는 적용 조건만, 단일 선택은 콤보박스·기본 심각도는 칩; 표는 고정 열 너비로 가로 스크롤 없음), `/rules/{tool}/{rule…}`(설명은 `lib/markdown.js` 안전 부분집합, 변경 내역 표, 결함 목록 링크 `rule=`), `/rules/syntax-error`(특수 키).
- **언어 탭**(X08): `/rules`는 "Python · ruff"와 "JavaScript/TypeScript · ESLint" 두 탭(머리글에 활성 버전의 총 규칙 수, meta `rules`)이며 도구 필터·도구 열은 없다. 언어 ↔ 도구 대응은 서버 `Support\ToolLanguage`, 브라우저 `lib/tool_language.js` 한 곳씩(대시보드·업로드도 같은 상수, `ToolLanguageTest`가 둘이 같은지 검사). 주소는 `?language=python|javascript&…`(현재 탭의 조건만). 첫 탭은 주소 → 예전 주소의 `tool=`(→ language로 `replaceState`, 둘이 어긋나면 language) → 이 브라우저의 마지막 탭(localStorage `pjg.rules.language`) → Python. 탭을 바꾸면 페이지를 다시 불러오지 않고 새 탭의 기본 조건으로 목록만 갱신(`pushState`, 뒤로 가기가 탭과 조건을 되돌림, 마지막 응답만 반영). 기준 버전 줄·최신 아님 안내·빈 목록 안내는 탭의 도구 기준. API는 `language`를 `tool`로 바꿔 받고 둘이 어긋나면 400. 상세 주소(`/rules/{tool}/{rule}`)는 그대로이고 상세 위에는 링크 탭(이 규칙의 언어 `aria-current`, 다른 탭은 그 언어의 목록), "목록으로"는 그 탭의 마지막 조건(sessionStorage `pjg.rules.search.<language>`). **탭은 표시 필터일 뿐 권한 판정에 쓰지 않고, 프로젝트 언어로 자동 전환하지 않는다.** 규칙 설명 목록을 내보내는 기능(P23)을 만들 때는 탭(언어)을 반영한다.
- **규칙 링크**: `Support\RuleLinks::ENABLED = true`. 결함 상세·결함 표 규칙 열이 규칙 설명으로 간다. 결함 행의 `rule_missing`(활성 버전에 없음)이면 표시(아이콘 + 글자·툴팁). 대시보드 상위 10 규칙 링크는 기존대로 결함 목록.
- **감사 로그**: `rules.load`(적재 결과 요약, 자동은 system), `rules.reload_request`.

## 38. 관리자 규칙 켜기·끄기 (P22B, 명세 5·11·12장)

- **저장**(016): 파일(`config/ruff.default.toml`, `config/eslint.default.json`)은 기본값, DB `rule_overrides`는 관리자가 바꾼 차이만(기본값과 같아지면 행 삭제). 도구별 개정 번호 `rule_override_state.revision`. 켤 수 없는 규칙은 `rule_enable_blocks`(적재 때 버전별로 다시 씀). 판정·저장은 `Services\Rules\RuleOverrides` 한 곳.
- **실효 설정 만들기**(한 곳, `Services\Tools\EffectiveRuleConfig`): 같은 입력이면 같은 출력(정렬·중복 제거·글자 검사).
  - ruff: 작업 폴더에 `extend = "<기본 파일>"` + `[lint] extend-select / ignore` 파일을 만들어 `--config`로(층마다 적용이라 개별 코드가 기본의 접두사 선택·ignore를 이김 — 실측). 프로젝트가 ruff 설정을 올렸으면 적용하지 않는다.
  - ESLint: 변경이 있을 때만 `-c tools/eslint/pjg.flat.overrides.config.cjs`(기본 설정 스크립트를 그대로 읽고 맨 뒤에 `rules` — 코어 규칙은 모든 대상 파일, `@typescript-eslint` 규칙은 플러그인을 등록한 TS 파일 설정에만, 켜기 수준 `error`). 이 ESLint에 없는 규칙·타입 정보 규칙 켜기는 스크립트가 건너뛴다(안전망).
- **분석 연결**: `ToolAnalysisRunner`가 도구를 고른 직후 `RuleOverrides::snapshotFor(도구, 버전)`을 한 번 읽어 러너에 넘긴다(그 분석 동안 고정 — 큐 대기 중 변경은 반영, 시작한 분석은 그대로). 그 버전 규칙 데이터에 없는 규칙·켤 수 없는 규칙은 건너뛰고 경고 로그. 스모크 테스트는 변경 없이(기본 설정) 돈다.
- **설정 해시**: 변경이 없으면 P15 계산 그대로(`RuffRunner::configHash`, `EslintRunner::configHash` — 시험으로 고정). 변경이 있으면 변경 집합(+ ESLint는 변경용 스크립트 해시)을 더한다 → 다음 분석에서 `AnalysisComparison`의 "설정 변경". 끈 규칙의 결함은 다음 분석에서 감지되지 않아 기존 규칙대로 resolved.
- **동시성**: 요청의 `expected_enabled`(지금 실효값)가 다르면 409(`details.current`). 변경은 트랜잭션 하나(상태 확인 → 행 쓰기·지우기 → 개정 번호 → 감사).
- **접근 통제**: 변경·되돌리기·목록은 admin만(라우트 `$admin`, 401·403·CSRF). 읽기(규칙 목록·상세의 `analysis_enabled`·`overridden`·`enableable`·`analysis`)는 로그인한 모든 사용자, 변경자·사유는 admin 응답에만. 서버 전체 설정이다(프로젝트·사용자 단위 없음, 탭·프로젝트 언어와 무관).
- **화면**: 규칙 목록 "분석 적용" 열·조건, "관리자 변경" 조건·배지(`ui.changed` 아이콘 + 글자 — 같은 "설정 변경" 개념), 상세 "분석 적용" 구역(admin 버튼·확인 창·켤 수 없는 이유), 시스템 관리 → 규칙 설정(`/admin/rule-settings`, 목록·되돌리기·도구별 전체 되돌리기 — 도구 이름 입력, 한 번에 `rules.override_reset_max`건).
- **감사 로그**: `rules.override_set`(도구·규칙·이전·이후·기본값·사유), `rules.override_clear`, `rules.override_reset_all`(건수, 규칙 100개까지).
- **목록에서 바로 변경(P22C)**: admin에게만 서버가 선택 열·이 쪽 전체 선택·일괄 막대를 그리고(`data-admin`), "분석 적용" 칸에 스위치(`role="switch"`)를 둔다. 스위치는 상세와 같은 확인 창(`ui/rule_override_dialog.js`)·같은 단일 API를 쓰고 그 행만 다시 그린다(창을 열기 전에 상세 API로 최신 상태를 읽어, 이미 바뀌었으면 창 없이 다시 그림). 켤 수 없는 꺼진 규칙은 스위치 비활성 + 이유(툴팁·화면 읽기 설명).
- **일괄 변경(P22C)**: 대상 = 현재 쪽에서 고른 키(`ui.page_size_max`개까지 — 서버가 각 키를 활성 버전과 대조, 없는 키·다른 도구 키는 "활성 버전에 없음", 특수 키는 `special_key`로 건너뜀) 또는 검색 결과 전체(마지막으로 적용한 조건을 서버가 `RuleQuery::matching`으로 다시 조회). 대상은 탭의 도구 하나.
  - 미리보기(쓰지 않음) → 확인 창(바뀔 수·그대로·건너뜀 이유별·예시 10개·서버 전체 열린 결함 수, 검색 결과 전체이거나 바뀔 수 20개 초과면 도구 이름 입력) → 적용.
  - 판정은 단일과 같은 `RuleOverrides::decide`(켤 수 없는 규칙은 일괄에서는 422가 아니라 건너뜀), 쓰기도 같은 `write`.
  - 적용은 한 트랜잭션(전부 되거나 전부 안 됨), 대상은 트랜잭션 안에서 다시 정하고, 개정 번호는 바뀐 것이 있을 때 한 번만 +1. 미리보기 뒤 개정 번호가 바뀌었으면 409 `rule_override_conflict`(`details.reason = revision_changed`) → 화면은 알리고 다시 미리보기.
  - 상한 `rules.bulk_max`(대상 수) — 넘으면 미리보기·적용 모두 400 `bulk_too_large`(잘라서 적용하지 않음).
  - 감사 `rules.override_bulk`(도구·동작·대상 방식과 조건 요약·바뀐/그대로/건너뜀(이유별) 건수·개정 번호 전→후·사유·바뀐 규칙 키 100개까지 + 나머지 수). 일괄에서는 개별 `rules.override_set`를 남기지 않는다.
- **규칙 키 접두사(P22C)**: 조건 `prefix`(키가 그 값으로 시작, 키 글자만 64자, ruff는 대문자·ESLint는 소문자로 맞춰 `substr` 비교). 검색어(부분 일치·설명 포함)와 별개이고 다른 조건과 조합된다(예: ruff `D` 계열 중 꺼진 것 = `prefix=D&analysis=0`).

## 39. 내보내기 (P23, 명세 14)

- **API**: `POST /api/v1/projects/{id}/defects/export`, `POST /api/v1/my-defects/export`, `POST /api/v1/rules/export`(POST + CSRF만, GET 없음). 본문 `{format: xlsx|csv, …목록 조건}` — 알 수 없는 키는 400. 결함은 라우트의 `RequireProjectAccess`(보이지 않는 프로젝트 404), 내 결함은 `mineWhere`(볼 수 있는 프로젝트만), 규칙은 로그인한 누구나(결함 수 등 프로젝트 정보 없음). 컨트롤러 `ExportController`.
- **같은 질의·같은 값**: 조건은 목록 API와 같은 검증기(`DefectFilter::fromQuery`, `RuleQuery::filter`, `RunService::resolve` — 삭제된 Run 410). 결함은 목록과 같은 원본·조건·정렬로 id만 모두 고르고(`DefectQuery::exportIds` — 정수 배열이라 5만 개도 작음) 행은 1,000개씩 목록의 `rowsByIds`(같은 변환·과거 Run 덮어쓰기)로 읽는다(`rowsForExport`). 규칙은 `RuleQuery::list`를 1,000개씩. 라벨은 화면과 같은 문자열 키(`icon.severity.*`, `icon.defect_status.*`, `rules.tool.*`). 한 번의 내보내기는 읽기 트랜잭션 하나(같은 시점의 결과).
- **판정 순서**(파일을 만들기 전에 모두 JSON 오류로): 형식(400 `invalid_export_format`) → 조건(400) → 접근(404)·Run(410) → 동시 실행(429 `export_busy`, 서버 `max_concurrent`·사용자 `max_per_user`, 잠금 파일) → 건수(0건 409 `export_empty`, 상한 초과 413 `export_too_large` + details count·limit — 잘라서 주지 않음) → 생성(시간 503 `export_timeout`, 메모리 503 `export_failed`, 500행마다 확인, 쓰던 파일 삭제). 성공만 파일 응답.
- **작성기**(`Services\Export`, 새 패키지 없음): `CsvWriter`(BOM·CRLF·RFC 4180), `XlsxWriter`(ZipArchive + XMLWriter — 시트 XML을 임시 파일에 스트리밍, 고정 부분과 함께 zip). 주입 방지는 `SheetText` 한 곳: CSV는 `= + - @ 탭 CR LF`로 시작하면 `'` 접두, XLSX는 모든 문자열을 인라인 문자열 셀로만 쓰고 수식 셀(`<f>`)을 만들지 않는다. XML 금지 제어 문자 제거, 셀 32,767자 한도.
- **응답**: `Response::file`(Content-Type, `Content-Disposition` ASCII + `filename*`, `Cache-Control: no-store`, `X-Export-Rows`; nosniff는 보안 헤더 미들웨어) — 보낸 뒤(연결이 끊겨도) 임시 파일을 지운다. 임시 파일·잠금 `<work_dir>/export/`.
- **화면**: 표 바로 위 한 줄(`.table-head` — 건수 왼쪽, `partials/export_bar` 오른쪽 맞춤, Excel 주 버튼·CSV 보조, `action.export` 아이콘). `ui/export_buttons.js`가 **적용된 조건**(결함 `apiQuery(state)`, 규칙 `bulkFilter(applied)` + 정렬, 내 결함 상태·프로젝트)을 보낸다 — 폼의 미적용 변경은 보내지 않고 "마지막으로 검색한 조건으로 내보냅니다", 과거 Run이면 "Run N 기준". 0건이면 버튼 꺼짐(이유 툴팁), 삭제된 Run 안내 화면에는 없음. 진행 중 두 버튼 꺼짐·`aria-busy`(연타해도 요청 하나), 파일 이름은 응답 헤더에서, 오류는 코드별 안내.
- **감사**: `export.defects`·`export.rules`(형식·행 수·조건·Run, 데이터 값 없음). 거부는 남기지 않는다. 운영 조정·임시 파일은 `docs/export.md`.

## 40. 앱 안 알림 (P24, 명세 17)

- **종류·받는 사람**(코드값 고정, 문구는 화면이 `kind` + `params`로 만든다 — 저장된 문장·HTML 없음):
  `analysis_done`(분석 요청자 — 성공, 신규·해결·재오픈·Run·변경 없음), `analysis_failed`(요청자 — 실패·취소·시간 초과, 상태·실패 코드, 가이드 키는 조회 때 `ErrorCatalog`),
  `defect_assigned`(새 담당자 — 사람이 지정, 자기 지정 포함, 해제는 없음), `defect_changed`(현재 담당자 — 사람이 상태·심각도를 바꿈, 본인 변경 포함),
  `analysis_summary`(그 프로젝트에 열린 담당 결함이 있거나 이번 분석이 그 사람의 담당 결함을 자동 전환한 사용자, 요청자 제외 — 프로젝트 신규·해결·재오픈 + 내 담당 결함의 해결·재오픈.
  프로젝트 전체 변화가 0이면 없음). 시스템의 자동 해결·재오픈은 결함마다 알리지 않고 요약 건수로만 전한다. 요청자가 없는 분석(주기 분석)은 건너뛴다(P25~에서 정책).
- **생성은 한 곳**: `Services\Notifications\NotificationService::emit(kind, recipients, refs, params, dedupeKey)`. 받는 사람을 ① 그 프로젝트를 볼 수 있는 **활성** 사용자로 거르고
  (`ProjectAccess::usersWithAccess`) ② 종류별 끄기(`notification_prefs`)를 빼고 ③ 한 문장 배치 INSERT OR IGNORE(200명씩). 예외는 삼키고 경고 로그 "알림 생성 실패(본 동작에는 영향 없음)".
- **발생 지점 = 이벤트 구독**(`App::events()`가 처음 만들 때 구독 — 분석 자식·워커·웹 요청 어디서든 같은 규칙). 모두 **본 동작 커밋 뒤**에 보낸다:
  `AnalysisSucceeded`(AnalysisFinalizer — `assignedChanges` 포함, 담당자 목록은 `defects_project_assignee_id` 인덱스로 담당자가 있는 행만), `AnalysisFailed`(run_analysis.php 자식의 실패 기록,
  워커의 타임아웃·자식 비정상 종료·시작 실패, WorkerRecovery의 worker_crashed), `AnalysisCancelled`(대기 중 취소 API, 실행 중 취소를 워커가 기록), `DefectsChanged`(DefectUpdate 요청마다 하나 —
  단건·일괄 같은 서비스, 바뀐 결함·이력 id). 분석 종료 알림은 상태를 DB에서 다시 읽으므로 이벤트가 겹쳐도 한 건이다.
- **중복 방지 키**: `analysis_done:{분석}`·`analysis_failed:{분석}`·`analysis_summary:{분석}`(사용자별 유일이라 사용자를 키에 넣지 않음), `defect_assigned:h{이력 id}`·`defect_changed:h{이력 id}`
  (그 받는 사람 몫 수동 이력 중 가장 작은 id — 단건·일괄 모두). 일괄 변경은 받는 사람마다 1건(`count`, 한 건일 때만 `defect_id`), 같은 요청에서 지정 알림을 받는 사람에게는 변경 알림을 보내지 않는다.
- **조회·접근**(`NotificationInbox`): 모든 쿼리가 `user_id = 로그인 사용자`(admin도 남의 알림 없음). admin이 아니면 볼 수 있는 프로젝트 id 목록(`scopeFor`)을 먼저 구해 JSON 매개변수로 거른다 —
  접근을 잃은 프로젝트의 알림은 목록·개수·읽음·삭제에서 없는 것과 같은 404. 프로젝트 이름은 보여 줄 때 조회, 이동 경로(`href`)는 서버가 만든다:
  성공 `/projects/{p}?run=N`, 실패·취소 `/analyses/{id}`, 요약 `/projects/{p}?run=N`, 결함 한 건 `/defects/{id}`, 일괄 `/my-defects`.
- **API**: `GET /api/v1/notifications`(kind, unread=1, page/size), `GET …/unread-count`, `POST …/{id}/read|unread`(멱등), `POST …/read-all`(내 모든 알림), `DELETE …/{id}`, `DELETE …/read`,
  `GET·PUT …/preferences`. 감사 로그 없음(대량·개인 동작). 017 전 DB: 개수 0(`available: false`), 나머지 503 `notifications_unavailable`.
- **헤더**: 주 메뉴 "알림"(`nav.notifications`) 항목에 배지(숫자 글자, 0 숨김, 99+)와 미리보기 패널(`#notif-panel`, 최근 8건·모두 읽음·모두 보기). `ui/notification_bell.js`:
  스크립트가 있으면 링크를 버튼(`aria-expanded`, `aria-controls`)으로 바꾸고, 없으면 `/notifications` 링크로 동작. 개수는 열 때 한 번 + 탭이 보이는 동안 `[ui] notification_poll_seconds`(60, 최소 15)마다
  (`core/poll.js`, 숨기면 멈춤·보이면 즉시, 실패 시 이전 값 유지·간격 최대 4배). 요청 헤더 `X-PJG-Background: 1` → `SessionMiddleware`가 세션 활동으로 치지 않는다(열어 둔 탭이 유휴 만료를 막지 않게).
  웹소켓·장시간 연결 없음.
- **화면**: `/notifications` — "알림 목록"(P21V 방식: 종류 콤보박스·안 읽은 알림만 → 검색, 주소에 조건, 항목 클릭 = 읽음 + 이동, 항목별 읽음/안 읽음·삭제, 모두 읽음, 읽은 알림 모두 삭제 확인 창)과
  "알림 설정"(종류별 `role=switch`, 즉시 저장·`role=status`, 기본값으로). 관리자 "시스템 관리 > 설정 관리 > 알림" 탭의 "알림 보관 기간" 구역(X12 — 옛 주소 `/admin/notifications`는 이동)이 `app_settings` 3개 값을 `PATCH /api/v1/admin/settings`로 바꾼다(감사 `setting.update`).
- **보관 정리**: `NotificationService::prune($now, $batch)` — 읽은 지 `retain_read_days` 넘음 / 만든 지 `retain_unread_days` 넘은 안 읽음 / 사용자별 `max_per_user` 초과분(읽은 것 중 오래된 것부터, 그다음 안 읽은 것)
  을 단계마다 최대 1,000건. 워커가 시작 때와 1시간마다(많이 남으면 1분 뒤) 분석과 별도로 부르고, 실패는 경고 로그, 지운 수는 INFO 로그 "알림 보관 정리"(감사 없음). 설정은 정리 때마다 다시 읽는다.

## 41. 소스 저장소 연동 (P25, 명세 18)

- **패키지** `Services\Repo`: `RepoInput`(입력 검증·정규화 — http/https만, 사용자 정보·쿼리 금지, 브랜치·하위 경로·P4PORT·depot 경로, 제어 문자는 자르기 전에 거부),
  `HostPolicy`(허용 목록 + 이름 풀이 + 주소 판정, 판정한 주소를 돌려줘 고정), `AllowedHosts`(목록 CRUD·감사), `SecretBox`(AES-256-GCM, 키 식별자·이전 키),
  `RepoConnections`(연결 저장·조회 — 비밀은 여기서만 복호화, view(admin/일반)), `VcsSandbox`(격리 폴더·환경 변수), `VcsProcess`(ProcessRunner 감싸기 — 시간 초과·취소·크기 감시·마스킹),
  `GitClient`·`PerforceClient`(+`P4Marshal`), `RepoTools`(ToolRegistry로 체크섬 검증된 실행 파일 선택), `RepoImport`(워커 쪽 가져오기 단계), `RepoService`(웹 쪽 연결 테스트·지금 가져와 분석),
  `RepoLinks`(커밋 링크). 컨트롤러 `RepoController`.
- **흐름**: "지금 가져와 분석"(admin) → 분석 행(queued, source_kind git|perforce, trigger user, 요청자, source_ref·source_url, work_dir NULL) → 기존 큐·워커 →
  분석 자식(`tools/run_analysis.php`)이 도구 실행 전에 `RepoImport::run`: 연결·허용 호스트·도구·디스크(해제 상한 × 2) 확인 → **작업 디렉토리를 먼저 analyses.work_dir에 기록** →
  `<work>/fetch/<분석 id>`에서 Git(bare 얕은 복제 → `archive` zip) / Perforce(`-G changes` → `-G print` → 파일 → zip) → **업로드와 같은 SafeUnzip**(최상위 폴더 유지) →
  대상 0개면 `repo_empty` → 출처(source_ref = 실제 브랜치, source_revision = 커밋/changelist, fetched_at) 기록 → 이후는 업로드 분석과 같은 실행·반영(AnalysisFinalizer).
  실패는 `AnalysisOutcome::failed(<repo 코드>)` + `AnalysisFailed`(P24 알림). 가져오는 중 취소 요청이면 기록하지 않고 끝내고 워커가 cancelled로.
- **시간**: 워커가 자식의 시간 상한을 `analysis.timeout + 가져오기 시간`(연결별 재정의 또는 `repository.fetch_timeout`)으로 둔다. 가져오기 명령마다 남은 시간으로 ProcessRunner 타임아웃.
- **정리**: 자식 정상 종료 = 임시 폴더 삭제(finally). 취소·시간 초과로 트리를 끝내면 워커 `finishAnalysis`가 DB에서 다시 읽은 work_dir와 `fetch/<id>`를 지운다(git 객체는 읽기 전용이라 권한을 준 뒤).
  워커가 죽으면 다음 기동의 `WorkerRecovery`가 실행 중이 아닌 분석의 `fetch/<id>`를 모두 지운다.
- **웹 요청에서 git·p4**: 연결 테스트만(admin, `repository.test_timeout` 30초, 사용자당 분당 `repository.test_per_minute` 6회 — 잠금 파일 + 시각 목록). 결과는 200 + `{ok, code}`.
- **접근**: 프로젝트 라우트는 `RequireProjectAccess`(보이지 않으면 404)가 먼저, 변경·테스트·가져오기는 `RequireAdmin`(403). 일반 사용자 view는 상태·출처 이름(호스트/경로, depot 경로)·마지막 커밋만.
- **커밋 링크**: `RepoLinks` — `analyses.source_url`(사용자 정보 없음)의 경로가 `/{owner}/{repo}(.git)`이고 호스트가 GitHub 호환(github.com 자동, 그 밖은 허용 목록 표시)일 때만
  `…/{owner}/{repo}/commit/{hash}`. 화면은 형식을 다시 확인하고 새 탭·`rel="noopener noreferrer"`(ui/source_cell.js). 분석 목록·상세·히스토리·Run 목록 API에 `revision`·`link`.
- **화면**: 프로젝트 화면 "소스 저장소" 상자(출처·상태·마지막 가져오기·마지막 커밋·다음 예정(P26 자리), admin "지금 가져와 분석" + 끈 이유), 프로젝트 편집 "소스 저장소"(유형별 입력·평문 경고·
  자격 증명 설정됨/교체/삭제·연결 테스트·해제), 설정 관리 "저장소 연결" 탭의 허용 호스트 구역(X12), "분석 도구"에 git·p4 읽기 전용.
- **P26B 허용 호스트 연결 테스트**: `HostProbe`(새 네트워크 코드는 여기만 — 이름 풀이·허용 판정 `HostPolicy::inspect` → TCP → TLS(인증서 확인은 검증 없이 받아 직접 판정 + 신뢰는 따로 검증 연결) → HTTP 상태 줄만), `HostTests`(등록된 행만·전체 상한·분당 제한 공유·결과 저장·감사). `POST /api/v1/admin/repo-hosts/{id}/test`, `…/test-all`.
- **P26B 심볼릭 링크**: 저장소 출처만 링크를 건너뛰고 경고(`analyses.source_warnings`) — Git은 해제 전에 zip에서 링크 항목 제거(`RepoImport::dropSymlinks`, `SafeUnzip::isSymlinkEntry`로 판정), Perforce는 symlink 유형을 쓰지 않음. 업로드 zip 규칙·안전 해제기 규칙은 그대로.
- 자세한 보안 모델·키 관리·적용 순서는 `docs/repo_integration.md`.

## 42. 주기 분석 (P26, 명세 18.2·18.3·18.5)

- **패키지** `Services\Schedule`: `ScheduleCalc`(일정 계산 — 순수 함수, KST 고정), `ScheduleService`(설정 저장·조회·켜기 조건·연결 해제 시 자동 끔·공개 요약),
  `ScheduleTicker`(워커의 주기 작업), `ScheduledAnalysisLauncher`(도래 → 분석 등록의 **유일한 호출 지점**, P27 "같은 커밋이면 생략"은 43절),
  `ScheduleRetry`(가져오기 재시도), `ScheduleOutcomes`(분석 종료 이벤트 구독 → 요약·관리자 알림), `ScheduleStatus`(관리자 현황 조회 — 화면과 분리).
- **스케줄러 = 워커의 주기 작업**: 새 프로세스·서비스 없음. 워커 루프가 `scheduler.tick_seconds`(60초)마다 `ScheduleTicker::tick` — `next_run_at <= 지금`(부분 인덱스
  `project_schedules_due`)을 최대 `scheduler.max_per_tick`(50)개. 프로젝트마다 연결·디스크 확인(쓰기 잠금 밖) → 쓰기 트랜잭션 하나에서 다시 읽기·도래 계산·결과
  (`queued`/`skipped_busy`/`skipped_disk`/`skipped_no_connection`)·`schedule_ticks` 기록·`next_run_at` 갱신. (project_id, due_at) 유일 + BEGIN IMMEDIATE + 다시 읽기로
  여러 워커·재시작에도 같은 도래는 한 번. 오류는 그 틱만 멈추고 경고 로그. 결과는 생존 신호의 `scheduler`(마지막 틱 시각·건수).
- **큐 등록**: "지금 가져와 분석"과 같은 함수 `RepoService::enqueue`(실행 주체 schedule, 요청자 NULL). 같은 큐·FIFO·동시 2·프로젝트당 1.
- **일정**: N일(1~365) + KST HH:MM. 켜거나 간격·시각을 바꾸면 "바꾼 시점 이후 가장 가까운 지정 시각". 도래 처리는 앵커(next_run_at) + kN일 계열에서 지금 이전의 가장 최근 1회만
  (워커가 꺼져 밀린 일정은 한 번만 보충), 다음은 그 + N일(항상 현재 이후).
- **재시도**(명세 18.3 "연속 3회 실패하면 그 회차 중단"): 주기 분석의 가져오기가 일시적 오류(`repo_unreachable`·`repo_timeout`·`repo_fetch_failed`)로 실패하면 분석 자식이
  결과를 기록하지 않고 **같은 행을 queued로 되돌린다**(retry_count + 1, retry_after = 지금 + `repository.connect_retry_interval`, retry_code, work_dir 비우고 폴더 삭제).
  `AnalysisQueue::claimNext`는 retry_after 전의 행을 건너뛴다(그동안 다른 작업이 먼저, sleep 없음). `repository.connect_max_attempts`(3)번째도 실패면 failed 1건으로 마감.
  상태 전이 추가는 running → queued 하나. 인증·호스트·도구·설정·대상 0개 등은 재시도하지 않는다. 사용자가 누른 가져오기는 재시도하지 않는다.
- **결과·알림**(`ScheduleOutcomes`, 커밋 뒤 이벤트, 실패는 경고 로그만): 저장소 출처 분석 성공 → 연속 실패 0(사용자 실행 포함). 주기 분석 실패 → 연속 실패 + 1, 활성 admin 전체에
  `schedule_failed`(admin 전용 종류 — `NotificationService::ADMIN_KINDS`, 설정·목록·개수에서도 admin에게만). 같은 연속은 `schedule_failed:{프로젝트}:{첫 실패 분석}` 한 건에
  횟수·오류·다음 예정·시각만 갱신(`emit(..., refresh: true)` — 읽음 유지). 디스크·연결 건너뜀은 도래마다 한 건(실패 횟수 아님). 완료 알림 없음, `analysis_summary`는 P24 그대로.
- **접근**: 설정 `GET/PUT /api/v1/projects/{id}/schedule`은 `RequireProjectAccess` 다음 `RequireAdmin`. 일반 사용자는 `GET /repo`의 `schedule`(사용 중·다음 예정)만.
  현황 `GET /api/v1/admin/schedules`·화면 `/admin/schedules`(시스템 관리 탭 — 시스템 모니터링 화면이 생기면 `ScheduleStatus`를 그대로 옮긴다).
- **019 전 DB**: 스케줄러 틱·결과 반영·공개 요약은 조용히 건너뛰고, 설정·현황 API는 503 `schedule_unavailable`.
- 자세한 운영 규칙은 `docs/repo_integration.md` 9절.

## 43. 성공(변경 없음) (P27, 명세 18.2·19.10)

- **표현**: 상태 값은 그대로 `succeeded`, 플래그 `analyses.no_change = 1`(001). Run 번호·통계·`run_summary`·스냅샷·결함 변경·알림이 없다. API는 `unchanged`·`unchanged_check_count`·
  `unchanged_last_checked_at`·`unchanged_base`(그 리비전을 분석한 분석 id·Run)·`force_analysis`를 더한다(기존 소비자 호환).
- **판단**: `Services\Repo\UnchangedSourceCheck::evaluate(project, force)` → `{decision: changed|unchanged, reason: forced|no_base|connection_changed|tool_or_config_changed|revision_differs|check_error|same, …}`.
  원격 최신 리비전은 `RepoHead::lookup`(연결 테스트 `RepoService::test`도 이것을 쓴다 — 새 실행 경로 없음). 분석 조건 키는 `Services\Analysis\AnalysisEnvKey`(실제 저장소 분석은 가져오기 직후 같은 함수로 `env_key`·`source_key` 기록).
  불확실하면(조회 실패·예외·키 없음) 분석한다.
- **호출 지점**: 주기 = `ScheduleTicker`(트랜잭션 밖 판단) → `ScheduledAnalysisLauncher::launch`(안에서 기준 재확인 후 `UnchangedSourceCheck::record`, 결과 `unchanged`). 지금 가져와 분석 =
  분석 자식(가져오기 전, 사용자 실행·강제 아님·재시도 아님일 때만). 업로드는 판단하지 않는다.
- **제외 규칙**: "성공 분석"을 세거나 고르는 쿼리는 `AND no_change = 0`(목록은 `docs/repo_integration.md` 13절·`docs/results/P27.md`). 새 쿼리를 쓸 때도 같은 조건을 넣는다. Run 번호 기준 쿼리는 원래 해당 없음.
- **이력 접기**: 주기 확인은 가장 최근 행이 같은 리비전·기준·키의 변경 없음이면 그 행을 갱신한다. 사용자가 누른 확인은 행 하나씩(요청한 분석 행을 마감하므로).
- **실패 상태**(P27C): "마지막 끝난 분석"으로 실패를 판정하는 곳(대시보드 실패 프로젝트, 프로젝트 화면 최근 분석 카드 — `AnalysisService::list(…, excludeUnchanged: true)`)은 변경 없음을 건너뛴다. 주기 분석 연속 실패(`ScheduleOutcomes`)는 변경 없음에 중립(호출하지 않음). 인덱스 `analyses_project_status_nochange_id`(022)가 `no_change` 조건 쿼리를 덮는 인덱스로 처리한다.

## 44. 대용량 결함 반영 (P19C, 사용자 결정 2026-10-08)

- **마지막 감지(last_seen)의 저장과 읽기**: 유지(계속 감지) 결함은 분석마다 쓰지 않는다. `defects.seen_live = 1`은 "프로젝트의 최신 실제 성공 분석(status succeeded, `no_change = 0`)에서 감지됨"이고 그 행의 `last_seen_analysis_id`는 오래된 값일 수 있다. 감지 → 미감지가 될 때만(자동 해결·범위 밖 유지·수동 해결 뒤 미감지) `last_seen_analysis_id = 직전 최신 분석`, `seen_live = 0`, `updated_at = max(updated_at, 그 분석 종료 시각)`으로 고정한다. 신규·재오픈·다시 감지는 `seen_live = 1`. 위치·메시지가 바뀐 행만 갱신한다. 그래서 같은 결과의 재반영은 결함 표 쓰기 0건이다.
- **읽기는 `Services\Defects\DefectSeen` 한 곳**: `DefectSeen::expr('d')` = `CASE WHEN d.seen_live = 1 THEN (프로젝트의 최신 실제 성공 분석) ELSE d.last_seen_analysis_id END`. 목록·상세·정렬(최근 감지·Run)·내 결함 순서·코드 뷰어·스냅샷 정리(발췌 대상)·Run 되채움·과거 Run 원본이 모두 이 식을 쓴다. 상세 API의 `updated_at`도 `DefectSeen::updatedAt`(감지 중이면 최신 분석 종료 시각과 늦은 쪽). 목록 정렬은 `DefectSeen::analysisColumn`(한 프로젝트면 감지 중 행의 Run 번호·종료 시각을 값 하나로 넣어 행마다 하위 질의를 피한다). 식에는 열 친화성이 없어 문자열 매개변수와 비교할 때는 `CAST(? AS INTEGER)`로 쓴다. 값은 예전(행마다 갱신)과 같다 — 교차 버전 비교·무작위 시나리오 시험(`tests/Integration/LargeApplyTest.php`)으로 확인.
- **판정 짝은 쓰기 트랜잭션 전에**: `DefectStore::match()`가 읽기로 `temp.cur_defects.defect_id`(서명·순번 → 기존 결함 id), 갱신할 행 표시 `upd`(위치·메시지가 바뀌었거나 `seen_live = 0`), 열린 존재 구간이 없는 행 `noint`, 이번에 감지되지 않은 기존 결함 후보 `temp.unseen`과 그 분류(`reason` = not_detected·file_deleted·NULL 범위 밖 — 경로·제외 규칙·범위·작업 폴더로만 정해지므로 경로마다 한 번), 신규 후보 수를 채운다. 자동 해결과 Run 구간 닫기(수동 resolved 미감지)는 이 표만 본다(프로젝트 전체를 훑지 않음)(상태처럼 사용자가 바꾸는 값은 트랜잭션 안에서 다시 읽는다). 트랜잭션 안의 단계는 기본 키로만 잇고, "이번에 감지" = 짝이 있는 결함 + `first_seen = 이번 분석`. 같은 프로젝트의 결함은 분석 하나만 만들므로(프로젝트당 실행 1) 그사이 짝이 바뀌지 않는다. 트랜잭션은 나누지 않았다(사용자 결정).
- **023 전 행·되채움**: 기존 행은 `seen_live = 0`이라 옛 방식 그대로 맞다. 첫 반영이 감지된 행을 한 번 `seen_live = 1`로 바꾸고, `tools/backfill_seen.php --apply`가 미리 바꿀 수 있다(멱등, 짧은 쓰기 트랜잭션 묶음).
- **결함 수 경고·상한**(`[limits] project_defect_warn`·`project_defect_max`, 0 = 끔): 결과를 읽은 뒤·쓰기 전에 이번에 감지한 결함만으로 상한을 넘으면 `DefectLimitExceededException` → 분석 실패 `defect_limit_exceeded`(결함·이전 결과 그대로). 반영 뒤 현재 결함(해결 아님)이 경고 기준을 넘으면 활성 admin에게 `defect_count_warn`(admin 전용 종류, 프로젝트당 한 건 갱신)과 프로젝트 화면 경고 한 줄.
- **체크포인트**(31절을 바꿈): 분석 사이에 PASSIVE를 먼저 하고, 다 옮겼는데도 WAL 파일이 `database.wal_truncate_threshold`를 넘고 워커가 한가하면 TRUNCATE. TRUNCATE의 대기(다른 연결 쓰기를 막을 수 있음)는 `database.checkpoint_max_block_ms`(busy_timeout을 잠시 그 값으로)로 제한하고 안 되면 다음 기회로. 기록 `mode`는 PASSIVE | PASSIVE+TRUNCATE, `truncate`는 done | skipped_busy | skipped_incomplete | skipped_active.
- **반영 연결의 캐시**: 분석 자식의 연결만 `PRAGMA cache_size`를 `database.analysis_cache_mb`(기본 128MB, 0 = SQLite 기본 2MB)로 키운다(`AnalysisFinalizer`). 결함 수십만 건 반영의 시간은 대부분 `defects` 색인 갱신(상태가 든 색인 4개 등)이고 기본 캐시에서는 페이지가 디스크를 오간다(50만 건 신규 전체 쓰기 트랜잭션 약 14초 → 8초). SQLite 할당이라 PHP `memory_limit` 밖이며 분석이 끝나면 사라진다. 웹·CLI 연결은 기본값 그대로.
- **결함이 많은 한 파일**: 한 파일의 결함이 `analysis.file_defect_spill_threshold`(2만)를 넘으면 그 파일만 `<work>/spill/<분석>-<임의>.sqlite`(별도 연결, 저널 끔)에 넣고 SQL `ORDER BY`·`ROW_NUMBER() OVER (PARTITION BY 서명 ...)`로 정렬·순번(메모리 경로와 같은 순서·같은 결과). 끝나면(예외 포함) 지우고, 남은 것은 워커(분석 단위)·정리 작업(1일)이 지운다. 넘치기 전에 여유 공간을 확인한다(부족하면 `insufficient_storage`). 도구 출력 파싱·NDJSON 쓰기·읽기는 원래 한 줄씩이라 바꾸지 않았다.

## 45. 설정 관리 (P27A, 사용자 결정 2026-10-08·2026-10-09)

- **허용 목록은 `ConfigSchema::EDITABLE` 한 곳**(36개: 보존 기간·디스크·알림·주기 분석·저장소 연결(X12 전 이름 "저장소 연결 시험")·분석·백업(P27B·P27E)). 형식·범위는 `definition()` 그대로, 메타는 group·restart(none|worker|web)·machine_specific. `ConfigSchema::NOT_EDITABLE`(app·security·paths·database·auth·ldap·smtp·tools·health·extract·업로드 청크·zip 크기·worker 실행 명령·저장소 인증서·개발 예외)에 걸리는 키는 `Support\SettingsRegistry`가 거부(LogicException). 시험·격리 점검만 `SettingsRegistry::with()`로 더미 키(경로·워커 재시작 형식)를 더한다.
- **저장·우선순위**: 화면 값은 config.ini 옆 `settings.local.ini`(`Services\Settings\SettingsStore`: 잠금 전용 파일, 버전 표지 = 내용 SHA-256, 임시 파일·fsync·이름 바꾸기, `.bak.1~5`). `Config::load`가 config.ini 검증 뒤 허용 목록 키에만 화면 설정·환경 변수 `PJG_<섹션>_<키>`를 덧씌운다(환경 변수 > 화면 > config.ini > 기본값). 덧씌운 값이 틀리면 그 키만 무시하고 `Config::warnings()`(기동 계속). `Config::source()`·`base()`·`localVersion()`·`reload()`·`withLocalFile()`. 배열로 만든 설정(시험)은 App 생성자가 작업 폴더의 settings.local.ini를 쓰게 한다(실제 config/를 건드리지 않게). config.ini·secrets.ini·DB는 쓰지 않는다(DB에 두면 복원이 PC별 값을 되돌림).
- **P32B**: `security.trusted_proxies`는 `ConfigSchema::NOT_EDITABLE_EXCEPTIONS`로 `security.*` 가운데 유일하게 화면 편집. 유지보수 탭 `maintenance.*` — `SettingsService::commitWithMaintenance`가 꺼짐→켜짐이면 버전 확인 → 직전 백업(`App::preMaintenanceBackup` — 워커에 `pre_upgrade` 백업 작업을 맡기고 `maintenance.backup_wait`까지 기다림, 웹 요청 안에서 직접 백업하지 않음: 워커가 남이 시작한 작업을 "끝나지 않은 작업"으로 정리하므로) → 저장 → `maintenance.on/off` 감사. 차단은 `UpgradeMaintenanceMiddleware`(세션 뒤·CSRF 앞, admin 아닌 사용자의 쓰기만 503), 배너 `Services\UpgradeMaintenance::banner`, 워커는 `claimAnalyses`·주기 분석 틱을 건너뜀. 접속 주소·https는 `Http\ForwardedHeaders::resolve` 한 곳(`App::handle` 맨 앞).
- **반영**: 웹은 요청마다 설정을 읽는다. 워커는 루프마다 settings.local.ini의 [수정 시각, 크기, 내용 해시(P32)]를 보고 바뀌면 `Config::reload()` → `App::replaceConfig()`(설정 값을 품은 재사용 서비스 diskGuard·uploads를 버림). 생존 신호 `config {version, loaded_at, pending_restart}` — 화면은 버전 비교로 "적용됨/곧 다시 읽음/워커 꺼짐", restart=worker 키가 시작 때 값과 다르면 "재시작 필요" 배너. 현재 허용 목록은 모두 none(워커가 쓸 때마다 읽거나 매번 새로 만드는 서비스).
- **API·화면**: `GET/PUT /api/v1/admin/config-settings`, `POST …/validate`, `DELETE …/{key}`(admin만, CSRF). PUT은 전체 성공 또는 전체 실패(400 `setting_not_editable`, 409 `settings_conflict`, 422 `setting_invalid_value`·`setting_cross_check`·`setting_env_locked`·`setting_path_invalid`). P21C·P24의 `/api/v1/admin/settings`(DB 화면 설정)와 별개 경로(사용자 결정 2026-10-09) — 화면엔 안내 링크만. 관리자 메뉴 "설정 관리"(`/admin/config-settings`, 아이콘 `admin.settings`). 감사 `config.update`(키마다, details old·new·old_source), 변경 이력은 감사 로그 `(target_type, target_id)` 색인으로.
- **경로 검증 `Services\Settings\PathPolicy`**(P27B 공유): 절대·UNC·프로젝트 기준 상대, `..` 풀고 이미 있는 상위를 realpath로 정규화한 결과(정션·링크를 따라간 실제 위치)로 금지 위치 비교 — 드라이브 루트, 프로젝트 폴더 전체, `paths.*` 데이터 폴더, 운영체제 폴더(예외: 프로젝트 바로 아래 `backups/` — P27B에서 `paths.backups_dir`에서 바꿈). 없으면 만들기 확인, 쓰기 시험, 여유 공간, 같은 볼륨 경고, UNC는 PHP 자식(`tools/lib/path_probe.php`) 5초 상한. 자세한 규칙·허용·제외 표는 `docs/settings.md`.

## 46. 백업·복원 (P27B, 명세 14·19.3·19.4, 사용자 결정 2026-10-08)

- **서비스는 `src/Services/Backup/` 한 곳**(웹·워커·명령줄 공유): `BackupService`(묶음 만들기 `VACUUM INTO`·검증·목록·보관 정리), `BundleReader`(허용 항목·경로 이탈·링크·중복·압축 비율·manifest·해시 — 해제는 항목별 스트림, `extractTo` 금지), `BundleFormat`(이름·허용 항목·민감 키 규칙), `ConfigSanitizer`, `RestoreService`(사전 검증·리허설·실행·롤백·중단 복구), `KeyVault`(.pjgkey AES-256-GCM + PBKDF2 310,000회), `BackupJobs`·`BackupRunner`(워커 작업·일일 예약·알림), `BundleUpload`(묶음 올리기), `MaintenanceMode`, `RestoreStateFile`, `BackupPaths`.
- **상태는 파일**: 백업 폴더의 `backup_state.json`·`<묶음>.json`, 작업 폴더 `run/`의 `backup_job.json`·진행·취소, `restore_request.json`, `restore_state.json`, `maintenance.flag`. 복원이 DB를 통째로 바꾸고 유지보수 중에는 DB를 열지 않으므로 DB에 두지 않는다(알림 종류만 마이그레이션 024).
- **웹 요청은 DB를 바꾸지 않는다**: 백업·리허설은 워커 작업(`tools/backup.php --job` 자식), 복원은 워커가 유지보수 모드로 들어가 진행 작업을 비우고 연결을 닫은 뒤 `tools/restore.php --request` 자식으로. 복원 단계·롤백·수동 복구 안내는 `docs/backup_restore.md` 3절.
- **유지보수 모드**: `Http\Middleware\MaintenanceMiddleware`가 요청 ID 다음·세션 앞(DB 전)에서 플래그 파일만 본다 — 상태 페이지·API만 통과, 다른 API 503 `maintenance`, 다른 화면 503 + 상태 페이지. 워커는 새 작업·주기 스케줄러·일일 백업을 멈춘다.
- **백업 폴더**: `[backup] dir`(P27A 허용 목록, PC 고유). `PathPolicy` 예외는 프로젝트 바로 아래 `backups/`만(P27A의 `paths.backups_dir` 예외는 없앰). 백업·복원 진행 중 폴더 변경은 `SettingsService`의 키별 검사로 409 `setting_busy`.
- **권한·재인증**: 화면·API는 admin만(라우트 `$admin`). 내려받기·삭제·복원·키 내보내기는 비밀번호 재입력(분당 5회). 내려받기는 일회용 표(60초) + `Response::stream`(파일을 지우지 않는 파일 응답). 복원 상태 페이지·API만 로그인 없이(비밀 없음).
- **키 불일치**: 저장소 연동의 P25/P26 동작은 그대로 쓰고(`repo_secret_unreadable`), 주기 분석 건너뜀 알림만 같은 프로젝트·같은 사유면 한 번(`ScheduleOutcomes::skipped`).
- **자동 백업 예약(P27E)**: 예정 계산은 `Services\Backup\BackupSchedule` 한 곳(순수, 현지 날짜마다 한 번 — 서머타임 중복·누락 없음, 매월 없는 날짜는 말일). 판단은 `BackupRunner::autoDue()`(가장 최근 예정 이후 처리한 자동 백업이 없으면 그 예정 하나, 규칙이 바뀌면 지난 예정은 지난 것, 꺼져 있으면 없음), 다음 예정은 `nextAuto()`. 상태는 `backup_state.json`의 `auto`. 워커는 결과(예정 시각)만 작업 요청에 넣는다. 꺼져 있으면 보관 정리·실패 알림도 없음. "오래됨" 기준은 `BackupSchedule::staleHours()`. 디스크 추정은 화면(`backup_core.diskEstimate`)만.
- **시험**: `tests/Unit/BackupFormatTest`, `tests/Unit/BackupScheduleTest`, `tests/Integration/BackupRestoreTest`(격리 파일 DB·프로젝트 밖 임시 백업 폴더, 장애 주입은 `RestoreService`의 `$fault` 단계 이름). 시험 설정(`TestApp::rawConfig`)은 백업 폴더를 실행마다 임시 폴더로 바꾼다 — 시험이 띄운 워커의 일일 백업이 실제 `backups/`에 쓰지 않게.

## 47. 화면 언어·테마 (P27D, 사용자 결정 2026-10-09)

- **결정은 `Services\UiPreferences` 한 곳**: 언어 = 계정 `users.ui_language` → 쿠키 `pjg_lang` → `Accept-Language`(가장 선호하는 언어가 ko면 ko, en이면 en, 그 밖은 ko) → ko. 테마 = 계정 `users.ui_theme` → 쿠키 `pjg_theme` → `system`. 허용 값(ko·en, light·dark·system) 밖은 무시.
- **`Http\Middleware\LocaleMiddleware`를 두 번**: 요청 ID 다음(쿠키·브라우저만 — 유지보수 안내도 그 언어로)과 세션 다음(계정 값 우선). `Lang::setLocale()`과 요청 속성 `ui_lang`·`ui_theme`. 로그인한 계정 값이 비어 있고 유효한 쿠키가 있으면 한 번 옮겨 저장(빈 열만 UPDATE). 계정 값은 세션 미들웨어가 따로 읽어(마이그레이션 025 전이면 없음) `user_ui` 속성으로 넘긴다. 워커·명령줄은 ko.
- **문구**: `Lang`은 영어 위에 한국어를 덧씌운 표(없는 키는 한국어 — 시험이 불일치를 실패로). 복수형 `{n:단수|복수}`(PHP `Lang::format`·JS `format` 같은 규칙). 서버 문구(API 오류·분석 실패 `failure_message`·내보내기 머리글·셀 잘림 표시)는 요청 언어로 그때 만든다. 알림·감사·분석 실패는 코드 + 매개변수 저장이라 읽을 때 번역(저장된 과거 알림도). DB에 문장으로 저장된 사용자 입력·도구 원문·스모크 결과는 그대로(`docs/i18n_glossary.md` 4절).
- **테마**: `<html data-theme="light|dark">`를 서버가 그려 깜빡임이 없다. `system`이면 속성을 두지 않고 `tokens.css`의 `@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) }`가 따른다(JS 없음). 다크 토큰은 `:root[data-theme="dark"]`와 미디어 블록에 같은 값(`tools/check_theme_contrast.mjs`가 같음과 WCAG 명암비 — 글자 4.5:1, 경계·아이콘 3:1 — 를 확인). 색은 토큰만(하드코딩 0), 글자로 쓰는 강조는 `--color-link`, 코드 뷰어 문법 색은 `--code-*`(CodeMirror `HighlightStyle`이 토큰을 씀). 프린트는 라이트.
- **헤더 전환**(`partials/ui_switch.php` + `ui/ui_prefs.js`, 로그인 화면 머리글에도): 언어 버튼(아이콘만 — X14, 지금 언어는 툴팁·접근 이름, 누르면 저장 뒤 다시 불러오기 — 입력 중인 폼이 있으면 확인), 테마 버튼(아이콘, 메뉴 `menuitemradio` 3개, 다시 불러오지 않고 바로 적용 — 차트가 있는 화면만 다시 불러옴). 로그인 상태면 `PUT /api/v1/users/me/preferences` + 쿠키, 아니면 쿠키만(SameSite=Lax, 1년, 경로 = 배포 경로).

## 48. 계정 CSV 일괄 처리·인증 방식 목록 (P28A)

- **인증 방식 목록은 `Services\Auth\AuthProviderRegistry` 한 곳**(`enabledTypes()`, 지금 `['local']`, 아는 방식 `KNOWN` = local·ldap·oidc·saml). CSV 검증과 사용자 생성(`UserService::create`의 `auth_type`)이 이 목록을 쓴다. P28B~D는 방식을 켜고 라벨(`account.auth_<방식>`)을 더한다.
- **CSV 읽기**(`Services\Accounts\AccountCsvReader`, DB 없음): 2 MiB·1,000행 상수, 인코딩 auto(BOM → UTF-8 → CP949 왕복 검사)·선택, RFC 4180 + LF, 머리글 이름으로 읽음. **검증·실행**(`AccountImportService`): 미리보기는 저장 없음, digest(파일 해시 + 인코딩 + 행별 판정)로 실행 때 같은 판정인지 확인(서버 상태 없음 — 파일 재전송), bcrypt는 트랜잭션 밖, 쓰기 트랜잭션 안에서 다시 판정 → 전부 또는 하나도. 임시 비밀번호는 실행 응답에만(서버·로그·감사 로그에 없음). 자세한 규칙은 `docs/account_csv.md`.

## 49. 외부 인증 공통 기반·LDAP/AD (P28B)

- **로그인 분기는 `LoginService` 한 곳**: 입력 ID의 계정이 local이면 앱 비밀번호, ldap이거나 계정이 없고 LDAP이 켜져 있으면 `Ldap\LdapLogin` → `ExternalAccountProvisioner`. 로컬 계정은 디렉터리로 로그인되지 않는다. 결과 pending·rejected·pending_limit·directory_unavailable은 세션 없이 안내(403·503).
- **승인 대기 공통 서비스 `Services\Auth\ExternalAccountProvisioner`**(P28C OIDC·P28D SAML도 호출만): 생성(이름·이메일·식별자는 이때 한 번), 미리 만든 계정 연결, 식별자 불일치 거부, 승인·거절·삭제(updated_at 낙관적 잠금), 정리(`CleanupService::purgePendingAccounts`), 알림. 승인되지 않은 계정은 늘 비활성(DB 제약)이라 권한·목록·알림 대상에서 저절로 빠진다.
- **디렉터리는 `Ldap\DirectoryClient` 인터페이스**: 실제 `PhpLdapClient`(ext-ldap은 `LdapTransport`로 감싸 시험 가능 — 서버 넘김·TLS·이스케이프), 시험·격리 점검은 `tests/Support/FakeDirectoryClient`(`App::useDirectoryClient`). 실제 서버에 연결하는 시험은 없다(로컬 닫힌 포트의 연결 실패 경로만, ldap 확장이 있을 때).
- **비밀**: 검색 계정 비밀번호는 `AppSecrets`(SecretBox, 표 app_secrets). 바인드 DN은 설정이지만 감사·변경 이력에서 가림(`SettingsService::AUDIT_MASKED`). 비밀번호·DN·필터 원문은 로그·감사·오류·시험 출력에 없다.
- **로컬 관리자 보존**: 활성 로컬(앱 비밀번호) 관리자가 0이 되는 비활성화·강등·인증 방식 전환은 409 `last_admin`(`UserService::guardLastAdmin`, `changeAuthType`).
- **로그인 화면 SSO 자리**: `App::ssoProviders()`(지금 빈 목록)가 `[{id, label, href}]`를 돌려주면 로그인 화면에 단추가 생긴다(P28C·P28D).
- 026 전 호환: `Support\SchemaFeatures::externalAuth()`. 자세한 규칙·실제 서버 점검표는 `docs/auth_ldap.md`.

## 50. SSO 로그인 OIDC (P28C)

- **프로토콜은 `Services\Auth\Oidc\OidcLogin`**(Authorization Code + PKCE S256): `begin()`이 state·nonce·verifier를 `OidcStateStore`(서버 쪽 파일, 로그인 전 세션 해시에 묶음, 10분·한 번만)에 두고 인가 주소, `complete()`가 state → IdP 오류 → 코드 교환 → ID 토큰 검증 → (선택) UserInfo. 화면 경로는 `OidcController`(`GET /auth/oidc/start`·`/auth/oidc/callback`, 로그인 전).
- **계정 결정은 `LoginService::attemptExternal` → `ExternalAccountProvisioner::onFederated`**: (발급자, sub) 먼저, 없으면 로그인 ID 클레임으로 승인 대기 생성·미리 만든 계정 연결, 다른 방식·다른 sub의 같은 ID는 거부(`login_id_conflict`). 이메일로 연결하지 않는다. P28D SAML도 같은 두 함수를 호출만 한다.
- **JWS 검증은 `Jose`**(새 패키지 없이 `openssl_verify`, JWK → DER/PEM, ES256 r‖s → DER) + 클레임 `IdTokenValidator`. 알고리즘은 `ConfigSchema::OIDC_ALGS`(RS256·ES256) 안에서만.
- **외부 요청은 `OidcHttp`**(https만·주소 판정 `HostPolicy::classify`·발급자보다 안쪽 주소 금지·판정한 주소로 고정·GET 리다이렉트 2번·1 MiB·5/10초) 위의 `HttpTransport`(실제 `StreamHttpTransport`, 시험·격리 점검은 `tests/Support/FakeOidcProvider` — `App::useOidcTransport`). 실제 IdP에 연결하는 시험은 없다.
- **메타데이터·키 캐시 `OidcProvider`**(`<작업 폴더>/oidc/cache/`, 발급자별): ttl, 모르는 kid면 60초에 한 번 새로 받기, 실패 때 오래된 캐시를 `oidc.jwks_stale_max`까지.
- **비밀**: 클라이언트 비밀은 `AppSecrets`(`oidc.client_secret`). 027부터 `app_secrets.name`은 형식만 DB가 검사하고 허용 이름은 `AppSecrets::NAMES` 한 곳. 027 전: `SchemaFeatures::oidc()`가 false — OIDC는 꺼진 것처럼, 비밀 저장은 409 `app_secret_migration_required`.
- **CSP 예외 2**: 제공자 로그아웃(`oidc.provider_logout`)이 켜졌을 때만 `form-action`에 IdP 로그아웃 출처 하나(`SecurityHeadersMiddleware` 생성자 클로저). 로그인 시작은 링크라 예외 없음.
- 로그인 화면 SSO 단추는 `App::ssoProviders()`(켜짐 + 027 + 발급자·클라이언트 ID). 실패는 `/login?sso_error=E-SSO-…`(허용 목록 코드만 표시), 승인 대기·거절·상한은 `/login?sso_notice=…`. 운영 문서 `docs/auth_oidc.md`.

## 51. SSO 로그인 SAML 2.0 (P28D)

- **XML 서명 검증·정규화는 라이브러리**(onelogin/php-saml 4.3.2 + xmlseclibs 3.1.5, Composer) — 직접 구현하지 않는다. 보안 옵션은 `Saml\SamlSettings::library()` 한 곳(strict, 스키마, 서명 필수(라이브러리 기본), IdP 시작 거부, SHA-256, 롤오버 인증서 2개는 `x509certMulti`).
- **흐름 `Saml\SamlLogin`**: `begin()`(AuthnRequest HTTP-Redirect, 요청 기록 = `OneTimeStore` 키는 요청 ID, 로그인 전 세션 해시·RelayState 토큰·돌아갈 곳, 10분·한 번) → `acs()`(IdP의 cross-site POST — 크기·base64 → 라이브러리 → InResponseTo로 기록 꺼냄·RelayState → 라이브러리 `isValid`(라이브러리의 "현재 주소"를 공개 주소로 고정하는 동안) → 앱 검사(Destination·Recipient 정확히, 오차 60초, SHA-1 거부, transient 거부, `ReplayGuard` 어서션 ID) → 2분 마무리 토큰) → `finish()`(같은 사이트 GET에 실린 Lax 세션 쿠키가 시작한 세션과 같아야). 화면 경로 `SamlController`.
- **CSRF 예외 하나**: `CsrfMiddleware::EXEMPT_PATHS = ['/auth/saml/acs']`(세션 쿠키 SameSite=Lax 유지 — None으로 바꾸지 않음). 로그인 CSRF는 마무리 GET의 세션 일치로 막는다.
- **계정 결정은 OIDC와 같은 두 함수**: `LoginService::attemptExternal('saml', EntityID, 신원)` → `ExternalAccountProvisioner::onFederated`(규칙 복제 없음).
- **관리 `Saml\SamlAdmin`**: 상태, SP 키·자체 서명 인증서 생성(openssl, `AppSecrets` `saml.sp_private_key`·`saml.sp_cert` — 이름별 상한 4 KiB), IdP 메타데이터 가져오기(값만 돌려줌 — 저장은 설정 화면, 주소는 `Oidc\OidcHttp` 정책), 설정 확인 4단계, 점검 항목, 인증서 만료 알림(`saml_cert_expiring`, 마이그레이션 028, 워커가 시간마다 — 인증서마다 하루 한 건, `SchemaFeatures::samlCertNotice`).
- 외부 요청 전송 바꿔 끼우기는 OIDC와 같은 `App::useOidcTransport`(시험·격리 점검은 `tests/Support/FakeSamlIdp`, 브라우저 점검은 `tests/Support/fake_saml_sign.php`). 실제 IdP에 연결하는 시험은 없다.
- 027 전·`vendor/` 없음: SAML은 꺼진 것처럼(`App::samlEnabled`), 메타데이터 404, 점검 화면에 이유. 운영 문서 `docs/auth_saml.md`.

## 52. 가이드 (P29, 명세 16장)

- **본문은 코드에 포함된 읽기 전용 마크다운**: `resources/guide/<user|admin>/<ko|en>/<번호>_<key>.md`(웹 루트 밖 — PHP로만), 머리말(key·title·order·summary·keywords·related·audience)은 `Services\Guide\GuideCatalog`가 직접 해석(새 패키지 없음, 파일 지문으로 캐시). 번역 없는 절은 한국어로 대신(fallback).
- **자리표시자는 `GuideContent`가 렌더 전에 채움**: `{{limit:<설정 키>}}`(설정 해석 결과 + 단위, 화면 언어), `{{version:app|ruff|eslint}}`, `{{ui:<문구 키>}}`(PHP 또는 JS 문구), `{{icon:…}}`은 브라우저 렌더러가 아이콘 + 이름으로. `validate()`가 모르는 자리표시자·없는 설정 키·원시 HTML·위험한 링크·없는 key·ko/en 불일치·그림 문제를 잡는다(GuideCatalogTest).
- **렌더는 P22의 안전한 마크다운 렌더러**(`lib/markdown.js`)에 선택 인자만 더함 — `link`(guide:·앱 경로), `image`(guide-img:), `token`(아이콘). HTML 문자열을 만들지 않는다. 검색 강조도 글자 노드를 나눠 `<mark>`를 만든다.
- **접근 통제**: 사용자 가이드·오류 코드·라이선스는 로그인한 모든 사용자(`$auth`), 관리자 가이드 화면·API·검색은 `$admin`(403 — 본문·제목·건수 없음), 그림은 `GuideController::image`가 직접(비로그인 401, 관리자 그림은 비관리자 403 — 파일 존재와 무관, 내용으로 판정한 PNG·WebP·JPEG만, nosniff·ETag). 관리자 가이드는 사용자 가이드 검색·결함·규칙 검색에 섞이지 않는다.
- **탭 정의 한 곳 `GuideTabs`**(사용자 · 관리자(admin만) · FAQ · 오류 코드 — P29B가 FAQ 한 줄을 더함), **화면 → 도움말 절 한 곳 `Support\GuideLinks`**(View::page가 레이아웃 머리 오른쪽에 "이 화면 도움말" — P29C가 관리자 화면을 더함), **오류 카탈로그 → 절** `ErrorCatalog::GUIDE_SECTION`.
- **절 단위 페이지(X11)**: 브라우저가 API의 절 전체를 한 번 받아 해시(`#<key>[/<소제목 slug>]`)에 맞는 절 하나만 그린다(새로고침 없음, `hashchange` = 뒤로/앞으로, 문서 제목 = 절 제목, 절 제목 포커스, 없는 key는 첫 절 + `replaceState`). API·접근 통제는 P29 그대로. 불릿은 `.guide-md`(가이드 본문 래퍼) 안에서만 CSS로 그리고 색은 `--guide-bullet` 토큰.
- 그림: `tools/make_guide_screenshots.mjs`가 `resources/guide/screenshots.json` 목록대로 가짜 데이터 설치본(`tools/guide_demo`)에서 다시 만든다. 배포 묶음 점검 `tools/verify_bundle.php`. 작성 규칙 `docs/guide_authoring.md`.
- **관리자 가이드(P29C)**: 23절 ko·en. 관리자 화면의 "이 화면 도움말"은 `GuideLinks::SCREENS`의 `['admin', <key>]` — `View::page`가 admin이 아니면 그리지 않는다(그룹 목록·상세는 그룹 관리자도 연다). 설정 관리는 `GuideLinks::CONFIG_TABS`(카테고리 탭 → 절, `data-help-tabs`로 화면에 넘겨 탭을 바꿀 때 링크를 고침, `GuideCatalogTest`가 모든 편집 묶음에 절이 있는지 검사). 그림 스크립트는 그림마다 로그인 계정(`login`)을 고를 수 있고 관리자 그림은 최대 8장. 본문 식별자·금지어는 `GuideCatalogTest::testGuideIdentifiersAndForbiddenStrings`.
- **FAQ(P29B)**: 가이드의 FAQ 탭(`/guide/faq`, `GuideTabs`에 한 줄 — 주 메뉴 항목 없음). 본문은 파일이 아니라 DB `faq_items`(마이그레이션 029)에 관리자가 화면에서 쓴다 — 기본 항목 없음. 서비스 `Services\Faq\FaqService`(`App::faq()`), 컨트롤러 `FaqController`.
  - 권한: 읽기 `GET /api/v1/faq[/{id}]`는 `$auth`(로그인한 모든 사용자), 쓰기 `/api/v1/admin/faq…`(추가·수정·삭제·순서·공개 전환)는 `$admin`(그룹 관리자·일반 사용자 403). 비공개 항목은 admin에게만 — 일반 사용자 응답에는 항목·건수·분류가 없고 한 항목 조회는 없는 항목과 같은 404. 편집 단추·대화상자는 admin에게만 DOM에 만든다(서버가 `data-admin`·추가 단추를 역할로 그림, CSS로 숨기지 않음).
  - 동시 수정: 항목마다 `version`(P18 방식) — 다르면 409 `faq_conflict` + `details.current`(최신 항목), 화면은 최신 내용을 보여 주고 입력은 그대로 둔 채 "최신 내용으로 바꾸기"·"내 내용으로 다시 저장". 순서 바꾸기는 분류의 id 목록 전체를 보내고 실제 목록과 다르면 409 `faq_order_conflict`(순서 변경은 version을 올리지 않음 — 내용 편집과 충돌하지 않게).
  - 순서: `sort_order`는 전체에서 하나의 수열, 분류 순서 = 그 분류(보이는 항목)의 가장 작은 `sort_order`. 새 항목·분류를 바꾼 항목은 맨 끝.
  - 렌더: 답변은 P22 안전한 마크다운 부분집합(`lib/markdown.js`, `headingBase 4`) — 원시 HTML은 글자 그대로, 링크는 http(s)(`rel="noopener noreferrer"`)와 앱 안 상대 주소(`faq_core.faqHref`)만, 이미지 없음. 편집 미리보기도 같은 렌더러.
  - 검색: 분류·질문·답변을 소문자·NFC로 합친 `search_text`에 낱말 AND `LIKE … ESCAPE '\'`(%·_ 는 글자). 항목 상한 500개라 색인 없이 충분(측정 P29B 보고서). 검색은 Enter·단추로만 적용.
  - 항목 주소 `#faq-<id>`(열면 펼치고 그 위치로, "링크 복사"). 감사: `faq.create`·`faq.update`·`faq.publish`·`faq.delete`·`faq.reorder`(답변 원문 대신 길이·SHA-256 앞 16자). 029 전에는 화면 안내·API 503 `faq_unavailable`(`SchemaFeatures::faq`).

## 53. 시스템 모니터링·헬스체크·감사 로그 조회 (P30, 명세 14·19.3·19.7)

- **판정 한 곳 `Services\Monitoring\SystemHealth`**: 카드 9개의 사실(`cards()`) → 경고 목록(`judge()`, 순수 함수) → 전체 상태(ok·warn·fail). 시스템 모니터링 화면(`/admin/monitoring`, admin만)·관리자 배너(`App::view`가 admin에게만 `healthAlerts`)·헬스체크(`GET /health`)·워커(`monitoring.cache`마다)가 같은 판정·같은 캐시(`<work>/monitoring/health.json`)를 쓴다. 기준·데이터 출처·설정 키는 `docs/monitoring.md`.
- **상태 변화**: 경고 시작·수준 바뀜·계속(`monitoring.log_repeat`)·해소를 경고 로그 `logs/alert-YYYYMMDD.log`(`App::alertLogger`, 앱 로그와 같은 보존)에 남기고, **정상 → 경고**일 때만 `AdminAlertSink::raised`(이메일을 붙일 한 지점 — P30 구현은 로그만, 이메일 없음). 시험은 `App::setAlertSink`로 가로챈다.
- **`/health`**: 미들웨어 없음(인증·CSRF·세션 없음). 허용 IP(`health.allowed_ips`, CIDR, `Request::clientIp` = `REMOTE_ADDR`만 — 프록시 헤더를 믿지 않음) 밖이면 등록되지 않은 경로와 같은 404. 고정 스키마(OpenAPI `HealthStatus`), fail이면 503, IP당 분당 호출 제한 429, 응답 캐시. 식별 정보 없음.
- **감사 로그 조회 `Services\Audit\AuditQuery`**(`/admin/audit-logs`, admin만, **읽기 전용 — 수정·삭제 라우트 없음**, `AuditLogTest`가 검사): 조건(기간 → occurred_at 색인으로 id 범위, 작업 접두사 → `audit_log_action` 색인(030) 범위, 부분 일치는 LIKE ESCAPE), 최신순 page/size, 세부 정보는 화면 단계에서 한 번 더 마스킹(`AuditQuery::safe` — 민감한 키 이름·문자열 안의 비밀·이메일). 작업 이름 목록 `Support\AuditActions`(화면 선택지·`docs/audit_actions.md`, 코드에 문자열로 쓴 이름이 모두 있는지 시험).
- **무결성 검사**: `AuditChain::verifyRange(after, limit)` — 조각(최대 20,000행)마다 첫 행의 앞 해시를 DB에서 다시 읽어(호출자 값을 믿지 않음) 비교, 화면이 끝까지 이어 부른다(워커·admin_tasks 불필요, 요청 하나는 짧음). 검사 실행은 앱 로그에만(감사 로그에 쓰면 검사하는 동안 체인 끝이 늘어남).
- **보존 정리 `Services\Audit\AuditRetention`**: `CleanupService::purgeAuditLog`(tools/cleanup.php·관리 작업 cleanup_run) → `AuditChain::purgeBefore(cutoff, batch, now)` — 앞에서부터 연속된 오래된 행만 묶음(`log.audit_purge_batch`)마다 지우고 묶음마다 기준점(체크포인트: `audit_log_meta.anchor_hash`·`purged_through`·`purged_through_at`(030))을 옮긴다. 중간에 멈춰도 검증은 통과한다. 지운 뒤 `audit.purge` 한 줄(행위자 system). 030 전이면 경계 시각만 기록하지 않는다(`SchemaFeatures::auditCheckpoint`).
- **CSV 내보내기**는 P23 `ExportService`(동시 실행 자리·행 수 상한·수식 방지·BOM) 재사용, `audit.export` 기록.
- 메뉴: `Navigation::admin()`의 `admin.system`(→ `/admin/monitoring`, 영어 이름 "Monitoring" — X12)·`admin.audit`(→ `/admin/audit-logs`) 활성화. 도움말 `GuideLinks`: `admin/monitoring` → monitoring, `admin/audit_logs` → audit-log, 설정 관리 탭 `monitoring` → monitoring.
- 설정 관리 새 탭 **모니터링**(`ConfigSchema::EDITABLE_GROUPS`의 `monitoring`): `health.allowed_ips`(서버마다 다른 값)·`monitoring.queue_stall`. `log.audit_retention`은 보존 기간 탭(90일 미만이면 저장 전 확인 — `config_settings_core.auditRetentionTooShort`).

- **모니터링 카드 공용 구조**(X18): 카드 9개 모두 `admin/_monitor_card.php` 하나(머리·핵심 값·세부·바닥 줄), 상태 배지 한 사전 `monitoring_core.js` `BADGES`(정상·주의·오류·확인 불가·정보 — 새 아이콘 `system.unknown`; X19: 제목 앞은 상태 아이콘 하나 — 정상·정보 파랑, 주의·오류 빨강, 카드 종류 아이콘 그룹은 뺌), 격자 1·2·3열과 한 줄 높이 맞춤 — `docs/ui_conventions.md` 4절. 데이터·판정·API는 그대로.

## 54. 감사 로그 사람이 읽는 설명 (X17)

- **한 곳 `Services\Audit\AuditDescriber`**: `AuditQuery`의 목록·한 행·CSV 반복(`iterate`, 2,000행 조각)이 행 묶음을 넘기면 `describeMany()`가 행마다 `summary`(한 문장)·`fields`(항목·이전·이후)·`target_label`/`target_href`/`target_deleted`·`actor_display`/`actor_name`·`source`(web·cli·login·system)·`outcome`(success·failure·requested, 사유·가이드 오류 코드 링크)·`known`을 더한다. **보여 줄 때만** 만든다 — 감사 행·해시 체인·보존 정리는 그대로(`AuditDescriberTest::testViewingDoesNotChangeRowsOrChain`).
- **문구**: `audit.desc.<작업>[.<변형>]`(변형은 `variant()` 한 곳 — 결과·출처·켜기/끄기 등), 작업 이름 `audit.act.*`, 분류 `audit.group.*`, 항목 이름 `audit.fname.<키>`(작업별 `audit.fname.<작업>.<키>` 먼저), 값 `audit.val.*`(종류 표 `KINDS`·`kind()`). 자리표시자에 넣을 값이 없으면 작업 이름만 쓴 일반 문장, 목록에 없는 작업은 `audit.desc._unknown` + `known=false`(화면 배지 "설명 없음(원본만)"). 한국어 조사는 문구에 "을(를)"처럼 두 꼴로 쓰고 `Support\Josa::fix`가 앞말(괄호 묶음·따옴표 건너뜀)의 받침으로 고른다. 설정 항목 이름은 설정 관리 화면 문구(`Support\JsStrings` — JS i18n의 `config_settings.key.<키>.label`)를 그대로.
- **이름 풀이는 일괄**: 한 묶음의 사용자(대상 + 행위자)·프로젝트·그룹·FAQ id를 종류마다 IN 한 번(500개씩), 없는 종류는 조회하지 않음 — 200행 목록 = COUNT + SELECT + 이름 풀이 최대 4(시험이 10행 = 200행 쿼리 수를 고정). 못 찾으면 세부 정보에 남은 이름, 없으면 "삭제된 항목(#id)"(링크 없음).
- **비밀**: 입력은 `AuditQuery::safe`를 거친 세부 정보뿐(가린 값을 풀거나 다른 표에서 보충하지 않음), 비밀 모양 설정 키(`Logger` 민감 키 규칙)의 `config`·`setting` 행은 이전·이후 값도 화면 단계에서 가림, 공백 없는 40자 이상 덩어리는 문장·표에서 "(긴 값 생략)"(확장자가 붙은 파일 이름 제외 — 백업 묶음 이름).
- 화면: 목록 "설명" 열(두 줄 말줄임·작업 이름 작은 글씨·상세 단추 접근 이름에 요약), 상세 구역(요약 → 바뀐 항목 표 → 세부 내용 → 누가·언제·어디서 → 대상 링크 → 결과 → 접힌 기술 정보: 작업 이름·요청 ID·행 번호·해시 앞 8자·원본 JSON 복사), 작업 선택지는 분류 `optgroup` + "분류 — 이름 (작업)". CSV는 마지막 열 "설명"(화면 언어). API 필드는 `docs/openapi.yaml`의 `AuditLogEntry`.
- **새 감사 작업을 기록하면** `AuditActions`·`docs/audit_actions.md`(작업별 의미·details 키 표)·ko/en 문장·작업 이름·`tests/Support/AuditSamples.php` 샘플·`AuditDescriberTest` 기대 문장을 함께 더한다(빠뜨리면 시험 실패).
- **목록 열(X19)**: 규칙(분류 — 작업 이름 / 작업 키 작은 고정폭, 설명 없음 배지) · 설명(문장만) · 행위자 · 대상 · 결과(모양이 다른 아이콘 + 글자 — 성공 파랑·실패 빨강·요청됨 회색, 토큰만) · 시간(`audit_core.js` `shortDateTime` — 화면 시간대 YY/MM/DD HH:MM, 전체 시각은 툴팁·상세) · 요청 ID · 상세. 설정 변경 문장은 설정 화면 이름만(키는 상세 기술 정보의 "대상(원본)"). CSV·API·필터는 그대로.

## 55. 관리자 장애 이메일 (P30B)

- **보내는 곳은 워커 한 곳**: `Worker::evaluateHealth`(`monitoring.cache`마다)가 `SystemHealth::snapshot()` 결과를 `Services\Mail\AlertMailer::tick()`에 넘긴다. 웹 요청은 보내지 않는다(시험 메일 API만 예외). `AdminAlertSink`(전이 순간·웹에서도 불림)에 붙이지 않은 이유와 규칙(새로·지속 중·수준 오름·복구, 한 통으로 묶기, 재발송 간격)은 `docs/monitoring.md` 4절. 판정·`/health`·모니터링 API는 그대로(앱 카드에 정보 값 `alert_mail` 하나만 더함).
- **상태 표 `alert_mail_state`**(031, 사건 키당 한 행, `SchemaFeatures::alertMail` — 없으면 "설정 필요"로 아무것도 안 함). 집기·갱신은 `Db::transaction`(BEGIN IMMEDIATE) 하나에서 — 워커가 여럿이어도 같은 사건을 한 번만. 실제 발송은 트랜잭션 밖(SQLite 쓰기 잠금을 SMTP 시간 동안 쥐지 않음), 결과 코드만 뒤에 짧게 기록. 실패는 워커를 멈추지 않는다(`tick` 밖에서도 `\Throwable`을 잡아 경고 로그).
- **발송기 `Services\Mail\MailSender`**(인터페이스) — `SmtpMailSender`(PHPMailer 7.1.1, `vendor/`, 메시지 구성 + 같은 라이브러리의 `SMTP` 클래스로 전송 — 직접 만든 SMTP 없음, 인증서 검증 끄는 옵션 없음). 시험·격리 점검은 `App::useMailSender(Closure)`로 바꿔 끼운다(`tests/Support/FakeSmtp.php` — 127.0.0.1 가짜 수신기, 시험용 사설 CA는 신뢰 목록으로만 주입하고 검증은 켠 그대로). 실패 사유는 `MailException::$reason`(connect·tls·auth·rejected·timeout·config·invalid)만 — 서버 응답 원문을 로그·화면에 내지 않는다.
- **설정·비밀**: `[alert_mail]` 18키(편집 13 — 설정 관리 "관리자 이메일" 탭, `docs/settings.md`), SMTP 비밀번호는 `AppSecrets`의 `mail.smtp_password`(SecretBox, 027 표 — 새 마이그레이션 없음). API `/api/v1/admin/alert-mail`(상태)·`/password`(PUT·DELETE)·`/test`(POST, 사용자당 분당·전체 시간당 제한 — 잠금 파일 `<work>/alert_mail/`) — admin만. 감사 `mail.secret_set`·`mail.secret_delete`·`mail.test`(받는 사람 가림). 자동 발송은 앱 로그만.
- 정리 작업 `CleanupService::purgeAlertMailStates`(풀린 지 `alert_mail.state_retention` 지난 행). 도움말 `GuideLinks::CONFIG_TABS` `admin_mail` → `admin-mail`.

## 56. 하드닝 (P31)

- **권한 행렬** `tests/Integration/RouteAuthMatrixTest`: 등록 라우트 전부를 손으로 적은 수준 표(P·L·A·D·J·JA + 핸들러 판정 표)와 역할 5개로 실제 요청해 대조한다. 새 라우트를 등록하면 이 표에도 적어야 시험이 통과한다(누락 방지). CSRF 예외 표 = `CsrfMiddleware::EXEMPT_PATHS`.
- **응답**: 보안 헤더에 Permissions-Policy, 화면 HTML `private, no-cache`, HSTS는 앱 한 곳(`SecurityHeadersMiddleware`). SQLite 쓰기 잠금 대기 초과(`SQLITE_BUSY`)는 `ErrorMiddleware`가 503 `db_busy` + Retry-After로(500 아님).
- **로그·잠금**: 로그인 실패 로그는 실제 계정이 아니면 입력 ID 원문 대신 `LoginService::loginHint`. 본인 비밀번호 변경의 현재 비밀번호 불일치는 로그인 잠금 규칙과 같은 횟수에서 잠그고 세션을 끊는다(`UserService::changeOwnPassword`).
- **SSRF**: `HostPolicy::classify`가 NAT64(`64:ff9b::/96`)·6to4(`2002::/16`)를 안쪽 IPv4로 판정.
- **초기 관리자 비밀번호**: 판정 한 곳 `App::initialPasswordAdmins()`(= 하드닝 점검의 `UserRepository::initialPasswordPendingAdmins`) → 레이아웃 배너(admin, 닫기 없음)·모니터링 화면 경고 한 건(`/health` 제외). `App::isLocalBaseUrl`이 아니면 문구를 강하게.
- **점검 탭 보안 줄** `EnvironmentCheck::security`(프로세스 없음): 설정 폴더·파일 쓰기, 비밀 파일 위치·권한, public/ 노출, display_errors·expose_php, 세션 저장. 상태 `info`·`unknown` 추가.
- **감사 로그 DB 밖 기록** `Services\Audit\AuditAnchor`(`docs/audit_actions.md` 보존과 체인): 워커 `log.audit_anchor_interval`·보존 정리 직후 기록, 무결성 API `anchor`, 모니터링 `audit_anchor_mismatch`, 복원 뒤 파일 교체(`RestoreService`).
- **성능**: 웹 연결 `wal_autocheckpoint = database.web_wal_autocheckpoint`(0, `App::applyWebDbPragmas`), 워커 한가할 때 `database.idle_checkpoint_interval`마다 PASSIVE. 결함 요약은 검색어·경로 포함·과거 Run이면 한 번 훑기, 과거 Run 원본(`RunService::source`)은 표지 뒤 이력을 한 번 모아 잇기, 목록 뒤쪽 절반 페이지는 정렬을 뒤집어 고르기(`DefectFilter::orderBy(..., reverse)`) — `docs/performance.md`.
- **배포 묶음** `tools/build_bundle.php` → `dist/`(검증 `tools/verify_bundle.php` — `data/`는 빈 뼈대만 허용). 문서 링크 검사 `tools/check_doc_links.php`·`DocLinksTest`, 목차 `docs/README.md`.

## 분석 행 삭제 금지 (P08 검토 반영)

`analyses` 행은 프로젝트 삭제(CASCADE) 때만 지운다. 개별 삭제하면 그 분석에서 처음 감지된 결함이 연쇄 삭제된다(`docs/db_schema.md` 12절). 정리는 스냅샷과 파일 목록 본문만 비운다.
