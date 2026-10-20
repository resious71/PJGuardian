<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 정적 파일 묶음 버전(P21 0절): public/assets/ 아래 **모든 파일의 내용**으로 만든 짧은 해시 하나.
 * 화면은 `/assets/v/<해시>/js/…`처럼 경로에 이 값을 넣는다. ES 모듈의 상대 import(`../core/i18n.js`, `../../icons/icons.json`)도
 * 같은 경로 접두사로 풀리므로 파일 하나가 바뀌면 진입 파일부터 하위 모듈까지 모두 새 주소가 된다(Ctrl+F5 불필요).
 *
 * 비용: 요청마다 파일 목록과 크기·수정 시각만 본다(stat, 파일 약 150개 수 ms). 그 서명이 캐시 파일과 같으면 저장된 해시를 쓰고,
 * 다르면(파일이 바뀜·배포) 내용을 다시 해시한다. 배포로 수정 시각만 바뀌고 내용이 같으면 해시도 같다(불필요한 갱신 없음).
 */
final class AssetVersion
{
    private ?string $hash = null;

    public function __construct(private readonly string $assetsDir, private readonly string $cacheFile)
    {
    }

    public function hash(): string
    {
        if ($this->hash !== null) {
            return $this->hash;
        }
        $files = self::files($this->assetsDir);
        $sig = [];
        foreach ($files as $rel => $abs) {
            $st = @stat($abs);
            $sig[] = $rel . '|' . ($st['size'] ?? 0) . '|' . ($st['mtime'] ?? 0);
        }
        $signature = sha1(implode("\n", $sig));
        $cached = @file_get_contents($this->cacheFile);
        $c = $cached === false ? null : json_decode($cached, true);
        if (is_array($c) && ($c['signature'] ?? null) === $signature && is_string($c['hash'] ?? null)) {
            return $this->hash = $c['hash'];
        }
        $parts = [];
        foreach ($files as $rel => $abs) {
            $parts[] = $rel . "\0" . (string) @sha1_file($abs);
        }
        $this->hash = substr(hash('sha256', implode("\n", $parts)), 0, 12);
        try {
            Fs::ensureDir(dirname($this->cacheFile));
            Fs::atomicWrite($this->cacheFile, (string) json_encode(['signature' => $signature, 'hash' => $this->hash, 'files' => count($files)]));
        } catch (\Throwable) {
            // 캐시를 못 써도 값은 같다(다음 요청이 다시 계산)
        }
        return $this->hash;
    }

    /** @return array<string, string> 상대 경로(정렬) → 절대 경로 */
    private static function files(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $base = strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $abs = str_replace('\\', '/', $f->getPathname());
                $out[substr($abs, $base)] = $abs;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
