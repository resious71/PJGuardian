<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Admin\AdminTasks;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Db;
use PJGuardian\Support\RuleLinks;
use PJGuardian\Support\ToolLanguage;

/**
 * 규칙 설명 조회(P22). 기준은 **활성 버전**(rule_versions.is_active — 서버가 분석에 쓰는 버전을 적재할 때 정함).
 * 결함 건수·프로젝트 이름은 사용자가 볼 수 있는 프로젝트(ProjectAccess::scopeFor)만. 목록에서는 결함을 세지 않는다.
 * P22B: "분석 적용"(analysis_enabled = 관리자 변경이 있으면 그 값, 없으면 기본 활성), overridden, enableable(+ enable_block 이유)을 더한다.
 * 변경자·사유는 상세의 admin 응답에만(analysis.override). 016 전 DB(표 없음)면 변경 없음으로 본다.
 * P22C: 규칙 키 접두사 조건(prefix — 키가 그 값으로 시작, ruff는 대문자·ESLint는 소문자로 맞춰 비교), 일괄 변경이 같은 조건으로 다시 조회하는 matching().
 */
final class RuleQuery
{
    public const TOOLS = ['ruff', 'eslint'];
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'not_defined'];
    public const SORTS = ['rule', 'name', 'severity', 'tool'];
    public const CHANGES = ['new', 'changed', 'unchanged'];
    /** 도구 규칙이 아니라 PJGuardian이 붙이는 특수 키(결함 rule_key 그대로) */
    public const SPECIAL = ['syntax-error', 'eslint:unused-disable-directive', 'eslint:no-rule-id'];
    private const Q_MAX = 100;
    /** 규칙 키 접두사: 키에 쓰는 글자만, 64자 */
    public const PREFIX_PATTERN = '#^[A-Za-z0-9@/_.-]{1,64}$#';

    public function __construct(private readonly Db $db, private readonly ProjectAccess $access, private readonly AdminTasks $tasks, private readonly ?RuleEnrichment $enrichment = null)
    {
    }

    /** 쉬운 설명(P32B): 실제 도구로 확인한 것만, 화면 언어로. 보강 자료가 깨졌어도 상세는 그린다(null) */
    public function guide(string $tool, string $rule): ?array
    {
        try {
            return $this->enrichment?->guide($tool, $rule, \PJGuardian\Support\Lang::locale());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 검색 조건 검증(틀린 값은 400 invalid_rule_filter). 빈 값은 조건 없음.
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public static function filter(array $q): array
    {
        $bad = static fn (string $field) => new ApiException(400, 'invalid_rule_filter', ['field' => $field], null, [], [], ['field' => $field]);
        $str = static fn (string $k): string => is_string($q[$k] ?? null) ? trim((string) $q[$k]) : '';
        $f = ['q' => $str('q'), 'tool' => $str('tool'), 'category' => $str('category'), 'severity' => [], 'default_enabled' => $str('default_enabled'),
            'fixable' => $str('fixable'), 'deprecated' => $str('deprecated'), 'change' => $str('change'), 'sort' => $str('sort') ?: 'rule', 'order' => $str('order') ?: 'asc',
            'analysis' => $str('analysis'), 'overridden' => $str('overridden'), 'prefix' => $str('prefix')];
        if (isset($q['prefix']) && !is_string($q['prefix']) || ($f['prefix'] !== '' && preg_match(self::PREFIX_PATTERN, $f['prefix']) !== 1)) {
            throw $bad('prefix');
        }
        if (isset($q['q']) && !is_string($q['q']) || mb_strlen($f['q']) > self::Q_MAX) {
            throw $bad('q');
        }
        if ($f['tool'] !== '' && !in_array($f['tool'], self::TOOLS, true)) {
            throw $bad('tool');
        }
        // X08: 언어 탭(language=python|javascript)은 도구 조건과 같다. 둘 다 오면 일치해야 한다
        $language = $str('language');
        if ($language !== '') {
            $tool = ToolLanguage::tool($language) ?? throw $bad('language');
            if ($f['tool'] !== '' && $f['tool'] !== $tool) {
                throw $bad('language');
            }
            $f['tool'] = $tool;
        }
        if (mb_strlen($f['category']) > 60) {
            throw $bad('category');
        }
        if ($str('severity') !== '') {
            $f['severity'] = array_values(array_unique(explode(',', $str('severity'))));
            if (array_diff($f['severity'], self::SEVERITIES) !== []) {
                throw $bad('severity');
            }
        }
        foreach (['default_enabled', 'fixable', 'deprecated', 'analysis'] as $k) {
            if (!in_array($f[$k], ['', '1', '0'], true)) {
                throw $bad($k);
            }
        }
        if (!in_array($f['overridden'], ['', '1'], true)) {
            throw $bad('overridden');
        }
        if ($f['change'] !== '' && !in_array($f['change'], self::CHANGES, true)) {
            throw $bad('change');
        }
        if (!in_array($f['sort'], self::SORTS, true) || !in_array($f['order'], ['asc', 'desc'], true)) {
            throw $bad('sort');
        }
        return $f;
    }

    /**
     * @param array<string, mixed> $f filter()
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(array $f, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($f);
        $join = $this->overrideJoin();
        $order = match ($f['sort']) {
            'name' => 'r.name',
            'severity' => "CASE r.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END",
            'tool' => 'r.tool',
            default => 'r.rule',
        };
        $dir = $f['order'] === 'desc' ? 'DESC' : 'ASC';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM rules r JOIN rule_versions v ON v.tool = r.tool AND v.version = r.version AND v.is_active = 1 {$join['from']} WHERE {$where}", $params);
        $rows = $this->db->all(
            'SELECT r.tool, r.version, r.rule, r.name, r.summary, r.category, r.origin, r.default_enabled, r.fixable, r.status, r.severity, r.severity_level, r.docs_url,'
            . ' (SELECT group_concat(DISTINCT c.kind) FROM rule_changes c WHERE c.tool = r.tool AND c.to_version = r.version AND c.rule = r.rule) AS changes,'
            . " {$join['select']}"
            . " FROM rules r JOIN rule_versions v ON v.tool = r.tool AND v.version = r.version AND v.is_active = 1 {$join['from']} WHERE {$where}"
            . " ORDER BY {$order} {$dir}, r.tool, r.rule LIMIT CAST(? AS INTEGER) OFFSET CAST(? AS INTEGER)",
            [...$params, $limit, $offset],
        );
        return [array_map(fn (array $r): array => $this->item($r), $rows), $total];
    }

    private ?bool $hasOverrides = null;

    /** 016(관리자 규칙 변경)이 적용된 DB인지 */
    private function hasOverrides(): bool
    {
        return $this->hasOverrides ??= $this->db->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'rule_overrides'") !== null;
    }

    /** @return array{from:string, select:string} 관리자 변경·켤 수 없는 이유를 붙이는 조인(016 전이면 빈 값) */
    private function overrideJoin(): array
    {
        if (!$this->hasOverrides()) {
            return ['from' => '', 'select' => 'NULL AS o_enabled, NULL AS blocks'];
        }
        return ['from' => 'LEFT JOIN rule_overrides o ON o.tool = r.tool AND o.rule = r.rule',
            'select' => 'o.enabled AS o_enabled, (SELECT group_concat(b.reason) FROM rule_enable_blocks b WHERE b.tool = r.tool AND b.version = r.version AND b.rule = r.rule) AS blocks'];
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function where(array $f): array
    {
        $w = ['1 = 1'];
        $p = [];
        if ($f['tool'] !== '') {
            $w[] = 'r.tool = ?';
            $p[] = $f['tool'];
        }
        if ($f['category'] !== '') {
            $w[] = 'r.category = ?';
            $p[] = $f['category'];
        }
        if ($f['severity'] !== []) {
            $w[] = 'r.severity IN (SELECT value FROM json_each(?))';
            $p[] = json_encode($f['severity']);
        }
        if ($f['default_enabled'] !== '') {
            $w[] = 'r.default_enabled = CAST(? AS INTEGER)';
            $p[] = $f['default_enabled'];
        }
        if ($f['fixable'] !== '') {
            $w[] = $f['fixable'] === '1' ? "r.fixable <> 'none'" : "r.fixable = 'none'";
        }
        // P22C: 규칙 키 접두사(키가 그 값으로 시작). 키 표기가 ruff 대문자·ESLint 소문자로 정해져 있어 도구별로 맞춰 비교한다
        if ($f['prefix'] !== '') {
            $n = strlen($f['prefix']);
            $w[] = "((r.tool = 'ruff' AND substr(r.rule, 1, CAST(? AS INTEGER)) = ?) OR (r.tool = 'eslint' AND substr(r.rule, 1, CAST(? AS INTEGER)) = ?))";
            array_push($p, $n, strtoupper($f['prefix']), $n, strtolower($f['prefix']));
        }
        // P22B: 분석 적용(관리자 변경 반영)·관리자 변경 여부
        $has = $this->hasOverrides();
        if ($f['analysis'] !== '') {
            $w[] = ($has ? 'COALESCE(o.enabled, r.default_enabled)' : 'r.default_enabled') . ' = CAST(? AS INTEGER)';
            $p[] = $f['analysis'];
        }
        if ($f['overridden'] === '1') {
            $w[] = $has ? 'o.rule IS NOT NULL' : '0 = 1';
        }
        if ($f['deprecated'] !== '') {
            $w[] = $f['deprecated'] === '1' ? "r.status IN ('deprecated', 'removed')" : "r.status NOT IN ('deprecated', 'removed')";
        }
        if ($f['change'] !== '') {
            $exists = "EXISTS (SELECT 1 FROM rule_changes c WHERE c.tool = r.tool AND c.to_version = r.version AND c.rule = r.rule%s)";
            $w[] = match ($f['change']) {
                'new' => sprintf($exists, " AND c.kind IN ('added', 'renamed')"),
                'changed' => sprintf($exists, " AND c.kind NOT IN ('added', 'renamed', 'removed')"),
                default => 'NOT ' . sprintf($exists, ''),
            };
        }
        if ($f['q'] !== '') {
            if (mb_strlen($f['q']) >= 3) {
                // FTS5 trigram(부분 일치·대소문자 무시). 입력은 따옴표로 감싼 구절 하나(따옴표는 두 번) — 색인 구문으로 해석되지 않는다
                $w[] = 'r.id IN (SELECT rowid FROM rules_fts WHERE rules_fts MATCH ?)';
                $p[] = '"' . str_replace('"', '""', $f['q']) . '"';
            } else {
                // 3글자 미만은 trigram이 못 찾으므로 키·이름·요약의 LIKE(와일드카드 이스케이프)
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($f['q'])) . '%';
                $w[] = "(lower(r.rule) LIKE ? ESCAPE '\\' OR lower(r.name) LIKE ? ESCAPE '\\' OR lower(r.summary) LIKE ? ESCAPE '\\')";
                array_push($p, $like, $like, $like);
            }
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array<string, mixed> 목록 행(설명 본문 없음) */
    private function item(array $r): array
    {
        $changes = $r['changes'] === null ? [] : explode(',', (string) $r['changes']);
        $key = $r['tool'] . ':' . $r['rule'];
        return [
            'tool' => (string) $r['tool'], 'rule' => (string) $r['rule'], 'key' => $key, 'name' => (string) $r['name'],
            'summary' => (string) $r['summary'], 'category' => $r['category'], 'origin' => (string) $r['origin'],
            'default_enabled' => (bool) $r['default_enabled'], 'fixable' => (string) $r['fixable'], 'status' => (string) $r['status'],
            'severity' => (string) $r['severity'], 'severity_level' => (string) $r['severity_level'],
            ...self::analysisFields($r),
            'change' => array_intersect(['added', 'renamed'], $changes) !== [] ? 'new' : ($changes !== [] ? 'changed' : null),
            // P23: 기준 도구 버전·공식 문서 주소(규칙 목록 내보내기 열 — 목록 API에도 그대로 실린다)
            'version' => (string) $r['version'], 'docs_url' => $r['docs_url'],
            'href' => RuleLinks::target($key),
        ];
    }

    /**
     * 분석 적용 필드(P22B): analysis_enabled, overridden, enableable, enable_block
     *
     * @param array<string, mixed> $r default_enabled, status, o_enabled, blocks
     * @return array<string, mixed>
     */
    private static function analysisFields(array $r): array
    {
        $block = RuleOverrides::blockReason((string) $r['status'], ($r['blocks'] ?? null) === null ? [] : explode(',', (string) $r['blocks']));
        $o = $r['o_enabled'] ?? null;
        return ['analysis_enabled' => $o === null ? (bool) $r['default_enabled'] : (bool) $o, 'overridden' => $o !== null,
            'enableable' => $block === null, 'enable_block' => $block];
    }

    /**
     * 일괄 변경 대상(P22C): 같은 조건(filter())의 활성 버전 규칙 전체(쪽 나누기 없음). $limit + 1개까지만 읽고 전체 수를 함께 준다(상한 판정).
     *
     * @param array<string, mixed> $f filter()
     * @return array{0: list<array{rule:string, name:string, status:string, default_enabled:int, o_enabled:?int, blocks:?string}>, 1: int}
     */
    public function matching(array $f, int $limit): array
    {
        [$where, $params] = $this->where($f);
        $join = $this->overrideJoin();
        $from = "FROM rules r JOIN rule_versions v ON v.tool = r.tool AND v.version = r.version AND v.is_active = 1 {$join['from']} WHERE {$where}";
        $total = (int) $this->db->value("SELECT COUNT(*) {$from}", $params);
        $rows = $this->db->all("SELECT r.rule, r.name, r.status, r.default_enabled, {$join['select']} {$from} ORDER BY r.rule LIMIT CAST(? AS INTEGER)", [...$params, $limit + 1]);
        return [$rows, $total];
    }

    /** @return array<string, mixed> 기준 버전·적재 상태·카테고리 */
    public function meta(?array $registryActive = null): array
    {
        $tools = [];
        foreach (self::TOOLS as $tool) {
            $v = $this->db->one('SELECT version, components, baseline, rule_count, severity_map_version, loaded_at FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
            $cats = $v === null ? [] : array_column($this->db->all('SELECT DISTINCT category FROM rules WHERE tool = ? AND version = ? AND category IS NOT NULL ORDER BY category', [$tool, $v['version']]), 'category');
            $tools[$tool] = [
                'version' => $v['version'] ?? null, 'components' => $v === null ? (object) [] : json_decode((string) $v['components'], true),
                'baseline' => $v === null ? null : (bool) $v['baseline'], 'rules' => (int) ($v['rule_count'] ?? 0), 'loaded_at' => $v['loaded_at'] ?? null,
                'versions' => array_column($this->db->all('SELECT version FROM rule_versions WHERE tool = ?', [$tool]), 'version'),
                'categories' => $cats,
                'registry_version' => $registryActive[$tool] ?? null,
                'stale' => $registryActive !== null && ($registryActive[$tool] ?? null) !== null && ($registryActive[$tool] ?? null) !== ($v['version'] ?? null),
            ];
            usort($tools[$tool]['versions'], static fn (string $a, string $b): int => version_compare($b, $a));
        }
        $task = $this->tasks->active('rules_load', '') ?? $this->tasks->latest('rules_load', '');
        return ['tools' => $tools, 'task' => $task];
    }

    /**
     * 규칙 상세. 활성 버전에 없으면 마지막으로 있던 버전의 정보와 그 뒤의 변경(삭제·이름 변경)을 안내용으로 돌려준다(missing = true).
     * 특수 키(문법 오류 등)는 special = true. 한 번도 적재된 적 없는 키는 404 rule_not_found.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function detail(array $user, string $tool, string $rule): array
    {
        $key = $tool === 'special' ? $rule : $tool . ':' . $rule;
        if (in_array($key, self::SPECIAL, true)) {
            return ['special' => true, 'key' => $key, 'tool' => $tool === 'special' ? null : $tool, 'rule' => $rule, 'defects' => $this->defects($user, $key)];
        }
        if (!in_array($tool, self::TOOLS, true) || $rule === '' || mb_strlen($rule) > 190) {
            throw new ApiException(404, 'rule_not_found');
        }
        $active = $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
        $rows = $this->db->all('SELECT * FROM rules WHERE tool = ? AND rule = ?', [$tool, $rule]);
        if ($rows === []) {
            throw new ApiException(404, 'rule_not_found');
        }
        usort($rows, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
        $current = null;
        foreach ($rows as $r) {
            if ($r['version'] === $active) {
                $current = $r;
            }
        }
        $shown = $current ?? $rows[0];
        $history = $this->db->all('SELECT from_version, to_version, kind, before, after, rule FROM rule_changes WHERE tool = ? AND (rule = ? OR (kind = \'renamed\' AND before = ?))',
            [$tool, $rule, $rule]);
        usort($history, static fn (array $a, array $b): int => version_compare((string) $b['to_version'], (string) $a['to_version']) ?: strcmp((string) $a['kind'], (string) $b['kind']));
        $baselineVersion = $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND baseline = 1', [$tool]);
        $fate = null;
        if ($current === null) {
            foreach ($history as $h) {
                if ($h['kind'] === 'renamed' && $h['before'] === $rule) {
                    $fate = ['kind' => 'renamed', 'to' => $h['after'], 'version' => $h['to_version'], 'href' => RuleLinks::target($tool . ':' . $h['after'])];
                    break;
                }
                if ($h['kind'] === 'removed' && $h['rule'] === $rule) {
                    $fate = ['kind' => 'removed', 'version' => $h['to_version']];
                    break;
                }
            }
        }
        $dep = json_decode((string) ($shown['deprecation'] ?? ''), true);
        return [
            'special' => false, 'key' => $key, 'tool' => $tool, 'rule' => $rule,
            'active_version' => $active, 'version' => (string) $shown['version'], 'missing' => $current === null, 'fate' => $fate,
            'name' => (string) $shown['name'], 'summary' => (string) $shown['summary'], 'description' => (string) $shown['description'],
            'category' => $shown['category'], 'origin' => (string) $shown['origin'], 'default_enabled' => (bool) $shown['default_enabled'],
            'fixable' => (string) $shown['fixable'], 'has_suggestions' => (bool) $shown['has_suggestions'],
            'has_options' => $shown['has_options'] === null ? null : (bool) $shown['has_options'], 'status' => (string) $shown['status'],
            'deprecation' => is_array($dep) ? [
                'message' => $dep['message'] ?? null, 'since' => $dep['since'] ?? null,
                'replaced_by' => array_map(fn (string $x): array => ['rule' => $x, 'href' => RuleLinks::target($tool . ':' . $x)], array_values(array_filter((array) ($dep['replaced_by'] ?? []), 'is_string'))),
            ] : null,
            'docs_url' => $shown['docs_url'],
            'severity' => ['value' => (string) $shown['severity'], 'level' => (string) $shown['severity_level'], 'reason' => (string) $shown['severity_reason'],
                'group' => $shown['category']],
            'source' => $shown['enrichment'] === null ? ['kind' => 'tool'] : ['kind' => 'enrichment', 'urls' => json_decode((string) $shown['enrichment'], true)],
            'guide' => $this->guide($tool, $rule),
            'versions' => array_map(static fn (array $r): string => (string) $r['version'], $rows),
            'baseline_version' => $baselineVersion,
            'history' => array_map(static fn (array $h): array => ['from' => $h['from_version'], 'to' => $h['to_version'], 'kind' => $h['kind'], 'before' => $h['before'], 'after' => $h['after']], $history),
            'defects' => $this->defects($user, $key),
            'analysis' => $current === null ? null : $this->analysis($user, $tool, $current),
        ];
    }

    /**
     * 상세의 "분석 적용"(P22B). 적용 범위: ESLint = 모든 프로젝트, ruff = 서버 기본 설정을 쓰는 프로젝트만. 변경자·사유는 admin에게만.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row 활성 버전 rules 행
     * @return array<string, mixed>
     */
    private function analysis(array $user, string $tool, array $row): array
    {
        $o = null;
        $blocks = [];
        if ($this->hasOverrides()) {
            $o = $this->db->one('SELECT o.enabled, o.base_enabled, o.reason, o.changed_at, o.tool_version, u.display_name AS changed_by_name'
                . ' FROM rule_overrides o LEFT JOIN users u ON u.id = o.changed_by WHERE o.tool = ? AND o.rule = ?', [$tool, $row['rule']]);
            $blocks = array_column($this->db->all('SELECT reason FROM rule_enable_blocks WHERE tool = ? AND version = ? AND rule = ?', [$tool, $row['version'], $row['rule']]), 'reason');
        }
        $f = self::analysisFields(['default_enabled' => $row['default_enabled'], 'status' => $row['status'], 'o_enabled' => $o['enabled'] ?? null, 'blocks' => $blocks === [] ? null : implode(',', $blocks)]);
        $admin = ($user['role'] ?? '') === 'admin';
        return [
            'enabled' => $f['analysis_enabled'], 'base_enabled' => (bool) $row['default_enabled'], 'overridden' => $f['overridden'],
            'enableable' => $f['enableable'], 'block' => $f['enable_block'], 'scope' => $tool === 'eslint' ? 'all' : 'server_default',
            'revision' => $this->hasOverrides() ? (int) ($this->db->value('SELECT revision FROM rule_override_state WHERE tool = ?', [$tool]) ?? 0) : 0,
            'override' => $admin && $o !== null ? ['reason' => $o['reason'], 'changed_at' => $o['changed_at'], 'changed_by_name' => $o['changed_by_name'], 'tool_version' => $o['tool_version']] : null,
        ];
    }

    /**
     * 이 규칙의 열린 결함(볼 수 있는 프로젝트만, 프로젝트별). 프로젝트 목록 링크는 결함 목록의 rule= 필터 주소.
     *
     * @param array<string, mixed> $user
     * @return array{total:int, projects:list<array{id:int, name:string, count:int, href:string}>}
     */
    private function defects(array $user, string $key): array
    {
        [$scope, $params] = $this->access->scopeFor($user, 'p');
        $rows = $this->db->all(
            "SELECT p.id, p.name, (SELECT COUNT(*) FROM defects d WHERE d.project_id = p.id AND d.rule_key = ? AND d.status <> 'resolved') AS n"
            . " FROM projects p WHERE {$scope} ORDER BY p.name, p.id",
            [$key, ...$params],
        );
        $out = [];
        $total = 0;
        foreach ($rows as $r) {
            if ((int) $r['n'] > 0) {
                $total += (int) $r['n'];
                $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'],
                    'href' => '/projects/' . (int) $r['id'] . '?' . http_build_query(['rule' => $key], '', '&', PHP_QUERY_RFC3986)];
            }
        }
        return ['total' => $total, 'projects' => $out];
    }

    /**
     * 결함 화면의 규칙 표시(P22): 키 → 'missing'(그 도구의 활성 버전이 적재돼 있는데 그 규칙이 없음) 또는 없음(있음·판정 불가).
     *
     * @param list<string> $keys 결함 rule_key
     * @return array<string, string>
     */
    public function missingInActive(array $keys): array
    {
        return self::missingIn($this->db, $keys);
    }

    /** @param list<string> $keys @return array<string, string> 결함 조회(DefectQuery)도 같은 판정을 쓴다. 015 전 DB(표 없음)면 빈 배열 */
    public static function missingIn(Db $db, array $keys): array
    {
        try {
            return self::missingQuery($db, $keys);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table')) {
                return [];
            }
            throw $e;
        }
    }

    /** @param list<string> $keys @return array<string, string> */
    private static function missingQuery(Db $db, array $keys): array
    {
        $by = [];
        foreach (array_unique($keys) as $k) {
            if (preg_match('/^(ruff|eslint):(.+)$/', $k, $m) === 1 && !in_array($k, self::SPECIAL, true)) {
                $by[$m[1]][] = $m[2];
            }
        }
        $out = [];
        foreach ($by as $tool => $rules) {
            $active = $db->value('SELECT version FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
            if ($active === null) {
                continue; // 적재 전: 판정하지 않는다
            }
            $have = array_flip(array_column($db->all('SELECT rule FROM rules WHERE tool = ? AND version = ? AND rule IN (SELECT value FROM json_each(?))',
                [$tool, $active, json_encode(array_values($rules))]), 'rule'));
            foreach ($rules as $r) {
                if (!isset($have[$r])) {
                    $out[$tool . ':' . $r] = 'missing';
                }
            }
        }
        return $out;
    }
}
