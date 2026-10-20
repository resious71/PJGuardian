<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * 도구 버전 디렉토리의 체크섬 매니페스트 `SHA256SUMS`(명세 12, 19.10). 형식은 sha256sum 출력: `<해시 64자리> *<상대 경로>`(또는 공백 둘).
 *
 * - verify(): 매니페스트의 **모든** 파일을 SHA-256으로 대조한다(활성화 전, 지문이 바뀌었을 때). ESLint는 node_modules 파일 수천 개라 수 초 걸린다.
 * - fingerprint(): 매니페스트에 적힌 파일들의 **크기·수정 시각**만 모아 만든 지문. 분석 실행 전에는 이것만 대조하고,
 *   달라졌을 때만 verify()를 다시 한다(절충: 내용만 바꾸고 크기·시각을 그대로 맞춘 변조는 다음 전체 검증 전까지 못 잡는다.
 *   bin/에는 웹·앱 계정이 쓰지 못하게 권한을 둔다는 전제, docs/tool_setup.md 5절).
 * - 매니페스트에 없는 실행 파일(예: ruff.exe)은 활성화하지 않는다(requiredPresent).
 */
final class ToolManifest
{
    public const FILE = 'SHA256SUMS';

    /** @return array<string, string> 상대 경로('/' 구분) → 소문자 해시. 형식이 틀린 줄이 있으면 예외 */
    public static function parse(string $versionDir): array
    {
        $path = $versionDir . '/' . self::FILE;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('매니페스트 없음');
        }
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $no => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([0-9a-fA-F]{64}) [ *](.+)$/', rtrim($line), $m) !== 1) {
                throw new \RuntimeException('매니페스트 형식 오류: ' . ($no + 1) . '줄');
            }
            $rel = str_replace('\\', '/', $m[2]);
            if (str_starts_with($rel, './')) {
                $rel = substr($rel, 2);
            }
            // 매니페스트가 버전 디렉토리 밖을 가리키면 거부(../, 절대 경로)
            if ($rel === '' || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel) === 1 || preg_match('/^[A-Za-z]:/', $rel) === 1) {
                throw new \RuntimeException('매니페스트 경로 오류: ' . ($no + 1) . '줄');
            }
            $out[$rel] = strtolower($m[1]);
        }
        if ($out === []) {
            throw new \RuntimeException('매니페스트가 비어 있음');
        }
        return $out;
    }

    /**
     * 전체 검증.
     *
     * @param list<string> $requiredPresent 매니페스트에 반드시 있어야 하는 파일(실행 파일)
     * @return array{ok:bool, files:int, problems:list<string>}
     */
    public static function verify(string $versionDir, array $requiredPresent = []): array
    {
        try {
            $entries = self::parse($versionDir);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'files' => 0, 'problems' => [$e->getMessage()]];
        }
        $problems = [];
        foreach ($requiredPresent as $req) {
            if (!isset($entries[$req])) {
                $problems[] = '매니페스트에 없음: ' . $req;
            }
        }
        foreach ($entries as $rel => $hash) {
            $file = $versionDir . '/' . $rel;
            if (!is_file($file)) {
                $problems[] = '파일 없음: ' . $rel;
            } elseif (!hash_equals($hash, (string) hash_file('sha256', $file))) {
                $problems[] = '해시 불일치: ' . $rel;
            }
            if (count($problems) >= 20) {
                $problems[] = '…(더 있음)';
                break;
            }
        }
        return ['ok' => $problems === [], 'files' => count($entries), 'problems' => $problems];
    }

    /** 크기·수정 시각 지문(매니페스트 파일 자체도 포함). 매니페스트가 없거나 깨졌으면 null */
    public static function fingerprint(string $versionDir): ?string
    {
        try {
            $entries = self::parse($versionDir);
        } catch (\RuntimeException) {
            return null;
        }
        $ctx = hash_init('sha256');
        $m = $versionDir . '/' . self::FILE;
        clearstatcache();
        hash_update($ctx, self::FILE . "\t" . (int) @filesize($m) . "\t" . (int) @filemtime($m) . "\n");
        ksort($entries, SORT_STRING);
        foreach (array_keys($entries) as $rel) {
            $f = $versionDir . '/' . $rel;
            $size = @filesize($f);
            $mtime = @filemtime($f);
            hash_update($ctx, $rel . "\t" . ($size === false ? '-' : $size) . "\t" . ($mtime === false ? '-' : $mtime) . "\n");
        }
        return hash_final($ctx);
    }
}
