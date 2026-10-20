---
key: tools-bin
title: bin/ 도구 배포
order: 4
summary: ruff·ESLint·git·p4를 bin/에 버전별로 두는 구조, 새 버전 설치·삭제, sha256 매니페스트와 검증, 차단·고정, Node.js 런타임, 버전 변경이 분석에 주는 영향
keywords: bin, ruff, ESLint, Node.js, git, p4, 버전, 설치, 패키지, 스테이징, tools_ctl, SHA256SUMS, 매니페스트, 체크섬, 스모크 테스트, 고정, 차단, 재검증, 삭제, node_path
related: install, deploy-bundle, worker, linux-ops, rules-admin, repository, troubleshooting
audience: admin
---
## 분석 도구와 bin/

분석에 쓰는 실행 파일은 모두 `bin/` 아래에 도구·버전별로 둡니다. 서버는 이 폴더 밖의 실행 파일을 쓰지 않습니다(Node.js 런타임만 설정 경로로 예외).

```
bin/ruff/<버전>/windows/ruff.exe, linux/ruff, SHA256SUMS
bin/eslint/<버전>/package.json, package-lock.json, node_modules/, SHA256SUMS
bin/git/<버전>/windows/cmd/git.exe, linux/bin/git, SHA256SUMS, VERSION.txt
bin/p4/<버전>/windows/p4.exe, linux/p4, SHA256SUMS, VERSION.txt
```

- 버전 폴더 이름은 `0.16.10`처럼 숫자 세 개(semver)만 씁니다.
- `SHA256SUMS`는 `sha256sum` 출력 형식(`<해시> *<상대 경로>`)의 매니페스트입니다. 버전 폴더 안 실행에 필요한 파일을 모두 적습니다.
- 지금 분석에 쓰는 버전: ruff {{version:ruff}}, ESLint {{version:eslint}}.
- `bin/`과 매니페스트에는 웹·워커 계정의 쓰기 권한을 주지 않습니다. 설치·삭제는 `bin/` 쓰기 권한이 있는 서버 관리자 계정이 명령으로 합니다.

## 버전을 고르는 방식과 검증

서버는 분석할 때마다 도구별로 쓸 버전을 고릅니다.

1. 고정한 버전이 있으면 그 버전만 씁니다. 없으면 semver가 가장 높은 버전부터 봅니다. 차단한 버전은 건너뜁니다. ESLint는 10.x만 씁니다.
2. 처음 보는 버전이나 파일이 바뀐 버전은 매니페스트의 모든 파일을 SHA-256으로 대조한 뒤 스모크 테스트(내장 시료를 실제로 분석해 기대 결함 수와 대조)를 합니다. 둘 다 통과해야 씁니다.
3. 실패하면 그 버전을 검증 실패로 두고 직전 유효 버전을 씁니다.
4. 이미 검증한 버전은 분석마다 전체 해시를 계산하지 않고 파일 크기·수정 시각 지문만 대조합니다. 지문이 바뀌면 2를 다시 합니다.
5. 지문까지 맞춘 변조는 다음 전체 검증 때까지 잡지 못합니다. 그래서 권한을 막고, 정기 점검 때 `php tools/tools_ctl.php verify ruff`, `verify eslint`로 전체 검증을 다시 합니다.

쓸 수 있는 버전이 하나도 없거나 고정한 버전을 쓸 수 없으면 그 도구의 분석은 `tool_unavailable`로 실패합니다.

## 관리자 화면: 분석 도구 탭

{{ui:admin_system.title}} > {{ui:admin_tools.title}}([분석 도구 화면](/admin/tools))에서 도구별 버전 표를 봅니다.

