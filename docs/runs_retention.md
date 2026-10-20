# Run 상세 보관과 수동 정리 (P21B)

정의와 규칙은 `docs/architecture.md` 35절, 스키마는 `docs/db_schema.md` 5.0e를 본다. 이 문서는 운영 절차만 적는다.

## 1. 무엇이 남고 무엇이 지워지나

| 구분 | 상세 삭제 뒤 |
| --- | --- |
| 과거 Run 보기(`?run=N`: 결함 현황, Run별 상세, 결함 상세·코드) | 볼 수 없음(410 `run_detail_deleted`, 안내 화면) |
| Run 번호, 날짜, 건수(감지·신규·해결·재오픈·유지·열린 결함), 도구 버전, 버전/설정 변경 표시 | 남음(히스토리 표, 대시보드 추이, Run 선택기의 표시) |
| 결함, 결함 이력(`defect_history`), 존재 구간(`defect_runs`), 분석 행 | 남음(다른 Run의 보기와 결함 전체 이력이 함께 쓴다) |
| 디스크 용량 | **거의 줄지 않음**(Run마다 따로 저장된 것은 분석 행의 세 열뿐, `docs/results/P21B.md` 0절) |

삭제는 되돌릴 수 없는 작업으로 다룬다(화면에서 프로젝트 이름 입력으로 확인). 실제로는 표식만 남기므로, 잘못 지운 경우 서버 관리자가 DB에서 해당 분석 행의 `run_detail_deleted_at`, `run_detail_deleted_by`, `run_detail_deleted_reason`을 NULL로 되돌리면 다시 보인다(감사 로그에는 삭제 기록이 남는다).

## 2. 설정 (`config/config.ini` `[runs]`)

| 키 | 기본 | 뜻 |
| --- | --- | --- |
| `detail_keep` | 15 | 프로젝트마다 상세를 유지할 최근 Run 수. 0이면 자동 보관 끔. 1·2는 설정 오류([가정] 너무 작은 값으로 과거 Run 상세가 곧바로 사라지는 실수를 막는다) |
| `retention_batch` | 20 | 성공한 분석 하나 뒤에 자동으로 표시할 최대 Run 수. 남으면 다음 분석에서 이어서 |

설정 관리에서 바꾸면 재시작 없이 반영된다(`ConfigSchema` `restart: none` — 워커가 다음 보관 작업 때 새 값을 읽음). 이미 삭제된 Run은 `detail_keep`을 늘려도 되살아나지 않는다.

## 3. 수동 삭제 (admin)

관리자 → 시스템 관리 → **Run 관리** 탭 → 프로젝트 선택 → Run 고르기("오래된 Run 모두 선택(최근 N개 제외)" 가능, 최신 Run은 고를 수 없음) → **상세 삭제** → 프로젝트 이름 입력 → 확인. 결과와 감사 로그(`run.detail_delete`)가 남는다.

## 4. VACUUM (수동, 선택)

상세 삭제는 행을 지우지 않으므로 VACUUM으로 얻는 공간은 거의 없다. 다른 이유(프로젝트 삭제 뒤 등)로 DB 파일을 줄이려면 서비스를 멈추고 수동으로 한다. 앱이 자동으로 실행하지 않는다.

1. 워커를 멈춘다(`php tools/worker.php --stop`, 진행 중 분석이 끝날 때까지 기다림). Apache도 멈추거나 사용자에게 알린다.
2. 백업: `sqlite3 data/db/pjguardian.sqlite "VACUUM INTO 'data/backups/pjguardian-before-vacuum-<날짜>.sqlite'"`
3. `sqlite3 data/db/pjguardian.sqlite "PRAGMA wal_checkpoint(TRUNCATE); VACUUM;"` — DB 크기만큼 여유 공간이 필요하고, 실행 중에는 DB에 쓸 수 없다.
4. `php tools/check_env.php`로 점검한 뒤 Apache와 워커를 다시 시작한다.

오래된 resolved 결함의 정리와 정기 VACUUM 정책은 P30에서 다룬다.
