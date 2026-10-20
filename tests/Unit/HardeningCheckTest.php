<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Services\HardeningCheck;
use PJGuardian\Support\Paths;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

/** 하드닝 점검(명세 19.5): 미충족 항목을 WARN으로 낸다. HTTP 응답은 가짜로 주입한다. */
final class HardeningCheckTest extends TestCase
{
    /** @param array<string, array<string, string>> $over */
    private function run(array $ini, ?\Closure $fetch, array $over = []): array
    {
        $config = TestApp::config($over);
        $results = (new HardeningCheck(PJG_ROOT, $config, new Paths(PJG_ROOT, $config), $ini, $fetch))->run();
        return array_column($results, 'status', 'item');
    }

    private static function goodHeaders(): array
    {
        return [
            'content-security-policy' => "default-src 'self'; script-src 'self'",
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'same-origin',
            'strict-transport-security' => 'max-age=31536000',
            'content-type' => 'application/json',
        ];
    }

    public function testAllGood(): void
    {
        $fetch = static fn (string $path): array => $path === '/api/v1/meta/version'
            ? ['status' => 200, 'headers' => self::goodHeaders()]
            : ['status' => 404, 'headers' => ['content-type' => 'text/html; charset=utf-8']];
        $s = $this->run(['display_errors' => 'Off', 'expose_php' => 'Off', 'log_errors' => 'On'], $fetch);
        foreach (['display_errors', 'expose_php', 'HTTPS 강제', 'app.env', 'bcrypt 비용', '웹 루트 밖 저장', '보안 헤더 응답', 'data·config·bin 웹 접근 차단', '관리·샘플 페이지 웹 노출'] as $item) {
            $this->assertSame('OK', $s[$item] ?? null, $item);
        }
    }

    public function testWarnsOnUnmetItems(): void
    {
        $fetch = static function (string $path): array {
            if ($path === '/api/v1/meta/version') {
                return ['status' => 200, 'headers' => ['x-powered-by' => 'PHP/8.2.12', 'server' => 'Apache/2.4.58 (Win64)', 'content-type' => 'application/json']];
            }
            if ($path === '/config/config.ini') {
                return ['status' => 200, 'headers' => ['content-type' => 'text/plain']];
            }
            if ($path === '/phpmyadmin/') {
                return ['status' => 200, 'headers' => ['content-type' => 'text/html']];
            }
            return ['status' => 404, 'headers' => ['content-type' => 'text/html']];
        };
        $s = $this->run(['display_errors' => 'On'], $fetch, ['app' => ['env' => 'dev'], 'auth' => ['bcrypt_cost' => '4']]);
        foreach (['display_errors', 'expose_php', 'app.env', 'bcrypt 비용', '보안 헤더 응답', 'X-Powered-By 헤더', 'Server 헤더', 'data·config·bin 웹 접근 차단', '관리·샘플 페이지 웹 노출'] as $item) {
            $this->assertSame('WARN', $s[$item] ?? null, $item);
        }
    }

    public function testInitialAdminPasswordNotChangedIsWarn(): void
    {
        $config = TestApp::config();
        $check = static fn (array $pending): array => array_column(
            (new HardeningCheck(PJG_ROOT, $config, new Paths(PJG_ROOT, $config), [], null, null, static fn (): array => $pending))->run(),
            'status',
            'item',
        );
        $this->assertSame('WARN', $check(['admin'])['관리자 초기 비밀번호'], '변경 전 WARN');
        $this->assertSame('OK', $check([])['관리자 초기 비밀번호'], '변경 후 OK');
    }

    public function testUnreachableServerIsInfoNotFailure(): void
    {
        $s = $this->run([], static fn (): ?array => null);
        $this->assertSame('INFO', $s['HTTP 점검']);
        $s = $this->run([], null);
        $this->assertSame('INFO', $s['HTTP 점검'], '--skip-http');
    }
}
