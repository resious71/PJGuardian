<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

use PJGuardian\Controllers\RuleController;
use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SystemClock;

/**
 * 관리자 규칙 켜기·끄기(P22B). 서버 기본 설정(파일)은 기본값이고, 관리자가 바꾼 것만 rule_overrides에 "차이"로 둔다(016).
 *
 * - 기본값 = 활성 버전 rules.default_enabled(P22가 서버 기본 설정으로 계산한 값). 실효값 = override가 있으면 그 값, 없으면 기본값.
 * - 기본값과 같아지는 변경은 행을 지운다. 바뀔 때마다 도구별 개정 번호(rule_override_state.revision) + 1.
 * - 낙관적 동시성: 요청의 expected_enabled가 지금 실효값과 다르면 409 rule_override_conflict(지금 상태를 details.current로).
 * - 켤 수 없는 규칙(422 rule_not_enableable, details.reason): removed·deprecated·preview(상태), requires_type_info·requires_options
 *   (rule_enable_blocks), special_key(PJGuardian 특수 키). 끄기는 언제나 된다(이미 꺼져 있으면 바뀌는 것 없음).
 * - 분석은 시작할 때 snapshotFor()로 한 번 읽어 그 분석 동안 고정한다(변경은 다음 분석부터). 그 도구 버전에 없는 규칙·켤 수 없게 된 규칙은
 *   적용하지 않고 건너뛴다(행은 지우지 않음 — 관리 화면에 "활성 버전에 없음"/"켤 수 없게 됨"으로 보인다).
 * - 적용 범위: ESLint는 모든 프로젝트, ruff는 서버 기본 설정을 쓰는 프로젝트만(프로젝트가 올린 ruff 설정은 그대로 — RuffRunner).
 * - 감사 로그: rules.override_set, rules.override_clear, rules.override_reset_all, rules.override_bulk(P22C).
 * - P22C 일괄 변경: 단일 변경과 같은 판정(decide)·쓰기(write)를 쓴다. 대상은 컨트롤러가 서버에서 다시 정한다(키 목록은 활성 버전과 대조,
 *   검색 결과 전체는 같은 조건으로 재조회). 켤 수 없는 규칙·활성 버전에 없는 키·특수 키는 건너뛰고 건수로 알린다(일괄에서는 422 없음).
 *   적용은 한 트랜잭션·개정 번호 한 번 +1, 미리보기 뒤 개정 번호가 바뀌었으면 409(details.reason = revision_changed). 대상이 bulk_max를 넘으면 400 bulk_too_large.
 */
final class RuleOverrides
{
    public const TOOLS = ['ruff', 'eslint'];
    public const REASONS = ['removed', 'deprecated', 'preview', 'requires_type_info', 'requires_options', 'special_key'];
    /** 관리 화면 상태: 적용 중 / 활성 버전에 없음 / 켤 수 없게 됨 */
    public const STATES = ['active', 'missing', 'not_enableable'];
    /** 일괄 동작 */
    public const BULK_ACTIONS = ['enable', 'disable', 'reset'];
    /** 일괄에서 건너뛰는 이유(켤 수 없는 6종 + 활성 버전에 없음) */
    public const SKIP_REASONS = ['removed', 'deprecated', 'preview', 'requires_type_info', 'requires_options', 'special_key', 'missing'];
    private const EXAMPLES = 10;
    private const AUDIT_KEYS = 100;
    private const REASON_MAX = 200;
    /** 켤 수 없는 이유 → 문자열 키(문구는 리소스, LangTest가 키를 검사) */
    private const BLOCK_TEXT = [
        'removed' => 'rules.block.removed', 'deprecated' => 'rules.block.deprecated', 'preview' => 'rules.block.preview',
        'requires_type_info' => 'rules.block.requires_type_info', 'requires_options' => 'rules.block.requires_options', 'special_key' => 'rules.block.special_key',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly int $resetMax = 1000,
        private readonly int $bulkMax = 1000,
    ) {
    }

