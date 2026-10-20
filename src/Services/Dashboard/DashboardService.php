<?php

declare(strict_types=1);

namespace PJGuardian\Services\Dashboard;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Analysis\AnalysisComparison;
use PJGuardian\Services\AppSettings;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Services\Defects\DefectQuery;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\Fs;
use PJGuardian\Support\SystemClock;

/**
 * 대시보드 집계(명세 9장, P20). 정의는 docs/architecture.md 29절 "대시보드 집계 정의".
 *
 * - **모든 집계는 사용자가 볼 수 있는 프로젝트 범위**(ProjectAccess::scopeFor, 판정 한 곳)로만 한다. 보이지 않는 프로젝트는 이름·건수 어디에도 없다.
 *   project_id를 받는 집계는 처음에 ProjectAccess::requireView(보이지 않으면 404, 없는 프로젝트와 같음).
 * - 열린 결함 = 상태가 resolved가 아닌 결함. 상태·심각도별 건수는 DefectQuery::summary와 같은 정의(시험이 일치를 검사).
 * - 계산은 조회 시. 모든 결함 집계는 project_id로 시작해 **인덱스 열 순서대로 GROUP BY** 한다(정렬용 임시 B-트리 없이 커버링 인덱스만 읽음):
 *   상태·심각도·규칙·도구 = defects_project_facets(project_id, severity, status, rule_key, …, 008), 파일 = defects_project_file_status
 *   (project_id, file_path, status, severity, 010). 도구는 프로젝트의 언어로 정해지므로(python → ruff, javascript → eslint) 결함 행에서 읽지 않는다.
 *   측정(결함 50만 건): docs/results/P20.md.
 * - 캐시(dashboard.cache_ttl초, 0이면 끔): <work_dir>/cache/dashboard/<키>.json. 키 = SHA-256(구역, 인자, **보이는 프로젝트 id 집합**,
 *   데이터 표지). 보이는 프로젝트가 바뀌면(그룹 구성원·매핑 변경) 키가 바뀌고, 데이터 표지(끝난 분석 수·마지막 끝난 시각, 결함 이력의 마지막 id,
 *   프로젝트 수·마지막 변경 시각)가 바뀌면 TTL을 기다리지 않고 다음 요청이 새로 계산한다. 사용자마다 다른 값(내게 지정된 결함)은 캐시하지 않는다.
 */
