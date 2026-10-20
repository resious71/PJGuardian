<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Rules\RuleOverrides;
use PJGuardian\Services\Rules\RuleQuery;
use PJGuardian\Services\Tools\EffectiveRuleConfig;
use PJGuardian\Services\Tools\RuffRunner;
use PJGuardian\Support\ExcludeMatcher;
use PJGuardian\Support\Lang;

/**
 * 관리자 규칙 켜기·끄기(P22B, admin 전용 — 라우트 미들웨어. 비로그인 401, admin 아님 403, CSRF 필요).
 * 화면: "시스템 관리 > 규칙 설정"(/admin/rule-settings) — 변경 목록·되돌리기·전체 되돌리기. 규칙 하나의 켜기·끄기는 규칙 설명 상세에서.
 * 서버 전체 설정이다(프로젝트·사용자 단위 없음). 판정·저장은 Services\Rules\RuleOverrides 한 곳.
 */
final class AdminRuleOverrideController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /admin/rule-settings */
    public function page(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('admin/rule_settings', [
            'title' => Lang::get('admin_rule_settings.title'),
            'user' => $request->attribute('user'),
            'active' => 'admin.tools',
            'scripts' => ['js/pages/admin_rule_settings.js'],
            'body_class' => 'page-admin-system',
        ], ['sub' => 'rule-settings', 'resetMax' => $this->app->config->int('rules.override_reset_max')]));
    }

    /** PUT /api/v1/admin/rules/{tool}/{rule…}/override {enabled, expected_enabled, reason?} */
    public function set(Request $request): Response
    {
        [$tool, $rule] = $this->key($request);
        $body = $request->json();
        if (!is_bool($body['enabled'] ?? null) || !is_bool($body['expected_enabled'] ?? null)) {
            throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => !is_bool($body['enabled'] ?? null) ? 'enabled' : 'expected_enabled']);
        }
        $reason = $body['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'reason']);
        }
        $s = $this->app->ruleOverrides()->set($request->attribute('user'), $request->clientIp, $tool, $rule, $body['enabled'], $body['expected_enabled'], $reason);
        return Response::data($s);
    }

    /** DELETE /api/v1/admin/rules/{tool}/{rule…}/override — 기본값으로 되돌리기(멱등) */
    public function clear(Request $request): Response
    {
        [$tool, $rule] = $this->key($request);
        return Response::data($this->app->ruleOverrides()->clear($request->attribute('user'), $request->clientIp, $tool, $rule));
    }

    /** PUT·DELETE /api/v1/admin/rules/syntax-error/override — 특수 키는 바꿀 수 없음(422 special_key) */
    public function special(Request $request): Response
    {
        throw new ApiException(422, 'rule_not_enableable', ['why' => Lang::get('rules.block.special_key')], null, [], [], ['reason' => 'special_key']);
    }

    /** POST /api/v1/admin/rules/overrides/bulk/preview — 일괄 변경 미리보기(쓰지 않음) */
    public function bulkPreview(Request $request): Response
    {
        [$tool, $action, $resolve] = $this->bulkTarget($request->json());
        return Response::data($this->app->ruleOverrides()->bulkPreview($tool, $action, $resolve));
    }

    /** POST /api/v1/admin/rules/overrides/bulk — 일괄 적용(한 트랜잭션, expected_revision 필수) */
    public function bulk(Request $request): Response
    {
        $body = $request->json();
        [$tool, $action, $resolve, $summary] = $this->bulkTarget($body);
        if (!is_int($body['expected_revision'] ?? null)) {
            throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'expected_revision']);
        }
        $reason = $body['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'reason']);
        }
        return Response::data($this->app->ruleOverrides()->bulkApply($request->attribute('user'), $request->clientIp, $tool, $action, $resolve, $summary, $reason, $body['expected_revision']));
    }

    /**
     * 일괄 대상: 키 목록(현재 쪽에서 고른 것 — ui.page_size_max개까지, 각 키를 활성 버전과 대조) 또는 검색 조건(GET /api/v1/rules와 같은 조건을 서버가 다시 조회).
     * 대상은 탭의 도구 하나로 제한된다(조건의 tool·language가 다르면 400).
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string, 2: callable, 3: array<string, mixed>}
     */
    private function bulkTarget(array $body): array
    {
        $tool = is_string($body['tool'] ?? null) ? $body['tool'] : '';
        if (!in_array($tool, RuleOverrides::TOOLS, true)) {
            throw new ApiException(404, 'rule_not_found');
        }
        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        $target = is_array($body['target'] ?? null) ? $body['target'] : [];
        $overrides = $this->app->ruleOverrides();
        if (($target['mode'] ?? null) === 'keys') {
            $keys = $target['keys'] ?? null;
            if (!is_array($keys) || $keys === [] || !array_is_list($keys) || count($keys) > $this->app->config->int('ui.page_size_max')) {
                throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'keys']);
            }
            return [$tool, $action, static fn (): array => $overrides->rowsForKeys($tool, $keys), ['mode' => 'keys', 'count' => count($keys)]];
        }
        if (($target['mode'] ?? null) === 'filter' && is_array($target['filter'] ?? null)) {
            $q = array_filter($target['filter'], static fn ($v): bool => is_string($v));
            unset($q['page'], $q['size'], $q['sort'], $q['order']);
            $f = RuleQuery::filter($q);
            if ($f['tool'] !== '' && $f['tool'] !== $tool) {
                throw new ApiException(400, 'invalid_rule_filter', ['field' => 'tool'], null, [], [], ['field' => 'tool']);
            }
            $f['tool'] = $tool;
            $query = $this->app->ruleQuery();
            $max = $overrides->bulkMax();
            $summary = ['mode' => 'filter', 'filter' => array_filter(['q' => $f['q'], 'prefix' => $tool === 'ruff' ? strtoupper($f['prefix']) : strtolower($f['prefix']), 'category' => $f['category'], 'severity' => implode(',', $f['severity']),
                'analysis' => $f['analysis'], 'overridden' => $f['overridden'], 'default_enabled' => $f['default_enabled'], 'fixable' => $f['fixable'],
                'deprecated' => $f['deprecated'], 'change' => $f['change']], static fn (string $v): bool => $v !== '')];
            return [$tool, $action, static function () use ($query, $f, $max): array {
                [$rows, $total] = $query->matching($f, $max);
                return [$rows, $total];
            }, $summary];
        }
        throw new ApiException(400, 'invalid_input', [], null, [], [], ['field' => 'target']);
    }

    /** GET /api/v1/admin/rules/overrides?tool=&state=&page=&size= — 목록 + 요약 */
    public function list(Request $request): Response
    {
        $tool = is_string($request->query['tool'] ?? null) && $request->query['tool'] !== '' ? (string) $request->query['tool'] : null;
        $state = is_string($request->query['state'] ?? null) && $request->query['state'] !== '' ? (string) $request->query['state'] : null;
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        $o = $this->app->ruleOverrides();
        [$items, $total] = $o->list($tool, $state, $p->size, $p->offset());
        return Response::page($items, $p, $total, ['summary' => $this->summary($o)]);
    }

    /** POST /api/v1/admin/rules/overrides/reset {tool, confirm} — 도구별 모두 기본값으로 */
    public function reset(Request $request): Response
    {
        $body = $request->json();
        $tool = is_string($body['tool'] ?? null) ? $body['tool'] : '';
        $confirm = is_string($body['confirm'] ?? null) ? $body['confirm'] : '';
        return Response::data($this->app->ruleOverrides()->resetAll($request->attribute('user'), $request->clientIp, $tool, $confirm));
    }

    /**
     * 요약: 도구별 변경 수·마지막 변경·개정 번호, 서버 기본 설정으로 분석할 때의 실효 설정 해시(앞 12자리 — .eslintignore·업로드 ruff 설정이 없는
     * 프로젝트 기준), ruff 업로드 설정을 써서 변경이 적용되지 않는 프로젝트 수(마지막 성공 ruff 분석의 설정 출처가 upload).
     *
     * @return array<string, mixed>
     */
    private function summary(RuleOverrides $o): array
    {
        $out = $o->summary();
        $matcher = new ExcludeMatcher($this->app->projects()->defaultExcludes(), []);
        $ruffDefault = (string) realpath($this->app->root . '/config/ruff.default.toml');
        $ruffSet = EffectiveRuleConfig::canonical('ruff', $o->activeSet('ruff'));
        $out['ruff']['config_hash'] = substr(RuffRunner::configHash([$ruffDefault], implode(',', $matcher->defaults()), $ruffSet), 0, 12);
        $eslint = $this->app->eslintRunner();
        [$files, $ignores] = $eslint->patterns($matcher);
        $out['eslint']['config_hash'] = substr($eslint->configHash($files, $ignores, EffectiveRuleConfig::canonical('eslint', $o->activeSet('eslint'))), 0, 12);
        $out['ruff']['upload_config_projects'] = (int) $this->app->db()->value(
            "SELECT COUNT(*) FROM projects p WHERE p.language = 'python' AND (SELECT a.config_source FROM analyses a WHERE a.project_id = p.id AND a.status = 'succeeded' AND a.no_change = 0"
            . " AND a.tool = 'ruff' ORDER BY a.id DESC LIMIT 1) = 'upload'"
        );
        $out['eslint']['upload_config_projects'] = 0; // ESLint 업로드 설정은 원래 무시 — 모든 프로젝트에 적용
        $out['reset_max'] = $this->app->config->int('rules.override_reset_max');
        return $out;
    }

    /** @return array{0:string, 1:string} */
    private function key(Request $request): array
    {
        $tool = (string) $request->param('tool');
        $rule = (string) $request->param('rule');
        if (!RuleController::validRule($rule)) {
            throw new ApiException(404, 'rule_not_found');
        }
        return [$tool, $rule];
    }
}
