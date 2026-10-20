-- X02: 현재 비밀번호가 어떻게 정해졌는지(발급 방식). 하드닝 점검의 "관리자 초기 비밀번호 미변경" 판정에 쓴다.
--   user      : 사용자가 직접 정함(정책 통과)
--   temporary : 관리자·CLI가 발급한 무작위 임시 비밀번호(24시간 만료, temp_password_expires_at)
--   initial   : 최초 관리자의 고정 초기 비밀번호(사용자 확정 결정, X02). 첫 로그인 때 변경 강제, 만료 없음
-- 비밀번호 값을 비교하지 않고 이 상태로 판정한다. LDAP 계정은 비밀번호가 없으므로 user로 둔다.

ALTER TABLE users ADD COLUMN password_kind TEXT NOT NULL DEFAULT 'user'
    CHECK (password_kind IN ('user', 'temporary', 'initial'));

UPDATE users SET password_kind = 'temporary' WHERE temp_password_expires_at IS NOT NULL;
