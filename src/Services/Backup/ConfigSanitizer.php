<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

/**
 * 설정 파일 사본의 민감 값 비우기(P27B, 명세 19.4). 줄 단위로 섹션을 따라가며 BundleFormat::sensitiveKey인 키의 값을 ""로 바꾼다.
 * 주석·순서·다른 값은 그대로 둔다. 비운 키("섹션.키") 목록을 manifest에 적는다(복원 뒤 다시 입력할 항목 안내).
 */
final class ConfigSanitizer
{
    /** @return array{0: string, 1: list<string>} [비운 사본, 비운 키] */
    public static function sanitize(string $ini): array
    {
        $section = '';
        $blanked = [];
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $ini) ?: [] as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m) === 1) {
                $section = trim($m[1]);
                $out[] = $line;
                continue;
            }
            if (preg_match('/^(\s*)([A-Za-z0-9_.]+)(\s*=\s*)(.*)$/', $line, $m) === 1 && BundleFormat::sensitiveKey($m[2])) {
                $value = trim($m[4]);
                if ($value !== '' && $value !== '""' && $value !== "''") {
                    $blanked[] = ($section === '' ? '' : $section . '.') . $m[2];
                }
                $out[] = $m[1] . $m[2] . $m[3] . '""';
                continue;
            }
            $out[] = $line;
        }
        return [implode("\n", $out), $blanked];
    }

    /**
     * INI 원문 → [섹션.키 => 값(따옴표 벗김)]. 설정 복원 차이 비교용(형식 검증은 Config가 한다).
     *
     * @return array<string, string>
     */
    public static function flatten(string $ini): array
    {
        $parsed = @parse_ini_string($ini, true, INI_SCANNER_RAW);
        $out = [];
        foreach (is_array($parsed) ? $parsed : [] as $section => $keys) {
            if (!is_array($keys)) {
                continue;
            }
            foreach ($keys as $k => $v) {
                if (is_array($v)) {
                    continue;
                }
                $v = trim((string) $v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $out[$section . '.' . $k] = $v;
            }
        }
        return $out;
    }
}
