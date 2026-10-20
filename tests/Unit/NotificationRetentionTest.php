<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\App;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 알림 보관 정리(P24, NotificationService::prune — 시간 주입): 읽은 알림은 읽은 시각 기준 7일, 안 읽은 알림은 생성 시각 기준 30일(경계 포함 여부),
 * 사용자당 상한 초과 시 읽은 것 중 오래된 것부터 → 그래도 넘으면 안 읽은 것 중 오래된 것부터, 단계별 배치 상한과 이어하기, 설정 변경은 다음 정리부터.
 * 그리고 생성 규칙의 기본(알 수 없는 종류·키 형식은 경고 로그 뒤 0건, params 상한).
 */
final class NotificationRetentionTest extends TestCase
{
    private App $app;
    private int $kim;
    private int $lee;
    private int $seq = 0;

    public function setUp(): void
    {
        $this->app = TestApp::make($this->tempDir('notif-ret'));
        $this->kim = TestDb::user($this->app->db(), 'kim', 'user');
        $this->lee = TestDb::user($this->app->db(), 'lee', 'user');
    }

    private function put(int $user, string $createdAt, ?string $readAt): int
    {
        $this->seq++;
        $this->app->db()->execute(
            "INSERT INTO notifications (user_id, kind, params, dedupe_key, created_at, read_at) VALUES (?, 'analysis_done', '{}', ?, ?, ?)",
            [$user, 'k' . $this->seq, $createdAt, $readAt],
        );
        return $this->app->db()->lastInsertId();
    }

    /** @return list<int> */
    private function ids(int $user): array
    {
        return array_map('intval', array_column($this->app->db()->all('SELECT id FROM notifications WHERE user_id = ? ORDER BY id', [$user]), 'id'));
    }

    private static function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    public function testReadAndUnreadBoundaries(): void
    {
        $now = '2026-10-31T12:00:00Z';
        $readOld = $this->put($this->kim, '2026-10-01T00:00:00Z', '2026-10-24T11:59:59Z');   // 읽은 지 7일 + 1초 → 삭제
        $readEdge = $this->put($this->kim, '2026-10-01T00:00:00Z', '2026-10-24T12:00:00Z');  // 정확히 7일 → 남김
        $readNew = $this->put($this->kim, '2026-09-01T00:00:00Z', '2026-10-30T00:00:00Z');   // 오래전에 만들었어도 최근에 읽음 → 남김
        $unreadOld = $this->put($this->kim, '2026-10-01T11:59:59Z', null);                  // 만든 지 30일 + 1초 → 삭제
        $unreadEdge = $this->put($this->kim, '2026-10-01T12:00:00Z', null);                 // 정확히 30일 → 남김
        $r = $this->app->notifications()->prune(self::at($now));
        $this->assertSame(['read' => 1, 'unread' => 1, 'over' => 0, 'more' => false], $r);
        $this->assertSame([$readEdge, $readNew, $unreadEdge], $this->ids($this->kim));
        $this->assertFalse(in_array($readOld, $this->ids($this->kim), true) || in_array($unreadOld, $this->ids($this->kim), true));
    }

    public function testSettingsChangeAppliesFromNextRun(): void
    {
        $this->put($this->kim, '2026-10-20T00:00:00Z', '2026-10-28T00:00:00Z'); // 3.5일 전에 읽음
        $this->assertSame(0, $this->app->notifications()->prune(self::at('2026-10-31T12:00:00Z'))['read']);
        $this->app->db()->execute("INSERT INTO app_settings (key, value, updated_at) VALUES ('notifications.retain_read_days', '3', '2026-10-31T00:00:00Z')");
        $this->assertSame(1, $this->app->notifications()->prune(self::at('2026-10-31T12:00:00Z'))['read'], '설정은 정리 때마다 다시 읽음(워커가 오래 돌아도 반영)');
    }

