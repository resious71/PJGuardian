-- 025: 화면 언어·테마의 계정별 설정(P27D, 사용자 결정 2026-10-09). 024 다음에 적용한다.
--
-- users.ui_language  ko | en | NULL(자동 — 쿠키 pjg_lang > 브라우저 Accept-Language > ko, Services\UiPreferences 한 곳에서 결정)
-- users.ui_theme     light | dark | system | NULL(자동 — 쿠키 pjg_theme > system)
-- 기존 행은 모두 NULL(지금처럼 동작). 본인만 PUT /api/v1/users/me/preferences로 바꾼다.
--
-- 되돌리기(사본에서 확인, docs/results/P27D.md):
--   ALTER TABLE users DROP COLUMN ui_theme;
--   ALTER TABLE users DROP COLUMN ui_language;
--   DELETE FROM schema_migrations WHERE version = '025';   (버전은 문자열)

ALTER TABLE users ADD COLUMN ui_language TEXT NULL CHECK (ui_language IS NULL OR ui_language IN ('ko', 'en'));
ALTER TABLE users ADD COLUMN ui_theme TEXT NULL CHECK (ui_theme IS NULL OR ui_theme IN ('light', 'dark', 'system'));
