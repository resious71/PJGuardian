-- 007: 발췌의 인코딩 대체 표시(P16, 명세 7)
-- 원본 소스가 UTF-8이 아니어서(예: CP949) 잘못된 바이트를 대체 문자(U+FFFD)로 바꿔 저장했으면 1.
-- 결함 상세 화면(P17)이 "원본 인코딩을 표시할 수 없어 일부 글자를 대체함"을 보여 주는 데 쓴다.
ALTER TABLE defect_excerpts ADD COLUMN content_replaced INTEGER NOT NULL DEFAULT 0 CHECK (content_replaced IN (0, 1));
