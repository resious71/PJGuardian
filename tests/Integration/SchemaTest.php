<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Support\AuditChain;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/** 스키마 제약(외래 키, CHECK, 고유 키, 트리거)과 감사 로그 해시 체인 */
final class SchemaTest extends TestCase
{
    private Db $db;
    private int $admin;
    private int $project;
    private int $analysis;

    public function setUp(): void
    {
        $this->db = TestDb::migrated();
        $this->admin = TestDb::user($this->db);
        $this->project = TestDb::project($this->db);
        $this->analysis = TestDb::analysis($this->db, $this->project, $this->admin);
    }

    private function rejects(string $sql, array $params, string $expect = ''): void
    {
        $this->assertThrows(\PDOException::class, fn () => $this->db->execute($sql, $params), $expect);
    }

    public function testForeignKeysAreEnforced(): void
    {
        $this->rejects(
            "INSERT INTO defects (project_id, signature, ordinal, tool, language, rule_key, file_path, line_no, message, first_seen_analysis_id, last_seen_analysis_id, created_at, updated_at)"
            . " VALUES (999, ?, 0, 'ruff', 'python', 'ruff:F401', 'a.py', 1, 'm', ?, ?, ?, ?)",
            [str_repeat('a', 64), $this->analysis, $this->analysis, TestDb::T0, TestDb::T0],
            'FOREIGN KEY',
        );
        $this->rejects('UPDATE defects SET assignee_id = 999 WHERE 1 = 0 OR id = ?', [TestDb::defect($this->db, $this->project, $this->analysis)], 'FOREIGN KEY');
    }

