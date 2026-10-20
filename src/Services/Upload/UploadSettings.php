<?php

declare(strict_types=1);

namespace PJGuardian\Services\Upload;

use PJGuardian\Support\Config;

/**
 * 업로드 설정 값(config.ini [upload], [extract], [disk]). 시험은 설정을 덮어써서 작은 값을 넣는다.
 */
final class UploadSettings
{
    /**
     * @param array<string, list<string>> $targetExtensions 언어별 사전 검사 대상 확장자(점 없이 소문자): python, javascript
     */
    public function __construct(
        public readonly int $maxBytes,
        public readonly int $chunkBytes,
        public readonly int $retentionSeconds,
        public readonly int $maxConcurrentPerUser,
        public readonly int $extractMaxBytes,
        public readonly int $warnPercent,
        public readonly int $blockPercent,
        public readonly array $targetExtensions = ['python' => ['py'], 'javascript' => ['js', 'jsx', 'ts', 'tsx']],
        public readonly int $confirmationRetentionSeconds = 86400,
        public readonly int $failedZipRetentionSeconds = 86400,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        $exts = static fn (string $key): array => array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(ltrim(trim($e), '.')),
            (array) $c->get($key),
        )));
        return new self(
            $c->int('upload.max_zip_size'),
            $c->int('upload.chunk_size'),
            $c->int('upload.incomplete_chunk_retention'),
            $c->int('upload.max_concurrent_per_user'),
            $c->int('extract.max_extracted_size'),
            $c->int('disk.warn_percent'),
            $c->int('disk.block_percent'),
            ['python' => $exts('upload.target_extensions_python'), 'javascript' => $exts('upload.target_extensions_javascript')],
            $c->int('upload.confirmation_retention'),
            $c->int('upload.failed_zip_retention'),
        );
    }
}
