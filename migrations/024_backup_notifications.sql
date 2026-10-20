-- 024: 백업·복원(P27B) 관리자 알림 종류. 023 다음에 적용한다.
--
-- 알림 종류(모두 관리자 전용, 명세 17):
--   backup_failed          일일·수동 백업이 실패(일일은 한 번 다시 시도한 뒤에도 실패). 같은 사유는 1시간에 한 번(명세 19.3)
--   backup_done            "지금 백업"(수동) 완료 — 요청한 관리자에게
--   restore_rehearsal_due  마지막 복원 리허설이 [backup] rehearsal_interval_days(90일)를 넘음 — 기한마다 한 번
-- kind CHECK를 넓히려고 notifications·notification_prefs를 다시 만든다(019·023과 같은 방식, 데이터·id·순번·인덱스 5개 그대로).
-- 백업 목록·상태는 DB가 아니라 백업 폴더의 파일(backup_state.json)이다 — 복원이 DB를 통째로 바꾸므로(docs/backup_restore.md).
--
-- 되돌리기(사본에서 확인, docs/results/P27B.md):
--   DELETE FROM notifications WHERE kind IN ('backup_failed', 'backup_done', 'restore_rehearsal_due');
--   DELETE FROM notification_prefs WHERE kind IN ('backup_failed', 'backup_done', 'restore_rehearsal_due');
--   (kind CHECK는 넓어진 채로 남아도 무해하다)
--   DELETE FROM schema_migrations WHERE version = '024';   (버전은 문자열)

CREATE TABLE notifications_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
                                                  'backup_failed', 'backup_done', 'restore_rehearsal_due')),
    project_id   INTEGER NULL REFERENCES projects (id) ON DELETE CASCADE,
    analysis_id  INTEGER NULL,
    defect_id    INTEGER NULL,
    params       TEXT    NOT NULL DEFAULT '{}' CHECK (length(params) <= 2000 AND json_valid(params) AND json_type(params) = 'object'),
    dedupe_key   TEXT    NOT NULL CHECK (length(dedupe_key) BETWEEN 1 AND 120),
    created_at   TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    read_at      TEXT    NULL CHECK (read_at IS NULL OR read_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    UNIQUE (user_id, dedupe_key)
);
INSERT INTO notifications_new (id, user_id, kind, project_id, analysis_id, defect_id, params, dedupe_key, created_at, read_at)
    SELECT id, user_id, kind, project_id, analysis_id, defect_id, params, dedupe_key, created_at, read_at FROM notifications;
INSERT INTO sqlite_sequence (name, seq) SELECT 'notifications_new', 0 WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'notifications_new');
UPDATE sqlite_sequence SET seq = max(seq, COALESCE((SELECT seq FROM sqlite_sequence WHERE name = 'notifications'), 0)) WHERE name = 'notifications_new';
DROP TABLE notifications;
ALTER TABLE notifications_new RENAME TO notifications;
CREATE INDEX notifications_user_read_created ON notifications (user_id, read_at, created_at DESC);
CREATE INDEX notifications_user_created ON notifications (user_id, created_at DESC, id DESC);
CREATE INDEX notifications_read_at ON notifications (read_at) WHERE read_at IS NOT NULL;
CREATE INDEX notifications_created ON notifications (created_at);
CREATE INDEX notifications_project ON notifications (project_id) WHERE project_id IS NOT NULL;

CREATE TABLE notification_prefs_new (
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind        TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
                                                 'backup_failed', 'backup_done', 'restore_rehearsal_due')),
    enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (user_id, kind)
) WITHOUT ROWID;
INSERT INTO notification_prefs_new (user_id, kind, enabled, updated_at) SELECT user_id, kind, enabled, updated_at FROM notification_prefs;
DROP TABLE notification_prefs;
ALTER TABLE notification_prefs_new RENAME TO notification_prefs;
