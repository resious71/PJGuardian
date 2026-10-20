-- 029: FAQ(P29B) — 가이드 메뉴의 FAQ 탭. 읽기는 로그인한 모든 사용자(비공개 항목은 admin만), 쓰기는 admin만. 028 다음에 적용한다.
--
-- faq_items 한 줄 = FAQ 한 항목. 프로젝트에 속하지 않는다. 초기 항목은 넣지 않는다(관리자가 화면에서 채움).
-- - category·question·answer는 서버가 정리한 값(앞뒤 공백 제거, category는 연속 공백 하나로)을 그대로 저장하고 길이는 CHECK로도 막는다.
-- - answer는 안전한 마크다운 부분집합의 원문(P22 렌더러가 화면에서 그림 — HTML로 해석하지 않음).
-- - search_text: 검색용(분류·질문·답변을 NFC + 소문자로 이은 값, 서버가 저장할 때마다 다시 만든다). 항목 수 상한(500)이 작아 FTS 대신 LIKE(이스케이프)로 찾는다.
-- - version: 수정할 때마다 +1(낙관적 잠금 — 다르면 409 faq_conflict). sort_order: 같은 분류 안의 표시 순서.
-- - created_by·updated_by: 계정 삭제(승인 대기 계정만 삭제 가능) 시 NULL.
--
-- 되돌리기(사본에서 확인, docs/results/P29B.md):
--   DROP TABLE faq_items;   (인덱스 함께 사라짐, 다른 표가 참조하지 않음)
--   DELETE FROM schema_migrations WHERE version = '029';   (버전은 문자열)

CREATE TABLE faq_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    category      TEXT    NOT NULL CHECK (length(category) BETWEEN 1 AND 40 AND category = trim(category)),
    question      TEXT    NOT NULL CHECK (length(question) BETWEEN 1 AND 200 AND question = trim(question)),
    answer        TEXT    NOT NULL CHECK (length(answer) BETWEEN 1 AND 10000),
    search_text   TEXT    NOT NULL CHECK (length(search_text) <= 10300),
    sort_order    INTEGER NOT NULL DEFAULT 0 CHECK (sort_order BETWEEN 0 AND 1000000),
    is_published  INTEGER NOT NULL DEFAULT 1 CHECK (is_published IN (0, 1)),
    version       INTEGER NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_by    INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    updated_by    INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at    TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    updated_at    TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z')
);

-- 목록: 공개 여부로 거르고 분류·순서로 정렬
CREATE INDEX idx_faq_items_list ON faq_items (is_published, category, sort_order, id);
