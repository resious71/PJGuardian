<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 마이그레이션 적용 여부에 따라 달라지는 코드 경로(P28B). 코드와 DB 마이그레이션은 사용자가 따로 적용하므로(개발 PC의 Apache가
 * 작업 중인 코드를 바로 쓴다) 새 열이 아직 없으면 예전 방식으로 동작한다 — 로그인·세션이 깨지지 않게.
 * 결과는 연결마다 한 번만 확인한다(PRAGMA table_info).
 */
final class SchemaFeatures
{
    /** @var array<int, bool> */
    private static array $externalAuth = [];
    /** @var array<int, bool> */
    private static array $secretNames = [];

    /** 026(외부 인증: users.approval_status·external_id, login_failures.login_key, app_secrets)이 적용됐는가 */
    public static function externalAuth(Db $db): bool
    {
        $k = spl_object_id($db->pdo());
        if (!isset(self::$externalAuth[$k])) {
            $cols = array_column($db->all('PRAGMA table_info(users)'), 'name');
            self::$externalAuth[$k] = in_array('approval_status', $cols, true);
        }
        return self::$externalAuth[$k];
    }

    /** 027(app_secrets 이름 제약을 형식 검사로 — OIDC 클라이언트 비밀, P28C)이 적용됐는가 */
    public static function appSecretNames(Db $db): bool
    {
        $k = spl_object_id($db->pdo());
        if (!isset(self::$secretNames[$k])) {
            $sql = $db->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'app_secrets'");
            self::$secretNames[$k] = is_string($sql) && !str_contains($sql, "IN ('ldap.bind_password')");
        }
        return self::$secretNames[$k];
    }

    /** OIDC 로그인(P28C)을 쓸 수 있는 스키마인가: 026 + 027 */
    public static function oidc(Db $db): bool
    {
        return self::externalAuth($db) && self::appSecretNames($db);
    }

    /** @var array<int, bool> */
    private static array $auditCheckpoint = [];

    /** 030(audit_log_meta.purged_through_at·purged_at, 감사 조회 색인, P30)이 적용됐는가 — 전이면 정리는 그대로 하고 경계 시각만 기록하지 않음 */
    public static function auditCheckpoint(Db $db): bool
    {
        $k = spl_object_id($db->pdo());
        if (!isset(self::$auditCheckpoint[$k])) {
            self::$auditCheckpoint[$k] = in_array('purged_through_at', array_column($db->all('PRAGMA table_info(audit_log_meta)'), 'name'), true);
        }
        return self::$auditCheckpoint[$k];
    }

    /** @var array<int, bool> */
    private static array $faq = [];

    /** 029(faq_items, P29B)가 적용됐는가 — 전이면 FAQ 화면은 안내, API는 503 faq_unavailable */
    public static function faq(Db $db): bool
    {
        $k = spl_object_id($db->pdo());
        if (!isset(self::$faq[$k])) {
            self::$faq[$k] = $db->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'faq_items'") !== null;
        }
        return self::$faq[$k];
    }

    /** @var array<int, bool> */
    private static array $alertMail = [];

    /** 031(alert_mail_state, P30B)가 적용됐는가 — 전이면 관리자 이메일을 보내지 않고 모니터링·설정 화면에 "설정 필요(031)" */
    public static function alertMail(Db $db): bool
    {
        $k = spl_object_id($db->pdo());
        if (!isset(self::$alertMail[$k])) {
            self::$alertMail[$k] = $db->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'alert_mail_state'") !== null;
        }
        return self::$alertMail[$k];
    }

    /** @var array<int, bool> */
    private static array $samlNotice = [];

    /** SAML 로그인(P28D)을 쓸 수 있는 스키마인가: 026 + 027(SP 키 저장). 028은 알림에만 필요 */
    public static function saml(Db $db): bool
    {
        return self::oidc($db);
    }

    /** 028(알림 종류 saml_cert_expiring)이 적용됐는가 — 전이면 인증서 만료 알림을 만들지 않는다(점검·설정 화면 경고는 그대로) */
    public static function samlCertNotice(Db $db): bool
    {
        $k = spl_object_id($db->pdo());
        if (!isset(self::$samlNotice[$k])) {
            $sql = $db->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'");
            self::$samlNotice[$k] = is_string($sql) && str_contains($sql, "'saml_cert_expiring'");
        }
        return self::$samlNotice[$k];
    }
}
