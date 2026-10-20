---
key: worker
title: 분석 워커 운영
order: 3
summary: 분석·업로드 후처리를 맡는 워커를 서비스로 등록하고, 상태 확인·정지·재시작·장애 대응을 하는 방법과 워커 탭 보는 법
keywords: 워커, worker.php, 작업 스케줄러, NSSM, systemd, cron, 정지, 재시작, 종료 코드 75, 생존 신호, 큐, 대기, 응답 없음, 정리 작업, cleanup, 점검
related: install, tools-bin, linux-ops, settings, troubleshooting, monitoring
audience: admin
---
## 워커가 하는 일

분석과 업로드 후처리(조립·압축 해제·사전 검사)는 웹 요청이 아니라 CLI 워커 `tools/worker.php`가 합니다. **워커가 꺼져 있으면 업로드가 "조립 대기"에서 멈추고 분석이 시작되지 않습니다.**

- 분석 큐를 등록 순서대로 처리합니다. 동시 분석은 {{limit:analysis.max_concurrent}}개, 프로젝트당 1개입니다. 앞 작업이 같은 프로젝트 때문에 막혀 있으면 다른 프로젝트의 뒤 작업이 먼저 실행됩니다.
- 업로드 후처리는 분석과 따로 셉니다(동시 {{limit:worker.upload_concurrency}}개).
- 주기 분석 스케줄러도 워커 안에서 {{limit:scheduler.tick_seconds}}마다 돕니다. 워커가 꺼져 있던 동안 밀린 일정은 다시 켤 때 한 번만 실행합니다.
- 관리 화면의 재검증·사용량 계산·정리 요청도 워커가 한 번에 하나씩 처리합니다.
- 분석·후처리는 자식 프로세스로 실행하고 워커는 감독만 합니다. 분석 시간 상한은 {{limit:analysis.timeout}}, 후처리 상한은 {{limit:worker.extract_timeout}}입니다.
- 워커는 서버에 하나만 돕니다. 이미 돌고 있으면 새로 띄운 워커는 종료 코드 3으로 바로 끝납니다.

## 명령

```
php tools/worker.php                # 상시 실행(정지 요청까지)
php tools/worker.php --status       # 실행 여부, 마지막 생존 신호, 처리 중 작업 수
php tools/worker.php --stop         # 정상 종료 요청: 새 작업을 받지 않고 진행 중인 작업이 끝나면 종료
php tools/worker.php --check        # 워커가 죽었고 생존 신호가 끊겼으면 남은 작업을 정리
php tools/worker.php --once         # 처리할 것이 없어지면 종료(수동 처리)
php tools/worker.php --force-stop   # 정지 요청 때 진행 중인 작업을 기다리지 않고 종료
php tools/cleanup.php [--dry-run]   # 정리 작업(매일)
```

- intl이 php.ini에서 꺼져 있으면 `-d extension=intl`을 붙입니다. 워커는 같은 php.ini와 켠 확장을 자식에게도 넘깁니다.
- 종료 코드: 0 정상, 2 설정·실행 오류, 3 이미 다른 워커가 실행 중, 75 관리자 화면의 재시작 요청으로 종료.
- 워커가 실행 중이면 수동 후처리 `tools/process_upload.php`는 종료 코드 3으로 끝납니다.

## 서비스로 등록하기

워커는 늘 떠 있어야 하고, 정리 작업 `tools/cleanup.php`는 매일 한 번 실행해야 합니다. 실행 계정에는 `data/` 쓰기 권한이 있어야 하고(Apache와 같은 계정 권장) `bin/` 쓰기 권한은 없어야 합니다.

### Windows 작업 스케줄러

추가 도구 없이 쓸 수 있는 방법입니다. "1분마다 실행 + 이미 실행 중이면 새 인스턴스 실행 안 함"으로 등록하면 워커가 죽어도 1분 안에 다시 뜹니다.

1. 작업을 만듭니다. 이름 예: `PJGuardianWorker`. "사용자의 로그온 여부에 관계없이 실행"을 고릅니다.
2. 트리거: 시스템 시작 시 + 1분마다 반복(기간 무기한).
3. 동작: 프로그램 `C:\xampp\php\php.exe`, 인수 `-d extension=intl <설치 폴더>\tools\worker.php`, 시작 위치 `<설치 폴더>`.
4. 설정: "작업이 이미 실행 중이면: 새 인스턴스 실행 안 함". "다음 시간 이상 실행되면 작업 중지"는 끕니다.
5. 정리 작업도 같은 방식으로 `tools\cleanup.php`를 매일 한 번(예: 03:00) 실행하는 작업으로 등록합니다.

