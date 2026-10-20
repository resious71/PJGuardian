-- 030: 감사 로그 조회·보존 정리(P30) — 029 다음에 적용한다.
--
-- 1) audit_log_meta에 보관 경계 시각 두 열(체크포인트 = 마지막으로 지운 행 번호 purged_through(001부터 있음)·그 행의 hash = anchor_hash(001)·그 행의 시각)
--    - purged_through_at: 마지막으로 지운 행의 occurred_at(정리한 적 없으면 NULL). 화면 "무결성 검사"가 "이 시각 이전은 보존 기간 정리로 지움"을 보여 준다.
--    - purged_at: 마지막 정리를 실행한 시각(UTC).
--    체인 검증은 anchor_hash부터 남은 행을 잇는다(Support\AuditChain::verify — 바뀌지 않음). 감사 로그 자체(audit_log)의 열·해시 규칙은 바꾸지 않는다.
-- 2) 조회용 색인: 작업 이름 접두사 필터(action >= 'user.' AND action < 'user/')와 최신순(id) — 감사 로그 화면(/admin/audit-logs).
--    기간 필터는 001의 audit_log_time(occurred_at), 대상은 audit_log_target(target_type, target_id)을 그대로 쓴다.
--
-- 코드는 030 적용 전에도 동작한다(SchemaFeatures::auditCheckpoint — 경계 시각을 기록하지 않고 화면에 "알 수 없음"만).
--
-- 되돌리기(사본에서 확인, docs/results/P30.md):
--   DROP INDEX audit_log_action;
--   ALTER TABLE audit_log_meta DROP COLUMN purged_at;
--   ALTER TABLE audit_log_meta DROP COLUMN purged_through_at;
--   DELETE FROM schema_migrations WHERE version = '030';

ALTER TABLE audit_log_meta ADD COLUMN purged_through_at TEXT NULL
    CHECK (purged_through_at IS NULL OR purged_through_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');
ALTER TABLE audit_log_meta ADD COLUMN purged_at TEXT NULL
    CHECK (purged_at IS NULL OR purged_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z');

CREATE INDEX audit_log_action ON audit_log (action, id);
