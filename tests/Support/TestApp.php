<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

use PJGuardian\App;
use PJGuardian\Http\Request;
use PJGuardian\Support\Config;
use PJGuardian\Support\FixedClock;
use PJGuardian\Support\Logger;
use PJGuardian\Support\Paths;

/**
 * 테스트용 앱 조립: config.ini.example을 바탕으로 일부 값만 바꾸고, 로그는 임시 디렉토리에 쓴다.
 */
final class TestApp
{
    /**
     * config.ini.example을 읽어 값을 덮어쓴 원시 배열.
     *
     * @param array<string, array<string, string>> $overrides
     * @return array<string, array<string, string>>
     */
    public static function rawConfig(array $overrides = []): array
    {
        $raw = parse_ini_file(PJG_ROOT . '/config/config.ini.example', true, INI_SCANNER_RAW);
        // 규칙 데이터 자동 적재(P22)는 시험에서 기본으로 끈다(워커 시험이 실제 도구를 실행하지 않게). 켜는 시험은 overrides로
        $raw['rules']['auto_load'] = 'false';
        // 백업 폴더(P27B)는 기본값이 프로젝트 바로 아래 backups/라서, 시험이 띄운 워커의 일일 백업이 실제 프로젝트 폴더에 쓰지 않게
        // 이 시험 실행 전용 임시 폴더(프로젝트 밖 — PathPolicy 허용)로 바꾼다. 실행이 끝나면 지운다. 바꾸는 시험은 overrides로
        $raw['backup']['dir'] = self::backupDir();
        foreach ($overrides as $section => $keys) {
            foreach ($keys as $k => $v) {
                $raw[$section][$k] = $v;
            }
        }
        return $raw;
    }

    /** 시험 실행(러너 프로세스)마다 하나인 임시 백업 폴더 — 러너가 끝날 때 지운다 */
    public static function backupDir(): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/pjg_test_bk_' . getmypid() . '_' . bin2hex(random_bytes(3));
            $made = $dir;
            register_shutdown_function(static function () use ($made): void {
                if (is_dir($made)) {
                    \PJGuardian\Services\Backup\BackupService::removeTree($made);
                }
            });
        }
        return $dir;
    }

    /**
     * 설정 파일로 쓴다(워커처럼 별도 프로세스가 --config=로 읽는 시험용). 값은 모두 큰따옴표로 감싼다.
     *
     * @param array<string, array<string, string>> $overrides
     */
    public static function writeIni(string $file, array $overrides = []): string
    {
        $lines = [];
        foreach (self::rawConfig($overrides) as $section => $keys) {
            $lines[] = '[' . $section . ']';
            foreach ($keys as $k => $v) {
                $lines[] = $k . ' = "' . trim((string) $v, '"') . '"';
            }
            $lines[] = '';
        }
        file_put_contents($file, implode("\n", $lines));
        Config::load($file); // 바로 검증(틀리면 예외)
        return $file;
    }

    /** @param array<string, array<string, string>> $overrides */
    /** @param array<string, string> $secrets 시크릿(P25 encryption_key 등) */
    public static function config(array $overrides = [], array $secrets = []): Config
    {
        return Config::fromArray(self::rawConfig($overrides), $secrets);
    }

    /**
     * 메모리 DB(마이그레이션 적용)를 넣은 앱. bcrypt 비용은 시험 속도를 위해 4로 낮춘다(따로 주면 그 값).
     *
     * @param array<string, array<string, string>> $overrides
     */
    public static function make(string $logDir, array $overrides = [], array $secrets = []): App
    {
        $overrides['paths']['logs_dir'] = $logDir;
        $overrides['auth']['bcrypt_cost'] ??= '4';
        $config = self::config($overrides, $secrets);
        $clock = new FixedClock('2026-10-02T12:00:00Z');
        $paths = new Paths(PJG_ROOT, $config);
        $logger = new Logger($paths->logs(), $clock, 'INFO');
        return new App(PJG_ROOT, $config, $clock, $logger, $paths, TestDb::migrated());
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public static function request(string $method, string $path, array $query = [], string $body = '', bool $secure = true, array $headers = []): Request
    {
        return new Request($method, $path, $query, $headers, $body, $secure, '127.0.0.1');
    }
}
