<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * WAL 체크포인트 분리(P20S, P19C에서 순서 변경). 분석 반영은 큰 쓰기를 만들고, SQLite 기본값인 자동 체크포인트가 그 COMMIT 직후 반영 연결에서 돌며
 * 반영 시간과 다른 연결의 쓰기 대기를 늘렸다(docs/results/P20R.md 3.3절).
 *
 * - 반영하는 연결(분석 자식 프로세스)에서만 `disableAuto()`로 자동 체크포인트를 끈다. 웹·CLI의 다른 연결은 기본 동작 그대로.
 * - 워커(부모)가 분석이 끝날 때마다(다음 작업을 가져오기 전)와 시작할 때 `run()`:
 *   1) **PASSIVE**(다른 연결의 읽기·쓰기를 막지 않고 할 수 있는 만큼 WAL 내용을 DB로 옮김)를 먼저 한다.
 *   2) 워커가 한가하고(진행 중 작업 없음) PASSIVE가 WAL을 **모두** 옮겼는데도 WAL 파일이 `database.wal_truncate_threshold`를 넘으면 그때만
 *      **TRUNCATE**(파일 줄이기)를 시도한다. 옮길 내용이 없으므로 짧고, 다른 연결의 쓰기를 막을 수 있는 대기는
 *      `database.checkpoint_max_block_ms`(busy_timeout을 잠시 그 값으로)로 제한한다 — 그 안에 안 되면(읽는 연결이 있음) 포기하고 다음 기회에.
 *   P19B에서 TRUNCATE를 바로 하던 방식은 330MB WAL을 옮기는 동안(최대 31초) 다른 쓰기를 막았다.
 * - 체크포인트는 실패해도 분석 결과에 영향이 없다. 경고 로그만 남기고 다음 기회에 다시 한다.
 */
final class WalCheckpoint
{
    public function __construct(
        private readonly Db $db,
        private readonly Logger $logger,
        private readonly int $truncateBytes,
        private readonly int $maxBlockMs = 2000,
        private readonly int $busyTimeoutMs = 5000,
    ) {
    }

    /** 이 연결의 자동 체크포인트를 끈다(반영 연결 전용) */
    public static function disableAuto(Db $db): void
    {
        $db->pdo()->exec('PRAGMA wal_autocheckpoint = 0');
    }

    /**
     * @param bool $idle 진행 중 작업이 없음(TRUNCATE 허용)
     * @return array{mode:string, ok:bool, busy:int, log:int, checkpointed:int, ms:int, wal_before:int, wal_after:int, passive_ms:int,
     *   truncate:?string, truncate_ms:int}
     *   truncate = null(필요 없음) | done | skipped_busy(제한 시간 안에 못 함) | skipped_incomplete(PASSIVE가 다 못 옮김) | skipped_active(작업 중)
     */
    public function run(bool $idle): array
    {
        $wal = $this->db->file() . '-wal';
        $before = self::size($wal);
        $t = hrtime(true);
        $out = ['mode' => 'PASSIVE', 'ok' => false, 'busy' => 0, 'log' => -1, 'checkpointed' => -1, 'ms' => 0, 'wal_before' => $before, 'wal_after' => $before,
            'passive_ms' => 0, 'truncate' => null, 'truncate_ms' => 0];
        $row = $this->checkpoint('PASSIVE', $out);
        $out['passive_ms'] = (int) ((hrtime(true) - $t) / 1e6);
        if ($row !== null) {
            [$out['busy'], $out['log'], $out['checkpointed']] = $row;
            $out['ok'] = $out['busy'] === 0;
        }
        $afterPassive = self::size($wal);
        if ($afterPassive > $this->truncateBytes) {
            if (!$idle) {
                $out['truncate'] = 'skipped_active';
            } elseif ($row === null || $out['log'] !== $out['checkpointed']) {
                $out['truncate'] = 'skipped_incomplete'; // 읽는 연결이 있어 다 옮기지 못함 — TRUNCATE는 그만큼 기다리며 쓰기를 막으므로 다음 기회에
            } else {
                $t2 = hrtime(true);
                $pdo = $this->db->pdo();
                $pdo->exec('PRAGMA busy_timeout = ' . max(0, $this->maxBlockMs));
                try {
                    $r = $this->checkpoint('TRUNCATE', $out);
                } finally {
                    $pdo->exec('PRAGMA busy_timeout = ' . max(0, $this->busyTimeoutMs));
                }
                $out['truncate_ms'] = (int) ((hrtime(true) - $t2) / 1e6);
                $out['mode'] = 'PASSIVE+TRUNCATE';
                $out['truncate'] = $r !== null && $r[0] === 0 ? 'done' : 'skipped_busy';
            }
        }
        $out['ms'] = (int) ((hrtime(true) - $t) / 1e6);
        $out['wal_after'] = self::size($wal);
        if (!$out['ok'] || $out['truncate'] === 'skipped_busy') {
            $this->logger->warn('WAL 체크포인트가 다른 연결에 막혀 끝나지 않음(분석 결과에는 영향 없음, 다음에 다시 시도)', $out);
        } elseif ($out['ms'] >= 100 || $out['truncate'] !== null) {
            $this->logger->info('WAL 체크포인트', $out);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $out 오류 코드를 남길 곳
     * @return array{0:int, 1:int, 2:int}|null [busy, log, checkpointed]
     */
    private function checkpoint(string $mode, array &$out): ?array
    {
        try {
            $row = $this->db->pdo()->query('PRAGMA wal_checkpoint(' . $mode . ')')->fetch(\PDO::FETCH_NUM);
            return [(int) ($row[0] ?? 0), (int) ($row[1] ?? -1), (int) ($row[2] ?? -1)];
        } catch (\PDOException $e) {
            $out['error'] = $e->getCode();
            return null;
        }
    }

    private static function size(string $file): int
    {
        clearstatcache(true, $file);
        return is_file($file) ? (int) filesize($file) : 0;
    }
}
