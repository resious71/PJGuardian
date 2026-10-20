# 소스 저장소 연동 (P25, 명세 18)

프로젝트에 Git(GitHub·GitHub Enterprise·사내 HTTP Git) 또는 Perforce를 연결하고, 관리자가 "지금 가져와 분석"으로 최신 소스를 가져와 분석한다.
주기 분석은 P26, "성공(변경 없음)"·같은 커밋 생략은 P27이다. 구조는 `docs/architecture.md` 41절, 표는 `docs/db_schema.md` 3.17.

## 1. 보안 모델

| 위협 | 대책 |
| --- | --- |
| 자격 증명 노출 | AES-256-GCM 암호화 저장(행마다 nonce, 프로젝트·종류를 AAD로), 키는 DB 밖(`config/secrets.ini`의 `encryption_key` 또는 환경 변수 `PJG_ENCRYPTION_KEY`). 응답에는 "설정됨/미설정"만. 로그는 비밀 값·Authorization·URL 사용자 정보를 가린다. 감사 로그에는 "변경됨(set·replaced·cleared·reencrypted)"만 |
| 명령행·프로세스 목록 노출 | Git 토큰은 `GIT_CONFIG_COUNT/KEY/VALUE` 환경 변수의 `http.<저장소 주소>.extraHeader`(Authorization: Basic)로만. Perforce 티켓은 `P4PASSWD` 환경 변수, 비밀번호는 `p4 login` 표준 입력. 명령은 셸 없이 인자 배열 |
| SSRF(내부망·메타데이터 접근) | 허용 호스트 목록에 있는 호스트만. 이름을 풀어 판정한 주소로 고정(Git `http.curloptResolve`, Perforce P4PORT를 주소로) — DNS 리바인딩 방지. 링크 로컬·메타데이터(169.254.169.254, fd00:ec2::254, 100.100.100.200)·미지정·멀티캐스트는 항상 거부, loopback은 개발용 예외(`repository.allow_local_dev`)일 때만. 리다이렉트를 따라가지 않음(`http.followRedirects=false`). 비표준 숫자 IP 표기(0x7f.1, 2130706433, 0177.0.0.1, 127.1) 거부 |
| 평문 전송 | HTTP는 허용 목록에 "HTTP 허용"으로 등록한 호스트만, 화면에 경고. Perforce 비SSL도 경고 |
| 저장소 내용이 코드를 실행 | hook 끔(`core.hooksPath`·`init.templateDir` = 빈 폴더), submodule 재귀 끔, LFS·외부 필터 비움(`filter.lfs.*`), fsmonitor 끔, `protocol.allow=never` + https(·http), file·ext 프로토콜 금지, 작업 트리를 만들지 않는 bare 얕은 복제 → `git archive` |
| 사용자·시스템 설정 개입 | `GIT_CONFIG_NOSYSTEM=1`(MinGit 번들 `etc/gitconfig`가 설치된 Git for Windows 설정을 include·credential.helper를 켬 — X09), 빈 임시 `HOME`·`GIT_CONFIG_GLOBAL`·`XDG_CONFIG_HOME`, `GIT_CEILING_DIRECTORIES`, 최소 PATH. p4는 `P4CONFIG`를 없는 이름으로(빈 값은 Windows에서 사라져 레지스트리 값이 쓰임), `P4ENVIRO`·`P4TICKETS`·`P4TRUST`는 임시 폴더(0600, 끝나면 삭제) |
| Perforce 서버 위장 | SSL 서버 지문을 관리자가 확인해 입력한 값만 `p4 trust -i`로 등록. 미등록·불일치는 `repo_tls_untrusted`(연결 테스트가 서버 지문을 보여 줌 — 자동 신뢰 없음) |
| 가져온 소스 | 업로드와 같은 안전 해제(SafeUnzip): 제외 대상, 실제 바이트 기준 10GB·100만 파일, 경로 이탈·심볼릭 링크·Windows 이름·대소문자 충돌, 설정 파일 정책(5.3). 대상 확장자 0개면 실패(`repo_empty`) — 기존 결함이 일괄 resolved되지 않음 |
| 웹 요청 점유 | 가져오기는 워커가(같은 큐·FIFO·동시 분석 한도). 웹에서는 연결 테스트만 짧게(30초, admin, 분당 6회) |
| 실행 파일 변조 | `bin/`의 git·p4만, ToolRegistry 체크섬(SHA256SUMS)·스모크(`git --version`, `p4 -V`) 통과 버전만 |

