<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

/** 규칙 정보 출처(P22): 실제는 RuleExtractor(설치된 도구 실행), 시험은 고정 데이터 */
interface RuleSource
{
    /**
     * @return array{version:string, components:array<string, string>, rules:array<string, array<string, mixed>>}
     */
    public function extract(string $tool, string $version): array;
}
