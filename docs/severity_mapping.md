# 규칙 기본 심각도 매핑 (P21S)

분석에서 새로 감지한 결함에 **규칙(결함 유형)별로 미리 정한 심각도**를 자동으로 넣는다(명세 6.2의 심각도 5단계 그대로).
ruff는 규칙 코드만 주고 심각도가 없고, ESLint는 설정의 warn/error만 있어 도구 자체의 심각도 체계가 없다. 그래서 PJGuardian이 매핑을 직접 가진다.

- 데이터: `resources/severity/ruff.json`, `resources/severity/eslint.json`(사람이 고친다), `*.catalog.json`(도구가 주는 규칙 목록, 생성 파일)
- 적용: `Services\Severity\SeverityMap`(파일을 한 번 읽어 메모리에 둔다. 결함마다 DB를 조회하지 않음)
- 사람이 바꾼 심각도가 **항상 우선**한다. 이미 있는 결함의 심각도는 분석 때마다 다시 계산하지 않는다.

이 문서는 사용자가 검토하는 문서다. 판단이 갈리는 규칙은 6절에 따로 모았다.

## 1. 심각도 5단계와 분류 기준

기준은 **결함이 코드의 동작·안전에 미치는 영향**이다.

| 심각도 | 기준 | 예 |
| --- | --- | --- |
| critical | 보안 취약점이 될 수 있는 규칙, 실행 시 거의 확실히 실패하거나 데이터를 망가뜨리는 규칙 | 하드코딩된 비밀번호(S105~S107), SQL 문자열 조립(S608), `eval`·`exec`(S307·S102, no-eval·no-new-func), 안전하지 않은 역직렬화(S301·S506)·해시(S303·S324)·암호, 인증서 검증 끔(S501), 셸 명령 주입(S602·S605), 양방향 유니코드(PLE2502) |
| high | 실제 버그일 가능성이 높은 규칙, 구문·이름 오류 | 정의되지 않은 이름(F821, no-undef), 도달 불가·항상 같은 조건, 잘못된 비교(F632, valid-typeof), 구문 오류(`syntax-error`), 처리되지 않은 Promise |
| medium | 잠재 문제·유지보수 위험·안티패턴 | 사용하지 않는 변수·import(F841·F401, no-unused-vars), 가변 기본 인자(B006), 과도한 복잡도(C901, complexity), 예외 삼키기(E722·S110·BLE001, no-empty), `any`(no-explicit-any) |
| low | 스타일·서식·이름 규칙·문서·정렬 | 줄 길이(E501), 공백, 따옴표, import 순서(I001), 이름 규칙(N8xx, camelcase), docstring(Dxxx), 최신 문법 바꾸기(UPxxx), prefer-const |
| not_defined | 매핑이 없는 규칙(새 규칙, 모르는 플러그인) | — (현재 활성 도구 버전의 규칙은 0개) |

## 2. 정하는 순서(우선순위)

**개별 규칙 지정 > 접두사 지정(가장 긴 것) > 분류군 기본 > not_defined**

- **분류군**은 도구가 규칙마다 주는 1차 분류다.
  - ruff: 규칙의 `category`(ruff 0.16, `ruff rule --all`이 준다) — security, correctness, suspicious, performance, complexity, pedantic, restriction, style, formatting
  - ESLint: 규칙의 `meta.type` — problem, suggestion, layout
- **접두사**는 ruff의 플러그인 묶음 전체를 한 번에 정할 때 쓴다. 접두사가 글자로 끝나면 다음 글자가 숫자일 때만 맞는다(`D`는 `D100`에 맞고 `DJ003`·`DTZ001`·`DOC201`에는 맞지 않는다).
- **개별 지정**은 보안·치명적 규칙을 올리거나 사소한 규칙을 내리는 예외다. 모든 항목에 근거 한 줄이 있다.
- 규칙 키: 결함의 `rule_key`(`ruff:F401`, `eslint:no-undef`, `eslint:@typescript-eslint/no-explicit-any`)에서 도구 이름을 뗀 이름으로 찾는다. ruff 코드는 대문자로 맞춘다(ESLint 규칙 이름은 대소문자 그대로). 구문 오류(`syntax-error`, 규칙 키 null)는 각 도구 파일의 `syntax-error` 항목(high).
- ESLint 특수 키: `unused-disable-directive`(쓰지 않는 eslint-disable, low), `no-rule-id`(규칙 없는 도구 메시지, low).

### 분류군 기본값

| 도구 | 분류군 | 기본 심각도 | 근거 |
| --- | --- | --- | --- |
| ruff | security | high | 보안 관련 검사. 실제 취약점이 되는 것만 개별로 critical(33개), 위험한 모듈 import 등은 medium·low로 내림 |
| ruff | correctness | high | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | suspicious | medium | 버그일 수 있는 의심스러운 코드·안티패턴(일부 확실한 버그는 high로 올림) |
| ruff | performance | medium | 불필요한 비용(동작은 맞음) |
| ruff | complexity, pedantic, restriction, style, formatting | low | 단순화·엄격한 관례·팀 정책·스타일·서식 |
| ESLint | problem | high | 오류나 혼란을 일으킬 코드(사용하지 않는 변수·정의 전 사용 등은 medium으로 내림) |
| ESLint | suggestion | low | 더 나은 작성 방법(eqeqeq·no-empty·`any` 등은 medium, eval 계열은 critical로 올림) |
| ESLint | layout | low | 공백·서식 |

### 접두사 지정 (ruff)

| 접두사 | 심각도 | 근거 |
| --- | --- | --- |
| PYI | low | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| PT | low | pytest 작성 관례(일부 실제 오류는 개별로 medium) |
| UP | low | 최신 문법으로 바꾸기 — 동작 같음 |
| D, DOC | low | docstring 서식·내용 |
| EXE, CPY, ERA | low | 실행 권한·저작권 표기·주석 처리된 코드 |

## 3. 자동 부여와 사람이 바꾼 값

- **출처**(`defects.severity_source`): `auto` = 매핑이 부여, `manual` = 사람이 바꿈(not_defined로 바꾼 것 포함), `none` = 부여된 적 없음(not_defined).
- **분석 때**: 신규 결함만 매핑 값(출처 auto, 매핑이 없으면 not_defined·none). 재감지·유지·재오픈(자동 해결 뒤 다시 감지 포함)·범위 밖 결함의 심각도와 출처는 바꾸지 않는다. 이력은 남기지 않는다(생성 시점 값이라 과거 Run 복원과 맞다).
- **사람이 바꾸면**(단건·일괄): 출처 manual. 그 뒤 어떤 분석·일괄 채움도 바꾸지 않는다. 이력은 P18 그대로(수동, 변경자, 사유).
- **설정**: `[severity] auto_assign = true`(기본). false면 신규 결함은 not_defined(이전 동작). 매핑 파일 형식이 틀리면 워커가 시작할 때 원인을 출력하고 멈춘다.
- **화면**: 결함 목록 심각도 배지에 마우스·포커스 툴팁("규칙 기본값 — …" / "수동 변경 — …"), 사람이 바꾼 값은 배지 옆에 작은 "수동 변경" 글자. 결함 상세의 심각도 옆에 "규칙 기본값"/"수동 변경". 일괄 변경 영역과 확인 창에 "사람이 바꾼 심각도는 이후 분석에서 바뀌지 않습니다." 대시보드·필터·칩은 코드 변경 없이 새 값으로 센다.

## 4. 기존 결함 일괄 채움 (`tools/severity_apply.php`)

```
php tools/severity_apply.php [--config=<ini> | --db=<sqlite>] [--project=<id>] [--reapply-auto] [--apply] [--chunk=500]
```

- 기본은 미리보기(바꾸지 않음). `--apply`일 때만 바꾼다. CLI 전용(웹에서 실행 불가).
- 대상: 출처 none이고 not_defined인 결함 중 매핑이 있는 규칙. `--reapply-auto`면 출처 auto도 지금 매핑으로 다시 계산(매핑 버전을 올린 뒤). manual은 어떤 옵션으로도 건드리지 않는다.
- 변경마다 이력: 자동, `reason_code = rule_default`, 메모 "규칙 기본 심각도 적용(매핑 ruff v1)", 분석 없음. 과거 Run 보기는 이 이력으로 **적용 전 Run을 not_defined로** 복원한다(P20R). 결함 version을 올린다(열려 있던 편집 화면은 409로 최신 값을 본다).
- 결함 id 순서로 청크(기본 500건)마다 짧은 쓰기 트랜잭션, 청크 사이에 20ms 양보. 각 행은 "지금도 대상 조건일 때만" 바꾸므로 중간에 멈춰도 다시 실행하면 남은 것만 끝난다(두 번째 실행은 0건).
- WAL 체크포인트는 P20S 정책과 같게: 이 도구 연결의 자동 체크포인트를 끄고 100청크마다 PASSIVE(다른 연결을 막지 않음), 끝나면 TRUNCATE(WAL이 `database.wal_truncate_threshold`를 넘었을 때). 청크마다 자동 체크포인트가 돌면 큰 DB에서 커밋 한 번이 0.7~7초였다(`docs/results/P21S.md`). 분석 워커와 같이 돌려도 되지만, 결함이 수십만 건이면 워커가 한가할 때 실행을 권한다.
- 출력: 프로젝트별·심각도별 변경(예정) 건수, 변경 전후 표, 매핑에 없는 규칙 상위 20, 소요 시간과 가장 긴 청크 트랜잭션, 실행 전 백업 명령(`VACUUM INTO`).

## 5. 도구 버전이 바뀌었을 때 (`tools/severity_coverage.php`)

```
php tools/severity_coverage.php [--write-catalog] [--markdown=<파일>] [--strict]
```

1. 새 도구 버전을 `bin/`에 설치·활성화한다(`docs/tool_setup.md`).
2. `php tools/severity_coverage.php`로 대조한다. 도구 목록과 카탈로그가 다르면 알려 준다.
3. `--write-catalog`로 `resources/severity/<도구>.catalog.json`을 다시 쓴다(새 규칙은 분류군 기본값으로 바로 정해진다).
4. 출력의 "not_defined로 남는 규칙"(분류군이 없는 새 규칙)과 "매핑에는 있으나 도구에 없는 규칙"(폐기·이름 변경 후보)을 보고 `<도구>.json`의 개별 지정을 고친다. 보안·치명 규칙이 새로 생겼으면 개별로 올린다.
5. 매핑을 바꿨으면 `mapping_version`을 올리고, 이미 자동 부여된 결함에 새 값을 반영하려면 `tools/severity_apply.php --reapply-auto --apply`(manual은 그대로).
6. `--markdown`으로 이 문서의 8절 표를 다시 만든다. `tests/Unit/SeverityMapTest`가 카탈로그 규칙 전부가 5단계 중 하나인지 확인한다.

규칙 설명 화면(P22)은 이 매핑의 값을 "기본 심각도"로 보여 주고, 버전별 규칙 변경 내역은 이 절차(카탈로그 차이)와 연결한다.

## 6. 판단이 갈리는 규칙 (검토 요청)

| 규칙 | 정한 값 | 다른 안 | 이유 |
| --- | --- | --- | --- |
| `eslint:no-undef` | high | medium | 정의되지 않은 이름은 실행 오류라 high. 테스트 프레임워크 전역(jasmine의 `describe`·`expect` 등) 오탐은 심각도가 아니라 서버 ESLint 설정에서 처리한다(X07: 테스트 파일에만 jasmine·mocha·jest·vitest 전역, Chart.js 사본 5,147 → 799건). 남은 것은 프로젝트 전용 전역(`Chart`, `acquireChart` 등) |
| `ruff:S311` 암호용이 아닌 난수 | high | critical | 기준표는 "안전하지 않은 난수"를 critical 예로 들었지만, 보안과 무관한 `random` 사용에도 모두 걸려 오탐이 많다 |
| ruff security 분류군 기본 | high | critical | 위험한 모듈 import(S4xx)·`assert`처럼 취약점이 아닌 검사가 섞여 있어 기본은 high, 확실한 취약점 33개만 critical |
| `ruff:F401` 사용하지 않는 import | medium | low | 기준표의 예(사용하지 않는 import는 medium)를 따름. 대부분은 지우기만 하면 되는 low 성격 |
| `ruff:E721` type() 비교 | medium | low | 하위 클래스를 놓치는 실제 버그 가능성. 개발 DB 사본 Pandas 170건 |
| `ruff:E731` lambda 대입 | low | medium | 스타일(pedantic). 개발 DB 사본 Pandas 437건 |
| `ruff:E741` 헷갈리는 변수 이름(l, O, I) | low | medium | 이름 규칙 |
| `eslint:@typescript-eslint/no-explicit-any` | medium | low | 타입 검사를 무력화하는 안티패턴 |
| ESLint suggestion 분류군 기본 | low | medium | suggestion 214개 중 대부분이 스타일(prefer-const, curly, camelcase 등). 의미 있는 것(eqeqeq, no-empty 등 30여 개)만 개별로 medium |
| ruff performance 분류군 | medium | low | 동작은 맞지만 큰 데이터에서 문제가 되는 안티패턴 |
| `eslint:no-fallthrough` | medium | high | problem이지만 의도한 fallthrough가 많음 |
| `eslint:require-atomic-updates` | medium | high | problem이지만 오탐이 많음 |
| `ruff:T100` 디버거 | high | medium | 남겨 두면 실행이 멈춤 |
| `ruff:E112`·`E113` 들여쓰기 오류 | high | low | pycodestyle 규칙이지만 실제로는 구문 오류 |
| `ruff:S603`·`S606`·`S607` 셸 없는 프로세스 | medium | high | 셸 주입은 아니지만 입력 검증이 필요 |
| `ruff:S4xx` 위험 모듈 import | medium(S404만 low) | low | 사용 위치는 다른 규칙이 critical로 본다 |
| `eslint:@typescript-eslint/ban-types` | low | medium | @typescript-eslint 8에서 없어진 규칙(이전 버전 분석 결과의 결함에만 있음). 매핑에 남겨 둔다 |