    public function bulkMax(): int
    {
        return $this->bulkMax;
    }

    /**
     * 한 규칙에 대한 동작의 결과(단일·일괄 공통 판정, 순수 함수).
     * enable: 켤 수 없으면(이미 켜져 있지 않은 한) 건너뜀. disable: 언제나. reset: 관리자 변경이 있을 때만 기본값으로.
     * 결과가 지금 값과 같으면 unchanged.
     *
     * @return array{kind: 'change'|'unchanged'|'skip', to: ?bool, why: ?string}
     */
    public static function decide(string $action, bool $current, bool $base, bool $overridden, ?string $block): array
    {
        if ($action === 'reset') {
            return $overridden ? ['kind' => 'change', 'to' => $base, 'why' => null] : ['kind' => 'unchanged', 'to' => null, 'why' => null];
        }
        $to = $action === 'enable';
        if ($to === $current) {
            return ['kind' => 'unchanged', 'to' => null, 'why' => null];
        }
        if ($to && $block !== null) {
            return ['kind' => 'skip', 'to' => null, 'why' => $block];
        }
        return ['kind' => 'change', 'to' => $to, 'why' => null];
    }

    /** 상태와 버전별 막힘으로 켤 수 없는 이유(없으면 null). 순서가 곧 우선순위 */
    public static function blockReason(string $status, array $blocks): ?string
    {
        if (in_array($status, ['removed', 'deprecated', 'preview'], true)) {
            return $status;
        }
        foreach (['requires_type_info', 'requires_options'] as $r) {
            if (in_array($r, $blocks, true)) {
                return $r;
            }
        }
        return null;
    }

    /** 016 표가 있는지(없으면 기존 동작 그대로 — 변경 없음) */
    public function available(): bool
    {
        return $this->db->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'rule_overrides'") !== null;
    }

    public function revision(string $tool): int
    {
        return (int) ($this->db->value('SELECT revision FROM rule_override_state WHERE tool = ?', [$tool]) ?? 0);
    }

    /**
     * 활성 버전의 규칙 하나와 실효 상태. 활성 버전에 없으면 404 rule_not_found(특수 키는 422 special_key).
     *
     * @return array<string, mixed>
     */
    public function state(string $tool, string $rule): array
    {
        $this->assertKey($tool, $rule);
        $row = $this->db->one(
            'SELECT r.rule, r.version, r.name, r.status, r.default_enabled,'
            . ' (SELECT group_concat(b.reason) FROM rule_enable_blocks b WHERE b.tool = r.tool AND b.version = r.version AND b.rule = r.rule) AS blocks'
            . ' FROM rules r JOIN rule_versions v ON v.tool = r.tool AND v.version = r.version AND v.is_active = 1 WHERE r.tool = ? AND r.rule = ?',
            [$tool, $rule],
        );
        if ($row === null) {
            throw new ApiException(404, 'rule_not_found');
        }
        $o = $this->db->one('SELECT o.*, u.display_name AS changed_by_name FROM rule_overrides o LEFT JOIN users u ON u.id = o.changed_by WHERE o.tool = ? AND o.rule = ?', [$tool, $rule]);
        $base = (bool) $row['default_enabled'];
        $block = self::blockReason((string) $row['status'], $row['blocks'] === null ? [] : explode(',', (string) $row['blocks']));
        return [
            'tool' => $tool, 'rule' => $rule, 'version' => (string) $row['version'],
            'base_enabled' => $base, 'enabled' => $o === null ? $base : (bool) $o['enabled'], 'overridden' => $o !== null,
            'enableable' => $block === null, 'block' => $block, 'revision' => $this->revision($tool),
            'override' => $o === null ? null : [
                'reason' => $o['reason'], 'changed_at' => $o['changed_at'], 'changed_by' => $o['changed_by'] === null ? null : (int) $o['changed_by'],
                'changed_by_name' => $o['changed_by_name'], 'base_enabled' => (bool) $o['base_enabled'], 'tool_version' => $o['tool_version'],
            ],
        ];
    }

