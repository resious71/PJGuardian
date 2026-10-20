<?php

declare(strict_types=1);

namespace PJGuardian\Services\Admin;

use PJGuardian\Http\ApiException;
use PJGuardian\Services\Actor;
use PJGuardian\Services\AuditLog;
use PJGuardian\Services\Tools\ToolRegistry;

/**
 * 관리자 "분석 도구" 화면의 동작(P20A, 명세 12, 19.1, 19.10). P15 레지스트리의 기존 규칙·함수를 그대로 쓴다.
 * **웹은 bin/에 쓰지 않고 도구를 실행하지 않는다**: 차단·고정은 DB 값만 바꾸고, 고정은 이미 검증된(지문이 같은) 버전만 허용하며,
 * 재검증은 관리 작업(admin_tasks)으로 넣어 워커 자식이 실행한다. 삭제 예정 표시는 선택에서 빼는 표시이고 실제 삭제는 서버 CLI remove.
 * 모든 변경은 감사 로그(대상 = '<도구>@<버전>', 사유)를 남긴다. 서버 경로는 응답·감사에 넣지 않는다.
 */
final class ToolAdmin
{
    public const TOOLS = ['ruff', 'eslint'];
    /** P25: 화면에 읽기 전용으로만 보이는 도구 */
    public const VCS_TOOLS = ['git', 'p4'];

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AdminTasks $tasks,
        private readonly AuditLog $audit,
        private readonly bool $rulesAutoLoad = false,
    ) {
    }

    /** @param array<string, mixed>|null $node 워커 생존 신호의 Node 검사 결과 */
    public function overview(?array $node): array
    {
        $tools = [];
        foreach (self::TOOLS as $tool) {
            $sel = $this->registry->explain($tool);
            $versions = [];
            foreach ($this->registry->list($tool) as $v) {
                $target = $tool . '@' . $v['version'];
                $task = $this->tasks->active('tool_verify', $target) ?? $this->tasks->latest('tool_verify', $target);
                $v['selected'] = $sel['version'] === $v['version'] && in_array($sel['reason'], ['pinned', 'latest', 'fallback'], true);
                // 디스크에 있지만 지금 선택되지 않는 버전(구버전 정리 안내)
                $v['unused'] = $v['on_disk'] && !$v['selected'] && $v['status'] !== 'pinned';
                $v['verify_task'] = $task;
                $v['remove_command'] = $v['on_disk'] && $v['removal_requested_at'] !== null ? "php tools/tools_ctl.php remove {$tool} {$v['version']}" : null;
                $versions[] = $v;
            }
            $tools[] = ['tool' => $tool, 'os' => ToolRegistry::os(), 'selection' => $sel, 'versions' => $versions];
        }
        // P25: 소스 저장소 연동용 git·p4는 **읽기 전용**으로 보인다(버전·체크섬·스모크 결과). 차단·고정·스테이징 대상이 아니다
        foreach (self::VCS_TOOLS as $tool) {
            $sel = $this->registry->explain($tool);
            $versions = array_map(static fn (array $v): array => $v + ['selected' => $sel['version'] === $v['version'], 'unused' => false,
                'verify_task' => null, 'remove_command' => null], $this->registry->list($tool));
            $tools[] = ['tool' => $tool, 'os' => ToolRegistry::os(), 'selection' => $sel, 'versions' => $versions, 'read_only' => true];
        }
        return ['tools' => $tools, 'node' => $node];
    }

    private function check(string $tool, string $version): void
    {
        if (!in_array($tool, self::TOOLS, true) || !ToolRegistry::validVersion($version)) {
            throw new ApiException(404, 'tool_version_not_found');
        }
        foreach ($this->registry->list($tool) as $v) {
            if ($v['version'] === $version && ($v['on_disk'] || $v['removed_at'] === null)) {
                return;
            }
        }
        throw new ApiException(404, 'tool_version_not_found');
    }

    private static function reason(mixed $reason): ?string
    {
        if (!is_string($reason) || trim($reason) === '') {
            return null;
        }
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $reason)), 0, 500);
    }

    private function record(Actor $actor, string $action, string $tool, ?string $version, mixed $reason, array $extra = []): void
    {
        $this->audit->record($actor, $action, 'tool', $version === null ? $tool : $tool . '@' . $version, array_filter(['reason' => self::reason($reason)] + $extra, static fn ($v) => $v !== null));
        // 고정·차단 변경은 활성 버전을 바꿀 수 있다 → 규칙 데이터 적재 요청(P22). 워커가 처리하며 바뀐 것이 없으면 아무것도 하지 않는다
        if ($this->rulesAutoLoad && in_array($action, ['tool.pin', 'tool.unpin', 'tool.block', 'tool.unblock'], true)) {
            $this->tasks->request('rules_load', '', null);
        }
    }

    public function block(Actor $actor, string $tool, string $version, mixed $reason): void
    {
        $this->check($tool, $version);
        foreach ($this->registry->list($tool) as $v) {
            if ($v['version'] === $version && $v['status'] === 'pinned') {
                throw new ApiException(409, 'tool_pinned');
            }
        }
        $this->registry->block($tool, $version);
        $this->record($actor, 'tool.block', $tool, $version, $reason);
    }

    public function unblock(Actor $actor, string $tool, string $version, mixed $reason): void
    {
        $this->check($tool, $version);
        $this->registry->unblock($tool, $version);
        $this->record($actor, 'tool.unblock', $tool, $version, $reason);
    }

    public function pin(Actor $actor, string $tool, string $version, mixed $reason): void
    {
        $this->check($tool, $version);
        // 검증을 실행하지 않는다: 이미 검증된(active, 지문 같음) 버전만. 아니면 409(먼저 재검증 요청)
        $r = $this->registry->pin($tool, $version, false);
        if (!$r['ok']) {
            throw new ApiException(409, match ($r['status']) {
                'needs_verification' => 'tool_needs_verification',
                'removal_requested' => 'tool_removal_requested',
                'blocked' => 'tool_blocked',
                default => 'tool_invalid',
            });
        }
        $this->record($actor, 'tool.pin', $tool, $version, $reason);
    }

    public function unpin(Actor $actor, string $tool, mixed $reason): void
    {
        if (!in_array($tool, self::TOOLS, true)) {
            throw new ApiException(404, 'tool_version_not_found');
        }
        $this->registry->unpin($tool);
        $this->record($actor, 'tool.unpin', $tool, null, $reason);
    }

    /** @return array{task: array<string, mixed>, merged: bool} */
    public function requestVerify(Actor $actor, string $tool, string $version, mixed $reason): array
    {
        $this->check($tool, $version);
        $r = $this->tasks->request('tool_verify', $tool . '@' . $version, $actor->userId);
        $this->record($actor, 'tool.reverify_request', $tool, $version, $reason, ['task_id' => $r['task']['id'], 'merged' => $r['merged']]);
        return $r;
    }

    public function markRemoval(Actor $actor, string $tool, string $version, mixed $reason): void
    {
        $this->check($tool, $version);
        if (!$this->registry->markRemoval($tool, $version)) {
            throw new ApiException(409, 'tool_pinned');
        }
        $this->record($actor, 'tool.mark_removal', $tool, $version, $reason);
    }

    public function unmarkRemoval(Actor $actor, string $tool, string $version, mixed $reason): void
    {
        $this->check($tool, $version);
        $this->registry->unmarkRemoval($tool, $version);
        $this->record($actor, 'tool.unmark_removal', $tool, $version, $reason);
    }
}
