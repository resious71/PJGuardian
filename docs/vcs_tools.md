# git·p4 준비 절차 (X09, 명세 12·18.4)

소스 저장소 연동(P25 Git, P26 Perforce)이 쓰는 `git`·`p4` 실행 파일을 `bin/`에 두는 방법. 배치·검증 규칙은 ruff·ESLint와 같다(`docs/tool_setup.md`, P15).

## 1. 배치 구조

```
bin/
  git/2.56.0/
    SHA256SUMS            windows/ 아래 모든 파일(373개) + (빌드했으면) linux/ 아래 모든 파일
    VERSION.txt           실제 빌드·출처·검증 기록(커밋)
    windows/              MinGit 2.56.0.2 64-bit 압축을 그대로 푼 것 — 진입점 windows/cmd/git.exe
    linux/                (운영 Linux에서 tools/build_git_linux.sh로 빌드) — 진입점 linux/bin/git
  p4/2026.1.0/
    SHA256SUMS            windows/p4.exe, linux/p4
    VERSION.txt
    windows/p4.exe        P4/NTX64/2026.1/3062361
    linux/p4              P4/LINUX26X86_64/2026.1/3062361
```

- 버전 폴더 이름은 `ToolRegistry::validVersion`(x.y.z, 각 1~5·1~5·1~6자리)을 따른다. 실제 빌드 번호(MinGit `2.56.0.2`, p4 패치 `3062361`)는 `VERSION.txt`에 적는다.
  같은 Git 버전의 Windows 빌드 갱신(예: 2.56.0.3)이나 같은 p4 릴리스의 패치는 **그 폴더를 통째로 교체**하고 매니페스트를 다시 만든다.
- 커밋하는 것: 버전 폴더의 `SHA256SUMS`·`VERSION.txt`뿐이다. OS 폴더(`windows/`·`linux/`) 아래는 라이선스 텍스트까지 모두 `.gitignore`로 제외한다(받은 묶음 그대로 두기 위해).
- **진입점(P25에서 반영)**: 지금 `ToolRegistry::executableRel('git')`은 `{os}/git{.exe}`를 가리킨다. MinGit은 `windows/cmd/git.exe`(자기 위치 기준으로 `ucrt64/`를 찾는 런처), 빌드한 Linux git은 `linux/bin/git`이므로 P25가 git 진입점을 이 경로로 바꾼다. p4는 `{os}/p4{.exe}` 그대로 맞다.

## 2. 출처와 검증(2026-10-07)

| 도구 | OS | 버전 | 출처(공식) | 검증 |
| --- | --- | --- | --- | --- |
| git(MinGit) | Windows x64 | 2.56.0.windows.2 | https://github.com/git-for-windows/git/releases/tag/v2.56.0.windows.2 — `MinGit-2.56.0.2-64-bit.zip` | 릴리스 노트 SHA-256 표 + GitHub 자산 digest 일치 |
| p4 | Windows x64 | 2026.1/3062361 | https://cdist2.perforce.com/perforce/r26.1/bin.ntx64/p4.exe | 같은 폴더 공식 `SHA256SUMS` 일치(서명 파일 없음) |
| p4 | Linux x86_64 | 2026.1/3062361 | https://cdist2.perforce.com/perforce/r26.1/bin.linux26x86_64/p4 | 공식 `SHA256SUMS`는 **불일치**(목록이 2026-08-13, 파일이 09-08 패치로 바뀜) → 공식 서명 `p4.asc` GPG **Good signature**(Perforce Software Package Signing, 주 키 지문 `E581 31C0 AEA7 B082 C6DC 4C93 7123 CB76 0FF1 8869`, 키 https://package.perforce.com/perforce.pubkey) |
| git | Linux x86_64 | 2.56.0(소스) | https://www.kernel.org/pub/software/scm/git/git-2.56.0.tar.xz | 스크립트가 sha256(공식 `sha256sums.asc`의 값) 대조, `--gpg`면 목록 서명도 확인 |

- p4 릴리스: r26.2 이후 폴더에는 p4 명령줄 클라이언트가 없다(P4V·P4 One 등) → p4가 있는 최신 릴리스 r26.1을 썼다. 새 릴리스(r27.1 등)가 나오면 그 폴더의 `bin.ntx64/p4.exe`·`bin.linux26x86_64/p4`를 같은 방법으로 검증한다.
- **받은 파일은 신뢰할 수 없는 입력**: `bin/` 밖 빈 임시 폴더에 받고 → 공식 체크섬(또는 서명)과 대조 → 압축은 항목별 검사(`..`·절대 경로·역슬래시·심볼릭 링크 거부, 실제로 쓴 바이트 확인)로 풀고 → 그 뒤에 `bin/`으로 옮긴다. 시스템 PATH의 `git`을 복사하지 않는다.

