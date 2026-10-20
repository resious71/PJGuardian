# 시스템 모니터링·오류/경고 판정·헬스체크 (P30, 명세 14·19.3)

관리자 메뉴 **시스템 모니터링**(`/admin/monitoring`, admin만, 읽기 전용), 모든 관리자 화면 위의 **오류·경고 배너**, 외부 감시 도구용 **헬스체크**(`GET /health`)는 모두
`Services\Monitoring\SystemHealth` **한 곳의 판정**을 쓴다.
화면 이름(X20): 수준 `warn` = **경고**(Warning), `fail` = **오류**(Error). 내부 키·API·`data-level`·`/health` 값(`ok/warn/fail`)과 경고 로그의 문장("장애 경고 시작/계속/해소", 기계로 읽는 로그 — 그대로)은 바꾸지 않는다. 서로 다른 화면이 같은 상태를 다르게 말하지 않게 하려는 것이다.

## 1. 카드와 데이터 출처

| 카드 | 출처(계산) | 비고 |
| --- | --- | --- |
| 워커 | `WorkerControl::status()` — 생존 신호 파일(`<work>/worker/heartbeat.json`)과 `worker.heartbeat_stale` | 점검·워커 탭, `worker.php --status`와 같은 판정(running·stopping = 살아 있음) |
| 큐 | `analyses`(queued 수, 가장 오래된 `queued_at`, 시작할 수 있는(그 프로젝트에 실행 중인 분석이 없는) 가장 오래된 대기, 실행 중 목록·Run 번호·경과) | `analysis.max_concurrent` |
| 디스크 | `EnvironmentCheck::disk()`(DB 폴더가 있는 디스크의 여유·전체) | `disk.warn_percent`(80)·`disk.block_percent`(90) 재사용 |
| 최근 실패 분석 | `analyses` failed 최근 `monitoring.recent_failures`(10)건 — 프로젝트·Run·사유 코드·시각 | 실패 문구 원문·경로 없음 |
| 백업 | 백업 상태 파일(`BackupState` — last_success·last_failure), `BackupSchedule::staleHours`(백업 화면의 "오래됨"과 같은 계산), `backup.enabled`, `BackupRunner::nextAuto()`, 백업 폴더와 DB 폴더의 `stat().dev` 비교(같은 디스크 경고) | |
| bin/ 도구 | `ToolAdmin::overview()` — 도구별 선택 버전·고정·차단·체크섬·스모크·마지막 검증 | ruff·eslint만 판정, git·p4는 표시만 |
| 주기 분석 | `ScheduleStatus::overview()` — 다음 실행 가까운 순, 연속 실패 수·마지막 오류 코드·"다음 주기 재수행 예정" | 019 전이면 확인 불가 |
| HTTPS 인증서 | `monitoring.tls_cert_file`(웹 서버가 쓰는 PEM)을 `openssl_x509_parse` | 비우면 "확인 불가(not_configured)" |
| 앱 정보 | 앱 버전, `schema_migrations` 최신 번호, PHP·SQLite 버전, OS 종류 | 설치 경로·계정 이름 없음 |

- 카드 하나의 계산이 실패하면 그 카드만 `{available:false, reason}`(error·not_configured·unreadable·invalid·not_installed)이고 앱 로그에 남는다. 화면 전체는 그대로 그린다.
- 웹 요청 안에서 프로세스를 띄우거나 큰 폴더를 훑거나 네트워크에 붙지 않는다(디스크 구분별 사용량은 점검 탭의 관리 작업).

### 화면 카드 구성 (X18)

모든 카드는 공용 틀(`_monitor_card.php`)을 쓴다(`docs/ui_conventions.md` 4절): 머리(아이콘·제목·상태 배지) → 핵심 값 → 세부(라벨-값 또는 최대 5행 표) → 바닥 줄(링크·확인 시각).