- 상태: 사용 중·고정·차단·삭제 예정·삭제됨·검증 실패·검증 전·파일 바뀜·지원하지 않는 주 버전·사용되지 않음·재검증 대기/중.
- 표 위 한 줄에 "지금 분석에 쓰는 버전과 이유"(고정, 최신, 직전 유효 버전으로 대체, 고정 버전 사용 불가, 다음 분석 때 검증)가 나옵니다.
- 모든 동작은 확인 대화상자에서 사유를 적고 감사 로그에 남습니다.
- {{ui:admin_tools.action.pin}}·{{ui:admin_tools.action.unpin}}: 자동 최신 선택을 멈추고 한 버전만 쓰거나 되돌립니다(`tool.pin`·`tool.unpin`).
- {{ui:admin_tools.action.block}}·{{ui:admin_tools.action.unblock}}: 그 버전을 쓰지 않고 직전 유효 버전으로 대체하거나 되돌립니다(`tool.block`·`tool.unblock`).
- {{ui:admin_tools.action.verify}}: 전체 체크섬과 스모크 테스트를 워커에 요청합니다(`tool.reverify_request`).
- {{ui:admin_tools.action.mark_removal}}·{{ui:admin_tools.action.unmark_removal}}: 삭제 전 표시입니다(`tool.mark_removal`·`tool.unmark_removal`).
- 고정은 검증을 통과한 버전만 됩니다. 웹 요청은 검증을 실행하지 않으므로 기록이 없거나 파일이 바뀐 버전은 먼저 재검증을 요청합니다. 재검증은 워커가 하며, 그동안 같은 도구의 분석 시작이 잠시 기다릴 수 있습니다.
- 고정된 버전은 차단하거나 삭제 예정으로 표시할 수 없습니다. 차단을 풀면 다음 선택 때 다시 검증합니다.
- git·p4는 읽기 전용으로 보입니다(버전·체크섬·점검 결과). 차단·고정·설치는 서버에서 합니다.

명령줄도 같은 기능을 씁니다: `php tools/tools_ctl.php list|select|verify|pin|unpin|block|unblock|node [도구] [버전]`. 종료 코드는 0 성공, 1 실패, 2 사용법·설정 오류입니다.

## 새 버전 설치

1. 배포처에서 도구를 받아 공식 체크섬과 대조합니다. 받은 파일은 `bin/` 밖 빈 임시 폴더에 둡니다.
2. `bin/`과 같은 배치로 zip을 만듭니다. 최상위에 `<도구>/<버전>/` 폴더 하나만, 그 안에 `SHA256SUMS`와 이 OS의 실행 파일(ruff `windows/ruff.exe` 또는 `linux/ruff`, ESLint `node_modules/eslint/bin/eslint.js`)이 있어야 합니다.
3. 분석 도구 탭의 {{ui:admin_tools.staging_title}}에서 도구를 고르고 zip을 올립니다(한도 {{limit:tools.package_max_size}}). 배포처가 공개한 SHA-256을 넣으면 대조하고, 다르면 설치 명령을 보여 주지 않습니다. 웹은 패키지를 보관만 합니다.
4. 화면에 나온 명령을 서버 관리자 계정으로 실행합니다.

```
php tools/tools_ctl.php install --staging=<id>
```

- 검사 순서: 대기 상태 → 배포처 해시 → 올릴 때의 해시와 같은지 → 안전 해제(경로 조작·절대 경로·심볼릭 링크·중첩 zip 거부) → 배치·버전 이름·실행 파일 → 매니페스트 전체 체크섬 → 같은 버전 폴더가 있으면 거부 → `bin/`으로 이동 → 전체 체크섬 + 스모크 테스트.
- 실패하면 `bin/`에 아무것도 남기지 않습니다. 결과(성공 또는 실패 사유)는 화면의 패키지 항목에 나옵니다. 설치가 끝난 zip은 지웁니다.
- 설치하지 않은 패키지는 {{limit:tools.staging_retention}} 뒤 정리됩니다.
- 설치 명령은 ruff·ESLint만 다룹니다. ESLint를 npm으로 직접 구성할 때는 `npm install --save-exact --ignore-scripts`로 정확한 버전을 설치하고 매니페스트를 만듭니다.

## 옛 버전 삭제

1. 쓰지 않는 버전은 표에 "사용되지 않음"으로 보입니다. {{ui:admin_tools.action.mark_removal}}을 누르면 분석에 쓰지 않게 되고 삭제 명령이 나옵니다.
2. 서버에서 `php tools/tools_ctl.php remove <도구> <버전>`을 실행합니다.

- 고정된 버전, 실행 중인 분석이 쓰는 버전, 지우면 쓸 수 있는 버전이 남지 않는 마지막 유효 버전은 거부합니다.
- 폴더는 `bin/.trash/`로 옮긴 뒤 지웁니다. 버전 기록은 이력으로 남아 과거 분석의 도구 버전을 설명합니다.

## 버전 변경이 분석에 주는 영향