## 3. 새 버전으로 교체

1. 위 표의 공식 주소에서 새 파일과 체크섬(또는 서명)을 받아 대조한다.
2. `bin/<도구>/<새 버전>/<os>/`에 둔다(Windows MinGit은 zip을 그대로 푼다).
3. 매니페스트: 버전 폴더에서 `find windows linux -type f | LC_ALL=C sort | xargs sha256sum | sed 's/  / */' > SHA256SUMS` (Git Bash·Linux). `VERSION.txt`를 고친다.
4. `php tools/check_bin_vcs.php --git=<버전> --p4=<버전>` — 매니페스트(앱의 `ToolManifest::verify`), 변조 감지(임시 사본), ELF 형식, (Windows) 격리 실행.
5. 옛 버전 폴더는 연동 기능(P25·P26)의 고정·차단 규칙을 확인한 뒤 지운다.

## 4. 운영 Linux에서 git 준비

공식 Linux 바이너리가 없어 **운영 서버와 같은 배포판**(glibc·libcurl·OpenSSL이 같아야 한다)에서 빌드한다. 개발 PC에는 WSL·Docker가 없어 X09에서는 빌드하지 않았다(미배치).

```bash
# bin/에 쓸 수 있는 서버 관리자 계정으로(웹·앱 계정 아님)
sudo apt-get install build-essential libcurl4-openssl-dev libssl-dev zlib1g-dev libexpat1-dev pkg-config   # 또는 dnf: gcc make libcurl-devel openssl-devel zlib-devel expat-devel
tools/build_git_linux.sh --gpg            # 기본 2.56.0, 공식 sha256 상수 대조, RUNTIME_PREFIX 빌드, 임시 저장소 시험, ldd 출력
php tools/check_bin_vcs.php               # linux/ 줄이 들어간 매니페스트와 ELF 확인
chmod -R a-w bin/git/2.56.0/linux         # 웹·앱 계정 쓰기 금지(명세 12)
```

- 시스템에 설치하지 않는다(`make install`은 임시 DESTDIR로만). 빌드 옵션: `NO_GETTEXT NO_TCLTK NO_PERL NO_PYTHON RUNTIME_PREFIX`(옮겨도 자기 위치 기준), HTTPS 가져오기용 `git-remote-https`는 libcurl·OpenSSL에 동적으로 연결된다 — 스크립트가 `ldd` 결과와 glibc 버전을 출력하고 `VERSION.txt`에 남긴다.
- 다른 배포판 서버로 옮길 때는 그 서버에서 다시 빌드한다.

## 5. 실행할 때(P25·P26이 지킬 것)

- 항상 **절대 경로**로, 셸 없이(인자 배열), 자격 증명은 환경 변수로(명세 18.4, CLAUDE.md 코딩 규칙).
- git: `GIT_CONFIG_NOSYSTEM=1`(MinGit 번들 `etc/gitconfig`가 `core.autocrlf=true`·`credential.helper=manager`를 켜고 **설치된 Git for Windows의 `C:/Program Files/Git/etc/gitconfig`를 include**한다 — X09 점검에서 확인), 임시 `HOME`·`GIT_CONFIG_GLOBAL`, `GIT_TERMINAL_PROMPT=0`, `PATH`는 최소.
- p4: `P4CONFIG` 비움, `P4ENVIRO`·`P4TICKETS`·`P4TRUST`를 작업 폴더 안 파일로(사용자 홈의 설정·티켓을 쓰지 않게).

## 6. 라이선스 — 확인 필요

- Git: GPL-2.0. MinGit 묶음의 라이선스 파일을 그대로 두었고 `THIRD_PARTY_NOTICES`에 소스 입수처를 적었다.
- **Perforce p4: 제품 패키지에 넣어 재배포해도 되는지 확인 필요**(Perforce 사용 약관). 이 문서는 법적 판단을 하지 않는다. 외부에 배포하기 전에 확인하고, 허용되지 않으면 패키지에서 빼고 설치 안내(2절 주소·검증 방법)로 대신한다.
