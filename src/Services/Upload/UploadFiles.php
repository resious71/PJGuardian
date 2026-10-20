<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Support\Fs;

/**
 * 업로드 파일 배치(명세 19.10, docs/architecture.md 17.2). **모든 파일은 uploads/<upload_id>/ 아래에만** 둔다
 * (프로젝트 삭제가 이 폴더를 지운다).
 *   <n>.part       받은 청크(n = 0부터, 정수로만 만든다)
 *   <n>.sha256     그 청크의 SHA-256(이어 올리기 비교용)
 *   .lock          잠금 전용 파일(Windows flock은 강제 잠금이라 다른 파일을 잠그지 않는다)
 *   upload.zip     조립본(조립 후)
 * 경로 요소는 서버가 만든 upload_id(32자리 16진수)와 정수 청크 번호뿐이다. 사용자가 보낸 파일명은 쓰지 않는다.
 */
final class UploadFiles
{
    public function __construct(private readonly string $root)
    {
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function dir(string $id): string
    {
        if (!self::validId($id)) {
            throw new \InvalidArgumentException('upload_id 형식');
        }
        return $this->root . '/' . $id;
    }

    public function part(string $id, int $n): string
    {
        return $this->dir($id) . '/' . $n . '.part';
    }

    public function partHash(string $id, int $n): string
    {
        return $this->dir($id) . '/' . $n . '.sha256';
    }

    public function lock(string $id): string
    {
        return $this->dir($id) . '/.lock';
    }

    public function zip(string $id): string
    {
        return $this->dir($id) . '/upload.zip';
    }

    /** @return list<int> 받은 청크 번호(오름차순). 디스크의 청크 파일이 원본이다 */
    public function receivedChunks(string $id): array
    {
        $out = [];
        foreach (Fs::listDir($this->dir($id)) as $name) {
            if (preg_match('/^(\d{1,7})\.part$/', $name, $m) === 1) {
                $out[] = (int) $m[1];
            }
        }
        sort($out);
        return $out;
    }

    public function remove(string $id): bool
    {
        $dir = $this->dir($id);
        return !file_exists($dir) || Fs::removeTree($dir);
    }
}