    public function testProjectDeletionCascades(): void
    {
        $d = TestDb::defect($this->db, $this->project, $this->analysis);
        TestDb::autoHistory($this->db, $d, $this->analysis, 'open', 'resolved', 'not_detected');
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$this->project]);
        foreach (['analyses', 'defects', 'defect_history'] as $t) {
            $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM {$t}"), "{$t}가 함께 지워져야 함(명세 4)");
        }
    }

    public function testCheckConstraintsRejectBadEnumsAndFormats(): void
    {
        $d = TestDb::defect($this->db, $this->project, $this->analysis);
        $this->rejects("UPDATE defects SET status = 'closed' WHERE id = ?", [$d], 'CHECK');
        $this->rejects("UPDATE defects SET severity = 'urgent' WHERE id = ?", [$d], 'CHECK');
        $this->rejects("UPDATE analyses SET status = 'done' WHERE id = ?", [$this->analysis], 'CHECK');
        // 관리자 계정이면 '마지막 활성 관리자' 트리거가 먼저 거부하므로 일반 사용자로 CHECK를 시험한다
        $plain = TestDb::user($this->db, 'plain', 'user');
        $this->rejects("UPDATE users SET role = 'root' WHERE id = ?", [$plain], 'CHECK');
        $this->rejects("UPDATE projects SET exclude_patterns = 'not json' WHERE id = ?", [$this->project], 'CHECK');
        $this->rejects("UPDATE projects SET exclude_patterns = '{\"a\":1}' WHERE id = ?", [$this->project], 'CHECK');
        $this->rejects("UPDATE defects SET updated_at = '2026-10-02 12:00:00' WHERE id = ?", [$d], 'CHECK');
        $this->rejects("UPDATE defects SET signature = 'XYZ' WHERE id = ?", [$d], 'CHECK');
        $this->rejects("UPDATE defects SET language = 'typescript' WHERE id = ?", [$d], 'CHECK');
        $this->rejects(
            "INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, last_activity_at, created_at)"
            . " VALUES ('../../etc', ?, ?, 'a.zip', 1, 1, 1, ?, ?)",
            [$this->admin, $this->project, TestDb::T0, TestDb::T0],
            'CHECK',
        );
        // 로컬 계정은 비밀번호 해시가 필요하고, LDAP 계정은 비어 있어야 한다
        $this->rejects("INSERT INTO users (login_id, display_name, auth_type, created_at, updated_at) VALUES ('x', 'x', 'local', ?, ?)", [TestDb::T0, TestDb::T0], 'CHECK');
        $this->rejects("INSERT INTO users (login_id, display_name, auth_type, password_hash, created_at, updated_at) VALUES ('y', 'y', 'ldap', 'h', ?, ?)", [TestDb::T0, TestDb::T0], 'CHECK');
        // 실패 상태에는 실패 코드가 필요
        $this->rejects("UPDATE analyses SET status = 'failed', failure_code = NULL WHERE id = ?", [$this->analysis], 'CHECK');
        // 자동 이력은 변경자가 없고 사유와 분석이 있어야 한다
        $this->rejects(
            "INSERT INTO defect_history (defect_id, field, changed_by, changed_at, kind, reason_code, analysis_id) VALUES (?, 'status', ?, ?, 'auto', 'not_detected', ?)",
            [$d, $this->admin, TestDb::T0, $this->analysis],
            'CHECK',
        );
    }

    public function testDefectUniqueKey(): void
    {
        $sig = str_repeat('b', 64);
        TestDb::defect($this->db, $this->project, $this->analysis, ['signature' => $sig, 'ordinal' => 0]);
        TestDb::defect($this->db, $this->project, $this->analysis, ['signature' => $sig, 'ordinal' => 1]);
        $this->assertThrows(\PDOException::class, fn () => TestDb::defect($this->db, $this->project, $this->analysis, ['signature' => $sig, 'ordinal' => 0]), 'UNIQUE');
        // 다른 프로젝트면 같은 시그니처·순번 허용
        $p2 = TestDb::project($this->db, 'other');
        $a2 = TestDb::analysis($this->db, $p2, $this->admin);
        TestDb::defect($this->db, $p2, $a2, ['signature' => $sig, 'ordinal' => 0]);
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM defects WHERE signature = ?', [$sig]));
    }

    public function testOptimisticLockDetectsStaleVersion(): void
    {
        $d = TestDb::defect($this->db, $this->project, $this->analysis);
        $sql = 'UPDATE defects SET status = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?';
        // 사용자 A와 B가 모두 version 1을 보고 있다
        $this->assertSame(1, $this->db->execute($sql, ['in_progress', TestDb::T0, $d, 1]), 'A 저장 성공');
        $this->assertSame(0, $this->db->execute($sql, ['postponed', TestDb::T0, $d, 1]), 'B는 영향 행 0 → 충돌(409)');
        $this->assertSame(['status' => 'in_progress', 'version' => 2], $this->db->one('SELECT status, version FROM defects WHERE id = ?', [$d]));
    }

    public function testUsersAreNeverDeletedAndLastActiveAdminIsProtected(): void
    {
        $this->rejects('DELETE FROM users WHERE id = ?', [$this->admin], 'users_are_never_deleted');
        $this->rejects("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->admin], 'last_active_admin');
        $this->rejects("UPDATE users SET role = 'user' WHERE id = ?", [$this->admin], 'last_active_admin');
        $second = TestDb::user($this->db, 'admin2');
        $this->assertSame(1, $this->db->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [$this->admin]), '다른 활성 관리자가 있으면 허용');
        $this->rejects("UPDATE users SET role = 'user' WHERE id = ?", [$second], 'last_active_admin');
    }

    public function testProjectLanguageIsImmutable(): void
    {
        $this->rejects("UPDATE projects SET language = 'javascript' WHERE id = ?", [$this->project], 'project_language_immutable');
        $this->assertSame(1, $this->db->execute("UPDATE projects SET name = 'renamed', language = 'python' WHERE id = ?", [$this->project]), '같은 값·다른 컬럼 변경은 허용');
    }

    public function testOnlyOneRunningAnalysisPerProject(): void
    {
        TestDb::analysis($this->db, $this->project, $this->admin, ['status' => 'running']);
        $this->assertThrows(\PDOException::class, fn () => TestDb::analysis($this->db, $this->project, $this->admin, ['status' => 'running']), 'UNIQUE');
        $p2 = TestDb::project($this->db, 'p2');
        TestDb::analysis($this->db, $p2, $this->admin, ['status' => 'running']);
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM analyses WHERE status = 'running'"), '다른 프로젝트는 동시에 실행 가능');
    }

    public function testOnlyOnePinnedToolVersionPerToolAndOs(): void
    {
        $ins = "INSERT INTO tool_versions (tool, version, os, status, checksum_ok, smoke_ok, detected_at, updated_at) VALUES ('ruff', ?, 'windows', ?, 1, 1, ?, ?)";
        $this->db->execute($ins, ['0.16.10', 'pinned', TestDb::T0, TestDb::T0]);
        $this->rejects($ins, ['0.17.0', 'pinned', TestDb::T0, TestDb::T0], 'UNIQUE');
        $this->db->execute($ins, ['0.17.0', 'active', TestDb::T0, TestDb::T0]);
        // 체크섬·스모크를 통과하지 않은 버전은 active가 될 수 없다
        $this->rejects("INSERT INTO tool_versions (tool, version, os, status, checksum_ok, smoke_ok, detected_at, updated_at) VALUES ('ruff', '0.18.0', 'windows', 'active', 1, 0, ?, ?)", [TestDb::T0, TestDb::T0], 'CHECK');
    }

    public function testAuditLogIsAppendOnlyAndChainVerifies(): void
    {
        $clock = new FixedClock(TestDb::T0);
        $id1 = AuditChain::append($this->db, $clock, $this->admin, 'admin', 'user.create', 'user', '2', ['login_id' => 'bob', 'password' => 'secret!']);
        $clock->advance(60);
        AuditChain::append($this->db, $clock, null, 'cli', 'user.unlock', 'user', '2');
        $clock->advance(60);
        AuditChain::append($this->db, $clock, $this->admin, 'admin', 'project.delete', 'project', '1', ['name' => 'demo']);
        $this->assertSame(['ok' => true, 'checked' => 3, 'broken_at' => null, 'reason' => null], AuditChain::verify($this->db));
        $this->assertNotContains('secret!', (string) $this->db->value('SELECT details FROM audit_log WHERE id = ?', [$id1]), '감사 로그 세부 정보도 비밀번호 마스킹');

        $this->rejects("UPDATE audit_log SET action = 'x' WHERE id = ?", [$id1], 'audit_log_is_append_only');
        $this->rejects('DELETE FROM audit_log WHERE id = ?', [$id1], 'audit_log_is_append_only');
        $this->rejects('DELETE FROM audit_log_meta', [], 'audit_log_meta_is_required');
    }

    public function testChainDetectsTamperingBypassingTriggers(): void
    {
        $clock = new FixedClock(TestDb::T0);
        foreach (['a', 'b', 'c'] as $i => $act) {
            AuditChain::append($this->db, $clock, $this->admin, 'admin', "act.{$act}");
            $clock->advance(10);
        }
        // DB 파일을 직접 고친 상황을 흉내 낸다(트리거 삭제 후 수정)
        $this->db->pdo()->exec('DROP TRIGGER audit_log_no_update');
        $this->db->execute("UPDATE audit_log SET details = '{\"forged\":true}' WHERE action = 'act.b'");
        $r = AuditChain::verify($this->db);
        $this->assertSame(false, $r['ok']);
        $this->assertSame('hash_mismatch', $r['reason']);
        $this->assertSame(2, $r['checked'], '변조된 두 번째 행에서 멈춤');
    }

    public function testPurgeKeepsRemainingChainVerifiable(): void
    {
        $clock = new FixedClock('2026-06-01T00:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            AuditChain::append($this->db, $clock, $this->admin, 'admin', "old.{$i}");
            $clock->advance(86400);
        }
        $clock = new FixedClock('2026-10-01T00:00:00Z');
        AuditChain::append($this->db, $clock, $this->admin, 'admin', 'recent');
        $deleted = AuditChain::purgeBefore($this->db, '2026-07-03T00:00:00Z'); // 90일 전
        $this->assertSame(5, $deleted);
        $this->assertSame(['recent'], array_column($this->db->all('SELECT action FROM audit_log'), 'action'));
        $this->assertSame(true, AuditChain::verify($this->db)['ok'], '정리 후에도 기준점(anchor)부터 체인이 이어짐');
        $this->assertSame(0, (int) $this->db->value('SELECT purge_enabled FROM audit_log_meta'), '정리가 끝나면 삭제 금지로 돌아감');
        $this->rejects('DELETE FROM audit_log', [], 'audit_log_is_append_only');
    }
}
