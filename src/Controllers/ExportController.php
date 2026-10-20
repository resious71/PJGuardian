<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Defects\DefectFilter;
use PJGuardian\Services\Export\ExportService;
use PJGuardian\Services\Rules\RuleQuery;
use PJGuardian\Support\Lang;
use PJGuardian\Support\SystemClock;
use PJGuardian\Support\ToolLanguage;

/**
 * 내보내기(P23, 명세 14): 결함 목록·내게 지정된 결함·규칙 설명 목록 → CSV·XLSX. POST + CSRF만(GET 없음).
 *
 * - 결함: 라우트가 RequireProjectAccess(보이지 않는 프로젝트 404)를 먼저 한다. 조건은 목록 API와 같은 검증기(DefectFilter::fromQuery,
 *   Run은 RunService::resolve — 삭제된 Run 410)로, 행은 목록과 같은 원본·조건·정렬·변환(DefectQuery::exportIds / rowsForExport).
 *   내게 지정된 결함은 같은 mineWhere(볼 수 있는 프로젝트만, ProjectAccess::scopeFor).
 * - 규칙: 로그인한 누구나(규칙은 프로젝트에 속하지 않음, 결함 수 같은 프로젝트 정보는 넣지 않음). 조건은 RuleQuery::filter, 행은 RuleQuery::list.
 * - 한 번의 내보내기는 읽기 트랜잭션 하나 안에서 센 뒤 읽는다(같은 시점의 결과 — 그 사이 분석이 반영돼도 섞이지 않음).
 * - 모든 오류(형식·조건·권한·상한·0건·동시 실행)는 파일을 만들기 전에 JSON 오류로 판정한다. 성공만 파일(Response::file — 보낸 뒤 임시 파일 삭제).
 * - 감사: export.defects·export.rules(형식·행 수·조건 요약·Run, 데이터 값 없음). 거부(상한 초과 등)는 남기지 않는다.
 */
final class ExportController
{
    private const CHUNK = 1000;
    private const DEFECT_KEYS = ['format', 'run', 'rule', 'severity', 'status', 'assignee', 'file', 'file_mode', 'q', 'sort', 'order', 'analysis', 'change',
        'from_run', 'in_run_from', 'in_run_to'];
    private const MINE_KEYS = ['format', 'project', 'status'];
    private const RULE_KEYS = ['format', 'language', 'tool', 'q', 'prefix', 'category', 'severity', 'analysis', 'overridden', 'default_enabled', 'fixable',
        'deprecated', 'change', 'sort', 'order'];

    public function __construct(private readonly App $app)
    {
    }

