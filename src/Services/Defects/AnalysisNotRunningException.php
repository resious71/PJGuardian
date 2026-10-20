<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

/** 결과를 반영하려는데 분석이 더 이상 running이 아님(취소·타임아웃과 겹침). 트랜잭션 전체를 되돌린다 */
final class AnalysisNotRunningException extends \RuntimeException
{
}
