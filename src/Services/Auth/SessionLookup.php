<?php

declare(strict_types=1);

namespace PJGuardian\Services\Auth;

/** 세션 조회 결과. expired는 "있었지만 무활동으로 만료됨"(로그인 화면 안내용). */
final class SessionLookup
{
    public function __construct(public readonly ?Session $session, public readonly bool $expired = false)
    {
    }
}
