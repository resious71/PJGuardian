-- 관리자의 비밀번호 직접 변경과 로그인 알림(2026-10-03 사용자 결정, 명세 13장·19.10)
--
-- users.admin_password_set_at: 관리자가 사용자 관리 화면에서 이 사용자의 비밀번호를 정한 시각(UTC).
--   다음 로그인 성공 때 "관리자가 비밀번호를 변경했다"는 알림을 한 번 보여 주고 NULL로 비운다. 변경은 강제하지 않는다.
-- sessions.flash: 다음 화면 한 번에 보여 줄 알림(JSON 객체, 예 {"code":"password_set_by_admin","at":"…"}).
--   로그인 후 리다이렉트된 화면이 읽고 비운다.

ALTER TABLE users ADD COLUMN admin_password_set_at TEXT NULL
    CHECK (admin_password_set_at IS NULL OR admin_password_set_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');

ALTER TABLE sessions ADD COLUMN flash TEXT NULL
    CHECK (flash IS NULL OR (json_valid(flash) AND json_type(flash) = 'object' AND length(flash) <= 1000));
