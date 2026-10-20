-- 011: Run(성공한 분석의 회차)과 결함의 존재 구간(P20R, 사용자 결정 2026-10-05)
-- 기존 행은 바꾸지 않는다. 이미 있는 분석·결함은 tools/backfill_runs.php가 채운다(docs/results/P20R.md의 적용 절차).

-- 1) Run 번호: 프로젝트 안에서 성공한 분석에만 1부터(실패·취소·대기·실행 중은 NULL). 분석 반영(DefectStore)이 성공을 기록하는
--    같은 쓰기 트랜잭션에서 부여한다(프로젝트 최댓값 + 1, 쓰기 트랜잭션이 직렬화되므로 중복·건너뜀 없음).
ALTER TABLE analyses ADD COLUMN run_number INTEGER NULL CHECK (run_number IS NULL OR run_number >= 1);
-- Run 종료 표지: 그 Run의 반영 트랜잭션이 끝날 때의 defect_history 마지막 id. 이 값보다 큰 이력은 "그 Run 뒤의 변경"이다
-- (같은 초에 일어난 수동 변경도 순서가 정확하다). 과거 Run의 상태·담당자·심각도 복원에 쓴다.
ALTER TABLE analyses ADD COLUMN run_history_mark INTEGER NULL CHECK (run_history_mark IS NULL OR run_history_mark >= 0);
-- Run 요약(그 Run에 존재한 결함의 상태별·심각도별·담당자별 건수, Run 종료 시점 값. 이후 수동 변경으로 갱신하지 않는다)
ALTER TABLE analyses ADD COLUMN run_summary TEXT NULL CHECK (run_summary IS NULL OR (json_valid(run_summary) AND json_type(run_summary) = 'object'));
CREATE UNIQUE INDEX analyses_project_run ON analyses (project_id, run_number) WHERE run_number IS NOT NULL;

-- 2) 결함의 존재 구간: 결함이 Run from_run부터 to_run까지 존재했다(to_run NULL = 지금까지 이어짐).
--    존재 = 그 Run에서 도구가 감지했거나, 분석 범위 밖이어서 직전 상태를 유지함. 범위 안 미감지·파일 삭제면 구간이 닫히고(to_run = 직전 Run),
--    다시 감지되면 새 구간이 시작된다. 줄·메시지는 Run별로 저장하지 않는다(결함 표의 마지막 감지 값).
CREATE TABLE defect_runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    defect_id   INTEGER NOT NULL REFERENCES defects (id) ON DELETE CASCADE,
    project_id  INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    from_run    INTEGER NOT NULL CHECK (from_run >= 1),
    to_run      INTEGER NULL,
    CHECK (to_run IS NULL OR to_run >= from_run)
);
-- 결함 하나에 열린 구간은 하나뿐
CREATE UNIQUE INDEX defect_runs_open ON defect_runs (defect_id) WHERE to_run IS NULL;
CREATE INDEX defect_runs_defect ON defect_runs (defect_id, from_run);
-- Run N에 존재한 결함 찾기(프로젝트 범위, 커버링)
CREATE INDEX defect_runs_project ON defect_runs (project_id, from_run, to_run, defect_id);

-- 3) 과거 Run의 값 복원: 결함·필드별로 표지 뒤의 첫 이력을 찾는다
CREATE INDEX defect_history_field ON defect_history (defect_id, field, id);
