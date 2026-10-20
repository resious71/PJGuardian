-- 016: 관리자 규칙 켜기·끄기(P22B). 015 다음에 적용한다.
--
-- 서버 기본 설정 파일(config/ruff.default.toml, config/eslint.default.json)은 그대로 "기본값"이고, 관리자가 화면에서 바꾼 것만
-- 여기에 "차이(override)"로 저장한다. 분석은 시작할 때 기본 설정 + override로 실효 설정을 만들어 쓴다(Services\Rules\RuleOverrides,
-- Services\Tools\EffectiveRuleConfig). 기본값과 같아지는 변경은 행을 지운다(저장은 차이만).
--
-- 1) rule_overrides       (도구, 규칙) 하나에 한 행. 규칙 키는 P22 표기(F401, no-undef, @typescript-eslint/no-unused-vars — 도구 접두사 없음)
-- 2) rule_override_state  도구별 개정 번호(변경할 때마다 1 증가: 화면·API의 최신 확인, 캐시 무효화)
-- 3) rule_enable_blocks   켤 수 없는 규칙(버전별): 타입 정보 필요(도구 meta), 옵션 없이는 검사하지 않음(resources/rules/requires_options.json).
--                         규칙 데이터 적재(RuleLoader) 때마다 다시 쓴다. 상태(removed·deprecated·preview)로 막는 경우는 rules.status로 판정한다
--
-- 기존 표·데이터는 바꾸지 않는다(행이 없으면 지금과 같은 분석·같은 설정 해시).
-- 되돌리기: DROP TABLE rule_enable_blocks; DROP TABLE rule_override_state; DROP TABLE rule_overrides; 와 schema_migrations의 016 행 삭제
--          (관리자 변경이 사라지고 다음 분석부터 서버 기본 설정만 쓴다 — 해시가 기본으로 돌아가 "설정 변경"으로 보인다).

CREATE TABLE rule_overrides (
    tool          TEXT    NOT NULL CHECK (tool IN ('ruff', 'eslint')),
    rule          TEXT    NOT NULL CHECK (length(rule) BETWEEN 1 AND 190),
    -- 관리자가 정한 값(1 = 분석에서 켬, 0 = 끔). 기본값과 다를 때만 행이 있다
    enabled       INTEGER NOT NULL CHECK (enabled IN (0, 1)),
    -- 변경 시점의 서버 기본값(기록용 — 기본값은 도구 버전·기본 설정 파일에 따라 바뀔 수 있다)
    base_enabled  INTEGER NOT NULL CHECK (base_enabled IN (0, 1)),
    reason        TEXT    NULL CHECK (reason IS NULL OR length(reason) <= 200),
    -- 변경 시점의 도구 활성 버전(기록용)
    tool_version  TEXT    NOT NULL CHECK (length(tool_version) BETWEEN 1 AND 40),
    changed_by    INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    changed_at    TEXT    NOT NULL CHECK (changed_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    PRIMARY KEY (tool, rule)
);

CREATE TABLE rule_override_state (
    tool        TEXT    PRIMARY KEY CHECK (tool IN ('ruff', 'eslint')),
    revision    INTEGER NOT NULL DEFAULT 0 CHECK (revision >= 0),
    updated_at  TEXT    NULL CHECK (updated_at IS NULL OR updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);
INSERT INTO rule_override_state (tool, revision) VALUES ('ruff', 0), ('eslint', 0);

CREATE TABLE rule_enable_blocks (
    tool     TEXT NOT NULL,
    version  TEXT NOT NULL,
    rule     TEXT NOT NULL CHECK (length(rule) BETWEEN 1 AND 190),
    reason   TEXT NOT NULL CHECK (reason IN ('requires_type_info', 'requires_options')),
    PRIMARY KEY (tool, version, rule, reason),
    FOREIGN KEY (tool, version) REFERENCES rule_versions (tool, version) ON DELETE CASCADE
);