## 7. 요약 (매핑 v1, ruff 0.16.10 · ESLint 10.11.0 + @typescript-eslint 8.71.0)

| 도구 | 규칙 수 | critical | high | medium | low | not_defined | 개별 지정 | 접두사 | 분류군 기본 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ruff | 953 | 33 | 124 | 160 | 636 | 0 | 133 | 189 | 631 |
| ESLint | 428 | 5 | 64 | 77 | 282 | 0 | 101 | 0 | 327 |

(ruff는 폐기(Removed)·코드 없는 규칙을 뺀 수. ESLint는 폐기 표시(deprecated)된 규칙 102개 포함 — 대부분 layout. 표에는 특수 키 `syntax-error`·`unused-disable-directive`·`no-rule-id`도 있다.)

규칙 설명 페이지(P22, `/rules`)는 규칙마다 이 매핑의 기본 심각도와 근거(개별 지정·접두사·카테고리 기본·미정의)를 보여 준다. 값은 규칙 데이터를 적재할 때 정해지며, 매핑 버전이 바뀌면 다음 적재(`tools/rules_load.php --apply` 또는 워커 자동 적재)가 다시 계산한다.

## 8. 전체 매핑 표

`php tools/severity_coverage.php --markdown=<파일>`로 만든 표다(도구, 심각도 순). "정한 단계"의 괄호는 그 규칙의 분류군.

