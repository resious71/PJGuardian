<?php

declare(strict_types=1);

namespace PJGuardian\Services\Defects;

/**
 * 결함이 많은 파일을 임시 SQLite로 처리하기 전 여유 공간 부족(P19C, 명세 19.3). 분석 자식이 insufficient_storage로 기록한다(결함은 그대로).
 */
final class SpillStorageException extends \RuntimeException
{
    public function __construct(public readonly int $free, public readonly int $need)
    {
        parent::__construct("임시 저장소 여유 공간 부족(남음 {$free}, 필요 {$need})");
    }
}
