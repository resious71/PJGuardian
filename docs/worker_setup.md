# 분석 워커 기동과 운영

PJGuardian의 분석과 업로드 후처리(조립·압축 해제)는 웹 요청 안에서 하지 않고 **CLI 워커** `tools/worker.php`가 한다 (명세 5.2, 5.4, 19.8). 이 문서는 워커를 상시 실행으로 등록하는 방법과 정리 작업(`tools/cleanup.php`)의 매일 실행, 재기동, 로그 위치, OS별 자원 제한을 설명한다. 실제 등록은 관리자가 한다. 설계와 측정은 `docs/architecture.md` 22절과 `docs/results/P14.md`.

## 1. 하는 일

- **분석 큐**: `analyses.status='queued'`를 등록 순서(FIFO)대로 처리한다. 동시에 2개(`analysis.max_concurrent`), 프로젝트당 1개. 앞 작업이 같은 프로젝트 때문에 막혀 있으면 다른 프로젝트의 뒤 작업이 먼저 실행된다(명세 19.8).
- **주기 분석 스케줄러(P26)**: 1분(`scheduler.tick_seconds`)마다 도래한 프로젝트의 가져오기·분석을 큐에 넣는다(별도 서비스·cron 없음 — 워커가 꺼져 있으면 등록되지 않고, 다시 켜면 밀린 일정은 한 번만). `docs/repo_integration.md` 9절.
- **업로드 후처리**: 조립 대기 → 조립, 해제 대기 → 안전 해제·사전 검사 → 분석 대기열. 분석 슬롯과 따로 센다(`worker.upload_concurrency`, 기본 1).
- 분석과 후처리는 모두 **자식 프로세스**로 실행하고 워커(부모)는 감독만 한다: 타임아웃(`analysis.timeout` 4시간, 후처리 `worker.extract_timeout` 2시간), 취소 요청(약 1초마다 확인), 생존 신호.
- 분석이 끝나면 작업 디렉토리를 지우고(휴지통으로 옮긴 뒤 자식이 비움), 분석이 성공하면 업로드 zip도 바로 지운다. 실패·취소된 분석의 zip은 1일 뒤 정리 작업이 지운다.

## 2. 명령

```
php tools/worker.php                     # 상시 실행(정지 요청까지)
php tools/worker.php --stop              # 정상 종료 요청: 새 작업을 받지 않고 진행 중인 작업이 끝나면 종료
php tools/worker.php --status            # 실행 여부, 마지막 생존 신호, 처리 중 작업 수
php tools/worker.php --check             # 워커가 죽었고 생존 신호가 끊겼으면 남은 작업을 정리(정리 작업과 같은 판정)
php tools/worker.php --once              # 처리할 것이 없어지면 종료(시험·수동 처리)
php tools/worker.php --force-stop        # 정지 요청 때 진행 중인 작업을 기다리지 않고 종료
php tools/cleanup.php [--dry-run]        # 정리 작업(매일)
```

- 공통 옵션 `--config=<config.ini>`: 다른 설정 파일(점검용 격리 환경). 기본은 `config/config.ini`.
- PHP 경로는 Windows `C:\xampp\php\php.exe`, Linux `/usr/bin/php`(배포판에 따라 다름). `php.ini`에서 intl이 꺼져 있으면 `-d extension=intl`을 붙인다. 워커는 같은 `php.ini`(`-c`)와 부모에서 켠 확장을 자식에게도 넘긴다.
- 종료 코드: 0 정상, 2 설정·실행 오류, 3 이미 다른 워커가 실행 중(**단일 인스턴스**: `data/work/worker/worker.lock`), **75 관리자 화면의 재시작 요청으로 종료**(서비스 관리자가 다시 띄우라는 뜻, P20A).
- 워커가 실행 중이면 `tools/process_upload.php`(수동 후처리)는 종료 코드 3으로 끝난다(둘이 같은 업로드를 잡지 않게).

### 정지 방법

