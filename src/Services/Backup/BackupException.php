<?php

declare(strict_types=1);

namespace PJGuardian\Services\Backup;

/**
 * 백업·복원 오류(P27B). errorCode는 화면·명령줄·상태 파일에 쓰는 정해진 코드(문자열 리소스 backup.error.<code>), detail은 로그용(경로·비밀 없음).
 */
final class BackupException extends \RuntimeException
{
    /** @param array<string, scalar> $params 문구 자리 값(건수·버전 등 공개 값만) */
    public function __construct(public readonly string $errorCode, public readonly array $params = [], string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $errorCode);
    }
}
