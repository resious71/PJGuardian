-- P10: DB 기반 세션 (명세 3: 세션 기반 인증, 30분 무활동 만료 / 명세 13: 비활성화·역할 변경 시 세션 즉시 무효화)
--
-- - 쿠키에는 무작위 세션 ID(32바이트)를 두고, DB에는 그 SHA-256만 저장한다(DB가 유출돼도 세션을 가로챌 수 없음).
-- - user_id가 NULL이면 로그인 전 세션이다. 로그인 화면의 CSRF 토큰을 담는다(명세 10: 모든 폼에 CSRF).
--   로그인하면 행을 지우고 새 ID·새 토큰으로 다시 만든다(세션 고정 공격 방지).
-- - 만료 판정은 last_activity_at(마지막 활동)으로 한다. 갱신은 일정 간격(auth.session_touch_interval) 이내면 건너뛴다.
-- - 사용자 비활성화·역할 변경·임시 비밀번호 발급 시 DELETE FROM sessions WHERE user_id = ?로 즉시 폐기한다.
-- - 만료된 행은 로그인할 때와 정리 작업(P14)이 지운다.

CREATE TABLE sessions (
    id_hash           TEXT    PRIMARY KEY CHECK (length(id_hash) = 64 AND id_hash NOT GLOB '*[^0-9a-f]*'),
    user_id           INTEGER NULL REFERENCES users (id),
    csrf_token        TEXT    NOT NULL CHECK (length(csrf_token) = 64 AND csrf_token NOT GLOB '*[^0-9a-f]*'),
    created_at        TEXT    NOT NULL CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_activity_at  TEXT    NOT NULL CHECK (last_activity_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    ip                TEXT    NULL CHECK (ip IS NULL OR length(ip) <= 45),
    user_agent        TEXT    NULL CHECK (user_agent IS NULL OR length(user_agent) <= 255)
);

-- 사용자의 세션 일괄 폐기
CREATE INDEX sessions_user ON sessions (user_id);
-- 만료 세션 정리
CREATE INDEX sessions_activity ON sessions (last_activity_at);