    /**
     * 켜기·끄기. 기본값과 같아지면 행을 지운다.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed> 새 state()
     */
    public function set(array $user, ?string $ip, string $tool, string $rule, bool $enabled, bool $expected, ?string $reason): array
    {
        $reason = self::cleanReason($reason);
        return $this->db->transaction(function () use ($user, $ip, $tool, $rule, $enabled, $expected, $reason): array {
            $s = $this->state($tool, $rule);
            if ($s['enabled'] !== $expected) {
                throw new ApiException(409, 'rule_override_conflict', [], null, [], [], ['current' => self::publicState($s, true)]);
            }
            $d = self::decide($enabled ? 'enable' : 'disable', $s['enabled'], $s['base_enabled'], $s['overridden'], $s['block']);
            if ($d['kind'] === 'unchanged') {
                return $s; // 바뀌는 것 없음(감사·개정 번호 그대로)
            }
            if ($d['kind'] === 'skip') {
                throw new ApiException(422, 'rule_not_enableable', ['why' => Lang::get(self::BLOCK_TEXT[$s['block']])], null, [], [], ['reason' => $s['block']]);
            }
            $now = SystemClock::iso($this->clock->now());
            $this->write($tool, $rule, $enabled, $s['base_enabled'], $reason, $s['version'], (int) $user['id'], $now);
            $this->bump($tool, $now);
            $this->audit->record(Actor::fromUser($user, $ip), 'rules.override_set', 'rule', $tool . ':' . $rule, [
                'tool' => $tool, 'rule' => $rule, 'before' => $s['enabled'], 'after' => $enabled, 'base' => $s['base_enabled'], 'reason' => $reason,
            ]);
            return $this->state($tool, $rule);
        });
    }

    /**
     * 기본값으로 되돌리기(멱등). 활성 버전에 없는 규칙의 변경도 지울 수 있다(관리 화면의 "활성 버전에 없음").
     *
     * @param array<string, mixed> $user
     * @return array{tool:string, rule:string, cleared:bool, revision:int}
     */
    public function clear(array $user, ?string $ip, string $tool, string $rule): array
    {
        $this->assertKey($tool, $rule);
        return $this->db->transaction(function () use ($user, $ip, $tool, $rule): array {
            $o = $this->db->one('SELECT enabled, base_enabled FROM rule_overrides WHERE tool = ? AND rule = ?', [$tool, $rule]);
            if ($o === null) {
                if ($this->db->value('SELECT 1 FROM rules WHERE tool = ? AND rule = ?', [$tool, $rule]) === null) {
                    throw new ApiException(404, 'rule_not_found');
                }
                return ['tool' => $tool, 'rule' => $rule, 'cleared' => false, 'revision' => $this->revision($tool)];
            }
            $now = SystemClock::iso($this->clock->now());
            $this->db->execute('DELETE FROM rule_overrides WHERE tool = ? AND rule = ?', [$tool, $rule]);
            $this->bump($tool, $now);
            $this->audit->record(Actor::fromUser($user, $ip), 'rules.override_clear', 'rule', $tool . ':' . $rule, [
                'tool' => $tool, 'rule' => $rule, 'before' => (bool) $o['enabled'], 'after' => (bool) $o['base_enabled'],
            ]);
            return ['tool' => $tool, 'rule' => $rule, 'cleared' => true, 'revision' => $this->revision($tool)];
        });
    }

