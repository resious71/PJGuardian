<?php

declare(strict_types=1);

/** 시험용: 표준 출력에 1MB씩 끝없이 쓴다(출력 한도 시험) */
$block = str_repeat('x', 1024 * 1024);
while (true) {
    fwrite(STDOUT, $block);
}
