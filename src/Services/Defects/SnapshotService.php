<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Support\CleanupQueue;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 코드 스냅샷(명세 7, 14, 19.10).
 *
 * 형식: 성공한 분석마다 `<snapshots_dir>/<프로젝트 id>/<분석 id>/source.zip` 한 파일(Deflate). 항목 = 도구가 분석한 파일
 *   (분석 범위, 루트 기준 NFC 상대 경로). 파일 단위 복사 대신 단일 보관 파일이라 Windows 경로 길이(259자)와 파일 수 문제를 피한다.
 *   `snapshots.storage_path` = "<프로젝트 id>/<분석 id>"(snapshots_dir 기준, 프로젝트 삭제가 프로젝트 폴더째 지운다).
 * 정리(prune): 프로젝트마다 최근 N회(설정 snapshot.full_keep_count, 5)만 full. 그보다 오래된 full 스냅샷은
 *   1) 그 분석이 **마지막 감지 분석**인 결함마다 앞뒤 M줄(snapshot.excerpt_context_lines, 20) 발췌를 defect_excerpts에 저장
 *      (원본이 UTF-8이 아니면 대체 문자 U+FFFD로 바꾸고 content_replaced = 1)
 *   2) 스냅샷 행 pruned, 같은 분석의 analysis_file_lists.files_blob을 빈 BLOB(x'' = 정리됨)으로
 *   3) 커밋 뒤 zip 파일 삭제(실패하면 work/cleanup_pending.jsonl)
 *   파일 목록 BLOB은 스냅샷이 없는 성공 분석(대상 파일 0개)도 최근 N회만 남긴다.
 * 정리는 분석 성공 직후(AnalysisFinalizer)와 tools/cleanup.php에서 한다. 실패해도 분석 결과에는 영향 없다.
 */