    /**
     * 도구별 모두 기본값으로(확인값 = 도구 이름, 대소문자 무시). 한 번에 resetMax건까지 — 남으면 remaining > 0.
     *
     * @param array<string, mixed> $user
     * @return array{tool:string, reset:int, remaining:int, revision:int}
     */
    public function resetAll(array $user, ?string $ip, string $tool, string $confirm): array
    {
        if (!in_array($tool, self::TOOLS, true)) {
            throw new ApiException(400, 'invalid_input', ['field' => 'tool'], null, [], [], ['field' => 'tool']);
        }
        if (strcasecmp(trim($confirm), $tool) !== 0) {
            throw new ApiException(400, 'rule_reset_confirm_mismatch');
        }
        return $this->db->transaction(function () use ($user, $ip, $tool): array {
            $rows = $this->db->all('SELECT rule FROM rule_overrides WHERE tool = ? ORDER BY rule LIMIT CAST(? AS INTEGER)', [$tool, $this->resetMax]);
            $rules = array_column($rows, 'rule');
            if ($rules !== []) {
                $this->db->execute('DELETE FROM rule_overrides WHERE tool = ? AND rule IN (SELECT value FROM json_each(?))', [$tool, json_encode($rules)]);
                $this->bump($tool, SystemClock::iso($this->clock->now()));
                $this->audit->record(Actor::fromUser($user, $ip), 'rules.override_reset_all', 'rule', $tool, ['tool' => $tool, 'count' => count($rules), 'rules' => array_slice($rules, 0, 100)]);
            }
            $remaining = (int) $this->db->value('SELECT COUNT(*) FROM rule_overrides WHERE tool = ?', [$tool]);
            return ['tool' => $tool, 'reset' => count($rules), 'remaining' => $remaining, 'revision' => $this->revision($tool)];
        });
    }

