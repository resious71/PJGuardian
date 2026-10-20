<?php

declare(strict_types=1);

/**
 * 경로 확인 도우미(P27A): Services\Settings\PathPolicy가 UNC 경로(\\서버\공유\…)를 확인할 때 자식 프로세스로 부른다.
 * 네트워크 공유 접근은 수십 초 걸릴 수 있어 부모가 시간 상한(5초)으로 끊는다. 결과는 표준 출력의 JSON 한 줄(PathPolicy::probe와 같은 모양).
 * 사용: php tools/lib/path_probe.php <정규화한 경로("/" 구분)> <만들기 0|1>
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

[, $path, $create] = $argv + [1 => '', 2 => '0'];
if ($path === '') {
    exit(2);
}
echo json_encode(\PJGuardian\Services\Settings\PathPolicy::probe($path, $create === '1'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
