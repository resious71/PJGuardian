<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\Db;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 웹 요청 연결의 자동 체크포인트(P31 B-2): 기본 끔(database.web_wal_autocheckpoint = 0) — 큰 반영 직후 웹 요청 하나가 WAL 수백 MB를 옮기며
 * 수십 초 걸리던 것(측정 docs/performance.md: 50만 10% 변경 직후 다른 연결 대기 21.4초 → 3.9초)을 막는다. 값을 주면 그 페이지 수.
 * CLI(워커·도구)는 SQLite 기본(1000)을 그대로 쓴다. 체크포인트는 워커가 한가할 때도 database.idle_checkpoint_interval마다.
 */
final class WebDbPragmasTest extends TestCase
{
    public function testWebConnectionsDoNotAutoCheckpointByDefault(): void
    {
        $file = $this->tempDir('webdb') . '/db.sqlite';
        TestDb::migrated($file);
        $db = new Db($file, 2000);
        $this->assertSame(1000, (int) $db->value('PRAGMA wal_autocheckpoint'), 'CLI·기본 연결은 SQLite 기본');
        App::applyWebDbPragmas($db, TestApp::config());
        $this->assertSame(0, (int) $db->value('PRAGMA wal_autocheckpoint'), '웹 연결 기본 끔');
        App::applyWebDbPragmas($db, TestApp::config(['database' => ['web_wal_autocheckpoint' => '20000']]));
        $this->assertSame(20000, (int) $db->value('PRAGMA wal_autocheckpoint'), '설정 값');
        $this->assertSame(600, TestApp::config()->int('database.idle_checkpoint_interval'), '워커 한가할 때 간격 기본 10분');
    }
}
