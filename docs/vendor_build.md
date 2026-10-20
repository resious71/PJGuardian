# 서드파티 빌드 — 프런트엔드 번들(P17)·PHP 의존성(Composer, P28D)

명세 2장(외부 라이브러리는 로컬 포함, CDN 금지)에 따라 브라우저용 라이브러리는 저장소에 결과물만 넣고 배포한다.
`node_modules`는 커밋·배포하지 않는다(`.gitignore`).

## 1. CodeMirror 6 (코드 뷰어)

CodeMirror 6은 ES 모듈 수십 개로 나뉘어 있어 그대로는 `<script type="module">`로 불러오기 어렵다.
그래서 **빌드할 때만** npm과 esbuild로 ES 모듈 하나를 만든다.

| 위치 | 내용 | 커밋 |
| --- | --- | --- |
| `tools/vendor/codemirror/package.json` | 정확한 버전 고정(범위 없음) | ○ |
| `tools/vendor/codemirror/package-lock.json` | 무결성 값(sha512) 포함 잠금 파일 | ○ |
| `tools/vendor/codemirror/entry.js` | 번들에 넣을 것만 내보냄(읽기 전용 뷰어에 필요한 것) | ○ |
| `tools/vendor/codemirror/node_modules/` | 빌드 중에만 생김 | × |
| `public/assets/vendor/codemirror/codemirror.js` | 결과 번들(ESM, minify, es2020) | ○ |
| `public/assets/vendor/codemirror/LICENSES.txt` | 번들에 실제로 들어간 패키지의 라이선스 원문(esbuild metafile 기준) | ○ |
| `public/assets/vendor/codemirror/SOURCE.txt` | 패키지 버전, 크기, SHA-256 | ○ |

### 빌드

```
node tools/build_vendor.mjs codemirror
```

- 순서: `npm ci`(잠금 파일이 없으면 `npm install`) → esbuild `bundle + format=esm + minify + target=es2020` → 라이선스·버전 기록.
- npm은 지금 실행 중인 node와 함께 설치된 `npm-cli.js`를 셸 없이 실행한다(Windows의 `npm.cmd`는 셸이 필요하므로).
- 네트워크가 필요하다(npm 레지스트리). 사내망에서 막히면 사내 npm 미러(`npm config set registry …`)를 쓴다.
- 같은 잠금 파일이면 같은 결과가 나온다(날짜 같은 가변 값을 넣지 않는다). `SOURCE.txt`의 SHA-256으로 확인한다.
- esbuild의 `postinstall`은 npm 11의 install-scripts 경고로 실행되지 않지만, esbuild는 플랫폼별 선택 의존성(`@esbuild/win32-x64` 등)의 실행 파일을 직접 쓰므로 빌드에는 영향이 없다(Windows 확인, Linux 미확인).

### 결과(2026-10-04, Windows, Node 24.21.0)

- `codemirror.js` 431,615바이트(421.5KB), 패키지 15개(모두 MIT).
- `@codemirror/autocomplete`는 직접 넣지 않았지만 언어 패키지(lang-python, lang-javascript)가 의존해 들어간다. 자동 완성을 켜지 않으므로 동작에는 쓰이지 않는다.

### 버전 올리기

1. `tools/vendor/codemirror/package.json`의 버전을 바꾼다(정확한 버전).
2. `tools/vendor/codemirror/package-lock.json`을 지우고 `node tools/build_vendor.mjs codemirror`(새 잠금 파일 생성).
3. `SOURCE.txt`의 버전 목록을 `THIRD_PARTY_NOTICES`의 CodeMirror 항목에 반영한다.
4. 결함 상세 화면을 브라우저로 열어 콘솔에 CSP 위반이 없는지 확인한다(`tools/check_defects_ui.mjs`).

### 쓰는 곳

- `public/assets/js/lib/code_viewer.js`만 번들을 불러온다(`import … from '../../vendor/codemirror/codemirror.js'`).
- 결함 상세 화면(`pages/defect_detail.js`)이 코드를 받은 뒤 동적 import로 불러온다(목록 화면은 번들을 받지 않는다).

### CSP

