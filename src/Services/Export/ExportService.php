<?php

declare(strict_types=1);

namespace PJGuardian\Services\Export;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\Fs;
use PJGuardian\Support\FileLock;
use PJGuardian\Support\Logger;

/**
 * 내보내기 공통(P23, 한 곳): 동시 실행 자리, 행 상한, 시간·메모리 제한, 임시 파일, 형식 작성기.
 *
 * - 자리: 서버 전체 max_concurrent개(잠금 파일 slot-N), 사용자당 max_per_user개(user-<id>-N). 못 잡으면 429 export_busy.
 *   잠금 파일만 잠근다(Support\FileLock — Windows flock은 강제 잠금). 경로에는 숫자(사용자 id·자리 번호)만 들어간다.
 * - 상한: 건수를 먼저 세어 max_rows를 넘으면 파일을 만들지 않고 413 export_too_large(details count·limit). 0건이면 409 export_empty
 *   (204는 본문이 없어 화면이 이유를 보여 줄 수 없으므로 오류 응답으로 알린다).
 * - 행은 생성기로 받아 작성기에 흘려 보낸다(전체 행을 배열에 올리지 않음). 500행마다 시간(timeout_seconds)·메모리(memory_limit)를 확인해
 *   넘으면 쓰던 임시 파일을 지우고 503 export_timeout / export_failed.
 * - 임시 파일: <work_dir>/export/<무작위 이름>. 응답을 보낸 뒤 지운다(Response::file). 한 시간 넘게 남은 파일은 다음 내보내기 때 지운다.
 */
final class ExportService
{
    public const FORMATS = ['xlsx', 'csv'];
    private const CHECK_EVERY = 500;
    private const STALE_SECONDS = 3600;

    public function __construct(
        private readonly string $dir,
        private readonly int $maxRows,
        private readonly int $maxConcurrent,
        private readonly int $maxPerUser,
        private readonly float $timeoutSeconds,
        private readonly int $memoryLimit,
        private readonly \DateTimeZone $tz,
        private readonly Logger $logger,
    ) {
    }

    public function maxRows(): int
    {
        return $this->maxRows;
    }

    public function timezone(): \DateTimeZone
    {
        return $this->tz;
    }

    public static function assertFormat(mixed $format): string
    {
        if (!is_string($format) || !in_array($format, self::FORMATS, true)) {
            throw new ApiException(400, 'invalid_export_format');
        }
        return $format;
    }

    /**
     * 동시 실행 자리(서버 + 사용자). 돌려준 잠금들을 놓으면(release 또는 소멸) 자리가 빈다.
     *
     * @return list<FileLock>
     */
    public function acquire(int $userId): array
    {
        Fs::ensureDir($this->dir . '/locks');
        $user = null;
        for ($i = 1; $i <= $this->maxPerUser && $user === null; $i++) {
            $user = FileLock::tryAcquire($this->dir . '/locks/user-' . $userId . '-' . $i . '.lock');
        }
        if ($user === null) {
            throw new ApiException(429, 'export_busy', [], null, [], [], ['scope' => 'user']);
        }
        for ($i = 1; $i <= $this->maxConcurrent; $i++) {
            $slot = FileLock::tryAcquire($this->dir . '/locks/slot-' . $i . '.lock');
            if ($slot !== null) {
                return [$user, $slot];
            }
        }
        $user->release();
        throw new ApiException(429, 'export_busy', [], null, [], [], ['scope' => 'server']);
    }

    /** 건수 확인: 0 → 409, 상한 초과 → 413(파일 없음) */
    public function assertCount(int $count): void
    {
        if ($count === 0) {
            throw new ApiException(409, 'export_empty');
        }
        if ($count > $this->maxRows) {
            throw new ApiException(413, 'export_too_large', ['count' => number_format($count), 'limit' => number_format($this->maxRows)], null, [], [],
                ['count' => $count, 'limit' => $this->maxRows]);
        }
    }

    /**
     * 파일 만들기. $rows는 열 순서대로의 값 목록을 내는 생성기.
     *
     * @param list<array{0:string, 1:string, 2:int}> $columns
     * @param iterable<list<mixed>> $rows
     * @param list<array{0:string, 1:string}> $info 조건 시트
     * @return array{path:string, rows:int, content_type:string, extension:string}
     */
    public function write(string $format, string $sheetName, string $infoName, array $columns, iterable $rows, array $info): array
    {
        Fs::ensureDir($this->dir);
        $this->sweep();
        $path = $this->dir . '/' . bin2hex(random_bytes(12)) . '.' . $format;
        $writer = $format === 'xlsx' ? new XlsxWriter($path, $this->tz, $sheetName, $infoName) : new CsvWriter($path, $this->tz);
        $start = hrtime(true);
        $n = 0;
        try {
            $writer->begin($columns);
            foreach ($rows as $row) {
                $writer->row($row);
                if (++$n % self::CHECK_EVERY === 0) {
                    $this->checkLimits($start, $n);
                }
            }
            $this->checkLimits($start, $n);
            $file = $writer->finish($info);
        } catch (\Throwable $e) {
            if ($writer instanceof XlsxWriter) {
                $writer->abort();
            } else {
                try {
                    $writer->finish([]);
                } catch (\Throwable) {
                    // 이미 닫힘
                }
            }
            @unlink($path);
            throw $e;
        }
        // 측정·운영용 기록(데이터 값 없음): 행 수, 걸린 시간, PHP 최대 메모리, 파일 크기
        $this->logger->info('내보내기 파일 생성', ['format' => $format, 'rows' => $n, 'seconds' => round((hrtime(true) - $start) / 1e9, 3),
            'peak_memory' => memory_get_peak_usage(true), 'bytes' => (int) @filesize($file)]);
        return ['path' => $file, 'rows' => $n, 'content_type' => $writer->contentType(), 'extension' => $writer->extension()];
    }

    private function checkLimits(int $start, int $rows): void
    {
        $elapsed = (hrtime(true) - $start) / 1e9;
        if ($elapsed > $this->timeoutSeconds) {
            $this->logger->warn('내보내기 시간 초과로 중단', ['rows' => $rows, 'seconds' => round($elapsed, 1)]);
            throw new ApiException(503, 'export_timeout', ['seconds' => (int) $this->timeoutSeconds]);
        }
        if (memory_get_usage(true) > $this->memoryLimit) {
            $this->logger->warn('내보내기 메모리 상한으로 중단', ['rows' => $rows, 'memory' => memory_get_usage(true)]);
            throw new ApiException(503, 'export_failed');
        }
    }

    /** 오래 남은 임시 파일(비정상 종료) 정리 */
    private function sweep(): void
    {
        foreach (Fs::listDir($this->dir) as $name) {
            $p = $this->dir . '/' . $name;
            if (is_file($p) && preg_match('/^[0-9a-f]{24}\.(xlsx|csv)(\.sheet1\.xml)?$/', $name) === 1 && (time() - (int) @filemtime($p)) > self::STALE_SECONDS) {
                @unlink($p);
            }
        }
    }

    /**
     * 내려받을 파일 이름의 한 조각(프로젝트 이름 등): 글자·숫자·_·-만, 40자. 비면 $fallback.
     */
    public static function namePart(string $s, string $fallback): string
    {
        $s = trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $s), '_');
        $s = mb_substr($s, 0, 40);
        return $s === '' ? $fallback : $s;
    }

    /** Content-Disposition: ASCII 대체 이름 + RFC 5987 UTF-8 이름 */
    public static function disposition(string $filename): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }
}
