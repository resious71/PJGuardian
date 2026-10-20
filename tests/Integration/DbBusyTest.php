<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Support\Db;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 큰 쓰기(분석 반영)가 쓰기 잠금을 오래 쥔 동안의 웹 쓰기(P31 B-1): busy_timeout만큼 기다려도 못 쓰면 500 "서버 오류"가 아니라
 * 503 db_busy + Retry-After와 "잠시 뒤 다시" 안내(API는 JSON, 화면은 오류 페이지). 잠금이 풀리면 같은 요청이 성공한다.
 */
final class DbBusyTest extends TestCase
{
    public function testWriteDuringLongLockIs503WithRetryAfter(): void
    {
        $dir = $this->tempDir('busy');
        $file = $dir . '/db.sqlite';
        TestDb::migrated($file);
        $seed = new Db($file, 2000);
        TestDb::user($seed, 'admin', 'admin');
        $seed = null;
        $config = TestApp::config(['paths' => ['work_dir' => $dir . '/work', 'logs_dir' => $dir], 'auth' => ['bcrypt_cost' => '4']]);
        $clock = new FixedClock('2026-10-02T12:00:00Z');
        $app = new App(PJG_ROOT, $config, $clock, new Logger($dir, $clock, 'INFO'), new Paths(PJG_ROOT, $config), new Db($file, 300));
        $c = new TestClient($app);
        $c->fetchCsrf();

        // 다른 연결(워커의 반영처럼)이 쓰기 잠금을 쥠
        $holder = new \PDO('sqlite:' . $file);
        $holder->exec('PRAGMA busy_timeout = 1000');
        $holder->exec('BEGIN IMMEDIATE');
        $t = microtime(true);
        $r = $c->post('/api/v1/auth/login', ['login_id' => 'admin', 'password' => 'Passw0rd!x']);
        $waited = microtime(true) - $t;
        $this->assertSame([503, 'db_busy'], [$r->status, $r->decoded()['error']['code'] ?? null], $r->body);
        $this->assertSame('5', $r->header('Retry-After'));
        $this->assertTrue(str_contains((string) ($r->decoded()['error']['message'] ?? ''), '다시 시도'), '안내 문구');
        $this->assertFalse(str_contains($r->body, 'database is locked') || str_contains($r->body, 'SQLSTATE'), '내부 오류 문장 없음');
        $this->assertTrue($waited >= 0.25, 'busy_timeout만큼 기다린 뒤');
        $log = (string) file_get_contents((string) glob($dir . '/app-*.log')[0]);
        $this->assertTrue(str_contains($log, 'DB 잠금 대기 초과'), '경고 로그');
        $this->assertFalse(str_contains($log, '처리되지 않은 예외'), '서버 오류로 남지 않음');

        $holder->exec('COMMIT');
        $this->assertSame(200, $c->post('/api/v1/auth/login', ['login_id' => 'admin', 'password' => 'Passw0rd!x'])->status, '잠금이 풀리면 성공');
    }
}
