---
key: linux-ops
title: Linux 운영
order: 25
summary: Linux 서버 준비물과 검증 순서, Windows와 다르게 동작하는 부분, 아직 운영 Linux에서 확인되지 않은 항목
keywords: Linux, Ubuntu, RHEL, systemd, setsid, prlimit, posix, pcntl, SIGTERM, 권한, www-data, 검증, 미확인, git 빌드
related: install, worker, tools-bin, security, troubleshooting
audience: admin
---
## 이 절의 범위

PJGuardian은 Windows와 Linux를 지원합니다. 다만 지금까지의 확인은 Windows 개발 PC에서 했고, **Linux에서는 아직 실행해 보지 않았습니다.** 코드는 OS별로 분기되어 있고 Windows 쪽 분기만 있는 곳은 없습니다. 이 절은 운영 Linux 서버(또는 시험용 Linux)에서 관리자가 직접 검증할 순서를 정리합니다. 각 단계는 독립적이며, 결과를 기록해 두면 이후 운영 판단에 씁니다.

설치 자체는 [설치와 환경](guide:install)과 같고, 워커 등록은 [분석 워커 운영](guide:worker)의 systemd·cron 절을 따릅니다.

## 준비물

- 배포판: Ubuntu 22.04/24.04 또는 RHEL 9 계열.
- PHP 8.2 이상 CLI와 Apache 모듈(또는 PHP-FPM). 확장: pdo_sqlite, zip, mbstring, openssl, fileinfo, intl, posix, pcntl, curl(점검 도구용). posix·pcntl은 워커의 프로세스 그룹과 정지 신호에 씁니다. 웹 PHP와 CLI PHP의 설정이 다를 수 있으니 둘 다 확인합니다.
- util-linux의 `setsid`, `prlimit`(`/usr/bin`에 있어야 함).
- Node.js: `tools.node_path`에 절대 경로를 적습니다. 최소 버전은 {{limit:tools.node_min_version}}입니다.
- `bin/`: `bin/ruff/<버전>/linux/ruff`, `bin/eslint/<버전>/`, `bin/p4/<버전>/linux/p4`, `bin/git/<버전>/linux/bin/git`. Linux git은 직접 빌드해야 합니다(아래).
- 계정: 웹 계정(예: `www-data`)이 쓸 수 있는 곳은 `config/`(설정 관리 화면의 settings.local.ini 저장용), `data/`, 백업 폴더뿐이고 `bin/`·`src/` 등 나머지에는 쓰기 권한이 없습니다. 이 방침과 위험·완화는 [설치와 환경](guide:install)을 보세요. 워커는 같은 계정이나 같은 그룹으로 실행합니다.

## 검증 순서

1. 환경 점검: `tools/check_env.php`에 FAIL이 없고(종료 코드 0), 설정 검증과 마이그레이션 현황(대기 없음)을 확인하고, `setsid`·`prlimit`가 있는지 봅니다.
2. 자동 시험: 배포 묶음에는 `tests/`가 없으므로 저장소 사본이 있는 시험용 서버에서 합니다. Linux에서 특히 의미 있는 시험은 ProcessRunner(타임아웃·취소 뒤 프로세스 그룹 종료), WorkerProcess(고아 손자까지 종료), ToolRunners(시료 결과가 Windows와 같은지)입니다.
3. 도구 레지스트리: Node 검사와 ruff·ESLint·git·p4 전체 검증이 통과하는지 봅니다(git은 Linux 빌드 뒤).
4. 프로세스 종료·메모리 한도: 워커 실행 중 `--status`로 pid와 실행 중을 확인하고, 워커를 강제로 죽인 뒤 `--check`로 죽은 워커 정리, 다음 기동 때 `worker_crashed` 처리를 확인합니다. ruff 메모리 한도는 시험용 설정에서 `analysis.process_memory_limit`를 낮춰 큰 Python 프로젝트를 분석해 봅니다(`memory_limit` 실패 또는 정상 결과).
5. 워커 시연: 별도 설정(`--config=<시험 ini>`, 별도 DB·data 폴더)으로 워커를 띄우고 샘플 zip을 올려 결함 수가 Windows 결과와 같은지 봅니다. `kill -TERM <워커 pid>`로 정지 요청 후 진행 중 분석을 기다렸다가 끝나는지, 두 번 보내면 강제 정지(`worker_stopped`)되는지 확인합니다.
6. 경로 규칙: 대소문자만 다른 두 파일이 든 zip은 Linux에서도 이름 충돌로 거부되어야 합니다. NFD 파일명은 NFC로 정규화되어 결함 경로·시그니처가 Windows와 같아야 합니다.
7. 파일 권한: 웹 계정으로 `bin/`에 파일을 만들 수 없고 `data/work/`와 `config/`에는 만들 수 있는지, 하드닝 점검 결과를 봅니다.
8. 저장소 연동: Linux git을 빌드한 뒤 저장소 시험과 주기 분석 시험이 "건너뜀" 없이 통과하는지 봅니다.
9. Apache: 가상 호스트를 Linux 경로로 옮겨 `public/`만 노출하고(`AllowOverride None` + `FallbackResource /index.php`), [설치와 환경](guide:install)의 노출 점검(404·301·HSTS)을 다시 합니다. 설정 파일 위치는 XAMPP for Linux면 `/opt/lampp/etc/extra/httpd-vhosts.conf`, Debian/Ubuntu Apache면 `/etc/apache2/sites-available/`입니다.

