# 샘플별 기대 결함 목록

이후 단계(P03~P05)의 테스트가 기준으로 삼는 목록이다. **도구를 직접 실행한 결과만** 적는다. 실행하지 못한 샘플은 "미확인"으로 둔다.

| 샘플 | 도구 | 설정 | 기대 결함 수 | 상태 |
| --- | --- | --- | --- | --- |
| `python_sample/` | ruff 0.16.10 | E,F | 19 | 확인 (Windows, 2026-10-02) |
| `python_syntax_error/` | ruff 0.16.10 | E,F | 2 (문법 오류) | 확인 (Windows, 2026-10-02) |
| `js_sample/` | ESLint 8.57.1 | 업로드된 `.eslintrc.json` | 9 | 확인 (Windows, 2026-10-02) |
| `js_sample_noconfig/` | ESLint 8.57.1 | 임시 기본 설정 | 5 | 확인 — P03 기본 설정 확정 후 재확인 |
| `js_with_js_config/` | ESLint 8.57.1 | `.eslintrc.js`를 따름 | 0 | 확인 (대조용: 서버는 이렇게 나오면 안 됨) |
| `js_with_js_config/` | ESLint 8.57.1 | 임시 기본 설정(JS 설정 무시) | 4 | 확인 — P03 기본 설정 확정 후 재확인 |
| `ts_sample/` | ESLint 8.57.1 | 임시 기본 설정 | 6 | 확인 — P03 기본 설정 확정 후 재확인 |
| `empty_no_target/` | — | — | 0 (대상 파일 없음) | 대상 확장자 파일 0개 (사전 검사 경고 시험용) |

## 0. 서버 기준 기대값 (P15 확정, ESLint 10.11.0 + 서버 flat 설정)

명세 19.10에 따라 서버는 업로드된 ESLint 설정을 **통째로 무시**한다. 그래서 `js_sample/`도 서버 기본 설정으로 분석되어 **5건**이다(아래 2.1의 9건은 업로드 설정을 따를 때의 대조값). P15 시험(`ToolRunnersTest`)과 도구 스모크 테스트는 이 표를 기준으로 한다(Windows, 2026-10-04 확인).

| 샘플 | 도구 | 기대 결함 수 | 비고 |
| --- | --- | --- | --- |
| `python_sample/` | ruff 0.16.10(서버 기본 E,F) | 19 | 1.1절 |
| `python_syntax_error/` | ruff 0.16.10 | 2(모두 `syntax-error`) | 1.2절 |
| `js_sample/` | ESLint 10.11.0(서버 설정) | 5 | 업로드 `.eslintrc.json` 무시 기록, 2.2절과 같은 5건 |
| `js_sample_noconfig/` | ESLint 10.11.0 | 5 | 2.2절 |
| `js_with_js_config/` | ESLint 10.11.0 | 4 | `.eslintrc.js` 무시 기록, 설정 파일은 분석 대상 아님 |
| `ts_sample/` | ESLint 10.11.0 | 6 | 2.4절 |
| `empty_no_target/` | 두 도구 | 0 | 분석 성공 |

## 1. Python (ruff)

실행 명령(샘플 폴더를 작업 디렉토리로 하여 실행):

```
bin/ruff/0.16.10/windows/ruff.exe check --isolated --select E,F --no-cache --output-format json .
```

- 규칙 집합은 P03에서 만들 서버 기본 설정(`config/ruff.default.toml`, 규칙 E, F)과 같게 `--select E,F`로 지정했다. `--isolated`는 다른 설정 파일을 읽지 않게 한다.
- 열 번호는 ruff 기준(1부터)이다.

### 1.1 `python_sample/` — 19건

규칙별: F401 6, E711 4, F821 3, F841 2, F811 1, E722 1, E712 1, E731 1

