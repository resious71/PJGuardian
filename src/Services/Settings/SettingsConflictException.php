<?php

declare(strict_types=1);

namespace PJGuardian\Services\Settings;

/** 화면이 읽은 뒤 다른 관리자가 settings.local.ini를 먼저 바꿨다(→ 409 settings_conflict, 다시 불러오기) */
final class SettingsConflictException extends \RuntimeException
{
    public function __construct(public readonly string $currentVersion)
    {
        parent::__construct('설정이 그사이 바뀜');
    }
}
