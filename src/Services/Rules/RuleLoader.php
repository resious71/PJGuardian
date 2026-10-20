<?php

declare(strict_types=1);

namespace PJGuardian\Services\Rules;

use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Severity\SeverityMap;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 규칙 데이터 적재(P22, 명세 11장). docs/rules.md.
 *
 * - 대상 버전: 활성 버전(ToolRegistry::select — 분석이 쓰는 버전, 고정·차단 반영) + 아직 적재하지 않은 bin/의 설치 버전(변경 내역용).
 *   --version으로 하나만, --all-installed로 설치된 모든 버전을 다시 볼 수도 있다(tools/rules_load.php).
 * - 버전마다 내용 해시(규칙 + 구성 요소 버전 + 심각도 매핑 버전)가 같으면 건드리지 않는다(멱등). 다르면 그 버전의 규칙을 통째로 바꾼다.
 * - 적재 뒤 도구별로 인접한 두 버전(semver 순)의 변경 내역을 다시 계산하고(가장 오래된 버전 = 기준 버전), 검색 색인을 다시 만든다.
 * - 기본 심각도는 P21S 매핑(SeverityMap::explain)으로 적재 때 정해 저장한다. 매핑 버전이 바뀌면 그 버전을 다시 적재한다.
 * - 보강 자료(resources/rules/enrichment/<도구>.json)는 도구가 비워 둔 항목만 채운다(출처 필수, RuleEnrichment).
 * - P22B: 켤 수 없는 규칙(rule_enable_blocks — 타입 정보 필요, 옵션 없이는 검사 안 함)은 내용 해시와 관계없이 적재할 때마다 버전별로 다시 쓴다
 *   (규칙 내용 해시·변경 내역에는 넣지 않는다 — 016을 나중에 적용해도 다음 적재에서 채워진다).
 */
final class RuleLoader
{
    public function __construct(
        private readonly Db $db,
        private readonly RuleSource $extractor,
        private readonly ToolRegistry $registry,
        private readonly SeverityMap $severity,
        private readonly RuleEnrichment $enrichment,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly string $requiresOptionsFile = '',
    ) {
    }

    /** 활성 버전(분석이 쓰는 버전). 없으면 null */
    public function activeVersion(string $tool): ?string
    {
        return $this->registry->select($tool)?->version;
    }

    /**
     * @param 'default'|'all' $mode default = 활성 + 아직 적재하지 않은 설치 버전, all = 설치된 모든 버전을 다시 확인
     * @param list<string>|null $only 이 버전들만(도구별, 미리 검증)
     * @return array{tools: array<string, array<string, mixed>>, changed: bool, errors: list<string>}
     */
    public function load(string $mode = 'default', ?array $onlyTools = null, ?string $onlyVersion = null, bool $apply = true): array
    {
        $report = ['tools' => [], 'changed' => false, 'errors' => []];
        foreach ($onlyTools ?? SeverityMap::TOOLS as $tool) {
            $active = $this->activeVersion($tool);
            if ($onlyVersion !== null) {
                $versions = [$onlyVersion];
            } else {
                $loaded = array_column($this->db->all('SELECT version, severity_map_version FROM rule_versions WHERE tool = ?', [$tool]), null, 'version');
                $installed = $this->registry->versionsOnDisk($tool);
                $versions = $mode === 'all' ? $installed : array_values(array_filter($installed, fn (string $v): bool => $v === $active || !isset($loaded[$v])
                    || (int) $loaded[$v]['severity_map_version'] !== $this->severity->version($tool)));
            }
            $this->loadVersions($tool, $versions, $active, $apply, $report);
        }
        if ($apply && $report['changed']) {
            $this->rebuildIndex();
        }
        return $report;
    }

