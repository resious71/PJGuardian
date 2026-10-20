<?php

declare(strict_types=1);

namespace PJGuardian\Services\Repo;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Archive\SafeUnzip;
use PJGuardian\Services\Archive\TargetScanner;
use PJGuardian\Services\Archive\UnzipException;
use PJGuardian\Services\Archive\UnzipLimits;
use PJGuardian\Services\ProjectService;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 저장소 분석의 "가져오기" 단계(P25, 명세 18.3). 분석 자식 프로세스(tools/run_analysis.php)가 도구를 돌리기 전에 부른다.
 * 웹 요청에서는 부르지 않는다(가져오기는 같은 큐·같은 워커).
 *
 *   1) 연결·허용 호스트(이름 풀이 + 주소 판정, 판정한 주소로 고정)·도구(체크섬)·디스크(해제 상한 × 2) 확인
 *   2) 작업 디렉토리 W(<work>/<8자리>)를 만들어 analyses.work_dir에 먼저 기록(취소·워커 사망 때 워커·복구가 지운다)
 *   3) 임시 폴더 S(<work>/fetch/<분석 id>)에서 git(얕은 bare 복제 → archive zip) 또는 p4(print → 파일 → zip)
 *   4) **업로드와 같은 안전 해제**(SafeUnzip: 제외 대상, 실제 바이트 기준 10GB·100만 파일 한도, 경로 이탈·심볼릭 링크·이름 규칙, 최상위 폴더는 유지)
 *      → 대상 확장자 파일 0개면 실패(repo_empty, 기존 결함이 일괄 resolved되지 않게)
 *   5) S 삭제, 출처(source_ref = 실제 브랜치, source_revision = 커밋/changelist, fetched_at) 기록, 연결의 마지막 가져오기 결과 기록
 * 실패는 RepoException(정제된 코드) — 호출자가 분석 failed로 기록한다. 원문은 서버 로그에만.
 */
