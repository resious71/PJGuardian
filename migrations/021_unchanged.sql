-- 021: 성공(변경 없음) — 같은 커밋·changelist면 분석 생략(P27, 명세 18.2·19.10). 020 다음에 적용한다.
--
-- "변경 없음"은 001의 analyses.no_change(= status succeeded + 플래그)를 그대로 쓴다(상태 값을 늘리지 않는다). Run 번호·통계·스냅샷 없음.
-- 1) analyses.unchanged_check_count       같은 리비전을 확인한 횟수(변경 없음 행만 1 이상. 같은 리비전이 다시 확인되면 새 행 대신 +1)
--    analyses.unchanged_last_checked_at   마지막으로 확인한 시각(UTC)
--    analyses.unchanged_base_analysis_id  그 리비전을 실제로 분석한 성공 분석(비교 기준, "Run N에서 이미 분석")
--    analyses.force_analysis              admin이 "그래도 분석"(강제)으로 만든 분석 — 변경 판단을 건너뛴다(감사 repo.fetch_now force)
--    analyses.source_key                  연결 식별 해시(종류·주소(사용자 정보 없음)·브랜치·하위 경로/depot 경로). 저장소 출처의 실제 분석·변경 없음 행
--    analyses.env_key                     분석 조건 해시(도구·버전(Node 포함)·서버 설정 해시(규칙 켜기·끄기 포함)·규칙 변경 개정·제외 패턴·대상 확장자)
--    두 키가 없으면(021 전 분석) 같은 조건인지 알 수 없으므로 생략하지 않는다(다음 실제 분석부터 기록된다).
-- 2) project_repo_connections.last_check_at, last_check_revision, last_check_result  원격 리비전 확인(가져오기 없이) 결과.
--    "마지막 분석"(가져와 분석한 시각)과 구분해 "마지막 확인"으로 표시한다. result: unchanged | changed | error
-- 3) schedule_ticks.result에 'unchanged'(도래했지만 변경 없음 — 분석 등록 없이 기록)를 더한다. CHECK를 바꿀 수 없어 표를 다시 만든다
--    (이 표를 참조하는 표가 없어 데이터·id를 그대로 옮긴다).
--
-- 기존 행: unchanged_check_count = 0, force_analysis = 0, 나머지 NULL. 기존 no_change 행은 없다(이 단계 전에는 만들지 않았다).
-- 인덱스는 추가하지 않는다: 직전 성공 분석 조회는 analyses_project_status_id, 이력·최근 행은 analyses_project_id를 그대로 탄다(P27.md EXPLAIN).
-- 되돌리기(사본에서 확인, docs/results/P27.md):
--   변경 없음 행을 지우려면 먼저 DELETE FROM analyses WHERE no_change = 1;  (Run·결함·스냅샷과 무관 — 남겨 두어도 된다)
--   ALTER TABLE analyses DROP COLUMN env_key; … DROP COLUMN source_key; … DROP COLUMN force_analysis; … DROP COLUMN unchanged_base_analysis_id;
--   … DROP COLUMN unchanged_last_checked_at; … DROP COLUMN unchanged_check_count;
--   ALTER TABLE project_repo_connections DROP COLUMN last_check_result; … DROP COLUMN last_check_revision; … DROP COLUMN last_check_at;
--   DELETE FROM schedule_ticks WHERE result = 'unchanged';  (CHECK는 넓어진 채로 남아도 무해. 019와 똑같이 하려면 같은 방식으로 다시 만든다)
--   DELETE FROM schema_migrations WHERE version = '021';   (버전은 문자열)

ALTER TABLE analyses ADD COLUMN unchanged_check_count INTEGER NOT NULL DEFAULT 0 CHECK (unchanged_check_count >= 0);
ALTER TABLE analyses ADD COLUMN unchanged_last_checked_at TEXT NULL CHECK (unchanged_last_checked_at IS NULL OR unchanged_last_checked_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE analyses ADD COLUMN unchanged_base_analysis_id INTEGER NULL REFERENCES analyses (id) ON DELETE SET NULL;
ALTER TABLE analyses ADD COLUMN force_analysis INTEGER NOT NULL DEFAULT 0 CHECK (force_analysis IN (0, 1));
ALTER TABLE analyses ADD COLUMN source_key TEXT NULL CHECK (source_key IS NULL OR (length(source_key) = 64 AND source_key NOT GLOB '*[^0-9a-f]*'));
ALTER TABLE analyses ADD COLUMN env_key TEXT NULL CHECK (env_key IS NULL OR (length(env_key) = 64 AND env_key NOT GLOB '*[^0-9a-f]*'));

ALTER TABLE project_repo_connections ADD COLUMN last_check_at TEXT NULL CHECK (last_check_at IS NULL OR last_check_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE project_repo_connections ADD COLUMN last_check_revision TEXT NULL CHECK (last_check_revision IS NULL OR length(last_check_revision) <= 64);
ALTER TABLE project_repo_connections ADD COLUMN last_check_result TEXT NULL CHECK (last_check_result IS NULL OR last_check_result IN ('unchanged', 'changed', 'error'));

CREATE TABLE schedule_ticks_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    due_at       TEXT    NOT NULL CHECK (due_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    result       TEXT    NOT NULL CHECK (result IN ('queued', 'unchanged', 'skipped_busy', 'skipped_disk', 'skipped_no_connection', 'error')),
    detail       TEXT    NULL CHECK (detail IS NULL OR length(detail) <= 60),
    analysis_id  INTEGER NULL,
    created_at   TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    UNIQUE (project_id, due_at)
);
INSERT INTO schedule_ticks_new (id, project_id, due_at, result, detail, analysis_id, created_at)
    SELECT id, project_id, due_at, result, detail, analysis_id, created_at FROM schedule_ticks;
DROP TABLE schedule_ticks;
ALTER TABLE schedule_ticks_new RENAME TO schedule_ticks;
-- 시스템 현황의 최근 기록(최신순)
CREATE INDEX schedule_ticks_created ON schedule_ticks (created_at DESC, id DESC);
