<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 규칙 설명 페이지 주소(명세 6.4, 11장). 링크는 **이 함수 한 곳**에서만 만든다(화면 템플릿과 API의 rule_url 모두).
 *
 * 대상 경로는 `/rules/<도구>/<규칙>`로 정했다(예: /rules/ruff/F401, /rules/eslint/@typescript-eslint/no-explicit-any, 문법 오류는 /rules/syntax-error).
 * P22 전에는 ENABLED = false라 null을 돌려주고 화면은 규칙 키를 텍스트로만 보여 주었다(미완성 링크 노출 금지). P22에서 켰다.
 */
final class RuleLinks
{
    public const ENABLED = true; // P22: 규칙 설명 페이지가 생겨 링크를 켠다

    /** 앱 안 경로(배포 경로 접두사 없음). 링크를 만들지 않을 때는 null */
    public static function path(string $ruleKey): ?string
    {
        if (!self::ENABLED) {
            return null;
        }
        return self::target($ruleKey);
    }

    /** 링크 대상 경로 규칙(ENABLED와 무관, 시험용). 형식이 틀린 키는 null */
    public static function target(string $ruleKey): ?string
    {
        if ($ruleKey === 'syntax-error') {
            return '/rules/syntax-error';
        }
        if (preg_match('#^(ruff|eslint):([A-Za-z0-9@/_.-]{1,190})$#', $ruleKey, $m) !== 1 || str_contains($m[2], '..')) {
            return null;
        }
        $segments = array_map('rawurlencode', explode('/', $m[2]));
        return '/rules/' . $m[1] . '/' . implode('/', $segments);
    }
}
