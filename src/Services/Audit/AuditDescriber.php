<?php

declare(strict_types=1);

namespace PJGuardian\Services\Audit;

use PJGuardian\Support\AuditActions;
use PJGuardian\Support\Db;
use PJGuardian\Support\ErrorCatalog;
use PJGuardian\Support\JsStrings;
use PJGuardian\Support\Josa;
use PJGuardian\Support\Lang;
use PJGuardian\Support\Logger;
use PJGuardian\Support\SchemaFeatures;

/**
 * 감사 로그 사람이 읽는 설명(X17). 저장된 행·해시 체인은 그대로 두고 **보여 줄 때만** 만든다(화면·API·CSV).
 *
 * - 한 문장 요약: 작업마다 문구 키 audit.desc.<작업>[.<변형>](ko·en). 값은 이미 화면 단계 마스킹(AuditQuery::safe)을 거친 세부 정보에서만 — 가린 값을 풀거나 다른 표에서 보충하지 않는다.
 *   문구의 자리표시자에 넣을 값이 없으면(세부 정보가 빠진 옛 행) 작업 이름만 쓴 일반 문장으로 바꾼다. 템플릿이 없는 작업은 "작업 xxx이(가) 기록되었습니다."(known=false — 화면 "설명 없음(원본만)").
 * - 항목별 풀이(fields): 세부 정보의 키 → 화면 이름(audit.fname.<키>), 값 → 풀어 쓴 값(역할·인증 방식·켜짐/꺼짐·크기·시각 등), {from,to}·[이전, 이후]는 이전/이후 칸.
 * - 대상 이름: 사용자·프로젝트·그룹·FAQ id를 한 화면 단위로 **종류마다 한 번씩** 일괄 조회(IN — N+1 없음). 행위자 사용자도 같은 사용자 조회에 함께.
 *   못 찾으면 세부 정보의 이름(삭제 기록 등), 그것도 없으면 "삭제된 항목(#id)" — 링크 없음.
 * - 한국어 조사는 문구에 "을(를)"처럼 두 꼴로 쓰고 Josa::fix가 고른다(영어는 그대로).
 */
final class AuditDescriber
{
    /** 세부 정보에서 화면 표·문장에 쓰지 않는 키(기술 정보에만) */
    private const HIDDEN = ['result', 'request_id'];
    /** 늘 실패인 작업 */
    private const ALWAYS_FAILED = ['auth.login_failed', 'restore.rollback'];
    private const MAX_LIST = 10;
    private const MAX_TEXT = 120;

    /** 키별 값 종류(작업별 예외는 kind()) */
    private const KINDS = [
        'role' => 'role', 'actor_role' => 'role', 'auth_type' => 'auth', 'subject_type' => 'subject', 'published' => 'published',
        'allow_http' => 'onoff', 'github_links' => 'onoff', 'enabled' => 'onoff',
        'size' => 'bytes', 'snapshot_bytes' => 'bytes', 'ms' => 'ms',
        'expires_at' => 'time', 'next_run_at' => 'time', 'through_at' => 'time', 'cutoff' => 'time',
        'format' => 'upper', 'code' => 'code', 'source' => 'source', 'wait_mode' => 'wait_mode', 'auth_change' => 'auth_change',
        'answer_sha256' => 'short_hash', 'file_sha256' => 'short_hash', 'sha256' => 'short_hash',
        'language' => 'language', 'fields' => 'field_names', 'old_source' => 'config_source',
    ];

    /** 값 종류 → 풀이 문구 접두사 */
    private const LOOKUP = [
        'role' => 'audit.val.role.', 'auth' => 'audit.val.auth.', 'subject' => 'audit.val.subject.', 'approval' => 'audit.val.approval.',
        'reason' => 'audit.val.reason.', 'login_reason' => 'audit.val.login_reason.', 'bulk_action' => 'audit.val.bulk.',
        'backup_kind' => 'audit.val.backup_kind.', 'repo_kind' => 'audit.val.repo_kind.', 'source' => 'audit.val.source.',
        'saml_source' => 'audit.val.saml_source.', 'wait_mode' => 'audit.val.wait_mode.', 'auth_change' => 'audit.val.auth_change.',
        'language' => 'audit.val.language.',
    ];

