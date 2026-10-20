-- 028: SAML IdP 인증서 만료 임박 알림 종류(P28D, 사용자 결정 2026-10-11). 027 다음에 적용한다.
--
-- 알림 종류 saml_cert_expiring(관리자 전용): 등록한 IdP 서명 인증서(현재·다음 — 롤오버) 중 하나가 notification.cert_expiry_warn(기본 30일) 안에
-- 만료되거나 이미 만료됨. 워커가 시간마다 보고 인증서마다 하루 한 건(dedupe saml_cert_expiring:<지문 앞 16자>:<날짜>).
-- kind CHECK를 넓히려고 notifications·notification_prefs를 다시 만든다(019·023·024·026과 같은 방식, 데이터·id·순번·인덱스 5개 그대로).
-- 두 표는 자식 표(users·projects 참조)이고 다른 표가 참조하지 않으므로 외래 키 표지(pjg:foreign-keys-off)가 필요 없다.
--
-- 되돌리기(사본에서 확인, docs/results/P28D.md):
--   DELETE FROM notifications WHERE kind = 'saml_cert_expiring';
--   DELETE FROM notification_prefs WHERE kind = 'saml_cert_expiring';
--   (kind CHECK는 넓어진 채로 남아도 무해하다)
--   DELETE FROM schema_migrations WHERE version = '028';   (버전은 문자열)

CREATE TABLE notifications_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
                                                  'backup_failed', 'backup_done', 'restore_rehearsal_due',
                                                  'account_pending', 'account_pending_limit', 'auth_identity_mismatch', 'saml_cert_expiring')),
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
DELETE FROM sqlite_sequence WHERE name = 'notifications_new';
CREATE INDEX notifications_user_read_created ON notifications (user_id, read_at, created_at DESC);
CREATE INDEX notifications_user_created ON notifications (user_id, created_at DESC, id DESC);
CREATE INDEX notifications_read_at ON notifications (read_at) WHERE read_at IS NOT NULL;
CREATE INDEX notifications_created ON notifications (created_at);
CREATE INDEX notifications_project ON notifications (project_id) WHERE project_id IS NOT NULL;

CREATE TABLE notification_prefs_new (
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind        TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
                                                 'backup_failed', 'backup_done', 'restore_rehearsal_due',
                                                 'account_pending', 'account_pending_limit', 'auth_identity_mismatch', 'saml_cert_expiring')),
    enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (user_id, kind)
) WITHOUT ROWID;
INSERT INTO notification_prefs_new (user_id, kind, enabled, updated_at) SELECT user_id, kind, enabled, updated_at FROM notification_prefs;
DROP TABLE notification_prefs;
ALTER TABLE notification_prefs_new RENAME TO notification_prefs;