    /**
     * 분석 시작 때 읽는 스냅샷(그 분석 동안 고정). 그 도구 버전의 규칙 데이터로 확인하고(적재 전 버전이면 활성 적재 버전), 없는 규칙·켤 수 없는 규칙은
     * 건너뛴다(skipped, 경고 로그). 016 전 DB나 규칙 데이터가 없으면 변경 없음(null).
     *
     * @return array{enable: list<string>, disable: list<string>, revision: int, version: ?string, skipped: list<array{rule:string, why:string}>}|null
     */
    public function snapshotFor(string $tool, string $toolVersion, bool $log = true): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $rows = $this->db->all('SELECT rule, enabled FROM rule_overrides WHERE tool = ? ORDER BY rule', [$tool]);
        if ($rows === []) {
            return null;
        }
        $version = $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND version = ?', [$tool, $toolVersion])
            ?? $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
        $out = ['enable' => [], 'disable' => [], 'revision' => $this->revision($tool), 'version' => $version, 'skipped' => []];
        if ($version === null) {
            foreach ($rows as $r) {
                $out['skipped'][] = ['rule' => (string) $r['rule'], 'why' => 'no_rule_data'];
            }
        } else {
            $known = [];
            foreach ($this->db->all(
                'SELECT r.rule, r.status, (SELECT group_concat(b.reason) FROM rule_enable_blocks b WHERE b.tool = r.tool AND b.version = r.version AND b.rule = r.rule) AS blocks'
                . ' FROM rules r WHERE r.tool = ? AND r.version = ? AND r.rule IN (SELECT value FROM json_each(?))',
                [$tool, $version, json_encode(array_column($rows, 'rule'))],
            ) as $k) {
                $known[(string) $k['rule']] = self::blockReason((string) $k['status'], $k['blocks'] === null ? [] : explode(',', (string) $k['blocks']));
            }
            foreach ($rows as $r) {
                $rule = (string) $r['rule'];
                if (!array_key_exists($rule, $known)) {
                    $out['skipped'][] = ['rule' => $rule, 'why' => 'missing'];
                } elseif ((int) $r['enabled'] === 1 && $known[$rule] !== null) {
                    $out['skipped'][] = ['rule' => $rule, 'why' => $known[$rule]];
                } else {
                    $out[(int) $r['enabled'] === 1 ? 'enable' : 'disable'][] = $rule;
                }
            }
        }
        if ($log && $out['skipped'] !== []) {
            $this->logger->warn('규칙 변경 일부를 적용하지 않음(도구 버전에 없거나 켤 수 없음)', ['tool' => $tool, 'tool_version' => $toolVersion,
                'rules_version' => $version, 'skipped' => array_slice($out['skipped'], 0, 50)]);
        }
        return $out;
    }

    /**
     * 관리 화면 목록(admin). 상태 필터: active | missing | not_enableable.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(?string $tool, ?string $state, int $limit, int $offset): array
    {
        if ($tool !== null && !in_array($tool, self::TOOLS, true)) {
            throw new ApiException(400, 'invalid_input', ['field' => 'tool'], null, [], [], ['field' => 'tool']);
        }
        if ($state !== null && !in_array($state, self::STATES, true)) {
            throw new ApiException(400, 'invalid_input', ['field' => 'state'], null, [], [], ['field' => 'state']);
        }
        $rows = $this->db->all(
            'SELECT o.tool, o.rule, o.enabled, o.base_enabled, o.reason, o.tool_version, o.changed_at, o.changed_by, u.display_name AS changed_by_name,'
            . ' r.name, r.status, r.default_enabled AS current_base,'
            . ' (SELECT group_concat(b.reason) FROM rule_enable_blocks b WHERE b.tool = r.tool AND b.version = r.version AND b.rule = r.rule) AS blocks'
            . ' FROM rule_overrides o LEFT JOIN users u ON u.id = o.changed_by'
            . ' LEFT JOIN rule_versions v ON v.tool = o.tool AND v.is_active = 1'
            . ' LEFT JOIN rules r ON r.tool = o.tool AND r.version = v.version AND r.rule = o.rule'
            . ' WHERE (? IS NULL OR o.tool = ?) ORDER BY o.tool, o.rule',
            [$tool, $tool],
        );
        $items = [];
        foreach ($rows as $r) {
            $block = $r['status'] === null ? null : self::blockReason((string) $r['status'], $r['blocks'] === null ? [] : explode(',', (string) $r['blocks']));
            $st = $r['status'] === null ? 'missing' : ((int) $r['enabled'] === 1 && $block !== null ? 'not_enableable' : 'active');
            if ($state !== null && $st !== $state) {
                continue;
            }
            $items[] = [
                'tool' => (string) $r['tool'], 'rule' => (string) $r['rule'], 'key' => $r['tool'] . ':' . $r['rule'], 'name' => $r['name'],
                'enabled' => (bool) $r['enabled'], 'base_enabled' => $r['current_base'] === null ? (bool) $r['base_enabled'] : (bool) $r['current_base'],
                'reason' => $r['reason'], 'tool_version' => $r['tool_version'], 'changed_at' => $r['changed_at'],
                'changed_by' => $r['changed_by'] === null ? null : (int) $r['changed_by'], 'changed_by_name' => $r['changed_by_name'],
                'state' => $st, 'block' => $st === 'not_enableable' ? $block : null,
                'href' => \PJGuardian\Support\RuleLinks::target($r['tool'] . ':' . $r['rule']),
            ];
        }
        return [array_slice($items, $offset, $limit), count($items)];
    }

    /**
     * 요약(도구별): 변경 수, 마지막 변경 시각, 개정 번호, 변경 집합 해시 앞 12자리.
     *
     * @return array<string, array<string, mixed>>
     */
    public function summary(): array
    {
        $out = [];
        foreach (self::TOOLS as $tool) {
            $rows = $this->db->all('SELECT rule, enabled FROM rule_overrides WHERE tool = ? ORDER BY rule', [$tool]);
            $out[$tool] = [
                'count' => count($rows),
                'enabled' => count(array_filter($rows, static fn (array $r): bool => (int) $r['enabled'] === 1)),
                'disabled' => count(array_filter($rows, static fn (array $r): bool => (int) $r['enabled'] === 0)),
                'last_changed_at' => $this->db->value('SELECT updated_at FROM rule_override_state WHERE tool = ?', [$tool]),
                'revision' => $this->revision($tool),
            ];
        }
        return $out;
    }

    /**
     * 활성 적재 버전 기준 변경 집합(관리 화면의 "실효 설정 해시" 계산용 — 분석이 쓰는 snapshotFor와 같은 걸러내기)
     *
     * @return array{enable: list<string>, disable: list<string>}|null
     */
    public function activeSet(string $tool): ?array
    {
        $v = $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
        $s = $v === null ? null : $this->snapshotFor($tool, (string) $v, false);
        return $s === null || ($s['enable'] === [] && $s['disable'] === []) ? null : ['enable' => $s['enable'], 'disable' => $s['disable']];
    }

    /**
     * 일반 사용자에게 보여 줄 상태(변경자·사유 없음). admin이면 그대로.
     *
     * @param array<string, mixed> $s state()
     * @return array<string, mixed>
     */
    public static function publicState(array $s, bool $admin): array
    {
        if (!$admin) {
            $s['override'] = null;
        }
        return $s;
    }

    /** 규칙 하나 쓰기(단일·일괄 공통): 기본값과 같으면 행 삭제, 다르면 넣거나 갱신 */
    private function write(string $tool, string $rule, bool $enabled, bool $base, ?string $reason, string $version, int $userId, string $now): void
    {
        if ($enabled === $base) {
            $this->db->execute('DELETE FROM rule_overrides WHERE tool = ? AND rule = ?', [$tool, $rule]);
            return;
        }
        $this->db->execute(
            'INSERT INTO rule_overrides (tool, rule, enabled, base_enabled, reason, tool_version, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON CONFLICT (tool, rule) DO UPDATE SET enabled = excluded.enabled, base_enabled = excluded.base_enabled, reason = excluded.reason,'
            . ' tool_version = excluded.tool_version, changed_by = excluded.changed_by, changed_at = excluded.changed_at',
            [$tool, $rule, $enabled ? 1 : 0, $base ? 1 : 0, $reason, $version, $userId, $now],
        );
    }

    // ---- P22C 일괄 변경 ----

    /**
     * 키 목록 → 활성 버전 규칙 행(없는 키·특수 키는 따로). 키 글자는 단일 변경과 같은 검사(틀리면 400).
     *
     * @param list<mixed> $keys
     * @return array{0: list<array<string, mixed>>, 1: int} [행(없는 키는 missing·special 표시), 대상 수]
     */
    public function rowsForKeys(string $tool, array $keys): array
    {
        $clean = [];
        foreach ($keys as $k) {
            if (!is_string($k) || !RuleController::validRule($k)) {
                throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'keys']);
            }
            $clean[$k] = true;
        }
        $clean = array_keys($clean);
        $found = [];
        foreach ($this->db->all(
            'SELECT r.rule, r.name, r.status, r.default_enabled, o.enabled AS o_enabled,'
            . ' (SELECT group_concat(b.reason) FROM rule_enable_blocks b WHERE b.tool = r.tool AND b.version = r.version AND b.rule = r.rule) AS blocks'
            . ' FROM rules r JOIN rule_versions v ON v.tool = r.tool AND v.version = r.version AND v.is_active = 1'
            . ' LEFT JOIN rule_overrides o ON o.tool = r.tool AND o.rule = r.rule'
            . ' WHERE r.tool = ? AND r.rule IN (SELECT value FROM json_each(?))',
            [$tool, json_encode($clean)],
        ) as $r) {
            $found[(string) $r['rule']] = $r;
        }
        $rows = [];
        foreach ($clean as $k) {
            if (in_array($tool . ':' . $k, RuleQuery::SPECIAL, true)) {
                $rows[] = ['rule' => $k, 'skip' => 'special_key'];
            } elseif (!isset($found[$k])) {
                $rows[] = ['rule' => $k, 'skip' => 'missing'];
            } else {
                $rows[] = $found[$k];
            }
        }
        return [$rows, count($clean)];
    }

    /**
     * 대상 행 → 계획(바뀔 것·그대로·건너뜀). 행: rule, name, status, default_enabled, o_enabled, blocks (또는 skip = 이유)
     *
     * @param list<array<string, mixed>> $rows
     * @return array{changes: list<array{rule:string, name:string, from:bool, to:bool, base:bool}>, unchanged: int, skipped: array<string, int>}
     */
    public static function plan(string $action, array $rows): array
    {
        $out = ['changes' => [], 'unchanged' => 0, 'skipped' => array_fill_keys(self::SKIP_REASONS, 0)];
        foreach ($rows as $r) {
            if (isset($r['skip'])) {
                $out['skipped'][$r['skip']]++;
                continue;
            }
            $base = (bool) $r['default_enabled'];
            $overridden = $r['o_enabled'] !== null;
            $current = $overridden ? (bool) $r['o_enabled'] : $base;
            $block = self::blockReason((string) $r['status'], $r['blocks'] === null ? [] : explode(',', (string) $r['blocks']));
            $d = self::decide($action, $current, $base, $overridden, $block);
            if ($d['kind'] === 'unchanged') {
                $out['unchanged']++;
            } elseif ($d['kind'] === 'skip') {
                $out['skipped'][(string) $d['why']]++;
            } else {
                $out['changes'][] = ['rule' => (string) $r['rule'], 'name' => (string) ($r['name'] ?? ''), 'from' => $current, 'to' => (bool) $d['to'], 'base' => $base];
            }
        }
        return $out;
    }

    /**
     * 미리보기(쓰지 않음). $resolve() → [행, 대상 수]. 대상이 bulk_max를 넘으면 400 bulk_too_large.
     *
     * @param callable(): array{0: list<array<string, mixed>>, 1: int} $resolve
     * @return array<string, mixed>
     */
    public function bulkPreview(string $tool, string $action, callable $resolve): array
    {
        $this->assertBulk($tool, $action);
        [$rows, $total] = $resolve();
        $this->assertSize($total);
        return $this->summarize($tool, $action, self::plan($action, $rows), $total) + ['revision' => $this->revision($tool)];
    }

    /**
     * 적용: 한 트랜잭션. 개정 번호가 미리보기 때(expected)와 다르면 409, 대상은 트랜잭션 안에서 다시 정한다. 개정 번호는 바뀐 것이 있을 때 한 번만 +1.
     *
     * @param array<string, mixed> $user
     * @param callable(): array{0: list<array<string, mixed>>, 1: int} $resolve
     * @param array<string, mixed> $targetSummary 감사 로그에 남길 대상 방식·조건 요약
     * @return array<string, mixed>
     */
    public function bulkApply(array $user, ?string $ip, string $tool, string $action, callable $resolve, array $targetSummary, ?string $reason, int $expectedRevision): array
    {
        $this->assertBulk($tool, $action);
        $reason = self::cleanReason($reason);
        return $this->db->transaction(function () use ($user, $ip, $tool, $action, $resolve, $targetSummary, $reason, $expectedRevision): array {
            $before = $this->revision($tool);
            if ($before !== $expectedRevision) {
                throw new ApiException(409, 'rule_override_conflict', [], 'error.rule_override_revision_changed', [], [], ['reason' => 'revision_changed', 'revision' => $before]);
            }
            [$rows, $total] = $resolve();
            $this->assertSize($total);
            $plan = self::plan($action, $rows);
            $version = (string) $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
            $now = SystemClock::iso($this->clock->now());
            foreach ($plan['changes'] as $c) {
                $this->write($tool, $c['rule'], $c['to'], $c['base'], $reason, $version, (int) $user['id'], $now);
            }
            $after = $before;
            if ($plan['changes'] !== []) {
                $this->bump($tool, $now);
                $after = $this->revision($tool);
                $keys = array_column($plan['changes'], 'rule');
                $this->audit->record(Actor::fromUser($user, $ip), 'rules.override_bulk', 'rule', $tool, [
                    'tool' => $tool, 'action' => $action, 'target' => $targetSummary, 'total' => $total,
                    'changed' => count($keys), 'unchanged' => $plan['unchanged'], 'skipped' => array_filter($plan['skipped']),
                    'revision' => ['from' => $before, 'to' => $after], 'reason' => $reason,
                    'rules' => array_slice($keys, 0, self::AUDIT_KEYS), 'rules_more' => max(0, count($keys) - self::AUDIT_KEYS),
                ]);
            }
            return $this->summarize($tool, $action, $plan, $total, false) + ['revision' => $after];
        });
    }

    /** @return array<string, mixed> 미리보기·적용 결과(건수, 예시 10개, 바뀔 규칙의 서버 전체 열린 결함 수) */
    private function summarize(string $tool, string $action, array $plan, int $total, bool $withDefects = true): array
    {
        $changes = $plan['changes'];
        $count = static fn (bool $to): int => count(array_filter($changes, static fn (array $c): bool => $c['to'] === $to));
        $out = [
            'tool' => $tool, 'action' => $action, 'total' => $total, 'max' => $this->bulkMax,
            'changes' => count($changes),
            'by_action' => $action === 'reset' ? ['reset' => count($changes)] : [$action => count($changes)],
            'turn_on' => $count(true), 'turn_off' => $count(false),
            'unchanged' => $plan['unchanged'], 'skipped' => $plan['skipped'], 'skipped_total' => array_sum($plan['skipped']),
            'examples' => array_map(static fn (array $c): array => ['rule' => $c['rule'], 'key' => $tool . ':' . $c['rule'], 'name' => $c['name'], 'from' => $c['from'], 'to' => $c['to']],
                array_slice($changes, 0, self::EXAMPLES)),
        ];
        if ($withDefects) {
            $keys = array_map(static fn (array $c): string => $tool . ':' . $c['rule'], $changes);
            $out['open_defects'] = $keys === [] ? 0 : (int) $this->db->value(
                "SELECT COUNT(*) FROM defects WHERE rule_key IN (SELECT value FROM json_each(?)) AND status <> 'resolved'", [json_encode($keys)]);
        }
        return $out;
    }

    private function assertBulk(string $tool, string $action): void
    {
        if (!in_array($tool, self::TOOLS, true)) {
            throw new ApiException(404, 'rule_not_found');
        }
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'action']);
        }
    }

    private function assertSize(int $total): void
    {
        if ($total > $this->bulkMax) {
            throw new ApiException(400, 'bulk_too_large', ['max' => $this->bulkMax, 'total' => $total], 'error.rule_bulk_too_large', [], [], ['total' => $total, 'max' => $this->bulkMax]);
        }
    }

    private function bump(string $tool, string $now): void
    {
        $this->db->execute('UPDATE rule_override_state SET revision = revision + 1, updated_at = ? WHERE tool = ?', [$now, $tool]);
    }

    private function assertKey(string $tool, string $rule): void
    {
        if (!in_array($tool, self::TOOLS, true) || !RuleController::validRule($rule)) {
            throw new ApiException(404, 'rule_not_found');
        }
        if (in_array($tool . ':' . $rule, RuleQuery::SPECIAL, true)) {
            throw new ApiException(422, 'rule_not_enableable', ['why' => Lang::get('rules.block.special_key')], null, [], [], ['reason' => 'special_key']);
        }
    }

    private static function cleanReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        // 제어 문자(줄바꿈 등)는 공백으로, 연속 공백은 하나로(한 줄 사유)
        $reason = trim(preg_replace('/ {2,}/', ' ', preg_replace('/[\x00-\x1F\x7F]+/', ' ', $reason) ?? '') ?? '');
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > self::REASON_MAX || !mb_check_encoding($reason, 'UTF-8')) {
            throw new ApiException(400, 'invalid_input', ['field' => 'reason'], null, [], [], ['field' => 'reason']);
        }
        return $reason;
    }
}
