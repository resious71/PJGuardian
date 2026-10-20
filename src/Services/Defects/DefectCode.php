<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\Db;

/**
 * 결함 코드 조회(명세 7, P17). 결함이 **마지막으로 감지된 분석**의 코드를 JSON 텍스트 줄 목록으로 돌려준다(HTML이 아니다).
 *
 * 출처:
 *   1) 그 분석의 스냅샷이 full → `<snapshots_dir>/<storage_path>/source.zip`에서 결함의 file_path 항목을 읽는다.
 *      mode=window(기본): 결함 줄 앞뒤 window줄, mode=full: 파일 전체(max_lines를 넘으면 window로 바꾸고 partial).
 *   2) 스냅샷이 정리됨(pruned) → defect_excerpts의 발췌(excerpt: true, 발췌 범위만. 범위 밖은 돌려주지 않는다).
 *   3) 둘 다 없음 → source: none과 이유(reason).
 * 경로는 DB 값(snapshots.storage_path, defects.file_path)으로만 찾는다. 요청 매개변수로 경로를 만들지 않는다.
 *
 * 한도(설정 [viewer]): 한 번에 돌려주는 줄 수 code_max_lines, 줄 길이 code_max_line_length(문자, 넘으면 자르고 truncated_lines에 줄 번호),
 *   파일 크기 code_max_file_size(넘으면 file_too_large). 바이너리(NUL 바이트) → binary.
 *   UTF-8이 아닌 파일은 잘못된 바이트를 U+FFFD로 바꾸고 content_replaced: true(발췌는 P16이 저장한 플래그).
 * 줄 나누기는 시그니처와 같은 SourceNormalizer::splitLines(BOM 제거, NFC, ESLint는 U+2028/2029도 줄바꿈)라 도구의 줄 번호와 맞는다.
 */
final class DefectCode
{
    public const MODES = ['window', 'full'];

    public function __construct(
        private readonly Db $db,
        private readonly string $snapshotsDir,
        private readonly int $windowLines,
        private readonly int $maxLines,
        private readonly int $maxLineLength,
        private readonly int $maxFileSize,
    ) {
    }

