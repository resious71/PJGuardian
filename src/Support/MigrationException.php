<?php

declare(strict_types=1);

namespace PJGuardian\Support;

final class MigrationException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $version = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
