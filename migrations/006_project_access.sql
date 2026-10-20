-- P11B: 프로젝트 접근 매핑(명세 3장 3.1, 19.10)
--
-- - 프로젝트를 그룹 또는 사용자에 매핑한다. 볼 수 있는 사람 = admin + 직접 매핑된 사용자 + 매핑된 그룹의 구성원.
--   매핑이 하나도 없으면 admin만 본다(기본 비공개). 이 마이그레이션은 기존 프로젝트에 매핑을 만들지 않는다.
-- - 접근 단계는 하나(조회 전용 등 단계 없음).
-- - subject_id는 subject_type에 따라 groups.id 또는 users.id를 가리킨다. 외래 키 하나로 표현할 수 없어 트리거로 보장한다:
--   삽입 때 대상이 없으면 거부, 그룹을 지우면 그 그룹의 매핑도 지운다(사용자는 삭제하지 않는다, users_no_delete).
-- - 행은 바꾸지 않는다(추가·삭제만). 대상 변경은 삭제 후 추가로 한다.

CREATE TABLE project_access (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id    INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    subject_type  TEXT    NOT NULL CHECK (subject_type IN ('group', 'user')),
    subject_id    INTEGER NOT NULL,
    created_at    TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    created_by    INTEGER NULL REFERENCES users (id),
    UNIQUE (project_id, subject_type, subject_id)
);

-- 대상 쪽 조회: "이 사용자(또는 이 그룹)가 볼 수 있는 프로젝트", 그룹 삭제 확인의 매핑 수
CREATE INDEX project_access_subject ON project_access (subject_type, subject_id, project_id);

CREATE TRIGGER project_access_subject_exists BEFORE INSERT ON project_access
WHEN (NEW.subject_type = 'group' AND NOT EXISTS (SELECT 1 FROM groups WHERE id = NEW.subject_id))
  OR (NEW.subject_type = 'user' AND NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.subject_id))
BEGIN
    SELECT RAISE(ABORT, 'project_access_subject_missing');
END;

CREATE TRIGGER project_access_no_update BEFORE UPDATE ON project_access
BEGIN
    SELECT RAISE(ABORT, 'project_access_rows_are_immutable');
END;

CREATE TRIGGER groups_delete_project_access AFTER DELETE ON groups
BEGIN
    DELETE FROM project_access WHERE subject_type = 'group' AND subject_id = OLD.id;
END;
