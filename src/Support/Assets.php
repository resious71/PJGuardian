<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * public/assets/ 정적 파일 주소(P21 0절): `<base>/assets/v/<묶음 버전>/<경로>`. 묶음 버전은 assets 전체 내용의 해시(AssetVersion)라
 * 파일이 하나라도 바뀌면 모든 주소가 바뀐다. 경로에 버전이 들어 있으므로 ES 모듈의 상대 import(하위 모듈·JSON)도 같은 버전 경로로 풀린다.
 * 응답은 Http\StaticAssets(버전이 맞으면 오래 캐시, 아니면 no-cache). 템플릿은 정적 파일 주소를 이 함수로만 만든다.
 * basePath는 앱이 놓인 웹 경로 접두사(vhost는 '', 하위 폴더 배포는 '/JGuardian/public' 등).
 */
final class Assets
{
    private readonly AssetVersion $version;

    public function __construct(private readonly string $publicDir, private readonly string $basePath = '', ?AssetVersion $version = null)
    {
        $this->version = $version ?? new AssetVersion($publicDir . '/assets', sys_get_temp_dir() . '/pjg_assets_version.json');
    }

    public function url(string $relPath): string
    {
        $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
        if (str_contains($relPath, '..')) {
            throw new \InvalidArgumentException('자산 경로에 ..를 쓸 수 없음: ' . $relPath);
        }
        return $this->basePath . '/assets/v/' . $this->version->hash() . '/' . $relPath;
    }

    /** 앱 안의 페이지 주소(배포 위치 접두사 포함) */
    public function page(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }
}