| 파일 | 줄 | 열 | 규칙 | 메시지 |
| --- | --- | --- | --- | --- |
| `app/handlers/api.py` | 2 | 8 | `F401` | `re` imported but unused |
| `app/handlers/api.py` | 3 | 20 | `F401` | `typing.List` imported but unused |
| `app/handlers/api.py` | 10 | 5 | `F811` | Redefinition of unused `handle` from line 6: `handle` redefined here |
| `app/models.py` | 9 | 25 | `E711` | Comparison to `None` should be `cond is None` |
| `app/models.py` | 14 | 25 | `E711` | Comparison to `None` should be `cond is None` |
| `app/models.py` | 20 | 11 | `F821` | Undefined name `missing_logger` |
| `app/models.py` | 24 | 11 | `F821` | Undefined name `missing_logger` |
| `app/services.py` | 2 | 8 | `F401` | `json` imported but unused |
| `app/services.py` | 6 | 5 | `F841` | Local variable `unused_total` is assigned to but never used |
| `app/services.py` | 9 | 5 | `E722` | Do not use bare `except` |
| `config_loader.py` | 5 | 20 | `E711` | Comparison to `None` should be `cond is None` |
| `config_loader.py` | 7 | 8 | `E712` | Avoid equality comparisons to `True`; use `settings.get("debug"):` for truth checks |
| `main.py` | 2 | 8 | `F401` | `os` imported but unused |
| `main.py` | 3 | 8 | `F401` | `sys` imported but unused |
| `main.py` | 11 | 11 | `F821` | Undefined name `undefined_config` |
| `utils/helpers.py` | 3 | 1 | `E731` | Do not assign a `lambda` expression, use a `def` |
| `utils/helpers.py` | 7 | 21 | `E711` | Comparison to `None` should be `cond is None` |
| `utils/한글_도구.py` | 2 | 8 | `F401` | `datetime` imported but unused |
| `utils/한글_도구.py` | 8 | 5 | `F841` | Local variable `메시지` is assigned to but never used |

**시그니처 순번 시험용 중복**: `app/models.py`에는 정규화 후 완전히 같은 결함 줄이 두 쌍 있다.

- 9줄과 14줄: `if self.name == None:` (E711)
- 20줄과 24줄: `print(missing_logger)` (F821)

`utils/helpers.py` 7줄(`return value == None`)은 같은 E711이지만 다른 파일·다른 코드이므로 중복이 아니다.

**결함 없는 파일**: `app/__init__.py`, `app/handlers/__init__.py`(빈 파일), `tests/test_basic.py`

**한글**: `utils/한글_도구.py`는 한글 파일명, 한글 식별자, 한글 주석을 포함한다.

### 1.2 `python_syntax_error/` — 2건

| 파일 | 줄 | 열 | 규칙 | 메시지 |
| --- | --- | --- | --- | --- |
| `broken.py` | 4 | 17 | `invalid-syntax` | Expected `)`, found newline |
| `broken.py` | 5 | 5 | `invalid-syntax` | Expected an expression |

- 문법 오류는 `broken.py` 하나에서 2건 보고된다. `ok_one.py`, `ok_two.py`는 결함 0건이다.
- ruff 0.16.10 종료 코드(Windows에서 확인): 결함 있음 1(이 샘플과 `python_sample/` 모두), 결함 없음 0, 잘못된 옵션 2. 결함 있음(1)과 실행 실패를 구분하는 방법은 P03에서 정한다.

> **P03 주의 — ruff는 기본적으로 `.gitignore`를 따른다.** 분석 디렉토리가 git 저장소 안의 무시된 경로(예: 이 저장소의 `data/work/`)에 있으면 ruff가 파일을 하나도 분석하지 않는다. 경고(`No Python files found`)만 내고 종료 코드 0으로 끝나 성공처럼 보인다.
> `--no-respect-gitignore`를 주면 정상적으로 분석된다(확인함). 업로드된 프로젝트 안의 `.gitignore`를 제외 규칙으로 인정할지는 명세 4장(제외 대상)과 함께 P03에서 정한다.

> **P03 주의 — ruff 버전에 따라 문법 오류 표현이 다르다.** 명세 6.1은 "ruff의 코드 없는 진단"을 문법 오류로 본다.
> ruff 0.16.10은 `"code": "invalid-syntax"`, `"name": "invalid-syntax"`를 준다. 같은 파일을 ruff 0.12.0으로 실행하면 `"code": null`이고 메시지가 `SyntaxError: ...`로 시작한다.
> 파서는 두 형태를 모두 `syntax-error`로 정규화해야 한다.

