<?php

declare(strict_types=1);

namespace PJGuardian\Services\Audit;

use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 감사 로그 체인 해시의 DB 밖 기록(P31 A-5). DB 파일에 쓸 수 있는 사람은 체인 전체를 다시 계산할 수 있으므로(docs/audit_actions.md),
 * 최신 행의 id·hash와 정리 경계(purged_through·anchor_hash)를 **웹 루트 밖 로그 폴더의 덧붙이기 전용 파일**(audit-anchor.jsonl)에 주기적으로 남기고,
 * 무결성 검사·모니터링이 DB와 대조한다.
 *
 * 기록: 워커가 log.audit_anchor_interval(1시간)마다, 감사 로그 보존 정리(audit.purge) 직후(새 경계). 마지막 기록과 같으면 쓰지 않는다.
 * 대조(check): 최근 기록부터 최대 CHECK_RECORDS개 —
 *   - 기록의 last_id가 지금 정리 경계보다 크면: 그 행이 DB에 있고 hash가 같아야 한다(없으면 missing_row, 다르면 hash_changed)
 *   - last_id가 지금 경계와 같으면: 경계 해시(anchor_hash)가 기록의 hash와 같아야 한다
 *   - 지금 경계가 기록의 경계보다 작으면(정리 경계가 뒤로 감 = DB를 과거로 바꿈) purge_rewound
 *   - last_id가 지금 경계보다 작으면: 보존 정리로 정상적으로 지워진 범위(확인할 수 없음 — 건너뜀)
 * 파일이 없거나 비었으면 unknown(경고 아님, 안내). 복원하면 DB가 과거로 돌아가므로 기록 파일을 옆으로 옮기고 새로 시작한다(rotate).
 */
final class AuditAnchor
{
    public const FILE = 'audit-anchor.jsonl';
    private const CHECK_RECORDS = 200;

    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly string $logsDir)
    {
    }

    public function path(): string
    {
        return rtrim($this->logsDir, '/\\') . '/' . self::FILE;
    }

    /** @return array{last_id:int, last_hash:string, purged_through:int, anchor_hash:string} 지금 DB의 끝과 경계 */
    private function current(): array
    {
        $last = $this->db->one('SELECT id, hash FROM audit_log ORDER BY id DESC LIMIT 1');
        $meta = $this->db->one('SELECT purged_through, anchor_hash FROM audit_log_meta WHERE id = 1') ?? [];
        return ['last_id' => (int) ($last['id'] ?? 0), 'last_hash' => (string) ($last['hash'] ?? ''),
            'purged_through' => (int) ($meta['purged_through'] ?? 0), 'anchor_hash' => (string) ($meta['anchor_hash'] ?? '')];
    }

    /** 한 줄 덧붙이기(마지막 기록과 같으면 건너뜀). 쓸 수 없으면 false — 호출자는 로그만 남기고 계속한다 */
    public function record(): bool
    {
        $cur = $this->current();
        $records = $this->records(1);
        $prev = $records[0] ?? null;
        if ($prev !== null && $prev['last_id'] === $cur['last_id'] && $prev['last_hash'] === $cur['last_hash'] && $prev['purged_through'] === $cur['purged_through']) {
            return true;
        }
        $dir = dirname($this->path());
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return false;
        }
        $isNew = !is_file($this->path());
        $line = json_encode(['at' => SystemClock::iso($this->clock->now())] + $cur, JSON_UNESCAPED_SLASHES) . "\n";
        $ok = @file_put_contents($this->path(), $line, FILE_APPEND | LOCK_EX) === strlen($line);
        if ($ok && $isNew && PHP_OS_FAMILY !== 'Windows') {
            @chmod($this->path(), 0640);
        }
        return $ok;
    }

    /**
     * 최근 기록(최신 먼저). 파일 끝에서 읽는다(큰 파일도 일부만).
     *
     * @return list<array{at:string, last_id:int, last_hash:string, purged_through:int, anchor_hash:string}>
     */
    public function records(int $max = self::CHECK_RECORDS): array
    {
        $file = $this->path();
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $size = (int) filesize($file);
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return [];
        }
        $chunk = min($size, max(4096, $max * 300));
        fseek($fh, $size - $chunk);
        $data = (string) fread($fh, $chunk);
        fclose($fh);
        $lines = array_reverse(array_values(array_filter(explode("\n", $data), static fn (string $l): bool => $l !== '')));
        if ($chunk < $size && $lines !== []) {
            array_pop($lines); // 잘린 첫 줄
        }
        $out = [];
        foreach ($lines as $l) {
            $r = json_decode($l, true);
            if (is_array($r) && is_int($r['last_id'] ?? null) && is_string($r['last_hash'] ?? null) && is_int($r['purged_through'] ?? null)) {
                $out[] = ['at' => (string) ($r['at'] ?? ''), 'last_id' => $r['last_id'], 'last_hash' => $r['last_hash'], 'purged_through' => $r['purged_through'],
                    'anchor_hash' => (string) ($r['anchor_hash'] ?? '')];
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /**
     * DB와 대조.
     *
     * @return array{state:string, checked:int, skipped:int, latest_at:?string, broken_id:?int, reason:?string}
     *   state: ok | mismatch | unknown(기록 없음·읽을 수 없음)
     */
    public function check(): array
    {
        $records = $this->records();
        if ($records === []) {
            return ['state' => 'unknown', 'checked' => 0, 'skipped' => 0, 'latest_at' => null, 'broken_id' => null, 'reason' => is_file($this->path()) ? 'unreadable' : 'no_file'];
        }
        $cur = $this->current();
        $checked = 0;
        $skipped = 0;
        foreach ($records as $r) {
            $reason = null;
            if ($cur['purged_through'] < $r['purged_through']) {
                $reason = 'purge_rewound';
            } elseif ($r['last_id'] > $cur['purged_through']) {
                $hash = $this->db->value('SELECT hash FROM audit_log WHERE id = ?', [$r['last_id']]);
                $reason = $hash === null ? 'missing_row' : (!hash_equals((string) $hash, $r['last_hash']) ? 'hash_changed' : null);
            } elseif ($r['last_id'] === $cur['purged_through'] && $r['last_id'] > 0) {
                $reason = hash_equals($cur['anchor_hash'], $r['last_hash']) ? null : 'hash_changed';
            } else {
                $skipped++;
                continue;
            }
            if ($reason !== null) {
                return ['state' => 'mismatch', 'checked' => $checked + 1, 'skipped' => $skipped, 'latest_at' => $records[0]['at'], 'broken_id' => $r['last_id'], 'reason' => $reason];
            }
            $checked++;
        }
        return ['state' => 'ok', 'checked' => $checked, 'skipped' => $skipped, 'latest_at' => $records[0]['at'], 'broken_id' => null, 'reason' => null];
    }

    /** 복원 뒤: DB가 과거 시점으로 돌아가므로 지금 기록을 옆으로 옮기고(audit-anchor.<UTC>.before-restore.jsonl) 새로 시작한다 */
    public static function rotateAfterRestore(string $logsDir, Clock $clock): void
    {
        $file = rtrim($logsDir, '/\\') . '/' . self::FILE;
        if (is_file($file)) {
            @rename($file, rtrim($logsDir, '/\\') . '/audit-anchor.' . $clock->now()->format('Ymd\THis\Z') . '.before-restore.jsonl');
        }
    }
}
