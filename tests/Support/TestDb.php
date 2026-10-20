<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Migrator;

/**
 * 테스트용 DB: migrations/를 적용한 SQLite(기본 메모리)와 최소 데이터 생성 도우미.
 * 시각은 고정값 T0 기준(UTC ISO 8601).
 */
final class TestDb
{
    public const T0 = '2026-10-02T12:00:00Z';

    /**
     * 마이그레이션을 적용한 DB. 기본은 메모리 DB다: 제약·트리거·쿼리 계획은 파일 DB와 같고,
     * Windows에서 새 DB 파일을 만드는 비용(약 1초, P08 측정)을 테스트마다 치르지 않는다.
     * WAL 등 파일 DB 동작은 MigratorTest가 파일로 시험한다.
     */
    public static function migrated(?string $file = null): Db
    {
        $db = new Db($file ?? ':memory:', 2000);
        (new Migrator($db, PJG_ROOT . '/migrations', new FixedClock(self::T0)))->migrate();
        return $db;
    }

    public static function user(Db $db, string $login = 'admin', string $role = 'admin', string $status = 'active'): int
    {
        $db->execute(
            'INSERT INTO users (login_id, display_name, role, status, auth_type, password_hash, created_at, updated_at)'
            . " VALUES (?, ?, ?, ?, 'local', ?, ?, ?)",
            [$login, $login, $role, $status, password_hash('Passw0rd!x', PASSWORD_BCRYPT, ['cost' => 4]), self::T0, self::T0],
        );
        return $db->lastInsertId();
    }

    public static function project(Db $db, string $name = 'demo', string $language = 'python'): int
    {
        $db->execute(
            'INSERT INTO projects (name, language, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $language, self::T0, self::T0],
        );
        return $db->lastInsertId();
    }

    /** @param array<string, mixed> $over */
    public static function analysis(Db $db, int $projectId, int $userId, array $over = []): int
    {
        $row = array_merge([
            'project_id' => $projectId,
            'status' => 'succeeded',
            'source_kind' => 'upload',
            'trigger_kind' => 'user',
            'requested_by' => $userId,
            'tool' => 'ruff',
            'tool_version' => '0.16.10',
            'config_source' => 'server_default',
            'config_hash' => str_repeat('a', 64),
            'queued_at' => self::T0,
            'started_at' => self::T0,
            'finished_at' => self::T0,
            'total_count' => 0, 'new_count' => 0, 'resolved_count' => 0, 'reopened_count' => 0, 'unchanged_count' => 0,
            'baseline_analysis_id' => null,
            'no_change' => 0,
            'failure_code' => null,
        ], $over);
        if ($row['status'] === 'queued') {
            $row['started_at'] = $row['finished_at'] = null;
        }
        if ($row['status'] === 'running') {
            $row['finished_at'] = null;
        }
        $cols = array_keys($row);
        $db->execute(
            'INSERT INTO analyses (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($row),
        );
        return $db->lastInsertId();
    }

    /** @param array<string, mixed> $over */
    public static function defect(Db $db, int $projectId, int $analysisId, array $over = []): int
    {
        static $n = 0;
        $n++;
        $row = array_merge([
            'project_id' => $projectId,
            'signature' => hash('sha256', 'sig' . $n . microtime()),
            'ordinal' => 0,
            'tool' => 'ruff',
            'language' => 'python',
            'rule_key' => 'ruff:F401',
            'file_path' => 'src/a.py',
            'line_no' => 1,
            'message' => 'm',
            'first_seen_analysis_id' => $analysisId,
            'last_seen_analysis_id' => $analysisId,
            'created_at' => self::T0,
            'updated_at' => self::T0,
        ], $over);
        $cols = array_keys($row);
        $db->execute(
            'INSERT INTO defects (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($row),
        );
        return $db->lastInsertId();
    }

    public static function autoHistory(Db $db, int $defectId, int $analysisId, string $old, string $new, string $reason): void
    {
        $db->execute(
            "INSERT INTO defect_history (defect_id, field, old_value, new_value, changed_at, kind, reason_code, analysis_id)"
            . " VALUES (?, 'status', ?, ?, ?, 'auto', ?, ?)",
            [$defectId, $old, $new, self::T0, $reason, $analysisId],
        );
    }
}
