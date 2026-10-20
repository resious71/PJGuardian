<?php

declare(strict_types=1);

namespace PJGuardian\Services\Archive;

use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Fs;

/**
 * 안전한 zip 해제(명세 5.1, 5.4, 10, 15.2, 19.10). 프로토타입(tools/proto/safe_unzip.php)의 설계를 제품 코드로 다시 쓴 것.
 *
 * 순서(아무것도 쓰기 전에 모든 항목을 검사한다):
 *  0. zip 파일 크기(zip_too_large) → ZipArchive를 RDONLY|CHECKCONS로 연다(invalid_zip)
 *  1. 1차 훑기: 이름 해석(UTF-8 플래그 → UTF-8, 없으면 CP949, CP949 왕복 실패 + UTF-8 유효 → UTF-8, 둘 다 실패 → filename_encoding,
 *     NFC 정규화) → 경로 검사(경로 조작·절대 경로·드라이브 문자·역슬래시·제어 문자 → forbidden_entry, **제외 대상 안이어도**)
 *     → 서버 기본 제외 목록 판정 → 남은 항목으로 최상위 단일 폴더 감지
 *  2. 최상위 폴더 제거 여부 결정(프로젝트 규칙, 명세 19.10). **이 단계에서 한 번만** 제거한다
 *  3. 2차 훑기: 제거 뒤 경로로 전체 제외 판정(기본 + 프로젝트 추가 패턴). 남은 항목만 심볼릭 링크·중첩 zip(.zip 확장자)
 *     → forbidden_entry, Windows 이름 규칙 → unsupported_name, 이름 충돌(대소문자·NFC·중복·파일과 폴더) → name_conflict,
 *     최종 경로 259자 초과 → unsupported_name. 선언 크기 합계·파일 수 사전 검사 → extract_limit(precheck)
 *  4. 해제: getStreamIndex()로 읽어 fopen('xb')로 직접 쓴다(extractTo() 쓰지 않음, 외부 명령 없음).
 *     64KB마다 **실제로 쓴 바이트**와 파일 수로 한도 검사(선언 크기는 위조될 수 있다) → extract_limit(extract).
 *     항목의 실제 크기 ≠ 선언 크기 → invalid_zip
 *  5. 실패하면 이유와 관계없이 작업 디렉토리를 비운다(만든 디렉토리는 지운다).
 *
 * 메모리(명세 19.10): 항목마다 이름 문자열을 들고 있지 않는다. 남길 항목은 번호·크기만 정수 배열에 두고 해제할 때 이름을 다시 해석한다.
 * 충돌 검사 키는 경로의 해시(16바이트)로 저장한다. 실측은 docs/results/P13.md.
 */
final class SafeUnzip
{
    public const MAX_PATH = 259;
    private const READ_BYTES = 65536;
    private const RESERVED = '/^(CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³])(\..*)?$/iu';

    public function __construct(
        private readonly UnzipLimits $limits,
        private readonly ExcludeMatcher $matcher,
    ) {
    }