CodeMirror(style-mod)는 테마 CSS를 `<style>` 요소로 문서에 넣는다. `style-src 'self'`만으로는 막힌다(브라우저에서 확인:
`securitypolicyviolation style-src-elem inline …codemirror.js`). 그래서 요청마다 새 nonce를 만들어 `style-src 'self' 'nonce-…'`로 그 요소만 허용하고,
`<meta name="csp-nonce">` → `EditorView.cspNonce`로 넘긴다. `unsafe-inline`은 쓰지 않는다(`docs/architecture.md` 5절, 25절).

## 2. Chart.js 4 (대시보드, P20)

CodeMirror와 같은 방식이다(`node tools/build_vendor.mjs chartjs`, 인자 없이 실행하면 모든 대상).

| 위치 | 내용 | 커밋 |
| --- | --- | --- |
| `tools/vendor/chartjs/package.json` | `chart.js` 4.5.1(정확한 버전), esbuild 0.28.2 | ○ |
| `tools/vendor/chartjs/package-lock.json` | 무결성 값 포함 잠금 파일 | ○ |
| `tools/vendor/chartjs/entry.js` | 대시보드에 필요한 것만 등록: 선·막대 컨트롤러, 범주·선형 축, 점·선·막대 요소, 툴팁, 범례 | ○ |
| `public/assets/vendor/chartjs/chart.js` | 결과 번들(ESM, minify, es2020), `export { Chart }` | ○ |
| `public/assets/vendor/chartjs/LICENSES.txt`, `SOURCE.txt` | 라이선스 원문(chart.js, @kurkle/color — 모두 MIT), 버전·크기·SHA-256 | ○ |

### 결과(2026-10-05, Windows, Node 24.21.0)

- `chart.js` 173,190바이트(169.1KB), 패키지 2개(chart.js 4.5.1, @kurkle/color 0.3.4). 전체 UMD(약 200KB)에서 파이·레이더·시간 축 등이 빠졌다.
- 같은 잠금 파일로 다시 빌드하면 같은 SHA-256(`302a6658…`)이 나온다. CodeMirror도 다시 빌드해 바뀌지 않음을 확인했다.
- CSP: Chart.js 4는 `<style>`을 넣지 않고 캔버스 크기를 CSSOM(`element.style`)으로만 바꾼다. 헤드리스 Chrome에서 대시보드의 CSP 위반 0건(nonce도 쓰지 않음). 화면 코드도 막대 너비 등을 `style` 속성이 아니라 CSSOM으로 정한다.

## 3. 시험 전용 의존성(배포하지 않음)

- `tests/tools/package.json`(+ 잠금 파일): `yaml` 2.9.1 — `tests/js/openapi.test.mjs`가 `docs/openapi.yaml` 전체를 엄격한 파서로 읽는다. `tools/run_all_tests.php`가 `tests/tools/node_modules`가 없으면 `npm ci`로 설치한다. `public/`·배포물에는 들어가지 않는다.

## 3. PHP 의존성 — Composer (P28D)

PHP 라이브러리는 두 곳에만 쓴다: SAML 로그인(P28D)의 서명 검증 — `onelogin/php-saml` 4.3.2(MIT)와 그 의존성 `robrichards/xmlseclibs` 3.1.5(BSD-3-Clause), 관리자 장애 이메일(P30B)의 메시지 구성·SMTP 전송 — `phpmailer/phpmailer` 7.1.1(LGPL-2.1, 의존성 없음, 고치지 않고 그대로 사용). 라이선스 원문은 `THIRD_PARTY_NOTICES`.

| 위치 | 내용 | 커밋 |
| --- | --- | --- |
| `composer.json` | 정확한 버전 고정(`onelogin/php-saml: 4.3.2`, `phpmailer/phpmailer: 7.1.1`), `config.platform.php = 8.2.0`(PHP 8.2 호환으로 풂) | ○ |
| `composer.lock` | 잠금 파일(배포는 이 파일 그대로 `install`) | ○ |
| `vendor/` | `composer install`이 만듦, 부트스트랩이 `vendor/autoload.php`를 불러옴 | ×(git 무시) — **배포 묶음에는 포함** |

### 빌드·배포

```
composer install --no-dev --optimize-autoloader     # 개발 PC: C:\xampp\php\php.exe C:\tools\composer\composer.phar install --no-dev
composer audit                                      # 알려진 취약점 0건 확인
```

