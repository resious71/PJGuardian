<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth\Ldap;

/** 검색 필터 만들기(P28B): 템플릿의 %s 하나에 로그인 ID를 RFC 4515 이스케이프해서 넣는다(필터 주입 방지) */
final class LdapFilter
{
    public static function build(string $template, string $loginId): string
    {
        if (substr_count($template, '%s') !== 1) {
            throw new DirectoryException('not_configured');
        }
        return str_replace('%s', self::escape($loginId), $template);
    }

    /** ldap_escape(..., LDAP_ESCAPE_FILTER)와 같은 결과(확장이 없어도 같은 규칙 — \ * ( ) NUL과 제어 문자) */
    public static function escape(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }
        return (string) preg_replace_callback('/[\\\\*()\x00]/', static fn (array $m): string => sprintf('\\%02x', ord($m[0])), $value);
    }
}
