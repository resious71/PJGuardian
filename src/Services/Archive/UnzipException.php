<?php

declare(strict_types=1);

namespace PJGuardian\Services\Archive;

use PJGuardian\Support\Config;
use PJGuardian\Support\Lang;

/**
 * 해제 오류(명세 15.2, 19.10). 코드: invalid_zip, zip_too_large, extract_limit, forbidden_entry, filename_encoding,
 * unsupported_name, name_conflict, io_error.
 * - $params: 사용자 문구의 자리표시자(한도 값 등)
 * - $detail: 항목 이름(entry), 사유 코드(reason → unzip.reason.*), 단계(stage: precheck|extract) 등. 로그와 화면 상세용.
 *   **entry는 zip 안의 이름(신뢰할 수 없는 값)**이다. 화면은 textContent로만 넣는다.
 */
final class UnzipException extends \RuntimeException
{
    /** 오류마다 가이드 절(P29가 링크로 연결) */
    public const GUIDE_KEYS = [
        'invalid_zip' => 'upload.error.invalid_zip',
        'zip_too_large' => 'upload.error.zip_too_large',
        'extract_limit' => 'upload.error.extract_limit',
        'forbidden_entry' => 'upload.error.forbidden_entry',
        'filename_encoding' => 'upload.error.filename_encoding',
        'unsupported_name' => 'upload.error.unsupported_name',
        'name_conflict' => 'upload.error.name_conflict',
        'io_error' => 'upload.error.io_error',
    ];

    /**
     * @param array<string, string|int> $params
     * @param array<string, mixed> $detail
     */
    public function __construct(public readonly string $errorCode, public readonly array $params = [], public readonly array $detail = [])
    {
        parent::__construct($errorCode);
    }

    /** 사용자용 문구: 오류 설명 + (있으면) 항목 이름과 사유 */
    public function userMessage(): string
    {
        $codeKey = 'error.' . $this->errorCode; // 코드별 문구(LangTest::testUnzipErrorKeys가 검사)
        $msg = Lang::get($codeKey, $this->params);
        $entry = $this->detail['entry'] ?? null;
        $reason = $this->detail['reason'] ?? null;
        if (is_string($entry) && $entry !== '') {
            $reasonKey = 'unzip.reason.' . (is_string($reason) ? $reason : '');
            $why = Lang::has($reasonKey) ? Lang::get($reasonKey) : '';
            $msg .= ' ' . Lang::get($why === '' ? 'unzip.detail_entry' : 'unzip.detail_entry_reason', ['entry' => self::shorten($entry), 'reason' => $why]);
        }
        return $msg;
    }

    public function guideKey(): string
    {
        return self::GUIDE_KEYS[$this->errorCode] ?? 'upload.error.io_error';
    }

    private static function shorten(string $s): string
    {
        return mb_strlen($s, 'UTF-8') > 200 ? mb_substr($s, 0, 200, 'UTF-8') . '…' : $s;
    }

    public static function humanBytes(int $bytes): string
    {
        return Config::formatSize($bytes);
    }
}