    /** POST /api/v1/projects/{id}/defects/export {format, …목록 조건, run?} */
    public function defects(Request $request): Response
    {
        $projectId = (int) $request->attribute('project_id');
        $body = self::body($request, self::DEFECT_KEYS, 'invalid_defect_filter');
        $format = ExportService::assertFormat($body['format'] ?? null);
        unset($body['format']);
        $filter = DefectFilter::fromQuery($body);
        $runs = $this->app->runs();
        $run = $runs->resolve($projectId, $body['run'] ?? null);
        $source = $run === null ? null : $runs->source($projectId, $run);
        $project = (string) $this->app->db()->value('SELECT name FROM projects WHERE id = ?', [$projectId]);
        $user = $request->attribute('user');
        $export = $this->app->exports();
        $slots = $export->acquire((int) $user['id']);
        try {
            $q = $this->app->defectQuery();
            $file = $this->app->db()->transaction(function () use ($q, $export, $projectId, $filter, $source, $run, $format, $project): array {
                $export->assertCount($q->count($projectId, $filter, $source));
                $ids = $q->exportIds($projectId, $filter, $source, $run);
                $rows = (function () use ($q, $ids, $run): \Generator {
                    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                        foreach ($q->rowsForExport($chunk, $run) as $d) {
                            yield self::defectRow($d, null);
                        }
                    }
                })();
                $info = $this->defectInfo($project, $filter, $run, count($ids));
                return $export->write($format, Lang::get('export.sheet.defects'), Lang::get('export.sheet.info'), self::defectColumns(false), $rows, $info);
            }, false);
        } finally {
            foreach ($slots as $l) {
                $l->release();
            }
        }
        $this->app->audit()->record(Actor::fromUser($user, $request->clientIp), 'export.defects', 'project', (string) $projectId, [
            'format' => $format, 'rows' => $file['rows'], 'run' => $run['run'] ?? null, 'filter' => self::summaryFilter($filter),
        ]);
        $name = 'PJGuardian_' . ExportService::namePart($project, 'project') . '_' . Lang::get('export.file.defects') . '_' . $this->stamp()
            . ($run !== null ? '_Run' . (int) $run['run'] : '') . '.' . $file['extension'];
        return Response::file($file['path'], $file['content_type'], ExportService::disposition($name))->withHeader('X-Export-Rows', (string) $file['rows']);
    }

    /** POST /api/v1/my-defects/export {format, project?, status?} */
    public function mine(Request $request): Response
    {
        $body = self::body($request, self::MINE_KEYS, 'invalid_defect_filter');
        $format = ExportService::assertFormat($body['format'] ?? null);
        $project = $body['project'] ?? null;
        if ($project !== null && $project !== '' && (!is_string($project) || preg_match('/^[1-9][0-9]{0,17}$/', $project) !== 1)) {
            throw new ApiException(400, 'invalid_defect_filter', ['field' => 'project']);
        }
        $projectId = $project === null || $project === '' ? null : (int) $project;
        $statuses = DefectFilter::fromQuery(['status' => $body['status'] ?? null])->statuses;
        $user = $request->attribute('user');
        $export = $this->app->exports();
        $slots = $export->acquire((int) $user['id']);
        try {
            $q = $this->app->defectQuery();
            $db = $this->app->db();
            $file = $db->transaction(function () use ($q, $db, $export, $user, $projectId, $statuses, $format): array {
                [$count] = $q->mineIds($user, $projectId, $statuses, false);
                $export->assertCount($count);
                [, $ids] = $q->mineIds($user, $projectId, $statuses, true);
                $rows = (function () use ($q, $db, $ids): \Generator {
                    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                        $rowsChunk = $q->rowsForExport($chunk);
                        $names = array_column($db->all('SELECT id, name FROM projects WHERE id IN (SELECT value FROM json_each(?))',
                            [json_encode(array_values(array_unique(array_column($rowsChunk, 'project_id'))))]), 'name', 'id');
                        foreach ($rowsChunk as $d) {
                            yield self::defectRow($d, (string) ($names[$d['project_id']] ?? ''));
                        }
                    }
                })();
                $projectName = $projectId === null ? Lang::get('export.info.all_projects') : (string) $db->value('SELECT name FROM projects WHERE id = ?', [$projectId]);
                $info = [
                    [Lang::get('export.info.exported_at'), $this->now()],
                    [Lang::get('export.info.scope'), Lang::get('export.info.mine')],
                    [Lang::get('export.info.project'), $projectName],
                    [Lang::get('export.info.filter'), $statuses === [] ? Lang::get('export.info.none')
                        : Lang::get('export.filter.status', ['v' => implode(', ', array_map(static fn (string $s): string => self::label('icon.defect_status', $s), $statuses))])],
                    [Lang::get('export.info.rows'), number_format(count($ids))],
                ];
                return $export->write($format, Lang::get('export.sheet.defects'), Lang::get('export.sheet.info'), self::defectColumns(true), $rows, $info);
            }, false);
        } finally {
            foreach ($slots as $l) {
                $l->release();
            }
        }
        $this->app->audit()->record(Actor::fromUser($user, $request->clientIp), 'export.defects', 'mine', null, [
            'format' => $format, 'rows' => $file['rows'], 'project' => $projectId, 'status' => $statuses,
        ]);
        $name = 'PJGuardian_' . Lang::get('export.file.mine') . '_' . $this->stamp() . '.' . $file['extension'];
        return Response::file($file['path'], $file['content_type'], ExportService::disposition($name))->withHeader('X-Export-Rows', (string) $file['rows']);
    }

    /** POST /api/v1/rules/export {format, language(탭), …규칙 목록 조건} */
    public function rules(Request $request): Response
    {
        $body = self::body($request, self::RULE_KEYS, 'invalid_rule_filter');
        $format = ExportService::assertFormat($body['format'] ?? null);
        unset($body['format']);
        $f = RuleQuery::filter($body);
        $user = $request->attribute('user');
        $export = $this->app->exports();
        $query = $this->app->ruleQuery();
        $slots = $export->acquire((int) $user['id']);
        try {
            $file = $this->app->db()->transaction(function () use ($query, $export, $f, $format): array {
                [, $total] = $query->list($f, 1, 0);
                $export->assertCount($total);
                $rows = (function () use ($query, $f, $total): \Generator {
                    for ($offset = 0; $offset < $total; $offset += self::CHUNK) {
                        [$items] = $query->list($f, self::CHUNK, $offset);
                        foreach ($items as $it) {
                            yield self::ruleRow($it);
                        }
                    }
                })();
                return $export->write($format, Lang::get('export.sheet.rules'), Lang::get('export.sheet.info'), self::ruleColumns(), $rows,
                    $this->ruleInfo($query->meta(), $f, $total));
            }, false);
        } finally {
            foreach ($slots as $l) {
                $l->release();
            }
        }
        $this->app->audit()->record(Actor::fromUser($user, $request->clientIp), 'export.rules', 'rules', $f['tool'] !== '' ? $f['tool'] : null, [
            'format' => $format, 'rows' => $file['rows'], 'filter' => array_filter(array_map(static fn ($v) => is_array($v) ? implode(',', $v) : $v, $f), static fn ($v) => $v !== ''),
        ]);
        $tab = ToolLanguage::language($f['tool']) ?? 'all';
        $name = 'PJGuardian_rules_' . $tab . '_' . $this->stamp() . '.' . $file['extension'];
        return Response::file($file['path'], $file['content_type'], ExportService::disposition($name))->withHeader('X-Export-Rows', (string) $file['rows']);
    }

    // ---- 열과 값 ----

    /** @return list<array{0:string, 1:string, 2:int}> */
    public static function defectColumns(bool $withProject): array
    {
        $cols = [
            [Lang::get('export.col.id'), 'int', 10], [Lang::get('export.col.tool'), 'string', 8], [Lang::get('export.col.rule'), 'string', 28],
            [Lang::get('export.col.severity'), 'string', 9], [Lang::get('export.col.status'), 'string', 10], [Lang::get('export.col.assignee'), 'string', 14],
            [Lang::get('export.col.file'), 'string', 50], [Lang::get('export.col.line'), 'int', 7], [Lang::get('export.col.message'), 'string', 80],
            [Lang::get('export.col.last_run'), 'int', 10], [Lang::get('export.col.last_at'), 'datetime', 17], [Lang::get('export.col.first_run'), 'int', 10],
        ];
        return $withProject ? [[Lang::get('export.col.project'), 'string', 20], ...$cols] : $cols;
    }

    /**
     * 결함 한 행(화면 표와 같은 값·같은 라벨: 심각도·상태는 화면의 icon.* 문자열, 담당자는 표시 이름, 줄임 없는 전체 값)
     *
     * @param array<string, mixed> $d DefectQuery::rowsForExport 행
     * @return list<mixed>
     */
    public static function defectRow(array $d, ?string $project): array
    {
        $row = [
            $d['id'], self::label('rules.tool', $d['tool']), $d['rule_key'],
            self::label('icon.severity', $d['severity']), self::label('icon.defect_status', $d['status']),
            $d['assignee']['display_name'] ?? null, $d['file_path'], $d['line'], $d['message'],
            $d['last_detected_run'] ?? null, $d['last_detected_at'] ?? null, $d['first_seen_run'] ?? null,
        ];
        return $project === null ? $row : [$project, ...$row];
    }

    /** @return list<array{0:string, 1:string, 2:int}> */
    public static function ruleColumns(): array
    {
        return [
            [Lang::get('export.col.tool'), 'string', 8], [Lang::get('export.col.rule_key'), 'string', 30], [Lang::get('export.col.name'), 'string', 30],
            [Lang::get('export.col.category'), 'string', 16], [Lang::get('export.col.default_severity'), 'string', 11],
            [Lang::get('export.col.analysis'), 'string', 10], [Lang::get('export.col.overridden'), 'string', 11], [Lang::get('export.col.default_enabled'), 'string', 10],
            [Lang::get('export.col.fixable'), 'string', 10], [Lang::get('export.col.deprecated'), 'string', 10], [Lang::get('export.col.summary'), 'string', 70],
            [Lang::get('export.col.version'), 'string', 12], [Lang::get('export.col.docs_url'), 'string', 50], [Lang::get('export.col.change'), 'string', 16],
        ];
    }

    /**
     * @param array<string, mixed> $it RuleQuery::list 항목
     * @return list<mixed>
     */
    public static function ruleRow(array $it): array
    {
        $yes = static fn (bool $b): string => Lang::get($b ? 'export.yes' : 'export.no');
        return [
            self::label('rules.tool', $it['tool']), $it['rule'], $it['name'] !== '' ? $it['name'] : null, $it['category'],
            self::label('icon.severity', $it['severity']),
            $yes((bool) ($it['analysis_enabled'] ?? $it['default_enabled'])), $yes((bool) ($it['overridden'] ?? false)), $yes((bool) $it['default_enabled']),
            $yes($it['fixable'] !== 'none'), $yes(in_array($it['status'], ['deprecated', 'removed'], true)),
            ($s = trim((string) preg_replace('/\s+/u', ' ', (string) $it['summary']))) === '' ? null : $s,
            $it['version'] ?? null, $it['docs_url'] ?? null,
            $it['change'] === null ? null : self::label('rules.change', $it['change']),
        ];
    }

    // ---- 조건 시트 ----

    /** @return list<array{0:string, 1:string}> */
    private function defectInfo(string $project, DefectFilter $f, ?array $run, int $rows): array
    {
        return [
            [Lang::get('export.info.exported_at'), $this->now()],
            [Lang::get('export.info.project'), $project],
            [Lang::get('export.info.filter'), $this->filterText($f)],
            [Lang::get('export.info.sort'), self::label('export.sort', $f->sort) . ' · ' . self::label('export.order', $f->order)],
            [Lang::get('export.info.run'), $run === null ? Lang::get('export.info.latest') : Lang::get('export.info.run_n', ['run' => (int) $run['run']])],
            [Lang::get('export.info.rows'), number_format($rows)],
        ];
    }

    /** 적용된 조건 요약(사람이 읽는 글). 담당자는 표시 이름(이메일·로그인 이름 없음) */
    private function filterText(DefectFilter $f): string
    {
        $parts = [];
        if ($f->q !== null) {
            $parts[] = Lang::get('export.filter.q', ['v' => $f->q]);
        }
        if ($f->rules !== []) {
            $parts[] = Lang::get('export.filter.rule', ['v' => implode(', ', $f->rules)]);
        }
        if ($f->severities !== []) {
            $parts[] = Lang::get('export.filter.severity', ['v' => implode(', ', array_map(static fn (string $s): string => self::label('icon.severity', $s), $f->severities))]);
        }
        if ($f->statuses !== []) {
            $parts[] = Lang::get('export.filter.status', ['v' => implode(', ', array_map(static fn (string $s): string => self::label('icon.defect_status', $s), $f->statuses))]);
        }
        if ($f->assignees !== [] || $f->unassigned) {
            $names = $f->assignees === [] ? [] : array_column($this->app->db()->all('SELECT display_name FROM users WHERE id IN (SELECT value FROM json_each(?)) ORDER BY display_name',
                [json_encode($f->assignees)]), 'display_name');
            if ($f->unassigned) {
                $names[] = Lang::get('export.filter.unassigned');
            }
            $parts[] = Lang::get('export.filter.assignee', ['v' => implode(', ', $names)]);
        }
        if ($f->file !== null) {
            $parts[] = Lang::get($f->fileMode === 'prefix' ? 'export.filter.file_prefix' : 'export.filter.file_contains', ['v' => $f->file]);
        }
        if ($f->fromRun !== null) {
            $parts[] = Lang::get('export.filter.from_run', ['run' => $f->fromRun]);
        }
        if ($f->inRunFrom !== null) {
            $parts[] = Lang::get('export.filter.in_run', ['from' => $f->inRunFrom, 'to' => $f->inRunTo]);
        }
        if ($f->analysisId !== null) {
            $parts[] = Lang::get('export.filter.analysis', ['id' => $f->analysisId, 'change' => self::label('export.change', $f->change)]);
        }
        return $parts === [] ? Lang::get('export.info.none') : implode(' / ', $parts);
    }

    /** @return array<string, mixed> 감사 로그용 조건(빈 값 제외) */
    private static function summaryFilter(DefectFilter $f): array
    {
        return array_filter($f->toArray(), static fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * @param array<string, mixed> $meta RuleQuery::meta
     * @param array<string, mixed> $f
     * @return list<array{0:string, 1:string}>
     */
    private function ruleInfo(array $meta, array $f, int $rows): array
    {
        $versions = [];
        $loaded = [];
        foreach ($meta['tools'] as $tool => $m) {
            if ($f['tool'] !== '' && $f['tool'] !== $tool) {
                continue;
            }
            if ($m['version'] !== null) {
                $versions[] = self::label('rules.tool', $tool) . ' ' . $m['version'];
                foreach ((array) $m['components'] as $c => $v) {
                    $versions[] = str_replace('/eslint-plugin', '', (string) $c) . ' ' . $v;
                }
                $loaded[] = (string) $m['loaded_at'];
            }
        }
        $cond = [];
        foreach (['q', 'prefix', 'category', 'severity', 'analysis', 'overridden', 'default_enabled', 'fixable', 'deprecated', 'change'] as $k) {
            $v = is_array($f[$k]) ? implode(',', $f[$k]) : (string) $f[$k];
            if ($v !== '') {
                $cond[] = $k . '=' . $v;
            }
        }
        rsort($loaded);
        return [
            [Lang::get('export.info.exported_at'), $this->now()],
            [Lang::get('export.info.tab'), $f['tool'] !== '' ? self::label('rules.tab', ToolLanguage::language($f['tool'])) : Lang::get('export.info.all_tools')],
            [Lang::get('export.info.versions'), $versions === [] ? Lang::get('export.info.none') : implode(' · ', $versions)],
            [Lang::get('export.info.loaded_at'), $loaded === [] ? Lang::get('export.info.none') : $this->local($loaded[0])],
            [Lang::get('export.info.filter'), $cond === [] ? Lang::get('export.info.none') : implode(' / ', $cond)],
            [Lang::get('export.info.sort'), $f['sort'] . ' · ' . $f['order']],
            [Lang::get('export.info.rows'), number_format($rows)],
        ];
    }

    // ---- 도움 ----

    /** 값에 따라 고르는 문구(화면과 같은 문자열 키: icon.severity.*, icon.defect_status.*, rules.tool.* 등) */
    private static function label(string $prefix, string $value): string
    {
        return Lang::get($prefix . '.' . $value);
    }

    /**
     * JSON 본문(문자열·정수 값만, 알 수 없는 키는 400). 값은 목록 쿼리와 같은 문자열로 바꿔 같은 검증기에 넘긴다.
     *
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private static function body(Request $request, array $allowed, string $code): array
    {
        $raw = $request->json();
        $out = [];
        foreach ($raw as $k => $v) {
            if (!is_string($k) || !in_array($k, $allowed, true)) {
                throw new ApiException(400, $code, ['field' => is_string($k) ? mb_substr($k, 0, 40) : '?'], null, [], [], ['field' => is_string($k) ? mb_substr($k, 0, 40) : '?']);
            }
            if ($v === null) {
                continue;
            }
            if (is_int($v)) {
                $v = (string) $v;
            }
            if (!is_string($v)) {
                throw new ApiException(400, $code, ['field' => $k], null, [], [], ['field' => $k]);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function now(): string
    {
        return $this->app->clock->now()->setTimezone($this->app->exports()->timezone())->format('Y-m-d H:i');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->setTimezone($this->app->exports()->timezone())->format('Ymd-Hi');
    }

    private function local(string $iso): string
    {
        return \PJGuardian\Services\Export\CsvWriter::localTime($iso, $this->app->exports()->timezone());
    }
}
