-- 015: 규칙 설명(P22, 명세 11장). 014 다음에 적용한다.
--
-- 1) rule_versions  적재한 도구 버전(도구별 여러 버전). is_active = 규칙 설명 페이지의 기준(서버의 활성 버전, 도구당 하나)
-- 2) rules          도구 버전별 규칙(도구가 내놓은 정보 + 사전 작성 보강). (도구, 버전, 규칙)이 유일
-- 3) rules_fts      검색 색인(FTS5 trigram — 부분 일치, 대소문자 무시). 외부 내용 표(rules)를 가리키며 적재 뒤 rebuild
-- 4) rule_changes   인접한 두 적재 버전(도구별) 사이의 규칙 변경(적재 때 미리 계산)
-- 5) admin_tasks    작업 종류에 rules_load(규칙 데이터 적재) 추가 — SQLite는 CHECK를 바꿀 수 없어 표를 다시 만든다(같은 열·같은 id·같은 인덱스)
--
-- 규칙 키(rule): 결함 rule_key의 도구 접두사를 뺀 값. ruff F401, ESLint no-undef, @typescript-eslint/no-unused-vars.
-- 되돌리기: DROP TABLE rule_changes; DROP TABLE rules_fts; DROP TABLE rules; DROP TABLE rule_versions;
--          admin_tasks는 rules_load 행을 지운 뒤 009의 정의로 같은 방법(새 표 → 복사 → 이름 바꾸기 → 인덱스 2개)으로 다시 만들고,
--          schema_migrations의 015 행을 지운다.

CREATE TABLE rule_versions (
    tool               TEXT    NOT NULL CHECK (tool IN ('ruff', 'eslint')),
    version            TEXT    NOT NULL CHECK (length(version) BETWEEN 1 AND 40),
    -- 함께 읽은 구성 요소 버전(JSON 객체, 예: {"@typescript-eslint/eslint-plugin":"8.71.0"})
    components         TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(components) AND json_type(components) = 'object'),
    is_active          INTEGER NOT NULL DEFAULT 0 CHECK (is_active IN (0, 1)),
    -- 이전 적재 버전이 없어 변경 내역이 없는 버전("기준 버전")
    baseline           INTEGER NOT NULL DEFAULT 0 CHECK (baseline IN (0, 1)),
    rule_count         INTEGER NOT NULL DEFAULT 0 CHECK (rule_count >= 0),
    -- 기본 심각도를 정할 때 쓴 매핑 버전(resources/severity, P21S). 매핑이 바뀌면 다시 적재한다
    severity_map_version INTEGER NOT NULL DEFAULT 0,
    content_hash       TEXT    NOT NULL CHECK (length(content_hash) = 64),
    loaded_at          TEXT    NOT NULL CHECK (loaded_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (tool, version)
);
CREATE UNIQUE INDEX rule_versions_one_active ON rule_versions (tool) WHERE is_active = 1;

