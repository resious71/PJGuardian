<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/**
 * 비밀번호 정책(명세 3). 로컬 계정에만 적용한다(LDAP 계정은 디렉터리 정책, 명세 19.2).
 * - 최소 길이(문자 수, 기본 10)
 * - 대문자·소문자·숫자·특수문자(그 밖의 모든 문자, 한글·공백 포함) 중 최소 종류 수(기본 3)
 * - bcrypt는 72바이트 이후를 무시하므로 72바이트를 넘으면 거부한다(조용히 잘리지 않게, 가정)
 */
final class PasswordPolicy
{
    public const MAX_BYTES = 72;

    public function __construct(private readonly int $minLength, private readonly int $minClasses)
    {
    }

    /** @return list<string> 위반 코드: too_short, too_few_classes, too_long. 비어 있으면 통과 */
    public function violations(string $password): array
    {
        $v = [];
        if (mb_strlen($password, 'UTF-8') < $this->minLength) {
            $v[] = 'too_short';
        }
        if ($this->charClasses($password) < $this->minClasses) {
            $v[] = 'too_few_classes';
        }
        if (strlen($password) > self::MAX_BYTES) {
            $v[] = 'too_long';
        }
        return $v;
    }

    public function charClasses(string $password): int
    {
        $classes = 0;
        foreach (['/[A-Z]/', '/[a-z]/', '/[0-9]/', '/[^A-Za-z0-9]/u'] as $re) {
            if (preg_match($re, $password) === 1) {
                $classes++;
            }
        }
        return $classes;
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    public function minClasses(): int
    {
        return $this->minClasses;
    }

    /** 정책을 만족하는 임시 비밀번호(4종 모두 포함, 헷갈리는 글자 제외) */
    public function generateTemporary(int $length = 14): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
        $length = max($length, $this->minLength, count($sets));
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