- 운영 서버는 인터넷 없이 배포할 수 있게 **빌드 PC에서 만든 `vendor/`를 배포 묶음에 함께 넣는다**(서버에서 Composer를 돌리지 않아도 됨).
- `vendor/`가 없으면 SAML만 꺼진 것처럼 동작하고(로그인 단추 없음) 점검 화면에 이유가 보인다. 관리자 이메일은 "설정 필요(메일 라이브러리)"로 보내지 않는다. 나머지 기능은 PHP 의존성이 없다.
- 업데이트 절차: `docs/auth_saml.md` 0절.

## 4. 배포 묶음 점검 (P29)

운영 배포 묶음에는 **`vendor/`(Composer — SAML·PHPMailer)** 와 **`resources/guide/`(가이드 본문·그림)** 가 반드시 들어가야 한다. P31부터는 스크립트로 만든다.

```
php tools/build_bundle.php [--out=dist] [--zip] [--with-bin] [--force]
```

- 저장소 파일(커밋된 것 + 무시 목록에 없는 새 파일)과 빌드 PC의 `vendor/`를 `dist/<pjguardian-버전-UTC시각-커밋>/`에 복사하고, `BUNDLE.json`(앱 버전·커밋·작업 트리 변경 여부·파일 수)과 `SHA256SUMS`(묶음 안 모든 파일)를 쓴 뒤 **바로 `tools/verify_bundle.php`로 검증**한다(실패하면 종료 코드 1 — 그 묶음은 쓰지 않는다). `--zip`이면 같은 이름의 zip도 만든다. `dist/`는 git이 무시한다.
- 넣지 않는 것: `tests/`, `tools/guide_demo/`, `tools/acceptance/`, `samples/zips`·`samples/zips_bad`, `docs/results/`, `prompts/`, 요구사항 명세. `data/`는 빈 폴더 뼈대(`.gitkeep`)만 넣는다(설치하자마자 폴더가 있게 — 데이터 파일이 있으면 검증 실패). 설정·비밀(`config.ini`·`settings.local.ini*`·`secrets.*`)은 git이 관리하지 않으므로 애초에 들어가지 않는다.
- `bin/`은 매니페스트(SHA256SUMS·VERSION·package*.json)만. `--with-bin`이면 매니페스트에 적힌 실행 파일도 넣는다(인터넷이 막힌 운영 PC용 — 크다).
- 커밋하지 않은 변경이 있으면 멈춘다(`--force`면 `BUNDLE.json`에 `dirty: true`로 남기고 계속 — 시험용).
- P31 확인(Windows): 만든 zip(4.4MB, 1,036개)을 새 폴더에 풀어 설치 가이드 순서(설정 복사 → `make_secret_key` → `check_env`(FAIL 0) → `migrate`(031까지) → `create_admin`)대로 설치하고 내장 서버로 로그인 200·대시보드·정적 파일을 확인했다. Linux·Apache 설치는 미확인(`docs/unverified.md`).

수동으로 만들 때의 절차(스크립트가 하는 일과 같다):

1. 빌드 PC에서 `composer install --no-dev --optimize-autoloader`(3절).
2. 저장소 파일에 `vendor/`를 더해 묶음 폴더를 만든다. 넣지 않을 것(`tests/`, `.git/`, `data/`의 데이터 파일, `config/config.ini`, `config/settings.local.ini*`, `config/secrets.*`(`secrets.example` 제외), `*.pjgkey`)은 3번의 도구가 검사한다(들어 있으면 오류 — `config/*.example`은 허용, X14).
3. `php tools/verify_bundle.php <묶음 폴더>` — 있어야 할 것(`vendor/autoload.php`, `resources/guide/user/ko`·`en`, FAQ 시드 `resources/faq/seed.ko.json`(X16), 도구 스모크 시료 `samples/python_sample`·`python_syntax_error`·`js_sample_noconfig`·`ts_sample`(P32), `screenshots.json`이 가리키는 그림 전부, `composer.lock`과 `vendor/composer/installed.json` 버전 일치)과 없어야 할 것(개발 전용·비밀·데이터·설치마다 다른 설정 `config/config.ini`·`settings.local.ini*`)을 검사하고 문제가 있으면 목록을 출력하고 종료 코드 1(읽기 전용, 파일을 바꾸지 않음).

