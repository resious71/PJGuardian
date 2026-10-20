# 분석 도구 설치 (ruff, ESLint)

ruff와 ESLint를 `bin/` 아래에 버전별로 둔다 (명세 12). 실행 파일과 `node_modules`는 git에 커밋하지 않는다(`.gitignore`). 체크섬 매니페스트(`SHA256SUMS`)와 `package.json`, `package-lock.json`은 커밋한다.

```
bin/
  ruff/<버전>/windows/ruff.exe
  ruff/<버전>/linux/ruff
  ruff/<버전>/SHA256SUMS
  eslint/<버전>/package.json, package-lock.json, node_modules/, SHA256SUMS
```

- 버전 디렉토리 이름은 `0.16.10`처럼 semver 숫자만 쓴다. 서버는 semver로 가장 높은 버전을 고른다 (명세 12).
- 운영 서버에서는 `bin/`과 매니페스트에 웹/애플리케이션 계정의 쓰기 권한을 주지 않는다 (명세 12, `docs/xampp_vhost.md` 2절).
- 설치 후 `php tools/check_env.php`로 확인한다.
- git·p4(소스 저장소 연동용)는 같은 규칙으로 `bin/git/<버전>/`, `bin/p4/<버전>/`에 둔다 — 절차·출처·검증은 `docs/vcs_tools.md`(X09).
- 체크섬 매니페스트는 `sha256sum` 출력 형식(`<해시> *<상대 경로>`, 공백 둘도 허용)이다. 검증 방법은 5절(P15에서 확정).

현재 개발 PC(Windows)의 설치 상태(2026-10-02): ruff 0.16.10, Node.js v24.21.0(포터블, `C:\tools\nodejs`), ESLint 8.57.1과 9.39.5(P03 비교용) 설치됨.

## 1. ruff

릴리스 실행 파일을 GitHub(`https://github.com/astral-sh/ruff/releases`)에서 내려받는다. 아래는 0.16.10 기준이며 다른 버전은 `V`만 바꾼다.

| OS | 릴리스 파일 |
| --- | --- |
| Windows x64 | `ruff-x86_64-pc-windows-msvc.zip` |
| Linux x64 | `ruff-x86_64-unknown-linux-gnu.tar.gz` |

각 파일에는 `.sha256` 파일이 함께 있으므로 **압축을 풀기 전에 해시를 확인한다.**

### 1.1 Windows (PowerShell)

```powershell
$V = "0.16.10"
$B = "https://github.com/astral-sh/ruff/releases/download/$V"
$T = New-Item -ItemType Directory -Force "$env:TEMP\ruff-$V"
curl.exe -sSL -o "$T\ruff.zip" "$B/ruff-x86_64-pc-windows-msvc.zip"
curl.exe -sSL -o "$T\ruff.zip.sha256" "$B/ruff-x86_64-pc-windows-msvc.zip.sha256"

# 해시 비교: 두 값이 같아야 한다
(Get-FileHash "$T\ruff.zip" -Algorithm SHA256).Hash.ToLower()
(Get-Content "$T\ruff.zip.sha256").Split(" ")[0]

New-Item -ItemType Directory -Force "bin\ruff\$V\windows"
Expand-Archive "$T\ruff.zip" -DestinationPath "bin\ruff\$V\windows" -Force
bin\ruff\$V\windows\ruff.exe --version
```

### 1.2 Linux

```bash
V=0.16.10
B=https://github.com/astral-sh/ruff/releases/download/$V
T=$(mktemp -d)
curl -sSL -o $T/ruff.tar.gz        $B/ruff-x86_64-unknown-linux-gnu.tar.gz
curl -sSL -o $T/ruff.tar.gz.sha256 $B/ruff-x86_64-unknown-linux-gnu.tar.gz.sha256
(cd $T && echo "$(cut -d' ' -f1 ruff.tar.gz.sha256)  ruff.tar.gz" | sha256sum -c -)

mkdir -p bin/ruff/$V/linux
tar xzf $T/ruff.tar.gz -C $T
cp $T/ruff-x86_64-unknown-linux-gnu/ruff bin/ruff/$V/linux/
chmod 755 bin/ruff/$V/linux/ruff
bin/ruff/$V/linux/ruff --version
```

