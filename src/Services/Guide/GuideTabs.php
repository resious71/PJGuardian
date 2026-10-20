<?php

declare(strict_types=1);

namespace PJGuardian\Services\Guide;

/**
 * 가이드 화면 탭 정의 한 곳(P29). 화면(guide/index.php)과 시험이 이것만 쓴다.
 * P29B(FAQ)는 여기에 ['id' => 'faq', …] 한 줄을 더하고 라우트·화면 스크립트 분기만 붙이면 된다(다른 탭은 그대로).
 * admin=true인 탭은 관리자에게만 그린다(서버에서 판정 — 비관리자 HTML에는 탭 이름·주소도 없음).
 */
final class GuideTabs
{
    /** @var list<array{id:string, label:string, icon:string, path:string, admin:bool}> */
    public const TABS = [
        ['id' => 'user', 'label' => 'guide.tab.user', 'icon' => 'nav.guide', 'path' => '/guide/user', 'admin' => false],
        ['id' => 'admin', 'label' => 'guide.tab.admin', 'icon' => 'admin.guide', 'path' => '/guide/admin', 'admin' => true],
        ['id' => 'faq', 'label' => 'guide.tab.faq', 'icon' => 'nav.faq', 'path' => '/guide/faq', 'admin' => false], // P29B
        ['id' => 'errors', 'label' => 'guide.tab.errors', 'icon' => 'system.error', 'path' => '/guide/errors', 'admin' => false],
    ];

    /** @return list<array{id:string, label:string, icon:string, path:string, admin:bool}> 이 사용자에게 보일 탭 */
    public static function visible(bool $isAdmin): array
    {
        return array_values(array_filter(self::TABS, static fn (array $t): bool => !$t['admin'] || $isAdmin));
    }
}
