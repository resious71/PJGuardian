-- 027: 앱 비밀 이름 제약 넓히기(P28C OIDC, 사용자 결정 2026-10-11). 026 다음에 적용한다.
--
-- 026의 app_secrets는 이름을 CHECK (name IN ('ldap.bind_password'))로 막아 두어 OIDC 클라이언트 비밀(oidc.client_secret)을 넣을 수 없다.
-- 이름 제약을 "형식 검사"(소문자로 시작하는 <섹션>.<이름>, 소문자·숫자·밑줄·점, 3~64자)로 바꾸고, 허용 이름 목록은 코드
-- (Services\Auth\AppSecrets::NAMES) 한 곳에서 지킨다 — P28D(SAML)가 비밀을 더할 때 마이그레이션이 다시 필요 없게.
-- app_secrets는 자식 테이블(users 참조)이고 이 표를 참조하는 표·트리거가 없으므로 외래 키를 끄지 않고 다시 만든다(행 그대로).
--
-- 되돌리기(사본에서 확인, docs/results/P28C.md): ldap.bind_password 말고 다른 이름의 행을 먼저 지운다.
--   DELETE FROM app_secrets WHERE name <> 'ldap.bind_password';
--   그리고 026의 CREATE TABLE app_secrets 정의로 같은 방식(새 표 → 복사 → DROP → RENAME)으로 다시 만든다.
--   DELETE FROM schema_migrations WHERE version = '027';   (버전은 문자열)

CREATE TABLE app_secrets_new (
    name        TEXT    PRIMARY KEY CHECK (length(name) BETWEEN 3 AND 64 AND name GLOB '[a-z]*.[a-z]*' AND name NOT GLOB '*[^a-z0-9._]*'),
    cipher      TEXT    NOT NULL CHECK (length(cipher) <= 8000),
    nonce       TEXT    NOT NULL CHECK (length(nonce) <= 64),
    key_id      TEXT    NOT NULL CHECK (length(key_id) = 16 AND key_id NOT GLOB '*[^0-9a-f]*'),
    updated_at  TEXT    NOT NULL CHECK (updated_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    updated_by  INTEGER NULL REFERENCES users (id) ON DELETE SET NULL
) WITHOUT ROWID;

INSERT INTO app_secrets_new (name, cipher, nonce, key_id, updated_at, updated_by)
    SELECT name, cipher, nonce, key_id, updated_at, updated_by FROM app_secrets;
DROP TABLE app_secrets;
ALTER TABLE app_secrets_new RENAME TO app_secrets;
