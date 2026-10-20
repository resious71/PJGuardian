<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * 검증을 통과해 분석에 쓸 도구 하나. executable은 bin/ 아래 실제 경로.
 * ESLint는 executable이 eslint.js이고 interpreter(Node 실행 파일)로 실행한다.
 */
final class SelectedTool
{
    public function __construct(
        public readonly string $tool,
        public readonly string $version,
        public readonly string $os,
        public readonly string $dir,
        public readonly string $executable,
        public readonly ?string $interpreter = null,
        public readonly ?string $interpreterVersion = null,
    ) {
    }

    /** 분석 기록용 버전 문자열(ESLint는 Node 버전을 함께): "10.11.0 (Node 24.21.0)" */
    public function label(): string
    {
        return $this->interpreterVersion !== null ? sprintf('%s (Node %s)', $this->version, $this->interpreterVersion) : $this->version;
    }
}
