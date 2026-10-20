<?php

declare(strict_types=1);

namespace PJGuardian\Services\Archive;

use PJGuardian\Support\Config;

/** 해제 한도(설정 upload.max_zip_size, extract.max_extracted_size, extract.max_files). 시험은 낮춘 값을 넣는다 */
final class UnzipLimits
{
    public function __construct(
        public readonly int $maxZipBytes,
        public readonly int $maxTotalBytes,
        public readonly int $maxFiles,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        return new self($c->int('upload.max_zip_size'), $c->int('extract.max_extracted_size'), $c->int('extract.max_files'));
    }
}