| 순서 | 카드 | 핵심 값 | 바닥 줄 링크 | 폭 |
| --- | --- | --- | --- | --- |
| 1 | 워커 | 상태(실행 중·중지…) | 워커 화면 | 1열 |
| 2 | 큐 | 대기 건수 | 워커 화면 | 1열 |
| 3 | 디스크 | 사용률(%) | 점검 화면 | 1열 |
| 4 | 백업 | 마지막 성공 후 지난 시간(없으면 "기록 없음") | 백업·복원 | 1열 |
| 5 | bin/ 도구 | 정상인 분석 도구 수 / 분석 도구 수 | 분석 도구 | 1열 |
| 6 | HTTPS 인증서 | 남은 일수(지났으면 지난 일수) | 없음 | 1열 |
| 7 | 주기 분석 | 다음 실행 예정 수 | 주기 분석 | 2열 격자에서 2열 폭 |
| 8 | 최근 실패 분석 | 실패 건수(최근 `monitoring.recent_failures`건까지) | 없음(전체 목록 화면 없음) | 2열 폭 |
| 9 | 앱 정보 | 앱 버전(배지 "정보") | 없음 | 1열 |

카드 데이터·판정·API는 P30 그대로이고, 화면 모양만 바꿨다. 표는 최대 5행만 보이고, 넘으면 "외 N건"이 붙는다(API에는 그대로 다 있음).

## 2. 오류·경고 판정(SystemHealth::judge — 순수 함수)

| 코드 | 수준 | 조건 |
| --- | --- | --- |
| `worker_down` | fail | 워커가 running·stopping이 아님(생존 신호 없음·정지·응답 없음) |
| `queue_stalled` | warn | 워커가 살아 있고, 실행 중 수 < `analysis.max_concurrent`, **시작할 수 있는** 가장 오래된 대기 분석이 `monitoring.queue_stall` **초과** |
| `disk_warn` / `disk_block` | warn / fail | 디스크 사용률 ≥ 경고 / ≥ 차단 기준 |
| `backup_failed` | warn | 마지막 실패가 마지막 성공보다 나중 |
| `backup_stale` | warn | 자동 백업이 켜져 있고 마지막 성공이 없거나 "오래됨" 기준보다 오래됨 |
| `tool_unhealthy` | warn | ruff·eslint 중 쓸 버전이 없거나, 선택 버전의 체크섬·스모크 실패·파일 바뀜 |
| `repo_fetch_failing` | warn | 주기 분석 연속 실패가 `monitoring.repo_failures`(2)회 이상인 프로젝트가 있음 |
| `cert_expiring` / `cert_expired` | warn / fail | 인증서 만료까지 `notification.cert_expiry_warn`(30일) 이하 / 이미 만료 |
| `audit_anchor_mismatch` | fail | (P31) 감사 로그가 DB 밖 기록(`logs/audit-anchor.jsonl`)과 맞지 않음 — 끝 삭제·체인 재계산·옛 DB 바꿔치기. 기록이 없으면(확인 불가) 경고하지 않음(`docs/audit_actions.md` 보존과 체인) |

- (P31) 화면 API(`/api/v1/admin/monitoring`)만 경고 목록에 `initial_admin_password`(warn)를 더한다 — 초기 비밀번호를 바꾸지 않은 활성 관리자(판정은 하드닝 점검과 같은 `UserRepository::initialPasswordPendingAdmins`). 운영 장애가 아니라 보안 설정이므로 `/health`·장애 배너(SystemHealth 판정)·관리자 이메일에는 넣지 않고, 관리자 화면 위의 별도 경고 배너로 보인다.

- 전체 상태: fail이 하나라도 있으면 `fail`, warn만 있으면 `warn`, 없으면 `ok`. 값을 못 구한 카드는 경고를 만들지 않는다.
- **큐 정체 기준 기본 15분(가정)**: 워커는 `worker.poll_interval`(수 초)마다 대기 분석을 선점하므로, 빈 자리가 있는데 시작 가능한 분석이 15분 넘게 남아 있으면 선점이 막힌 것이다(DB 잠금·워커 내부 오류). 프로젝트당 1개 규칙 때문에 기다리는 분석과 상한까지 차 있을 때는 정체로 보지 않는다(긴 분석은 `analysis.timeout`이 따로 다룬다). 설정 관리 > 모니터링에서 바꾼다.
- **인증서 만료일을 파일에서 읽는 이유**: 서비스 주소(`app.base_url`)에 TLS로 접속하는 방식은 (1) 웹 요청 안에서 자기 자신에게 연결해 작업자 프로세스를 하나 더 잡고(내장 서버·작은 Apache 설정에서 교착), (2) 개발·사내 주소가 루프백·사설 주소로 풀려 저장소 연결의 주소 규칙(`HostPolicy` — 사설·루프백 거부)을 완화하거나 예외를 만들어야 한다. 서버가 쓰는 인증서 파일을 읽으면 네트워크가 필요 없고 SSRF 규칙을 건드리지 않는다. 앞단 프록시가 TLS를 끝내면 그 인증서 파일 경로를 넣거나 비워 두고(확인 불가) 외부 감시 도구로 확인한다.