CREATE TABLE rules (
    id               INTEGER PRIMARY KEY,
    tool             TEXT    NOT NULL,
    version          TEXT    NOT NULL,
    rule             TEXT    NOT NULL CHECK (length(rule) BETWEEN 1 AND 190),
    name             TEXT    NOT NULL DEFAULT '',
    summary          TEXT    NOT NULL DEFAULT '',
    -- 상세 설명(마크다운 원문, 화면은 안전한 부분집합만 렌더링). 없으면 ''
    description      TEXT    NOT NULL DEFAULT '',
    category         TEXT    NULL,
    -- ruff linter(플러그인 출처) / ESLint 플러그인('' = 코어, '@typescript-eslint')
    origin           TEXT    NOT NULL DEFAULT '',
    -- 서버 기본 설정에서 켜짐(ruff: config/ruff.default.toml의 선택, ESLint: eslint:recommended + @typescript-eslint/recommended)
    default_enabled  INTEGER NOT NULL CHECK (default_enabled IN (0, 1)),
    -- 자동 수정: none | sometimes | always
    fixable          TEXT    NOT NULL CHECK (fixable IN ('none', 'sometimes', 'always')),
    has_suggestions  INTEGER NOT NULL DEFAULT 0 CHECK (has_suggestions IN (0, 1)),
    has_options      INTEGER NULL CHECK (has_options IS NULL OR has_options IN (0, 1)),
    -- 상태: stable | preview(ruff 미리보기) | deprecated | removed(ruff가 남겨 둔 삭제 규칙)
    status           TEXT    NOT NULL CHECK (status IN ('stable', 'preview', 'deprecated', 'removed')),
    -- 폐기 정보(JSON: {message?, since?, replaced_by:[규칙…]}) — 도구가 알려 준 값만
    deprecation      TEXT    NULL CHECK (deprecation IS NULL OR json_valid(deprecation)),
    docs_url         TEXT    NULL CHECK (docs_url IS NULL OR docs_url GLOB 'https://*'),
    -- 기본 심각도(P21S 매핑)와 근거 단계(rule | prefix | group | none), 근거 문장
    severity         TEXT    NOT NULL CHECK (severity IN ('critical', 'high', 'medium', 'low', 'not_defined')),
    severity_level   TEXT    NOT NULL CHECK (severity_level IN ('rule', 'prefix', 'group', 'none')),
    severity_reason  TEXT    NOT NULL DEFAULT '',
    -- 보강 자료(resources/rules/enrichment)로 채운 항목의 출처 주소(JSON 배열). 도구 값만이면 NULL
    enrichment       TEXT    NULL CHECK (enrichment IS NULL OR json_valid(enrichment)),
    content_hash     TEXT    NOT NULL CHECK (length(content_hash) = 64),
    FOREIGN KEY (tool, version) REFERENCES rule_versions (tool, version) ON DELETE CASCADE,
    UNIQUE (tool, version, rule)
);
CREATE INDEX rules_rule ON rules (tool, rule, version);

CREATE VIRTUAL TABLE rules_fts USING fts5(rule, name, summary, description, content = 'rules', content_rowid = 'id', tokenize = 'trigram');

CREATE TABLE rule_changes (
    id            INTEGER PRIMARY KEY,
    tool          TEXT    NOT NULL,
    from_version  TEXT    NOT NULL,
    to_version    TEXT    NOT NULL,
    rule          TEXT    NOT NULL,
    kind          TEXT    NOT NULL CHECK (kind IN ('added', 'removed', 'renamed', 'description', 'default_enabled', 'default_severity', 'deprecated', 'fixable')),
    -- 이전·이후 값 요약(텍스트, 설명은 원문 그대로 — 화면에서 접어 보여 준다). renamed는 before = 이전 규칙 이름
    before        TEXT    NULL,
    after         TEXT    NULL,
    FOREIGN KEY (tool, to_version) REFERENCES rule_versions (tool, version) ON DELETE CASCADE
);
CREATE INDEX rule_changes_rule ON rule_changes (tool, rule, to_version);
CREATE INDEX rule_changes_version ON rule_changes (tool, to_version, kind);

-- admin_tasks: kind에 rules_load 추가(009 정의 그대로, CHECK만 다름)
CREATE TABLE admin_tasks_new (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kind          TEXT    NOT NULL CHECK (kind IN ('tool_verify', 'cleanup_run', 'disk_scan', 'rules_load')),
    target        TEXT    NOT NULL DEFAULT '' CHECK (length(target) <= 64),
    status        TEXT    NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'succeeded', 'failed')),
    requested_by  INTEGER NULL REFERENCES users (id),
    result        TEXT    NULL CHECK (result IS NULL OR (json_valid(result) AND json_type(result) = 'object')),
    created_at    TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    started_at    TEXT    NULL,
    finished_at   TEXT    NULL,
    CHECK ((status = 'queued') = (started_at IS NULL)),
    CHECK ((status IN ('succeeded', 'failed')) = (finished_at IS NOT NULL))
);
INSERT INTO admin_tasks_new (id, kind, target, status, requested_by, result, created_at, started_at, finished_at)
    SELECT id, kind, target, status, requested_by, result, created_at, started_at, finished_at FROM admin_tasks;
DROP TABLE admin_tasks;
ALTER TABLE admin_tasks_new RENAME TO admin_tasks;
CREATE UNIQUE INDEX admin_tasks_one_active ON admin_tasks (kind, target) WHERE status IN ('queued', 'running');
CREATE INDEX admin_tasks_status_id ON admin_tasks (status, id);
