<?php

declare(strict_types=1);

namespace PJGuardian\Services\Admin;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 디스크 사용 현황(P20A, 명세 14, 19.3). 구분별 합계는 폴더를 모두 훑어야 해서(해제한 파일 100만 개일 수 있음) 웹 요청에서 하지 않는다:
 * 관리 작업 disk_scan(워커 자식)이 scan()으로 계산해 <work_dir>/admin/disk_usage.json에 저장하고, 화면은 그 결과와 "계산 시각"을 보여 준다.
 * 전체 여유·사용률(disk_free_space)은 가벼워 화면이 그때그때 읽는다.
 *
 * 구분: upload_chunks(업로드 조각), upload_zips(조립 zip), work_dirs(분석·해제 작업 폴더), results(분석 결과 파일),
 *       snapshots, trash(작업 폴더 휴지통), tool_staging(도구 패키지 스테이징), logs, backups, db(DB + WAL), other_work(그 밖의 작업 폴더 파일).
 */
final class DiskUsage
{
    public const CATEGORIES = ['upload_chunks', 'upload_zips', 'work_dirs', 'results', 'snapshots', 'trash', 'tool_staging', 'logs', 'backups', 'db', 'other_work'];

    /** @param array{uploads:string, work:string, snapshots:string, logs:string, backups:string, db:string} $paths */
    public function __construct(private readonly array $paths, private readonly Clock $clock)
    {
    }

    public function cacheFile(): string
    {
        return rtrim($this->paths['work'], '/') . '/admin/disk_usage.json';
    }

    /** @return array<string, mixed>|null 저장된 마지막 계산 결과 */
    public function cached(): ?array
    {
        $raw = @file_get_contents($this->cacheFile());
        $v = $raw === false ? null : json_decode($raw, true);
        return is_array($v) ? $v : null;
    }

    /**
     * 계산하고 저장한다(워커 자식에서).
     *
     * @param \Closure(): array<string, int>|null $preview 정리 대기 미리보기(CleanupService::runAll(dryRun)). 함께 저장한다
     * @return array<string, mixed>
     */
    public function scan(?\Closure $preview = null): array
    {
        $t0 = microtime(true);
        $cat = array_fill_keys(self::CATEGORIES, ['bytes' => 0, 'files' => 0]);
        $add = static function (string $key, array $sum) use (&$cat): void {
            $cat[$key]['bytes'] += $sum['bytes'];
            $cat[$key]['files'] += $sum['files'];
        };
        // 업로드: <id>/<n>.part·<n>.sha256 = 조각, upload.zip = 조립본
        foreach (Fs::listDir($this->paths['uploads']) as $id) {
            $dir = $this->paths['uploads'] . '/' . $id;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (Fs::listDir($dir) as $f) {
                $size = (int) @filesize($dir . '/' . $f);
                $add($f === 'upload.zip' ? 'upload_zips' : 'upload_chunks', ['bytes' => $size, 'files' => 1]);
            }
        }
        // 작업 폴더: <8자리> = 작업 디렉토리, results, trash, tool_staging, 나머지
        $work = rtrim($this->paths['work'], '/');
        foreach (Fs::listDir($work) as $name) {
            $key = match (true) {
                preg_match('/^[0-9a-f]{8}$/', $name) === 1 => 'work_dirs',
                $name === 'results' => 'results',
                $name === 'trash' => 'trash',
                $name === 'tool_staging' => 'tool_staging',
                default => 'other_work',
            };
            $add($key, self::sum($work . '/' . $name));
        }
        $add('snapshots', self::sum($this->paths['snapshots']));
        $add('logs', self::sum($this->paths['logs']));
        $add('backups', self::sum($this->paths['backups']));
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->paths['db'] . $suffix)) {
                $add('db', ['bytes' => (int) filesize($this->paths['db'] . $suffix), 'files' => 1]);
            }
        }
        $data = ['scanned_at' => SystemClock::iso($this->clock->now()), 'duration_ms' => (int) round((microtime(true) - $t0) * 1000), 'categories' => $cat];
        if ($preview !== null) {
            $data['cleanup_preview'] = $preview();
        }
        Fs::ensureDir(dirname($this->cacheFile()));
        Fs::atomicWrite($this->cacheFile(), (string) json_encode($data));
        return $data;
    }

    /** @return array{bytes:int, files:int} 폴더(또는 파일) 합계. 심볼릭 링크는 따라가지 않는다 */
    public static function sum(string $path): array
    {
        if (is_link($path)) {
            return ['bytes' => 0, 'files' => 0];
        }
        if (is_file($path)) {
            return ['bytes' => (int) @filesize($path), 'files' => 1];
        }
        if (!is_dir($path)) {
            return ['bytes' => 0, 'files' => 0];
        }
        $bytes = 0;
        $files = 0;
        $stack = [$path];
        while ($stack !== []) {
            $dir = array_pop($stack);
            foreach (Fs::listDir($dir) as $name) {
                $p = $dir . '/' . $name;
                if (is_link($p)) {
                    continue;
                }
                if (is_dir($p)) {
                    $stack[] = $p;
                } else {
                    $bytes += (int) @filesize($p);
                    $files++;
                }
            }
        }
        return ['bytes' => $bytes, 'files' => $files];
    }
}
