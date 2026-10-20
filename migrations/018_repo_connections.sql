-- 018: 소스 저장소 연동(P25, 명세 18). 017 다음에 적용한다.
--
-- 1) project_repo_connections  프로젝트당 연결 하나(행이 없으면 "업로드 전용"). Git(HTTPS·HTTP URL, 브랜치, 하위 경로, 토큰) 또는
--                              Perforce(P4PORT, depot 경로, 사용자명, 비밀번호·티켓, SSL 서버 지문).
--                              자격 증명은 인증 암호화(AES-256-GCM, 키는 config/secrets.ini의 encryption_key 또는 환경 변수 —
--                              DB에 두지 않는다)한 본문·nonce·키 식별자만 저장한다. 평문은 어디에도 없다.
--                              마지막 연결 테스트·가져오기 결과(상태·시각·오류 코드)와 낙관적 개정 번호(동시 수정 409).
-- 2) repo_allowed_hosts        접속을 허용한 호스트(SSRF 방지). 목록에 없는 호스트는 접속하지 않는다. HTTP(평문)는 allow_http=1만,
--                              github_links=1이면 커밋 링크(/{owner}/{repo}/commit/{hash})를 만든다(github.com은 자동).
-- 3) analyses.source_url       가져온 저장소 주소(사용자 정보 없는 형태, 커밋 링크용), analyses.fetched_at(가져온 시각).
--                              출처 종류·브랜치/depot 경로·커밋/changelist·실행 주체는 001의 source_kind·source_ref·source_revision·
--                              trigger_kind를 쓴다(기존 분석은 이미 source_kind='upload' — 되채움 필요 없음).
--
-- 프로젝트를 지우면 연결(암호문 포함)도 지운다(CASCADE). 사용자 행은 지우지 않는다(updated_by 등은 참조만).
-- 기존 표·데이터는 바꾸지 않는다(analyses에 NULL 열 두 개만 더한다).
-- 되돌리기: DROP TABLE project_repo_connections; DROP TABLE repo_allowed_hosts;
--          ALTER TABLE analyses DROP COLUMN fetched_at; ALTER TABLE analyses DROP COLUMN source_url;(SQLite 3.35 이상)
--          와 schema_migrations의 018 행 삭제. 저장소 출처 분석 행(source_kind git·perforce)은 그대로 남는다.

CREATE TABLE project_repo_connections (
    project_id        INTEGER PRIMARY KEY REFERENCES projects (id) ON DELETE CASCADE,
    kind              TEXT    NOT NULL CHECK (kind IN ('git', 'perforce')),
    -- Git: https://host[:port]/path(사용자 정보 없음) / Perforce: [ssl:|tcp:|tcp4:]host:port
    location          TEXT    NOT NULL CHECK (length(location) BETWEEN 1 AND 500),
    -- Git 브랜치(NULL = 원격 기본 브랜치)
    branch            TEXT    NULL CHECK (branch IS NULL OR length(branch) BETWEEN 1 AND 200),
    -- Git 하위 경로(선택, 상대) / Perforce depot 경로(//depot/a/...)
    path              TEXT    NULL CHECK (path IS NULL OR length(path) BETWEEN 1 AND 500),
    -- Git: HTTP 기본 인증 사용자명(선택, 비우면 x-access-token) / Perforce: 사용자명
    username          TEXT    NULL CHECK (username IS NULL OR length(username) BETWEEN 1 AND 200),
    -- 자격 증명: token(Git) | password | ticket(Perforce). 암호문·nonce는 base64, 키 식별자는 키 SHA-256 앞 16자리
    secret_kind       TEXT    NULL CHECK (secret_kind IS NULL OR secret_kind IN ('token', 'password', 'ticket')),
    secret_cipher     TEXT    NULL CHECK (secret_cipher IS NULL OR length(secret_cipher) <= 8000),
    secret_nonce      TEXT    NULL CHECK (secret_nonce IS NULL OR length(secret_nonce) <= 64),
    secret_key_id     TEXT    NULL CHECK (secret_key_id IS NULL OR (length(secret_key_id) = 16 AND secret_key_id NOT GLOB '*[^0-9a-f]*')),
    -- Perforce SSL 서버 지문(관리자가 확인해 입력, XX:XX:… 대문자 16진)
    p4_fingerprint    TEXT    NULL CHECK (p4_fingerprint IS NULL OR length(p4_fingerprint) <= 200),
    -- 가져오기 타임아웃 재정의(초, NULL = 설정 repository.fetch_timeout)
    fetch_timeout     INTEGER NULL CHECK (fetch_timeout IS NULL OR fetch_timeout BETWEEN 60 AND 86400),
    last_test_status  TEXT    NULL CHECK (last_test_status IS NULL OR last_test_status IN ('ok', 'error')),
    last_test_at      TEXT    NULL CHECK (last_test_at IS NULL OR last_test_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_test_code    TEXT    NULL CHECK (last_test_code IS NULL OR length(last_test_code) <= 60),
    last_fetch_status TEXT    NULL CHECK (last_fetch_status IS NULL OR last_fetch_status IN ('ok', 'error')),
    last_fetch_at     TEXT    NULL CHECK (last_fetch_at IS NULL OR last_fetch_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_fetch_code   TEXT    NULL CHECK (last_fetch_code IS NULL OR length(last_fetch_code) <= 60),
    last_fetch_revision TEXT  NULL CHECK (last_fetch_revision IS NULL OR length(last_fetch_revision) <= 64),
    -- 낙관적 개정 번호(저장할 때마다 +1, 화면이 보낸 값과 다르면 409)
    revision          INTEGER NOT NULL DEFAULT 1 CHECK (revision >= 1),
    created_by        INTEGER NULL REFERENCES users (id),
    created_at        TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    updated_by        INTEGER NULL REFERENCES users (id),
    updated_at        TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    CHECK ((secret_kind IS NULL) = (secret_cipher IS NULL) AND (secret_cipher IS NULL) = (secret_nonce IS NULL) AND (secret_nonce IS NULL) = (secret_key_id IS NULL))
);

CREATE TABLE repo_allowed_hosts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    -- 소문자, 후행 점 없음. 이름 또는 IP(IPv6는 대괄호 없이)
    host          TEXT    NOT NULL CHECK (length(host) BETWEEN 1 AND 253 AND host = lower(host) AND host NOT LIKE '%.'),
    -- 0 = 모든 포트
    port          INTEGER NOT NULL DEFAULT 0 CHECK (port BETWEEN 0 AND 65535),
    allow_http    INTEGER NOT NULL DEFAULT 0 CHECK (allow_http IN (0, 1)),
    github_links  INTEGER NOT NULL DEFAULT 0 CHECK (github_links IN (0, 1)),
    note          TEXT    NOT NULL DEFAULT '' CHECK (length(note) <= 200),
    created_by    INTEGER NULL REFERENCES users (id),
    created_at    TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    updated_at    TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    UNIQUE (host, port)
);

ALTER TABLE analyses ADD COLUMN source_url TEXT NULL CHECK (source_url IS NULL OR length(source_url) <= 500);
ALTER TABLE analyses ADD COLUMN fetched_at TEXT NULL CHECK (fetched_at IS NULL OR fetched_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
