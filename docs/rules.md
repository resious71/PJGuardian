# 규칙 설명 데이터 (P22, 명세 11장)

규칙 설명 페이지(주 메뉴 "규칙 설명", `/rules`)가 보여 주는 ruff·ESLint 규칙 정보가 어디서 오고 어떻게 적재·갱신되는지 적는다.
구조와 화면 동작(언어 탭 Python · ruff / JavaScript/TypeScript · ESLint, X08)은 `docs/architecture.md` 37절, 표는 `docs/db_schema.md` 3.14.

## 1. 데이터 출처 (런타임에 네트워크를 쓰지 않음)

| 도구 | 실행(고정 경로·고정 인자, 시간 제한 120초·출력 64MB) | 얻는 것 |
| --- | --- | --- |
| ruff | `bin/ruff/<버전>/<os>/ruff rule --all --output-format json` | 코드, 이름, 요약, **상세 설명(마크다운, 대부분 예시 포함)**, linter, category, 수정 가능(Always/Sometimes/None), 상태(Stable/Preview/Deprecated/Removed + since) |
| ruff | `ruff check --show-settings --no-cache --config config/ruff.default.toml <빈 파일>` | **기본 활성** = 서버 기본 설정(업로드 설정이 없을 때 쓰는 설정)에서 켜진 규칙(`linter.rules.enabled`) |
| ESLint 코어 + `@typescript-eslint` | `node tools/severity/eslint_rules.cjs bin/eslint/<버전>` | 규칙 이름, `meta.docs.description`(요약), `meta.docs.url`(공식 문서), `meta.type`(카테고리), `fixable`, `hasSuggestions`, 옵션 스키마 유무, `deprecated`·`replacedBy`, 권장 설정 포함 여부(= **기본 활성**: 서버 설정이 `eslint:recommended` + `plugin:@typescript-eslint/recommended`) |

- 추출 코드는 `Services\Rules\RuleExtractor` 한 곳이다. P21S의 규칙 카탈로그(`tools/severity_coverage.php --write-catalog`)도 같은 코드를 쓴다(카탈로그 형식은 그대로).
- **도구가 내놓지 않는 것은 지어내지 않는다**: ESLint는 상세 설명·예시가 meta에 없어 "설명 없음"과 공식 문서 링크만, ruff는 문서 주소를 내놓지 않아 링크 없음.
- 기본 심각도는 P21S 매핑(`resources/severity`, `SeverityMap::explain`)으로 적재할 때 정해 저장한다(값·근거 단계 rule/prefix/group/none·근거 문장).
- 설명 본문은 텍스트로 저장하고, 화면은 안전한 마크다운 부분집합(`public/assets/js/lib/markdown.js`: 문단·제목·목록·코드 블록·인라인 코드·굵게·기울임·http(s) 링크)만 DOM으로 그린다. 원시 HTML은 글자 그대로 보인다.

## 2. 적재

```
php tools/rules_load.php [--config=<ini>] [--tool=ruff|eslint] [--version=<버전>] [--all-installed] [--apply]
```

- 기본은 미리보기(도구 실행 + 건수만, DB 그대로). `--apply`로 반영(감사 로그 `rules.load`).
- 대상: 도구마다 **활성 버전**(`ToolRegistry::select` — 분석이 쓰는 버전, 고정·차단 반영) + **아직 적재하지 않은 설치 버전**(`bin/`의 다른 버전, 변경 내역용). 심각도 매핑 버전이 바뀐 버전도 다시 적재한다. `--all-installed`는 설치된 모든 버전을 다시 확인한다.
- **멱등**: 버전마다 내용 해시(규칙 내용 + 구성 요소 버전 + 매핑 버전)가 같으면 건드리지 않는다.
- 적재 뒤 도구별로 인접 버전의 변경 내역을 다시 계산하고 검색 색인(FTS5 trigram)을 다시 만든다.
- 한 도구·버전이 실패해도(도구 없음·실행 오류·시간 초과) 나머지는 적재한다. 분석에는 영향이 없다. 종료 코드 1.

## 3. 자동 갱신