## 2. 설정

`config/config.ini`의 `[repository]`:

| 키 | 기본 | 뜻 |
| --- | --- | --- |
| `fetch_timeout` | 30m | 가져오기 시간 상한(분석 시간과 별도, 연결별 재정의 가능 1~1440분) |
| `test_timeout` | 30s | 연결 테스트 시간 상한(웹 요청) [가정] |
| `test_per_minute` | 6 | 사용자당 분당 연결 테스트 횟수 [가정] |
| `allow_local_dev` | false | 개발용 예외: 등록한 loopback에도 접속. **운영은 false**, 켜면 WARN 로그 |
| `ca_cert_file` | (비움) | 사내 CA 인증서(Git `http.sslCAInfo`). 검증 끄기는 지원하지 않는다 |

시크릿(`config/secrets.ini` 또는 환경 변수): `encryption_key`(현재 키), `encryption_key_previous`(교체 중 이전 키).

## 3. 암호화 키 — 만들기·교체·분실

- **만들기**: `php tools/make_secret_key.php`(기본 `config/secrets.ini`, `--out=`으로 다른 파일). 키 값은 화면·로그에 출력하지 않고 파일에만 쓴다(Linux 0600). `public/` 안은 거부. 키가 없으면 자격 증명 저장은 503 `repo_secret_key_missing`(자동 생성하지 않는다).
- **교체**: `php tools/make_secret_key.php --rotate` → 새 키가 `encryption_key`, 이전 키가 `encryption_key_previous`. 이전 키로도 풀리므로 바로 동작하고, 관리자가 각 연결을 **저장**하면 새 키로 다시 암호화된다(감사 `auth_change: reencrypted`). 모든 연결을 다시 저장한 뒤 `encryption_key_previous`를 지운다. 웹 서버·워커를 다시 시작해 새 시크릿을 읽게 한다.
- **분실**: 저장된 자격 증명은 **복구할 수 없다**. 연결 화면에 "읽을 수 없음"이 보이고 가져오기는 409 `repo_secret_unreadable`. 새 키를 만들고 각 연결의 자격 증명을 다시 입력한다.
- 키 파일은 DB 백업과 **따로** 보관한다(같이 잃거나 같이 유출되지 않게).

## 4. git·p4 실행 파일

`bin/git/<버전>/{windows,linux}/`, `bin/p4/<버전>/{windows,linux}/` + 버전 폴더의 `SHA256SUMS`(X09, `docs/vcs_tools.md`). 진입점: Windows MinGit `windows/cmd/git.exe`, Linux git `linux/bin/git`(운영 서버에서 `tools/build_git_linux.sh`로 빌드), p4 `{os}/p4(.exe)`.
관리자 "시스템 관리 > 분석 도구"에 읽기 전용으로 보인다(버전·체크섬·스모크). 새 버전 교체·매니페스트 갱신은 `docs/vcs_tools.md` 3절.

## 5. 허용 호스트

"시스템 관리 > 설정 관리 > 저장소 연결" 탭의 허용 호스트 구역(admin, X12 — 옛 주소 /admin/repo-hosts는 그 탭으로 이동). 호스트(이름 또는 IP, 소문자·후행 점 없음으로 정규화)·포트(0 = 모든 포트)·HTTP 허용·GitHub 호환(커밋 링크)·메모. 변경은 감사 `repo_host.add|update|delete`.
연결을 저장할 때 호스트가 목록에 없으면 400 `repo_host_not_listed`(화면이 등록 위치를 안내). 접속 때마다 다시 판정한다(목록에서 지우면 그 연결은 가져오지 못함).

## 6. 오류 코드