| 방법 | 동작 |
| --- | --- |
| `php tools/worker.php --stop` (모든 환경) | `data/work/worker/stop.request`를 만든다. 워커는 새 작업 선점을 멈추고 진행 중인 자식이 끝나면 종료한다 |
| Ctrl+C / Ctrl+Break (Windows 콘솔) | 같다. 두 번 누르면 강제 정지 |
| `SIGTERM` / `SIGINT` (Linux, pcntl 확장) | 같다. 두 번 받으면 강제 정지. systemd `stop`은 SIGTERM |
| `--force-stop`으로 실행한 워커, 또는 두 번째 정지 신호 | 자식 프로세스 트리를 종료하고 분석은 실패(`worker_stopped`), 업로드 후처리는 되돌려 다음 기동 때 다시 처리 |

분석 하나가 최대 4시간 걸릴 수 있으므로, 서비스 관리자의 정지 대기 시간(systemd `TimeoutStopSec`, NSSM `AppStopMethodConsole` 등)을 길게 두거나 강제 정지를 감수한다.

## 3. Windows

### 3.1 작업 스케줄러(권장: 추가 도구 없음)

"1분마다 실행 + 이미 실행 중이면 바로 종료(단일 인스턴스)"로 등록하면, 워커가 죽어도 1분 안에 다시 뜨고 기동하면서 남은 작업을 정리한다.

1. 작업 스케줄러 → 작업 만들기
   - 일반: 이름 `PJGuardianWorker`(공백 없이 — `schtasks /run /tn PJGuardianWorker`와 같은 이름), "사용자의 로그온 여부에 관계없이 실행", 권한은 `data/` 쓰기 권한이 있는 계정(Apache와 같은 계정 권장)
   - 트리거: "시스템 시작 시" + "매일, 1분마다 반복, 기간: 무기한"
   - 동작: 프로그램 `C:\xampp\php\php.exe`, 인수 `-d extension=intl D:\PJGuardian\tools\worker.php`, 시작 위치 `D:\PJGuardian`
   - 설정: "작업이 이미 실행 중이면: 새 인스턴스 실행 안 함", "다음 시간 이상 실행되면 작업 중지"는 **끈다**
2. 명령줄로 만들 때(관리자 PowerShell):

```powershell
$action  = New-ScheduledTaskAction -Execute 'C:\xampp\php\php.exe' -Argument '-d extension=intl D:\PJGuardian\tools\worker.php' -WorkingDirectory 'D:\PJGuardian'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable
Register-ScheduledTask -TaskName 'PJGuardianWorker' -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest
```

3. 정리 작업(매일 03:00):

```powershell
$a = New-ScheduledTaskAction -Execute 'C:\xampp\php\php.exe' -Argument '-d extension=intl D:\PJGuardian\tools\cleanup.php' -WorkingDirectory 'D:\PJGuardian'
Register-ScheduledTask -TaskName 'PJGuardianCleanup' -Action $a -Trigger (New-ScheduledTaskTrigger -Daily -At 3am) -User 'SYSTEM'
```

콘솔이 없는 작업 스케줄러 실행에서는 Ctrl+C를 보낼 수 없으므로 정지는 `php tools/worker.php --stop`으로 한다. 작업 스케줄러의 "작업 끝내기"는 강제 종료(워커 부모만 종료될 수 있음)이며, 다음 기동 때 남은 작업을 실패(`worker_crashed`)로 정리하고 남은 자식 프로세스를 종료한다.

### 3.2 서비스(NSSM 등, 선택)

서비스로 돌리려면 서비스 래퍼(예: NSSM, 별도 설치)를 쓴다. PJGuardian은 래퍼를 포함하지 않는다.

```
nssm install PJGuardianWorker C:\xampp\php\php.exe -d extension=intl D:\PJGuardian\tools\worker.php
nssm set PJGuardianWorker AppDirectory D:\PJGuardian
nssm set PJGuardianWorker AppStopMethodConsole 600000   (Ctrl+C 후 최대 10분 대기)
nssm set PJGuardianWorker AppStdout D:\PJGuardian\data\logs\worker-console.log
nssm set PJGuardianWorker AppStderr D:\PJGuardian\data\logs\worker-console.log
```

