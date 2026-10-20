<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Services\Rules\RuleQuery;
use PJGuardian\Support\Lang;

/**
 * 규칙 설명(P22, 명세 11장): 화면 /rules, /rules/{tool}/{rule…}, /rules/syntax-error와 API. 로그인한 모든 사용자(규칙은 프로젝트에 속하지 않음).
 * 결함 건수·프로젝트 이름은 볼 수 있는 프로젝트만(RuleQuery). 다시 불러오기는 admin만(라우트 미들웨어).
 */
final class RuleController
{
    public function __construct(private readonly App $app)
    {
    }

    public function listPage(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::html($this->app->view($request)->page('rules/index', [
            'title' => Lang::get('rules.title'), 'user' => $user, 'active' => 'nav.rules', 'scripts' => ['js/pages/rules.js'],
        ], ['pageSize' => $this->app->config->int('ui.defect_page_size'), 'isAdmin' => ($user['role'] ?? '') === 'admin']));
    }

    public function detailPage(Request $request): Response
    {
        $tool = (string) ($request->param('tool') ?? 'special');
        $rule = $request->param('rule') ?? 'syntax-error';
        return Response::html($this->app->view($request)->page('rules/show', [
            'title' => Lang::get('rules.title'), 'user' => $request->attribute('user'), 'active' => 'nav.rules', 'scripts' => ['js/pages/rule_detail.js'],
        ], ['tool' => $tool, 'rule' => (string) $rule, 'isAdmin' => ($request->attribute('user')['role'] ?? '') === 'admin']));
    }

    /** GET /api/v1/rules */
    public function list(Request $request): Response
    {
        $f = RuleQuery::filter($request->query);
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        [$items, $total] = $this->app->ruleQuery()->list($f, $p->size, $p->offset());
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/rules/meta */
    public function meta(Request $request): Response
    {
        $reg = $this->app->toolRegistry();
        $active = [];
        foreach (RuleQuery::TOOLS as $tool) {
            $active[$tool] = $reg->explain($tool)['version']; // 검증을 실행하지 않는 예상(관리자 화면과 같음)
        }
        return Response::data($this->app->ruleQuery()->meta($active));
    }

    /** GET /api/v1/rules/{tool}/{rule…}, /api/v1/rules/syntax-error */
    public function detail(Request $request): Response
    {
        $tool = (string) ($request->param('tool') ?? 'special');
        $rule = (string) ($request->param('rule') ?? 'syntax-error');
        if ($tool !== 'special' && !self::validRule($rule)) {
            throw new ApiException(404, 'rule_not_found');
        }
        return Response::data($this->app->ruleQuery()->detail($request->attribute('user'), $tool, $rule));
    }

    /** POST /api/v1/admin/rules/reload: 관리 작업 rules_load 요청(워커가 처리, 대기 중이면 합침). 감사 rules.reload_request */
    public function reload(Request $request): Response
    {
        $user = $request->attribute('user');
        $r = $this->app->adminTasks()->request('rules_load', '', (int) $user['id']);
        if (!$r['merged']) {
            $this->app->audit()->record(Actor::fromUser($user, $request->clientIp), 'rules.reload_request', 'rules', null, ['task_id' => $r['task']['id']]);
        }
        return Response::data($r, 202);
    }

    /** 경로의 규칙 이름 검사(라우트 제약 다음 한 번 더): 결함 rule_key 규칙과 같은 글자만 */
    public static function validRule(string $rule): bool
    {
        return preg_match('#^[A-Za-z0-9@/_.-]{1,190}$#', $rule) === 1 && !str_contains($rule, '..');
    }
}