- 워커가 **시작할 때와 `rules.load_interval`(기본 1시간)마다** 관리 작업 `rules_load`를 요청한다(대기 중이면 합쳐짐, `rules.auto_load = false`면 끔).
- 관리자 화면에서 도구를 고정·고정 해제·차단·차단 해제하거나 재검증하면 바로 요청한다.
- 작업은 워커가 자식 프로세스(`tools/run_admin_task.php`)로 실행한다(분석과 같은 큐 규칙, 시간 제한 `worker.admin_task_timeout`). 바뀐 것이 없으면 아무것도 바꾸지 않는다.
- 규칙 설명 페이지 위쪽에 기준 버전과 마지막 적재 시각을 보이고, 활성 도구 버전과 적재 버전이 다르면 "규칙 데이터가 최신이 아닙니다"를 표시한다.
- admin은 규칙 설명 페이지의 "규칙 데이터 다시 불러오기"로 즉시 요청할 수 있다(연타해도 요청 하나, 감사 로그 `rules.reload_request`).

## 4. 버전 변경 내역 판정

인접한 두 적재 버전(도구별, semver 순)의 같은 규칙을 비교한다. 가장 오래된 적재 버전은 **기준 버전**이라 변경 내역이 없다.

| 종류 | 판정 |
| --- | --- |
| added / removed | 새 버전에만 / 이전 버전에만 있음 |
| renamed | 이전 버전의 규칙이 폐기 정보의 대체 규칙(`replacedBy`)으로 가리킨 규칙이 새 버전에 새로 생기고 이전 규칙은 사라짐 — **도구가 알려 준 경우만**(추측하지 않음, 없으면 removed + added) |
| description | 요약이 바뀜(요약이 같으면 상세 설명이 바뀜) |
| default_enabled / default_severity / fixable | 기본 활성 / 기본 심각도 / 자동 수정 값이 바뀜 |
| deprecated | 폐기 예정 여부가 바뀜 |

규칙의 내용 해시가 같으면 변경 없음으로 본다.

## 5. 사전 작성 보강 자료(선택)

명세 11장의 "예시와 상세 설명 보강". 두 가지가 있다.

- **쉬운 설명 `guide`(P32B)**: PJGuardian이 직접 쓴 쉬운 설명·나쁜 예·고친 코드·고치는 법(한국어·영어). 도구 원문을 덮어쓰지 않고 규칙 설명 화면과 결함 상세에 따로 보인다. 서버 기본 설정에서 켜지는 규칙 — ruff E·F 61개 중 59개, ESLint 권장 84개 중 65개(기본 48·TS 17). 형식 `{guide: {name?, lang?, bad, good, ko: {summary, fix}, en: {summary, fix}, checked}}`.
  - **사실 확인**: 각 규칙의 도구 원문(`ruff rule <코드>`, ESLint `meta.docs`·`messages`)을 읽고 그와 어긋나지 않게 쓴다. `node tools/rules_guide_check.mjs --write`가 bin/의 실제 도구로 "나쁜 예는 그 규칙에 걸리고 고친 코드는 걸리지 않음"(ruff는 규칙 이름도)을 확인하고 `checked: {tool, version}`을 적는다. **checked가 없으면 화면에 나오지 않는다.** 도구 버전을 올리면 다시 돌린다(시험 `RuleGuideDataTest`가 규칙 목록·버전과 맞는지 검사).
  - 넣지 않은 규칙(예시를 짧게 만들 수 없거나 드묾): `docs/unverified.md` 2절.
- **도구 값 채우기(summary·description·docs_url)**: 도구가 비워 둔 항목만 채운다. 공식 문서를 그대로 옮길 때는 출처(`sources`, https)가 필수다. 지금은 쓰지 않는다(공식 문서를 내려받지 않았고, 지어낸 설명을 넣지 않는다).

- 파일: `resources/rules/enrichment/ruff.json`, `eslint.json`
- 형식:

```json
{
  "format": 1,
  "rules": {
    "no-undef": {
      "description": "## 예시\n```js\n…\n```",
      "sources": ["https://eslint.org/docs/latest/rules/no-undef"]
    }
  }
}
```

- 항목: `summary`, `description`(마크다운), `docs_url`(https), **`sources`(https 출처 주소 1개 이상, 필수)**. 그 밖의 키는 거부한다.
- 도구가 내놓은 값이 우선이다. **비어 있는 항목만** 채운다(ESLint 상세 설명, ruff 문서 주소 등). 채운 규칙의 화면에는 "사전 작성 보강 자료(출처: …)"가 보인다.
- 절차: 공식 문서의 사용 조건(라이선스)을 확인한 뒤 내용을 옮기고 출처를 적는다 → `php tests/run.php RuleLoadTest`(형식 검사) → `php tools/rules_load.php --all-installed --apply`.

