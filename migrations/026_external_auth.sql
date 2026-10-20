-- pjg:foreign-keys-off
-- 026: 외부 인증 공통 기반(P28B, 명세 19.2). 025 다음에 적용한다.
--
-- 첫 줄 표지(pjg:foreign-keys-off): users는 여러 테이블이 참조하는 부모 테이블이라, 외래 키를 켠 채 다시 만들면 DROP TABLE의
-- 암묵적 DELETE가 ON DELETE CASCADE(알림·알림 설정)를 일으킨다. 그래서 Migrator가 이 파일만 외래 키를 끄고 한 트랜잭션으로 적용한 뒤
-- 커밋 전에 PRAGMA foreign_key_check가 0건인지 확인하고(아니면 롤백) 외래 키를 다시 켠다(SQLite 공식 테이블 재구성 절차).
--
-- 1. users 다시 만들기(행·id·AUTOINCREMENT 순번 그대로):
--    - auth_type CHECK를 local·ldap·oidc·saml로(P28C·P28D가 다시 만들지 않게 한 번에). 비밀번호 해시는 local만 있고 외부 방식은 NULL.
--      임시 비밀번호(만료 시각)는 local만.
--    - external_id(디렉터리·IdP의 안정 식별자 — LDAP objectGUID/entryUUID, OIDC iss+sub, SAML NameID), external_issuer(발급자·서버 구분).
--      유일 인덱스 (auth_type, external_issuer(NULL은 ''), external_id), external_id가 NULL인 행은 제외.
--    - approval_status approved(기본, 기존 모든 행) | pending(외부 인증 첫 로그인, 관리자 승인 대기) | rejected(거절).
--      승인되지 않은 계정은 항상 비활성(status = 'inactive') — 기존의 모든 "활성 사용자" 조건(권한·목록·알림 대상)이 저절로 뺀다.
--    - 트리거: users_no_delete는 **한 번도 승인되지 않은 계정**(pending·rejected)만 지울 수 있게(보관 기간 정리, auth.pending_retention_days).
--      users_keep_last_active_admin은 그대로.
-- 2. app_secrets: 앱 전역 비밀(LDAP 검색 계정 비밀번호 ldap.bind_password). P25 SecretBox(AES-256-GCM)로 암호화한 본문만. 백업(P27B)에는
--    암호화된 채 들어가고 키는 들어가지 않는다(저장소 자격 증명과 같은 규칙).
-- 3. 알림 종류 3개(관리자 전용): account_pending(승인 대기 계정 생김, 같은 날 5건 넘으면 요약 1건), account_pending_limit(대기 상한 도달,
--    하루 1회), auth_identity_mismatch(같은 로그인 ID인데 외부 식별자가 다름 — 로그인 거부). 019·023·024와 같은 방식으로 다시 만든다.
--
-- 되돌리기(사본에서 확인, docs/results/P28B.md): 외부 계정·대기 계정이 없을 때만 의미가 있다.
--   외래 키를 끈 연결에서 한 트랜잭션으로: users를 025 정의로 다시 만들고(외부 열 3개를 빼고 복사, auth_type이 local·ldap이 아닌 행이 있으면 중단),
--   트리거 2개를 001 원문으로 다시 만들고, DROP TABLE app_secrets;
--   DELETE FROM notifications WHERE kind IN ('account_pending', 'account_pending_limit', 'auth_identity_mismatch'); (prefs도 같은 kind)
--   DELETE FROM schema_migrations WHERE version = '026';   (버전은 문자열)

