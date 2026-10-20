<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\ProjectAccess;
use PJGuardian\Services\Runs\RunService;
use PJGuardian\Support\Db;
use PJGuardian\Support\RuleLinks;

/**
 * 결함 조회(명세 6.4, 8, P17): 목록(필터·검색·정렬·페이지), 필터 요약, 상세, 변경 이력. 읽기 전용이다(수정은 P18).
 *
 * 프로젝트 접근 판정은 라우트의 RequireProjectAccess가 먼저 한다(목록 = 프로젝트 id, 상세·이력·코드 = 결함 id → 소유 프로젝트,
 * 보이지 않으면 404). 이 클래스는 판정하지 않으므로 다른 곳에서 부를 때도 먼저 ProjectAccess를 거친다.
 *
 * 목록은 두 단계로 읽는다: ① 조건·정렬·페이지로 결함 id만(인덱스로 끝나는 경우가 많다) ② 그 id들의 행과 담당자·마지막 감지 분석.
 * 깊은 페이지에서 건너뛰는 행마다 JOIN하지 않게 하려는 것이다.
 *
 * 과거 Run 보기(P20R): 목록·요약은 결함 표 대신 RunService::source(그 Run에 존재한 결함 + Run 시점 값, 같은 열 이름의 별칭 d)를
 * 원본으로 받아 같은 필터·검색·정렬·페이지를 쓴다. 행 정보(줄·메시지 등)는 결함 표의 최신 감지 값이고 상태·담당자·심각도는 Run 시점 값으로 덮는다.
 */
final class DefectQuery
{
    /** 과거 Run 목록에서 정렬 인덱스 순서로 훑다가 멈추는 형태를 쓸 최대 OFFSET(P20S) */
    private const EARLY_STOP_OFFSET = 1000;
    /** 내게 지정된 결함의 순서(목록·내보내기 공통): 상태 순 다음 최근 감지 순 */
    private const MINE_ORDER = "CASE d.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'postponed' THEN 2 WHEN 'intentional' THEN 3 WHEN 'not_fix' THEN 4 ELSE 5 END,"
        . ' {seen} DESC, d.id DESC';

    /** 정렬이 마지막 감지(Run·시각)를 쓰는가 — 그때만 최신 분석 값을 한 번 읽는다 */
    private static function needsLive(DefectFilter $filter): bool
    {
        return in_array($filter->sort, ['run', 'last_seen'], true);
    }

    /** 내 결함 순서(마지막 감지는 DefectSeen — 여러 프로젝트라 상관 하위 질의) */
    private static function mineOrder(): string
    {
        return str_replace('{seen}', DefectSeen::expr('d'), self::MINE_ORDER);
    }

    /** @var array<string, bool> (담당자, 프로젝트) → 접근 가능(한 요청 안 캐시) */
    private array $accessCache = [];

    /** @param ProjectAccess|null $access 담당자의 "접근 권한 없음" 표시(P18). 없으면 has_access를 null로 둔다 */
    public function __construct(private readonly Db $db, private readonly ?ProjectAccess $access = null, private readonly ?RunService $runs = null)
    {
    }

    /**
     * 목록·요약의 원본: 기본은 결함 표(현재), 과거 Run이면 RunService::source.
     *
     * @param array{0:string, 1:list<mixed>}|null $source
     * @return array{0:string, 1:list<mixed>}
     */
    private static function from(?array $source): array
    {
        return $source ?? ['defects d', []];
    }

