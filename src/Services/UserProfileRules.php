<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Support\Db;

/**
 * 표시 이름·이메일 규칙(X05 수정 경로, X06 생성 경로 공용). 검증은 여기 한 곳에서 하고, 응답 오류 코드는 경로가 정한다:
 *   - 수정(UserService::updateProfile): display_name_required / _too_long / _invalid, email_invalid / email_too_long
 *   - 생성(UserService::create 등, P10 계약 유지): invalid_display_name, invalid_email
 *   - 두 경로 공통: 다른 사용자의 로그인 ID와 같은 이름 → display_name_reserved
 *
 * 함수는 [정규화한 값, 사유] 를 돌려준다. 사유가 null이면 통과.
 *   표시 이름 사유: required, too_long, invalid      이메일 사유: invalid, too_long
 */
final class UserProfileRules
{
    public const NAME_MAX = 100;
    public const EMAIL_MAX = 254;

    /**
     * 앞뒤 공백 제거, NFC, 1~100자, 제어 문자(\p{Cc})와 양방향 서식 문자(U+202A~202E, U+2066~2069) 금지.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function displayName(mixed $v): array
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return [null, 'invalid'];
        }
        $n = \Normalizer::normalize(trim($v), \Normalizer::FORM_C);
        if (!is_string($n)) {
            return [null, 'invalid'];
        }
        $n = trim($n);
        if ($n === '') {
            return [null, 'required'];
        }
        if (mb_strlen($n) > self::NAME_MAX) {
            return [null, 'too_long'];
        }
        // 화면에서 글자 순서를 뒤집어 다른 이름처럼 보이게 하는 문자도 막는다(사칭 방지)
        if (preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $n) === 1) {
            return [null, 'invalid'];
        }
        return [$n, null];
    }

    /**
     * 선택 항목. 앞뒤 공백 제거, 비면 NULL, 254자 이하, @ 하나·앞부분 있음·도메인에 점·공백/제어 문자 없음. 대소문자 그대로, 중복 허용.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function email(mixed $v): array
    {
        if ($v === null) {
            return [null, null];
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            return [null, 'invalid'];
        }
        $v = trim($v);
        if ($v === '') {
            return [null, null];
        }
        if (strlen($v) > self::EMAIL_MAX) {
            return [null, 'too_long'];
        }
        $parts = explode('@', $v);
        if (count($parts) !== 2 || $parts[0] === '' || preg_match('/[\s\p{Cc}]/u', $v) === 1
            || preg_match('/^[^.]+(\.[^.]+)+$/u', $parts[1]) !== 1) {
            return [null, 'invalid'];
        }
        return [$v, null];
    }

    /**
     * 다른 사용자의 로그인 ID와 같은 표시 이름인지(대소문자 무시, 로그인 ID는 ASCII라 lower()로 비교).
     * $selfId: 수정 대상 사용자 id(본인 로그인 ID와 같은 이름은 허용). 생성은 null(새 계정은 아직 없으니 기존 모든 사용자와 비교).
     */
    public static function reserved(Db $db, string $name, ?int $selfId): bool
    {
        return $db->value('SELECT 1 FROM users WHERE lower(login_id) = lower(?) AND id <> ? LIMIT 1', [$name, $selfId ?? 0]) !== null;
    }
}
