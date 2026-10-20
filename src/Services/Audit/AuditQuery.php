<?php

declare(strict_types=1);

namespace PJGuardian\Services\Audit;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\AuditActions;
use PJGuardian\Support\AuditChain;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SchemaFeatures;
use PJGuardian\Support\SystemClock;

/**
 * 감사 로그 조회(P30, 읽기 전용 — 수정·삭제 없음). 화면 /admin/audit-logs, API /api/v1/admin/audit-logs.
 *
 * 조건(모두 선택): 기간(days = 1·7·30·90 빠른 선택 또는 from·to UTC ISO), 행위자(로그인 ID 부분 일치), 작업(이름 하나 또는 "접두사." — AuditActions에 있는 것만),
 * 대상(종류 또는 "종류:id" 부분 일치), 자유 검색 q(행위자·작업·대상·세부 정보 부분 일치). 최신순(id 내림차순), page/size(기본 50, 최대 200).
 * - 기간은 occurred_at 색인으로 id 범위를 먼저 구해 기본 키 범위로 좁힌다(id와 시각은 같은 순서로 쌓임 — 시각 조건도 함께 걸어 정확).
 * - 작업 접두사는 action 색인(030)의 범위 조건(action >= 'user.' AND action < 'user/').
 * - 부분 일치는 LIKE … ESCAPE '\'(%·_는 글자).
 * 화면에 내보내는 세부 정보는 기록 때 가린 값에 **한 번 더** 마스킹(민감한 키 이름·문자열 안의 비밀·이메일 주소)을 건다.
 */
final class AuditQuery
{
    public const QUICK_DAYS = [1, 7, 30, 90];
    public const MAX_TEXT = 100;
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private readonly AuditDescriber $describer;

    /** $describer: 사람이 읽는 설명(X17) — 없으면 UTC 시각으로 만든다 */
    public function __construct(private readonly Db $db, private readonly Clock $clock, ?AuditDescriber $describer = null)
    {
        $this->describer = $describer ?? new AuditDescriber($db);
    }

    /**
     * 질의 문자열 → 정리한 조건. 형식이 틀리면 400 invalid_audit_filter(details.field).
     *
     * @param array<string, mixed> $q
     * @return array{from:?string, to:?string, days:?int, actor:string, action:string, target:string, q:string}
     */
    public function filter(array $q): array
    {
        $bad = static fn (string $field): ApiException => new ApiException(400, 'invalid_audit_filter', ['field' => $field], null, [], [], ['field' => $field]);
        $text = static function (string $field) use ($q, $bad): string {
            $v = $q[$field] ?? '';
            if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
                throw $bad($field);
            }
            $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
            if (mb_strlen($v) > self::MAX_TEXT) {
                throw $bad($field);
            }
            return $v;
        };
        $days = null;
        $from = $to = null;
        if (($q['days'] ?? '') !== '') {
            $d = filter_var($q['days'], FILTER_VALIDATE_INT);
            if (!in_array($d, self::QUICK_DAYS, true)) {
                throw $bad('days');
            }
            $days = $d;
            $from = SystemClock::iso($this->clock->now()->modify("-{$d} days"));
        }
        foreach (['from', 'to'] as $k) {
            $v = $text($k);
            if ($v === '') {
                continue;
            }
            if (preg_match(self::ISO, $v) !== 1 || strtotime($v) === false) {
                throw $bad($k);
            }
            if ($k === 'from' && $days === null) {
                $from = $v;
            } elseif ($k === 'to') {
                $to = $v;
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw $bad('to');
        }
        $action = $text('action');
        if ($action !== '' && !in_array($action, AuditActions::all(), true) && !in_array(rtrim($action, '.'), AuditActions::prefixes(), true)) {
            throw $bad('action');
        }
        if ($action !== '' && !str_contains(rtrim($action, '.'), '.')) {
            $action = rtrim($action, '.') . '.';
        }
        return ['from' => $from, 'to' => $to, 'days' => $days, 'actor' => $text('actor'), 'action' => $action, 'target' => $text('target'), 'q' => $text('q')];
    }