    /** 결함 id(경로 문자열) → 소유 프로젝트 id. 형식이 틀리거나 없으면 null(라우트가 404) */
    public function projectIdOf(string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            return null;
        }
        $pid = $this->db->value('SELECT project_id FROM defects WHERE id = ?', [(int) $id]);
        return $pid === null ? null : (int) $pid;
    }

    /**
     * 빈 상태 구분용(분석 전 / 결함 0건): 성공 분석이 있는지, 필터 없는 전체 결함 수.
     *
     * @return array{has_analysis:bool, total:int}
     */
    public function overview(int $projectId): array
    {
        return [
            'has_analysis' => $this->db->value("SELECT 1 FROM analyses WHERE project_id = ? AND status = 'succeeded' LIMIT 1", [$projectId]) !== null,
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM defects WHERE project_id = ?', [$projectId]),
        ];
    }

    /**
     * 프로젝트별 해결되지 않은 결함 수(전체 결함 메뉴의 프로젝트 선택 화면). 호출하는 쪽이 볼 수 있는 프로젝트 id만 넘긴다.
     *
     * @param list<int> $projectIds
     * @return array<int, array{open:int, total:int}>
     */
    public function countsByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $out = array_fill_keys($projectIds, ['open' => 0, 'total' => 0]);
        $rows = $this->db->all(
            "SELECT project_id, SUM(status <> 'resolved') AS open, COUNT(*) AS total FROM defects"
            . ' WHERE project_id IN (SELECT value FROM json_each(?)) GROUP BY project_id',
            [json_encode(array_values($projectIds))],
        );
        foreach ($rows as $r) {
            $out[(int) $r['project_id']] = ['open' => (int) $r['open'], 'total' => (int) $r['total']];
        }
        return $out;
    }

    /**
     * @param array{0:string, 1:list<mixed>}|null $source 과거 Run 원본(RunService::source), null = 결함 표
     * @param array{run:int, mark:int}|null $runView 과거 Run이면 행 값을 그 시점으로 덮는다
     * @param bool $forceFullRestore 시험용: 최적화 없이 항상 복원 원본으로 고른다(P20S 동일성 시험의 기준)
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function list(int $projectId, DefectFilter $filter, int $limit, int $offset, ?array $source = null, ?array $runView = null, bool $forceFullRestore = false): array
    {
        // P20S: 과거 Run인데 필터·정렬이 Run 시점 값(상태·담당자·심각도)을 쓰지 않으면, 존재 집합(복원 없음)으로 고르고 세어
        // 그 페이지 행만 복원한다(rowsByIds의 덮어쓰기). 고르는 조건·순서가 복원 값과 무관하므로 결과는 전체 복원과 같다.
        $total = null;
        $pick = $source;
        $memberOnly = false;
        if ($source !== null && $runView !== null && $this->runs !== null && !$forceFullRestore && !$filter->usesRunValues()) {
            $memberOnly = true;
            $run = (int) $runView['run'];
            $source = $this->runs->memberSource($projectId, $run);
            // 필터가 없으면 건수 = 그 Run에 존재한 결함 수 = 저장된 Run 요약의 total(되채움 전이면 센다)
            if ($filter->isEmpty() && isset($runView['analysis_id'])) {
                $total = $this->runs->storedSummary((int) $runView['analysis_id'])['total'] ?? null;
            }
        }
        [$from, $fromParams] = self::from($source);
        [$where, $params] = $filter->where($projectId);
        $total ??= (int) $this->db->value('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, [...$fromParams, ...$params]);
        if ($offset >= $total) {
            return [[], $total];
        }
        // P31 B-3: 뒤쪽 절반 페이지(마지막 쪽 등)는 순서를 뒤집어 앞에서 고르고 결과를 다시 뒤집는다 — 정렬된 전체를 끝까지 건너뛰지 않음
        // (100만 건 과거 Run 마지막 쪽 4.4초 → docs/performance.md 4절). 같은 페이지 내용·순서(DefectListReverseTest)
        $take = min($limit, $total - $offset);
        $reverse = $offset > intdiv($total, 2);
        $skip = $reverse ? $total - $offset - $take : $offset;
        if ($memberOnly) {
            // 앞쪽 페이지(검색어 없음)는 정렬 인덱스 순서로 훑다가 멈추는 형태, 그 밖은 조인 형태(측정: docs/results/P20S.md)
            // run·last_seen 정렬(P21U)은 정렬 인덱스가 없어 멈출 수 없으므로 조인 형태(측정: docs/results/P21U.md)
            $early = $skip <= self::EARLY_STOP_OFFSET && $filter->q === null && !in_array($filter->sort, ['run', 'last_seen'], true);
            $pick = $early ? $this->runs->memberSource($projectId, (int) $runView['run'], 'exists') : $source;
        }
        [$pickFrom, $pickParams] = self::from($pick);
        [$order, $orderParams] = $filter->orderBy($runView, self::needsLive($filter) ? DefectSeen::liveValues($this->db, $projectId) : null, $reverse);
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT d.id FROM ' . $pickFrom . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            [...$pickParams, ...$params, ...$orderParams, $take, $skip],
        ), 'id'));
        if ($reverse) {
            $ids = array_reverse($ids);
        }
        return [$this->rowsByIds($ids, $runView), $total];
    }

    // ---- P23 내보내기: 목록과 같은 원본·조건·정렬(같은 DefectFilter::where·orderBy)로 전체 행을 고른다 ----

    /**
     * 목록과 같은 조건의 건수(내보내기 상한 확인). 과거 Run이면 복원 원본 기준(list()의 total과 같은 값).
     *
     * @param array{0:string, 1:list<mixed>}|null $source
     */
    public function count(int $projectId, DefectFilter $filter, ?array $source = null): int
    {
        [$from, $fromParams] = self::from($source);
        [$where, $params] = $filter->where($projectId);
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, [...$fromParams, ...$params]);
    }

    /**
     * 목록 순서 그대로의 결함 id 전체(정수만 — 행 값은 rowsForExport로 나눠 읽는다).
     * 과거 Run은 복원 원본으로 고른다(list()의 최적화 경로와 결과가 같음은 P20S 동일성 시험이 확인한다).
     *
     * @param array{0:string, 1:list<mixed>}|null $source
     * @param array{run:int, finished_at:?string}|null $runView
     * @return list<int>
     */
    public function exportIds(int $projectId, DefectFilter $filter, ?array $source = null, ?array $runView = null): array
    {
        [$from, $fromParams] = self::from($source);
        [$where, $params] = $filter->where($projectId);
        [$order, $orderParams] = $filter->orderBy($runView, self::needsLive($filter) ? DefectSeen::liveValues($this->db, $projectId) : null);
        return array_map('intval', array_column($this->db->all(
            'SELECT d.id FROM ' . $from . ' WHERE ' . $where . ' ORDER BY ' . $order, [...$fromParams, ...$params, ...$orderParams]), 'id'));
    }

    /**
     * 목록 행과 같은 값(같은 변환·과거 Run 덮어쓰기) + 처음 감지 Run(first_seen_run).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function rowsForExport(array $ids, ?array $runView = null): array
    {
        $rows = $this->rowsByIds($ids, $runView);
        if ($rows === [] || $this->runs === null) {
            return $rows;
        }
        $first = array_column($this->db->all('SELECT id, first_seen_analysis_id FROM defects WHERE id IN (SELECT value FROM json_each(?))', [json_encode($ids)]),
            'first_seen_analysis_id', 'id');
        $runs = $this->runs->runsOf(array_values(array_unique(array_map('intval', $first))));
        foreach ($rows as &$r) {
            $r['first_seen_run'] = $runs[(int) ($first[$r['id']] ?? 0)] ?? null;
        }
        unset($r);
        return $rows;
    }

    /**
     * 내게 지정된 결함의 건수·id(목록과 같은 조건·순서)
     *
     * @param array<string, mixed> $user
     * @param list<string> $statuses
     * @return array{0:int, 1:list<int>|null} [건수, $withIds면 id 목록]
     */
    public function mineIds(array $user, ?int $projectId, array $statuses, bool $withIds): array
    {
        [$where, $params] = $this->mineWhere($user, $projectId, $statuses);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM defects d JOIN projects p ON p.id = d.project_id WHERE ' . $where, $params);
        if (!$withIds) {
            return [$total, null];
        }
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT d.id FROM defects d JOIN projects p ON p.id = d.project_id WHERE ' . $where . ' ORDER BY ' . self::mineOrder(), $params), 'id'));
        return [$total, $ids];
    }

    /**
     * 필터 요약: 현재 필터에서의 항목별 건수. 각 항목은 **자기 필터만 뺀** 조건으로 센다(예: 심각도 칩의 숫자는 심각도 선택과 무관하게
     * 다른 필터·검색어를 적용한 건수). 그래서 칩을 더 고를 때 결과가 몇 건이 될지 보인다. total은 모든 필터를 적용한 건수.
     *
     * 계산(P17 측정 반영): 항목마다 GROUP BY를 따로 하면 필터가 있을 때 결함 5만 건 프로젝트에서 140~250ms였다. 그래서 두 번만 훑는다.
     *   A: (심각도, 상태, 규칙)별 건수 — 담당자 필터와 파일·검색어는 SQL에서 → 심각도·상태·규칙 칩과 total을 PHP에서 나눠 센다
     *   B: (심각도, 상태, 담당자)별 건수 — 규칙 필터와 파일·검색어는 SQL에서 → 담당자 목록
     * 묶음 수는 A ≤ 30 × 규칙 수, B ≤ 30 × 담당자 수로 작다. 둘 다 커버링 인덱스 defects_project_facets(마이그레이션 008)로 끝난다.
     *
     * @return array<string, mixed>
     */
    public function summary(int $projectId, DefectFilter $filter, int $ruleLimit, ?array $source = null): array
    {
        [$from, $fromParams] = self::from($source);
        $out = ['total' => 0, 'severity' => array_fill_keys(DefectFilter::SEVERITIES, 0), 'status' => array_fill_keys(DefectFilter::STATUSES, 0)];
        // P31 B-3: 검색어·경로 포함(LIKE — 색인을 못 써 행마다 문자열 비교)이 있으면 두 번 훑지 않고 한 번에 (심각도, 상태, 규칙, 담당자)로 묶어
        // 규칙·담당자 필터도 PHP에서 나눠 센다. 묶음 수 ≤ 30 × 규칙 수 × 담당자 수(작음). 결과는 두 번 훑기와 같다(DefectApiTest::testSummaryCountsAgreeWithList)
        // 과거 Run 원본(복원 값 — 결함 표를 존재 구간·이력과 이어 매번 계산)도 훑는 비용이 커서 한 번에(100만 건 2.8초 → docs/performance.md 4절)
        $text = $filter->q !== null || ($filter->file !== null && $filter->fileMode === 'contains') || $source !== null;
        $groups = null;
        if ($text) {
            [$w, $p] = $filter->where($projectId, ['rule', 'assignee', 'severity', 'status']);
            $groups = $this->db->all('SELECT d.severity AS s, d.status AS st, d.rule_key AS r, d.assignee_id AS a, COUNT(*) AS n FROM ' . $from . ' WHERE ' . $w
                . ' GROUP BY d.severity, d.status, d.rule_key, d.assignee_id', [...$fromParams, ...$p]);
        }
        $rules = [];
        if ($groups !== null) {
            $byRule = [];
            foreach ($groups as $g) {
                if ($filter->accepts('assignee', $g['a'] === null ? null : (string) $g['a'])) {
                    $k = $g['s'] . "\0" . $g['st'] . "\0" . $g['r'];
                    $byRule[$k] ??= ['s' => $g['s'], 'st' => $g['st'], 'r' => $g['r'], 'n' => 0];
                    $byRule[$k]['n'] += (int) $g['n'];
                }
            }
            $ruleGroups = array_values($byRule);
        } else {
            [$w, $p] = $filter->where($projectId, ['rule', 'severity', 'status']);
            $ruleGroups = $this->db->all('SELECT d.severity AS s, d.status AS st, d.rule_key AS r, COUNT(*) AS n FROM ' . $from . ' WHERE ' . $w . ' GROUP BY d.severity, d.status, d.rule_key', [...$fromParams, ...$p]);
        }
        foreach ($ruleGroups as $g) {
            $n = (int) $g['n'];
            $sev = $filter->accepts('severity', $g['s']);
            $st = $filter->accepts('status', $g['st']);
            $rule = $filter->accepts('rule', $g['r']);
            if ($sev && $st && $rule) {
                $out['total'] += $n;
            }
            if ($st && $rule) {
                $out['severity'][(string) $g['s']] += $n;
            }
            if ($sev && $rule) {
                $out['status'][(string) $g['st']] += $n;
            }
            if ($sev && $st) {
                $rules[(string) $g['r']] = ($rules[(string) $g['r']] ?? 0) + $n;
            }
        }
        // 규칙: 건수 많은 순 상위 $ruleLimit개 + 선택한 규칙(건수 0이어도 화면에서 선택을 풀 수 있게)
        uksort($rules, static fn (string $a, string $b): int => [$rules[$b], $a] <=> [$rules[$a], $b]);
        $rules = array_slice($rules, 0, $ruleLimit, true);
        foreach ($filter->rules as $selected) {
            $rules[$selected] ??= 0;
        }
        $out['rules'] = [];
        foreach ($rules as $k => $n) {
            $out['rules'][] = ['rule_key' => (string) $k, 'count' => $n];
        }
        // 담당자: 미지정(null) + 결함이 있는 담당자(비활성 포함, 비활성 표시)
        $counts = [];
        if ($groups !== null) {
            $assigneeGroups = array_values(array_filter($groups, static fn (array $g): bool => $filter->accepts('rule', (string) $g['r'])));
        } else {
            [$w, $p] = $filter->where($projectId, ['assignee', 'severity', 'status']);
            $assigneeGroups = $this->db->all('SELECT d.severity AS s, d.status AS st, d.assignee_id AS a, COUNT(*) AS n FROM ' . $from . ' WHERE ' . $w . ' GROUP BY d.severity, d.status, d.assignee_id', [...$fromParams, ...$p]);
        }
        foreach ($assigneeGroups as $g) {
            if ($filter->accepts('severity', $g['s']) && $filter->accepts('status', $g['st'])) {
                $key = $g['a'] === null ? 0 : (int) $g['a'];
                $counts[$key] = ($counts[$key] ?? 0) + (int) $g['n'];
            }
        }
        $out['assignees'] = [];
        if (isset($counts[0])) {
            $out['assignees'][] = ['id' => null, 'count' => $counts[0]];
            unset($counts[0]);
        }
        if ($counts !== []) {
            $users = $this->db->all('SELECT id, login_id, display_name, status FROM users WHERE id IN (SELECT value FROM json_each(?)) ORDER BY display_name, id',
                [json_encode(array_keys($counts))]);
            foreach ($users as $u) {
                $out['assignees'][] = ['id' => (int) $u['id'], 'login_id' => $u['login_id'], 'display_name' => $u['display_name'],
                    'active' => $u['status'] === 'active', 'count' => $counts[(int) $u['id']]];
            }
        }
        return $out;
    }

    /**
     * 필터 없는 과거 Run의 요약(P20R): 심각도·상태·담당자·총계는 저장된 Run 요약, 규칙은 존재 집합에서 센다(규칙은 Run마다 바뀌지 않음).
     * summary()와 같은 모양을 돌려준다(필터가 없으면 각 칩의 "자기 필터만 뺀" 건수 = 전체 건수).
     *
     * @param array{total:int, by_status:array<string,int>, by_severity:array<string,int>, by_assignee:array<string,int>} $stored
     * @param array{0:string, 1:list<mixed>} $member RunService::memberSource
     * @return array<string, mixed>
     */
    public function summaryStored(int $projectId, array $stored, array $member, int $ruleLimit): array
    {
        $out = [
            'total' => (int) $stored['total'],
            'severity' => array_map('intval', array_replace(array_fill_keys(DefectFilter::SEVERITIES, 0), array_intersect_key($stored['by_severity'], array_flip(DefectFilter::SEVERITIES)))),
            'status' => array_map('intval', array_replace(array_fill_keys(DefectFilter::STATUSES, 0), array_intersect_key($stored['by_status'], array_flip(DefectFilter::STATUSES)))),
            'rules' => array_map(static fn (array $r): array => ['rule_key' => (string) $r['k'], 'count' => (int) $r['n']], $this->db->all(
                'SELECT d.rule_key AS k, COUNT(*) AS n FROM ' . $member[0] . ' WHERE d.project_id = ? GROUP BY d.rule_key ORDER BY n DESC, d.rule_key LIMIT ?',
                [...$member[1], $projectId, $ruleLimit])),
            'assignees' => [],
        ];
        $counts = $stored['by_assignee'];
        if (isset($counts['none'])) {
            $out['assignees'][] = ['id' => null, 'count' => (int) $counts['none']];
            unset($counts['none']);
        }
        if ($counts !== []) {
            $users = $this->db->all('SELECT id, login_id, display_name, status FROM users WHERE id IN (SELECT value FROM json_each(?)) ORDER BY display_name, id',
                [json_encode(array_map('intval', array_keys($counts)))]);
            foreach ($users as $u) {
                $out['assignees'][] = ['id' => (int) $u['id'], 'login_id' => $u['login_id'], 'display_name' => $u['display_name'],
                    'active' => $u['status'] === 'active', 'count' => (int) $counts[(string) $u['id']]];
            }
        }
        return $out;
    }

    /**
     * 상세. $runView(과거 Run)가 있으면 그 Run에 존재했는지(in_run)와 구간, Run 시점 상태·담당자·심각도. 존재하지 않아도 200(in_run = false).
     *
     * @param array{run:int, mark:int, analysis_id:int, latest:int}|null $runView
     * @return array<string, mixed>
     */
    public function detail(int $id, ?array $runView = null): array
    {
        $r = $this->db->one(
            'SELECT d.*, u.login_id AS assignee_login, u.display_name AS assignee_name, u.status AS assignee_status,'
            . ' fa.tool_version AS first_tool_version, fa.finished_at AS first_finished_at,'
            . ' la.tool_version AS last_tool_version, la.finished_at AS last_finished_at, ' . DefectSeen::expr('d') . ' AS seen_analysis_id'
            . ' FROM defects d JOIN analyses fa ON fa.id = d.first_seen_analysis_id JOIN analyses la ON la.id = ' . DefectSeen::expr('d')
            . ' LEFT JOIN users u ON u.id = d.assignee_id WHERE d.id = ?',
            [$id],
        );
        if ($r === null) {
            throw new ApiException(404, 'defect_not_found');
        }
        // P19C: 마지막 감지·수정 시각은 DefectSeen의 읽기 값(유지 결함은 행마다 쓰지 않음 — 값은 이전과 같다)
        $r['last_seen_analysis_id'] = $r['seen_analysis_id'];
        $r['updated_at'] = DefectSeen::updatedAt($r, $r['last_finished_at']);
        $runs = $this->runs?->runsOf([(int) $r['first_seen_analysis_id'], (int) $r['last_seen_analysis_id']]) ?? [];
        $intervals = $this->runs?->intervals($id) ?? [];
        $out = $this->format($r) + [
            // P22: 규칙이 활성 도구 버전에 없음(결함 상세의 규칙 링크 옆 표시)
            'rule_missing' => isset(\PJGuardian\Services\Rules\RuleQuery::missingIn($this->db, [(string) $r['rule_key']])[(string) $r['rule_key']]),
            'first_seen' => ['analysis_id' => (int) $r['first_seen_analysis_id'], 'run' => $runs[(int) $r['first_seen_analysis_id']] ?? null, 'tool_version' => $r['first_tool_version'], 'finished_at' => $r['first_finished_at']],
            'last_seen' => ['analysis_id' => (int) $r['last_seen_analysis_id'], 'run' => $runs[(int) $r['last_seen_analysis_id']] ?? null, 'tool_version' => $r['last_tool_version'], 'finished_at' => $r['last_finished_at']],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'runs' => $intervals,
            'run_view' => null,
        ];
        if ($runView !== null && $this->runs !== null) {
            $out = $this->overlay([$out], $runView)[0];
            $out['run_view'] = ['run' => $runView['run'], 'latest' => $runView['latest'], 'in_run' => RunService::covers($intervals, $runView['run']), 'read_only' => true];
        }
        return $out;
    }

    /**
     * 과거 Run: 상태·담당자·심각도를 그 Run 시점 값으로 덮는다(줄·메시지는 최신 감지 값 그대로).
     *
     * @param list<array<string, mixed>> $rows format() 결과
     * @param array{run:int, mark:int} $runView
     * @return list<array<string, mixed>>
     */
    private function overlay(array $rows, array $runView): array
    {
        $vals = $this->runs?->restoredValues(array_column($rows, 'id'), $runView['mark']) ?? [];
        $users = [];
        $need = array_values(array_unique(array_filter(array_map(static fn (array $v): ?int => $v['assignee_id'], $vals))));
        if ($need !== []) {
            foreach ($this->db->all('SELECT id, login_id, display_name, status FROM users WHERE id IN (SELECT value FROM json_each(?))', [json_encode($need)]) as $u) {
                $users[(int) $u['id']] = $u;
            }
        }
        foreach ($rows as &$row) {
            $v = $vals[$row['id']] ?? null;
            if ($v === null) {
                continue;
            }
            $row['severity'] = $v['severity'];
            $row['severity_source'] = $v['severity_source'];
            $row['status'] = $v['status'];
            $a = $v['assignee_id'];
            $row['assignee'] = $a === null ? null : [
                'id' => $a, 'login_id' => $users[$a]['login_id'] ?? null, 'display_name' => $users[$a]['display_name'] ?? null,
                'active' => ($users[$a]['status'] ?? null) === 'active', 'has_access' => $this->assigneeCanView($a, (int) $row['project_id']),
            ];
            $row['location_is_latest'] = true;
        }
        unset($row);
        return $rows;
    }

    /**
     * 변경 이력(최신순). 담당자 변경의 이전·이후 값(사용자 id)은 사용자 정보로 바꿔 준다(비활성 표시 포함).
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function history(int $defectId, int $limit, int $offset): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM defect_history WHERE defect_id = ?', [$defectId]);
        $rows = $this->db->all(
            "SELECT h.*, cu.login_id AS by_login, cu.display_name AS by_name, cu.status AS by_status,"
            . " ou.login_id AS old_login, ou.display_name AS old_name, ou.status AS old_status,"
            . " nu.login_id AS new_login, nu.display_name AS new_name, nu.status AS new_status"
            . " FROM defect_history h LEFT JOIN users cu ON cu.id = h.changed_by"
            . " LEFT JOIN users ou ON h.field = 'assignee' AND ou.id = CAST(h.old_value AS INTEGER)"
            . " LEFT JOIN users nu ON h.field = 'assignee' AND nu.id = CAST(h.new_value AS INTEGER)"
            . ' WHERE h.defect_id = ? ORDER BY h.id DESC LIMIT ? OFFSET ?',
            [$defectId, $limit, $offset],
        );
        $user = static fn (?string $login, ?string $name, ?string $status, mixed $id): ?array => $login === null ? null
            : ['id' => (int) $id, 'login_id' => $login, 'display_name' => $name, 'active' => $status === 'active'];
        $items = [];
        foreach ($rows as $h) {
            $isAssignee = $h['field'] === 'assignee';
            $items[] = [
                'id' => (int) $h['id'],
                'field' => (string) $h['field'],
                'old_value' => $h['old_value'],
                'new_value' => $h['new_value'],
                'old_user' => $isAssignee ? $user($h['old_login'], $h['old_name'], $h['old_status'], $h['old_value']) : null,
                'new_user' => $isAssignee ? $user($h['new_login'], $h['new_name'], $h['new_status'], $h['new_value']) : null,
                'kind' => (string) $h['kind'],
                'reason_code' => $h['reason_code'],
                'memo' => $h['memo'],
                'analysis_id' => $h['analysis_id'] === null ? null : (int) $h['analysis_id'],
                'changed_at' => $h['changed_at'],
                'changed_by' => $user($h['by_login'], $h['by_name'], $h['by_status'], $h['changed_by']),
            ];
        }
        return [$items, $total];
    }

    /**
     * 내게 지정된 결함(명세 17, P18): 담당자가 나이고 **지금 볼 수 있는 프로젝트**의 결함. 상태·프로젝트 필터, 상태 순(open → … → resolved) 다음 최근 감지 순.
     *
     * @param array<string, mixed> $user
     * @param list<string> $statuses
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function mine(array $user, ?int $projectId, array $statuses, int $limit, int $offset): array
    {
        [$where, $params] = $this->mineWhere($user, $projectId, $statuses);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM defects d JOIN projects p ON p.id = d.project_id WHERE ' . $where, $params);
        $rows = $this->db->all(
            'SELECT d.id, p.name AS project_name FROM defects d JOIN projects p ON p.id = d.project_id WHERE ' . $where
            . ' ORDER BY ' . self::mineOrder() . ' LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );
        $names = array_column($rows, 'project_name', 'id');
        $items = [];
        foreach ($this->rowsByIds(array_map('intval', array_column($rows, 'id'))) as $d) {
            $items[] = $d + ['project' => ['id' => $d['project_id'], 'name' => (string) $names[$d['id']]]];
        }
        return [$items, $total];
    }

    /**
     * 내게 지정된 결함의 상태별·프로젝트별 건수(필터 없음).
     *
     * @param array<string, mixed> $user
     * @return array{status: array<string, int>, projects: list<array{id:int, name:string, count:int}>, total:int}
     */
    public function mineSummary(array $user): array
    {
        [$where, $params] = $this->mineWhere($user, null, []);
        $out = ['status' => array_fill_keys(DefectFilter::STATUSES, 0), 'projects' => [], 'total' => 0];
        foreach ($this->db->all('SELECT d.status AS k, COUNT(*) AS n FROM defects d JOIN projects p ON p.id = d.project_id WHERE ' . $where . ' GROUP BY d.status', $params) as $r) {
            $out['status'][(string) $r['k']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        foreach ($this->db->all('SELECT p.id, p.name, COUNT(*) AS n FROM defects d JOIN projects p ON p.id = d.project_id WHERE ' . $where
            . ' GROUP BY p.id, p.name ORDER BY n DESC, p.name COLLATE NOCASE', $params) as $r) {
            $out['projects'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string> $statuses
     * @return array{0: string, 1: list<mixed>}
     */
    private function mineWhere(array $user, ?int $projectId, array $statuses): array
    {
        if ($this->access === null) {
            throw new \LogicException('ProjectAccess 필요');
        }
        // 볼 수 있는 프로젝트 조건은 ProjectAccess::scopeFor(판정 한 곳, 명세 3.1)
        [$scope, $scopeParams] = $this->access->scopeFor($user, 'p');
        $where = 'd.assignee_id = ? AND ' . $scope;
        $params = [(int) $user['id'], ...$scopeParams];
        if ($projectId !== null) {
            $where .= ' AND d.project_id = ?';
            $params[] = $projectId;
        }
        if ($statuses !== []) {
            $where .= ' AND d.status IN (SELECT value FROM json_each(?))';
            $params[] = json_encode($statuses);
        }
        return [$where, $params];
    }

    private function assigneeCanView(int $userId, int $projectId): ?bool
    {
        if ($this->access === null) {
            return null;
        }
        return $this->accessCache[$userId . ':' . $projectId] ??= $this->access->userCanView($userId, $projectId);
    }

    /**
     * @param list<int> $ids 순서대로
     * @return list<array<string, mixed>>
     */
    private function rowsByIds(array $ids, ?array $runView = null): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT d.*, u.login_id AS assignee_login, u.display_name AS assignee_name, u.status AS assignee_status, la.finished_at AS last_finished_at,'
            . ' ' . DefectSeen::expr('d') . ' AS seen_analysis_id'
            . ' FROM defects d JOIN analyses la ON la.id = ' . DefectSeen::expr('d') . ' LEFT JOIN users u ON u.id = d.assignee_id'
            . ' WHERE d.id IN (SELECT value FROM json_each(?))',
            [json_encode($ids)],
        );
        $byId = [];
        foreach ($rows as $r) {
            $r['last_seen_analysis_id'] = $r['seen_analysis_id']; // P19C: DefectSeen 읽기 값
            $byId[(int) $r['id']] = $this->format($r) + ['last_seen' => ['analysis_id' => (int) $r['last_seen_analysis_id'], 'finished_at' => $r['last_finished_at']]];
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        if ($this->runs !== null && $out !== []) {
            $runs = $this->runs->runsOf(array_map(static fn (array $r): int => $r['last_seen']['analysis_id'], $out));
            foreach ($out as &$o) {
                $o['last_seen']['run'] = $runs[$o['last_seen']['analysis_id']] ?? null;
            }
            unset($o);
        }
        foreach ($out as &$o) {
            $o += self::lastDetected($o['last_seen'], $runView);
        }
        unset($o);
        // P21B: Run 열의 Run이 상세 삭제됐는지(툴팁 안내, 링크는 히스토리 비교 패널이라 그대로)
        if ($out !== []) {
            $deleted = array_flip(array_map('intval', array_column($this->db->all(
                'SELECT id FROM analyses WHERE run_detail_deleted_at IS NOT NULL AND id IN (SELECT value FROM json_each(?))',
                [json_encode(array_values(array_unique(array_column($out, 'last_detected_analysis_id'))))]), 'id')));
            foreach ($out as &$o) {
                $o['last_detected_detail_deleted'] = isset($deleted[$o['last_detected_analysis_id']]);
            }
            unset($o);
            // P22: 규칙이 활성 도구 버전에 없음(예전 결함). 결함 표의 규칙 링크 옆 표시
            $missing = \PJGuardian\Services\Rules\RuleQuery::missingIn($this->db, array_column($out, 'rule_key'));
            foreach ($out as &$o) {
                $o['rule_missing'] = isset($missing[$o['rule_key']]);
            }
            unset($o);
        }
        return $runView !== null ? $this->overlay($out, $runView) : $out;
    }

    /**
     * 결함 표의 "Run"·"최근 감지" 열 값(P21U): 마지막으로 감지한 분석의 Run 번호·종료 시각·분석 id.
     * 과거 Run 보기(Run N)에서 마지막 감지 Run이 N보다 크면 N으로 한정한다("Run N 이하의 마지막 감지" — 결함 표에는 최신 감지만 있어
     * Run N 시점의 값을 복원할 수 없다. 이 결함은 Run N에 존재했으므로 N으로 읽는다). Run 번호가 없으면(되채움 전) run은 null.
     *
     * @param array{analysis_id:int, finished_at:?string, run?:?int} $lastSeen
     * @param array{run:int, analysis_id:int, finished_at:?string}|null $runView
     * @return array{last_detected_run:?int, last_detected_at:?string, last_detected_analysis_id:int}
     */
    public static function lastDetected(array $lastSeen, ?array $runView = null): array
    {
        $run = $lastSeen['run'] ?? null;
        if ($runView !== null && $run !== null && $run > (int) $runView['run']) {
            return ['last_detected_run' => (int) $runView['run'], 'last_detected_at' => $runView['finished_at'] ?? null, 'last_detected_analysis_id' => (int) $runView['analysis_id']];
        }
        return ['last_detected_run' => $run, 'last_detected_at' => $lastSeen['finished_at'], 'last_detected_analysis_id' => (int) $lastSeen['analysis_id']];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'project_id' => (int) $r['project_id'],
            'tool' => (string) $r['tool'],
            'language' => (string) $r['language'],
            'rule_key' => (string) $r['rule_key'],
            'rule_url' => RuleLinks::path((string) $r['rule_key']),
            'file_path' => (string) $r['file_path'],
            'line' => (int) $r['line_no'],
            'column' => $r['col_no'] === null ? null : (int) $r['col_no'],
            'end_line' => $r['end_line_no'] === null ? null : (int) $r['end_line_no'],
            'end_column' => $r['end_col_no'] === null ? null : (int) $r['end_col_no'],
            'message' => (string) $r['message'],
            'severity' => (string) $r['severity'],
            // P21S: 심각도 출처 none(부여된 적 없음) | auto(규칙 기본값) | manual(사람이 바꿈)
            'severity_source' => (string) ($r['severity_source'] ?? 'none'),
            'status' => (string) $r['status'],
            'assignee' => $r['assignee_id'] === null ? null : [
                'id' => (int) $r['assignee_id'],
                'login_id' => $r['assignee_login'],
                'display_name' => $r['assignee_name'],
                'active' => $r['assignee_status'] === 'active',
                // 담당은 유지하되 프로젝트 접근을 잃었으면 false(명세 3.1: "접근 권한 없음" 표시, 알림 대상 제외). 판정은 ProjectAccess
                'has_access' => $this->assigneeCanView((int) $r['assignee_id'], (int) $r['project_id']),
            ],
            'version' => (int) $r['version'],
        ];
    }
}