- 각 분석에는 도구 버전(ESLint는 Node 버전 포함), 설정 출처, 설정 해시가 기록됩니다.
- 직전 성공 분석과 이 값이 하나라도 다르면 히스토리의 Run 표에 "{{ui:runs.changed}}" 표시가 붙고, 대시보드 추이 점에도 변경 표식이 붙습니다. 히스토리 비교에는 "{{ui:history.warn_tool_change}}" 안내가 나옵니다.
- 규칙이 늘거나 바뀐 새 버전은 코드가 그대로여도 신규·해결 결함을 만들 수 있습니다. 운영 중 버전을 올리기 전에 사용자에게 알리고, 필요하면 고정으로 시점을 정하세요.
- 저장소 연결 프로젝트는 도구·설정이 바뀌면 소스가 같아도 "변경 없음"으로 건너뛰지 않고 분석합니다.
- 고정·차단을 바꾸면 활성 버전이 바뀔 수 있으므로, 규칙 데이터 자동 적재(`rules.auto_load`)가 켜져 있으면 규칙 설명 데이터 적재가 워커에 요청됩니다([규칙 관리](guide:rules-admin)).

## Node.js 런타임

ESLint는 `bin/` 밖에 설치한 Node.js로 실행합니다.

- `tools.node_path`에 node 실행 파일의 절대 경로를 적는 것을 권장합니다. 비우면 워커 계정의 PATH에서 찾는데, 서비스 계정의 PATH는 사용자 PATH와 다를 수 있습니다.
- 최소 버전은 `tools.node_min_version`(지금 {{limit:tools.node_min_version}})입니다. 워커가 기동할 때 검사하고, 문제가 있으면 ESLint 분석은 `tool_unavailable`로 실패합니다.
- 분석 도구 탭의 Node.js 항목에서 상태·버전·경로 출처(설정 또는 PATH)를 봅니다. 경로 자체는 화면에 보이지 않습니다.
- Node를 바꾸거나 경로를 고친 뒤 {{ui:admin_tools.node_refresh}}를 누르면 워커가 설정을 다시 읽어 확인합니다. 분석이 쓰는 Node 경로는 워커를 다시 시작해야 바뀝니다([분석 워커 운영](guide:worker)).

## git·p4 배치

저장소 연동이 쓰는 git·p4도 같은 규칙으로 `bin/git/<버전>/`, `bin/p4/<버전>/`에 둡니다.

- Windows git은 MinGit 압축을 `windows/`에 그대로 풉니다(진입점 `windows/cmd/git.exe`). p4는 공식 배포처의 실행 파일을 체크섬 또는 서명으로 확인해 둡니다.
- Linux git은 공식 바이너리가 없어 운영 서버와 같은 배포판에서 `tools/build_git_linux.sh --gpg`로 빌드합니다([Linux 운영](guide:linux-ops)).
- 같은 버전의 빌드 갱신은 폴더를 통째로 바꾸고 매니페스트와 `VERSION.txt`를 다시 만듭니다.
- 점검: `php tools/check_bin_vcs.php`(매니페스트·변조 감지·실행 파일 형식), `php tools/tools_ctl.php verify git`, `verify p4`.
- git·p4를 쓸 수 없으면 저장소 가져오기가 `repo_tool_unavailable`로 실패합니다.

## 권한

- Windows: `bin` 폴더 보안에서 웹 서버·워커 계정은 읽기·실행만, 설치 담당 관리자 계정만 수정 권한을 줍니다(`icacls`로 확인).
- Linux: 설치 담당 계정이 소유하고 웹·워커 계정은 같은 그룹으로 읽기만 합니다.
- 점검 화면은 웹 계정이 `bin/`에 쓸 수 있으면 경고합니다. Windows에서는 이 판정이 ACL을 반영하지 못할 수 있으니 `icacls bin`으로 직접 확인하세요.

## 문제 해결

- 분석이 `tool_unavailable`로 실패합니다: 분석 도구 탭의 "지금 쓰는 버전" 줄과 실패 사유(체크섬 불일치 파일, 스모크 결과)를 보고, 버전을 다시 설치하거나 고정을 해제합니다. ESLint면 Node 상태도 봅니다.
- 상태가 "파일 바뀜"입니다: 누군가 버전 폴더를 고쳤습니다. 의도한 변경이면 매니페스트를 다시 만들고 재검증을 요청합니다.
- 설치 명령이 보이지 않습니다: 배포처 해시가 다릅니다. 파일을 다시 받아 올립니다.
- 설치가 거부됩니다: 같은 버전 폴더가 이미 있거나 zip 배치가 다릅니다. 결과의 사유를 확인합니다.