분석 실패(`analyses.failure_code`, 가이드 키 `analysis.failure.<코드>`): `repo_auth_failed`, `repo_unreachable`, `repo_not_found`, `repo_timeout`, `repo_limit_exceeded`, `repo_host_not_allowed`, `repo_tls_untrusted`, `repo_tool_unavailable`, `repo_empty`, `repo_not_configured`, `repo_secret_unreadable`, `repo_fetch_failed`, `repo_unsafe_content`, (기존) `insufficient_storage`. 문구·조치는 `docs/error_codes.md` 2절. 원문 stderr는 서버 로그에만(마스킹 후).

## 7. 개발 DB 적용 순서

1. 워커를 멈추고 DB를 백업한다.
2. `php tools/migrate.php` → 018 적용, `--status`로 확인(017이 아직이면 017부터).
3. `php tools/make_secret_key.php`로 키를 만든다(이미 있으면 그대로).
4. 관리자 화면 "시스템 관리 > 설정 관리 > 저장소 연결"의 허용 호스트에 사내 Git·GitHub Enterprise·Perforce 호스트를 등록한다(HTTP면 "HTTP 허용", GHE면 "GitHub 호환").
5. 워커·웹 서버를 다시 시작한다(새 시크릿·코드). 관리자 "분석 도구"에서 git·p4가 보이는지 확인(처음 사용할 때 체크섬·스모크 검증).
6. 프로젝트 편집 화면 "소스 저장소"에서 연결 → 연결 테스트 → 저장 → 프로젝트 화면 "지금 가져와 분석".

## 9. 주기 분석 (P26)

- **설정**: 프로젝트 편집 > "주기 분석"(admin). 사용 여부, 간격(1~365일, 권장 7일), 실행 시각(KST, 24시간제 HH:MM). 저장소 연결이 있고 허용 호스트에 있으며 자격 증명을 읽을 수 있어야 켤 수 있다.
  마지막 연결 테스트·가져오기가 실패였거나 확인 전이면 저장하면서 경고한다. 연결을 해제하면 주기는 자동으로 꺼지고 감사 로그(`schedule.auto_disable`)가 남는다.
- **일정 계산**: 켜거나 간격·시각을 바꾸면 바꾼 시점 이후 가장 가까운 지정 시각에 첫 실행(소급하지 않음), 그 뒤 N일마다 같은 시각. KST는 서머타임이 없어 월말·연말에도 같은 시각.
- **스케줄러**: 별도 프로세스 없이 워커가 1분(`scheduler.tick_seconds`)마다 확인해 큐에 넣는다. **워커가 꺼져 있으면 등록되지 않는다** — 다시 켜면 밀린 일정은 가장 최근 1회만 실행하고 다음 시각은 현재 이후로.
  그 프로젝트에 대기·실행 중 분석이 있으면 그 회차는 건너뛴다(이력에 분석 행 없음, 현황의 "건너뜀"). 디스크 사용률 차단(90%)·여유 공간 부족·연결 문제도 건너뜀으로 기록하고 관리자에게 알린다.
- **실패와 재시도**: 저장소 접속 불가·시간 초과·분류할 수 없는 가져오기 실패는 같은 분석을 5분(`repository.connect_retry_interval`) 뒤 다시 시도, 3번째(`repository.connect_max_attempts`)도
  실패하면 failed 1건으로 끝내고 다음 주기에 다시 수행한다(주기 설정 유지). 인증 실패·허용 호스트 아님·도구 없음·SSL 신뢰 안 됨·저장소/경로 없음·한도 초과·대상 0개는 바로 실패.
  재시도를 기다리는 동안 워커는 다른 분석을 처리하고, 그 프로젝트의 "지금 가져와 분석"은 409(진행 중)다. 대상 파일이 0개면 실패로 끝나 기존 결함이 일괄 해결되지 않는다.
- **알림**: 주기 분석 실패는 활성 관리자 전체에게 앱 안 알림 "주기 분석 실패"(관리자 전용 — 일반 사용자에게는 만들지도 보이지도 않음, 알림 설정에서 끌 수 있음). 같은 프로젝트의 연속 실패는
  한 건에 횟수가 늘어난다(읽음 상태 유지). 완료 알림은 보내지 않는다. 담당자의 "새 결함·해결 요약"은 업로드 분석과 같다. 이메일은 보내지 않는다(관리자 장애 이메일은 이후 단계).