    /**
     * 도구 하나의 정해진 버전들을 적재하고 활성 버전을 표시, 변경 내역을 다시 계산한다(load()와 시험이 함께 쓴다). 색인은 rebuildIndex().
     *
     * @param list<string> $versions
     * @param array{tools: array<string, mixed>, changed: bool, errors: list<string>} $report
     */
    public function loadVersions(string $tool, array $versions, ?string $active, bool $apply, array &$report): void
    {
        $loaded = array_column($this->db->all('SELECT version, content_hash FROM rule_versions WHERE tool = ?', [$tool]), 'content_hash', 'version');
        $t = ['active' => $active, 'versions' => []];
        foreach ($versions as $v) {
            $start = hrtime(true);
            try {
                $data = $this->extractor->extract($tool, $v);
            } catch (RuleLoadException | \JsonException $e) {
                // 한 도구·버전이 실패해도 나머지는 적재한다(분석에는 영향 없음)
                $t['versions'][$v] = ['error' => $e->getMessage()];
                $report['errors'][] = "{$tool} {$v}: " . $e->getMessage();
                continue;
            }
            $rows = $this->rows($tool, $data['rules']);
            $hash = hash('sha256', json_encode([array_column($rows, 'content_hash'), $data['components'], $this->severity->version($tool)]));
            $same = ($loaded[$v] ?? null) === $hash;
            $t['versions'][$v] = ['rules' => count($rows), 'unchanged' => $same, 'ms' => (int) round((hrtime(true) - $start) / 1e6),
                'with_description' => count(array_filter($rows, static fn (array $r): bool => $r['description'] !== '')),
                'with_docs_url' => count(array_filter($rows, static fn (array $r): bool => $r['docs_url'] !== null))];
            if ($apply && !$same) {
                $this->store($tool, $v, $data['components'], $rows, $hash);
                $report['changed'] = true;
            }
            if ($apply) {
                $t['versions'][$v]['blocks'] = $this->storeBlocks($tool, $v, $data['rules']);
            }
        }
        if ($apply) {
            $wasActive = $this->db->value('SELECT version FROM rule_versions WHERE tool = ? AND is_active = 1', [$tool]);
            if ($active !== null && $wasActive !== $active && $this->db->value('SELECT 1 FROM rule_versions WHERE tool = ? AND version = ?', [$tool, $active]) !== null) {
                $this->db->transaction(function () use ($tool, $active): void {
                    $this->db->execute('UPDATE rule_versions SET is_active = 0 WHERE tool = ? AND is_active = 1', [$tool]);
                    $this->db->execute('UPDATE rule_versions SET is_active = 1 WHERE tool = ? AND version = ?', [$tool, $active]);
                });
                $report['changed'] = true;
            }
            $t['changes'] = $this->recomputeChanges($tool);
        }
        $report['tools'][$tool] = $t;
    }

    /** 검색 색인(FTS5 외부 내용 표) 다시 만들기 */
    public function rebuildIndex(): void
    {
        $this->db->pdo()->exec("INSERT INTO rules_fts (rules_fts) VALUES ('rebuild')");
    }

    /** 적재 결과를 감사 로그에(자동은 system, 수동은 요청한 admin) */
    public function audit(Actor $actor, array $report): void
    {
        $summary = [];
        foreach ($report['tools'] as $tool => $t) {
            $summary[$tool] = ['active' => $t['active'], 'versions' => array_map(static fn (array $v): array => isset($v['error']) ? ['error' => true] : ['rules' => $v['rules'], 'unchanged' => $v['unchanged']], $t['versions'])];
        }
        $this->audit->record($actor, 'rules.load', 'rules', null, ['tools' => $summary, 'changed' => $report['changed'], 'errors' => count($report['errors'])]);
    }

