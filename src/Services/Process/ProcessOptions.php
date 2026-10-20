<?php

declare(strict_types=1);

namespace PJGuardian\Services\Process;

/**
 * 프로세스 하나의 실행 조건(ProcessRunner).
 *
 * - env: 넘길 환경 변수 전체. null이면 ProcessRunner::minimalEnv(임시 폴더)만 넘긴다(부모 환경을 그대로 넘기지 않는다).
 * - timeoutSeconds: 초과하면 프로세스 트리를 종료(사유 timeout). 0 이하는 제한 없음.
 * - outputLimit: stdout + stderr 파일 크기 합계 한도(바이트). 초과를 감지하면 트리를 종료(사유 output_limit).
 *   감지는 폴링이라 한도를 조금 넘겨 쓸 수 있다(초과량은 ProcessResult::overshoot()).
 * - memoryLimit: 바이트. **Linux만** prlimit --as로 적용한다. Windows는 적용하지 않는다(docs/worker_setup.md 5절).
 *   Node(ESLint)는 가상 메모리 예약이 커서 --as가 맞지 않으므로 null로 두고 --max-old-space-size를 인자로 넘긴다(호출자).
 * - outputDir: stdout·stderr 임시 파일을 만들 폴더(기본: 시스템 임시 폴더).
 * - pollMicros: 상태·출력 크기 확인 간격. 짧을수록 출력 한도 초과량이 줄고 CPU를 조금 더 쓴다.
 * - cancelCheckSeconds: 취소 확인 콜백을 부르는 간격.
 * - newProcessGroup: Windows에서 새 프로세스 그룹으로 실행(CREATE_NEW_PROCESS_GROUP). 워커 콘솔의 Ctrl+C가 자식에게 가지 않아
 *   정상 종료 때 진행 중인 자식을 기다릴 수 있다. Linux는 항상 setsid로 새 세션이라 해당 없음.
 * - stdin: 표준 입력으로 쓸 짧은 값(비밀번호 등, 로그에 남기지 않는다). 64KB 이하.
 */
final class ProcessOptions
{
    /** @param array<string, string>|null $env */
    public function __construct(
        public readonly ?string $cwd = null,
        public readonly ?array $env = null,
        public readonly float $timeoutSeconds = 0,
        public readonly int $outputLimit = PHP_INT_MAX,
        public readonly ?int $memoryLimit = null,
        public readonly ?string $outputDir = null,
        public readonly int $pollMicros = 10_000,
        public readonly float $cancelCheckSeconds = 1.0,
        public readonly bool $newProcessGroup = false,
        // 표준 입력으로 보낼 짧은 값(P25: p4 login의 비밀번호 — 명령행 인자로 넘기지 않으려고). 쓰고 바로 닫는다. null이면 빈 입력
        public readonly ?string $stdin = null,
    ) {
    }
}