- **현황**: 시스템 관리 > 주기 분석 — 스케줄러 마지막 확인(간격의 5배 넘게 확인이 없으면 지연 경고), 프로젝트별 일정(다음 실행·마지막 결과·연속 실패·마지막 오류·건너뜀),
  최근 실패(다음 주기 재수행 예정 포함)·재시도 대기, 최근 건너뜀. 프로젝트 목록·화면에 주기 표시(일반 사용자는 사용 중·다음 예정만, 관리자는 연속 실패 경고도).
- **연속 실패 대응**: 현황의 마지막 오류를 보고 — 인증이면 토큰·비밀번호를 다시 입력, 접속 불가·시간 초과면 서버·네트워크(프록시) 확인 뒤 프로젝트 화면 "연결 테스트"·"지금 가져와 분석"으로 확인
  (실제 분석이 성공하면 연속 실패 0 — "변경 없음"은 연속 실패를 늘리지도 끊지도 않는다, P27C). 자동으로 주기를 끄지는 않는다.
- **운영 권장**: 같은 시각에 많은 프로젝트를 몰지 않는다(설정 화면이 같은 시각 예약 수를 알려 준다). 동시 분석은 2개라 몰리면 사용자 업로드가 기다린다(명세 19.8).
- **개발 DB 적용 순서**: 워커 정지 → DB 백업 → `php tools/migrate.php`(019 적용, `--status` 확인) → 워커·웹 서버 다시 시작(워커는 이제 `--config`를 줘도 같은 폴더의 `secrets.ini`를 읽는다) →
  시스템 관리 > 주기 분석에서 "마지막 확인"이 1분 안에 바뀌는지 확인 → 프로젝트 편집 > 주기 분석에서 켜기.

## 10. 허용 호스트 연결 테스트 (P26B)

시스템 관리 > 설정 관리 > 저장소 연결의 허용 호스트 표 행별 **"연결 테스트"**와 표 위 **"전체 테스트"**(한 번에 최대 `repository.host_test_max` 20개, 나머지는 안내). admin만, CSRF, 분당 횟수는 저장소 연결 테스트와 공유(`repository.test_per_minute`, 전체 테스트는 1회로 센다). **저장소·자격 증명 없이 호스트 자체만** 본다.

| 단계 | 내용 | 시간 상한 |
| --- | --- | --- |
| 1. 이름 풀이·허용 판정 | 풀린 주소 전부와 분류(공인·사설·loopback·금지)를 보여 주고, 금지 주소로 하나라도 풀리면 **접속하지 않는다**. 이후 접속은 판정한 첫 주소로 고정 | — |
| 2. TCP | 행의 포트. 0이면 https 443과 "HTTP 허용"이면 80 | `host_test_tcp_timeout` 5초 |
| 3. TLS | 443·그 밖의 포트: 인증서 만료일·남은 일수·발급자·이름 일치, 신뢰(`repository.ca_cert_file`이 있으면 그 파일만 — git의 `http.sslCAInfo`와 같은 규칙, 없으면 PHP 기본 CA). 80·443이 아닌 포트에서 TLS가 안 되면 평문 포트(Perforce 등)로 보고 TCP만 | `host_test_tls_timeout` 5초 |
| 4. HTTP | 포트 0·80·443 또는 "HTTP 허용"·"GitHub 호환" 행: 인증 없이 `HEAD /`(405·501이면 `GET /`)의 **상태 줄만** 읽고 닫는다. 리다이렉트를 따라가지 않고 본문·쿠키를 쓰지 않는다. 401·403은 "응답함(인증 필요)" = 정상 | `host_test_http_timeout` 10초 |
| 5. Perforce 지문 | HTTP가 아닌 TLS 포트: 공개 키 SHA-1(콜론 구분 대문자 20바이트 — p4 trust 형식, [가정] 실제 p4d로 미확인)과 인증서 SHA-256을 보여 준다. **자동으로 신뢰하지 않는다**(연결 설정의 지문 칸에 관리자가 확인한 값만) | — |

결과 코드와 해결 안내:

