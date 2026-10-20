<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

use PJGuardian\Http\ApiException;

/**
 * 결함 목록의 필터·검색·정렬(명세 6.4, P17). 요청 쿼리를 검증해 SQL 조건과 매개변수를 만든다.
 *
 * - SQL에 들어가는 문자열은 이 클래스의 상수(열 이름·정렬식 허용 목록)뿐이다. 사용자 값은 모두 매개변수(?)다.
 * - 다중 선택 값은 JSON 배열 한 개의 매개변수로 넘기고 `IN (SELECT value FROM json_each(?))`로 비교한다(값 개수만큼 SQL을 늘리지 않음).
 * - LIKE 검색은 `%`, `_`, `\`를 이스케이프하고 `ESCAPE '\'`를 붙인다(입력한 글자 그대로 부분 일치).
 * - 파일 접두사는 LIKE 대신 범위 조건(file_path >= p AND file_path < p의 마지막 바이트 + 1)으로 인덱스를 쓴다(docs/db_schema.md 5절).
 *
 * 쿼리 매개변수(모두 선택):
 *   rule      규칙 키, 쉼표 구분(최대 50개)        severity  critical|high|medium|low|not_defined, 쉼표 구분
 *   status    open|in_progress|postponed|intentional|not_fix|resolved, 쉼표 구분
 *   assignee  사용자 id 또는 none(미지정), 쉼표 구분  file      경로(앞뒤 공백 제거, \ → /, NFC)
 *   file_mode prefix(기본) | contains                q         검색어(메시지, 규칙 키, 파일 경로의 부분 일치)
 *   sort      file(기본) | rule | severity | status | run | last_seen      order asc(기본) | desc
 *             run = 마지막 감지 Run 번호, last_seen = 마지막 감지 분석의 종료 시각(P21U). 과거 Run 보기에서는 둘 다 그 Run으로 한정한 값
 *   analysis + change(P19): 분석 id와 new|resolved|reopened — 그 분석에서 신규(처음 감지)·해결(자동 resolved)·재오픈된 결함(분석 결과 요약의 링크)
 */