## 2. JavaScript / TypeScript (ESLint)

ESLint 8.57.1(`bin/eslint/8.57.1/`, Node.js v24.21.0)로 실행했다. ESLint 9.39.5와의 비교는 P03에서 한다. 열 번호는 ESLint 기준(1부터)이고, 모든 결함의 `severity`는 2(error), `fatal`은 없다.

**업로드된 설정을 쓰는 경우**(샘플 폴더에서):

```
node bin/eslint/8.57.1/node_modules/eslint/bin/eslint.js --ext .js,.jsx,.ts,.tsx --format json .
```

**서버 기본 설정을 쓰는 경우**: 서버 기본 설정 파일은 P03에서 만든다. 아래 결과는 다음 **임시 기본 설정**으로 얻은 값이므로, P03이 실제 기본 설정을 정하면 다시 확인한다.

```
node bin/eslint/8.57.1/node_modules/eslint/bin/eslint.js --no-eslintrc -c <임시설정.json> \
  --resolve-plugins-relative-to bin/eslint/8.57.1 --ext .js,.jsx,.ts,.tsx --format json .
```

```json
{
  "root": true,
  "extends": "eslint:recommended",
  "env": { "browser": true, "node": true, "es2022": true },
  "parserOptions": { "ecmaVersion": "latest", "sourceType": "module" },
  "overrides": [
    {
      "files": ["*.ts", "*.tsx"],
      "parser": "<bin/eslint/8.57.1 절대 경로>/node_modules/@typescript-eslint/parser/dist/index.js",
      "plugins": ["@typescript-eslint"],
      "extends": ["plugin:@typescript-eslint/recommended"]
    }
  ]
}
```

### 2.1 `js_sample/` — 9건 (`.eslintrc.json`: eslint:recommended + `eqeqeq`)

규칙별: eqeqeq 4, no-unused-vars 2, no-undef 2, no-unreachable 1

| 파일 | 줄 | 열 | 규칙 | 메시지 |
| --- | --- | --- | --- | --- |
| `src/index.js` | 4 | 7 | `no-unused-vars` | 'unusedVersion' is assigned a value but never used. |
| `src/index.js` | 8 | 10 | `no-undef` | 'undefinedRuntime' is not defined. |
| `src/lib/format.js` | 3 | 12 | `eqeqeq` | Expected '===' and instead saw '=='. |
| `src/lib/math.js` | 3 | 13 | `eqeqeq` | Expected '===' and instead saw '=='. |
| `src/lib/math.js` | 10 | 13 | `eqeqeq` | Expected '===' and instead saw '=='. |
| `src/lib/storage.js` | 3 | 9 | `no-unused-vars` | 'unusedPrefix' is assigned a value but never used. |
| `src/lib/storage.js` | 8 | 51 | `no-undef` | 'missingDefault' is not defined. |
| `src/ui/events.js` | 9 | 12 | `eqeqeq` | Expected '!==' and instead saw '!='. |
| `src/ui/render.js` | 12 | 3 | `no-unreachable` | Unreachable code. |

- **시그니처 순번 시험용 중복**: `src/lib/math.js` 3줄과 10줄은 같은 코드 `if (value == 0) {`의 같은 결함(eqeqeq)이다.
- `src/clean.js`, `src/ui/events.js`의 `bind` 함수는 결함이 없다.

### 2.2 `js_sample_noconfig/` — 5건 (임시 기본 설정)

`js_sample/`과 코드가 같고 `.eslintrc.json`만 없다. 기본 설정에는 `eqeqeq`가 없어 그 4건이 빠진다.