CREATE TABLE users_new (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    login_id                  TEXT    NOT NULL UNIQUE COLLATE NOCASE CHECK (length(login_id) BETWEEN 1 AND 100),
    display_name              TEXT    NOT NULL CHECK (length(display_name) BETWEEN 1 AND 100),
    email                     TEXT    NULL CHECK (email IS NULL OR length(email) <= 254),
    role                      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    status                    TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    auth_type                 TEXT    NOT NULL CHECK (auth_type IN ('local', 'ldap', 'oidc', 'saml')),
    -- bcrypt 해시(명세 3). 외부 인증 계정(LDAP·OIDC·SAML)은 비밀번호를 앱에 두지 않는다(명세 19.2)
    password_hash             TEXT    NULL,
    temp_password_expires_at  TEXT    NULL,
    must_change_password      INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)),
    failed_login_count        INTEGER NOT NULL DEFAULT 0 CHECK (failed_login_count >= 0),
    locked_until              TEXT    NULL,
    created_at                TEXT    NOT NULL,
    updated_at                TEXT    NOT NULL,
    password_kind             TEXT    NOT NULL DEFAULT 'user' CHECK (password_kind IN ('user', 'temporary', 'initial')),
    admin_password_set_at     TEXT    NULL CHECK (admin_password_set_at IS NULL OR admin_password_set_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    ui_language               TEXT    NULL CHECK (ui_language IS NULL OR ui_language IN ('ko', 'en')),
    ui_theme                  TEXT    NULL CHECK (ui_theme IS NULL OR ui_theme IN ('light', 'dark', 'system')),
    external_id               TEXT    NULL CHECK (external_id IS NULL OR length(external_id) BETWEEN 1 AND 255),
    external_issuer           TEXT    NULL CHECK (external_issuer IS NULL OR length(external_issuer) BETWEEN 1 AND 255),
    approval_status           TEXT    NOT NULL DEFAULT 'approved' CHECK (approval_status IN ('approved', 'pending', 'rejected')),
    CHECK ((auth_type = 'local' AND password_hash IS NOT NULL) OR (auth_type <> 'local' AND password_hash IS NULL)),
    CHECK (temp_password_expires_at IS NULL OR auth_type = 'local'),
    CHECK (temp_password_expires_at IS NULL OR temp_password_expires_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (locked_until IS NULL OR locked_until GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    -- 승인되지 않은 계정(대기·거절)은 비활성이고 외부 인증 계정이다
    CHECK (approval_status = 'approved' OR (status = 'inactive' AND auth_type <> 'local'))
);
INSERT INTO users_new (id, login_id, display_name, email, role, status, auth_type, password_hash, temp_password_expires_at, must_change_password,
                       failed_login_count, locked_until, created_at, updated_at, password_kind, admin_password_set_at, ui_language, ui_theme)
    SELECT id, login_id, display_name, email, role, status, auth_type, password_hash, temp_password_expires_at, must_change_password,
           failed_login_count, locked_until, created_at, updated_at, password_kind, admin_password_set_at, ui_language, ui_theme FROM users;
INSERT INTO sqlite_sequence (name, seq) SELECT 'users_new', 0 WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'users_new');
UPDATE sqlite_sequence SET seq = max(seq, COALESCE((SELECT seq FROM sqlite_sequence WHERE name = 'users'), 0)) WHERE name = 'users_new';
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
DELETE FROM sqlite_sequence WHERE name = 'users_new';
CREATE UNIQUE INDEX users_external_identity ON users (auth_type, COALESCE(external_issuer, ''), external_id) WHERE external_id IS NOT NULL;
CREATE INDEX users_approval_pending ON users (approval_status, created_at) WHERE approval_status <> 'approved';

-- 계정은 삭제하지 않고 비활성화만 한다(명세 13). 예외: 한 번도 승인되지 않은 외부 인증 요청(대기·거절)은 보관 기간 뒤 정리한다(P28B)
CREATE TRIGGER users_no_delete BEFORE DELETE ON users
WHEN OLD.approval_status = 'approved'
BEGIN
    SELECT RAISE(ABORT, 'users_are_never_deleted');
END;

-- 마지막 활성 관리자는 비활성화하거나 일반 사용자로 바꿀 수 없다(명세 13, 001과 같음)
CREATE TRIGGER users_keep_last_active_admin BEFORE UPDATE OF role, status ON users
WHEN OLD.role = 'admin' AND OLD.status = 'active'
     AND (NEW.role <> 'admin' OR NEW.status <> 'active')
     AND (SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> OLD.id) = 0
BEGIN
    SELECT RAISE(ABORT, 'last_active_admin');
END;

CREATE TABLE app_secrets (
    name        TEXT    PRIMARY KEY CHECK (name IN ('ldap.bind_password')),
    cipher      TEXT    NOT NULL CHECK (length(cipher) <= 8000),
    nonce       TEXT    NOT NULL CHECK (length(nonce) <= 64),
    key_id      TEXT    NOT NULL CHECK (length(key_id) = 16 AND key_id NOT GLOB '*[^0-9a-f]*'),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    updated_by  INTEGER NULL REFERENCES users (id) ON DELETE SET NULL
) WITHOUT ROWID;

CREATE TABLE notifications_new (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind         TEXT    NOT NULL CHECK (kind IN ('analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
                                                  'backup_failed', 'backup_done', 'restore_rehearsal_due',
                                                  'account_pending', 'account_pending_limit', 'auth_identity_mismatch')),
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
                                                 'account_pending', 'account_pending_limit', 'auth_identity_mismatch')),
    enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (user_id, kind)
) WITHOUT ROWID;
INSERT INTO notification_prefs_new (user_id, kind, enabled, updated_at) SELECT user_id, kind, enabled, updated_at FROM notification_prefs;
DROP TABLE notification_prefs;
ALTER TABLE notification_prefs_new RENAME TO notification_prefs;

-- 4. 로그인 ID 단위 요청 제한(P28B): 외부 인증(LDAP) 실패를 IP와 함께 로그인 ID 키(소문자 로그인 ID의 SHA-256 앞 32자)로도 기록해
--    같은 ID로 짧은 시간에 많이 실패하면 디렉터리에 요청하지 않는다(디렉터리 계정 잠금 폭주 방지). 앱의 계정 잠금(5회/15분)은 LDAP 계정에 적용하지 않는다.
ALTER TABLE login_failures ADD COLUMN login_key TEXT NULL CHECK (login_key IS NULL OR (length(login_key) = 32 AND login_key NOT GLOB '*[^0-9a-f]*'));
CREATE INDEX login_failures_key ON login_failures (login_key, failed_at) WHERE login_key IS NOT NULL;
