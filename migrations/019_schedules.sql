-- 019: 주기 분석(P26, 명세 18.2·18.3·18.5). 018 다음에 적용한다.
--
-- 1) project_schedules  프로젝트별 일정 한 행(사용 여부, N일 간격, 실행 시각 KST "HH:MM", 다음 실행 시각 next_run_at(UTC)).
--                        스케줄러 결과 요약(마지막 도래·마지막 등록 분석, 연속 실패 횟수와 첫 실패 분석, 마지막 결과·오류, 건너뜀 횟수·사유).
--                        revision = 낙관적 개정 번호(설정 저장마다 +1, 다르면 409). 스케줄러가 쓰는 요약 열은 revision을 올리지 않는다.
-- 2) schedule_ticks     도래 기록(이력·중복 방지). (project_id, due_at) 유일 — 여러 워커·재시작에도 같은 도래를 두 번 등록하지 않는다.
--                        result: queued(분석 등록) / skipped_busy(queued·running 있음) / skipped_disk(디스크 차단) /
--                        skipped_no_connection(연결 없음·자격 증명을 읽을 수 없음 등, detail에 코드) / error(등록 중 오류).
-- 3) analyses.retry_after, retry_count, retry_code  주기 분석 가져오기 재시도(5분 간격, 총 3번). 같은 분석 행을 queued로 되돌리고
--                        retry_after 전에는 선점하지 않는다(실패 행을 새로 만들지 않아 이력에는 최종 결과 1건만).
-- 4) 알림 종류 schedule_failed(주기 분석 실패, 받는 사람 = 활성 admin). 017의 kind CHECK를 넓히려고 notifications·notification_prefs를
--    다시 만든다(SQLite는 CHECK를 바꿀 수 없음). 이 두 표를 참조하는 표가 없어 데이터·id를 그대로 옮긴다.
--
-- 프로젝트를 지우면 일정·도래 기록도 함께 지운다(ON DELETE CASCADE). 기존 분석 행은 retry_count = 0, retry_after = NULL.
-- 되돌리기(사본에서 확인, docs/results/P26.md):
--   DROP TABLE schedule_ticks; DROP TABLE project_schedules;
--   ALTER TABLE analyses DROP COLUMN retry_code; ALTER TABLE analyses DROP COLUMN retry_count; ALTER TABLE analyses DROP COLUMN retry_after;  (인덱스 analyses_retry_after 먼저 DROP)
--   DELETE FROM notifications WHERE kind = 'schedule_failed'; DELETE FROM notification_prefs WHERE kind = 'schedule_failed';
--   (kind CHECK는 넓어진 채로 남아도 무해하다. 017과 똑같이 되돌리려면 같은 방식으로 표를 다시 만든다)
--   DELETE FROM schema_migrations WHERE version = '019';

CREATE TABLE project_schedules (
    project_id            INTEGER PRIMARY KEY REFERENCES projects (id) ON DELETE CASCADE,
    enabled               INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    interval_days         INTEGER NOT NULL DEFAULT 7 CHECK (interval_days BETWEEN 1 AND 365),
    -- 실행 시각(KST, 24시간제 "HH:MM")
    run_time              TEXT    NOT NULL DEFAULT '03:00' CHECK (length(run_time) = 5 AND run_time GLOB '[0-2][0-9]:[0-5][0-9]' AND run_time <= '23:59'),
    -- 다음 실행 시각(UTC). 사용 중일 때만 값이 있다
    next_run_at           TEXT    NULL CHECK (next_run_at IS NULL OR next_run_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_due_at           TEXT    NULL CHECK (last_due_at IS NULL OR last_due_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_analysis_id      INTEGER NULL,
    consecutive_failures  INTEGER NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    -- 연속 실패가 시작된 분석(알림 중복 키 schedule_failed:{프로젝트}:{이 id}). 성공하면 NULL
    first_failure_analysis_id INTEGER NULL,
    -- 결과를 마지막으로 반영한 분석(같은 분석의 종료 이벤트가 두 번 와도 한 번만 센다)
    last_outcome_analysis_id INTEGER NULL,
    last_result           TEXT    NULL CHECK (last_result IS NULL OR last_result IN ('succeeded', 'failed', 'cancelled')),
    last_result_at        TEXT    NULL CHECK (last_result_at IS NULL OR last_result_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_error_code       TEXT    NULL CHECK (last_error_code IS NULL OR length(last_error_code) <= 60),
    last_error_at         TEXT    NULL CHECK (last_error_at IS NULL OR last_error_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    skip_count            INTEGER NOT NULL DEFAULT 0 CHECK (skip_count >= 0),
    last_skip_reason      TEXT    NULL CHECK (last_skip_reason IS NULL OR length(last_skip_reason) <= 60),
    last_skip_at          TEXT    NULL CHECK (last_skip_at IS NULL OR last_skip_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    revision              INTEGER NOT NULL DEFAULT 1 CHECK (revision >= 1),
    created_at            TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    updated_by            INTEGER NULL REFERENCES users (id),
    updated_at            TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (enabled = 0 OR next_run_at IS NOT NULL)
);
-- 도래 조회: 사용 중인 일정만, next_run_at 범위 조회
CREATE INDEX project_schedules_due ON project_schedules (next_run_at) WHERE enabled = 1;

CREATE TABLE schedule_ticks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    due_at       TEXT    NOT NULL CHECK (due_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    result       TEXT    NOT NULL CHECK (result IN ('queued', 'skipped_busy', 'skipped_disk', 'skipped_no_connection', 'error')),
    detail       TEXT    NULL CHECK (detail IS NULL OR length(detail) <= 60),
    analysis_id  INTEGER NULL,
    created_at   TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    UNIQUE (project_id, due_at)
);
-- 시스템 현황의 최근 기록(최신순)
CREATE INDEX schedule_ticks_created ON schedule_ticks (created_at DESC, id DESC);

ALTER TABLE analyses ADD COLUMN retry_after TEXT NULL CHECK (retry_after IS NULL OR retry_after GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE analyses ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0 CHECK (retry_count BETWEEN 0 AND 10);
-- 직전 시도의 가져오기 실패 코드(재시도 대기 표시용. failure_code는 최종 실패에만 쓴다)
ALTER TABLE analyses ADD COLUMN retry_code TEXT NULL CHECK (retry_code IS NULL OR length(retry_code) <= 60);
-- 재시도 대기 중인 분석(선점 조건, 대부분 비어 있음)
CREATE INDEX analyses_retry_after ON analyses (retry_after) WHERE retry_after IS NOT NULL;

-- ---- 알림 종류 schedule_failed (017 표 다시 만들기) ----
CREATE TABLE notifications_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed')),
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
-- AUTOINCREMENT 순번도 이어 받는다(지운 알림의 id를 다시 쓰지 않게)
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
    kind        TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed')),
    enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (user_id, kind)
) WITHOUT ROWID;
INSERT INTO notification_prefs_new (user_id, kind, enabled, updated_at) SELECT user_id, kind, enabled, updated_at FROM notification_prefs;
DROP TABLE notification_prefs;
ALTER TABLE notification_prefs_new RENAME TO notification_prefs;