### 1.3 매니페스트

두 OS 실행 파일을 모두 넣은 뒤 버전 디렉토리에서 만든다(Linux 또는 Git Bash).

```bash
cd bin/ruff/$V && sha256sum -b windows/ruff.exe linux/ruff > SHA256SUMS
```

## 2. Node.js (ESLint 실행 런타임)

ESLint는 서버에 설치된 Node.js로 실행한다 (명세 2, 12). **현재 활성 LTS인 Node.js 24**를 권장한다(2026-10 기준 v24.21.0 "Krypton"). 아래 ESLint 후보는 모두 Node 24에서 동작한다(엔진 요구: ESLint 8.57.1은 `>=16`, 9.x와 typescript-eslint 8.x는 `^18.18 || ^20.9 || >=21.1`).

| OS | 설치 방법 |
| --- | --- |
| Windows (설치 프로그램) | `https://nodejs.org/` 에서 Windows x64 LTS `.msi`를 받아 설치한다("Add to PATH" 선택). 설치 후 새 터미널에서 `node --version`. |
| Windows (포터블, 관리자 권한 불필요) | `https://nodejs.org/dist/v24.21.0/node-v24.21.0-win-x64.zip`과 같은 폴더의 `SHASUMS256.txt`를 받아 해시를 비교한 뒤, `C:\tools\nodejs`에 풀고 그 폴더를 사용자 PATH에 추가한다. 개발 PC는 이 방식으로 설치했다. 서비스 계정으로 Apache·워커를 돌리면 사용자 PATH가 적용되지 않으므로 운영에서는 설치 프로그램이나 시스템 PATH를 쓴다. |
| Linux | 배포판 패키지 또는 `https://nodejs.org/dist/latest-v24.x/`의 `node-v24.*-linux-x64.tar.xz`를 `/opt/nodejs`에 풀고 `/usr/local/bin`에 `node`, `npm` 링크를 만든다. |

설치 후 웹 서버(Apache)와 워커를 실행하는 계정의 PATH에서도 `node`가 보이는지 확인한다.

## 3. ESLint

`bin/eslint/<버전>/`에 npm으로 ESLint와 TypeScript 지원 패키지를 설치해 `node_modules`를 구성한다.

### 3.1 버전 선택 — **확정: ESLint 10.x + 서버가 만든 flat 설정**(명세 19.10, P03·P15)

> 서버는 ESLint **10.x만** 쓴다(레지스트리가 다른 주 버전을 검증하지 않고 건너뛴다). 서버 기본 설정 변경 이력: 2026-10-05 X07 — 테스트 파일(`**/*.test.*`, `**/*.spec.*`, `**/test/**`, `**/tests/**`, `**/__tests__/**`, `**/spec/**`)에만 jasmine·mocha·jest 환경과 `vi`·`vitest` 전역(`config/eslint.default.json`의 overrides, 설정 해시가 바뀜). 업로드된 ESLint 설정은 통째로 무시하고 서버 설정(`tools/eslint/pjg.flat.config.cjs` + `config/eslint.default.json`)으로 분석한다. 아래 8.57.1·9.39.5 비교는 P03 기록으로 남긴다. 개발 PC의 `bin/eslint/8.57.1`, `bin/eslint/9.39.5`는 더 이상 쓰지 않으므로 지워도 된다(남겨 두어도 선택되지 않는다).
> 10.x 설치: `npm install --save-exact --ignore-scripts eslint@10.11.0 @eslint/js@10.0.1 @eslint/eslintrc@3.3.7 @typescript-eslint/parser@8.71.0 @typescript-eslint/eslint-plugin@8.71.0 typescript@6.0.3`(현재 `bin/eslint/10.11.0/package.json`).

#### P03 비교 기록