    /**
     * 추출한 규칙 → 저장 행(보강·기본 심각도·내용 해시)
     *
     * @param array<string, array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function rows(string $tool, array $rules): array
    {
        $out = [];
        foreach ($rules as $r) {
            $r = $this->enrichment->apply($tool, $r);
            $e = $this->severity->explain($tool, $tool . ':' . $r['rule']);
            $row = [
                'rule' => (string) $r['rule'], 'name' => (string) $r['name'], 'summary' => (string) $r['summary'], 'description' => (string) $r['description'],
                'category' => $r['category'], 'origin' => (string) $r['origin'], 'default_enabled' => $r['default_enabled'] ? 1 : 0, 'fixable' => $r['fixable'],
                'has_suggestions' => $r['has_suggestions'] ? 1 : 0, 'has_options' => $r['has_options'] === null ? null : ($r['has_options'] ? 1 : 0),
                'status' => $r['status'], 'deprecation' => $r['deprecation'] === null ? null : json_encode($r['deprecation'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'docs_url' => $r['docs_url'], 'severity' => $e['severity'], 'severity_level' => $e['level'], 'severity_reason' => (string) $e['reason'],
                'enrichment' => isset($r['enrichment_sources']) ? json_encode($r['enrichment_sources'], JSON_UNESCAPED_SLASHES) : null,
            ];
            $row['content_hash'] = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 켤 수 없는 규칙(P22B)을 버전별로 다시 쓴다. 016 전 DB(표 없음)면 건너뛴다.
     *
     * @param array<string, array<string, mixed>> $rules 추출 결과
     * @return int 기록한 행 수
     */
    private function storeBlocks(string $tool, string $version, array $rules): int
    {
        $options = self::requiresOptions($this->requiresOptionsFile)[$tool] ?? [];
        $blocks = [];
        foreach ($rules as $r) {
            $rule = (string) $r['rule'];
            if (!empty($r['requires_type_info'])) {
                $blocks[] = [$rule, 'requires_type_info'];
            }
            if (isset($options[$rule])) {
                $blocks[] = [$rule, 'requires_options'];
            }
        }
        try {
            $this->db->transaction(function () use ($tool, $version, $blocks): void {
                $this->db->execute('DELETE FROM rule_enable_blocks WHERE tool = ? AND version = ?', [$tool, $version]);
                foreach ($blocks as [$rule, $reason]) {
                    $this->db->execute('INSERT INTO rule_enable_blocks (tool, version, rule, reason) VALUES (?, ?, ?, ?)', [$tool, $version, $rule, $reason]);
                }
            });
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table')) {
                return 0;
            }
            throw $e;
        }
        return count($blocks);
    }

    /**
     * 옵션 없이는 검사하지 않는 규칙 목록(resources/rules/requires_options.json, 도구 → 규칙 → 설명). 파일이 없으면 빈 목록.
     *
     * @return array<string, array<string, string>>
     */
    public static function requiresOptions(string $file): array
    {
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $doc = json_decode((string) file_get_contents($file), true);
        $out = [];
        foreach (['ruff', 'eslint'] as $tool) {
            foreach ((array) ($doc[$tool] ?? []) as $rule => $why) {
                if (is_string($rule) && is_string($why)) {
                    $out[$tool][$rule] = $why;
                }
            }
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private function store(string $tool, string $version, array $components, array $rows, string $hash): void
    {
        $now = SystemClock::iso($this->clock->now());
        $this->db->transaction(function () use ($tool, $version, $components, $rows, $hash, $now): void {
            $this->db->execute('DELETE FROM rules WHERE tool = ? AND version = ?', [$tool, $version]);
            $this->db->execute('INSERT INTO rule_versions (tool, version, components, rule_count, severity_map_version, content_hash, loaded_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
                . ' ON CONFLICT (tool, version) DO UPDATE SET components = excluded.components, rule_count = excluded.rule_count,'
                . ' severity_map_version = excluded.severity_map_version, content_hash = excluded.content_hash, loaded_at = excluded.loaded_at',
                [$tool, $version, json_encode((object) $components, JSON_UNESCAPED_SLASHES), count($rows), $this->severity->version($tool), $hash, $now]);
            $cols = ['rule', 'name', 'summary', 'description', 'category', 'origin', 'default_enabled', 'fixable', 'has_suggestions', 'has_options', 'status',
                'deprecation', 'docs_url', 'severity', 'severity_level', 'severity_reason', 'enrichment', 'content_hash'];
            $stmt = $this->db->pdo()->prepare('INSERT INTO rules (tool, version, ' . implode(', ', $cols) . ') VALUES (?, ?' . str_repeat(', ?', count($cols)) . ')');
            foreach ($rows as $r) {
                $stmt->execute(array_merge([$tool, $version], array_map(static fn (string $c): mixed => $r[$c], $cols)));
            }
        });
    }

    /**
     * 도구별 변경 내역 다시 계산(인접 버전 semver 순). 가장 오래된 적재 버전은 기준 버전(baseline = 1, 변경 내역 없음).
     *
     * @return array<string, int> 변경 종류별 건수(모든 버전 합)
     */
    public function recomputeChanges(string $tool): array
    {
        $versions = array_column($this->db->all('SELECT version FROM rule_versions WHERE tool = ?', [$tool]), 'version');
        usort($versions, 'version_compare');
        $counts = [];
        $this->db->transaction(function () use ($tool, $versions, &$counts): void {
            $this->db->execute('DELETE FROM rule_changes WHERE tool = ?', [$tool]);
            $this->db->execute('UPDATE rule_versions SET baseline = 0 WHERE tool = ?', [$tool]);
            if ($versions === []) {
                return;
            }
            $this->db->execute('UPDATE rule_versions SET baseline = 1 WHERE tool = ? AND version = ?', [$tool, $versions[0]]);
            $prev = $this->snapshot($tool, $versions[0]);
            for ($i = 1; $i < count($versions); $i++) {
                $next = $this->snapshot($tool, $versions[$i]);
                foreach (self::diff($prev, $next) as [$rule, $kind, $before, $after]) {
                    $this->db->execute('INSERT INTO rule_changes (tool, from_version, to_version, rule, kind, before, after) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$tool, $versions[$i - 1], $versions[$i], $rule, $kind, $before, $after]);
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                }
                $prev = $next;
            }
        });
        return $counts;
    }

    /** @return array<string, array<string, mixed>> 규칙 → 비교에 쓰는 값 */
    private function snapshot(string $tool, string $version): array
    {
        $out = [];
        foreach ($this->db->all('SELECT rule, summary, description, default_enabled, fixable, status, deprecation, severity, content_hash FROM rules WHERE tool = ? AND version = ?', [$tool, $version]) as $r) {
            $out[(string) $r['rule']] = $r;
        }
        return $out;
    }

    /**
     * 두 버전의 규칙 비교(순수 함수, RuleChangesTest). 이름 변경은 도구가 알려 준 대체 정보(폐기 정보의 replaced_by)로만 판정하고 추측하지 않는다.
     *
     * @param array<string, array<string, mixed>> $prev
     * @param array<string, array<string, mixed>> $next
     * @return list<array{0:string, 1:string, 2:?string, 3:?string}> [규칙, 종류, 이전, 이후]
     */
    public static function diff(array $prev, array $next): array
    {
        $out = [];
        $gone = array_diff_key($prev, $next);
        $new = array_diff_key($next, $prev);
        // 이름 변경: 사라진 규칙(이전 버전)이 대체 규칙으로 가리킨 새 규칙
        $renamedTo = [];
        foreach ($gone as $old => $r) {
            $dep = json_decode((string) ($r['deprecation'] ?? ''), true);
            foreach ((array) ($dep['replaced_by'] ?? []) as $target) {
                if (is_string($target) && isset($new[$target]) && !isset($renamedTo[$target])) {
                    $renamedTo[$target] = (string) $old;
                    unset($gone[$old]);
                    break;
                }
            }
        }
        foreach ($new as $rule => $r) {
            $out[] = isset($renamedTo[$rule]) ? [(string) $rule, 'renamed', $renamedTo[$rule], (string) $rule] : [(string) $rule, 'added', null, null];
        }
        foreach ($gone as $rule => $r) {
            $out[] = [(string) $rule, 'removed', null, null];
        }
        foreach (array_intersect_key($next, $prev) as $rule => $n) {
            $p = $prev[$rule];
            if ($p['content_hash'] === $n['content_hash']) {
                continue; // 해시가 같으면 변경 없음
            }
            if ($p['summary'] !== $n['summary']) {
                $out[] = [(string) $rule, 'description', (string) $p['summary'], (string) $n['summary']];
            } elseif ($p['description'] !== $n['description']) {
                $out[] = [(string) $rule, 'description', (string) $p['description'], (string) $n['description']];
            }
            if ((int) $p['default_enabled'] !== (int) $n['default_enabled']) {
                $out[] = [(string) $rule, 'default_enabled', (string) (int) $p['default_enabled'], (string) (int) $n['default_enabled']];
            }
            if ($p['severity'] !== $n['severity']) {
                $out[] = [(string) $rule, 'default_severity', (string) $p['severity'], (string) $n['severity']];
            }
            if (($p['status'] === 'deprecated') !== ($n['status'] === 'deprecated')) {
                $out[] = [(string) $rule, 'deprecated', (string) $p['status'], (string) $n['status']];
            }
            if ($p['fixable'] !== $n['fixable']) {
                $out[] = [(string) $rule, 'fixable', (string) $p['fixable'], (string) $n['fixable']];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $out;
    }
}
