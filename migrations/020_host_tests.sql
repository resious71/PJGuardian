-- 020: 허용 호스트 연결 테스트 결과(P26B) + 저장소 가져오기 경고(심볼릭 링크 건너뜀). 019 다음에 적용한다.
--
-- 1) repo_allowed_hosts  마지막 호스트 연결 테스트(관리자 "연결 테스트"·"전체 테스트"): 시각, 결과 코드, 소요(ms), 인증서 만료일·발급자.
--                        저장소·자격 증명 없이 호스트 자체만 본 결과다. 프로젝트의 "연결 상태"(project_repo_connections.last_test_*)와 별개.
-- 2) analyses.source_warnings  저장소에서 가져온 소스의 경고(JSON 배열). 지금은 심볼릭 링크 건너뜀 하나:
--                        [{"code":"symlinks_skipped","count":N,"paths":[처음 20개 경로]}]. 링크 대상 경로는 저장하지 않는다.
--                        업로드 분석은 NULL(업로드 경고는 그대로 uploads.warnings).
-- 기존 행·데이터는 바꾸지 않는다(새 열은 모두 NULL).
-- 되돌리기(사본에서 확인, docs/results/P26B.md):
--   ALTER TABLE analyses DROP COLUMN source_warnings;
--   ALTER TABLE repo_allowed_hosts DROP COLUMN cert_issuer; … DROP COLUMN cert_not_after; … DROP COLUMN last_test_ms; … DROP COLUMN last_test_code; … DROP COLUMN last_test_at;
--   DELETE FROM schema_migrations WHERE version = '020';   (버전은 문자열)

ALTER TABLE repo_allowed_hosts ADD COLUMN last_test_at TEXT NULL CHECK (last_test_at IS NULL OR last_test_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE repo_allowed_hosts ADD COLUMN last_test_code TEXT NULL CHECK (last_test_code IS NULL OR length(last_test_code) <= 40);
ALTER TABLE repo_allowed_hosts ADD COLUMN last_test_ms INTEGER NULL CHECK (last_test_ms IS NULL OR last_test_ms >= 0);
-- 인증서 만료 시각(UTC), 발급자(CN 또는 O, 200자 이하)
ALTER TABLE repo_allowed_hosts ADD COLUMN cert_not_after TEXT NULL CHECK (cert_not_after IS NULL OR cert_not_after GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE repo_allowed_hosts ADD COLUMN cert_issuer TEXT NULL CHECK (cert_issuer IS NULL OR length(cert_issuer) <= 200);

ALTER TABLE analyses ADD COLUMN source_warnings TEXT NULL CHECK (source_warnings IS NULL OR (length(source_warnings) <= 20000 AND json_valid(source_warnings) AND json_type(source_warnings) = 'array'));