## 4. Linux (systemd)

`/etc/systemd/system/pjguardian-worker.service`:

```ini
[Unit]
Description=PJGuardian analysis worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/pjguardian
ExecStart=/usr/bin/php /opt/pjguardian/tools/worker.php
ExecStop=/usr/bin/php /opt/pjguardian/tools/worker.php --stop
# 진행 중인 분석(최대 4시간)을 기다릴 시간. 넘으면 systemd가 SIGKILL(다음 기동 때 worker_crashed로 정리)
TimeoutStopSec=4h
KillMode=mixed
# P20A: 정지 요청(종료 코드 0)은 그대로 멈추고, 재시작 요청(75)·비정상 종료는 다시 띄운다
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
```

정리 작업 `/etc/systemd/system/pjguardian-cleanup.service`와 `.timer`:

```ini
# pjguardian-cleanup.service
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/opt/pjguardian
ExecStart=/usr/bin/php /opt/pjguardian/tools/cleanup.php

# pjguardian-cleanup.timer
[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true
[Install]
WantedBy=timers.target
```

```
sudo systemctl daemon-reload
sudo systemctl enable --now pjguardian-worker pjguardian-cleanup.timer
sudo systemctl restart pjguardian-worker     # 재기동(진행 중인 작업을 기다린 뒤)
journalctl -u pjguardian-worker -f           # 콘솔 출력
```

cron만 쓸 때: `* * * * * www-data /usr/bin/php /opt/pjguardian/tools/worker.php >/dev/null 2>&1`(단일 인스턴스라 1분마다 불러도 하나만 돈다)와 `0 3 * * * www-data /usr/bin/php /opt/pjguardian/tools/cleanup.php`.

Linux에서는 `setsid`(util-linux)와 `prlimit`(util-linux)이 `/usr/bin`에 있어야 한다. **Linux 동작은 P14에서 확인하지 못했다**(`docs/results/P14.md` "확인하지 못한 항목"의 시험 절차).

## 5. 자원 제한과 OS별 차이

| 항목 | Windows | Linux |
| --- | --- | --- |
| 분석 타임아웃(`analysis.timeout`, 4시간) | 적용. 넘으면 `taskkill /PID <pid> /T /F`로 트리 종료 → 실패 `timeout` | 적용. `setsid`로 만든 프로세스 그룹에 `SIGKILL` |
| 출력 크기 한도(`analysis.output_limit`, 1GB) | 적용(폴링). 감지 전까지 한도를 넘겨 쓸 수 있다: 최대 속도로 쓰는 출력기에서 약 250~450MB, 초당 60MB 정도면 0.3MB(P14 측정) | 적용(폴링, 같은 방식). `prlimit --fsize`로 커널이 막는 방법은 미적용·미확인 |
| 분석 프로세스 메모리(`analysis.process_memory_limit`, 8GB) | **적용하지 않는다.** PHP만으로는 프로세스 메모리를 제한할 수 없다(Job Object는 PHP 밖의 도우미가 필요). 시간·출력 한도로 대신한다. ESLint(Node)는 `--max-old-space-size`로 힙만 제한(P15) | ruff 등은 `prlimit --as=<바이트>`로 가상 메모리 제한(P15가 사용). Node는 가상 메모리 예약이 커서 `--as`를 쓰지 않고 `--max-old-space-size` |
| 워커 자식(PHP) 메모리 | `memory_limit` = `extract.worker_memory_limit`(512MB). libzip 등 PHP 밖 메모리는 제외(100만 항목 해제에서 프로세스 전체 486MB) | 같다 |
| 고아 자식 | 트리 종료는 **살아 있는 부모**에서 자식을 찾는다. 부모가 먼저 끝난 손자는 잡지 못한다(ruff·ESLint는 이런 자식을 만들지 않음) | 같은 프로세스 그룹이면 부모가 먼저 끝나도 함께 종료된다(미확인) |

