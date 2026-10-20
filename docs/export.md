# 내보내기 운영 안내 (P23)

결함 목록·내게 지정된 결함·규칙 설명 목록을 CSV·Excel(.xlsx)로 내려받는다. 구조는 `docs/architecture.md` 39절.

## 1. 설정 (`config/config.ini`의 `[export]`, 기본값은 `config.ini.example`)

| 키 | 기본 | 범위 | 뜻 |
| --- | --- | --- | --- |
| `max_rows` | 50000 | 1~200000 | 한 번에 내보낼 행 수 상한. 넘으면 파일을 만들지 않고 413 `export_too_large`(건수·상한)로 거부한다(잘라서 주지 않음) |
| `max_concurrent` | 2 | 1~16 | 서버 전체 동시 내보내기 수. 넘으면 429 `export_busy`(scope = server) |
| `max_per_user` | 1 | 1~4 | 사용자당 동시 내보내기 수. 넘으면 429 `export_busy`(scope = user) |
| `timeout_seconds` | 60 | 5~600 | 파일 하나를 만드는 시간 상한. 넘으면 중단·임시 파일 삭제, 503 `export_timeout` |
| `memory_limit` | 128MB | 32MB~2GB | PHP 메모리 사용량 상한(500행마다 확인). 넘으면 중단, 503 `export_failed` |

- 잘못된 값(범위 밖·문자열)은 시작할 때 설정 오류로 거부한다(`php tools/config_check.php`).
- 상한을 올리면 시간·메모리도 함께 본다. 측정(P23, Windows 개발 PC): 50,000행 XLSX 2.5초·최대 약 30MB, CSV 1.0초. 행 수와 거의 무관하게 메모리가 일정하다(1,000행씩 읽어 파일에 바로 쓴다).
- PHP의 `memory_limit`·`max_execution_time`(php.ini)이 위 값보다 작으면 그쪽이 먼저 걸린다. 운영에서는 `memory_limit` ≥ 256M을 권장한다.

## 2. 임시 파일과 잠금

- 위치: `<paths.work_dir>/export/`(파일 이름은 무작위 24자리 + 확장자, 사용자 입력이 들어가지 않음). 동시 실행 잠금은 `export/locks/slot-N.lock`, `user-<id>-N.lock`(잠금 전용 파일, `Support\FileLock`).
- 파일은 다 만든 뒤 내려보내고 **응답이 끝나면 지운다**(성공·실패·연결 끊김 — 연결이 끊겨도 끝까지 실행해 지우고, 비정상 종료면 종료 함수가 지운다). 중단(시간·메모리)되면 쓰던 파일도 지운다.
- 비정상 종료로 남은 파일은 다음 내보내기가 1시간 넘은 것을 지운다. 정기 정리(`tools/cleanup.php`)에 넣는 것은 P30에서 한다.

## 3. 감사 로그

- `export.defects`: 대상 `project`(프로젝트 id) 또는 `mine`, 세부 = 형식·행 수·Run·적용된 조건(값만 — 결함 데이터는 넣지 않음).
- `export.rules`: 대상 `rules`(탭의 도구), 세부 = 형식·행 수·조건.
- 거부된 요청(상한 초과·0건·동시 실행·권한)은 남기지 않는다(앱 로그에는 WARN — 시간·메모리 중단).
- 앱 로그(INFO) "내보내기 파일 생성": 형식·행 수·걸린 시간·PHP 최대 메모리·파일 크기(데이터 값 없음). 상한 조정의 근거로 쓴다.

## 4. 파일

- 파일 이름: `PJGuardian_<프로젝트 이름>_결함_<yyyymmdd-HHMM>[_Run<N>].xlsx|csv`, 내 결함 `PJGuardian_내결함_…`, 규칙 `PJGuardian_rules_<python|javascript>_…`(시각 KST). 프로젝트 이름은 글자·숫자·`_`·`-`만 남기고 40자로 자른다. 헤더는 ASCII 대체 이름 + `filename*=UTF-8''…`.
- CSV: UTF-8 BOM, 쉼표, CRLF, RFC 4180 인용. `=`·`+`·`-`·`@`·탭·CR·LF로 시작하는 값 앞에 `'`(수식 주입 방지).
- XLSX: 시트 1(머리글 굵게·고정, 자동 필터, 열 너비, 숫자·날짜 형식), 시트 2 "조건"(내보낸 시각·프로젝트·적용된 조건·정렬·보기 Run·행 수, 내보낸 사람 정보 없음). 모든 문자열은 인라인 문자열 셀(수식 셀 없음).
- 셀 하나는 32,767자까지(넘으면 잘라 `…(생략)`), XML에 쓸 수 없는 제어 문자는 지운다.