final class DefectFilter
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'not_defined'];
    public const STATUSES = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'];
    public const SORTS = ['file', 'rule', 'severity', 'status', 'run', 'last_seen'];
    public const FACETS = ['rule', 'severity', 'status', 'assignee'];

    private const MAX_VALUES = 50;
    private const MAX_TEXT = 200;
    private const MAX_FILE = 1000;

    /**
     * 정렬식 허용 목록(사용자 값은 이 표의 키로만 고른다). 심각도·상태는 화면에서 보이는 순서(심각한 것부터, 진행 순서)로.
     * 마지막의 id는 같은 값끼리 순서를 고정한다(페이지가 바뀌어도 행이 섞이지 않음).
     */
    private const ORDER_SQL = [
        'file' => ['d.file_path %s, d.line_no %s, d.id %s'],
        'rule' => ['d.rule_key %s, d.id %s'],
        'severity' => ["CASE d.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END %s, d.id %s"],
        'status' => ["CASE d.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'postponed' THEN 2 WHEN 'intentional' THEN 3 WHEN 'not_fix' THEN 4 ELSE 5 END %s, d.id %s"],
        // P21U: 마지막 감지 분석의 Run 번호·종료 시각({run}·{at}은 orderBy()가 채운다 — 과거 Run 보기면 그 Run으로 한정)
        'run' => ['{run} %s, d.id %s'],
        'last_seen' => ['{at} %s, d.id %s'],
    ];

    /**
     * @param list<string> $rules
     * @param list<string> $severities
     * @param list<string> $statuses
     * @param list<int> $assignees
     */
    private function __construct(
        public readonly array $rules,
        public readonly array $severities,
        public readonly array $statuses,
        public readonly array $assignees,
        public readonly bool $unassigned,
        public readonly ?string $file,
        public readonly string $fileMode,
        public readonly ?string $q,
        public readonly string $sort,
        public readonly string $order,
        public readonly ?int $analysisId = null,
        public readonly ?string $change = null,
        // P21: "Run N에서 새로 생김"(처음 감지가 Run N), "Run N~M에 존재"(그 범위의 Run 중 한 번이라도 존재, defect_runs 구간)
        public readonly ?int $fromRun = null,
        public readonly ?int $inRunFrom = null,
        public readonly ?int $inRunTo = null,
    ) {
    }

    /** @param array<string, mixed> $query 요청 쿼리 문자열 값 */
    public static function fromQuery(array $query): self
    {
        $rules = [];
        foreach (self::list($query, 'rule') as $r) {
            if (mb_strlen($r) > self::MAX_TEXT || preg_match('/[\x00-\x1F\x7F]/', $r) === 1) {
                throw new ApiException(400, 'invalid_defect_filter', ['field' => 'rule']);
            }
            $rules[$r] = true;
        }
        $severities = self::enum($query, 'severity', self::SEVERITIES);
        $statuses = self::enum($query, 'status', self::STATUSES);
        $assignees = [];
        $unassigned = false;
        foreach (self::list($query, 'assignee') as $a) {
            if ($a === 'none') {
                $unassigned = true;
            } elseif (preg_match('/^[1-9][0-9]{0,17}$/', $a) === 1) {
                $assignees[(int) $a] = true;
            } else {
                throw new ApiException(400, 'invalid_defect_filter', ['field' => 'assignee']);
            }
        }
        $file = self::text($query, 'file', self::MAX_FILE);
        if ($file !== null) {
            // 저장된 경로와 같은 형식으로: '/' 구분, NFC, 앞의 './'·'/' 제거
            $file = str_replace('\\', '/', $file);
            $file = (string) preg_replace('#^(\./|/)+#', '', $file);
            $nfc = \Normalizer::normalize($file, \Normalizer::FORM_C);
            $file = is_string($nfc) && $nfc !== '' ? $nfc : ($file !== '' ? $file : null);
        }
        $mode = self::choice($query, 'file_mode', ['prefix', 'contains'], 'prefix');
        $q = self::text($query, 'q', self::MAX_TEXT);
        $sort = self::choice($query, 'sort', self::SORTS, 'file');
        $order = self::choice($query, 'order', ['asc', 'desc'], 'asc');
        // 분석 변경 필터: 둘 다 있거나 둘 다 없어야 한다
        $analysis = $query['analysis'] ?? null;
        $change = $query['change'] ?? null;
        $analysisId = null;
        if (($analysis !== null && $analysis !== '') || ($change !== null && $change !== '')) {
            if (!is_string($analysis) || preg_match('/^[1-9][0-9]{0,17}$/', $analysis) !== 1) {
                throw new ApiException(400, 'invalid_defect_filter', ['field' => 'analysis']);
            }
            if (!in_array($change, ['new', 'resolved', 'reopened'], true)) {
                throw new ApiException(400, 'invalid_defect_filter', ['field' => 'change']);
            }
            $analysisId = (int) $analysis;
        } else {
            $change = null;
        }
        $fromRun = self::runNumber($query, 'from_run');
        $inFrom = self::runNumber($query, 'in_run_from');
        $inTo = self::runNumber($query, 'in_run_to');
        if ($inFrom !== null || $inTo !== null) {
            // 한쪽만 주면 그 Run 하나
            $inFrom ??= $inTo;
            $inTo ??= $inFrom;
            if ($inFrom > $inTo) {
                throw new ApiException(400, 'invalid_defect_filter', ['field' => 'in_run_to']);
            }
        }
        return new self(array_keys($rules), $severities, $statuses, array_keys($assignees), $unassigned, $file, $mode, $q, $sort, $order, $analysisId, $change,
            $fromRun, $inFrom, $inTo);
    }

    private static function runNumber(array $query, string $name): ?int
    {
        $v = $query[$name] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match('/^[1-9][0-9]{0,8}$/', $v) !== 1) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => $name]);
        }
        return (int) $v;
    }

    /**
     * 프로젝트 조건 + 필터 조건. $except에 준 필터(FACETS 이름 하나 또는 목록)는 뺀다(필터 요약의 항목별 건수).
     *
     * @param string|list<string>|null $except
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(int $projectId, string|array|null $except = null): array
    {
        $skip = array_fill_keys((array) $except, true);
        $sql = ['d.project_id = ?'];
        $params = [$projectId];
        if (!isset($skip['rule']) && $this->rules !== []) {
            $sql[] = 'd.rule_key IN (SELECT value FROM json_each(?))';
            $params[] = json_encode($this->rules, JSON_UNESCAPED_UNICODE);
        }
        if (!isset($skip['severity']) && $this->severities !== []) {
            $sql[] = 'd.severity IN (SELECT value FROM json_each(?))';
            $params[] = json_encode($this->severities);
        }
        if (!isset($skip['status']) && $this->statuses !== []) {
            $sql[] = 'd.status IN (SELECT value FROM json_each(?))';
            $params[] = json_encode($this->statuses);
        }
        if (!isset($skip['assignee']) && ($this->assignees !== [] || $this->unassigned)) {
            $parts = [];
            if ($this->assignees !== []) {
                $parts[] = 'd.assignee_id IN (SELECT value FROM json_each(?))';
                $params[] = json_encode($this->assignees);
            }
            if ($this->unassigned) {
                $parts[] = 'd.assignee_id IS NULL';
            }
            $sql[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($this->file !== null) {
            if ($this->fileMode === 'prefix') {
                // 범위 조건: 접두사 p로 시작하는 문자열 = [p, p의 마지막 바이트 + 1). UTF-8에는 0xFF 바이트가 없어 넘치지 않는다
                $sql[] = 'd.file_path >= ? AND d.file_path < ?';
                $params[] = $this->file;
                $params[] = substr($this->file, 0, -1) . chr(ord($this->file[strlen($this->file) - 1]) + 1);
            } else {
                $sql[] = "d.file_path LIKE ? ESCAPE '\\'";
                $params[] = '%' . self::escapeLike($this->file) . '%';
            }
        }
        if ($this->analysisId !== null) {
            // 분석 결과 요약의 건수와 같은 정의(P16 DefectStore): 신규 = 그 분석에서 처음 감지, 해결 = 그 분석의 자동 resolved,
            // 재오픈 = 그 분석에서 다시 감지되어 open. 다른 프로젝트의 분석 id면 project_id 조건 때문에 0건
            if ($this->change === 'new') {
                // P21: 처음 감지 인덱스(defects_first_seen)로 먼저 고른다(프로젝트 인덱스로 결함 표 전체를 훑지 않게, 결과는 같다)
                $sql[] = 'd.id IN (SELECT n.id FROM defects n WHERE n.first_seen_analysis_id = ?)';
                $params[] = $this->analysisId;
            } else {
                $sql[] = 'd.id IN (SELECT h.defect_id FROM defect_history h WHERE h.analysis_id = ? AND h.reason_code IN (SELECT value FROM json_each(?)))';
                $params[] = $this->analysisId;
                $params[] = json_encode($this->change === 'resolved' ? ['not_detected', 'file_deleted'] : ['redetected', 'still_detected']);
            }
        }
        if ($this->fromRun !== null) {
            // Run N에서 새로 생김 = 처음 감지한 분석이 Run N(신규 정의와 같음)
            $sql[] = 'd.id IN (SELECT n.id FROM defects n WHERE n.first_seen_analysis_id IN (SELECT a.id FROM analyses a WHERE a.project_id = ? AND a.run_number = ?))';
            $params[] = $projectId;
            $params[] = $this->fromRun;
        }
        if ($this->inRunFrom !== null) {
            // Run N~M에 존재 = 존재 구간이 [N, M]과 겹침(그 사이 Run 중 한 번이라도 존재)
            $sql[] = 'EXISTS (SELECT 1 FROM defect_runs r WHERE r.defect_id = d.id AND r.from_run <= ? AND (r.to_run IS NULL OR r.to_run >= ?))';
            $params[] = $this->inRunTo;
            $params[] = $this->inRunFrom;
        }
        if ($this->q !== null) {
            $like = '%' . self::escapeLike($this->q) . '%';
            $sql[] = "(d.message LIKE ? ESCAPE '\\' OR d.rule_key LIKE ? ESCAPE '\\' OR d.file_path LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }
        return [implode(' AND ', $sql), $params];
    }

    /**
     * ORDER BY 식(허용 목록에서만 만든다)과 그 매개변수.
     *
     * 과거 Run 보기($runView)의 run·last_seen 정렬은 화면 값과 같게 "Run N 이하의 마지막 감지"로 한정한다:
     * 마지막 감지 Run이 N보다 크면 N(시각은 Run N 분석의 종료 시각). 결함 표에는 Run N 시점의 마지막 감지가 남아 있지 않기 때문이다.
     *
     * @param array{run:int, finished_at:?string}|null $runView
     * @param array{run:?int, at:?string}|null $live 한 프로젝트 목록이면 DefectSeen::liveValues(감지 중 행의 값 — 행마다 하위 질의를 피함)
     * @return array{0:string, 1:list<mixed>}
     */
    public function orderBy(?array $runView = null, ?array $live = null, bool $reverse = false): array
    {
        // $reverse(P31): 모든 키의 방향을 뒤집은 순서 — 키가 모두 같은 방향이고 마지막이 d.id(유일)라 정확히 거꾸로 된 순서다
        // (SQLite에서 NULL은 가장 작은 값이라 오름차순 맨 앞 ↔ 내림차순 맨 뒤). 뒤쪽 페이지를 앞에서 고를 때 쓴다(DefectQuery::list)
        $dir = ($this->order === 'desc') !== $reverse ? 'DESC' : 'ASC';
        $tpl = self::ORDER_SQL[$this->sort][0];
        $params = [];
        if (str_contains($tpl, '{run}')) {
            [$expr, $params] = DefectSeen::analysisColumn('run_number', $live); // P19C: 마지막 감지 읽기는 DefectSeen 한 곳
            if ($runView !== null) {
                $expr = 'MIN(' . $expr . ', CAST(? AS INTEGER))';
                $params[] = (int) $runView['run'];
            }
            $tpl = str_replace('{run}', $expr, $tpl);
        }
        if (str_contains($tpl, '{at}')) {
            [$expr, $atParams] = DefectSeen::analysisColumn('finished_at', $live);
            array_push($params, ...$atParams);
            if ($runView !== null && ($runView['finished_at'] ?? null) !== null) {
                $expr = 'MIN(' . $expr . ', ?)';
                $params[] = (string) $runView['finished_at'];
            }
            $tpl = str_replace('{at}', $expr, $tpl);
        }
        return [sprintf($tpl, ...array_fill(0, substr_count($tpl, '%s'), $dir)), $params];
    }

    /** @return array<string, mixed> 응답에 되돌려 주는 정규화된 필터(화면이 URL에 반영) */
    public function toArray(): array
    {
        return [
            'rule' => $this->rules,
            'severity' => $this->severities,
            'status' => $this->statuses,
            'assignee' => array_merge($this->unassigned ? ['none'] : [], array_map('strval', $this->assignees)),
            'file' => $this->file,
            'file_mode' => $this->fileMode,
            'q' => $this->q,
            'sort' => $this->sort,
            'order' => $this->order,
            'analysis' => $this->analysisId,
            'change' => $this->change,
            'from_run' => $this->fromRun,
            'in_run_from' => $this->inRunFrom,
            'in_run_to' => $this->inRunTo,
        ];
    }

    public static function escapeLike(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** @return list<string> 쉼표로 나눈 값(빈 값 제외). 같은 이름을 여러 번 주거나 배열이면 거부 */
    private static function list(array $query, string $name): array
    {
        $v = $query[$name] ?? null;
        if ($v === null || $v === '') {
            return [];
        }
        if (!is_string($v)) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => $name]);
        }
        $out = [];
        foreach (explode(',', $v) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        if (count($out) > self::MAX_VALUES) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => $name]);
        }
        return $out;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function enum(array $query, string $name, array $allowed): array
    {
        $out = [];
        foreach (self::list($query, $name) as $v) {
            if (!in_array($v, $allowed, true)) {
                throw new ApiException(400, 'invalid_defect_filter', ['field' => $name]);
            }
            $out[$v] = true;
        }
        return array_keys($out);
    }

    /** 다중 선택 필터 하나가 이 값을 통과하는지(필터가 비었으면 모두 통과). 필터 요약의 PHP 집계용 */
    /** 필터나 정렬이 과거 Run에서 복원하는 값(상태·담당자·심각도)을 쓰는가(P20S: 안 쓰면 복원 없이 고를 수 있다) */
    public function usesRunValues(): bool
    {
        return $this->severities !== [] || $this->statuses !== [] || $this->assignees !== [] || $this->unassigned
            || in_array($this->sort, ['severity', 'status'], true);
    }

    /** 조건이 하나도 없는가(정렬·순서는 조건이 아님) */
    public function isEmpty(): bool
    {
        return $this->rules === [] && $this->severities === [] && $this->statuses === [] && $this->assignees === [] && !$this->unassigned
            && $this->file === null && $this->q === null && $this->analysisId === null && $this->change === null
            && $this->fromRun === null && $this->inRunFrom === null;
    }

    public function accepts(string $facet, ?string $value): bool
    {
        return match ($facet) {
            'rule' => $this->rules === [] || in_array($value, $this->rules, true),
            'severity' => $this->severities === [] || in_array($value, $this->severities, true),
            'status' => $this->statuses === [] || in_array($value, $this->statuses, true),
            'assignee' => ($this->assignees === [] && !$this->unassigned)
                || ($value === null ? $this->unassigned : in_array((int) $value, $this->assignees, true)),
            default => throw new \InvalidArgumentException($facet),
        };
    }

    private static function text(array $query, string $name, int $max): ?string
    {
        $v = $query[$name] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => $name]);
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => $name]);
        }
        return $v;
    }

    /** @param list<string> $allowed */
    private static function choice(array $query, string $name, array $allowed, string $default): string
    {
        $v = $query[$name] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new ApiException(400, $name === 'sort' || $name === 'order' ? 'invalid_sort' : 'invalid_defect_filter', ['field' => $name]);
        }
        return $v;
    }
}
