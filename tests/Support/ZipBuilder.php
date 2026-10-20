<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

/**
 * 시험용 zip 작성기(STORE만). 항목 이름의 원래 바이트, UTF-8 플래그(bit 11), 외부 속성(심볼릭 링크 등)을 직접 정한다.
 * ZipArchive로는 만들 수 없는 zip(CP949 이름, 플래그 없는 UTF-8, 제외 폴더 안의 심볼릭 링크 …)을 만들 때 쓴다.
 * 파일에 바로 써서(addStream) 항목 100만 개짜리 zip도 메모리를 적게 쓰고 만든다.
 */
final class ZipBuilder
{
    /** @var resource */
    private $out;
    private int $offset = 0;
    private int $count = 0;
    /** @var resource 중앙 디렉토리를 임시 파일에 모은다(항목이 많아도 메모리를 쓰지 않게) */
    private $central;
    private int $centralSize = 0;

    public function __construct(private readonly string $path)
    {
        $this->out = fopen($path, 'wb');
        $this->central = fopen('php://temp/maxmemory:1048576', 'w+b');
    }

    /**
     * @param string $rawName 이름 바이트(그대로 기록)
     * @param int $unixMode 0이면 일반 파일(0100644), 디렉토리면 040755, 심볼릭 링크면 0120777
     * @param int|null $declaredSize 중앙·로컬 헤더에 적을 크기(위조 시험). null이면 실제 크기
     */
    public function add(string $rawName, string $data = '', bool $utf8Flag = false, int $unixMode = 0, ?int $declaredSize = null): self
    {
        $isDir = str_ends_with($rawName, '/');
        $mode = $unixMode !== 0 ? $unixMode : ($isDir ? 0040755 : 0100644);
        $crc = crc32($data);
        $size = $declaredSize ?? strlen($data);
        $flags = $utf8Flag ? 0x0800 : 0;
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, 0, 0, 0x21, $crc, $size, $size, strlen($rawName), 0) . $rawName;
        fwrite($this->out, $local);
        fwrite($this->out, $data);
        $ext = ($mode & 0xFFFF) << 16;
        $cd = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, $flags, 0, 0, 0x21, $crc, $size, $size, strlen($rawName), 0, 0, 0, 0, $ext, $this->offset) . $rawName;
        fwrite($this->central, $cd);
        $this->centralSize += strlen($cd);
        $this->offset += strlen($local) + strlen($data);
        $this->count++;
        return $this;
    }

    public function close(): string
    {
        rewind($this->central);
        stream_copy_to_stream($this->central, $this->out);
        fclose($this->central);
        $n = min($this->count, 0xFFFF);
        if ($this->count > 0xFFFF || $this->offset > 0xFFFFFFFF) {
            // ZIP64: 항목 수가 65,535를 넘으면 ZIP64 끝 레코드와 위치 레코드를 쓴다
            $zip64At = $this->offset + $this->centralSize;
            fwrite($this->out, pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $this->count, $this->count, $this->centralSize, $this->offset));
            fwrite($this->out, pack('VVPV', 0x07064b50, 0, $zip64At, 1));
            fwrite($this->out, pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
        } else {
            fwrite($this->out, pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $this->centralSize, $this->offset, 0));
        }
        fclose($this->out);
        return $this->path;
    }
}