## 5a. 관리자 규칙 켜기·끄기 (P22B)

- admin은 규칙 상세의 "분석 적용" 구역에서 "이 규칙 켜기"·"이 규칙 끄기"·"기본값으로 되돌리기"를 누른다(확인 창: 변경 내용, 다음 분석부터 적용, 끈 규칙의 열린 결함은 다음 분석에서 해결됨이 될 수 있음, 서버 전체 열린 결함 수, 사유 선택 200자). 일반 사용자는 "적용 중/꺼짐"과 "관리자 변경" 표시만 본다(변경자·사유 없음).
- 목록의 "분석 적용" 열(적용 중/꺼짐 + 관리자 변경 배지, 툴팁에 기본값)과 조건 "분석 적용"(전체·적용 중·꺼짐)·"관리자 변경"(변경된 것만). 예전 주소의 `default_enabled=`는 "분석 적용"으로 읽는다.
- 켤 수 없는 규칙(버튼 비활성 + 이유): 도구에서 삭제됨(removed), 폐기 예정(deprecated — 끌 수만), ruff 미리보기(preview), 타입 정보 필요(ESLint `requiresTypeChecking`), 옵션 없이는 검사하지 않음(`resources/rules/requires_options.json` — 조사: 실제 도구로 옵션 없이 켜고 걸릴 코드에서 메시지 0개), 특수 키(syntax-error 등).
- 변경 목록·한 건 되돌리기·도구별 전체 되돌리기(도구 이름 입력, 한 번에 `rules.override_reset_max`건): 시스템 관리 → 규칙 설정. 감사 로그 `rules.override_set`·`rules.override_clear`·`rules.override_reset_all`.
- 반영 시점: 분석이 **시작할 때** 한 번 읽어 그 분석 동안 고정(큐에서 기다리는 동안의 변경은 반영, 이미 시작한 분석은 그대로). 도구 버전이 바뀌어 그 버전에 없는 규칙·켤 수 없게 된 규칙은 적용하지 않고 경고 로그(관리 화면에 "활성 버전에 없음"/"켤 수 없게 된 규칙").
- 서버 기본 설정 파일과의 관계는 `docs/tool_setup.md` 5.0절.
- **목록에서 바로(P22C)**: admin은 규칙 목록의 "분석 적용" 칸 스위치로 켜고 끈다(상세와 같은 확인 창, 그 행만 바뀌고 쪽·조건·스크롤 위치는 그대로).
- **일괄 변경(P22C)**: 행 앞 체크박스로 고르거나 머리글 체크박스로 이 쪽 전체를 고른 뒤 막대의 "선택한 규칙 켜기/끄기/기본값으로". 이 쪽을 모두 골랐고 결과가 더 있으면 "검색 결과 전체 M개 선택"으로 마지막으로 검색한 조건의 결과 전체가 대상이 된다. 미리보기(바뀔 수·그대로·건너뜀 이유별·예시)를 보고 확인한다(검색 결과 전체이거나 20개를 넘으면 도구 이름 입력). 한 번에 `rules.bulk_max`(기본 1000)개까지.
  - 예: ruff 탭에서 규칙 키 접두사 `D` + 분석 적용 "꺼짐"으로 검색 → 이 쪽 전체 선택 → "검색 결과 전체 선택" → "선택한 규칙 켜기" = pydocstyle 규칙을 한꺼번에 켬. 되돌리기는 "관리자 변경: 변경된 것만"으로 검색 → 전체 선택 → "기본값으로".
  - ruff는 서로 맞지 않는 규칙 쌍(D203↔D211, D212↔D213)을 함께 켜면 한쪽(D203·D213)을 스스로 끈다(화면은 "적용 중"으로 보이지만 분석에서는 나오지 않음). 함께 켜지 않는다.

## 6. "설명 없음" 기준

- 요약·상세 설명이 비어 있으면 "설명 없음", 문서 주소가 없으면 "도구가 문서 주소를 주지 않음", 옵션 스키마 정보가 없으면 "알 수 없음".
- PJGuardian 특수 키(`syntax-error`, `eslint:unused-disable-directive`, `eslint:no-rule-id`)는 도구 규칙이 아니라 앱이 붙인 키라 앱의 설명(결함 기록 방식)만 보인다.
