<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Unit;

use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigException;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testExampleFileIsValid(): void
    {
        $c = Config::load(PJG_ROOT . '/config/config.ini.example');
        $this->assertSame(2 * 1024 ** 3, $c->get('upload.max_zip_size'));
        $this->assertSame(8 * 1024 ** 2, $c->get('upload.chunk_size'));
        $this->assertSame(4 * 3600, $c->get('analysis.timeout'));
        $this->assertSame(30 * 60, $c->get('auth.session_idle'));
        $this->assertSame(true, $c->get('security.require_https'));
        $this->assertSame(null, $c->get('tools.node_path'));
        $this->assertSame([], $c->get('health.allowed_ips'));
    }

    public function testExampleHasDefaultExcludesFromSpec1910(): void
    {
        $ex = TestApp::config()->get('extract.default_excludes');
        foreach (['node_modules', '.venv', 'venv', '__pycache__', '.git', 'dist', 'build', '__MACOSX', '.DS_Store'] as $name) {
            $this->assertTrue(in_array($name, $ex, true), "기본 제외 목록에 {$name} 없음");
        }
    }

    public function testParseSize(): void
    {
        $this->assertSame(8 * 1024 * 1024, Config::parseSize('8MB'));
        $this->assertSame(2 * 1024 ** 3, Config::parseSize('2gb'));
        $this->assertSame((int) (1.5 * 1024 ** 3), Config::parseSize('1.5GB'));
        $this->assertSame(512 * 1024, Config::parseSize('512 KB'));
        $this->assertSame(1048576, Config::parseSize('1048576'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Config::parseSize('8 megabytes'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Config::parseSize('-1MB'));
    }

    public function testParseDuration(): void
    {
        $this->assertSame(1800, Config::parseDuration('30m'));
        $this->assertSame(14400, Config::parseDuration('4h'));
        $this->assertSame(60 * 86400, Config::parseDuration('60d'));
        $this->assertSame(604800, Config::parseDuration('1w'));
        $this->assertSame(90, Config::parseDuration('90'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Config::parseDuration('1.5h'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Config::parseDuration('soon'));
    }

    public function testRejectsWrongValuesAndNamesEveryKey(): void
    {
        /** @var ConfigException $e */
        $e = $this->assertThrows(ConfigException::class, static fn () => TestApp::config([
            'upload' => ['chunk_size' => 'eight'],
            'analysis' => ['max_concurrent' => '0'],
            'log' => ['level' => 'DEBUG'],
            'security' => ['require_https' => 'maybe'],
            'app' => ['display_timezone' => 'Mars/Base'],
        ]));
        $all = implode("\n", $e->errors);
        $this->assertContains('upload.chunk_size: 크기 형식이 아닙니다', $all);
        $this->assertContains('analysis.max_concurrent: 너무 작습니다', $all);
        $this->assertContains('log.level: 다음 중 하나여야 합니다', $all);
        $this->assertContains('security.require_https: true 또는 false', $all);
        $this->assertContains('app.display_timezone: 알 수 없는 시간대', $all);
        $this->assertSame(5, count($e->errors), '오류를 한 번에 모두 알려야 함');
    }

    public function testRejectsRangeWithReadableLimit(): void
    {
        $e = $this->assertThrows(ConfigException::class, static fn () => TestApp::config(['upload' => ['chunk_size' => '128MB']]));
        $this->assertContains('upload.chunk_size: 너무 큽니다(최대 64MB)', $e->getMessage());
    }

    /** P13: 나중 단계에서 추가한 키(default가 있는 키)가 없는 기존 config.ini도 기동된다. 값을 주면 검증한다 */
    public function testMissingKeyWithDefaultUsesDefault(): void
    {
        $raw = TestApp::rawConfig();
        unset($raw['upload']['confirmation_retention'], $raw['extract']['worker_memory_limit']);
        $c = Config::fromArray($raw);
        $this->assertSame(24 * 3600, $c->int('upload.confirmation_retention'));
        $this->assertSame(512 * 1024 ** 2, $c->int('extract.worker_memory_limit'));
        $e = $this->assertThrows(ConfigException::class, static fn () => TestApp::config(['extract' => ['worker_memory_limit' => '1MB']]));
        $this->assertContains('extract.worker_memory_limit: 너무 작습니다', $e->getMessage());
    }

    public function testRejectsUnknownAndMissingKeys(): void
    {
        $raw = TestApp::rawConfig();
        $raw['upload']['chunk_sise'] = '8MB';
        unset($raw['analysis']['timeout']);
        $raw['mystery'] = ['x' => '1'];
        $e = $this->assertThrows(ConfigException::class, static fn () => Config::fromArray($raw));
        $msg = $e->getMessage();
        $this->assertContains('upload.chunk_sise: 알 수 없는 키', $msg);
        $this->assertContains('analysis.timeout: 키가 없습니다', $msg);
        $this->assertContains('[mystery]: 알 수 없는 섹션', $msg);
    }

    public function testCrossFieldChecks(): void
    {
        $e = $this->assertThrows(ConfigException::class, static fn () => TestApp::config(['disk' => ['warn_percent' => '95', 'block_percent' => '90']]));
        $this->assertContains('disk.block_percent: warn_percent보다 커야', $e->getMessage());
        $e = $this->assertThrows(ConfigException::class, static fn () => TestApp::config(['app' => ['base_url' => 'http://pjguardian.local']]));
        $this->assertContains('app.base_url: security.require_https가 true이면 https://', $e->getMessage());
        // require_https=false(개발용)면 http 주소 허용
        $c = TestApp::config(['app' => ['base_url' => 'http://localhost/JGuardian/public'], 'security' => ['require_https' => 'false']]);
        $this->assertSame('http://localhost/JGuardian/public', $c->get('app.base_url'));
    }

    public function testInvalidIniSyntaxIsRejected(): void
    {
        $dir = $this->tempDir('cfg');
        file_put_contents("$dir/bad.ini", "[upload\nchunk_size = 8MB\n");
        $this->assertThrows(ConfigException::class, static fn () => Config::load("$dir/bad.ini"), 'INI 문법 오류');
    }

    public function testErrorMessageDoesNotEchoValues(): void
    {
        $e = $this->assertThrows(ConfigException::class, static fn () => TestApp::config(['smtp' => ['port' => 'hunter2-secret']]));
        $this->assertNotContains('hunter2-secret', $e->getMessage(), '오류 문장에 값이 들어가면 안 됨(시크릿 노출 방지)');
    }

    public function testSecretsFromFileAndEnvironmentOverride(): void
    {
        $dir = $this->tempDir('sec');
        copy(PJG_ROOT . '/config/config.ini.example', "$dir/config.ini");
        file_put_contents("$dir/secrets.ini", "encryption_key = \"from-file\"\nsmtp_password = \"smtp-file\"\n");
        $c = Config::load("$dir/config.ini", "$dir/secrets.ini", ['PJG_ENCRYPTION_KEY' => 'from-env']);
        $this->assertSame('from-env', $c->secret('encryption_key'), '환경 변수가 우선');
        $this->assertSame('smtp-file', $c->secret('smtp_password'));
        $this->assertSame(null, $c->secret('ldap_bind_password'));
        file_put_contents("$dir/secrets.ini", "unknown_secret = x\n");
        $this->assertThrows(ConfigException::class, static fn () => Config::load("$dir/config.ini", "$dir/secrets.ini"), 'unknown_secret');
    }

    public function testUnregisteredKeyIsProgrammingError(): void
    {
        $this->assertThrows(\LogicException::class, static fn () => TestApp::config()->get('upload.nope'));
    }
}