    /** @var array<string, string> */
    private array $configLabels = [];

    public function __construct(private readonly Db $db, private readonly string $timezone = 'UTC')
    {
    }

    /**
     * 행 묶음 설명. 입력은 AuditQuery 한 행(details = 화면 단계 마스킹을 거친 배열). 각 행에 설명 필드를 더해 돌려준다.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function describeMany(array $rows): array
    {
        $names = $this->lookup($rows);
        return array_map(fn (array $r): array => $r + $this->describe($r, $names), $rows);
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, array<int, array<string, mixed>>> $names
     * @return array<string, mixed>
     */
    private function describe(array $r, array $names): array
    {
        $action = (string) $r['action'];
        $d = is_array($r['details'] ?? null) ? $r['details'] : [];
        $known = in_array($action, AuditActions::all(), true) && Lang::has("audit.desc.{$action}");
        $actor = $this->actor($r, $names);
        $target = $this->target($r, $d, $names);
        $outcome = $this->outcome($action, $d);
        $summary = $known ? $this->sentence($action, $r, $d, $actor, $target) : null;
        if ($summary === null) {
            $summary = Lang::get($known ? 'audit.desc._generic' : 'audit.desc._unknown', [
                'actor' => $actor['phrase'], 'action' => $known ? Lang::get("audit.act.{$action}") : $action,
            ]);
        }
        $group = strstr($action, '.', true) ?: $action;
        $source = $this->source($r);
        return [
            'summary' => $this->finish($summary),
            'known' => $known,
            'action_label' => Lang::has("audit.act.{$action}") ? Lang::get("audit.act.{$action}") : $action,
            'action_group' => Lang::has("audit.group.{$group}") ? Lang::get("audit.group.{$group}") : $group,
            'actor_display' => $actor['display'],
            'actor_name' => $actor['name'],
            'source' => $source,
            'source_label' => Lang::get("audit.source.{$source}"),
            'target_label' => $target['label'],
            'target_href' => $target['href'],
            'target_deleted' => $target['deleted'],
            'outcome' => $outcome['outcome'],
            'outcome_label' => Lang::get("audit.outcome.{$outcome['outcome']}"),
            'outcome_reason' => $outcome['reason'],
            'outcome_code' => $outcome['code'],
            'outcome_href' => $outcome['href'],
            'fields' => $this->fields($action, $d),
        ];
    }

    /** 문장 끝맺음: 공백 정리·한국어 조사 */
    private function finish(string $s): string
    {
        $s = trim((string) preg_replace('/[ \t]{2,}/', ' ', $s));
        return Lang::locale() === 'ko' ? Josa::fix($s) : $s;
    }

    // ---------------------------------------------------------------- 이름 풀이(일괄)

