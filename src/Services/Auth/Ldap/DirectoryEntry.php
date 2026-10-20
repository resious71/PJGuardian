<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/** 디렉터리에서 찾은 사용자 한 명(P28B). 값은 디렉터리 원문이며 로그·감사 로그에 DN을 넣지 않는다 */
final class DirectoryEntry
{
    public function __construct(
        public readonly string $dn,
        /** attr_login 값(앱 로그인 ID 후보) */
        public readonly string $login,
        /** attr_id의 안정 식별자(objectGUID는 GUID 문자열로, entryUUID는 소문자 그대로) */
        public readonly string $id,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly bool $disabled,
    ) {
    }

    /** 원시 속성 값 → 안정 식별자 문자열. AD objectGUID(16바이트 이진, 앞 세 부분은 little-endian)는 표준 GUID 표기로 */
    public static function normalizeId(string $raw): string
    {
        if (strlen($raw) === 16 && (!mb_check_encoding($raw, 'UTF-8') || preg_match('/[^\x20-\x7e]/', $raw) === 1)) {
            $h = bin2hex($raw);
            $le = static fn (string $s): string => implode('', array_reverse(str_split($s, 2)));
            return $le(substr($h, 0, 8)) . '-' . $le(substr($h, 8, 4)) . '-' . $le(substr($h, 12, 4)) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
        }
        return strtolower(trim($raw));
    }

    /** 비활성 속성 값 → 비활성인가. 숫자면 AD userAccountControl의 ACCOUNTDISABLE(비트 2), 아니면 true·yes·disabled */
    public static function isDisabledValue(?string $raw): bool
    {
        if ($raw === null || trim($raw) === '') {
            return false;
        }
        $v = trim($raw);
        if (ctype_digit($v)) {
            return ((int) $v & 2) === 2;
        }
        return in_array(strtolower($v), ['true', 'yes', 'disabled'], true);
    }
}
