-- 022: 분석 표 인덱스 (project_id, status, no_change, id)(P27C, 사용자 결정 2026-10-08). 021 다음에 적용한다.
--
-- P27이 "성공 분석"을 세거나 고르는 쿼리에 `no_change = 0`을 더하면서 기존 analyses_project_status_id(project_id, status, id)가
-- 덮는 인덱스가 아니게 되어(no_change를 행에서 읽음) 분석 수만 건 프로젝트에서 다음 Run 번호 계산(RunTracker::nextRun)·
-- 이력 "변경 없음 숨기기" 건수가 10ms대로 느려졌다(docs/results/P27.md 8절). 이 인덱스로 다시 덮는 인덱스를 쓴다.
-- 다른 열·표·기존 인덱스는 바꾸지 않는다(기존 analyses_project_status_id는 지우지 않음 — 판단은 docs/results/P27C.md).
-- 되돌리기(사본에서 확인): DROP INDEX analyses_project_status_nochange_id; DELETE FROM schema_migrations WHERE version = '022';  (버전은 문자열)

CREATE INDEX IF NOT EXISTS analyses_project_status_nochange_id ON analyses (project_id, status, no_change, id);
