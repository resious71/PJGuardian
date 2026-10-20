<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Support\Db;
use PJGuardian\Support\Fs;

/**
 * 결과 NDJSON(P15) → 시그니처·순번 → 연결 전용 임시 표 `temp.cur_defects`(P16).
 *
 * - **파일 단위 처리**: 같은 파일의 결함(NDJSON에서 이어서 나옴)을 모아 그 소스 파일을 한 번 읽고, 정규화 코드의 **해시만** 남긴다
 *   (정규화 코드 원문은 저장하지 않음). 파일을 다 처리하면 줄 배열을 버린다. 최대 메모리 ≈ 가장 큰 파일 하나 + 그 파일의 결함.
 * - 순번: 파일 안에서 (줄, 열, 끝 줄, 끝 열, 규칙) 순으로 정렬한 뒤 같은 시그니처마다 0, 1, 2…(P04와 같음). 같은 키는 입력 순서(안정 정렬).
 * - **결함이 많은 한 파일**(P19C): 한 파일의 결함이 `analysis.file_defect_spill_threshold`(기본 2만)를 넘으면 그 파일만 작업 폴더의 임시 SQLite
 *   (<work>/spill/<분석>-<임의>.sqlite, 본 DB·쓰기 트랜잭션과 무관한 별도 연결)에 한 줄씩 넣고 SQL `ORDER BY`·`ROW_NUMBER()`로 정렬·순번을
 *   매긴다 — 메모리가 결함 수에 비례하지 않는다. 임계 이하 파일은 메모리 경로 그대로(두 경로의 결과가 같음을 시험한다). 임시 파일은 그 파일을
 *   끝내면(성공·예외 모두) 지우고, 분석 자식이 죽어 남은 것은 워커·정리 작업이 지운다(cleanupSpill). 넘치기 전에 여유 공간을 확인한다(부족하면
 *   SpillStorageException → 분석 실패 insufficient_storage).
 * - 임시 표는 TEMP 데이터베이스에 있어 쓰기가 본 DB의 쓰기 잠금을 잡지 않는다. 판정·반영(DefectStore)만 짧은 쓰기 트랜잭션에서 한다.
 * - 같은 파일이 NDJSON에서 떨어져 다시 나오면(도구 출력 형식 위반) 예외: 순번을 결정적으로 매길 수 없다.
 */
final class DefectIngest
{
    /** 임시 SQLite 한 행의 넉넉한 크기 추정(시그니처 64 + 경로·메시지·규칙 + 색인) — 여유 공간 확인용 */
    private const SPILL_ROW_BYTES = 600;
    /** 메모리 경로와 같은 정렬(열·끝 줄·끝 열이 없으면 0, 같은 키는 입력 순서) */
    private const SPILL_ORDER = 'line, COALESCE(col, 0), COALESCE(endl, 0), COALESCE(endcol, 0), rule, seq';

    private ?\PDO $spill = null;
    private ?string $spillFile = null;
    private ?\PDOStatement $spillInsert = null;
    /** @var list<string> 넘친 파일의 소스 줄(그 파일 하나만) */
    private array $spillLines = [];
    private int $spillRows = 0;
    private int $spilledFiles = 0;
    private int $spillPeakRows = 0;

    /**
     * @param int $spillThreshold 이보다 결함이 많은 파일은 임시 SQLite로(기본: 넘치지 않음 — 시험·측정 도구의 옛 동작)
     * @param string|null $spillDir 임시 SQLite를 둘 폴더(spillRoot()). null이면 넘치지 않음
     * @param string $spillTag 임시 파일 이름 앞부분(분석 id — 잔여물 정리 단위)
     */
    public function __construct(
        private readonly Db $db,
        private readonly int $spillThreshold = PHP_INT_MAX,
        private readonly ?string $spillDir = null,
        private readonly string $spillTag = 'x',
    ) {
    }

