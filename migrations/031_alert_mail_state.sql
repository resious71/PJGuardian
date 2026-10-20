-- 031: 관리자 장애 이메일 발송 상태(P30B) — 030 다음에 적용한다.
--
-- 사건 하나(사건 키)마다 한 행: 같은 사건은 alert_mail.resend_interval(기본 1시간)에 한 번만 보내고, 풀리면 복구 메일 한 통.
--   event_key   사건 키 — worker_down, queue_stalled, disk_warn, disk_block, backup_failed, backup_stale, tool_verify_failed:<도구>,
--               repo_fetch_failed:<프로젝트 id>, cert_expiring, cert_expired (수준이 다른 키는 다른 사건 — 경고→오류는 바로 보냄)
--   kind        사건 종류(설정 alert_mail.events의 값): worker_down·queue_stalled·disk·backup·tool·repo·cert
--   level       warn(경고) | fail(오류)
--   status      active(이어지는 중) | resolved(풀림 — 다시 생기면 새로 시작)
--   first_at    이번 active가 시작된 시각, last_seen_at 마지막으로 판정에 나온 시각, resolved_at 풀린 시각
--   last_sent_at·sent_count  마지막 발송 시각·이번 active 동안 보낸 횟수(복구 메일 포함하지 않음), suppressed_count 간격 안이라 보내지 않은 횟수
--   last_result 마지막 발송 결과 코드(ok·sending·connect·tls·auth·rejected·timeout·config·invalid) — 비밀·서버 응답 원문 없음
-- 갱신은 워커의 주기 판정에서만(여러 워커가 동시에 판정해도 BEGIN IMMEDIATE 트랜잭션으로 선점 — 같은 사건을 두 번 보내지 않음).
-- 보존: resolved 후 alert_mail.state_retention(30일)이 지나면 정리 작업(tools/cleanup.php)이 지운다.
--
-- 코드는 031 적용 전에도 동작한다(SchemaFeatures::alertMail — 표가 없으면 메일을 보내지 않고 모니터링에 "설정 필요(031)").
--
-- 되돌리기(사본에서 확인, docs/results/P30B.md):
--   DROP TABLE alert_mail_state;   (색인 함께 사라짐, 다른 표가 참조하지 않음)
--   DELETE FROM schema_migrations WHERE version = '031';

CREATE TABLE alert_mail_state (
    event_key        TEXT    PRIMARY KEY CHECK (length(event_key) BETWEEN 3 AND 100 AND event_key NOT GLOB '*[^a-z0-9_:]*'),
    kind             TEXT    NOT NULL CHECK (kind IN ('worker_down', 'queue_stalled', 'disk', 'backup', 'tool', 'repo', 'cert')),
    level            TEXT    NOT NULL CHECK (level IN ('warn', 'fail')),
    status           TEXT    NOT NULL CHECK (status IN ('active', 'resolved')),
    first_at         TEXT    NOT NULL CHECK (first_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_seen_at     TEXT    NOT NULL CHECK (last_seen_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_sent_at     TEXT    NULL CHECK (last_sent_at IS NULL OR last_sent_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    sent_count       INTEGER NOT NULL DEFAULT 0 CHECK (sent_count >= 0),
    suppressed_count INTEGER NOT NULL DEFAULT 0 CHECK (suppressed_count >= 0),
    resolved_at      TEXT    NULL CHECK (resolved_at IS NULL OR resolved_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9]:[0-9][0-9]:[0-9][0-9]Z'),
    last_result      TEXT    NULL CHECK (last_result IS NULL OR (length(last_result) <= 32 AND last_result NOT GLOB '*[^a-z_]*')),
    CHECK ((status = 'resolved') = (resolved_at IS NOT NULL))
);

-- 정리 작업: 풀린 지 오래된 행
CREATE INDEX alert_mail_state_resolved ON alert_mail_state (status, resolved_at);