## 3. 캐시·상태 변화·경고 로그

- 판정 결과는 `<work>/monitoring/health.json`에 `monitoring.cache`(30초) 동안 캐시한다. 화면·배너·`/health`는 캐시가 지났을 때만 다시 판정하고(잠금 파일 하나로 한 번만), 화면의 "지금 새로 고침"(`?refresh=1`)은 캐시를 쓰지 않는다.
- **워커**도 `monitoring.cache`마다 판정한다 — 아무도 화면을 열지 않아도 상태 변화가 기록된다.
- 상태 변화는 `<work>/monitoring/alert_state.json`(코드별 수준·시작 시각·마지막 기록 시각)과 비교한다:
  - 새 경고·수준 바뀜 → 경고 로그 `logs/alert-YYYYMMDD.log`에 "장애 경고 시작/수준 바뀜" 한 줄
  - 같은 경고가 이어짐 → `monitoring.log_repeat`(1시간)마다 "장애 경고 계속" 한 줄
  - 풀림 → "장애 경고 해소" 한 줄
  - 로그는 한 줄 JSON(Logger, 마스킹), 내용은 코드·수준·값(백분율·분·건수·날짜)만 — 비밀·경로·사용자 이름 없음. 보존은 앱 로그와 같음(`log.error_access_retention`, 정리 작업).

## 4. 관리자 장애 이벤트와 관리자 이메일(P30B)

- 인터페이스 `Services\Monitoring\AdminAlertSink::raised(array $event)` — `{code, level, params, at}`. `SystemHealth`가 경고 하나가 **정상 → 경고(또는 오류)** 로 바뀌는 순간에만 부른다. 구현은 `LogAlertSink`(경고 로그 한 줄) 그대로다.
- **이메일은 sink가 아니라 워커의 판정 회차에서 보낸다**(P30B 결정): sink는 전이 순간에만 불리고 웹 요청의 판정에서도 불리므로, "이어지면 간격마다 다시", "복구", "한 통으로 묶기", "웹은 보내지 않음"을 만족시킬 수 없다. 그래서 `Worker::evaluateHealth`가 `SystemHealth::snapshot()` 결과 전체를 `Services\Mail\AlertMailer::tick($snap)`에 넘긴다. 판정 로직·`/health`·모니터링 API는 바뀌지 않았다.
- `AlertMailer::events($snap, $kinds)`(순수 함수)가 경고를 사건 키로 바꾼다: 코드 그대로(`worker_down`, `queue_stalled`, `disk_warn`, `disk_block`, `backup_failed`, `backup_stale`, `cert_expiring`, `cert_expired`), 도구는 `tool_verify_failed:<tool>`(도구마다), 저장소는 `repo_fetch_failed:<project_id>`(연속 실패가 기준 이상인 프로젝트마다). 사건 종류(`alert_mail.events`: worker_down·queue_stalled·disk·backup·tool·repo·cert)로 거른다.
- 상태는 DB `alert_mail_state`(031, 사건 키당 한 행). `tick`은 한 트랜잭션(BEGIN IMMEDIATE — 워커가 여럿이어도 한 곳만 같은 사건을 집음)에서:
  - 새 사건·풀렸다 다시 생김 → 행을 active로, 항목 `new`
  - 이어짐 → 마지막 발송에서 `alert_mail.resend_interval`(기본 1시간, 10분~24시간)이 지났으면 `ongoing`(그 사이 다시 감지 횟수 `suppressed_count`를 적고 0으로), 아니면 `suppressed_count`만 +1
  - 수준 오름은 다른 키(`disk_warn → disk_block`, `cert_expiring → cert_expired`)라 바로 `new`
  - 판정에서 사라진 active 행 → resolved, `alert_mail.recovery`가 켜져 있으면 항목 `recovered`(더 높은 수준 키가 active면 복구로 보내지 않음)
  - 보낼 항목은 `last_result = 'sending'`으로 표시하고, 트랜잭션 **밖에서** 한 통으로 보낸 뒤 결과 코드(`ok`·`connect`·`tls`·`auth`·`rejected`·`timeout`·`config`·`invalid`)로 바꾼다. 실패해도 `last_sent_at`은 남아 간격 뒤에 다시 시도한다(폭주 방지).
