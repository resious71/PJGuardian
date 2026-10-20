<?php

declare(strict_types=1);

namespace PJGuardian\Services\Process;

/** 허용되지 않은 실행 파일·작업 디렉토리(보안 규칙 위반). 사용자에게 내부 경로를 보이지 않는다 */
final class ProcessException extends \RuntimeException
{
}