    public static function createTempTables(Db $db): void
    {
        $db->pdo()->exec(
            'CREATE TEMP TABLE IF NOT EXISTS cur_defects ('
            . ' signature TEXT NOT NULL, ordinal INTEGER NOT NULL, tool TEXT NOT NULL, language TEXT NOT NULL, rule_key TEXT NOT NULL,'
            . ' file_path TEXT NOT NULL, line_no INTEGER NOT NULL, col_no INTEGER NULL, end_line_no INTEGER NULL, end_col_no INTEGER NULL,'
            . ' message TEXT NOT NULL, defect_id INTEGER NULL, upd INTEGER NOT NULL DEFAULT 0, noint INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (signature, ordinal)) WITHOUT ROWID'
        );
        // P19C: 이번에 감지되지 않은 기존 결함 후보와 분류(not_detected·file_deleted·NULL = 범위 밖), 경로별 분류(DefectStore::match가 쓰기 트랜잭션 전에 채운다)
        $db->pdo()->exec('CREATE TEMP TABLE IF NOT EXISTS unseen (id INTEGER PRIMARY KEY, reason TEXT NULL)');
        $db->pdo()->exec('CREATE TEMP TABLE IF NOT EXISTS unseen_path (path TEXT PRIMARY KEY, reason TEXT NULL) WITHOUT ROWID');
        // P19C: 기존 결함 id 짝(DefectStore가 쓰기 트랜잭션 전에 읽기로 채운다 — 판정 단계는 기본 키로 잇는다)
        $db->pdo()->exec('CREATE INDEX IF NOT EXISTS temp.cur_defects_defect ON cur_defects (defect_id)');
        // 갱신할 행(upd)·열린 존재 구간이 없는 기존 결함(noint) 표시 — 대부분 0이라 부분 색인으로 그 행만 찾는다
        $db->pdo()->exec('CREATE INDEX IF NOT EXISTS temp.cur_defects_upd ON cur_defects (upd) WHERE upd = 1');
        $db->pdo()->exec('CREATE INDEX IF NOT EXISTS temp.cur_defects_noint ON cur_defects (noint) WHERE noint = 1');
        $db->pdo()->exec('CREATE TEMP TABLE IF NOT EXISTS reopened (id INTEGER PRIMARY KEY)');
        $db->pdo()->exec('CREATE TEMP TABLE IF NOT EXISTS auto_resolve (id INTEGER PRIMARY KEY, reason TEXT NOT NULL)');
    }

    /** <work>/spill */
    public static function spillRoot(string $workRoot): string
    {
        return rtrim(str_replace('\\', '/', $workRoot), '/') . '/spill';
    }