명세 5.3은 업로드된 정적 `.eslintrc` 설정(JSON/YAML)을 우선 사용한다. ESLint 버전마다 이 설정을 다루는 방식이 다르므로, 아래 두 후보를 **모두 설치해 P03에서 비교한 뒤** 하나로 확정한다.

| 후보 | ESLint | 설정 방식 | 비고 |
| --- | --- | --- | --- |
| A | 8.57.1 | `.eslintrc.*`(eslintrc) 기본 지원 | 8.x 마지막 버전, 공식 지원 종료(EOL) |
| B | 9.39.5 | 기본은 flat config(`eslint.config.js`). eslintrc는 `ESLINT_USE_FLAT_CONFIG=false`로만 사용(사용 중단 예정 경고) | 9.x 최신. **npm 설치 시 "This version is no longer supported" 경고가 나온다(2026-10-02 확인)** |

> 두 후보 모두 공식 지원이 끝난 버전이다. 보안 패치 분기 점검(명세 19.5)과 충돌하므로, P03에서 eslintrc 유지(A/B)와 "업로드된 정적 설정을 서버가 읽어 flat 설정으로 변환"하는 방식(10.x 사용 가능)을 함께 비교해 결정한다.

- 최신 ESLint는 10.11.0이다. 10.x는 eslintrc 방식 지원이 제거된 것으로 알려져 있어 후보에서 뺐다. P03에서 B안(서버가 정적 flat 설정을 생성)이 채택되면 10.x도 다시 검토할 수 있다.
- TypeScript 지원 패키지는 두 후보에 같은 버전을 써서 비교 조건을 맞춘다.
  - `@typescript-eslint/parser`와 `@typescript-eslint/eslint-plugin` 8.71.0: ESLint `^8.57.0 || ^9.0.0 || ^10.0.0`을 지원한다.
  - `typescript` 6.0.3: typescript-eslint 8.71.0이 요구하는 범위가 `>=4.8.4 <6.1.0`이다. 최신 TypeScript 7.x는 이 범위를 벗어나므로 쓰지 않는다.
- 버전 정보는 npm 레지스트리에서 2026-10-02에 확인했다. 설치할 때 다시 확인한다.

### 3.2 설치 (Windows, Linux 공통)

각 버전 디렉토리에서 실행한다. `--ignore-scripts`로 패키지 설치 스크립트를 실행하지 않고, `--save-exact`로 버전을 고정한다. 외부 네트워크가 막힌 운영 서버라면 개발 PC에서 설치한 디렉토리를 통째로 복사한다(같은 OS끼리. ESLint 패키지는 순수 JS라 OS 간 복사도 가능하지만 복사 후 `--version`으로 확인한다).

후보 A (8.57.1):

```
mkdir bin/eslint/8.57.1
cd bin/eslint/8.57.1
npm init -y
npm install --save-exact --ignore-scripts eslint@8.57.1 @typescript-eslint/parser@8.71.0 @typescript-eslint/eslint-plugin@8.71.0 typescript@6.0.3
node node_modules/eslint/bin/eslint.js --version
```

후보 B (9.39.5):

```
mkdir bin/eslint/9.39.5
cd bin/eslint/9.39.5
npm init -y
npm install --save-exact --ignore-scripts eslint@9.39.5 @typescript-eslint/parser@8.71.0 @typescript-eslint/eslint-plugin@8.71.0 typescript@6.0.3
node node_modules/eslint/bin/eslint.js --version
```

- 실행은 항상 `node bin/eslint/<버전>/node_modules/eslint/bin/eslint.js ...` 형태로 한다(전역 설치나 `npx`를 쓰지 않는다).
- `npm init -y`는 디렉토리 이름(예: `8.57.1`)을 패키지 `name`으로 쓴다. 이 `package.json`은 의존성 고정용이며 배포하지 않으므로 그대로 둔다.
- 9.x에서 eslintrc를 쓸 때 필요한 `@eslint/eslintrc` 같은 추가 패키지는 P03 검증 중에 필요하면 추가하고 `package.json`에 남긴다.

### 3.3 매니페스트