final class SnapshotService
{
    public const FILE = 'source.zip';

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly string $snapshotsDir,
        private readonly string $workRoot,
        private readonly int $keep,
        private readonly int $context,
    ) {
    }

    public function dirOf(int $projectId, int $analysisId): string
    {
        return rtrim(str_replace('\\', '/', $this->snapshotsDir), '/') . '/' . $projectId . '/' . $analysisId;
    }

    /**
     * 스냅샷을 만든다(분석 성공 반영 전, 자식 프로세스 안에서 작업 디렉토리가 있을 때). 대상 파일이 없으면 null.
     *
     * @return array{storage_path:string, size_bytes:int, file_count:int}|null
     */
    public function create(int $projectId, int $analysisId, string $workPath, ScopeList $scope): ?array
    {
        if ($scope->count() === 0) {
            return null;
        }
        $dir = $this->dirOf($projectId, $analysisId);
        Fs::ensureDir($dir);
        $zipPath = $dir . '/' . self::FILE;
        $partial = $zipPath . '.part';
        @unlink($partial);
        $zip = new \ZipArchive();
        if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('스냅샷 파일을 만들 수 없음');
        }
        $root = rtrim(str_replace('\\', '/', $workPath), '/');
        $n = 0;
        foreach ($scope->paths() as $rel) {
            $src = $root . '/' . $rel;
            if (!is_file($src)) {
                continue;
            }
            if (!$zip->addFile($src, $rel)) {
                $zip->close();
                @unlink($partial);
                throw new \RuntimeException('스냅샷에 파일을 넣을 수 없음');
            }
            $n++;
        }
        if (!$zip->close() || !@rename($partial, $zipPath)) {
            @unlink($partial);
            throw new \RuntimeException('스냅샷 파일을 저장할 수 없음');
        }
        return ['storage_path' => $projectId . '/' . $analysisId, 'size_bytes' => (int) filesize($zipPath), 'file_count' => $n];
    }

    /** 반영이 실패·취소되었을 때 만든 스냅샷을 지운다 */
    public function discard(int $projectId, int $analysisId): void
    {
        Fs::removeTree($this->dirOf($projectId, $analysisId));
    }

    /**
     * 프로젝트의 오래된 스냅샷 정리.
     *
     * @return array{pruned:int, excerpts:int, replaced:int, blobs_emptied:int}
     */
    public function prune(int $projectId): array
    {
        $out = ['pruned' => 0, 'excerpts' => 0, 'replaced' => 0, 'blobs_emptied' => 0];
        $old = $this->db->all(
            "SELECT s.analysis_id, s.storage_path FROM snapshots s JOIN analyses a ON a.id = s.analysis_id"
            . " WHERE a.project_id = ? AND s.status = 'full' ORDER BY s.analysis_id DESC LIMIT -1 OFFSET ?",
            [$projectId, $this->keep],
        );
        foreach ($old as $s) {
            $r = $this->pruneOne($projectId, (int) $s['analysis_id'], (string) $s['storage_path']);
            $out['pruned']++;
            $out['excerpts'] += $r['excerpts'];
            $out['replaced'] += $r['replaced'];
        }
        // 파일 목록 BLOB: 최근 N회 성공 분석만 보존(스냅샷이 없는 분석 포함, "변경 없음"(P27)은 분석이 아니므로 세지 않음)
        $out['blobs_emptied'] += $this->db->execute(
            "UPDATE analysis_file_lists SET files_blob = x'' WHERE length(files_blob) > 0 AND analysis_id IN ("
            . " SELECT id FROM analyses WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id DESC LIMIT -1 OFFSET ?)",
            [$projectId, $this->keep],
        );
        if ($out['pruned'] > 0) {
            $this->logger->info('스냅샷 정리', ['project_id' => $projectId] + $out);
        }
        return $out;
    }

    /** @return array{excerpts:int, replaced:int} */
    private function pruneOne(int $projectId, int $analysisId, string $storagePath): array
    {
        $zipPath = rtrim(str_replace('\\', '/', $this->snapshotsDir), '/') . '/' . $storagePath . '/' . self::FILE;
        $now = SystemClock::iso($this->clock->now());
        $excerpts = 0;
        $replaced = 0;
        $zip = new \ZipArchive();
        $opened = is_file($zipPath) && $zip->open($zipPath, \ZipArchive::RDONLY) === true;
        $this->db->transaction(function (Db $db) use ($analysisId, $opened, $zip, $now, &$excerpts, &$replaced): void {
            $ins = $db->pdo()->prepare(
                'INSERT OR REPLACE INTO defect_excerpts (defect_id, analysis_id, file_path, start_line, end_line, content, created_at, content_replaced)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $currentFile = null;
            $lines = [];
            $isUtf8 = true;
            // P19C: 마지막 감지 = 이 분석(DefectSeen). 정리하는 분석은 최신이 아니므로 감지 중(seen_live) 행은 해당 없다 — 고정된 값만 본다
            $stmt = $db->pdo()->prepare('SELECT d.id, d.tool, d.file_path, d.line_no, d.end_line_no FROM defects d WHERE d.last_seen_analysis_id = ? AND ' . DefectSeen::expr('d') . ' = CAST(? AS INTEGER) ORDER BY d.file_path, d.line_no'); // 식에는 열 친화성이 없어 문자열 매개변수와 비교하면 같지 않다 — 정수로
            $stmt->execute([$analysisId, $analysisId]);
            while (($d = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (!$opened) {
                    break;
                }
                if ($d['file_path'] !== $currentFile) {
                    $currentFile = (string) $d['file_path'];
                    $bytes = $zip->getFromName($currentFile);
                    if ($bytes === false) {
                        $lines = []; // 스냅샷에 없는 파일(있을 수 없지만 방어): 발췌 없음
                    } else {
                        $split = SourceNormalizer::splitLines($bytes, (string) $d['tool']);
                        $lines = $split['lines'];
                        $isUtf8 = $split['utf8'];
                    }
                }
                if ($lines === []) {
                    continue;
                }
                $line = (int) $d['line_no'];
                $end = max($line, (int) ($d['end_line_no'] ?? $line));
                $start = max(1, $line - $this->context);
                $stop = min(count($lines), $end + $this->context);
                if ($stop < $start) {
                    $stop = $start;
                }
                $text = implode("\n", array_slice($lines, $start - 1, $stop - $start + 1));
                $conv = SourceNormalizer::toUtf8Text($text);
                $ins->execute([(int) $d['id'], $analysisId, $currentFile, $start, $stop, $conv['text'], $now, $conv['replaced'] || !$isUtf8 ? 1 : 0]);
                $excerpts++;
                if ($conv['replaced'] || !$isUtf8) {
                    $replaced++;
                }
            }
            $stmt->closeCursor();
            $db->execute("UPDATE snapshots SET status = 'pruned', pruned_at = ? WHERE analysis_id = ? AND status = 'full'", [$now, $analysisId]);
            $db->execute("UPDATE analysis_file_lists SET files_blob = x'' WHERE analysis_id = ?", [$analysisId]);
        });
        if ($opened) {
            $zip->close();
        }
        $dir = dirname($zipPath);
        if (file_exists($dir) && !Fs::removeTree($dir)) {
            $this->logger->warn('스냅샷 파일을 지우지 못함(정리 대상 목록에 남김)', ['analysis_id' => $analysisId]);
            (new CleanupQueue($this->workRoot . '/cleanup_pending.jsonl', $this->clock))->add($dir, 'snapshot_pruned', ['analysis_id' => $analysisId]);
        }
        return ['excerpts' => $excerpts, 'replaced' => $replaced];
    }

    /** 모든 프로젝트 정리(tools/cleanup.php) */
    public function pruneAll(): array
    {
        $total = ['pruned' => 0, 'excerpts' => 0, 'replaced' => 0, 'blobs_emptied' => 0];
        foreach ($this->db->all('SELECT id FROM projects ORDER BY id') as $p) {
            foreach ($this->prune((int) $p['id']) as $k => $v) {
                $total[$k] += $v;
            }
        }
        return $total;
    }
}
