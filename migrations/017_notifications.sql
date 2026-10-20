-- 017: 앱 안 알림(P24). 016 다음에 적용한다.
--
-- 1) notifications       받는 사람 한 명에게 알림 한 건. 문구는 저장하지 않고 화면이 kind + params로 만든다(저장된 HTML 없음).
--                         params에는 건수·Run 번호·상태값·오류 코드처럼 문구에 쓰는 값만(결함 메시지·파일 경로·소스 조각·프로젝트 이름 금지).
--                         같은 사건은 한 번만: (user_id, dedupe_key) 유일(워커 재시도·중복 호출에도 한 건).
-- 2) notification_prefs  사용자별 종류 켜기·끄기. 기본(켜짐)과 다른 행만 저장한다(사실상 꺼진 종류만).
--
-- 프로젝트를 지우면 그 프로젝트의 알림도 지운다(ON DELETE CASCADE). 접근을 잃은 프로젝트의 알림은 행을 두고 목록·개수에서 숨긴다
-- (보관 정리가 지운다). analysis_id·defect_id는 이동 대상 참고값이라 FK를 두지 않는다(Run 상세 삭제·결함 삭제는 대상 화면이 안내한다).
-- 사용자 행이 지워지면 알림·설정도 함께 지운다(ON DELETE CASCADE. 현재 사용자는 비활성화만 하고 행을 지우지 않는다).
-- 기존 표·데이터는 바꾸지 않는다.
-- 되돌리기: DROP TABLE notification_prefs; DROP TABLE notifications; 와 schema_migrations의 017 행 삭제
--          (app_settings의 notifications.* 행은 남아도 무해하다. 지우려면 DELETE FROM app_settings WHERE key LIKE 'notifications.%').

CREATE TABLE notifications (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary')),
    project_id   INTEGER NULL REFERENCES projects (id) ON DELETE CASCADE,
    analysis_id  INTEGER NULL,
    defect_id    INTEGER NULL,
    params       TEXT    NOT NULL DEFAULT '{}' CHECK (length(params) <= 2000 AND json_valid(params) AND json_type(params) = 'object'),
    dedupe_key   TEXT    NOT NULL CHECK (length(dedupe_key) BETWEEN 1 AND 120),
    created_at   TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    read_at      TEXT    NULL CHECK (read_at IS NULL OR read_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    UNIQUE (user_id, dedupe_key)
);
-- 안 읽은 개수·안 읽은 목록(사용자, 읽음 여부, 최신순)
CREATE INDEX notifications_user_read_created ON notifications (user_id, read_at, created_at DESC);
-- 전체 목록(사용자, 최신순)
CREATE INDEX notifications_user_created ON notifications (user_id, created_at DESC, id DESC);
-- 보관 정리: 읽은 지 오래된 것 / 만든 지 오래된 것
CREATE INDEX notifications_read_at ON notifications (read_at) WHERE read_at IS NOT NULL;
CREATE INDEX notifications_created ON notifications (created_at);
-- 프로젝트 삭제(CASCADE)
CREATE INDEX notifications_project ON notifications (project_id) WHERE project_id IS NOT NULL;

CREATE TABLE notification_prefs (
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind        TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary')),
    enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (user_id, kind)
) WITHOUT ROWID;