```bash
# Linux 또는 Git Bash, 버전 디렉토리에서
find node_modules -type f -print0 | sort -z | xargs -0 sha256sum -b > SHA256SUMS
sha256sum -b package.json package-lock.json >> SHA256SUMS
```

## 4. 확인

```
php tools/check_env.php
```

- `ruff <버전> (<OS>)`와 `eslint <버전>` 항목이 `OK`여야 한다.
- 도구가 없으면 `WARN`으로, Node.js가 없으면 `FAIL`로 표시된다.

## 5. 검증과 선택 (P15, 명세 12, 19.10)

서버는 분석할 때마다 `bin/`에서 쓸 버전을 고른다(`Services\Tools\ToolRegistry`, 확인: `php tools/tools_ctl.php list`).

1. **선택**: 고정한 버전이 있으면 그것만, 없으면 semver가 가장 높은 버전부터. 차단한 버전은 건너뛴다. ESLint는 10.x만.
2. **처음 보는 버전**(또는 파일이 바뀐 버전): 매니페스트의 **모든 파일**을 SHA-256으로 대조한 뒤 **스모크 테스트**(`samples/` 시료를 실제로 분석해 기대 결함 수와 대조: ruff `python_sample` 19건·`python_syntax_error` 2건, ESLint `js_sample_noconfig` 5건·`ts_sample` 6건·문법 오류 JS 1건)를 한다. 둘 다 통과해야 쓴다. 실패하면 그 버전을 `invalid`로 두고 **직전 유효 버전**을 쓰며 WARN 로그를 남긴다.
3. **이미 검증한 버전**: 매번 전체 해시를 계산하지 않고 매니페스트에 적힌 파일들의 **크기·수정 시각 지문**만 대조한다. 지문이 바뀌면 2를 다시 한다.
   - 이유: ESLint의 `node_modules`는 파일이 수천 개라 전체 해시에 이 PC에서 버전당 약 20초가 걸렸다(백신 검사 포함, P15 측정). 분석마다 하면 대기열이 밀린다.
   - 절충: 내용만 바꾸고 크기·수정 시각까지 똑같이 맞춘 변조는 다음 전체 검증(`tools_ctl.php verify`) 전까지 잡지 못한다. 그래서 **`bin/`과 매니페스트에는 웹·앱·워커 계정의 쓰기 권한을 주지 않는다**(설치 계정만 쓰기). 정기 점검 때 `php tools/tools_ctl.php verify ruff`, `verify eslint`로 전체 검증을 다시 한다.
4. **관리**: `tools_ctl.php pin <도구> <버전>`(고정, 검증 통과 버전만), `unpin <도구>`, `block <도구> <버전>`(차단), `unblock`(다음 선택 때 다시 검증). 관리자 화면 `/admin/tools`(P20A)도 같은 함수를 쓴다(웹 고정은 검증을 실행하지 않으므로 이미 검증된 버전만, 재검증은 워커에 요청).
5. **Node 런타임**: 설정 `tools.node_path`(비우면 PATH의 `node`), 최소 버전 `tools.node_min_version`. 워커가 기동할 때 검사하고 문제가 있으면 경고를 남긴다(ESLint 분석은 `tool_unavailable`로 실패). 운영에서는 `tools.node_path`를 **절대 경로로 지정**하는 것을 권장한다(서비스 계정의 PATH가 다를 수 있다). 분석 기록에는 `10.11.0 (Node 24.21.0)`처럼 Node 버전을 함께 남긴다.
   - **상태 갱신(P21C)**: Node를 설치·교체하거나 `tools.node_path`를 바꾼 뒤 관리자 화면(시스템 관리 → 분석 도구 → Node.js의 "상태 갱신")을 누르면 **워커를 다시 시작하지 않고** 워커가 설정 파일을 다시 읽어 확인한다(워커의 실행 계정·PATH 기준, 최대 10초). 워커가 멈춰 있으면 "확인할 수 없음"과 마지막 확인 시각이 나온다. 분석이 쓰는 Node 경로 자체는 워커를 다시 시작해야 바뀐다(상태 갱신은 확인만 한다).

