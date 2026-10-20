<?php

declare(strict_types=1);

namespace PJGuardian\Services\Analysis;

use PJGuardian\Services\ProjectService;
use PJGuardian\Services\Rules\RuleOverrides;
use PJGuardian\Services\Tools\EffectiveRuleConfig;
use PJGuardian\Services\Tools\EslintRunner;
use PJGuardian\Services\Tools\RuffRunner;
use PJGuardian\Services\Tools\ToolRegistry;
use PJGuardian\Services\Upload\UploadProcessor;
use PJGuardian\Support\Db;

/**
 * 분석 조건 키(P27, analyses.env_key): "같은 커밋이면 생략"에서 리비전 말고도 같아야 하는 것. **소스를 받기 전에** 계산할 수 있는 값만 쓴다.
 *
 *   tool, 선택될 도구 버전 라벨(ToolRegistry::select — 차단·고정·체크섬 반영, ESLint는 Node 버전 포함),
 *   서버 설정 해시(P15·P22B가 분석 행 config_hash를 만드는 같은 함수 — 서버 기본 설정 + 관리자 규칙 켜기·끄기 실효 집합),
 *   규칙 켜기·끄기 개정 번호(rule_override_state.revision — 실효 집합이 같아도 개정이 바뀌면 다시 분석, 보수적),
 *   제외 패턴(서버 기본 목록 + 프로젝트 추가 패턴), 대상 확장자(언어별 설정).
 *
 * 분석 행의 config_hash를 그대로 비교하지 못하는 이유: ruff 해시는 저장소에 든 ruff 설정 파일 내용을 포함해 가져오기 전에는 알 수 없다.
 * 같은 리비전이면 저장소 설정 파일도 같으므로 서버 쪽 입력만 비교하면 충분하다(ESLint는 업로드 설정을 쓰지 않는다).
 * 실제 분석(저장소 출처)은 가져오기 직후 같은 함수로 키를 기록한다(tools/run_analysis.php).
 */
final class AnalysisEnvKey
{
    /**
     * @param array<string, list<string>> $targetExtensions 언어 → 대상 확장자
     */
    public function __construct(
        private readonly Db $db,
        private readonly ToolRegistry $registry,
        private readonly ProjectService $projects,
        private readonly ?RuleOverrides $overrides,
        private readonly EslintRunner $eslint,
        private readonly string $ruffDefaultConfig,
        private readonly array $targetExtensions,
    ) {
    }

    /** 키를 이루는 값(보고·시험용). 도구를 쓸 수 없으면 null(→ 생략하지 않는다) */
    public function parts(int $projectId): ?array
    {
        $language = $this->db->value('SELECT language FROM projects WHERE id = ?', [$projectId]);
        if (!is_string($language)) {
            return null;
        }
        $tool = UploadProcessor::toolFor($language);
        $sel = $this->registry->select($tool);
        if ($sel === null) {
            return null;
        }
        $matcher = $this->projects->matcherFor($projectId);
        $useOverrides = $this->overrides !== null && $this->overrides->available();
        $set = $useOverrides ? EffectiveRuleConfig::canonical($tool, $this->overrides->snapshotFor($tool, $sel->version, false)) : null;
        if ($tool === 'ruff') {
            $config = RuffRunner::configHash([(string) realpath($this->ruffDefaultConfig)], implode(',', $matcher->defaults()), $set);
        } else {
            [$files, $ignores] = $this->eslint->patterns($matcher);
            $config = $this->eslint->configHash($files, $ignores, $set);
        }
        $ext = $this->targetExtensions[$language] ?? [];
        sort($ext);
        return [
            'tool' => $tool,
            'tool_version' => $sel->label(),
            'server_config_hash' => $config,
            'rule_override_revision' => $useOverrides ? $this->overrides->revision($tool) : 0,
            'exclude_defaults' => $matcher->defaults(),
            'exclude_patterns' => $matcher->patterns(),
            'target_extensions' => $ext,
        ];
    }

    public function compute(int $projectId): ?string
    {
        $p = $this->parts($projectId);
        return $p === null ? null : hash('sha256', (string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
