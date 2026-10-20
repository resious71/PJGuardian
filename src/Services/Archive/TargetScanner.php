<?php

declare(strict_types=1);

namespace PJGuardian\Services\Archive;

/**
 * 사전 검사(명세 5.4): 해제된 디렉토리에서 프로젝트 언어의 대상 확장자 파일 수를 센다.
 * JavaScript/TypeScript는 업로드된 ESLint 설정 파일(eslint.config.*, .eslintrc.*)을 분석 대상에서 빼므로(명세 19.10) 세지 않는다.
 * 해제 결과에는 심볼릭 링크가 없다(SafeUnzip이 거부). 그래도 링크는 따라가지 않는다.
 */
final class TargetScanner
{
    /** @param list<string> $extensions 점 없는 소문자 확장자 */
    public static function count(string $dir, string $language, array $extensions): int
    {
        return self::scan($dir, $language, $extensions)['total'];
    }

    /**
     * 분석 대상 파일 수와 확장자별 수, 대상에서 뺀 업로드 ESLint 설정 파일 수(업로드 내용 요약, P21C). 한 번 훑는다.
     *
     * @param list<string> $extensions
     * @return array{total:int, by_extension:array<string, int>, config_skipped:int}
     */
    public static function scan(string $dir, string $language, array $extensions): array
    {
        $by = array_fill_keys($extensions, 0);
        $out = ['total' => 0, 'by_extension' => $by, 'config_skipped' => 0];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $ext = strtolower($f->getExtension());
            if (!$f->isFile() || $f->isLink() || !isset($by[$ext])) {
                continue;
            }
            if ($language === 'javascript' && self::isJsConfig($f->getFilename())) {
                $out['config_skipped']++;
                continue;
            }
            $out['total']++;
            $out['by_extension'][$ext]++;
        }
        return $out;
    }

    /** 업로드된 ESLint 설정 파일(분석 대상에서 뺀다, 명세 19.10) */
    public static function isJsConfig(string $name): bool
    {
        return preg_match('/^(eslint\.config\.[A-Za-z0-9]+|\.eslintrc(\.[A-Za-z0-9]+)?)$/', $name) === 1;
    }
}
