<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 분석 언어 ↔ 분석 도구 대응(한 곳, X08). 프로젝트 언어(projects.language CHECK)는 python·javascript(JS/TS) 둘이고 도구가 하나씩 정해진다.
 * 대시보드 언어 필터(P21C), 업로드 분석 등록, 규칙 설명 탭(X08)이 이것을 쓴다. 화면 쪽은 public/assets/js/lib/tool_language.js(같은 값).
 */
final class ToolLanguage
{
    public const TOOL_BY_LANGUAGE = ['python' => 'ruff', 'javascript' => 'eslint'];

    /** 언어 → 도구(모르는 언어는 null) */
    public static function tool(?string $language): ?string
    {
        return self::TOOL_BY_LANGUAGE[$language ?? ''] ?? null;
    }

    /** 도구 → 언어(모르는 도구는 null) */
    public static function language(?string $tool): ?string
    {
        $found = array_search($tool, self::TOOL_BY_LANGUAGE, true);
        return $found === false ? null : (string) $found;
    }
}
