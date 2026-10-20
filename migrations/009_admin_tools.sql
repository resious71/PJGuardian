-- 009: 관리자 도구·워커 관리(P20A, 명세 12, 14, 19.1, 19.3)
-- (프롬프트는 "008"이라고 했지만 008은 P17의 결함 요약 인덱스가 이미 썼다. 001~008은 고치지 않는다.)

-- 1) 도구 버전의 삭제 예정 표시와 삭제 기록. 상태 CHECK(active·blocked·pinned·invalid)는 그대로 두고 열만 더한다.
--    삭제 예정(removal_requested_at)인 버전은 차단과 같이 선택에서 빠진다(웹은 표시만, 실제 삭제는 서버 CLI remove).
--    CLI가 지우면 removed_at을 기록하고 행은 이력으로 남긴다(분석 기록의 tool_version 문자열이 가리키는 버전을 알 수 있게).
ALTER TABLE tool_versions ADD COLUMN removal_requested_at TEXT NULL
    CHECK (removal_requested_at IS NULL OR removal_requested_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE tool_versions ADD COLUMN removed_at TEXT NULL
    CHECK (removed_at IS NULL OR removed_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');

-- 2) 관리 작업 요청(웹은 넣기만, 워커가 자식 프로세스로 실행). 웹 요청 안에서 오래 걸리는 일(도구 재검증 = 전체 체크섬 + 스모크,
--    정리 작업, 디스크 사용량 계산)을 하지 않기 위해서다. 같은 종류·대상의 대기·실행 중 요청은 하나뿐(부분 고유 인덱스 = 중복 합침).
CREATE TABLE admin_tasks (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kind          TEXT    NOT NULL CHECK (kind IN ('tool_verify', 'cleanup_run', 'disk_scan')),
    -- 대상: tool_verify는 '<도구>@<버전>', 나머지는 ''
    target        TEXT    NOT NULL DEFAULT '' CHECK (length(target) <= 64),
    status        TEXT    NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'succeeded', 'failed')),
    requested_by  INTEGER NULL REFERENCES users (id),
    -- 결과 요약(JSON 객체, 서버 경로·비밀값 없음)
    result        TEXT    NULL CHECK (result IS NULL OR (json_valid(result) AND json_type(result) = 'object')),
    created_at    TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    started_at    TEXT    NULL,
    finished_at   TEXT    NULL,
    CHECK ((status = 'queued') = (started_at IS NULL)),
    CHECK ((status IN ('succeeded', 'failed')) = (finished_at IS NOT NULL))
);
CREATE UNIQUE INDEX admin_tasks_one_active ON admin_tasks (kind, target) WHERE status IN ('queued', 'running');
CREATE INDEX admin_tasks_status_id ON admin_tasks (status, id);

-- 3) 도구 설치 패키지 스테이징(웹은 zip을 <work_dir>/tool_staging/<id>/package.zip에 저장만 한다. 풀기·bin/ 설치는 CLI).
CREATE TABLE tool_staging (
    id               TEXT    PRIMARY KEY CHECK (length(id) = 32 AND id NOT GLOB '*[^0-9a-f]*'),
    tool             TEXT    NOT NULL CHECK (tool IN ('ruff', 'eslint')),
    file_name        TEXT    NOT NULL CHECK (length(file_name) BETWEEN 1 AND 255),
    total_size       INTEGER NOT NULL CHECK (total_size > 0),
    received_bytes   INTEGER NOT NULL DEFAULT 0,
    chunk_size       INTEGER NOT NULL CHECK (chunk_size > 0),
    total_chunks     INTEGER NOT NULL CHECK (total_chunks > 0),
    status           TEXT    NOT NULL DEFAULT 'receiving'
                     CHECK (status IN ('receiving', 'staged', 'installing', 'installed', 'install_failed', 'discarded')),
    sha256           TEXT    NULL CHECK (sha256 IS NULL OR (length(sha256) = 64 AND sha256 NOT GLOB '*[^0-9a-f]*')),
    -- 관리자가 입력한 배포처 공개 해시(선택). 다르면 설치 안내를 막는다(CLI도 거부)
    expected_sha256  TEXT    NULL CHECK (expected_sha256 IS NULL OR (length(expected_sha256) = 64 AND expected_sha256 NOT GLOB '*[^0-9a-f]*')),
    -- CLI 설치 결과(JSON 객체: ok, version, status, message)
    result           TEXT    NULL CHECK (result IS NULL OR (json_valid(result) AND json_type(result) = 'object')),
    uploaded_by      INTEGER NOT NULL REFERENCES users (id),
    created_at       TEXT    NOT NULL,
    updated_at       TEXT    NOT NULL
);
CREATE INDEX tool_staging_status ON tool_staging (status, updated_at);
