<?php

declare(strict_types=1);

/**
 * 시험용 프로세스 트리: 손자 프로세스(php -r sleep)를 띄우고 두 PID를 파일에 쓴 뒤 기다린다.
 * 사용: php tree.php <pid 파일> <초>
 */
$pidFile = $argv[1];
$seconds = (int) ($argv[2] ?? 60);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$child = proc_open([PHP_BINARY, '-r', 'sleep(' . $seconds . ');'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
$grand = proc_get_status($child)['pid'];
file_put_contents($pidFile . '.tmp', getmypid() . ' ' . $grand);
rename($pidFile . '.tmp', $pidFile);
echo "started\n";
sleep($seconds);
