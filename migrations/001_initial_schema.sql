-- PJGuardian 초기 스키마 (P08). 설계 근거는 docs/db_schema.md.
-- 규칙: 시간은 UTC ISO 8601 TEXT('YYYY-MM-DDTHH:MM:SSZ', 문자열 정렬 = 시간 순서), 불리언은 INTEGER 0/1,
--       열거형은 CHECK. 이 파일에 PRAGMA를 쓰지 않는다(foreign_keys 등은 Support\Db가 접속할 때 켠다).
-- 한 번 적용한 뒤에는 이 파일을 고치지 않고 새 마이그레이션(002_...)을 추가한다.
-- 이번 묶음(P19까지)에 없는 테이블(세션 P10, 알림, 규칙 설명, 저장소 연결, 백업 이력 등)은 해당 단계에서 추가한다.

-- ---------------------------------------------------------------------------
-- 사용자 (명세 3, 13, 19.2)
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    login_id                  TEXT    NOT NULL UNIQUE COLLATE NOCASE CHECK (length(login_id) BETWEEN 1 AND 100),
    display_name              TEXT    NOT NULL CHECK (length(display_name) BETWEEN 1 AND 100),
    email                     TEXT    NULL CHECK (email IS NULL OR length(email) <= 254),
    role                      TEXT    NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    status                    TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    auth_type                 TEXT    NOT NULL CHECK (auth_type IN ('local', 'ldap')),
    -- bcrypt 해시(명세 3). LDAP 계정은 비밀번호를 앱에 두지 않는다(명세 19.2)
    password_hash             TEXT    NULL,
    -- 관리자가 발급한 임시 비밀번호의 만료 시각(발급 24시간 후, 명세 3·13). NULL이면 임시 비밀번호 아님
    temp_password_expires_at  TEXT    NULL,
    must_change_password      INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)),
    -- 연속 로그인 실패 횟수와 잠금 해제 시각(5회 실패 시 15분, 명세 3). 로컬 계정만 쓴다
    failed_login_count        INTEGER NOT NULL DEFAULT 0 CHECK (failed_login_count >= 0),
    locked_until              TEXT    NULL,
    created_at                TEXT    NOT NULL,
    updated_at                TEXT    NOT NULL,
    CHECK ((auth_type = 'local' AND password_hash IS NOT NULL) OR (auth_type = 'ldap' AND password_hash IS NULL)),
    CHECK (temp_password_expires_at IS NULL OR auth_type = 'local'),
    CHECK (temp_password_expires_at IS NULL OR temp_password_expires_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (locked_until IS NULL OR locked_until GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

-- 계정은 삭제하지 않고 비활성화만 한다(명세 13)
CREATE TRIGGER users_no_delete BEFORE DELETE ON users
BEGIN
    SELECT RAISE(ABORT, 'users_are_never_deleted');
END;

-- 마지막 활성 관리자는 비활성화하거나 일반 사용자로 바꿀 수 없다(명세 13). 화면 메시지는 서비스(P10)가 먼저 검사해 만든다
CREATE TRIGGER users_keep_last_active_admin BEFORE UPDATE OF role, status ON users
WHEN OLD.role = 'admin' AND OLD.status = 'active'
     AND (NEW.role <> 'admin' OR NEW.status <> 'active')
     AND (SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> OLD.id) = 0
BEGIN
    SELECT RAISE(ABORT, 'last_active_admin');
END;

-- ---------------------------------------------------------------------------
-- IP 단위 로그인 실패 (명세 3, 19.5: 같은 IP 10분 내 20회 이상 실패 시 15분 차단)
-- 차단 해제 시각 = (가장 최근 실패 시각 + 차단 시간), 단 "최근 20건의 실패가 10분 안에 몰려 있을 때"만.
-- (ip, failed_at) 인덱스로 최근 N건만 읽어 계산한다. 오래된 행은 정리 작업(P14)이 지운다.
-- ---------------------------------------------------------------------------
CREATE TABLE login_failures (
    id         INTEGER PRIMARY KEY,
    ip         TEXT    NOT NULL CHECK (length(ip) BETWEEN 2 AND 45),
    failed_at  TEXT    NOT NULL CHECK (failed_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
CREATE INDEX login_failures_ip_time ON login_failures (ip, failed_at);

-- ---------------------------------------------------------------------------
-- 프로젝트 (명세 4, 19.10)
-- ---------------------------------------------------------------------------
CREATE TABLE projects (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE COLLATE NOCASE CHECK (length(name) BETWEEN 1 AND 100),
    description       TEXT    NOT NULL DEFAULT '' CHECK (length(description) <= 2000),
    -- 대상 언어. 생성 후 변경 불가(명세 4, 아래 트리거)
    language          TEXT    NOT NULL CHECK (language IN ('python', 'javascript')),
    -- 최상위 폴더 제거 규칙(명세 19.10): NULL = 아직 업로드 없음(미확정), 1 = 제거, 0 = 유지. 첫 업로드에서 확정
    strip_top_folder  INTEGER NULL CHECK (strip_top_folder IS NULL OR strip_top_folder IN (0, 1)),
    -- 관리자가 추가한 제외 패턴(JSON 문자열 배열, 명세 4). 서버 기본 목록은 설정 extract.default_excludes
    exclude_patterns  TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(exclude_patterns) AND json_type(exclude_patterns) = 'array'),
    created_at        TEXT    NOT NULL,
    updated_at        TEXT    NOT NULL,
    CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

CREATE TRIGGER projects_language_immutable BEFORE UPDATE OF language ON projects
WHEN NEW.language <> OLD.language
BEGIN
    SELECT RAISE(ABORT, 'project_language_immutable');
END;

-- ---------------------------------------------------------------------------
-- 업로드 (명세 5.1, 5.4, 19.3, 19.10). 청크 파일 자체는 data/uploads/tmp/<id>/ 에 있고 DB에는 상태만 둔다.
-- status가 업로드 후처리(조립·해제)의 큐 역할을 한다: assemble_pending → assembling → extracting
--   → (경고가 있으면 needs_confirmation) → queued(분석 행 생성) / failed / aborted
-- ---------------------------------------------------------------------------
CREATE TABLE uploads (
    id                TEXT    PRIMARY KEY CHECK (length(id) = 32 AND id NOT GLOB '*[^0-9a-f]*'),
    user_id           INTEGER NOT NULL REFERENCES users (id),
    project_id        INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    -- 표시용 파일 이름. 경로에 쓰지 않는다
    file_name         TEXT    NOT NULL CHECK (length(file_name) <= 255),
    total_size        INTEGER NOT NULL CHECK (total_size > 0),
    chunk_size        INTEGER NOT NULL CHECK (chunk_size > 0),
    total_chunks      INTEGER NOT NULL CHECK (total_chunks > 0),
    status            TEXT    NOT NULL DEFAULT 'receiving' CHECK (status IN (
                          'receiving', 'assemble_pending', 'assembling', 'extracting',
                          'needs_confirmation', 'queued', 'failed', 'aborted')),
    -- 디스크 사전 검사용 예약 크기(명세 19.3, 19.10: 진행 중 업로드의 예약 크기 합산). 끝난 업로드는 0으로 둔다
    reserved_bytes    INTEGER NOT NULL DEFAULT 0 CHECK (reserved_bytes >= 0),
    -- 조립본 SHA-256(명세 19.10). 조립이 끝나야 생긴다
    sha256            TEXT    NULL CHECK (sha256 IS NULL OR (length(sha256) = 64 AND sha256 NOT GLOB '*[^0-9a-f]*')),
    -- 해제 작업 디렉토리 이름(data/work/<8자리 16진수>, 명세 19.10). 해제를 시작할 때 정한다
    work_dir          TEXT    NULL CHECK (work_dir IS NULL OR (length(work_dir) = 8 AND work_dir NOT GLOB '*[^0-9a-f]*')),
    -- 사용자 확인이 필요한 경고 코드 목록(JSON 배열: no_target_files, top_folder_missing, top_folder_kept 등)
    warnings          TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(warnings) AND json_type(warnings) = 'array'),
    -- 경고를 사용자가 확인한 시각(명세 5.4: 확인하면 진행)
    confirmed_at      TEXT    NULL,
    error_code        TEXT    NULL,
    error_message     TEXT    NULL,
    -- 마지막 청크·상태 변경 시각. 미완료 조각 24시간 정리(명세 5.4)의 기준
    last_activity_at  TEXT    NOT NULL,
    created_at        TEXT    NOT NULL,
    CHECK (status <> 'failed' OR error_code IS NOT NULL),
    CHECK (status NOT IN ('extracting', 'needs_confirmation', 'queued') OR sha256 IS NOT NULL),
    CHECK (confirmed_at IS NULL OR confirmed_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (last_activity_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
-- 사용자당 동시 진행 업로드 수(2개) 검사
CREATE INDEX uploads_user_status ON uploads (user_id, status);
-- 후처리 큐(assemble_pending 등)를 등록 순서로 꺼냄
CREATE INDEX uploads_status_created ON uploads (status, created_at);
-- 미완료 조각 정리
CREATE INDEX uploads_activity ON uploads (last_activity_at);
CREATE INDEX uploads_project ON uploads (project_id);

-- ---------------------------------------------------------------------------
-- 분석 (명세 5.2, 5.3, 8, 12, 18.2, 18.3, 19.8, 19.10)
-- 분석 큐 = status='queued' 행을 id(등록 순서) 순으로 꺼냄(별도 큐 테이블 없음, docs/db_schema.md 4절)
-- id는 AUTOINCREMENT: 프로젝트 삭제로 행이 지워져도 번호를 다시 쓰지 않아 등록 순서가 항상 보존된다
-- ---------------------------------------------------------------------------
CREATE TABLE analyses (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id            INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    upload_id             TEXT    NULL REFERENCES uploads (id) ON DELETE SET NULL,
    status                TEXT    NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
    -- 소스 종류와 출처(명세 18.3): upload / git(브랜치, 커밋) / perforce(depot 경로, changelist)
    source_kind           TEXT    NOT NULL CHECK (source_kind IN ('upload', 'git', 'perforce')),
    source_ref            TEXT    NULL,
    source_revision       TEXT    NULL,
    -- 실행 주체(명세 18.3): user(업로드, 지금 가져와 분석) / schedule(주기 분석)
    trigger_kind          TEXT    NOT NULL CHECK (trigger_kind IN ('user', 'schedule')),
    requested_by          INTEGER NULL REFERENCES users (id),
    -- 도구와 실제 사용 버전, 설정 출처·해시(명세 5.3, 12, 19.1)
    tool                  TEXT    NOT NULL CHECK (tool IN ('ruff', 'eslint')),
    tool_version          TEXT    NULL,
    config_source         TEXT    NULL CHECK (config_source IS NULL OR config_source IN ('upload', 'server_default')),
    config_hash           TEXT    NULL CHECK (config_hash IS NULL OR (length(config_hash) = 64 AND config_hash NOT GLOB '*[^0-9a-f]*')),
    -- 무시된 설정 파일과 사유(JSON 배열, "설정 무시됨" 표시, 명세 5.3·15.2·19.10)
    ignored_configs       TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(ignored_configs) AND json_type(ignored_configs) = 'array'),
    cancel_requested      INTEGER NOT NULL DEFAULT 0 CHECK (cancel_requested IN (0, 1)),
    queued_at             TEXT    NOT NULL,
    started_at            TEXT    NULL,
    finished_at           TEXT    NULL,
    failure_code          TEXT    NULL,
    failure_message       TEXT    NULL,
    -- 결함 통계(명세 8·9: 이력의 결함 개수, 추이 차트). 성공한 분석만 채운다
    total_count           INTEGER NULL CHECK (total_count IS NULL OR total_count >= 0),
    new_count             INTEGER NULL CHECK (new_count IS NULL OR new_count >= 0),
    resolved_count        INTEGER NULL CHECK (resolved_count IS NULL OR resolved_count >= 0),
    reopened_count        INTEGER NULL CHECK (reopened_count IS NULL OR reopened_count >= 0),
    unchanged_count       INTEGER NULL CHECK (unchanged_count IS NULL OR unchanged_count >= 0),
    -- 이번 분석에서 감지된 결함의 심각도별 건수(JSON 객체: {"critical":0,...,"not_defined":n})
    severity_counts       TEXT    NULL CHECK (severity_counts IS NULL OR (json_valid(severity_counts) AND json_type(severity_counts) = 'object')),
    -- 조립본 SHA-256(명세 19.10)
    assembled_sha256      TEXT    NULL CHECK (assembled_sha256 IS NULL OR (length(assembled_sha256) = 64 AND assembled_sha256 NOT GLOB '*[^0-9a-f]*')),
    -- 비교 기준(직전 성공 분석, 명세 8). NULL = 비교 대상 없음(최초 분석)
    baseline_analysis_id  INTEGER NULL REFERENCES analyses (id) ON DELETE SET NULL,
    -- "변경 없음"(명세 18.2: 직전 성공 분석과 같은 커밋·changelist라 분석을 생략). status는 succeeded로 기록
    no_change             INTEGER NOT NULL DEFAULT 0 CHECK (no_change IN (0, 1)),
    -- 분석 대상 작업 디렉토리(data/work/<8자리 16진수>)
    work_dir              TEXT    NULL CHECK (work_dir IS NULL OR (length(work_dir) = 8 AND work_dir NOT GLOB '*[^0-9a-f]*')),
    -- 취소·비정상 종료 복구용(명세 5.4, 14): 처리 중인 워커 PID, 도구 프로세스 그룹(Linux PGID / Windows 트리 루트 PID)
    worker_pid            INTEGER NULL,
    process_group_id      INTEGER NULL,
    CHECK ((trigger_kind = 'user' AND requested_by IS NOT NULL) OR (trigger_kind = 'schedule' AND requested_by IS NULL)),
    CHECK (status NOT IN ('running', 'succeeded', 'failed') OR started_at IS NOT NULL OR (status = 'succeeded' AND no_change = 1)),
    CHECK (status NOT IN ('succeeded', 'failed', 'cancelled') OR finished_at IS NOT NULL),
    CHECK (status <> 'failed' OR failure_code IS NOT NULL),
    CHECK (no_change = 0 OR status = 'succeeded'),
    CHECK (status <> 'succeeded' OR no_change = 1 OR (total_count IS NOT NULL AND new_count IS NOT NULL AND resolved_count IS NOT NULL
                                                      AND reopened_count IS NOT NULL AND unchanged_count IS NOT NULL)),
    CHECK (queued_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (started_at IS NULL OR started_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (finished_at IS NULL OR finished_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
-- 전체 큐: 상태별 등록 순서(FIFO, 명세 19.8)
CREATE INDEX analyses_status_id ON analyses (status, id);
-- 프로젝트별 이력·직전 성공 분석·queued/running 존재 확인
CREATE INDEX analyses_project_status_id ON analyses (project_id, status, id);
CREATE INDEX analyses_project_id ON analyses (project_id, id);
CREATE INDEX analyses_upload ON analyses (upload_id);
-- 같은 프로젝트의 분석은 한 번에 하나만 실행(명세 5.4): DB 수준에서도 보장
CREATE UNIQUE INDEX analyses_one_running_per_project ON analyses (project_id) WHERE status = 'running';

-- ---------------------------------------------------------------------------
-- 분석별 제외 규칙과 분석 대상 파일 목록 (명세 19.10: 신규/해결/범위 외 판정의 입력)
-- 파일 100만 개를 행으로 저장하지 않고, 정렬한 NFC 경로를 줄바꿈으로 이어 zlib 압축한 BLOB 하나로 둔다
-- (P08 측정: 100만 개 ≈ 8MB, 압축 0.8초·해제 0.1초)
-- ---------------------------------------------------------------------------
CREATE TABLE analysis_file_lists (
    analysis_id    INTEGER PRIMARY KEY REFERENCES analyses (id) ON DELETE CASCADE,
    -- 실제로 적용한 제외 규칙(JSON 객체: server_default, project, config_files 목록)
    exclude_rules  TEXT    NOT NULL CHECK (json_valid(exclude_rules) AND json_type(exclude_rules) = 'object'),
    file_count     INTEGER NOT NULL CHECK (file_count >= 0),
    encoding       TEXT    NOT NULL DEFAULT 'zlib-lines-v1' CHECK (encoding IN ('zlib-lines-v1')),
    files_blob     BLOB    NOT NULL,
    created_at     TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

-- ---------------------------------------------------------------------------
-- 전체 코드 스냅샷 (명세 7, 14: 최근 5회 전체 보존, 이후 결함 주변 발췌만)
-- ---------------------------------------------------------------------------
CREATE TABLE snapshots (
    analysis_id   INTEGER PRIMARY KEY REFERENCES analyses (id) ON DELETE CASCADE,
    -- snapshots_dir 기준 상대 경로. 서버가 만든 이름만 쓴다
    storage_path  TEXT    NOT NULL,
    size_bytes    INTEGER NOT NULL CHECK (size_bytes >= 0),
    file_count    INTEGER NOT NULL CHECK (file_count >= 0),
    -- full: 전체 보존 중 / pruned: 전체 삭제됨(필요한 결함은 defect_excerpts로 발췌)
    status        TEXT    NOT NULL DEFAULT 'full' CHECK (status IN ('full', 'pruned')),
    created_at    TEXT    NOT NULL,
    pruned_at     TEXT    NULL,
    CHECK ((status = 'full' AND pruned_at IS NULL) OR (status = 'pruned' AND pruned_at IS NOT NULL)),
    CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (pruned_at IS NULL OR pruned_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

-- ---------------------------------------------------------------------------
-- 결함 (명세 6.1, 6.2, 19.10). 고유 키 (project_id, signature, ordinal)
-- 위치·메시지는 "마지막으로 감지된 분석" 기준 값으로 갱신한다
-- ---------------------------------------------------------------------------
CREATE TABLE defects (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id              INTEGER NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
    signature               TEXT    NOT NULL CHECK (length(signature) = 64 AND signature NOT GLOB '*[^0-9a-f]*'),
    ordinal                 INTEGER NOT NULL CHECK (ordinal >= 0),
    tool                    TEXT    NOT NULL CHECK (tool IN ('ruff', 'eslint')),
    language                TEXT    NOT NULL CHECK (language IN ('python', 'javascript', 'typescript')),
    -- 도구 포함 규칙 키(ruff:F401, eslint:no-unused-vars) 또는 syntax-error(명세 6.1)
    rule_key                TEXT    NOT NULL CHECK (length(rule_key) BETWEEN 1 AND 200),
    -- 분석 루트 기준 NFC 정규화 경로('/' 구분)
    file_path               TEXT    NOT NULL CHECK (length(file_path) BETWEEN 1 AND 1000),
    line_no                 INTEGER NOT NULL CHECK (line_no >= 1),
    col_no                  INTEGER NULL CHECK (col_no IS NULL OR col_no >= 0),
    end_line_no             INTEGER NULL CHECK (end_line_no IS NULL OR end_line_no >= 1),
    end_col_no              INTEGER NULL CHECK (end_col_no IS NULL OR end_col_no >= 0),
    message                 TEXT    NOT NULL,
    severity                TEXT    NOT NULL DEFAULT 'not_defined' CHECK (severity IN ('critical', 'high', 'medium', 'low', 'not_defined')),
    status                  TEXT    NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved')),
    assignee_id             INTEGER NULL REFERENCES users (id),
    -- 낙관적 잠금(명세 6.2, 19.7): 사용자 수정·자동 전환마다 1 증가. WHERE id=? AND version=? 의 영향 행이 0이면 충돌(409)
    version                 INTEGER NOT NULL DEFAULT 1 CHECK (version >= 1),
    first_seen_analysis_id  INTEGER NOT NULL REFERENCES analyses (id) ON DELETE CASCADE,
    last_seen_analysis_id   INTEGER NOT NULL REFERENCES analyses (id) ON DELETE CASCADE,
    created_at              TEXT    NOT NULL,
    updated_at              TEXT    NOT NULL,
    UNIQUE (project_id, signature, ordinal),
    CHECK ((tool = 'ruff' AND language = 'python') OR (tool = 'eslint' AND language IN ('javascript', 'typescript'))),
    CHECK (last_seen_analysis_id >= first_seen_analysis_id),
    CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
-- 목록 필터(명세 6.4: 규칙, 심각도, 상태, 담당자, 파일) + 기본 정렬 id. docs/db_schema.md 5절
-- 필터 없는 목록(프로젝트 안 id 순): SQLite는 인덱스 끝에 rowid(=id)를 붙이므로 (project_id)만으로 id 순 정렬이 된다
CREATE INDEX defects_project             ON defects (project_id);
CREATE INDEX defects_project_status_id   ON defects (project_id, status, id);
CREATE INDEX defects_project_severity_id ON defects (project_id, severity, id);
CREATE INDEX defects_project_assignee_id ON defects (project_id, assignee_id, id);
CREATE INDEX defects_project_rule_id     ON defects (project_id, rule_key, id);
CREATE INDEX defects_project_file_line   ON defects (project_id, file_path, line_no);
-- "내게 지정된 결함"(명세 17): 프로젝트와 관계없이 담당자·상태별
CREATE INDEX defects_assignee_status     ON defects (assignee_id, status);
-- 분석별 신규(first_seen)와 마지막 감지(last_seen, 스냅샷 정리 시 발췌 대상)
CREATE INDEX defects_first_seen          ON defects (first_seen_analysis_id);
CREATE INDEX defects_last_seen           ON defects (last_seen_analysis_id);

-- ---------------------------------------------------------------------------
-- 결함 변경 이력 (명세 6.2, 6.3)
-- 자동 전환 사유 코드: not_detected(범위 안에서 미감지 → resolved), file_deleted(파일 삭제 → resolved),
--   redetected(직전 성공 분석에 없던 결함이 다시 감지 → open, "신규"로 집계),
--   still_detected(수동 resolved였지만 계속 감지 → open, "신규" 아님)
-- ---------------------------------------------------------------------------
CREATE TABLE defect_history (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    defect_id    INTEGER NOT NULL REFERENCES defects (id) ON DELETE CASCADE,
    field        TEXT    NOT NULL CHECK (field IN ('assignee', 'severity', 'status')),
    old_value    TEXT    NULL,
    new_value    TEXT    NULL,
    -- 변경자. 자동 전환이면 NULL
    changed_by   INTEGER NULL REFERENCES users (id),
    changed_at   TEXT    NOT NULL,
    kind         TEXT    NOT NULL CHECK (kind IN ('manual', 'auto')),
    reason_code  TEXT    NULL CHECK (reason_code IS NULL OR reason_code IN ('not_detected', 'file_deleted', 'redetected', 'still_detected')),
    -- 사용자가 입력한 사유 메모(선택, 명세 6.2)
    memo         TEXT    NULL CHECK (memo IS NULL OR length(memo) <= 1000),
    -- 자동 전환을 일으킨 분석
    analysis_id  INTEGER NULL REFERENCES analyses (id) ON DELETE CASCADE,
    CHECK ((kind = 'manual' AND changed_by IS NOT NULL AND reason_code IS NULL AND analysis_id IS NULL)
        OR (kind = 'auto' AND changed_by IS NULL AND reason_code IS NOT NULL AND analysis_id IS NOT NULL AND field = 'status')),
    CHECK (changed_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
CREATE INDEX defect_history_defect ON defect_history (defect_id, id);
-- 분석별 해결·재오픈 목록(명세 8 비교)
CREATE INDEX defect_history_analysis ON defect_history (analysis_id, reason_code);

-- ---------------------------------------------------------------------------
-- 결함 주변 발췌 (명세 7: 전체 스냅샷이 정리된 분석은 결함 라인 앞뒤 20줄만 보관)
-- 결함당 하나. 그 결함의 마지막 감지 분석의 스냅샷이 정리될 때 만들고,
-- 결함이 더 새 분석에서 다시 감지되면(새 전체 스냅샷이 생김) 지운다
-- ---------------------------------------------------------------------------
CREATE TABLE defect_excerpts (
    defect_id    INTEGER PRIMARY KEY REFERENCES defects (id) ON DELETE CASCADE,
    analysis_id  INTEGER NOT NULL REFERENCES analyses (id) ON DELETE CASCADE,
    file_path    TEXT    NOT NULL,
    start_line   INTEGER NOT NULL CHECK (start_line >= 1),
    end_line     INTEGER NOT NULL,
    -- 발췌한 소스(UTF-8 텍스트). 화면에서는 항상 텍스트로 렌더링한다(명세 7)
    content      TEXT    NOT NULL,
    created_at   TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (end_line >= start_line)
);
CREATE INDEX defect_excerpts_analysis ON defect_excerpts (analysis_id);

-- ---------------------------------------------------------------------------
-- 감사 로그 (명세 10, 13, 18.4, 19.7): append-only + 해시 체인
-- hash = SHA-256(prev_hash, occurred_at, actor_user_id, actor_label, action, target_type, target_id, details, ip)
--   (계산과 검증은 Support\AuditChain). 첫 행의 prev_hash는 audit_log_meta.anchor_hash
-- UPDATE는 항상 거부. DELETE는 보존 기간(90일) 정리 때만 audit_log_meta.purge_enabled=1로 허용한다
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    occurred_at    TEXT    NOT NULL CHECK (occurred_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    -- 행위자. CLI(설치 스크립트, 잠금 해제 등)·시스템 작업이면 NULL
    actor_user_id  INTEGER NULL REFERENCES users (id),
    -- 행위 당시의 로그인 ID 또는 'cli' / 'system'(사용자 정보가 바뀌어도 기록 유지)
    actor_label    TEXT    NOT NULL CHECK (length(actor_label) BETWEEN 1 AND 100),
    action         TEXT    NOT NULL CHECK (length(action) BETWEEN 1 AND 100),
    target_type    TEXT    NULL,
    target_id      TEXT    NULL,
    details        TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(details) AND json_type(details) = 'object'),
    ip             TEXT    NULL,
    prev_hash      TEXT    NOT NULL CHECK (length(prev_hash) = 64 AND prev_hash NOT GLOB '*[^0-9a-f]*'),
    hash           TEXT    NOT NULL UNIQUE CHECK (length(hash) = 64 AND hash NOT GLOB '*[^0-9a-f]*')
);
CREATE INDEX audit_log_time ON audit_log (occurred_at);
CREATE INDEX audit_log_target ON audit_log (target_type, target_id);

-- 해시 체인의 기준점과 정리 허용 플래그(행 하나)
CREATE TABLE audit_log_meta (
    id               INTEGER PRIMARY KEY CHECK (id = 1),
    -- 남아 있는 첫 행의 prev_hash. 처음은 0이 64개, 정리 후에는 마지막으로 지운 행의 hash
    anchor_hash      TEXT    NOT NULL CHECK (length(anchor_hash) = 64),
    -- 마지막으로 지운 행의 id(정리한 적 없으면 0)
    purged_through   INTEGER NOT NULL DEFAULT 0 CHECK (purged_through >= 0),
    purge_enabled    INTEGER NOT NULL DEFAULT 0 CHECK (purge_enabled IN (0, 1))
);
INSERT INTO audit_log_meta (id, anchor_hash, purged_through, purge_enabled)
VALUES (1, '0000000000000000000000000000000000000000000000000000000000000000', 0, 0);

CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log_is_append_only');
END;

CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
WHEN (SELECT purge_enabled FROM audit_log_meta WHERE id = 1) <> 1
BEGIN
    SELECT RAISE(ABORT, 'audit_log_is_append_only');
END;

CREATE TRIGGER audit_log_meta_no_delete BEFORE DELETE ON audit_log_meta
BEGIN
    SELECT RAISE(ABORT, 'audit_log_meta_is_required');
END;

-- ---------------------------------------------------------------------------
-- 분석 도구 버전 (명세 12, 18.4, 19.1)
-- ---------------------------------------------------------------------------
CREATE TABLE tool_versions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    tool               TEXT    NOT NULL CHECK (tool IN ('ruff', 'eslint', 'git', 'p4')),
    version            TEXT    NOT NULL CHECK (length(version) BETWEEN 1 AND 50),
    os                 TEXT    NOT NULL CHECK (os IN ('windows', 'linux')),
    -- active: 사용 가능 / blocked: 관리자가 차단 / pinned: 관리자가 고정(자동 최신 선택 중단) / invalid: 체크섬·스모크 실패
    status             TEXT    NOT NULL CHECK (status IN ('active', 'blocked', 'pinned', 'invalid')),
    checksum_ok        INTEGER NULL CHECK (checksum_ok IS NULL OR checksum_ok IN (0, 1)),
    checksum_checked_at TEXT   NULL,
    smoke_ok           INTEGER NULL CHECK (smoke_ok IS NULL OR smoke_ok IN (0, 1)),
    smoke_checked_at   TEXT    NULL,
    smoke_message      TEXT    NULL,
    detected_at        TEXT    NOT NULL,
    updated_at         TEXT    NOT NULL,
    UNIQUE (tool, version, os),
    CHECK (status NOT IN ('active', 'pinned') OR (checksum_ok = 1 AND smoke_ok = 1)),
    CHECK (checksum_checked_at IS NULL OR checksum_checked_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (smoke_checked_at IS NULL OR smoke_checked_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (detected_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
-- 도구·OS마다 고정 버전은 하나만
CREATE UNIQUE INDEX tool_versions_one_pinned ON tool_versions (tool, os) WHERE status = 'pinned';
