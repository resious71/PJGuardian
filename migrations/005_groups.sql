-- P11A: 사용자 그룹과 그룹 관리자(명세 3장 3.1, 19.10)
--
-- - 그룹은 한 단계(계층 없음). 이름은 대소문자를 무시한 유일 값(1~100자), 설명 2000자 이하.
-- - 구성원은 일반 구성원 또는 그룹 관리자(is_manager = 1). 그룹 관리자는 시스템 역할이 아니라 그룹별 지정.
-- - admin(users.role)은 모든 그룹의 관리자로 간주하며 구성원 행을 만들지 않는다(서비스가 막음).
-- - 그룹을 지우면 구성원 행도 함께 지운다(CASCADE). 사용자는 삭제하지 않으므로 사용자 쪽 연쇄는 두지 않는다.
-- - 프로젝트 매핑(project_access)은 P11B가 추가한다.

CREATE TABLE groups (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL UNIQUE COLLATE NOCASE CHECK (length(name) BETWEEN 1 AND 100),
    description  TEXT    NOT NULL DEFAULT '' CHECK (length(description) <= 2000),
    created_at   TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    created_by   INTEGER NULL REFERENCES users (id),
    updated_at   TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

CREATE TABLE group_members (
    group_id    INTEGER NOT NULL REFERENCES groups (id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users (id),
    is_manager  INTEGER NOT NULL DEFAULT 0 CHECK (is_manager IN (0, 1)),
    added_at    TEXT    NOT NULL CHECK (added_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    added_by    INTEGER NULL REFERENCES users (id),
    PRIMARY KEY (group_id, user_id)
) WITHOUT ROWID;

-- 사용자 기준 조회: 내가 관리하는 그룹, 소속 그룹 이름(사용자 관리 화면), 프로젝트 접근 판정(P11B)
CREATE INDEX group_members_user ON group_members (user_id, is_manager);