| 코드 | 뜻 | 안내 |
| --- | --- | --- |
| `ok` | 정상. 상태 줄이 오면 도달 성공: 2xx·3xx(따라가지 않음)·**407을 뺀 모든 4xx**(P27C). 401·403은 "응답함(인증 필요)", 그 밖의 4xx(404·410·429 등)는 "응답함(루트 경로에 페이지가 없습니다 — 저장소 주소는 연결 테스트로)" 안내(`http.note` = auth·no_page) | — |
| `warn_cert_expiring` | 인증서 만료가 `notification.cert_expiry_warn`(30일) 안 — 표에서도 경고 | 갱신 요청 |
| `fail_dns` | 이름이 풀리지 않음 | 호스트 이름 확인 |
| `fail_blocked_address` | 링크 로컬·메타데이터·(개발 예외가 꺼진) loopback으로 풀림 — 접속 안 함 | 허용되지 않는 주소 |
| `fail_connect` | TCP 거부·시간 초과, TLS 핸드셰이크 실패·시간 초과 | 방화벽·**프록시**·포트 확인 |
| `fail_tls_untrusted` | 신뢰할 수 없는 인증서(자체 서명·사내 CA 미등록) | `[repository] ca_cert_file`에 사내 CA |
| `fail_tls_expired` | 만료(또는 아직 시작 전) | 갱신 요청 |
| `fail_tls_name_mismatch` | 인증서 이름 ≠ 등록한 호스트 | 인증서에 있는 이름으로 등록 |
| `fail_http_status` | 5xx, 407(프록시 인증 필요, `http.note` = proxy) | 주소·서버 상태·중간 프록시 |

- 결과는 행에 저장(`last_test_*`, `cert_not_after`, `cert_issuer`)하고 표의 "마지막 테스트"·"인증서 만료" 열에 아이콘 + 글자로. 프로젝트의 "연결 상태"(저장소 연결 테스트·가져오기)와 **별개**다. 감사 `repo.host_test`(호스트·코드).
- **사내 프록시는 만들지 않았다**(필요 확인 전). 프록시 없이 접속이 안 되는 호스트는 `fail_connect`로 드러난다 — 그런 호스트가 있으면 `repository.http_proxy` 후속을 요청한다.
- 등록되지 않은 호스트·임의 주소를 테스트하는 기능은 없다(먼저 허용 목록에 추가).

## 11. 저장소 심볼릭 링크 건너뛰기 (P26B)

- 저장소에서 가져온 소스의 심볼릭 링크(Git 모드 `120000`, Perforce `symlink` 유형)는 **따라가지도 파일로 만들지도 않고 건너뛴다**. 분석 대상·파일 수·크기에 넣지 않고 링크 대상(내용)은 읽지 않는다.
- 건너뛴 개수와 처음 20개 경로를 분석 경고(`analyses.source_warnings`)로 남기고 분석 상세에 "심볼릭 링크 N개를 건너뛰었습니다"로 보인다. 주기 분석도 확인 없이 계속한다. 링크만 있어 대상 파일이 0개면 여전히 `repo_empty`.
- 구현: Git은 `git archive`가 만든 zip에서 링크 항목(항목 속성 — 내용은 읽지 않음)과 그 때문에 비게 된 폴더 항목을 해제 **전에** 지운다(`RepoImport::dropSymlinks`). Perforce는 `p4 print`의 symlink 유형을 쓰지 않는다. 안전 해제기(`SafeUnzip`)의 규칙은 그대로다.
- **안전 규칙은 그대로 실패**(`repo_unsafe_content`): 경로 이탈(`..`·절대 경로·드라이브 — Perforce도 이제 건너뛰지 않고 실패), 대소문자만 다른 이름 충돌, Windows 금지 이름, 너무 긴 경로, 중첩 zip.
- **업로드(zip)의 심볼릭 링크 규칙은 바뀌지 않는다**(거부).

## 12. P26B 개발 DB 적용 순서

1. 워커를 멈추고 DB를 백업한다.
2. `php tools/migrate.php` → "적용함: 020"(`repo_allowed_hosts` 테스트 결과 열 5개, `analyses.source_warnings`). `--status`로 확인.
3. 워커·Apache를 다시 시작한다.
4. 시스템 관리 > 설정 관리 > 저장소 연결에서 "전체 테스트" → 결과·인증서 만료일 확인. 사내 CA가 신뢰되지 않으면 `config.ini`의 `[repository] ca_cert_file`(서버 관리자가 설정).

