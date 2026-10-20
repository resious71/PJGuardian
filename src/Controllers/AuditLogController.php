<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Export\ExportService;
use PJGuardian\Support\AuditActions;
use PJGuardian\Support\Lang;

/**
 * 감사 로그 조회(P30, admin만, **읽기 전용** — 수정·삭제 라우트 없음. 보존 정리는 tools/cleanup.php만).
 *   GET  /admin/audit-logs                         화면(조건은 주소 질의 문자열 — 링크로 공유)
 *   GET  /api/v1/admin/audit-logs                  목록 {data:[…], page:{…}, meta:{actions, prefixes, range}} 조건 days|from·to, actor, action, target, q, page, size
 *   GET  /api/v1/admin/audit-logs/{id}             한 행 전체(세부 정보는 화면 단계에서 한 번 더 마스킹)
 *   GET  /api/v1/admin/audit-logs/integrity        무결성 검사 한 조각 ?after=<id>&limit — 화면이 끝날 때까지 이어 부름(요청 하나는 짧게, 워커 불필요).
 *                                                  검사 실행은 감사 로그가 아니라 앱 로그에만(읽기 동작 — 기록하면 검사 중 체인 끝이 계속 늘어남)
 *   POST /api/v1/admin/audit-logs/export           CSV(현재 조건, 최대 export.max_rows행, 수식 방지·UTF-8 BOM — P23 규칙, 마지막 열 "설명" = 화면 언어의 한 문장 요약 X17), 감사 audit.export
 * 목록·한 행 응답의 summary·fields·target_label 등은 AuditDescriber가 보여 줄 때 만든다(X17 — 저장된 행은 그대로).
 */
final class AuditLogController
{
    public const VERIFY_CHUNK = 20000;

    public function __construct(private readonly App $app)
    {
    }

    public function page(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('admin/audit_logs', [
            'title' => Lang::get('audit.title'),
            'user' => $request->attribute('user'),
            'active' => 'admin.audit',
            'scripts' => ['js/pages/admin_audit_logs.js'],
            'body_class' => 'page-admin-audit',
        ], ['groups' => AuditActions::GROUPS, 'maxRows' => $this->app->exports()->maxRows()]));
    }

    /** @return array{0:int, 1:int} */
    private static function paging(Request $request): array
    {
        $page = filter_var($request->query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $size = filter_var($request->query['size'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($page === false || $size === false) {
            throw new ApiException(400, 'invalid_audit_filter', ['field' => $page === false ? 'page' : 'size'], null, [], [], ['field' => $page === false ? 'page' : 'size']);
        }
        return [$page, $size];
    }

    public function list(Request $request): Response
    {
        $q = $this->app->auditQuery();
        $f = $q->filter($request->query);
        [$page, $size] = self::paging($request);
        [$items, $total] = $q->list($f, $page, $size);
        return Response::json([
            'data' => $items,
            'page' => ['page' => $page, 'size' => $size, 'total' => $total, 'pages' => max(1, (int) ceil($total / $size))],
            'meta' => ['filter' => $f, 'prefixes' => AuditActions::prefixes(), 'actions' => AuditActions::all(), 'range' => $q->range()],
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function show(Request $request): Response
    {
        return Response::data($this->app->auditQuery()->get((int) $request->param('id')))->withHeader('Cache-Control', 'no-store');
    }

    public function integrity(Request $request): Response
    {
        $after = filter_var($request->query['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($request->query['limit'] ?? self::VERIFY_CHUNK, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::VERIFY_CHUNK]]);
        if ($after === false || $limit === false) {
            throw new ApiException(400, 'invalid_audit_filter', ['field' => $after === false ? 'after' : 'limit'], null, [], [], ['field' => $after === false ? 'after' : 'limit']);
        }
        $q = $this->app->auditQuery();
        $r = $q->verify($after, $limit);
        if ($after === 0 || !$r['ok'] || $r['done']) {
            $r['range'] = $q->range();
        }
        // P31: 끝까지 검사했으면 DB 밖 기록(audit-anchor.jsonl)과도 대조 — 체인을 통째로 다시 계산해도 바깥 기록과 어긋난다
        if ($r['done']) {
            $r['anchor'] = $this->app->auditAnchor()->check();
        }
        if (!$r['ok'] || ($r['done'] && $after === 0)) {
            $this->app->logger->info('감사 로그 무결성 검사', ['ok' => $r['ok'], 'broken_at' => $r['broken_at'], 'reason' => $r['reason'], 'anchor' => $r['anchor']['state'] ?? null,
                'by' => $request->attribute('user')['login_id'] ?? null]);
        }
        return Response::data($r)->withHeader('Cache-Control', 'no-store');
    }

    /** POST /api/v1/admin/audit-logs/export {…목록 조건} */
    public function export(Request $request): Response
    {
        $body = $request->json();
        $query = [];
        foreach (['days', 'from', 'to', 'actor', 'action', 'target', 'q'] as $k) {
            if (isset($body[$k]) && is_scalar($body[$k])) {
                $query[$k] = (string) $body[$k];
            }
        }
        $q = $this->app->auditQuery();
        $f = $q->filter($query);
        $user = $request->attribute('user');
        $export = $this->app->exports();
        $slots = $export->acquire((int) $user['id']);
        try {
            $count = $q->count($f);
            $export->assertCount($count);
            $rows = (static function () use ($q, $f, $count): \Generator {
                foreach ($q->iterate($f, $count) as $r) {
                    yield [(int) $r['id'], $r['occurred_at'], $r['occurred_at'], $r['actor'], $r['action'], (string) ($r['target_type'] ?? ''), (string) ($r['target_id'] ?? ''),
                        (string) ($r['result'] ?? ''), (string) ($r['request_id'] ?? ''), (string) ($r['ip'] ?? ''),
                        (string) json_encode($r['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) $r['summary']];
                }
            })();
            $columns = [[Lang::get('audit.col.id'), 'int', 10], [Lang::get('audit.col.time_local'), 'datetime', 17], [Lang::get('audit.col.time_utc'), 'string', 22],
                [Lang::get('audit.col.actor'), 'string', 16], [Lang::get('audit.col.action'), 'string', 26], [Lang::get('audit.col.target_type'), 'string', 14],
                [Lang::get('audit.col.target_id'), 'string', 20], [Lang::get('audit.col.result'), 'string', 10], [Lang::get('audit.col.request_id'), 'string', 20],
                [Lang::get('audit.col.ip'), 'string', 16], [Lang::get('audit.col.details'), 'string', 80], [Lang::get('audit.col.summary'), 'string', 80]];
            $file = $export->write('csv', Lang::get('audit.title'), Lang::get('export.sheet.info'), $columns, $rows, []);
        } finally {
            foreach ($slots as $l) {
                $l->release();
            }
        }
        $this->app->audit()->record(Actor::fromUser($user, $request->clientIp), 'audit.export', 'audit_log', null, [
            'format' => 'csv', 'rows' => $file['rows'], 'filter' => array_filter($f, static fn ($v) => $v !== '' && $v !== null),
        ]);
        $name = 'PJGuardian_audit_' . $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('app.display_timezone')))->format('Ymd_His') . '.csv';
        return Response::file($file['path'], $file['content_type'], ExportService::disposition($name))->withHeader('X-Export-Rows', (string) $file['rows']);
    }
}
