<?php

declare(strict_types=1);

/** 시험용: 인자를 그대로 한 줄씩 출력하고 환경 변수 PJG_PROBE 유무를 쓴 뒤 마지막 인자(정수)로 끝난다 */
$args = array_slice($argv, 1);
foreach ($args as $a) {
    echo $a, "\n";
}
echo 'probe=', getenv('PJG_PROBE') === false ? 'none' : 'set', "\n";
fwrite(STDERR, "err-line\n");
exit((int) end($args));
