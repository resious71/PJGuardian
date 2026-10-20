-- 013: Run 상세 보관(P21B, 사용자 결정 2026-10-05) — 프로젝트마다 최근 runs.detail_keep(15)개 Run만 상세(과거 Run 보기)를 유지하고,
-- admin이 Run을 골라 상세를 삭제할 수 있다. 012(P21S) 다음에 적용한다.
--
-- "상세 삭제"는 표식만 남기는 정리다(docs/results/P21B.md 0절): Run마다 따로 저장된 것은 analyses의 Run 번호·종료 표지·Run 요약뿐이고,
-- 존재 구간(defect_runs)·결함 이력(defect_history)·결함 표는 다른 Run의 보기와 결함 전체 이력이 함께 쓰므로 지우지 않는다.
-- Run 번호·종료 표지·Run 요약(건수)도 히스토리 표·대시보드 추이를 위해 그대로 둔다. 삭제된 Run은 과거 Run 보기(run=N)만 막는다(410).
--
-- 되돌리기: 기존 행에는 영향이 없다(기본값 = 상세 있음). 상세를 되살리려면 세 열을 NULL로 되돌린다(지운 데이터가 없으므로 그대로 다시 보인다).
-- 열을 없애려면 ALTER TABLE analyses DROP COLUMN …(SQLite 3.35 이상, 세 열 모두)과 schema_migrations의 013 행 삭제.

-- 삭제 시각(NULL = 상세 있음), 삭제한 사용자(수동만, 자동은 NULL = system), 사유(manual = admin이 골라 삭제, retention = 자동 보관)
ALTER TABLE analyses ADD COLUMN run_detail_deleted_at TEXT NULL
    CHECK (run_detail_deleted_at IS NULL OR run_detail_deleted_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE analyses ADD COLUMN run_detail_deleted_by INTEGER NULL REFERENCES users (id);
ALTER TABLE analyses ADD COLUMN run_detail_deleted_reason TEXT NULL
    -- IS 비교: 사유가 NULL일 때 = 비교는 NULL이 되어 CHECK를 통과해 버린다
    CHECK ((run_detail_deleted_reason IS NULL AND run_detail_deleted_at IS NULL AND run_detail_deleted_by IS NULL)
        OR (run_detail_deleted_reason IS 'manual' AND run_detail_deleted_at IS NOT NULL AND run_detail_deleted_by IS NOT NULL AND run_number IS NOT NULL)
        OR (run_detail_deleted_reason IS 'retention' AND run_detail_deleted_at IS NOT NULL AND run_detail_deleted_by IS NULL AND run_number IS NOT NULL));
