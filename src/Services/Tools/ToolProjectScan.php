<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

use PJGuardian\Support\ExcludeMatcher;

/**
 * 분석 루트를 한 번 훑어 도구 설정 정책에 필요한 파일을 찾는다(명세 5.3, 19.10). 제외 대상 폴더(ExcludeMatcher)는 들어가지 않는다.
 *   - ruff: 하위 폴더의 ruff.toml / .ruff.toml / pyproject.toml(ruff 설정 포함) → 쓰지 않고 "무시됨" 기록
 *   - ESLint: 업로드된 ESLint 설정(eslint.config.*, .eslintrc*) → 통째로 무시하고 기록. JS 설정 파일은 분석 대상에서도 뺀다(ignores)
 *   - 루트의 .eslintignore → 서버 ignore 패턴으로 변환
 * 목록은 도구별 최대 100개(그 이상은 개수만).
 */
final class ToolProjectScan
{
    public const LIST_MAX = 100;

    /** @var list<string> */
    public array $ruffNested = [];
    public int $ruffNestedTotal = 0;
    /** @var list<string> */
    public array $eslintConfigs = [];
    public int $eslintConfigsTotal = 0;
    public bool $hasEslintIgnore = false;

    public static function run(string $root, ExcludeMatcher $matcher): self
    {
        $scan = new self();
        $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
        $scan->hasEslintIgnore = is_file($root . '/.eslintignore');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
                static function (\SplFileInfo $f) use ($root, $matcher): bool {
                    if ($f->isLink()) {
                        return false;
                    }
                    if ($f->isDir()) {
                        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
                        return !$matcher->matchesDir($rel);
                    }
                    return true;
                },
            ),
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $name = $f->getFilename();
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
            $nested = str_contains($rel, '/');
            if ($nested && ($name === 'ruff.toml' || $name === '.ruff.toml'
                || ($name === 'pyproject.toml' && self::safeHasRuff($f->getPathname())))) {
                $scan->ruffNestedTotal++;
                if (count($scan->ruffNested) < self::LIST_MAX) {
                    $scan->ruffNested[] = $rel;
                }
            }
            if (self::isEslintConfigName($name)) {
                $scan->eslintConfigsTotal++;
                if (count($scan->eslintConfigs) < self::LIST_MAX) {
                    $scan->eslintConfigs[] = $rel;
                }
            }
        }
        sort($scan->ruffNested, SORT_STRING);
        sort($scan->eslintConfigs, SORT_STRING);
        return $scan;
    }

    /** eslint.config.(js|mjs|cjs|ts|mts|cts) 또는 .eslintrc / .eslintrc.* */
    public static function isEslintConfigName(string $name): bool
    {
        return preg_match('/^eslint\.config\.[A-Za-z]+$/', $name) === 1 || preg_match('/^\.eslintrc(\.[A-Za-z]+)?$/', $name) === 1;
    }

    private static function safeHasRuff(string $file): bool
    {
        try {
            return RuffConfigResolver::pyprojectHasRuff($file);
        } catch (\Throwable) {
            return false;
        }
    }
}
