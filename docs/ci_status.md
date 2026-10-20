# CI 상태 (GitHub Actions)

**상태: Linux 첫 실행 전**(2026-10-20, P33). 워크플로 `.github/workflows/ci.yml`은 작성 PC(Windows)에서 구문만 확인했고(`tests/js/ci_workflow.test.mjs`, 엄격한 YAML 파서), GitHub에서 실제로 돌려 본 적이 없다. PJGuardian은 지금까지 Windows에서만 시험했다(`docs/unverified.md`). 그래서 첫 실행은 실패할 수 있다.

README에는 상태 배지를 달지 않는다. 첫 실행 결과가 나오면 이 문서의 "실행 기록"에 적는다.

## 1. CI가 하는 일

| 순서 | 단계 | 명령 |
| --- | --- | --- |
| 1 | 체크아웃·PHP(8.2, 8.3 매트릭스, 확장 pdo_sqlite·zip·mbstring·openssl·fileinfo·intl·dom)·Node 24 준비 | `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/setup-node@v4` |
| 2 | Composer 의존성(캐시) | `composer install --no-interaction --no-progress --prefer-dist` |
| 3 | 시험 전용 Node 의존성(엄격한 YAML 파서) | `npm ci --prefix tests/tools` |
| 4 | 환경 점검(참고용 — 실패해도 계속) | `php tools/check_env.php` |
| 5 | 문서 링크 검사 | `php tools/check_doc_links.php` |
| 6 | 비밀·개인 정보 스캔(저장소 추적 파일) | `php tools/scan_release.php --git=.` |
| 7 | JS 시험 | `node --test "tests/js/*.test.mjs"` |
| 8 | PHP 제품 시험(bin/ 도구 없이) | `php tests/run.php --exclude-needs-bin` |

- 실행 조건: 모든 push와 pull request. 권한은 `contents: read`만.
- 액션은 주 버전 태그(`@v4`, `@v2`)로 고정했다. 작성할 때 인터넷을 쓰지 않아 커밋 SHA로 고정하지 못했다 — 공개 뒤 SHA 고정(또는 Dependabot의 github-actions 갱신)을 검토한다.
- 브라우저 점검(`tools/check_*_ui.mjs`), 1단계 E2E(`tests/e2e/phase1.php`), 인수 시험(`tools/acceptance/run.mjs`)은 실제 도구·헤드리스 Chrome이 필요해 CI에 넣지 않았다.

## 2. bin/ 도구가 필요한 시험 (`@needs-bin`)

저장소의 `bin/`에는 체크섬 매니페스트(`SHA256SUMS`, `package.json` 등)만 있고 ruff·ESLint(node_modules)·git·p4 실행 파일은 없다. 그런 시험은 클래스나 메서드 docblock에 `@needs-bin <이유>`로 표시하고, `php tests/run.php --exclude-needs-bin`일 때만 건너뛴다.

- 건너뛴 시험은 `  건너뜀(needs-bin) 클래스::메서드`로 이름이 나오고, 요약 줄에 `결과: 통과 N, 실패 M, 건너뜀(needs-bin) K (…)`로 개수가 붙는다.
- 옵션이 없으면 표시는 아무 영향이 없다(개발 PC의 `php tests/run.php`·`tools/run_all_tests.php`는 전과 같이 모두 실행).
- 표시한 시험: `ToolRunnersTest`(실제 도구 7개), `RuleOverrideToolsTest`(클래스 전체), `SeverityMapTest::testCoverageToolAgainstInstalledTools`, `RuleLoadTest::testRealToolsExtractAndLoadIdempotently`, `RepoPerforceTest::testRealP4BinaryVersionAndRefusedConnection`, `RepoWorkerTest`(2개), `RepoUnchangedFlowTest::testGitSameHeadSkipsThenNewCommitForceRulesSubpathAndFailure`, `ScheduleWorkerTest::testScheduledRunRetryFailureAndRecovery`.
- 새 시험이 실제 `bin/` 도구를 실행하면 같은 표시를 붙인다(표시가 없으면 CI에서 실패로 드러난다).

## 3. 첫 실행 방침 (`continue-on-error`)

- Linux 작업에는 처음에 `continue-on-error: true`를 두었다. 실패해도 워크플로 전체가 빨간색이 되지 않는다(작업 화면에서는 실패가 그대로 보인다).
- **사용자가 두 매트릭스(PHP 8.2·8.3)의 통과를 확인하면 `.github/workflows/ci.yml`의 `continue-on-error: true` 줄을 지우고**, `tests/js/ci_workflow.test.mjs`의 해당 단언도 함께 고친다(의도된 단언 변경). 이 문서의 상태도 "통과"로 바꾼다.
- 실패하면 원인을 Linux 전용 문제(경로·권한·프로세스·대소문자 구분)와 시험 환경 문제(도구 없음)로 나눠 고친다. 시험을 약화해 통과시키지 않는다.

## 4. 같은 것을 로컬에서

```
composer install
npm ci --prefix tests/tools
php tools/check_doc_links.php
php tools/scan_release.php --git=.
node --test "tests/js/*.test.mjs"
php tests/run.php --exclude-needs-bin
```

개발 PC(Windows)에서 `bin/` 도구까지 모두 시험하려면 옵션 없이 `php tests/run.php` 또는 `php tools/run_all_tests.php`.

## 5. 실행 기록

| 날짜 | 커밋 | PHP 8.2 | PHP 8.3 | 비고 |
| --- | --- | --- | --- | --- |
| (아직 없음) | | | | Linux 첫 실행 전 |
