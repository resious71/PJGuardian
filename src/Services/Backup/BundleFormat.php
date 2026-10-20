<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

/**
 * 백업 묶음(.pjgbak = zip, P27B) 형식. **아래 목록에 없는 항목은 만들지도 풀지도 않는다.**
 *
 *   manifest.json                     앱·스키마 버전, 시각, PC, 항목별 크기·SHA-256, 포함 옵션, 건수, 비운 설정 키, bin 도구 목록, 만든 사람
 *   db/pjguardian.sqlite              VACUUM INTO로 만든 일관된 단일 파일(-wal·-shm 없음)
 *   config/config.ini                 민감 값(password·secret·token·key가 이름에 든 키)을 비운 사본
 *   config/settings.local.ini         화면 설정(P27A, 있으면) — 같은 규칙으로 비움
 *   bin/manifest.json                 bin/ 도구·버전의 SHA256SUMS 내용(실행 파일 없음)
 *   snapshots/<프로젝트>/<분석>/source.zip   코드 스냅샷(옵션, 기본 포함)
 * 암호화 키(secrets.ini)는 절대 넣지 않는다(명세 19.4) — 키는 KeyVault의 .pjgkey로 따로.
 */
final class BundleFormat
{
    public const FORMAT = 'pjguardian-backup';
    public const VERSION = 1;
    public const KINDS = ['daily', 'manual', 'pre_restore', 'pre_upgrade'];
    public const EXT = '.pjgbak';
    public const DB = 'db/pjguardian.sqlite';
    public const CONFIG = 'config/config.ini';
    public const LOCAL = 'config/settings.local.ini';
    public const BIN = 'bin/manifest.json';
    public const MANIFEST = 'manifest.json';
    /** 묶음 안 항목 수 상한(스냅샷 수 + 고정 5개) */
    public const MAX_ENTRIES = 100000;
    /** 항목 하나의 압축 비율 상한(zip 폭탄 방어) — 실제 SQLite·ini는 수십 배 이내 */
    public const MAX_RATIO = 200;

    /** 묶음 파일 이름 규칙(경로 조각 없이) */
    public static function validName(string $name): bool
    {
        return preg_match('/^pjguardian-\d{8}-\d{6}-(daily|manual|pre_restore|pre_upgrade)(-\d{1,3})?\.pjgbak$/D', $name) === 1;
    }

    /** 이름에서 종류 */
    public static function kindOf(string $name): ?string
    {
        return preg_match('/-(daily|manual|pre_restore|pre_upgrade)(-\d{1,3})?\.pjgbak$/D', $name, $m) === 1 ? $m[1] : null;
    }

    /** 허용 항목인가(정확한 이름 또는 스냅샷 규칙) */
    public static function allowedEntry(string $name): bool
    {
        return in_array($name, [self::MANIFEST, self::DB, self::CONFIG, self::LOCAL, self::BIN], true)
            || preg_match('#^snapshots/[1-9]\d{0,8}/[1-9]\d{0,11}/source\.zip$#D', $name) === 1;
    }

    /**
     * 민감 값으로 보는 설정 키 이름(명세 19.4·P27B): 이름의 **마지막 조각**(밑줄 기준)이 password·passwd·pass·secret·token·key·apikey·passphrase
     * (예: password, smtp_password, api_token, encryption_key, client_secret). 값이 아니라 정책인 password_min_length·config_machine_keys 같은 이름은 아니다.
     */
    public static function sensitiveKey(string $key): bool
    {
        return preg_match('/(^|_)(password|passwd|pass|secret|token|key|apikey|passphrase)$/iD', $key) === 1;
    }
}
