<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 감사 로그 해시 체인 (명세 19.7: append-only, 해시 체인으로 변조 탐지, 90일 보존)
 *
 * hash = SHA-256( prev_hash \n occurred_at \n actor_user_id \n actor_label \n action \n target_type \n target_id \n details \n ip )
 *   - details는 저장된 JSON 문자열 그대로 해시한다(다시 직렬화하지 않음).
 *   - 첫 행의 prev_hash는 audit_log_meta.anchor_hash(처음은 0×64, 정리 후에는 마지막으로 지운 행의 hash).
 * append()는 쓰기 트랜잭션(BEGIN IMMEDIATE) 안에서 직전 해시를 읽어 이어 붙인다(동시 기록에도 체인이 갈라지지 않음).
 *
 * 한계: DB 파일에 쓰기 권한이 있는 사람은 체인 전체를 다시 계산해 바꿀 수 있다. 이를 탐지하려면 최신 해시를
 * DB 밖(백업, 로그)에 주기적으로 남겨 비교해야 한다(P31 하드닝에서 다룸, docs/db_schema.md).
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * 감사 로그 한 줄을 추가하고 id를 돌려준다.
     *
     * @param array<string, mixed> $details
     */
    public static function append(
        Db $db,
        Clock $clock,
        ?int $actorUserId,
        string $actorLabel,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
        ?string $ip = null,
    ): int {
        return $db->transaction(static function (Db $db) use ($clock, $actorUserId, $actorLabel, $action, $targetType, $targetId, $details, $ip): int {
            $prev = (string) ($db->value('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1')
                ?? $db->value('SELECT anchor_hash FROM audit_log_meta WHERE id = 1'));
            $row = [
                'occurred_at' => SystemClock::iso($clock->now()),
                'actor_user_id' => $actorUserId,
                'actor_label' => $actorLabel,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => json_encode((object) Logger::maskArray($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'ip' => $ip,
            ];
            $hash = self::hash($prev, $row);
            $db->execute(
                'INSERT INTO audit_log (occurred_at, actor_user_id, actor_label, action, target_type, target_id, details, ip, prev_hash, hash)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$row['occurred_at'], $row['actor_user_id'], $row['actor_label'], $row['action'], $row['target_type'],
                 $row['target_id'], $row['details'], $row['ip'], $prev, $hash],
            );
            return $db->lastInsertId();
        });
    }

    /** @param array<string, mixed> $row */
    public static function hash(string $prevHash, array $row): string
    {
        return hash('sha256', implode("\n", [
            $prevHash,
            (string) $row['occurred_at'],
            $row['actor_user_id'] === null ? '' : (string) $row['actor_user_id'],
            (string) $row['actor_label'],
            (string) $row['action'],
            (string) ($row['target_type'] ?? ''),
            (string) ($row['target_id'] ?? ''),
            (string) $row['details'],
            (string) ($row['ip'] ?? ''),
        ]));
    }

    /**
     * 체인 전체를 검증한다.
     *
     * @return array{ok:bool, checked:int, broken_at:?int, reason:?string}
     */
    public static function verify(Db $db): array
    {
        $expectedPrev = (string) $db->value('SELECT anchor_hash FROM audit_log_meta WHERE id = 1');
        $checked = 0;
        $st = $db->pdo()->prepare('SELECT * FROM audit_log ORDER BY id');
        $st->execute();
        while (($r = $st->fetch()) !== false) {
            $checked++;
            if ($r['prev_hash'] !== $expectedPrev) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r['id'], 'reason' => 'prev_hash_mismatch'];
            }
            if (self::hash($r['prev_hash'], $r) !== $r['hash']) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r['id'], 'reason' => 'hash_mismatch'];
            }
            $expectedPrev = $r['hash'];
        }
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /**
     * 체인을 나눠 검증한다(P30 화면 "무결성 검사" — 큰 로그도 요청 하나가 길어지지 않게). id가 $afterId보다 큰 행부터 $limit개.
     * 첫 행의 prev_hash는 **DB에서 다시 읽은** 직전 행 hash(없으면 기준점 anchor_hash)와 비교한다 — 호출자가 넘긴 해시를 믿지 않는다.
     * 모든 조각을 차례로 돌리면 verify()와 같은 결과다.
     *
     * @return array{ok:bool, checked:int, broken_at:?int, broken_at_time:?string, reason:?string, last_id:int, done:bool}
     */
    public static function verifyRange(Db $db, int $afterId, int $limit): array
    {
        $prevRow = $afterId > 0 ? $db->value('SELECT hash FROM audit_log WHERE id = (SELECT MAX(id) FROM audit_log WHERE id <= ?)', [$afterId]) : null;
        $expectedPrev = (string) ($prevRow ?? $db->value('SELECT anchor_hash FROM audit_log_meta WHERE id = 1'));
        $checked = 0;
        $last = $afterId;
        $more = false;
        $st = $db->pdo()->prepare('SELECT * FROM audit_log WHERE id > ? ORDER BY id LIMIT ?');
        $st->execute([$afterId, $limit + 1]);
        while (($r = $st->fetch()) !== false) {
            if ($checked === $limit) {
                $more = true;
                break;
            }
            $checked++;
            $reason = $r['prev_hash'] !== $expectedPrev ? 'prev_hash_mismatch' : (self::hash($r['prev_hash'], $r) !== $r['hash'] ? 'hash_mismatch' : null);
            if ($reason !== null) {
                $st->closeCursor();
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r['id'], 'broken_at_time' => (string) $r['occurred_at'], 'reason' => $reason,
                    'last_id' => (int) $r['id'], 'done' => true];
            }
            $expectedPrev = $r['hash'];
            $last = (int) $r['id'];
        }
        $st->closeCursor();
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'broken_at_time' => null, 'reason' => null, 'last_id' => $last, 'done' => !$more];
    }

    /**
     * 보존 기간 정리 대상: occurred_at이 기준보다 이전인 **연속된 앞부분**(id 순서)의 행 수와 마지막 행(dry-run·화면 안내).
     *
     * @return array{count:int, through_id:?int, through_at:?string}
     */
    public static function purgeCandidates(Db $db, string $cutoffIso): array
    {
        $lastPurge = self::lastPurgeId($db, $cutoffIso);
        if ($lastPurge === null) {
            return ['count' => 0, 'through_id' => null, 'through_at' => null];
        }
        return ['count' => (int) $db->value('SELECT COUNT(*) FROM audit_log WHERE id <= ?', [$lastPurge]), 'through_id' => $lastPurge,
            'through_at' => (string) $db->value('SELECT occurred_at FROM audit_log WHERE id = ?', [$lastPurge])];
    }

    /** occurred_at이 기준보다 이전인 연속된 앞부분의 마지막 id(없으면 null) */
    private static function lastPurgeId(Db $db, string $cutoffIso): ?int
    {
        $firstKeep = $db->value('SELECT MIN(id) FROM audit_log WHERE occurred_at >= ?', [$cutoffIso]);
        $lastPurge = $firstKeep === null
            ? $db->value('SELECT MAX(id) FROM audit_log')
            : $db->value('SELECT MAX(id) FROM audit_log WHERE id < ?', [$firstKeep]);
        return $lastPurge === null ? null : (int) $lastPurge;
    }

    /**
     * 보존 기간이 지난 행을 앞에서부터 지우고 기준점(체크포인트)을 옮긴다(명세 14, 19.7: 감사 로그 90일).
     * id 순서의 앞부분만 지운다(중간 행을 지울 수 없다). 지운 행 수를 돌려준다.
     * - $batch: 한 트랜잭션에서 지울 최대 행 수(P30 — 수십만 건도 쓰기 잠금을 짧게). null이면 한 번에.
     *   묶음마다 기준점(anchor_hash = 마지막으로 지운 행의 hash, purged_through = 그 id)을 옮기므로 중간에 멈춰도 체인 검증은 그대로 통과한다.
     * - $nowIso: 030 이후 purged_at(정리 시각)과 purged_through_at(마지막으로 지운 행의 시각)도 남긴다(030 전이면 건너뜀).
     * 삭제는 이 함수 안에서만 purge_enabled=1로 잠시 허용된다. 호출: tools/cleanup.php(Services\Audit\AuditRetention).
     */
    public static function purgeBefore(Db $db, string $cutoffIso, ?int $batch = null, ?string $nowIso = null): int
    {
        $total = 0;
        $withTime = SchemaFeatures::auditCheckpoint($db);
        do {
            $n = $db->transaction(static function (Db $db) use ($cutoffIso, $batch, $nowIso, $withTime): int {
                $lastPurge = self::lastPurgeId($db, $cutoffIso);
                if ($lastPurge === null) {
                    return 0;
                }
                if ($batch !== null) {
                    $cap = $db->value('SELECT id FROM audit_log ORDER BY id LIMIT 1 OFFSET ?', [$batch - 1]);
                    $lastPurge = $cap === null ? $lastPurge : min($lastPurge, (int) $cap);
                }
                $row = $db->one('SELECT hash, occurred_at FROM audit_log WHERE id = ?', [$lastPurge]);
                $db->execute('UPDATE audit_log_meta SET purge_enabled = 1 WHERE id = 1');
                $n = $db->execute('DELETE FROM audit_log WHERE id <= ?', [$lastPurge]);
                $db->execute('UPDATE audit_log_meta SET anchor_hash = ?, purged_through = ?, purge_enabled = 0 WHERE id = 1', [(string) $row['hash'], $lastPurge]);
                if ($withTime) {
                    $db->execute('UPDATE audit_log_meta SET purged_through_at = ?, purged_at = ? WHERE id = 1', [(string) $row['occurred_at'], $nowIso]);
                }
                return $n;
            });
            $total += $n;
        } while ($batch !== null && $n > 0);
        return $total;
    }
}