    /**
     * @param array{from:?string, to:?string, actor:string, action:string, target:string, q:string} $f
     * @return array{0:string, 1:list<mixed>} WHERE 절과 인자
     */
    private function where(array $f): array
    {
        $w = ['1 = 1'];
        $args = [];
        // 기간 → id 범위(기본 키)로 먼저 좁힌다
        if ($f['from'] !== null) {
            $lo = $this->db->value('SELECT MIN(id) FROM audit_log WHERE occurred_at >= ?', [$f['from']]);
            $w[] = 'id >= ?';
            $args[] = $lo === null ? PHP_INT_MAX : (int) $lo;
            $w[] = 'occurred_at >= ?';
            $args[] = $f['from'];
        }
        if ($f['to'] !== null) {
            $hi = $this->db->value('SELECT MAX(id) FROM audit_log WHERE occurred_at <= ?', [$f['to']]);
            $w[] = 'id <= ?';
            $args[] = $hi === null ? 0 : (int) $hi;
            $w[] = 'occurred_at <= ?';
            $args[] = $f['to'];
        }
        if ($f['action'] !== '') {
            if (str_ends_with($f['action'], '.')) {
                $w[] = 'action >= ? AND action < ?';
                $args[] = $f['action'];
                $args[] = substr($f['action'], 0, -1) . '/';
            } else {
                $w[] = 'action = ?';
                $args[] = $f['action'];
            }
        }
        if ($f['actor'] !== '') {
            $w[] = "actor_label LIKE ? ESCAPE '\\'";
            $args[] = '%' . self::like($f['actor']) . '%';
        }
        if ($f['target'] !== '') {
            $w[] = "(IFNULL(target_type, '') || ':' || IFNULL(target_id, '')) LIKE ? ESCAPE '\\'";
            $args[] = '%' . self::like($f['target']) . '%';
        }
        if ($f['q'] !== '') {
            $w[] = "(actor_label LIKE ? ESCAPE '\\' OR action LIKE ? ESCAPE '\\' OR IFNULL(target_id, '') LIKE ? ESCAPE '\\' OR details LIKE ? ESCAPE '\\')";
            $like = '%' . self::like($f['q']) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $args];
    }

