# Linux 검증 절차 (P19B)

이 PC(Windows 11)에는 WSL·docker가 없어 Linux 확인은 **미확인**이다(P14·P15·P19·P19B). 이 문서는 운영 Linux 서버나 시험용 Linux에서 사용자가 실행할 순서를 정리한다.
각 단계는 독립적이다. 결과(명령 출력)를 `docs/results/`의 다음 보고서에 붙이면 "확인됨"으로 바꾼다.

## 0. 준비

| 항목 | 값 |
| --- | --- |
| 배포판 | Ubuntu 22.04/24.04 또는 RHEL 9 계열(macOS 미지원) |
| PHP | 8.2 이상 CLI + Apache 모듈 또는 PHP-FPM. 확장: `pdo_sqlite`, `zip`, `mbstring`, `openssl`, `fileinfo`, `intl`, `posix`, `pcntl`(워커 신호), `curl`(점검 도구) |
| SQLite | 3.38.0 이상(`Db::MIN_SQLITE_VERSION` — 미만이면 접속을 거부, STRICT·JSON 함수·`DROP COLUMN`) — `php -r 'echo SQLite3::version()["versionString"];'` |
| util-linux | `setsid`, `prlimit`(ruff 메모리 한도) — `which setsid prlimit` |
| Node.js | 20.19 이상(ESLint 10). `config.ini`의 `tools.node_path`에 절대 경로 |
| bin/ | `bin/ruff/<버전>/linux/ruff`, `bin/eslint/<버전>/`, `bin/p4/<버전>/linux/p4`, `bin/git/<버전>/linux/bin/git`(X09: 아직 없음 → `tools/build_git_linux.sh --gpg`로 빌드) |
| 계정 | 웹 계정(예: `www-data`)은 `bin/`·`src/`에 **쓰기 권한 없음**. 쓰기는 `data/`·백업 폴더·`config/`(설정 관리의 `settings.local.ini*` — X14 방침, 위험과 완화는 `docs/xampp_vhost.md` 2절). 워커는 같은 계정 또는 같은 그룹 |

Ubuntu 예: `sudo apt install php8.3-cli php8.3-sqlite3 php8.3-zip php8.3-mbstring php8.3-intl php8.3-curl util-linux`(posix·pcntl·fileinfo·openssl은 CLI에 기본 포함).

## 1. 환경 점검

```
php tools/check_env.php                 # FAIL이 없어야 한다(종료 코드 0)
php tools/config_check.php config/config.ini
php tools/migrate.php --status          # 적용 대기 없음
which setsid prlimit
```

- 실패 시: `check_env.php` 출력의 항목 이름(확장·버전)을 본다. intl이 없으면 경로 NFC 정규화를 할 수 없어 업로드가 막힌다.

## 2. 자동 시험

```
php tests/run.php                       # 제품 시험 전체(약 8~10분). 기대: 실패 0
node --test "tests/js/*.test.mjs"       # 기대: 실패 0
php tools/run_all_tests.php             # 요약(+ 1단계 E2E 12개 시나리오)
```

- Linux에서만 의미 있는 시험: `ProcessRunner`(타임아웃·취소 뒤 `aliveAmong` 빈 목록, `killMethod`에 `SIGKILL`), `WorkerProcess`(고아 손자까지 프로세스 그룹 종료), `ToolRunners`(시료 대조표 — Windows와 같아야 함), `Repo*`(git은 `bin/git/<버전>/linux/bin/git`이 있어야 실행, 없으면 "건너뜀" 출력).
- 실패하면 `data/logs/app-YYYY-MM-DD.log`(한 줄 JSON)의 `ERROR`·`child_stderr`를 본다.

## 3. 도구 레지스트리

```
php tools/tools_ctl.php node            # Node 경로·버전(최소 버전 이상)
php tools/tools_ctl.php verify ruff     # 체크섬·스모크 통과
php tools/tools_ctl.php verify eslint
php tools/tools_ctl.php verify git      # bin/git/<버전>/linux 빌드 뒤
php tools/tools_ctl.php verify p4
php tools/check_bin_vcs.php             # 매니페스트·ELF·격리 실행
```

## 4. 프로세스 트리 종료·메모리 한도

1. 타임아웃·취소: `php tests/run.php ProcessRunner` → 통과, 출력의 종료 방식이 `setsid` 프로세스 그룹 `kill(-PGID, SIGKILL)`.
2. ruff 메모리 한도(`prlimit --as`): 시험용 ini에 `analysis.process_memory_limit = 256MB`를 두고 큰 Python 프로젝트를 분석 → `memory_limit` 실패 또는 정상 결과, stderr에 `memory allocation`.
   직접: `php -r 'require "src/bootstrap.php"; $r=(new PJGuardian\Services\Process\ProcessRunner(["/usr/bin"],[PHP_BINARY]))->run([PHP_BINARY,"-r","str_repeat(\"x\",600*1024*1024);"], new PJGuardian\Services\Process\ProcessOptions(memoryLimit: 256*1024*1024)); var_dump($r->reason, $r->exitCode);'` → 비정상 종료.