    /**
     * 종류마다 한 번의 IN 조회(없는 종류는 조회하지 않음, 500개씩 나눔). 사용자 = 대상 사용자 + 행위자 사용자.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function lookup(array $rows): array
    {
        $ids = ['user' => [], 'project' => [], 'group' => [], 'faq' => []];
        foreach ($rows as $r) {
            $type = (string) ($r['target_type'] ?? '');
            $tid = (string) ($r['target_id'] ?? '');
            if (isset($ids[$type]) && preg_match('/^[1-9]\d{0,17}$/', $tid) === 1) {
                $ids[$type][(int) $tid] = true;
            }
            if (($r['actor_user_id'] ?? null) !== null) {
                $ids['user'][(int) $r['actor_user_id']] = true;
            }
        }
        $sql = [
            'user' => 'SELECT id, login_id, display_name, role FROM users WHERE id IN (%s)',
            'project' => 'SELECT id, name FROM projects WHERE id IN (%s)',
            'group' => 'SELECT id, name FROM groups WHERE id IN (%s)',
            'faq' => 'SELECT id, question FROM faq_items WHERE id IN (%s)',
        ];
        $out = [];
        foreach ($ids as $type => $set) {
            $out[$type] = [];
            if ($set === [] || ($type === 'faq' && !SchemaFeatures::faq($this->db))) {
                continue;
            }
            foreach (array_chunk(array_keys($set), 500) as $chunk) {
                // 자리표시자 개수만 문자열로 만든다(값은 모두 바인딩)
                foreach ($this->db->all(sprintf($sql[$type], implode(',', array_fill(0, count($chunk), '?'))), $chunk) as $row) {
                    $out[$type][(int) $row['id']] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, array<int, array<string, mixed>>> $names
     * @return array{phrase:string, display:string, name:?string}
     */
    private function actor(array $r, array $names): array
    {
        $label = (string) $r['actor'];
        $fixed = match (true) {
            $label === 'system' => Lang::get('audit.actor.system'),
            $label === 'cli' => Lang::get('audit.actor.cli'),
            str_starts_with($label, 'restore:') => Lang::get('audit.actor.restore', ['by' => substr($label, 8)]),
            $label === 'login' || str_starts_with($label, 'login@') => Lang::get('audit.actor.login'),
            default => null,
        };
        if ($fixed !== null) {
            return ['phrase' => $fixed, 'display' => $fixed, 'name' => null];
        }
        $u = ($r['actor_user_id'] ?? null) !== null ? ($names['user'][(int) $r['actor_user_id']] ?? null) : null;
        if ($u !== null) {
            $phrase = Lang::get((string) $u['role'] === 'admin' ? 'audit.actor.admin' : 'audit.actor.user', ['login' => $label]);
            return ['phrase' => $phrase, 'display' => $label, 'name' => (string) $u['display_name']];
        }
        return ['phrase' => $label, 'display' => $label, 'name' => null];
    }

