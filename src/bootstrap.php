<?php

declare(strict_types=1);

/**
 * PJGuardian 부트스트랩: 자체 PSR-4 오토로더와 기본 PHP 설정.
 *
 * - PJGuardian\        → src/
 * - PJGuardian\Tests\  → tests/
 * Composer(P28D부터): 빌드 때 `composer install --no-dev`로 vendor/를 만들고 배포 묶음에 함께 넣는다(composer.json·composer.lock은 커밋, vendor/는 git 무시).
 * vendor/autoload.php가 생기면 함께 불러온다.
 */

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "PJGuardian은 PHP 8.2 이상이 필요합니다(명세 19.10). 현재: " . PHP_VERSION . "\n");
    exit(1);
}

define('PJG_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'PJGuardian\\Tests\\' => PJG_ROOT . '/tests/',
        'PJGuardian\\' => PJG_ROOT . '/src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

if (is_file(PJG_ROOT . '/vendor/autoload.php')) {
    require PJG_ROOT . '/vendor/autoload.php';
}

// 화면에 오류를 내보내지 않는다(명세 19.5). 오류는 로거와 PHP error_log로만 남긴다.
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