관리자 가이드(P29) 입력: Windows 서버에서는 분석 도구의 메모리 사용이 제한되지 않는다. 메모리가 부족한 서버는 `analysis.max_concurrent`를 1로 낮추거나 Linux 서버를 쓴다.

## 6. 상태 파일과 로그

| 위치 | 내용 |
| --- | --- |
| `data/work/worker/worker.lock` | 단일 인스턴스 잠금. 워커가 살아 있는 동안 잠겨 있다 |
| `data/work/worker/heartbeat.json` | 생존 신호(10초마다): pid, 호스트, 시작 시각, 마지막 신호, 상태(running·stopping·stopped), 처리 중 분석·업로드와 자식 PID |
| `data/work/worker/stop.request` | 정상 종료 요청(`--stop`). 기동할 때 지운다 |
| `data/work/worker/tmp/` | 자식 프로세스의 표준 출력 임시 파일(끝나면 지움) |
| `data/work/trash/` | 지울 작업 디렉토리(워커가 옮겨 두고 자식·정리 작업이 비움) |
| `data/work/cleanup_pending.jsonl` | 지우지 못한 파일 목록. 정리 작업이 다시 지운다 |
| `data/logs/app-YYYYMMDD.log` | 한 줄 JSON 로그(웹·워커 공통). 워커 자식의 줄에는 `job_id`(분석 id 또는 `upload:<id>`)가 붙는다. 보존 30일(`log.error_access_retention`) |

### 6.1 WAL 체크포인트 (P20S)

- SQLite는 WAL 모드다. 분석 반영은 감지된 결함 행을 모두 갱신해 분석 한 번에 WAL이 크게 생긴다(결함 10만 건 약 145MB).
- **분석 자식 프로세스(반영 연결)는 자동 체크포인트를 끈다**(`PRAGMA wal_autocheckpoint = 0`). 큰 COMMIT 직후의 체크포인트가 반영 시간과 다른 사용자의 쓰기 대기에 끼지 않게 하려는 것이다.
- **워커(부모)가 체크포인트한다**: 시작할 때 한 번(지난 워커가 비정상 종료해 남긴 WAL 정리), 분석이 끝날 때마다(성공·실패·취소 모두, 다음 작업을 가져오기 전) `PASSIVE`(다른 연결을 막지 않음).
  WAL 파일이 `[database] wal_truncate_threshold`(기본 256MB)를 넘고 워커가 한가하면(진행 중인 분석·업로드·관리 작업 없음) `TRUNCATE`로 WAL 파일을 줄인다.
  `TRUNCATE`는 다른 연결의 읽기·쓰기가 있으면 `busy_timeout` 동안 기다렸다가 못 끝내면 포기한다.
- 체크포인트가 다른 연결에 막혀 끝나지 않아도 분석 결과에는 영향이 없다. 로그에 경고("WAL 체크포인트가 다른 연결에 막혀…")만 남기고 다음 분석이 끝날 때 다시 한다. 100ms 이상 걸리거나 TRUNCATE면 정보 로그("WAL 체크포인트")에 모드·시간·WAL 크기가 남는다. 마지막 결과는 생존 신호(`heartbeat.json`의 `wal_checkpoint`)에도 있다.
- 웹(Apache·PHP)과 CLI 도구의 연결은 SQLite 기본 동작(자동 체크포인트 1000페이지) 그대로다. 워커가 체크포인트하기 전 짧은 틈에 웹 요청이 쓰면 그 요청이 자동 체크포인트를 할 수 있다(워커는 자식이 끝나면 0.2초 안에 체크포인트한다).

## 7. 장애 대응