## 13. 성공(변경 없음) — 같은 커밋·changelist면 분석 생략 (P27)

명세 18.2 "직전 성공 분석과 같은 커밋 또는 changelist이면 분석을 생략하고 이력에 변경 없음으로 기록". 상태 값은 늘리지 않고 `succeeded` + `analyses.no_change = 1`(001부터 있던 열)이다.

**판단 시점**(`Services\Repo\UnchangedSourceCheck::evaluate`, 두 호출 지점이 같은 함수):

| 경로 | 언제 | 변경 없음이면 |
| --- | --- | --- |
| 주기 분석 | `ScheduleTicker`가 쓰기 트랜잭션 **밖에서** 판단 → `ScheduledAnalysisLauncher::launch`가 트랜잭션 안에서 기준이 그대로인지 다시 확인 | 큐에 넣지 않고 바로 기록(`schedule_ticks.result = unchanged`). 가장 최근 분석 행이 같은 리비전·같은 기준·같은 연결·조건의 변경 없음이면 **그 행의 확인 횟수·마지막 확인만 갱신**(새 행 없음) |
| 지금 가져와 분석 | 웹 요청은 원격을 부르지 않고 행만 만든다(P25 그대로). 워커가 분석을 집은 직후·**가져오기 전에** 분석 자식(`tools/run_analysis.php`)이 판단 | 그 행을 succeeded + no_change로 마감(가져오기·도구·결함·Run·알림 없음). 화면이 지켜보다가 "변경이 없습니다. 그래도 분석할까요?" → 확인하면 `force: true`로 다시 요청 |

**같다는 기준**(하나라도 다르거나 알 수 없으면 분석):

| 비교 | 값(기준 = 직전 성공 분석, `no_change = 0`, 상세 보관 안) | 출처 |
| --- | --- | --- |
| 리비전 | `analyses.source_revision` ↔ 원격 최신(Git 브랜치 헤드 `ls-remote`, Perforce `changes -m 1 -s submitted <경로>`) | P25 열 + `RepoHead`(연결 테스트와 같은 호출·허용 호스트·주소 고정·검증된 도구·`repository.test_timeout`) |
| 연결 | `analyses.source_key` = SHA-256(종류, 주소(사용자 정보 없음)/P4PORT, 브랜치, 하위 경로/depot 경로) | 021 새 열 |
| 분석 조건 | `analyses.env_key` = SHA-256(도구, 선택될 버전 라벨(ESLint는 Node 포함), 서버 설정 해시(`RuffRunner::configHash`/`EslintRunner::configHash` + 관리자 규칙 켜기·끄기 실효 집합), `rule_override_state.revision`, 제외 패턴(기본 + 프로젝트), 대상 확장자) | 021 새 열, `AnalysisEnvKey` — 분석 행의 `config_hash`는 저장소 안 ruff 설정 내용이 들어가 가져오기 전에는 알 수 없어서 서버 쪽 입력만 따로 묶었다(같은 리비전이면 저장소 설정도 같다) |

- 처음 분석·직전 성공이 변경 없음뿐·직전 성공이 업로드·021 전에 끝난 분석(키 없음)·기준 상세가 보관 정리로 삭제됨 → 분석. 직전이 실패여도 직전 **성공**과 비교한다.
- 원격 조회 실패(도달 불가·인증·타임아웃·허용 호스트)는 변경 없음이 **아니다**: 평소처럼 등록되고, 가져오기가 같은 오류로 P26 재시도·실패를 기록한다. 판단 중 예외도 분석(경고 로그).
- 업로드 분석은 판단하지 않는다(항상 분석). 주기 분석에는 강제 옵션이 없다.

**Git 하위 경로 한계**: 하위 경로를 정해도 변경 여부는 **브랜치 헤드 커밋**으로 판단한다. 하위 경로 밖만 바뀌어도 헤드가 바뀌므로 다시 분석한다(불필요한 분석이 생길 수 있음 — 연결 설정 화면 안내 문구). Perforce는 경로 기준 changelist라 정확하다.