final class DashboardService
{
    /** 열린 결함의 상태(결함 목록 링크의 status 값) */
    public const OPEN_STATUSES = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix'];
    /** 언어 → 도구(프로젝트의 언어는 하나: python 또는 javascript(JS/TS), 스키마 CHECK) */
    public const LANGUAGE_TOOL = \PJGuardian\Support\ToolLanguage::TOOL_BY_LANGUAGE; // 대응은 한 곳(X08)

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly ProjectAccess $access,
        private readonly DefectQuery $defects,
        private readonly string $cacheDir,
        private readonly int $cacheTtl,
        private readonly int $trendCount,
        private readonly int $topN,
        private readonly ?AppSettings $settings = null,
    ) {
    }

    /** "프로젝트별 추이"에 보여 줄 프로젝트 수(P21C, admin 설정 dashboard.trend_projects, 기본 5) */
    public function trendProjects(): int
    {
        return $this->settings?->int('dashboard.trend_projects') ?? AppSettings::SCHEMA['dashboard.trend_projects'][3];
    }

    /** 언어 필터 값(P21C): python·javascript만, 그 밖(빈 값·잘못된 값)은 전체(null) */
    public static function language(mixed $value): ?string
    {
        return is_string($value) && isset(self::LANGUAGE_TOOL[$value]) ? $value : null;
    }

    /**
     * 보이는 프로젝트 중 그 언어만(P21C). 범위 판정은 그대로 visibleProjects(보이지 않는 프로젝트는 처음부터 없음)
     *
     * @param array<int, array{id:int, name:string, language:string}> $visible
     * @return array<int, array{id:int, name:string, language:string}>
     */
    private static function ofLanguage(array $visible, ?string $language): array
    {
        return $language === null ? $visible : array_filter($visible, static fn (array $p): bool => $p['language'] === $language);
    }

    private function now(): string
    {
        return SystemClock::iso($this->clock->now());
    }

    // ---- 범위·캐시 ----

    /**
     * @param array<string, mixed> $user
     * @return array<int, array{id:int, name:string, language:string}> 보이는 프로젝트(id 순)
     */
    public function visibleProjects(array $user): array
    {
        [$scope, $params] = $this->access->scopeFor($user, 'p');
        $out = [];
        foreach ($this->db->all("SELECT p.id, p.name, p.language FROM projects p WHERE {$scope} ORDER BY p.id", $params) as $r) {
            $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'language' => (string) $r['language']];
        }
        return $out;
    }

    /** 데이터 표지: 집계에 영향을 주는 변경이 있으면 바뀐다(모두 PK·작은 표라 즉시) */
    public function dataStamp(): string
    {
        // r: Run 번호가 붙은 분석 수(P20R 되채움은 분석을 새로 끝내지 않고 번호·요약만 쓴다)
        // d: 상세가 삭제된 Run 수(P21B 삭제는 분석을 새로 끝내지 않으므로 따로 센다)
        $a = $this->db->one('SELECT COUNT(*) AS n, MAX(finished_at) AS t, COUNT(run_number) AS r, COUNT(run_detail_deleted_at) AS d FROM analyses WHERE finished_at IS NOT NULL');
        $h = $this->db->value('SELECT MAX(id) FROM defect_history');
        $p = $this->db->one('SELECT COUNT(*) AS n, MAX(updated_at) AS t FROM projects');
        return implode('|', [$a['n'] ?? 0, $a['t'] ?? '', $a['r'] ?? 0, $a['d'] ?? 0, $h ?? 0, $p['n'] ?? 0, $p['t'] ?? '']);
    }

    /**
     * @param array<int, mixed> $visible
     * @param array<string, mixed> $args
     * @param \Closure(): array<string, mixed> $compute
     * @return array<string, mixed> 결과 + as_of(집계 시각) + cached
     */
    private function cached(string $section, array $args, array $visible, \Closure $compute): array
    {
        if ($this->cacheTtl <= 0) {
            return $compute() + ['as_of' => $this->now(), 'cached' => false];
        }
        $key = hash('sha256', json_encode([$section, $args, array_keys($visible), $this->dataStamp()]));
        $file = $this->cacheDir . '/' . $key . '.json';
        $raw = @file_get_contents($file);
        $hit = $raw === false ? null : json_decode($raw, true);
        if (is_array($hit) && isset($hit['as_of'], $hit['data'])) {
            $t = strtotime((string) $hit['as_of']);
            if ($t !== false && $this->clock->now()->getTimestamp() - $t < $this->cacheTtl) {
                return $hit['data'] + ['as_of' => $hit['as_of'], 'cached' => true];
            }
        }
        $data = $compute();
        $asOf = $this->now();
        try {
            Fs::ensureDir($this->cacheDir);
            Fs::atomicWrite($file, (string) json_encode(['as_of' => $asOf, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (random_int(1, 50) === 1) {
                $this->prune();
            }
        } catch (\Throwable) {
            // 캐시를 못 써도 응답은 한다
        }
        return $data + ['as_of' => $asOf, 'cached' => false];
    }

    /** 오래된 캐시 파일 정리(TTL의 10배 또는 1시간 중 긴 것) */
    public function prune(): int
    {
        $limit = $this->clock->now()->getTimestamp() - max(3600, $this->cacheTtl * 10);
        $n = 0;
        foreach (Fs::listDir($this->cacheDir) as $f) {
            if (str_ends_with($f, '.json') && (int) @filemtime($this->cacheDir . '/' . $f) < $limit && @unlink($this->cacheDir . '/' . $f)) {
                $n++;
            }
        }
        return $n;
    }

    /** @param array<int, mixed> $visible */
    private static function ids(array $visible): string
    {
        return (string) json_encode(array_keys($visible));
    }

    /** @param array<string, mixed> $user */
    private function requireProject(array $user, mixed $projectId): ?int
    {
        if ($projectId === null || $projectId === '') {
            return null;
        }
        if (!is_string($projectId) && !is_int($projectId) || preg_match('/^[1-9][0-9]{0,17}$/', (string) $projectId) !== 1) {
            throw new ApiException(400, 'invalid_project_id');
        }
        $this->access->requireView($user, (int) $projectId);
        return (int) $projectId;
    }

    /** 결함 목록(프로젝트 상세의 결함 탭) 링크 쿼리: 열린 결함 + 규칙 또는 파일 */
    public static function defectsLink(int $projectId, array $filter): string
    {
        return '/projects/' . $projectId . '?' . http_build_query($filter + ['status' => implode(',', self::OPEN_STATUSES)], '', '&', PHP_QUERY_RFC3986);
    }

    // ---- 구역 ----

    /**
     * 현황 요약.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function summary(array $user): array
    {
        $visible = $this->visibleProjects($user);
        $data = $this->cached('summary', [], $visible, function () use ($visible): array {
            $out = [
                'projects' => ['visible' => count($visible), 'with_analysis' => 0, 'without_analysis' => 0, 'without_defects' => 0],
                'open' => ['total' => 0, 'by_severity' => array_fill_keys(DefectFilter::SEVERITIES, 0)],
                'by_status' => array_fill_keys(DefectFilter::STATUSES, 0),
                'analyses_7d' => ['succeeded' => 0, 'failed' => 0, 'cancelled' => 0],
                'failed_projects' => [],
            ];
            if ($visible === []) {
                return $out;
            }
            $ids = self::ids($visible);
            foreach ($this->db->all('SELECT d.project_id, d.severity AS s, d.status AS st, COUNT(*) AS n FROM defects d WHERE d.project_id IN (SELECT value FROM json_each(?))'
                . ' GROUP BY d.project_id, d.severity, d.status', [$ids]) as $g) {
                $n = (int) $g['n'];
                $out['by_status'][(string) $g['st']] += $n;
                if ($g['st'] !== 'resolved') {
                    $out['open']['total'] += $n;
                    $out['open']['by_severity'][(string) $g['s']] += $n;
                }
            }
            $since = SystemClock::iso($this->clock->now()->modify('-7 days'));
            // "변경 없음"(P27)은 분석을 하지 않았으므로 세지 않는다
            foreach ($this->db->all("SELECT status, COUNT(*) AS n FROM analyses WHERE project_id IN (SELECT value FROM json_each(?)) AND finished_at >= ?"
                . " AND status IN ('succeeded', 'failed', 'cancelled') AND no_change = 0 GROUP BY status", [$ids, $since]) as $r) {
                $out['analyses_7d'][(string) $r['status']] = (int) $r['n'];
            }
            $withAnalysis = array_map('intval', array_column($this->db->all(
                "SELECT DISTINCT project_id FROM analyses WHERE status = 'succeeded' AND no_change = 0 AND project_id IN (SELECT value FROM json_each(?))", [$ids]), 'project_id'));
            $withDefects = array_map('intval', array_column($this->db->all(
                'SELECT DISTINCT project_id FROM defects WHERE project_id IN (SELECT value FROM json_each(?))', [$ids]), 'project_id'));
            $out['projects']['with_analysis'] = count($withAnalysis);
            $out['projects']['without_analysis'] = count($visible) - count($withAnalysis);
            $out['projects']['without_defects'] = count(array_diff($withAnalysis, $withDefects));
            // 마지막으로 끝난 분석(성공·실패, 취소 제외)이 실패인 프로젝트, 최근 순 5개. "변경 없음"은 실제 분석이 아니므로 건너뛴다(P27C:
            // 실패 뒤 변경 없음 확인이 있어도 실패로 남고 실제 분석이 성공해야 빠진다)
            $rows = $this->db->all(
                "SELECT a.project_id, a.id, a.failure_code, a.finished_at FROM analyses a WHERE a.project_id IN (SELECT value FROM json_each(?))"
                . " AND a.id = (SELECT a2.id FROM analyses a2 WHERE a2.project_id = a.project_id AND a2.status IN ('succeeded', 'failed') AND a2.no_change = 0 ORDER BY a2.id DESC LIMIT 1)"
                . " AND a.status = 'failed' ORDER BY a.finished_at DESC, a.id DESC LIMIT 5",
                [$ids],
            );
            foreach ($rows as $r) {
                $code = (string) ($r['failure_code'] ?? 'unknown');
                $out['failed_projects'][] = [
                    'project_id' => (int) $r['project_id'], 'project_name' => $visible[(int) $r['project_id']]['name'],
                    'analysis_id' => (int) $r['id'], 'failure_code' => $code, 'failure_title' => ErrorCatalog::describe('analysis', $code)['title'],
                    'finished_at' => $r['finished_at'],
                ];
            }
            return $out;
        });
        // 내게 지정된 결함은 사용자마다 달라 캐시하지 않는다(P18 mineSummary 재사용)
        $mine = $this->defects->mineSummary($user);
        $data['mine'] = ['open' => $mine['total'] - $mine['status']['resolved'], 'by_status' => $mine['status']];
        return $data;
    }

    /**
     * 추이: 프로젝트 하나(지정) 또는 가장 최근에 분석한 보이는 프로젝트 5개.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function trend(array $user, mixed $projectId, mixed $language = null): array
    {
        $pid = $this->requireProject($user, $projectId);
        $lang = self::language($language);
        $limit = $this->trendProjects();
        $visible = $this->visibleProjects($user);
        $scoped = self::ofLanguage($visible, $lang);
        return $this->cached('trend', ['project' => $pid, 'language' => $lang, 'limit' => $limit], $visible, function () use ($pid, $visible, $scoped, $lang, $limit): array {
            if ($pid !== null) {
                // 고른 프로젝트가 언어 필터와 다르면 비어 있다(오류 아님 — 화면은 언어에 맞는 프로젝트만 고르게 한다)
                $pids = isset($scoped[$pid]) ? [$pid] : [];
            } elseif ($scoped === []) {
                $pids = [];
            } else {
                $pids = array_map('intval', array_column($this->db->all(
                    "SELECT project_id, MAX(finished_at) AS t FROM analyses WHERE status = 'succeeded' AND no_change = 0 AND project_id IN (SELECT value FROM json_each(?))"
                    . ' GROUP BY project_id ORDER BY t DESC, project_id LIMIT ?', [self::ids($scoped), $limit]), 'project_id'));
            }
            $series = [];
            foreach ($pids as $id) {
                $series[] = $this->series($id, $visible[$id]['name'] ?? (string) $this->db->value('SELECT name FROM projects WHERE id = ?', [$id]));
            }
            return ['project_id' => $pid, 'language' => $lang, 'trend_count' => $this->trendCount, 'project_limit' => $limit, 'series' => $series];
        });
    }

    /** @return array<string, mixed> 프로젝트 하나의 최근 성공 분석(no_change 제외) */
    private function series(int $projectId, string $name): array
    {
        $rows = array_reverse($this->db->all(
            "SELECT id, run_number, run_summary, finished_at, total_count, severity_counts, tool, tool_version, config_source, config_hash, run_detail_deleted_at FROM analyses"
            . " WHERE project_id = ? AND status = 'succeeded' AND no_change = 0 ORDER BY id DESC LIMIT ?",
            [$projectId, $this->trendCount + 1],
        ));
        // 가장 오래된 점의 표식 판정을 위해 하나 더 읽고(직전 성공 분석), 표시는 최근 trendCount개만
        $prev = count($rows) > $this->trendCount ? array_shift($rows) : null;
        $points = [];
        foreach ($rows as $r) {
            // P20R: Run 요약(그 Run에 존재한 결함 = Run 선택기·Run 목록과 같은 수)이 있으면 그 값, 없으면(되채움 전) 분석의 감지 건수.
            // 둘은 범위 밖으로 존재를 유지한 결함이 있을 때만 다르다(severity_counts는 감지 건수로 분석 결과 화면이 계속 쓴다)
            $summary = json_decode((string) ($r['run_summary'] ?? ''), true);
            $sev = is_array($summary) ? $summary['by_severity'] : json_decode((string) ($r['severity_counts'] ?? ''), true);
            $diff = AnalysisComparison::differences($prev, $r);
            $points[] = [
                'analysis_id' => (int) $r['id'], 'run' => $r['run_number'] === null ? null : (int) $r['run_number'], 'finished_at' => $r['finished_at'],
                'total' => is_array($summary) ? (int) $summary['total'] : (int) $r['total_count'],
                'by_severity' => array_map('intval', array_replace(array_fill_keys(DefectFilter::SEVERITIES, 0), is_array($sev) ? $sev : [])),
                'tool_name' => (string) $r['tool'], 'tool_version' => $r['tool_version'], 'config_source' => $r['config_source'],
                'changed' => $diff !== [], 'changes' => $diff,
                'detail_deleted' => $r['run_detail_deleted_at'] !== null, // P21B: 점은 그대로, 눌러도 과거 Run 보기로 가지 않음
            ];
            $prev = $r;
        }
        $state = 'ok';
        if ($points === []) {
            $state = $this->db->value("SELECT 1 FROM analyses WHERE project_id = ? AND status = 'failed' LIMIT 1", [$projectId]) !== null ? 'failed_only' : 'no_analysis';
        }
        return ['project_id' => $projectId, 'project_name' => $name, 'state' => $state, 'points' => $points];
    }

    /**
     * 규칙별·파일별 상위 N(열린 결함). 전체 범위면 규칙은 (도구, 규칙 키)로 합치고, 파일은 프로젝트마다 따로다.
     *
     * @param array<string, mixed> $user
     * @param 'rules'|'files' $kind
     * @return array<string, mixed>
     */
    public function top(array $user, string $kind, mixed $projectId, mixed $language = null): array
    {
        $pid = $this->requireProject($user, $projectId);
        // 언어 필터(P21C)는 규칙별 상위만(요청 범위: 추이·규칙 상위 10). 파일별은 그대로
        $lang = $kind === 'rules' ? self::language($language) : null;
        $visible = $this->visibleProjects($user);
        $scoped = self::ofLanguage($visible, $lang);
        return $this->cached('top-' . $kind, ['project' => $pid, 'language' => $lang], $visible, function () use ($kind, $pid, $visible, $scoped, $lang): array {
            $items = [];
            if ($pid !== null ? isset($scoped[$pid]) : $scoped !== []) {
                $ids = $pid !== null ? (string) json_encode([$pid]) : self::ids($scoped);
                $items = $kind === 'rules' ? $this->topRules($ids, $visible) : $this->topFiles($ids, $visible);
            }
            return ['project_id' => $pid, 'language' => $lang, 'limit' => $this->topN, 'items' => $items];
        });
    }

    /** @param array<int, array{id:int, name:string, language:string}> $visible @return list<array<string, mixed>> */
    private function topRules(string $ids, array $visible): array
    {
        $agg = [];
        foreach ($this->db->all('SELECT d.project_id AS p, d.severity AS s, d.status AS st, d.rule_key AS r, COUNT(*) AS n FROM defects d'
            . " WHERE d.project_id IN (SELECT value FROM json_each(?)) AND d.status <> 'resolved' GROUP BY d.project_id, d.severity, d.status, d.rule_key", [$ids]) as $g) {
            $tool = self::toolOf($visible, (int) $g['p']);
            $key = $tool . "\0" . $g['r'];
            $agg[$key] ??= ['tool' => $tool, 'rule_key' => (string) $g['r'], 'count' => 0, 'by_severity' => array_fill_keys(DefectFilter::SEVERITIES, 0), 'projects' => []];
            $n = (int) $g['n'];
            $agg[$key]['count'] += $n;
            $agg[$key]['by_severity'][(string) $g['s']] += $n;
            $agg[$key]['projects'][(int) $g['p']] = ($agg[$key]['projects'][(int) $g['p']] ?? 0) + $n;
        }
        usort($agg, static fn (array $a, array $b): int => [$b['count'], $a['rule_key'], $a['tool']] <=> [$a['count'], $b['rule_key'], $b['tool']]);
        $out = [];
        foreach (array_slice($agg, 0, $this->topN) as $it) {
            $projects = self::projectBreakdown($it['projects'], $visible);
            $it['projects'] = $projects;
            // 링크: 건수가 가장 많은 프로젝트의 결함 목록(규칙 + 열린 상태)
            $it['link'] = self::defectsLink($projects[0]['id'], ['rule' => $it['rule_key']]);
            $out[] = $it;
        }
        return $out;
    }

    /** @param array<int, array{id:int, name:string, language:string}> $visible */
    private static function toolOf(array $visible, int $projectId): string
    {
        return self::LANGUAGE_TOOL[$visible[$projectId]['language'] ?? ''] ?? 'ruff';
    }

    /**
     * 파일별: (프로젝트, 파일) 순위는 SQL에서 상위 N개만(파일 수가 많아 PHP로 모두 가져오지 않는다), 심각도 분포는 그 N개만 따로 센다.
     *
     * @param array<int, array{id:int, name:string, language:string}> $visible
     * @return list<array<string, mixed>>
     */
    private function topFiles(string $ids, array $visible): array
    {
        $rows = $this->db->all('SELECT d.project_id AS p, d.file_path AS f, COUNT(*) AS n FROM defects d'
            . " WHERE d.project_id IN (SELECT value FROM json_each(?)) AND d.status <> 'resolved' GROUP BY d.project_id, d.file_path"
            . ' ORDER BY n DESC, d.file_path, d.project_id LIMIT ?', [$ids, $this->topN]);
        $out = [];
        foreach ($rows as $r) {
            $sev = array_fill_keys(DefectFilter::SEVERITIES, 0);
            foreach ($this->db->all("SELECT d.severity AS s, COUNT(*) AS n FROM defects d WHERE d.project_id = ? AND d.file_path = ? AND d.status <> 'resolved' GROUP BY d.severity",
                [(int) $r['p'], (string) $r['f']]) as $g) {
                $sev[(string) $g['s']] = (int) $g['n'];
            }
            $out[] = ['project_id' => (int) $r['p'], 'project_name' => $visible[(int) $r['p']]['name'] ?? '', 'file_path' => (string) $r['f'],
                'count' => (int) $r['n'], 'by_severity' => $sev, 'link' => self::defectsLink((int) $r['p'], ['file' => (string) $r['f']])];
        }
        return $out;
    }

    /**
     * @param array<int, int> $counts 프로젝트 id → 건수
     * @param array<int, array{id:int, name:string, language:string}> $visible
     * @return list<array{id:int, name:string, count:int}> 건수 많은 순(같으면 id 순)
     */
    private static function projectBreakdown(array $counts, array $visible): array
    {
        $out = [];
        foreach ($counts as $id => $n) {
            $out[] = ['id' => $id, 'name' => $visible[$id]['name'] ?? '', 'count' => $n];
        }
        usort($out, static fn (array $a, array $b): int => [$b['count'], $a['id']] <=> [$a['count'], $b['id']]);
        return $out;
    }

    /**
     * 언어·도구별 통계: 프로젝트 수(분석 전 포함), 열린 결함·심각도별, 최근 성공 분석 기준 도구 버전 분포.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function tools(array $user): array
    {
        $visible = $this->visibleProjects($user);
        return $this->cached('tools', [], $visible, function () use ($visible): array {
            $groups = [];
            foreach (self::LANGUAGE_TOOL as $lang => $tool) {
                $groups[$tool] = ['language' => $lang, 'tool' => $tool, 'projects' => 0, 'projects_with_analysis' => 0,
                    'open' => 0, 'by_severity' => array_fill_keys(DefectFilter::SEVERITIES, 0), 'versions' => []];
            }
            foreach ($visible as $p) {
                $groups[self::LANGUAGE_TOOL[$p['language']] ?? 'ruff']['projects']++;
            }
            if ($visible !== []) {
                $ids = self::ids($visible);
                foreach ($this->db->all('SELECT d.project_id AS p, d.severity AS s, COUNT(*) AS n FROM defects d WHERE d.project_id IN (SELECT value FROM json_each(?))'
                    . " AND d.status <> 'resolved' GROUP BY d.project_id, d.severity", [$ids]) as $g) {
                    $tool = self::toolOf($visible, (int) $g['p']);
                    $groups[$tool]['open'] += (int) $g['n'];
                    $groups[$tool]['by_severity'][(string) $g['s']] += (int) $g['n'];
                }
                foreach ($this->db->all('SELECT a.tool AS t, a.tool_version AS v, COUNT(*) AS n FROM analyses a WHERE a.id IN'
                    . " (SELECT MAX(id) FROM analyses WHERE status = 'succeeded' AND no_change = 0 AND project_id IN (SELECT value FROM json_each(?)) GROUP BY project_id)"
                    . ' GROUP BY a.tool, a.tool_version ORDER BY n DESC, a.tool_version', [$ids]) as $r) {
                    $groups[(string) $r['t']]['versions'][] = ['version' => $r['v'], 'projects' => (int) $r['n']];
                    $groups[(string) $r['t']]['projects_with_analysis'] += (int) $r['n'];
                }
            }
            return ['groups' => array_values($groups)];
        });
    }
}