- **워커가 죽었다**: 다시 띄우면(작업 스케줄러·systemd가 자동) 기동하면서 `running`으로 남은 분석을 실패(`worker_crashed`)로 바꾸고 남은 자식 프로세스를 종료한다. 재시도는 없다(명세 5.4, 19.10): 사용자가 다시 업로드한다. 조립·해제 중이던 업로드는 되돌려 다시 처리한다.
- **워커를 다시 띄우지 못하는 동안**: 정리 작업(또는 `--check`)이 생존 신호가 3분(`worker.heartbeat_stale`) 넘게 끊기고 잠금도 풀린 것을 확인하면 같은 정리를 한다. 잠금이 잡혀 있는데 신호만 끊겼으면(워커 멈춤) 되돌리지 않고 로그에 "워커 응답 없음" 경고만 남긴다.
- **분석이 오래 대기한다**: `php tools/worker.php --status`로 워커 실행 여부를 본다.

## 8. 관리자 화면에서 워커 다루기 (P20A)

관리자 메뉴 **시스템 관리 → 워커**(`/admin/worker`). 웹은 워커 프로그램을 직접 실행하거나 PID로 종료하지 않는다. 할 수 있는 일은 아래 세 가지다.

| 동작 | 방식 | 설정 |
| --- | --- | --- |
| 상태 보기 | 생존 신호 파일(`data/work/worker/heartbeat.json`)과 큐를 읽기만(실행 중/정지 중/중지/응답 없음, PID, 시작·신호 시각, 실행 중 분석, 대기 수, 마지막 복구, Node 검사 결과) | — |
| 안전 정지 요청 | `stop.request`(JSON: kind=stop, 요청 시각)를 쓴다. 워커는 새 작업을 받지 않고 진행 중인 분석을 끝낸 뒤 종료 코드 0 | — |
| 재시작 요청 | 같은 신호(kind=restart). 진행 중인 분석을 끝낸 뒤 **종료 코드 75**. 서비스 관리자가 다시 띄운다 | `worker.supervised = true`일 때만(아니면 거부) |
| 시작 | 설정 `worker.start_command`의 **고정 명령 하나**를 셸 없이 인자 배열로 실행(사전 등록한 작업 시작). 화면 입력은 명령에 들어가지 않는다 | `worker.start_command`, `worker.start_timeout`(기본 10초) |

- **오래된 신호**: 워커는 시작할 때 `stop.request`를 지우고, 실행 중에 자기 시작 시각보다 오래된 요청을 보면 무시하고 지운다. 처리한 요청은 종료할 때 지운다(재시작된 워커가 같은 신호로 바로 멈추지 않게).
- **`worker.supervised`**(기본 false): 서비스 관리자가 종료 코드 75를 보고 다시 띄우도록 등록했으면 true로 바꾼다. false면 화면은 "정지 요청 → 중지 확인 → 시작 버튼(또는 서버에서 실행)" 순서로 안내하고, 정지 요청 확인 대화상자에 "다시 실행해야 한다"는 경고가 나온다.
  - systemd: 위 4절의 유닛처럼 `Restart=on-failure`(정지 = 0은 그대로, 재시작 = 75·비정상 종료는 다시 띄움).
  - NSSM: `nssm set PJGuardianWorker AppExit Default Restart`, `nssm set PJGuardianWorker AppExit 0 Exit`.
  - 작업 스케줄러: "작업이 실패하면 다시 시작" 설정이 종료 코드 75를 실패로 보고 다시 띄우는지는 **미확인**(P20A). 확실하지 않으면 supervised=false로 두고 시작 버튼을 쓴다.

### 8.1 시작 명령과 웹 계정 권한

`config.ini`의 `[worker] start_command`에 **사전 등록한 작업을 시작하는 명령 하나**를 적는다. 첫 단어는 절대 경로이거나 `schtasks`·`systemctl`·`sudo` 중 하나(고정 시스템 폴더 `%SystemRoot%\System32`, `/usr/bin` 등에서만 찾고 PATH를 쓰지 않는다). PHP나 `tools/worker.php`를 직접 가리키면 거부한다. 따옴표로 인자를 묶을 수 있고 셸 문법(`&&`, `;`, `|`, 변수)은 해석하지 않는다.