    /** 출처: web(로그인한 사용자의 웹 요청)·cli(서버 명령줄)·login(로그인 시도)·system(워커·예약 작업·복원) */
    private function source(array $r): string
    {
        $label = (string) $r['actor'];
        if ($label === 'cli') {
            return 'cli';
        }
        if ($label === 'login' || str_starts_with($label, 'login@')) {
            return 'login';
        }
        return ($r['actor_user_id'] ?? null) !== null || ($r['ip'] ?? null) !== null ? 'web' : 'system';
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $d
     * @param array<string, array<int, array<string, mixed>>> $names
     * @return array{label:string, href:?string, deleted:bool}
     */
    private function target(array $r, array $d, array $names): array
    {
        $type = (string) ($r['target_type'] ?? '');
        $id = (string) ($r['target_id'] ?? '');
        $num = preg_match('/^[1-9]\d{0,17}$/', $id) === 1 ? (int) $id : null;
        $gone = static fn (mixed $fromDetails): array => [
            'label' => is_scalar($fromDetails) && (string) $fromDetails !== '' ? self::clip((string) $fromDetails, 60) : Lang::get('audit.target.deleted', ['id' => $id]),
            'href' => null, 'deleted' => true,
        ];
        $ok = static fn (string $label, ?string $href): array => ['label' => $label, 'href' => $href, 'deleted' => false];
        switch ($type) {
            case 'user':
                if ($num !== null && isset($names['user'][$num])) {
                    $u = $names['user'][$num];
                    return $ok((string) $u['display_name'] === (string) $u['login_id'] ? (string) $u['login_id'] : $u['display_name'] . ' (' . $u['login_id'] . ')', '/admin/users');
                }
                return $gone($d['login_id'] ?? null);
            case 'project':
                return $num !== null && isset($names['project'][$num]) ? $ok((string) $names['project'][$num]['name'], '/projects/' . $num) : $gone($d['name'] ?? null);
            case 'group':
                if ($num !== null && isset($names['group'][$num])) {
                    return $ok((string) $names['group'][$num]['name'], '/groups/' . $num);
                }
                return $gone(is_array($d['name'] ?? null) ? ($d['name']['to'] ?? null) : ($d['name'] ?? null));
            case 'faq':
                return $num !== null && isset($names['faq'][$num])
                    ? $ok(self::clip((string) $names['faq'][$num]['question'], 60), '/guide/faq#faq-' . $num) : $gone($d['question'] ?? null);
            case 'config':
                return $ok($this->configLabel($id), '/admin/config-settings');
            case 'setting':
                return $ok(Lang::has("audit.setting.{$id}") ? Lang::get("audit.setting.{$id}") : $id, '/admin/config-settings');
            case 'backup':
                return $id === '' ? $ok('', null) : $ok($id, '/admin/backups');
            case 'repo_host':
                return $ok($id, '/admin/config-settings#repository');
            case 'rule':
                if (str_contains($id, ':')) {
                    [$tool, $rule] = explode(':', $id, 2);
                    return $ok($id, in_array($tool, ['ruff', 'eslint'], true) ? '/rules/' . $tool . '/' . rawurlencode($rule) : null);
                }
                return $ok($id, '/admin/rule-settings');
            case 'rules':
                return $ok($id !== '' ? $id : Lang::get('audit.ttype.rules'), '/rules');
            case 'tool':
                return $ok($id, '/admin/tools');
            case 'tool_staging':
                return $ok('#' . $id, '/admin/tools');
            case 'worker':
                return $ok(Lang::get('audit.ttype.worker'), '/admin/worker');
            case 'admin_task':
                return $ok('#' . $id, '/admin/checks');
            case 'audit_log':
                return $ok(Lang::get('audit.title'), null);
            case 'mine':
                return $ok(Lang::get('audit.ttype.mine'), null);
            case 'faq_category':
                return $ok($id, '/guide/faq');
            case '':
                return $ok('', null);
            default:
                return $ok($id === '' ? $type : $type . ':' . $id, null);
        }
    }

    /** 설정 관리 항목 이름(설정 관리 화면과 같은 글자 — JS 문구 사전). 화면 이름이 없는 설정은 키 그대로 */
    private function configLabel(string $key): string
    {
        $loc = Lang::locale();
        if (!isset($this->configLabels[$loc . ':' . $key])) {
            $label = JsStrings::get('config_settings.key.' . $key . '.label', $loc);
            $this->configLabels[$loc . ':' . $key] = $label === null || $label === '' ? $key : $label; // X19: 화면 이름만(키는 기술 정보의 대상 원본·원본 JSON에)
        }
        return $this->configLabels[$loc . ':' . $key];
    }

    // ---------------------------------------------------------------- 결과

    /**
     * @param array<string, mixed> $d
     * @return array{outcome:string, reason:?string, code:?string, href:?string}
     */
    private function outcome(string $action, array $d): array
    {
        $failed = in_array($action, self::ALWAYS_FAILED, true) || ($d['result'] ?? null) === 'failed' || ($d['ok'] ?? null) === false
            || ($action === 'repo.host_test' && is_string($d['code'] ?? null) && str_starts_with($d['code'], 'fail'));
        if (!$failed) {
            return ['outcome' => ($d['result'] ?? null) === 'requested' ? 'requested' : 'success', 'reason' => null, 'code' => null, 'href' => null];
        }
        $code = null;
        foreach (['code', 'reason'] as $k) {
            if (is_string($d[$k] ?? null) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $d[$k]) === 1) {
                $code = $d[$k];
                break;
            }
        }
        return ['outcome' => 'failure', 'reason' => $code === null ? null : self::codeText($code, $action), 'code' => $code, 'href' => $code === null ? null : self::codeHref($code)];
    }

