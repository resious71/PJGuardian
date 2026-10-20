<?php

declare(strict_types=1);

/**
 * 설정 검증 (명세 19.7: 시작할 때 값의 범위와 형식을 검증하고 오류가 있으면 기동을 거부)
 *
 * 사용법: php tools/config_check.php [설정 파일 경로]
 *   기본: config/config.ini (+ config/secrets.ini, 환경 변수 PJG_*)
 * 종료 코드: 정상 0, 설정 오류 1
 * 웹은 같은 검증에 실패하면 503과 일반 메시지만 내보내고 원인은 서버 로그에 남긴다.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\Support\Config;
use PJGuardian\Support\ConfigException;

$path = $argv[1] ?? PJG_ROOT . '/config/config.ini';
try {
    $config = is_file($path) || isset($argv[1])
        ? Config::load($path, PJG_ROOT . '/config/secrets.ini', getenv())
        : Config::fromRoot(PJG_ROOT);
    echo "설정 정상: {$path}\n";
    $secrets = array_filter(Config::SECRET_KEYS, static fn (string $k): bool => $config->secret($k) !== null);
    echo '시크릿 설정됨: ' . ($secrets === [] ? '(없음)' : implode(', ', $secrets)) . "\n";
    // P27A 화면 설정(settings.local.ini)·환경 변수 덮어쓰기 — 값은 출력하지 않고 키·출처·무시 이유만
    $by = ['screen' => [], 'env' => []];
    foreach (array_keys($config->registry()->all()) as $name) {
        $src = $config->source($name);
        if (isset($by[$src])) {
            $by[$src][] = $name;
        }
    }
    echo '화면 설정(' . ($config->localPath() ?? '-') . '): ' . ($by['screen'] === [] ? '(없음)' : implode(', ', $by['screen'])) . "\n";
    if ($by['env'] !== []) {
        echo '환경 변수가 정한 값: ' . implode(', ', $by['env']) . "\n";
    }
    foreach ($config->warnings() as $w) {
        echo '경고: 무시한 화면 설정 ' . ($w['key'] ?? '(파일 전체)') . " — {$w['code']}\n";
    }
    exit(0);
} catch (ConfigException $e) {
    fwrite(STDERR, "기동 거부 — " . $e->getMessage() . "\n");
    exit(1);
}