    public function testMaxPerUserDeletesOldestReadFirstThenUnread(): void
    {
        $this->app->db()->execute("INSERT INTO app_settings (key, value, updated_at) VALUES ('notifications.max_per_user', '100', '2026-10-31T00:00:00Z')");
        $t = static fn (int $i): string => sprintf('2026-10-30T%02d:%02d:00Z', intdiv($i, 60), $i % 60);
        $unreadOldest = [];
        for ($i = 0; $i < 3; $i++) {
            $unreadOldest[] = $this->put($this->kim, $t($i), null);           // 가장 오래된 안 읽은 3건
        }
        $readIds = [];
        for ($i = 3; $i < 6; $i++) {
            $readIds[] = $this->put($this->kim, $t($i), $t($i + 100));        // 그다음 읽은 3건
        }
        for ($i = 6; $i < 104; $i++) {
            $this->put($this->kim, $t($i), null);                             // 안 읽은 98건(총 104)
        }
        $this->put($this->lee, $t(1), null);                                  // 다른 사용자(상한 안)
        $r = $this->app->notifications()->prune(self::at('2026-10-31T00:00:00Z'));
        $this->assertSame(4, $r['over'], '104 - 100');
        $left = $this->ids($this->kim);
        $this->assertSame(100, count($left));
        $this->assertSame([], array_values(array_intersect($readIds, $left)), '읽은 3건이 먼저');
        $this->assertSame([$unreadOldest[1], $unreadOldest[2]], array_values(array_intersect($unreadOldest, $left)), '그다음 안 읽은 것 중 가장 오래된 1건');
        $this->assertSame(1, count($this->ids($this->lee)));
    }

    public function testBatchLimitContinuesNextRun(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->put($this->kim, '2026-08-01T00:00:00Z', '2026-08-02T00:00:00Z');
        }
        for ($i = 0; $i < 12; $i++) {
            $this->put($this->lee, '2026-08-01T00:00:00Z', null);
        }
        $svc = $this->app->notifications();
        $now = self::at('2026-10-31T00:00:00Z');
        $this->assertSame(['read' => 10, 'unread' => 10, 'over' => 0, 'more' => true], $svc->prune($now, 10));
        $this->assertSame(['read' => 10, 'unread' => 2, 'over' => 0, 'more' => true], $svc->prune($now, 10));
        $this->assertSame(['read' => 5, 'unread' => 0, 'over' => 0, 'more' => false], $svc->prune($now, 10));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications'));
    }

    public function testEmitRejectsBadInputWithoutThrowing(): void
    {
        $svc = $this->app->notifications();
        $this->assertSame(0, $svc->emit('email', [$this->kim], [], [], 'x'), '알 수 없는 종류');
        $this->assertSame(0, $svc->emit('analysis_done', [$this->kim], [], [], ''), '빈 키');
        $this->assertSame(0, $svc->emit('analysis_done', [$this->kim], [], ['big' => str_repeat('a', 3000)], 'big'), 'params 상한');
        $this->assertSame(0, $svc->emit('analysis_done', [], [], [], 'none'), '받는 사람 없음');
        // 프로젝트 없는 알림은 활성 사용자에게만(지금 단계에는 쓰지 않지만 규칙은 같다)
        $this->assertSame(1, $svc->emit('analysis_done', [$this->kim, $this->kim], [], [], 'dup'), '같은 사람 두 번 → 한 건');
        $this->assertSame(0, $svc->emit('analysis_done', [$this->kim], [], [], 'dup'), '같은 키 → 만들지 않음');
        $this->assertSame('{}', $this->app->db()->value("SELECT params FROM notifications WHERE dedupe_key = 'dup'"), '빈 params는 JSON 객체');
        $log = (string) file_get_contents($this->app->logger->fileFor(self::at('2026-10-02T12:00:00Z')));
        $this->assertContains('알림 생성 실패(본 동작에는 영향 없음)', $log);
    }
}
