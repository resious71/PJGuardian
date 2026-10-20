<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Tests\TestCase;

final class LoggerTest extends TestCase
{
    private function lines(string $dir, FixedClock $clock): array
    {
        $file = $dir . '/app-' . $clock->now()->format('Ymd') . '.log';
        return array_map(static fn (string $l): array => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES));
    }

    public function testWritesOneJsonLinePerRecordWithContextIds(): void
    {
        $dir = $this->tempDir('log');
        $clock = new FixedClock('2026-10-02T13:45:07Z');
        $log = new Logger($dir, $clock);
        $log->withRequestId('req123');
        $log->withJobId(42);
        $log->withUserId(7);
        $log->info('분석 시작', ['project' => 3]);
        $log->error('실패');
        $lines = $this->lines($dir, $clock);
        $this->assertSame(2, count($lines));
        $this->assertSame('2026-10-02T13:45:07Z', $lines[0]['ts']);
        $this->assertSame('INFO', $lines[0]['level']);
        $this->assertSame('req123', $lines[0]['request_id']);
        $this->assertSame(42, $lines[0]['job_id']);
        $this->assertSame(7, $lines[0]['user_id']);
        $this->assertSame('분석 시작', $lines[0]['message']);
        $this->assertSame(['project' => 3], $lines[0]['context']);
        $this->assertTrue(is_file($dir . '/app-20261002.log'), '파일 이름은 app-YYYYMMDD.log(UTC)');
    }

    public function testMasksSensitiveKeysInContext(): void
    {
        $dir = $this->tempDir('log');
        $clock = new FixedClock();
        $log = new Logger($dir, $clock);
        $log->warn('로그인 시도', [
            'username' => 'alice',
            'password' => 'S3cret!pass',
            'nested' => ['api_token' => 'tok-abc', 'Authorization' => 'Bearer xyz.abc.def', 'keep' => 'ok'],
            'headers' => ['Cookie' => 'PHPSESSID=abcdef123456', 'X-CSRF-Token' => 'csrf-value'],
            'encryption_key' => 'k3y',
        ]);
        $raw = (string) file_get_contents($dir . '/app-' . $clock->now()->format('Ymd') . '.log');
        foreach (['S3cret!pass', 'tok-abc', 'xyz.abc.def', 'abcdef123456', 'csrf-value', 'k3y'] as $secret) {
            $this->assertNotContains($secret, $raw, '로그 파일에 민감 값이 남음');
        }
        $ctx = $this->lines($dir, $clock)[0]['context'];
        $this->assertSame('alice', $ctx['username']);
        $this->assertSame(Logger::MASK, $ctx['password']);
        $this->assertSame('ok', $ctx['nested']['keep']);
    }

    public function testMasksSecretsInsideMessageStrings(): void
    {
        $cases = [
            'login failed password=hunter2 user=bob' => 'hunter2',
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig' => 'eyJhbGciOiJIUzI1NiJ9',
            '{"user":"bob","password":"p@ss w0rd"}' => 'p@ss w0rd',
            'git clone https://bot:ghp_token123@github.com/org/repo.git' => 'ghp_token123',
            'header Cookie: PHPSESSID=sess999; path=/' => 'sess999',
            'retry with api_key: AKIA1234 now' => 'AKIA1234',
        ];
        foreach ($cases as $input => $secret) {
            $masked = Logger::maskString($input);
            $this->assertNotContains($secret, $masked, "마스킹 실패: {$input}");
            $this->assertContains(Logger::MASK, $masked);
        }
        $this->assertSame('분석 완료: 결함 19건', Logger::maskString('분석 완료: 결함 19건'), '평범한 문장은 그대로');
    }

    public function testLevelThreshold(): void
    {
        $dir = $this->tempDir('log');
        $clock = new FixedClock();
        $log = new Logger($dir, $clock, 'WARN');
        $log->info('보이면 안 됨');
        $log->warn('경고');
        $log->error('오류');
        $levels = array_column($this->lines($dir, $clock), 'level');
        $this->assertSame(['WARN', 'ERROR'], $levels);
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $log->log('DEBUG', 'x'));
    }

    public function testPurgeOlderThanRetention(): void
    {
        $dir = $this->tempDir('log');
        foreach (['app-20260801.log', 'app-20260901.log', 'app-20261001.log', 'other.txt'] as $f) {
            file_put_contents("$dir/$f", "x\n");
        }
        $log = new Logger($dir, new FixedClock('2026-10-02T00:00:00Z'));
        $deleted = $log->purgeOlderThan(30 * 86400);
        sort($deleted);
        $this->assertSame(['app-20260801.log', 'app-20260901.log'], $deleted);
        $this->assertTrue(is_file("$dir/app-20261001.log"));
        $this->assertTrue(is_file("$dir/other.txt"), '로그 형식이 아닌 파일은 건드리지 않음');
    }
}
