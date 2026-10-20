<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 지우지 못한 파일의 정리 대상 목록. 한 줄에 JSON 하나(`<work_dir>/cleanup_pending.jsonl`).
 * 프로젝트 삭제처럼 DB는 이미 지웠는데 파일 삭제가 실패한 경우에 남기고, 정리 작업(P14)이 다시 지운다.
 * 동시 기록은 잠금 전용 파일(.lock)로 직렬화한다(Windows flock 규칙, CLAUDE.md).
 */
final class CleanupQueue
{
    public function __construct(private readonly string $file, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $context 이유·대상 정보(경로는 서버가 만든 값만) */
    public function add(string $path, string $reason, array $context = []): void
    {
        Fs::ensureDir(dirname($this->file));
        $lock = FileLock::acquire($this->file . '.lock');
        try {
            $line = json_encode(
                ['at' => SystemClock::iso($this->clock->now()), 'reason' => $reason, 'path' => str_replace('\\', '/', $path)] + $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            file_put_contents($this->file, $line . "\n", FILE_APPEND);
        } finally {
            $lock->release();
        }
    }
}