| 도구 | 규칙 키 | 규칙 이름·설명 | 기본 심각도 | 정한 단계 | 근거 |
| --- | --- | --- | --- | --- | --- |
| ruff | `ruff:PLE2502` | bidirectional-unicode | critical | 개별 (suspicious) | 양방향 유니코드 문자 — 보이는 코드와 실행 코드가 다름(Trojan Source) |
| ruff | `ruff:S102` | exec-builtin | critical | 개별 (security) | exec 사용 — 임의 코드 실행 |
| ruff | `ruff:S105` | hardcoded-password-string | critical | 개별 (security) | 하드코딩된 비밀번호(문자열) |
| ruff | `ruff:S106` | hardcoded-password-func-arg | critical | 개별 (security) | 하드코딩된 비밀번호(함수 인자) |
| ruff | `ruff:S107` | hardcoded-password-default | critical | 개별 (security) | 하드코딩된 비밀번호(기본값) |
| ruff | `ruff:S201` | flask-debug-true | critical | 개별 (security) | Flask debug=True — 원격 코드 실행 콘솔 노출 |
| ruff | `ruff:S202` | tarfile-unsafe-members | critical | 개별 (security) | tarfile 전체 해제 — 경로 조작으로 임의 파일 쓰기 |
| ruff | `ruff:S301` | suspicious-pickle-usage | critical | 개별 (security) | pickle 역직렬화 — 임의 코드 실행 |
| ruff | `ruff:S302` | suspicious-marshal-usage | critical | 개별 (security) | marshal 역직렬화 |
| ruff | `ruff:S303` | suspicious-insecure-hash-usage | critical | 개별 (security) | 안전하지 않은 해시(MD5·SHA1) |
| ruff | `ruff:S304` | suspicious-insecure-cipher-usage | critical | 개별 (security) | 안전하지 않은 암호 알고리즘 |
| ruff | `ruff:S305` | suspicious-insecure-cipher-mode-usage | critical | 개별 (security) | 안전하지 않은 암호 모드(ECB) |
| ruff | `ruff:S307` | suspicious-eval-usage | critical | 개별 (security) | eval 사용 — 임의 코드 실행 |
| ruff | `ruff:S323` | suspicious-unverified-context-usage | critical | 개별 (security) | 인증서 검증 없는 SSL 컨텍스트 |
| ruff | `ruff:S324` | hashlib-insecure-hash-function | critical | 개별 (security) | hashlib 안전하지 않은 해시 |
| ruff | `ruff:S501` | request-with-no-cert-validation | critical | 개별 (security) | requests verify=False — 인증서 검증 끔 |
| ruff | `ruff:S502` | ssl-insecure-version | critical | 개별 (security) | 안전하지 않은 SSL/TLS 버전 |
| ruff | `ruff:S503` | ssl-with-bad-defaults | critical | 개별 (security) | 안전하지 않은 SSL 기본값 |
| ruff | `ruff:S504` | ssl-with-no-version | critical | 개별 (security) | 버전 지정 없는 SSL |
| ruff | `ruff:S505` | weak-cryptographic-key | critical | 개별 (security) | 약한 암호 키 길이 |
| ruff | `ruff:S506` | unsafe-yaml-load | critical | 개별 (security) | yaml.load(안전하지 않은 로더) — 임의 코드 실행 |
| ruff | `ruff:S507` | ssh-no-host-key-verification | critical | 개별 (security) | SSH 호스트 키 검증 끔 |
| ruff | `ruff:S508` | snmp-insecure-version | critical | 개별 (security) | 안전하지 않은 SNMP 버전 |
| ruff | `ruff:S509` | snmp-weak-cryptography | critical | 개별 (security) | 약한 SNMP 암호 |
| ruff | `ruff:S602` | subprocess-popen-with-shell-equals-true | critical | 개별 (security) | subprocess shell=True — 명령 주입 |
| ruff | `ruff:S604` | call-with-shell-equals-true | critical | 개별 (security) | shell=True 호출 — 명령 주입 |
| ruff | `ruff:S605` | start-process-with-a-shell | critical | 개별 (security) | 셸로 프로세스 시작(os.system 등) — 명령 주입 |
| ruff | `ruff:S608` | hardcoded-sql-expression | critical | 개별 (security) | 문자열로 조립한 SQL — SQL 주입 |
| ruff | `ruff:S609` | unix-command-wildcard-injection | critical | 개별 (security) | 명령의 와일드카드 주입 |
| ruff | `ruff:S610` | django-extra | critical | 개별 (security) | Django extra() — SQL 주입 |
| ruff | `ruff:S611` | django-raw-sql | critical | 개별 (security) | Django RawSQL — SQL 주입 |
| ruff | `ruff:S701` | jinja2-autoescape-false | critical | 개별 (security) | Jinja2 autoescape=False — XSS |
| ruff | `ruff:S704` | unsafe-markup-use | critical | 개별 (security) | 안전하지 않은 Markup 사용 — XSS |
| ruff | `ruff:B002` | unary-prefix-increment-decrement | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B005` | strip-with-multi-characters | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B012` | jump-statement-in-finally | high | 개별 (suspicious) | finally 안의 return·break — 예외를 삼킴 |
| ruff | `ruff:B014` | duplicate-handler-exception | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B015` | useless-comparison | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B016` | raise-literal | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B020` | loop-variable-overrides-iterator | high | 개별 (suspicious) | 반복 변수가 반복 대상을 덮어씀 |
| ruff | `ruff:B023` | function-uses-loop-variable | high | 개별 (suspicious) | 루프 변수를 늦게 바인딩하는 함수 |
| ruff | `ruff:B025` | duplicate-try-block-exception | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B029` | except-with-empty-tuple | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B030` | except-with-non-exception-classes | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B035` | static-key-dict-comprehension | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:B909` | loop-iterator-mutation | high | 개별 (suspicious) | 반복 중인 컬렉션 변경 |
| ruff | `ruff:E112` | no-indented-block | high | 개별 (pedantic) | 들여쓰기 블록 없음(구문 오류) |
| ruff | `ruff:E113` | unexpected-indentation | high | 개별 (pedantic) | 예상하지 않은 들여쓰기(구문 오류) |
| ruff | `ruff:E902` | io-error | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F404` | late-future-import | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F406` | undefined-local-with-nested-import-star-usage | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F407` | future-feature-not-defined | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F501` | percent-format-invalid-format | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F502` | percent-format-expected-mapping | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F503` | percent-format-expected-sequence | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F504` | percent-format-extra-named-arguments | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F505` | percent-format-missing-argument | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F506` | percent-format-mixed-positional-and-named | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F507` | percent-format-positional-count-mismatch | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F508` | percent-format-star-requires-sequence | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F509` | percent-format-unsupported-format-character | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F521` | string-dot-format-invalid-format | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F522` | string-dot-format-extra-named-arguments | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F523` | string-dot-format-extra-positional-arguments | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F524` | string-dot-format-missing-arguments | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F525` | string-dot-format-mixing-automatic | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F601` | multi-value-repeated-key-literal | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F602` | multi-value-repeated-key-variable | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F621` | expressions-in-star-assignment | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F622` | multiple-starred-expressions | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F631` | assert-tuple | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F632` | is-literal | high | 개별 (suspicious) | 리터럴과 is 비교 — 결과가 구현에 따라 다름 |
| ruff | `ruff:F633` | invalid-print-syntax | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F634` | if-tuple | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F701` | break-outside-loop | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F702` | continue-outside-loop | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F704` | yield-outside-function | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F706` | return-outside-function | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F707` | default-except-not-last | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F722` | forward-annotation-syntax-error | high | 개별 (pedantic) | 전방 참조 주석의 구문 오류 |
| ruff | `ruff:F821` | undefined-name | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F822` | undefined-export | high | 개별 (suspicious) | __all__에 정의되지 않은 이름 |
| ruff | `ruff:F823` | undefined-local | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:F901` | raise-not-implemented | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PGH005` | invalid-mock-access | high | 개별 (suspicious) | mock의 잘못된 속성 접근(검사가 항상 통과) |
| ruff | `ruff:PIE794` | duplicate-class-field-definition | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0100` | yield-in-init | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0101` | return-in-init | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0115` | nonlocal-and-global | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0116` | continue-in-finally | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0117` | nonlocal-without-binding | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0118` | load-before-global-declaration | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0241` | duplicate-bases | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0303` | invalid-length-return-type | high | 개별 (suspicious) | __len__ 반환 형식 오류 |
| ruff | `ruff:PLE0304` | invalid-bool-return-type | high | 개별 (suspicious) | __bool__ 반환 형식 오류 |
| ruff | `ruff:PLE0305` | invalid-index-return-type | high | 개별 (suspicious) | __index__ 반환 형식 오류 |
| ruff | `ruff:PLE0307` | invalid-str-return-type | high | 개별 (suspicious) | __str__ 반환 형식 오류 |
| ruff | `ruff:PLE0308` | invalid-bytes-return-type | high | 개별 (suspicious) | __bytes__ 반환 형식 오류 |
| ruff | `ruff:PLE0309` | invalid-hash-return-type | high | 개별 (suspicious) | __hash__ 반환 형식 오류 |
| ruff | `ruff:PLE0604` | invalid-all-object | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0605` | invalid-all-format | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0643` | potential-index-error | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE0704` | misplaced-bare-raise | high | 개별 (suspicious) | except 밖의 bare raise |
| ruff | `ruff:PLE1132` | repeated-keyword-argument | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE1141` | dict-iter-missing-items | high | 개별 (suspicious) | dict 반복에 items() 누락 |
| ruff | `ruff:PLE1142` | await-outside-async | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE1300` | bad-string-format-character | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE1307` | bad-string-format-type | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE1310` | bad-str-strip-call | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE1507` | invalid-envvar-value | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE1700` | yield-from-in-async-function | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLE4703` | modified-iterating-set | high | 개별 (suspicious) | 반복 중인 set 변경 |
| ruff | `ruff:PLR0124` | comparison-with-itself | high | 개별 (suspicious) | 자기 자신과 비교 |
| ruff | `ruff:PLR0206` | property-with-parameters | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLR1708` | stop-iteration-return | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW0128` | redeclared-assigned-name | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW0129` | assert-on-string-literal | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW0133` | useless-exception-statement | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW0177` | nan-comparison | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW0244` | redefined-slots-in-subclass | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW0245` | super-without-brackets | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW1501` | bad-open-mode | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW1507` | shallow-copy-environ | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PLW2101` | useless-with-lock | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:PTH210` | invalid-pathlib-with-suffix | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF016` | invalid-index-type | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF020` | never-union | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF026` | default-factory-kwarg | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF049` | dataclass-enum | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF053` | class-with-mixed-type-vars | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF060` | in-empty-collection | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF066` | property-without-return | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:RUF074` | incorrect-decorator-order | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:S103` | bad-file-permissions | high | 개별 (security) | 너무 넓은 파일 권한 |
| ruff | `ruff:S306` | suspicious-mktemp-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S308` | suspicious-mark-safe-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S310` | suspicious-url-open-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S311` | suspicious-non-cryptographic-random-usage | high | 개별 (security) | 암호용이 아닌 난수 — 보안 용도면 위험(그 밖의 용도면 오탐이 많아 critical로 두지 않음) |
| ruff | `ruff:S312` | suspicious-telnet-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S313` | suspicious-xmlc-element-tree-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S314` | suspicious-xml-element-tree-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S315` | suspicious-xml-expat-reader-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S316` | suspicious-xml-expat-builder-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S317` | suspicious-xml-sax-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S318` | suspicious-xml-mini-dom-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S319` | suspicious-xml-pull-dom-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S321` | suspicious-ftp-lib-usage | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S601` | paramiko-call | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S612` | logging-config-insecure-listen | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:S702` | mako-templates | high | 분류군 (security) | 보안 관련 검사(flake8-bandit 등). 실제 취약점이 되는 것은 개별 규칙에서 critical로 올린다 |
| ruff | `ruff:SIM107` | return-in-try-except-finally | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:SIM220` | expr-and-not-expr | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:SIM221` | expr-or-not-expr | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:SIM222` | expr-or-true | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:SIM223` | expr-and-false | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:T100` | debugger | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `ruff:W605` | invalid-escape-sequence | high | 분류군 (correctness) | 실행하면 틀리게 동작하거나 예외가 나는 코드 |
| ruff | `syntax-error` | (특수 키) | high | 개별 | 구문 오류 — 파일을 실행·가져올 수 없다 |
| ruff | `ruff:AIR002` | airflow-dag-no-schedule-argument | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:AIR003` | airflow-variable-get-outside-task | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:AIR304` | airflow3-dag-dynamic-value | medium | 개별 (correctness) | Airflow DAG 동적 값 |
| ruff | `ruff:ASYNC100` | cancel-scope-no-checkpoint | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC105` | trio-sync-call | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC210` | blocking-http-call-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC212` | blocking-http-call-httpx-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC220` | create-subprocess-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC221` | run-process-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC222` | wait-for-process-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC230` | blocking-open-call-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC240` | blocking-path-method-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC250` | blocking-input-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ASYNC251` | blocking-sleep-in-async-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B003` | assignment-to-os-environ | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B004` | unreliable-callable-check | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B006` | mutable-argument-default | medium | 개별 (suspicious) | 가변 기본 인자 |
| ruff | `ruff:B008` | function-call-in-default-argument | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B017` | assert-raises-exception | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B018` | useless-expression | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B019` | cached-instance-method | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B022` | useless-contextlib-suppress | medium | 개별 (correctness) | 인자 없는 contextlib.suppress |
| ruff | `ruff:B024` | abstract-base-class-without-abstract-method | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B026` | star-arg-unpacking-after-keyword-arg | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B027` | empty-method-without-abstract-decorator | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B031` | reuse-of-groupby-generator | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B032` | unintentional-type-annotation | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B033` | duplicate-value | medium | 개별 (correctness) | 집합 리터럴의 중복 값 |
| ruff | `ruff:B039` | mutable-contextvar-default | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:B901` | return-in-generator | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:BLE001` | blind-except | medium | 개별 (suspicious) | 포괄적인 except Exception |
| ruff | `ruff:C901` | complex-structure | medium | 개별 (restriction) | 함수가 너무 복잡함(유지보수 위험) |
| ruff | `ruff:COM818` | trailing-comma-on-bare-tuple | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:DJ003` | django-locals-in-render-function | medium | 개별 (security) | render에 locals() 전달 |
| ruff | `ruff:DJ006` | django-exclude-with-model-form | medium | 개별 (security) | ModelForm exclude 사용 |
| ruff | `ruff:DJ007` | django-all-with-model-form | medium | 개별 (security) | ModelForm __all__ 사용 |
| ruff | `ruff:DJ013` | django-non-leading-receiver-decorator | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:DTZ002` | call-datetime-today | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:DTZ003` | call-datetime-utcnow | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:DTZ004` | call-datetime-utcfromtimestamp | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:E721` | type-comparison | medium | 개별 (pedantic) | type() 비교 — 하위 클래스를 놓침 |
| ruff | `ruff:E722` | bare-except | medium | 개별 (suspicious) | bare except — 모든 예외(KeyboardInterrupt 포함)를 삼킴 |
| ruff | `ruff:F401` | unused-import | medium | 개별 (suspicious) | 사용하지 않는 import — 지울 코드 또는 누락된 사용(기준표: 사용하지 않는 import는 medium) |
| ruff | `ruff:F402` | import-shadowed-by-loop-var | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:F403` | undefined-local-with-import-star | medium | 개별 (restriction) | import * — 이름 출처를 알 수 없음 |
| ruff | `ruff:F405` | undefined-local-with-import-star-usage | medium | 개별 (restriction) | import *에서 온 것일 수 있는 이름 |
| ruff | `ruff:F811` | redefined-while-unused | medium | 개별 (suspicious) | 사용하지 않은 채 다시 정의 |
| ruff | `ruff:F841` | unused-variable | medium | 개별 (correctness) | 사용하지 않는 지역 변수 — 오타·누락의 흔적일 수 있음 |
| ruff | `ruff:FA102` | future-required-type-annotation | medium | 개별 (correctness) | future annotations 없이 최신 타입 문법 |
| ruff | `ruff:FAST003` | fast-api-unused-path-parameter | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:FURB113` | repeated-append | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:FURB189` | subclass-builtin | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:G010` | logging-warn | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:G101` | logging-extra-attr-clash | medium | 개별 (correctness) | logging extra 키 충돌 |
| ruff | `ruff:INT001` | f-string-in-get-text-func-call | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:INT002` | format-in-get-text-func-call | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:INT003` | printf-in-get-text-func-call | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:ISC004` | implicit-string-concatenation-in-collection-literal | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:LOG001` | direct-logger-instantiation | medium | 개별 (correctness) | Logger 직접 생성 |
| ruff | `ruff:LOG002` | invalid-get-logger-argument | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:LOG009` | undocumented-warn | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:LOG014` | exc-info-outside-except-handler | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:NPY001` | numpy-deprecated-type-alias | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:NPY002` | numpy-legacy-random | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:NPY003` | numpy-deprecated-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:NPY201` | numpy2-deprecation | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PERF101` | unnecessary-list-cast | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:PIE796` | non-unique-enums | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLC0131` | type-bivariance | medium | 개별 (correctness) | 공변·반공변 TypeVar 동시 지정 |
| ruff | `ruff:PLC0132` | type-param-name-mismatch | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLC0205` | single-string-slots | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLC0207` | missing-maxsplit-arg | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:PLC0208` | iteration-over-set | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:PLE1205` | logging-too-many-args | medium | 개별 (correctness) | logging 인자가 많음(메시지 오류로 기록) |
| ruff | `ruff:PLE1206` | logging-too-few-args | medium | 개별 (correctness) | logging 인자가 적음(메시지 오류로 기록) |
| ruff | `ruff:PLE1519` | singledispatch-method | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLE1520` | singledispatchmethod-function | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLE2510` | invalid-character-backspace | medium | 개별 (suspicious) | 문자열 안 백스페이스 문자 |
| ruff | `ruff:PLE2512` | invalid-character-sub | medium | 개별 (suspicious) | 문자열 안 SUB 문자 |
| ruff | `ruff:PLE2513` | invalid-character-esc | medium | 개별 (suspicious) | 문자열 안 ESC 문자 |
| ruff | `ruff:PLE2514` | invalid-character-nul | medium | 개별 (suspicious) | 문자열 안 NUL 문자 |
| ruff | `ruff:PLE2515` | invalid-character-zero-width-space | medium | 개별 (suspicious) | 문자열 안 폭 없는 공백 |
| ruff | `ruff:PLR1704` | redefined-argument-from-local | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW0127` | self-assigning-variable | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW0211` | bad-staticmethod-argument | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW0406` | import-self | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW0642` | self-or-cls-assignment | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW0711` | binary-op-exception | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW1508` | invalid-envvar-default | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW1509` | subprocess-popen-preexec-fn | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PLW1510` | subprocess-run-without-check | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:PT010` | pytest-raises-without-exception | medium | 개별 (correctness) | 예외 없이 pytest.raises |
| ruff | `ruff:PT014` | pytest-duplicate-parametrize-test-cases | medium | 개별 (correctness) | 중복 parametrize 사례 |
| ruff | `ruff:PT025` | pytest-erroneous-use-fixtures-on-fixture | medium | 개별 (correctness) | fixture에 usefixtures(동작 안 함) |
| ruff | `ruff:PT026` | pytest-use-fixtures-without-parameters | medium | 개별 (correctness) | 인자 없는 usefixtures |
| ruff | `ruff:RUF001` | ambiguous-unicode-character-string | medium | 개별 (security) | 헷갈리는 유니코드 문자(문자열 값) |
| ruff | `ruff:RUF008` | mutable-dataclass-default | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF009` | function-call-in-dataclass-default-argument | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF012` | mutable-class-default | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF013` | implicit-optional | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF015` | unnecessary-iterable-allocation-for-first-element | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:RUF017` | quadratic-list-summation | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:RUF018` | assignment-in-assert | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF024` | mutable-fromkeys-value | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF027` | missing-f-string-syntax | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF028` | invalid-formatter-suppression-comment | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF030` | assert-with-print-message | medium | 개별 (correctness) | assert 메시지에 print |
| ruff | `ruff:RUF032` | decimal-from-float-literal | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF033` | post-init-default | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF034` | useless-if-else | medium | 개별 (correctness) | 쓸모없는 if-else |
| ruff | `ruff:RUF040` | invalid-assert-message-literal-argument | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF045` | implicit-class-var-in-dataclass | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF048` | map-int-version-parsing | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF059` | unused-unpacked-variable | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF063` | access-annotations-from-class-dict | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF064` | non-octal-permissions | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF065` | logging-eager-conversion | medium | 분류군 (performance) | 불필요한 비용이 드는 코드(동작은 맞음) |
| ruff | `ruff:RUF069` | float-equality-comparison | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF071` | os-path-commonprefix | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF073` | f-string-percent-format | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF075` | fallible-context-manager | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF077` | method-receiver-default | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:RUF200` | invalid-pyproject-toml | medium | 개별 (correctness) | 잘못된 pyproject.toml |
| ruff | `ruff:S104` | hardcoded-bind-all-interfaces | medium | 개별 (security) | 모든 인터페이스에 바인딩(0.0.0.0) — 배포 환경에 따라 다름 |
| ruff | `ruff:S108` | hardcoded-temp-file | medium | 개별 (security) | 고정 경로의 임시 파일 |
| ruff | `ruff:S110` | try-except-pass | medium | 개별 (suspicious) | try-except-pass — 예외 삼키기 |
| ruff | `ruff:S112` | try-except-continue | medium | 개별 (suspicious) | try-except-continue — 예외 삼키기 |
| ruff | `ruff:S113` | request-without-timeout | medium | 개별 (security) | timeout 없는 요청 — 멈춤 위험 |
| ruff | `ruff:S401` | suspicious-telnetlib-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(telnetlib) — 사용 위치는 다른 규칙이 본다 |
| ruff | `ruff:S402` | suspicious-ftplib-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(ftplib) |
| ruff | `ruff:S403` | suspicious-pickle-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(pickle) |
| ruff | `ruff:S405` | suspicious-xml-etree-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(xml.etree) |
| ruff | `ruff:S406` | suspicious-xml-sax-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(xml.sax) |
| ruff | `ruff:S407` | suspicious-xml-expat-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(xml.dom.expatbuilder) |
| ruff | `ruff:S408` | suspicious-xml-minidom-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(xml.dom.minidom) |
| ruff | `ruff:S409` | suspicious-xml-pulldom-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(xml.dom.pulldom) |
| ruff | `ruff:S411` | suspicious-xmlrpc-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(xmlrpc) |
| ruff | `ruff:S412` | suspicious-httpoxy-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(httpoxy) |
| ruff | `ruff:S413` | suspicious-pycrypto-import | medium | 개별 (security) | 유지보수 중단된 암호 라이브러리 import(pycrypto) |
| ruff | `ruff:S415` | suspicious-pyghmi-import | medium | 개별 (security) | 위험할 수 있는 모듈 import(pyghmi) |
| ruff | `ruff:S603` | subprocess-without-shell-equals-true | medium | 개별 (security) | 셸 없는 subprocess — 입력을 검증하는지 확인 필요 |
| ruff | `ruff:S606` | start-process-with-no-shell | medium | 개별 (security) | 셸 없는 프로세스 시작 |
| ruff | `ruff:S607` | start-process-with-partial-path | medium | 개별 (security) | 부분 경로로 실행 파일 지정 |
| ruff | `ruff:SIM114` | if-with-same-arms | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:SIM115` | open-file-with-context-handler | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:TC004` | runtime-import-in-type-checking-block | medium | 개별 (correctness) | 실행 시 쓰는 import가 TYPE_CHECKING 블록 안 |
| ruff | `ruff:TC005` | empty-type-checking-block | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:TC007` | unquoted-type-alias | medium | 개별 (correctness) | 따옴표 없는 타입 별칭 |
| ruff | `ruff:TC010` | runtime-string-union | medium | 개별 (correctness) | 실행 시 문자열 유니온 |
| ruff | `ruff:TID255` | lazy-import-immediately-resolved | medium | 개별 (correctness) | 지연 import가 바로 해석됨 |
| ruff | `ruff:YTT101` | sys-version-slice3 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT102` | sys-version2 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT103` | sys-version-cmp-str3 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT201` | sys-version-info0-eq3 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT202` | six-py3 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT203` | sys-version-info1-cmp-int | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT204` | sys-version-info-minor-cmp-int | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT301` | sys-version0 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT302` | sys-version-cmp-str10 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:YTT303` | sys-version-slice1 | medium | 분류군 (suspicious) | 버그일 수 있는 의심스러운 코드·안티패턴 |
| ruff | `ruff:A001` | builtin-variable-shadowing | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:A002` | builtin-argument-shadowing | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:A003` | builtin-attribute-shadowing | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:A004` | builtin-import-shadowing | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:A005` | stdlib-module-shadowing | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:A006` | builtin-lambda-argument-shadowing | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:AIR001` | airflow-variable-name-task-id-mismatch | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:AIR004` | airflow-task-branch-as-short-circuit | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:AIR201` | airflow-xcom-pull-in-template-string | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:AIR202` | airflow-task-implicit-multiple-outputs | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:AIR301` | airflow3-removal | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:AIR302` | airflow3-moved-to-provider | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:AIR303` | airflow3-incompatible-function-signature | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:AIR311` | airflow3-suggested-update | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:AIR312` | airflow3-suggested-to-move-to-provider | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:AIR321` | airflow31-moved | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN001` | missing-type-function-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN002` | missing-type-args | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN003` | missing-type-kwargs | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN201` | missing-return-type-undocumented-public-function | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN202` | missing-return-type-private-function | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN204` | missing-return-type-special-method | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN205` | missing-return-type-static-method | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN206` | missing-return-type-class-method | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ANN401` | any-type | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ARG001` | unused-function-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ARG002` | unused-method-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ARG003` | unused-class-method-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ARG004` | unused-static-method-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ARG005` | unused-lambda-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ASYNC109` | async-function-with-timeout | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ASYNC110` | async-busy-wait | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ASYNC115` | async-zero-sleep | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:ASYNC116` | long-sleep-not-forever | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:ASYNC119` | yield-in-context-manager-in-async-generator | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B007` | unused-loop-control-variable | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B009` | get-attr-with-constant | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:B010` | set-attr-with-constant | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:B011` | assert-false | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B013` | redundant-tuple-in-exception-handler | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:B021` | f-string-docstring | low | 개별 (correctness) | f-string docstring(docstring으로 인식되지 않음) |
| ruff | `ruff:B028` | no-explicit-stacklevel | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B034` | re-sub-positional-args | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B043` | del-attr-with-constant | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:B903` | class-as-data-structure | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B904` | raise-without-from-inside-except | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B905` | zip-without-explicit-strict | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B911` | batched-without-explicit-strict | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:B912` | map-without-explicit-strict | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:C400` | unnecessary-generator-list | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C401` | unnecessary-generator-set | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C402` | unnecessary-generator-dict | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C403` | unnecessary-list-comprehension-set | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C404` | unnecessary-list-comprehension-dict | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C405` | unnecessary-literal-set | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C406` | unnecessary-literal-dict | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C408` | unnecessary-collection-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C409` | unnecessary-literal-within-tuple-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C410` | unnecessary-literal-within-list-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C411` | unnecessary-list-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C413` | unnecessary-call-around-sorted | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C414` | unnecessary-double-cast-or-process | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C415` | unnecessary-subscript-reversal | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C416` | unnecessary-comprehension | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:C417` | unnecessary-map | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C418` | unnecessary-literal-within-dict-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C419` | unnecessary-comprehension-in-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:C420` | unnecessary-dict-comprehension-for-iterable | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:COM812` | missing-trailing-comma | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:COM819` | prohibited-trailing-comma | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:CPY001` | missing-copyright-notice | low | 접두사 (pedantic) | 저작권 표기 |
| ruff | `ruff:D100` | undocumented-public-module | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D101` | undocumented-public-class | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D102` | undocumented-public-method | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D103` | undocumented-public-function | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D104` | undocumented-public-package | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D105` | undocumented-magic-method | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D106` | undocumented-public-nested-class | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D107` | undocumented-public-init | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D200` | unnecessary-multiline-docstring | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D201` | blank-line-before-function | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D202` | blank-line-after-function | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D203` | incorrect-blank-line-before-class | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D204` | incorrect-blank-line-after-class | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D205` | missing-blank-line-after-summary | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D206` | docstring-tab-indentation | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D207` | under-indentation | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D208` | over-indentation | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D209` | new-line-after-last-paragraph | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D210` | surrounding-whitespace | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D211` | blank-line-before-class | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D212` | multi-line-summary-first-line | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D213` | multi-line-summary-second-line | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D214` | overindented-section | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D215` | overindented-section-underline | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D300` | triple-single-quotes | low | 접두사 (formatting) | docstring 서식(pydocstyle) |
| ruff | `ruff:D301` | escape-sequence-in-docstring | low | 개별 (suspicious) | docstring 이스케이프 |
| ruff | `ruff:D400` | missing-trailing-period | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D401` | non-imperative-mood | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D402` | signature-in-docstring | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D403` | first-word-uncapitalized | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D404` | docstring-starts-with-this | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D405` | non-capitalized-section-name | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D406` | missing-new-line-after-section-name | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D407` | missing-dashed-underline-after-section | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D408` | missing-section-underline-after-name | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D409` | mismatched-section-underline-length | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D410` | no-blank-line-after-section | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D411` | no-blank-line-before-section | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D412` | blank-lines-between-header-and-content | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D413` | missing-blank-line-after-last-section | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D414` | empty-docstring-section | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D415` | missing-terminal-punctuation | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D416` | missing-section-name-colon | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D417` | undocumented-param | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D418` | overload-with-docstring | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D419` | empty-docstring | low | 접두사 (suspicious) | docstring 서식(pydocstyle) |
| ruff | `ruff:D420` | incorrect-section-order | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:D421` | property-docstring-starts-with-verb | low | 접두사 (pedantic) | docstring 서식(pydocstyle) |
| ruff | `ruff:DJ001` | django-nullable-model-string-field | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DJ008` | django-model-without-dunder-str | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:DJ012` | django-unordered-body-content-in-model | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:DOC102` | docstring-extraneous-parameter | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DOC201` | docstring-missing-returns | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DOC202` | docstring-extraneous-returns | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DOC402` | docstring-missing-yields | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DOC403` | docstring-extraneous-yields | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DOC501` | docstring-missing-exception | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DOC502` | docstring-extraneous-exception | low | 접두사 (pedantic) | docstring 내용(pydoclint) |
| ruff | `ruff:DTZ001` | call-datetime-without-tzinfo | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DTZ005` | call-datetime-now-without-tzinfo | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DTZ006` | call-datetime-fromtimestamp | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DTZ007` | call-datetime-strptime-without-zone | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DTZ011` | call-date-today | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DTZ012` | call-date-fromtimestamp | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:DTZ901` | datetime-min-max | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E101` | mixed-spaces-and-tabs | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E111` | indentation-with-invalid-multiple | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E114` | indentation-with-invalid-multiple-comment | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E115` | no-indented-block-comment | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E116` | unexpected-indentation-comment | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E117` | over-indented | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E201` | whitespace-after-open-bracket | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E202` | whitespace-before-close-bracket | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E203` | whitespace-before-punctuation | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E204` | whitespace-after-decorator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E211` | whitespace-before-parameters | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E221` | multiple-spaces-before-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E222` | multiple-spaces-after-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E223` | tab-before-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E224` | tab-after-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E225` | missing-whitespace-around-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E226` | missing-whitespace-around-arithmetic-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E227` | missing-whitespace-around-bitwise-or-shift-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E228` | missing-whitespace-around-modulo-operator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E231` | missing-whitespace | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E241` | multiple-spaces-after-comma | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E242` | tab-after-comma | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E251` | unexpected-spaces-around-keyword-parameter-equals | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E252` | missing-whitespace-around-parameter-equals | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E261` | too-few-spaces-before-inline-comment | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E262` | no-space-after-inline-comment | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E265` | no-space-after-block-comment | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E266` | multiple-leading-hashes-for-block-comment | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E271` | multiple-spaces-after-keyword | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E272` | multiple-spaces-before-keyword | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E273` | tab-after-keyword | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E274` | tab-before-keyword | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E275` | missing-whitespace-after-keyword | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E301` | blank-line-between-methods | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E302` | blank-lines-top-level | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E303` | too-many-blank-lines | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E304` | blank-line-after-decorator | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E305` | blank-lines-after-function-or-class | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E306` | blank-lines-before-nested-definition | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E401` | multiple-imports-on-one-line | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E402` | module-import-not-at-top-of-file | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E501` | line-too-long | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E502` | redundant-backslash | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E701` | multiple-statements-on-one-line-colon | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E702` | multiple-statements-on-one-line-semicolon | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E703` | useless-semicolon | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:E711` | none-comparison | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E712` | true-false-comparison | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E713` | not-in-test | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E714` | not-is-test | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E731` | lambda-assignment | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E741` | ambiguous-variable-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E742` | ambiguous-class-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:E743` | ambiguous-function-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:EM101` | raw-string-in-exception | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:EM102` | f-string-in-exception | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:EM103` | dot-format-in-exception | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ERA001` | commented-out-code | low | 접두사 (pedantic) | 주석 처리된 코드 |
| ruff | `ruff:EXE001` | shebang-not-executable | low | 접두사 (suspicious) | 실행 권한·shebang |
| ruff | `ruff:EXE002` | shebang-missing-executable-file | low | 접두사 (correctness) | 실행 권한·shebang |
| ruff | `ruff:EXE003` | shebang-missing-python | low | 접두사 (pedantic) | 실행 권한·shebang |
| ruff | `ruff:EXE004` | shebang-leading-whitespace | low | 접두사 (correctness) | 실행 권한·shebang |
| ruff | `ruff:EXE005` | shebang-not-first-line | low | 접두사 (suspicious) | 실행 권한·shebang |
| ruff | `ruff:F541` | f-string-missing-placeholders | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:F842` | unused-annotation | low | 개별 (correctness) | 사용하지 않는 지역 변수 주석 |
| ruff | `ruff:FA100` | future-rewritable-type-annotation | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:FAST001` | fast-api-redundant-response-model | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FAST002` | fast-api-non-annotated-dependency | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:FBT001` | boolean-type-hint-positional-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FBT002` | boolean-default-value-positional-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FBT003` | boolean-positional-value-in-call | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FIX001` | line-contains-fixme | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FIX002` | line-contains-todo | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FIX003` | line-contains-xxx | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FIX004` | line-contains-hack | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FLY002` | static-join-to-f-string | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB101` | read-whole-file | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB103` | write-whole-file | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB105` | print-empty-string | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB110` | if-exp-instead-of-or-operator | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB116` | f-string-number-format | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB118` | reimplemented-operator | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB122` | for-loop-writes | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB129` | readlines-in-for | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB131` | delete-full-slice | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB132` | check-and-remove-from-set | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB136` | if-expr-min-max | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB140` | reimplemented-starmap | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB142` | for-loop-set-mutations | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB145` | slice-copy | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:FURB148` | unnecessary-enumerate | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB152` | math-constant | low | 개별 (correctness) | math 상수 대신 리터럴 |
| ruff | `ruff:FURB154` | repeated-global | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:FURB156` | hardcoded-string-charset | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB157` | verbose-decimal-constructor | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB161` | bit-count | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB162` | fromisoformat-replace-z | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB163` | redundant-log-base | low | 개별 (correctness) | 불필요한 log 밑 |
| ruff | `ruff:FURB164` | unnecessary-from-float | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB166` | int-on-sliced-str | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB167` | regex-flag-alias | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:FURB168` | isinstance-type-none | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB169` | type-none-comparison | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB171` | single-item-membership-test | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB177` | implicit-cwd | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB180` | meta-class-abc-meta | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB181` | hashlib-digest-hex | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB187` | list-reverse-copy | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:FURB188` | slice-to-remove-prefix-or-suffix | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:FURB192` | sorted-min-max | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:G001` | logging-string-format | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:G002` | logging-percent-format | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:G003` | logging-string-concat | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:G004` | logging-f-string | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:G201` | logging-exc-info | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:G202` | logging-redundant-exc-info | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:I001` | unsorted-imports | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:I002` | missing-required-import | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ICN001` | unconventional-import-alias | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ICN002` | banned-import-alias | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ICN003` | banned-import-from | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:INP001` | implicit-namespace-package | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:ISC001` | single-line-implicit-string-concatenation | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:ISC002` | multi-line-implicit-string-concatenation | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:ISC003` | explicit-string-concatenation | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:LOG004` | log-exception-outside-except-handler | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:LOG007` | exception-without-exc-info | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:LOG015` | root-logger-call | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:N801` | invalid-class-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N802` | invalid-function-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N803` | invalid-argument-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N804` | invalid-first-argument-name-for-class-method | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N805` | invalid-first-argument-name-for-method | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N806` | non-lowercase-variable-in-function | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N807` | dunder-function-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N811` | constant-imported-as-non-constant | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N812` | lowercase-imported-as-non-lowercase | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N813` | camelcase-imported-as-lowercase | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N814` | camelcase-imported-as-constant | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N815` | mixed-case-variable-in-class-scope | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N816` | mixed-case-variable-in-global-scope | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N817` | camelcase-imported-as-acronym | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N818` | error-suffix-on-exception-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:N999` | invalid-module-name | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PD002` | pandas-use-of-inplace-argument | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD003` | pandas-use-of-dot-is-null | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PD004` | pandas-use-of-dot-not-null | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PD007` | pandas-use-of-dot-ix | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD008` | pandas-use-of-dot-at | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD009` | pandas-use-of-dot-iat | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD010` | pandas-use-of-dot-pivot-or-unstack | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD011` | pandas-use-of-dot-values | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD012` | pandas-use-of-dot-read-table | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PD013` | pandas-use-of-dot-stack | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PD015` | pandas-use-of-pd-merge | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PD101` | pandas-nunique-constant-series-check | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PERF102` | incorrect-dict-iterator | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PERF203` | try-except-in-loop | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PERF401` | manual-list-comprehension | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PERF402` | manual-list-copy | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PERF403` | manual-dict-comprehension | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PGH003` | blanket-type-ignore | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:PGH004` | blanket-noqa | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PIE790` | unnecessary-placeholder | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PIE800` | unnecessary-spread | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PIE804` | unnecessary-dict-kwargs | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PIE807` | reimplemented-container-builtin | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PIE808` | unnecessary-range-start | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PIE810` | multiple-starts-ends-with | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLC0105` | type-name-incorrect-variance | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLC0206` | dict-index-missing-items | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLC0414` | useless-import-alias | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLC0415` | import-outside-top-level | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLC1802` | len-test | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLC1901` | compare-to-empty-string | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLC2401` | non-ascii-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLC2403` | non-ascii-import-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLC2701` | import-private-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLC2801` | unnecessary-dunder-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLC3002` | unnecessary-direct-lambda-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLE0237` | non-slot-assignment | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLE0302` | unexpected-special-method-signature | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0133` | comparison-of-constant | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR0202` | no-classmethod-decorator | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLR0203` | no-staticmethod-decorator | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLR0402` | manual-from-import | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR0904` | too-many-public-methods | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0911` | too-many-return-statements | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0912` | too-many-branches | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0913` | too-many-arguments | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0914` | too-many-locals | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0915` | too-many-statements | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0916` | too-many-boolean-expressions | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR0917` | too-many-positional-arguments | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR1702` | too-many-nested-blocks | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR1711` | useless-return | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR1712` | swap-with-temporary-variable | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR1714` | repeated-equality-comparison | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR1716` | boolean-chained-comparison | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLR1722` | sys-exit-alias | low | 개별 (suspicious) | exit()·quit() 대신 sys.exit() |
| ruff | `ruff:PLR1730` | if-stmt-min-max | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR1733` | unnecessary-dict-index-lookup | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR1736` | unnecessary-list-index-lookup | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLR2004` | magic-value-comparison | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR2044` | empty-comment | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLR5501` | collapsible-else-if | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR6104` | non-augmented-assignment | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR6201` | literal-membership | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLR6301` | no-self-use | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLW0108` | unnecessary-lambda | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLW0120` | useless-else-on-loop | low | 개별 (suspicious) | 쓸모없는 loop else |
| ruff | `ruff:PLW0131` | named-expr-without-context | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:PLW0602` | global-variable-not-assigned | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:PLW0603` | global-statement | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:PLW0604` | global-at-module-level | low | 개별 (correctness) | 모듈 수준 global 문(효과 없음) |
| ruff | `ruff:PLW0717` | too-many-statements-in-try-clause | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLW1514` | unspecified-encoding | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLW1641` | eq-without-hash | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLW2901` | redefined-loop-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLW3201` | bad-dunder-method-name | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PLW3301` | nested-min-max | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PT001` | pytest-fixture-incorrect-parentheses-style | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT002` | pytest-fixture-positional-args | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT003` | pytest-extraneous-scope-function | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT006` | pytest-parametrize-names-wrong-type | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT007` | pytest-parametrize-values-wrong-type | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT008` | pytest-patch-with-lambda | low | 접두사 (complexity) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT009` | pytest-unittest-assertion | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT011` | pytest-raises-too-broad | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT012` | pytest-raises-with-multiple-statements | low | 접두사 (suspicious) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT013` | pytest-incorrect-pytest-import | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT015` | pytest-assert-always-false | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT016` | pytest-fail-without-message | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT017` | pytest-assert-in-except | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT018` | pytest-composite-assertion | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT019` | pytest-fixture-param-without-value | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT020` | pytest-deprecated-yield-fixture | low | 접두사 (suspicious) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT021` | pytest-fixture-finalizer-callback | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT022` | pytest-useless-yield-fixture | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT023` | pytest-incorrect-mark-parentheses-style | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT024` | pytest-unnecessary-asyncio-mark-on-fixture | low | 접두사 (correctness) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT027` | pytest-unittest-raises-assertion | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT028` | pytest-parameter-with-default-argument | low | 접두사 (suspicious) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT029` | pytest-warns-without-warning | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT030` | pytest-warns-too-broad | low | 접두사 (pedantic) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PT031` | pytest-warns-with-multiple-statements | low | 접두사 (suspicious) | pytest 작성 관례(테스트 코드) |
| ruff | `ruff:PTH100` | os-path-abspath | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH101` | os-chmod | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH102` | os-mkdir | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH103` | os-makedirs | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH104` | os-rename | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH105` | os-replace | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH106` | os-rmdir | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH107` | os-remove | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH108` | os-unlink | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH109` | os-getcwd | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH110` | os-path-exists | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH111` | os-path-expanduser | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH112` | os-path-isdir | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH113` | os-path-isfile | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH114` | os-path-islink | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH115` | os-readlink | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH116` | os-stat | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH117` | os-path-isabs | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH118` | os-path-join | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH119` | os-path-basename | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH120` | os-path-dirname | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH121` | os-path-samefile | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH122` | os-path-splitext | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH123` | builtin-open | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH124` | py-path | low | 개별 (suspicious) | py.path 사용 |
| ruff | `ruff:PTH201` | path-constructor-current-directory | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH202` | os-path-getsize | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH203` | os-path-getatime | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH204` | os-path-getmtime | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH205` | os-path-getctime | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH206` | os-sep-split | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH207` | glob | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH208` | os-listdir | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PTH211` | os-symlink | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:PYI001` | unprefixed-type-param | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI002` | complex-if-statement-in-stub | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI003` | unrecognized-version-info-check | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI004` | patch-version-comparison | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI005` | wrong-tuple-length-version-comparison | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI006` | bad-version-info-comparison | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI007` | unrecognized-platform-check | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI008` | unrecognized-platform-name | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI009` | pass-statement-stub-body | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI010` | non-empty-stub-body | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI011` | typed-argument-default-in-stub | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI012` | pass-in-class-body | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI013` | ellipsis-in-non-empty-class-body | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI014` | argument-default-in-stub | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI015` | assignment-default-in-stub | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI016` | duplicate-union-member | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI017` | complex-assignment-in-stub | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI018` | unused-private-type-var | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI019` | custom-type-var-for-self | low | 접두사 (complexity) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI020` | quoted-annotation-in-stub | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI021` | docstring-in-stub | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI024` | collections-named-tuple | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI025` | unaliased-collections-abc-set-import | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI026` | type-alias-without-annotation | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI029` | str-or-repr-defined-in-stub | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI030` | unnecessary-literal-union | low | 접두사 (complexity) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI032` | any-eq-ne-annotation | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI033` | legacy-type-comment | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI034` | non-self-return-type | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI035` | unassigned-special-variable-in-stub | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI036` | bad-exit-annotation | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI041` | redundant-numeric-union | low | 접두사 (complexity) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI042` | snake-case-type-alias | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI043` | t-suffixed-type-alias | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI044` | future-annotations-in-stub | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI045` | iter-method-return-iterable | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI046` | unused-private-protocol | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI047` | unused-private-type-alias | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI048` | stub-body-multiple-statements | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI049` | unused-private-typed-dict | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI050` | no-return-argument-annotation-in-stub | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI051` | redundant-literal-union | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI052` | unannotated-assignment-in-stub | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI053` | string-or-bytes-too-long | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI054` | numeric-literal-too-long | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI055` | unnecessary-type-union | low | 접두사 (complexity) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI056` | unsupported-method-call-on-all | low | 접두사 (pedantic) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI057` | byte-string-usage | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI058` | generator-return-from-iter-method | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI059` | generic-not-last-base-class | low | 접두사 (correctness) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI061` | redundant-none-literal | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI062` | duplicate-literal-member | low | 접두사 (suspicious) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI063` | pep484-style-positional-only-parameter | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI064` | redundant-final-literal | low | 접두사 (complexity) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:PYI066` | bad-version-info-order | low | 접두사 (style) | 타입 스텁(.pyi) 작성 규칙 — 실행 코드에 영향 없음 |
| ruff | `ruff:Q000` | bad-quotes-inline-string | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:Q001` | bad-quotes-multiline-string | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:Q002` | bad-quotes-docstring | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:Q003` | avoidable-escaped-quote | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:Q004` | unnecessary-escaped-quote | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:RET501` | unnecessary-return-none | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RET502` | implicit-return-value | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RET503` | implicit-return | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RET504` | unnecessary-assign | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RET505` | superfluous-else-return | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RET506` | superfluous-else-raise | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RET507` | superfluous-else-continue | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RET508` | superfluous-else-break | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RSE102` | unnecessary-paren-on-raise-exception | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF002` | ambiguous-unicode-character-docstring | low | 개별 (security) | 헷갈리는 유니코드 문자(docstring) |
| ruff | `ruff:RUF003` | ambiguous-unicode-character-comment | low | 개별 (security) | 헷갈리는 유니코드 문자(주석) |
| ruff | `ruff:RUF005` | collection-literal-concatenation | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF006` | asyncio-dangling-task | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF007` | zip-instead-of-pairwise | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF010` | explicit-f-string-type-conversion | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF019` | unnecessary-key-check | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF021` | parenthesize-chained-operators | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF022` | unsorted-dunder-all | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF023` | unsorted-dunder-slots | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF029` | unused-async | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF031` | incorrectly-parenthesized-tuple-in-subscript | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF036` | none-not-at-end-of-union | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF037` | unnecessary-empty-iterable-within-deque-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF038` | redundant-bool-literal | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF039` | unraw-re-pattern | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF041` | unnecessary-nested-literal | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF043` | pytest-raises-ambiguous-pattern | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF046` | unnecessary-cast-to-int | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF047` | needless-else | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF050` | unnecessary-if | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF051` | if-key-in-dict-del | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF052` | used-dummy-variable | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF054` | indented-form-feed | low | 개별 (correctness) | 들여쓰기된 form feed |
| ruff | `ruff:RUF055` | unnecessary-regular-expression | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF056` | falsy-dict-get-fallback | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF057` | unnecessary-round | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF058` | starmap-zip | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF061` | legacy-form-pytest-raises | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF067` | non-empty-init-module | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF068` | duplicate-entry-in-dunder-all | low | 개별 (correctness) | __all__ 중복 항목 |
| ruff | `ruff:RUF070` | unnecessary-assign-before-yield | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF072` | useless-finally | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:RUF100` | unused-noqa | low | 개별 (suspicious) | 쓰지 않는 noqa |
| ruff | `ruff:RUF101` | redirected-noqa | low | 개별 (suspicious) | 바뀐 규칙 코드의 noqa |
| ruff | `ruff:RUF102` | invalid-rule-code | low | 개별 (suspicious) | 잘못된 규칙 코드의 noqa |
| ruff | `ruff:RUF103` | invalid-suppression-comment | low | 개별 (suspicious) | 잘못된 억제 주석 |
| ruff | `ruff:RUF104` | unmatched-suppression-comment | low | 개별 (suspicious) | 짝이 맞지 않는 억제 주석 |
| ruff | `ruff:RUF105` | noqa-comments | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:RUF106` | rule-codes-in-suppression-comments | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:RUF201` | rule-codes-in-selectors | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:S101` | assert | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:S404` | suspicious-subprocess-import | low | 개별 (security) | subprocess import 자체는 정상 — 사용 위치는 S6xx가 본다 |
| ruff | `ruff:SIM101` | duplicate-isinstance-call | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM102` | collapsible-if | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM103` | needless-bool | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM105` | suppressible-exception | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM108` | if-else-block-instead-of-if-exp | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM109` | compare-with-tuple | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM110` | reimplemented-builtin | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM112` | uncapitalized-environment-variables | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM113` | enumerate-for-loop | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM116` | if-else-block-instead-of-dict-lookup | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM117` | multiple-with-statements | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM118` | in-dict-keys | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM201` | negate-equal-op | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM202` | negate-not-equal-op | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM208` | double-negation | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM210` | if-expr-with-true-false | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM211` | if-expr-with-false-true | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM212` | if-expr-with-twisted-arms | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM300` | yoda-conditions | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM401` | if-else-block-instead-of-dict-get | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM905` | split-static-string | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SIM910` | dict-get-with-none-default | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SIM911` | zip-dict-keys-and-values | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:SLF001` | private-member-access | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SLOT000` | no-slots-in-str-subclass | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SLOT001` | no-slots-in-tuple-subclass | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:SLOT002` | no-slots-in-namedtuple-subclass | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:T201` | print | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:T203` | p-print | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:TC001` | typing-only-first-party-import | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TC002` | typing-only-third-party-import | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TC003` | typing-only-standard-library-import | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TC006` | runtime-cast-value | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TC008` | quoted-type-alias | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD001` | invalid-todo-tag | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD002` | missing-todo-author | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD003` | missing-todo-link | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD004` | missing-todo-colon | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD005` | missing-todo-description | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD006` | invalid-todo-capitalization | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TD007` | missing-space-after-todo-colon | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TID251` | banned-api | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:TID252` | relative-imports | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:TID253` | banned-module-level-imports | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:TID254` | lazy-import-mismatch | low | 분류군 (restriction) | 팀 정책으로 쓰는 제한 규칙 |
| ruff | `ruff:TRY002` | raise-vanilla-class | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:TRY003` | raise-vanilla-args | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TRY004` | type-check-without-type-error | low | 분류군 (style) | 스타일·관례 |
| ruff | `ruff:TRY201` | verbose-raise | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:TRY203` | useless-try-except | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:TRY300` | try-consider-else | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TRY301` | raise-within-try | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TRY400` | error-instead-of-exception | low | 분류군 (pedantic) | 엄격한 관례·가독성 |
| ruff | `ruff:TRY401` | verbose-log-message | low | 분류군 (complexity) | 더 단순하게 쓸 수 있는 코드 |
| ruff | `ruff:UP001` | useless-metaclass-type | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP003` | type-of-primitive | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP004` | useless-object-inheritance | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP005` | deprecated-unittest-alias | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP006` | non-pep585-annotation | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP007` | non-pep604-annotation-union | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP008` | super-call-with-parameters | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP009` | utf8-encoding-declaration | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP010` | unnecessary-future-import | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP011` | lru-cache-without-parameters | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP012` | unnecessary-encode-utf8 | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP013` | convert-typed-dict-functional-to-class | low | 접두사 (pedantic) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP014` | convert-named-tuple-functional-to-class | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP015` | redundant-open-modes | low | 접두사 (pedantic) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP017` | datetime-timezone-utc | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP018` | native-literals | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP019` | typing-text-str-alias | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP020` | open-alias | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP021` | replace-universal-newlines | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP022` | replace-stdout-stderr | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP023` | deprecated-c-element-tree | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP024` | os-error-alias | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP025` | unicode-kind-prefix | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP026` | deprecated-mock-import | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP028` | yield-in-for-loop | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP029` | unnecessary-builtin-import | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP030` | format-literals | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP031` | printf-string-formatting | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP032` | f-string | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP033` | lru-cache-with-maxsize-none | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP034` | extraneous-parentheses | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP035` | deprecated-import | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP036` | outdated-version-block | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP037` | quoted-annotation | low | 접두사 (correctness) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP039` | unnecessary-class-parentheses | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP040` | non-pep695-type-alias | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP041` | timeout-error-alias | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP042` | replace-str-enum | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP043` | unnecessary-default-type-args | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP044` | non-pep646-unpack | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP045` | non-pep604-annotation-optional | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP046` | non-pep695-generic-class | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP047` | non-pep695-generic-function | low | 접두사 (complexity) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP048` | while-one | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP049` | private-type-parameter | low | 접두사 (style) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP050` | useless-class-metaclass-type | low | 접두사 (correctness) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP051` | deprecated-abc-decorator | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:UP052` | context-manager-iterator | low | 접두사 (suspicious) | 최신 문법으로 바꾸기(pyupgrade) — 동작 같음 |
| ruff | `ruff:W191` | tab-indentation | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:W291` | trailing-whitespace | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:W292` | missing-newline-at-end-of-file | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:W293` | blank-line-with-whitespace | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:W391` | too-many-newlines-at-end-of-file | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| ruff | `ruff:W505` | doc-line-too-long | low | 분류군 (formatting) | 공백·줄 길이 등 서식 |
| eslint | `eslint:@typescript-eslint/no-implied-eval` | Disallow the use of `eval()`-like functions | critical | 개별 (suggestion) | 암묵적 eval(TS) |
| eslint | `eslint:no-eval` | Disallow the use of `eval()` | critical | 개별 (suggestion) | eval — 임의 코드 실행 |
| eslint | `eslint:no-implied-eval` | Disallow the use of `eval()`-like methods | critical | 개별 (suggestion) | 문자열 setTimeout 등 암묵적 eval |
| eslint | `eslint:no-new-func` | Disallow `new` operators with the `Function` object | critical | 개별 (suggestion) | new Function — 임의 코드 실행 |
| eslint | `eslint:no-script-url` | Disallow `javascript:` URLs | critical | 개별 (suggestion) | javascript: URL — XSS |
| eslint | `eslint:@typescript-eslint/await-thenable` | Disallow awaiting a value that is not a Thenable | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-array-delete` | Disallow using the `delete` operator on array values | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-dupe-class-members` | Disallow duplicate class members | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-duplicate-enum-values` | Disallow duplicate enum member values | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-floating-promises` | Require Promise-like statements to be handled appropriately | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-for-in-array` | Disallow iterating over an array with a for-in loop | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-loss-of-precision` | Disallow literal numbers that lose precision | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-misused-new` | Enforce valid definition of `new` and `constructor` | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-misused-promises` | Disallow Promises in places not designed to handle them | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-misused-spread` | Disallow using the spread operator when it might cause unexpected behavior | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-non-null-asserted-optional-chain` | Disallow non-null assertions after an optional chain expression | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-unsafe-enum-assignment` | Disallow assigning non-enum values to enum typed locations | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-unsafe-unary-minus` | Require unary negation to take a number | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/no-var-requires` | Disallow `require` statements except in import statements | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/prefer-ts-expect-error` | Enforce using `@ts-expect-error` over `@ts-ignore` | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:array-callback-return` | Enforce `return` statements in callbacks of array methods | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:constructor-super` | Require `super()` calls in constructors | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:for-direction` | Enforce `for` loop update clause moving the counter in the right direction | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:getter-return` | Enforce `return` statements in getters | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-async-promise-executor` | Disallow using an async function as a Promise executor | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-buffer-constructor` | Disallow use of the `Buffer()` constructor | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-class-assign` | Disallow reassigning class members | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-compare-neg-zero` | Disallow comparing against `-0` | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-cond-assign` | Disallow assignment operators in conditional expressions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-const-assign` | Disallow reassigning `const`, `using`, and `await using` variables | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-constant-binary-expression` | Disallow expressions where the operation doesn't affect the value | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-constant-condition` | Disallow constant expressions in conditions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-debugger` | Disallow the use of `debugger` | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-dupe-args` | Disallow duplicate arguments in `function` definitions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-dupe-class-members` | Disallow duplicate class members | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-dupe-else-if` | Disallow duplicate conditions in if-else-if chains | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-dupe-keys` | Disallow duplicate keys in object literals | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-duplicate-case` | Disallow duplicate case labels | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-empty-character-class` | Disallow empty character classes in regular expressions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-ex-assign` | Disallow reassigning exceptions in `catch` clauses | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-extend-native` | Disallow extending native types | high | 개별 (suggestion) | 내장 객체 프로토타입 확장 — 다른 코드와 충돌 |
| eslint | `eslint:no-func-assign` | Disallow reassigning `function` declarations | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-global-assign` | Disallow assignments to native objects or read-only global variables | high | 개별 (suggestion) | 전역 객체(undefined 등) 덮어쓰기 |
| eslint | `eslint:no-import-assign` | Disallow assigning to imported bindings | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-invalid-regexp` | Disallow invalid regular expression strings in `RegExp` constructors | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-loss-of-precision` | Disallow literal numbers that lose precision | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-misleading-character-class` | Disallow characters which are made with multiple code points in character class syntax | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-negated-in-lhs` | Disallow negating the left operand in `in` expressions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-new-native-nonconstructor` | Disallow `new` operators with global non-constructor functions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-new-symbol` | Disallow `new` operators with the `Symbol` object | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-obj-calls` | Disallow calling global object properties as functions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-self-assign` | Disallow assignments where both sides are exactly the same | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-self-compare` | Disallow comparisons where both sides are exactly the same | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-setter-return` | Disallow returning values from setters | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-shadow-restricted-names` | Disallow identifiers from shadowing restricted names | high | 개별 (suggestion) | NaN·undefined 등 예약 이름 가리기 |
| eslint | `eslint:no-sparse-arrays` | Disallow sparse arrays | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-this-before-super` | Disallow `this`/`super` before calling `super()` in constructors | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unassigned-vars` | Disallow `let` or `var` variables that are read but never assigned | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-undef` | Disallow the use of undeclared variables unless mentioned in `/*global */` comments | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unexpected-multiline` | Disallow confusing multiline expressions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unmodified-loop-condition` | Disallow unmodified loop conditions | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unreachable` | Disallow unreachable code after `return`, `throw`, `continue`, and `break` statements | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unreachable-loop` | Disallow loops with a body that allows only one iteration | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unsafe-finally` | Disallow control flow statements in `finally` blocks | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unsafe-negation` | Disallow negating the left operand of relational operators | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-unsafe-optional-chaining` | Disallow use of optional chaining in contexts where the `undefined` value is not allowed | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:no-with` | Disallow `with` statements | high | 개별 (suggestion) | with 문 — strict 모드 구문 오류, 이름 해석 혼란 |
| eslint | `syntax-error` | (특수 키) | high | 개별 | 파싱 실패(구문 오류) — 파일을 실행할 수 없다 |
| eslint | `eslint:use-isnan` | Require calls to `isNaN()` when checking for `NaN` | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:valid-typeof` | Enforce comparing `typeof` expressions against valid strings | high | 분류군 (problem) | meta.type problem — 오류나 혼란을 일으킬 코드 |
| eslint | `eslint:@typescript-eslint/ban-ts-comment` | Disallow `@ts-<directive>` comments or require descriptions after directives | medium | 개별 (problem) | @ts-ignore 등 타입 검사 무시 주석 |
| eslint | `eslint:@typescript-eslint/consistent-return` | Require `return` statements to either always or never specify values | medium | 개별 (suggestion) | 값 반환 불일치(TS) |
| eslint | `eslint:@typescript-eslint/no-base-to-string` | Require `.toString()` and `.toLocaleString()` to only be called on objects which provide useful information when stringified | medium | 개별 (suggestion) | [object Object]로 바뀌는 문자열 변환 |
| eslint | `eslint:@typescript-eslint/no-confusing-non-null-assertion` | Disallow non-null assertion in locations that may be confusing | medium | 개별 (problem) | 비교와 헷갈리는 ! 단언 |
| eslint | `eslint:@typescript-eslint/no-confusing-void-expression` | Require expressions of type void to appear in statement position | medium | 개별 (problem) | void 식을 값으로 사용 |
| eslint | `eslint:@typescript-eslint/no-deprecated` | Disallow using code marked as `@deprecated` | medium | 개별 (problem) | 폐기된 API 사용 |
| eslint | `eslint:@typescript-eslint/no-explicit-any` | Disallow the `any` type | medium | 개별 (suggestion) | any — 타입 검사를 무력화 |
| eslint | `eslint:@typescript-eslint/no-invalid-this` | Disallow `this` keywords outside of classes or class-like objects | medium | 개별 (suggestion) | 클래스 밖 this(TS) |
| eslint | `eslint:@typescript-eslint/no-invalid-void-type` | Disallow `void` type outside of generic or return types | medium | 개별 (problem) | 잘못된 위치의 void 타입 |
| eslint | `eslint:@typescript-eslint/no-mixed-enums` | Disallow enums from having both number and string members | medium | 개별 (problem) | 숫자·문자 섞인 enum |
| eslint | `eslint:@typescript-eslint/no-non-null-asserted-nullish-coalescing` | Disallow non-null assertions in the left operand of a nullish coalescing operator | medium | 개별 (problem) | ?? 왼쪽의 ! 단언 |
| eslint | `eslint:@typescript-eslint/no-non-null-assertion` | Disallow non-null assertions using the `!` postfix operator | medium | 개별 (problem) | ! 비-null 단언 — null 오류를 숨김 |
| eslint | `eslint:@typescript-eslint/no-redeclare` | Disallow variable redeclaration | medium | 개별 (suggestion) | 같은 이름 다시 선언(TS) |
| eslint | `eslint:@typescript-eslint/no-this-alias` | Disallow aliasing `this` | medium | 개별 (suggestion) | this 별칭 |
| eslint | `eslint:@typescript-eslint/no-unnecessary-condition` | Disallow conditionals where the type is always truthy or always falsy | medium | 개별 (suggestion) | 항상 참·거짓인 조건(타입 기준) |
| eslint | `eslint:@typescript-eslint/no-unsafe-argument` | Disallow calling a function with a value with type `any` | medium | 개별 (problem) | any를 인자로 전달 |
| eslint | `eslint:@typescript-eslint/no-unsafe-assignment` | Disallow assigning a value with type `any` to variables and properties | medium | 개별 (problem) | any 대입 |
| eslint | `eslint:@typescript-eslint/no-unsafe-call` | Disallow calling a value with type `any` | medium | 개별 (problem) | any 호출 |
| eslint | `eslint:@typescript-eslint/no-unsafe-declaration-merging` | Disallow unsafe declaration merging | medium | 개별 (problem) | 클래스·인터페이스 병합 |
| eslint | `eslint:@typescript-eslint/no-unsafe-enum-comparison` | Disallow comparing an enum value with a non-enum value | medium | 개별 (suggestion) | enum과 다른 값 비교 |
| eslint | `eslint:@typescript-eslint/no-unsafe-function-type` | Disallow using the unsafe built-in Function type | medium | 개별 (problem) | Function 타입 — 인자·반환 검사 없음 |
| eslint | `eslint:@typescript-eslint/no-unsafe-member-access` | Disallow member access on a value with type `any` | medium | 개별 (problem) | any 멤버 접근 |
| eslint | `eslint:@typescript-eslint/no-unsafe-return` | Disallow returning a value with type `any` from a function | medium | 개별 (problem) | any 반환 |
| eslint | `eslint:@typescript-eslint/no-unsafe-type-assertion` | Disallow type assertions that narrow a type | medium | 개별 (problem) | 좁히는 타입 단언 |
| eslint | `eslint:@typescript-eslint/no-unused-expressions` | Disallow unused expressions | medium | 개별 (suggestion) | 효과 없는 식(TS) |
| eslint | `eslint:@typescript-eslint/no-unused-private-class-members` | Disallow unused private class members | medium | 개별 (problem) | 사용하지 않는 private 멤버(TS) |
| eslint | `eslint:@typescript-eslint/no-unused-vars` | Disallow unused variables | medium | 개별 (problem) | 사용하지 않는 변수(TS) |
| eslint | `eslint:@typescript-eslint/no-use-before-define` | Disallow the use of variables before they are defined | medium | 개별 (problem) | 정의 전 사용(TS) |
| eslint | `eslint:@typescript-eslint/no-wrapper-object-types` | Disallow using confusing built-in primitive class wrappers | medium | 개별 (problem) | String 등 래퍼 타입 |
| eslint | `eslint:@typescript-eslint/only-throw-error` | Disallow throwing non-`Error` values as exceptions | medium | 개별 (problem) | Error가 아닌 값 throw(TS) |
| eslint | `eslint:@typescript-eslint/prefer-promise-reject-errors` | Require using Error objects as Promise rejection reasons | medium | 개별 (suggestion) | Error가 아닌 값으로 reject(TS) |
| eslint | `eslint:@typescript-eslint/related-getter-setter-pairs` | Enforce that `get()` types should be assignable to their equivalent `set()` type | medium | 개별 (problem) | getter·setter 타입 불일치 |
| eslint | `eslint:@typescript-eslint/require-array-sort-compare` | Require `Array#sort` and `Array#toSorted` calls to always provide a `compareFunction` | medium | 개별 (problem) | 비교 함수 없는 sort(문자열 정렬) |
| eslint | `eslint:@typescript-eslint/restrict-plus-operands` | Require both operands of addition to be the same type and be `bigint`, `number`, or `string` | medium | 개별 (problem) | 형이 다른 + 연산 |
| eslint | `eslint:@typescript-eslint/restrict-template-expressions` | Enforce template literal expressions to be of `string` type | medium | 개별 (problem) | 템플릿 문자열의 의도하지 않은 형 |
| eslint | `eslint:@typescript-eslint/return-await` | Enforce consistent awaiting of returned promises | medium | 개별 (problem) | try 안 return await 누락 등 |
| eslint | `eslint:@typescript-eslint/strict-void-return` | Disallow passing a value-returning function in a position accepting a void function | medium | 개별 (problem) | void 반환 위치에 값 반환 |
| eslint | `eslint:@typescript-eslint/switch-exhaustiveness-check` | Require switch-case statements to be exhaustive | medium | 개별 (suggestion) | 모든 경우를 다루지 않는 switch |
| eslint | `eslint:@typescript-eslint/unbound-method` | Enforce unbound methods are called with their expected scope | medium | 개별 (problem) | this 없이 메서드 참조 |
| eslint | `eslint:complexity` | Enforce a maximum cyclomatic complexity allowed in a program | medium | 개별 (suggestion) | 순환 복잡도 초과(유지보수 위험) |
| eslint | `eslint:consistent-return` | Require `return` statements to either always or never specify values | medium | 개별 (suggestion) | 값을 돌려주기도 안 돌려주기도 하는 함수 |
| eslint | `eslint:eqeqeq` | Require the use of `===` and `!==` | medium | 개별 (suggestion) | == 형 변환 비교 |
| eslint | `eslint:no-await-in-loop` | Disallow `await` inside of loops | medium | 개별 (problem) | 루프 안 await — 순차 실행으로 느림 |
| eslint | `eslint:no-caller` | Disallow the use of `arguments.caller` or `arguments.callee` | medium | 개별 (suggestion) | arguments.caller/callee — strict 모드 오류 |
| eslint | `eslint:no-case-declarations` | Disallow lexical declarations in case clauses | medium | 개별 (suggestion) | case 안의 선언 — 다른 case로 새는 범위 |
| eslint | `eslint:no-constructor-return` | Disallow returning value from constructor | medium | 개별 (problem) | 생성자에서 값 반환 |
| eslint | `eslint:no-control-regex` | Disallow control characters in regular expressions | medium | 개별 (problem) | 정규식 안 제어 문자 |
| eslint | `eslint:no-delete-var` | Disallow deleting variables | medium | 개별 (suggestion) | 변수 delete |
| eslint | `eslint:no-empty` | Disallow empty block statements | medium | 개별 (suggestion) | 빈 블록(예외 삼키기 포함) |
| eslint | `eslint:no-empty-pattern` | Disallow empty destructuring patterns | medium | 개별 (problem) | 빈 구조 분해 패턴 |
| eslint | `eslint:no-fallthrough` | Disallow fallthrough of `case` statements | medium | 개별 (problem) | case 빠져나감 — 의도한 경우도 많음 |
| eslint | `eslint:no-implicit-globals` | Disallow declarations in the global scope | medium | 개별 (suggestion) | 암묵적 전역 선언 |
| eslint | `eslint:no-inner-declarations` | Disallow variable or `function` declarations in nested blocks | medium | 개별 (problem) | 블록 안 함수 선언 |
| eslint | `eslint:no-invalid-this` | Disallow use of `this` in contexts where the value of `this` is `undefined` | medium | 개별 (suggestion) | 클래스 밖 this |
| eslint | `eslint:no-irregular-whitespace` | Disallow irregular whitespace | medium | 개별 (problem) | 보이지 않는 공백 문자 |
| eslint | `eslint:no-loop-func` | Disallow function declarations that contain unsafe references inside loop statements | medium | 개별 (suggestion) | 루프 안 함수가 루프 변수를 늦게 참조 |
| eslint | `eslint:no-new-wrappers` | Disallow `new` operators with the `String`, `Number`, and `Boolean` objects | medium | 개별 (suggestion) | new String 등 래퍼 객체 |
| eslint | `eslint:no-nonoctal-decimal-escape` | Disallow `\8` and `\9` escape sequences in string literals | medium | 개별 (suggestion) | \8 \9 이스케이프 |
| eslint | `eslint:no-octal` | Disallow octal literals | medium | 개별 (suggestion) | 8진 리터럴 — strict 모드 오류 |
| eslint | `eslint:no-octal-escape` | Disallow octal escape sequences in string literals | medium | 개별 (suggestion) | 8진 이스케이프 |
| eslint | `eslint:no-promise-executor-return` | Disallow returning values from Promise executor functions | medium | 개별 (problem) | Promise executor에서 값 반환 |
| eslint | `eslint:no-proto` | Disallow the use of the `__proto__` property | medium | 개별 (suggestion) | __proto__ 사용 |
| eslint | `eslint:no-prototype-builtins` | Disallow calling some `Object.prototype` methods directly on objects | medium | 개별 (problem) | obj.hasOwnProperty 직접 호출 |
| eslint | `eslint:no-redeclare` | Disallow variable redeclaration | medium | 개별 (suggestion) | 같은 이름 다시 선언 |
| eslint | `eslint:no-return-assign` | Disallow assignment operators in `return` statements | medium | 개별 (suggestion) | return 안의 대입(비교 오타일 수 있음) |
| eslint | `eslint:no-sequences` | Disallow comma operators | medium | 개별 (suggestion) | 쉼표 연산자(의도하지 않은 경우가 많음) |
| eslint | `eslint:no-template-curly-in-string` | Disallow template literal placeholder syntax in regular strings | medium | 개별 (problem) | 일반 문자열 안 ${} — 템플릿 문자열 오타 |
| eslint | `eslint:no-throw-literal` | Disallow throwing literals as exceptions | medium | 개별 (suggestion) | Error가 아닌 값 throw — 스택 없음 |
| eslint | `eslint:no-unused-expressions` | Disallow unused expressions | medium | 개별 (suggestion) | 효과 없는 식 — 의도한 호출·대입 누락일 수 있음 |
| eslint | `eslint:no-unused-private-class-members` | Disallow unused private class members | medium | 개별 (problem) | 사용하지 않는 private 멤버 |
| eslint | `eslint:no-unused-vars` | Disallow unused variables | medium | 개별 (problem) | 사용하지 않는 변수 — 오타·누락의 흔적일 수 있음(기준표 medium) |
| eslint | `eslint:no-use-before-define` | Disallow the use of variables before they are defined | medium | 개별 (problem) | 정의 전 사용(TDZ 오류 가능) |
| eslint | `eslint:no-useless-assignment` | Disallow variable assignments when the value is not used | medium | 개별 (problem) | 읽지 않고 덮어쓰는 대입 |
| eslint | `eslint:no-useless-backreference` | Disallow useless backreferences in regular expressions | medium | 개별 (problem) | 항상 빈 문자열과 맞는 역참조 |
| eslint | `eslint:prefer-promise-reject-errors` | Require using Error objects as Promise rejection reasons | medium | 개별 (suggestion) | Error가 아닌 값으로 reject |
| eslint | `eslint:require-atomic-updates` | Disallow assignments that can lead to race conditions due to usage of `await` or `yield` | medium | 개별 (problem) | await 사이 경쟁 조건 가능(오탐 많음) |
| eslint | `eslint:require-yield` | Require generator functions to contain `yield` | medium | 개별 (suggestion) | yield 없는 제너레이터 |
| eslint | `eslint:@typescript-eslint/adjacent-overload-signatures` | Require that function overload signatures be consecutive | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/array-type` | Require consistently using either `T[]` or `Array<T>` for arrays | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/ban-tslint-comment` | Disallow `// tslint:<rule-flag>` comments | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/class-literal-property-style` | Enforce that literals on classes are exposed in a consistent style | low | 개별 (problem) | 클래스 리터럴 속성 스타일 |
| eslint | `eslint:@typescript-eslint/class-methods-use-this` | Enforce that class methods utilize `this` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/consistent-generic-constructors` | Enforce specifying generic type arguments on type annotation or constructor name of a constructor call | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/consistent-indexed-object-style` | Require or disallow the `Record` type | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/consistent-type-assertions` | Enforce consistent usage of type assertions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/consistent-type-definitions` | Enforce type definitions to consistently use either `interface` or `type` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/consistent-type-exports` | Enforce consistent usage of type exports | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/consistent-type-imports` | Enforce consistent usage of type imports | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/default-param-last` | Enforce default parameters to be last | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/dot-notation` | Enforce dot notation whenever possible | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/explicit-function-return-type` | Require explicit return types on functions and class methods | low | 개별 (problem) | 함수 반환 타입 명시(정책) |
| eslint | `eslint:@typescript-eslint/explicit-member-accessibility` | Require explicit accessibility modifiers on class properties and methods | low | 개별 (problem) | 접근 제한자 명시(정책) |
| eslint | `eslint:@typescript-eslint/explicit-module-boundary-types` | Require explicit return and argument types on exported functions' and classes' public class methods | low | 개별 (problem) | 모듈 경계 타입 명시(정책) |
| eslint | `eslint:@typescript-eslint/init-declarations` | Require or disallow initialization in variable declarations | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/max-params` | Enforce a maximum number of parameters in function definitions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/member-ordering` | Require a consistent member declaration order | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/method-signature-style` | Enforce using a particular method signature syntax | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/naming-convention` | Enforce naming conventions for everything across a codebase | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-array-constructor` | Disallow generic `Array` constructors | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-duplicate-type-constituents` | Disallow duplicate constituents of union or intersection types | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-dynamic-delete` | Disallow using the `delete` operator on computed key expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-empty-function` | Disallow empty functions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-empty-interface` | Disallow the declaration of empty interfaces | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-empty-object-type` | Disallow accidentally using the "empty object" type | low | 개별 (suggestion) | {} 타입 — 거의 모든 값 허용 |
| eslint | `eslint:@typescript-eslint/no-extra-non-null-assertion` | Disallow extra non-null assertions | low | 개별 (problem) | 중복 ! 단언 |
| eslint | `eslint:@typescript-eslint/no-extraneous-class` | Disallow classes used as namespaces | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-generated-empty-object-type` | Disallow type operations that resolve to the "empty object" type | low | 개별 (problem) | 생성된 빈 객체 타입 |
| eslint | `eslint:@typescript-eslint/no-import-type-side-effects` | Enforce the use of top-level import type qualifier when an import only has specifiers with inline type qualifiers | low | 개별 (problem) | 타입 import 부수 효과(번들 크기) |
| eslint | `eslint:@typescript-eslint/no-inferrable-types` | Disallow explicit type declarations for variables or parameters initialized to a number, string, or boolean | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-loop-func` | Disallow function declarations that contain unsafe references inside loop statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-magic-numbers` | Disallow magic numbers | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-meaningless-void-operator` | Disallow the `void` operator except when used to discard a value | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-namespace` | Disallow TypeScript namespaces | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-redundant-type-constituents` | Disallow members of unions and intersections that do nothing or override type information | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-require-imports` | Disallow invocation of `require()` | low | 개별 (problem) | require 대신 import(스타일) |
| eslint | `eslint:@typescript-eslint/no-restricted-imports` | Disallow specified modules when loaded by `import` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-restricted-types` | Disallow certain types | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-shadow` | Disallow variable declarations from shadowing variables declared in the outer scope | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-type-alias` | Disallow type aliases | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-boolean-literal-compare` | Disallow unnecessary equality comparisons against boolean literals | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-parameter-property-assignment` | Disallow unnecessary assignment of constructor property parameter | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-qualifier` | Disallow unnecessary namespace qualifiers | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-template-expression` | Disallow unnecessary template expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-type-arguments` | Disallow type arguments that are equal to the default | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-type-assertion` | Disallow type assertions that do not change the type of an expression | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-type-constraint` | Disallow unnecessary constraints on generic types | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-type-conversion` | Disallow conversion idioms when they do not change the type or value of the expression | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-unnecessary-type-parameters` | Disallow type parameters that aren't used multiple times | low | 개별 (problem) | 불필요한 타입 매개변수 |
| eslint | `eslint:@typescript-eslint/no-useless-constructor` | Disallow unnecessary constructors | low | 개별 (problem) | 쓸모없는 생성자 |
| eslint | `eslint:@typescript-eslint/no-useless-default-assignment` | Disallow default values that will never be used | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/no-useless-empty-export` | Disallow empty exports that don't change anything in a module file | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/non-nullable-type-assertion-style` | Enforce non-null assertions over explicit type assertions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/parameter-properties` | Require or disallow parameter properties in class constructors | low | 개별 (problem) | 매개변수 속성 스타일 |
| eslint | `eslint:@typescript-eslint/prefer-as-const` | Enforce the use of `as const` over literal type | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-destructuring` | Require destructuring from arrays and/or objects | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-enum-initializers` | Require each enum member value to be explicitly initialized | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-find` | Enforce the use of Array.prototype.find() over Array.prototype.filter() followed by [0] when looking for a single result | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-for-of` | Enforce the use of `for-of` loop over the standard `for` loop where possible | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-function-type` | Enforce using function types instead of interfaces with call signatures | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-includes` | Enforce `includes` method over `indexOf` method | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-literal-enum-member` | Require all enum members to be literal values | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-namespace-keyword` | Require using `namespace` keyword over `module` keyword to declare custom TypeScript modules | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-nullish-coalescing` | Enforce using the nullish coalescing operator instead of logical assignments or chaining | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-optional-chain` | Enforce using concise optional chain expressions instead of chained logical ands, negated logical ors, or empty objects | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-readonly` | Require private members to be marked as `readonly` if they're never modified outside of the constructor | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-readonly-parameter-types` | Require function parameters to be typed as `readonly` to prevent accidental mutation of inputs | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-reduce-type-parameter` | Enforce using type parameter when calling `Array#reduce` instead of using a type assertion | low | 개별 (problem) | reduce 타입 매개변수 스타일 |
| eslint | `eslint:@typescript-eslint/prefer-regexp-exec` | Enforce `RegExp#exec` over `String#match` if no global flag is provided | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-return-this-type` | Enforce that `this` is used when only `this` type is returned | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/prefer-string-starts-ends-with` | Enforce using `String#startsWith` and `String#endsWith` over other equivalent methods of checking substrings | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/promise-function-async` | Require any function or method that returns a Promise to be marked async | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/require-await` | Disallow async functions which do not return promises and have no `await` expression | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/sort-type-constituents` | Enforce constituents of a type union/intersection to be sorted alphabetically | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/strict-boolean-expressions` | Disallow certain types in boolean expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/triple-slash-reference` | Disallow certain triple slash directives in favor of ES6-style import declarations | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/typedef` | Require type annotations in certain places | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/unified-signatures` | Disallow two overloads that could be unified into one with a union or an optional/rest parameter | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:@typescript-eslint/use-unknown-in-catch-callback-variable` | Enforce typing arguments in Promise rejection callbacks as `unknown` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:accessor-pairs` | Enforce getter and setter pairs in objects and classes | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:array-bracket-newline` | Enforce linebreaks after opening and before closing array brackets | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:array-bracket-spacing` | Enforce consistent spacing inside array brackets | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:array-element-newline` | Enforce line breaks after each array element | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:arrow-body-style` | Require braces around arrow function bodies | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:arrow-parens` | Require parentheses around arrow function arguments | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:arrow-spacing` | Enforce consistent spacing before and after the arrow in arrow functions | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:block-scoped-var` | Enforce the use of variables within the scope they are defined | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:block-spacing` | Disallow or enforce spaces inside of blocks after opening block and before closing block | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:brace-style` | Enforce consistent brace style for blocks | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:callback-return` | Require `return` statements after callbacks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:camelcase` | Enforce camelcase naming convention | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:capitalized-comments` | Enforce or disallow capitalization of the first letter of a comment | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:class-methods-use-this` | Enforce that class methods utilize `this` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:comma-dangle` | Require or disallow trailing commas | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:comma-spacing` | Enforce consistent spacing before and after commas | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:comma-style` | Enforce consistent comma style | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:computed-property-spacing` | Enforce consistent spacing inside computed property brackets | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:consistent-this` | Enforce consistent naming when capturing the current execution context | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:curly` | Enforce consistent brace style for all control statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:default-case` | Require `default` cases in `switch` statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:default-case-last` | Enforce `default` clauses in `switch` statements to be last | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:default-param-last` | Enforce default parameters to be last | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:dot-location` | Enforce consistent newlines before and after dots | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:dot-notation` | Enforce dot notation whenever possible | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:eol-last` | Require or disallow newline at the end of files | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:func-call-spacing` | Require or disallow spacing between function identifiers and their invocations | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:func-name-matching` | Require function names to match the name of the variable or property to which they are assigned | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:func-names` | Require or disallow named `function` expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:func-style` | Enforce the consistent use of either `function` declarations or expressions assigned to variables | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:function-call-argument-newline` | Enforce line breaks between arguments of a function call | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:function-paren-newline` | Enforce consistent line breaks inside function parentheses | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:generator-star-spacing` | Enforce consistent spacing around `*` operators in generator functions | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:global-require` | Require `require()` calls to be placed at top-level module scope | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:grouped-accessor-pairs` | Require grouped accessor pairs in object literals and classes | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:guard-for-in` | Require `for-in` loops to include an `if` statement | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:handle-callback-err` | Require error handling in callbacks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:id-blacklist` | Disallow specified identifiers | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:id-denylist` | Disallow specified identifiers | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:id-length` | Enforce minimum and maximum identifier lengths | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:id-match` | Require identifiers to match a specified regular expression | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:implicit-arrow-linebreak` | Enforce the location of arrow function bodies | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:indent` | Enforce consistent indentation | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:indent-legacy` | Enforce consistent indentation | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:init-declarations` | Require or disallow initialization in variable declarations | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:jsx-quotes` | Enforce the consistent use of either double or single quotes in JSX attributes | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:key-spacing` | Enforce consistent spacing between keys and values in object literal properties | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:keyword-spacing` | Enforce consistent spacing before and after keywords | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:line-comment-position` | Enforce position of line comments | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:linebreak-style` | Enforce consistent linebreak style | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:lines-around-comment` | Require empty lines around comments | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:lines-around-directive` | Require or disallow newlines around directives | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:lines-between-class-members` | Require or disallow an empty line between class members | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:logical-assignment-operators` | Require or disallow logical assignment operator shorthand | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-classes-per-file` | Enforce a maximum number of classes per file | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-depth` | Enforce a maximum depth that blocks can be nested | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-len` | Enforce a maximum line length | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:max-lines` | Enforce a maximum number of lines per file | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-lines-per-function` | Enforce a maximum number of lines of code in a function | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-nested-callbacks` | Enforce a maximum depth that callbacks can be nested | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-params` | Enforce a maximum number of parameters in function definitions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-statements` | Enforce a maximum number of statements allowed in function blocks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:max-statements-per-line` | Enforce a maximum number of statements allowed per line | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:multiline-comment-style` | Enforce a particular style for multiline comments | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:multiline-ternary` | Enforce newlines between operands of ternary expressions | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:new-cap` | Require constructor names to begin with a capital letter | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:new-parens` | Enforce or disallow parentheses when invoking a constructor with no arguments | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:newline-after-var` | Require or disallow an empty line after variable declarations | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:newline-before-return` | Require an empty line before `return` statements | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:newline-per-chained-call` | Require a newline after each call in a method chain | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-alert` | Disallow the use of `alert`, `confirm`, and `prompt` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-array-constructor` | Disallow `Array` constructors | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-bitwise` | Disallow bitwise operators | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-catch-shadow` | Disallow `catch` clause parameters from shadowing variables in the outer scope | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-confusing-arrow` | Disallow arrow functions where they could be confused with comparisons | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-console` | Disallow the use of `console` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-continue` | Disallow `continue` statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-div-regex` | Disallow equal signs explicitly at the beginning of regular expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-duplicate-imports` | Disallow duplicate module imports | low | 개별 (problem) | 같은 모듈 중복 import |
| eslint | `eslint:no-else-return` | Disallow `else` blocks after `return` statements in `if` statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-empty-function` | Disallow empty functions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-empty-static-block` | Disallow empty static blocks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-eq-null` | Disallow `null` comparisons without type-checking operators | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-extra-bind` | Disallow unnecessary calls to `.bind()` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-extra-boolean-cast` | Disallow unnecessary boolean casts | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-extra-label` | Disallow unnecessary labels | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-extra-parens` | Disallow unnecessary parentheses | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-extra-semi` | Disallow unnecessary semicolons | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-floating-decimal` | Disallow leading or trailing decimal points in numeric literals | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-implicit-coercion` | Disallow shorthand type conversions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-inline-comments` | Disallow inline comments after code | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-iterator` | Disallow the use of the `__iterator__` property | low | 개별 (suggestion) | __iterator__ 사용(비표준) |
| eslint | `eslint:no-label-var` | Disallow labels that share a name with a variable | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-labels` | Disallow labeled statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-lone-blocks` | Disallow unnecessary nested blocks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-lonely-if` | Disallow `if` statements as the only statement in `else` blocks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-magic-numbers` | Disallow magic numbers | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-mixed-operators` | Disallow mixed binary operators | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-mixed-requires` | Disallow `require` calls to be mixed with regular variable declarations | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-mixed-spaces-and-tabs` | Disallow mixed spaces and tabs for indentation | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-multi-assign` | Disallow use of chained assignment expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-multi-spaces` | Disallow multiple spaces | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-multi-str` | Disallow multiline strings | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-multiple-empty-lines` | Disallow multiple empty lines | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-native-reassign` | Disallow assignments to native objects or read-only global variables | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-negated-condition` | Disallow negated conditions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-nested-ternary` | Disallow nested ternary expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-new` | Disallow `new` operators outside of assignments or comparisons | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-new-object` | Disallow `Object` constructors | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-new-require` | Disallow `new` operators with calls to `require` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-object-constructor` | Disallow calls to the `Object` constructor without an argument | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-param-reassign` | Disallow reassigning function parameters | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-path-concat` | Disallow string concatenation with `__dirname` and `__filename` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-plusplus` | Disallow the unary operators `++` and `--` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-process-env` | Disallow the use of `process.env` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-process-exit` | Disallow the use of `process.exit()` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-regex-spaces` | Disallow multiple spaces in regular expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-restricted-exports` | Disallow specified names in exports | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-restricted-globals` | Disallow specified global variables | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-restricted-imports` | Disallow specified modules when loaded by `import` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-restricted-modules` | Disallow specified modules when loaded by `require` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-restricted-properties` | Disallow certain properties on certain objects | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-restricted-syntax` | Disallow specified syntax | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-return-await` | Disallow unnecessary `return await` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-rule-id` | (특수 키) | low | 개별 | 규칙 없이 보고된 메시지(도구 안내) |
| eslint | `eslint:no-shadow` | Disallow variable declarations from shadowing variables declared in the outer scope | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-spaced-func` | Disallow spacing between function identifiers and their applications (deprecated) | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-sync` | Disallow synchronous methods | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-tabs` | Disallow all tabs | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-ternary` | Disallow ternary operators | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-trailing-spaces` | Disallow trailing whitespace at the end of lines | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:no-undef-init` | Disallow initializing variables to `undefined` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-undefined` | Disallow the use of `undefined` as an identifier | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-underscore-dangle` | Disallow dangling underscores in identifiers | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-unneeded-ternary` | Disallow ternary operators when simpler alternatives exist | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-unused-labels` | Disallow unused labels | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-call` | Disallow unnecessary calls to `.call()` and `.apply()` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-catch` | Disallow unnecessary `catch` clauses | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-computed-key` | Disallow unnecessary computed property keys in objects and classes | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-concat` | Disallow unnecessary concatenation of literals or template literals | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-constructor` | Disallow unnecessary constructors | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-escape` | Disallow unnecessary escape characters | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-rename` | Disallow renaming import, export, and destructured assignments to the same name | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-useless-return` | Disallow redundant return statements | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-var` | Require `let` or `const` instead of `var` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-void` | Disallow `void` operators | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-warning-comments` | Disallow specified warning terms in comments | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:no-whitespace-before-property` | Disallow whitespace before properties | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:nonblock-statement-body-position` | Enforce the location of single-line statements | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:object-curly-newline` | Enforce consistent line breaks after opening and before closing braces | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:object-curly-spacing` | Enforce consistent spacing inside braces | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:object-property-newline` | Enforce placing object properties on separate lines | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:object-shorthand` | Require or disallow method and property shorthand syntax for object literals | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:one-var` | Enforce variables to be declared either together or separately in functions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:one-var-declaration-per-line` | Require or disallow newlines around variable declarations | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:operator-assignment` | Require or disallow assignment operator shorthand where possible | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:operator-linebreak` | Enforce consistent linebreak style for operators | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:padded-blocks` | Require or disallow padding within blocks | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:padding-line-between-statements` | Require or disallow padding lines between statements | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:prefer-arrow-callback` | Require using arrow functions for callbacks | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-const` | Require `const` declarations for variables that are never reassigned after declared | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-destructuring` | Require destructuring from arrays and/or objects | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-exponentiation-operator` | Disallow the use of `Math.pow` in favor of the `**` operator | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-named-capture-group` | Enforce using named capture group in regular expression | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-numeric-literals` | Disallow `parseInt()` and `Number.parseInt()` in favor of binary, octal, and hexadecimal literals | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-object-has-own` | Disallow use of `Object.prototype.hasOwnProperty.call()` and prefer use of `Object.hasOwn()` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-object-spread` | Disallow using `Object.assign` with an object literal as the first argument and prefer the use of object spread instead | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-reflect` | Require `Reflect` methods where applicable | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-regex-literals` | Disallow use of the `RegExp` constructor in favor of regular expression literals | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-rest-params` | Require rest parameters instead of `arguments` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-spread` | Require spread operators instead of `.apply()` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:prefer-template` | Require template literals instead of string concatenation | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:preserve-caught-error` | Disallow losing originally caught error when re-throwing custom errors | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:quote-props` | Require quotes around object literal property names | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:quotes` | Enforce the consistent use of either backticks, double, or single quotes | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:radix` | Enforce the use of the radix argument when using `parseInt()` | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:require-await` | Disallow async functions which have no `await` expression | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:require-unicode-regexp` | Enforce the use of `u` or `v` flag on regular expressions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:rest-spread-spacing` | Enforce spacing between rest and spread operators and their expressions | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:semi` | Require or disallow semicolons instead of ASI | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:semi-spacing` | Enforce consistent spacing before and after semicolons | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:semi-style` | Enforce location of semicolons | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:sort-imports` | Enforce sorted `import` declarations within modules | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:sort-keys` | Require object keys to be sorted | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:sort-vars` | Require variables within the same declaration block to be sorted | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:space-before-blocks` | Enforce consistent spacing before blocks | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:space-before-function-paren` | Enforce consistent spacing before `function` definition opening parenthesis | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:space-in-parens` | Enforce consistent spacing inside parentheses | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:space-infix-ops` | Require spacing around infix operators | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:space-unary-ops` | Enforce consistent spacing before or after unary operators | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:spaced-comment` | Enforce consistent spacing after the `//` or `/*` in a comment | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:strict` | Require or disallow strict mode directives | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:switch-colon-spacing` | Enforce spacing around colons of switch statements | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:symbol-description` | Require symbol descriptions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:template-curly-spacing` | Require or disallow spacing around embedded expressions of template strings | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:template-tag-spacing` | Require or disallow spacing between template tags and their literals | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:unicode-bom` | Require or disallow Unicode byte order mark (BOM) | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:unused-disable-directive` | (특수 키) | low | 개별 | 쓰지 않는 eslint-disable 주석 |
| eslint | `eslint:vars-on-top` | Require `var` declarations be placed at the top of their containing scope | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
| eslint | `eslint:wrap-iife` | Require parentheses around immediate `function` invocations | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:wrap-regex` | Require parenthesis around regex literals | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:yield-star-spacing` | Require or disallow spacing around the `*` in `yield*` expressions | low | 분류군 (layout) | meta.type layout — 공백·서식 |
| eslint | `eslint:yoda` | Require or disallow "Yoda" conditions | low | 분류군 (suggestion) | meta.type suggestion — 더 나은 작성 방법(동작에는 대개 영향 없음) |
