<?php

declare(strict_types=1);

namespace PJGuardian\Services\Severity;

/** 심각도 매핑 파일이 없거나 형식이 틀림(시작 시 점검에서 원인을 그대로 보여 준다. 화면 응답에는 넣지 않는다) */
final class SeverityMapException extends \RuntimeException
{
}