| 파일 | 줄 | 열 | 규칙 | 메시지 |
| --- | --- | --- | --- | --- |
| `src/index.js` | 4 | 7 | `no-unused-vars` | 'unusedVersion' is assigned a value but never used. |
| `src/index.js` | 8 | 10 | `no-undef` | 'undefinedRuntime' is not defined. |
| `src/lib/storage.js` | 3 | 9 | `no-unused-vars` | 'unusedPrefix' is assigned a value but never used. |
| `src/lib/storage.js` | 8 | 51 | `no-undef` | 'missingDefault' is not defined. |
| `src/ui/render.js` | 12 | 3 | `no-unreachable` | Unreachable code. |

### 2.3 `js_with_js_config/` — 설정을 따르면 0건, 무시하면 4건

`.eslintrc.js`가 `no-unused-vars`와 `no-undef`를 끈다. ESLint 8.57.1에 아무 옵션 없이 실행하면 이 JS 설정을 읽어 **0건**이다(종료 코드 0). 서버는 이 설정을 무시하고 기본 설정으로 분석해야 하며(명세 5.3), 그 결과는 아래 **4건**이다(종료 코드 1).

| 파일 | 줄 | 열 | 규칙 | 메시지 |
| --- | --- | --- | --- | --- |
| `src/app.js` | 2 | 7 | `no-unused-vars` | 'unusedFlag' is assigned a value but never used. |
| `src/app.js` | 5 | 10 | `no-undef` | 'notDefinedAnywhere' is not defined. |
| `src/util.js` | 3 | 9 | `no-unused-vars` | 'unusedLocal' is assigned a value but never used. |
| `src/util.js` | 4 | 10 | `no-undef` | 'globalHelper' is not defined. |

### 2.4 `ts_sample/` — 6건 (임시 기본 설정, `@typescript-eslint/recommended`)

규칙별: @typescript-eslint/no-explicit-any 3, @typescript-eslint/no-unused-vars 3

| 파일 | 줄 | 열 | 규칙 | 메시지 |
| --- | --- | --- | --- | --- |
| `src/api.ts` | 2 | 29 | `@typescript-eslint/no-explicit-any` | Unexpected any. Specify a different type. |
| `src/api.ts` | 2 | 35 | `@typescript-eslint/no-explicit-any` | Unexpected any. Specify a different type. |
| `src/api.ts` | 3 | 9 | `@typescript-eslint/no-unused-vars` | 'unusedHeader' is assigned a value but never used. |
| `src/index.ts` | 4 | 7 | `@typescript-eslint/no-unused-vars` | 'unusedCount' is assigned a value but never used. |
| `src/store.ts` | 4 | 49 | `@typescript-eslint/no-unused-vars` | 'debug' is defined but never used. |
| `src/user.ts` | 5 | 10 | `@typescript-eslint/no-explicit-any` | Unexpected any. Specify a different type. |

- TS 파일에서는 `@typescript-eslint/recommended`가 코어 `no-unused-vars`를 끄고 `@typescript-eslint/no-unused-vars`로 바꾼다. 규칙 키는 `eslint:@typescript-eslint/no-unused-vars` 형태가 된다.
- `src/api.ts` 2줄에는 `any`가 두 번 있어 같은 줄에서 2건이 보고된다(열 29, 35).
- `src/clean.ts`, `src/user.ts`의 `describeUser`는 결함이 없다.

> **P03 주의 — ESLint 종료 코드**: 결함 있음 1, 결함 없음 0(`js_with_js_config`를 설정대로 실행한 경우). 실행 실패와의 구분은 P03에서 정한다.

## 3. zip

`python tools/make_sample_zips.py`로 `samples/zips/`에 만든다. 내용은 위 샘플 폴더와 같다.

| zip | 원본 | 비고 |
| --- | --- | --- |
| `python_sample.zip` | `python_sample/` | |
| `python_sample_toplevel.zip` | `python_sample/` | 모든 항목이 `my_project_v2/` 아래(최상위 폴더 제거 시험용) |
| `python_syntax_error.zip` | `python_syntax_error/` | |
| `js_sample.zip` | `js_sample/` | |
| `js_with_js_config.zip` | `js_with_js_config/` | |
| `ts_sample.zip` | `ts_sample/` | |
| `empty_no_target.zip` | `empty_no_target/` | |