### 5.0 서버 기본 설정 파일과 관리자 화면의 규칙 켜기·끄기 (P22B)

- `config/ruff.default.toml`, `config/eslint.default.json`은 **기본값**이다. 관리자가 규칙 설명 화면에서 켜고 끈 것은 DB에 **차이**로만 저장되고, 분석할 때 "기본 설정 + 차이"로 실효 설정을 만든다(파일은 바꾸지 않는다). 변경 목록·되돌리기는 시스템 관리 → 규칙 설정.
- 파일을 직접 고치는 것도 그대로 된다. 그러면 기본값이 바뀌므로(다음 규칙 데이터 적재에서 "기본 활성"도 다시 계산) 같은 규칙을 화면에서도 바꿨다면 화면의 변경이 그 위에 얹힌다. 기본값과 같아진 변경은 관리 화면에 그대로 남으니 필요 없으면 "기본값으로 되돌리기"로 지운다.
- 적용 범위: ESLint는 모든 프로젝트(업로드한 ESLint 설정은 원래 쓰지 않음), ruff는 **서버 기본 설정을 쓰는 프로젝트만**(프로젝트가 `.ruff.toml`·`ruff.toml`·`pyproject.toml`의 ruff 설정을 올렸으면 그 설정을 존중하고 화면의 변경을 적용하지 않는다).
- 설정 해시: 변경이 있을 때만 해시에 변경 집합이 더해진다(변경이 없으면 P15와 같은 값 — 기존 Run과 "설정 변경"이 생기지 않음). ESLint는 변경이 있을 때만 `tools/eslint/pjg.flat.overrides.config.cjs`(기본 설정 스크립트를 읽고 맨 뒤에 규칙 변경을 얹음)를 `-c`로 넘긴다.

### 5.1 실행 격리 — 코드로 보장하는 것과 권장 사항

| 항목 | 코드로 보장 | 운영 권장(코드로 보장 못 함) |
| --- | --- | --- |
| 실행 파일 | `bin/` 아래와 설정한 Node만 실행(허용 목록), 셸을 거치지 않음 | — |
| 작업 범위 | 실행 디렉토리 = 분석 작업 디렉토리. 결과는 작업 디렉토리 밖의 경로를 버림 | 워커를 **비권한 계정**으로 실행해 다른 폴더를 읽지 못하게 |
| 환경 변수 | 최소 환경(HOME·TEMP는 실행마다 빈 임시 폴더, 사용자 설정·`NODE_OPTIONS` 등을 넘기지 않음) | — |
| 업로드 설정 | ESLint 설정은 읽지도 실행하지도 않음, ruff extend는 프로젝트 안만 | — |
| 네트워크 | — | 도구는 네트워크가 필요 없다. 워커 계정의 외부 통신을 방화벽으로 막는 것을 권장 |
| 자원 | 시간·출력 한도(두 OS), 메모리 한도(Linux ruff `prlimit`, Node 힙) | Windows 메모리 제한 없음 → 동시 분석 수를 낮추거나 Linux(`docs/worker_setup.md` 5절) |

### 5.1a 권한 구성(P20A): 웹 계정은 `bin/`에 쓰지 않는다

| 계정 | `bin/` | `data/work/tool_staging/` | 하는 일 |
| --- | --- | --- | --- |
| 웹 서버 계정(Apache) | **읽기만**(쓰기 권한 없음) | 읽기·쓰기 | 상태 표시, 차단·고정(DB), 재검증 요청, 패키지 보관, 삭제 예정 표시 |
| 워커 계정 | 읽기만 | 읽기 | 분석, 재검증(관리 작업) |
| **서버 관리자 계정**(설치 담당) | 읽기·쓰기 | 읽기·쓰기 | `tools_ctl.php install`·`remove` |