3. `/proc` 생존 확인: 워커 실행 중 `php tools/worker.php --status` → pid·"실행 중". 워커를 `kill -9`로 죽인 뒤 `--check` → 죽은 워커 정리, 다음 기동 때 `worker_crashed`.

## 5. 워커 시연(업로드 → 분석)

```
php tools/worker.php --config=<시험 ini>            # 별도 DB·data 폴더
# 다른 터미널: 시험 서버(php -S 127.0.0.1:8099 -t public <router>) 또는 Apache로 samples/zips/python_sample.zip, ts_sample.zip 업로드
```

- 기대: 결과 결함 19건(python_sample), 6건(ts_sample) — `samples/EXPECTED.md`와 같다.
- 정지: `kill -TERM <워커 pid>` → "정지 요청" 후 진행 중 분석을 기다렸다가 종료, 두 번 보내면 강제 정지(분석은 `worker_stopped`).
- systemd 등록은 `docs/worker_setup.md` 4절(`Restart=on-failure`, 재시작 요청 종료 코드 75).

## 6. 경로·대소문자·NFC

- 대소문자만 다른 두 파일(`A.py`, `a.py`)을 담은 zip → Linux에서도 `name_conflict`로 거부(Windows와 같은 규칙, 명세 19.10).
- NFD 파일명(macOS에서 만든 zip, `samples/zips_bad/`)→ NFC로 정규화되어 결함 경로·시그니처가 Windows 결과와 같다.
- 결과 경로: ruff·ESLint가 돌려준 경로가 분석 루트 밖이면 거부(`ToolOutputParser`). 심볼릭 링크는 해제 단계에서 거부.

## 7. 파일 권한

```
sudo -u www-data touch bin/x 2>&1         # Permission denied 여야 한다
sudo -u www-data touch data/work/x && rm data/work/x
php tools/create_admin.php --check-only   # 하드닝 점검(쓰기 권한·노출 폴더)
```

## 8. 저장소 연동·주기 분석(P25·P26)

- `tools/build_git_linux.sh --gpg`로 `bin/git/<버전>/linux` 빌드 → `php tests/run.php Repo` → git 시험이 "건너뜀" 없이 통과.
- `php tests/run.php Schedule` → 실제 워커 시험(`ScheduleWorkerTest`)까지 통과.

## 9. Apache(Linux)

- `docs/xampp_vhost.md`의 가상 호스트를 Linux 경로로 옮겨 `public/`만 노출, `AllowOverride None` + `FallbackResource /index.php`.
- 청크 업로드 한도는 `docs/results/P19B.md` 1절 표(8MB 청크는 PHP·Apache 기본값으로 통과, `LimitRequestBody` 0 또는 1GiB 이상).

## Windows 전용 가정 목록(P19B 코드 검색)

코드는 OS를 `PHP_OS_FAMILY`(`ProcessTree::isWindows()`, `ToolRegistry::os()`)로 갈라 두었다. Windows 쪽 분기만 있는 곳은 없다.

| 위치 | Windows 동작 | Linux 동작 |
| --- | --- | --- |
| `Services/Process/ProcessTree.php` | `System32\taskkill.exe /T /F`, `tasklist.exe` | `setsid`로 새 세션, `posix_kill(-PGID, SIGKILL)`, `/proc/<pid>` |
| `Services/Process/ProcessRunner.php` | 최소 환경에 `SystemRoot` | `PATH=/usr/bin:/bin`, ruff는 `prlimit --as` |
| `Services/Tools/ToolRegistry.php` | `windows/ruff.exe`, `windows/cmd/git.exe`, `windows/p4.exe` | `linux/ruff`, `linux/bin/git`, `linux/p4` |
| `Services/Tools/NodeRuntime.php`, `Repo/VcsSandbox.php` | `node.exe`, `.exe` 접미 | 접미 없음 |
| `Services/Queue/Worker.php` | `sapi_windows_set_ctrl_handler`(Ctrl+C) | pcntl `SIGTERM`·`SIGINT` |
| `Services/Admin/WorkerControl.php` | `schtasks` → `System32\schtasks.exe` | `systemctl`·`sudo` 절대 경로 |
| `DefectWriter`·`RuffConfigResolver`·`CleanupService`·`HardeningCheck` | 경로 비교를 소문자로(대소문자 비구분) | 그대로 비교 |
| `Support/EnvironmentCheck.php` | — | Linux에서만 `posix`·`pcntl` 확장 점검(워커의 프로세스 그룹·정지 신호, 없으면 경고). `setsid`·`prlimit`은 점검하지 않음 — 0절 `which setsid prlimit`로 직접 확인 |
| `tools/run_all_tests.php` | `NUL`, 기본 Node `C:/tools/nodejs/node.exe`(있을 때만) | `/dev/null`, `node`(PATH) — `--node=`로 지정 가능 |
| `tools/check_bin_vcs.php` | Windows 격리 실행 시험 | ELF 확인만(실행은 Linux에서) |