```
# 1. 환경 점검
php tools/check_env.php
php tools/config_check.php config/config.ini
php tools/migrate.php --status
which setsid prlimit
# 2. 자동 시험(시험용 서버)
php tests/run.php
node --test "tests/js/*.test.mjs"
php tools/run_all_tests.php
# 3. 도구 레지스트리
php tools/tools_ctl.php node
php tools/tools_ctl.php verify ruff
php tools/tools_ctl.php verify eslint
php tools/tools_ctl.php verify git
php tools/tools_ctl.php verify p4
php tools/check_bin_vcs.php
# 7. 파일 권한
sudo -u www-data touch bin/x               # Permission denied여야 함
sudo -u www-data touch data/work/x && rm data/work/x
sudo -u www-data touch config/x && rm config/x
php tools/create_admin.php --check-only
```

## Windows와 다르게 동작하는 부분

- 프로세스 트리 종료: Windows는 `taskkill /T /F`, Linux는 `setsid`로 만든 프로세스 그룹 전체에 SIGKILL을 보냅니다. 설계상 Linux에서는 부모가 먼저 끝난 손자도 같은 그룹이면 함께 종료됩니다(운영 환경에서 확인할 것).
- 메모리 한도(`analysis.process_memory_limit`, 지금 {{limit:analysis.process_memory_limit}}): ruff는 Linux에서만 `prlimit --as`로 제한합니다. Windows는 제한하지 않습니다. ESLint(Node)는 두 OS 모두 힙 크기만 제한합니다.
- 출력 크기 한도(`analysis.output_limit`, 지금 {{limit:analysis.output_limit}})는 두 OS 모두 폴링으로 감시합니다.
- 워커 정지 신호: Linux는 SIGTERM·SIGINT(pcntl 확장 필요), systemd `stop`은 SIGTERM입니다. 두 번 받으면 강제 정지입니다.
- 워커 시작 단추의 명령: Linux는 `sudo -n systemctl start pjguardian-worker` 형태를 쓰고 sudoers에 이 명령 하나만 허용합니다.
- 경로 비교: 서버 내부의 일부 경로 비교는 Windows에서 대소문자를 구분하지 않고, Linux에서는 그대로 비교합니다. 업로드 파일 이름 충돌 규칙은 두 OS가 같습니다.
- 설정 관리 화면이 쓰는 `config/settings.local.ini`는 Linux에서 0640으로 만들어집니다.

## Linux git 빌드

공식 Linux git 바이너리가 없으므로 운영 서버와 같은 배포판(glibc·libcurl·OpenSSL이 같은)에서 빌드합니다. `bin/`에 쓸 수 있는 서버 관리자 계정으로 합니다.

1. 빌드 도구와 개발 패키지(libcurl·OpenSSL·zlib·expat)를 설치합니다.
2. `tools/build_git_linux.sh --gpg`를 실행합니다. 공식 체크섬을 대조하고, 시스템에 설치하지 않고 `bin/git/<버전>/linux/`에 만든 뒤 매니페스트를 씁니다.
3. `php tools/check_bin_vcs.php`로 매니페스트와 실행 파일 형식을 확인합니다.
4. 웹·워커 계정이 그 폴더에 쓸 수 없게 권한을 막습니다.

다른 배포판 서버로 옮길 때는 그 서버에서 다시 빌드합니다.

## 운영 환경에서 확인할 것

아래 항목은 코드와 절차만 있고 Linux에서 실행해 확인하지 못했습니다. 운영 환경에서 확인할 것.

- `tools/check_env.php`의 Linux 결과(posix·pcntl 표시 포함)와 점검 화면의 환경 항목.
- `setsid` 프로세스 그룹 종료, `/proc` 기반 생존 확인, 고아 손자 프로세스 정리.
- ruff `prlimit --as` 메모리 한도. `prlimit`은 `/usr/bin/prlimit` 고정 경로라 배포판에 따라 다르면 확인이 필요합니다.
- 출력 크기 한도를 커널(`prlimit --fsize`)로 막는 방식은 적용하지 않았습니다(폴링만).
- SIGTERM 정상 정지와 두 번째 신호의 강제 정지.
- systemd 서비스·타이머 실제 등록, `Restart=on-failure`로 종료 코드 75 뒤 다시 뜨는지, 시작 단추의 `sudo -n systemctl start`와 sudoers 한 줄.
- Linux에서 빌드한 git, Linux p4 실행, 저장소 연동·주기 분석 시험.
- Linux Apache 가상 호스트와 청크 업로드 한도.
- ruff·ESLint 시료 결과가 Windows와 같은지.
- settings.local.ini가 0640으로 만들어지고, 웹 계정이 쓴 파일을 워커 계정이 같은 그룹으로 읽는 구성이 실제로 동작하는지.
- `config/` 쓰기를 허용한 채 `config.ini`·`secrets.ini`만 웹 계정 읽기 전용으로 두는 파일 단위 권한이 화면 저장을 막지 않는지.

## 문제 해결

- `tools/check_env.php`가 intl FAIL: 경로 NFC 정규화를 할 수 없어 업로드가 막힙니다. php-intl 패키지를 설치하고 웹·CLI 양쪽에서 켜졌는지 봅니다.
- 점검 화면에 posix·pcntl 경고: CLI PHP에 확장이 없습니다. 워커가 프로세스 그룹을 만들거나 정지 신호를 받지 못합니다.
- 실패한 시험이나 분석은 `data/logs/app-YYYYMMDD.log`(한 줄 JSON)의 ERROR 줄을 봅니다.
