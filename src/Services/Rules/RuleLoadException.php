<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

/** 규칙 데이터 적재 실패(도구 없음·실행 오류·시간 초과·출력 형식). 분석에는 영향이 없다(P22) */
final class RuleLoadException extends \RuntimeException
{
}
