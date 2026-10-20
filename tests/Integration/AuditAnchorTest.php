<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Audit\AuditAnchor;
use PJGuardian\Support\AuditChain;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 감사 로그 무결성 — DB 밖 기록(P31 A-5):
 * - 기록이 없으면 "확인 불가"(경고 아님), 기록하면 일치.
 * - 체인 끝의 행을 지우면(체인 검사는 통과) DB 밖 기록이 잡는다(missing_row) → 무결성 검사 결과·모니터링 오류(audit_anchor_mismatch)·/health 503.
 * - 행을 지우고 체인을 처음부터 다시 계산해도(DB에 쓸 수 있는 사람의 위조) 해시가 달라 잡는다(hash_changed).
 * - 보존 정리 뒤에는 새 경계를 기록하고, 정리로 지운 범위의 옛 기록은 건너뛴다(정상). 경계가 뒤로 가면(옛 DB로 바꿈) purge_rewound.
 * - 복원하면 기록 파일을 옆으로 옮기고 새로 시작한다(옛 기록과 비교하지 않음).
 */
final class AuditAnchorTest extends TestCase
{
    private function app(): App
    {
        $dir = $this->tempDir('anchor');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'health' => ['allowed_ips' => '127.0.0.1'], 'log' => ['audit_retention' => '30d']]);
        TestDb::user($app->db(), 'admin', 'admin');
        return $app;
    }

    private function add(App $app, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $app->audit()->record(Actor::labeled('system'), 'cleanup.run_request', null, null, ['n' => $i]);
        }
    }

    private function integrity(TestClient $c): array
    {
        $after = 0;
        for (;;) {
            $r = $c->get('/api/v1/admin/audit-logs/integrity', ['after' => (string) $after, 'limit' => '2'])->decoded()['data'];
            if (!$r['ok'] || $r['done']) {
                return $r;
            }
            $after = $r['last_id'];
        }
    }

    public function testNoRecordIsUnknownThenMatches(): void
    {
        $app = $this->app();
        $this->add($app, 5);
        $anchor = $app->auditAnchor();
        $this->assertSame('unknown', $anchor->check()['state']);
        $this->assertFalse(in_array('audit_anchor_mismatch', array_column($app->systemHealth()->snapshot(true)['alerts'], 'code'), true), '기록 없음은 경고 아님');
        $this->assertTrue($anchor->record());
        $this->assertTrue($anchor->record(), '같으면 다시 쓰지 않음');
        $this->assertSame(1, count(file($anchor->path(), FILE_SKIP_EMPTY_LINES)));
        $this->add($app, 2);
        $app->clock->advance(3600);
        $anchor->record();
        $this->assertSame(2, count(file($anchor->path(), FILE_SKIP_EMPTY_LINES)));
        $chk = $anchor->check();
        $this->assertSame(['ok', 2], [$chk['state'], $chk['checked']]);
        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $r = $this->integrity($c);
        $this->assertSame([true, 'ok'], [$r['ok'], $r['anchor']['state']], '무결성 검사 결과에 대조 포함');
        $this->assertSame('ok', $app->systemHealth()->cards()['app']['audit_anchor']);
    }

    public function testTruncatedTailIsCaughtOnlyByOutsideRecord(): void
    {
        $app = $this->app();
        $this->add($app, 6);
        $app->auditAnchor()->record();
        $db = $app->db();
        $last = (int) $db->value('SELECT MAX(id) FROM audit_log');
        // 끝의 행을 지움(지우기를 잠깐 허용하는 표지를 직접 켬 — DB 파일에 쓸 수 있는 사람의 행동)
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 1 WHERE id = 1');
        $db->execute('DELETE FROM audit_log WHERE id >= ?', [$last - 1]);
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 0 WHERE id = 1');
        $this->assertTrue(AuditChain::verify($db)['ok'] ?? true, '체인 자체는 이어져 있음(끝을 자르면 체인 검사만으로는 못 잡음)');
        $chk = $app->auditAnchor()->check();
        $this->assertSame(['mismatch', $last, 'missing_row'], [$chk['state'], $chk['broken_id'], $chk['reason']]);

        $c = new TestClient($app);
        $c->login('admin', 'Passw0rd!x');
        $r = $this->integrity($c);
        $this->assertSame([true, 'mismatch'], [$r['ok'], $r['anchor']['state']]);
        $snap = $app->systemHealth()->snapshot(true);
        $this->assertSame('fail', $snap['status']);
        $this->assertTrue(in_array('audit_anchor_mismatch', array_column($snap['alerts'], 'code'), true), '모니터링 오류');
        $health = $app->handle(new Request('GET', '/health', [], ['user-agent' => 't'], '', true, '127.0.0.1'));
        $this->assertSame(503, $health->status);
        // 관리자 장애 배너(첫 경고 문장 + 건수 — 오류가 여럿이면 다른 문장이 먼저일 수 있음)
        $html = $c->get('/dashboard')->body;
        $this->assertTrue(str_contains($html, 'data-health-banner') && str_contains($html, 'banner--error'), '관리자 장애 배너(오류)');
    }

    public function testRebuiltChainIsCaughtByHash(): void
    {
        $app = $this->app();
        $this->add($app, 4);
        $app->auditAnchor()->record();
        $db = $app->db();
        // 위조: 모두 지우고 다른 내용으로 체인을 처음부터 다시 만든다(해시 규칙은 공개 코드라 누구나 계산 가능)
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 1 WHERE id = 1');
        $db->execute('DELETE FROM audit_log');
        $db->execute('UPDATE audit_log_meta SET purge_enabled = 0 WHERE id = 1');
        $db->execute("DELETE FROM sqlite_sequence WHERE name = 'audit_log'");
        for ($i = 0; $i < 4; $i++) {
            $app->audit()->record(Actor::labeled('system'), 'cleanup.run_request', null, null, ['n' => 'forged-' . $i]);
        }
        $this->assertTrue(AuditChain::verify($db)['ok'], '다시 계산한 체인은 DB 안 검사로는 정상');
        $chk = $app->auditAnchor()->check();
        $this->assertSame(['mismatch', 'hash_changed'], [$chk['state'], $chk['reason']]);
    }

    public function testPurgeRecordsNewBoundaryAndRewindIsCaught(): void
    {
        $app = $this->app();
        $this->add($app, 5);
        $app->auditAnchor()->record();
        $copy = $this->tempDir('anchor_copy') . '/old.sqlite';
        $app->db()->execute('VACUUM INTO ?', [$copy]);
        $this->add($app, 1); // 옛 기록 뒤의 행 — 정리 경계가 옛 기록보다 뒤가 되게
        $app->clock->advance(40 * 86400);
        $this->add($app, 2);
        $r = $app->auditRetention()->purge();
        $this->assertTrue($r['deleted'] >= 5, json_encode($r));
        $chk = $app->auditAnchor()->check();
        $this->assertSame('ok', $chk['state'], '정리 뒤 새 경계 기록, 지운 범위의 옛 기록은 건너뜀');
        $this->assertTrue($chk['skipped'] >= 1, json_encode($chk));
        // 옛 DB(정리 전)로 바꿔치기 → 경계가 뒤로 감
        $old = new AuditAnchor(new \PJGuardian\Support\Db($copy, 2000), $app->clock, $app->paths->logs());
        $this->assertSame(['mismatch', 'purge_rewound'], [$old->check()['state'], $old->check()['reason']]);
    }

    public function testRestoreRotatesTheRecord(): void
    {
        $app = $this->app();
        $this->add($app, 2);
        $app->auditAnchor()->record();
        AuditAnchor::rotateAfterRestore($app->paths->logs(), $app->clock);
        $this->assertFalse(is_file($app->auditAnchor()->path()));
        $this->assertSame(1, count(glob($app->paths->logs() . '/audit-anchor.*.before-restore.jsonl') ?: []), '옛 기록은 옆에 보관');
        $this->assertSame('unknown', $app->auditAnchor()->check()['state']);
    }
}