    public static function like(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /**
     * @param array{from:?string, to:?string, actor:string, action:string, target:string, q:string} $f
     * @return array{0:list<array<string, mixed>>, 1:int}
     */
    public function list(array $f, int $page, int $size): array
    {
        [$where, $args] = $this->where($f);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE {$where}", $args);
        $rows = $this->db->all("SELECT * FROM audit_log WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?", [...$args, $size, ($page - 1) * $size]);
        // 설명(X17)은 한 화면 단위로 이름을 일괄 조회해 만들고, 목록 응답에는 세부 정보 원본을 넣지 않는다
        $items = $this->describer->describeMany(array_map(fn (array $r): array => $this->row($r), $rows));
        return [array_map(static function (array $x): array {
            unset($x['details']);
            return $x;
        }, $items), $total];
    }

    /** @return iterable<array<string, mixed>> 내보내기용(최신순, 같은 조건) */
    public function iterate(array $f, int $limit): iterable
    {
        [$where, $args] = $this->where($f);
        $before = PHP_INT_MAX;
        $left = $limit;
        while ($left > 0) {
            $chunk = $this->db->all("SELECT * FROM audit_log WHERE {$where} AND id < ? ORDER BY id DESC LIMIT ?", [...$args, $before, min(2000, $left)]);
            if ($chunk === []) {
                return;
            }
            foreach ($this->describer->describeMany(array_map(fn (array $r): array => $this->row($r), $chunk)) as $r) {
                yield $r;
                $before = (int) $r['id'];
                $left--;
            }
        }
    }

    public function count(array $f): int
    {
        [$where, $args] = $this->where($f);
        return (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE {$where}", $args);
    }

    /** @return array<string, mixed> 한 행 전체(세부 정보 포함). 없으면 404 audit_not_found */
    public function get(int $id): array
    {
        $r = $this->db->one('SELECT * FROM audit_log WHERE id = ?', [$id]);
        if ($r === null) {
            throw new ApiException(404, 'audit_not_found');
        }
        return $this->describer->describeMany([$this->row($r)])[0] + ['prev_hash' => (string) $r['prev_hash'], 'hash' => (string) $r['hash']];
    }

    /** @return array<string, mixed> 보관 경계(체크포인트)와 남은 범위 — 화면 "무결성 검사" 안내 */
    public function range(): array
    {
        $meta = $this->db->one('SELECT * FROM audit_log_meta WHERE id = 1') ?? [];
        $first = $this->db->one('SELECT id, occurred_at FROM audit_log ORDER BY id LIMIT 1');
        $last = $this->db->one('SELECT id, occurred_at FROM audit_log ORDER BY id DESC LIMIT 1');
        return [
            'purged_through' => (int) ($meta['purged_through'] ?? 0),
            'purged_through_at' => SchemaFeatures::auditCheckpoint($this->db) ? ($meta['purged_through_at'] ?? null) : null,
            'purged_at' => SchemaFeatures::auditCheckpoint($this->db) ? ($meta['purged_at'] ?? null) : null,
            'first' => $first === null ? null : ['id' => (int) $first['id'], 'at' => $first['occurred_at']],
            'last' => $last === null ? null : ['id' => (int) $last['id'], 'at' => $last['occurred_at']],
            'rows' => $first === null ? 0 : (int) $this->db->value('SELECT COUNT(*) FROM audit_log'),
        ];
    }

    /** 무결성 검사 한 조각(AuditChain::verifyRange) */
    public function verify(int $afterId, int $limit): array
    {
        return AuditChain::verifyRange($this->db, max(0, $afterId), $limit);
    }

    /**
     * 화면용 한 행. 세부 정보는 다시 마스킹. "결과"·"요청 ID"는 감사 표의 열이 아니어서(해시 대상 열을 바꾸지 않음) 세부 정보에 그 이름이 있을 때만 보인다.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r): array
    {
        $details = json_decode((string) $r['details'], true);
        $details = is_array($details) ? self::safe($details) : [];
        // X17: 설정 키 이름이 비밀 모양(password·secret·token·key…)이면 이전·이후 값도 가린다(화면 단계 — 저장된 행은 그대로)
        $key = (string) ($r['target_id'] ?? '');
        if (in_array($r['target_type'], ['config', 'setting'], true) && $key !== '' && Logger::maskArray([$key => 'x'])[$key] === Logger::MASK) {
            foreach (['old', 'new', 'from', 'to'] as $k) {
                if (array_key_exists($k, $details) && $details[$k] !== null && $details[$k] !== '') {
                    $details[$k] = Logger::MASK;
                }
            }
        }
        $out = [
            'id' => (int) $r['id'], 'occurred_at' => (string) $r['occurred_at'], 'actor' => (string) $r['actor_label'],
            'actor_user_id' => $r['actor_user_id'] === null ? null : (int) $r['actor_user_id'], 'action' => (string) $r['action'],
            'target_type' => $r['target_type'], 'target_id' => $r['target_id'] === null ? null : self::maskText((string) $r['target_id']), 'ip' => $r['ip'],
            'result' => is_scalar($details['result'] ?? null) ? (string) $details['result'] : null,
            'request_id' => is_string($details['request_id'] ?? null) ? $details['request_id'] : null,
            'details_brief' => self::brief($details),
            'details' => $details,
        ];
        return $out;
    }

    /** 세부 정보 원본 한 줄(키=값 몇 개, 기술용 — 사람이 읽는 문장은 AuditDescriber의 summary) */
    private static function brief(array $details): string
    {
        $keys = array_slice(array_keys($details), 0, 4);
        $parts = [];
        foreach ($keys as $k) {
            $v = $details[$k];
            $parts[] = $k . '=' . (is_scalar($v) || $v === null ? mb_substr(var_export($v, true), 0, 40) : '…');
        }
        return mb_substr(implode(', ', $parts), 0, 160);
    }

    /**
     * 화면 단계 마스킹(기록 단계 마스킹과 별개 — 예전 행·실수로 남은 값까지): 민감한 키 이름(Logger 규칙 + 이메일·해시·키 원문),
     * 문자열 안의 비밀(Logger::maskString), 이메일 주소(앞 글자 하나만).
     *
     * @param array<array-key, mixed> $d
     * @return array<array-key, mixed>
     */
    public static function safe(array $d): array
    {
        $d = Logger::maskArray($d);
        $out = [];
        foreach ($d as $k => $v) {
            if (is_string($k) && preg_match('/^(e-?mail|mail|private|key_material|hash_value|password_hash)$/i', $k) === 1 && is_string($v) && $v !== '') {
                $out[$k] = str_contains($v, '@') ? self::maskEmail($v) : Logger::MASK;
            } elseif (is_array($v)) {
                $out[$k] = self::safe($v);
            } elseif (is_string($v)) {
                $out[$k] = self::maskText($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public static function maskText(string $s): string
    {
        $s = Logger::maskString($s);
        return (string) preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', static fn (array $m): string => self::maskEmail($m[0]), $s);
    }

    public static function maskEmail(string $e): string
    {
        $at = strrpos($e, '@');
        if ($at === false || $at === 0) {
            return Logger::MASK;
        }
        return mb_substr($e, 0, 1) . '***' . substr($e, $at);
    }
}