작업 스케줄러에는 콘솔이 없으므로 정지는 `php tools/worker.php --stop`으로 합니다. "작업 끝내기"는 강제 종료입니다.

### Windows 서비스(NSSM 등)

서비스 래퍼는 PJGuardian에 들어 있지 않으므로 따로 설치합니다. 정지할 때 Ctrl+C 후 오래 기다리도록(분석이 끝날 때까지) 설정합니다. 자동 재시작을 쓰려면 종료 코드 0은 그대로 멈추고 그 밖의 코드는 다시 띄우게 합니다(`AppExit Default Restart`, `AppExit 0 Exit`).

### Linux systemd·cron

`pjguardian-worker.service`를 만들어 `ExecStart`에 `/usr/bin/php <설치 폴더>/tools/worker.php`, `ExecStop`에 같은 명령의 `--stop`을 적습니다.

- `Restart=on-failure`: 정지 요청(종료 코드 0)은 그대로 멈추고, 재시작 요청(75)·비정상 종료는 다시 띄웁니다. `Restart=always`면 정지 요청 뒤에도 다시 뜹니다.
- `TimeoutStopSec`은 진행 중인 분석을 기다릴 만큼 길게(분석 시간 상한 이상) 둡니다. 넘으면 강제 종료되고 다음 기동 때 정리됩니다.
- 정리 작업은 `pjguardian-cleanup.service`(oneshot)와 매일 실행하는 `.timer`로 등록합니다.
- cron만 쓸 때는 매분 `tools/worker.php`를 부르고(단일 인스턴스라 하나만 돔) 매일 한 번 `tools/cleanup.php`를 부릅니다.
- Linux에서는 `setsid`와 `prlimit`(util-linux)이 `/usr/bin`에 있어야 합니다.

## 관리자 화면: 워커 탭

{{ui:admin_system.title}} > {{ui:admin_worker.title}}([워커 화면](/admin/worker))은 생존 신호 파일과 큐를 읽어 보여 줍니다. 웹은 워커를 직접 실행하거나 PID로 종료하지 않습니다.

- **상태**: {{ui:admin_worker.state.running}} / {{ui:admin_worker.state.stopping}} / {{ui:admin_worker.state.stopped}} / {{ui:admin_worker.state.unresponsive}}. 응답 없음은 마지막 생존 신호가 {{limit:worker.heartbeat_stale}}보다 오래된 경우입니다. PID, 시작 시각, 마지막 신호, 동시 분석 한도, 마지막 복구, 자동 재시작 여부, Node 검사 결과도 나옵니다.
- **실행 중인 분석과 대기열**: 읽기 전용입니다. 관리자 취소 단추는 없습니다(취소는 요청한 사용자만).
- 대기 중인 분석·업로드가 있는데 워커가 없으면 붉은 경고와 시작 방법 도움말이 펼쳐집니다.
- **{{ui:admin_worker.stop}}**: 새 작업을 받지 않고 진행 중인 작업이 끝난 뒤 종료 코드 0으로 멈춥니다. 자동 재시작이 없으면 다시 시작해야 한다는 경고가 대화상자에 나옵니다.
- **{{ui:admin_worker.restart}}**: 진행 중인 작업이 끝난 뒤 종료 코드 75로 끝나고 서비스 관리자가 다시 띄웁니다. `worker.supervised`가 켜져 있을 때만 보입니다(지금 값: {{limit:worker.supervised}}).
- **{{ui:admin_worker.start}}**: config.ini의 `worker.start_command`에 적은 고정 명령 하나(사전 등록한 작업 시작)를 셸 없이 실행합니다. 설정이 비어 있거나 PHP에서 proc_open이 꺼져 있으면 숨겨지고, 이미 실행 중이면 비활성입니다. 실행 뒤 30초 동안 생존 신호를 확인해 시작됨/시작 확인 실패를 보여 줍니다. 감사 로그에는 `worker.start_request`(성공 여부·종료 코드)만 남습니다.
- **최근 로그**: 오늘 앱 로그의 마지막 {{limit:worker.log_tail_lines}}줄입니다. "{{ui:admin_worker.log_scope_worker}}"는 웹 요청이 아닌 줄만 보여 줍니다. 서버 경로·비밀값은 가려지고 원본 내려받기는 없습니다.

