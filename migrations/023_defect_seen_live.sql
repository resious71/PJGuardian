-- 023: 대용량 결함 반영(P19C, 사용자 결정 2026-10-08). 022 다음에 적용한다.
--
-- 1) defects.seen_live  유지 결함의 last_seen 행별 갱신을 없애기 위한 표시. 1 = "프로젝트의 최신 실제 성공 분석(변경 없음 제외)에서 감지됨" —
--                       이 행의 last_seen_analysis_id는 오래된 값일 수 있고, 읽을 때는 그 최신 분석으로 대체한다(Services\Defects\DefectSeen 한 곳).
--                       0 = last_seen_analysis_id가 그대로 마지막 감지 분석(옛 방식·해결·범위 밖 유지).
--                       반영은 감지 → 미감지가 될 때만 last_seen_analysis_id = 직전 최신 분석, seen_live = 0으로 고정(쓰기는 상태가 바뀌는 행만),
--                       신규·재오픈·다시 감지는 seen_live = 1. 기존 행은 모두 0(옛 방식 그대로 맞다) — 앱은 되채움 전에도 정상이고,
--                       첫 반영이 감지된 행을 1로 바꾸거나 tools/backfill_seen.php가 미리 바꾼다.
-- 2) 알림 종류 defect_count_warn(관리자 전용): 프로젝트의 현재 결함이 [limits] project_defect_warn을 넘음. kind CHECK를 넓히려고
--    notifications·notification_prefs를 다시 만든다(019와 같은 방식, 데이터·id·순번 그대로).
--
-- 되돌리기(사본에서 확인, docs/results/P19C.md):
--   먼저 옛 코드로 돌아가기 전에 seen_live = 1 행의 마지막 감지를 실제 값으로 되돌린다(옛 코드는 last_seen_analysis_id만 읽는다):
--     UPDATE defects SET last_seen_analysis_id = (SELECT MAX(a.id) FROM analyses a WHERE a.project_id = defects.project_id AND a.status = 'succeeded' AND a.no_change = 0)
--       WHERE seen_live = 1;
--   ALTER TABLE defects DROP COLUMN seen_live;
--   DELETE FROM notifications WHERE kind = 'defect_count_warn'; DELETE FROM notification_prefs WHERE kind = 'defect_count_warn';
--   (kind CHECK는 넓어진 채로 남아도 무해하다)
--   DELETE FROM schema_migrations WHERE version = '023';   (버전은 문자열)

ALTER TABLE defects ADD COLUMN seen_live INTEGER NOT NULL DEFAULT 0 CHECK (seen_live IN (0, 1));

CREATE TABLE notifications_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn')),
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
    kind        TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn')),
    enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (user_id, kind)
) WITHOUT ROWID;
INSERT INTO notification_prefs_new (user_id, kind, enabled, updated_at) SELECT user_id, kind, enabled, updated_at FROM notification_prefs;
DROP TABLE notification_prefs;
ALTER TABLE notification_prefs_new RENAME TO notification_prefs;