**강제 분석**: `POST /api/v1/projects/{id}/repo/fetch` 본문 `{"force": true}`(admin 전용 라우트, 불리언이 아니면 400). `analyses.force_analysis = 1`, 분석 자식이 판단을 건너뛴다. 감사 `repo.fetch_now`에 `force: true`. 확인 대화상자에서 취소하면 아무것도 더 만들지 않는다(이미 생긴 "변경 없음" 행 하나는 남는다 — 판단을 워커가 하므로).

**제외 지점**(변경 없음은 분석이 아니다): 비교 기준(`DefectStore`), Run 번호·되채움(`RunTracker::ready`·`nextRun`, `RunService::status`, `RunBackfill`), 히스토리 이전·다음·비교(409 `analysis_unchanged`), 대시보드 추이·7일 분석 수·분석 있는 프로젝트 수·도구 버전, 파일 목록 보관(`SnapshotService`), Run 보관(Run 번호로만 세고 워커는 변경 없음에 보관 정리를 부르지 않음), 최근 성공(`ProjectRepository::latestSuccess`)·연결 상태의 마지막 분석, 규칙 설정의 업로드 ruff 설정 프로젝트 수, 알림(아무것도 만들지 않음). 내보내기·Run 선택·상위 10은 Run 번호·Run 요약 기준이라 원래 들어가지 않는다.

**실패 상태와의 관계**(P27C, 사용자 결정 2026-10-08): 변경 없음은 실패 상태를 지우지 않는다. 대시보드 "마지막 분석이 실패한 프로젝트"와 프로젝트 화면 "최근 분석" 카드는 변경 없음 행을 건너뛰고 마지막 **실제** 분석으로 판정한다 — 실패 뒤 변경 없음 확인이 몇 번 있어도 실패로 남고, 실제 분석(강제 분석 포함)이 성공해야 빠진다. 주기 분석의 연속 실패·마지막 결과·`schedule_failed` 알림(중복 키·읽음 상태)에도 중립. 이력·분석 상세·분석 탭은 변경 없음 행을 그대로 보여 준다.
실패 뒤 변경 없음이 나오는 경로: 직전 성공과 **같은 헤드**에서 실패한 경우뿐이다 — 가져오기 일시 오류(접속 불가·시간 초과·재시도 3번 실패), 같은 커밋의 강제 분석 실패, 워커 비정상 종료 등. 새 커밋을 가져와 분석하다 실패했으면 다음 확인의 헤드는 기준(직전 성공)과 달라 **다시 분석**한다(변경 없음이 아님). 헤드가 기준 리비전으로 되돌아간 경우(되돌리기·강제 푸시)도 변경 없음이다.

**이력 접기**: 주기 분석은 같은 리비전이 계속 확인되면 한 행에 `unchanged_check_count`·`unchanged_last_checked_at`을 올린다(이력이 주기마다 늘지 않음). 이력 화면은 "성공(변경 없음)" 배지 + "N회 확인 · 마지막 확인 yy-mm-dd HH:mm" + "기준 Run N" 링크, 필터 "변경 없음 숨기기"(`?unchanged=hide`). 프로젝트 화면은 "마지막 분석한 커밋"과 따로 "마지막 확인"(원격 리비전만 본 시각·결과)을 보인다. 연결을 바꾸면 마지막 확인은 지운다.

## 14. P27 개발 DB 적용 순서

1. 워커를 멈추고 DB를 백업한다.
2. `php tools/migrate.php` → "적용함: 021"(`analyses` 열 6개, `project_repo_connections` 열 3개, `schedule_ticks` 다시 만들기). `--status`로 확인.
3. 워커·Apache를 다시 시작한다. 021 전에 끝난 저장소 분석에는 연결·조건 키가 없으므로 **다음 한 번은 같은 커밋이어도 분석**한다(그 분석부터 생략 판단).
4. 되돌리기: `docs/results/P27.md` 2절(사본에서 확인).

## 8. 라이선스(배포 전 확인 필요)

`bin/`의 git(GPL-2.0)·p4(Perforce 사용 약관)를 제품 패키지에 넣어 배포할지는 `THIRD_PARTY_NOTICES`와 `docs/vcs_tools.md` 6절의 확인 사항을 따른다(이 문서는 법적 판단을 하지 않는다).