    /** 남은 임시 SQLite 지우기: $tag(분석 id)를 주면 그 분석 것만, 아니면 $olderThan초보다 오래된 것 전부. 지운 파일 수 */
    public static function cleanupSpill(string $workRoot, ?string $tag = null, int $olderThan = 0): int
    {
        $dir = self::spillRoot($workRoot);
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (Fs::listDir($dir) as $name) {
            if (preg_match('/^([0-9a-z]+)-[0-9a-f]{12}\.sqlite(-journal|-wal|-shm)?$/', $name, $m) !== 1) {
                continue;
            }
            $path = $dir . '/' . $name;
            if ($tag !== null ? $m[1] !== $tag : (int) @filemtime($path) > time() - $olderThan) {
                continue;
            }
            if (@unlink($path)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return array{count:int, files:int, syntax:int, spilled_files:int, spill_peak_rows:int}
     */
    public function load(string $ndjson, string $root): array
    {
        self::createTempTables($this->db);
        $this->db->pdo()->exec('DELETE FROM temp.cur_defects');
        $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
        $h = @fopen($ndjson, 'rb');
        if ($h === false) {
            throw new \RuntimeException('결과 파일을 열 수 없음');
        }
        $stats = ['count' => 0, 'files' => 0, 'syntax' => 0];
        $done = [];
        $group = [];
        $current = null;
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO temp.cur_defects (signature, ordinal, tool, language, rule_key, file_path, line_no, col_no, end_line_no, end_col_no, message)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $resultBytes = (int) @filesize($ndjson);
        try {
            $this->db->transaction(function () use ($h, $root, $insert, $resultBytes, &$stats, &$done, &$group, &$current): void {
                $no = 0;
                while (($line = fgets($h)) !== false) {
                    $no++;
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $d = json_decode($line, true);
                    if (!is_array($d) || !is_string($d['file'] ?? null) || !is_int($d['line'] ?? null) || !is_string($d['rule_key'] ?? null)) {
                        throw new \RuntimeException("결과 파일 {$no}줄 형식 오류");
                    }
                    $file = SourceNormalizer::path($d['file']);
                    if ($file !== $current) {
                        if ($current !== null) {
                            $this->finishFile($root, $current, $group, $insert, $stats);
                            $done[$current] = true;
                        }
                        if (isset($done[$file])) {
                            throw new \RuntimeException('결과 파일에서 같은 파일의 결함이 떨어져 나옴: ' . $file);
                        }
                        $current = $file;
                        $group = [];
                    }
                    if ($this->spill !== null) {
                        $this->spillRow($file, $d, $stats);
                        continue;
                    }
                    $group[] = $d;
                    if ($this->spillDir !== null && count($group) > $this->spillThreshold) {
                        // 이 파일만 임시 SQLite로 옮기고 이후 줄은 바로 넣는다(메모리 일정)
                        $this->openSpill($root, $file, (string) $group[0]['tool'], $resultBytes);
                        foreach ($group as $g) {
                            $this->spillRow($file, $g, $stats);
                        }
                        $group = [];
                    }
                }
                if ($current !== null) {
                    $this->finishFile($root, $current, $group, $insert, $stats);
                }
            }, false);
        } finally {
            fclose($h);
            $this->closeSpill();
        }
        return $stats + ['spilled_files' => $this->spilledFiles, 'spill_peak_rows' => $this->spillPeakRows];
    }

    /**
     * @param list<array<string, mixed>> $group
     * @param array{count:int, files:int, syntax:int} $stats
     */
    private function finishFile(string $root, string $file, array $group, \PDOStatement $insert, array &$stats): void
    {
        if ($this->spill !== null) {
            $this->flushSpill($file, $insert, $stats);
            return;
        }
        $this->flush($root, $file, $group, $insert, $stats);
    }

    /**
     * 파일 하나의 결함(메모리 경로): 소스를 한 번 읽어 시그니처를 계산하고 정렬·순번을 매겨 임시 표에 넣는다.
     *
     * @param list<array<string, mixed>> $group
     * @param array{count:int, files:int, syntax:int} $stats
     */
    private function flush(string $root, string $file, array $group, \PDOStatement $insert, array &$stats): void
    {
        $tool = (string) $group[0]['tool'];
        $disk = SourceNormalizer::onDisk($root, $file);
        $src = $disk === null ? ['lines' => [], 'utf8' => true] : SourceNormalizer::readLines($disk, $tool);
        $rows = [];
        foreach ($group as $d) {
            $rows[] = self::row($file, $d, $src['lines']);
            if (($d['syntax_error'] ?? false) === true) {
                $stats['syntax']++;
            }
        }
        unset($src);
        usort($rows, static fn (array $a, array $b): int => [$a['line'], $a['col'] ?? 0, $a['end'] ?? 0, $a['endcol'] ?? 0, $a['rule']]
            <=> [$b['line'], $b['col'] ?? 0, $b['end'] ?? 0, $b['endcol'] ?? 0, $b['rule']]);
        $seen = [];
        foreach ($rows as $r) {
            $ordinal = $seen[$r['sig']] ?? 0;
            $seen[$r['sig']] = $ordinal + 1;
            $insert->execute([$r['sig'], $ordinal, $r['tool'], $r['language'], $r['rule'], $file, $r['line'], $r['col'], $r['end'], $r['endcol'], $r['msg']]);
            $stats['count']++;
        }
        $stats['files']++;
    }

    /**
     * @param array<string, mixed> $d NDJSON 한 줄
     * @param list<string> $lines 소스 줄
     * @return array{sig:string, tool:string, language:string, rule:string, line:int, col:?int, end:?int, endcol:?int, msg:string}
     */
    private static function row(string $file, array $d, array $lines): array
    {
        $line = max(1, (int) $d['line']);
        $end = is_int($d['end_line'] ?? null) ? $d['end_line'] : null;
        return [
            'sig' => SourceNormalizer::signature($file, (string) $d['rule_key'], SourceNormalizer::codeOf($lines, $line, $end)),
            'tool' => (string) $d['tool'], 'language' => (string) $d['language'], 'rule' => (string) $d['rule_key'],
            'line' => $line, 'col' => is_int($d['column'] ?? null) ? $d['column'] : null, 'end' => $end,
            'endcol' => is_int($d['end_column'] ?? null) ? $d['end_column'] : null, 'msg' => (string) ($d['message'] ?? ''),
        ];
    }

    /** 넘친 파일 시작: 여유 공간 확인 → 임시 SQLite 열기 → 그 파일 소스 읽기(메모리 경로와 같은 도구 기준 줄 나누기) */
    private function openSpill(string $root, string $file, string $tool, int $resultBytes): void
    {
        $dir = (string) $this->spillDir;
        Fs::ensureDir($dir);
        // 결과 파일 크기가 이 파일 결함들의 상한이다(한 줄 = 결함 하나) → 그 두 배 + 임계 × 행 크기 + 여유
        $need = 2 * $resultBytes + min(PHP_INT_MAX >> 2, $this->spillThreshold * self::SPILL_ROW_BYTES) + 64 * 1048576;
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $need) {
            throw new SpillStorageException((int) $free, $need);
        }
        $this->spillFile = $dir . '/' . preg_replace('/[^0-9a-z]/', '', strtolower($this->spillTag)) . '-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->spillFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('PRAGMA temp_store = FILE');
        $pdo->exec('PRAGMA cache_size = -16000');
        $pdo->exec('CREATE TABLE r (seq INTEGER PRIMARY KEY, sig TEXT NOT NULL, tool TEXT NOT NULL, language TEXT NOT NULL, rule TEXT NOT NULL,'
            . ' line INTEGER NOT NULL, col INTEGER NULL, endl INTEGER NULL, endcol INTEGER NULL, msg TEXT NOT NULL)');
        $pdo->beginTransaction();
        $this->spill = $pdo;
        $this->spillInsert = $pdo->prepare('INSERT INTO r (sig, tool, language, rule, line, col, endl, endcol, msg) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $disk = SourceNormalizer::onDisk($root, $file);
        $this->spillLines = $disk === null ? [] : SourceNormalizer::readLines($disk, $tool)['lines'];
        $this->spillRows = 0;
        $this->spilledFiles++;
    }

    /**
     * @param array<string, mixed> $d
     * @param array{count:int, files:int, syntax:int} $stats
     */
    private function spillRow(string $file, array $d, array &$stats): void
    {
        $r = self::row($file, $d, $this->spillLines);
        $this->spillInsert->execute([$r['sig'], $r['tool'], $r['language'], $r['rule'], $r['line'], $r['col'], $r['end'], $r['endcol'], $r['msg']]);
        $this->spillRows++;
        if (($d['syntax_error'] ?? false) === true) {
            $stats['syntax']++;
        }
    }

    /**
     * 넘친 파일 끝: 메모리 경로와 같은 순서로 읽으며 같은 시그니처마다 순번(ROW_NUMBER)을 매겨 임시 표에 넣는다.
     *
     * @param array{count:int, files:int, syntax:int} $stats
     */
    private function flushSpill(string $file, \PDOStatement $insert, array &$stats): void
    {
        $pdo = $this->spill;
        $pdo->commit();
        $stmt = $pdo->query('SELECT sig, ROW_NUMBER() OVER (PARTITION BY sig ORDER BY ' . self::SPILL_ORDER . ') - 1 AS ord, tool, language, rule,'
            . ' line, col, endl, endcol, msg FROM r ORDER BY ' . self::SPILL_ORDER);
        while (($r = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
            $insert->execute([$r[0], (int) $r[1], $r[2], $r[3], $r[4], $file, (int) $r[5], $r[6] === null ? null : (int) $r[6],
                $r[7] === null ? null : (int) $r[7], $r[8] === null ? null : (int) $r[8], $r[9]]);
            $stats['count']++;
        }
        $stmt->closeCursor();
        unset($stmt, $pdo); // 연결 참조를 모두 놓아야 Windows에서 파일을 지울 수 있다
        $stats['files']++;
        $this->spillPeakRows = max($this->spillPeakRows, $this->spillRows);
        $this->closeSpill();
    }

    /** 임시 SQLite 닫고 지우기(성공·예외 모두) */
    private function closeSpill(): void
    {
        if ($this->spill === null) {
            return;
        }
        if ($this->spill->inTransaction()) {
            $this->spill->rollBack();
        }
        $this->spillInsert = null;
        $this->spill = null;
        $this->spillLines = [];
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            @unlink((string) $this->spillFile . $suffix);
        }
        $this->spillFile = null;
    }
}
