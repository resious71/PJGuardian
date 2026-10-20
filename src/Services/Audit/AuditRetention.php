<?php

declare(strict_types=1);

namespace PJGuardian\Services\Audit;

use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\AuditChain;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\WalCheckpoint;

/**
 * 감사 로그 보존 정리(P30, 명세 10·14·19.7 — 기본 90일, log.audit_retention). tools/cleanup.php가 매일 부른다.
 * append-only·해시 체인을 깨지 않는다: 앞에서부터 연속된 오래된 행만 묶음(log.audit_purge_batch)마다 지우고, 묶음마다 기준점(체크포인트:
 * audit_log_meta.anchor_hash = 마지막으로 지운 행의 hash, purged_through = 그 id, purged_through_at = 그 시각)을 옮긴다(AuditChain::purgeBefore).
 * 지운 뒤 "audit.purge" 한 줄(지운 건수·경계 id·경계 시각·기준 시각)을 남긴다 — 그 줄은 남은 체인 끝에 이어진다.
 */
final class AuditRetention
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly int $retentionSeconds,
        private readonly int $batch,
        // P31: 정리한 뒤 새 경계를 DB 밖 기록(audit-anchor.jsonl)에 남긴다(없으면 건너뜀)
        private readonly ?AuditAnchor $anchor = null,
    ) {
    }

    public function cutoff(): string
    {
        return SystemClock::iso($this->clock->now()->modify('-' . $this->retentionSeconds . ' seconds'));
    }

    /** @return array{cutoff:string, count:int, through_id:?int, through_at:?string} dry-run */
    public function preview(): array
    {
        $cutoff = $this->cutoff();
        return ['cutoff' => $cutoff] + AuditChain::purgeCandidates($this->db, $cutoff);
    }

    /** @return array{cutoff:string, deleted:int, through_id:?int, through_at:?string, ms:int, checkpoint_ms:int} */
    public function purge(): array
    {
        $t0 = hrtime(true);
        $p = $this->preview();
        $deleted = 0;
        $checkpointMs = 0;
        if ($p['count'] > 0) {
            // 큰 삭제는 분석 반영(P20S)과 같이 이 연결의 자동 체크포인트를 끄고(묶음마다 COMMIT 직후 체크포인트가 돌면 디스크가 느릴 때 묶음당 수십 초),
            // 끝에 PASSIVE 체크포인트를 한 번 — 다른 연결의 읽기·쓰기를 막지 않는다. 쓰기 잠금은 묶음 트랜잭션 동안만(측정 docs/results/P30.md)
            $auto = (int) $this->db->value('PRAGMA wal_autocheckpoint');
            WalCheckpoint::disableAuto($this->db);
            try {
                $deleted = AuditChain::purgeBefore($this->db, $p['cutoff'], $this->batch, SystemClock::iso($this->clock->now()));
            } finally {
                $this->db->pdo()->exec('PRAGMA wal_autocheckpoint = ' . max(0, $auto));
                $t1 = hrtime(true);
                try {
                    $this->db->pdo()->query('PRAGMA wal_checkpoint(PASSIVE)')?->fetchAll();
                } catch (\Throwable) {
                    // 다음 체크포인트(워커)에 맡긴다
                }
                $checkpointMs = (int) ((hrtime(true) - $t1) / 1e6);
            }
            $this->audit->record(Actor::labeled('system'), 'audit.purge', 'audit_log', null,
                ['deleted' => $deleted, 'through_id' => $p['through_id'], 'through_at' => $p['through_at'], 'cutoff' => $p['cutoff']]);
            $this->anchor?->record();
        }
        return ['cutoff' => $p['cutoff'], 'deleted' => $deleted, 'through_id' => $p['through_id'], 'through_at' => $p['through_at'],
            'ms' => (int) ((hrtime(true) - $t0) / 1e6), 'checkpoint_ms' => $checkpointMs];
    }
}