final class RepoImport
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly RepoConnections $connections,
        private readonly HostPolicy $hosts,
        private readonly RepoTools $tools,
        private readonly DiskGuard $disk,
        private readonly ProjectService $projects,
        private readonly UnzipLimits $limits,
        private readonly string $workRoot,
        private readonly int $defaultTimeout,
        /** @var array<string, list<string>> 언어 → 대상 확장자 */
        private readonly array $targetExtensions,
    ) {
    }

    public static function stageDir(string $workRoot, int $analysisId): string
    {
        return rtrim(str_replace('\\', '/', $workRoot), '/') . '/fetch/' . $analysisId;
    }

    /**
     * @param array<string, mixed> $row analyses 행(running, source_kind git|perforce)
     * @param (\Closure(): bool)|null $cancel
     * @return string 소스가 풀린 작업 디렉토리 경로
     */
    public function run(int $analysisId, array $row, ?\Closure $cancel = null): string
    {
        $projectId = (int) $row['project_id'];
        $stage = self::stageDir($this->workRoot, $analysisId);
        $t0 = hrtime(true);
        try {
            $path = $this->import($analysisId, $row, $stage, $cancel);
            $this->logger->info('저장소 가져오기 완료', ['analysis_id' => $analysisId, 'seconds' => round((hrtime(true) - $t0) / 1e9, 2)]);
            return $path;
        } catch (RepoException $e) {
            if ($e->errorCode !== 'repo_cancelled') {
                $this->connections->mark($projectId, 'fetch', false, $e->errorCode);
                $this->logger->warn('저장소 가져오기 실패', ['analysis_id' => $analysisId, 'code' => $e->errorCode] + array_intersect_key($e->params, ['what' => 1, 'tool' => 1, 'reason' => 1]));
            }
            throw $e;
        } finally {
            self::removeStage($stage);
        }
    }

    /** 가져오기 임시 폴더 삭제(git 객체 파일은 읽기 전용이라 권한을 준 뒤). 다 지웠으면 true */
    public static function removeStage(string $stage): bool
    {
        if (!is_dir($stage)) {
            return true;
        }
        (new VcsSandbox($stage))->remove();
        return !is_dir($stage);
    }

    /** @param (\Closure(): bool)|null $cancel */
    private function import(int $analysisId, array $row, string $stage, ?\Closure $cancel): string
    {
        $projectId = (int) $row['project_id'];
        $conn = $this->connections->row($projectId);
        if ($conn === null || $conn['kind'] !== $row['source_kind']) {
            throw new RepoException('repo_not_configured');
        }
        $target = $this->connections->target($projectId);
        $ip = $target instanceof GitTarget
            ? $this->hosts->check($target->host, $target->port, $target->scheme)
            : $this->hosts->check($target->host, $target->portNumber, 'p4');
        $target = $target->withPinnedIp($ip);
        $secrets = array_values(array_filter([$target instanceof GitTarget ? $target->token : $target->secret]));
        $client = $target instanceof GitTarget ? $this->tools->git($secrets) : $this->tools->p4($secrets);
        $max = $this->limits->maxTotalBytes;
        try {
            $this->disk->checkFree($this->workRoot, 2 * $max);
        } catch (ApiException) {
            throw new RepoException('insufficient_storage');
        }

        // 작업 디렉토리를 먼저 기록: 이후 어디서 끝나도(취소·타임아웃·워커 사망) 워커·복구가 지운다
        Fs::ensureDir($this->workRoot);
        $work = $this->newWorkDir();
        $name = basename($work);
        if ($this->db->execute("UPDATE analyses SET work_dir = ? WHERE id = ? AND status = 'running'", [$name, $analysisId]) !== 1) {
            Fs::removeTree($work);
            throw new RepoException('repo_cancelled');
        }
        Fs::ensureDir($stage);
        $sb = new VcsSandbox($stage . '/sb');
        $deadline = microtime(true) + (float) ((int) ($conn['fetch_timeout'] ?? 0) ?: $this->defaultTimeout);
        $zip = $stage . '/src.zip';
        if ($client instanceof GitClient && $target instanceof GitTarget) {
            $res = $client->fetch($target, $sb, $stage, $zip, $max, $deadline, $cancel);
            [$ref, $revision] = [$res['branch'], $res['commit']];
            // P26B: git archive는 링크를 심볼릭 링크 항목(모드 120000, 내용 = 대상 경로)으로 넣는다 → 해제 전에 zip에서 뺀다(해제기 규칙은 그대로)
            $links = self::dropSymlinks($zip);
        } else {
            /** @var PerforceClient $client */
            /** @var P4Target $target */
            $res = $client->fetch($target, $sb, $stage, $max, $this->limits->maxFiles, $deadline, $cancel);
            $links = $res['symlinks'];
            self::zipTree($res['dir'], $zip);
            Fs::removeTree($res['dir']);
            [$ref, $revision] = [$target->depotPath, (string) $res['change']];
        }
        if ($cancel !== null && $cancel()) {
            throw new RepoException('repo_cancelled');
        }

        // 업로드와 같은 안전 해제(최상위 폴더는 그대로 — 저장소 구조를 바꾸지 않는다). zip 크기 상한은 해제 상한과 같게
        $limits = new UnzipLimits(max($this->limits->maxZipBytes, $max), $max, $this->limits->maxFiles);
        try {
            (new SafeUnzip($limits, $this->projects->matcherFor((int) $row['project_id'])))->extract($zip, $work, false);
        } catch (UnzipException $e) {
            $code = in_array($e->errorCode, ['extract_limit', 'zip_too_large'], true) ? 'repo_limit_exceeded'
                : ($e->errorCode === 'io_error' ? 'repo_fetch_failed' : 'repo_unsafe_content');
            $this->logger->warn('가져온 소스의 안전 해제 실패', ['analysis_id' => $analysisId, 'unzip_code' => $e->errorCode] + array_intersect_key($e->detail, ['reason' => 1, 'entry' => 1]));
            throw new RepoException($code, ['what' => $e->errorCode]);
        }
        @unlink($zip);
        $language = (string) $this->db->value('SELECT language FROM projects WHERE id = ?', [(int) $row['project_id']]);
        $scan = TargetScanner::scan($work, $language, $this->targetExtensions[$language] ?? []);
        if ($scan['total'] === 0) {
            throw new RepoException('repo_empty', ['what' => 'no_target']);
        }
        $now = SystemClock::iso($this->clock->now());
        $this->db->execute('UPDATE analyses SET source_ref = ?, source_revision = ?, fetched_at = ? WHERE id = ?', [mb_substr($ref, 0, 500), $revision, $now, $analysisId]);
        if ($links['count'] > 0) {
            $this->recordSymlinks($analysisId, $links);
        }
        $this->connections->mark((int) $row['project_id'], 'fetch', true, null, $revision);
        return $work;
    }

    /**
     * zip에서 심볼릭 링크 항목을 지운다(링크 대상 내용은 읽지 않는다 — 항목 속성만 본다). 저장소 출처 전용(P26B).
     *
     * @return array{count:int, paths:list<string>} 지운 수와 처음 20개 경로
     */
    public static function dropSymlinks(string $zipFile): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RepoException('repo_fetch_failed');
        }
        $out = ['count' => 0, 'paths' => []];
        try {
            $drop = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_ends_with($name, '/') && SafeUnzip::isSymlinkEntry($zip, $i)) {
                    $drop[] = $i;
                    if (++$out['count'] <= 20) {
                        $out['paths'][] = mb_substr($name, 0, 500);
                    }
                }
            }
            if ($drop !== []) {
                // 링크만 있던 폴더 항목(git archive가 넣는 "dir/")도 뺀다 — 빈 폴더를 만들지 않게
                $dropSet = array_flip($drop);
                $dirs = [];
                $parents = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (isset($dropSet[$i])) {
                        continue;
                    }
                    $name = (string) $zip->getNameIndex($i);
                    if (str_ends_with($name, '/')) {
                        $dirs[$i] = $name;
                        continue; // 폴더는 그 아래 남은 파일이 있을 때만 남긴다(파일의 조상 폴더 목록으로 판정)
                    }
                    for ($p = strrpos($name, '/'); $p !== false; $p = strrpos($name, '/')) {
                        $name = substr($name, 0, $p);
                        if (isset($parents[$name . '/'])) {
                            break;
                        }
                        $parents[$name . '/'] = true;
                    }
                }
                foreach ($dirs as $i => $name) {
                    if (!isset($parents[$name])) {
                        $drop[] = $i;
                    }
                }
            }
            foreach ($drop as $i) {
                $zip->deleteIndex($i);
            }
        } finally {
            if (!$zip->close()) {
                throw new RepoException('repo_fetch_failed');
            }
        }
        return $out;
    }

    /** @param array{count:int, paths:list<string>} $links */
    private function recordSymlinks(int $analysisId, array $links): void
    {
        $warn = [['code' => 'symlinks_skipped', 'count' => $links['count'], 'paths' => $links['paths']]];
        try {
            $this->db->execute('UPDATE analyses SET source_warnings = ? WHERE id = ?', [json_encode($warn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $analysisId]);
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'no such column: source_warnings')) {
                throw $e;
            }
        }
        $this->logger->info('저장소의 심볼릭 링크를 건너뜀', ['analysis_id' => $analysisId, 'count' => $links['count']]);
    }

    private function newWorkDir(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $dir = rtrim($this->workRoot, '/') . '/' . bin2hex(random_bytes(4));
            if (@mkdir($dir, 0700)) {
                return $dir;
            }
        }
        throw new RepoException('repo_fetch_failed');
    }

    /** Perforce로 받은 파일 → zip(STORE, 안전 해제 입력). 파일 내용은 close 때 디스크에서 읽힌다 */
    private static function zipTree(string $dir, string $zip): void
    {
        $z = new \ZipArchive();
        if ($z->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RepoException('repo_fetch_failed');
        }
        $base = strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $rel = substr(str_replace('\\', '/', $f->getPathname()), $base);
                $z->addFile($f->getPathname(), $rel);
                $z->setCompressionName($rel, \ZipArchive::CM_STORE);
            }
        }
        if (!$z->close()) {
            throw new RepoException('repo_fetch_failed');
        }
    }
}