`worker.start_command`의 첫 단어는 절대 경로이거나 `schtasks`·`systemctl`·`sudo`여야 하고, PHP나 `tools/worker.php`를 직접 가리키면 거부합니다. 예: Windows `schtasks /run /tn PJGuardianWorker`, Linux `sudo -n systemctl start pjguardian-worker`(sudoers에 이 명령 하나만 허용). 웹 서버 계정이 그 작업을 실행할 권한이 있어야 합니다.

## 관리자 화면: 점검 탭

{{ui:admin_system.title}} > {{ui:admin_checks.title}}([점검 화면](/admin/checks))는 디스크 사용 현황, 정리 작업, 최근 실패한 분석, 환경, 설정을 읽기 전용으로 보여 줍니다. 항목별 읽는 법과 조치는 [시스템 점검과 모니터링](guide:monitoring)를 보세요. 워커 운영과 관련해서는 다음만 기억하면 됩니다.

- {{ui:admin_checks.disk_scan}}과 {{ui:admin_checks.cleanup_run}}은 워커에 요청을 넣으므로 워커가 실행 중이어야 처리됩니다. 정리로 지운 파일은 되돌릴 수 없습니다.
- 정리 작업이 "실행 기록 없음"이면 위의 정리 작업 스케줄 등록을 확인합니다.

## 워커를 다시 시작해야 하는 경우

- 마이그레이션을 적용할 때: 워커를 정지하고 적용한 뒤 다시 시작합니다.
- `config/config.ini`나 `config/secrets.ini`를 직접 고쳤을 때(예: 암호화 키 교체).
- `tools.node_path`를 바꾸거나 Node를 교체했을 때: {{ui:admin_tools.node_refresh}}는 확인만 하고, 분석이 쓰는 Node 경로는 다시 시작해야 바뀝니다.
- 설정 관리 화면에 "워커를 다시 시작해야 반영" 배너가 보일 때. 화면에서 바꾸는 설정은 대부분 워커가 다시 읽어 바로(다음 작업부터) 반영합니다.

다시 시작은 정지 요청 → {{ui:admin_worker.state.stopped}} 확인 → 시작 순서로 합니다. 자동 재시작이 켜져 있으면 {{ui:admin_worker.restart}} 하나로 됩니다.

## 장애 대응

- **분석이 계속 대기합니다**: `php tools/worker.php --status` 또는 워커 탭에서 실행 여부를 봅니다. 실행 중인데 대기하면 같은 프로젝트의 앞 분석, 동시 분석 한도, 도구 재검증 진행 여부를 확인합니다.
- **워커가 죽었습니다**: 다시 띄우면 기동하면서 실행 중으로 남은 분석을 `worker_crashed`로 실패 처리하고 남은 자식 프로세스를 종료합니다. 재시도는 없으며 사용자가 다시 올립니다. 조립·해제 중이던 업로드는 되돌려 다시 처리합니다.
- **다시 띄우지 못하는 동안**: 정리 작업이나 `--check`가 생존 신호가 {{limit:worker.heartbeat_stale}} 넘게 끊기고 잠금도 풀린 것을 확인하면 같은 정리를 합니다.
- **응답 없음**: 잠금은 잡혀 있는데 신호만 끊긴 경우입니다. 작업을 되돌리지 않고 로그에 경고만 남깁니다. 서버에서 워커 프로세스 상태를 확인하세요.
- **강제 정지**: `--force-stop`이나 두 번째 정지 신호는 진행 중인 분석을 `worker_stopped`로 실패 처리합니다.
- **메모리**: Windows에서는 분석 도구의 메모리 사용을 제한하지 않습니다. 메모리가 부족하면 동시 분석 수를 1로 낮추거나 Linux 서버를 씁니다.
- 로그는 `data/logs/app-YYYYMMDD.log`(한 줄 JSON, UTC 날짜)이고, 워커 자식의 줄에는 작업 ID가 붙습니다.

## 운영 환경에서 확인할 것

- 작업 스케줄러의 "실패하면 다시 시작"이 종료 코드 75를 실패로 보고 다시 띄우는지는 확인되지 않았습니다. 확실하지 않으면 `worker.supervised`를 끈 채 시작 단추를 쓰세요.
- 웹 서버 계정의 `schtasks /run` 권한, Linux sudoers 한 줄, NSSM·systemd 실제 등록, Windows 콘솔 Ctrl+C 정지는 운영 환경에서 확인할 것.