- 꺼짐(`alert_mail.enabled=false`)·설정 불완전(마이그레이션 031·메일 라이브러리·서버·보낸 사람·사용자 이름·비밀번호·받는 사람)이면 아무것도 하지 않는다(행도 만들지 않음).
- 발송: `Services\Mail\MailSender` 인터페이스, 구현 `SmtpMailSender`(PHPMailer 7.1.1로 메시지를 만들고 같은 라이브러리의 `SMTP` 클래스로 전송, 직접 만든 SMTP 없음). TLS `none`·`starttls`·`smtps`, 인증 `none`·`login`, **인증서 검증은 늘 켜짐**(끄는 옵션 없음 — 사내 CA는 OS·PHP 신뢰 저장소에), 연결 10초·전체 30초, 받는 사람 최대 20, 헤더 주입(제목·보낸 사람 이름의 제어 문자, 주소 형식) 거부. 실패는 `MailException::$reason`(위 코드)로만 — 서버 응답 원문은 로그·화면에 내지 않는다.
- 메일 내용: 제목 `[PJGuardian] 경고|오류|복구: <첫 사건>`(여러 건이면 "N건 … 외"), 본문은 사건 이름·수준·처음 감지 시각·지속 중이면 다시 감지 횟수, 확인 시각, 모니터링 주소(`app.base_url` + `/admin/monitoring`), 재발송 간격 안내. 경로·비밀·사용자 입력(프로젝트 이름 포함) 없음 — 프로젝트는 번호로만. 언어는 `alert_mail.language`.
- 받는 사람: `alert_mail.recipients`, 비우면 이메일이 있는 활성 admin 계정 전체.
- 앱 로그: 발송마다 한 줄(`관리자 이메일 발송`, 건수·결과 코드·받는 사람 수 — 주소 원문 없음). 자동 발송은 감사 로그에 남기지 않는다(시험 발송 `mail.test`, 비밀번호 `mail.secret_set`·`mail.secret_delete`만 감사).
- **한계**: 워커가 멈추면 판정도 발송도 없다. 웹 요청이 판정 만료를 보고 `worker_down`만 보조로 보내는 방식은 **하지 않았다**(웹은 메일을 보내지 않는다는 원칙, 여러 웹 요청의 중복·응답 지연 위험). 워커 정지는 `/health`(6절)를 외부 감시에 연결해 잡는다 — 관리자 가이드 "관리자 이메일 알림" 절에 적음.
- 정리: 정리 작업(`tools/cleanup.php`)이 풀린 지 `alert_mail.state_retention`(30일)이 지난 행을 지운다(`alert_mail_states_purged`).
- 모니터링 화면 앱 정보 카드에 정보 한 줄 `alert_mail`(off·setup·on) — 판정·경고에는 넣지 않는다.
## 5. 관리자 배너

- admin에게만(`App::view`가 역할을 보고 캐시된 판정을 넘김), 레이아웃 위 배너 하나: "오류·경고 N건: <첫 경고(오류 먼저)>" + "시스템 모니터링에서 보기". 장애가 있으면 오류 색, 경고만 있으면 경고 색. 닫기 없음(상태가 풀리면 사라짐).
- 일반 사용자·그룹 관리자의 HTML에는 없다(서버가 그리지 않음). 판정 계산이 실패해도 화면은 그린다(앱 로그 경고).

## 6. 헬스체크 `GET /health`