    /**
     * @param ?bool $stripTop 프로젝트의 최상위 폴더 제거 규칙. null = 첫 업로드(감지 결과로 정해 strip_rule로 돌려줌)
     * @return array{dest:string, files:int, dirs:int, bytes:int, skipped:int, top_folder:string, stripped_prefix:string,
     *               strip_rule:bool, warnings:list<string>}
     * @throws UnzipException
     */
    public function extract(string $zipPath, string $dest, ?bool $stripTop): array
    {
        $zip = new \ZipArchive();
        $opened = false;
        $created = false;
        $ok = false;
        $dest = rtrim(str_replace('\\', '/', $dest), '/');
        try {
            $size = is_file($zipPath) ? @filesize($zipPath) : false;
            if ($size === false) {
                throw new UnzipException('invalid_zip', [], ['reason' => 'unreadable']);
            }
            if ($size > $this->limits->maxZipBytes) {
                throw new UnzipException('zip_too_large', ['max' => UnzipException::humanBytes($this->limits->maxZipBytes)], ['size' => $size]);
            }
            $rc = $zip->open($zipPath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);
            if ($rc !== true) {
                throw new UnzipException('invalid_zip', [], ['reason' => 'open_failed', 'libzip_error' => $rc]);
            }
            $opened = true;
            $n = $zip->count();

            // ---- 1차: 이름·경로 검사, 기본 제외, 최상위 폴더 감지 ----
            $top = null;          // 남은 항목들의 공통 첫 조각
            $single = true;       // 모두 같은 첫 조각 아래 있는가
            $nested = false;      // 첫 조각 아래 실제 항목이 있는가
            for ($i = 0; $i < $n; $i++) {
                [$name, $segs] = $this->entryName($zip, $i);
                if ($this->defaultExcluded($segs)) {
                    continue;
                }
                $isDir = str_ends_with($name, '/');
                if (count($segs) === 1 && !$isDir) {
                    $single = false; // 루트에 파일
                } elseif ($single) {
                    if ($top !== null && $segs[0] !== $top) {
                        $single = false;
                    }
                    $top ??= $segs[0];
                    $nested = $nested || count($segs) > 1;
                }
            }
            $detected = $single && $top !== null && $nested ? $top : '';
            $warnings = [];
            $strip = $stripTop ?? ($detected !== '');
            if ($stripTop === true && $detected === '') {
                $warnings[] = 'top_folder_missing';
            }
            if ($stripTop === false && $detected !== '') {
                $warnings[] = 'top_folder_kept';
            }
            $prefix = $strip && $detected !== '' ? $detected : '';

            // ---- 2차: 제거 뒤 경로로 제외 판정, 남은 항목의 위험 검사·충돌·길이·선언 크기 ----
            $keepIndex = [];
            $keepSize = [];
            $seen = [];          // md5(소문자 경로) => md5(정확한 경로) . 파일 여부('f'|'d')
            $skipped = 0;
            $declared = 0;
            $fileCount = 0;
            $destLen = mb_strlen($dest, 'UTF-8');
            for ($i = 0; $i < $n; $i++) {
                [$name, $segs] = $this->entryName($zip, $i);
                $isDir = str_ends_with($name, '/');
                $rel = $this->relative($segs, $prefix);
                if ($rel === null) {
                    continue; // 제거한 최상위 폴더 자체의 폴더 항목
                }
                if ($this->defaultExcluded($segs) || $this->matcher->matches($isDir ? $rel . '/x' : $rel)) {
                    $skipped++;
                    continue;
                }
                if (!$isDir && self::isSymlinkEntry($zip, $i)) {
                    throw new UnzipException('forbidden_entry', [], ['entry' => $name, 'reason' => 'symlink']);
                }
                if (!$isDir && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip') {
                    throw new UnzipException('forbidden_entry', [], ['entry' => $name, 'reason' => 'nested_zip']);
                }
                $relSegs = explode('/', $rel);
                foreach ($relSegs as $seg) {
                    $why = self::windowsNameProblem($seg);
                    if ($why !== null) {
                        throw new UnzipException('unsupported_name', [], ['entry' => $name, 'reason' => $why]);
                    }
                }
                $len = $destLen + 1 + mb_strlen($rel, 'UTF-8');
                if ($len > self::MAX_PATH) {
                    throw new UnzipException('unsupported_name', [], ['entry' => $name, 'reason' => 'path_too_long', 'length' => $len, 'max' => self::MAX_PATH]);
                }
                $this->checkConflict($seen, $relSegs, $isDir, $name);
                $stat = $zip->statIndex($i, \ZipArchive::FL_ENC_RAW);
                if ($stat === false) {
                    throw new UnzipException('invalid_zip', [], ['entry' => $name, 'reason' => 'stat_failed']);
                }
                // P21C: 암호화된 항목은 읽을 수 없다 — 손상과 구분해 알린다(전에는 읽기 단계에서 invalid_zip/read_failed)
                if (!$isDir && (int) ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new UnzipException('invalid_zip', [], ['entry' => $name, 'reason' => 'encrypted']);
                }
                $keepIndex[] = $i;
                $keepSize[] = $isDir ? -1 : (int) $stat['size'];
                if (!$isDir) {
                    $fileCount++;
                    $declared += (int) $stat['size'];
                }
            }
            unset($seen);
            if ($fileCount > $this->limits->maxFiles || $declared > $this->limits->maxTotalBytes) {
                throw $this->limitError(['stage' => 'precheck', 'declared_bytes' => $declared, 'files' => $fileCount]);
            }

            // ---- 해제 ----
            if (is_dir($dest)) {
                if (Fs::listDir($dest) !== []) {
                    throw new UnzipException('io_error', [], ['reason' => 'dest_not_empty']);
                }
            } elseif (!@mkdir($dest, 0700, true)) {
                throw new UnzipException('io_error', [], ['reason' => 'mkdir_failed']);
            }
            $created = true;
            $destReal = rtrim(str_replace('\\', '/', (string) realpath($dest)), '/');
            $written = 0;
            $files = 0;
            $dirs = 0;
            foreach ($keepIndex as $k => $i) {
                [$name, $segs] = $this->entryName($zip, $i);
                $rel = (string) $this->relative($segs, $prefix);
                $target = $destReal . '/' . $rel;
                // 이중 방어: 대상이 작업 디렉토리 아래인지 문자열로 다시 확인
                if (!str_starts_with($target, $destReal . '/') || str_contains('/' . $rel . '/', '/../')) {
                    throw new UnzipException('forbidden_entry', [], ['entry' => $name, 'reason' => 'outside_dest']);
                }
                if ($keepSize[$k] === -1) {
                    if (!is_dir($target) && !@mkdir($target, 0700, true)) {
                        throw new UnzipException('io_error', [], ['entry' => $name, 'reason' => 'mkdir_failed']);
                    }
                    $dirs++;
                    continue;
                }
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
                    throw new UnzipException('io_error', [], ['entry' => $name, 'reason' => 'mkdir_failed']);
                }
                if (++$files > $this->limits->maxFiles) {
                    throw $this->limitError(['stage' => 'extract', 'files' => $files]);
                }
                $written += $this->writeEntry($zip, $i, $target, $name, $keepSize[$k], $written);
            }
            $ok = true;
            return [
                'dest' => $destReal, 'files' => $files, 'dirs' => $dirs, 'bytes' => $written, 'skipped' => $skipped,
                'top_folder' => $detected === '' ? '' : $detected . '/', 'stripped_prefix' => $prefix === '' ? '' : $prefix . '/',
                'strip_rule' => $strip, 'warnings' => $warnings,
            ];
        } catch (\ValueError $e) {
            throw new UnzipException('invalid_zip', [], ['reason' => 'libzip']);
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (!$ok && $created) {
                Fs::removeTree($dest);
            }
        }
    }

    /** 항목 하나를 쓴다. 쓴 바이트 수를 돌려준다(누적 한도는 $writtenBefore + 이번 항목으로 검사) */
    private function writeEntry(\ZipArchive $zip, int $i, string $target, string $name, int $declared, int $writtenBefore): int
    {
        $in = $zip->getStreamIndex($i);
        if ($in === false) {
            throw new UnzipException('invalid_zip', [], ['entry' => $name, 'reason' => 'stream_failed']);
        }
        // 'x': 이미 있으면 실패(충돌 검사를 빠져나간 덮어쓰기 방지)
        $out = @fopen($target, 'xb');
        if ($out === false) {
            fclose($in);
            throw new UnzipException('io_error', [], ['entry' => $name, 'reason' => 'open_failed']);
        }
        $bytes = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::READ_BYTES);
                if ($chunk === false) {
                    throw new UnzipException('invalid_zip', [], ['entry' => $name, 'reason' => 'read_failed']);
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($writtenBefore + $bytes > $this->limits->maxTotalBytes) {
                    throw $this->limitError(['stage' => 'extract', 'entry' => $name, 'written_bytes' => $writtenBefore + $bytes, 'declared_entry_bytes' => $declared]);
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new UnzipException('io_error', [], ['entry' => $name, 'reason' => 'write_failed']);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($bytes !== $declared) {
            throw new UnzipException('invalid_zip', [], ['entry' => $name, 'reason' => 'size_mismatch', 'declared' => $declared, 'actual' => $bytes]);
        }
        return $bytes;
    }

    private function limitError(array $detail): UnzipException
    {
        return new UnzipException('extract_limit', [
            'size' => UnzipException::humanBytes($this->limits->maxTotalBytes),
            'files' => number_format($this->limits->maxFiles),
        ], $detail);
    }

    /** @return array{0:string, 1:list<string>} 해석한 이름과 경로 조각(경로 검사 통과) */
    private function entryName(\ZipArchive $zip, int $i): array
    {
        $raw = $zip->getNameIndex($i, \ZipArchive::FL_ENC_RAW);
        $strict = $zip->getNameIndex($i, \ZipArchive::FL_ENC_STRICT);
        if ($raw === false || $strict === false) {
            throw new UnzipException('invalid_zip', [], ['index' => $i, 'reason' => 'name_unreadable']);
        }
        // FL_ENC_STRICT는 UTF-8 플래그가 있을 때만 원래 바이트를 돌려준다(없으면 CP437로 바꿔 돌려줌)
        $name = self::decodeName($raw, $raw === $strict);
        return [$name, self::checkPath($name)];
    }

    /**
     * 이름 바이트를 UTF-8(NFC)로(명세 5.1, 19.10).
     * ASCII → 그대로, 플래그 있음 → UTF-8(무효면 오류), 플래그 없음 → CP949(왕복이 원래 바이트와 같을 때),
     * CP949 왕복 실패 + UTF-8로 유효 → UTF-8, 둘 다 실패 → filename_encoding.
     */
    public static function decodeName(string $raw, bool $utf8Flag): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $raw) === 1) {
            return $raw;
        }
        $fail = static fn () => new UnzipException('filename_encoding', [], ['raw' => bin2hex(substr($raw, 0, 120))]);
        if ($utf8Flag) {
            if (!mb_check_encoding($raw, 'UTF-8')) {
                throw $fail();
            }
            $name = $raw;
        } else {
            $cp = @mb_convert_encoding($raw, 'UTF-8', 'CP949');
            $back = is_string($cp) ? @mb_convert_encoding($cp, 'CP949', 'UTF-8') : false;
            if (is_string($cp) && $back === $raw && mb_check_encoding($cp, 'UTF-8') && !str_contains($cp, "\u{FFFD}")) {
                $name = $cp;
            } elseif (mb_check_encoding($raw, 'UTF-8')) {
                $name = $raw;
            } else {
                throw $fail();
            }
        }
        $nfc = \Normalizer::normalize($name, \Normalizer::FORM_C);
        return $nfc === false ? $name : $nfc;
    }

    /**
     * 경로 보안 검사(제외 대상이어도 항상). 통과하면 '.'과 빈 조각을 뺀 경로 조각.
     *
     * @return list<string>
     */
    public static function checkPath(string $name): array
    {
        $fail = static fn (string $why) => new UnzipException('forbidden_entry', [], ['entry' => $name, 'reason' => $why]);
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw $fail('control_char');
        }
        if (str_contains($name, '\\')) {
            throw $fail('backslash');
        }
        if (str_starts_with($name, '/')) {
            throw $fail('absolute');
        }
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw $fail('drive_letter');
        }
        $segs = [];
        foreach (explode('/', $name) as $seg) {
            if ($seg === '..') {
                throw $fail('parent_dir');
            }
            if ($seg !== '' && $seg !== '.') {
                $segs[] = $seg;
            }
        }
        if ($segs === []) {
            throw $fail('empty_name');
        }
        return $segs;
    }

    /** Windows 이름 규칙 위반 사유(두 OS 공통 적용). 없으면 null */
    public static function windowsNameProblem(string $seg): ?string
    {
        return match (true) {
            preg_match(self::RESERVED, $seg) === 1 => 'reserved',
            preg_match('/[<>:"|?*]/', $seg) === 1 => 'forbidden_char',
            preg_match('/[ .]$/', $seg) === 1 => 'trailing_dot_space',
            default => null,
        };
    }

    /** @param list<string> $segs */
    private function defaultExcluded(array $segs): bool
    {
        $defaults = $this->matcher->defaults();
        foreach ($segs as $seg) {
            if (in_array($seg, $defaults, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 최상위 폴더 제거 뒤 상대 경로. 제거한 폴더 자체면 null.
     *
     * @param list<string> $segs
     */
    private function relative(array $segs, string $prefix): ?string
    {
        if ($prefix === '') {
            return implode('/', $segs);
        }
        if ($segs[0] !== $prefix) {
            return implode('/', $segs); // 제외 대상(1차에서 감지에 쓰지 않은 항목)
        }
        return count($segs) === 1 ? null : implode('/', array_slice($segs, 1));
    }

    /**
     * 이름 충돌: 이 경로와 모든 상위 경로를 대소문자 무시 키로 비교(이름은 이미 NFC). 키와 값은 해시로 저장한다.
     *
     * @param array<string, string> $seen
     * @param list<string> $segs
     */
    private function checkConflict(array &$seen, array $segs, bool $isDir, string $name): void
    {
        $count = count($segs);
        $path = '';
        for ($k = 0; $k < $count; $k++) {
            $path = $k === 0 ? $segs[0] : $path . '/' . $segs[$k];
            $leafFile = $k === $count - 1 && !$isDir;
            $key = md5(mb_strtolower($path, 'UTF-8'), true);
            $val = md5($path, true) . ($leafFile ? 'f' : 'd');
            if (!isset($seen[$key])) {
                $seen[$key] = $val;
                continue;
            }
            $prev = $seen[$key];
            // 다른 표기(대소문자·정규화), 같은 파일 두 번, 파일과 폴더가 같은 이름
            if (substr($prev, 0, 16) !== substr($val, 0, 16) || ($leafFile && $prev[16] === 'f') || ($leafFile xor $prev[16] === 'f')) {
                throw new UnzipException('name_conflict', [], ['entry' => $name, 'reason' => 'conflict']);
            }
        }
    }

    /** 심볼릭 링크 항목인지(Unix 모드 120000, Windows reparse point). 저장소 가져오기(P26B)도 같은 판정으로 링크를 걸러 낸다 */
    public static function isSymlinkEntry(\ZipArchive $zip, int $i): bool
    {
        if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
            return false;
        }
        $mode = ($attr >> 16) & 0xFFFF;
        if ($opsys === \ZipArchive::OPSYS_UNIX && ($mode & 0xF000) === 0xA000) {
            return true;
        }
        // DOS/NTFS 속성 FILE_ATTRIBUTE_REPARSE_POINT(0x400): 심볼릭 링크·정션
        return ($attr & 0x400) !== 0 && in_array($opsys, [\ZipArchive::OPSYS_DOS, \ZipArchive::OPSYS_WINDOWS_NTFS], true);
    }
}
