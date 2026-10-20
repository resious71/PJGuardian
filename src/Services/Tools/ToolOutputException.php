<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/** 도구 출력의 필수 필드가 없거나 형식이 틀림(분석 실패 tool_crash) */
final class ToolOutputException extends \RuntimeException
{
}