    /** 실패 사유 코드 풀이(있는 문구만, 자리표시자가 남는 문구는 쓰지 않음 — 없으면 코드 그대로) */
    public static function codeText(string $code, string $action = ''): string
    {
        $keys = $action === 'auth.login_failed' ? ["audit.val.login_reason.{$code}"] : ($action === 'mail.test' ? ["mail.reason.{$code}"] : []);
        array_push($keys, "backup.error.{$code}", "analysis.failure.{$code}", "error.{$code}", "audit.val.reason.{$code}");
        foreach ($keys as $k) {
            if (Lang::has($k)) {
                $t = Lang::get($k);
                if (!str_contains($t, '{')) {
                    return $t;
                }
            }
        }
        return $code;
    }

    /** 가이드 오류 코드 탭에 있는 코드만 링크 */
    private static function codeHref(string $code): ?string
    {
        foreach (array_keys(ErrorCatalog::GUIDE_SECTION) as $kind) {
            if (in_array($code, ErrorCatalog::codes($kind), true)) {
                return '/guide/errors#' . rawurlencode($code);
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- 한 문장

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $d
     * @param array{phrase:string} $actor
     * @param array{label:string} $target
     */
    private function sentence(string $action, array $r, array $d, array $actor, array $target): ?string
    {
        $p = ['actor' => $actor['phrase'], 'target' => $target['label']];
        foreach ($d as $k => $v) {
            if (!is_string($k) || in_array($k, self::HIDDEN, true)) {
                continue;
            }
            if (is_array($v) && self::isPair($action, $k, $v)) {
                [$from, $to] = self::pair($v);
                $p[$k . '_from'] = $this->value($action, $k, $from);
                $p[$k . '_to'] = $this->value($action, $k, $to);
                continue;
            }
            $p[$k] = $this->value($action, $k, $v);
        }
        // 작업별 덧붙임 값
        switch ($action) {
            case 'user.update':
            case 'project.update':
            case 'group.update':
                $p['fields'] = $this->changedLabels($action, $d);
                break;
            case 'faq.update':
                // 항목 이름 목록(글자)만 — 모양이 다른 값(배열 안의 배열 등)은 건너뜀(P31: 배열을 글자로 바꾸던 경고)
                $p['fields'] = is_array($d['fields'] ?? null)
                    ? $this->joinLabels(array_map(fn ($f): string => $this->label((string) $f, $action), array_values(array_filter($d['fields'], 'is_scalar')))) : '';
                break;
            case 'backup.delete':
            case 'run.detail_delete':
                $list = $d['files'] ?? $d['runs'] ?? null;
                $p['n'] = is_array($list) ? number_format(count($list)) : '';
                break;
        }
        $key = "audit.desc.{$action}";
        $variant = $this->variant($action, $r, $d);
        if ($variant !== null && Lang::has("{$key}.{$variant}")) {
            $key .= ".{$variant}";
        }
        // 자리표시자에 넣을 값이 없거나 비면(세부 정보가 빠진 옛 행) → 일반 문장
        foreach (self::placeholders(Lang::get($key)) as $name) {
            if (!isset($p[$name]) || $p[$name] === '') {
                return null;
            }
        }
        return Lang::get($key, $p);
    }

    /** @return list<string> */
    private static function placeholders(string $template): array
    {
        preg_match_all('/\{(\w+)(?::[^{}]*)?\}/u', $template, $m);
        return array_values(array_unique($m[1]));
    }

    /** 작업별 문구 변형(문구가 있을 때만 쓰임) */
    private function variant(string $action, array $r, array $d): ?string
    {
        return match ($action) {
            'user.create' => ($d['source'] ?? null) === 'csv' ? 'csv' : (($d['initial_admin'] ?? false) === true ? 'initial' : null),
            'user.update' => ($d['self'] ?? false) === true ? 'self' : null,
            'auth.ldap_secret_set', 'auth.oidc_secret_set', 'auth.saml_sp_key_generate', 'mail.secret_set' => ($d['replaced'] ?? false) === true ? 'replaced' : null,
            'repo.save' => ($d['created'] ?? false) === true ? 'created' : null,
            'repo.test', 'mail.test' => ($d['ok'] ?? null) === true ? 'ok' : (($d['ok'] ?? null) === false ? 'fail' : null),
            'repo.host_test' => ($d['code'] ?? null) === 'ok' ? 'ok' : (is_string($d['code'] ?? null) && str_starts_with($d['code'], 'fail') ? 'fail' : null),
            'repo.fetch_now' => ($d['force'] ?? false) === true ? 'force' : null,
            'rules.override_set' => ($d['after'] ?? null) === true ? 'on' : (($d['after'] ?? null) === false ? 'off' : null),
            'rules.override_bulk' => is_string($d['action'] ?? null) && in_array($d['action'], ['enable', 'disable', 'reset'], true) ? $d['action'] : null,
            'export.defects' => ($r['target_type'] ?? null) === 'mine' ? 'mine' : null,
            'worker.start_request' => ($d['ok'] ?? null) === false ? 'fail' : null,
            'backup.create' => is_string($d['result'] ?? null) && in_array($d['result'], ['requested', 'ok', 'failed'], true) ? $d['result'] : null,
            'backup.delete' => ($d['reason'] ?? null) === 'retention' ? 'retention' : null,
            'backup.verify', 'restore.rehearsal' => ($d['result'] ?? null) === 'failed' ? 'fail' : (($d['result'] ?? null) === 'ok' ? 'ok' : null),
            'faq.publish' => ($d['published'] ?? null) === false ? 'hide' : (($d['published'] ?? null) === true ? 'show' : null),
            'faq.create' => ($d['published'] ?? null) === false ? 'draft' : null,
            default => null,
        };
    }

    /** 바뀐 항목({from,to}) 이름 목록 */
    private function changedLabels(string $action, array $d): string
    {
        $out = [];
        foreach ($d as $k => $v) {
            if (is_string($k) && is_array($v) && self::isPair($action, $k, $v)) {
                $out[] = $this->label($k, $action);
            }
        }
        return $this->joinLabels($out);
    }

    /** @param list<string> $labels 영어 문장 안에서는 항목 이름 첫 글자를 소문자로("changed display name of …") */
    private function joinLabels(array $labels): string
    {
        return implode(', ', Lang::locale() === 'en' ? array_map(static fn (string $l): string => preg_match('/^[A-Z][a-z]/', $l) === 1 ? lcfirst($l) : $l, $labels) : $labels);
    }

    // ---------------------------------------------------------------- 항목별 풀이

    /**
     * @param array<string, mixed> $d
     * @return list<array{key:string, label:string, before:?string, after:string, changed:bool}>
     */
    private function fields(string $action, array $d): array
    {
        $rows = [];
        $add = function (string $key, mixed $before, mixed $after, bool $changed) use ($action, &$rows): void {
            $rows[] = ['key' => $key, 'label' => $this->label($key, $action), 'before' => $changed ? $this->value($action, $key, $before) : null,
                'after' => $this->value($action, $key, $after), 'changed' => $changed];
        };
        // 이전/이후가 최상위 키(old·new, from·to)인 작업: 한 행으로
        $pairKeys = match ($action) {
            'config.update' => ['value', 'old', 'new'],
            'setting.update' => ['value', 'from', 'to'],
            'user.role_change' => ['role', 'from', 'to'],
            'user.auth_type_change' => ['auth_type', 'from', 'to'],
            default => null,
        };
        if ($pairKeys !== null && (array_key_exists($pairKeys[1], $d) || array_key_exists($pairKeys[2], $d))) {
            $add($pairKeys[0], $d[$pairKeys[1]] ?? null, $d[$pairKeys[2]] ?? null, true);
            unset($d[$pairKeys[1]], $d[$pairKeys[2]]);
            if ($action === 'setting.update') {
                unset($d['key']);
            }
        }
        foreach ($d as $k => $v) {
            if (!is_string($k) || in_array($k, self::HIDDEN, true)) {
                continue;
            }
            if ($k === 'changes' && is_array($v) && !array_is_list($v)) {
                foreach ($v as $ck => $cv) {
                    if (is_string($ck) && is_array($cv) && self::isPair($action, $ck, $cv)) {
                        [$from, $to] = self::pair($cv);
                        $add($ck, $from, $to, true);
                    } elseif (is_string($ck)) {
                        $add($ck, null, $cv, false);
                    }
                }
                continue;
            }
            if (is_array($v) && self::isPair($action, $k, $v)) {
                [$from, $to] = self::pair($v);
                $add($k, $from, $to, true);
                continue;
            }
            $add($k, null, $v, false);
        }
        return $rows;
    }

    /** {from,to} 또는 [이전, 이후](두 값 목록 — 허용 호스트·주기 분석 변경) */
    private static function isPair(string $action, string $key, array $v): bool
    {
        if (!array_is_list($v)) {
            return $v !== [] && array_diff(array_keys($v), ['from', 'to']) === [];
        }
        return count($v) === 2 && in_array($action, ['repo_host.update', 'schedule.save'], true) && !in_array($key, ['rules', 'ids', 'files', 'runs', 'fields'], true);
    }

    /** @return array{0:mixed, 1:mixed} */
    private static function pair(array $v): array
    {
        return array_is_list($v) ? [$v[0] ?? null, $v[1] ?? null] : [$v['from'] ?? null, $v['to'] ?? null];
    }

    /** 항목 이름: 작업별 이름(audit.fname.<작업>.<키>)이 있으면 그것, 없으면 공통 이름, 그것도 없으면 키 그대로 */
    private function label(string $key, string $action = ''): string
    {
        foreach ($action !== '' ? ["audit.fname.{$action}.{$key}", "audit.fname.{$key}"] : ["audit.fname.{$key}"] as $k) {
            if (Lang::has($k)) {
                return Lang::get($k);
            }
        }
        return $key;
    }

    /** 값 종류(키 + 작업별 예외) */
    private static function kind(string $action, string $key, mixed $v): string
    {
        return match (true) {
            $action === 'user.role_change' && in_array($key, ['from', 'to', 'role'], true) => 'role',
            $action === 'user.auth_type_change' && in_array($key, ['from', 'to', 'auth_type'], true) => 'auth',
            in_array($action, ['user.approve', 'user.pending_delete'], true) && $key === 'from' => 'approval',
            in_array($action, ['rules.override_set', 'rules.override_clear'], true) && in_array($key, ['before', 'after', 'base'], true) => 'onoff',
            $action === 'rules.override_bulk' && $key === 'action' => 'bulk_action',
            in_array($action, ['run.detail_delete', 'backup.delete', 'schedule.auto_disable', 'worker.start_request'], true) && $key === 'reason' => 'reason',
            $action === 'auth.login_failed' && $key === 'reason' => 'login_reason',
            str_starts_with($action, 'backup.') && $key === 'kind' => 'backup_kind',
            in_array($action, ['repo.save', 'repo.fetch_now'], true) && $key === 'kind' => 'repo_kind',
            $action === 'auth.saml_metadata_import' && $key === 'source' => 'saml_source',
            default => self::KINDS[$key] ?? (is_bool($v) ? 'bool' : 'plain'),
        };
    }

    /** 값 → 풀어 쓴 글자(가린 값은 "(가려진 값)", 비어 있으면 "(없음)") */
    private function value(string $action, string $key, mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return Lang::get('audit.val.none');
        }
        if ($v === Logger::MASK) {
            return Lang::get('audit.val.masked');
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                $parts = array_map(fn ($x): string => is_array($x) ? self::compact($x) : $this->value($action, $key, $x), array_slice($v, 0, self::MAX_LIST));
                $more = count($v) - self::MAX_LIST;
                return implode(', ', $parts) . ($more > 0 ? ' ' . Lang::get('audit.val.more', ['n' => number_format($more)]) : '');
            }
            return self::compact($v);
        }
        $kind = self::kind($action, $key, $v);
        $s = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        if (isset(self::LOOKUP[$kind])) {
            $k = self::LOOKUP[$kind] . $s;
            return Lang::has($k) ? Lang::get($k) : self::clip($s, self::MAX_TEXT);
        }
        return match ($kind) {
            'published' => Lang::get($v === true || $s === '1' ? 'audit.val.published' : 'audit.val.unpublished'),
            'onoff' => Lang::get($v === true || $s === '1' ? 'audit.val.on' : 'audit.val.off'),
            'bool' => Lang::get($v ? 'audit.val.yes' : 'audit.val.no'),
            'bytes' => is_numeric($v) ? self::bytes((float) $v) : self::clip($s, self::MAX_TEXT),
            'ms' => is_numeric($v) ? Lang::get('audit.val.seconds', ['n' => number_format((float) $v / 1000, 1)]) : self::clip($s, self::MAX_TEXT),
            'time' => $this->time($s),
            'field_names' => $this->label($s, $action),
            'config_source' => JsStrings::get("config_settings.source.{$s}") ?? $s,
            'upper' => strtoupper(self::clip($s, 20)),
            'short_hash' => self::clip($s, 12),
            'code' => self::clip($s, 64),
            default => is_int($v) || is_float($v) ? number_format((float) $v, is_float($v) && floor($v) != $v ? 1 : 0) : self::clip($s, self::MAX_TEXT),
        };
    }

    /** UTC ISO → 화면 시간대 "YYYY-MM-DD HH:MM KST"(형식이 다르면 그대로) */
    private function time(string $iso): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $iso) !== 1) {
            return self::clip($iso, 40);
        }
        try {
            $t = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone($this->timezone));
        } catch (\Throwable) {
            return $iso;
        }
        $abbr = $t->format('T');
        return $t->format('Y-m-d H:i') . ' ' . (preg_match('/^[A-Z]{2,5}$/', $abbr) === 1 ? $abbr : 'UTC' . $t->format('P'));
    }

    private static function bytes(float $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? number_format($n) : number_format($n, 1)) . ' ' . $units[$i];
    }

    /** 객체 값 한 줄(키=값, 안쪽 목록은 /로, 안쪽 객체는 한 단계까지 {…}, 길이 제한) */
    private static function compact(array $v, int $depth = 0): string
    {
        $parts = [];
        foreach (array_slice($v, 0, 8, true) as $k => $x) {
            if (is_array($x)) {
                $s = array_is_list($x) && !array_filter($x, 'is_array') ? implode('/', array_map(static fn ($y): string => is_bool($y) ? ($y ? 'true' : 'false') : (string) ($y ?? '-'), array_slice($x, 0, 5))) . (count($x) > 5 ? '/…' : '')
                    : ($depth === 0 ? '{' . self::compact($x, 1) . '}' : '{…}');
            } else {
                $s = is_bool($x) ? ($x ? 'true' : 'false') : (string) ($x ?? '-');
            }
            $parts[] = (is_int($k) ? '' : $k . '=') . $s;
        }
        return self::clip(implode(', ', $parts), self::MAX_TEXT);
    }

    /** 한 줄로 줄이기. 공백 없는 40자 이상 덩어리(토큰·키 모양 — 확장자가 붙은 파일 이름은 제외)는 "(긴 값 생략)"으로, 원본은 기술 정보에만 */
    private static function clip(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
        $s = (string) preg_replace('/[A-Za-z0-9+\/=_\-]{40,}+(?!\.[A-Za-z0-9]{1,8}\b)/', Lang::get('audit.val.long'), $s);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
