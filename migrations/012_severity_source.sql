-- 012: 규칙 기본 심각도(P21S, 사용자 결정 2026-10-05)
-- 1) 결함 심각도의 출처  2) 일괄 채움의 자동 이력을 받도록 defect_history CHECK를 넓힌다(표를 다시 만든다, id·열·인덱스 그대로).
-- 되돌리기: docs/db_schema.md 3.8·3.9(출처 열은 그대로 두어도 이전 코드와 호환. 이력 표는 rule_default 행을 지운 뒤 001의 정의로 다시 만든다).

-- 1) 심각도 출처: none = 부여된 적 없음(not_defined), auto = 규칙 기본 심각도 매핑이 부여, manual = 사람이 바꿈(not_defined로 바꾼 것 포함).
--    분석은 신규 결함에만 auto를 넣고, 사람이 바꾸면 manual이 된다. manual은 어떤 자동 처리도 덮어쓰지 않는다.
ALTER TABLE defects ADD COLUMN severity_source TEXT NOT NULL DEFAULT 'none' CHECK (severity_source IN ('none', 'auto', 'manual'));
-- 기존 결함: 지금까지 심각도는 사람만 바꿀 수 있었다(자동 부여 없음). not_defined가 아니거나 수동 심각도 이력이 있으면 manual
UPDATE defects SET severity_source = 'manual'
 WHERE severity <> 'not_defined'
    OR id IN (SELECT defect_id FROM defect_history WHERE field = 'severity' AND kind = 'manual');

-- 2) defect_history: 자동 이력에 "규칙 기본 심각도 적용"(field = severity, reason_code = rule_default, 분석 없음)을 더한다.
--    SQLite는 CHECK를 바꿀 수 없어 새 표로 옮긴다. id를 그대로 복사하므로 Run 종료 표지(analyses.run_history_mark)가 그대로 맞는다.
--    이 표를 참조하는 표·트리거·뷰는 없다.
CREATE TABLE defect_history_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    defect_id    INTEGER NOT NULL REFERENCES defects (id) ON DELETE CASCADE,
    field        TEXT    NOT NULL CHECK (field IN ('assignee', 'severity', 'status')),
    old_value    TEXT    NULL,
    new_value    TEXT    NULL,
    -- 변경자. 자동 전환이면 NULL
    changed_by   INTEGER NULL REFERENCES users (id),
    changed_at   TEXT    NOT NULL,
    kind         TEXT    NOT NULL CHECK (kind IN ('manual', 'auto')),
    reason_code  TEXT    NULL CHECK (reason_code IS NULL OR reason_code IN ('not_detected', 'file_deleted', 'redetected', 'still_detected', 'rule_default')),
    -- 사용자가 입력한 사유 메모(선택, 명세 6.2). rule_default면 "규칙 기본 심각도 적용(매핑 …)"
    memo         TEXT    NULL CHECK (memo IS NULL OR length(memo) <= 1000),
    -- 자동 전환을 일으킨 분석
    analysis_id  INTEGER NULL REFERENCES analyses (id) ON DELETE CASCADE,
    CHECK ((kind = 'manual' AND changed_by IS NOT NULL AND reason_code IS NULL AND analysis_id IS NULL)
        OR (kind = 'auto' AND changed_by IS NULL AND field = 'status' AND reason_code IN ('not_detected', 'file_deleted', 'redetected', 'still_detected') AND analysis_id IS NOT NULL)
        OR (kind = 'auto' AND changed_by IS NULL AND field = 'severity' AND reason_code = 'rule_default' AND analysis_id IS NULL)),
    CHECK (changed_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
INSERT INTO defect_history_new (id, defect_id, field, old_value, new_value, changed_by, changed_at, kind, reason_code, memo, analysis_id)
    SELECT id, defect_id, field, old_value, new_value, changed_by, changed_at, kind, reason_code, memo, analysis_id FROM defect_history ORDER BY id;
DROP TABLE defect_history;
ALTER TABLE defect_history_new RENAME TO defect_history;
CREATE INDEX defect_history_defect ON defect_history (defect_id, id);
CREATE INDEX defect_history_analysis ON defect_history (analysis_id, reason_code);
CREATE INDEX defect_history_field ON defect_history (defect_id, field, id);
