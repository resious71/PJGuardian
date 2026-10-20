<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 설정 검증 실패. 모든 오류를 모아 한 번에 알린다(어떤 키가 왜 잘못되었는지).
 */
final class ConfigException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, string $source = '')
    {
        parent::__construct(
            '설정 오류' . ($source !== '' ? "({$source})" : '') . ' ' . count($errors) . "건:\n - " . implode("\n - ", $errors),
        );
    }
}