- **Windows(작업 스케줄러)**: 3.1절처럼 `PJGuardianWorker` 작업을 등록한 뒤 `start_command = "schtasks /run /tn PJGuardianWorker"`. 웹 서버 계정(Apache 서비스 계정)이 그 작업을 실행할 권한이 있어야 한다: 작업을 관리자 권한으로 만든 경우 일반 계정은 `schtasks /run`이 "액세스 거부"가 된다. 작업 폴더 `C:\Windows\System32\Tasks\PJGuardianWorker` 파일의 보안에 웹 계정의 "읽기 및 실행"을 더하는 방법이 알려져 있다(이 PC에서 **미확인**).
- **Linux(systemd)**: `start_command = "sudo -n systemctl start pjguardian-worker"`. sudoers에 이 명령 하나만 허용한다(`visudo -f /etc/sudoers.d/pjguardian`):
  ```
  www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start pjguardian-worker
  ```
  (`-n`: 비밀번호를 묻지 않고 실패. 이 PC는 Windows라 **미확인**.)
- 버튼은 설정이 비어 있거나 PHP `disable_functions`에 `proc_open`이 있으면 숨겨지고, 이미 실행 중이면 비활성이다. 시작 요청은 잠금(`data/work/worker/start.lock`)으로 하나씩 처리하고, 성공한 시작 뒤 30초 동안은 다시 실행하지 않는다(그래도 겹치면 워커의 단일 인스턴스 잠금이 하나만 남긴다). 화면은 종료 코드와 출력 첫 줄(서버 경로·비밀값 가림)을 보여 주고, 생존 신호를 최대 30초 확인해 "시작됨/시작 확인 실패"를 표시한다. 감사 로그 `worker.start_request`에는 성공 여부·종료 코드만 남는다.

### 8.2 관리 작업과 로그 보기

- 화면의 **재검증 요청**(도구), **사용량 다시 계산**·**지금 정리 실행**(점검)은 `admin_tasks` 표에 들어가고 워커가 한 번에 하나씩 자식(`tools/run_admin_task.php`)으로 실행한다(상한 `worker.admin_task_timeout`, 분석 슬롯과 별도). 워커가 없으면 대기로 남는다.
- **최근 로그**: 오늘(없으면 어제) `data/logs/app-YYYYMMDD.log`의 마지막 `worker.log_tail_lines`줄(기본 200). 끝에서부터 읽고 서버 경로·비밀값을 가린다. "워커·CLI만"은 웹 요청이 아닌 줄(request_id 없음). 원본 다운로드는 없다.

## 9. 결함이 아주 많은 프로젝트 (P19C)

- 결함이 수십만 건이면 반영·목록이 느려지고 쓸모도 적다. 대부분 **생성 파일·압축(minified) 파일·외부 라이브러리 폴더**에서 나온다 → 프로젝트 편집 화면의 **제외 패턴**에 넣는다(예: `dist/**`, `**/*.min.js`, `vendor/**`, `**/generated/**`).
- `[limits] project_defect_warn`(기본 20만): 현재 결함(해결 아님)이 넘으면 관리자 알림 "결함 수 경고"와 프로젝트 화면 경고 한 줄. `project_defect_max`(기본 100만): 이번 분석에서 감지한 결함만으로 넘으면 분석이 "결함 수 상한 초과"(`defect_limit_exceeded`)로 실패하고 기존 결함은 그대로다. 0이면 끈다. 상한을 올리기 전에 제외 패턴을 먼저 검토한다.
- 한 파일에 결함이 2만 건(`analysis.file_defect_spill_threshold`)을 넘으면 그 파일은 작업 폴더의 임시 SQLite(`<work>/spill/`)에서 처리한다(메모리 일정). 남은 파일은 워커·`tools/cleanup.php`가 지운다. 여유 공간이 부족하면 분석이 `insufficient_storage`로 실패한다.
- 반영 중 다른 사용자의 쓰기 대기, 분석 사이 체크포인트(PASSIVE 우선, `database.checkpoint_max_block_ms`)의 측정은 `docs/results/P19C.md`.