- Windows: `bin` 폴더 속성 → 보안에서 Apache 서비스 계정(기본 `LocalSystem`이면 전용 계정으로 바꾸기를 권장)과 워커 계정의 "쓰기·수정"을 빼고, 설치 담당 관리자 계정만 "수정"을 준다. `icacls bin /inheritance:r /grant:r "Administrators:(OI)(CI)F" "<웹 계정>:(OI)(CI)RX" "<워커 계정>:(OI)(CI)RX"`.
- Linux: `chown -R pjgadmin:pjguardian bin && chmod -R u=rwX,g=rX,o= bin` (웹 `www-data`·워커 계정은 `pjguardian` 그룹, 쓰기 없음).
- 관리자 화면 "점검"의 환경 항목이 **웹 계정이 `bin/`에 쓸 수 있으면 경고**를 보여 준다(Windows의 `is_writable`은 읽기 전용 속성만 보아 ACL을 반영하지 못할 수 있다 — 미확인, 실제 ACL은 `icacls bin`으로 확인).

## 6. 설치·삭제 절차(P20A: 관리자 화면 + 서버 CLI)

1. 배포처에서 도구를 받아 `bin/`과 같은 배치로 zip을 만든다: 최상위에 `<도구>/<버전>/` 폴더 **하나만**, 그 안에 `SHA256SUMS`와 이 OS의 실행 파일(ruff `windows/ruff.exe`·`linux/ruff`, ESLint `node_modules/eslint/bin/eslint.js`). 버전 폴더 이름은 semver.
2. 관리자 화면 **시스템 관리 → 분석 도구 → 새 버전 설치 패키지 올리기**: 도구를 고르고 zip을 올린다(한도 `tools.package_max_size`, 기본 1GB). 화면이 SHA-256을 보여 준다. 배포처가 해시를 공개했으면 입력해 대조한다(다르면 설치 명령을 보여 주지 않고 CLI도 거부). 웹은 zip을 `data/work/tool_staging/<id>/`에 보관만 한다.
3. 서버에서 **관리자 계정**으로 화면에 나온 명령을 실행한다: `php tools/tools_ctl.php install --staging=<id>`.
   - 검사 순서: 상태 staged → 배포처 해시 → 올릴 때의 SHA-256과 같은지 → SafeUnzip(경로 조작·절대 경로·심볼릭 링크·중첩 zip 거부, 크기 한도) → 레이아웃·semver·실행 파일 → **옮기기 전 매니페스트 전체 체크섬** → 같은 버전 폴더가 있으면 거부(덮어쓰지 않음) → `bin/`으로 이동 → 전체 체크섬 + 스모크 → active.
   - 실패하면 `bin/`에 아무것도 남기지 않는다(스모크 실패면 옮긴 폴더를 다시 지우고 기록도 지움). 결과(성공·실패 코드와 사유)는 화면의 스테이징 항목에 나온다. 설치가 끝난 zip은 지운다.
   - 종료 코드: 0 설치, 1 거부·실패(`bad_layout`, `bad_version`, `checksum_failed`, `version_exists`, `unzip_<사유>`, `verify_failed`, `hash_mismatch`, `package_changed`, `staging_not_staged` …), 2 사용법.
4. 구버전 정리: 화면에서 선택되지 않는 버전은 "사용되지 않음"으로 보인다. **삭제 예정 표시**를 하면 분석에 쓰지 않게 되고 화면에 명령이 나온다. 서버에서 `php tools/tools_ctl.php remove <도구> <버전>`.
   - 거부: 고정된 버전(`pinned`), 실행 중인 같은 도구의 분석이 그 버전을 쓰는 중(`in_use`: 기록된 버전, 또는 아직 기록 전이면 지금 선택될 버전), 지우면 쓸 수 있는 버전이 남지 않음(`last_valid`).
   - 폴더는 `bin/.trash/`로 이름을 바꾼 뒤(즉시 선택 대상에서 빠짐) 자식 프로세스(`tools_ctl.php purge-trash`)가 지운다. `tool_versions` 행은 `removed_at`으로 남는다(분석 기록의 도구 버전 문자열을 설명하기 위해). 같은 버전을 다시 설치하면 이력 행을 지우고 새로 검증한다.

