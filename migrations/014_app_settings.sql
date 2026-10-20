-- 014: 화면에서 admin이 바꾸는 전체 공통 설정(P21C, 사용자 결정 2026-10-06). 012·013 다음에 적용한다.
--
-- 키-값 한 행씩. 허용 키·형식·범위는 코드(Services\AppSettings::SCHEMA)가 검사하고, 행이 없으면 코드의 기본값을 쓴다
-- (그래서 기존 DB에 이 표만 만들면 동작이 그대로다). 첫 키는 dashboard.trend_projects(대시보드 "프로젝트별 추이"에 보여 줄 프로젝트 수, 기본 5).
-- config.ini는 서버 관리자가 정하는 운영 설정이고, 이 표는 화면에서 바꾸는 표시 설정이다(바꾸면 감사 로그 setting.update).
--
-- 같은 단계(P21C)의 업로드 내용 요약: uploads.summary(JSON, 해제 결과의 숫자 — 파일 수·크기·제외·대상·직전 업로드 비교). 분석이 성공하면 업로드 폴더
-- (extract.json 포함)가 바로 지워지므로 결과 화면이 요약을 보여 주려면 DB에 남겨야 한다. 기존 행은 NULL(화면은 요약 없음 또는 extract.json).
--
-- 되돌리기: DROP TABLE app_settings; ALTER TABLE uploads DROP COLUMN summary;(SQLite 3.35 이상) 와 schema_migrations의 014 행 삭제
-- (설정 값은 기본값으로 돌아가고, 요약은 화면에서 사라진다).

CREATE TABLE app_settings (
    key        TEXT PRIMARY KEY CHECK (key GLOB '[a-z]*.[a-z]*' AND length(key) <= 64),
    value      TEXT NOT NULL CHECK (length(value) <= 1000),
    updated_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

ALTER TABLE uploads ADD COLUMN summary TEXT NULL CHECK (summary IS NULL OR json_valid(summary));