    /** @return array<string, mixed> */
    /**
     * @param array{run:int, mark:int, analysis_id:int}|null $runView 과거 Run(P20R): 그 Run(존재하지 않았으면 그 전에 마지막으로 존재한 Run)의
     *   스냅샷을 쓴다. 줄 번호는 최신 감지 위치라 note = latest_location. 그 Run이 정리됐으면 발췌(마지막 감지 분석의 코드, note = excerpt_latest).
     */
    public function get(int $defectId, string $mode, ?array $runView = null): array
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new ApiException(400, 'invalid_code_mode');
        }
        $analysisSql = DefectSeen::expr('d'); // P19C: 마지막 감지 분석(읽기 값)
        $params = [$defectId];
        $note = null;
        if ($runView !== null) {
            // 그 Run 이하에서 결함이 존재한 마지막 Run → 그 Run의 분석
            $target = $this->db->value(
                'SELECT MAX(CASE WHEN r.to_run IS NULL OR r.to_run >= ? THEN ? ELSE r.to_run END) FROM defect_runs r WHERE r.defect_id = ? AND r.from_run <= ?',
                [$runView['run'], $runView['run'], $defectId, $runView['run']],
            );
            if ($target === null) {
                return ['defect_id' => $defectId, 'run' => $runView['run'], 'note' => null] + self::none('not_in_run');
            }
            $analysisSql = '(SELECT a.id FROM analyses a WHERE a.project_id = d.project_id AND a.run_number = ?)';
            $params = [(int) $target, $defectId];
            $note = 'latest_location';
        }
        $d = $this->db->one(
            'SELECT d.id, d.tool, d.language, d.file_path, d.line_no, d.col_no, d.end_line_no, d.end_col_no, ' . DefectSeen::expr('d') . ' AS last_seen_analysis_id, ' . $analysisSql . ' AS code_analysis_id,'
            . ' s.status AS snapshot_status, s.storage_path,'
            . ' e.analysis_id AS excerpt_analysis_id, e.start_line, e.end_line AS excerpt_end, e.content, e.content_replaced'
            . ' FROM defects d LEFT JOIN snapshots s ON s.analysis_id = ' . $analysisSql
            . ' LEFT JOIN defect_excerpts e ON e.defect_id = d.id WHERE d.id = ?',
            $runView !== null ? [$params[0], $params[0], $defectId] : $params,
        );
        if ($d === null) {
            throw new ApiException(404, 'defect_not_found');
        }
        if ($runView !== null && (int) $d['code_analysis_id'] === (int) $d['last_seen_analysis_id']) {
            $note = null; // 그 Run이 마지막 감지 분석이면 줄 번호도 그 Run의 것
        }
        $line = (int) $d['line_no'];
        $endLine = max($line, (int) ($d['end_line_no'] ?? $line));
        $base = [
            'defect_id' => (int) $d['id'],
            'analysis_id' => (int) ($d['code_analysis_id'] ?? $d['last_seen_analysis_id']),
            'note' => $note,
            'file_path' => (string) $d['file_path'],
            'language' => (string) $d['language'],
            'highlight' => [
                'line' => $line,
                'column' => $d['col_no'] === null ? null : (int) $d['col_no'],
                'end_line' => $d['end_line_no'] === null ? null : (int) $d['end_line_no'],
                'end_column' => $d['end_col_no'] === null ? null : (int) $d['end_col_no'],
            ],
            'max_line_length' => $this->maxLineLength,
        ];

        if ($d['snapshot_status'] === 'full') {
            $r = $this->fromSnapshot((string) $d['storage_path'], (string) $d['file_path'], (string) $d['tool'], $line, $endLine, $mode);
            if ($r !== null) {
                return $base + $r;
            }
            // zip이 없어졌으면(정리 중 경합 등) 발췌가 있을 때 발췌로
        }
        if ($d['content'] !== null) {
            if ($runView !== null && (int) $d['code_analysis_id'] !== (int) $d['last_seen_analysis_id']) {
                $base['note'] = 'excerpt_latest'; // 그 Run의 코드는 정리됨: 발췌는 마지막 감지 분석의 코드
                $base['analysis_id'] = (int) $d['last_seen_analysis_id'];
            }
            $lines = explode("\n", (string) $d['content']);
            $start = (int) $d['start_line'];
            if (count($lines) > $this->maxLines) {
                // 결함이 아주 긴 범위면 발췌도 한도까지만(결함 줄이 들어가게 자른다)
                $from = max(0, min(count($lines) - $this->maxLines, $line - $start - intdiv($this->maxLines, 2)));
                $lines = array_slice($lines, $from, $this->maxLines);
                $start += $from;
            }
            [$lines, $truncated] = $this->clip($lines, $start);
            return $base + [
                'source' => 'excerpt', 'excerpt' => true, 'reason' => null,
                'start_line' => $start, 'end_line' => $start + count($lines) - 1, 'total_lines' => null, 'partial' => true,
                'lines' => $lines, 'truncated_lines' => $truncated, 'content_replaced' => (int) $d['content_replaced'] === 1,
            ];
        }
        // 정리된 분석인데 발췌가 없음 / 스냅샷 행이 없거나 zip 파일이 없어짐
        return $base + self::none($d['snapshot_status'] === 'pruned' ? 'excerpt_missing' : 'snapshot_missing');
    }

    /** @return array<string, mixed>|null zip 파일이 없으면 null */
    private function fromSnapshot(string $storagePath, string $file, string $tool, int $line, int $endLine, string $mode): ?array
    {
        // storage_path는 서버가 기록한 "<프로젝트 id>/<분석 id>"뿐이다. 다른 형식이면 읽지 않는다
        if (preg_match('#^[1-9][0-9]{0,17}/[1-9][0-9]{0,17}$#', $storagePath) !== 1) {
            return self::none('snapshot_missing');
        }
        $zipPath = rtrim(str_replace('\\', '/', $this->snapshotsDir), '/') . '/' . $storagePath . '/' . SnapshotService::FILE;
        if (!is_file($zipPath)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $stat = $zip->statName($file);
            if ($stat === false) {
                return self::none('file_missing');
            }
            if ((int) $stat['size'] > $this->maxFileSize) {
                return self::none('file_too_large');
            }
            $bytes = $zip->getFromIndex((int) $stat['index']);
        } finally {
            $zip->close();
        }
        if ($bytes === false) {
            return self::none('file_missing');
        }
        if (str_contains(substr($bytes, 0, 8192), "\0")) {
            return self::none('binary');
        }
        $split = SourceNormalizer::splitLines($bytes, $tool);
        unset($bytes);
        $all = $split['lines'];
        // 파일 끝 줄바꿈 뒤의 빈 조각은 줄이 아니다(편집기와 같은 줄 수)
        if (count($all) > 1 && end($all) === '') {
            array_pop($all);
        }
        $total = count($all);
        if ($mode === 'full' && $total <= $this->maxLines) {
            $start = 1;
            $stop = $total;
        } else {
            $start = max(1, $line - $this->windowLines);
            $stop = min($total, $endLine + $this->windowLines);
            if ($stop - $start + 1 > $this->maxLines) {
                $stop = $start + $this->maxLines - 1;
            }
            if ($start > $total) {
                // 결함 줄이 파일보다 뒤(도구와 줄 나누기가 다른 드문 경우): 파일 끝 부분
                $start = max(1, $total - $this->windowLines);
                $stop = $total;
            }
        }
        $lines = $total === 0 ? [] : array_slice($all, $start - 1, $stop - $start + 1);
        unset($all);
        $replaced = false;
        if (!$split['utf8']) {
            foreach ($lines as $i => $l) {
                $conv = SourceNormalizer::toUtf8Text($l);
                $lines[$i] = $conv['text'];
                $replaced = $replaced || $conv['replaced'];
            }
        }
        [$lines, $truncated] = $this->clip($lines, $start);
        return [
            'source' => 'snapshot', 'excerpt' => false, 'reason' => null,
            'start_line' => $start, 'end_line' => $start + max(0, count($lines) - 1), 'total_lines' => $total,
            'partial' => !($start === 1 && $stop >= $total),
            'lines' => $lines, 'truncated_lines' => $truncated, 'content_replaced' => $replaced,
        ];
    }

    /**
     * 긴 줄 자르기(문자 단위).
     *
     * @param list<string> $lines
     * @return array{0: list<string>, 1: list<int>}
     */
    private function clip(array $lines, int $start): array
    {
        $truncated = [];
        foreach ($lines as $i => $l) {
            if (strlen($l) > $this->maxLineLength && mb_strlen($l, 'UTF-8') > $this->maxLineLength) {
                $lines[$i] = mb_substr($l, 0, $this->maxLineLength, 'UTF-8');
                $truncated[] = $start + $i;
            }
        }
        return [array_values($lines), $truncated];
    }

    /** @return array<string, mixed> */
    private static function none(string $reason): array
    {
        return [
            'source' => 'none', 'excerpt' => false, 'reason' => $reason,
            'start_line' => null, 'end_line' => null, 'total_lines' => null, 'partial' => false,
            'lines' => [], 'truncated_lines' => [], 'content_replaced' => false,
        ];
    }
}