- 인증 없음. **허용 IP**(`health.allowed_ips`, 쉼표 구분, IPv4·IPv6·CIDR)에서만 응답한다. 기본값은 빈 목록 = 모두 거부(안전한 쪽 — 운영자가 감시 도구 주소를 명시적으로 넣게 함). 설정 관리 > 모니터링 탭에서 바꾸고, 잘못된 IP·CIDR은 저장 때 거부된다.
- 판정은 **접속 주소(`REMOTE_ADDR`)만** 본다. `X-Forwarded-For`·`X-Real-IP`·`Forwarded` 헤더는 믿지 않는다(누구나 위조 가능).
  - 앞단 프록시(리버스 프록시·로드밸런서)를 쓰면 앱에는 프록시 주소가 보인다. 허용 목록에 **프록시 주소**를 넣고, 프록시에서 `/health` 경로를 감시 도구 주소에만 열어 둔다(운영 환경에서 확인할 것).
- 허용 밖·빈 목록이면 **등록되지 않은 경로와 같은 404**(존재를 알리지 않음). 403이면 "있지만 막힘"을 알려 주므로 쓰지 않는다.
- 응답(고정 스키마, OpenAPI `HealthStatus`):

```json
{"status":"warn","time":"2026-10-12T12:00:00Z","checks":{
  "worker":{"alive":true,"last_seen":"2026-10-12T11:59:58Z"},
  "db":{"ok":true},
  "disk":{"used_percent":57.9,"state":"ok"},
  "backup":{"last_success_at":"2026-10-12T03:00:05Z","state":"ok"},
  "queue":{"waiting":0,"oldest_wait_seconds":null,"stalled":false}}}
```

  - `status`는 2절의 전체 상태(인증서·도구 경고도 포함)와 같다. DB가 응답하지 않으면 `fail`.
  - HTTP: `ok`·`warn` → 200, `fail` → 503(감시 도구가 상태 코드만으로 판단).
  - 비밀·경로·사용자 이름·프로젝트 이름은 넣지 않는다. 세션 쿠키를 만들지 않는다.
- 폭주 방지: 허용 IP당 분당 `health.per_minute`(60)회(넘으면 429 `health_rate_limited`, 파일 이름은 주소의 해시), 같은 본문을 `health.cache`(5초) 동안 다시 쓴다. DB는 읽기만, 판정은 캐시(30초) 공유.
- 외부 감시 도구 연결 예: `curl -s -o /dev/null -w "%{http_code}" https://<서버>/health` → 200이면 정상(경고 포함), 503이면 오류, 그 밖(404·연결 실패)은 설정·네트워크 문제.

- P32B: `/health` 본문에 `maintenance`(업그레이드 유지보수 모드, 판정에 넣지 않음). 허용 IP 판정의 주소는 신뢰 프록시를 거쳤을 때 `X-Forwarded-For`(`docs/hardening.md` 4a절).

## 7. 설정 키

| 키 | 기본값 | 화면 | 뜻 |
| --- | --- | --- | --- |
| `health.allowed_ips` | ""(모두 거부) | 설정 관리 > 모니터링(서버마다 다른 값) | `/health` 허용 주소 |
| `health.per_minute` | 60 | config.ini | 허용 IP당 분당 호출 |
| `health.cache` | 5s | config.ini | 응답 재사용 |
| `monitoring.queue_stall` | 15m | 설정 관리 > 모니터링 | 큐 정체 기준 |
| `monitoring.cache` | 30s | config.ini | 판정 캐시·워커 판정 간격 |
| `monitoring.log_repeat` | 1h | config.ini | 이어지는 경고의 로그 간격 |
| `monitoring.refresh` | 30s | config.ini | 화면 자동 갱신(탭이 보일 때만) |
| `monitoring.recent_failures` | 10 | config.ini | 최근 실패 분석 카드 건수 |
| `monitoring.repo_failures` | 2 | config.ini | 저장소 연속 실패 경고 기준 |
| `monitoring.tls_cert_file` | "" | config.ini(서버마다 다른 값) | HTTPS 인증서 파일(PEM) |
| `alert_mail.*` | 꺼짐 | 설정 관리 > 관리자 이메일(13개), 나머지 5개 config.ini | 관리자 장애 이메일(P30B) — `docs/settings.md` 3·4절 |

## 8. 성능(P30 측정, Windows, 가짜 데이터 설치본)

모니터링 API 새 판정 p95 265 ms(목표 1초), 캐시 사용 p95 21 ms, `/health` p95 22 ms — `docs/results/P30.md`.
