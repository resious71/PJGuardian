<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

/**
 * 백업 묶음 읽기·검증(P27B). 백업 직후 검증, "다시 검증", 복원 사전 검증, 리허설이 모두 이것을 쓴다.
 *
 * 검사(하나라도 틀리면 BackupException, 아무것도 풀지 않음): zip 열기, 항목 이름 허용 목록(BundleFormat::allowedEntry — 그 밖의 항목,
 * "..", 절대 경로, 드라이브, 역슬래시, 제어 문자 거부), 같은 이름 중복, 심볼릭 링크·장치 파일(외부 속성), 항목 수 상한, 압축 비율(zip 폭탄),
 * manifest 형식, manifest의 항목 목록 = zip 항목(manifest 제외), 항목마다 크기·SHA-256(스트림으로 실제 읽은 바이트).
 * 풀기는 항목 하나씩 getStream으로 읽어 직접 쓰고 **실제로 쓴 바이트**로 크기를 검사한다(P05·P13 SafeUnzip 규칙과 같다 — extractTo 쓰지 않음).
 */
final class BundleReader
{
    private \ZipArchive $zip;
    /** @var array<string, mixed> */
    private array $manifest = [];
    /** @var array<string, array{index: int, size: int, csize: int}> */
    private array $entries = [];

    public function __construct(private readonly string $file)
    {
    }

    /**
     * 열고 구조·manifest를 검사한다(해시는 verifyHashes).
     *
     * @return array<string, mixed> manifest
     */
    public function open(): array
    {
        if (!is_file($this->file)) {
            throw new BackupException('bundle_not_found');
        }
        $zip = new \ZipArchive();
        if ($zip->open($this->file, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            // 열지 못한 ZipArchive는 close()가 ValueError — 열린 것만 붙잡는다
            throw new BackupException('bundle_corrupt', [], 'zip 열기 실패');
        }
        $this->zip = $zip;
        $n = $this->zip->numFiles;
        if ($n < 2 || $n > BundleFormat::MAX_ENTRIES) {
            throw new BackupException('bundle_corrupt', [], '항목 수 ' . $n);
        }
        for ($i = 0; $i < $n; $i++) {
            $st = $this->zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
            if ($st === false) {
                throw new BackupException('bundle_corrupt', [], 'stat 실패');
            }
            $name = (string) $st['name'];
            if ($name === '' || str_contains($name, '\\') || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1
                || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || !BundleFormat::allowedEntry($name)) {
                throw new BackupException('bundle_entry_not_allowed', [], '허용되지 않은 항목');
            }
            if (isset($this->entries[$name])) {
                throw new BackupException('bundle_entry_not_allowed', [], '중복 이름');
            }
            // 외부 속성: Unix 모드(위 16비트)가 일반 파일이 아니면(심볼릭 링크·장치) 거부
            if ($this->zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
                $mode = ($attr >> 16) & 0xF000;
                if ($mode !== 0 && $mode !== 0x8000) {
                    throw new BackupException('bundle_entry_not_allowed', [], '일반 파일이 아님');
                }
            }
            $size = (int) $st['size'];
            $csize = max(1, (int) $st['comp_size']);
            if ($size > 1024 * 1024 && $size / $csize > BundleFormat::MAX_RATIO) {
                throw new BackupException('bundle_entry_not_allowed', [], '압축 비율 초과');
            }
            $this->entries[$name] = ['index' => $i, 'size' => $size, 'csize' => $csize];
        }
        foreach ([BundleFormat::MANIFEST, BundleFormat::DB] as $required) {
            if (!isset($this->entries[$required])) {
                throw new BackupException('bundle_corrupt', [], '필수 항목 없음');
            }
        }
        if ($this->entries[BundleFormat::MANIFEST]['size'] > 8 * 1024 * 1024) {
            throw new BackupException('bundle_corrupt', [], 'manifest가 너무 큼');
        }
        $m = json_decode((string) $this->zip->getFromName(BundleFormat::MANIFEST), true);
        if (!is_array($m) || ($m['format'] ?? null) !== BundleFormat::FORMAT || !is_int($m['format_version'] ?? null) || !is_array($m['entries'] ?? null)
            || !is_string($m['schema_version'] ?? null)) {
            throw new BackupException('manifest_invalid');
        }
        if ($m['format_version'] > BundleFormat::VERSION) {
            throw new BackupException('bundle_format_newer');
        }
        $listed = array_keys($m['entries']);
        $actual = array_values(array_diff(array_keys($this->entries), [BundleFormat::MANIFEST]));
        sort($listed);
        sort($actual);
        if ($listed !== $actual) {
            throw new BackupException('manifest_mismatch', [], 'manifest 항목 ≠ zip 항목');
        }
        $this->manifest = $m;
        return $m;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /** 압축을 풀었을 때 총 크기(디스크 여유 판단) */
    public function totalSize(): int
    {
        return array_sum(array_column($this->entries, 'size'));
    }

    /** 항목마다 실제로 읽은 바이트의 크기·SHA-256 = manifest */
    public function verifyHashes(?\Closure $progress = null): void
    {
        $done = 0;
        foreach ($this->manifest['entries'] as $name => $meta) {
            $h = hash_init('sha256');
            $n = $this->stream((string) $name, static function (string $buf) use ($h): void { hash_update($h, $buf); });
            if ($n !== (int) ($meta['size'] ?? -1) || !hash_equals((string) ($meta['sha256'] ?? ''), hash_final($h))) {
                throw new BackupException('bundle_hash_mismatch', ['entry' => (string) $name]);
            }
            $done += $n;
            if ($progress !== null) {
                $progress($done);
            }
        }
    }

    /** 항목 하나를 파일로(실제 쓴 바이트가 선언 크기를 넘으면 중단) */
    public function extract(string $name, string $dest): int
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new BackupException('restore_io_failed', [], '폴더 생성 실패');
        }
        $out = @fopen($dest, 'xb');
        if ($out === false) {
            throw new BackupException('restore_io_failed', [], '파일 생성 실패');
        }
        try {
            $n = $this->stream($name, static function (string $buf) use ($out): void {
                if (fwrite($out, $buf) !== strlen($buf)) {
                    throw new BackupException('restore_io_failed', [], '쓰기 실패');
                }
            });
        } finally {
            fclose($out);
        }
        return $n;
    }

    public function close(): void
    {
        if (isset($this->zip)) {
            @$this->zip->close();
            unset($this->zip);
        }
    }

    /** @param \Closure(string): void $sink */
    private function stream(string $name, \Closure $sink): int
    {
        $e = $this->entries[$name] ?? throw new BackupException('bundle_corrupt', [], '항목 없음');
        $in = $this->zip->getStreamIndex($e['index']);
        if ($in === false) {
            throw new BackupException('bundle_corrupt', [], '항목 읽기 실패');
        }
        $n = 0;
        try {
            while (!feof($in)) {
                $buf = fread($in, 1024 * 1024);
                if ($buf === false) {
                    throw new BackupException('bundle_corrupt', [], '읽기 실패');
                }
                if ($buf === '') {
                    break;
                }
                $n += strlen($buf);
                if ($n > $e['size']) {
                    throw new BackupException('bundle_entry_not_allowed', [], '선언 크기 초과');
                }
                $sink($buf);
            }
        } finally {
            fclose($in);
        }
        return $n;
    }
}
